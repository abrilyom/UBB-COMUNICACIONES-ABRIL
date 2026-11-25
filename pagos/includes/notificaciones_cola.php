<?php
declare(strict_types=1);

/**
 * Cola de notificaciones a clientes (WhatsApp / SMS).
 *
 * Los procesos automáticos solo ENCOLAN mensajes en la tabla
 * notificaciones_cola. El script enviar_notificaciones.php los envía con
 * el proveedor configurado. Así, si el proveedor falla, ningún pago ni
 * suspensión se detiene, y cada mensaje queda registrado.
 *
 * La columna clave_unica evita mandar dos veces el mismo aviso
 * (por ejemplo, el recordatorio de la misma mensualidad).
 */

const UBB_TIPOS_NOTIFICACION = [
    'RECORDATORIO_PREVIO',   // 3 días antes del vencimiento
    'RECORDATORIO_HOY',      // el día del vencimiento
    'ULTIMO_AVISO',          // último día del periodo de gracia
    'SUSPENSION',            // al solicitar la suspensión
    'PAGO_RECIBIDO',         // al conciliar un pago
];

/**
 * Agrega un mensaje a la cola. Si ya existía (misma clave), no hace nada.
 *
 * @return bool true si se encoló un mensaje nuevo.
 */
function encolarNotificacion(
    mysqli $conexion,
    int $idCliente,
    int $idServicio,
    ?int $idObligacion,
    string $tipo,
    string $claveUnica,
    array $datos
): bool {
    if (!in_array($tipo, UBB_TIPOS_NOTIFICACION, true)) {
        throw new InvalidArgumentException("Tipo de notificación inválido: $tipo");
    }

    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $claveUnica = substr($tipo . ':' . $claveUnica, 0, 150);

    $stmt = $conexion->prepare("
        INSERT IGNORE INTO notificaciones_cola
            (id_cliente, id_servicio, id_obligacion, tipo, clave_unica, datos_json, estado)
        VALUES (?, ?, ?, ?, ?, ?, 'PENDIENTE')
    ");
    $stmt->bind_param('iiisss', $idCliente, $idServicio, $idObligacion, $tipo, $claveUnica, $json);
    $stmt->execute();
    $nueva = $stmt->affected_rows === 1;
    $stmt->close();

    return $nueva;
}

/**
 * Texto del mensaje para cada tipo de aviso.
 */
function redactarNotificacion(string $tipo, string $nombre, int $idServicio, array $d): string
{
    $saludo = 'Hola' . ($nombre !== '' ? ", $nombre" : '') . '. Te escribimos de UBB Comunicaciones.';
    $ref = $d['referencia'] ?? '';
    $pie = "\n\nSi ya realizaste tu pago, ignora este mensaje. Dudas: 238 211 4997.";

    return match ($tipo) {
        'RECORDATORIO_PREVIO' =>
            "$saludo\nTu mensualidad del servicio $idServicio vence el {$d['fecha']}. "
            . "Importe: \${$d['saldo']}. Referencia de pago: $ref" . $pie,
        'RECORDATORIO_HOY' =>
            "$saludo\nHoy vence tu mensualidad del servicio $idServicio. "
            . "Importe: \${$d['saldo']}. Referencia de pago: $ref" . $pie,
        'ULTIMO_AVISO' =>
            "$saludo\nHoy es el último día de tu periodo de gracia. Para evitar la suspensión "
            . "del servicio $idServicio, liquida \${$d['saldo']} con la referencia $ref" . $pie,
        'SUSPENSION' =>
            "$saludo\nTu servicio $idServicio fue suspendido por falta de pago. Saldo pendiente: "
            . "\${$d['saldo']}. Al pagar con la referencia $ref se reactiva automáticamente.",
        'PAGO_RECIBIDO' =>
            "$saludo\nRecibimos tu pago de \${$d['monto']} con referencia $ref. "
            . ((float)str_replace(',', '', (string)($d['saldo'] ?? '0')) > 0
                ? "Saldo restante: \${$d['saldo']}."
                : '¡Gracias, tu mensualidad quedó cubierta!'),
        default => throw new InvalidArgumentException("Tipo sin plantilla: $tipo"),
    };
}

/**
 * Normaliza un teléfono mexicano al formato internacional 52XXXXXXXXXX.
 * Devuelve null si no es un número de 10 dígitos válido.
 */
function normalizarTelefonoMx(?string $telefono): ?string
{
    $digitos = preg_replace('/\D+/', '', (string)$telefono);

    if (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
        $digitos = substr($digitos, 2);
    } elseif (strlen($digitos) === 13 && str_starts_with($digitos, '521')) {
        $digitos = substr($digitos, 3);
    }

    return strlen($digitos) === 10 ? '52' . $digitos : null;
}
