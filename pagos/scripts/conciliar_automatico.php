<?php
declare(strict_types=1);

/*
 * CONCILIACIÓN BANCARIA AUTOMÁTICA UBB
 *
 * Revisa los abonos bancarios PENDIENTES, detecta la referencia UBB en el
 * concepto o la referencia del banco y los concilia con la mensualidad
 * correspondiente. Cuando una mensualidad queda pagada y el servicio ya no
 * tiene adeudos vencidos, solicita a Grezz la reactivación.
 *
 * Los movimientos que no se pueden conciliar con seguridad pasan a
 * REVISION con el motivo guardado en conciliacion_revisiones.
 *
 * Uso:
 *   php conciliar_automatico.php                  (simulación, no guarda nada)
 *   php conciliar_automatico.php --confirmar      (guarda pagos y solicitudes)
 *   php conciliar_automatico.php --confirmar --incluir-revision
 *       (vuelve a intentar los movimientos que estaban en REVISION)
 *
 * Entorno de pruebas:
 *   UBB_PAGOS_ENTORNO=pruebas php conciliar_automatico.php
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/conciliacion.php';
require_once __DIR__ . '/../includes/automatizacion_servicios.php';
require_once __DIR__ . '/../includes/notificaciones_cola.php';

$opciones = array_slice($argv, 1);
$opcionesValidas = ['--confirmar', '--incluir-revision'];

foreach ($opciones as $opcion) {
    if (!in_array($opcion, $opcionesValidas, true)) {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$confirmar = in_array('--confirmar', $opciones, true);
$incluirRevision = in_array('--incluir-revision', $opciones, true);
$nombreBloqueo = 'ubb_conciliacion_automatica';

$conexion = null;
$bloqueo = false;

$contadores = [
    'revisados' => 0,
    'conciliados' => 0,
    'parciales' => 0,
    'revision' => 0,
    'reactivaciones' => 0,
];

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n\n";

    $estados = $incluirRevision ? "'PENDIENTE', 'REVISION'" : "'PENDIENTE'";

    $movimientos = $conexion->query("
        SELECT id_movimiento, fecha_movimiento, monto, moneda, ordenante,
               referencia_bancaria, concepto, clave_rastreo, estado_conciliacion
        FROM movimientos_bancarios
        WHERE tipo_movimiento = 'ABONO'
          AND id_pago IS NULL
          AND estado_conciliacion IN ($estados)
        ORDER BY fecha_movimiento ASC, id_movimiento ASC
        LIMIT 500
    ")->fetch_all(MYSQLI_ASSOC);

    echo "Movimientos por revisar: " . count($movimientos) . "\n\n";

    foreach ($movimientos as $movimiento) {
        $contadores['revisados']++;
        $idMovimiento = (int)$movimiento['id_movimiento'];
        $texto = ($movimiento['referencia_bancaria'] ?? '') . ' ' . ($movimiento['concepto'] ?? '');
        $referencias = extraerReferenciasUBB($texto);
        $metodo = metodoDesdeMovimiento($movimiento);

        echo "— Movimiento $idMovimiento | {$movimiento['fecha_movimiento']} | "
            . "{$movimiento['monto']} {$movimiento['moneda']} | $metodo\n";

        $referencia = null;
        $exigirEnTexto = true;
        $motivoRevision = null;

        if (count($referencias['mensuales']) === 1) {
            $referencia = $referencias['mensuales'][0];
        } elseif (count($referencias['mensuales']) > 1) {
            $motivoRevision = 'El movimiento contiene varias referencias mensuales distintas.';
        } elseif (count($referencias['permanentes']) === 1) {
            $permanente = $referencias['permanentes'][0];
            $referencia = referenciaMensualMasAntigua(
                $conexion,
                $permanente['cliente'],
                $permanente['servicio']
            );
            $exigirEnTexto = false;

            if ($referencia === null) {
                $motivoRevision = "Referencia permanente {$permanente['referencia']} sin mensualidades pendientes.";
            } else {
                echo "  Referencia permanente {$permanente['referencia']} → mensualidad $referencia\n";
            }
        } elseif (count($referencias['permanentes']) > 1) {
            $motivoRevision = 'El movimiento contiene varias referencias permanentes distintas.';
        } else {
            $motivoRevision = 'No se encontró ninguna referencia UBB en el movimiento.';
        }

        if ($referencia !== null) {
            try {
                $resultado = conciliarMovimientoBancario(
                    $conexion,
                    $idMovimiento,
                    $referencia,
                    $metodo,
                    $confirmar,
                    $exigirEnTexto,
                    'AUTOMATICA'
                );

                $contadores['conciliados']++;

                if (!$resultado['obligacion_pagada']) {
                    $contadores['parciales']++;
                }

                printf(
                    "  ✔ %s: cliente %d, servicio %d, periodo %s. Aplicado %.2f, saldo restante %.2f%s\n",
                    $confirmar ? 'CONCILIADO' : 'SE CONCILIARÍA',
                    $resultado['id_cliente'],
                    $resultado['id_servicio'],
                    $resultado['periodo'],
                    $resultado['importe'],
                    $resultado['saldo_despues'],
                    $resultado['id_pago'] ? " (pago {$resultado['id_pago']})" : ''
                );

                if ($confirmar) {
                    encolarNotificacion(
                        $conexion,
                        $resultado['id_cliente'],
                        $resultado['id_servicio'],
                        $resultado['id_obligacion'],
                        'PAGO_RECIBIDO',
                        'pago-' . $idMovimiento,
                        [
                            'monto' => number_format($resultado['importe'], 2, '.', ','),
                            'saldo' => number_format($resultado['saldo_despues'], 2, '.', ','),
                            'referencia' => $resultado['referencia'],
                        ]
                    );
                }

                // Reactivar si ya no debe nada vencido.
                // En simulación la mensualidad aún no está pagada, por eso se excluye.
                if (
                    $resultado['obligacion_pagada'] &&
                    !servicioTieneAdeudoVencido(
                        $conexion,
                        $resultado['id_servicio'],
                        null,
                        $resultado['id_obligacion']
                    )
                ) {
                    $estadoGrezz = estadoServicioEnGrezz($resultado['id_servicio']);

                    // Si Grezz no responde, se envía igual: para un servicio activo
                    // Grezz responde SIN_CAMBIOS y no afecta al cliente.
                    if ($estadoGrezz === null || $estadoGrezz === 'SUSPENDIDO') {
                        $solicitud = solicitarCambioServicio(
                            $conexion,
                            $resultado['id_cliente'],
                            $resultado['id_servicio'],
                            'REACTIVAR',
                            "Pago confirmado por conciliación bancaria. Referencia {$resultado['referencia']}.",
                            $resultado['referencia'],
                            'CONCILIACION_AUTOMATICA',
                            $confirmar
                        );

                        $contadores['reactivaciones']++;
                        echo "  ↻ Reactivación: {$solicitud['mensaje']}\n";
                    }
                }
            } catch (ConciliacionException $e) {
                $motivoRevision = $e->getMessage();
            }
        }

        if ($motivoRevision !== null) {
            $contadores['revision']++;
            echo "  ✖ A REVISIÓN: $motivoRevision\n";

            if ($confirmar && $movimiento['estado_conciliacion'] === 'PENDIENTE') {
                enviarMovimientoARevision($conexion, $idMovimiento, $motivoRevision, $referencia);
            }
        }

        echo "\n";
    }

    echo "===== RESUMEN =====\n";
    echo "Movimientos revisados:        {$contadores['revisados']}\n";
    echo "Conciliados:                  {$contadores['conciliados']}\n";
    echo "  de ellos pagos parciales:   {$contadores['parciales']}\n";
    echo "Enviados a revisión manual:   {$contadores['revision']}\n";
    echo "Reactivaciones solicitadas:   {$contadores['reactivaciones']}\n";

    if (!$confirmar) {
        echo "\nSIMULACIÓN COMPLETADA. Para guardar, agrega --confirmar.\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($bloqueo && $conexion instanceof mysqli) {
        liberarBloqueoScript($conexion, $nombreBloqueo);
    }
}
