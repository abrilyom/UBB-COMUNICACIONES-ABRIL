<?php
declare(strict_types=1);

/*
 * MENSUALIDADES DESDE LAS FACTURAS DE UISP
 *
 * Para los servicios que se facturan en UISP (factura_en_plataforma = 0), lo que
 * debe el cliente es su factura de UISP, no el precio del plan. Con las facturas
 * que copia sincronizar_facturas_uisp.php (tabla uisp_facturas), este script
 * mantiene una mensualidad por factura (obligaciones_pago.uisp_invoice_id):
 *
 *   - Si ya existe la mensualidad de ese servicio y mes (generada por plan), se
 *     LIGA a la factura y su monto se ajusta al total facturado. No se cambia su
 *     referencia de cobro.
 *   - Si no existe, se crea: monto = total, mes = inicio del periodo,
 *     vencimiento = inicio del periodo (prepago; con 10 días de gracia),
 *     referencia = UBBC<cliente>S<servicio>M<AAAAMMDD>.
 *   - Si la factura se anula en UISP, la mensualidad se cancela (si no tiene pagos).
 *   - Si la factura cambia de total (p. ej. se agrega reconexión), se ajusta.
 *   El estado (PENDIENTE / PAGADA) se recalcula con los pagos ya aplicados.
 *
 * Solo facturas cuyo periodo empieza desde --desde (por defecto 2026-11-01, el
 * primer mes con mensualidades en UBB): las anteriores son historia.
 *
 * Uso:
 *   php mensualidades_desde_uisp.php                  (simulación)
 *   php mensualidades_desde_uisp.php --confirmar
 */

require_once __DIR__ . '/../includes/entorno_cli.php';

const MENS_UISP_PROCESO = 'MENSUALIDADES_UISP';

$confirmar = false;
$desde = getenv('UBB_MENSUALIDADES_UISP_DESDE') ?: '2026-11-01';

