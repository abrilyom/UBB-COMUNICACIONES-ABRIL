<?php

require_once "conexion.php";
require_once "conexion_plataforma.php";
require_once __DIR__ . "/api/cliente_grezz.php";

// =====================================================
// INDICADORES REALES DEL DASHBOARD
// =====================================================

// Pagos registrados hoy y ayer.
// Se utiliza la fecha de MariaDB para mantener consistencia
// con el servidor que almacena los pagos.

$pagos_hoy = 0;
$pagos_ayer = 0;

$consulta_pagos_hoy = $conexion->query("
    SELECT COUNT(*) AS total
    FROM pagos
    WHERE DATE(fecha_pago) = CURDATE()
      AND estado_pago = 'PAGADO'
      AND metodo_pago <> 'CORTESIA'
");

if ($consulta_pagos_hoy) {
    $fila = $consulta_pagos_hoy->fetch_assoc();
    $pagos_hoy = (int)$fila['total'];
}

$consulta_pagos_ayer = $conexion->query("
    SELECT COUNT(*) AS total
    FROM pagos
    WHERE DATE(fecha_pago) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
      AND estado_pago = 'PAGADO'
      AND metodo_pago <> 'CORTESIA'
");

if ($consulta_pagos_ayer) {
    $fila = $consulta_pagos_ayer->fetch_assoc();
    $pagos_ayer = (int)$fila['total'];
}

$texto_tendencia_pagos = "Sin comparación disponible";
$clase_tendencia_pagos = "tendencia-neutra";

if ($pagos_ayer > 0) {
    $variacion_pagos = (($pagos_hoy - $pagos_ayer) / $pagos_ayer) * 100;
    $simbolo_pagos = $variacion_pagos > 0
        ? "↗"
        : ($variacion_pagos < 0 ? "↘" : "→");

    $texto_tendencia_pagos = $simbolo_pagos . " " .
        ($variacion_pagos > 0 ? "+" : "") .
        round($variacion_pagos) . "% vs. ayer";

    $clase_tendencia_pagos = $variacion_pagos > 0
        ? "tendencia-positiva"
        : ($variacion_pagos < 0
            ? "tendencia-negativa"
            : "tendencia-neutra");

} elseif ($pagos_ayer === 0 && $pagos_hoy > 0) {
    $texto_tendencia_pagos = "Nuevos pagos · ayer hubo 0";
    $clase_tendencia_pagos = "tendencia-positiva";
}

// Estados operativos consultados desde Grezz (solo lectura).
$servicios_activos = null;
$servicios_suspendidos = null;
$error_servicios = false;
$servicios_estado_grezz = [
    "ACTIVO" => 0,
    "SUSPENDIDO" => 0,
    "SIN_DATOS" => 0
];

try {
    $consultaGrezz = listarServiciosGrezz();

    if (
        empty($consultaGrezz["transporte_ok"]) ||
        (int)$consultaGrezz["codigo_http"] !== 200 ||
        !isset($consultaGrezz["respuesta"]["servicios"]) ||
        !is_array($consultaGrezz["respuesta"]["servicios"])
    ) {
        throw new RuntimeException("Respuesta de Grezz no disponible o inválida.");
    }

    foreach ($consultaGrezz["respuesta"]["servicios"] as $servicioGrezz) {
        $estado = strtoupper(trim((string)($servicioGrezz["estado_actual"] ?? "")));

        if ($estado === "ACTIVO") {
            $servicios_estado_grezz["ACTIVO"]++;
        } elseif ($estado === "SUSPENDIDO") {
            $servicios_estado_grezz["SUSPENDIDO"]++;
        } else {
            $servicios_estado_grezz["SIN_DATOS"]++;
        }
    }

    $servicios_activos = $servicios_estado_grezz["ACTIVO"];
    $servicios_suspendidos = $servicios_estado_grezz["SUSPENDIDO"];

} catch (Throwable $e) {
    $error_servicios = true;
    $servicios_activos = null;
    $servicios_suspendidos = null;

    error_log("Dashboard UBB: error al consultar Grezz: " . $e->getMessage());
}


// =====================================================
// PRÓXIMOS VENCIMIENTOS: MISMA REGLA QUE proximos.php
// Consulta exclusivamente de lectura a ubb_plataforma.
// =====================================================

date_default_timezone_set("America/Mexico_City");

$total_proximos = null;
$error_proximos = false;
$vencimientos_dashboard = [];

function calcularCicloDashboard($fechaInstalacion, $hoy) {
    if (!$fechaInstalacion || $fechaInstalacion === "0000-00-00") {
        return [
            "fecha_pago" => null,
            "dias" => "Sin fecha de instalación",
            "situacion" => "Revisar fecha"
        ];
    }

    try {
        $instalacion = new DateTimeImmutable($fechaInstalacion);
        $diaOriginal = (int)$instalacion->format("j");
        $anio = (int)$hoy->format("Y");
        $mes = (int)$hoy->format("n");

        $primerDia = new DateTimeImmutable(
            sprintf("%04d-%02d-01", $anio, $mes)
        );

        $ultimoDia = (int)$primerDia->format("t");
        $diaPago = min($diaOriginal, $ultimoDia);

        $fechaPago = $primerDia->setDate($anio, $mes, $diaPago);
        $limite = $fechaPago->modify("+10 days");

        $diferencia = (int)$hoy->diff($fechaPago)->format("%r%a");

        if ($diferencia > 10) {
            return null;
        }

        if ($diferencia > 0) {
            return [
                "fecha_pago" => $fechaPago,
                "dias" => "Faltan " . $diferencia . " días",
                "situacion" => "Próximo a vencer"
            ];
        }

        if ($diferencia === 0) {
            return [
                "fecha_pago" => $fechaPago,
                "dias" => "Vence hoy",
                "situacion" => "Vence hoy"
            ];
        }

        if ($hoy <= $limite) {
            return [
                "fecha_pago" => $fechaPago,
                "dias" => abs($diferencia) . " días desde el vencimiento",
                "situacion" => "En plazo de gracia"
            ];
        }

        $diasFuera = (int)$limite->diff($hoy)->format("%a");

        return [
            "fecha_pago" => $fechaPago,
            "dias" => $diasFuera . " días fuera de plazo",
            "situacion" => "Plazo vencido"
        ];

    } catch (Exception $e) {
        return [
            "fecha_pago" => null,
            "dias" => "Fecha no válida",
            "situacion" => "Revisar fecha"
        ];
    }
}

require_once __DIR__ . "/includes/estado_pagos.php";

try {
    // Misma regla que proximos.php: mensualidades y pagos reales.
    $estado_pagos_dashboard = estadoPagosPorServicio($conexion);

    $sql_vencimientos = "
        SELECT
            s.id_servicio,
            c.nombre,
            c.apellido_paterno,
            c.apellido_materno,
            s.fecha_instalacion
        FROM servicios s
        INNER JOIN clientes c ON c.id_cliente = s.id_cliente
        WHERE s.estado = 'ACTIVO'
    ";

    $resultado_vencimientos = $conexion_plataforma->query($sql_vencimientos);

    while ($fila = $resultado_vencimientos->fetch_assoc()) {
        $ciclo = $estado_pagos_dashboard[(int)$fila["id_servicio"]] ?? null;

        if ($ciclo === null || $ciclo["tipo"] === "futuro") {
            continue;
        }

        $fila["nombre_completo"] = trim(
            ($fila["nombre"] ?? "") . " " .
            ($fila["apellido_paterno"] ?? "") . " " .
            ($fila["apellido_materno"] ?? "")
        );

        $fila["ciclo"] = $ciclo;
        $vencimientos_dashboard[] = $fila;
    }

    $total_proximos = count($vencimientos_dashboard);

    // Primero mostrar las fechas de pago más cercanas.
    usort($vencimientos_dashboard, function ($a, $b) {
        $fechaA = $a["ciclo"]["fecha_pago"];
        $fechaB = $b["ciclo"]["fecha_pago"];

        if ($fechaA === null) return 1;
        if ($fechaB === null) return -1;

        return $fechaA <=> $fechaB;
    });

} catch (Throwable $e) {
    $error_proximos = true;
    $total_proximos = null;
    $vencimientos_dashboard = [];

    error_log(
        "Dashboard UBB: no fue posible consultar próximos vencimientos: " .
        $e->getMessage()
    );
}

/* ===== ESTADOS OPERATIVOS DE GREZZ PARA LA GRAFICA ===== */

$servicios_estado = [
    "ACTIVO" => 0,
    "SUSPENDIDO" => 0,
    "SIN_DATOS" => 0
];

$error_grafica_servicios = $error_servicios;

if (!$error_grafica_servicios) {
    $servicios_estado = $servicios_estado_grezz;
}

$total_estados = array_sum($servicios_estado);

$porcentaje_activo = $total_estados > 0
    ? $servicios_estado["ACTIVO"] * 100 / $total_estados : 0;

$porcentaje_suspendido = $total_estados > 0
    ? $servicios_estado["SUSPENDIDO"] * 100 / $total_estados : 0;

$porcentaje_sin_datos = $total_estados > 0
    ? $servicios_estado["SIN_DATOS"] * 100 / $total_estados : 0;

$porcentaje_acumulado = $porcentaje_activo + $porcentaje_suspendido;

/* ===== INGRESOS DE LA SEMANA SELECCIONADA ===== */
$zona = new DateTimeZone(date_default_timezone_get());
$fecha_hoy = new DateTimeImmutable('today', $zona);
$fecha_seleccionada = $_GET['semana'] ?? $fecha_hoy->format('Y-m-d');

if (!is_string($fecha_seleccionada) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_seleccionada)) {
    $fecha_seleccionada = $fecha_hoy->format('Y-m-d');
}

$obj_fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha_seleccionada, $zona);
if (!$obj_fecha || $obj_fecha->format('Y-m-d') !== $fecha_seleccionada) {
    $obj_fecha = $fecha_hoy;
    $fecha_seleccionada = $fecha_hoy->format('Y-m-d');
}

