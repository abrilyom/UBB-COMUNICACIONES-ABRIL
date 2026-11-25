<?php
declare(strict_types=1);

/**
 * API UBB Pagos
 * GET /pagos/api/v1/clientes.php?id_cliente=ID
 * GET /pagos/api/v1/clientes.php?codigo_cliente=CODIGO
 * Consulta de solo lectura.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responder(405, ['error' => 'Método no permitido.']);
}

// Autenticación Bearer.
$archivoToken = '/etc/ubb-api.env';

if (!is_readable($archivoToken)) {
    responder(500, ['error' => 'Error de configuración.']);
}

$configToken = parse_ini_file($archivoToken);
$tokenEsperado = $configToken['API_TOKEN'] ?? '';

if (!is_string($tokenEsperado) || $tokenEsperado === '') {
    responder(500, ['error' => 'Error de configuración.']);
}

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

// Debe enviarse exactamente un identificador.
$tieneId = isset($_GET['id_cliente']);
$tieneCodigo = isset($_GET['codigo_cliente']);

if ($tieneId === $tieneCodigo) {
    responder(400, [
        'error' => 'Indica únicamente id_cliente o codigo_cliente.'
    ]);
}

try {
    require '/var/www/html/pagos/conexion_plataforma.php';

    if ($tieneId) {
        $idCliente = (string) $_GET['id_cliente'];

        if (!preg_match('/^[1-9][0-9]*$/', $idCliente)) {
            responder(400, ['error' => 'id_cliente inválido.']);
        }

        $sql = "
            SELECT id_cliente, codigo_cliente, nombre,
                   apellido_paterno, apellido_materno, estado
            FROM clientes
            WHERE id_cliente = ?
            LIMIT 1
        ";

        $stmt = $conexion_plataforma->prepare($sql);
        $stmt->bind_param('s', $idCliente);

    } else {
        $codigoCliente = trim((string) $_GET['codigo_cliente']);

        if (
            $codigoCliente === ''
            || strlen($codigoCliente) > 30
            || preg_match('/[\x00-\x1F\x7F]/', $codigoCliente)
        ) {
            responder(400, ['error' => 'codigo_cliente inválido.']);
        }

        $sql = "
            SELECT id_cliente, codigo_cliente, nombre,
                   apellido_paterno, apellido_materno, estado
            FROM clientes
            WHERE codigo_cliente = ?
            LIMIT 1
        ";

        $stmt = $conexion_plataforma->prepare($sql);
        $stmt->bind_param('s', $codigoCliente);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado->fetch_assoc();

    $stmt->close();
    $conexion_plataforma->close();

    if (!$fila) {
        responder(404, ['error' => 'Cliente no encontrado.']);
    }

    responder(200, [
        'exito' => true,
        'cliente' => [
            'id_cliente' => (int) $fila['id_cliente'],
            'codigo_cliente' => $fila['codigo_cliente'],
            'nombre' => trim(implode(' ', array_filter([
                $fila['nombre'],
                $fila['apellido_paterno'],
                $fila['apellido_materno']
            ]))),
            'estado' => $fila['estado']
        ]
    ]);

} catch (Throwable $e) {
    error_log('UBB API: error al consultar cliente.');
    responder(500, ['error' => 'No fue posible consultar el cliente.']);
}
