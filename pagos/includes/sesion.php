<?php

/**
 * Sesión de Pagos con el pase de la plataforma (SSO de Jamileth).
 *
 * Al dar clic en "Pagos" en la plataforma, el navegador llega con ?sso=<pase>.
 * El pase es de un solo uso y vence en 2 minutos. Aquí se cambia por los
 * datos del usuario con:
 *
 *   POST {UBB_PLATAFORMA_SSO_URL}   campo sso=<pase>, encabezado X-API-Key
 *   200 → {"ok":true,"usuario":{"id_usuario":1,"nombre":"…","correo":"…","rol":"ADMINISTRADOR"}}
 *   404 → pase usado o vencido
 *
 * Roles: ADMINISTRADOR, OPERADOR, CONSULTA.
 *
 * Configuración (opcional):
 *   UBB_PLATAFORMA_SSO_URL   por defecto, la misma dirección que los avisos
 *                            (UBB_PLATAFORMA_AVISOS_URL) con sso.php.
 *   UBB_PLATAFORMA_URL       dirección de la plataforma en el navegador, para
 *                            "Entrar" y "Salir". Por defecto http://192.168.102.221:8081/
 * La llave es la misma de los avisos (/etc/ubb-plataforma-avisos.key).
 *
 * Por ahora no bloquea: sin sesión, las páginas funcionan como antes.
 */

if (PHP_SAPI === 'cli') {
    return;
}

/*
 * Los avisos y errores de PHP van al log del contenedor (docker logs), nunca
 * a la pantalla: mostrarlos rompe redirecciones y enseña rutas internas.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../api/cliente_avisos_plataforma.php';

const UBB_ROLES_PLATAFORMA = [
    'ADMINISTRADOR' => 'Administrador',
    'OPERADOR' => 'Operador',
    'CONSULTA' => 'Consulta',
];

function urlSsoPlataforma(): string
{
    $url = trim((string)getenv('UBB_PLATAFORMA_SSO_URL'));

    if ($url !== '') {
        return $url;
    }

    // Misma máquina y ruta que la API de avisos, que ya funciona desde el servidor.
    return preg_replace('#/[^/]*$#', '/sso.php', urlAvisosPlataforma());
}

function urlPlataformaNavegador(): string
{
    return trim((string)(getenv('UBB_PLATAFORMA_URL') ?: 'http://192.168.102.221:8081/'));
}

/** Usuario con sesión iniciada, o null. */
function usuarioSesion(): ?array
{
    $usuario = $_SESSION['usuario'] ?? null;

    return (is_array($usuario) && !empty($usuario['id_usuario'])) ? $usuario : null;
}

/** "Ana López · Operador", o "" si no hay sesión. */
function textoUsuarioSesion(): string
{
    $usuario = usuarioSesion();

    if ($usuario === null) {
        return '';
    }

    $rol = UBB_ROLES_PLATAFORMA[$usuario['rol']] ?? ucfirst(strtolower((string)$usuario['rol']));

    return trim($usuario['nombre'] . ($rol !== '' ? ' · ' . $rol : ''));
}

/** La URL actual sin los parámetros sso y salir (conserva id=N y los filtros). */
function urlActualSinPase(): string
{
    $ruta = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $consulta = $_GET;
    unset($consulta['sso'], $consulta['salir']);

    return $ruta . ($consulta ? '?' . http_build_query($consulta) : '');
}

/** Cambia el pase por los datos del usuario. Devuelve el usuario o null. */
function canjearPaseSso(string $pase): ?array
{
    $ch = curl_init(urlSsoPlataforma());

    if ($ch === false) {
        throw new RuntimeException('No se pudo iniciar la conexión HTTP.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['sso' => $pase]),
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . llaveAvisosPlataforma(),
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);

    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($respuesta === false) {
        throw new RuntimeException('La plataforma no respondió: ' . $error);
    }

    if ($codigo === 404) {
        return null; // Pase usado o vencido.
    }

    $datos = json_decode((string)$respuesta, true);
    $usuario = is_array($datos) && !empty($datos['ok']) ? ($datos['usuario'] ?? null) : null;

    if ($codigo !== 200 || !is_array($usuario)) {
        throw new RuntimeException("Respuesta inesperada de la plataforma (HTTP $codigo).");
    }

    $id = filter_var($usuario['id_usuario'] ?? null, FILTER_VALIDATE_INT);
    $rol = strtoupper(trim((string)($usuario['rol'] ?? '')));

    if (!$id || $id <= 0 || !isset(UBB_ROLES_PLATAFORMA[$rol])) {
        throw new RuntimeException('La plataforma devolvió un usuario sin id o con un rol desconocido.');
    }

    return [
        'id_usuario' => $id,
        'nombre' => mb_substr(trim((string)($usuario['nombre'] ?? '')), 0, 120, 'UTF-8'),
        'correo' => mb_substr(trim((string)($usuario['correo'] ?? '')), 0, 150, 'UTF-8'),
        'rol' => $rol,
        'inicio' => time(),
    ];
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('UBB_PAGOS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/* Salir: borra la sesión de Pagos y regresa a la plataforma. */
if (isset($_GET['salir'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ' . urlPlataformaNavegador());
    exit;
}

/* Llegada desde la plataforma con pase. */
if (isset($_GET['sso'])) {
    $pase = (string)$_GET['sso'];

    if (preg_match('/^[A-Za-z0-9._~-]{8,256}$/', $pase)) {
        try {
            $usuario = canjearPaseSso($pase);

            if ($usuario !== null) {
                session_regenerate_id(true);
                $_SESSION['usuario'] = $usuario;
            } else {
                error_log('SSO plataforma: pase usado o vencido.');
            }
        } catch (Throwable $e) {
            error_log('SSO plataforma: ' . $e->getMessage());
        }
    }

    // Quita el pase de la barra de direcciones (y del historial del navegador).
    header('Location: ' . urlActualSinPase());
    exit;
}

/*
 * Rol CONSULTA: solo ve. No puede enviar nada que modifique datos (POST)
 * ni abrir las páginas que solo sirven para capturar.
 */
const UBB_PAGINAS_SOLO_CAPTURA = ['registrar.php', 'subir_comprobante.php'];

function esSoloConsulta(): bool
{
    $usuario = usuarioSesion();

    return $usuario !== null && $usuario['rol'] === 'CONSULTA';
}

/** true si el usuario puede registrar, cancelar o cambiar datos. */
function puedeModificar(): bool
{
    return !esSoloConsulta();
}

if (esSoloConsulta()) {
    $metodoConsulta = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $paginaConsulta = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if (
        !in_array($metodoConsulta, ['GET', 'HEAD'], true)
        || in_array($paginaConsulta, UBB_PAGINAS_SOLO_CAPTURA, true)
    ) {
        http_response_code(403);
        $mensajeConsulta = 'Tu usuario es de solo consulta: puedes ver la información, pero no registrar ni cambiar pagos.';

        $pideJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

        if ($pideJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $mensajeConsulta, 'mensaje' => $mensajeConsulta], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Solo consulta</title>'
                . '<style>body{font-family:system-ui,sans-serif;background:#f7f2f8;color:#3b2f40;display:flex;'
                . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
                . 'div{background:#fff;padding:32px 36px;border-radius:16px;max-width:440px;text-align:center;'
                . 'box-shadow:0 8px 30px rgba(90,40,110,.12)}a{color:#8d5998;font-weight:600}</style></head><body><div>'
                . '<h2>Solo consulta</h2><p>' . htmlspecialchars($mensajeConsulta, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="index.php">Volver al centro de control</a></p></div></body></html>';
        }

        exit;
    }
}
