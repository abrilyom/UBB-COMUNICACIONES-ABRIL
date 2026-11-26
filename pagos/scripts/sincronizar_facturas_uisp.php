<?php
declare(strict_types=1);

/*
 * SINCRONIZACIÓN DE FACTURAS DESDE UISP
 *
 * Copia las facturas del CRM de UISP en la tabla uisp_facturas de UBB Pagos:
 * total, descuento, pagado, saldo, estado, periodo que cobran, conceptos
 * (servicio, reconexión u otros cargos) y los pagos de UISP que las cubren.
 *
 * - SOLO LECTURA sobre UISP.
 * - Solo escribe en uisp_facturas: no cambia pagos ni mensualidades.
 * - Cada factura se identifica por su ID de UISP; si ya existe se actualiza
 *   (por ejemplo, cuando pasa de PENDIENTE a PAGADA).
 *
 * Uso:
 *   php sincronizar_facturas_uisp.php                          (simulación, últimos 62 días)
 *   php sincronizar_facturas_uisp.php --confirmar
 *   php sincronizar_facturas_uisp.php --desde=2026-10-01 --confirmar
 *
 * Variables opcionales: las de includes/cliente_uisp.php y
 *   UBB_UISP_FACTURAS_DIAS  días hacia atrás sin --desde (62: así se actualiza
 *                           el estado de las facturas de los dos últimos meses)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/cliente_uisp.php';

const FACTURAS_PROCESO = 'FACTURAS_UISP';

const ESTADOS_FACTURA_UISP = [
    0 => 'BORRADOR',
    1 => 'PENDIENTE',
    2 => 'PARCIAL',
    3 => 'PAGADA',
    4 => 'ANULADA',
    5 => 'PROFORMA',
];

/** Abreviaturas de mes que UISP usa en el concepto (español e inglés). */
const MESES_UISP = [
    'ene' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'apr' => 4,
    'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'sep' => 9,
    'oct' => 10, 'nov' => 11, 'dic' => 12, 'dec' => 12,
];

/* ===================== Opciones ===================== */

$confirmar = false;
$desde = null;

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

$dias = max(1, (int)(getenv('UBB_UISP_FACTURAS_DIAS') ?: 62));
$desde ??= (new DateTimeImmutable("-$dias days"))->format('Y-m-d');
$hasta = (new DateTimeImmutable('today'))->format('Y-m-d');

/* ===================== Utilidades ===================== */

/** "2026-11-14T03:00:00-0600" → "2026-11-14 03:00:00" en hora de México. */
function fechaUispLocal(?string $fecha): ?string
{
    if ($fecha === null || $fecha === '') {
        return null;
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $fecha);

    if (!$d) {
        throw new RuntimeException("Fecha de UISP no reconocida: $fecha");
    }

    return $d->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
}

/**
 * Lee el periodo del concepto, p. ej. "HOGAR 35 Mbps 14 Nov 2026 – 13 Dic 2026".
 *
 * @return array{0: ?string, 1: ?string} [inicio, fin] en Y-m-d
 */
function periodoDeConcepto(string $texto): array
{
    $texto = str_replace(["\u{00A0}", "\u{2013}", "\u{2014}"], [' ', '-', '-'], $texto);
    $fecha = '(\d{1,2})\s+([[:alpha:]]{3,4})\.?\s+(\d{4})';

    if (!preg_match("/$fecha\s*-\s*$fecha/u", $texto, $m)) {
        return [null, null];
    }

    $convertir = function (string $dia, string $mes, string $anio): ?string {
        $numero = MESES_UISP[mb_strtolower(mb_substr($mes, 0, 3))] ?? null;

        return $numero !== null && checkdate($numero, (int)$dia, (int)$anio)
            ? sprintf('%04d-%02d-%02d', (int)$anio, $numero, (int)$dia)
            : null;
    };

    return [$convertir($m[1], $m[2], $m[3]), $convertir($m[4], $m[5], $m[6])];
}

