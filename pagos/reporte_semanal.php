<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/conexion.php';

/* Identificar la conexión MySQL que utiliza la plataforma. */
$db = null;

foreach (get_defined_vars() as $valor) {
    if ($valor instanceof mysqli) {
        $db = $valor;
        break;
    }
}

if (!$db) {
    http_response_code(500);
    exit('No se pudo identificar la conexión MySQL de conexion.php.');
}

$db->set_charset('utf8mb4');

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function dinero($valor): string {
    return '$' . number_format((float)$valor, 2, '.', ',');
}

/* Semana de lunes a domingo, con opción de seleccionar otro periodo. */
$hoy = new DateTimeImmutable('today');
$inicioSemana = $hoy->modify('monday this week');
$finSemana = $inicioSemana->modify('+6 days');

$desde = $_GET['desde'] ?? $inicioSemana->format('Y-m-d');
$hasta = $_GET['hasta'] ?? $finSemana->format('Y-m-d');

$fechaValida = static function ($fecha): bool {
    if (!is_string($fecha)) {
        return false;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $d !== false && $d->format('Y-m-d') === $fecha;
};

if (!$fechaValida($desde) || !$fechaValida($hasta) || $desde > $hasta) {
    $desde = $inicioSemana->format('Y-m-d');
    $hasta = $finSemana->format('Y-m-d');
}

$desdeAnterior = (new DateTimeImmutable($desde))->modify(
    '-' . ((int)((new DateTimeImmutable($hasta))->diff(
        new DateTimeImmutable($desde)
    )->days) + 1) . ' days'
)->format('Y-m-d');

$diasPeriodo = (int)(new DateTimeImmutable($desde))->diff(
    new DateTimeImmutable($hasta)
)->days + 1;

$hastaAnterior = (new DateTimeImmutable($desde))
    ->modify('-1 day')->format('Y-m-d');

$desdeAnterior = (new DateTimeImmutable($desde))
    ->modify('-' . $diasPeriodo . ' days')->format('Y-m-d');

$esCsv = ($_GET['exportar'] ?? '') === 'csv';

$condicion = "fecha_pago >= ? AND fecha_pago < DATE_ADD(?, INTERVAL 1 DAY)";
$metodo = trim((string)($_GET['metodo'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));

$metodosPermitidos = [
    'EFECTIVO', 'TRANSFERENCIA', 'DEPOSITO', 'TARJETA',
    'OXXO', '3B', 'OTRO'
];
$estadosPermitidos = ['PAGADO', 'PENDIENTE', 'CANCELADO'];

if (!in_array($metodo, $metodosPermitidos, true)) {
    $metodo = '';
}
if (!in_array($estado, $estadosPermitidos, true)) {
    $estado = '';
}

$filtros = $condicion;
$tipos = 'ss';
$parametros = [$desde, $hasta];

if ($metodo !== '') {
    $filtros .= ' AND metodo_pago = ?';
    $tipos .= 's';
    $parametros[] = $metodo;
}
if ($estado !== '') {
    $filtros .= ' AND estado_pago = ?';
    $tipos .= 's';
    $parametros[] = $estado;
}

function consultar(mysqli $db, string $sql, string $tipos = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar una consulta del reporte.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('No se pudo ejecutar una consulta del reporte.');
    }
    $resultado = $stmt->get_result();
    $filas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $filas;
}

try {
    $resumen = consultar(
        $db,
        "SELECT
            COUNT(*) AS cantidad,
            COALESCE(SUM(CASE WHEN estado_pago='PAGADO' AND metodo_pago<>'CORTESIA' THEN monto ELSE 0 END),0) AS ingresos,
            SUM(estado_pago='PAGADO') AS pagados,
            SUM(estado_pago='PENDIENTE') AS pendientes,
            COALESCE(SUM(CASE WHEN estado_pago='PENDIENTE' THEN monto ELSE 0 END),0) AS monto_pendiente,
            SUM(estado_pago='CANCELADO') AS cancelados
         FROM pagos WHERE $filtros",
        $tipos,
        $parametros
    )[0];

    $metodos = consultar(
        $db,
        "SELECT metodo_pago,
            COUNT(*) AS cantidad,
            COALESCE(SUM(CASE WHEN estado_pago='PAGADO' AND metodo_pago<>'CORTESIA' THEN monto ELSE 0 END),0) AS ingresos
         FROM pagos WHERE $filtros
         GROUP BY metodo_pago ORDER BY ingresos DESC",
        $tipos,
        $parametros
    );

    $movimientos = consultar(
        $db,
        "SELECT id_pago, id_cliente, id_servicio, fecha_pago, monto,
                metodo_pago, estado_pago, referencia, comprobante
         FROM pagos WHERE $filtros
         ORDER BY fecha_pago DESC, id_pago DESC",
        $tipos,
        $parametros
    );

    $anterior = consultar(
        $db,
        "SELECT
            COUNT(*) AS cantidad,
            COALESCE(SUM(CASE WHEN estado_pago='PAGADO' AND metodo_pago<>'CORTESIA' THEN monto ELSE 0 END),0) AS ingresos
         FROM pagos
         WHERE fecha_pago >= ? AND fecha_pago < DATE_ADD(?, INTERVAL 1 DAY)",
        'ss',
        [$desdeAnterior, $hastaAnterior]
    )[0];

} catch (Throwable $e) {
    error_log('Reporte semanal: ' . $e->getMessage());
    http_response_code(500);
    exit('No fue posible generar el reporte. Revisa el registro de errores de PHP.');
}

if ($esCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_pagos_' . $desde . '_' . $hasta . '.csv"');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'w');
    fputcsv($salida, ['ID pago', 'ID cliente', 'ID servicio', 'Fecha', 'Monto', 'Método', 'Estado', 'Referencia']);
    foreach ($movimientos as $m) {
        fputcsv($salida, [
            $m['id_pago'], $m['id_cliente'], $m['id_servicio'],
            $m['fecha_pago'], $m['monto'], $m['metodo_pago'],
            $m['estado_pago'], $m['referencia']
        ]);
    }
    fclose($salida);
    exit;
}

$ingresos = (float)$resumen['ingresos'];
$ingresosAnterior = (float)$anterior['ingresos'];
$diferencia = $ingresos - $ingresosAnterior;
$porcentaje = $ingresosAnterior > 0
    ? ($diferencia / $ingresosAnterior) * 100
    : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reporte de pagos | UBB Comunicaciones</title>
<style>
:root{--morado:#5f109a;--fondo:#f3f1fa;--texto:#221749;--borde:#eadcf4}
*{box-sizing:border-box}
body{margin:0;background:var(--fondo);font-family:Arial,Helvetica,sans-serif;color:var(--texto)}
.contenedor{max-width:1250px;margin:auto;padding:26px}
.reporte-titulo{background:linear-gradient(120deg,#5f109a,#811fcd);color:white;padding:25px 28px;border-radius:18px;margin-bottom:22px}
.reporte-titulo h1{margin:0 0 8px;font-size:25px}
.reporte-titulo p{margin:0;opacity:.9}
.panel{background:white;border:1px solid var(--borde);border-radius:15px;padding:20px;margin-bottom:20px;box-shadow:0 5px 18px rgba(60,20,91,.04)}
.filtros{display:flex;gap:14px;align-items:end;flex-wrap:wrap}
.campo{display:flex;flex-direction:column;gap:7px;min-width:150px}
label{font-size:13px;font-weight:bold}
input,select{padding:11px;border:1px solid #ddcbec;border-radius:9px;background:white;color:var(--texto)}
.boton{display:inline-block;padding:11px 15px;border-radius:9px;border:0;background:var(--morado);color:white;text-decoration:none;font-weight:bold;cursor:pointer}
.boton.secundario{background:#f0e5f8;color:var(--morado)}
.tarjetas{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:15px;margin-bottom:20px}
.tarjeta{background:white;border:1px solid var(--borde);border-radius:15px;padding:20px}
.tarjeta small{color:#716982;font-weight:bold}
.tarjeta h2{font-size:25px;margin:12px 0 5px;overflow-wrap:anywhere}
.tarjeta p{font-size:12px;color:#716982;margin:0}
h2.seccion{font-size:18px;margin:0 0 15px}
.tabla{overflow-x:auto}
table{width:100%;border-collapse:collapse;white-space:nowrap}
th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #f0e6f7;font-size:13px}
th{background:#f8f3fc;color:#5f1a93}
.estado{font-size:11px;font-weight:bold;padding:5px 8px;border-radius:20px;background:#f0e5f8}
.estado.PAGADO{background:#e1f6e9;color:#197444}
.estado.PENDIENTE{background:#fff0cc;color:#805900}
.estado.CANCELADO{background:#ffe4e4;color:#a02c2c}
.pie{font-size:12px;color:#716982;margin-top:15px}
@media(max-width:600px){.contenedor{padding:12px}.encabezado{padding:20px}.tarjeta h2{font-size:22px}}
</style>
    <link rel="stylesheet" href="css/pagos.css">
</head>
<body>
<?php require __DIR__ . "/includes/encabezado.php"; ?>

<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<div class="contenedor">
<div class="reporte-titulo">
<h1>📊 Reporte de pagos</h1>
<p>UBB COMUNICACIONES · Resumen por periodo y comparación con el periodo anterior</p>
</div>

<form class="panel filtros" method="get">
<div class="campo"><label for="desde">Fecha inicial</label><input id="desde" type="date" name="desde" value="<?= h($desde) ?>" required></div>
<div class="campo"><label for="hasta">Fecha final</label><input id="hasta" type="date" name="hasta" value="<?= h($hasta) ?>" required></div>
<div class="campo"><label for="metodo">Método</label>
<select id="metodo" name="metodo"><option value="">Todos</option>
<?php foreach ($metodosPermitidos as $m): ?><option value="<?= h($m) ?>" <?= $metodo===$m?'selected':'' ?>><?= h($m) ?></option><?php endforeach; ?>
</select></div>
<div class="campo"><label for="estado">Estado</label>
<select id="estado" name="estado"><option value="">Todos</option>
<?php foreach ($estadosPermitidos as $e): ?><option value="<?= h($e) ?>" <?= $estado===$e?'selected':'' ?>><?= h($e) ?></option><?php endforeach; ?>
</select></div>
<button class="boton" type="submit">Generar reporte</button>
<a class="boton secundario" href="reporte_semanal.php">Esta semana</a>
</form>

<div class="tarjetas">
<div class="tarjeta"><small>INGRESOS CONFIRMADOS</small><h2><?= dinero($ingresos) ?></h2><p>Solo pagos con estado PAGADO</p></div>
<div class="tarjeta"><small>PAGOS CONFIRMADOS</small><h2><?= number_format((int)$resumen['pagados']) ?></h2><p>Movimientos pagados en el periodo</p></div>
<div class="tarjeta"><small>PAGOS PENDIENTES</small><h2><?= number_format((int)$resumen['pendientes']) ?></h2><p>Monto pendiente: <?= dinero($resumen['monto_pendiente']) ?></p></div>
<div class="tarjeta"><small>PAGOS CANCELADOS</small><h2><?= number_format((int)$resumen['cancelados']) ?></h2><p>No se consideran ingresos</p></div>
<div class="tarjeta"><small>PERIODO ANTERIOR</small><h2><?= dinero($ingresosAnterior) ?></h2><p>
<?php if ($porcentaje !== null): ?>
<?= $diferencia >= 0 ? 'Aumento de ' : 'Disminución de ' ?><?= number_format(abs($porcentaje),1) ?>%
<?php else: ?>Sin ingresos en el periodo anterior para comparar<?php endif; ?>
</p></div>
</div>

<section class="panel">
<h2 class="seccion">Ingresos por método de pago</h2>
<div class="tabla"><table>
<thead><tr><th>Método</th><th>Movimientos</th><th>Ingresos confirmados</th></tr></thead>
<tbody>
<?php if (!$metodos): ?><tr><td colspan="3">No hay movimientos para los filtros seleccionados.</td></tr>
<?php else: foreach ($metodos as $fila): ?>
<tr><td><?= h($fila['metodo_pago']) ?></td><td><?= number_format((int)$fila['cantidad']) ?></td><td><?= dinero($fila['ingresos']) ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</section>

<section class="panel">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:15px">
<h2 class="seccion" style="margin:0">Detalle de movimientos</h2>
<a class="boton" href="?<?= h(http_build_query(['desde'=>$desde,'hasta'=>$hasta,'metodo'=>$metodo,'estado'=>$estado,'exportar'=>'csv'])) ?>">⬇ Exportar CSV</a>
</div>
<div class="tabla"><table>
<thead><tr><th>Folio</th><th>Cliente ID</th><th>Servicio ID</th><th>Fecha</th><th>Monto</th><th>Método</th><th>Estado</th><th>Referencia</th><th>Comprobante</th></tr></thead>
<tbody>
<?php if (!$movimientos): ?><tr><td colspan="9">No hay movimientos en el periodo seleccionado.</td></tr>
<?php else: foreach ($movimientos as $m): ?>
<tr>
<td><?= h($m['id_pago']) ?></td><td><?= h($m['id_cliente']) ?></td>
<td><?= $m['id_servicio'] === null ? '—' : h($m['id_servicio']) ?></td>
<td><?= h($m['fecha_pago']) ?></td><td><?= dinero($m['monto']) ?></td>
<td><?= h($m['metodo_pago']) ?></td>
<td><span class="estado <?= h($m['estado_pago']) ?>"><?= h($m['estado_pago']) ?></span></td>
<td><?= h($m['referencia'] ?: '—') ?></td>
<td><?php if (!empty($m['comprobante'])): ?><a href="<?= h($m['comprobante']) ?>" target="_blank" rel="noopener">Ver</a><?php else: ?>—<?php endif; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<p class="pie">Periodo: <?= h($desde) ?> al <?= h($hasta) ?>. Los ingresos excluyen pagos pendientes y cancelados. El detalle utiliza los identificadores disponibles en la tabla de pagos; no inventa nombres de clientes.</p>
</section>
<p><a class="boton secundario" href="index.php">← Volver al centro de control</a></p>
</div>
</body>
</html>
