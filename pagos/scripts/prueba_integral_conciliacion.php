<?php
declare(strict_types=1);

// Solo se ejecuta desde la terminal (docker exec ... php ...), nunca desde el navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../conexion.php';

$cliente = 4;
$servicio = 3;
$periodo = '209912';
$referencia = 'UBB-M-4-3-209912';
$monto = 1.00;
$huella = hash('sha256', 'UBB-PRUEBA-INTEGRAL-209912');

try {
    $conexion->begin_transaction();

    // 1. Crear obligación temporal.
    $stmt = $conexion->prepare("
        INSERT INTO obligaciones_pago (
            id_cliente, id_servicio, periodo, concepto,
            monto, fecha_vencimiento, referencia_cobro,
            estado, observaciones
        ) VALUES (?, ?, ?, 'MENSUALIDAD', ?,
                  '2099-12-31', ?, 'PENDIENTE',
                  'PRUEBA TEMPORAL')
    ");
    $stmt->bind_param(
        'iisds',
        $cliente, $servicio, $periodo, $monto, $referencia
    );
    $stmt->execute();
    $idObligacion = $conexion->insert_id;
    $stmt->close();

    // 2. Crear movimiento bancario temporal.
    $banco = 'PRUEBA';
    $cuenta = 'TEST';
    $externo = 'TEST-UBB-209912';
    $fecha = '2099-12-15 12:00:00';
    $tipo = 'ABONO';
    $moneda = 'MXN';
    $ordenante = 'PRUEBA UBB';
    $concepto = $referencia;
    $estado = 'PENDIENTE';
    $fuente = 'PRUEBA';

    $stmt = $conexion->prepare("
        INSERT INTO movimientos_bancarios (
            banco, cuenta_alias, identificador_externo,
            huella_deduplicacion, fecha_movimiento,
            tipo_movimiento, monto, moneda, ordenante,
            referencia_bancaria, concepto,
            estado_conciliacion, fuente_datos
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'ssssssdssssss',
        $banco, $cuenta, $externo, $huella, $fecha,
        $tipo, $monto, $moneda, $ordenante,
        $referencia, $concepto, $estado, $fuente
    );
    $stmt->execute();
    $idMovimiento = $conexion->insert_id;
    $stmt->close();

    // 3. Registrar pago temporal.
    $metodo = 'TRANSFERENCIA';
    $estadoPago = 'PAGADO';
    $observaciones = 'PRUEBA INTEGRAL TEMPORAL';

    $stmt = $conexion->prepare("
        INSERT INTO pagos (
            id_cliente, id_servicio, monto, fecha_pago,
            metodo_pago, referencia, estado_pago, observaciones
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iidsssss',
        $cliente, $servicio, $monto, $fecha,
        $metodo, $referencia, $estadoPago, $observaciones
    );
    $stmt->execute();
    $idPago = $conexion->insert_id;
    $stmt->close();

    // 4. Relacionar pago y obligación.
    $stmt = $conexion->prepare("
        INSERT INTO pagos_obligaciones (
            id_pago, id_obligacion, monto_aplicado, fecha_aplicacion
        ) VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param('iid', $idPago, $idObligacion, $monto);
    $stmt->execute();
    $stmt->close();

    // 5. Actualizar obligación.
    $stmt = $conexion->prepare("
        UPDATE obligaciones_pago
        SET estado = 'PAGADA'
        WHERE id_obligacion = ? AND estado = 'PENDIENTE'
    ");
    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('Falló la actualización de obligación.');
    }
    $stmt->close();

    // 6. Actualizar movimiento.
    $stmt = $conexion->prepare("
        UPDATE movimientos_bancarios
        SET estado_conciliacion = 'CONCILIADO', id_pago = ?
        WHERE id_movimiento = ? AND id_pago IS NULL
    ");
    $stmt->bind_param('ii', $idPago, $idMovimiento);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('Falló la actualización del movimiento.');
    }
    $stmt->close();

    // 7. Verificar las tres relaciones.
    $stmt = $conexion->prepare("
        SELECT
            p.id_pago,
            o.estado AS estado_obligacion,
            m.estado_conciliacion,
            m.id_pago AS pago_del_movimiento,
            po.monto_aplicado
        FROM pagos p
        INNER JOIN pagos_obligaciones po ON po.id_pago = p.id_pago
        INNER JOIN obligaciones_pago o ON o.id_obligacion = po.id_obligacion
        INNER JOIN movimientos_bancarios m ON m.id_pago = p.id_pago
        WHERE p.id_pago = ?
    ");
    $stmt->bind_param('i', $idPago);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$resultado ||
        $resultado['estado_obligacion'] !== 'PAGADA' ||
        $resultado['estado_conciliacion'] !== 'CONCILIADO' ||
        (int)$resultado['pago_del_movimiento'] !== $idPago
    ) {
        throw new RuntimeException('La verificación integral falló.');
    }

    echo "===== PRUEBA INTEGRAL CORRECTA =====\n";
    echo json_encode($resultado, JSON_PRETTY_PRINT) . "\n";
    echo "Pago, obligación y movimiento relacionados correctamente.\n";

    $conexion->rollback();

    echo "\n===== REVERSIÓN COMPLETADA =====\n";
    echo "Todos los registros temporales fueron revertidos.\n";

} catch (Throwable $e) {
    $conexion->rollback();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
