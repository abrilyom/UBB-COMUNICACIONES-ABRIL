<?php
declare(strict_types=1);

/**
 * Puente entre el estado de los pagos y el estado del servicio.
 *
 * Registra la solicitud con el gestor central (UUID, bloqueo por servicio,
 * sin duplicados) y la envía a la API de Grezz mediante el procesador.
 * UBB Pagos nunca modifica RADIUS ni MikroTik directamente.
 */

require_once __DIR__ . '/../api/gestor_solicitudes.php';
require_once __DIR__ . '/../api/procesador_grezz.php';

/**
 * Estado actual del servicio según Grezz (ACTIVO, SUSPENDIDO, ...) o null
 * si no se pudo consultar.
 */
function estadoServicioEnGrezz(int $idServicio): ?string
{
    try {
        $resultado = consultarServicioGrezz($idServicio);
    } catch (Throwable $e) {
        return null;
    }

    if (!$resultado['transporte_ok'] || $resultado['codigo_http'] !== 200) {
        return null;
    }

    $datos = $resultado['respuesta'] ?? [];
    // La respuesta puede venir directa o dentro de "servicio" / "data".
    $servicio = $datos['servicio'] ?? $datos['data'] ?? $datos;
    $estado = $servicio['estado_actual'] ?? null;

    return is_string($estado) ? strtoupper($estado) : null;
}

/**
 * Estado de todos los servicios según Grezz: [id_servicio => ESTADO].
 * Si Grezz no pudo resolver un servicio, el valor es "ERROR_<código>"
 * (por ejemplo ERROR_SIN_RELACION).
 *
 * Nota: por ahora la API de Grezz solo expone servicios DHCP con equipo
 * relacionado; los servicios PPPoE todavía no aparecen.
 *
 * Devuelve null si Grezz no respondió correctamente.
 */
function mapaEstadosGrezz(): ?array
{
    $consulta = listarServiciosGrezz();

    if (
        empty($consulta['transporte_ok']) ||
        (int)($consulta['codigo_http'] ?? 0) !== 200 ||
        !is_array($consulta['respuesta']['servicios'] ?? null)
    ) {
        return null;
    }

    $mapa = [];

    foreach ($consulta['respuesta']['servicios'] as $servicio) {
        $id = filter_var($servicio['id_servicio'] ?? null, FILTER_VALIDATE_INT);
        $estado = $servicio['estado_actual'] ?? null;
        $error = $servicio['error'] ?? null;

        if (!$id) {
            continue;
        }

        if (is_string($estado) && $estado !== '') {
            $mapa[(int)$id] = strtoupper($estado);
        } elseif (is_string($error) && $error !== '') {
            $mapa[(int)$id] = 'ERROR_' . strtoupper($error);
        } else {
            $mapa[(int)$id] = 'ERROR_SIN_ESTADO';
        }
    }

    return $mapa;
}

/**
 * Registra y envía una solicitud SUSPENDER o REACTIVAR.
 *
 * @param bool $confirmar false = solo describe lo que haría.
 * @return array{accion:string, registrada:bool, estado:?string, mensaje:string, id_solicitud:?string}
 */
function solicitarCambioServicio(
    mysqli $conexion,
    int $idCliente,
    int $idServicio,
    string $accion,
    string $motivo,
    ?string $referenciaPago,
    string $usuario,
    bool $confirmar
): array {
    $accion = strtoupper($accion);

    if (!$confirmar) {
        return [
            'accion' => $accion,
            'registrada' => false,
            'estado' => null,
            'mensaje' => "SIMULACIÓN: se solicitaría $accion para el servicio $idServicio.",
            'id_solicitud' => null,
        ];
    }

    try {
        $solicitud = prepararSolicitudEstadoServicio($conexion, [
            'id_cliente' => (string)$idCliente,
            'id_servicio' => (string)$idServicio,
            'accion' => $accion,
            'motivo' => $motivo,
            'referencia_pago' => $referenciaPago,
            'usuario_solicitante' => $usuario,
        ]);
    } catch (DomainException $e) {
        // Ya hay una solicitud pendiente para este servicio.
        return [
            'accion' => $accion,
            'registrada' => false,
            'estado' => null,
            'mensaje' => 'No registrada: ' . $e->getMessage(),
            'id_solicitud' => null,
        ];
    }

    if ($solicitud['repetida'] ?? false) {
        return [
            'accion' => $accion,
            'registrada' => false,
            'estado' => $solicitud['estado'],
            'mensaje' => 'La solicitud ya existía.',
            'id_solicitud' => $solicitud['id_solicitud'],
        ];
    }

    try {
        $resultado = procesarSolicitudGrezz($conexion, $solicitud);
    } catch (Throwable $e) {
        // Queda PENDIENTE; recuperar_pendientes.php la reintentará.
        return [
            'accion' => $accion,
            'registrada' => true,
            'estado' => 'PENDIENTE',
            'mensaje' => 'Registrada, pero no se pudo enviar a Grezz: ' . $e->getMessage(),
            'id_solicitud' => $solicitud['id_solicitud'],
        ];
    }

    return [
        'accion' => $accion,
        'registrada' => true,
        'estado' => $resultado['estado'],
        'mensaje' => $resultado['mensaje'],
        'id_solicitud' => $solicitud['id_solicitud'],
    ];
}
