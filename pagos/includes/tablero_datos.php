<?php
declare(strict_types=1);

/**
 * Datos del Centro de control (index.php) que no estaban en el resumen de
 * automatización: estado de cada proceso y actividad reciente.
 *
 * Solo lectura. Cada consulta va por separado y, si falla (por ejemplo, una
 * tabla que todavía no existe), esa parte queda vacía y el resto del tablero
 * sigue funcionando.
 */

require_once __DIR__ . '/automatizacion_servicios.php';

/**
 * Estado de un proceso automático a partir de su última ejecución.
 *
 * @param array|null $ejecucion fila de automatizacion_ejecuciones o null
 * @param int        $alertas   pendientes que requieren atención humana
 * @return array{clase:string, texto:string}
 */
function estadoProcesoTablero(?array $ejecucion, int $alertas = 0, bool $sinDatos = false): array
{
    if ($sinDatos) {
        return ['clase' => 'error', 'texto' => 'Sin datos'];
    }

    if ($ejecucion !== null && ($ejecucion['resultado'] ?? '') === 'ERROR') {
        return ['clase' => 'error', 'texto' => 'Con error'];
    }

    if ($alertas > 0) {
        return ['clase' => 'atencion', 'texto' => 'Requiere atención'];
    }

    if ($ejecucion === null) {
        return ['clase' => 'inactivo', 'texto' => 'Sin ejecuciones'];
    }

    if (($ejecucion['modo'] ?? '') === 'SIMULACION') {
        return ['clase' => 'simulacion', 'texto' => 'En simulación'];
    }

    return ['clase' => 'ok', 'texto' => 'Operando'];
}

/** "hoy 10:15", "ayer 09:00" o "25/11 14:00"; sin hora si $soloFecha. */
function fechaRelativaTablero(?string $fecha, bool $soloFecha = false): string
{
    if ($fecha === null || $fecha === '') {
        return '—';
    }

    try {
        $f = new DateTimeImmutable($fecha);
    } catch (Throwable $e) {
        return $fecha;
    }

    $hoy = new DateTimeImmutable('today');
    $dia = $f->setTime(0, 0);
    $hora = $soloFecha ? '' : ' ' . $f->format('H:i');

    if ($dia == $hoy) {
        return 'Hoy' . $hora;
    }

    if ($dia == $hoy->modify('-1 day')) {
        return 'Ayer' . $hora;
    }

    return $f->format($f->format('Y') === $hoy->format('Y') ? 'd/m' : 'd/m/Y') . $hora;
}

/** Texto de la última ejecución de un proceso, con el mismo formato del tablero. */
function ultimaEjecucionTablero(?array $ejecucion): string
{
    if (!$ejecucion) {
        return 'Sin ejecuciones registradas';
    }

    $texto = 'Última ejecución: ' . fechaRelativaTablero($ejecucion['fecha_fin'] ?? null);

    if (($ejecucion['modo'] ?? '') === 'SIMULACION') {
        $texto .= ' (simulación)';
    }

    return $texto;
}

/**
 * Solicitudes a Grezz de los últimos 7 días por resultado (para el proceso
 * de integración). null si la tabla no está disponible.
 */