$inicio_semana = $obj_fecha->modify('monday this week');
$fin_semana = $inicio_semana->modify('+6 days');
$inicio_siguiente_semana = $inicio_semana->modify('+7 days');

$ingresos_uisp = [];
$ingresos_ubb = [];

for ($i = 0; $i < 7; $i++) {
    $fecha = $inicio_semana->modify("+$i days")->format('Y-m-d');
    $ingresos_uisp[$fecha] = 0.0;
    $ingresos_ubb[$fecha] = 0.0;
}

$inicio_sql = $inicio_semana->format('Y-m-d');
$fin_sql = $inicio_siguiente_semana->format('Y-m-d');

/* ===== HISTORICO UISP: SOLO LECTURA ===== */
$stmt_uisp = $conexion->prepare(
    "SELECT
         DATE(LEFT(fecha_original_uisp, 10)) AS dia,
         SUM(monto) AS ingresos
     FROM uisp_pagos_importacion
     WHERE LEFT(fecha_original_uisp, 10) >= ?
       AND LEFT(fecha_original_uisp, 10) < ?
       AND moneda = 'MXN'
       AND metodo_original_uisp NOT LIKE '%cortes%'
     GROUP BY dia
     ORDER BY dia"
);

$stmt_uisp->bind_param('ss', $inicio_sql, $fin_sql);
$stmt_uisp->execute();
$resultado_uisp = $stmt_uisp->get_result();

