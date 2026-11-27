<?php
declare(strict_types=1);
require_once __DIR__ . "/includes/sesion.php";

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
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
    <style>
        /* Filtros: fechas y selects del mismo alto que el resto */
        .reporte .hist-campo input[type="date"] {
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--cc-borde);
            border-radius: 11px;
            background: #fff;
            color: var(--cc-texto);
            font: inherit;
            font-size: 14px;
        }
        .reporte .hist-campo input[type="date"]:focus {
            outline: none;
            border-color: var(--cc-morado-medio);
            box-shadow: 0 0 0 3px rgba(154, 90, 166, .15);
        }
        .rep-acciones-filtro {
            display: flex;
            gap: 8px;
            align-self: flex-end;
            margin-left: auto;
        }
        .rep-acciones-filtro .hist-boton {
            height: 42px;
            padding: 0 16px;
            font-size: 13px;
            cursor: pointer;
            font-family: inherit;
        }

        /* Cinco tarjetas */
        .rep-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .rep-kpis .cc-kpi-numero { font-size: 28px; }
        .rep-kpis .cc-tendencia { align-self: flex-start; margin-top: 4px; font-size: 11.5px; }

        /* Ingresos por método: barra proporcional */
        .rep-barra {
            position: relative;
            width: 100%;
            min-width: 140px;
            height: 8px;
            border-radius: 999px;
            background: #f3eaf5;
            overflow: hidden;
        }
        .rep-barra span {
            position: absolute;
            inset: 0 auto 0 0;
            border-radius: 999px;
            background: linear-gradient(90deg, #c08fca, #8a4a98);
        }
        .rep-porcentaje { color: var(--cc-texto-suave); font-size: 12px; margin-top: 4px; }

        .rep-pie {
            padding: 13px 16px;
            border-top: 1px solid var(--cc-borde);
            background: #fcf8fd;
            color: var(--cc-texto-suave);
            font-size: 12.5px;
            line-height: 1.5;
        }
        .reporte .hist-tabla + .hist-tabla { margin-top: 18px; }

        @media (max-width: 1250px) {
            .rep-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .rep-kpis { grid-template-columns: minmax(0, 1fr); }
            .rep-acciones-filtro { margin-left: 0; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<?php
$fechaCorta = static fn(string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
$maxMetodo = 0.0;
foreach ($metodos as $fila) {
    $maxMetodo = max($maxMetodo, (float)$fila['ingresos']);
}
$urlCsv = '?' . http_build_query(['desde' => $desde, 'hasta' => $hasta, 'metodo' => $metodo, 'estado' => $estado, 'exportar' => 'csv']);
?>

<main class="contenedor cc hist reporte">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h17"/><path d="M8 15v-3M12 15V8M16 15v-5M20 15V6"/></svg>
            </span>
            <div>
                <h1>Reporte de pagos</h1>
                <p>Del <?= h($fechaCorta($desde)) ?> al <?= h($fechaCorta($hasta)) ?> · comparado con los <?= (int)$diasPeriodo ?> días anteriores</p>
            </div>
        </div>
        <a class="hist-boton hist-boton--principal" href="<?= h($urlCsv) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
            Exportar CSV
        </a>
    </header>


    <!-- =================================================
         FILTROS
    ================================================== -->

    <form class="cc-panel hist-filtros" method="get">
        <label class="hist-campo">
            <span>Fecha inicial</span>
            <input id="desde" type="date" name="desde" value="<?= h($desde) ?>" required>
        </label>
        <label class="hist-campo">
            <span>Fecha final</span>
            <input id="hasta" type="date" name="hasta" value="<?= h($hasta) ?>" required>
        </label>
        <label class="hist-campo">
            <span>Método</span>
            <select id="metodo" name="metodo" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($metodosPermitidos as $m): ?>
                    <option value="<?= h($m) ?>" <?= $metodo === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="hist-campo">
            <span>Estado</span>
            <select id="estado" name="estado" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($estadosPermitidos as $e): ?>
                    <option value="<?= h($e) ?>" <?= $estado === $e ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="rep-acciones-filtro">
            <a class="hist-boton" href="reporte_semanal.php">Esta semana</a>
            <button class="hist-boton hist-boton--principal" type="submit">Generar reporte</button>
        </div>
    </form>


    <!-- =================================================
         RESUMEN
    ================================================== -->

    <div class="cc-kpis hist-kpis rep-kpis">

        <div class="cc-kpi cc-kpi--activos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                <span class="cc-kpi-nombre">Ingresos confirmados</span>
            </div>
            <strong class="cc-kpi-numero"><?= dinero($ingresos) ?></strong>
            <span class="cc-kpi-detalle">Solo pagos con estado PAGADO</span>
        </div>

        <div class="cc-kpi cc-kpi--pagos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                <span class="cc-kpi-nombre">Pagos confirmados</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$resumen['pagados']) ?></strong>
            <span class="cc-kpi-detalle">Movimientos pagados en el periodo</span>
        </div>

        <div class="cc-kpi cc-kpi--proximos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <span class="cc-kpi-nombre">Pagos pendientes</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$resumen['pendientes']) ?></strong>
            <span class="cc-kpi-detalle">Monto pendiente: <?= dinero($resumen['monto_pendiente']) ?></span>
        </div>

        <div class="cc-kpi cc-kpi--suspendidos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg></span>
                <span class="cc-kpi-nombre">Pagos cancelados</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$resumen['cancelados']) ?></strong>
            <span class="cc-kpi-detalle">No se consideran ingresos</span>
        </div>

        <div class="cc-kpi hist-kpi--confirmados">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></span>
                <span class="cc-kpi-nombre">Periodo anterior</span>
            </div>
            <strong class="cc-kpi-numero"><?= dinero($ingresosAnterior) ?></strong>
            <?php if ($porcentaje !== null): ?>
                <span class="cc-tendencia <?= $diferencia >= 0 ? 'tendencia-positiva' : 'tendencia-negativa' ?>">
                    <?= $diferencia >= 0 ? '↑ Aumento de ' : '↓ Disminución de ' ?><?= number_format(abs($porcentaje), 1) ?>%
                </span>
            <?php else: ?>
                <span class="cc-kpi-detalle">Sin ingresos en el periodo anterior para comparar</span>
            <?php endif; ?>
        </div>

    </div>


    <!-- =================================================
         INGRESOS POR MÉTODO
    ================================================== -->

    <section class="cc-panel hist-tabla">
        <div class="mens-faltantes-cabecera">
            <div>
                <h2>Ingresos por método de pago</h2>
                <p>Cuánto entró por cada método en el periodo (solo pagos confirmados).</p>
            </div>
        </div>
        <div class="hist-tabla-scroll">
            <table style="min-width:640px">
                <thead>
                    <tr>
                        <th>Método</th>
                        <th class="hist-num">Movimientos</th>
                        <th class="hist-num">Ingresos confirmados</th>
                        <th style="width:38%">Proporción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$metodos): ?>
                    <tr><td colspan="4" class="hist-vacio">No hay movimientos para los filtros seleccionados.</td></tr>
                <?php else: foreach ($metodos as $fila):
                    $proporcion = $ingresos > 0 ? ((float)$fila['ingresos'] / $ingresos) * 100 : 0;
                    $anchoBarra = $maxMetodo > 0 ? ((float)$fila['ingresos'] / $maxMetodo) * 100 : 0;
                ?>
                    <tr>
                        <td><span class="hist-pildora hist-pildora--metodo"><?= h($fila['metodo_pago']) ?></span></td>
                        <td class="hist-num"><?= number_format((int)$fila['cantidad']) ?></td>
                        <td class="hist-num hist-fuerte"><?= dinero($fila['ingresos']) ?></td>
                        <td>
                            <div class="rep-barra"><span style="width:<?= number_format($anchoBarra, 1, '.', '') ?>%"></span></div>
                            <div class="rep-porcentaje"><?= number_format($proporcion, 1) ?>% de los ingresos</div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>


    <!-- =================================================
         DETALLE DE MOVIMIENTOS
    ================================================== -->

    <section class="cc-panel hist-tabla">
        <div class="mens-faltantes-cabecera">
            <div>
                <h2>Detalle de movimientos</h2>
                <p><?= number_format(count($movimientos)) ?> movimientos en el periodo.</p>
            </div>
            <a class="hist-boton adeudos-exportar" href="<?= h($urlCsv) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>
                Exportar CSV
            </a>
        </div>
        <div class="hist-tabla-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Cliente</th>
                        <th>Servicio</th>
                        <th>Fecha</th>
                        <th class="hist-num">Monto</th>
                        <th>Método</th>
                        <th>Estado</th>
                        <th>Referencia</th>
                        <th>Comprobante</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$movimientos): ?>
                    <tr><td colspan="9" class="hist-vacio">No hay movimientos en el periodo seleccionado.</td></tr>
                <?php else: foreach ($movimientos as $m): ?>
                    <tr>
                        <td class="hist-fuerte">PAG-<?= str_pad((string)(int)$m['id_pago'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <a class="hist-boton" href="ver_cliente.php?id=<?= (int)$m['id_cliente'] ?>">#<?= h($m['id_cliente']) ?></a>
                        </td>
                        <td class="hist-servicio"><?= $m['id_servicio'] === null ? '<span class="hist-suave">—</span>' : '#' . h($m['id_servicio']) ?></td>
                        <td class="hist-fecha">
                            <?= h(date('d/m/Y', strtotime((string)$m['fecha_pago']))) ?>
                            <span><?= h(date('H:i', strtotime((string)$m['fecha_pago']))) ?></span>
                        </td>
                        <td class="hist-num hist-fuerte"><?= dinero($m['monto']) ?></td>
                        <td><span class="hist-pildora hist-pildora--metodo"><?= h($m['metodo_pago']) ?></span></td>
                        <td><span class="hist-pildora hist-estado-<?= h(strtolower((string)$m['estado_pago'])) ?>"><?= h($m['estado_pago']) ?></span></td>
                        <td class="hist-referencia"><?= h($m['referencia'] ?: '—') ?></td>
                        <td>
                            <a class="hist-boton hist-boton--principal" href="comprobante.php?id=<?= (int)$m['id_pago'] ?>" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
                                Ver
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="rep-pie">
            Periodo: <?= h($fechaCorta($desde)) ?> al <?= h($fechaCorta($hasta)) ?>.
            Los ingresos excluyen pagos pendientes, cancelados y créditos de cortesía.
        </div>
    </section>

</main>
</body>
</html>
