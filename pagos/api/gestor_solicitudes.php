<?php
declare(strict_types=1);

/**
 * Gestor central de solicitudes de estado de servicio.
 * Registra solicitudes localmente. No ejecuta cambios en RADIUS.
 */

function generarUUIDSolicitud(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function prepararSolicitudEstadoServicio(
    mysqli $conexion,
    array $datos
): array {

    $idCliente = (string)($datos['id_cliente'] ?? '');
    $idServicio = (string)($datos['id_servicio'] ?? '');

    foreach ([
        'id_cliente' => $idCliente,
        'id_servicio' => $idServicio
    ] as $campo => $valor) {
        if (
            !preg_match('/^[1-9][0-9]*$/', $valor)
            || strlen($valor) > 20
        ) {
            throw new InvalidArgumentException(
                "$campo debe ser un entero positivo."
            );
        }
    }

    $tipoServicio = $datos['tipo_servicio'] ?? null;

    if ($tipoServicio !== null) {
        $tipoServicio = strtoupper(trim((string)$tipoServicio));

        if ($tipoServicio === '') {
            $tipoServicio = null;
        }

        if (
            $tipoServicio !== null
            && !in_array($tipoServicio, ['PPPOE', 'DHCP', 'AMBOS'], true)
        ) {
            throw new InvalidArgumentException('Tipo de servicio inválido.');
        }
    }

    $accion = strtoupper(trim((string)($datos['accion'] ?? '')));
    $motivo = trim((string)($datos['motivo'] ?? ''));
    $referencia = trim((string)($datos['referencia_pago'] ?? ''));
    $usuario = trim((string)($datos['usuario_solicitante'] ?? ''));

    if (!in_array($accion, ['SUSPENDER', 'REACTIVAR'], true)) {
        throw new InvalidArgumentException('Acción inválida.');
    }

    if ($motivo === '' || strlen($motivo) > 2000 || $usuario === '') {
        throw new InvalidArgumentException('Faltan datos obligatorios.');
    }

    if (strlen($usuario) > 120) {
        throw new InvalidArgumentException('Usuario solicitante demasiado largo.');
    }

    if (strlen($referencia) > 120) {
        throw new InvalidArgumentException('Referencia demasiado larga.');
    }

    $idSolicitud = trim((string)($datos['id_solicitud'] ?? ''));

    if ($idSolicitud === '') {
        $idSolicitud = generarUUIDSolicitud();
    }

    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $idSolicitud
    )) {
        throw new InvalidArgumentException('UUID de solicitud inválido.');
    }

    $referenciaSQL = $referencia !== '' ? $referencia : null;

    // El bloqueo se aplica al servicio completo, sin importar la acción.
    // Evita suspensiones y reactivaciones pendientes simultáneamente.
    $claveBloqueo = 'ubb_sol_' . substr(
        hash('sha256', $idServicio),
        0,
        48
    );

    $stmtLock = $conexion->prepare('SELECT GET_LOCK(?, 10)');
    $stmtLock->bind_param('s', $claveBloqueo);
    $stmtLock->execute();
    $lockObtenido = (int)$stmtLock->get_result()->fetch_row()[0] === 1;
    $stmtLock->close();

    if (!$lockObtenido) {
        throw new RuntimeException('No se pudo bloquear la solicitud.');
    }

    try {
        // Primero comprobar si el UUID ya fue registrado.
        $stmtExistente = $conexion->prepare("
            SELECT id_solicitud, id_cliente, id_servicio,
                   tipo_servicio, accion, motivo, referencia_pago,
                   estado, solicitud_json
            FROM solicitudes_estado_servicio
            WHERE id_solicitud = ?
            LIMIT 1
        ");

        $stmtExistente->bind_param('s', $idSolicitud);
        $stmtExistente->execute();
        $existente = $stmtExistente->get_result()->fetch_assoc();
        $stmtExistente->close();

        if ($existente) {
            $mismaOperacion =
                (string)$existente['id_cliente'] === $idCliente
                && (string)$existente['id_servicio'] === $idServicio
                && $existente['tipo_servicio'] === $tipoServicio
                && $existente['accion'] === $accion
                && $existente['motivo'] === $motivo
                && $existente['referencia_pago'] === $referenciaSQL;

            if (!$mismaOperacion) {
                throw new DomainException(
                    'El UUID ya está asociado a otra operación.'
                );
            }

            return [
                'id_solicitud' => $idSolicitud,
                'id_cliente' => $idCliente,
                'id_servicio' => $idServicio,
                'tipo_servicio' => $tipoServicio,
                'accion' => $accion,
                'estado' => $existente['estado'],
                'repetida' => true
            ];
        }

        // No permitir otra solicitud pendiente para el mismo servicio,
        // aunque la nueva solicitud tenga una acción diferente.
        $stmtDuplicado = $conexion->prepare("
            SELECT id_solicitud
            FROM solicitudes_estado_servicio
            WHERE id_servicio = ?
              AND estado = 'PENDIENTE'
            LIMIT 1
        ");

        $stmtDuplicado->bind_param('s', $idServicio);
        $stmtDuplicado->execute();
        $duplicado = $stmtDuplicado->get_result()->fetch_assoc();
        $stmtDuplicado->close();

        if ($duplicado) {
            throw new DomainException(
                'Ya existe una solicitud pendiente para este servicio y acción. '
                . 'UUID: ' . $duplicado['id_solicitud']
            );
        }

        date_default_timezone_set('America/Mexico_City');
        $ahora = new DateTimeImmutable('now');

        $payload = [
            'id_solicitud' => $idSolicitud,
            'id_cliente' => $idCliente,
            'id_servicio' => $idServicio,
            'tipo_servicio' => $tipoServicio,
            'accion' => $accion,
            'motivo' => $motivo,
            'referencia_pago' => $referenciaSQL,
            'fecha_solicitud' => $ahora->format(DateTimeInterface::ATOM),
            'usuario_solicitante' => $usuario
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $fechaSQL = $ahora->format('Y-m-d H:i:s');

        $stmtInsertar = $conexion->prepare("
            INSERT INTO solicitudes_estado_servicio (
                id_solicitud,
                id_cliente,
                id_servicio,
                tipo_servicio,
                accion,
                motivo,
                referencia_pago,
                usuario_solicitante,
                estado,
                solicitud_json,
                fecha_solicitud
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?)
        ");

        $stmtInsertar->bind_param(
            'ssssssssss',
            $idSolicitud,
            $idCliente,
            $idServicio,
            $tipoServicio,
            $accion,
            $motivo,
            $referenciaSQL,
            $usuario,
            $json,
            $fechaSQL
        );

        $stmtInsertar->execute();
        $stmtInsertar->close();

        $payload['estado'] = 'PENDIENTE';
        $payload['repetida'] = false;

        return $payload;

    } finally {
        $stmtLiberar = $conexion->prepare('SELECT RELEASE_LOCK(?)');
        $stmtLiberar->bind_param('s', $claveBloqueo);
        $stmtLiberar->execute();
        $stmtLiberar->close();
    }
}
