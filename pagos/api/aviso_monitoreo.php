<?php
declare(strict_types=1);

/**
 * Aviso al servidor de monitoreo de Christian cuando un servicio se corta
 * o se reactiva por pago, para que Telegram lo reporte como
 * "CORTE POR PAGO" y no como falla de antena o fibra.
 *
 *   POST {UBB_MONITOREO_URL}
 *   X-API-Key: <llave>
 *   {"accion": "BAJA_PAGO" | "REACTIVACION", "mac": "...", "motivo": "...", "ejecutado_por": "Pagos"}
 *
 * Configuración (fuera del repositorio):
 *   UBB_MONITOREO_URL       por defecto http://192.168.100.224:5000/api/v1/webhook/administrativo
 *   UBB_MONITOREO_KEY_FILE  archivo con la llave (por defecto /etc/ubb-monitoreo.key)
 *
 * Sin llave no se envía nada (queda apagado hasta instalarla). Un fallo del aviso nunca detiene
 * el corte ni la reactivación: solo se registra en el log de PHP.
 */

require_once __DIR__ . '/cliente_grezz.php';

/** Acción de UBB Pagos (SUSPENDER / REACTIVAR) → acción del webhook. */
const UBB_MONITOREO_ACCIONES = [
    'SUSPENDER' => 'BAJA_PAGO',
    'REACTIVAR' => 'REACTIVACION',
];

/** MAC en formato AA:BB:CC:DD:EE:FF, o null si no es válida. */
function formatearMacMonitoreo(?string $mac): ?string
{
    $limpia = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac) ?? '');

    return strlen($limpia) === 12 ? implode(':', str_split($limpia, 2)) : null;
}

/** MAC del equipo del servicio según Grezz, o null si no se pudo obtener. */
function macServicioGrezz(int $idServicio, ?array $respuestaGrezz = null): ?string
{
    // Primero la respuesta del corte, por si Grezz ya la incluye.
    foreach ([$respuestaGrezz, $respuestaGrezz['servicio'] ?? null, $respuestaGrezz['data'] ?? null] as $datos) {
        $mac = is_array($datos) ? formatearMacMonitoreo($datos['mac'] ?? null) : null;
        if ($mac !== null) {
            return $mac;
        }
    }

    try {
        $consulta = consultarServicioGrezz($idServicio);
    } catch (Throwable $e) {
        return null;
    }

    if (!$consulta['transporte_ok'] || $consulta['codigo_http'] !== 200) {
        return null;
    }

    $datos = $consulta['respuesta'] ?? [];
    $servicio = $datos['servicio'] ?? $datos['data'] ?? $datos;

    return formatearMacMonitoreo($servicio['mac'] ?? null);
}

/**
 * Envía el aviso. Devuelve true solo si el servidor de monitoreo respondió 2xx.
 */
function avisarMonitoreo(string $accion, int $idServicio, string $motivo, ?array $respuestaGrezz = null): bool
{
    $accionWebhook = UBB_MONITOREO_ACCIONES[strtoupper($accion)] ?? null;
    $url = trim((string)(getenv('UBB_MONITOREO_URL')
        ?: 'http://192.168.100.224:5000/api/v1/webhook/administrativo'));
    $archivoLlave = getenv('UBB_MONITOREO_KEY_FILE') ?: '/etc/ubb-monitoreo.key';

    if ($accionWebhook === null || $url === '') {
        return false;
    }

    $llave = is_readable($archivoLlave) ? trim((string)file_get_contents($archivoLlave)) : '';

    if ($llave === '') {
        error_log("Aviso a monitoreo omitido (servicio $idServicio): falta la llave en $archivoLlave");
        return false;
    }

    $mac = macServicioGrezz($idServicio, $respuestaGrezz);

    if ($mac === null) {
        error_log("Aviso a monitoreo omitido (servicio $idServicio): no se encontró la MAC");
        return false;
    }

    $json = json_encode([
        'accion' => $accionWebhook,
        'mac' => $mac,
        'motivo' => $motivo !== '' ? $motivo : ($accionWebhook === 'BAJA_PAGO' ? 'Adeudo mensual' : 'Pago registrado'),
        'ejecutado_por' => 'Pagos',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);

    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $llave,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);

    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($respuesta === false || $codigo < 200 || $codigo >= 300) {
        error_log("Aviso a monitoreo falló (servicio $idServicio, $accionWebhook): HTTP $codigo $error");
        return false;
    }

    return true;
}
