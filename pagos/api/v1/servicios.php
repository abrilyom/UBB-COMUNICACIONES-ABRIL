<?php
declare(strict_types=1);

/**
 * API UBB Pagos
 * GET /pagos/api/v1/servicios.php?id_servicio=ID
 *
 * Consulta de solo lectura sobre ubb_plataforma.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);

    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// Solo permitir GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responder(405, ['error' => 'Método no permitido.']);
}

// Leer token privado.
$archivoToken = '/etc/ubb-api.env';

if (!is_readable($archivoToken)) {
    error_log('UBB API: configuración de autenticación no disponible.');
    responder(500, ['error' => 'Error de configuración.']);
}

$configToken = parse_ini_file($archivoToken);

$tokenEsperado = $configToken['API_TOKEN'] ?? '';

if (!is_string($tokenEsperado) || $tokenEsperado === '') {
    responder(500, ['error' => 'Error de configuración.']);
}

// Obtener encabezado Authorization.
$autorizacion = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($autorizacion === '') {
    $autorizacion = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if ($autorizacion === '' && function_exists('getallheaders')) {
    $encabezados = getallheaders();
    $autorizacion = $encabezados['Authorization']
        ?? $encabezados['authorization']
        ?? '';
}

if (
    !preg_match('/^Bearer\s+(.+)$/i', trim($autorizacion), $coincidencias)
    || !hash_equals($tokenEsperado, trim($coincidencias[1]))
) {
    header('WWW-Authenticate: Bearer');
    responder(401, ['error' => 'Autenticación requerida.']);
}

// Validar identificador.
$idServicio = filter_var(
    $_GET['id_servicio'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($idServicio === false || $idServicio === null) {
    responder(400, ['error' => 'El id_servicio debe ser un entero positivo.']);
}

// Consultar plataforma.
try {
    require '/var/www/html/pagos/conexion_plataforma.php';

    $sql = "
        SELECT
            s.id_servicio,
            s.codigo_servicio,
            s.id_cliente,
            s.tecnologia,
            s.paquete_referencia,
            s.id_velocidad,
            s.estado AS estado_servicio,
            s.fecha_contratacion,
            s.fecha_instalacion,

            c.codigo_cliente,
            c.nombre,
            c.apellido_paterno,
            c.apellido_materno,
            c.telefono,
            c.correo,
            c.estado AS estado_cliente,

            d.calle,
            d.numero_exterior,
            d.numero_interior,
            d.colonia,
            d.municipio,
            d.estado AS estado_direccion,
            d.codigo_postal

        FROM servicios s

        INNER JOIN clientes c
            ON c.id_cliente = s.id_cliente

        LEFT JOIN direcciones_servicio d
            ON d.id_direccion = s.id_direccion
            AND d.id_cliente = s.id_cliente

        WHERE s.id_servicio = ?

        LIMIT 1
    ";

    $stmt = $conexion_plataforma->prepare($sql);
    $stmt->bind_param('i', $idServicio);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $fila = $resultado->fetch_assoc();

    $stmt->close();
    $conexion_plataforma->close();

    if (!$fila) {
        responder(404, ['error' => 'Servicio no encontrado.']);
    }

    $respuesta = [
        'exito' => true,
        'servicio' => [
            'id_servicio' => (int) $fila['id_servicio'],
            'codigo_servicio' => $fila['codigo_servicio'],
            'tecnologia' => $fila['tecnologia'],
            'paquete_referencia' => $fila['paquete_referencia'],
            'id_velocidad' => $fila['id_velocidad'] !== null
                ? (int) $fila['id_velocidad'] : null,
            'estado' => $fila['estado_servicio'],
            'fecha_contratacion' => $fila['fecha_contratacion'],
            'fecha_instalacion' => $fila['fecha_instalacion']
        ],
        'cliente' => [
            'id_cliente' => (int) $fila['id_cliente'],
            'codigo_cliente' => $fila['codigo_cliente'],
            'nombre' => trim(implode(' ', array_filter([
                $fila['nombre'],
                $fila['apellido_paterno'],
                $fila['apellido_materno']
            ]))),
            'telefono' => $fila['telefono'],
            'correo' => $fila['correo'],
            'estado' => $fila['estado_cliente']
        ],
        'direccion' => $fila['calle'] !== null ? [
            'calle' => $fila['calle'],
            'numero_exterior' => $fila['numero_exterior'],
            'numero_interior' => $fila['numero_interior'],
            'colonia' => $fila['colonia'],
            'municipio' => $fila['municipio'],
            'estado' => $fila['estado_direccion'],
            'codigo_postal' => $fila['codigo_postal']
        ] : null
    ];

    responder(200, $respuesta);

} catch (Throwable $e) {
    error_log('UBB API: error al consultar servicio.');
    responder(500, ['error' => 'No fue posible consultar el servicio.']);
}
