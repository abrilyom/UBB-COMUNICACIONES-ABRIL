<?php
declare(strict_types=1);

/**
 * Puente entre la campanita del encabezado y la API de avisos de la
 * plataforma. La llave se queda en el servidor; el navegador solo habla
 * con este archivo.
 *
 *   GET                       → {"ok": true, "avisos": [...]}
 *   GET  ?refrescar=1         → igual, sin usar la caché
 *   POST id=<id>              → marca el aviso como revisado
 */

require_once __DIR__ . '/includes/sesion.php';
require_once __DIR__ . '/api/cliente_avisos_plataforma.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderAvisos(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    responderAvisos(200, avisosPlataformaPendientes(isset($_GET['refrescar'])));
}

if ($metodo !== 'POST') {
    responderAvisos(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

// Solo desde la propia página (fetch con este encabezado), no desde un
// formulario de otro sitio.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    responderAvisos(403, ['ok' => false, 'error' => 'Solicitud no permitida.']);
}

$id = trim((string)($_POST['id'] ?? ''));

try {
    if (!marcarAvisoPlataformaRevisado($id)) {
        responderAvisos(502, ['ok' => false, 'error' => 'La plataforma no confirmó el cambio.']);
    }
} catch (InvalidArgumentException $e) {
    responderAvisos(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Marcar aviso revisado: ' . $e->getMessage());
    responderAvisos(502, ['ok' => false, 'error' => 'No se pudo contactar a la plataforma.']);
}

responderAvisos(200, ['ok' => true]);