function pesosTexto($monto): string
{
    return number_format((float)$monto, 2, '.', '');
}

/* ===================== Proceso ===================== */

$conexion = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_sincronizar_facturas_uisp';
$inicio = new DateTimeImmutable();

$contadores = [
    'desde' => $desde,
    'hasta' => $hasta,
    'en_uisp' => 0,
    'nuevas' => 0,
    'actualizadas' => 0,
    'sin_cambios' => 0,
    'sin_periodo' => 0,
    'con_otros_cargos' => 0,
    'con_descuento' => 0,
];
$porEstado = [];
$saldoPendiente = 0.0;

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    if ($conexion->query("SHOW TABLES LIKE 'uisp_facturas'")->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-01_facturas_uisp.sql.');
    }

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n";
    echo "Facturas creadas del $desde al $hasta\n\n";

    $uisp = new ClienteUisp();
    $facturas = $uisp->facturas($desde, $hasta);
    $contadores['en_uisp'] = count($facturas);

    // Estado actual guardado, para contar nuevas / actualizadas / sin cambios.
    $guardadas = [];
    foreach (array_chunk(array_map(fn($f) => (int)$f['id'], $facturas), 500) as $bloque) {
        $r = $conexion->query("
            SELECT uisp_invoice_id, CONCAT_WS('|', estado, total, descuento, pagado, saldo, COALESCE(pagos_json, ''))
            FROM uisp_facturas WHERE uisp_invoice_id IN (" . implode(',', $bloque) . ")
        ");
        while ($fila = $r->fetch_row()) {
            $guardadas[(int)$fila[0]] = $fila[1];
        }
    }

    $guardar = $conexion->prepare("
        INSERT INTO uisp_facturas
            (uisp_invoice_id, numero, uisp_client_id, uisp_service_id, fecha_emision, fecha_limite,
             periodo_inicio, periodo_fin, total, descuento, pagado, saldo, moneda, estado,
             estado_uisp, proforma, conceptos_json, pagos_json, sincronizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            numero = VALUES(numero), uisp_client_id = VALUES(uisp_client_id),
            uisp_service_id = VALUES(uisp_service_id), fecha_emision = VALUES(fecha_emision),
            fecha_limite = VALUES(fecha_limite), periodo_inicio = VALUES(periodo_inicio),
            periodo_fin = VALUES(periodo_fin), total = VALUES(total), descuento = VALUES(descuento),
            pagado = VALUES(pagado), saldo = VALUES(saldo), moneda = VALUES(moneda),
            estado = VALUES(estado), estado_uisp = VALUES(estado_uisp), proforma = VALUES(proforma),
            conceptos_json = VALUES(conceptos_json), pagos_json = VALUES(pagos_json),
            sincronizado_en = NOW()
    ");

    if ($confirmar) {
        $conexion->begin_transaction();
    }

    foreach ($facturas as $f) {
        $id = (int)$f['id'];
        $codigoEstado = (int)($f['status'] ?? -1);
        $estado = ESTADOS_FACTURA_UISP[$codigoEstado] ?? null;

        if ($estado === null) {
            throw new RuntimeException("Factura $id con estado desconocido en UISP: $codigoEstado.");
        }

        $conceptos = [];
        $idServicio = null;
        $periodo = [null, null];
        $otrosCargos = false;

        foreach ($f['items'] ?? [] as $item) {
            $tipo = (string)($item['type'] ?? '');
            $conceptos[] = [
                'tipo' => $tipo,
                'descripcion' => $item['label'] ?? null,
                'servicio' => $item['serviceId'] ?? null,
                'precio' => $item['price'] ?? null,
                'cantidad' => $item['quantity'] ?? null,
                'total' => $item['total'] ?? null,
                'descuento' => $item['discountTotal'] ?? null,
            ];

            if ($tipo === 'service' && $idServicio === null) {
                $idServicio = isset($item['serviceId']) ? (int)$item['serviceId'] : null;
                $periodo = periodoDeConcepto((string)($item['label'] ?? ''));
            } elseif ($tipo !== 'service') {
                $otrosCargos = true;
            }
        }

        $pagos = array_map(fn($c) => [
            'pago' => $c['paymentId'] ?? null,
            'monto' => $c['amount'] ?? null,
        ], $f['paymentCovers'] ?? []);

        $numero = $f['number'] ?? null;
        $cliente = (string)$f['clientId'];
        $emision = fechaUispLocal($f['createdDate'] ?? null);
        $limite = fechaUispLocal($f['dueDate'] ?? null);
        $total = pesosTexto($f['total'] ?? 0);
        $descuento = pesosTexto(abs((float)($f['totalDiscount'] ?? 0)));
        $pagado = pesosTexto($f['amountPaid'] ?? 0);
        $saldo = pesosTexto($f['amountToPay'] ?? 0);
        $moneda = (string)($f['currencyCode'] ?? '');
        $proforma = !empty($f['proforma']) ? 1 : 0;
        $conceptosJson = json_encode($conceptos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pagosJson = json_encode($pagos, JSON_UNESCAPED_SLASHES);

        if ($emision === null) {
            throw new RuntimeException("Factura $id sin fecha de emisión.");
        }

        $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;
        if (in_array($estado, ['PENDIENTE', 'PARCIAL'], true)) {
            $saldoPendiente += (float)$saldo;
        }
        if ($periodo[0] === null) {
            $contadores['sin_periodo']++;
        }
        if ($otrosCargos) {
            $contadores['con_otros_cargos']++;
        }
        if ((float)$descuento > 0) {
            $contadores['con_descuento']++;
        }

        $huella = implode('|', [$estado, $total, $descuento, $pagado, $saldo, $pagosJson]);

        if (!isset($guardadas[$id])) {
            $contadores['nuevas']++;
        } elseif ($guardadas[$id] !== $huella) {
            $contadores['actualizadas']++;
        } else {
            $contadores['sin_cambios']++;
            continue;
        }

        if ($confirmar) {
            $guardar->bind_param(
                'ississssssssssiiss',
                $id, $numero, $cliente, $idServicio, $emision, $limite,
                $periodo[0], $periodo[1], $total, $descuento, $pagado, $saldo, $moneda, $estado,
                $codigoEstado, $proforma, $conceptosJson, $pagosJson
            );
            $guardar->execute();
        }
    }

    if ($confirmar) {
        $conexion->commit();
    }

    ksort($porEstado);

    echo "===== RESUMEN =====\n";
    echo "Facturas en UISP:        {$contadores['en_uisp']}\n";
    echo "Nuevas:                  {$contadores['nuevas']}\n";
    echo "Actualizadas:            {$contadores['actualizadas']}\n";
    echo "Sin cambios:             {$contadores['sin_cambios']}\n";
    echo "Por estado:              " . implode(', ', array_map(
        fn($e, $n) => "$e $n", array_keys($porEstado), $porEstado
    )) . "\n";
    echo "Saldo pendiente:         $" . number_format($saldoPendiente, 2) . " (pendientes y parciales)\n";
    echo "Con cargos además del servicio: {$contadores['con_otros_cargos']}\n";
    echo "Con descuento:           {$contadores['con_descuento']}\n";
    echo "Sin periodo legible:     {$contadores['sin_periodo']}\n";

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    $contadores['saldo_pendiente'] = round($saldoPendiente, 2);
    registrarEjecucion($conexion, FACTURAS_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli && $confirmar) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, FACTURAS_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
    }

    $codigoSalida = 1;
} finally {
    if ($conexion instanceof mysqli) {
        if ($bloqueo) {
            liberarBloqueoScript($conexion, $nombreBloqueo);
        }
        $conexion->close();
    }
}

exit($codigoSalida ?? 0);
