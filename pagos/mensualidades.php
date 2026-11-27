<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");

/*
 * MENSUALIDADES
 * Todas las mensualidades de un mes (pagadas, pendientes y canceladas) y, sobre
 * todo, los servicios que deberían tener mensualidad ese mes y no la tienen:
 * si la generación automática falla (cron, conexión con UISP, cliente sin
 * ligar), el servicio no aparece en Adeudos y nadie se entera.
 *
 * Solo lectura:
 *   - ubb_pagos: obligaciones_pago, pagos aplicados y servicios_cortes.
 *   - ubb_plataforma: clientes y servicios (nombre, paquete, cómo se factura).
 *
 * Origen de cada mensualidad:
 *   - id_factura       → factura de la plataforma (sincronizar_facturas_plataforma.php)
 *   - uisp_invoice_id  → factura de UISP (mensualidades_desde_uisp.php)
 *   - ninguno          → precio del plan (simular_obligaciones.php --generar)
 */

function escMens($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

// Primer mes con mensualidades en UBB (igual que UBB_MENSUALIDADES_UISP_DESDE).
const MENS_PRIMER_MES = "2026-11";

const MENS_ESTADOS = [
    "PAGADA" => "Pagada",
    "PARCIAL" => "Pago parcial",
    "PENDIENTE" => "Pendiente",
    "CANCELADA" => "Cancelada",
];

const MENS_ORIGENES = [
    "plataforma" => "Factura plataforma",
    "uisp" => "Factura UISP",
    "plan" => "Precio del plan",
];

const MENS_MOTIVOS_NO_COBRO = [
    "MES_DEL_CORTE" => "No se cobra: mes del corte",
    "SERVICIO_CORTADO" => "No se cobra: servicio cortado",
];

$meses = ["", "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
          "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

$hoy = new DateTimeImmutable("today");

// Meses disponibles: del primero con mensualidades al siguiente del actual.
$opcionesMes = [];
$cursor = new DateTimeImmutable(MENS_PRIMER_MES . "-01");
$ultimo = $hoy->modify("first day of next month");
while ($cursor <= $ultimo) {
    $opcionesMes[$cursor->format("Y-m")] = ucfirst($meses[(int)$cursor->format("n")]) . " " . $cursor->format("Y");
    $cursor = $cursor->modify("+1 month");
}

$mesElegido = (string)($_GET["mes"] ?? "");
if (!isset($opcionesMes[$mesElegido])) {
    $mesElegido = isset($opcionesMes[$hoy->format("Y-m")]) ? $hoy->format("Y-m") : array_key_first($opcionesMes);
}
$inicioMes = new DateTimeImmutable($mesElegido . "-01");
$finMes = $inicioMes->modify("last day of this month");
$periodo = $inicioMes->format("Ym");
$nombreMes = $opcionesMes[$mesElegido];

$mensualidades = [];
$faltantes = [];
$errorCarga = null;
$totales = [
    "generadas" => 0,
    "importe" => 0.0,
    "cobrado" => 0.0,
    "por_cobrar" => 0.0,
    "canceladas" => 0,
];
$conteoEstado = array_fill_keys(array_keys(MENS_ESTADOS), 0);
$conteoOrigen = array_fill_keys(array_keys(MENS_ORIGENES), 0);

try {
    $conexion_plataforma = require __DIR__ . "/conexion_plataforma.php";
    require_once __DIR__ . "/conexion.php";

    // Columnas que agregan las migraciones (por si alguna base aún no las tiene).
    $columnas = [];
    foreach (["id_factura", "uisp_invoice_id", "no_cobro_motivo", "cargo_reconexion"] as $col) {
        $columnas[$col] = $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE '$col'")->num_rows > 0;
    }
    $sel = fn($col, $defecto = "NULL") => $columnas[$col] ? "o.$col" : "$defecto AS $col";
    $columnasOpcionales = implode(", ", [
        $sel("id_factura"), $sel("uisp_invoice_id"), $sel("no_cobro_motivo"), $sel("cargo_reconexion", "0"),
    ]);

    // Clientes y servicios de la plataforma.
    $servicios = [];
    $r = $conexion_plataforma->query("
        SELECT s.id_servicio, s.id_cliente, s.estado, s.fecha_instalacion, s.paquete_referencia,
               COALESCE(s.factura_en_plataforma, 0) AS factura_en_plataforma,
               c.nombre, c.apellido_paterno, c.apellido_materno, c.telefono,
               TRIM(COALESCE(c.uisp_id, '')) AS uisp_id
        FROM servicios s
        LEFT JOIN clientes c ON c.id_cliente = s.id_cliente
    ");
    while ($f = $r->fetch_assoc()) {
        $nombre = trim(implode(" ", array_filter([
            $f["nombre"] ?? "", $f["apellido_paterno"] ?? "", $f["apellido_materno"] ?? "",
        ])));
        $servicios[(int)$f["id_servicio"]] = [
            "id_cliente" => (int)$f["id_cliente"],
            "nombre" => $nombre !== "" ? $nombre : "Sin datos del cliente",
            "telefono" => (string)($f["telefono"] ?? ""),
            "paquete" => trim((string)($f["paquete_referencia"] ?? "")),
            "estado" => (string)($f["estado"] ?? ""),
            "instalacion" => (string)($f["fecha_instalacion"] ?? ""),
            "plataforma" => (int)$f["factura_en_plataforma"] === 1,
            "uisp_id" => (string)$f["uisp_id"],
        ];
    }

    // Mensualidades del mes con lo pagado (sin cortesías) y lo cubierto por cortesía.
    $stmt = $conexion->prepare("
        SELECT o.id_obligacion, o.id_cliente, o.id_servicio, o.monto, o.fecha_vencimiento,
               o.referencia_cobro, o.estado,
               $columnasOpcionales,
               COALESCE(a.pagado, 0) AS pagado,
               COALESCE(a.cortesia, 0) AS cortesia
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT po.id_obligacion,
                   SUM(CASE WHEN COALESCE(p.metodo_pago, '') <> 'CORTESIA' THEN po.monto_aplicado ELSE 0 END) AS pagado,
                   SUM(CASE WHEN COALESCE(p.metodo_pago, '') = 'CORTESIA' THEN po.monto_aplicado ELSE 0 END) AS cortesia
            FROM pagos_obligaciones po
            INNER JOIN pagos p ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.concepto = 'MENSUALIDAD'
          AND o.periodo = ?
        ORDER BY o.fecha_vencimiento, o.id_obligacion
    ");
    $stmt->bind_param("s", $periodo);
    $stmt->execute();
    $r = $stmt->get_result();

    $serviciosConMensualidad = [];
    while ($f = $r->fetch_assoc()) {
        $idServicio = (int)$f["id_servicio"];
        $serviciosConMensualidad[$idServicio] = true;
        $servicio = $servicios[$idServicio] ?? null;

        $monto = (float)$f["monto"];
        $pagado = (float)$f["pagado"];
        $cortesia = (float)$f["cortesia"];
        $saldo = max(0.0, $monto - $pagado - $cortesia);

        if ($f["estado"] === "CANCELADA") {
            $estado = "CANCELADA";
        } elseif ($f["estado"] === "PAGADA" || $saldo <= 0.004) {
            $estado = "PAGADA";
        } elseif ($pagado + $cortesia > 0) {
            $estado = "PARCIAL";
        } else {
            $estado = "PENDIENTE";
        }

        $origen = $f["id_factura"] !== null ? "plataforma" : ($f["uisp_invoice_id"] !== null ? "uisp" : "plan");

        $nota = "";
        if ($estado === "CANCELADA") {
            $nota = MENS_MOTIVOS_NO_COBRO[$f["no_cobro_motivo"] ?? ""] ?? "Cancelada";
        } elseif ($cortesia > 0) {
            $nota = "Cortesía: $" . number_format($cortesia, 2);
        }
        if ((float)$f["cargo_reconexion"] > 0) {
            $nota = trim($nota . " · Incluye reconexión $" . number_format((float)$f["cargo_reconexion"], 2), " ·");
        }

        $mensualidades[] = [
            "id_servicio" => $idServicio,
            "id_cliente" => (int)$f["id_cliente"],
            "nombre" => $servicio["nombre"] ?? "Servicio sin datos en la plataforma",
            "telefono" => $servicio["telefono"] ?? "",
            "paquete" => $servicio["paquete"] ?? "",
            "monto" => $monto,
            "pagado" => $pagado,
            "saldo" => $estado === "CANCELADA" ? 0.0 : $saldo,
            "vencimiento" => $f["fecha_vencimiento"] ? new DateTimeImmutable(substr((string)$f["fecha_vencimiento"], 0, 10)) : null,
            "referencia" => (string)$f["referencia_cobro"],
            "estado" => $estado,
            "origen" => $origen,
            "nota" => $nota,
        ];

        $conteoEstado[$estado]++;
        $conteoOrigen[$origen]++;
        if ($estado === "CANCELADA") {
            $totales["canceladas"]++;
        } else {
            $totales["generadas"]++;
            $totales["importe"] += $monto;
            $totales["cobrado"] += $pagado;
            $totales["por_cobrar"] += $saldo;
        }
    }
    $stmt->close();

    // Cortes abiertos (o que tocan el mes), para explicar por qué falta una mensualidad.
    $cortados = [];
    if ($conexion->query("SHOW TABLES LIKE 'servicios_cortes'")->num_rows > 0) {
        $inicio = $inicioMes->format("Y-m-d");
        $fin = $finMes->format("Y-m-d");
        $stmt = $conexion->prepare("
            SELECT id_servicio, MIN(fecha_inicio) AS desde
            FROM servicios_cortes
            WHERE anulado = 0
              AND fecha_inicio <= ?
              AND (fecha_fin IS NULL OR fecha_fin >= ?)
            GROUP BY id_servicio
        ");
        $stmt->bind_param("ss", $fin, $inicio);
        $stmt->execute();
        $rc = $stmt->get_result();
        while ($f = $rc->fetch_assoc()) {
            $cortados[(int)$f["id_servicio"]] = new DateTimeImmutable((string)$f["desde"]);
        }
        $stmt->close();
    }

    // Servicios que deberían tener mensualidad en el mes y no tienen ninguna
    // (ni pagada, ni pendiente, ni cancelada): no son baja y se instalaron
    // a más tardar el último día del mes.
    foreach ($servicios as $idServicio => $s) {
        if (isset($serviciosConMensualidad[$idServicio]) || strtoupper($s["estado"]) === "BAJA") {
            continue;
        }
        $instalacion = ($s["instalacion"] !== "" && $s["instalacion"] !== "0000-00-00")
            ? new DateTimeImmutable(substr($s["instalacion"], 0, 10)) : null;
        if ($instalacion && $instalacion > $finMes) {
            continue;
        }

        if ($s["plataforma"]) {
            $esperado = "plataforma";
            $pista = "Sin factura de la plataforma para este mes";
        } elseif ($s["uisp_id"] !== "") {
            $esperado = "uisp";
            $pista = "Sin factura de UISP para este mes (o aún no se sincroniza)";
        } else {
            $esperado = "plan";
            $pista = "Cliente sin ligar a UISP: solo se genera por plan, a mano";
        }
        if (!$instalacion) {
            $pista .= " · Sin fecha de instalación";
        } elseif ($instalacion >= $inicioMes) {
            $pista .= " · Instalado el " . $instalacion->format("d/m/Y");
        }
        if (isset($cortados[$idServicio])) {
            $pista .= " · Cortado desde el " . $cortados[$idServicio]->format("d/m/Y");
        }

        $faltantes[] = [
            "id_servicio" => $idServicio,
            "id_cliente" => $s["id_cliente"],
            "nombre" => $s["nombre"],
            "telefono" => $s["telefono"],
            "paquete" => $s["paquete"],
            "estado_servicio" => $s["estado"] !== "" ? $s["estado"] : "—",
            "esperado" => $esperado,
            "uisp_id" => $s["uisp_id"],
            "cortado" => isset($cortados[$idServicio]),
            "pista" => $pista,
        ];
    }

    usort($faltantes, fn($a, $b) =>
        ($a["cortado"] <=> $b["cortado"]) ?: strcmp($a["esperado"], $b["esperado"]) ?: strcmp($a["nombre"], $b["nombre"])
    );

    // Pendientes primero, luego parciales, pagadas y canceladas.
    $orden = ["PENDIENTE" => 1, "PARCIAL" => 2, "PAGADA" => 3, "CANCELADA" => 4];
    usort($mensualidades, fn($a, $b) =>
        ($orden[$a["estado"]] <=> $orden[$b["estado"]])
        ?: ($a["vencimiento"] <=> $b["vencimiento"])
        ?: strcmp($a["nombre"], $b["nombre"])
    );
} catch (Throwable $e) {
    error_log("Error en módulo Mensualidades: " . $e->getMessage());
    $errorCarga = "No fue posible consultar las mensualidades. Intenta nuevamente más tarde.";
}

$faltantesSinCorte = count(array_filter($faltantes, fn($f) => !$f["cortado"]));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensualidades - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/vencidos.css?v=<?php echo filemtime(__DIR__ . '/css/vencidos.css'); ?>">
</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor proximos-page">
    <section class="px-panel">
        <header class="px-encabezado">
            <div class="px-titulo">
                <div class="px-icono" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h3M8 18h6"/>
                    </svg>
                </div>
                <div>
                    <span class="px-etiqueta">COBRANZA</span>
                    <h1>Mensualidades de <?= escMens(mb_strtolower($nombreMes, "UTF-8")) ?></h1>
                    <p>Todas las mensualidades del mes y los servicios que no tienen una. Se generan solas desde las facturas de UISP y de la plataforma; las de precio del plan, a mano.</p>
                </div>
            </div>
            <a href="index.php" class="px-regresar">← Regresar al centro de control</a>
        </header>

        <form class="px-mes" method="get">
            <label for="mes">Mes</label>
            <select id="mes" name="mes" onchange="this.form.submit()">
                <?php foreach ($opcionesMes as $clave => $texto): ?>
                    <option value="<?= escMens($clave) ?>" <?= $clave === $mesElegido ? "selected" : "" ?>><?= escMens($texto) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit">Ver</button></noscript>
        </form>

        <div class="px-resumen">
            <div class="px-indicador">
                <span>Mensualidades generadas</span>
                <strong><?= $errorCarga ? "—" : number_format($totales["generadas"]) ?></strong>
                <?php if (!$errorCarga && $totales["canceladas"]): ?><small class="px-subtexto">y <?= number_format($totales["canceladas"]) ?> canceladas por corte</small><?php endif; ?>
            </div>
            <div class="px-indicador px-indicador-info">
                <span>Importe del mes</span>
                <strong><?= $errorCarga ? "—" : "$" . number_format($totales["importe"], 2) ?></strong>
            </div>
            <div class="px-indicador px-indicador-red-con_servicio">
                <span>Cobrado</span>
                <strong><?= $errorCarga ? "—" : "$" . number_format($totales["cobrado"], 2) ?></strong>
            </div>
            <div class="px-indicador px-indicador-red-cortado">
                <span>Por cobrar</span>
                <strong><?= $errorCarga ? "—" : "$" . number_format($totales["por_cobrar"], 2) ?></strong>
            </div>
            <a class="px-indicador <?= $faltantesSinCorte ? "px-indicador-red-cortado" : "px-indicador-red-con_servicio" ?>" href="#faltantes">
                <span>Servicios sin mensualidad</span>
                <strong><?= $errorCarga ? "—" : number_format(count($faltantes)) ?></strong>
                <?php if (!$errorCarga && count($faltantes) > $faltantesSinCorte): ?><small class="px-subtexto"><?= number_format(count($faltantes) - $faltantesSinCorte) ?> están cortados</small><?php endif; ?>
            </a>
        </div>

        <div class="px-filtros">
            <label class="px-busqueda">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="m20 20-4-4"/>
                </svg>
                <input type="search" id="buscarMensualidad"
                       placeholder="Buscar cliente, teléfono, servicio, paquete o referencia..."
                       autocomplete="off">
            </label>

            <div class="px-filtros-secundarios">
                <select id="filtroEstado" aria-label="Filtrar por estado">
                    <option value="">Todos los estados (<?= number_format(count($mensualidades)) ?>)</option>
                    <?php foreach (MENS_ESTADOS as $clave => $texto): ?>
                        <option value="<?= $clave ?>"><?= escMens($texto) ?> (<?= number_format($conteoEstado[$clave]) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <select id="filtroOrigen" aria-label="Filtrar por origen">
                    <option value="">Todos los orígenes</option>
                    <?php foreach (MENS_ORIGENES as $clave => $texto): ?>
                        <option value="<?= $clave ?>"><?= escMens($texto) ?> (<?= number_format($conteoOrigen[$clave]) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="px-btn-exportar" id="exportarMensualidades">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                    Exportar a Excel
                </button>
            </div>
            <span class="px-ayuda">Los filtros se aplican al momento y la exportación descarga solo lo que ves.</span>
        </div>

        <div class="px-tabla-contenedor">
            <table class="px-tabla" id="tablaMensualidades">
                <thead>
                    <tr>
                        <th>CLIENTE</th>
                        <th>SERVICIO</th>
                        <th>ESTADO</th>
                        <th>VENCE</th>
                        <th>IMPORTE</th>
                        <th>PAGADO</th>
                        <th>SALDO</th>
                        <th>ORIGEN</th>
                        <th>REFERENCIA</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($errorCarga): ?>
                    <tr><td colspan="10" class="px-vacio"><?= escMens($errorCarga) ?></td></tr>
                <?php elseif (!$mensualidades): ?>
                    <tr><td colspan="10" class="px-vacio">No hay mensualidades generadas para <?= escMens(mb_strtolower($nombreMes, "UTF-8")) ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($mensualidades as $m):
                        $vence = $m["vencimiento"] ? $m["vencimiento"]->format("d/m/Y") : "—";
                        $busqueda = implode(" ", [$m["nombre"], $m["telefono"], $m["id_cliente"], $m["id_servicio"], $m["paquete"], $m["referencia"]]);
                        $claseEstado = [
                            "PAGADA" => "px-red-con_servicio",
                            "PARCIAL" => "px-estado-gracia",
                            "PENDIENTE" => "px-estado-proximo",
                            "CANCELADA" => "px-estado-revisar",
                        ][$m["estado"]];
                    ?>
                    <tr data-registro="1"
                        data-busqueda="<?= escMens($busqueda) ?>"
                        data-estado="<?= escMens($m["estado"]) ?>"
                        data-origen="<?= escMens($m["origen"]) ?>"
                        data-saldo="<?= number_format($m["saldo"], 2, ".", "") ?>"
                        data-exportar="<?= escMens(json_encode([
                            $m["nombre"], $m["telefono"], $m["id_cliente"], $m["id_servicio"], $m["paquete"],
                            MENS_ESTADOS[$m["estado"]], $vence,
                            number_format($m["monto"], 2, ".", ""),
                            number_format($m["pagado"], 2, ".", ""),
                            number_format($m["saldo"], 2, ".", ""),
                            MENS_ORIGENES[$m["origen"]], $m["referencia"], $m["nota"],
                        ], JSON_UNESCAPED_UNICODE)) ?>">
                        <td>
                            <strong class="px-nombre"><?= escMens($m["nombre"]) ?></strong>
                            <?php if ($m["telefono"]): ?><small class="px-subtexto"><?= escMens($m["telefono"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= (int)$m["id_servicio"] ?>
                            <?php if ($m["paquete"]): ?><small class="px-subtexto"><?= escMens($m["paquete"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="px-estado <?= $claseEstado ?>"><?= escMens(MENS_ESTADOS[$m["estado"]]) ?></span>
                            <?php if ($m["nota"]): ?><small class="px-subtexto"><?= escMens($m["nota"]) ?></small><?php endif; ?>
                        </td>
                        <td><?= escMens($vence) ?></td>
                        <td>$<?= number_format($m["monto"], 2) ?></td>
                        <td>$<?= number_format($m["pagado"], 2) ?></td>
                        <td><strong class="<?= $m["saldo"] > 0 ? "px-importe-vencido" : "px-importe-por-vencer" ?>">$<?= number_format($m["saldo"], 2) ?></strong></td>
                        <td><?= escMens(MENS_ORIGENES[$m["origen"]]) ?></td>
                        <td><code class="px-referencia"><?= escMens($m["referencia"] ?: "—") ?></code></td>
                        <td>
                            <?php if ($m["id_cliente"] > 0): ?>
                                <a class="px-btn-ver" href="ver_cliente.php?id=<?= (int)$m["id_cliente"] ?>">Ver cliente</a>
                            <?php else: ?>
                                <a class="px-btn-ver" href="ver_servicio.php?id=<?= (int)$m["id_servicio"] ?>">Ver servicio</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p class="px-vacio" id="mensualidadesSinResultados" hidden>Ninguna mensualidad coincide con los filtros.</p>
        </div>

        <div class="px-pie">
            Mostrando <strong id="mensualidadesVisibles"><?= count($mensualidades) ?></strong> de <?= count($mensualidades) ?> mensualidades
            · Saldo de lo mostrado: <strong id="mensualidadesSaldoVisible">—</strong>
        </div>
    </section>

    <section class="px-panel px-panel-faltantes" id="faltantes">
        <header class="px-encabezado">
            <div class="px-titulo">
                <div>
                    <span class="px-etiqueta">REVISIÓN</span>
                    <h1>Servicios sin mensualidad en <?= escMens(mb_strtolower($nombreMes, "UTF-8")) ?></h1>
                    <p>Servicios que no son baja, instalados a más tardar el <?= $finMes->format("d/m/Y") ?>, sin ninguna mensualidad del mes (ni pagada, ni pendiente, ni cancelada). Mientras no la tengan, no aparecen en Adeudos.</p>
                </div>
            </div>
            <?php if ($faltantes): ?>
                <button type="button" class="px-btn-exportar" id="exportarFaltantes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                    Exportar a Excel
                </button>
            <?php endif; ?>
        </header>

        <div class="px-tabla-contenedor">
            <table class="px-tabla" id="tablaFaltantes">
                <thead>
                    <tr>
                        <th>CLIENTE</th>
                        <th>SERVICIO</th>
                        <th>ESTADO EN PLATAFORMA</th>
                        <th>SE ESPERA DE</th>
                        <th>POSIBLE CAUSA</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($errorCarga): ?>
                    <tr><td colspan="6" class="px-vacio"><?= escMens($errorCarga) ?></td></tr>
                <?php elseif (!$faltantes): ?>
                    <tr><td colspan="6" class="px-vacio">Todos los servicios tienen su mensualidad de <?= escMens(mb_strtolower($nombreMes, "UTF-8")) ?>. ✔</td></tr>
                <?php else: ?>
                    <?php foreach ($faltantes as $f): ?>
                    <tr data-exportar="<?= escMens(json_encode([
                            $f["nombre"], $f["telefono"], $f["id_cliente"], $f["id_servicio"], $f["paquete"],
                            $f["estado_servicio"], MENS_ORIGENES[$f["esperado"]], $f["uisp_id"], $f["pista"],
                        ], JSON_UNESCAPED_UNICODE)) ?>">
                        <td>
                            <strong class="px-nombre"><?= escMens($f["nombre"]) ?></strong>
                            <?php if ($f["telefono"]): ?><small class="px-subtexto"><?= escMens($f["telefono"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= (int)$f["id_servicio"] ?>
                            <?php if ($f["paquete"]): ?><small class="px-subtexto"><?= escMens($f["paquete"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="px-estado <?= $f["cortado"] ? "px-red-cortado" : "px-estado-revisar" ?>"><?= escMens($f["cortado"] ? "Cortado" : $f["estado_servicio"]) ?></span>
                        </td>
                        <td>
                            <?= escMens(MENS_ORIGENES[$f["esperado"]]) ?>
                            <?php if ($f["uisp_id"] !== ""): ?><small class="px-subtexto">Cliente UISP <?= escMens($f["uisp_id"]) ?></small><?php endif; ?>
                        </td>
                        <td><?= escMens($f["pista"]) ?></td>
                        <td>
                            <?php if ($f["id_cliente"] > 0): ?>
                                <a class="px-btn-ver" href="ver_cliente.php?id=<?= (int)$f["id_cliente"] ?>">Ver cliente</a>
                            <?php else: ?>
                                <a class="px-btn-ver" href="ver_servicio.php?id=<?= (int)$f["id_servicio"] ?>">Ver servicio</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(() => {
    const formato = new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" });
    const mes = <?= json_encode($mesElegido) ?>;

    // Exporta filas a un CSV que Excel abre directamente (UTF-8 con BOM).
    function exportar(encabezados, filas, archivo) {
        const celda = v => '"' + String(v ?? "").replace(/"/g, '""') + '"';
        const lineas = [encabezados.map(celda).join(",")];
        filas.forEach(f => lineas.push(JSON.parse(f.dataset.exportar).map(celda).join(",")));

        const blob = new Blob(["﻿" + lineas.join("\r\n")], { type: "text/csv;charset=utf-8" });
        const enlace = document.createElement("a");
        enlace.href = URL.createObjectURL(blob);
        enlace.download = archivo;
        document.body.append(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);
    }

    const tabla = document.getElementById("tablaMensualidades");
    if (tabla) {
        const filas = [...tabla.querySelectorAll("tbody tr[data-registro]")];
        const buscador = document.getElementById("buscarMensualidad");
        const estado = document.getElementById("filtroEstado");
        const origen = document.getElementById("filtroOrigen");
        const contador = document.getElementById("mensualidadesVisibles");
        const saldoVisible = document.getElementById("mensualidadesSaldoVisible");
        const sinResultados = document.getElementById("mensualidadesSinResultados");

        function aplicar() {
            const texto = (buscador.value || "").toLocaleLowerCase("es").trim();
            let total = 0;
            let n = 0;

            filas.forEach(f => {
                const ok = (f.dataset.busqueda || "").toLocaleLowerCase("es").includes(texto)
                    && (!estado.value || f.dataset.estado === estado.value)
                    && (!origen.value || f.dataset.origen === origen.value);

                f.hidden = !ok;
                if (ok) {
                    n++;
                    total += Number(f.dataset.saldo) || 0;
                }
            });

            contador.textContent = n;
            saldoVisible.textContent = formato.format(total);
            sinResultados.hidden = n > 0 || filas.length === 0;
        }

        buscador.addEventListener("input", aplicar);
        [estado, origen].forEach(el => el.addEventListener("change", aplicar));

        document.getElementById("exportarMensualidades").addEventListener("click", () => exportar(
            ["Cliente", "Teléfono", "ID cliente", "ID servicio", "Paquete", "Estado", "Vence",
             "Importe", "Pagado", "Saldo", "Origen", "Referencia", "Nota"],
            filas.filter(f => !f.hidden),
            "mensualidades_" + mes + ".csv"
        ));

        aplicar();
    }

    const botonFaltantes = document.getElementById("exportarFaltantes");
    if (botonFaltantes) {
        botonFaltantes.addEventListener("click", () => exportar(
            ["Cliente", "Teléfono", "ID cliente", "ID servicio", "Paquete", "Estado en plataforma",
             "Se espera de", "Cliente UISP", "Posible causa"],
            [...document.querySelectorAll("#tablaFaltantes tbody tr[data-exportar]")],
            "servicios_sin_mensualidad_" + mes + ".csv"
        ));
    }
})();
</script>

</body>
</html>
