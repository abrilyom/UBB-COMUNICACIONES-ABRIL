<?php
declare(strict_types=1);

/**
 * Situación de pago de cada servicio según las mensualidades REALES
 * (obligaciones_pago) y los pagos aplicados (pagos_obligaciones).
 *
 * Es la misma regla que usan la conciliación y la suspensión automática,
 * para que el panel y la automatización muestren lo mismo:
 *   - próximo:  vence en 1 a 10 días
 *   - hoy:      vence hoy
 *   - gracia:   vencida hace 1 a 10 días
 *   - vencido:  vencida hace más de 10 días (se suspende el día 11)
 *   - futuro:   vence en más de 10 días
 *
 * Un servicio sin mensualidades con saldo está AL CORRIENTE.
 */

if (!defined('UBB_DIAS_GRACIA')) {
    define('UBB_DIAS_GRACIA', 10);
}

/**
 * @return array<int, array> [id_servicio => situación de su mensualidad más antigua con saldo]
 */
function estadoPagosPorServicio(mysqli $conexion, ?DateTimeImmutable $hoy = null): array
{
    $hoy ??= new DateTimeImmutable('today');

    $resultado = $conexion->query("
        SELECT o.id_obligacion,
               o.id_cliente,
               o.id_servicio,
               o.periodo,
               o.fecha_vencimiento,
               o.referencia_cobro,
               o.monto,
               COALESCE(a.total_pagado, 0) AS total_pagado,
               o.monto - COALESCE(a.total_pagado, 0) AS saldo
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT po.id_obligacion, SUM(po.monto_aplicado) AS total_pagado
            FROM pagos_obligaciones po
            INNER JOIN pagos p ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.concepto = 'MENSUALIDAD'
          AND o.estado = 'PENDIENTE'
          AND o.fecha_vencimiento IS NOT NULL
          AND o.monto > COALESCE(a.total_pagado, 0)
        ORDER BY o.fecha_vencimiento ASC, o.id_obligacion ASC
    ");

    $estado = [];

    while ($fila = $resultado->fetch_assoc()) {
        $idServicio = (int)$fila['id_servicio'];

        // La mensualidad más antigua define la situación; las demás se suman.
        if (isset($estado[$idServicio])) {
            $estado[$idServicio]['mensualidades_pendientes']++;
            $estado[$idServicio]['saldo_total'] += (float)$fila['saldo'];
            continue;
        }

        $vencimiento = new DateTimeImmutable(substr((string)$fila['fecha_vencimiento'], 0, 10));
        $limite = $vencimiento->modify('+' . UBB_DIAS_GRACIA . ' days');
        $diferencia = (int)$hoy->diff($vencimiento)->format('%r%a');

        if ($diferencia > UBB_DIAS_GRACIA) {
            $tipo = 'futuro';
            $situacion = 'Al corriente';
            $dias = "Vence en $diferencia días";
        } elseif ($diferencia > 0) {
            $tipo = 'proximo';
            $situacion = 'Próximo a vencer';
            $dias = "Faltan $diferencia días";
        } elseif ($diferencia === 0) {
            $tipo = 'hoy';
            $situacion = 'Vence hoy';
            $dias = 'Vence hoy';
        } elseif (abs($diferencia) <= UBB_DIAS_GRACIA) {
            $tipo = 'gracia';
            $situacion = 'En plazo de gracia';
            $dias = abs($diferencia) . ' días desde el vencimiento';
        } else {
            $tipo = 'vencido';
            $situacion = 'Plazo vencido';
            $dias = (abs($diferencia) - UBB_DIAS_GRACIA) . ' días fuera de plazo';
        }

        $estado[$idServicio] = [
            'id_obligacion' => (int)$fila['id_obligacion'],
            'id_cliente' => (int)$fila['id_cliente'],
            'periodo' => (string)$fila['periodo'],
            'referencia' => (string)$fila['referencia_cobro'],
            'monto' => (float)$fila['monto'],
            'pagado' => (float)$fila['total_pagado'],
            'saldo' => (float)$fila['saldo'],
            'saldo_total' => (float)$fila['saldo'],
            'mensualidades_pendientes' => 1,
            // Mismas claves que usaban las pantallas para no cambiar su diseño.
            'fecha_pago' => $vencimiento,
            'limite' => $limite,
            'dias' => $dias,
            'situacion' => $situacion,
            'tipo' => $tipo,
        ];
    }

    return $estado;
}

/**
 * Texto corto del adeudo, por ejemplo "$250.00" o "$500.00 (2 meses)".
 */
function textoAdeudo(array $situacion): string
{
    $texto = '$' . number_format((float)$situacion['saldo_total'], 2);

    if (($situacion['mensualidades_pendientes'] ?? 1) > 1) {
        $texto .= ' (' . $situacion['mensualidades_pendientes'] . ' meses)';
    }

    return $texto;
}
