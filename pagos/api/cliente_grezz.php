<?php
declare(strict_types=1);

/**
 * Cliente HTTP para la API privada de Grezz.
 *
 * IMPORTANTE:
 * Este cliente trabaja exclusivamente en modo simulación.
 * No ejecuta cambios reales en RADIUS, MikroTik ni UISP.
 */

function llamarApiGrezz(array $solicitud): array
{
    $idServicio = (string)($solicitud['id_servicio'] ?? '');
    $idSolicitud = (string)($solicitud['id_solicitud'] ?? '');
    $accion = strtoupper(trim((string)($solicitud['accion'] ?? '')));
    $motivo = trim((string)($solicitud['motivo'] ?? ''));
    $referencia = trim((string)($solicitud['referencia_pago'] ?? ''));

    if (!preg_match('/^[1-9][0-9]*$/', $idServicio)) {
        throw new InvalidArgumentException('ID de servicio inválido.');
    }

    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $idSolicitud
    )) {
        throw new InvalidArgumentException('UUID de solicitud inválido.');
    }

    if (!in_array($accion, ['SUSPENDER', 'REACTIVAR'], true)) {
        throw new InvalidArgumentException('Acción inválida para Grezz.');
    }

    if ($motivo === '') {
        throw new InvalidArgumentException('El motivo es obligatorio.');
    }

    $archivoToken = '/etc/ubb-grezz.token';

    if (!is_readable($archivoToken)) {
        throw new RuntimeException('Token de Grezz no disponible.');
    }

    $token = trim((string)file_get_contents($archivoToken));

    if ($token === '') {
        throw new RuntimeException('Token de Grezz vacío.');
    }

    $accionUrl = strtolower($accion);
    $url = 'http://192.168.100.223/api-radius/v1/servicios/'
        . rawurlencode($idServicio) . '/' . $accionUrl;

    $datos = [
        'solicitud_id' => $idSolicitud,
        'motivo' => $motivo,
        'referencia' => $referencia,
        'simular' => true
    ];

    $json = json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('No se pudo iniciar la conexión HTTP.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);

    $respuesta = curl_exec($ch);
    $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorCurl = curl_error($ch);

    if ($respuesta === false) {
        return [
            'transporte_ok' => false,
            'codigo_http' => $codigoHttp,
            'error' => $errorCurl,
            'respuesta' => null
        ];
    }

    $respuestaJson = json_decode($respuesta, true);

    return [
        'transporte_ok' => true,
        'codigo_http' => $codigoHttp,
        'error' => null,
        'respuesta' => is_array($respuestaJson)
            ? $respuestaJson
            : null,
        'respuesta_original' => $respuesta
    ];
}

/** Consulta de solo lectura del estado de un servicio en Grezz. */
function consultarServicioGrezz(int $idServicio): array
{
    if ($idServicio < 1) {
        throw new InvalidArgumentException("ID de servicio inválido.");
    }

    $archivoToken = '/etc/ubb-grezz-consulta.token';

    if (!is_readable($archivoToken)) {
        throw new RuntimeException("Token de consulta no disponible.");
    }

    $token = trim((string)file_get_contents($archivoToken));
    if ($token === '') {
        throw new RuntimeException("Token de consulta vacío.");
    }

    $url = 'http://192.168.100.223/api-radius/v1/servicios/' . $idServicio;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);

    $respuesta = curl_exec($ch);
    $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorCurl = curl_error($ch);

    if ($respuesta === false) {
        return [
            'transporte_ok' => false,
            'codigo_http' => $codigoHttp,
            'error' => $errorCurl,
            'respuesta' => null
        ];
    }

    $datos = json_decode($respuesta, true);

    return [
        'transporte_ok' => true,
        'codigo_http' => $codigoHttp,
        'error' => null,
        'respuesta' => is_array($datos) ? $datos : null
    ];
}


/**
 * Consulta general de servicios en Grezz.
 * Comparte una caché de 5 minutos entre los módulos Activos y Suspendidos.
 * Exclusivamente lectura.
 */
function listarServiciosGrezz(): array
{
    $archivoToken = '/etc/ubb-grezz-consulta.token';
    $archivoCache = '/tmp/ubb_grezz_servicios_cache.json';
    $archivoBloqueo = '/tmp/ubb_grezz_servicios_cache.lock';

    $bloqueo = fopen($archivoBloqueo, 'c');

    if ($bloqueo === false) {
        return [
            'transporte_ok' => false,
            'codigo_http' => 0,
            'error' => 'No se pudo acceder al bloqueo de caché.',
            'respuesta' => null
        ];
    }

    flock($bloqueo, LOCK_EX);

    try {
        if (
            is_file($archivoCache) &&
            (time() - filemtime($archivoCache)) < 300
        ) {
            $cache = json_decode(
                (string)file_get_contents($archivoCache),
                true
            );

            if (is_array($cache)) {
                return $cache;
            }
        }

        if (!is_readable($archivoToken)) {
            throw new RuntimeException('Token de consulta no disponible.');
        }

        $token = trim((string)file_get_contents($archivoToken));

        if ($token === '') {
            throw new RuntimeException('Token de consulta vacío.');
        }

        $url = 'http://192.168.100.223/api-radius/v1/servicios';
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar la conexión HTTP.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);

        if ($respuesta === false) {
            return [
                'transporte_ok' => false,
                'codigo_http' => $codigoHttp,
                'error' => $errorCurl,
                'respuesta' => null
            ];
        }

        $datos = json_decode($respuesta, true);

        if ($codigoHttp !== 200 || !is_array($datos)) {
            return [
                'transporte_ok' => true,
                'codigo_http' => $codigoHttp,
                'error' => 'Grezz no devolvió una respuesta válida.',
                'respuesta' => is_array($datos) ? $datos : null
            ];
        }

        $resultado = [
            'transporte_ok' => true,
            'codigo_http' => $codigoHttp,
            'error' => null,
            'respuesta' => $datos
        ];

        file_put_contents(
            $archivoCache,
            json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $resultado;

    } catch (Throwable $e) {
        return [
            'transporte_ok' => false,
            'codigo_http' => 0,
            'error' => $e->getMessage(),
            'respuesta' => null
        ];
    } finally {
        flock($bloqueo, LOCK_UN);
        fclose($bloqueo);
    }
}
