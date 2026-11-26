<?php
declare(strict_types=1);

/*
 * MENSUALIDADES DESDE LAS FACTURAS DE LA PLATAFORMA (Jamileth)
 *
 * Para los servicios que se facturan en la plataforma (servicios.factura_en_plataforma = 1)
 * lo que debe el cliente es su FACTURA, no el precio del plan: puede incluir
 * reconexión, diferencia por cambio de paquete o equipo en parcialidades.
 *
 * Este script lee esas facturas (solo lectura en ubb_plataforma) y mantiene en
 * UBB Pagos una mensualidad por factura (obligaciones_pago.id_factura):
 *   - monto = total de la factura          - referencia_cobro = folio (FAC-000001)
 *   - periodo = mes de periodo_inicio      - fecha_vencimiento = periodo_inicio
 *     (prepago: con los 10 días de gracia vence en fecha_limite_pago)
 * Así vencidos, suspensiones, avisos y Registrar pago funcionan igual que hoy.
 *
 * También liga los pagos de UBB que traen id_factura con su mensualidad, para
 * que una factura pagada no aparezca como deuda.
 *
 * No toca mensualidades por plan que ya existan: si una choca con una factura
 * (mismo servicio y mes) se reporta para revisión.
 *
 * Uso:
 *   php sincronizar_facturas_plataforma.php              (simulación)
 *   php sincronizar_facturas_plataforma.php --confirmar
 */

require_once __DIR__ . '/../includes/entorno_cli.php';

const FACT_PLATAFORMA_PROCESO = 'FACTURAS_PLATAFORMA';

