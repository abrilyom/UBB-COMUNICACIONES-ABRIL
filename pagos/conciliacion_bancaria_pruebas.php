<?php
declare(strict_types=1);

// Pagina de pruebas: solo se muestra si el contenedor corre con
// UBB_PAGOS_ENTORNO=pruebas. En produccion responde "no disponible".
if (getenv('UBB_PAGOS_ENTORNO') !== 'pruebas') {
    http_response_code(404);
    exit('Página no disponible.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    require_once "/var/www/ubb_config/conexion_pruebas.php";
} catch (Throwable $e) {
    error_log("Error en conciliación de pruebas: " . $e->getMessage());
    http_response_code(503);
    exit("No fue posible conectar con la base de pruebas.");
}

$conexion = $conexion_pruebas;

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function dinero($valor, string $moneda = "MXN"): string {
    return "$" . number_format((float)$valor, 2) . " " . h($moneda);
}

function claseEstado(string $estado): string {
    $clases = [
        "PENDIENTE" => "pendiente",
        "REVISION" => "revision",
        "CONCILIADO" => "conciliado",
        "IGNORADO" => "ignorado"
    ];
    return $clases[$estado] ?? "pendiente";
}

/* Filtros */
$estado = $_GET["estado"] ?? "TODOS";
$buscar = trim($_GET["buscar"] ?? "");

$estadosValidos = [
    "TODOS", "PENDIENTE", "REVISION", "CONCILIADO", "IGNORADO"
];

if (!in_array($estado, $estadosValidos, true)) {
    $estado = "TODOS";
}

/* Indicadores */
$estadisticas = [
    "PENDIENTE" => 0,
    "REVISION" => 0,
    "CONCILIADO" => 0,
    "IGNORADO" => 0
];

$resumen = $conexion->query("
    SELECT estado_conciliacion, COUNT(*) AS total
    FROM movimientos_bancarios
    GROUP BY estado_conciliacion
");

while ($fila = $resumen->fetch_assoc()) {
    $estadisticas[$fila["estado_conciliacion"]] = (int)$fila["total"];
}

$totalMovimientos = array_sum($estadisticas);

$resumenImportes = $conexion->query("
    SELECT
        COALESCE(SUM(monto), 0) AS total,
        COALESCE(SUM(CASE WHEN estado_conciliacion = 'PENDIENTE'
            THEN monto ELSE 0 END), 0) AS pendiente,
        COALESCE(SUM(CASE WHEN estado_conciliacion = 'CONCILIADO'
            THEN monto ELSE 0 END), 0) AS conciliado
    FROM movimientos_bancarios
")->fetch_assoc();

/* Consulta de movimientos */
$sql = "
    SELECT
        id_movimiento, banco, cuenta_alias, fecha_movimiento,
        tipo_movimiento, monto, moneda, ordenante,
        referencia_bancaria, concepto, clave_rastreo,
        estado_conciliacion, id_pago
    FROM movimientos_bancarios
    WHERE 1 = 1
";

$parametros = [];
$tipos = "";

if ($estado !== "TODOS") {
    $sql .= " AND estado_conciliacion = ?";
    $parametros[] = $estado;
    $tipos .= "s";
}

if ($buscar !== "") {
    $sql .= " AND (
        banco LIKE ? OR ordenante LIKE ?
        OR referencia_bancaria LIKE ? OR concepto LIKE ?
        OR clave_rastreo LIKE ?
        OR CAST(id_movimiento AS CHAR) LIKE ?
    )";

    $termino = "%" . $buscar . "%";
    for ($i = 0; $i < 6; $i++) {
        $parametros[] = $termino;
        $tipos .= "s";
    }
}

$sql .= " ORDER BY fecha_movimiento DESC, id_movimiento DESC LIMIT 300";

$stmt = $conexion->prepare($sql);

if ($parametros) {
    $stmt->bind_param($tipos, ...$parametros);
}

$stmt->execute();
$movimientos = $stmt->get_result();

/* Detección de referencias */
function detectarReferencia(mysqli $conexion, string $texto): array
{
    $resultado = [
        'referencia' => '',
        'tipo' => 'SIN_REFERENCIA_UBB',
        'cliente' => null,
        'servicio' => null,
        'obligacion' => null
    ];

    $texto = strtoupper($texto);

    $patronMensual = '/(?<![A-Z0-9])(?:UBBC\d+S\d+M\d{8}|UBB-M-\d+-\d+-\d{6}(?:\d{2})?)(?![A-Z0-9])/';

    if (preg_match($patronMensual, $texto, $m)) {
        $referencia = $m[0];

        if (preg_match('/^UBBC(\d+)S(\d+)M(\d{8})$/', $referencia, $datos)) {
            $cliente = $datos[1];
            $servicio = $datos[2];
            $fechaReferencia = $datos[3];

            $anio = substr($fechaReferencia, 0, 4);
            $mes = substr($fechaReferencia, 4, 2);
            $dia = substr($fechaReferencia, 6, 2);

            if (!checkdate((int)$mes, (int)$dia, (int)$anio)) {
                return array_merge($resultado, [
                    'referencia' => $referencia,
                    'tipo' => 'FECHA_REFERENCIA_INVALIDA'
                ]);
            }

            $periodo = $anio . $mes;
        } elseif (preg_match('/^UBB-M-(\d+)-(\d+)-(\d{6})(\d{2})?$/', $referencia, $datos)) {
            $cliente = $datos[1];
            $servicio = $datos[2];
            $periodo = $datos[3];
            $fechaReferencia = $datos[4] ?? '';

            if ($fechaReferencia !== '') {
                $anio = substr($periodo, 0, 4);
                $mes = substr($periodo, 4, 2);

                if (!checkdate((int)$mes, (int)$fechaReferencia, (int)$anio)) {
                    return array_merge($resultado, [
                        'referencia' => $referencia,
                        'tipo' => 'FECHA_REFERENCIA_INVALIDA'
                    ]);
                }
            }
        } else {
            return $resultado;
        }

        $sql = "SELECT id_obligacion, id_cliente, id_servicio,
                       periodo, fecha_vencimiento, estado, monto
                FROM obligaciones_pago
                WHERE referencia_cobro = ?
                  AND id_cliente = ?
                  AND id_servicio = ?
                  AND periodo = ?
                LIMIT 1";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            return array_merge($resultado, [
                'referencia' => $referencia,
                'tipo' => 'ERROR'
            ]);
        }

        $stmt->bind_param("ssss", $referencia, $cliente, $servicio, $periodo);
        $stmt->execute();
        $obligacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$obligacion) {
            return [
                'referencia' => $referencia,
                'tipo' => 'MENSUAL_SIN_OBLIGACION',
                'cliente' => $cliente,
                'servicio' => $servicio,
                'obligacion' => null
            ];
        }

        if ($fechaReferencia !== '') {
            $vencimiento = date('Ymd', strtotime($obligacion['fecha_vencimiento']));

            if ($vencimiento !== str_replace('-', '', $fechaReferencia)) {
                return [
                    'referencia' => $referencia,
                    'tipo' => 'FECHA_VENCIMIENTO_NO_COINCIDE',
                    'cliente' => $cliente,
                    'servicio' => $servicio,
                    'obligacion' => $obligacion
                ];
            }
        }

        return [
            'referencia' => $referencia,
            'tipo' => 'MENSUAL',
            'cliente' => $cliente,
            'servicio' => $servicio,
            'obligacion' => $obligacion
        ];
    }

    $patronPermanente = '/(?<![A-Z0-9])(?:UBBC(\d+)S(\d+)|UBB-(\d+)-(\d+))(?![A-Z0-9])/';

    if (preg_match($patronPermanente, $texto, $m)) {
        $cliente = ($m[1] ?? '') !== '' ? $m[1] : ($m[3] ?? '');
        $servicio = ($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? '');

        return [
            'referencia' => $m[0],
            'tipo' => 'PERMANENTE_NO_VALIDADA',
            'cliente' => $cliente,
            'servicio' => $servicio,
            'obligacion' => null
        ];
    }

    return $resultado;
}