while ($fila = $resultado_uisp->fetch_assoc()) {
    $dia = $fila['dia'];
    if (array_key_exists($dia, $ingresos_uisp)) {
        $ingresos_uisp[$dia] = (float)$fila['ingresos'];
    }
}
$stmt_uisp->close();

/* ===== PAGOS NUEVOS UBB: EXCLUIR LOS CUATRO REGISTROS DE PRUEBA ===== */
$stmt_ubb = $conexion->prepare(
    "SELECT
         DATE(fecha_pago) AS dia,
         SUM(monto) AS ingresos
     FROM pagos
     WHERE estado_pago = 'PAGADO'
       AND id_pago NOT IN (1, 2, 3, 4)
       AND metodo_pago <> 'CORTESIA'
       AND fecha_pago >= ?
       AND fecha_pago < ?
     GROUP BY DATE(fecha_pago)
     ORDER BY dia"
);

$inicio_sql_datetime = $inicio_semana->format('Y-m-d 00:00:00');
$fin_sql_datetime = $inicio_siguiente_semana->format('Y-m-d 00:00:00');

$stmt_ubb->bind_param('ss', $inicio_sql_datetime, $fin_sql_datetime);
$stmt_ubb->execute();
$resultado_ubb = $stmt_ubb->get_result();

