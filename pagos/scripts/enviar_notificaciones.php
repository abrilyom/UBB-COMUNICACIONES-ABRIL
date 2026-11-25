<?php
declare(strict_types=1);

/*
 * ENVÍO DE NOTIFICACIONES UBB (WhatsApp / SMS)
 *
 * Toma los mensajes PENDIENTES de notificaciones_cola, obtiene el nombre y
 * teléfono del cliente desde la plataforma y los envía con el proveedor
 * configurado en /etc/ubb-notificaciones.env (fuera del repositorio):
 *
 *   PROVEEDOR=whatsapp_cloud | twilio_sms | ninguno
 *
 *   # WhatsApp Cloud API (Meta). Requiere plantillas aprobadas.
 *   WA_TOKEN=...
 *   WA_PHONE_NUMBER_ID=...
 *   WA_IDIOMA=es_MX
 *   WA_PLANTILLA_RECORDATORIO_PREVIO=ubb_recordatorio_previo
 *   WA_PLANTILLA_RECORDATORIO_HOY=ubb_recordatorio_hoy
 *   WA_PLANTILLA_ULTIMO_AVISO=ubb_ultimo_aviso
 *   WA_PLANTILLA_SUSPENSION=ubb_suspension
 *   WA_PLANTILLA_PAGO_RECIBIDO=ubb_pago_recibido
 *
 *   # SMS con Twilio
 *   TWILIO_SID=...
 *   TWILIO_TOKEN=...
 *   TWILIO_FROM=+1...
 *
 * Con PROVEEDOR=ninguno (o sin archivo) solo muestra los mensajes; el
 * personal puede enviarlos manualmente desde el módulo Notificaciones.
 *
 * Uso:
 *   php enviar_notificaciones.php            (vista previa, no envía)
 *   php enviar_notificaciones.php --enviar   (envía y registra el resultado)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/notificaciones_cola.php';

const UBB_MAX_INTENTOS_NOTIFICACION = 3;
const UBB_ARCHIVO_CONFIG_NOTIFICACIONES = '/etc/ubb-notificaciones.env';

$opciones = array_slice($argv, 1);

foreach ($opciones as $opcion) {
    if ($opcion !== '--enviar') {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$enviar = in_array('--enviar', $opciones, true);

function cargarConfigNotificaciones(): array
{
    if (!is_readable(UBB_ARCHIVO_CONFIG_NOTIFICACIONES)) {
        return ['PROVEEDOR' => 'ninguno'];
    }

    $config = parse_ini_file(UBB_ARCHIVO_CONFIG_NOTIFICACIONES, false, INI_SCANNER_RAW);

    return is_array($config) ? $config : ['PROVEEDOR' => 'ninguno'];
}

/**
 * Parámetros de la plantilla de WhatsApp, en el orden {{1}}, {{2}}, ...
 * Las plantillas en Meta deben crearse con estas variables en este orden.
 */
function parametrosPlantilla(string $tipo, string $nombre, int $idServicio, array $d): array
{
    $nombre = $nombre !== '' ? $nombre : 'cliente';

    return match ($tipo) {
        'RECORDATORIO_PREVIO', 'RECORDATORIO_HOY' =>
            [$nombre, (string)$idServicio, $d['fecha'] ?? '', '$' . ($d['saldo'] ?? ''), $d['referencia'] ?? ''],
        'ULTIMO_AVISO', 'SUSPENSION' =>
            [$nombre, (string)$idServicio, '$' . ($d['saldo'] ?? ''), $d['referencia'] ?? ''],
        'PAGO_RECIBIDO' =>
            [$nombre, '$' . ($d['monto'] ?? ''), $d['referencia'] ?? '', '$' . ($d['saldo'] ?? '0.00')],
        default => [$nombre],
    };
}

function peticionHttp(string $url, array $encabezados, string $cuerpo, ?string $usuarioPass = null): array
{
    $ch = curl_init($url);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $encabezados,
        CURLOPT_POSTFIELDS => $cuerpo,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($usuarioPass !== null) {
        $opciones[CURLOPT_USERPWD] = $usuarioPass;
    }

    curl_setopt_array($ch, $opciones);
    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    return [
        'ok' => $respuesta !== false && $codigo >= 200 && $codigo < 300,
        'codigo' => $codigo,
        'respuesta' => $respuesta === false ? $error : substr((string)$respuesta, 0, 1000),
    ];
}

