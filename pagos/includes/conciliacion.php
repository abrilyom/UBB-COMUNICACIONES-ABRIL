<?php
declare(strict_types=1);

/**
 * Biblioteca de conciliación bancaria UBB.
 *
 * Contiene la misma lógica que scripts/confirmar_conciliacion_bancaria.php,
 * pero como funciones reutilizables para que la conciliación automática,
 * el importador y la futura API de BBVA usen exactamente el mismo proceso.
 *
 * Reglas principales:
 *  - Solo se concilian ABONOS en MXN pendientes o en revisión.
 *  - La referencia mensual UBBC<cliente>S<servicio>M<AAAAMMDD> identifica
 *    la obligación exacta.
 *  - Se trabaja en centavos para evitar errores de redondeo.
 *  - Se aceptan pagos parciales; nunca importes mayores al saldo.
 *  - Todo ocurre dentro de una transacción con FOR UPDATE.
 */

const UBB_DIAS_GRACIA = 10;

class ConciliacionException extends RuntimeException
{
}

/**
 * Busca referencias UBB dentro de un texto bancario.
 *
 * @return array{mensuales: string[], permanentes: array<int, array{cliente:int, servicio:int, referencia:string}>}
 */
function extraerReferenciasUBB(string $texto): array
{
    $texto = strtoupper($texto);
    $mensuales = [];
    $permanentes = [];

    // Referencia mensual: UBBC8S6M20261101
    if (preg_match_all('/(?<![A-Z0-9])UBBC(\d+)S(\d+)M(\d{8})(?![A-Z0-9])/', $texto, $m, PREG_SET_ORDER)) {
        foreach ($m as $coincidencia) {
            $mensuales[$coincidencia[0]] = true;
        }
    }

    // Referencia permanente: UBBC8S6 (sin la parte M<fecha>)
    if (preg_match_all('/(?<![A-Z0-9])UBBC(\d+)S(\d+)(?![A-Z0-9])/', $texto, $m, PREG_SET_ORDER)) {
        foreach ($m as $coincidencia) {
            $permanentes[$coincidencia[0]] = [
                'cliente' => (int)$coincidencia[1],
                'servicio' => (int)$coincidencia[2],
                'referencia' => $coincidencia[0],
            ];
        }
    }

    return [
        'mensuales' => array_keys($mensuales),
        'permanentes' => array_values($permanentes),
    ];
}

/**
 * Determina el método de pago a partir del movimiento bancario.
 * Las transferencias SPEI siempre traen clave de rastreo;
 * los depósitos en ventanilla o practicaja normalmente no.
 */
function metodoDesdeMovimiento(array $movimiento): string
{
    $clave = trim((string)($movimiento['clave_rastreo'] ?? ''));
    return $clave !== '' ? 'TRANSFERENCIA' : 'DEPOSITO';
}

/**
 * Para una referencia permanente, devuelve la referencia mensual de la
 * obligación pendiente más antigua de ese cliente y servicio.
 */
