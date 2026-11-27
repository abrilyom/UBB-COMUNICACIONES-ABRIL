<?php
require_once __DIR__ . "/includes/sesion.php";

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
// SITUACIÓN DE PAGOS: MISMA REGLA QUE adeudos.php
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
    // Situación de cada servicio según mensualidades y pagos reales (ubb_pagos).
    $estado_pagos_todos = estadoPagosPorServicio($conexion);

    // Base única para todas las cifras de cobranza del tablero: servicios que
    // existen en la plataforma y no están dados de baja (mismo criterio que
    // adeudos.php). El estado ACTIVO de la plataforma no se usa porque no se
    // actualiza al cortar; el estado real del servicio lo da Grezz.
    $resultado_servicios = $conexion_plataforma->query("
        SELECT id_servicio
        FROM servicios
        WHERE COALESCE(estado, '') <> 'BAJA'
    ");

    $servicios_vigentes = [];
    while ($fila = $resultado_servicios->fetch_assoc()) {
        $servicios_vigentes[(int)$fila["id_servicio"]] = true;
    }

    $estado_pagos_dashboard = array_intersect_key($estado_pagos_todos, $servicios_vigentes);

    // Próximas a vencer: vencen en los próximos 10 días o hoy. Las que ya
    // vencieron (en gracia o fuera de gracia) se muestran en el monitor.
    $total_proximos = 0;
    $total_vencen_hoy = 0;

    foreach ($estado_pagos_dashboard as $ciclo) {
        if ($ciclo["tipo"] === "proximo" || $ciclo["tipo"] === "hoy") {
            $total_proximos++;
        }
        if ($ciclo["tipo"] === "hoy") {
            $total_vencen_hoy++;
        }
    }

} catch (Throwable $e) {
    $error_proximos = true;
    $total_proximos = null;
    $total_vencen_hoy = null;
    $estado_pagos_dashboard = [];

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

$ingresos_semana = [];

for ($i = 0; $i < 7; $i++) {
    $fecha = $inicio_semana->modify("+$i days")->format('Y-m-d');
    $ingresos_semana[$fecha] = 0.0;
}

/* ===== INGRESOS DE LA SEMANA =====
   La tabla pagos ya incluye los pagos que llegan desde UISP
   (scripts/aplicar_pagos_uisp.php), así que es la única fuente.
   Se excluyen los cuatro registros de prueba. */
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
    if (array_key_exists($dia, $ingresos_semana)) {
        $ingresos_semana[$dia] = (float)$fila['ingresos'];
    }
}
$stmt_ubb->close();

$total_ingresos_semana = array_sum($ingresos_semana);
$maximo_diario = max($ingresos_semana);

$escala_ingresos = $maximo_diario > 0
    ? ceil($maximo_diario / 4 / 100) * 100 : 100;
$maximo_eje_ingresos = $escala_ingresos * 4;
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

// Si no se pudo calcular la situación de pagos, las cifras de cobranza se
// muestran como "—" y no como 0.
$auto_venc = $error_proximos ? array_fill_keys(['proximos', 'vencen_hoy', 'en_gracia', 'fuera_gracia'], null)
    : $automatizacion['vencimientos'];
if ($error_proximos) {
    $auto_cortes['fuera_gracia'] = null;
    $auto_cortes['en_gracia'] = null;
}

/* ===== CENTRO DE CONTROL: ESTADO DE PROCESOS Y ACTIVIDAD ===== */
require_once __DIR__ . "/includes/tablero_datos.php";

$importe_pagos_hoy = importePagosHoy($conexion);
$grezz_semana = solicitudesGrezzSemana($conexion);
$actividad = actividadRecienteTablero($conexion, $conexion_plataforma ?? null, 25);

// Última ejecución de cada proceso (o null).
$ejec = fn(string $proceso) => $auto_ejec[$proceso] ?? null;

// La ejecución más reciente entre varios procesos relacionados.
$ultimaDe = function (array $procesos) use ($ejec): ?array {
    $ultima = null;
    foreach ($procesos as $p) {
        $e = $ejec($p);
        if ($e && (!$ultima || $e['fecha_fin'] > $ultima['fecha_fin'])) {
            $ultima = $e;
        }
    }
    return $ultima;
};

$errorEn = fn(array $procesos) => (bool)array_filter($procesos, fn($p) => ($ejec($p)['resultado'] ?? '') === 'ERROR');

