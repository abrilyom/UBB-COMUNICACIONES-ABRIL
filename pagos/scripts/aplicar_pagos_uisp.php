<?php
declare(strict_types=1);

/*
 * APLICAR PAGOS DE UISP A LAS MENSUALIDADES
 *
 * Toma los pagos que sincronizar_pagos_uisp.php trajo de UISP
 * (uisp_pagos_importacion con uisp_payment_id) y los registra en `pagos`,
 * aplicados a las mensualidades pendientes (obligaciones_pago), igual que la
 * conciliación bancaria. Así los módulos (pagos de hoy, próximos, vencidos,
 * suspensiones) reflejan lo que el personal capturó en UISP.
 *
 * Reglas (ante cualquier duda, el pago va a REVISION con su motivo):
 *   - El cliente UISP debe estar ligado a un solo cliente UBB (clientes.uisp_id).
 *   - Solo métodos de dinero: EFECTIVO, TRANSFERENCIA, DEPOSITO, TARJETA.
 *     El "Crédito de cortesía" (descuento, p. ej. por falla del servicio) se
 *     aplica con método CORTESIA: cubre la mensualidad pero no es ingreso.
 *     Personalizado y cheque van a revisión.
 *   - El cliente debe tener mensualidades pendientes en UN solo servicio.
 *   - Se aplica a la mensualidad más antigua y, si sobra, a las siguientes.
 *     La que llega a saldo 0 queda PAGADA. Pagos parciales sí se aplican.
 *   - Lo que sobra después de cubrir lo que debe es SALDO A FAVOR: se abona
 *     solo a la siguiente mensualidad del servicio (cada ejecución lo revisa).
 *     Solo si UISP también registró ese sobrante como saldo a favor del pago;
 *     si no, es una diferencia de precio o cargo entre UISP y UBB: revisión.
 *   - Si el servicio tiene un pago PENDIENTE en UBB (transferencia sin
 *     confirmar) o un pago PAGADO igual en ±3 días: revisión, para no contar
 *     el mismo dinero dos veces.
 *   - El pago se crea como PAGADO con referencia UISP-<id de pago UISP>.
 *   - No llama a Grezz ni suspende o reactiva servicios.
 *
 * Uso:
 *   php aplicar_pagos_uisp.php                         (simulación)
 *   php aplicar_pagos_uisp.php --confirmar             (guarda)
 *   php aplicar_pagos_uisp.php --reintentar-revision   (vuelve a evaluar los que están en REVISION)
 *   php aplicar_pagos_uisp.php --desde=2026-11-01      (solo pagos desde esa fecha)
 *
 * Entorno de pruebas:
 *   UBB_PAGOS_ENTORNO=pruebas php aplicar_pagos_uisp.php
 */

require_once __DIR__ . '/../includes/entorno_cli.php';

const UISP_APLICAR_PROCESO = 'APLICAR_PAGOS_UISP';
const UISP_METODOS_DINERO = ['EFECTIVO', 'TRANSFERENCIA', 'DEPOSITO', 'TARJETA'];
/** Descuentos de UISP: cubren la mensualidad, no son dinero recibido. */
const UISP_METODO_CORTESIA = 'CORTESIA';
const UISP_DIAS_DUPLICADO = 3;
/** Un pago cuenta para una mensualidad si llega como mucho estos días antes de su vencimiento (un mes menos la gracia). */
define('UISP_DIAS_ANTICIPO', max(1, (int)(getenv('UBB_UISP_DIAS_ANTICIPO') ?: 20)));
/**
 * Saldo a favor automático. Apagado mientras el equipo no confirme qué son los
 * sobrantes (p. ej. los +$12 con tarjeta podrían ser comisión): cada sobrante va a
 * revisión y el personal decide en Revisión UISP. Encender con UBB_UISP_SALDO_FAVOR=1.
 */
define('UISP_SALDO_FAVOR_AUTOMATICO', getenv('UBB_UISP_SALDO_FAVOR') === '1');
/** Primer mes con mensualidades en UBB: una factura de UISP de antes es historia. */
define('UISP_MENSUALIDADES_DESDE', getenv('UBB_MENSUALIDADES_UISP_DESDE') ?: '2026-11-01');

class RevisionPagoUisp extends RuntimeException
{
}

/** Pago de un periodo anterior a las mensualidades que existen en UBB: no se aplica ni se revisa. */
final class HistoricoPagoUisp extends RevisionPagoUisp
{
}

/* ===================== Opciones ===================== */

$confirmar = false;
$reintentar = false;
$desde = null;

