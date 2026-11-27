<?php
require_once __DIR__ . "/includes/sesion.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");

/*
 * ADEUDOS
 * Todos los servicios con mensualidades sin pagar, en una sola lista para
 * cobranza. Reemplaza a proximos.php y vencidos.php (que ahora redirigen aquí).
 *
 * Fuentes (solo lectura):
 *   - ubb_pagos: mensualidades (obligaciones_pago) y pagos aplicados. Misma
 *     regla que el tablero y la suspensión automática (estado_pagos.php).
 *   - ubb_plataforma: datos del cliente y del servicio. Se excluyen las bajas.
 *   - RADIUS (API de Grezz): si el servicio sigue conectado o está cortado.
 */

function escAdeudo($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

const ADEUDOS_SITUACIONES = [
    "por_vencer" => "Por vencer (incluye hoy)",
    "hoy" => "Vence hoy",
    "gracia" => "En plazo de gracia",
    "vencido" => "Fuera de gracia",
];

const ADEUDOS_ESTADOS_RED = [
    "con_servicio" => "Con servicio",
    "cortado" => "Cortado",
    "sin_dato" => "Sin dato en RADIUS",
];

$adeudos = [];
$detalleMensualidades = [];
$ultimoPago = [];
$errorCarga = null;
$estadosRed = null;

$totales = [
    "importe_vencido" => 0.0,
    "importe_por_vencer" => 0.0,
    "clientes_deben" => [],
    "clientes_dos_meses" => [],
];
$conteoSituacion = ["por_vencer" => 0, "hoy" => 0, "gracia" => 0, "vencido" => 0];

require_once __DIR__ . "/includes/estado_pagos.php";

/* Estado del servicio en RADIUS (si no responde, la lista se muestra igual). */
try {
    require_once __DIR__ . "/api/cliente_grezz.php";
    $consultaGrezz = listarServiciosGrezz();

    if (
        !empty($consultaGrezz["transporte_ok"]) &&
        (int)($consultaGrezz["codigo_http"] ?? 0) === 200 &&
        is_array($consultaGrezz["respuesta"]["servicios"] ?? null)
    ) {
        $estadosRed = [];
        foreach ($consultaGrezz["respuesta"]["servicios"] as $servicioGrezz) {
            $id = filter_var($servicioGrezz["id_servicio"] ?? null, FILTER_VALIDATE_INT);
            if ($id && is_string($servicioGrezz["estado_actual"] ?? null)) {
                $estadosRed[(int)$id] = strtoupper($servicioGrezz["estado_actual"]);
            }
        }
    } else {
        error_log("Adeudos: RADIUS no respondió: " . ($consultaGrezz["error"] ?? "respuesta inválida"));
    }
} catch (Throwable $e) {
    error_log("Adeudos: no se pudo consultar RADIUS: " . $e->getMessage());
}

try {
    $conexion_plataforma = require __DIR__ . "/conexion_plataforma.php";
    require_once __DIR__ . "/conexion.php";

    $estadoPagos = estadoPagosPorServicio($conexion);

    // Mensualidades ya vencidas (fecha anterior a hoy) por servicio: cuántas
    // y cuánto falta pagar de ellas.
    $vencidasPorServicio = [];
    $r = $conexion->query("
        SELECT o.id_servicio,
               COUNT(*) AS meses,
               SUM(o.monto - COALESCE(a.total_pagado, 0)) AS saldo
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT po.id_obligacion, SUM(po.monto_aplicado) AS total_pagado
            FROM pagos_obligaciones po
            INNER JOIN pagos p ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.concepto = 'MENSUALIDAD'
          AND o.estado = 'PENDIENTE'
          AND o.fecha_vencimiento IS NOT NULL
          AND DATE(o.fecha_vencimiento) < CURDATE()
          AND o.monto > COALESCE(a.total_pagado, 0)
        GROUP BY o.id_servicio
    ");
    while ($f = $r->fetch_assoc()) {
        $vencidasPorServicio[(int)$f["id_servicio"]] = [
            "meses" => (int)$f["meses"],
            "saldo" => (float)$f["saldo"],
        ];
    }

    // Detalle para la ventana "Detalles": cada mensualidad con saldo, de la
    // más antigua a la más reciente (misma regla que estado_pagos.php).
    $hoy = new DateTimeImmutable("today");
    $r = $conexion->query("
        SELECT o.id_servicio,
               o.periodo,
               o.fecha_vencimiento,
               o.referencia_cobro,
               o.monto,
               COALESCE(a.total_pagado, 0) AS pagado
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT po.id_obligacion, SUM(po.monto_aplicado) AS total_pagado
            FROM pagos_obligaciones po
            INNER JOIN pagos p ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.concepto = 'MENSUALIDAD'
          AND o.estado = 'PENDIENTE'
          AND o.fecha_vencimiento IS NOT NULL
          AND o.monto > COALESCE(a.total_pagado, 0)
        ORDER BY o.fecha_vencimiento ASC, o.id_obligacion ASC
    ");
    while ($f = $r->fetch_assoc()) {
        $vence = new DateTimeImmutable(substr((string)$f["fecha_vencimiento"], 0, 10));
        $dif = (int)$hoy->diff($vence)->format("%r%a");
        [$estadoMes, $claseMes] = match (true) {
            $dif > UBB_DIAS_GRACIA => ["Aún no vence", "futuro"],
            $dif > 0 => ["Por vencer", "proximo"],
            $dif === 0 => ["Vence hoy", "hoy"],
            -$dif <= UBB_DIAS_GRACIA => ["En gracia", "gracia"],
            default => ["Fuera de gracia", "vencido"],
        };
        $detalleMensualidades[(int)$f["id_servicio"]][] = [
            "periodo" => (string)$f["periodo"],
            "vence" => $vence->format("d/m/Y"),
            "monto" => (float)$f["monto"],
            "pagado" => (float)$f["pagado"],
            "saldo" => (float)$f["monto"] - (float)$f["pagado"],
            "referencia" => (string)$f["referencia_cobro"],
            "estado" => $estadoMes,
            "clase" => $claseMes,
        ];
    }

    // Último pago confirmado de cada servicio.
    $r = $conexion->query("
        SELECT p.id_servicio, p.fecha_pago, p.monto, p.metodo_pago
        FROM pagos p
        INNER JOIN (
            SELECT id_servicio, MAX(fecha_pago) AS ultima
            FROM pagos
            WHERE estado_pago = 'PAGADO'
            GROUP BY id_servicio
        ) u ON u.id_servicio = p.id_servicio AND u.ultima = p.fecha_pago
        WHERE p.estado_pago = 'PAGADO'
    ");
    while ($f = $r->fetch_assoc()) {
        $ultimoPago[(int)$f["id_servicio"]] = [
            "fecha" => date("d/m/Y", strtotime((string)$f["fecha_pago"])),
            "monto" => (float)$f["monto"],
            "metodo" => (string)$f["metodo_pago"],
        ];
    }

    $resultado = $conexion_plataforma->query("
        SELECT
            s.id_servicio,
            s.id_cliente,
            c.nombre,
            c.apellido_paterno,
            c.apellido_materno,
            c.telefono,
            d.calle,
            d.numero_exterior,
            d.numero_interior,
            d.colonia,
            d.municipio,
            d.codigo_postal,
            s.paquete_referencia
        FROM servicios s
        LEFT JOIN clientes c ON c.id_cliente = s.id_cliente
        LEFT JOIN direcciones_servicio d
            ON d.id_direccion = s.id_direccion
           AND d.id_cliente = s.id_cliente
        WHERE COALESCE(s.estado, '') <> 'BAJA'
    ");

    while ($fila = $resultado->fetch_assoc()) {
        $idServicio = (int)$fila["id_servicio"];
        $ciclo = $estadoPagos[$idServicio] ?? null;

        if ($ciclo === null || $ciclo["tipo"] === "futuro") {
            continue;
        }

        $nombre = trim(implode(" ", array_filter([
            $fila["nombre"] ?? "", $fila["apellido_paterno"] ?? "", $fila["apellido_materno"] ?? "",
        ])));
        $vencidas = $vencidasPorServicio[$idServicio] ?? ["meses" => 0, "saldo" => 0.0];

        $estadoGrezz = $estadosRed[$idServicio] ?? null;
        $red = match ($estadoGrezz) {
            "ACTIVO" => "con_servicio",
            "SUSPENDIDO" => "cortado",
            default => "sin_dato",
        };

        $direccion = implode(", ", array_filter([
            $fila["calle"] ?? "",
            !empty($fila["numero_exterior"]) ? "No. " . $fila["numero_exterior"] : "",
            !empty($fila["numero_interior"]) ? "Int. " . $fila["numero_interior"] : "",
            $fila["colonia"] ?? "",
            $fila["municipio"] ?? "",
            !empty($fila["codigo_postal"]) ? "C.P. " . $fila["codigo_postal"] : "",
        ], fn($v) => trim((string)$v) !== ""));

        $adeudos[] = [
            "id_servicio" => $idServicio,
            "id_cliente" => (int)$fila["id_cliente"],
            "nombre" => $nombre !== "" ? $nombre : "Sin datos del cliente",
            "telefono" => $fila["telefono"] ?: "",
            "direccion" => $direccion,
            "paquete" => trim((string)($fila["paquete_referencia"] ?? "")),
            "ciclo" => $ciclo,
            "meses_vencidos" => $vencidas["meses"],
            "saldo_vencido" => $vencidas["saldo"],
            "red" => $red,
        ];

        $tipo = $ciclo["tipo"];
        $conteoSituacion[$tipo === "proximo" ? "por_vencer" : $tipo]++;
        if ($tipo === "hoy") {
            $conteoSituacion["por_vencer"]++;
        }

        if ($vencidas["saldo"] > 0) {
            $totales["importe_vencido"] += $vencidas["saldo"];
            $totales["clientes_deben"][(int)$fila["id_cliente"] ?: "s$idServicio"] = true;
            if ($vencidas["meses"] >= 2) {
                $totales["clientes_dos_meses"][(int)$fila["id_cliente"] ?: "s$idServicio"] = true;
            }
        } else {
            $totales["importe_por_vencer"] += (float)$ciclo["saldo"];
        }
    }

    // Primero los más atrasados.
    $orden = ["vencido" => 1, "gracia" => 2, "hoy" => 3, "proximo" => 4];
    usort($adeudos, fn($a, $b) =>
        ($orden[$a["ciclo"]["tipo"]] <=> $orden[$b["ciclo"]["tipo"]])
        ?: ($a["ciclo"]["fecha_pago"] <=> $b["ciclo"]["fecha_pago"])
        ?: strcmp($a["nombre"], $b["nombre"])
    );

} catch (Throwable $e) {
    error_log("Error en módulo Adeudos: " . $e->getMessage());
    $errorCarga = "No fue posible consultar los adeudos. Intenta nuevamente más tarde.";
}

// Datos de la ventana "Detalles", por servicio.
$datosDetalle = [];
foreach ($adeudos as $a) {
    $datosDetalle[$a["id_servicio"]] = [
        "nombre" => $a["nombre"],
        "telefono" => $a["telefono"],
        "direccion" => $a["direccion"],
        "id_cliente" => $a["id_cliente"],
        "id_servicio" => $a["id_servicio"],
        "paquete" => $a["paquete"],
        "situacion" => $a["ciclo"]["situacion"],
        "tipo" => $a["ciclo"]["tipo"],
        "dias" => $a["ciclo"]["dias"],
        "red" => ADEUDOS_ESTADOS_RED[$a["red"]],
        "red_clase" => $a["red"],
        "meses_vencidos" => $a["meses_vencidos"],
        "saldo_vencido" => $a["saldo_vencido"],
        "saldo_total" => (float)$a["ciclo"]["saldo_total"],
        "mensualidades" => $detalleMensualidades[$a["id_servicio"]] ?? [],
        "ultimo_pago" => $ultimoPago[$a["id_servicio"]] ?? null,
    ];
}

$paquetes = array_values(array_unique(array_filter(array_column($adeudos, "paquete"))));
natcasesort($paquetes);

// Filtro inicial desde la URL (enlaces del tablero y de las páginas anteriores).
$situacionInicial = (string)($_GET["situacion"] ?? "");
if (!isset(ADEUDOS_SITUACIONES[$situacionInicial])) {
    $situacionInicial = "";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adeudos - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/vencidos.css?v=<?php echo filemtime(__DIR__ . '/css/vencidos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist adeudos">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
            <div>
                <h1>Adeudos</h1>
                <p>Servicios con mensualidades sin pagar, según los pagos registrados y conciliados. Gracia: <?= UBB_DIAS_GRACIA ?> días; se suspenden el día <?= UBB_DIAS_GRACIA + 1 ?>.</p>
            </div>
        </div>
    </header>


    <!-- =================================================
         RESUMEN
    ================================================== -->

    <div class="cc-kpis hist-kpis adeudos-kpis">

        <div class="cc-kpi cc-kpi--suspendidos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                <span class="cc-kpi-nombre">Total adeudado</span>
            </div>
            <strong class="cc-kpi-numero"><?= $errorCarga ? "—" : "$" . number_format($totales["importe_vencido"], 2) ?></strong>
            <span class="cc-kpi-detalle">Mensualidades vencidas</span>
        </div>

        <div class="cc-kpi cc-kpi--pagos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 3.2a4 4 0 0 1 0 7.6M20 21v-2a4 4 0 0 0-3-3.9"/></svg></span>
                <span class="cc-kpi-nombre">Clientes que deben</span>
            </div>
            <strong class="cc-kpi-numero"><?= $errorCarga ? "—" : number_format(count($totales["clientes_deben"])) ?></strong>
            <span class="cc-kpi-detalle">Con al menos una mensualidad vencida</span>
        </div>

        <div class="cc-kpi cc-kpi--proximos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>
                <span class="cc-kpi-nombre">Deben 2 meses o más</span>
            </div>
            <strong class="cc-kpi-numero"><?= $errorCarga ? "—" : number_format(count($totales["clientes_dos_meses"])) ?></strong>
            <span class="cc-kpi-detalle">Clientes con mayor atraso</span>
        </div>

        <div class="cc-kpi hist-kpi--confirmados">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 13v3l2 1"/></svg></span>
                <span class="cc-kpi-nombre">Por vencer</span>
            </div>
            <strong class="cc-kpi-numero"><?= $errorCarga ? "—" : "$" . number_format($totales["importe_por_vencer"], 2) ?></strong>
            <span class="cc-kpi-detalle">Aún sin atraso</span>
        </div>

    </div>


    <!-- =================================================
         FILTROS (se aplican al momento, sin recargar)
    ================================================== -->

    <div class="cc-panel hist-filtros">

        <div class="hist-buscador adeudos-buscador">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="buscarAdeudo"
                   placeholder="Buscar cliente, teléfono, servicio, paquete o referencia..."
                   aria-label="Buscar adeudo"
                   autocomplete="off">
        </div>

        <label class="hist-campo">
            <span>Situación</span>
            <select id="filtroSituacion">
                <option value="">Todas (<?= number_format(count($adeudos)) ?>)</option>
                <?php foreach (ADEUDOS_SITUACIONES as $clave => $texto): ?>
                    <option value="<?= $clave ?>" <?= $situacionInicial === $clave ? "selected" : "" ?>>
                        <?= escAdeudo($texto) ?> (<?= number_format($conteoSituacion[$clave]) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="hist-campo">
            <span>Atraso</span>
            <select id="filtroMeses">
                <option value="">Cualquier atraso</option>
                <option value="1">Al menos 1 mes vencido</option>
                <option value="2">2 meses vencidos o más</option>
            </select>
        </label>

        <label class="hist-campo">
            <span>Servicio (RADIUS)</span>
            <select id="filtroRed">
                <option value="">Con y sin servicio</option>
                <?php foreach (ADEUDOS_ESTADOS_RED as $clave => $texto): ?>
                    <option value="<?= $clave ?>"><?= escAdeudo($texto) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="hist-campo">
            <span>Paquete</span>
            <select id="filtroPaquete">
                <option value="">Todos los paquetes</option>
                <?php foreach ($paquetes as $paquete): ?>
                    <option value="<?= escAdeudo($paquete) ?>"><?= escAdeudo($paquete) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="button" class="hist-boton adeudos-exportar" id="exportarAdeudos">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
            Exportar a Excel
        </button>

        <span class="adeudos-ayuda">Los filtros se aplican al momento y la exportación descarga solo lo que ves.</span>

    </div>

    <?php if ($estadosRed === null && !$errorCarga): ?>
        <p class="px-aviso-grezz" role="status">
            No se pudo consultar RADIUS: no se sabe cuáles de estos servicios ya están cortados.
            Los adeudos se calculan igual con los pagos registrados.
        </p>
    <?php endif; ?>


    <!-- =================================================
         TABLA
    ================================================== -->

    <section class="cc-panel hist-tabla">

        <div class="hist-tabla-scroll">
            <table id="tablaAdeudos">
                <thead>
                    <tr>
                        <th class="adeudos-col-cliente">Cliente</th>
                        <th class="adeudos-col-servicio">Servicio</th>
                        <th>Situación</th>
                        <th>Desde</th>
                        <th class="px-centro">Meses vencidos</th>
                        <th class="hist-num">Adeudo</th>
                        <th>Servicio (RADIUS)</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($errorCarga): ?>
                    <tr><td colspan="8" class="hist-vacio"><?= escAdeudo($errorCarga) ?></td></tr>
                <?php elseif (!$adeudos): ?>
                    <tr><td colspan="8" class="hist-vacio">No hay servicios con mensualidades pendientes.</td></tr>
                <?php else: ?>
                    <?php foreach ($adeudos as $a):
                        $ciclo = $a["ciclo"];
                        $desde = $ciclo["fecha_pago"] ? $ciclo["fecha_pago"]->format("d/m/Y") : "Sin información";
                        $importe = $a["saldo_vencido"] > 0
                            ? "$" . number_format($a["saldo_vencido"], 2)
                            : "$" . number_format((float)$ciclo["saldo"], 2) . " por vencer";
                        $situacionFiltro = in_array($ciclo["tipo"], ["proximo", "hoy"], true) ? "por_vencer " . $ciclo["tipo"] : $ciclo["tipo"];
                        $busqueda = implode(" ", [$a["nombre"], $a["telefono"], $a["id_cliente"], $a["id_servicio"], $a["paquete"], $ciclo["referencia"]]);
                    ?>
                    <tr data-registro="1"
                        data-busqueda="<?= escAdeudo($busqueda) ?>"
                        data-situacion="<?= escAdeudo($situacionFiltro) ?>"
                        data-meses="<?= (int)$a["meses_vencidos"] ?>"
                        data-red="<?= escAdeudo($a["red"]) ?>"
                        data-paquete="<?= escAdeudo($a["paquete"]) ?>"
                        data-exportar="<?= escAdeudo(json_encode([
                            $a["nombre"], $a["telefono"], $a["id_cliente"], $a["id_servicio"], $a["paquete"],
                            $ciclo["situacion"], $desde, $a["meses_vencidos"],
                            number_format($a["saldo_vencido"], 2, ".", ""),
                            number_format((float)$ciclo["saldo_total"], 2, ".", ""),
                            $ciclo["referencia"], ADEUDOS_ESTADOS_RED[$a["red"]], $a["direccion"],
                        ], JSON_UNESCAPED_UNICODE)) ?>">
                        <td class="hist-cliente adeudos-col-cliente">
                            <strong><?= escAdeudo($a["nombre"]) ?></strong>
                            <?php if ($a["telefono"]): ?><span><?= escAdeudo($a["telefono"]) ?></span><?php endif; ?>
                        </td>
                        <td class="hist-cliente">
                            <strong>#<?= (int)$a["id_servicio"] ?></strong>
                            <?php if ($a["paquete"]): ?><span><?= escAdeudo($a["paquete"]) ?></span><?php endif; ?>
                            <?php if ($ciclo["referencia"]): ?><span class="adeudos-ref" title="Referencia de pago"><?= escAdeudo($ciclo["referencia"]) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <span class="px-estado px-estado-<?= escAdeudo($ciclo["tipo"]) ?>"><?= escAdeudo($ciclo["situacion"]) ?></span>
                            <small class="px-subtexto"><?= escAdeudo($ciclo["dias"]) ?></small>
                        </td>
                        <td class="hist-fecha"><?= escAdeudo($desde) ?></td>
                        <td class="px-centro">
                            <?php if ($a["meses_vencidos"] > 0): ?>
                                <span class="px-meses <?= $a["meses_vencidos"] >= 2 ? "px-meses-alto" : "" ?>"><?= (int)$a["meses_vencidos"] ?></span>
                            <?php else: ?>
                                <span class="hist-suave">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="hist-num"><strong class="<?= $a["saldo_vencido"] > 0 ? "px-importe-vencido" : "px-importe-por-vencer" ?>"><?= escAdeudo($importe) ?></strong></td>
                        <td>
                            <span class="px-estado px-red-<?= escAdeudo($a["red"]) ?>"><?= escAdeudo(ADEUDOS_ESTADOS_RED[$a["red"]]) ?></span>
                        </td>
                        <td>
                            <div class="hist-acciones adeudos-acciones">
                                <button type="button" class="hist-boton hist-boton--principal" data-detalle="<?= (int)$a["id_servicio"] ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    Detalles
                                </button>
                                <?php if ($a["id_cliente"] > 0): ?>
                                    <a class="hist-boton" href="ver_cliente.php?id=<?= (int)$a["id_cliente"] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                                        Cliente
                                    </a>
                                <?php else: ?>
                                    <a class="hist-boton" href="ver_servicio.php?id=<?= (int)$a["id_servicio"] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><path d="M12 19.5h.01"/></svg>
                                        Servicio
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p class="hist-vacio" id="adeudosSinResultados" hidden>Ningún adeudo coincide con los filtros.</p>
        </div>

        <div class="adeudos-pie">
            Mostrando <strong id="adeudosVisibles"><?= count($adeudos) ?></strong> de <?= count($adeudos) ?> servicios
            · Adeudo de lo mostrado: <strong id="adeudosImporteVisible">—</strong>
        </div>

    </section>

</main>


<!-- =================================================
     VENTANA DE DETALLES
================================================== -->

<dialog class="cc adeudo-detalle"id="adeudoDetalle" aria-labelledby="adeudoDetalleTitulo">
    <header class="adeudo-detalle-cabecera">
        <div>
            <span class="adeudo-detalle-etiqueta">Detalle del adeudo</span>
            <h2 id="adeudoDetalleTitulo"></h2>
            <p id="adeudoDetalleSub"></p>
        </div>
        <button type="button" class="adeudo-detalle-cerrar" data-cerrar aria-label="Cerrar">×</button>
    </header>

    <div class="adeudo-detalle-cuerpo">
        <div class="adeudo-detalle-resumen">
            <div><span>Adeudo vencido</span><strong id="adDetVencido"></strong></div>
            <div><span>Saldo pendiente total</span><strong id="adDetTotal"></strong></div>
            <div><span>Meses vencidos</span><strong id="adDetMeses"></strong></div>
            <div><span>Situación</span><strong id="adDetSituacion"></strong></div>
        </div>

        <dl class="adeudo-detalle-datos">
            <div><dt>Teléfono</dt><dd id="adDetTelefono"></dd></div>
            <div><dt>Paquete</dt><dd id="adDetPaquete"></dd></div>
            <div><dt>Servicio (RADIUS)</dt><dd id="adDetRed"></dd></div>
            <div><dt>Último pago</dt><dd id="adDetUltimoPago"></dd></div>
            <div class="adeudo-detalle-ancho"><dt>Dirección</dt><dd id="adDetDireccion"></dd></div>
        </dl>

        <h3>Mensualidades pendientes</h3>
        <div class="hist-tabla-scroll">
            <table class="adeudo-detalle-tabla">
                <thead>
                    <tr>
                        <th>Periodo</th>
                        <th>Vence</th>
                        <th>Estado</th>
                        <th class="hist-num">Monto</th>
                        <th class="hist-num">Pagado</th>
                        <th class="hist-num">Saldo</th>
                        <th>Referencia</th>
                    </tr>
                </thead>
                <tbody id="adDetMensualidades"></tbody>
            </table>
        </div>
    </div>

    <footer class="adeudo-detalle-pie">
        <a class="hist-boton" id="adDetCliente" href="#">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
            Ver cliente
        </a>
        <a class="hist-boton" id="adDetServicio" href="#">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><path d="M12 19.5h.01"/></svg>
            Ver servicio
        </a>
        <button type="button" class="hist-boton hist-boton--principal" data-cerrar>Cerrar</button>
    </footer>
</dialog>

<script type="application/json" id="adeudosDatosDetalle"><?= json_encode($datosDetalle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<script>
// Ventana "Detalles": se llena con los datos ya cargados, sin otra consulta.
(() => {
    const ventana = document.getElementById("adeudoDetalle");
    const fuente = document.getElementById("adeudosDatosDetalle");
    if (!ventana || !fuente) return;

    const datos = JSON.parse(fuente.textContent || "{}");
    const dinero = new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" });
    const $ = id => document.getElementById(id);
    const texto = (id, valor) => { $(id).textContent = valor || "—"; };

    function etiqueta(textoEtiqueta, clase) {
        const span = document.createElement("span");
        span.className = "px-estado " + clase;
        span.textContent = textoEtiqueta;
        return span;
    }

    function abrir(id) {
        const d = datos[id];
        if (!d) return;

        texto("adeudoDetalleTitulo", d.nombre);
        texto("adeudoDetalleSub", "Servicio #" + d.id_servicio + (d.id_cliente ? " · Cliente #" + d.id_cliente : ""));
        texto("adDetVencido", dinero.format(d.saldo_vencido));
        texto("adDetTotal", dinero.format(d.saldo_total));
        texto("adDetMeses", String(d.meses_vencidos));
        $("adDetSituacion").replaceChildren(etiqueta(d.situacion, "px-estado-" + d.tipo));
        $("adDetSituacion").title = d.dias || "";
        texto("adDetTelefono", d.telefono);
        texto("adDetPaquete", d.paquete);
        $("adDetRed").replaceChildren(etiqueta(d.red, "px-red-" + d.red_clase));
        texto("adDetUltimoPago", d.ultimo_pago
            ? d.ultimo_pago.fecha + " · " + dinero.format(d.ultimo_pago.monto) + " · " + d.ultimo_pago.metodo
            : "Sin pagos registrados");
        texto("adDetDireccion", d.direccion);

        const cuerpo = $("adDetMensualidades");
        cuerpo.replaceChildren();
        d.mensualidades.forEach(m => {
            const fila = cuerpo.insertRow();
            fila.insertCell().textContent = m.periodo || "—";
            fila.insertCell().textContent = m.vence;
            fila.insertCell().append(etiqueta(m.estado, "px-estado-" + m.clase));
            [m.monto, m.pagado, m.saldo].forEach(v => {
                const c = fila.insertCell();
                c.className = "hist-num";
                c.textContent = dinero.format(v);
            });
            const ref = fila.insertCell();
            ref.className = "hist-referencia";
            ref.textContent = m.referencia || "—";
        });
        if (!d.mensualidades.length) {
            const c = cuerpo.insertRow().insertCell();
            c.colSpan = 7;
            c.className = "hist-vacio";
            c.textContent = "Sin mensualidades pendientes.";
        }

        const cliente = $("adDetCliente");
        cliente.hidden = !(d.id_cliente > 0);
        cliente.href = "ver_cliente.php?id=" + encodeURIComponent(d.id_cliente);
        $("adDetServicio").href = "ver_servicio.php?id=" + encodeURIComponent(d.id_servicio);

        ventana.showModal();
    }

    document.addEventListener("click", e => {
        const boton = e.target.closest("[data-detalle]");
        if (boton) abrir(boton.dataset.detalle);
    });

    ventana.querySelectorAll("[data-cerrar]").forEach(b => b.addEventListener("click", () => ventana.close()));
    // Cerrar al hacer clic fuera de la ventana.
    ventana.addEventListener("click", e => { if (e.target === ventana) ventana.close(); });
})();
</script>

<script>
(() => {
    const tabla = document.getElementById("tablaAdeudos");
    if (!tabla) return;

    const filas = [...tabla.querySelectorAll("tbody tr[data-registro]")];
    const buscador = document.getElementById("buscarAdeudo");
    const situacion = document.getElementById("filtroSituacion");
    const meses = document.getElementById("filtroMeses");
    const red = document.getElementById("filtroRed");
    const paquete = document.getElementById("filtroPaquete");
    const contador = document.getElementById("adeudosVisibles");
    const importeVisible = document.getElementById("adeudosImporteVisible");
    const sinResultados = document.getElementById("adeudosSinResultados");
    const formato = new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" });

    function visibles() {
        return filas.filter(f => !f.hidden);
    }

    function aplicar() {
        const texto = (buscador.value || "").toLocaleLowerCase("es").trim();
        let total = 0;
        let n = 0;

        filas.forEach(f => {
            const ok = (f.dataset.busqueda || "").toLocaleLowerCase("es").includes(texto)
                && (!situacion.value || f.dataset.situacion.split(" ").includes(situacion.value))
                && (!meses.value || Number(f.dataset.meses) >= Number(meses.value))
                && (!red.value || f.dataset.red === red.value)
                && (!paquete.value || f.dataset.paquete === paquete.value);

            f.hidden = !ok;
            if (ok) {
                n++;
                total += Number(JSON.parse(f.dataset.exportar)[8]) || 0;
            }
        });

        contador.textContent = n;
        importeVisible.textContent = formato.format(total);
        sinResultados.hidden = n > 0 || filas.length === 0;
    }

    [buscador].forEach(el => el.addEventListener("input", aplicar));
    [situacion, meses, red, paquete].forEach(el => el.addEventListener("change", aplicar));

    // Exportar lo visible a un CSV que Excel abre directamente (UTF-8 con BOM).
    document.getElementById("exportarAdeudos").addEventListener("click", () => {
        const encabezados = ["Cliente", "Teléfono", "ID cliente", "ID servicio", "Paquete",
            "Situación", "Desde", "Meses vencidos", "Adeudo vencido", "Saldo pendiente total",
            "Referencia", "Servicio (RADIUS)", "Dirección"];
        const celda = v => '"' + String(v ?? "").replace(/"/g, '""') + '"';
        const lineas = [encabezados.map(celda).join(",")];

        visibles().forEach(f => lineas.push(JSON.parse(f.dataset.exportar).map(celda).join(",")));

        const blob = new Blob(["﻿" + lineas.join("\r\n")], { type: "text/csv;charset=utf-8" });
        const enlace = document.createElement("a");
        enlace.href = URL.createObjectURL(blob);
        enlace.download = "adeudos_" + new Date().toISOString().slice(0, 10) + ".csv";
        document.body.append(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);
    });

    aplicar();
})();
</script>

</body>
</html>