// 1. Conciliación y pagos bancarios (banco + UISP).
$proc_conc_ejec = $ultimaDe(['CONCILIACION', 'PAGOS_UISP', 'APLICAR_PAGOS_UISP']);
$proc_conc_alertas = (int)($auto_mov['revision'] ?? 0) + (int)($auto_uisp['revision'] ?? 0);
$proc_conc = estadoProcesoTablero(
    $errorEn(['CONCILIACION', 'PAGOS_UISP', 'APLICAR_PAGOS_UISP']) ? ['resultado' => 'ERROR'] : $proc_conc_ejec,
    $proc_conc_alertas,
    $auto_mov === null && $auto_conc === null && $auto_uisp === null
);

// 2. Vencimientos y periodos de gracia (mensualidades).
$proc_venc_ejec = $ultimaDe(['MENSUALIDADES_UISP', 'MESES_SIN_SERVICIO', 'FACTURAS_UISP', 'FACTURAS_PLATAFORMA']);
$proc_venc = estadoProcesoTablero(
    $errorEn(['MENSUALIDADES_UISP', 'MESES_SIN_SERVICIO', 'FACTURAS_UISP', 'FACTURAS_PLATAFORMA']) ? ['resultado' => 'ERROR'] : $proc_venc_ejec,
    0,
    $error_proximos
);

// 3. Suspensiones y reactivaciones.
$proc_susp_ejec = $ultimaDe(['SUSPENSION']);
// Los cortados sin adeudo no son alerta: se cortan a propósito los servicios
// sin datos del cliente para que el cliente llame y los proporcione.
$proc_susp = estadoProcesoTablero($proc_susp_ejec, 0, $error_proximos);

// 4. Integración con Grezz.
$grezz_simulacion = defined('GREZZ_SIMULAR') && GREZZ_SIMULAR;
$grezz_pendientes = (int)($auto_sol['pendientes'] ?? 0);
$grezz_rechazadas = (int)($grezz_semana['rechazadas'] ?? 0);
if ($error_servicios) {
    $proc_grezz = ['clase' => 'error', 'texto' => 'Sin conexión'];
} elseif ($grezz_pendientes > 0 || $grezz_rechazadas > 0) {
    $proc_grezz = ['clase' => 'atencion', 'texto' => 'Requiere atención'];
} elseif ($grezz_simulacion) {
    $proc_grezz = ['clase' => 'simulacion', 'texto' => 'Conectado · simulación'];
} else {
    $proc_grezz = ['clase' => 'ok', 'texto' => 'Conectado'];
}

// 5. Notificaciones a clientes.
$proc_avisos_ejec = $ultimaDe(['NOTIFICACIONES', 'RECORDATORIOS']);
$proc_avisos = estadoProcesoTablero(
    $errorEn(['NOTIFICACIONES', 'RECORDATORIOS']) ? ['resultado' => 'ERROR'] : $proc_avisos_ejec,
    (int)($auto_avisos['con_problema'] ?? 0),
    $auto_avisos === null
);

// Errores y alertas de todos los procesos, para el aviso del encabezado.
$procesos_con_error = count(array_filter(
    [$proc_conc, $proc_venc, $proc_susp, $proc_grezz, $proc_avisos],
    fn($p) => $p['clase'] === 'error'
));
$procesos_con_alerta = count(array_filter(
    [$proc_conc, $proc_venc, $proc_susp, $proc_grezz, $proc_avisos],
    fn($p) => $p['clase'] === 'atencion'
));

