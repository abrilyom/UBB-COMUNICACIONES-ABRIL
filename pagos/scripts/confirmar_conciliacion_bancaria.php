<?php declare(strict_types=1);
/*
 * CONFIRMACION CONTROLADA DE CONCILIACION BANCARIA UBB
 *
 * Uso:
 * php confirmar_conciliacion_bancaria.php ID_MOVIMIENTO REFERENCIA METODO
 *
 * Para guardar:
 * php confirmar_conciliacion_bancaria.php ID_MOVIMIENTO REFERENCIA METODO --confirmar
 *
 * Sin --confirmar: valida y simula, sin guardar cambios.
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
    throw new RuntimeException(
        'Entorno inválido. Utiliza produccion o pruebas.'
    );
}

$baseEsperada = $entornoPagos === 'pruebas'
    ? 'ubb_pagos_pruebas'
    : 'ubb_pagos';

$baseSeleccionada = $conexion->query(
    'SELECT DATABASE()'
)->fetch_row()[0] ?? '';

if ($baseSeleccionada !== $baseEsperada) {
    throw new RuntimeException(
        'La conexión no corresponde al entorno solicitado.'
    );
}

echo "Entorno: $entornoPagos | Base: $baseSeleccionada\n";


if ($argc < 4 || $argc > 5) {
    exit(
        "Uso: php confirmar_conciliacion_bancaria.php " .
        "ID_MOVIMIENTO REFERENCIA_MENSUAL TRANSFERENCIA|DEPOSITO [--confirmar]\n"
    );
}

$idMovimiento = filter_var($argv[1], FILTER_VALIDATE_INT);
$referencia = strtoupper(trim($argv[2]));
$metodo = strtoupper(trim($argv[3]));
$confirmar = isset($argv[4]) && $argv[4] === '--confirmar';

if (!$idMovimiento || $idMovimiento < 1) {
    exit("ID de movimiento inválido.\n");
}

if (!in_array($metodo, ['TRANSFERENCIA', 'DEPOSITO'], true)) {
    exit("Método inválido. Utiliza TRANSFERENCIA o DEPOSITO.\n");
}

if (isset($argv[4]) && !$confirmar) {
    exit("Opción desconocida.\n");
}

if (preg_match('/^UBBC(\d+)S(\d+)M(\d{8})$/', $referencia, $partes)) {
    $clienteReferencia = (int)$partes[1];
    $servicioReferencia = (int)$partes[2];
    $fechaVencimientoReferencia = $partes[3];

    $anioReferencia = (int)substr($fechaVencimientoReferencia, 0, 4);
    $mesReferencia = (int)substr($fechaVencimientoReferencia, 4, 2);
    $diaReferencia = (int)substr($fechaVencimientoReferencia, 6, 2);

    if (!checkdate($mesReferencia, $diaReferencia, $anioReferencia)) {
        exit("La fecha de vencimiento de la referencia no es válida.\n");
    }

    $periodoReferencia = substr($fechaVencimientoReferencia, 0, 6);
} else {
    exit("Se requiere una referencia mensual UBB válida.\n");
}

$conexion->begin_transaction();

try {
    /*
     * 1. Bloquear el movimiento bancario.
     */
    $stmt = $conexion->prepare("
        SELECT
            id_movimiento,
            banco,
            fecha_movimiento,
            tipo_movimiento,
            monto,
            moneda,
            ordenante,
            referencia_bancaria,
            concepto,
            estado_conciliacion,
            id_pago
        FROM movimientos_bancarios
        WHERE id_movimiento = ?
        FOR UPDATE
    ");

    $stmt->bind_param('i', $idMovimiento);
    $stmt->execute();
    $movimiento = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$movimiento) {
        throw new RuntimeException("Movimiento bancario inexistente.");
    }

    if ($movimiento['tipo_movimiento'] !== 'ABONO') {
        throw new RuntimeException("No se pueden conciliar cargos.");
    }

    if (!in_array(
        $movimiento['estado_conciliacion'],
        ['PENDIENTE', 'REVISION'],
        true
    )) {
        throw new RuntimeException(
            "El movimiento no está pendiente de conciliación."
        );
    }

    if ($movimiento['id_pago'] !== null) {
        throw new RuntimeException(
            "El movimiento ya tiene un pago asociado."
        );
    }

    if ($movimiento['moneda'] !== 'MXN') {
        throw new RuntimeException(
            "La moneda no es MXN. Requiere revisión."
        );
    }

    /*
     * 2. Verificar que la referencia mensual esté realmente
     *    presente en el movimiento bancario.
     */
    $textoBancario = strtoupper(
        ($movimiento['referencia_bancaria'] ?? '') . ' ' .
        ($movimiento['concepto'] ?? '')
    );

    $patron = '/(?<![A-Z0-9])' .
        preg_quote($referencia, '/') .
        '(?![A-Z0-9])/i';

    if (!preg_match($patron, $textoBancario)) {
        throw new RuntimeException(
            "La referencia mensual no aparece en el movimiento bancario."
        );
    }

    /*
     * 3. Bloquear la obligación mensual.
     */
    $stmt = $conexion->prepare("
        SELECT
            id_obligacion,
            id_cliente,
            id_servicio,
            periodo,
            fecha_vencimiento,
            monto,
            estado,
            referencia_cobro
        FROM obligaciones_pago
        WHERE referencia_cobro = ?
        FOR UPDATE
    ");

    $stmt->bind_param('s', $referencia);
    $stmt->execute();
    $obligacion = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$obligacion) {
        throw new RuntimeException(
            "No existe una obligación con esa referencia."
        );
    }

    if (
        (int)$obligacion['id_cliente'] !== $clienteReferencia ||
        (int)$obligacion['id_servicio'] !== $servicioReferencia ||
        $obligacion['periodo'] !== $periodoReferencia ||
        ($fechaVencimientoReferencia !== null &&
            (new DateTimeImmutable($obligacion['fecha_vencimiento']))->format('Ymd') !== $fechaVencimientoReferencia)
    ) {
        throw new RuntimeException(
            "Los datos de la obligación no coinciden con la referencia."
        );
    }

    if ($obligacion['estado'] !== 'PENDIENTE') {
        throw new RuntimeException(
            "La obligación no está pendiente. Estado: " .
            $obligacion['estado']
        );
    }

    /*
     * 4. Calcular el saldo pendiente considerando aplicaciones previas.
     */
    $idObligacion = (int)$obligacion['id_obligacion'];

    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(po.monto_aplicado), 0) AS total_aplicado
        FROM pagos_obligaciones po
        INNER JOIN pagos p ON p.id_pago = po.id_pago
        WHERE po.id_obligacion = ?
          AND p.estado_pago = 'PAGADO'
    ");

    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();
    $aplicaciones = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /*
     * Trabajar en centavos para evitar errores de redondeo.
     */
    $centavosMovimiento = (int)round(
        (float)$movimiento['monto'] * 100
    );

    $centavosObligacion = (int)round(
        (float)$obligacion['monto'] * 100
    );

    $centavosAplicados = (int)round(
        (float)$aplicaciones['total_aplicado'] * 100
    );

    $centavosSaldo = $centavosObligacion - $centavosAplicados;

    if ($centavosSaldo <= 0) {
        throw new RuntimeException(
            "La obligación no tiene saldo pendiente disponible."
        );
    }

    if ($centavosMovimiento <= 0) {
        throw new RuntimeException(
            "El importe del movimiento bancario no es válido."
        );
    }

    if ($centavosMovimiento > $centavosSaldo) {
        throw new RuntimeException(
            "El importe bancario supera el saldo pendiente. " .
            "Movimiento: " .
            number_format($centavosMovimiento / 100, 2, '.', '') .
            "; saldo: " .
            number_format($centavosSaldo / 100, 2, '.', '') . "."
        );
    }

    /*
     * 5. Buscar pagos manuales pendientes ya relacionados
     *    con esta obligación.
     */
    $stmt = $conexion->prepare("
        SELECT
            p.id_pago,
            p.id_cliente,
            p.id_servicio,
            p.monto,
            p.metodo_pago,
            po.monto_aplicado,
            (
                SELECT COUNT(*)
                FROM pagos_obligaciones po2
                WHERE po2.id_pago = p.id_pago
            ) AS numero_aplicaciones,
            (
                SELECT COALESCE(SUM(po2.monto_aplicado), 0)
                FROM pagos_obligaciones po2
                WHERE po2.id_pago = p.id_pago
            ) AS total_aplicado_pago
        FROM pagos_obligaciones po
        INNER JOIN pagos p ON p.id_pago = po.id_pago
        WHERE po.id_obligacion = ?
          AND p.estado_pago = 'PENDIENTE'
        FOR UPDATE
    ");

    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();
    $pagosPendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /*
     * 5.1 Detectar pagos pendientes del mismo cliente y servicio
     *     que no estén vinculados con la obligación actual.
     *     No crear otro pago automáticamente si existen.
     */
    $stmt = $conexion->prepare("
        SELECT p.id_pago
        FROM pagos p
        WHERE p.id_cliente = ?
          AND p.id_servicio = ?
          AND p.estado_pago = 'PENDIENTE'
          AND NOT EXISTS (
              SELECT 1
              FROM pagos_obligaciones po
              WHERE po.id_pago = p.id_pago
                AND po.id_obligacion = ?
          )
        FOR UPDATE
    ");

    $stmt->bind_param(
        'iii',
        $obligacion['id_cliente'],
        $obligacion['id_servicio'],
        $idObligacion
    );

    $stmt->execute();
    $pendientesNoVinculados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($pendientesNoVinculados) > 0) {
        throw new RuntimeException(
            "Existe un pago pendiente del mismo cliente y servicio " .
            "que no está vinculado con esta obligación. " .
            "Requiere revisión manual antes de crear otro pago."
        );
    }

    $coincidencias = [];

    foreach ($pagosPendientes as $pagoPendiente) {
        $montoPago = (int)round((float)$pagoPendiente['monto'] * 100);
        $montoAplicado = (int)round(
            (float)$pagoPendiente['monto_aplicado'] * 100
        );
        $totalAplicadoPago = (int)round(
            (float)$pagoPendiente['total_aplicado_pago'] * 100
        );
        $numeroAplicaciones = (int)$pagoPendiente['numero_aplicaciones'];

        if (
            (int)$pagoPendiente['id_cliente'] ===
                (int)$obligacion['id_cliente'] &&
            (int)$pagoPendiente['id_servicio'] ===
                (int)$obligacion['id_servicio'] &&
            $pagoPendiente['metodo_pago'] === $metodo &&
            $montoPago === $centavosMovimiento &&
            $montoAplicado === $centavosMovimiento &&
            $numeroAplicaciones === 1 &&
            $totalAplicadoPago === $centavosMovimiento &&
            $totalAplicadoPago <= $montoPago
        ) {
            $coincidencias[] = (int)$pagoPendiente['id_pago'];
        }
    }

    if (count($coincidencias) > 1) {
        throw new RuntimeException(
            "Hay varios pagos pendientes coincidentes. Requiere revisión."
        );
    }

    if (count($coincidencias) === 0 && count($pagosPendientes) > 0) {
        throw new RuntimeException(
            "Existe un pago pendiente relacionado con la obligación, " .
            "pero no coincide exactamente con el movimiento bancario. " .
            "Requiere revisión manual."
        );
    }

    $reutilizarPagoPendiente = count($coincidencias) === 1;
    $idPagoPendiente = $reutilizarPagoPendiente
        ? $coincidencias[0]
        : null;

    $centavosRestantes = $centavosSaldo - $centavosMovimiento;

    /*
     * 6. Mostrar los datos que se conciliarían.
     */
    echo "===== VALIDACIÓN DE CONCILIACIÓN =====\n";
    echo "Movimiento: {$movimiento['id_movimiento']}\n";
    echo "Fecha: {$movimiento['fecha_movimiento']}\n";
    echo "Importe: {$movimiento['monto']} {$movimiento['moneda']}\n";
    echo "Ordenante: " . ($movimiento['ordenante'] ?? '') . "\n";
    echo "Método seleccionado: $metodo\n";
    echo "Cliente: {$obligacion['id_cliente']}\n";
    echo "Servicio: {$obligacion['id_servicio']}\n";
    echo "Periodo: {$obligacion['periodo']}\n";
    echo "Obligación: {$obligacion['id_obligacion']}\n";
    echo "Referencia: $referencia\n";
    echo "Importe de obligación: {$obligacion['monto']}\n";
    echo "Aplicaciones anteriores: " .
        number_format($centavosAplicados / 100, 2, '.', '') . "\n";
    echo "Saldo antes del movimiento: " .
        number_format($centavosSaldo / 100, 2, '.', '') . "\n";
    echo "Importe que se aplicará: " .
        number_format($centavosMovimiento / 100, 2, '.', '') . "\n";
    echo "Saldo después del movimiento: " .
        number_format($centavosRestantes / 100, 2, '.', '') . "\n";
    echo "Estado resultante: " .
        ($centavosRestantes === 0 ? 'PAGADA' : 'PENDIENTE') . "\n";
    echo "Pago manual pendiente coincidente: " .
        ($reutilizarPagoPendiente ? "Sí (ID $idPagoPendiente)" : "No") . "\n";

    if (!$confirmar) {
        $conexion->rollback();

        echo "\nSIMULACIÓN CORRECTA.\n";
        echo "No se creó ningún pago ni se modificó ninguna tabla.\n";
        echo "Para confirmar, agrega --confirmar cuando hayas revisado ";
        echo "el método y los datos bancarios.\n";
        exit(0);
    }

    /*
     * 7. Reutilizar el pago manual pendiente si coincide exactamente.
     *    Si no existe, crear un pago nuevo.
     */
    $idCliente = (int)$obligacion['id_cliente'];
    $idServicio = (int)$obligacion['id_servicio'];
    $monto = $centavosMovimiento / 100;
    $fechaPago = $movimiento['fecha_movimiento'];
    $estadoPago = 'PAGADO';

    $observaciones =
        "Conciliación bancaria. Movimiento: $idMovimiento. " .
        "Referencia mensual: $referencia. " .
        "Banco: " . ($movimiento['banco'] ?? 'No especificado');

    if ($reutilizarPagoPendiente) {
        $idPago = $idPagoPendiente;

        $stmt = $conexion->prepare("
            UPDATE pagos
            SET estado_pago = 'PAGADO',
                fecha_pago = ?,
                observaciones = CONCAT(
                    COALESCE(observaciones, ''),
                    CASE
                        WHEN COALESCE(observaciones, '') = '' THEN ''
                        ELSE '\\n'
                    END,
                    ?
                )
            WHERE id_pago = ?
              AND estado_pago = 'PENDIENTE'
        ");

        $stmt->bind_param(
            'ssi',
            $fechaPago,
            $observaciones,
            $idPago
        );

        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException(
                "No se pudo confirmar el pago pendiente existente."
            );
        }

        $stmt->close();

    } else {
        $stmt = $conexion->prepare("
            INSERT INTO pagos (
                id_cliente,
                id_servicio,
                monto,
                fecha_pago,
                metodo_pago,
                referencia,
                estado_pago,
                observaciones
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'iidsssss',
            $idCliente,
            $idServicio,
            $monto,
            $fechaPago,
            $metodo,
            $referencia,
            $estadoPago,
            $observaciones
        );

        $stmt->execute();
        $idPago = $conexion->insert_id;
        $stmt->close();

        /*
         * Solo los pagos nuevos necesitan una aplicación nueva.
         * El pago reutilizado ya está relacionado con la obligación.
         */
        $stmt = $conexion->prepare("
            INSERT INTO pagos_obligaciones (
                id_pago,
                id_obligacion,
                monto_aplicado,
                fecha_aplicacion
            ) VALUES (?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            'iid',
            $idPago,
            $idObligacion,
            $monto
        );

        $stmt->execute();
        $stmt->close();
    }

    /*
     * 9. Marcar la obligación como PAGADA únicamente
     *    cuando el saldo pendiente llegue a cero.
     *    Si es un abono parcial, conserva PENDIENTE.
     */
    if ($centavosRestantes === 0) {
        $stmt = $conexion->prepare("
            UPDATE obligaciones_pago
            SET estado = 'PAGADA'
            WHERE id_obligacion = ?
              AND estado = 'PENDIENTE'
        ");

        $stmt->bind_param('i', $idObligacion);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException(
                "No se pudo marcar la obligación como PAGADA."
            );
        }

        $stmt->close();
    }

    /*
     * 10. Marcar el movimiento bancario como conciliado.
     */
    $stmt = $conexion->prepare("
        UPDATE movimientos_bancarios
        SET estado_conciliacion = 'CONCILIADO',
            id_pago = ?
        WHERE id_movimiento = ?
          AND id_pago IS NULL
          AND estado_conciliacion IN ('PENDIENTE', 'REVISION')
    ");

    $stmt->bind_param('ii', $idPago, $idMovimiento);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException(
            "No se pudo actualizar el movimiento bancario."
        );
    }

    $stmt->close();

    /*
     * 11. Registrar la conciliación en auditoría.
     */
    $detalleAuditoria = sprintf(
        "Movimiento conciliado. Referencia: %s. " .
        "Método: %s. Importe aplicado: %s MXN. " .
        "Saldo restante: %s MXN.",
        $referencia,
        $metodo,
        number_format($centavosMovimiento / 100, 2, '.', ''),
        number_format($centavosRestantes / 100, 2, '.', '')
    );

    $stmt = $conexion->prepare("
        INSERT INTO auditoria_conciliacion_bancaria (
            id_movimiento,
            id_pago,
            tipo_evento,
            usuario_id,
            resultado,
            detalle
        ) VALUES (?, ?, 'CONCILIACION', NULL, 'EXITOSA', ?)
    ");

    $stmt->bind_param(
        'iis',
        $idMovimiento,
        $idPago,
        $detalleAuditoria
    );

    $stmt->execute();
    $stmt->close();

    $conexion->commit();

    echo "\n===== CONCILIACIÓN COMPLETADA =====\n";
    echo "ID de pago creado: $idPago\n";
    echo "Importe aplicado: " .
        number_format($centavosMovimiento / 100, 2, '.', '') . "\n";
    echo "Saldo pendiente: " .
        number_format($centavosRestantes / 100, 2, '.', '') . "\n";
    echo "Estado de obligación: " .
        ($centavosRestantes === 0 ? 'PAGADA' : 'PENDIENTE') . "\n";
    echo "Movimiento marcado como CONCILIADO.\n";

} catch (Throwable $e) {
    $conexion->rollback();

    fwrite(
        STDERR,
        "\nCONCILIACIÓN CANCELADA: " . $e->getMessage() . "\n"
    );

    exit(1);
}