$opciones = array_slice($argv, 1);
foreach ($opciones as $opcion) {
    if ($opcion !== '--confirmar') {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}
$confirmar = in_array('--confirmar', $opciones, true);

function centavosFp($monto): int
{
    return (int)round((float)$monto * 100);
}

function pesosFp(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

/** Total aplicado por pagos PAGADO a una mensualidad, en centavos. */
function aplicadoMensualidad(mysqli $conexion, int $idObligacion): int
{
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(po.monto_aplicado), 0)
        FROM pagos_obligaciones po
        INNER JOIN pagos p ON p.id_pago = po.id_pago
        WHERE po.id_obligacion = ? AND p.estado_pago = 'PAGADO'
    ");
    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();
    $valor = centavosFp($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    return $valor;
}

$conexion = null;
$plataforma = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_facturas_plataforma';
$inicio = new DateTimeImmutable();

$contadores = [
    'facturas' => 0,
    'creadas' => 0,
    'montos_actualizados' => 0,
    'canceladas' => 0,
    'sin_cambios' => 0,
    'pagos_ligados' => 0,
    'mensualidades_pagadas' => 0,
    'mensualidades_reabiertas' => 0,
    'para_revision' => 0,
];
$revision = [];

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    if ($conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'id_factura'")->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-03_facturas_plataforma.sql.');
    }

    // ¿La mensualidad admite el estado CANCELADA?
    $tipoEstado = (string)$conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'estado'")->fetch_assoc()['Type'];
    $admiteCancelada = str_contains($tipoEstado, "'CANCELADA'");

    // Columnas de meses sin servicio (2026-12-05_meses_sin_servicio.sql, opcional).
    $colCorte = $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'cargo_reconexion'")->num_rows > 0
        ? 'cargo_reconexion, no_cobro_motivo'
        : '0 AS cargo_reconexion, NULL AS no_cobro_motivo';

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n\n";

    $plataforma = conectarPlataformaCli();
    $facturas = $plataforma->query("
        SELECT f.id_factura, f.folio, s.id_cliente, f.id_servicio, f.periodo_inicio, f.periodo_fin,
               f.total, f.saldo, f.estado, f.fecha_limite_pago, f.es_prueba
        FROM facturas f
        INNER JOIN servicios s ON s.id_servicio = f.id_servicio
        WHERE s.factura_en_plataforma = 1
        ORDER BY f.periodo_inicio, f.id_factura
    ")->fetch_all(MYSQLI_ASSOC);
    $plataforma->close();
    $plataforma = null;

    $contadores['facturas'] = count($facturas);
    echo "Facturas de servicios facturados en la plataforma: " . count($facturas) . "\n\n";

    if ($confirmar) {
        $conexion->begin_transaction();
    }

    /* ===== 1. Una mensualidad por factura ===== */
    foreach ($facturas as $f) {
        $idFactura = (int)$f['id_factura'];
        $folio = (string)$f['folio'];
        $idCliente = (int)$f['id_cliente'];
        $idServicio = (int)$f['id_servicio'];
        $total = centavosFp($f['total']);
        $periodo = str_replace('-', '', substr((string)$f['periodo_inicio'], 0, 7));
        $vencimiento = substr((string)$f['periodo_inicio'], 0, 10);

        $stmt = $conexion->prepare("SELECT id_obligacion, monto, estado, $colCorte FROM obligaciones_pago WHERE id_factura = ?");
        $stmt->bind_param('i', $idFactura);
        $stmt->execute();
        $obligacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($f['estado'] === 'CANCELADA') {
            if ($obligacion && $obligacion['estado'] === 'PENDIENTE') {
                $aplicado = aplicadoMensualidad($conexion, (int)$obligacion['id_obligacion']);

                if (!$admiteCancelada || $aplicado > 0) {
                    $revision[] = "$folio cancelada en la plataforma, pero su mensualidad UBB "
                        . ($aplicado > 0 ? 'ya tiene pagos aplicados' : 'no admite el estado CANCELADA') . '.';
                    continue;
                }

                echo "  ✖ $folio cancelada → mensualidad {$obligacion['id_obligacion']} CANCELADA\n";
                $contadores['canceladas']++;

                if ($confirmar) {
                    $stmt = $conexion->prepare("UPDATE obligaciones_pago SET estado = 'CANCELADA' WHERE id_obligacion = ? AND estado = 'PENDIENTE'");
                    $stmt->bind_param('i', $obligacion['id_obligacion']);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            continue;
        }

        if ($obligacion) {
            // Mes que no se cobra porque el servicio estaba cortado (meses_sin_servicio.php): no se reabre.
            if ($obligacion['estado'] === 'CANCELADA' && $obligacion['no_cobro_motivo'] !== null) {
                $contadores['sin_cambios']++;
                continue;
            }

            // La reconexión que suma UBB (meses_sin_servicio.php) se conserva sobre el total de la factura.
            $total += centavosFp($obligacion['cargo_reconexion']);

            // La factura puede cambiar de total si se le agrega un cargo (reconexión, equipo…).
            if (centavosFp($obligacion['monto']) === $total) {
                $contadores['sin_cambios']++;
                continue;
            }

            $aplicado = aplicadoMensualidad($conexion, (int)$obligacion['id_obligacion']);

            if ($total < $aplicado) {
                $revision[] = "$folio: el total bajó a " . pesosFp($total) . " pero ya tiene " . pesosFp($aplicado) . " pagados.";
                continue;
            }

            echo "  ✎ $folio: monto " . pesosFp(centavosFp($obligacion['monto'])) . " → " . pesosFp($total) . "\n";
            $contadores['montos_actualizados']++;

            if ($confirmar) {
                $montoTexto = pesosFp($total);
                $nuevoEstado = $aplicado >= $total ? 'PAGADA' : 'PENDIENTE';
                $stmt = $conexion->prepare("UPDATE obligaciones_pago SET monto = ?, estado = ? WHERE id_obligacion = ?");
                $stmt->bind_param('ssi', $montoTexto, $nuevoEstado, $obligacion['id_obligacion']);
                $stmt->execute();
                $stmt->close();
            }
            continue;
        }

        // ¿Ya hay una mensualidad por plan para ese servicio y mes? No se toca: revisión.
        $stmt = $conexion->prepare("
            SELECT id_obligacion, referencia_cobro FROM obligaciones_pago
            WHERE id_servicio = ? AND periodo = ? AND concepto = 'MENSUALIDAD'
        ");
        $stmt->bind_param('is', $idServicio, $periodo);
        $stmt->execute();
        $choque = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($choque) {
            $revision[] = "$folio (servicio $idServicio, $periodo) choca con la mensualidad UBB "
                . "{$choque['id_obligacion']} ({$choque['referencia_cobro']}).";
            continue;
        }

        echo "  + $folio → mensualidad $periodo del servicio $idServicio por " . pesosFp($total)
            . " (vence $vencimiento, límite {$f['fecha_limite_pago']})"
            . ((int)$f['es_prueba'] === 1 ? ' [prueba]' : '') . "\n";
        $contadores['creadas']++;

        if ($confirmar) {
            $montoTexto = pesosFp($total);
            $observaciones = "Factura $folio de la plataforma (periodo {$f['periodo_inicio']} a {$f['periodo_fin']}).";
            $stmt = $conexion->prepare("
                INSERT INTO obligaciones_pago
                    (id_cliente, id_servicio, periodo, concepto, monto, fecha_vencimiento,
                     referencia_cobro, estado, observaciones, id_factura)
                VALUES (?, ?, ?, 'MENSUALIDAD', ?, ?, ?, 'PENDIENTE', ?, ?)
            ");
            $stmt->bind_param('iisssssi', $idCliente, $idServicio, $periodo, $montoTexto,
                $vencimiento, $folio, $observaciones, $idFactura);
            $stmt->execute();
            $stmt->close();
        }
    }

    /* ===== 2. Ligar los pagos de UBB que traen id_factura con su mensualidad ===== */
    $pagos = $conexion->query("
        SELECT p.id_pago, p.id_factura, p.monto,
               COALESCE((SELECT SUM(po.monto_aplicado) FROM pagos_obligaciones po WHERE po.id_pago = p.id_pago), 0) AS aplicado
        FROM pagos p
        WHERE p.estado_pago = 'PAGADO' AND p.id_factura IS NOT NULL
        ORDER BY p.fecha_pago, p.id_pago
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($pagos as $p) {
        $disponible = centavosFp($p['monto']) - centavosFp($p['aplicado']);
        $idFactura = (int)$p['id_factura'];

        if ($disponible <= 0) {
            // Ya está aplicado completo: avisar si fue a una mensualidad que no es la de su factura.
            $stmt = $conexion->prepare("
                SELECT COUNT(*) FROM pagos_obligaciones po
                INNER JOIN obligaciones_pago o ON o.id_obligacion = po.id_obligacion
                WHERE po.id_pago = ? AND (o.id_factura IS NULL OR o.id_factura <> ?)
            ");
            $stmt->bind_param('ii', $p['id_pago'], $idFactura);
            $stmt->execute();
            if ((int)$stmt->get_result()->fetch_row()[0] > 0) {
                $revision[] = "Pago {$p['id_pago']} es de la factura $idFactura, pero está aplicado a otra mensualidad.";
            }
            $stmt->close();
            continue;
        }

        $stmt = $conexion->prepare("
            SELECT id_obligacion, monto, estado FROM obligaciones_pago WHERE id_factura = ? FOR UPDATE
        ");
        $stmt->bind_param('i', $idFactura);
        $stmt->execute();
        $obligacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$obligacion) {
            if (!$confirmar) {
                // En simulación la mensualidad aún no existe; se ligaría al confirmar.
                echo "  ↪ Pago {$p['id_pago']} → factura $idFactura (se liga al crear su mensualidad)\n";
                $contadores['pagos_ligados']++;
            } else {
                $revision[] = "Pago {$p['id_pago']} trae id_factura $idFactura, pero no hay mensualidad para esa factura.";
            }
            continue;
        }

        if ($obligacion['estado'] === 'CANCELADA') {
            $revision[] = "Pago {$p['id_pago']} de la factura $idFactura: su mensualidad {$obligacion['id_obligacion']} está cancelada "
                . "(p. ej. mes sin servicio por corte); no se aplica.";
            continue;
        }

        $idObligacion = (int)$obligacion['id_obligacion'];
        $saldo = centavosFp($obligacion['monto']) - aplicadoMensualidad($conexion, $idObligacion);

        if ($saldo <= 0) {
            $revision[] = "Pago {$p['id_pago']} de la factura $idFactura: la mensualidad ya está cubierta; sobran " . pesosFp($disponible) . '.';
            continue;
        }

        $monto = min($disponible, $saldo);
        $liquida = $monto === $saldo;
        echo "  ↪ Pago {$p['id_pago']} → mensualidad $idObligacion (factura $idFactura) " . pesosFp($monto)
            . ($liquida ? ' (pagada)' : ' (parcial)') . "\n";
        $contadores['pagos_ligados']++;
        if ($liquida) {
            $contadores['mensualidades_pagadas']++;
        }

        if ($confirmar) {
            $montoTexto = pesosFp($monto);
            $stmt = $conexion->prepare("
                INSERT INTO pagos_obligaciones (id_pago, id_obligacion, monto_aplicado, fecha_aplicacion)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param('iis', $p['id_pago'], $idObligacion, $montoTexto);
            $stmt->execute();
            $stmt->close();

            if ($liquida) {
                $stmt = $conexion->prepare("UPDATE obligaciones_pago SET estado = 'PAGADA' WHERE id_obligacion = ? AND estado = 'PENDIENTE'");
                $stmt->bind_param('i', $idObligacion);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /* ===== 3. Mensualidades de factura PAGADAS cuyo pago se canceló: vuelven a PENDIENTE ===== */
    $pagadas = $conexion->query("
        SELECT id_obligacion, monto FROM obligaciones_pago
        WHERE id_factura IS NOT NULL AND estado = 'PAGADA'
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($pagadas as $o) {
        $idObligacion = (int)$o['id_obligacion'];
        if (aplicadoMensualidad($conexion, $idObligacion) >= centavosFp($o['monto'])) {
            continue;
        }

        echo "  ↺ Mensualidad $idObligacion: su pago ya no está PAGADO → PENDIENTE\n";
        $contadores['mensualidades_reabiertas']++;

        if ($confirmar) {
            $stmt = $conexion->prepare("UPDATE obligaciones_pago SET estado = 'PENDIENTE' WHERE id_obligacion = ? AND estado = 'PAGADA'");
            $stmt->bind_param('i', $idObligacion);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($confirmar) {
        $conexion->commit();
    }

    $contadores['para_revision'] = count($revision);

    echo "\n===== RESUMEN =====\n";
    echo "Facturas:                    {$contadores['facturas']}\n";
    echo "Mensualidades creadas:       {$contadores['creadas']}\n";
    echo "Montos actualizados:         {$contadores['montos_actualizados']}\n";
    echo "Canceladas:                  {$contadores['canceladas']}\n";
    echo "Sin cambios:                 {$contadores['sin_cambios']}\n";
    echo "Pagos ligados a su factura:  {$contadores['pagos_ligados']} ({$contadores['mensualidades_pagadas']} mensualidades pagadas)\n";
    echo "Mensualidades reabiertas:    {$contadores['mensualidades_reabiertas']}\n";

    if ($revision) {
        echo "\nPara revisión ({$contadores['para_revision']}):\n";
        foreach ($revision as $texto) {
            echo "  ⚠ $texto\n";
        }
    }

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    registrarEjecucion($conexion, FACT_PLATAFORMA_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli && $confirmar) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, FACT_PLATAFORMA_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
    }

    $codigoSalida = 1;
} finally {
    if ($plataforma instanceof mysqli) {
        $plataforma->close();
    }
    if ($conexion instanceof mysqli) {
        if ($bloqueo) {
            liberarBloqueoScript($conexion, $nombreBloqueo);
        }
        $conexion->close();
    }
}

exit($codigoSalida ?? 0);