require_once __DIR__ . '/automatizacion_servicios.php';
function solicitudesGrezzSemana(mysqli $conexion): ?array
{
    try {
        $fila = $conexion->query("
            SELECT
                SUM(estado = 'COMPLETADA') AS completadas,
                SUM(estado = 'SIMULADA')   AS simuladas,
                SUM(estado = 'RECHAZADA')  AS rechazadas,
                SUM(estado = 'PENDIENTE')  AS pendientes,
                MAX(fecha_solicitud)       AS ultima
            FROM solicitudes_estado_servicio
            WHERE fecha_solicitud >= CURDATE() - INTERVAL 7 DAY
        ")->fetch_assoc();

        return $fila ?: [];
    } catch (Throwable $e) {
        error_log('Centro de control (solicitudes Grezz): ' . $e->getMessage());
        return null;
    }
}

/** Importe total de los pagos de hoy (mismo criterio que el contador de pagos de hoy). */
function importePagosHoy(mysqli $conexion): ?float
{
    try {
        $fila = $conexion->query("
            SELECT COALESCE(SUM(monto), 0) AS total
            FROM pagos
            WHERE DATE(fecha_pago) = CURDATE()
              AND estado_pago = 'PAGADO'
              AND metodo_pago <> 'CORTESIA'
        ")->fetch_assoc();

        return (float)($fila['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('Centro de control (importe de hoy): ' . $e->getMessage());
        return null;
    }
}

/** Origen de un pago según cómo lo registró cada proceso. */
function origenPagoTablero(string $observaciones): string
{
    if (str_starts_with($observaciones, 'Pago registrado en UISP')) {
        return 'UISP';
    }

    if (str_contains($observaciones, 'Conciliación bancaria')) {
        return 'Banco';
    }

    return 'Captura manual';
}

/**
 * Últimos movimientos de todos los procesos, del más reciente al más antiguo.
 *
 * Cada elemento: tipo (pago, conciliacion, revision, suspension, reactivacion,
 * notificacion, error), titulo, detalle, fecha, solo_fecha, etiqueta,
 * etiqueta_clase, id_cliente, id_servicio.
 */
function actividadRecienteTablero(mysqli $conexion, ?mysqli $plataforma, int $limite = 25): array
{
    $eventos = [];

    // Pagos (manuales, de UISP y conciliados del banco). fecha_pago es la fecha
    // del pago, no la hora de captura: se muestra solo el día.
    try {
        $r = $conexion->query("
            SELECT id_pago, id_cliente, id_servicio, monto, fecha_pago, metodo_pago,
                   COALESCE(observaciones, '') AS observaciones
            FROM pagos
            WHERE estado_pago = 'PAGADO'
              AND metodo_pago <> 'CORTESIA'
              AND id_pago NOT IN (1, 2, 3, 4)
            ORDER BY fecha_pago DESC, id_pago DESC
            LIMIT $limite
        ");

        while ($f = $r->fetch_assoc()) {
            $origen = origenPagoTablero($f['observaciones']);
            $esBanco = $origen === 'Banco';

            $eventos[] = [
                'tipo' => $esBanco ? 'conciliacion' : 'pago',
                'titulo' => ($esBanco ? 'Pago conciliado del banco' : 'Pago registrado')
                    . ' · $' . number_format((float)$f['monto'], 2),
                'detalle' => trim($origen . ($f['metodo_pago'] ? ' · ' . $f['metodo_pago'] : '')),
                'fecha' => (string)$f['fecha_pago'],
                'solo_fecha' => true,
                'etiqueta' => $origen,
                'etiqueta_clase' => 'neutra',
                'id_cliente' => (int)$f['id_cliente'],
                'id_servicio' => (int)$f['id_servicio'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Centro de control (actividad: pagos): ' . $e->getMessage());
    }

    // Movimientos bancarios que la conciliación dejó para revisión manual.
    try {
        $r = $conexion->query("
            SELECT id_movimiento, motivo, fecha_revision
            FROM conciliacion_revisiones
            ORDER BY fecha_revision DESC
            LIMIT $limite
        ");

        while ($f = $r->fetch_assoc()) {
            $eventos[] = [
                'tipo' => 'revision',
                'titulo' => 'Movimiento bancario enviado a revisión',
                'detalle' => (string)$f['motivo'],
                'fecha' => (string)$f['fecha_revision'],
                'solo_fecha' => false,
                'etiqueta' => 'Movimiento ' . $f['id_movimiento'],
                'etiqueta_clase' => 'atencion',
                'id_cliente' => null,
                'id_servicio' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Centro de control (actividad: revisiones): ' . $e->getMessage());
    }

    // Suspensiones y reactivaciones solicitadas a Grezz.
    try {
        $r = $conexion->query("
            SELECT id_cliente, id_servicio, tipo_servicio, accion, motivo, estado,
                   fecha_solicitud, mensaje_respuesta
            FROM solicitudes_estado_servicio
            ORDER BY fecha_solicitud DESC
            LIMIT $limite
        ");

        $clases = [
            'COMPLETADA' => 'ok',
            'SIMULADA' => 'simulacion',
            'PENDIENTE' => 'atencion',
            'RECHAZADA' => 'error',
        ];

        while ($f = $r->fetch_assoc()) {
            $suspender = $f['accion'] === 'SUSPENDER';
            $tipoServicio = $f['tipo_servicio'] ? ' · ' . str_replace('PPPOE', 'PPPoE', (string)$f['tipo_servicio']) : '';

            $eventos[] = [
                'tipo' => $suspender ? 'suspension' : 'reactivacion',
                'titulo' => ($suspender ? 'Suspensión solicitada a servicios (RADIUS)' : 'Reactivación solicitada a servicios (RADIUS)') . $tipoServicio,
                'detalle' => (string)($f['mensaje_respuesta'] ?: $f['motivo']),
                'fecha' => (string)$f['fecha_solicitud'],
                'solo_fecha' => false,
                'etiqueta' => ucfirst(strtolower((string)$f['estado'])),
                'etiqueta_clase' => $clases[$f['estado']] ?? 'neutra',
                'id_cliente' => (int)$f['id_cliente'],
                'id_servicio' => (int)$f['id_servicio'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Centro de control (actividad: solicitudes): ' . $e->getMessage());
    }

    // Avisos a clientes enviados o con problema.
    try {
        $r = $conexion->query("
            SELECT id_cliente, id_servicio, tipo, estado, canal,
                   COALESCE(fecha_envio, fecha_creacion) AS fecha
            FROM notificaciones_cola
            WHERE estado IN ('ENVIADA', 'ERROR', 'SIN_TELEFONO')
            ORDER BY COALESCE(fecha_envio, fecha_creacion) DESC
            LIMIT $limite
        ");

        $tipos = [
            'RECORDATORIO_PREVIO' => 'Recordatorio de pago',
            'RECORDATORIO_HOY' => 'Recordatorio: vence hoy',
            'ULTIMO_AVISO' => 'Último aviso antes del corte',
            'SUSPENSION' => 'Aviso de suspensión',
            'PAGO_RECIBIDO' => 'Confirmación de pago',
        ];
        $estados = [
            'ENVIADA' => ['Enviado', 'ok'],
            'ERROR' => ['Error al enviar', 'error'],
            'SIN_TELEFONO' => ['Sin teléfono', 'atencion'],
        ];

        while ($f = $r->fetch_assoc()) {
            [$texto, $clase] = $estados[$f['estado']] ?? [(string)$f['estado'], 'neutra'];

            $eventos[] = [
                'tipo' => 'notificacion',
                'titulo' => ($tipos[$f['tipo']] ?? (string)$f['tipo']) . ' al cliente',
                'detalle' => $f['canal'] ? 'Canal: ' . $f['canal'] : '',
                'fecha' => (string)$f['fecha'],
                'solo_fecha' => false,
                'etiqueta' => $texto,
                'etiqueta_clase' => $clase,
                'id_cliente' => (int)$f['id_cliente'],
                'id_servicio' => (int)$f['id_servicio'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Centro de control (actividad: avisos): ' . $e->getMessage());
    }

    // Ejecuciones de procesos que terminaron con error.
    try {
        $r = $conexion->query("
            SELECT proceso, mensaje, fecha_fin
            FROM automatizacion_ejecuciones
            WHERE resultado = 'ERROR'
            ORDER BY fecha_fin DESC
            LIMIT 10
        ");

        while ($f = $r->fetch_assoc()) {
            $eventos[] = [
                'tipo' => 'error',
                'titulo' => 'Error en el proceso ' . str_replace('_', ' ', (string)$f['proceso']),
                'detalle' => (string)($f['mensaje'] ?? ''),
                'fecha' => (string)$f['fecha_fin'],
                'solo_fecha' => false,
                'etiqueta' => 'Error',
                'etiqueta_clase' => 'error',
                'id_cliente' => null,
                'id_servicio' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Centro de control (actividad: errores): ' . $e->getMessage());
    }

    usort($eventos, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    $eventos = array_slice($eventos, 0, $limite);

    // Nombre de los clientes (plataforma, solo lectura).
    $ids = array_values(array_unique(array_filter(array_map(fn($e) => (int)($e['id_cliente'] ?? 0), $eventos))));
    $nombres = [];

    if ($plataforma !== null && $ids) {
        try {
            $r = $plataforma->query("
                SELECT id_cliente, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre
                FROM clientes
                WHERE id_cliente IN (" . implode(',', $ids) . ")
            ");

            while ($f = $r->fetch_assoc()) {
                $nombres[(int)$f['id_cliente']] = trim((string)$f['nombre']);
            }
        } catch (Throwable $e) {
            error_log('Centro de control (actividad: clientes): ' . $e->getMessage());
        }
    }

    foreach ($eventos as &$evento) {
        $id = (int)($evento['id_cliente'] ?? 0);
        $evento['cliente'] = $id ? ($nombres[$id] ?? "Cliente $id") : null;
    }
    unset($evento);

    return $eventos;
}

/**
 * Servicios que deberían tener internet y siguen cortados: la reactivación
 * automática no se hizo o falló. Debe quedar vacía cuando todo funciona.
 *
 * Junta dos casos:
 *   - RADIUS (Grezz) los reporta SUSPENDIDO, no tienen mensualidad fuera de
 *     gracia y tienen al menos un pago registrado. Se exige el pago para no
 *     incluir los que se cortan a propósito por falta de datos del cliente.
 *   - La última solicitud a Grezz del servicio es una REACTIVAR de los
 *     últimos 7 días que Grezz rechazó o no confirmó en una hora (por
 *     ejemplo, servicios con varios equipos: SIN_RELACION).
 *
 * Cada elemento sale de la lista solo: cuando Grezz reporta el servicio
 * ACTIVO, cuando vuelve a deber (corte correcto) o cuando una nueva
 * solicitud lo resuelve.
 *
 * @param array|null $estadoPagos    estadoPagosPorServicio(), o null si no se pudo calcular
 * @param array|null $serviciosGrezz servicios de Grezz, o null si no respondió
 * @return array{elementos: array<int, array>, completo: bool}
 */
function reactivacionesFallidasTablero(
    mysqli $conexion,
    ?mysqli $plataforma,
    ?array $estadoPagos,
    ?array $serviciosGrezz
): array {
    $elementos = [];
    $completo = $estadoPagos !== null && $serviciosGrezz !== null;
    $vencido = fn(int $id) => ($estadoPagos[$id]['tipo'] ?? '') === 'vencido';

    // Estado y tipo según Grezz.
    $grezz = [];
    foreach ($serviciosGrezz ?? [] as $s) {
        $id = filter_var($s['id_servicio'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $grezz[(int)$id] = [
                'estado' => strtoupper((string)($s['estado_actual'] ?? '')),
                'tipo' => tipoServicioGrezz($s),
            ];
        }
    }

    // 1. Cortados en RADIUS sin adeudo vencido y con pagos registrados.
    if ($completo) {
        $candidatos = [];
        foreach ($grezz as $id => $g) {
            if ($g['estado'] === 'SUSPENDIDO' && !$vencido($id)) {
                $candidatos[] = $id;
            }
        }

        if ($candidatos) {
            try {
                $r = $conexion->query("
                    SELECT id_servicio, MAX(id_cliente) AS id_cliente, MAX(fecha_pago) AS ultimo_pago
                    FROM pagos
                    WHERE estado_pago = 'PAGADO'
                      AND metodo_pago <> 'CORTESIA'
                      AND id_servicio IN (" . implode(',', $candidatos) . ")
                    GROUP BY id_servicio
                ");

                while ($f = $r->fetch_assoc()) {
                    $id = (int)$f['id_servicio'];
                    $situacion = $estadoPagos[$id]['situacion'] ?? null;

                    $elementos[$id] = [
                        'id_servicio' => $id,
                        'id_cliente' => (int)$f['id_cliente'],
                        'tipo' => $grezz[$id]['tipo'],
                        'motivo' => $situacion === null
                            ? 'Está al corriente y RADIUS lo reporta cortado'
                            : "Su mensualidad no está fuera de gracia ($situacion) y RADIUS lo reporta cortado",
                        'etiqueta' => 'Cortado sin adeudo',
                        'fecha' => (string)$f['ultimo_pago'],
                        'texto_fecha' => 'último pago',
                    ];
                }
            } catch (Throwable $e) {
                $completo = false;
                error_log('Centro de control (reactivaciones: pagos): ' . $e->getMessage());
            }
        }
    }

    // 2. Reactivaciones que Grezz rechazó o no confirmó.
    try {
        $r = $conexion->query("
            SELECT s.id_servicio, s.id_cliente, s.tipo_servicio, s.estado,
                   s.fecha_solicitud, s.mensaje_respuesta
            FROM solicitudes_estado_servicio s
            INNER JOIN (
                SELECT id_servicio, MAX(fecha_solicitud) AS ultima
                FROM solicitudes_estado_servicio
                GROUP BY id_servicio
            ) u ON u.id_servicio = s.id_servicio AND u.ultima = s.fecha_solicitud
            WHERE s.accion = 'REACTIVAR'
              AND s.fecha_solicitud >= NOW() - INTERVAL 7 DAY
              AND (s.estado = 'RECHAZADA'
                   OR (s.estado = 'PENDIENTE' AND s.fecha_solicitud < NOW() - INTERVAL 1 HOUR))
        ");

        while ($f = $r->fetch_assoc()) {
            $id = (int)$f['id_servicio'];

            if (isset($elementos[$id]) || ($grezz[$id]['estado'] ?? '') === 'ACTIVO' || $vencido($id)) {
                continue;
            }

            $rechazada = $f['estado'] === 'RECHAZADA';
            $respuesta = trim((string)($f['mensaje_respuesta'] ?? ''));

            $elementos[$id] = [
                'id_servicio' => $id,
                'id_cliente' => (int)$f['id_cliente'],
                'tipo' => $grezz[$id]['tipo'] ?? ($f['tipo_servicio'] ? strtoupper((string)$f['tipo_servicio']) : null),
                'motivo' => ($rechazada ? 'Grezz rechazó la reactivación' : 'Grezz no confirmó la reactivación')
                    . ($respuesta !== '' ? ": $respuesta" : ''),
                'etiqueta' => $rechazada ? 'Reactivación rechazada' : 'Sin confirmar',
                'fecha' => (string)$f['fecha_solicitud'],
                'texto_fecha' => 'solicitada',
            ];
        }
    } catch (Throwable $e) {
        $completo = false;
        error_log('Centro de control (reactivaciones: solicitudes): ' . $e->getMessage());
    }

    // Nombre de los clientes (plataforma, solo lectura).
    $ids = array_values(array_unique(array_filter(array_column($elementos, 'id_cliente'))));
    $nombres = [];

    if ($plataforma !== null && $ids) {
        try {
            $r = $plataforma->query("
                SELECT id_cliente, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre
                FROM clientes
                WHERE id_cliente IN (" . implode(',', array_map('intval', $ids)) . ")
            ");

            while ($f = $r->fetch_assoc()) {
                $nombres[(int)$f['id_cliente']] = trim((string)$f['nombre']);
            }
        } catch (Throwable $e) {
            error_log('Centro de control (reactivaciones: clientes): ' . $e->getMessage());
        }
    }

    foreach ($elementos as &$e) {
        $e['cliente'] = $e['id_cliente'] ? ($nombres[$e['id_cliente']] ?? 'Cliente ' . $e['id_cliente']) : null;
        $e['protegido'] = servicioProtegido($e['id_servicio']);
    }
    unset($e);

    // Los pagos más antiguos primero: son los que llevan más tiempo sin servicio.
    usort($elementos, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

    return ['elementos' => $elementos, 'completo' => $completo];
}
