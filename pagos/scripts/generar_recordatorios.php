<?php
declare(strict_types=1);

/*
 * GENERADOR DIARIO DE RECORDATORIOS DE PAGO UBB
 *
 * Encola (no envía) los avisos del día:
 *   - RECORDATORIO_PREVIO: la mensualidad vence en 3 días.
 *   - RECORDATORIO_HOY:    la mensualidad vence hoy.
 *   - ULTIMO_AVISO:        hoy termina el periodo de gracia (día 10).
 *
 * Cada aviso se encola una sola vez por mensualidad, aunque el script se
 * ejecute varias veces el mismo día. El envío lo hace enviar_notificaciones.php.
 *
 * Uso:
 *   php generar_recordatorios.php               (simulación)
 *   php generar_recordatorios.php --confirmar   (encola los avisos)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/conciliacion.php';
require_once __DIR__ . '/../includes/notificaciones_cola.php';

const UBB_DIAS_RECORDATORIO_PREVIO = 3;

$opciones = array_slice($argv, 1);

foreach ($opciones as $opcion) {
    if ($opcion !== '--confirmar') {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$confirmar = in_array('--confirmar', $opciones, true);

try {
    $conexion = conectarPagosCli();
    $hoy = new DateTimeImmutable('today');

    $avisos = [
        'RECORDATORIO_PREVIO' => $hoy->modify('+' . UBB_DIAS_RECORDATORIO_PREVIO . ' days'),
        'RECORDATORIO_HOY' => $hoy,
        'ULTIMO_AVISO' => $hoy->modify('-' . UBB_DIAS_GRACIA . ' days'),
    ];

    $stmt = $conexion->prepare("
        SELECT o.id_obligacion, o.id_cliente, o.id_servicio, o.fecha_vencimiento,
               o.referencia_cobro,
               o.monto - COALESCE(a.total_pagado, 0) AS saldo
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
          AND DATE(o.fecha_vencimiento) = ?
          AND o.monto > COALESCE(a.total_pagado, 0)
    ");

    $total = 0;

    foreach ($avisos as $tipo => $fechaVencimiento) {
        $fecha = $fechaVencimiento->format('Y-m-d');
        $stmt->bind_param('s', $fecha);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo "$tipo (vencimiento $fecha): " . count($filas) . " mensualidad(es)\n";

        foreach ($filas as $fila) {
            $total++;

            if (!$confirmar) {
                continue;
            }

            $nueva = encolarNotificacion(
                $conexion,
                (int)$fila['id_cliente'],
                (int)$fila['id_servicio'],
                (int)$fila['id_obligacion'],
                $tipo,
                (string)$fila['id_obligacion'],
                [
                    'fecha' => (new DateTimeImmutable($fila['fecha_vencimiento']))->format('d/m/Y'),
                    'saldo' => number_format((float)$fila['saldo'], 2, '.', ','),
                    'referencia' => (string)$fila['referencia_cobro'],
                ]
            );

            if (!$nueva) {
                echo "  · Obligación {$fila['id_obligacion']}: ya estaba encolado.\n";
            }
        }
    }

    $stmt->close();

    echo "\nTotal de avisos: $total\n";
    echo $confirmar
        ? "Avisos encolados. Ejecuta enviar_notificaciones.php para mandarlos.\n"
        : "SIMULACIÓN COMPLETADA. Para encolar, agrega --confirmar.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
