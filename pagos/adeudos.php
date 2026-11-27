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
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
                <div>
                    <span class="px-etiqueta">COBRANZA</span>
                    <h1>Adeudos</h1>
                    <p>Servicios con mensualidades sin pagar, según los pagos registrados y conciliados. Gracia: <?= UBB_DIAS_GRACIA ?> días; se suspenden el día <?= UBB_DIAS_GRACIA + 1 ?>.</p>
                </div>
            </div>
            <a href="index.php" class="px-regresar">← Regresar al centro de control</a>
        </header>

        <div class="px-resumen">
            <div class="px-indicador px-indicador-red-cortado">
                <span>Total adeudado (mensualidades vencidas)</span>
                <strong><?= $errorCarga ? "—" : "$" . number_format($totales["importe_vencido"], 2) ?></strong>
            </div>
            <div class="px-indicador">
                <span>Clientes que deben</span>
                <strong><?= $errorCarga ? "—" : number_format(count($totales["clientes_deben"])) ?></strong>
            </div>
            <div class="px-indicador px-indicador-red-cortado">
                <span>Deben 2 meses o más</span>
                <strong><?= $errorCarga ? "—" : number_format(count($totales["clientes_dos_meses"])) ?></strong>
            </div>
            <div class="px-indicador px-indicador-info">
                <span>Por vencer (aún sin atraso)</span>
                <strong><?= $errorCarga ? "—" : "$" . number_format($totales["importe_por_vencer"], 2) ?></strong>
            </div>
        </div>

        <div class="px-filtros">
            <label class="px-busqueda">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="m20 20-4-4"/>
                </svg>
                <input type="search" id="buscarAdeudo"
                       placeholder="Buscar cliente, teléfono, servicio, paquete o referencia..."
                       autocomplete="off">
            </label>

            <div class="px-filtros-secundarios">
                <select id="filtroSituacion" aria-label="Filtrar por situación">
                    <option value="">Todas las situaciones (<?= number_format(count($adeudos)) ?>)</option>
                    <?php foreach (ADEUDOS_SITUACIONES as $clave => $texto): ?>
                        <option value="<?= $clave ?>" <?= $situacionInicial === $clave ? "selected" : "" ?>>
                            <?= escAdeudo($texto) ?> (<?= number_format($conteoSituacion[$clave]) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="filtroMeses" aria-label="Filtrar por meses vencidos">
                    <option value="">Cualquier atraso</option>
                    <option value="1">Con al menos 1 mes vencido</option>
                    <option value="2">2 meses vencidos o más</option>
                </select>

                <select id="filtroRed" aria-label="Filtrar por estado del servicio en RADIUS">
                    <option value="">Con y sin servicio</option>
                    <?php foreach (ADEUDOS_ESTADOS_RED as $clave => $texto): ?>
                        <option value="<?= $clave ?>"><?= escAdeudo($texto) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filtroPaquete" aria-label="Filtrar por paquete">
                    <option value="">Todos los paquetes</option>
                    <?php foreach ($paquetes as $paquete): ?>
                        <option value="<?= escAdeudo($paquete) ?>"><?= escAdeudo($paquete) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="px-btn-exportar" id="exportarAdeudos">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                    Exportar a Excel
                </button>
            </div>
            <span class="px-ayuda">Los filtros se aplican al momento y la exportación descarga solo lo que ves.</span>
        </div>

        <?php if ($estadosRed === null && !$errorCarga): ?>
            <p class="px-aviso-grezz" role="status">
                No se pudo consultar RADIUS: no se sabe cuáles de estos servicios ya están cortados.
                Los adeudos se calculan igual con los pagos registrados.
            </p>
        <?php endif; ?>

        <div class="px-tabla-contenedor">
            <table class="px-tabla" id="tablaAdeudos">
                <thead>
                    <tr>
                        <th>CLIENTE</th>
                        <th>SERVICIO</th>
                        <th>SITUACIÓN</th>
                        <th>DESDE</th>
                        <th>MESES VENCIDOS</th>
                        <th>ADEUDO</th>
                        <th>REFERENCIA</th>
                        <th>SERVICIO (RADIUS)</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($errorCarga): ?>
                    <tr><td colspan="9" class="px-vacio"><?= escAdeudo($errorCarga) ?></td></tr>
                <?php elseif (!$adeudos): ?>
                    <tr><td colspan="9" class="px-vacio">No hay servicios con mensualidades pendientes.</td></tr>
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
                        <td>
                            <strong class="px-nombre"><?= escAdeudo($a["nombre"]) ?></strong>
                            <?php if ($a["telefono"]): ?><small class="px-subtexto"><?= escAdeudo($a["telefono"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= (int)$a["id_servicio"] ?>
                            <?php if ($a["paquete"]): ?><small class="px-subtexto"><?= escAdeudo($a["paquete"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="px-estado px-estado-<?= escAdeudo($ciclo["tipo"]) ?>"><?= escAdeudo($ciclo["situacion"]) ?></span>
                            <small class="px-subtexto"><?= escAdeudo($ciclo["dias"]) ?></small>
                        </td>
                        <td><?= escAdeudo($desde) ?></td>
                        <td class="px-centro">
                            <?php if ($a["meses_vencidos"] > 0): ?>
                                <span class="px-meses <?= $a["meses_vencidos"] >= 2 ? "px-meses-alto" : "" ?>"><?= (int)$a["meses_vencidos"] ?></span>
                            <?php else: ?>
                                <span class="px-subtexto">0</span>
                            <?php endif; ?>
                        </td>
                        <td><strong class="<?= $a["saldo_vencido"] > 0 ? "px-importe-vencido" : "px-importe-por-vencer" ?>"><?= escAdeudo($importe) ?></strong></td>
                        <td><code class="px-referencia"><?= escAdeudo($ciclo["referencia"] ?: "—") ?></code></td>
                        <td>
                            <span class="px-estado px-red-<?= escAdeudo($a["red"]) ?>"><?= escAdeudo(ADEUDOS_ESTADOS_RED[$a["red"]]) ?></span>
                        </td>
                        <td>
                            <?php if ($a["id_cliente"] > 0): ?>
                                <a class="px-btn-ver" href="ver_cliente.php?id=<?= (int)$a["id_cliente"] ?>">Ver cliente</a>
                            <?php else: ?>
                                <a class="px-btn-ver" href="ver_servicio.php?id=<?= (int)$a["id_servicio"] ?>">Ver servicio</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <p class="px-vacio" id="adeudosSinResultados" hidden>Ningún adeudo coincide con los filtros.</p>
        </div>

        <div class="px-pie">
            Mostrando <strong id="adeudosVisibles"><?= count($adeudos) ?></strong> de <?= count($adeudos) ?> servicios
            · Adeudo de lo mostrado: <strong id="adeudosImporteVisible">—</strong>
        </div>
    </section>
</main>

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