foreach (array_slice($argv, 1) as $opcion) {
    if ($opcion === '--confirmar') {
        $confirmar = true;
    } elseif (preg_match('/^--desde=(\d{4}-\d{2}-\d{2})$/', $opcion, $m)) {
        $desde = $m[1];
    } else {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

function centavosMu($monto): int
{
    return (int)round((float)$monto * 100);
}

function pesosMu(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

function aplicadoMu(mysqli $conexion, int $idObligacion): int
{
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(po.monto_aplicado), 0)
        FROM pagos_obligaciones po
        INNER JOIN pagos p ON p.id_pago = po.id_pago
        WHERE po.id_obligacion = ? AND p.estado_pago = 'PAGADO'
    ");
    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();
    $valor = centavosMu($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    return $valor;
}

$conexion = null;
$plataforma = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_mensualidades_uisp';
$inicio = new DateTimeImmutable();

$contadores = [
    'facturas' => 0,
    'creadas' => 0,
    'ligadas' => 0,
    'montos_ajustados' => 0,
    'canceladas' => 0,
    'sin_cambios' => 0,
    'antes_de_desde' => 0,
    'sin_servicio_ubb' => 0,
    'no_cobradas_por_corte' => 0,
    'reabiertas_por_factura' => 0,
    'para_revision' => 0,
];
$revision = [];
$ajustes = [];
$mostrados = 0;

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    if ($conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'uisp_invoice_id'")->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-04_mensualidades_uisp.sql.');
    }
    if ($conexion->query("SHOW TABLES LIKE 'uisp_facturas'")->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-01_facturas_uisp.sql.');
    }

    // Mensualidades que no se cobran por corte (2026-12-05_meses_sin_servicio.sql, opcional).
    $colNoCobro = $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'no_cobro_motivo'")->num_rows > 0
        ? 'no_cobro_motivo' : 'NULL AS no_cobro_motivo';

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n";
    echo "Facturas de UISP con periodo desde $desde\n\n";

    // Cliente UISP → cliente UBB, y servicios UBB que se facturan en UISP.
    $plataforma = conectarPlataformaCli();
    $clientesUisp = [];
    $r = $plataforma->query("
        SELECT id_cliente, TRIM(uisp_id) FROM clientes WHERE uisp_id IS NOT NULL AND TRIM(uisp_id) <> ''
    ");
    while ([$idCliente, $uispId] = $r->fetch_row()) {
        $clientesUisp[$uispId][] = (int)$idCliente;
    }

    $serviciosCliente = [];
    $r = $plataforma->query("
        SELECT id_cliente, id_servicio, estado FROM servicios WHERE COALESCE(factura_en_plataforma, 0) = 0
    ");
    while ([$idCliente, $idServicio, $estado] = $r->fetch_row()) {
        $serviciosCliente[(int)$idCliente][] = ['id' => (int)$idServicio, 'estado' => (string)$estado];
    }
    $plataforma->close();
    $plataforma = null;

    $facturas = $conexion->query("
        SELECT uisp_invoice_id, numero, uisp_client_id, periodo_inicio, periodo_fin, total, estado
        FROM uisp_facturas
        WHERE proforma = 0
          AND estado IN ('PENDIENTE', 'PARCIAL', 'PAGADA', 'ANULADA')
          AND periodo_inicio IS NOT NULL
        ORDER BY periodo_inicio, uisp_invoice_id
    ")->fetch_all(MYSQLI_ASSOC);

    $contadores['facturas'] = count($facturas);

    if ($confirmar) {
        $conexion->begin_transaction();
    }

    foreach ($facturas as $f) {
        $idFactura = (int)$f['uisp_invoice_id'];
        $numero = (string)$f['numero'];
        $total = centavosMu($f['total']);
        $inicioPeriodo = (string)$f['periodo_inicio'];
        $periodo = str_replace('-', '', substr($inicioPeriodo, 0, 7));
        $anulada = $f['estado'] === 'ANULADA';

        // ¿Ya hay mensualidad ligada a esta factura?
        $stmt = $conexion->prepare("SELECT id_obligacion, monto, estado, $colNoCobro FROM obligaciones_pago WHERE uisp_invoice_id = ?");
        $stmt->bind_param('i', $idFactura);
        $stmt->execute();
        $ligada = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ligada && $inicioPeriodo < $desde) {
            $contadores['antes_de_desde']++;
            continue;
        }

        // Mes que no se cobra porque el servicio estaba cortado (meses_sin_servicio.php): no se reabre.
        if ($ligada && $ligada['estado'] === 'CANCELADA' && $ligada['no_cobro_motivo'] !== null) {
            $contadores['no_cobradas_por_corte']++;
            continue;
        }

        if ($ligada) {
            $idObligacion = (int)$ligada['id_obligacion'];
            $aplicado = aplicadoMu($conexion, $idObligacion);

            if ($anulada) {
                if ($ligada['estado'] === 'CANCELADA') {
                    $contadores['sin_cambios']++;
                } elseif ($aplicado > 0) {
                    $revision[] = "Factura $numero anulada en UISP, pero su mensualidad $idObligacion ya tiene pagos.";
                } else {
                    $contadores['canceladas']++;
                    if ($confirmar) {
                        $stmt = $conexion->prepare("UPDATE obligaciones_pago SET estado = 'CANCELADA' WHERE id_obligacion = ?");
                        $stmt->bind_param('i', $idObligacion);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                continue;
            }

            if (centavosMu($ligada['monto']) === $total) {
                $contadores['sin_cambios']++;
                continue;
            }
            // El total de la factura cambió: se ajusta más abajo.
            $obligacion = $ligada + ['liga' => false];
        } else {
            if ($anulada) {
                continue;
            }

            // Servicio UBB de este cliente que se factura en UISP.
            $clientes = $clientesUisp[trim((string)$f['uisp_client_id'])] ?? [];
            if (count($clientes) !== 1) {
                $contadores['sin_servicio_ubb']++;
                if (count($clientes) > 1) {
                    $revision[] = "Factura $numero: el cliente UISP {$f['uisp_client_id']} está ligado a varios clientes UBB.";
                }
                continue;
            }
            $idCliente = $clientes[0];
            $servicios = $serviciosCliente[$idCliente] ?? [];
            $vigentes = array_values(array_filter($servicios, fn($s) => $s['estado'] !== 'BAJA'));
            $candidatos = count($vigentes) > 0 ? $vigentes : $servicios;

            if (count($candidatos) !== 1) {
                $contadores['sin_servicio_ubb']++;
                $revision[] = "Factura $numero: el cliente UBB $idCliente tiene "
                    . count($candidatos) . " servicios facturados en UISP; no se sabe a cuál corresponde.";
                continue;
            }
            $idServicio = $candidatos[0]['id'];

            // ¿Existe ya la mensualidad de ese servicio y mes (por plan)?
            $stmt = $conexion->prepare("
                SELECT id_obligacion, monto, estado, uisp_invoice_id, id_factura, $colNoCobro
                FROM obligaciones_pago
                WHERE id_servicio = ? AND periodo = ? AND concepto = 'MENSUALIDAD'
            ");
            $stmt->bind_param('is', $idServicio, $periodo);
            $stmt->execute();
            $existente = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existente && ($existente['uisp_invoice_id'] !== null || $existente['id_factura'] !== null)) {
                $revision[] = "Factura $numero (servicio $idServicio, $periodo): ese mes ya tiene la mensualidad "
                    . "{$existente['id_obligacion']} ligada a otra factura (¿dos facturas en el mismo mes?).";
                continue;
            }

            if (!$existente) {
                // Crear la mensualidad de esta factura.
                $vencimiento = substr($inicioPeriodo, 0, 10);
                $referencia = 'UBBC' . $idCliente . 'S' . $idServicio . 'M' . str_replace('-', '', $vencimiento);
                $contadores['creadas']++;

                if ($mostrados++ < 15) {
                    echo "  + Factura $numero → mensualidad $periodo del servicio $idServicio por "
                        . pesosMu($total) . " ($referencia)\n";
                }

                if ($confirmar) {
                    $montoTexto = pesosMu($total);
                    $observaciones = "Factura UISP $numero (periodo {$f['periodo_inicio']} a {$f['periodo_fin']}).";
                    $stmt = $conexion->prepare("
                        INSERT INTO obligaciones_pago
                            (id_cliente, id_servicio, periodo, concepto, monto, fecha_vencimiento,
                             referencia_cobro, estado, observaciones, uisp_invoice_id)
                        VALUES (?, ?, ?, 'MENSUALIDAD', ?, ?, ?, 'PENDIENTE', ?, ?)
                    ");
                    $stmt->bind_param('iisssssi', $idCliente, $idServicio, $periodo, $montoTexto,
                        $vencimiento, $referencia, $observaciones, $idFactura);
                    $stmt->execute();
                    $stmt->close();
                }
                continue;
            }

            // Se canceló por corte porque UISP no la había facturado; ahora sí la factura
            // (p. ej. el cliente regresó): se reabre y se liga a su factura.
            $reabrir = $existente['estado'] === 'CANCELADA' && $existente['no_cobro_motivo'] !== null;

            if ($reabrir) {
                $contadores['reabiertas_por_factura']++;
                echo "  ↺ Factura $numero → reabre la mensualidad {$existente['id_obligacion']} ($periodo), cancelada por corte\n";
                if ($confirmar) {
                    $stmt = $conexion->prepare("
                        UPDATE obligaciones_pago SET no_cobro_motivo = NULL, id_corte_no_cobro = NULL WHERE id_obligacion = ?
                    ");
                    $stmt->bind_param('i', $existente['id_obligacion']);
                    $stmt->execute();
                    $stmt->close();
                }
            } elseif ($existente['estado'] === 'CANCELADA') {
                $revision[] = "Factura $numero (servicio $idServicio, $periodo): la mensualidad {$existente['id_obligacion']} está cancelada en UBB.";
                continue;
            }

            $obligacion = $existente + ['liga' => true];
            $idObligacion = (int)$existente['id_obligacion'];
            $aplicado = aplicadoMu($conexion, $idObligacion);
        }

        // Ligar (si viene de plan) y ajustar el monto al total de la factura.
        $montoAnterior = centavosMu($obligacion['monto']);

        if ($total < $aplicado) {
            $revision[] = "Factura $numero: total " . pesosMu($total) . " menor a lo ya pagado en UBB ("
                . pesosMu($aplicado) . ") en la mensualidad $idObligacion.";
            continue;
        }

        $nuevoEstado = $aplicado >= $total ? 'PAGADA' : 'PENDIENTE';

        if ($obligacion['liga']) {
            $contadores['ligadas']++;
        }
        if ($montoAnterior !== $total) {
            $contadores['montos_ajustados']++;
            $ajustes[] = sprintf('  ✎ Mensualidad %d (%s): %s → %s según factura %s%s',
                $idObligacion, $periodo, pesosMu($montoAnterior), pesosMu($total), $numero,
                $nuevoEstado !== $obligacion['estado'] ? " ({$obligacion['estado']} → $nuevoEstado)" : '');
        }

        if ($confirmar) {
            $montoTexto = pesosMu($total);
            $stmt = $conexion->prepare("
                UPDATE obligaciones_pago SET uisp_invoice_id = ?, monto = ?, estado = ? WHERE id_obligacion = ?
            ");
            $stmt->bind_param('issi', $idFactura, $montoTexto, $nuevoEstado, $idObligacion);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($confirmar) {
        $conexion->commit();
    }

    if ($ajustes) {
        echo "\nMontos ajustados al total de la factura (primeros 20):\n";
        echo implode("\n", array_slice($ajustes, 0, 20)), "\n";
    }

    $contadores['para_revision'] = count($revision);

    echo "\n===== RESUMEN =====\n";
    echo "Facturas de UISP:              {$contadores['facturas']}\n";
    echo "Anteriores a $desde (historia): {$contadores['antes_de_desde']}\n";
    echo "Mensualidades creadas:         {$contadores['creadas']}\n";
    echo "Mensualidades por plan ligadas: {$contadores['ligadas']}\n";
    echo "Montos ajustados a la factura: {$contadores['montos_ajustados']}\n";
    echo "Canceladas (factura anulada):  {$contadores['canceladas']}\n";
    echo "Sin cambios:                   {$contadores['sin_cambios']}\n";
    echo "Sin servicio UBB identificable: {$contadores['sin_servicio_ubb']}\n";
    echo "No cobradas (servicio cortado): {$contadores['no_cobradas_por_corte']}\n";
    echo "Reabiertas (UISP sí facturó):  {$contadores['reabiertas_por_factura']}\n";

    if ($revision) {
        echo "\nPara revisión ({$contadores['para_revision']}, primeras 30):\n";
        foreach (array_slice($revision, 0, 30) as $texto) {
            echo "  ⚠ $texto\n";
        }
    }

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    registrarEjecucion($conexion, MENS_UISP_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli && $confirmar) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, MENS_UISP_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
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
