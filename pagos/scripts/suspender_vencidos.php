<?php
declare(strict_types=1);

/*
 * SUSPENSIÓN AUTOMÁTICA POR FALTA DE PAGO UBB
 *
 * Regla: el servicio se suspende cuando una mensualidad con saldo pendiente
 * supera los 10 días de gracia (se corta el día 11 después del vencimiento).
 *
 * Antes de solicitar la suspensión verifica:
 *   - que la mensualidad siga con saldo (puede haberse pagado hoy),
 *   - que no haya abonos bancarios sin conciliar con su referencia,
 *   - que el servicio no esté ya suspendido en Grezz,
 *   - que no exista otra solicitud pendiente para el servicio.
 *
 * Uso:
 *   php suspender_vencidos.php               (simulación, no guarda nada)
 *   php suspender_vencidos.php --confirmar   (registra y envía a Grezz)
 *
 * Recomendado: ejecutar primero conciliar_automatico.php --confirmar
 * para que los pagos del día ya estén aplicados.
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/conciliacion.php';
require_once __DIR__ . '/../includes/automatizacion_servicios.php';
require_once __DIR__ . '/../includes/notificaciones_cola.php';

$opciones = array_slice($argv, 1);

foreach ($opciones as $opcion) {
    if ($opcion !== '--confirmar') {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$confirmar = in_array('--confirmar', $opciones, true);
$nombreBloqueo = 'ubb_suspension_automatica';
$conexion = null;
$bloqueo = false;

$contadores = [
    'candidatos' => 0,
    'ya_suspendidos' => 0,
    'con_abono_sin_conciliar' => 0,
    'solicitadas' => 0,
    'no_registradas' => 0,
];

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    echo $confirmar
        ? "MODO: CONFIRMAR (se enviarán solicitudes a Grezz)\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n";

    $hoy = new DateTimeImmutable('today');
    $limite = $hoy->modify('-' . UBB_DIAS_GRACIA . ' days')->format('Y-m-d');

    echo "Fecha: " . $hoy->format('Y-m-d')
        . " | Se suspenden mensualidades vencidas antes del $limite\n\n";

    // Mensualidad vencida más antigua con saldo, por servicio.
    $stmt = $conexion->prepare("
        SELECT o.id_servicio,
               o.id_cliente,
               MIN(o.fecha_vencimiento) AS vencimiento_mas_antiguo,
               COUNT(*) AS mensualidades_vencidas,
               SUM(o.monto - COALESCE(a.total_pagado, 0)) AS saldo_vencido,
               SUBSTRING_INDEX(
                   GROUP_CONCAT(o.referencia_cobro ORDER BY o.fecha_vencimiento ASC),
                   ',', 1
               ) AS referencia
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
          AND o.fecha_vencimiento < ?
          AND o.monto > COALESCE(a.total_pagado, 0)
        GROUP BY o.id_servicio, o.id_cliente
        ORDER BY vencimiento_mas_antiguo ASC
    ");
    $stmt->bind_param('s', $limite);
    $stmt->execute();
    $candidatos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $contadores['candidatos'] = count($candidatos);
    echo "Servicios fuera del periodo de gracia: " . count($candidatos) . "\n\n";

    if (!$candidatos) {
        echo "No hay servicios por suspender.\n";
        exit(0);
    }

    $estadosGrezz = mapaEstadosGrezz();

    if ($estadosGrezz === null) {
        // Sin el estado real no se puede saber quién ya está suspendido.
        throw new RuntimeException(
            'No fue posible consultar el estado de los servicios en Grezz. ' .
            'No se envió ninguna suspensión.'
        );
    }

    // Abonos que aún no se concilian pero mencionan al servicio.
    $stmtAbono = $conexion->prepare("
        SELECT COUNT(*)
        FROM movimientos_bancarios
        WHERE tipo_movimiento = 'ABONO'
          AND id_pago IS NULL
          AND estado_conciliacion IN ('PENDIENTE', 'REVISION')
          AND (
              UPPER(CONCAT_WS(' ', referencia_bancaria, concepto)) REGEXP ?
          )
    ");

    foreach ($candidatos as $fila) {
        $idServicio = (int)$fila['id_servicio'];
        $idCliente = (int)$fila['id_cliente'];
        $estadoActual = $estadosGrezz[$idServicio] ?? 'DESCONOCIDO';

        printf(
            "— Servicio %d (cliente %d) | vencida desde %s | %d mensualidad(es) | saldo %.2f | Grezz: %s\n",
            $idServicio,
            $idCliente,
            $fila['vencimiento_mas_antiguo'],
            (int)$fila['mensualidades_vencidas'],
            (float)$fila['saldo_vencido'],
            $estadoActual
        );

        if ($estadoActual === 'SUSPENDIDO') {
            $contadores['ya_suspendidos']++;
            echo "  · Ya está suspendido. Sin cambios.\n\n";
            continue;
        }

        // Protección: si llegó dinero con su referencia, no cortar todavía.
        $patron = '(^|[^A-Z0-9])UBBC' . $idCliente . 'S' . $idServicio . '([^0-9]|$)';
        $stmtAbono->bind_param('s', $patron);
        $stmtAbono->execute();
        $abonos = (int)$stmtAbono->get_result()->fetch_row()[0];

        if ($abonos > 0) {
            $contadores['con_abono_sin_conciliar']++;
            echo "  · Tiene $abonos abono(s) bancario(s) sin conciliar con su referencia. "
                . "No se suspende hasta revisarlo.\n\n";
            continue;
        }

        $motivo = sprintf(
            'Falta de pago: mensualidad con vencimiento %s sin liquidar después de %d días de gracia. Saldo vencido: %.2f MXN.',
            $fila['vencimiento_mas_antiguo'],
            UBB_DIAS_GRACIA,
            (float)$fila['saldo_vencido']
        );

        $solicitud = solicitarCambioServicio(
            $conexion,
            $idCliente,
            $idServicio,
            'SUSPENDER',
            $motivo,
            (string)$fila['referencia'],
            'SUSPENSION_AUTOMATICA',
            $confirmar
        );

        echo "  ⏸ {$solicitud['mensaje']}\n\n";

        if (!$confirmar) {
            $contadores['solicitadas']++;
            continue;
        }

        if ($solicitud['registrada']) {
            $contadores['solicitadas']++;

            encolarNotificacion(
                $conexion,
                $idCliente,
                $idServicio,
                null,
                'SUSPENSION',
                'suspension-' . $solicitud['id_solicitud'],
                [
                    'saldo' => number_format((float)$fila['saldo_vencido'], 2, '.', ','),
                    'referencia' => (string)$fila['referencia'],
                ]
            );
        } else {
            $contadores['no_registradas']++;
        }
    }

    $stmtAbono->close();

    echo "===== RESUMEN =====\n";
    echo "Servicios fuera de gracia:          {$contadores['candidatos']}\n";
    echo "Ya suspendidos en Grezz:            {$contadores['ya_suspendidos']}\n";
    echo "Detenidos por abono sin conciliar:  {$contadores['con_abono_sin_conciliar']}\n";
    echo ($confirmar ? "Suspensiones solicitadas:           " : "Suspensiones que se solicitarían:   ")
        . "{$contadores['solicitadas']}\n";

    if ($confirmar) {
        echo "No registradas (solicitud previa):  {$contadores['no_registradas']}\n";
    } else {
        echo "\nSIMULACIÓN COMPLETADA. Para enviar las solicitudes, agrega --confirmar.\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($bloqueo && $conexion instanceof mysqli) {
        liberarBloqueoScript($conexion, $nombreBloqueo);
    }
}