function e_tablero($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>UBB Comunicaciones - Gestión de Pagos</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
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
<main class="contenedor cc">

    <!-- =====================================================
         CABECERA DEL CENTRO DE CONTROL
    ====================================================== -->
    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>
            </span>
            <div>
                <h1>Centro de control</h1>
                <p>Cobranza, servicios de internet y procesos automáticos de UBB Comunicaciones</p>
            </div>
        </div>

        <div class="cc-cabecera-estado">
            <?php if ($procesos_con_error > 0): ?>
                <a class="cc-pildora cc-pildora--error" href="#cc-monitor">
                    <span class="cc-punto"></span><?= $procesos_con_error ?> proceso<?= $procesos_con_error === 1 ? '' : 's' ?> con error
                </a>
            <?php elseif ($procesos_con_alerta > 0): ?>
                <a class="cc-pildora cc-pildora--atencion" href="#cc-monitor">
                    <span class="cc-punto"></span><?= $procesos_con_alerta ?> proceso<?= $procesos_con_alerta === 1 ? '' : 's' ?> requiere<?= $procesos_con_alerta === 1 ? '' : 'n' ?> atención
                </a>
            <?php else: ?>
                <span class="cc-pildora cc-pildora--ok"><span class="cc-punto"></span>Procesos sin errores</span>
            <?php endif; ?>
            <span class="cc-actualizado">Consultado hoy a las <?= date('H:i') ?></span>
        </div>
    </header>


    <!-- =====================================================
         1. RESUMEN GENERAL
         Solo indicadores de estado. Cada cifra aparece una vez.
    ====================================================== -->
    <section class="cc-seccion" aria-labelledby="cc-titulo-resumen">
        <div class="cc-seccion-titulo">
            <h2 id="cc-titulo-resumen">Resumen general</h2>
            <p>Cómo están hoy los servicios y la cobranza</p>
        </div>

        <div class="cc-kpis">

            <a class="cc-kpi cc-kpi--activos" href="activos.php">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 3.2a4 4 0 0 1 0 7.6M20 21v-2a4 4 0 0 0-3-3.9"/></svg></span>
                    <span class="cc-kpi-nombre">Servicios activos</span>
                </div>
                <strong class="cc-kpi-numero"><?= $servicios_activos !== null ? number_format($servicios_activos) : '—' ?></strong>
                <span class="cc-kpi-detalle"><?= $error_servicios ? 'RADIUS no respondió: no se puede confirmar' : 'Servicios DHCP conectados' ?></span>
                <span class="cc-kpi-fuente">Fuente: servicios (RADIUS) · no incluye PPPoE</span>
            </a>

            <a class="cc-kpi cc-kpi--pagos" href="pagos_hoy.php">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/></svg></span>
                    <span class="cc-kpi-nombre">Pagos registrados hoy</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($pagos_hoy) ?></strong>
                <span class="cc-kpi-detalle">
                    <?= $importe_pagos_hoy !== null ? '$' . number_format($importe_pagos_hoy, 2) . ' cobrados hoy' : 'Importe no disponible' ?>
                </span>
                <span class="cc-kpi-fuente cc-tendencia <?= e_tablero($clase_tendencia_pagos) ?>"><?= e_tablero($texto_tendencia_pagos) ?></span>
            </a>

            <a class="cc-kpi cc-kpi--proximos" href="adeudos.php?situacion=por_vencer">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 13v3l2 1"/></svg></span>
                    <span class="cc-kpi-nombre">Próximas a vencer</span>
                </div>
                <strong class="cc-kpi-numero"><?= $total_proximos !== null ? number_format($total_proximos) : '—' ?></strong>
                <span class="cc-kpi-detalle"><?= $error_proximos ? 'No disponible: revisar conexión' : 'Mensualidades que vencen hoy o en los próximos 10 días' ?></span>
                <span class="cc-kpi-fuente">Fuente: mensualidades y pagos registrados</span>
            </a>

            <a class="cc-kpi cc-kpi--suspendidos" href="suspendidos.php">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>
                    <span class="cc-kpi-nombre">Servicios suspendidos</span>
                </div>
                <strong class="cc-kpi-numero"><?= $servicios_suspendidos !== null ? number_format($servicios_suspendidos) : '—' ?></strong>
                <span class="cc-kpi-detalle"><?= $error_servicios ? 'RADIUS no respondió: no se puede confirmar' : 'Servicios DHCP cortados' ?></span>
                <span class="cc-kpi-fuente">Fuente: servicios (RADIUS) · no incluye PPPoE</span>
            </a>

        </div>

        <div class="graficas-servicios cc-graficas">

            <!-- Ingresos de la semana -->
            <div class="panel cc-panel">
                <div class="panel-titulo encabezado-grafica-ingresos">
                    <div class="titulo-grafica titulo-ingresos-limpio">
                        <span class="icono-circular-ingresos" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                            <svg class="icono-calendario-control" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                                <path d="M16 3v4M8 3v4M3 10h18"></path>
                            </svg>
                            <span class="texto-rango-semana">
                                <?php
                                $meses_cortos = [1 => 'ene.', 2 => 'feb.', 3 => 'mar.', 4 => 'abr.', 5 => 'may.', 6 => 'jun.',
                                    7 => 'jul.', 8 => 'ago.', 9 => 'sep.', 10 => 'oct.', 11 => 'nov.', 12 => 'dic.'];
                                echo e_tablero(
                                    $inicio_semana->format('j') . ' ' . $meses_cortos[(int)$inicio_semana->format('n')] . ' ' . $inicio_semana->format('Y')
                                    . ' – ' .
                                    $fin_semana->format('j') . ' ' . $meses_cortos[(int)$fin_semana->format('n')] . ' ' . $fin_semana->format('Y')
                                );
                                ?>
                            </span>
                            <svg class="flecha-selector-semana" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </label>
                        <input id="fecha-semana" type="date" name="semana"
                               value="<?= e_tablero($fecha_seleccionada) ?>"
                               aria-label="Elegir una fecha para consultar su semana"
                               onchange="this.form.submit()">
                    </form>
                </div>

                <div class="resumen-ingresos-semana resumen-ingresos-doble resumen-ingresos-unico">
                    <div class="resumen-fuente">
                        <span>Total de la semana</span>
                        <strong>$<?= number_format($total_ingresos_semana, 2) ?></strong>
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
                            <?php
                            $dias_es = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
                            foreach ($ingresos_semana as $fecha => $importe_dia):
                                $altura_dia = $maximo_eje_ingresos > 0
                                    ? (int)round($importe_dia / $maximo_eje_ingresos * 150) : 0;
                                $fecha_obj = new DateTimeImmutable($fecha);
                                $etiqueta_dia = $dias_es[(int)$fecha_obj->format('N') - 1];
                                $detalle = $fecha . ' | $' . number_format($importe_dia, 2);
                            ?>
                            <div class="barra-vertical grupo-barras-dia" title="<?= e_tablero($detalle) ?>">
                                <div class="barras-dobles">
                                    <div class="barra-fuente barra-uisp barra-unica"
                                         style="height: <?= $altura_dia ?>px !important;"
                                         title="$<?= number_format($importe_dia, 2) ?>">
                                    </div>
                                </div>
                                <span class="etiqueta-fecha"><?= $etiqueta_dia ?><br><?= $fecha_obj->format('d/m') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribución de servicios DHCP según Grezz -->
            <div class="panel cc-panel">
                <div class="panel-titulo titulo-estados-pago">
                    <span class="icono-circular-ingresos" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16v16H4z"></path>
                            <path d="M8 8h8M8 12h5"></path>
                            <path d="m8 16 2 2 5-5"></path>
                        </svg>
                    </span>
                    <h3>Distribución de servicios DHCP</h3>
                </div>

                <?php if ($error_grafica_servicios): ?>
                    <p class="cc-vacio">RADIUS no respondió: no se puede mostrar la distribución.</p>
                <?php else: ?>
                <div class="grafica-circular dona-ubb">
                    <div class="dona-ubb-visual"
                         role="img"
                         aria-label="Distribución de <?= $total_estados ?> servicios DHCP: <?= $servicios_estado["ACTIVO"] ?> activos, <?= $servicios_estado["SUSPENDIDO"] ?> suspendidos y <?= $servicios_estado["SIN_DATOS"] ?> sin estado."
                         style="background: conic-gradient(
                            #16a34a 0% <?= number_format($porcentaje_activo, 2, '.', '') ?>%,
                            #f0b429 <?= number_format($porcentaje_activo, 2, '.', '') ?>% <?= number_format($porcentaje_acumulado, 2, '.', '') ?>%,
                            #94a3b8 <?= number_format($porcentaje_acumulado, 2, '.', '') ?>% 100%
                         );">
                        <div class="dona-ubb-centro">
                            <strong><?= number_format($total_estados) ?></strong>
                            <span>Servicios en RADIUS</span>
                        </div>
                    </div>

                    <!-- Porcentajes: las cantidades de activos y suspendidos están en el resumen. -->
                    <div class="leyenda-pastel leyenda-ubb">
                        <div class="item-leyenda item-leyenda-ubb">
                            <span class="punto-leyenda pagado"></span>
                            <div class="detalle-leyenda-ubb">
                                <span class="nombre-estado-ubb">Activos</span>
                                <strong><?= number_format($porcentaje_activo, 1) ?>%</strong>
                            </div>
                        </div>
                        <div class="item-leyenda item-leyenda-ubb">
                            <span class="punto-leyenda pendiente"></span>
                            <div class="detalle-leyenda-ubb">
                                <span class="nombre-estado-ubb">Suspendidos</span>
                                <strong><?= number_format($porcentaje_suspendido, 1) ?>%</strong>
                            </div>
                        </div>
                        <div class="item-leyenda item-leyenda-ubb">
                            <span class="punto-leyenda cancelado"></span>
                            <div class="detalle-leyenda-ubb">
                                <span class="nombre-estado-ubb">Sin estado en RADIUS</span>
                                <strong><?= number_format($porcentaje_sin_datos, 1) ?>%</strong>
                                <small><?= number_format($servicios_estado["SIN_DATOS"]) ?> servicios</small>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </section>


    <!-- =====================================================
         2. MONITOR DE AUTOMATIZACIONES
         Procesos independientes: cada uno con su estado, cifras,
         pendientes y errores.
    ====================================================== -->
    <section class="cc-seccion" id="cc-monitor" aria-labelledby="cc-titulo-monitor">
        <div class="cc-seccion-titulo">
            <h2 id="cc-titulo-monitor">Monitor de automatizaciones</h2>
            <p>Cada proceso funciona por separado · Gracia: <?= UBB_DIAS_GRACIA ?> días · Corte: día <?= UBB_DIAS_GRACIA + 1 ?></p>
        </div>

        <div class="cc-procesos">

            <!-- Conciliación y pagos bancarios -->
            <article class="cc-proceso cc-proceso--<?= $proc_conc['clase'] ?>">
                <header class="cc-proceso-cabeza">
                    <span class="cc-proceso-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 10h18M5 10V20M19 10V20M9 10v10M15 10v10M3 20h18M12 3 3 8h18Z"/></svg></span>
                    <h3>Conciliación de pagos</h3>
                    <span class="cc-estado cc-estado--<?= $proc_conc['clase'] ?>"><?= e_tablero($proc_conc['texto']) ?></span>
                </header>
                <div class="cc-proceso-cifra">
                    <strong><?= numeroAuto($auto_conc['total'] ?? null) ?></strong>
                    <span>pagos del banco conciliados solos · 7 días</span>
                </div>
                <dl class="cc-filas">
                    <div><dt>Importe aplicado (7 días)</dt><dd><?= $auto_conc === null ? '—' : '$' . number_format((float)($auto_conc['importe'] ?? 0), 2) ?></dd></div>
                    <div><dt>Movimientos por conciliar</dt><dd><?= numeroAuto($auto_mov === null ? null : (int)($auto_mov['pendientes'] ?? 0)) ?></dd></div>
                    <div class="<?= (int)($auto_mov['revision'] ?? 0) > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Movimientos en revisión manual</dt><dd><?= numeroAuto($auto_mov === null ? null : (int)($auto_mov['revision'] ?? 0)) ?></dd></div>
                    <div><dt>Pagos de UISP aplicados (7 días)</dt><dd><?= numeroAuto($auto_uisp === null ? null : (int)($auto_uisp['aplicados_7d'] ?? 0)) ?></dd></div>
                    <div class="<?= (int)($auto_uisp['revision'] ?? 0) > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Pagos de UISP en revisión</dt><dd><?= numeroAuto($auto_uisp === null ? null : (int)($auto_uisp['revision'] ?? 0)) ?></dd></div>
                </dl>
                <footer class="cc-proceso-pie">
                    <span>Banco: <?= e_tablero(fechaRelativaTablero($ejec('CONCILIACION')['fecha_fin'] ?? null)) ?> · UISP: <?= e_tablero(fechaRelativaTablero($ejec('PAGOS_UISP')['fecha_fin'] ?? null)) ?></span>
                    <span class="cc-enlaces"><a href="conciliacion_bancaria.php">Banco</a><a href="revision_uisp.php">UISP</a></span>
                </footer>
            </article>

            <!-- Vencimientos y periodos de gracia -->
            <article class="cc-proceso cc-proceso--<?= $proc_venc['clase'] ?>">
                <header class="cc-proceso-cabeza">
                    <span class="cc-proceso-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                    <h3>Vencimientos y gracia</h3>
                    <span class="cc-estado cc-estado--<?= $proc_venc['clase'] ?>"><?= e_tablero($proc_venc['texto']) ?></span>
                </header>
                <div class="cc-proceso-cifra">
                    <strong><?= numeroAuto($auto_venc['en_gracia']) ?></strong>
                    <span>mensualidades vencidas dentro de la gracia (1 a <?= UBB_DIAS_GRACIA ?> días)</span>
                </div>
                <dl class="cc-filas">
                    <div><dt>Vencen hoy</dt><dd><?= numeroAuto($auto_venc['vencen_hoy']) ?></dd></div>
                    <div><dt>Vencen en 1 a <?= UBB_DIAS_GRACIA ?> días</dt><dd><?= numeroAuto($auto_venc['proximos']) ?></dd></div>
                    <div class="<?= (int)($auto_venc['fuera_gracia'] ?? 0) > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Fuera de gracia (pasan a corte)</dt><dd><?= numeroAuto($auto_venc['fuera_gracia']) ?></dd></div>
                </dl>
                <footer class="cc-proceso-pie">
                    <span>Mensualidades: <?= e_tablero(fechaRelativaTablero($proc_venc_ejec['fecha_fin'] ?? null)) ?></span>
                    <span class="cc-enlaces"><a href="adeudos.php?situacion=gracia">En gracia</a><a href="adeudos.php?situacion=vencido">Fuera de gracia</a></span>
                </footer>
            </article>

            <!-- Suspensiones y reactivaciones -->
            <article class="cc-proceso cc-proceso--<?= $proc_susp['clase'] ?>">
                <header class="cc-proceso-cabeza">
                    <span class="cc-proceso-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18.4 5.6a9 9 0 1 1-12.8 0"/><path d="M12 2v10"/></svg></span>
                    <h3>Suspensiones y reactivaciones</h3>
                    <span class="cc-estado cc-estado--<?= $proc_susp['clase'] ?>"><?= e_tablero($proc_susp['texto']) ?></span>
                </header>
                <div class="cc-proceso-cifra">
                    <strong><?= numeroAuto($auto_cortes['gestionables']) ?></strong>
                    <span>fuera de gracia y con servicio DHCP: corte automático</span>
                </div>
                <dl class="cc-filas">
                    <div><dt>Fuera de gracia ya cortados (DHCP)</dt><dd><?= numeroAuto($auto_cortes['cortados']) ?></dd></div>
                    <div class="<?= (int)($auto_cortes['manuales'] ?? 0) > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Fuera de gracia sin RADIUS: corte manual (PPPoE u otros)</dt><dd><?= numeroAuto($auto_cortes['manuales']) ?></dd></div>
                    <div><dt>Suspensiones solicitadas hoy</dt><dd><?= numeroAuto($auto_sol === null ? null : (int)($auto_sol['suspensiones_hoy'] ?? 0)) ?></dd></div>
                    <div><dt>Reactivaciones solicitadas hoy</dt><dd><?= numeroAuto($auto_sol === null ? null : (int)($auto_sol['reactivaciones_hoy'] ?? 0)) ?></dd></div>
                    <div><dt><a href="suspendidos.php?adeudo=sin">Cortados sin adeudo (sin datos del cliente u otro motivo)</a></dt><dd><?= numeroAuto($auto_cortes['suspendidos_sin_adeudo'] ?? null) ?></dd></div>
                </dl>
                <footer class="cc-proceso-pie">
                    <span><?= e_tablero(ultimaEjecucionTablero($proc_susp_ejec)) ?></span>
                    <span class="cc-enlaces"><a href="adeudos.php?situacion=vencido">Adeudos</a><a href="suspendidos.php">Suspendidos</a></span>
                </footer>
            </article>

            <!-- Integración con Grezz -->
            <article class="cc-proceso cc-proceso--<?= $proc_grezz['clase'] ?>">
                <header class="cc-proceso-cabeza">
                    <span class="cc-proceso-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/></svg></span>
                    <h3>Integración con servicios (RADIUS)</h3>
                    <span class="cc-estado cc-estado--<?= $proc_grezz['clase'] ?>"><?= e_tablero($proc_grezz['texto']) ?></span>
                </header>
                <div class="cc-proceso-cifra">
                    <strong><?= numeroAuto($grezz_semana === null ? null
                        : (int)($grezz_semana['completadas'] ?? 0) + (int)($grezz_semana['simuladas'] ?? 0)
                          + (int)($grezz_semana['rechazadas'] ?? 0) + (int)($grezz_semana['pendientes'] ?? 0)) ?></strong>
                    <span>solicitudes enviadas · 7 días</span>
                </div>
                <dl class="cc-filas">
                    <div><dt>Modo de operación</dt><dd><?= $grezz_simulacion ? 'Simulación (no corta)' : 'Real' ?></dd></div>
                    <div><dt>Completadas · simuladas (7 días)</dt><dd><?= numeroAuto($grezz_semana === null ? null : (int)($grezz_semana['completadas'] ?? 0)) ?> · <?= numeroAuto($grezz_semana === null ? null : (int)($grezz_semana['simuladas'] ?? 0)) ?></dd></div>
                    <div class="<?= $grezz_rechazadas > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Rechazadas por RADIUS (7 días)</dt><dd><?= numeroAuto($grezz_semana === null ? null : $grezz_rechazadas) ?></dd></div>
                    <div class="<?= $grezz_pendientes > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Sin confirmar por RADIUS</dt><dd><?= numeroAuto($auto_sol === null ? null : $grezz_pendientes) ?></dd></div>
                    <div><dt>Servicios DHCP reportados</dt><dd><?= $error_servicios ? '—' : number_format($total_estados) ?></dd></div>
                </dl>
                <footer class="cc-proceso-pie">
                    <span>Última solicitud: <?= e_tablero(fechaRelativaTablero($grezz_semana['ultima'] ?? null)) ?></span>
                    <span class="cc-enlaces"><a href="suspendidos.php">Ver servicios</a></span>
                </footer>
            </article>

            <!-- Notificaciones a clientes -->
            <article class="cc-proceso cc-proceso--<?= $proc_avisos['clase'] ?>">
                <header class="cc-proceso-cabeza">
                    <span class="cc-proceso-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
                    <h3>Notificaciones a clientes</h3>
                    <span class="cc-estado cc-estado--<?= $proc_avisos['clase'] ?>"><?= e_tablero($proc_avisos['texto']) ?></span>
                </header>
                <div class="cc-proceso-cifra">
                    <strong><?= numeroAuto($auto_avisos === null ? null : (int)($auto_avisos['enviados_hoy'] ?? 0)) ?></strong>
                    <span>avisos enviados hoy</span>
                </div>
                <dl class="cc-filas">
                    <div><dt>Por enviar</dt><dd><?= numeroAuto($auto_avisos === null ? null : (int)($auto_avisos['pendientes'] ?? 0)) ?></dd></div>
                    <div class="<?= (int)($auto_avisos['con_problema'] ?? 0) > 0 ? 'cc-fila--alerta' : '' ?>"><dt>Sin teléfono o con error</dt><dd><?= numeroAuto($auto_avisos === null ? null : (int)($auto_avisos['con_problema'] ?? 0)) ?></dd></div>
                    <div><dt>Recordatorios generados</dt><dd><?= e_tablero(fechaRelativaTablero($ejec('RECORDATORIOS')['fecha_fin'] ?? null)) ?></dd></div>
                </dl>
                <footer class="cc-proceso-pie">
                    <span>Envío: <?= e_tablero(fechaRelativaTablero($ejec('NOTIFICACIONES')['fecha_fin'] ?? null)) ?></span>
                    <span class="cc-enlaces"><a href="notificaciones.php">Ver avisos</a></span>
                </footer>
            </article>

        </div>
    </section>


    <!-- =====================================================
         3. ACTIVIDAD RECIENTE
    ====================================================== -->
    <section class="cc-seccion" aria-labelledby="cc-titulo-actividad">
        <div class="cc-seccion-titulo cc-seccion-titulo--con-filtros">
            <div>
                <h2 id="cc-titulo-actividad">Actividad reciente</h2>
                <p>Últimos <?= count($actividad) ?> movimientos de todos los procesos</p>
            </div>
            <div class="cc-filtros" role="group" aria-label="Filtrar actividad">
                <button type="button" class="cc-filtro" data-filtro="" aria-pressed="true">Todo</button>
                <button type="button" class="cc-filtro" data-filtro="pago conciliacion" aria-pressed="false">Pagos</button>
                <button type="button" class="cc-filtro" data-filtro="conciliacion revision" aria-pressed="false">Conciliación</button>
                <button type="button" class="cc-filtro" data-filtro="suspension reactivacion" aria-pressed="false">Cortes y reconexiones</button>
                <button type="button" class="cc-filtro" data-filtro="notificacion" aria-pressed="false">Notificaciones</button>
                <button type="button" class="cc-filtro" data-filtro="error" aria-pressed="false">Errores</button>
            </div>
        </div>

        <div class="cc-panel cc-actividad">
            <?php if (!$actividad): ?>
                <p class="cc-vacio">Todavía no hay movimientos registrados.</p>
            <?php else: ?>
            <ol class="cc-linea" id="ccLinea">
                <?php foreach ($actividad as $ev): ?>
                    <li class="cc-evento cc-evento--<?= e_tablero($ev['tipo']) ?>" data-tipo="<?= e_tablero($ev['tipo']) ?>">
                        <span class="cc-evento-icono" aria-hidden="true">
                            <?php switch ($ev['tipo']):
                                case 'pago': ?><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19"/></svg><?php break;
                                case 'conciliacion': ?><svg viewBox="0 0 24 24"><path d="M3 10h18M12 3 3 8h18ZM5 10v8M19 10v8M3 20h18"/></svg><?php break;
                                case 'revision': ?><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><?php break;
                                case 'suspension': ?><svg viewBox="0 0 24 24"><path d="M18.4 5.6a9 9 0 1 1-12.8 0"/><path d="M12 2v10"/></svg><?php break;
                                case 'reactivacion': ?><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/></svg><?php break;
                                case 'notificacion': ?><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><?php break;
                                default: ?><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg><?php
                            endswitch; ?>
                        </span>
                        <div class="cc-evento-cuerpo">
                            <div class="cc-evento-linea1">
                                <strong><?= e_tablero($ev['titulo']) ?></strong>
                                <span class="cc-estado cc-estado--<?= e_tablero($ev['etiqueta_clase']) ?>"><?= e_tablero($ev['etiqueta']) ?></span>
                            </div>
                            <div class="cc-evento-linea2">
                                <?php if ($ev['cliente']): ?>
                                    <a href="ver_cliente.php?id=<?= (int)$ev['id_cliente'] ?>"><?= e_tablero($ev['cliente']) ?></a>
                                    <?php if ($ev['id_servicio']): ?>· servicio <?= (int)$ev['id_servicio'] ?><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($ev['detalle'] !== ''): ?>
                                    <span class="cc-evento-detalle"><?= $ev['cliente'] ? '· ' : '' ?><?= e_tablero($ev['detalle']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <time class="cc-evento-fecha" datetime="<?= e_tablero($ev['fecha']) ?>"><?= e_tablero(fechaRelativaTablero($ev['fecha'], $ev['solo_fecha'])) ?></time>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="cc-vacio" id="ccLineaVacia" hidden>No hay movimientos de este tipo entre los recientes.</p>
            <?php endif; ?>
        </div>
    </section>


    <!-- =====================================================
         ACCESOS DIRECTOS
    ====================================================== -->
    <nav class="cc-accesos" aria-label="Accesos directos">
        <span>Accesos directos</span>
        <?php if (puedeModificar()): ?><a href="registrar.php">Registrar pago</a><?php endif; ?>
        <a href="historial.php">Historial de pagos</a>
        <a href="reporte_uisp.php">Historial UISP</a>
        <a href="reporte_semanal.php">Reporte semanal</a>
    </nav>

</main>

<script>
// Filtros de la actividad reciente.
(() => {
    const botones = document.querySelectorAll('.cc-filtro');
    const eventos = document.querySelectorAll('#ccLinea .cc-evento');
    const vacio = document.getElementById('ccLineaVacia');

    botones.forEach((boton) => {
        boton.addEventListener('click', () => {
            const tipos = boton.dataset.filtro ? boton.dataset.filtro.split(' ') : null;
            let visibles = 0;

            botones.forEach((b) => b.setAttribute('aria-pressed', String(b === boton)));

            eventos.forEach((ev) => {
                const mostrar = !tipos || tipos.includes(ev.dataset.tipo);
                ev.hidden = !mostrar;
                visibles += mostrar ? 1 : 0;
            });

            if (vacio) vacio.hidden = visibles > 0;
        });
    });
})();
</script>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->



</body>
</html>