/* Auditoría reciente */
$auditoria = $conexion->query("
    SELECT id_auditoria, id_movimiento, id_pago,
           tipo_evento, fecha_evento, resultado, detalle
    FROM auditoria_conciliacion_bancaria
    ORDER BY fecha_evento DESC, id_auditoria DESC
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conciliación bancaria - Pruebas UBB</title>
<link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
<style>
.pruebas-banner {
    padding: 13px 17px;
    margin-bottom: 20px;
    background: #fff4dc;
    border: 1px solid #f0d89d;
    border-radius: 11px;
    color: #795500;
    font-size: 13px;
}
.conc-intro { color:#77718b; font-size:14px; margin-top:7px; }
.conc-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:15px;
    margin:22px 0;
}
.conc-card {
    background:#fff;
    border:1px solid #f1e9f2;
    border-radius:14px;
    padding:18px;
    box-shadow:0 3px 12px rgba(89,34,100,.04);
}
.conc-card span { display:block;color:#77718b;font-size:13px; }
.conc-card strong {
    display:block;color:#63336d;font-size:26px;margin-top:8px;
}
.conc-card small { display:block;color:#77718b;margin-top:5px; }
.conc-filtros {
    display:flex;flex-wrap:wrap;gap:10px;margin:20px 0;
}
.conc-filtros input,.conc-filtros select {
    min-height:42px;border:1px solid #e5d7e8;
    border-radius:9px;padding:8px 12px;background:#fff;
}
.conc-filtros button {
    min-height:42px;padding:8px 18px;border:0;
    border-radius:9px;background:#a974b4;color:#fff;cursor:pointer;
}
.conc-tabla {
    overflow-x:auto;background:#fff;border:1px solid #f1e9f2;
    border-radius:13px;margin-bottom:25px;
}
.conc-tabla table { width:100%;border-collapse:collapse;min-width:1050px; }
.conc-tabla th,.conc-tabla td {
    padding:12px;border-bottom:1px solid #f2ebf3;
    text-align:left;font-size:13px;vertical-align:top;
}
.conc-tabla th { background:#faf6fb;color:#765a7b; }
.conc-tabla small {
    display:block;color:#77718b;margin-top:5px;overflow-wrap:anywhere;
}
.conc-estado {
    display:inline-block;padding:5px 9px;border-radius:20px;
    font-size:11px;font-weight:700;
}
.pendiente { background:#fff3d9;color:#946000; }
.revision { background:#fce8ef;color:#a52c56; }
.conciliado { background:#e4f6eb;color:#247044; }
.ignorado { background:#eeeef2;color:#666; }
.conc-ref { color:#875092;font-weight:700;overflow-wrap:anywhere; }
.conc-ayuda {
    background:#faf6fb;border:1px solid #efe1f2;
    border-radius:12px;padding:15px 18px;margin-bottom:18px;
    color:#765f7b;font-size:13px;line-height:1.6;
}
.conc-vacio { padding:30px!important;text-align:center!important;color:#77718b; }
.conc-seccion { color:#63336d;margin:28px 0 12px; }
@media(max-width:900px) { .conc-grid {grid-template-columns:repeat(2,minmax(0,1fr));} }
@media(max-width:520px) {
    .conc-grid {gap:9px;}
    .conc-card {padding:13px;}
    .conc-card strong {font-size:21px;}
}
</style>
</head>
<body>
<main class="contenedor">
<section class="panel">

<div class="panel-encabezado">
    <div>
        <span class="etiqueta-seccion">CONTROL DE PAGOS · ENTORNO DE PRUEBAS</span>
        <h2 class="panel-titulo">Conciliación bancaria</h2>
        <p class="conc-intro">Consulta de movimientos bancarios y detección de referencias UBB.</p>
    </div>
</div>

<div class="pruebas-banner">
    <strong>ENTORNO DE PRUEBAS.</strong>
    Esta página consulta exclusivamente ubb_pagos_pruebas.
    No registra pagos ni modifica movimientos u obligaciones.
</div>

<div class="conc-grid">
    <div class="conc-card">
        <span>Movimientos pendientes</span>
        <strong><?= $estadisticas["PENDIENTE"] ?></strong>
        <small><?= dinero($resumenImportes["pendiente"]) ?></small>
    </div>
    <div class="conc-card">
        <span>En revisión</span>
        <strong><?= $estadisticas["REVISION"] ?></strong>
    </div>
    <div class="conc-card">
        <span>Conciliados</span>
        <strong><?= $estadisticas["CONCILIADO"] ?></strong>
        <small><?= dinero($resumenImportes["conciliado"]) ?></small>
    </div>
    <div class="conc-card">
        <span>Ignorados</span>
        <strong><?= $estadisticas["IGNORADO"] ?></strong>
    </div>
</div>

<div class="conc-ayuda">
    <strong>Resumen general:</strong>
    <?= $totalMovimientos ?> movimientos registrados por
    <?= dinero($resumenImportes["total"]) ?>.
    Las referencias mensuales se contrastan con obligaciones.
    Las permanentes se identifican, pero aún no se validan contra
    referencias_servicio en este entorno.
</div>

<form method="GET" class="conc-filtros">
    <select name="estado" aria-label="Filtrar por estado">
        <?php foreach ($estadosValidos as $opcion): ?>
            <option value="<?= h($opcion) ?>"
                <?= $estado === $opcion ? "selected" : "" ?>>
                <?= h($opcion === "TODOS" ? "Todos los estados" : $opcion) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="search" name="buscar"
        placeholder="Banco, ordenante, referencia o movimiento..."
        value="<?= h($buscar) ?>">
    <button type="submit">Buscar movimientos</button>
    <a href="conciliacion_bancaria_pruebas.php">Limpiar filtros</a>
</form>

<h3 class="conc-seccion">Movimientos bancarios</h3>
<div class="conc-tabla">
<table>
<thead>
<tr>
    <th>Fecha</th><th>Banco / ordenante</th><th>Movimiento</th>
    <th>Importe</th><th>Referencia detectada</th>
    <th>Cliente / servicio</th><th>Obligación</th><th>Estado</th>
</tr>
</thead>
<tbody>
<?php if ($movimientos->num_rows === 0): ?>
<tr><td colspan="8" class="conc-vacio">No hay movimientos para este filtro.</td></tr>
<?php else: ?>
<?php while ($movimiento = $movimientos->fetch_assoc()): ?>
<?php $deteccion = detectarReferencia($conexion, ($movimiento["concepto"] ?? "") . " " . ($movimiento["referencia_bancaria"] ?? "")); ?>
<tr>
    <td><?= h(date("d/m/Y H:i", strtotime($movimiento["fecha_movimiento"]))) ?></td>
    <td>
        <strong><?= h($movimiento["banco"]) ?></strong>
        <small><?= h($movimiento["cuenta_alias"]) ?></small>
        <?= h($movimiento["ordenante"]) ?>
    </td>
    <td>
        <?= h($movimiento["tipo_movimiento"]) ?>
        <small><?= h($movimiento["clave_rastreo"]) ?></small>
    </td>
    <td><strong><?= dinero($movimiento["monto"], $movimiento["moneda"]) ?></strong></td>
    <td>
        <?php if ($deteccion["referencia"] !== ""): ?>
            <span class="conc-ref"><?= h($deteccion["referencia"]) ?></span>
            <small><?= h($deteccion["tipo"]) ?></small>
        <?php else: ?>
            Sin referencia UBB
        <?php endif; ?>
        <small><?= h($movimiento["referencia_bancaria"]) ?></small>
        <small><?= h($movimiento["concepto"]) ?></small>
    </td>
    <td>
        <?php if ($deteccion["cliente"] !== null): ?>
            Cliente #<?= h($deteccion["cliente"]) ?>
            <small>Servicio #<?= h($deteccion["servicio"]) ?></small>
        <?php else: ?>
            No identificado
        <?php endif; ?>
    </td>
    <td>
        <?php if ($deteccion["tipo"] === "MENSUAL" && $deteccion["obligacion"]): ?>
            <?= dinero($deteccion["obligacion"]["monto"]) ?>
            <small>
                <?= h($deteccion["obligacion"]["periodo"]) ?>
                · <?= h($deteccion["obligacion"]["estado"]) ?>
            </small>
            <small>Vence: <?= h($deteccion["obligacion"]["fecha_vencimiento"]) ?></small>
        <?php elseif ($deteccion["tipo"] === "FECHA_VENCIMIENTO_NO_COINCIDE"): ?>
            Fecha de vencimiento no coincide
            <small>Registrada: <?= h($deteccion["obligacion"]["fecha_vencimiento"]) ?></small>
        <?php elseif ($deteccion["tipo"] === "MENSUAL_SIN_OBLIGACION"): ?>
            Obligación no registrada
        <?php elseif ($deteccion["tipo"] === "PERMANENTE_NO_VALIDADA"): ?>
            Referencia permanente
            <small>Pendiente de validación en referencias_servicio.</small>
        <?php else: ?>
            Sin coincidencia
        <?php endif; ?>
    </td>
    <td>
        <span class="conc-estado <?= h(claseEstado($movimiento["estado_conciliacion"])) ?>">
            <?= h($movimiento["estado_conciliacion"]) ?>
        </span>
        <?php if ($movimiento["id_pago"]): ?>
            <small>Pago #<?= h($movimiento["id_pago"]) ?></small>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<h3 class="conc-seccion">Auditoría reciente</h3>
<div class="conc-tabla">
<table>
<thead>
<tr>
    <th>Fecha</th><th>Movimiento</th><th>Pago</th>
    <th>Evento</th><th>Resultado</th><th>Detalle</th>
</tr>
</thead>
<tbody>
<?php if ($auditoria->num_rows === 0): ?>
<tr><td colspan="6" class="conc-vacio">Todavía no hay eventos de auditoría.</td></tr>
<?php else: ?>
<?php while ($evento = $auditoria->fetch_assoc()): ?>
<tr>
    <td><?= h($evento["fecha_evento"]) ?></td>
    <td><?= h($evento["id_movimiento"]) ?></td>
    <td><?= h($evento["id_pago"] ?? "—") ?></td>
    <td><?= h($evento["tipo_evento"]) ?></td>
    <td><?= h($evento["resultado"]) ?></td>
    <td><?= h($evento["detalle"]) ?></td>
</tr>
<?php endwhile; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<p class="conc-intro">
    Registros consultados: máximo 300 movimientos y 10 eventos de auditoría.
    Total de movimientos en la base: <?= $totalMovimientos ?>.
</p>

</section>
</main>
</body>
</html>