foreach (array_slice($argv, 1) as $opcion) {
    if ($opcion === '--confirmar') {
        $confirmar = true;
    } elseif ($opcion === '--reintentar-revision') {
        $reintentar = true;
    } elseif (preg_match('/^--desde=(\d{4}-\d{2}-\d{2})$/', $opcion, $m)) {
        $desde = $m[1];
    } else {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

/* ===================== Utilidades ===================== */

function centavos($monto): int
{
    return (int)round((float)$monto * 100);
}

function pesos(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

/** "2026-10-15T09:03:15-06:00" → "2026-10-15 09:03:15" en hora de México. */
function fechaLocal(string $fechaUisp): string
{
    return (new DateTimeImmutable($fechaUisp))
        ->setTimezone(new DateTimeZone('America/Mexico_City'))
        ->format('Y-m-d H:i:s');
}

function marcarResultado(
    mysqli $conexion,
    int $idImportacion,
    string $estado,
    ?int $idPago,
    ?int $idCliente,
    ?int $idServicio,
    ?string $motivo
): void {
    $motivo = $motivo !== null ? mb_substr($motivo, 0, 500, 'UTF-8') : null;

    $stmt = $conexion->prepare("
        UPDATE uisp_pagos_importacion
        SET estado_aplicacion = ?, id_pago_ubb = ?, id_cliente_ubb = ?,
            id_servicio_ubb = ?, motivo_revision = ?, aplicado_en = NOW()
            , decision_revision = IF(? = 'REVISION', NULL, decision_revision)
        WHERE id_importacion = ?
    ");
    $stmt->bind_param('siiissi', $estado, $idPago, $idCliente, $idServicio, $motivo, $estado, $idImportacion);
    $stmt->execute();
    $stmt->close();
}

/* ===================== Proceso ===================== */

$conexion = null;
$plataforma = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_aplicar_pagos_uisp';
$inicio = new DateTimeImmutable();

$contadores = [
    'revisados' => 0,
    'aplicados' => 0,
    'importe_aplicado' => 0.0,
    'mensualidades_pagadas' => 0,
    'revision' => 0,
    'historicos' => 0,
    'cortesias' => 0,
    'importe_cortesia' => 0.0,
    'saldo_favor_generado' => 0.0,
    'saldo_favor_aplicado' => 0.0,
    'mensualidades_con_saldo_favor' => 0,
];
$motivos = [];

/** Lo aplicado durante una simulación, para que los pagos siguientes del mismo cliente lo vean. */
$simulado = [];
/** Saldos a favor que generaría una simulación (en confirmación se leen de la base). */
$creditosSimulados = [];
$mostradosAplicados = 0;
$mostradosRevision = 0;

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    $columna = $conexion->query("SHOW COLUMNS FROM uisp_pagos_importacion LIKE 'decision_revision'");
    if ($columna->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-02_revision_uisp.sql.');
    }

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n\n";

    // Relación cliente UISP → cliente UBB (puede haber más de uno: eso es revisión).
    $plataforma = conectarPlataformaCli();
    $clientesUisp = [];
    $r = $plataforma->query("
        SELECT id_cliente, TRIM(uisp_id) AS uisp_id
        FROM clientes
        WHERE uisp_id IS NOT NULL AND TRIM(uisp_id) <> ''
    ");
    while ($fila = $r->fetch_assoc()) {
        $clientesUisp[$fila['uisp_id']][] = (int)$fila['id_cliente'];
    }

    // Clientes que se facturan en la plataforma: pagan en UBB con su factura, no en UISP.
    $clientesFacturaPlataforma = [];
    $r = $plataforma->query("SELECT DISTINCT id_cliente FROM servicios WHERE factura_en_plataforma = 1");
    while ($fila = $r->fetch_row()) {
        $clientesFacturaPlataforma[(int)$fila[0]] = true;
    }
    $plataforma->close();
    $plataforma = null;

    $estados = $reintentar
        ? "(estado_aplicacion IS NULL OR estado_aplicacion = 'REVISION')"
        // Siempre incluye los que el personal ya decidió en Revisión UISP.
        : "(estado_aplicacion IS NULL OR (estado_aplicacion = 'REVISION' AND decision_revision IS NOT NULL))";
    $filtroFecha = $desde !== null
        ? "AND LEFT(fecha_original_uisp, 10) >= '" . $conexion->real_escape_string($desde) . "'"
        : '';

    $pagosUisp = $conexion->query("
        SELECT id_importacion, uisp_payment_id, uisp_client_id, monto, moneda, credito_uisp,
               fecha_original_uisp, metodo_original_uisp, metodo_normalizado, decision_revision,
               facturas_uisp_json
        FROM uisp_pagos_importacion
        WHERE uisp_payment_id IS NOT NULL
          AND $estados
          $filtroFecha
        ORDER BY fecha_original_uisp ASC, id_importacion ASC
    ")->fetch_all(MYSQLI_ASSOC);

    echo "Pagos de UISP por aplicar: " . count($pagosUisp) . "\n\n";

    foreach ($pagosUisp as $p) {
        $contadores['revisados']++;
        $idImportacion = (int)$p['id_importacion'];
        $idPagoUisp = (int)$p['uisp_payment_id'];
        $referencia = 'UISP-' . $idPagoUisp;
        $centavosPago = centavos($p['monto']);
        $esCortesia = stripos((string)$p['metodo_original_uisp'], 'cortes') !== false;
        $metodo = $esCortesia ? UISP_METODO_CORTESIA : (string)$p['metodo_normalizado'];
        $decision = $p['decision_revision'] ?? null;
        $idCliente = null;
        $idServicio = null;

        $conexion->begin_transaction();

        try {
            if ($decision === 'HISTORICO') {
                throw new HistoricoPagoUisp('Marcado como histórico en Revisión UISP.');
            }

            if ($p['moneda'] !== 'MXN') {
                throw new RevisionPagoUisp('La moneda no es MXN.');
            }
            if ($centavosPago <= 0) {
                throw new RevisionPagoUisp('Importe no válido.');
            }
            if (!$esCortesia && !in_array($metodo, UISP_METODOS_DINERO, true)) {
                throw new RevisionPagoUisp(
                    "Método \"{$p['metodo_original_uisp']}\": no es un ingreso de dinero que se aplique solo."
                );
            }

            $clientes = $clientesUisp[trim((string)$p['uisp_client_id'])] ?? [];
            if (count($clientes) === 0) {
                throw new RevisionPagoUisp("El cliente UISP {$p['uisp_client_id']} no está ligado a ningún cliente UBB.");
            }
            if (count($clientes) > 1) {
                throw new RevisionPagoUisp(
                    "El cliente UISP {$p['uisp_client_id']} está ligado a varios clientes UBB: " . implode(', ', $clientes) . '.'
                );
            }
            $idCliente = $clientes[0];

            if (isset($clientesFacturaPlataforma[$idCliente])) {
                throw new RevisionPagoUisp(
                    "El cliente UBB $idCliente se factura en la plataforma: su pago debe registrarse en UBB "
                    . 'con su factura (FAC-…), no en UISP.'
                );
            }

            // Si ya existe el pago (ejecución interrumpida), solo se marca.
            $stmt = $conexion->prepare('SELECT id_pago, id_servicio FROM pagos WHERE referencia = ? LIMIT 1');
            $stmt->bind_param('s', $referencia);
            $stmt->execute();
            $existente = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existente) {
                if ($confirmar) {
                    marcarResultado($conexion, $idImportacion, 'APLICADO', (int)$existente['id_pago'],
                        $idCliente, (int)$existente['id_servicio'], null);
                    $conexion->commit();
                } else {
                    $conexion->rollback();
                }
                $contadores['aplicados']++;
                continue;
            }

            // Mensualidades pendientes del cliente, bloqueadas.
            $stmt = $conexion->prepare("
                SELECT id_obligacion, id_servicio, periodo, fecha_vencimiento, monto
                FROM obligaciones_pago
                WHERE id_cliente = ?
                  AND concepto = 'MENSUALIDAD'
                  AND estado = 'PENDIENTE'
                  AND fecha_vencimiento IS NOT NULL
                ORDER BY fecha_vencimiento ASC, id_obligacion ASC
                FOR UPDATE
            ");
            $stmt->bind_param('i', $idCliente);
            $stmt->execute();
            $obligaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $aplicado = $conexion->prepare("
                SELECT COALESCE(SUM(po.monto_aplicado), 0)
                FROM pagos_obligaciones po
                INNER JOIN pagos p ON p.id_pago = po.id_pago
                WHERE po.id_obligacion = ? AND p.estado_pago = 'PAGADO'
            ");

            $pendientes = [];
            foreach ($obligaciones as $o) {
                $idObligacion = (int)$o['id_obligacion'];
                $aplicado->bind_param('i', $idObligacion);
                $aplicado->execute();
                $centavosAplicados = centavos($aplicado->get_result()->fetch_row()[0])
                    + ($simulado[$idObligacion] ?? 0);
                $saldo = centavos($o['monto']) - $centavosAplicados;

                if ($saldo > 0) {
                    $o['saldo'] = $saldo;
                    $pendientes[] = $o;
                }
            }
            $fechaPago = fechaLocal((string)$p['fecha_original_uisp']);
            $diaPago = new DateTimeImmutable(substr($fechaPago, 0, 10));
            $anticipo = UISP_DIAS_ANTICIPO;

            /* ===== Por factura: el pago va a la mensualidad de la factura de UISP que cubrió ===== */
            $porFactura = null;
            $coberturas = array_values(array_filter(
                json_decode((string)($p['facturas_uisp_json'] ?? ''), true) ?: [],
                fn($c) => !empty($c['factura'])
            ));

            if ($coberturas) {
                $idsFacturas = implode(',', array_map(fn($c) => (int)$c['factura'], $coberturas));
                $ligadas = [];
                $r = $conexion->query("
                    SELECT uisp_invoice_id, id_obligacion, id_servicio, periodo, monto, estado
                    FROM obligaciones_pago
                    WHERE uisp_invoice_id IN ($idsFacturas) AND id_cliente = $idCliente
                    FOR UPDATE
                ");
                while ($fila = $r->fetch_assoc()) {
                    $ligadas[(int)$fila['uisp_invoice_id']] = $fila;
                }

                // Periodo de cada factura cubierta. Una factura que no está en la copia
                // (uisp_facturas) se creó antes de octubre: también es historia.
                $periodos = [];
                $r = $conexion->query("SELECT uisp_invoice_id, periodo_inicio FROM uisp_facturas WHERE uisp_invoice_id IN ($idsFacturas)");
                while ([$idF, $inicioF] = $r->fetch_row()) {
                    $periodos[(int)$idF] = $inicioF;
                }

                $historicas = [];
                $pendientesDeLigar = 0;
                foreach ($coberturas as $c) {
                    $idF = (int)$c['factura'];
                    if (isset($ligadas[$idF])) {
                        continue;
                    }
                    $inicioF = $periodos[$idF] ?? null;
                    if ($inicioF === null || $inicioF < UISP_MENSUALIDADES_DESDE) {
                        $historicas[$idF] = centavos($c['monto'] ?? 0);
                    } else {
                        $pendientesDeLigar++;
                    }
                }

                if ($ligadas === [] && $pendientesDeLigar === 0) {
                    throw new HistoricoPagoUisp(
                        'Pagó solo facturas de UISP anteriores a las mensualidades de UBB ('
                        . implode(', ', array_map(fn($id) => ($periodos[$id] ?? 'antes de octubre'), array_keys($historicas))) . ').'
                    );
                }

                if ($ligadas !== [] && $pendientesDeLigar === 0) {
                    // Se aplica cada monto a la mensualidad de su factura; lo de facturas viejas es historia.
                    $porFactura = [];
                    foreach ($coberturas as $c) {
                        if (!isset($ligadas[(int)$c['factura']])) {
                            continue;
                        }
                        $o = $ligadas[(int)$c['factura']];
                        $idObligacion = (int)$o['id_obligacion'];
                        $aplicado->bind_param('i', $idObligacion);
                        $aplicado->execute();
                        $saldo = centavos($o['monto'])
                            - centavos($aplicado->get_result()->fetch_row()[0])
                            - ($simulado[$idObligacion] ?? 0);
                        $o['saldo'] = max(0, $o['estado'] === 'CANCELADA' ? 0 : $saldo);
                        $o['cubre'] = min(centavos($c['monto'] ?? 0), $o['saldo']);
                        $porFactura[] = $o;
                    }
                }
            }
            $aplicado->close();

            if ($porFactura !== null) {
                $serviciosFactura = array_values(array_unique(array_map(fn($o) => (int)$o['id_servicio'], $porFactura)));
                if (count($serviciosFactura) > 1) {
                    throw new RevisionPagoUisp(
                        'El pago cubrió facturas de varios servicios (' . implode(', ', $serviciosFactura) . ').'
                    );
                }
                $idServicio = $serviciosFactura[0];
            }

            // ¿El pago es de antes de que existieran mensualidades en UBB para este cliente?
            if ($porFactura === null) {

            $stmt = $conexion->prepare("
                SELECT MIN(fecha_vencimiento) FROM obligaciones_pago
                WHERE id_cliente = ? AND concepto = 'MENSUALIDAD' AND fecha_vencimiento IS NOT NULL
            ");
            $stmt->bind_param('i', $idCliente);
            $stmt->execute();
            $primeraMensualidad = $stmt->get_result()->fetch_row()[0];
            $stmt->close();

            if ($primeraMensualidad !== null) {
                $primera = new DateTimeImmutable(substr((string)$primeraMensualidad, 0, 10));

                if ($diaPago < $primera->modify("-$anticipo days")) {
                    throw new HistoricoPagoUisp(
                        'Pago anterior a las mensualidades registradas en UBB (la primera vence ' .
                        $primera->format('Y-m-d') . ').'
                    );
                }
            }

            if ($primeraMensualidad === null) {
                throw new RevisionPagoUisp("El cliente UBB $idCliente no tiene mensualidades registradas en UBB.");
            }

            $servicios = array_values(array_unique(array_map(fn($o) => (int)$o['id_servicio'], $pendientes)));

            if (count($servicios) > 1) {
                throw new RevisionPagoUisp(
                    "El cliente UBB $idCliente tiene adeudo en varios servicios (" . implode(', ', $servicios) .
                    '); no se sabe a cuál corresponde el pago.'
                );
            }

            if (count($servicios) === 1) {
                $idServicio = $servicios[0];

                // Un pago muy anterior al vencimiento más antiguo probablemente es de otro periodo.
                $vencimiento = new DateTimeImmutable(substr((string)$pendientes[0]['fecha_vencimiento'], 0, 10));

                if ($diaPago < $vencimiento->modify("-$anticipo days")) {
                    throw new RevisionPagoUisp(sprintf(
                        'Pago hecho %d días antes del vencimiento de la mensualidad pendiente más antigua (%s); puede ser de otro periodo.',
                        (int)$diaPago->diff($vencimiento)->days,
                        $vencimiento->format('Y-m-d')
                    ));
                }
            } else {
                // No debe nada: el pago completo puede ser saldo a favor, si se sabe de qué servicio es.
                $stmt = $conexion->prepare("
                    SELECT DISTINCT id_servicio FROM obligaciones_pago
                    WHERE id_cliente = ? AND concepto = 'MENSUALIDAD'
                ");
                $stmt->bind_param('i', $idCliente);
                $stmt->execute();
                $todos = array_map(fn($f) => (int)$f[0], $stmt->get_result()->fetch_all());
                $stmt->close();

                if (count($todos) !== 1) {
                    throw new RevisionPagoUisp(
                        "El cliente UBB $idCliente no debe nada y tiene varios servicios (" . implode(', ', $todos) .
                        '); no se sabe a cuál abonar el saldo a favor.'
                    );
                }
                $idServicio = $todos[0];
            }
            } // Fin de la regla por fechas (pagos que no se pudieron ligar por factura).

            // Pago PENDIENTE en UBB del mismo servicio (p. ej. transferencia sin confirmar).
            $stmt = $conexion->prepare("
                SELECT id_pago FROM pagos
                WHERE id_cliente = ? AND id_servicio = ? AND estado_pago = 'PENDIENTE'
                LIMIT 1
            ");
            $stmt->bind_param('ii', $idCliente, $idServicio);
            $stmt->execute();
            $pendienteUbb = $stmt->get_result()->fetch_row();
            $stmt->close();

            if ($pendienteUbb) {
                throw new RevisionPagoUisp(
                    "El servicio $idServicio tiene un pago pendiente en UBB (id_pago {$pendienteUbb[0]}); puede ser el mismo pago."
                );
            }

            // Pago PAGADO igual registrado en UBB por otra vía en ±3 días.
            $montoTexto = pesos($centavosPago);
            $dias = UISP_DIAS_DUPLICADO;

            $stmt = $conexion->prepare("
                SELECT id_pago FROM pagos
                WHERE id_cliente = ? AND id_servicio = ? AND estado_pago = 'PAGADO'
                  AND monto = ?
                  AND fecha_pago BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
                  AND COALESCE(referencia, '') NOT LIKE 'UISP-%'
                LIMIT 1
            ");
            $stmt->bind_param('iissisi', $idCliente, $idServicio, $montoTexto, $fechaPago, $dias, $fechaPago, $dias);
            $stmt->execute();
            $duplicado = $stmt->get_result()->fetch_row();
            $stmt->close();

            if ($duplicado) {
                throw new RevisionPagoUisp(
                    "Ya hay un pago igual registrado en UBB (id_pago {$duplicado[0]}) en ±$dias días; puede ser el mismo."
                );
            }

            // Lo que sobra después de cubrir lo que debe es saldo a favor para la siguiente
            // mensualidad. Solo si UISP también lo registró como saldo a favor del pago: si no,
            // la diferencia viene de un precio o cargo distinto entre UISP y UBB, y se revisa.
            $saldoTotal = $porFactura !== null
                ? (int)array_sum(array_column($porFactura, 'cubre'))
                : (int)array_sum(array_column($pendientes, 'saldo'));
            // Lo que el pago cubrió de facturas viejas (historia) no es sobrante.
            $centavosHistoricos = $porFactura !== null ? (int)array_sum($historicas ?? []) : 0;
            $sobrante = max(0, $centavosPago - $saldoTotal - $centavosHistoricos);
            $creditoUisp = centavos($p['credito_uisp'] ?? 0);

            // Si el personal ya decidió en Revisión UISP que es saldo a favor, se respeta.
            $aprobadoSaldoFavor = $decision === 'SALDO_FAVOR';

            if ($sobrante > 0 && !$aprobadoSaldoFavor && $creditoUisp < $sobrante) {
                throw new RevisionPagoUisp(sprintf(
                    'Paga %s y en UBB debe %s, pero UISP solo registró %s de saldo a favor: '
                        . 'posible diferencia de precio o cargo extra (servicio %d).',
                    $montoTexto,
                    pesos($saldoTotal),
                    pesos($creditoUisp),
                    $idServicio
                ));
            }

            // Mientras no se confirme la regla (UBB_UISP_SALDO_FAVOR=1), cada sobrante se revisa a mano.
            if ($sobrante > 0 && !$aprobadoSaldoFavor && !UISP_SALDO_FAVOR_AUTOMATICO) {
                throw new RevisionPagoUisp(sprintf(
                    'Sobrante de %s (paga %s y en UBB debe %s; UISP también lo registró a favor): '
                        . 'falta confirmar si se abona a la siguiente mensualidad.',
                    pesos($sobrante),
                    $montoTexto,
                    pesos($saldoTotal)
                ));
            }

            // Repartir el pago: por factura, a la mensualidad de cada factura que cubrió;
            // si no, de la mensualidad más antigua a la más nueva.
            $restante = $centavosPago;
            $aplicaciones = [];

            foreach ($porFactura ?? [] as $o) {
                $monto = min($restante, $o['cubre']);
                if ($monto <= 0) {
                    continue;
                }
                $aplicaciones[] = [
                    'id_obligacion' => (int)$o['id_obligacion'],
                    'periodo' => $o['periodo'],
                    'centavos' => $monto,
                    'liquida' => $monto === $o['saldo'],
                ];
                $restante -= $monto;
            }

            foreach ($porFactura === null ? $pendientes : [] as $o) {
                if ($restante === 0) {
                    break;
                }
                $monto = min($restante, $o['saldo']);
                $aplicaciones[] = [
                    'id_obligacion' => (int)$o['id_obligacion'],
                    'periodo' => $o['periodo'],
                    'centavos' => $monto,
                    'liquida' => $monto === $o['saldo'],
                ];
                $restante -= $monto;
            }

            // La parte que pagó facturas viejas ya quedó en la historia de UISP: no es saldo a favor.
            $restante = max(0, $restante - $centavosHistoricos);

            $partes = array_map(
                fn($a) => $a['periodo'] . ' $' . pesos($a['centavos']) . ($a['liquida'] ? ' (pagada)' : ' (parcial)'),
                $aplicaciones
            );
            if ($restante > 0) {
                $partes[] = 'saldo a favor $' . pesos($restante);
                $contadores['saldo_favor_generado'] += $restante / 100;

                if (!$confirmar) {
                    $creditosSimulados[] = [
                        'id_pago' => null,
                        'id_cliente' => $idCliente,
                        'id_servicio' => $idServicio,
                        'disponible' => $restante,
                        'referencia' => $referencia,
                    ];
                }
            }
            $detalle = implode(', ', $partes);

            if ($mostradosAplicados++ < 20) {
                echo "  ✔ UISP $idPagoUisp | $fechaPago | cliente $idCliente servicio $idServicio | "
                    . "$montoTexto $metodo → $detalle\n";
            }

            $contadores['aplicados']++;
            if ($esCortesia) {
                $contadores['cortesias']++;
                $contadores['importe_cortesia'] += $centavosPago / 100;
            } else {
                $contadores['importe_aplicado'] += $centavosPago / 100;
            }
            $contadores['mensualidades_pagadas'] += count(array_filter($aplicaciones, fn($a) => $a['liquida']));

            if (!$confirmar) {
                foreach ($aplicaciones as $a) {
                    $simulado[$a['id_obligacion']] = ($simulado[$a['id_obligacion']] ?? 0) + $a['centavos'];
                }
                $conexion->rollback();
                continue;
            }

            $observaciones = "Pago registrado en UISP (pago $idPagoUisp, cliente UISP {$p['uisp_client_id']}, "
                . "método \"{$p['metodo_original_uisp']}\"). Aplicado automáticamente: $detalle.";
            if ($esCortesia) {
                $observaciones .= ' Descuento (crédito de cortesía): cubre la mensualidad, no es dinero recibido.';
            }
            $estadoPago = 'PAGADO';

            $stmt = $conexion->prepare("
                INSERT INTO pagos (id_cliente, id_servicio, monto, fecha_pago, metodo_pago,
                                   referencia, estado_pago, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('iissssss', $idCliente, $idServicio, $montoTexto, $fechaPago,
                $metodo, $referencia, $estadoPago, $observaciones);
            $stmt->execute();
            $idPago = (int)$conexion->insert_id;
            $stmt->close();

            $insertarAplicacion = $conexion->prepare("
                INSERT INTO pagos_obligaciones (id_pago, id_obligacion, monto_aplicado, fecha_aplicacion)
                VALUES (?, ?, ?, NOW())
            ");
            $marcarPagada = $conexion->prepare("
                UPDATE obligaciones_pago SET estado = 'PAGADA'
                WHERE id_obligacion = ? AND estado = 'PENDIENTE'
            ");

            foreach ($aplicaciones as $a) {
                $montoAplicado = pesos($a['centavos']);
                $insertarAplicacion->bind_param('iis', $idPago, $a['id_obligacion'], $montoAplicado);
                $insertarAplicacion->execute();

                if ($a['liquida']) {
                    $marcarPagada->bind_param('i', $a['id_obligacion']);
                    $marcarPagada->execute();

                    if ($marcarPagada->affected_rows !== 1) {
                        throw new RuntimeException("No se pudo marcar como PAGADA la obligación {$a['id_obligacion']}.");
                    }
                }
            }
            $insertarAplicacion->close();
            $marcarPagada->close();

            marcarResultado($conexion, $idImportacion, 'APLICADO', $idPago, $idCliente, $idServicio, null);
            $conexion->commit();
        } catch (RevisionPagoUisp $e) {
            $conexion->rollback();
            $motivo = $e->getMessage();
            $esHistorico = $e instanceof HistoricoPagoUisp;

            if ($esHistorico) {
                $contadores['historicos']++;
            } else {
                $contadores['revision']++;
                $tipo = preg_replace('/[0-9]+|\(.*?\)|"[^"]*"/', '#', $motivo);
                $motivos[$tipo] = ($motivos[$tipo] ?? 0) + 1;

                if ($mostradosRevision++ < 30) {
                    echo "  ⚠ UISP $idPagoUisp | {$p['fecha_original_uisp']} | {$p['monto']} | REVISIÓN: $motivo\n";
                }
            }

            if ($confirmar) {
                marcarResultado($conexion, $idImportacion, $esHistorico ? 'HISTORICO' : 'REVISION',
                    null, $idCliente, $idServicio, $motivo);
            }
        } catch (Throwable $e) {
            $conexion->rollback();
            throw new RuntimeException("Pago UISP $idPagoUisp: " . $e->getMessage(), 0, $e);
        }
    }

    /* ===== Saldo a favor → siguiente mensualidad =====
     * Lo que sobró de un pago de UISP (monto menos lo ya aplicado) se abona a las
     * mensualidades pendientes del mismo servicio, de la más antigua a la más nueva.
     * Corre en cada ejecución: cuando se genera la mensualidad siguiente, se abona sola
     * y solo queda por cobrar la diferencia. Se registra como aplicación del pago original.
     */
    $creditos = $conexion->query("
        SELECT p.id_pago, p.id_cliente, p.id_servicio, p.referencia,
               -- Sin decisión del personal, el saldo a favor no puede ser mayor al que registró UISP
               -- (así lo que pagó facturas viejas no se vuelve saldo a favor).
               IF(MAX(u.decision_revision) = 'SALDO_FAVOR',
                  p.monto - COALESCE(SUM(po.monto_aplicado), 0),
                  LEAST(p.monto - COALESCE(SUM(po.monto_aplicado), 0), COALESCE(MAX(u.credito_uisp), 0))
               ) AS disponible
        FROM pagos p
        LEFT JOIN pagos_obligaciones po ON po.id_pago = p.id_pago
        LEFT JOIN uisp_pagos_importacion u ON u.id_pago_ubb = p.id_pago
        WHERE p.estado_pago = 'PAGADO'
          AND p.referencia LIKE 'UISP-%'
          AND (" . (UISP_SALDO_FAVOR_AUTOMATICO ? '1' : "u.decision_revision = 'SALDO_FAVOR'") . ")
        GROUP BY p.id_pago, p.id_cliente, p.id_servicio, p.referencia, p.monto, p.fecha_pago
        HAVING disponible > 0
        ORDER BY p.fecha_pago ASC, p.id_pago ASC
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($creditos as &$c) {
        $c['disponible'] = centavos($c['disponible']);
    }
    unset($c);

    foreach (array_merge($creditos, $creditosSimulados) as $credito) {
        $idCliente = (int)$credito['id_cliente'];
        $idServicio = (int)$credito['id_servicio'];
        $disponible = (int)$credito['disponible'];

        $conexion->begin_transaction();

        try {
            $stmt = $conexion->prepare("
                SELECT id_obligacion, periodo, monto
                FROM obligaciones_pago
                WHERE id_cliente = ? AND id_servicio = ?
                  AND concepto = 'MENSUALIDAD' AND estado = 'PENDIENTE'
                  AND fecha_vencimiento IS NOT NULL
                ORDER BY fecha_vencimiento ASC, id_obligacion ASC
                FOR UPDATE
            ");
            $stmt->bind_param('ii', $idCliente, $idServicio);
            $stmt->execute();
            $obligaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $aplicado = $conexion->prepare("
                SELECT COALESCE(SUM(po.monto_aplicado), 0)
                FROM pagos_obligaciones po
                INNER JOIN pagos p ON p.id_pago = po.id_pago
                WHERE po.id_obligacion = ? AND p.estado_pago = 'PAGADO'
            ");

            $abonos = [];
            foreach ($obligaciones as $o) {
                if ($disponible === 0) {
                    break;
                }
                $idObligacion = (int)$o['id_obligacion'];
                $aplicado->bind_param('i', $idObligacion);
                $aplicado->execute();
                $saldo = centavos($o['monto'])
                    - centavos($aplicado->get_result()->fetch_row()[0])
                    - ($simulado[$idObligacion] ?? 0);

                if ($saldo <= 0) {
                    continue;
                }

                $monto = min($disponible, $saldo);
                $abonos[] = [
                    'id_obligacion' => $idObligacion,
                    'periodo' => $o['periodo'],
                    'centavos' => $monto,
                    'liquida' => $monto === $saldo,
                ];
                $disponible -= $monto;
            }
            $aplicado->close();

            if ($abonos === []) {
                $conexion->rollback();
                continue;
            }

            $detalle = implode(', ', array_map(
                fn($a) => $a['periodo'] . ' $' . pesos($a['centavos']) . ($a['liquida'] ? ' (pagada)' : ' (parcial)'),
                $abonos
            ));
            echo "  ↪ Saldo a favor de {$credito['referencia']} | cliente $idCliente servicio $idServicio → $detalle\n";

            foreach ($abonos as $a) {
                $contadores['saldo_favor_aplicado'] += $a['centavos'] / 100;
                $contadores['mensualidades_con_saldo_favor']++;
                if ($a['liquida']) {
                    $contadores['mensualidades_pagadas']++;
                }
            }

            if (!$confirmar) {
                foreach ($abonos as $a) {
                    $simulado[$a['id_obligacion']] = ($simulado[$a['id_obligacion']] ?? 0) + $a['centavos'];
                }
                $conexion->rollback();
                continue;
            }

            $idPago = (int)$credito['id_pago'];
            $insertarAplicacion = $conexion->prepare("
                INSERT INTO pagos_obligaciones (id_pago, id_obligacion, monto_aplicado, fecha_aplicacion)
                VALUES (?, ?, ?, NOW())
            ");
            $marcarPagada = $conexion->prepare("
                UPDATE obligaciones_pago SET estado = 'PAGADA'
                WHERE id_obligacion = ? AND estado = 'PENDIENTE'
            ");

            foreach ($abonos as $a) {
                $montoAplicado = pesos($a['centavos']);
                $insertarAplicacion->bind_param('iis', $idPago, $a['id_obligacion'], $montoAplicado);
                $insertarAplicacion->execute();

                if ($a['liquida']) {
                    $marcarPagada->bind_param('i', $a['id_obligacion']);
                    $marcarPagada->execute();
                }
            }
            $insertarAplicacion->close();
            $marcarPagada->close();

            $nota = "\nSaldo a favor aplicado el " . date('Y-m-d') . ": $detalle.";
            $stmt = $conexion->prepare("UPDATE pagos SET observaciones = CONCAT(COALESCE(observaciones, ''), ?) WHERE id_pago = ?");
            $stmt->bind_param('si', $nota, $idPago);
            $stmt->execute();
            $stmt->close();

            $conexion->commit();
        } catch (Throwable $e) {
            $conexion->rollback();
            throw new RuntimeException("Saldo a favor de {$credito['referencia']}: " . $e->getMessage(), 0, $e);
        }
    }

    $contadores['saldo_favor_generado'] = round($contadores['saldo_favor_generado'], 2);
    $contadores['saldo_favor_aplicado'] = round($contadores['saldo_favor_aplicado'], 2);
    $contadores['importe_aplicado'] = round($contadores['importe_aplicado'], 2);

    echo "\n===== RESUMEN =====\n";
    echo "Revisados:              {$contadores['revisados']}\n";
    $contadores['importe_cortesia'] = round($contadores['importe_cortesia'], 2);

    echo "Aplicados:              {$contadores['aplicados']} | $" . number_format($contadores['importe_aplicado'], 2) . " en dinero\n";
    echo "  de ellos, cortesías:  {$contadores['cortesias']} | $" . number_format($contadores['importe_cortesia'], 2)
        . " (descuentos, no son ingreso)\n";
    echo "Mensualidades pagadas:  {$contadores['mensualidades_pagadas']}\n";
    echo "Saldo a favor nuevo:    $" . number_format($contadores['saldo_favor_generado'], 2) . " (sobrantes, para la siguiente mensualidad)\n";
    echo "Saldo a favor abonado:  $" . number_format($contadores['saldo_favor_aplicado'], 2)
        . " a {$contadores['mensualidades_con_saldo_favor']} mensualidades\n";
    echo "A revisión manual:      {$contadores['revision']}\n";
    echo "Históricos (no aplican): {$contadores['historicos']} (anteriores a las mensualidades de UBB)\n";

    if ($motivos) {
        arsort($motivos);
        echo "\nMotivos de revisión:\n";
        foreach ($motivos as $tipo => $cantidad) {
            echo "  $cantidad × $tipo\n";
        }
    }

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    registrarEjecucion($conexion, UISP_APLICAR_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, UISP_APLICAR_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
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
