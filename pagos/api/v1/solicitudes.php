<?php
declare(strict_types=1);

/**
 * API UBB Pagos
 * POST /pagos/api/v1/solicitudes.php
 *
 * Registra solicitudes pendientes mediante el gestor central.
 * No ejecuta cambios en RADIUS ni llama a la API de Grezz.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, ['error' => 'Método no permitido.']);
}

// Autenticación Bearer.
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

// Leer JSON.
$contenido = file_get_contents('php://input');
$datos = json_decode($contenido, true);

if (!is_array($datos) || json_last_error() !== JSON_ERROR_NONE) {
    responder(400, ['error' => 'El cuerpo debe contener JSON válido.']);
}

// Validar identificadores.
$idCliente = $datos['id_cliente'] ?? null;
$idServicio = $datos['id_servicio'] ?? null;

foreach ([
    'id_cliente' => $idCliente,
    'id_servicio' => $idServicio
] as $campo => $valor) {
    if (
        (!is_string($valor) && !is_int($valor))
        || !preg_match('/^[1-9][0-9]*$/', (string)$valor)
        || strlen((string)$valor) > 20
    ) {
        responder(400, [
            'error' => "El campo $campo debe ser un entero positivo."
        ]);
    }
}

$idCliente = (string)$idCliente;
$idServicio = (string)$idServicio;

// El tipo técnico es opcional; Grezz lo resolverá mediante id_servicio.
$tipoServicio = $datos['tipo_servicio'] ?? null;

if ($tipoServicio !== null) {
    if (!is_string($tipoServicio)) {
        responder(400, ['error' => 'tipo_servicio debe ser texto.']);
    }

    $tipoServicio = strtoupper(trim($tipoServicio));

    if (
        $tipoServicio !== ''
        && !in_array($tipoServicio, ['PPPOE', 'DHCP', 'AMBOS'], true)
    ) {
        responder(400, [
            'error' => 'tipo_servicio debe ser PPPOE, DHCP o AMBOS.'
        ]);
    }

    if ($tipoServicio === '') {
        $tipoServicio = null;
    }
}

// Acción.
$accion = $datos['accion'] ?? '';

if (
    !is_string($accion)
    || !in_array(strtoupper(trim($accion)), ['SUSPENDER', 'REACTIVAR'], true)
) {
    responder(400, [
        'error' => 'accion debe ser SUSPENDER o REACTIVAR.'
    ]);
}

$accion = strtoupper(trim($accion));

// Motivo.
$motivo = $datos['motivo'] ?? '';

if (!is_string($motivo)) {
    responder(400, ['error' => 'motivo debe ser texto.']);
}

$motivo = trim($motivo);

if ($motivo === '' || strlen($motivo) > 2000) {
    responder(400, [
        'error' => 'El motivo es obligatorio y no debe superar 2000 caracteres.'
    ]);
}

// Referencia de pago opcional.
$referenciaPago = $datos['referencia_pago'] ?? null;

if ($referenciaPago !== null) {
    if (!is_string($referenciaPago)) {
        responder(400, ['error' => 'referencia_pago debe ser texto.']);
    }

    $referenciaPago = trim($referenciaPago);

    if (strlen($referenciaPago) > 120) {
        responder(400, [
            'error' => 'referencia_pago supera 120 caracteres.'
        ]);
    }

    if ($referenciaPago === '') {
        $referenciaPago = null;
    }
}

// UUID opcional para identificar y repetir una misma solicitud.
$idSolicitud = $datos['id_solicitud'] ?? null;

if ($idSolicitud !== null) {
    if (!is_string($idSolicitud) || trim($idSolicitud) === '') {
        responder(400, ['error' => 'id_solicitud debe ser un UUID válido.']);
    }

    $idSolicitud = trim($idSolicitud);

    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $idSolicitud
    )) {
        responder(400, ['error' => 'id_solicitud debe ser un UUID válido.']);
    }
}

$conexionPlataforma = null;
$conexion = null;

try {
    // Verificar que el servicio pertenece al cliente.
    require '/var/www/html/pagos/conexion_plataforma.php';

    $sqlVerificar = "
        SELECT id_servicio
        FROM servicios
        WHERE id_servicio = ?
          AND id_cliente = ?
        LIMIT 1
    ";

    $stmtVerificar = $conexion_plataforma->prepare($sqlVerificar);
    $stmtVerificar->bind_param('ss', $idServicio, $idCliente);
    $stmtVerificar->execute();

    $resultado = $stmtVerificar->get_result();
    $existe = $resultado->num_rows === 1;

    $stmtVerificar->close();
    $conexion_plataforma->close();
    $conexionPlataforma = null;

    if (!$existe) {
        responder(404, [
            'error' => 'No existe ese servicio asociado al cliente indicado.'
        ]);
    }

    // Conectar a UBB Pagos.
    require '/var/www/html/pagos/conexion.php';

    // Registrar mediante el gestor central.
    require_once '/var/www/html/pagos/api/gestor_solicitudes.php';

    $solicitud = prepararSolicitudEstadoServicio($conexion, [
        'id_solicitud' => $idSolicitud,
        'id_cliente' => $idCliente,
        'id_servicio' => $idServicio,
        'tipo_servicio' => $tipoServicio,
        'accion' => $accion,
        'motivo' => $motivo,
        'referencia_pago' => $referenciaPago,
        'usuario_solicitante' => 'API_UBB_PAGOS'
    ]);

    $repetida = $solicitud['repetida'] ?? false;
    $resultadoGrezz = null;

    // Solo enviar a Grezz las solicitudes nuevas.
    if (!$repetida) {
        require_once '/var/www/html/pagos/api/procesador_grezz.php';
        $resultadoGrezz = procesarSolicitudGrezz($conexion, $solicitud);
        $solicitud['estado'] = $resultadoGrezz['estado'];
    }

    $conexion->close();
    $conexion = null;

    $estadoRespuesta = $solicitud['estado'] ?? 'PENDIENTE';

    $codigoRespuesta = $repetida
        ? 200
        : ($estadoRespuesta === 'PENDIENTE' ? 202 : 201);

    $mensajeRespuesta = $repetida
        ? 'La solicitud ya estaba registrada; no se volvió a enviar a Grezz.'
        : ($estadoRespuesta === 'PENDIENTE'
            ? 'Solicitud registrada; permanece pendiente de confirmación de Grezz.'
            : 'Solicitud procesada en modo simulación.');

    responder($codigoRespuesta, [
        'exito' => true,
        'mensaje' => $mensajeRespuesta,
        'id_solicitud' => $solicitud['id_solicitud'],
        'id_servicio' => $solicitud['id_servicio'],
        'accion' => $solicitud['accion'],
        'estado' => $solicitud['estado'],
        'repetida' => $repetida,
        'codigo_http_grezz' => $resultadoGrezz['codigo_http'] ?? null,
        'mensaje_grezz' => $resultadoGrezz['mensaje'] ?? null
    ]);

} catch (InvalidArgumentException $e) {
    responder(400, ['error' => $e->getMessage()]);

} catch (DomainException $e) {
    $mensaje = $e->getMessage();

    if (str_contains($mensaje, 'UUID ya está asociado a otra operación.')) {
        responder(409, [
            'error' => $mensaje
        ]);
    }

    $respuesta = [
        'error' => 'Ya existe una solicitud pendiente para este servicio.'
    ];

    if (preg_match(
        '/UUID:\s*([0-9a-f-]{36})/i',
        $mensaje,
        $coincidencias
    )) {
        $respuesta['id_solicitud'] = $coincidencias[1];
    }

    responder(409, $respuesta);

} catch (Throwable $e) {
    error_log(
        'UBB API: error al registrar solicitud. '
        . get_class($e) . ': ' . $e->getMessage()
    );

    responder(500, [
        'error' => 'No fue posible registrar la solicitud.'
    ]);

} finally {
    if ($conexionPlataforma instanceof mysqli) {
        @$conexionPlataforma->close();
    }

    if ($conexion instanceof mysqli) {
        @$conexion->close();
    }
}
