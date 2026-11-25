<?php
declare(strict_types=1);

/*
 * PREVISUALIZACIÓN DE CONCILIACIÓN BANCARIA UBB
 *
 * Solo lectura.
 * No crea pagos.
 * No modifica obligaciones.
 * No cambia estados de movimientos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$entornoPagos = getenv('UBB_PAGOS_ENTORNO') ?: 'produccion';

if ($entornoPagos === 'pruebas') {
    require __DIR__ . '/../conexion_pruebas.php';
} elseif ($entornoPagos === 'produccion') {
    require __DIR__ . '/../conexion.php';
} else {
    throw new RuntimeException('Entorno de pagos no válido.');
}

$conexion->set_charset('utf8mb4');

function normalizarReferencia(string $texto): array
{
    preg_match_all(
        '/(?<![A-Z0-9])(?:UBBC\d+S\d+M\d{8}|UBBC\d+S\d+|UBB-M-\d+-\d+-\d{6}(?:\d{2})?|UBB-\d+-\d+)(?![A-Z0-9])/i',
        strtoupper($texto),
        $coincidencias
    );

    return array_values(array_unique($coincidencias[0] ?? []));
}

$referenciasServicio = [];
$resultado = $conexion->query("
    SELECT
        id_cliente,
        id_servicio,
        referencia_base
    FROM referencias_servicio
");

while ($fila = $resultado->fetch_assoc()) {
    $referenciasServicio[strtoupper(trim($fila['referencia_base']))] = $fila;
}

$obligaciones = [];
$resultado = $conexion->query("
    SELECT
        id_obligacion,
        id_cliente,
        id_servicio,
        periodo,
        concepto,
        monto,
        referencia_cobro,
        estado
    FROM obligaciones_pago
");

while ($fila = $resultado->fetch_assoc()) {
    $obligaciones[strtoupper(trim($fila['referencia_cobro']))] = $fila;
}

$movimientos = $conexion->query("
    SELECT
        id_movimiento,
        fecha_movimiento,
        banco,
        ordenante,
        monto,
        moneda,
        referencia_bancaria,
        concepto,
        estado_conciliacion
    FROM movimientos_bancarios
    WHERE tipo_movimiento = 'ABONO'
      AND estado_conciliacion IN ('PENDIENTE', 'REVISION')
    ORDER BY fecha_movimiento DESC
");

$totales = [
    'movimientos' => 0,
    'referencia_mensual' => 0,
    'referencia_servicio' => 0,
    'sin_coincidencia' => 0,
    'referencias_desconocidas' => 0
];

echo "===== PREVISUALIZACIÓN DE CONCILIACIÓN UBB =====\n";
echo "Solo lectura: no se modifica ninguna tabla.\n\n";

while ($movimiento = $movimientos->fetch_assoc()) {
    $totales['movimientos']++;

    $texto = ($movimiento['referencia_bancaria'] ?? '') . ' ' .
             ($movimiento['concepto'] ?? '');

    $encontradas = normalizarReferencia($texto);

    $tipoCoincidencia = 'SIN_COINCIDENCIA';
    $cliente = '';
    $servicio = '';
    $periodo = '';
    $obligacion = '';
    $estadoObligacion = '';
    $referenciaEncontrada = '';

    foreach ($encontradas as $referencia) {
        // Primero se busca una referencia mensual exacta.
        if (isset($obligaciones[$referencia])) {
            $o = $obligaciones[$referencia];

            $tipoCoincidencia = 'REFERENCIA_MENSUAL';
            $cliente = $o['id_cliente'];
            $servicio = $o['id_servicio'];
            $periodo = $o['periodo'];
            $obligacion = $o['id_obligacion'];
            $estadoObligacion = $o['estado'];
            $referenciaEncontrada = $referencia;
            break;
        }

        // Después se busca la referencia permanente del servicio.
        if (isset($referenciasServicio[$referencia])) {
            $r = $referenciasServicio[$referencia];

            $tipoCoincidencia = 'REFERENCIA_SERVICIO';
            $cliente = $r['id_cliente'];
            $servicio = $r['id_servicio'];
            $referenciaEncontrada = $referencia;
            break;
        }
    }

    if ($tipoCoincidencia === 'REFERENCIA_MENSUAL') {
        $totales['referencia_mensual']++;
    } elseif ($tipoCoincidencia === 'REFERENCIA_SERVICIO') {
        $totales['referencia_servicio']++;
    } else {
        $totales['sin_coincidencia']++;

        if ($encontradas !== []) {
            $totales['referencias_desconocidas']++;
        }
    }

    echo implode(" | ", [
        'MOVIMIENTO=' . $movimiento['id_movimiento'],
        'FECHA=' . $movimiento['fecha_movimiento'],
        'MONTO=' . $movimiento['monto'] . ' ' . $movimiento['moneda'],
        'ORDENANTE=' . ($movimiento['ordenante'] ?? ''),
        'RESULTADO=' . $tipoCoincidencia,
        'CLIENTE=' . $cliente,
        'SERVICIO=' . $servicio,
        'PERIODO=' . $periodo,
        'OBLIGACION=' . $obligacion,
        'ESTADO_OBLIGACION=' . $estadoObligacion,
        'REFERENCIA=' . $referenciaEncontrada
    ]) . PHP_EOL;
}

echo "\n===== RESUMEN =====\n";

foreach ($totales as $nombre => $cantidad) {
    echo strtoupper($nombre) . ': ' . $cantidad . PHP_EOL;
}

echo "\nFIN. No se crearon pagos ni se modificaron movimientos u obligaciones.\n";
