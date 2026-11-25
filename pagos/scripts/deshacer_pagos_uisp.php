<?php
declare(strict_types=1);

/*
 * DESHACER LA APLICACIÓN DE PAGOS DE UISP
 *
 * Revierte exactamente lo que hizo aplicar_pagos_uisp.php --confirmar:
 *   1. Borra los pagos creados desde UISP (referencia UISP-<id>) y sus
 *      aplicaciones en pagos_obligaciones.
 *   2. Regresa a PENDIENTE las mensualidades que quedaron PAGADAS por esos
 *      pagos, solo si sin ellos ya no están cubiertas.
 *   3. Limpia estado_aplicacion en uisp_pagos_importacion, para poder volver
 *      a aplicar con otras reglas.
 *
 * NO toca pagos registrados a mano ni conciliaciones bancarias, ni borra los
 * pagos traídos de UISP (el historial queda igual). Todo ocurre en una sola
 * transacción: o se deshace completo o no se cambia nada. Antes de borrar,
 * guarda un respaldo JSON en pagos/reportes/.
 *
 * Uso:
 *   php deshacer_pagos_uisp.php              (simulación: muestra qué haría)
 *   php deshacer_pagos_uisp.php --confirmar  (deshace)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';

const DESHACER_PROCESO = 'DESHACER_PAGOS_UISP';

$opciones = array_slice($argv, 1);
foreach ($opciones as $opcion) {
    if ($opcion !== '--confirmar') {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}
$confirmar = in_array('--confirmar', $opciones, true);

$conexion = null;
$bloqueo = false;
$inicio = new DateTimeImmutable();
$contadores = ['pagos' => 0, 'importe' => 0.0, 'aplicaciones' => 0, 'mensualidades_reabiertas' => 0, 'marcas_limpiadas' => 0];

try {
    $conexion = conectarPagosCli();

    // Mismo bloqueo que aplicar_pagos_uisp.php: no pueden correr a la vez.
    obtenerBloqueoScript($conexion, 'ubb_aplicar_pagos_uisp');
    $bloqueo = true;

    echo $confirmar
        ? "MODO: CONFIRMAR (se deshará la aplicación)\n\n"
        : "MODO: SIMULACIÓN (no se cambiará nada)\n\n";

    $conexion->begin_transaction();

    // 1. Pagos creados por aplicar_pagos_uisp.php.
    $pagos = $conexion->query("
        SELECT * FROM pagos
        WHERE referencia LIKE 'UISP-%'
          AND observaciones LIKE 'Pago registrado en UISP%'
        FOR UPDATE
    ")->fetch_all(MYSQLI_ASSOC);

    if ($pagos === []) {
        // Sin pagos creados, solo pueden quedar marcas de revisión o histórico.
        $contadores['marcas_limpiadas'] = (int)$conexion->query("
            SELECT COUNT(*) FROM uisp_pagos_importacion WHERE estado_aplicacion IS NOT NULL
        ")->fetch_row()[0];

        if ($confirmar && $contadores['marcas_limpiadas'] > 0) {
            $conexion->query("
                UPDATE uisp_pagos_importacion
                SET estado_aplicacion = NULL, id_pago_ubb = NULL, id_cliente_ubb = NULL,
                    id_servicio_ubb = NULL, motivo_revision = NULL, aplicado_en = NULL
                WHERE estado_aplicacion IS NOT NULL
            ");
            $conexion->commit();
        } else {
            $conexion->rollback();
        }

        echo "No hay pagos de UISP aplicados. Marcas de revisión/histórico: {$contadores['marcas_limpiadas']}"
            . ($confirmar ? " (limpiadas)" : '') . ".\n";
    } else {
        $ids = implode(',', array_map(fn($p) => (int)$p['id_pago'], $pagos));
        $contadores['pagos'] = count($pagos);
        $contadores['importe'] = round(array_sum(array_map(fn($p) => (float)$p['monto'], $pagos)), 2);

        // Si algo más ya usa estos pagos (p. ej. una conciliación bancaria), no se toca nada.
        $usado = $conexion->query("SELECT COUNT(*) FROM movimientos_bancarios WHERE id_pago IN ($ids)")->fetch_row()[0];
        if ((int)$usado > 0) {
            throw new RuntimeException("$usado movimientos bancarios apuntan a pagos de UISP. Revísalos antes de deshacer.");
        }

        $aplicaciones = $conexion->query("
            SELECT * FROM pagos_obligaciones WHERE id_pago IN ($ids)
        ")->fetch_all(MYSQLI_ASSOC);
        $contadores['aplicaciones'] = count($aplicaciones);

        // 2. Mensualidades PAGADAS que, sin los pagos de UISP, ya no quedan cubiertas.
        $reabrir = $conexion->query("
            SELECT o.id_obligacion, o.monto,
                   COALESCE((
                       SELECT SUM(po.monto_aplicado)
                       FROM pagos_obligaciones po
                       INNER JOIN pagos p ON p.id_pago = po.id_pago
                       WHERE po.id_obligacion = o.id_obligacion
                         AND p.estado_pago = 'PAGADO'
                         AND p.id_pago NOT IN ($ids)
                   ), 0) AS cubierto_sin_uisp
            FROM obligaciones_pago o
            WHERE o.estado = 'PAGADA'
              AND o.id_obligacion IN (SELECT id_obligacion FROM pagos_obligaciones WHERE id_pago IN ($ids))
            FOR UPDATE
        ")->fetch_all(MYSQLI_ASSOC);

        $reabrir = array_filter(
            $reabrir,
            fn($o) => (int)round((float)$o['cubierto_sin_uisp'] * 100) < (int)round((float)$o['monto'] * 100)
        );
        $contadores['mensualidades_reabiertas'] = count($reabrir);

        $contadores['marcas_limpiadas'] = (int)$conexion->query("
            SELECT COUNT(*) FROM uisp_pagos_importacion WHERE estado_aplicacion IS NOT NULL
        ")->fetch_row()[0];

        echo "Pagos de UISP a borrar:          {$contadores['pagos']} | $" . number_format($contadores['importe'], 2) . "\n";
        echo "Aplicaciones a mensualidades:    {$contadores['aplicaciones']}\n";
        echo "Mensualidades que vuelven a PENDIENTE: {$contadores['mensualidades_reabiertas']}\n";
        echo "Marcas a limpiar (aplicado/revisión/histórico): {$contadores['marcas_limpiadas']}\n";

        if (!$confirmar) {
            $conexion->rollback();
            echo "\nSimulación: no se cambió nada. Agrega --confirmar para deshacer.\n";
        } else {
            // Respaldo de todo lo que se va a borrar o cambiar.
            $carpeta = __DIR__ . '/../reportes';
            if (!is_dir($carpeta) && !mkdir($carpeta, 0750, true)) {
                throw new RuntimeException('No se pudo crear la carpeta de respaldos.');
            }
            $archivo = $carpeta . '/respaldo_deshacer_uisp_' . date('Ymd_His') . '.json';
            $respaldo = json_encode([
                'fecha' => date('c'),
                'pagos' => $pagos,
                'pagos_obligaciones' => $aplicaciones,
                'mensualidades_reabiertas' => array_values(array_map(fn($o) => (int)$o['id_obligacion'], $reabrir)),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            if ($respaldo === false || file_put_contents($archivo, $respaldo) === false) {
                throw new RuntimeException('No se pudo guardar el respaldo; no se deshizo nada.');
            }

            if ($reabrir) {
                $idsReabrir = implode(',', array_map(fn($o) => (int)$o['id_obligacion'], $reabrir));
                $conexion->query("
                    UPDATE obligaciones_pago SET estado = 'PENDIENTE'
                    WHERE id_obligacion IN ($idsReabrir) AND estado = 'PAGADA'
                ");
            }

            $conexion->query("DELETE FROM pagos_obligaciones WHERE id_pago IN ($ids)");
            $conexion->query("DELETE FROM pagos WHERE id_pago IN ($ids)");
            $conexion->query("
                UPDATE uisp_pagos_importacion
                SET estado_aplicacion = NULL, id_pago_ubb = NULL, id_cliente_ubb = NULL,
                    id_servicio_ubb = NULL, motivo_revision = NULL, aplicado_en = NULL
                WHERE estado_aplicacion IS NOT NULL
            ");

            $conexion->commit();
            echo "\nListo: aplicación deshecha. Respaldo: $archivo\n";
            echo "Para volver a aplicar: php aplicar_pagos_uisp.php (simulación) y luego --confirmar.\n";
        }
    }

    registrarEjecucion($conexion, DESHACER_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\nNo se cambió nada.\n");

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, DESHACER_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
    }

    $codigoSalida = 1;
} finally {
    if ($conexion instanceof mysqli) {
        if ($bloqueo) {
            liberarBloqueoScript($conexion, 'ubb_aplicar_pagos_uisp');
        }
        $conexion->close();
    }
}

exit($codigoSalida ?? 0);
