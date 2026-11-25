<?php
declare(strict_types=1);

/**
 * Datos del "Centro de automatización" del dashboard.
 *
 * Solo lectura. Cada indicador se consulta por separado: si una tabla
 * todavía no existe (por ejemplo, antes de aplicar una migración), ese
 * indicador queda en null y el resto del panel sigue funcionando.
 */

function consultaEscalarSegura(mysqli $conexion, string $sql): ?array
{
    try {
        $resultado = $conexion->query($sql);
        $fila = $resultado->fetch_assoc();
        return $fila ?: [];
    } catch (Throwable $e) {
        error_log('Centro de automatización: ' . $e->getMessage());
        return null;
    }
}

/**
 * @param array      $estadoPagos     resultado de estadoPagosPorServicio()
 * @param array|null $serviciosGrezz  lista de servicios de Grezz, o null si no respondió
 */
function resumenAutomatizacion(mysqli $conexion, array $estadoPagos, ?array $serviciosGrezz): array
{
    $r = [];

    // Movimientos bancarios.
    $r['movimientos'] = consultaEscalarSegura($conexion, "
        SELECT
            SUM(estado_conciliacion = 'PENDIENTE') AS pendientes,
            SUM(estado_conciliacion = 'REVISION')  AS revision,
            SUM(estado_conciliacion = 'CONCILIADO') AS conciliados
        FROM movimientos_bancarios
        WHERE tipo_movimiento = 'ABONO'
    ");

    // Pagos registrados por la conciliación automática en los últimos 7 días.
    $r['conciliados_auto'] = consultaEscalarSegura($conexion, "
        SELECT COUNT(*) AS total, COALESCE(SUM(monto), 0) AS importe
        FROM pagos
        WHERE estado_pago = 'PAGADO'
          AND observaciones LIKE '%Conciliación bancaria (AUTOMATICA)%'
          AND fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");

    // Solicitudes a Grezz de hoy.
    $r['solicitudes'] = consultaEscalarSegura($conexion, "
        SELECT
            SUM(accion = 'SUSPENDER' AND DATE(fecha_solicitud) = CURDATE()) AS suspensiones_hoy,
            SUM(accion = 'REACTIVAR' AND DATE(fecha_solicitud) = CURDATE()) AS reactivaciones_hoy,
            SUM(estado = 'PENDIENTE') AS pendientes
        FROM solicitudes_estado_servicio
    ");

    // Avisos a clientes.
    $r['avisos'] = consultaEscalarSegura($conexion, "
        SELECT
            SUM(estado = 'PENDIENTE') AS pendientes,
            SUM(estado = 'ENVIADA' AND DATE(fecha_envio) = CURDATE()) AS enviados_hoy,
            SUM(estado IN ('ERROR', 'SIN_TELEFONO')) AS con_problema
        FROM notificaciones_cola
    ");

    // Última ejecución de cada proceso.
    $r['ejecuciones'] = [];

    try {
        $resultado = $conexion->query("
            SELECT e.proceso, e.modo, e.resultado, e.fecha_fin, e.mensaje
            FROM automatizacion_ejecuciones e
            INNER JOIN (
                SELECT proceso, MAX(id_ejecucion) AS ultima
                FROM automatizacion_ejecuciones
                GROUP BY proceso
            ) u ON u.ultima = e.id_ejecucion
        ");

        while ($fila = $resultado->fetch_assoc()) {
            $r['ejecuciones'][$fila['proceso']] = $fila;
        }
    } catch (Throwable $e) {
        $r['ejecuciones'] = null;
    }

    // Servicios fuera de gracia y cuántos puede suspender Grezz.
    $estadosGrezz = [];

    foreach ($serviciosGrezz ?? [] as $servicio) {
        $id = filter_var($servicio['id_servicio'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $estadosGrezz[(int)$id] = strtoupper((string)($servicio['estado_actual'] ?? ''));
        }
    }

    $fueraGracia = 0;
    $gestionables = 0;
    $enGracia = 0;

    foreach ($estadoPagos as $idServicio => $situacion) {
        if ($situacion['tipo'] === 'vencido') {
            $fueraGracia++;
            if (($estadosGrezz[$idServicio] ?? '') === 'ACTIVO') {
                $gestionables++;
            }
        } elseif ($situacion['tipo'] === 'gracia') {
            $enGracia++;
        }
    }

    $r['cortes'] = [
        'fuera_gracia' => $fueraGracia,
        'gestionables' => $serviciosGrezz === null ? null : $gestionables,
        'manuales' => $serviciosGrezz === null ? null : $fueraGracia - $gestionables,
        'en_gracia' => $enGracia,
    ];

    return $r;
}

/** Texto amigable de la última ejecución de un proceso. */
function textoUltimaEjecucion(?array $ejecucion): string
{
    if (!$ejecucion) {
        return 'Sin ejecuciones registradas';
    }

    $fecha = new DateTimeImmutable($ejecucion['fecha_fin']);
    $hoy = new DateTimeImmutable('today');

    $cuando = $fecha >= $hoy
        ? 'hoy ' . $fecha->format('H:i')
        : $fecha->format('d/m H:i');

    $modo = $ejecucion['modo'] === 'SIMULACION' ? ' (simulación)' : '';

    return $ejecucion['resultado'] === 'ERROR'
        ? "Error $cuando$modo"
        : "Última: $cuando$modo";
}