while ($fila = $resultado_ubb->fetch_assoc()) {
    $dia = $fila['dia'];
    if (array_key_exists($dia, $ingresos_ubb)) {
        $ingresos_ubb[$dia] = (float)$fila['ingresos'];
    }
}
$stmt_ubb->close();

$total_uisp_semana = array_sum($ingresos_uisp);
$total_ubb_semana = array_sum($ingresos_ubb);

$maximo_diario = 0.0;
foreach ($ingresos_uisp as $fecha => $importe) {
    $maximo_diario = max(
        $maximo_diario,
        $importe,
        $ingresos_ubb[$fecha]
    );
}

$escala_ingresos = $maximo_diario > 0
    ? ceil($maximo_diario / 4 / 100) * 100 : 100;
$maximo_eje_ingresos = $escala_ingresos * 4;

$total_ingresos_semana = $total_uisp_semana + $total_ubb_semana;
$etiqueta_semana = $inicio_semana->format('d/m') . ' – ' . $fin_semana->format('d/m');

/* ===== CENTRO DE AUTOMATIZACIÓN ===== */
require_once __DIR__ . "/includes/resumen_automatizacion.php";

$automatizacion = resumenAutomatizacion(
    $conexion,
    $estado_pagos_dashboard ?? [],
    $error_servicios ? null : ($consultaGrezz["respuesta"]["servicios"] ?? null)
);

function numeroAuto($valor): string
{
    return $valor === null ? '—' : number_format((int)$valor);
}

$auto_mov = $automatizacion['movimientos'];
$auto_conc = $automatizacion['conciliados_auto'];
$auto_sol = $automatizacion['solicitudes'];
$auto_avisos = $automatizacion['avisos'];
$auto_cortes = $automatizacion['cortes'];
$auto_ejec = $automatizacion['ejecuciones'] ?? [];
$auto_uisp = $automatizacion['pagos_uisp'] ?? null;

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>UBB Comunicaciones - Gestión de Pagos</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
</head>

<body>

<!-- =========================================================
     ENCABEZADO
========================================================= -->

<?php require __DIR__ . "/includes/encabezado.php"; ?>


<!-- =========================================================
     CONTENIDO PRINCIPAL
========================================================= -->

<?php require __DIR__ . "/includes/panel_lateral.php"; ?>
<main class="contenedor">

    <!-- =====================================================
         CENTRO DE CONTROL
    ====================================================== -->

    <section class="encabezado-control">

        <div>
            <div class="titulo-centro-fila"><span class="icono-centro-control" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg></span><h2 class="titulo-centro-control">CENTRO DE CONTROL</h2></div>
            <p class="subtitulo-centro-control">Resumen general de pagos y servicios de internet</p>
        </div>

        <div class="automatizacion">
            <span class="punto-verde"></span>

            <div>
                <strong>AUTOMATIZACIÓN DE PAGOS</strong>
                <small>Pagos conciliados y servicios coordinados con Grezz</small>
            </div>
        </div>

    </section>


    <!-- =====================================================
         TARJETAS PRINCIPALES
    ====================================================== -->

    <section class="tarjetas">

        <div class="tarjeta tarjeta-activos" onclick="window.location.href='activos.php'">
           <div class="tarjeta-titulo">
                <span class="icono-tarjeta" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 3.2a4 4 0 0 1 0 7.6M20 21v-2a4 4 0 0 0-3-3.9"/></svg></span>
                <span>ACTIVOS</span>
           </div>

    <strong class="numero"><?php echo $servicios_activos !== null ? $servicios_activos : '—'; ?></strong>

    <small><?php echo $error_servicios ? 'No disponible: revisar conexión' : 'Servicios DHCP activos en Grezz'; ?></small>
    <span class="capsula-tendencia tendencia-neutra"><?php echo $error_servicios ? 'Sin conexión' : 'Datos reales'; ?></span>