function referenciaMensualMasAntigua(mysqli $conexion, int $idCliente, int $idServicio): ?string
{
    $stmt = $conexion->prepare("
        SELECT o.referencia_cobro
        FROM obligaciones_pago o
        WHERE o.id_cliente = ?
          AND o.id_servicio = ?
          AND o.estado = 'PENDIENTE'
          AND o.concepto = 'MENSUALIDAD'
        ORDER BY o.fecha_vencimiento ASC, o.id_obligacion ASC
        LIMIT 1
    ");
    $stmt->bind_param('ii', $idCliente, $idServicio);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fila ? (string)$fila['referencia_cobro'] : null;
}

/**
 * Concilia un movimiento bancario con una obligación mensual.
 *
 * @param bool $confirmar        false = solo simula (rollback al final).
 * @param bool $exigirEnTexto    true = la referencia mensual debe aparecer
 *                               literalmente en el movimiento. Se pone en
 *                               false cuando el cliente pagó con su
 *                               referencia permanente y la mensual se
 *                               resolvió automáticamente.
 *
 * @throws ConciliacionException cuando el movimiento requiere revisión.
 */
function conciliarMovimientoBancario(
    mysqli $conexion,
    int $idMovimiento,
    string $referencia,
    string $metodo,
    bool $confirmar,
    bool $exigirEnTexto = true,
    string $origen = 'CONCILIACION'
): array {
    $referencia = strtoupper(trim($referencia));
    $metodo = strtoupper(trim($metodo));

    if ($idMovimiento < 1) {
        throw new ConciliacionException('ID de movimiento inválido.');
    }

    if (!in_array($metodo, ['TRANSFERENCIA', 'DEPOSITO'], true)) {
        throw new ConciliacionException('Método inválido. Utiliza TRANSFERENCIA o DEPOSITO.');
    }

    if (!preg_match('/^UBBC(\d+)S(\d+)M(\d{8})$/', $referencia, $partes)) {
        throw new ConciliacionException('Se requiere una referencia mensual UBB válida.');
    }

    $clienteReferencia = (int)$partes[1];
    $servicioReferencia = (int)$partes[2];
    $fechaVencimientoReferencia = $partes[3];

    if (!checkdate(
        (int)substr($fechaVencimientoReferencia, 4, 2),
        (int)substr($fechaVencimientoReferencia, 6, 2),
        (int)substr($fechaVencimientoReferencia, 0, 4)
    )) {
        throw new ConciliacionException('La fecha de vencimiento de la referencia no es válida.');
    }

    $periodoReferencia = substr($fechaVencimientoReferencia, 0, 6);

    $conexion->begin_transaction();

    try {
        // 1. Bloquear el movimiento bancario.
        $stmt = $conexion->prepare("
            SELECT id_movimiento, banco, fecha_movimiento, tipo_movimiento,
                   monto, moneda, ordenante, referencia_bancaria, concepto,
                   estado_conciliacion, id_pago
            FROM movimientos_bancarios
            WHERE id_movimiento = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $idMovimiento);
        $stmt->execute();
        $movimiento = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$movimiento) {
            throw new ConciliacionException('Movimiento bancario inexistente.');
        }
        if ($movimiento['tipo_movimiento'] !== 'ABONO') {
            throw new ConciliacionException('No se pueden conciliar cargos.');
        }
        if (!in_array($movimiento['estado_conciliacion'], ['PENDIENTE', 'REVISION'], true)) {
            throw new ConciliacionException('El movimiento no está pendiente de conciliación.');
        }
        if ($movimiento['id_pago'] !== null) {
            throw new ConciliacionException('El movimiento ya tiene un pago asociado.');
        }
        if ($movimiento['moneda'] !== 'MXN') {
            throw new ConciliacionException('La moneda no es MXN. Requiere revisión.');
        }

        // 2. La referencia debe aparecer en el movimiento (salvo referencia permanente).
        if ($exigirEnTexto) {
            $textoBancario = strtoupper(
                ($movimiento['referencia_bancaria'] ?? '') . ' ' . ($movimiento['concepto'] ?? '')
            );
            $patron = '/(?<![A-Z0-9])' . preg_quote($referencia, '/') . '(?![A-Z0-9])/i';

            if (!preg_match($patron, $textoBancario)) {
                throw new ConciliacionException('La referencia mensual no aparece en el movimiento bancario.');
            }
        }

        // 3. Bloquear la obligación mensual.
        $stmt = $conexion->prepare("
            SELECT id_obligacion, id_cliente, id_servicio, periodo,
                   fecha_vencimiento, monto, estado, referencia_cobro
            FROM obligaciones_pago
            WHERE referencia_cobro = ?
            FOR UPDATE
        ");
        $stmt->bind_param('s', $referencia);
        $stmt->execute();
        $obligacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$obligacion) {
            throw new ConciliacionException('No existe una obligación con esa referencia.');
        }

        if (
            (int)$obligacion['id_cliente'] !== $clienteReferencia ||
            (int)$obligacion['id_servicio'] !== $servicioReferencia ||
            $obligacion['periodo'] !== $periodoReferencia ||
            (new DateTimeImmutable($obligacion['fecha_vencimiento']))->format('Ymd') !== $fechaVencimientoReferencia
        ) {
            throw new ConciliacionException('Los datos de la obligación no coinciden con la referencia.');
        }

        if ($obligacion['estado'] !== 'PENDIENTE') {
            throw new ConciliacionException('La obligación no está pendiente. Estado: ' . $obligacion['estado']);
        }

        // 4. Saldo pendiente considerando aplicaciones previas.
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

        $centavosMovimiento = (int)round((float)$movimiento['monto'] * 100);
        $centavosObligacion = (int)round((float)$obligacion['monto'] * 100);
        $centavosAplicados = (int)round((float)$aplicaciones['total_aplicado'] * 100);
        $centavosSaldo = $centavosObligacion - $centavosAplicados;

        if ($centavosSaldo <= 0) {
            throw new ConciliacionException('La obligación no tiene saldo pendiente disponible.');
        }
        if ($centavosMovimiento <= 0) {
            throw new ConciliacionException('El importe del movimiento bancario no es válido.');
        }
        if ($centavosMovimiento > $centavosSaldo) {
            throw new ConciliacionException(sprintf(
                'El importe bancario supera el saldo pendiente. Movimiento: %s; saldo: %s.',
                number_format($centavosMovimiento / 100, 2, '.', ''),
                number_format($centavosSaldo / 100, 2, '.', '')
            ));
        }

        // 5. Pagos manuales pendientes ya relacionados con la obligación.
        $stmt = $conexion->prepare("
            SELECT p.id_pago, p.id_cliente, p.id_servicio, p.monto, p.metodo_pago,
                   po.monto_aplicado,
                   (SELECT COUNT(*) FROM pagos_obligaciones po2
                     WHERE po2.id_pago = p.id_pago) AS numero_aplicaciones,
                   (SELECT COALESCE(SUM(po2.monto_aplicado), 0) FROM pagos_obligaciones po2
                     WHERE po2.id_pago = p.id_pago) AS total_aplicado_pago
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

        // 5.1 Pagos pendientes del mismo servicio no vinculados a esta obligación.
        $idClienteObl = (int)$obligacion['id_cliente'];
        $idServicioObl = (int)$obligacion['id_servicio'];

        $stmt = $conexion->prepare("
            SELECT p.id_pago
            FROM pagos p
            WHERE p.id_cliente = ?
              AND p.id_servicio = ?
              AND p.estado_pago = 'PENDIENTE'
              AND NOT EXISTS (
                  SELECT 1 FROM pagos_obligaciones po
                  WHERE po.id_pago = p.id_pago AND po.id_obligacion = ?
              )
            FOR UPDATE
        ");
        $stmt->bind_param('iii', $idClienteObl, $idServicioObl, $idObligacion);
        $stmt->execute();
        $pendientesNoVinculados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($pendientesNoVinculados) > 0) {
            throw new ConciliacionException(
                'Existe un pago pendiente del mismo cliente y servicio que no está vinculado ' .
                'con esta obligación. Requiere revisión manual.'
            );
        }

        $coincidencias = [];

        foreach ($pagosPendientes as $pp) {
            $montoPago = (int)round((float)$pp['monto'] * 100);
            $montoAplicado = (int)round((float)$pp['monto_aplicado'] * 100);
            $totalAplicadoPago = (int)round((float)$pp['total_aplicado_pago'] * 100);

            if (
                (int)$pp['id_cliente'] === $idClienteObl &&
                (int)$pp['id_servicio'] === $idServicioObl &&
                $pp['metodo_pago'] === $metodo &&
                $montoPago === $centavosMovimiento &&
                $montoAplicado === $centavosMovimiento &&
                (int)$pp['numero_aplicaciones'] === 1 &&
                $totalAplicadoPago === $centavosMovimiento
            ) {
                $coincidencias[] = (int)$pp['id_pago'];
            }
        }

        if (count($coincidencias) > 1) {
            throw new ConciliacionException('Hay varios pagos pendientes coincidentes. Requiere revisión.');
        }
        if (count($coincidencias) === 0 && count($pagosPendientes) > 0) {
            throw new ConciliacionException(
                'Existe un pago pendiente relacionado con la obligación, pero no coincide ' .
                'exactamente con el movimiento bancario. Requiere revisión manual.'
            );
        }

        $idPagoPendiente = $coincidencias[0] ?? null;
        $centavosRestantes = $centavosSaldo - $centavosMovimiento;

        $resumen = [
            'id_movimiento' => $idMovimiento,
            'id_obligacion' => $idObligacion,
            'id_cliente' => $idClienteObl,
            'id_servicio' => $idServicioObl,
            'periodo' => $obligacion['periodo'],
            'referencia' => $referencia,
            'metodo' => $metodo,
            'importe' => $centavosMovimiento / 100,
            'saldo_antes' => $centavosSaldo / 100,
            'saldo_despues' => $centavosRestantes / 100,
            'obligacion_pagada' => $centavosRestantes === 0,
            'reutiliza_pago' => $idPagoPendiente,
            'id_pago' => null,
            'confirmado' => false,
        ];

        if (!$confirmar) {
            $conexion->rollback();
            return $resumen;
        }

        // 6. Crear el pago o confirmar el pago manual pendiente.
        $monto = $centavosMovimiento / 100;
        $fechaPago = $movimiento['fecha_movimiento'];
        $observaciones =
            "Conciliación bancaria ($origen). Movimiento: $idMovimiento. " .
            "Referencia mensual: $referencia. Banco: " . ($movimiento['banco'] ?? 'No especificado');

        if ($idPagoPendiente !== null) {
            $idPago = $idPagoPendiente;

            $stmt = $conexion->prepare("
                UPDATE pagos
                SET estado_pago = 'PAGADO',
                    fecha_pago = ?,
                    observaciones = CONCAT(
                        COALESCE(observaciones, ''),
                        CASE WHEN COALESCE(observaciones, '') = '' THEN '' ELSE '\\n' END,
                        ?
                    )
                WHERE id_pago = ?
                  AND estado_pago = 'PENDIENTE'
            ");
            $stmt->bind_param('ssi', $fechaPago, $observaciones, $idPago);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new ConciliacionException('No se pudo confirmar el pago pendiente existente.');
            }
            $stmt->close();
        } else {
            $estadoPago = 'PAGADO';

            $stmt = $conexion->prepare("
                INSERT INTO pagos (
                    id_cliente, id_servicio, monto, fecha_pago, metodo_pago,
                    referencia, estado_pago, observaciones
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'iidsssss',
                $idClienteObl,
                $idServicioObl,
                $monto,
                $fechaPago,
                $metodo,
                $referencia,
                $estadoPago,
                $observaciones
            );
            $stmt->execute();
            $idPago = (int)$conexion->insert_id;
            $stmt->close();

            $stmt = $conexion->prepare("
                INSERT INTO pagos_obligaciones (id_pago, id_obligacion, monto_aplicado, fecha_aplicacion)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param('iid', $idPago, $idObligacion, $monto);
            $stmt->execute();
            $stmt->close();
        }

        // 7. Marcar la obligación como PAGADA solo si el saldo llega a cero.
        if ($centavosRestantes === 0) {
            $stmt = $conexion->prepare("
                UPDATE obligaciones_pago
                SET estado = 'PAGADA'
                WHERE id_obligacion = ? AND estado = 'PENDIENTE'
            ");
            $stmt->bind_param('i', $idObligacion);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new ConciliacionException('No se pudo marcar la obligación como PAGADA.');
            }
            $stmt->close();
        }

        // 8. Marcar el movimiento como conciliado.
        $stmt = $conexion->prepare("
            UPDATE movimientos_bancarios
            SET estado_conciliacion = 'CONCILIADO', id_pago = ?
            WHERE id_movimiento = ?
              AND id_pago IS NULL
              AND estado_conciliacion IN ('PENDIENTE', 'REVISION')
        ");
        $stmt->bind_param('ii', $idPago, $idMovimiento);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new ConciliacionException('No se pudo actualizar el movimiento bancario.');
        }
        $stmt->close();

        // 9. Auditoría.
        $detalle = sprintf(
            'Movimiento conciliado (%s). Referencia: %s. Método: %s. Importe aplicado: %s MXN. Saldo restante: %s MXN.',
            $origen,
            $referencia,
            $metodo,
            number_format($centavosMovimiento / 100, 2, '.', ''),
            number_format($centavosRestantes / 100, 2, '.', '')
        );

        $stmt = $conexion->prepare("
            INSERT INTO auditoria_conciliacion_bancaria
                (id_movimiento, id_pago, tipo_evento, usuario_id, resultado, detalle)
            VALUES (?, ?, 'CONCILIACION', NULL, 'EXITOSA', ?)
        ");
        $stmt->bind_param('iis', $idMovimiento, $idPago, $detalle);
        $stmt->execute();
        $stmt->close();

        $conexion->commit();

        $resumen['id_pago'] = $idPago;
        $resumen['confirmado'] = true;

        return $resumen;

    } catch (Throwable $e) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }

        if ($e instanceof ConciliacionException) {
            throw $e;
        }

        throw new ConciliacionException('Error técnico: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Envía un movimiento a revisión manual y guarda el motivo.
 */
function enviarMovimientoARevision(
    mysqli $conexion,
    int $idMovimiento,
    string $motivo,
    ?string $referenciaDetectada
): void {
    $conexion->begin_transaction();

    try {
        $stmt = $conexion->prepare("
            UPDATE movimientos_bancarios
            SET estado_conciliacion = 'REVISION'
            WHERE id_movimiento = ?
              AND id_pago IS NULL
              AND estado_conciliacion = 'PENDIENTE'
        ");
        $stmt->bind_param('i', $idMovimiento);
        $stmt->execute();
        $stmt->close();

        $motivo = mb_substr($motivo, 0, 500, 'UTF-8');

        $stmt = $conexion->prepare("
            INSERT INTO conciliacion_revisiones (id_movimiento, motivo, referencia_detectada)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iss', $idMovimiento, $motivo, $referenciaDetectada);
        $stmt->execute();
        $stmt->close();

        $conexion->commit();
    } catch (Throwable $e) {
        $conexion->rollback();
        throw $e;
    }
}

/**
 * Indica si un servicio tiene alguna mensualidad con saldo pendiente
 * cuyo periodo de gracia ya terminó (más de UBB_DIAS_GRACIA días vencida).
 */
function servicioTieneAdeudoVencido(
    mysqli $conexion,
    int $idServicio,
    ?DateTimeImmutable $hoy = null,
    int $excluirObligacion = 0
): bool {
    $hoy ??= new DateTimeImmutable('today');
    $limite = $hoy->modify('-' . UBB_DIAS_GRACIA . ' days')->format('Y-m-d');

    $stmt = $conexion->prepare("
        SELECT 1
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT po.id_obligacion, SUM(po.monto_aplicado) AS total_pagado
            FROM pagos_obligaciones po
            INNER JOIN pagos p ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.id_servicio = ?
          AND o.concepto = 'MENSUALIDAD'
          AND o.estado = 'PENDIENTE'
          AND o.fecha_vencimiento < ?
          AND o.monto > COALESCE(a.total_pagado, 0)
          AND o.id_obligacion <> ?
        LIMIT 1
    ");
    $stmt->bind_param('isi', $idServicio, $limite, $excluirObligacion);
    $stmt->execute();
    $existe = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $existe;
}
