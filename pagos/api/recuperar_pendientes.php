<?php
declare(strict_types=1);

/**
 * Recuperador manual de solicitudes pendientes UBB-Grezz.
 * Exclusivo para CLI y modo simulación.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

date_default_timezone_set('America/Mexico_City');

require_once '/var/www/html/pagos/conexion.php';
require_once __DIR__ . '/procesador_grezz.php';

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    exit("No se pudo establecer la conexión con UBB Pagos.\n");
}

// Solo recuperar solicitudes con al menos dos minutos de antigüedad.
$limiteFecha = (new DateTimeImmutable('-2 minutes'))
    ->format('Y-m-d H:i:s');

$stmt = $conexion->prepare("
    SELECT
        id_solicitud,
        id_servicio,
        accion,
        motivo,
        referencia_pago,
        fecha_solicitud
    FROM solicitudes_estado_servicio
    WHERE estado = 'PENDIENTE'
      AND fecha_solicitud <= ?
    ORDER BY fecha_solicitud ASC
    LIMIT 20
");

if (!$stmt) {
    exit("Error preparando consulta de pendientes.\n");
}

$stmt->bind_param('s', $limiteFecha);
$stmt->execute();

$resultado = $stmt->get_result();
$pendientes = [];

while ($fila = $resultado->fetch_assoc()) {
    $pendientes[] = $fila;
}

$stmt->close();

echo "===== RECUPERACIÓN DE SOLICITUDES =====\n";
echo "Pendientes encontrados: " . count($pendientes) . "\n\n";

if (!$pendientes) {
    echo "No hay solicitudes pendientes antiguas para recuperar.\n";
    $conexion->close();
    exit(0);
}

foreach ($pendientes as $fila) {
    $idSolicitud = (string)$fila['id_solicitud'];

    echo "Solicitud: " . $idSolicitud . "\n";

    try {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $idSolicitud
            )
            || !preg_match('/^[1-9][0-9]*$/', (string)$fila['id_servicio'])
            || !in_array($fila['accion'], ['SUSPENDER', 'REACTIVAR'], true)
        ) {
            echo "Omitida: datos originales inválidos.\n\n";
            continue;
        }

        $solicitud = [
            'id_solicitud' => $idSolicitud,
            'id_servicio' => (string)$fila['id_servicio'],
            'accion' => $fila['accion'],
            'motivo' => $fila['motivo'],
            'referencia_pago' => $fila['referencia_pago']
        ];

        $respuesta = procesarSolicitudGrezz($conexion, $solicitud);

        echo "Servicio: " . $fila['id_servicio'] . "\n";
        echo "Estado: " . $respuesta['estado'] . "\n";
        echo "HTTP Grezz: "
            . ($respuesta['codigo_http'] ?? 'sin respuesta') . "\n";
        echo "Mensaje: " . $respuesta['mensaje'] . "\n\n";

    } catch (Throwable $e) {
        echo "No se pudo procesar: " . $e->getMessage() . "\n";
        echo "La solicitud no se reintentará con un UUID nuevo.\n\n";
    }
}

$conexion->close();

echo "===== FIN DE RECUPERACIÓN =====\n";
