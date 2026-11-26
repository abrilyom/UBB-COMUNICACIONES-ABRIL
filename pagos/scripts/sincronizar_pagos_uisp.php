<?php
declare(strict_types=1);

/*
 * SINCRONIZACIÓN DE PAGOS DESDE UISP
 *
 * Lee los pagos registrados en el CRM de UISP (API v1.0) y los guarda en
 * uisp_pagos_importacion, la misma tabla que llenó el CSV del 15/10/2026,
 * para que todos los reportes los muestren sin cambios.
 *
 * - SOLO LECTURA sobre UISP: no crea, edita ni borra nada en el CRM.
 * - Nunca duplica: cada pago se identifica por su ID de UISP
 *   (uisp_payment_id, índice único).
 * - Si un pago ya venía en el CSV (sin ID), se le asigna el ID a esa fila
 *   en lugar de insertarlo otra vez. La coincidencia es exacta:
 *   cliente + monto + fecha/hora original de UISP.
 *
 * Uso:
 *   php sincronizar_pagos_uisp.php                        (simulación, últimos 7 días)
 *   php sincronizar_pagos_uisp.php --confirmar            (guarda los cambios)
 *   php sincronizar_pagos_uisp.php --desde=2026-10-15 --confirmar
 *   php sincronizar_pagos_uisp.php --desde=2026-10-01 --hasta=2026-10-31
 *
 * Configuración (variables de entorno, todas opcionales):
 *   UBB_PAGOS_ENTORNO      produccion | pruebas
 *   UBB_UISP_URL           https://10.0.1.6:18445/crm/api/v1.0
 *   UBB_UISP_TOKEN_FILE    /etc/ubb-uisp.token  (llave de solo lectura del CRM)
 *   UBB_UISP_CA            certificado del CRM para verificar TLS
 *   UBB_UISP_DIAS          días hacia atrás cuando no se indica --desde (7)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/cliente_uisp.php';

const UISP_PROCESO = 'PAGOS_UISP';

/** Cómo normaliza los métodos el CSV original (metodo_original_uisp → metodo_normalizado). */
const UISP_METODOS_NORMALIZADOS = [
    'Efectivo' => 'EFECTIVO',
    'Transferencia bancaria' => 'TRANSFERENCIA',
    'Depósito' => 'DEPOSITO',
    'Tarjeta Débito/Crédito' => 'TARJETA',
];

/* ===================== Opciones ===================== */

$confirmar = false;
$desde = null;
$hasta = null;

foreach (array_slice($argv, 1) as $opcion) {
    if ($opcion === '--confirmar') {
        $confirmar = true;
    } elseif (preg_match('/^--desde=(\d{4}-\d{2}-\d{2})$/', $opcion, $m)) {
        $desde = $m[1];
    } elseif (preg_match('/^--hasta=(\d{4}-\d{2}-\d{2})$/', $opcion, $m)) {
        $hasta = $m[1];
    } else {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$dias = max(1, (int)(getenv('UBB_UISP_DIAS') ?: 7));
$desde ??= (new DateTimeImmutable("-$dias days"))->format('Y-m-d');
$hasta ??= (new DateTimeImmutable('today'))->format('Y-m-d');

foreach ([$desde, $hasta] as $fecha) {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        fwrite(STDERR, "Fecha inválida: $fecha\n");
        exit(1);
    }
}

if ($desde > $hasta) {
    fwrite(STDERR, "--desde no puede ser posterior a --hasta.\n");
    exit(1);
}

/* ===================== Utilidades ===================== */

/** "2026-10-15T09:03:15-0600" → "2026-10-15T09:03:15-06:00" (formato del CSV). */
function fechaFormatoCsv(string $fechaUisp): string
{
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $fechaUisp);

    if (!$fecha) {
        throw new RuntimeException("Fecha de UISP no reconocida: $fechaUisp");
    }

    return $fecha->format('Y-m-d\TH:i:sP');
}

function montoTexto($monto): string
{
    return number_format((float)$monto, 2, '.', '');
}

