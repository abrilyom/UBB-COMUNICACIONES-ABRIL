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
 * Servicios que no deben suspenderse ni reactivarse automáticamente
 * mientras se valida su asignación (regla del proyecto).
 */
const UBB_SERVICIOS_PROTEGIDOS = [1255, 1299];

function servicioProtegido(int $idServicio): bool
{
    return in_array($idServicio, UBB_SERVICIOS_PROTEGIDOS, true);
}

/**
 * Tipos de servicio que la automatización puede suspender o reactivar.
 * Grezz ya reporta PPPoE (desde el 26/11/2026), pero su corte y su
 * reactivación se habilitan solo después de probarlos: hasta entonces los
 * PPPoE se atienden a mano. Para habilitarlos, agregar 'PPPOE' aquí.
 */
const UBB_TIPOS_AUTOMATICOS = ['DHCP'];

/**
 * Tipo del servicio según Grezz (DHCP, PPPOE) o null si no lo reporta.
 */
function tipoServicioGrezz(array $servicioGrezz): ?string
{
    $tipo = strtoupper(trim((string)($servicioGrezz['tipo'] ?? '')));

    return $tipo !== '' ? $tipo : null;
}

function tipoGestionableAutomaticamente(?string $tipo): bool
{
    return $tipo !== null && in_array($tipo, UBB_TIPOS_AUTOMATICOS, true);
}

/**
 * Datos del servicio según Grezz (estado_actual, tipo, ip, mac, ...) o null
 * si no se pudo consultar.
 */
function datosServicioEnGrezz(int $idServicio): ?array
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

    return is_array($servicio) ? $servicio : null;
}

/**
 * Estado actual del servicio según Grezz (ACTIVO, SUSPENDIDO, ...) o null
 * si no se pudo consultar.
 */
function estadoServicioEnGrezz(int $idServicio): ?string
{
    $estado = datosServicioEnGrezz($idServicio)['estado_actual'] ?? null;

    return is_string($estado) ? strtoupper($estado) : null;
}

/**
 * Lista completa de servicios de Grezz (id_servicio, estado_actual, ip,
 * mac, plan, ...) o null si Grezz no respondió correctamente.
 */
function serviciosGrezz(): ?array
{
    $consulta = listarServiciosGrezz();

    if (
        empty($consulta['transporte_ok']) ||
        (int)($consulta['codigo_http'] ?? 0) !== 200 ||
        !is_array($consulta['respuesta']['servicios'] ?? null)
    ) {
        return null;
    }

    return $consulta['respuesta']['servicios'];
}

function normalizarMacUbb(?string $mac): string
{
    return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac) ?? '');
}

/**
 * IP y MAC registradas en integracion_winbox (plataforma de Jamileth,
 * solo lectura), agrupadas por servicio: [id_servicio => [[ip, mac], ...]].
 */
function huellasRedPlataforma(mysqli $plataforma, array $idsServicio): array
{
    $ids = implode(',', array_map('intval', $idsServicio));

    if ($ids === '') {
        return [];
    }

    $huellas = [];
    $r = $plataforma->query("
        SELECT id_servicio, ip, mac
        FROM integracion_winbox
        WHERE id_servicio IN ($ids)
    ");

    while ($f = $r->fetch_assoc()) {
        $huellas[(int)$f['id_servicio']][] = [
            'ip' => trim((string)$f['ip']),
            'mac' => normalizarMacUbb($f['mac']),
        ];
    }

    return $huellas;
}

/**
 * Confirma que el servicio de Grezz es el mismo equipo que el de la
 * plataforma: al menos un registro con IP y/o MAC comparables y todas
 * iguales. Sin datos para comparar, devuelve false (no se corta).
 */
function redCoincideConPlataforma(array $servicioGrezz, array $huellasServicio): bool
{
    $ipG = trim((string)($servicioGrezz['ip'] ?? ''));
    $macG = normalizarMacUbb($servicioGrezz['mac'] ?? '');

    foreach ($huellasServicio as $h) {
        $comparables = 0;
        $iguales = 0;

        if ($ipG !== '' && $h['ip'] !== '') {
            $comparables++;
            $iguales += $ipG === $h['ip'] ? 1 : 0;
        }

        if ($macG !== '' && $h['mac'] !== '') {
            $comparables++;
            $iguales += $macG === $h['mac'] ? 1 : 0;
        }

        if ($comparables > 0 && $iguales === $comparables) {
            return true;
        }
    }

    return false;
}

/**
 * Estado de todos los servicios según Grezz: [id_servicio => ESTADO].
 * Si Grezz no pudo resolver un servicio, el valor es "ERROR_<código>"
 * (por ejemplo ERROR_SIN_RELACION).
 *
 * Incluye servicios DHCP y PPPoE (campo "tipo"); los que Grezz no relaciona
 * con un equipo llegan sin estado y con error SIN_RELACION.
 *
 * Devuelve null si Grezz no respondió correctamente.
 */
function mapaEstadosGrezz(?array $servicios = null): ?array
{
    $servicios ??= serviciosGrezz();

    if ($servicios === null) {
        return null;
    }

    $mapa = [];

    foreach ($servicios as $servicio) {
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

    if (servicioProtegido($idServicio)) {
        return [
            'accion' => $accion,
            'registrada' => false,
            'estado' => null,
            'mensaje' => "Servicio $idServicio protegido: no se modifica hasta su validación.",
            'id_solicitud' => null,
        ];
    }

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
