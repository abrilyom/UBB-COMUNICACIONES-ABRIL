<?php
declare(strict_types=1);

require_once __DIR__ . '/configuracion.php';

/**
 * Realiza una petición HTTP a UBB-GESTION-PLANES.
 *
 * No modifica bases de datos.
 * No reintenta automáticamente solicitudes.
 */
function peticionPlanesAPI(
    string $metodo,
    string $ruta,
    ?array $datos = null
): array {

    $metodo = strtoupper($metodo);

    if (!in_array($metodo, ['GET', 'POST'], true)) {
        throw new InvalidArgumentException('Método HTTP no permitido.');
    }

    if (
        !str_starts_with($ruta, '/api/v1/solicitudes/estado-servicio') ||
        str_contains($ruta, '..') ||
        str_contains($ruta, '?') ||
        str_contains($ruta, '#')
    ) {
        throw new InvalidArgumentException('Ruta de API no permitida.');
    }

    $config = obtenerConfiguracionPlanesAPI();

    $url = $config['base_url'] . $ruta;

    $encabezados = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['token']
    ];

    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $encabezados,
        CURLOPT_CUSTOMREQUEST => $metodo,
    ];

    if ($metodo === 'POST') {
        if ($datos === null) {
            throw new InvalidArgumentException('Falta el contenido JSON.');
        }

        $json = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $opciones[CURLOPT_POSTFIELDS] = $json;
    }

    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('No se pudo iniciar cURL.');
    }

    curl_setopt_array($curl, $opciones);

    $respuesta = curl_exec($curl);
    $codigoHttp = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errorCurl = curl_error($curl);


    if ($respuesta === false) {
        return [
            'comunicacion_exitosa' => false,
            'codigo_http' => $codigoHttp,
            'respuesta_json' => null,
            'respuesta_original' => null,
            'error' => $errorCurl
        ];
    }

    $respuestaJson = json_decode($respuesta, true);

    return [
        'comunicacion_exitosa' => true,
        'codigo_http' => $codigoHttp,
        'respuesta_json' => (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($respuestaJson)
        ) ? $respuestaJson : null,
        'respuesta_original' => $respuesta,
        'error' => null
    ];
}

/**
 * Envía una solicitud de suspensión o reactivación.
 */
function enviarSolicitudEstadoServicio(array $datos): array
{
    return peticionPlanesAPI(
        'POST',
        '/api/v1/solicitudes/estado-servicio',
        $datos
    );
}

/**
 * Consulta el resultado mediante el id_seguimiento de Grezz.
 */
function consultarEstadoServicio(string $idSeguimiento): array
{
    $idSeguimiento = trim($idSeguimiento);

    if ($idSeguimiento === '') {
        throw new InvalidArgumentException(
            'El identificador de seguimiento es obligatorio.'
        );
    }

    return peticionPlanesAPI(
        'GET',
        '/api/v1/solicitudes/estado-servicio/' .
        rawurlencode($idSeguimiento)
    );
}