function nombreClienteUisp(array $cliente): string
{
    $empresa = trim((string)($cliente['companyName'] ?? ''));

    if ((int)($cliente['clientType'] ?? 1) === 2 && $empresa !== '') {
        return $empresa;
    }

    $apellido = trim((string)($cliente['lastName'] ?? ''));
    $nombre = trim((string)($cliente['firstName'] ?? ''));

    return $apellido !== '' && $nombre !== ''
        ? "$apellido, $nombre"
        : ($apellido . $nombre ?: $empresa);
}

/* ===================== Proceso ===================== */

$conexion = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_sincronizar_pagos_uisp';
$inicio = new DateTimeImmutable();

$contadores = [
    'desde' => $desde,
    'hasta' => $hasta,
    'en_uisp' => 0,
    'ya_sincronizados' => 0,
    'enlazados_csv' => 0,
    'nuevos' => 0,
    'importe_nuevo' => 0.0,
];

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    $columna = $conexion->query("SHOW COLUMNS FROM uisp_pagos_importacion LIKE 'uisp_payment_id'");
    if ($columna->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-11-27_pagos_uisp_api.sql.');
    }

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n";
    echo "Periodo: $desde a $hasta\n\n";

    $uisp = new ClienteUisp();

    $pagos = $uisp->pagos($desde, $hasta);
    $contadores['en_uisp'] = count($pagos);
    echo "Pagos en UISP: " . count($pagos) . "\n";

    $metodos = $uisp->catalogo('/payment-methods', fn($m) => (string)$m['name']);
    $organizaciones = $uisp->catalogo('/organizations', fn($o) => (string)$o['name']);
    $administradores = $uisp->catalogo('/users/admins', fn($a) => trim(
        ($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? '')
    ) ?: (string)($a['username'] ?? ''));

    // IDs de UISP que ya están en la tabla.
    $ids = array_map(fn($p) => (int)$p['id'], $pagos);
    $yaGuardados = [];

    foreach (array_chunk($ids, 500) as $bloque) {
        $lista = implode(',', $bloque);
        $r = $conexion->query("SELECT uisp_payment_id FROM uisp_pagos_importacion WHERE uisp_payment_id IN ($lista)");
        while ($fila = $r->fetch_row()) {
            $yaGuardados[(int)$fila[0]] = true;
        }
    }

    $buscarCsv = $conexion->prepare("
        SELECT id_importacion
        FROM uisp_pagos_importacion
        WHERE uisp_payment_id IS NULL
          AND uisp_client_id = ?
          AND fecha_original_uisp = ?
          AND monto = ?
        ORDER BY id_importacion
    ");

    $nombreGuardado = $conexion->prepare("
        SELECT cliente_nombre_uisp, organizacion
        FROM uisp_pagos_importacion
        WHERE uisp_client_id = ? AND cliente_nombre_uisp IS NOT NULL
        ORDER BY id_importacion DESC
        LIMIT 1
    ");

    $enlazar = $conexion->prepare("
        UPDATE uisp_pagos_importacion
        SET uisp_payment_id = ?, uisp_metodo_id = ?, credito_uisp = ?,
            facturas_uisp_json = ?, sincronizado_en = NOW()
        WHERE id_importacion = ? AND uisp_payment_id IS NULL
    ");

    $insertar = $conexion->prepare("
        INSERT INTO uisp_pagos_importacion
            (uisp_payment_id, uisp_client_id, cliente_nombre_uisp, monto, moneda,
             credito_uisp, fecha_original_uisp, metodo_original_uisp,
             metodo_normalizado, uisp_metodo_id, organizacion, nota,
             facturas_uisp_json, admin_uisp, admin_id_uisp, numero_cheque,
             origen, sincronizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'API', NOW())
    ");

    $filasCsvUsadas = [];
    $clientes = [];
    $nuevosMostrados = 0;

    if ($confirmar) {
        $conexion->begin_transaction();
    }

    foreach ($pagos as $pago) {
        $idPago = (int)$pago['id'];

        if (isset($yaGuardados[$idPago])) {
            $contadores['ya_sincronizados']++;
            continue;
        }

        $idCliente = (string)($pago['clientId'] ?? '');
        $fecha = fechaFormatoCsv((string)$pago['createdDate']);
        $monto = montoTexto($pago['amount']);
        $metodoId = (string)($pago['methodId'] ?? '');
        $credito = montoTexto($pago['creditAmount'] ?? 0);
        $facturas = json_encode(
            array_map(fn($c) => [
                'factura' => $c['invoiceId'] ?? null,
                'nota_credito' => $c['creditNoteId'] ?? null,
                'reembolso' => $c['refundId'] ?? null,
                'monto' => $c['amount'] ?? null,
            ], $pago['paymentCovers'] ?? []),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // ¿Ya venía en el CSV? Se enlaza la primera fila idéntica sin ID.
        $buscarCsv->bind_param('sss', $idCliente, $fecha, $monto);
        $buscarCsv->execute();
        $filaCsv = null;

        foreach ($buscarCsv->get_result()->fetch_all() as [$idImportacion]) {
            if (!isset($filasCsvUsadas[$idImportacion])) {
                $filaCsv = (int)$idImportacion;
                break;
            }
        }

        if ($filaCsv !== null) {
            $filasCsvUsadas[$filaCsv] = true;
            $contadores['enlazados_csv']++;

            if ($confirmar) {
                $enlazar->bind_param('isssi', $idPago, $metodoId, $credito, $facturas, $filaCsv);
                $enlazar->execute();
            }
            continue;
        }

        // Pago nuevo. Se reutiliza el nombre que ya tenga el cliente en la tabla
        // para que los reportes no muestren dos nombres distintos.
        if (!isset($clientes[$idCliente])) {
            $nombreGuardado->bind_param('s', $idCliente);
            $nombreGuardado->execute();
            $guardado = $nombreGuardado->get_result()->fetch_assoc();

            if ($guardado) {
                $clientes[$idCliente] = [$guardado['cliente_nombre_uisp'], $guardado['organizacion']];
            } else {
                $cliente = $uisp->get('/clients/' . rawurlencode($idCliente));
                $clientes[$idCliente] = [
                    nombreClienteUisp($cliente),
                    $organizaciones[(string)($cliente['organizationId'] ?? '')] ?? null,
                ];
            }
        }

        [$nombreCliente, $organizacion] = $clientes[$idCliente];
        $metodoNombre = $metodos[$metodoId] ?? 'Desconocido';
        $metodoNormalizado = UISP_METODOS_NORMALIZADOS[$metodoNombre] ?? 'REVISAR';
        $moneda = (string)($pago['currencyCode'] ?? '');
        $nota = $pago['note'] ?? null;
        $adminId = isset($pago['userId']) ? (string)$pago['userId'] : null;
        $adminNombre = $adminId !== null ? ($administradores[$adminId] ?? null) : null;
        $cheque = $pago['checkNumber'] ?? null;

        $contadores['nuevos']++;
        $contadores['importe_nuevo'] += (float)$monto;

        if ($nuevosMostrados < 15) {
            echo "  + Pago $idPago | $fecha | cliente UISP $idCliente | $monto $moneda | $metodoNombre\n";
            $nuevosMostrados++;
        }

        if ($confirmar) {
            $insertar->bind_param(
                'i' . str_repeat('s', 15),
                $idPago, $idCliente, $nombreCliente, $monto, $moneda,
                $credito, $fecha, $metodoNombre,
                $metodoNormalizado, $metodoId, $organizacion, $nota,
                $facturas, $adminNombre, $adminId, $cheque
            );
            $insertar->execute();
        }
    }

    if ($confirmar) {
        $conexion->commit();
    }

    if ($contadores['nuevos'] > $nuevosMostrados) {
        echo "  … y " . ($contadores['nuevos'] - $nuevosMostrados) . " más\n";
    }

    $contadores['importe_nuevo'] = round($contadores['importe_nuevo'], 2);

    echo "\n===== RESUMEN =====\n";
    echo "Pagos en UISP:              {$contadores['en_uisp']}\n";
    echo "Ya sincronizados antes:     {$contadores['ya_sincronizados']}\n";
    echo "Ya estaban en el CSV:       {$contadores['enlazados_csv']} (se les asigna el ID de UISP)\n";
    echo "Nuevos:                     {$contadores['nuevos']} | $" .
        number_format($contadores['importe_nuevo'], 2) . "\n";

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    registrarEjecucion($conexion, UISP_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli && $confirmar) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, UISP_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
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