</div>


  <div class="tarjeta tarjeta-pagos" onclick="window.location.href='pagos_hoy.php'">
    <div class="tarjeta-titulo">
        <span class="icono-tarjeta" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/></svg></span>
        <span>PAGOS HOY</span>
    </div>
    
          <strong class="numero"><?php echo $pagos_hoy; ?></strong>

    <small>Pagos registrados hoy</small>
    <span class="capsula-tendencia <?php echo $clase_tendencia_pagos; ?>"><?php echo htmlspecialchars($texto_tendencia_pagos, ENT_QUOTES, "UTF-8"); ?></span>
</div>


        <div class="tarjeta tarjeta-proximos" onclick="window.location.href='./proximos.php';">
            <div class="tarjeta-titulo">
                <span class="icono-tarjeta" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 13v3l2 1"/></svg></span>
                <span>PRÓXIMOS</span>
            </div>

            <strong class="numero"><?php echo $total_proximos !== null ? number_format($total_proximos) : '—'; ?></strong>

            <small><?php echo $error_proximos ? 'No disponible: revisar conexión' : 'Con mensualidad por vencer o vencida'; ?></small>
            <span class="capsula-tendencia tendencia-precaucion"><?php echo $error_proximos ? 'Sin conexión' : 'Datos reales'; ?></span>
        </div>


        <div class="tarjeta tarjeta-suspendidos" onclick="window.location.href='suspendidos.php'">

            <div class="tarjeta-titulo">
                <span class="icono-tarjeta" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>
                <span>SUSPENDIDOS</span>
            </div>

            <strong class="numero"><?php echo $servicios_suspendidos !== null ? $servicios_suspendidos : '—'; ?></strong>

            <small><?php echo $error_servicios ? 'No disponible: revisar conexión' : 'Servicios DHCP suspendidos en Grezz'; ?></small>
            <span class="capsula-tendencia tendencia-alerta"><?php echo $error_servicios ? 'Sin conexión' : 'Datos reales'; ?></span>
        </div>

    </section>


    <!-- =====================================================
         CENTRO DE AUTOMATIZACIÓN
         Lo que el sistema hace solo: conciliar pagos, solicitar
         suspensiones y reactivaciones a Grezz y avisar a los clientes.
    ====================================================== -->

    <section class="panel auto-centro">

        <div class="auto-encabezado">
            <div class="auto-titulo">
                <span class="icono-circular-ingresos" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </span>
                <div>
                    <h3>Centro de automatización</h3>
                    <p>Lo que el sistema hace solo: concilia pagos, coordina cortes y reconexiones con Grezz y avisa a los clientes.</p>
                </div>
            </div>
            <span class="auto-regla">Gracia: 10 días · Corte: día 11</span>
        </div>

        <div class="auto-flujo">

            <!-- 1. Pagos bancarios -->
            <a class="auto-paso" href="conciliacion_bancaria.php">
                <span class="auto-paso-num">1</span>
                <strong>Pagos bancarios</strong>
                <div class="auto-cifra">
                    <?= numeroAuto($auto_conc['total'] ?? null) ?>
                    <small>conciliados solos (7 días)</small>
                </div>
                <ul>
                    <li>$<?= number_format((float)($auto_conc['importe'] ?? 0), 2) ?> aplicados</li>
                    <li><?= numeroAuto($auto_mov['pendientes'] ?? ($auto_mov === null ? null : 0)) ?> por conciliar</li>
                    <li class="<?= (int)($auto_mov['revision'] ?? 0) > 0 ? 'auto-alerta' : '' ?>">
                        <?= numeroAuto($auto_mov['revision'] ?? ($auto_mov === null ? null : 0)) ?> en revisión manual
                    </li>
                    <li class="<?= ($auto_ejec['PAGOS_UISP']['resultado'] ?? '') === 'ERROR' ? 'auto-alerta' : '' ?>">
                        Pagos UISP: <?= htmlspecialchars(textoUltimaEjecucion($auto_ejec['PAGOS_UISP'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                    </li>
                    <li class="<?= (int)($auto_uisp['revision'] ?? 0) > 0 ? 'auto-alerta' : '' ?>">
                        <?= numeroAuto($auto_uisp['aplicados_7d'] ?? ($auto_uisp === null ? null : 0)) ?> de UISP aplicados (7 días) ·
                        <?= numeroAuto($auto_uisp['revision'] ?? ($auto_uisp === null ? null : 0)) ?> en revisión
                    </li>
                </ul>
                <span class="auto-ejecucion"><?= htmlspecialchars(textoUltimaEjecucion($auto_ejec['CONCILIACION'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <!-- 2. Cortes -->
            <a class="auto-paso" href="vencidos.php">
                <span class="auto-paso-num">2</span>
                <strong>Suspensiones</strong>
                <div class="auto-cifra">
                    <?= numeroAuto($auto_cortes['fuera_gracia']) ?>
                    <small>fuera de gracia</small>
                </div>
                <ul>
                    <li><?= numeroAuto($auto_cortes['gestionables']) ?> se cortan automáticamente</li>
                    <li><?= numeroAuto($auto_cortes['manuales']) ?> requieren corte manual (PPPoE)</li>
                    <li><?= numeroAuto($auto_cortes['en_gracia']) ?> en periodo de gracia</li>
                </ul>
                <span class="auto-ejecucion"><?= htmlspecialchars(textoUltimaEjecucion($auto_ejec['SUSPENSION'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <!-- 3. Grezz -->
            <a class="auto-paso" href="suspendidos.php">
                <span class="auto-paso-num">3</span>
                <strong>Solicitudes a Grezz</strong>
                <div class="auto-cifra">
                    <?= numeroAuto(isset($auto_sol) && $auto_sol !== null
                        ? (int)($auto_sol['suspensiones_hoy'] ?? 0) + (int)($auto_sol['reactivaciones_hoy'] ?? 0)
                        : null) ?>
                    <small>enviadas hoy</small>
                </div>
                <ul>
                    <li><?= numeroAuto($auto_sol['suspensiones_hoy'] ?? ($auto_sol === null ? null : 0)) ?> suspensiones</li>
                    <li><?= numeroAuto($auto_sol['reactivaciones_hoy'] ?? ($auto_sol === null ? null : 0)) ?> reactivaciones</li>
                    <li class="<?= (int)($auto_sol['pendientes'] ?? 0) > 0 ? 'auto-alerta' : '' ?>">
                        <?= numeroAuto($auto_sol['pendientes'] ?? ($auto_sol === null ? null : 0)) ?> sin confirmar
                    </li>
                </ul>
                <span class="auto-ejecucion">Modo simulación hasta validar con Grezz</span>
            </a>

            <!-- 4. Avisos -->
            <a class="auto-paso" href="notificaciones.php">
                <span class="auto-paso-num">4</span>
                <strong>Avisos a clientes</strong>
                <div class="auto-cifra">
                    <?= numeroAuto($auto_avisos['enviados_hoy'] ?? ($auto_avisos === null ? null : 0)) ?>
                    <small>enviados hoy</small>
                </div>
                <ul>
                    <li><?= numeroAuto($auto_avisos['pendientes'] ?? ($auto_avisos === null ? null : 0)) ?> por enviar</li>
                    <li class="<?= (int)($auto_avisos['con_problema'] ?? 0) > 0 ? 'auto-alerta' : '' ?>">
                        <?= numeroAuto($auto_avisos['con_problema'] ?? ($auto_avisos === null ? null : 0)) ?> sin teléfono o con error
                    </li>
                </ul>
                <span class="auto-ejecucion"><?= htmlspecialchars(textoUltimaEjecucion($auto_ejec['NOTIFICACIONES'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
            </a>

        </div>
    </section>


    <!-- =====================================================
         PAGOS + ESTADO DE SERVICIOS
    ====================================================== -->


       <!-- PAGOS + DISTRIBUCIÓN -->

<section class="graficas-servicios">

    <!-- PAGOS -->
    <div class="panel">

        <div class="panel-titulo encabezado-grafica-ingresos">
    <div class="titulo-grafica titulo-ingresos-limpio">
    <span class="icono-circular-ingresos" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="20" height="20"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19V5"></path>
            <path d="M4 19h17"></path>
            <rect x="7" y="11" width="3" height="5" rx="1" fill="currentColor" stroke="none"></rect>
            <rect x="13" y="7" width="3" height="9" rx="1" fill="currentColor" stroke="none"></rect>
            <rect x="19" y="3" width="2" height="13" rx="1" fill="currentColor" stroke="none"></rect>
        </svg>
    </span>
    <h3>Ingresos por semana</h3>
</div>

    <form class="selector-semana" method="get" action="index.php">
        <label class="selector-semana-control" for="fecha-semana">
            <svg class="icono-calendario-control" viewBox="0 0 24 24"
                 width="17" height="17" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
            </svg>
            <span class="texto-rango-semana">
                <?= htmlspecialchars($inicio_semana->format('j') . ' ' . [
                    1=>'ene.', 2=>'feb.', 3=>'mar.', 4=>'abr.',
                    5=>'may.', 6=>'jun.', 7=>'jul.', 8=>'ago.',
                    9=>'sep.', 10=>'oct.', 11=>'nov.', 12=>'dic.'
                ][(int)$inicio_semana->format('n')] . ' ' . $inicio_semana->format('Y')
                . ' – ' .
                $fin_semana->format('j') . ' ' . [
                    1=>'ene.', 2=>'feb.', 3=>'mar.', 4=>'abr.',
                    5=>'may.', 6=>'jun.', 7=>'jul.', 8=>'ago.',
                    9=>'sep.', 10=>'oct.', 11=>'nov.', 12=>'dic.'
                ][(int)$fin_semana->format('n')] . ' ' . $fin_semana->format('Y'),
                ENT_QUOTES, 'UTF-8') ?>
            </span>
            <svg class="flecha-selector-semana" viewBox="0 0 24 24"
                 width="15" height="15" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true">
                <path d="m6 9 6 6 6-6"></path>
            </svg>
        </label>
        <input
            id="fecha-semana"
            type="date"
            name="semana"
            value="<?= htmlspecialchars($fecha_seleccionada, ENT_QUOTES, 'UTF-8') ?>"
            aria-label="Elegir una fecha para consultar su semana"
            onchange="this.form.submit()">
    </form>
</div>

<div class="resumen-ingresos-semana resumen-ingresos-doble">
    <div class="resumen-fuente resumen-fuente-uisp">
        <span>Histórico UISP</span>
        <strong>$<?= number_format($total_uisp_semana, 2) ?></strong>
    </div>
    <div class="resumen-fuente resumen-fuente-ubb">
        <span>Pagos nuevos UBB</span>
        <strong>$<?= number_format($total_ubb_semana, 2) ?></strong>
    </div>
</div>

<div class="grafica grafica-ingresos">
    <div class="grafica-vertical">
        <div class="eje-grafica">
            <?php for ($nivel = 4; $nivel >= 0; $nivel--): ?>
                <span>$<?= number_format($escala_ingresos * $nivel, 0) ?></span>
            <?php endfor; ?>
        </div>

        <div class="area-barras">
            <?php foreach ($ingresos_uisp as $fecha => $importe_uisp):
                $importe_ubb = $ingresos_ubb[$fecha];

                $altura_uisp = $maximo_eje_ingresos > 0
                    ? (int)round($importe_uisp / $maximo_eje_ingresos * 150) : 0;

                $altura_ubb = $maximo_eje_ingresos > 0
                    ? (int)round($importe_ubb / $maximo_eje_ingresos * 150) : 0;

                $fecha_obj = new DateTimeImmutable($fecha);
                $dias_es = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
                $etiqueta_dia = $dias_es[(int)$fecha_obj->format('N') - 1];

                $detalle = $fecha
                    . ' | UISP: $' . number_format($importe_uisp, 2)
                    . ' | UBB: $' . number_format($importe_ubb, 2);
            ?>
            <div class="barra-vertical grupo-barras-dia"
                 title="<?= htmlspecialchars($detalle, ENT_QUOTES, 'UTF-8') ?>">

                <div class="barras-dobles">
                    <div class="barra-fuente barra-uisp"
                         style="height: <?= $altura_uisp ?>px !important;"
                         title="UISP: $<?= number_format($importe_uisp, 2) ?>">
                    </div>

                    <div class="barra-fuente barra-ubb"
                         style="height: <?= $altura_ubb ?>px !important;"
                         title="UBB: $<?= number_format($importe_ubb, 2) ?>">
                    </div>
                </div>

                <span class="etiqueta-fecha">
                    <?= $etiqueta_dia ?><br><?= $fecha_obj->format('d/m') ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="leyenda-ingresos leyenda-ingresos-doble">
        <span class="leyenda-uisp"></span> Histórico UISP
        <span class="leyenda-ubb"></span> Pagos nuevos UBB
    </div>
</div>
    </div>


    <!-- DISTRIBUCIÓN DE SERVICIOS -->
    <div class="panel">

        <div class="panel-titulo titulo-estados-pago">
            <span class="icono-circular-ingresos" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20"
                     fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16v16H4z"></path>
                    <path d="M8 8h8M8 12h5"></path>
                    <path d="m8 16 2 2 5-5"></path>
                </svg>
            </span>
            <h3>Estado de servicios</h3>
        </div>

        <?php if ($error_grafica_servicios): ?>
            <p>No fue posible consultar los estados de los servicios.</p>
        <?php else: ?>

        <div class="grafica-circular dona-ubb">
            <div class="dona-ubb-visual"
                 role="img"
                 aria-label="Distribución de <?= $total_estados ?> servicios: <?= $servicios_estado["ACTIVO"] ?> activos, <?= $servicios_estado["SUSPENDIDO"] ?> suspendidos y <?= $servicios_estado["SIN_DATOS"] ?> sin datos."
                 style="background: conic-gradient(
                    #16a34a 0% <?= number_format($porcentaje_activo, 2, '.', '') ?>%,
                    #f0b429 <?= number_format($porcentaje_activo, 2, '.', '') ?>% <?= number_format($porcentaje_acumulado, 2, '.', '') ?>%,
                    #94a3b8 <?= number_format($porcentaje_acumulado, 2, '.', '') ?>% 100%
                 );">
                <div class="dona-ubb-centro">
                    <strong><?= number_format($total_estados) ?></strong>
                    <span>Servicios totales</span>
                </div>
            </div>

            <div class="leyenda-pastel leyenda-ubb">
                <div class="item-leyenda item-leyenda-ubb">
                    <span class="punto-leyenda pagado"></span>
                    <div class="detalle-leyenda-ubb">
                        <span class="nombre-estado-ubb">Activos</span>
                        <strong><?= number_format($servicios_estado["ACTIVO"]) ?></strong>
                        <small><?= number_format($porcentaje_activo, 1) ?>%</small>
                    </div>
                </div>

                <div class="item-leyenda item-leyenda-ubb">
                    <span class="punto-leyenda pendiente"></span>
                    <div class="detalle-leyenda-ubb">
                        <span class="nombre-estado-ubb">Suspendidos</span>
                        <strong><?= number_format($servicios_estado["SUSPENDIDO"]) ?></strong>
                        <small><?= number_format($porcentaje_suspendido, 1) ?>%</small>
                    </div>
                </div>

                <div class="item-leyenda item-leyenda-ubb">
                    <span class="punto-leyenda cancelado"></span>
                    <div class="detalle-leyenda-ubb">
                        <span class="nombre-estado-ubb">Sin datos</span>
                        <strong><?= number_format($servicios_estado["SIN_DATOS"]) ?></strong>
                        <small><?= number_format($porcentaje_sin_datos, 1) ?>%</small>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

</section>


<!-- ESTADO DE SERVICIOS -->




    <!-- ACCESO DIRECTO -->

    <section class="panel">

        <div class="panel-titulo">
            <span>⚡</span>
            <div>
                <span class="etiqueta-seccion">ACCESO DIRECTO</span>
                <h3>Operaciones</h3>
            </div>
        </div>

        <div class="acciones">
            <a href="reporte_uisp.php" class="accion">
                <span>📊</span>
                <div>
                    <strong>Historial UISP</strong>
                    <small>Consultar pagos históricos importados</small>
                </div>
            </a>
        </div>

    </section>











</main>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->



</body>
</html>