function enviarWhatsappCloud(array $config, string $telefono, string $tipo, array $parametros): array
{
    $plantilla = $config['WA_PLANTILLA_' . $tipo] ?? '';

    if ($plantilla === '' || empty($config['WA_TOKEN']) || empty($config['WA_PHONE_NUMBER_ID'])) {
        return ['ok' => false, 'codigo' => 0, 'respuesta' => "Configuración de WhatsApp incompleta para $tipo."];
    }

    $cuerpo = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $telefono,
        'type' => 'template',
        'template' => [
            'name' => $plantilla,
            'language' => ['code' => $config['WA_IDIOMA'] ?? 'es_MX'],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(
                    fn($valor) => ['type' => 'text', 'text' => (string)$valor],
                    $parametros
                ),
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return peticionHttp(
        'https://graph.facebook.com/v21.0/' . rawurlencode($config['WA_PHONE_NUMBER_ID']) . '/messages',
        ['Authorization: Bearer ' . $config['WA_TOKEN'], 'Content-Type: application/json'],
        $cuerpo
    );
}

function enviarSmsTwilio(array $config, string $telefono, string $texto): array
{
    if (empty($config['TWILIO_SID']) || empty($config['TWILIO_TOKEN']) || empty($config['TWILIO_FROM'])) {
        return ['ok' => false, 'codigo' => 0, 'respuesta' => 'Configuración de Twilio incompleta.'];
    }

    return peticionHttp(
        'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($config['TWILIO_SID']) . '/Messages.json',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['To' => '+' . $telefono, 'From' => $config['TWILIO_FROM'], 'Body' => $texto]),
        $config['TWILIO_SID'] . ':' . $config['TWILIO_TOKEN']
    );
}

try {
    $conexion = conectarPagosCli();
    $config = cargarConfigNotificaciones();
    $proveedor = strtolower((string)($config['PROVEEDOR'] ?? 'ninguno'));

    echo "Proveedor: $proveedor\n";
    echo $enviar ? "MODO: ENVIAR\n\n" : "MODO: VISTA PREVIA (no se envía nada)\n\n";

    $maxIntentos = UBB_MAX_INTENTOS_NOTIFICACION;
    $pendientes = $conexion->query("
        SELECT id_notificacion, id_cliente, id_servicio, tipo, datos_json, intentos
        FROM notificaciones_cola
        WHERE estado = 'PENDIENTE'
           OR (estado = 'ERROR' AND intentos < $maxIntentos)
        ORDER BY fecha_creacion ASC
        LIMIT 200
    ")->fetch_all(MYSQLI_ASSOC);

    echo "Mensajes por enviar: " . count($pendientes) . "\n\n";

    if (!$pendientes) {
        exit(0);
    }

    // Nombre y teléfono desde la plataforma (solo lectura).
    $plataforma = conectarPlataformaCli();
    $ids = implode(',', array_unique(array_map(fn($f) => (int)$f['id_cliente'], $pendientes)));
    $clientes = [];

    $resultado = $plataforma->query("
        SELECT id_cliente, nombre, apellido_paterno, telefono
        FROM clientes
        WHERE id_cliente IN ($ids)
    ");

    while ($c = $resultado->fetch_assoc()) {
        $clientes[(int)$c['id_cliente']] = $c;
    }

    $actualizar = $conexion->prepare("
        UPDATE notificaciones_cola
        SET estado = ?, canal = ?, telefono = ?, mensaje = ?,
            respuesta_proveedor = ?, intentos = intentos + 1,
            fecha_envio = IF(? = 'ENVIADA', NOW(), fecha_envio)
        WHERE id_notificacion = ?
    ");

    $enviadas = 0;
    $errores = 0;

    foreach ($pendientes as $fila) {
        $idNotificacion = (int)$fila['id_notificacion'];
        $idCliente = (int)$fila['id_cliente'];
        $idServicio = (int)$fila['id_servicio'];
        $tipo = (string)$fila['tipo'];
        $datos = json_decode((string)$fila['datos_json'], true) ?: [];
        $cliente = $clientes[$idCliente] ?? null;
        $nombre = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido_paterno'] ?? ''));
        $telefono = normalizarTelefonoMx($cliente['telefono'] ?? null);
        $texto = redactarNotificacion($tipo, $nombre, $idServicio, $datos);

        echo "— #$idNotificacion $tipo | cliente $idCliente | tel. " . ($telefono ?? 'SIN TELÉFONO') . "\n";

        if (!$enviar) {
            echo '  ' . str_replace("\n", "\n  ", $texto) . "\n\n";
            continue;
        }

        if ($telefono === null) {
            $estado = 'SIN_TELEFONO';
            $respuesta = ['respuesta' => 'El cliente no tiene un teléfono válido de 10 dígitos.'];
        } elseif ($proveedor === 'whatsapp_cloud') {
            $respuesta = enviarWhatsappCloud(
                $config,
                $telefono,
                $tipo,
                parametrosPlantilla($tipo, $nombre, $idServicio, $datos)
            );
            $estado = $respuesta['ok'] ? 'ENVIADA' : 'ERROR';
        } elseif ($proveedor === 'twilio_sms') {
            $respuesta = enviarSmsTwilio($config, $telefono, $texto);
            $estado = $respuesta['ok'] ? 'ENVIADA' : 'ERROR';
        } else {
            echo "  · Sin proveedor configurado: queda pendiente para envío manual.\n\n";
            continue;
        }

        $detalle = (string)($respuesta['respuesta'] ?? '');
        $actualizar->bind_param(
            'ssssssi',
            $estado,
            $proveedor,
            $telefono,
            $texto,
            $detalle,
            $estado,
            $idNotificacion
        );
        $actualizar->execute();

        $estado === 'ENVIADA' ? $enviadas++ : $errores++;
        echo "  → $estado\n\n";
    }

    $actualizar->close();

    echo "===== RESUMEN =====\n";
    echo "Enviadas: $enviadas | Con error o sin teléfono: $errores\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
