<?php
declare(strict_types=1);

/**
 * Procesador de solicitudes UBB-Grezz.
 * Exclusivamente en modo simulación.
 */

require_once __DIR__ . '/cliente_grezz.php';
require_once __DIR__ . '/aviso_monitoreo.php';

function procesarSolicitudGrezz(
    mysqli $conexion,
    array $solicitud
): array {

    $idSolicitud = (string)($solicitud['id_solicitud'] ?? '');
    $idServicio = (string)($solicitud['id_servicio'] ?? '');

    if (!preg_match('/^[1-9][0-9]*$/', $idServicio)) {
        throw new InvalidArgumentException('ID de servicio inválido.');
    }

    $claveBloqueo = 'ubb_sol_' . substr(
        hash('sha256', $idServicio),
        0,
        48
    );

    $stmtLock = $conexion->prepare('SELECT GET_LOCK(?, 10)');
    $stmtLock->bind_param('s', $claveBloqueo);
    $stmtLock->execute();

    $lockObtenido = (int)$stmtLock->get_result()->fetch_row()[0] === 1;
    $stmtLock->close();

    if (!$lockObtenido) {
        return [
            'id_solicitud' => $idSolicitud,
            'estado' => 'PENDIENTE',
            'codigo_http' => null,
            'mensaje' => 'No se pudo obtener el bloqueo del servicio. Se conserva pendiente.'
        ];
    }

    try {
        // Verificar el registro y su estado antes de contactar a Grezz.
        $stmt = $conexion->prepare("
            SELECT estado, id_servicio
            FROM solicitudes_estado_servicio
            WHERE id_solicitud = ?
            LIMIT 1
        ");

        $stmt->bind_param('s', $idSolicitud);
        $stmt->execute();
        $registro = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$registro) {
            throw new RuntimeException('No existe el registro de la solicitud.');
        }

        if ((string)$registro['id_servicio'] !== $idServicio) {
            throw new RuntimeException('El servicio no coincide con el registro.');
        }

        if ($registro['estado'] !== 'PENDIENTE') {
            return [
                'id_solicitud' => $idSolicitud,
                'estado' => $registro['estado'],
                'codigo_http' => null,
                'mensaje' => 'La solicitud ya no está pendiente; no se reenvió.'
            ];
        }

        $resultado = llamarApiGrezz($solicitud);
        $codigoHttp = (int)$resultado['codigo_http'];

        if (!$resultado['transporte_ok']) {
            $estado = 'PENDIENTE';
            $codigoGuardar = null;
            $mensaje = 'Comunicación no confirmada con Grezz. '
                . 'Se conserva pendiente para recuperación.';
            $respuestaJson = json_encode([
                'error_transporte' => $resultado['error'] ?? 'Error desconocido'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        } else {
            $respuesta = $resultado['respuesta'] ?? [];
            $resultadoGrezz = $respuesta['resultado'] ?? '';
            $mensaje = (string)($respuesta['mensaje'] ?? '');

            $respuestaOk = $codigoHttp >= 200
                && $codigoHttp < 300
                && ($respuesta['ok'] ?? false) === true;

            // Según Grezz (26/11/2026): en modo real responde APLICADO (ya quedó hecho)
            // o SIN_CAMBIOS (ya estaba así); las dos cuentan como COMPLETADA.
            // Mientras cliente_grezz.php envíe "simular": true, todo queda SIMULADA.
            if ($respuestaOk && $resultadoGrezz === 'APLICADO') {
                $estado = 'COMPLETADA';
            } elseif ($respuestaOk && $resultadoGrezz === 'SIN_CAMBIOS' && !GREZZ_SIMULAR) {
                $estado = 'COMPLETADA';
            } elseif ($respuestaOk && in_array($resultadoGrezz, ['SIMULADO', 'SIN_CAMBIOS'], true)) {
                $estado = 'SIMULADA';
            } elseif ($codigoHttp >= 400 && $codigoHttp < 500) {
                $estado = 'RECHAZADA';
            } else {
                // Respuesta no concluyente: conservar para reconciliación.
                $estado = 'PENDIENTE';
            }

            $codigoGuardar = $codigoHttp > 0 ? $codigoHttp : null;

            if ($mensaje === '') {
                $mensaje = 'Respuesta de Grezz: '
                    . ($resultadoGrezz !== ''
                        ? $resultadoGrezz
                        : 'resultado no concluyente');
            }

            $respuestaJson = (string)(
                $resultado['respuesta_original']
                ?? json_encode(
                    $respuesta,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        $stmtGuardar = $conexion->prepare("
            UPDATE solicitudes_estado_servicio
            SET estado = ?,
                codigo_http = ?,
                mensaje_respuesta = ?,
                respuesta_json = ?,
                fecha_respuesta = ?
            WHERE id_solicitud = ?
              AND estado = 'PENDIENTE'
            LIMIT 1
        ");

        $fechaRespuesta = $estado === 'PENDIENTE'
            ? null
            : date('Y-m-d H:i:s');

        $stmtGuardar->bind_param(
            'sissss',
            $estado,
            $codigoGuardar,
            $mensaje,
            $respuestaJson,
            $fechaRespuesta,
            $idSolicitud
        );

        if (!$stmtGuardar->execute()) {
            $error = $stmtGuardar->error;
            $stmtGuardar->close();
            throw new RuntimeException(
                'No se pudo guardar la respuesta: ' . $error
            );
        }

        $actualizada = $stmtGuardar->affected_rows === 1;
        $stmtGuardar->close();

        // Corte o reactivación real: avisar al monitoreo (Telegram) para que
        // lo reporte como corte por pago. Solo una vez por solicitud y sin
        // detener el flujo si el aviso falla.
        if ($actualizada && $estado === 'COMPLETADA') {
            try {
                avisarMonitoreo(
                    (string)($solicitud['accion'] ?? ''),
                    (int)$idServicio,
                    (string)($solicitud['motivo'] ?? ''),
                    $resultado['respuesta'] ?? null
                );
            } catch (Throwable $e) {
                error_log('Aviso a monitoreo con error: ' . $e->getMessage());
            }
        }

        return [
            'id_solicitud' => $idSolicitud,
            'estado' => $estado,
            'codigo_http' => $codigoGuardar,
            'mensaje' => $mensaje
        ];

    } finally {
        $stmtLiberar = $conexion->prepare('SELECT RELEASE_LOCK(?)');
        $stmtLiberar->bind_param('s', $claveBloqueo);
        $stmtLiberar->execute();
        $stmtLiberar->close();
    }
}
