<?php
declare(strict_types=1);

/*
 * NO COBRAR LOS MESES EN QUE EL SERVICIO ESTUVO CORTADO
 *
 * Regla del ingeniero: al reactivar un servicio se cobra el MES ACTUAL más
 * $39 de reconexión. El mes que provocó el corte y los meses sin servicio no
 * se cobran.
 *
 * 1. Cortes (tabla servicios_cortes), de dos fuentes:
 *    - UISP: servicios suspendidos en el CRM (solo lectura). Si UISP no da la
 *      fecha del corte, se toma el día en que se detectó (inicio_estimado = 1).
 *      Cuando el servicio deja de estar suspendido, el corte se cierra.
 *    - GREZZ: suspensiones y reactivaciones COMPLETADAS que pidió UBB Pagos
 *      (solicitudes_estado_servicio).
 *    Si las dos fuentes reportan el mismo corte, se toma como uno solo.
 *
 * 2. Mientras el servicio está cortado (o al reactivarse), con "mes actual" =
 *    el mes de hoy (o el mes en que se reactivó):
 *    - Las mensualidades SIN PAGOS de meses anteriores al mes actual quedan
 *      CANCELADAS con motivo MES_DEL_CORTE (vencía antes del corte) o
 *      SERVICIO_CORTADO (vencía durante el corte). Las que tienen un pago
 *      parcial no se tocan y se listan para revisión.
 *    - La mensualidad del mes actual se cobra. Si el cliente paga en UBB Pagos
 *      (servicios que se facturan en la plataforma de Jamileth), se le suma la
 *      reconexión (cargo_reconexion). Si se factura y paga en UISP, UBB no
 *      puede cobrarla: solo avisa si su factura de UISP no trae la reconexión.
 *    - Si el corte sigue abierto y cambia el mes, la reconexión pasa a la
 *      mensualidad del nuevo mes y la anterior se cancela.
 *
 * Nunca escribe en UISP, en Grezz ni en la plataforma: las facturas que dejan
 * de cobrarse se listan para que el personal las anule allá.
 *
 * Uso:
 *   php meses_sin_servicio.php                      (simulación)
 *   php meses_sin_servicio.php --confirmar
 *   php meses_sin_servicio.php --sin-uisp           (solo cortes de Grezz y los ya guardados)
 *   php meses_sin_servicio.php --deshacer=<id_corte> [--confirmar]
 *       Regresa a PENDIENTE las mensualidades que canceló ese corte, quita
 *       su reconexión y lo marca como anulado para no volver a aplicarlo.
 *
 * Configuración (variables de entorno, opcionales):
 *   UBB_CARGO_RECONEXION     39
 *   UBB_CORTES_DIAS          días que se siguen revisando los cortes ya cerrados (62)
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../includes/cliente_uisp.php';

const MSS_PROCESO = 'MESES_SIN_SERVICIO';
const MSS_UISP_SUSPENDIDO = 3;
/** Mismos servicios protegidos que includes/automatizacion_servicios.php. */
const MSS_SERVICIOS_PROTEGIDOS = [1255, 1299];

$confirmar = false;
$usarUisp = true;
$deshacer = null;

foreach (array_slice($argv, 1) as $opcion) {
    if ($opcion === '--confirmar') {
        $confirmar = true;
    } elseif ($opcion === '--sin-uisp') {
        $usarUisp = false;
    } elseif (preg_match('/^--deshacer=(\d+)$/', $opcion, $m)) {
        $deshacer = (int)$m[1];
    } else {
        fwrite(STDERR, "Opción desconocida: $opcion\n");
        exit(1);
    }
}

$cargoReconexion = (int)round((float)(getenv('UBB_CARGO_RECONEXION') ?: 39) * 100);
$diasRevision = (int)(getenv('UBB_CORTES_DIAS') ?: 62);

function centavosMss($monto): int
{
    return (int)round((float)$monto * 100);
}

function pesosMss(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}

/** Fecha AAAA-MM-DD de una fecha ISO de UISP, en hora local, o null. */
function fechaLocalMss($valor): ?string
{
    if (!is_string($valor) || trim($valor) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($valor))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** Periodo de suspensión vigente que UISP reporte en el servicio: [inicio, fin] o [null, null]. */
function periodoSuspensionUisp(array $servicio): array
{
    $ultimo = [null, null];

    foreach ($servicio['suspensionPeriods'] ?? [] as $periodo) {
        $inicio = fechaLocalMss($periodo['startDate'] ?? null);

        if ($inicio !== null && ($ultimo[0] === null || $inicio >= $ultimo[0])) {
            $ultimo = [$inicio, fechaLocalMss($periodo['endDate'] ?? null)];
        }
    }

    return $ultimo;
}

function aplicadoMss(mysqli $conexion, int $idObligacion): int
{
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(po.monto_aplicado), 0)
        FROM pagos_obligaciones po
        INNER JOIN pagos p ON p.id_pago = po.id_pago
        WHERE po.id_obligacion = ? AND p.estado_pago = 'PAGADO'
    ");
    $stmt->bind_param('i', $idObligacion);
    $stmt->execute();
    $valor = centavosMss($stmt->get_result()->fetch_row()[0]);
    $stmt->close();

    return $valor;
}

/** Abre un corte (o actualiza su fecha de fin si ya existe para ese día). */
function abrirCorteMss(
    mysqli $conexion,
    int $idServicio,
    ?int $idCliente,
    string $fuente,
    string $referencia,
    string $inicio,
    bool $estimado,
    ?string $fin = null
): void {
    $estimadoInt = $estimado ? 1 : 0;
    $stmt = $conexion->prepare("
        INSERT INTO servicios_cortes
            (id_servicio, id_cliente, fuente, referencia_fuente, fecha_inicio, fecha_fin, inicio_estimado)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fecha_fin = IF(anulado = 1, fecha_fin, VALUES(fecha_fin)),
            referencia_fuente = VALUES(referencia_fuente)
    ");
    $stmt->bind_param('iissssi', $idServicio, $idCliente, $fuente, $referencia, $inicio, $fin, $estimadoInt);
    $stmt->execute();
    $stmt->close();
}

function cerrarCorteMss(mysqli $conexion, int $idCorte, string $fin): void
{
    $stmt = $conexion->prepare("UPDATE servicios_cortes SET fecha_fin = ? WHERE id_corte = ? AND fecha_fin IS NULL");
    $stmt->bind_param('si', $fin, $idCorte);
    $stmt->execute();
    $stmt->close();
}

/** Suma (o resta, con centavos negativos) la reconexión al monto de una mensualidad. */
function moverReconexionMss(mysqli $conexion, int $idObligacion, int $centavos): void
{
    $monto = pesosMss($centavos);
    $stmt = $conexion->prepare("
        UPDATE obligaciones_pago
        SET monto = monto + ?, cargo_reconexion = cargo_reconexion + ?
        WHERE id_obligacion = ?
    ");
    $stmt->bind_param('ssi', $monto, $monto, $idObligacion);
    $stmt->execute();
    $stmt->close();
}

$conexion = null;
$bloqueo = false;
$nombreBloqueo = 'ubb_meses_sin_servicio';
$inicio = new DateTimeImmutable();
$hoy = new DateTimeImmutable('today');
$hoyTexto = $hoy->format('Y-m-d');

$contadores = [
    'suspendidos_uisp' => 0,
    'cortes_abiertos_nuevos' => 0,
    'cortes_cerrados' => 0,
    'cortes_revisados' => 0,
    'mensualidades_no_cobradas' => 0,
    'importe_no_cobrado' => '0.00',
    'reconexiones_cargadas' => 0,
    'reconexiones_en_factura' => 0,
    'reconexiones_falta_en_uisp' => 0,
    'para_revision' => 0,
];
$importeNoCobrado = 0;
$revision = [];
$anularUisp = [];
$anularPlataforma = [];
$faltaReconexionUisp = [];

try {
    $conexion = conectarPagosCli();
    obtenerBloqueoScript($conexion, $nombreBloqueo);
    $bloqueo = true;

    if ($conexion->query("SHOW TABLES LIKE 'servicios_cortes'")->num_rows === 0
        || $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'cargo_reconexion'")->num_rows === 0) {
        throw new RuntimeException('Falta aplicar db/migraciones/2026-12-05_meses_sin_servicio.sql.');
    }

    $tipoEstado = (string)$conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'estado'")->fetch_assoc()['Type'];
    if (!str_contains($tipoEstado, "'CANCELADA'")) {
        throw new RuntimeException('obligaciones_pago.estado no admite CANCELADA: aplica 2026-12-05_meses_sin_servicio.sql.');
    }

    $tieneUispInvoice = $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'uisp_invoice_id'")->num_rows > 0;
    $tieneIdFactura = $conexion->query("SHOW COLUMNS FROM obligaciones_pago LIKE 'id_factura'")->num_rows > 0;

    echo $confirmar
        ? "MODO: CONFIRMAR (se guardarán los cambios)\n"
        : "MODO: SIMULACIÓN (no se guardará nada)\n";
    echo "Hoy: $hoyTexto | Reconexión: $" . pesosMss($cargoReconexion) . "\n\n";

    if ($confirmar) {
        $conexion->begin_transaction();
    }

    /* ===================== Deshacer un corte ===================== */
    if ($deshacer !== null) {
        $stmt = $conexion->prepare("SELECT * FROM servicios_cortes WHERE id_corte = ?");
        $stmt->bind_param('i', $deshacer);
        $stmt->execute();
        $corte = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$corte) {
            throw new RuntimeException("No existe el corte $deshacer.");
        }

        $stmt = $conexion->prepare("
            SELECT id_obligacion, periodo, monto FROM obligaciones_pago
            WHERE id_corte_no_cobro = ? AND estado = 'CANCELADA'
        ");
        $stmt->bind_param('i', $deshacer);
        $stmt->execute();
        $canceladas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($canceladas as $o) {
            echo "  ↺ Mensualidad {$o['id_obligacion']} ({$o['periodo']}, {$o['monto']}) → PENDIENTE\n";
        }

        $idReconexion = $corte['id_obligacion_reconexion'] !== null ? (int)$corte['id_obligacion_reconexion'] : null;
        if ($idReconexion !== null && $corte['reconexion_estado'] === 'CARGADA') {
            echo "  ↺ Se quita la reconexión de la mensualidad $idReconexion\n";
        }

        if ($confirmar) {
            $stmt = $conexion->prepare("
                UPDATE obligaciones_pago
                SET estado = 'PENDIENTE', no_cobro_motivo = NULL, id_corte_no_cobro = NULL
                WHERE id_corte_no_cobro = ? AND estado = 'CANCELADA'
            ");
            $stmt->bind_param('i', $deshacer);
            $stmt->execute();
            $stmt->close();

            if ($idReconexion !== null && $corte['reconexion_estado'] === 'CARGADA') {
                moverReconexionMss($conexion, $idReconexion, -$cargoReconexion);
            }

            $stmt = $conexion->prepare("
                UPDATE servicios_cortes
                SET anulado = 1, id_obligacion_reconexion = NULL, reconexion_estado = NULL
                WHERE id_corte = ?
            ");
            $stmt->bind_param('i', $deshacer);
            $stmt->execute();
            $stmt->close();

            $conexion->commit();
            echo "\nCorte $deshacer anulado. Si el servicio sigue cortado en su fuente, no se vuelve a aplicar.\n";
        } else {
            echo "\nSimulación: no se guardó nada. Agrega --confirmar para deshacer.\n";
        }

        registrarEjecucion($conexion, MSS_PROCESO, $confirmar, $inicio, ['deshacer' => $deshacer, 'mensualidades' => count($canceladas)]);
        // exit() no ejecuta el finally: se libera el bloqueo aquí.
        liberarBloqueoScript($conexion, $nombreBloqueo);
        $conexion->close();
        exit(0);
    }

    /* ===================== Datos de la plataforma ===================== */
    $plataforma = conectarPlataformaCli();

    $clientesUisp = [];
    $r = $plataforma->query("
        SELECT id_cliente, TRIM(uisp_id) FROM clientes WHERE uisp_id IS NOT NULL AND TRIM(uisp_id) <> ''
    ");
    while ([$idCliente, $uispId] = $r->fetch_row()) {
        $clientesUisp[$uispId][] = (int)$idCliente;
    }

    $servicios = [];
    $serviciosCliente = [];
    $r = $plataforma->query("
        SELECT id_servicio, id_cliente, estado, COALESCE(factura_en_plataforma, 0) FROM servicios
    ");
    while ([$idServicio, $idCliente, $estado, $enPlataforma] = $r->fetch_row()) {
        $servicios[(int)$idServicio] = [
            'id_cliente' => (int)$idCliente,
            'estado' => (string)$estado,
            'en_plataforma' => (int)$enPlataforma === 1,
        ];
        $serviciosCliente[(int)$idCliente][] = (int)$idServicio;
    }

    $clientesConUisp = [];
    foreach ($clientesUisp as $ids) {
        foreach ($ids as $id) {
            $clientesConUisp[$id] = true;
        }
    }

    $plataforma->close();
    $plataforma = null;

    /* ===================== 1a. Cortes de UISP ===================== */
    if ($usarUisp) {
        echo "Consultando servicios suspendidos en UISP...\n";
        $uisp = new ClienteUisp();
        // Se pide filtrado por estado; si UISP ignorara el filtro, se revisa el estado de cada uno.
        $serviciosUisp = $uisp->get('/clients/services', ['statuses' => [MSS_UISP_SUSPENDIDO]]);
        $suspendidosUisp = [];

        foreach ($serviciosUisp as $s) {
            if ((int)($s['status'] ?? -1) !== MSS_UISP_SUSPENDIDO) {
                continue;
            }
            $contadores['suspendidos_uisp']++;

            $clientes = $clientesUisp[trim((string)($s['clientId'] ?? ''))] ?? [];
            if (count($clientes) !== 1) {
                continue; // Cliente UISP sin ligar (o ligado a varios): no hay mensualidades UBB que ajustar.
            }

            $vigentes = array_values(array_filter(
                $serviciosCliente[$clientes[0]] ?? [],
                fn($id) => $servicios[$id]['estado'] !== 'BAJA'
            ));

            if (count($vigentes) !== 1) {
                $revision[] = "Servicio UISP {$s['id']} suspendido: el cliente UBB {$clientes[0]} tiene "
                    . count($vigentes) . " servicios vigentes; no se sabe cuál está cortado.";
                continue;
            }

            [$inicioUisp, $finUisp] = periodoSuspensionUisp($s);
            if ($finUisp !== null && $finUisp <= $hoyTexto) {
                $inicioUisp = null; // Ese periodo ya terminó; el actual no trae fecha.
            }

            $suspendidosUisp[$vigentes[0]] = [
                'id_cliente' => $clientes[0],
                'uisp_service_id' => (string)$s['id'],
                'inicio' => $inicioUisp,
            ];
        }

        $abiertos = [];
        $r = $conexion->query("
            SELECT id_corte, id_servicio, referencia_fuente FROM servicios_cortes
            WHERE fuente = 'UISP' AND fecha_fin IS NULL AND anulado = 0
        ");
        while ($f = $r->fetch_assoc()) {
            $abiertos[(int)$f['id_servicio']] = $f;
        }

        if ($contadores['suspendidos_uisp'] === 0 && count($abiertos) > 10) {
            throw new RuntimeException('UISP no reportó servicios suspendidos pero hay ' . count($abiertos)
                . ' cortes abiertos: se detiene para no cerrarlos por error.');
        }

        foreach ($suspendidosUisp as $idServicio => $s) {
            if (isset($abiertos[$idServicio])) {
                continue;
            }
            $contadores['cortes_abiertos_nuevos']++;
            $fechaInicio = $s['inicio'] ?? $hoyTexto;
            echo "  ✂ Servicio $idServicio cortado en UISP desde $fechaInicio"
                . ($s['inicio'] === null ? ' (fecha de detección)' : '') . "\n";

            if ($confirmar) {
                abrirCorteMss($conexion, $idServicio, $s['id_cliente'], 'UISP', $s['uisp_service_id'],
                    $fechaInicio, $s['inicio'] === null);
            }
        }

        foreach ($abiertos as $idServicio => $corte) {
            if (isset($suspendidosUisp[$idServicio])) {
                continue;
            }
            $contadores['cortes_cerrados']++;
            echo "  ↻ Servicio $idServicio ya no está suspendido en UISP: corte {$corte['id_corte']} cerrado hoy\n";

            if ($confirmar) {
                cerrarCorteMss($conexion, (int)$corte['id_corte'], $hoyTexto);
            }
        }
    } else {
        echo "Sin consultar UISP (--sin-uisp).\n";
    }

    /* ===================== 1b. Cortes de Grezz ===================== */
    $r = $conexion->query("
        SELECT id_solicitud, id_cliente, id_servicio, accion, DATE(fecha_respuesta) AS fecha
        FROM solicitudes_estado_servicio
        WHERE estado = 'COMPLETADA' AND fecha_respuesta IS NOT NULL
        ORDER BY fecha_respuesta, id_solicitud
    ");
    $abiertoGrezz = [];
    $cortesGrezz = [];

    while ($f = $r->fetch_assoc()) {
        $idServicio = (int)$f['id_servicio'];

        if ($f['accion'] === 'SUSPENDER' && !isset($abiertoGrezz[$idServicio])) {
            $abiertoGrezz[$idServicio] = count($cortesGrezz);
            $cortesGrezz[] = [
                'id_servicio' => $idServicio,
                'id_cliente' => (int)$f['id_cliente'],
                'referencia' => (string)$f['id_solicitud'],
                'inicio' => (string)$f['fecha'],
                'fin' => null,
            ];
        } elseif ($f['accion'] === 'REACTIVAR' && isset($abiertoGrezz[$idServicio])) {
            $cortesGrezz[$abiertoGrezz[$idServicio]]['fin'] = (string)$f['fecha'];
            unset($abiertoGrezz[$idServicio]);
        }
    }

    if ($confirmar) {
        foreach ($cortesGrezz as $c) {
            abrirCorteMss($conexion, $c['id_servicio'], $c['id_cliente'], 'GREZZ', $c['referencia'],
                $c['inicio'], false, $c['fin']);
        }
    }
    echo "Cortes registrados por Grezz: " . count($cortesGrezz) . "\n\n";

    /* ===================== 2. Aplicar la regla ===================== */
    // En simulación se agregan en memoria los cortes que se habrían guardado.
    $limiteCerrados = $hoy->modify("-$diasRevision days")->format('Y-m-d');
    $stmt = $conexion->prepare("
        SELECT * FROM servicios_cortes
        WHERE anulado = 0 AND (fecha_fin IS NULL OR fecha_fin >= ?)
        ORDER BY id_servicio, fecha_inicio, id_corte
    ");
    $stmt->bind_param('s', $limiteCerrados);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$confirmar) {
        foreach ($suspendidosUisp ?? [] as $idServicio => $s) {
            if (!isset($abiertos[$idServicio])) {
                $filas[] = [
                    'id_corte' => 0, 'id_servicio' => $idServicio, 'id_cliente' => $s['id_cliente'],
                    'fuente' => 'UISP', 'fecha_inicio' => $s['inicio'] ?? $hoyTexto, 'fecha_fin' => null,
                    'id_obligacion_reconexion' => null, 'reconexion_estado' => null,
                ];
            }
        }
        foreach ($cortesGrezz as $c) {
            if ($c['fin'] === null || $c['fin'] >= $limiteCerrados) {
                $filas[] = [
                    'id_corte' => 0, 'id_servicio' => $c['id_servicio'], 'id_cliente' => $c['id_cliente'],
                    'fuente' => 'GREZZ', 'fecha_inicio' => $c['inicio'], 'fecha_fin' => $c['fin'],
                    'id_obligacion_reconexion' => null, 'reconexion_estado' => null,
                ];
            }
        }
        foreach ($filas as &$fila) {
            if ((int)$fila['id_corte'] !== 0 && $fila['fuente'] === 'UISP' && $fila['fecha_fin'] === null
                && $usarUisp && !isset($suspendidosUisp[(int)$fila['id_servicio']])) {
                $fila['fecha_fin'] = $hoyTexto;
            }
        }
        unset($fila);
        usort($filas, fn($a, $b) => [$a['id_servicio'], $a['fecha_inicio']] <=> [$b['id_servicio'], $b['fecha_inicio']]);
    }

    // Une los cortes que se enciman (UISP y Grezz reportando el mismo corte).
    // El primero del grupo "es dueño" de la reconexión.
    $grupos = [];
    foreach ($filas as $f) {
        $idServicio = (int)$f['id_servicio'];
        $ultimo = array_key_last($grupos);

        if ($ultimo !== null && $grupos[$ultimo]['id_servicio'] === $idServicio
            && ($grupos[$ultimo]['fin'] === null || $f['fecha_inicio'] <= $grupos[$ultimo]['fin'])) {
            $g = &$grupos[$ultimo];
            if ($g['fin'] !== null) {
                $g['fin'] = $f['fecha_fin'] === null ? null : max($g['fin'], $f['fecha_fin']);
            }
            if ($g['dueno']['id_obligacion_reconexion'] === null && $f['id_obligacion_reconexion'] !== null) {
                $g['dueno'] = $f; // Conserva el que ya lleva la reconexión.
            }
            unset($g);
            continue;
        }

        $grupos[] = [
            'id_servicio' => $idServicio,
            'inicio' => (string)$f['fecha_inicio'],
            'fin' => $f['fecha_fin'] !== null ? (string)$f['fecha_fin'] : null,
            'dueno' => $f,
        ];
    }

    foreach ($grupos as $g) {
        $idServicio = $g['id_servicio'];
        $idCorte = (int)$g['dueno']['id_corte'];
        $contadores['cortes_revisados']++;

        if (in_array($idServicio, MSS_SERVICIOS_PROTEGIDOS, true)) {
            $revision[] = "Servicio $idServicio protegido: no se ajustan sus mensualidades.";
            continue;
        }

        $servicio = $servicios[$idServicio] ?? null;
        $referencia = new DateTimeImmutable($g['fin'] ?? $hoyTexto);
        $periodoActual = $referencia->format('Ym');
        // Paga en UBB Pagos: se factura en la plataforma, o su cliente no está en UISP.
        $cobraUbb = $servicio !== null
            && ($servicio['en_plataforma'] || !isset($clientesConUisp[$servicio['id_cliente']]));

        $columnas = 'id_obligacion, periodo, monto, estado, fecha_vencimiento, cargo_reconexion'
            . ($tieneUispInvoice ? ', uisp_invoice_id' : ', NULL AS uisp_invoice_id')
            . ($tieneIdFactura ? ', id_factura' : ', NULL AS id_factura')
            . ', referencia_cobro';
        $stmt = $conexion->prepare("
            SELECT $columnas FROM obligaciones_pago
            WHERE id_servicio = ? AND concepto = 'MENSUALIDAD'
            ORDER BY fecha_vencimiento, id_obligacion
        ");
        $stmt->bind_param('i', $idServicio);
        $stmt->execute();
        $mensualidades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $actual = null;
        foreach ($mensualidades as $o) {
            if ($o['periodo'] === $periodoActual && $o['estado'] !== 'CANCELADA') {
                $actual = $o;
            }
        }

        $etiqueta = "Servicio $idServicio (corte desde {$g['inicio']}" . ($g['fin'] ? " hasta {$g['fin']}" : ', sigue cortado') . ')';

        /* --- Reconexión --- */
        $idConReconexion = $g['dueno']['id_obligacion_reconexion'] !== null ? (int)$g['dueno']['id_obligacion_reconexion'] : null;
        $estadoReconexion = $g['dueno']['reconexion_estado'];
        $nuevoDestino = $idConReconexion;

        if ($actual === null) {
            $nuevoEstadoReconexion = $estadoReconexion === 'CARGADA' ? 'CARGADA' : 'SIN_MENSUALIDAD';
            if ($estadoReconexion !== 'CARGADA') {
                $revision[] = "$etiqueta: no tiene mensualidad de $periodoActual; la reconexión se cargará cuando exista.";
            }
        } elseif ((int)$actual['id_obligacion'] === $idConReconexion) {
            $nuevoEstadoReconexion = $estadoReconexion; // Ya está en la mensualidad del mes.
        } elseif (!$cobraUbb) {
            // Se paga en UISP: la reconexión debe venir en su factura.
            $enFactura = false;
            $factura = null;
            if ($actual['uisp_invoice_id'] !== null) {
                $stmt = $conexion->prepare("SELECT numero, conceptos_json FROM uisp_facturas WHERE uisp_invoice_id = ?");
                $stmt->bind_param('i', $actual['uisp_invoice_id']);
                $stmt->execute();
                $factura = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $enFactura = $factura && preg_match('/reconex/iu', (string)$factura['conceptos_json']) === 1;
            }

            if ($enFactura) {
                $nuevoEstadoReconexion = 'EN_FACTURA';
                $nuevoDestino = (int)$actual['id_obligacion'];
                $contadores['reconexiones_en_factura']++;
            } else {
                $nuevoEstadoReconexion = 'FALTA_EN_UISP';
                $contadores['reconexiones_falta_en_uisp']++;
                $faltaReconexionUisp[] = "$etiqueta: mensualidad {$actual['id_obligacion']} ($periodoActual)"
                    . (isset($factura['numero']) ? ", factura UISP {$factura['numero']}" : ', sin factura de UISP todavía');
            }
        } elseif ($actual['estado'] !== 'PENDIENTE') {
            $nuevoEstadoReconexion = 'REVISION';
            $revision[] = "$etiqueta: la mensualidad de $periodoActual ya está {$actual['estado']}; la reconexión no se cargó.";
        } else {
            // Quitar la reconexión de la mensualidad anterior (si no tiene pagos) y pasarla al mes actual.
            $puedeMover = true;
            if ($idConReconexion !== null && $estadoReconexion === 'CARGADA') {
                if (aplicadoMss($conexion, $idConReconexion) > 0) {
                    $puedeMover = false;
                    $revision[] = "$etiqueta: la reconexión está en la mensualidad $idConReconexion, que ya tiene pagos; no se mueve.";
                } elseif ($confirmar) {
                    moverReconexionMss($conexion, $idConReconexion, -$cargoReconexion);
                }
            }

            if ($puedeMover) {
                $nuevoEstadoReconexion = 'CARGADA';
                $nuevoDestino = (int)$actual['id_obligacion'];
                $contadores['reconexiones_cargadas']++;
                echo "  + $etiqueta: reconexión $" . pesosMss($cargoReconexion) . " en la mensualidad {$actual['id_obligacion']} ($periodoActual): "
                    . pesosMss(centavosMss($actual['monto'])) . ' → ' . pesosMss(centavosMss($actual['monto']) + $cargoReconexion) . "\n";
                if ($confirmar) {
                    moverReconexionMss($conexion, $nuevoDestino, $cargoReconexion);
                }
            } else {
                $nuevoEstadoReconexion = $estadoReconexion;
            }
        }

        /* --- Meses anteriores al mes actual: no se cobran --- */
        foreach ($mensualidades as $o) {
            if ($o['estado'] !== 'PENDIENTE' || $o['periodo'] >= $periodoActual) {
                continue;
            }

            $idObligacion = (int)$o['id_obligacion'];
            $aplicado = aplicadoMss($conexion, $idObligacion);

            if ($aplicado > 0) {
                $revision[] = "$etiqueta: la mensualidad $idObligacion ({$o['periodo']}) tiene un pago parcial de "
                    . pesosMss($aplicado) . "; no se cancela.";
                continue;
            }

            $motivo = (string)$o['fecha_vencimiento'] < $g['inicio'] ? 'MES_DEL_CORTE' : 'SERVICIO_CORTADO';
            // Si la mensualidad llevaba esta reconexión y no se pudo quitar antes, no se cancela con ella.
            $monto = centavosMss($o['monto']);
            $contadores['mensualidades_no_cobradas']++;
            $importeNoCobrado += $monto - centavosMss($o['cargo_reconexion']);
            echo "  ✖ $etiqueta: mensualidad $idObligacion ({$o['periodo']}, " . pesosMss($monto) . ") no se cobra: $motivo\n";

            if ($o['uisp_invoice_id'] !== null) {
                $anularUisp[] = "Mensualidad $idObligacion ({$o['periodo']}) del servicio $idServicio: factura UISP id {$o['uisp_invoice_id']}";
            } elseif ($o['id_factura'] !== null) {
                $anularPlataforma[] = "Mensualidad $idObligacion ({$o['periodo']}) del servicio $idServicio: folio {$o['referencia_cobro']}";
            }

            if ($confirmar) {
                $nota = ' | No cobrada: ' . ($motivo === 'MES_DEL_CORTE' ? 'mes del corte' : 'servicio cortado')
                    . ($idCorte > 0 ? " (corte $idCorte)" : '') . '.';
                $stmt = $conexion->prepare("
                    UPDATE obligaciones_pago
                    SET estado = 'CANCELADA', no_cobro_motivo = ?, id_corte_no_cobro = ?,
                        observaciones = CONCAT(COALESCE(observaciones, ''), ?)
                    WHERE id_obligacion = ? AND estado = 'PENDIENTE'
                ");
                $stmt->bind_param('sisi', $motivo, $idCorte, $nota, $idObligacion);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($confirmar && $idCorte > 0) {
            $stmt = $conexion->prepare("
                UPDATE servicios_cortes SET id_obligacion_reconexion = ?, reconexion_estado = ? WHERE id_corte = ?
            ");
            $stmt->bind_param('isi', $nuevoDestino, $nuevoEstadoReconexion, $idCorte);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($confirmar) {
        $conexion->commit();
    }

    $contadores['importe_no_cobrado'] = pesosMss($importeNoCobrado);
    $contadores['para_revision'] = count($revision);

    echo "\n===== RESUMEN =====\n";
    if ($usarUisp) {
        echo "Servicios suspendidos en UISP:      {$contadores['suspendidos_uisp']}\n";
        echo "Cortes nuevos (UISP):               {$contadores['cortes_abiertos_nuevos']}\n";
        echo "Cortes cerrados (UISP):             {$contadores['cortes_cerrados']}\n";
    }
    echo "Cortes revisados:                   {$contadores['cortes_revisados']}\n";
    echo "Mensualidades que no se cobran:     {$contadores['mensualidades_no_cobradas']} ($" . $contadores['importe_no_cobrado'] . ")\n";
    echo "Reconexiones cargadas en UBB:       {$contadores['reconexiones_cargadas']}\n";
    echo "Reconexiones ya en factura UISP:    {$contadores['reconexiones_en_factura']}\n";
    echo "Reconexiones que faltan en UISP:    {$contadores['reconexiones_falta_en_uisp']}\n";

    $listas = [
        'Facturas de UISP que el personal debe anular en el CRM' => $anularUisp,
        'Facturas de la plataforma que Jamileth debe cancelar' => $anularPlataforma,
        'Agregar la reconexión en la factura de UISP' => $faltaReconexionUisp,
        'Para revisión' => $revision,
    ];
    foreach ($listas as $titulo => $lista) {
        if ($lista) {
            echo "\n$titulo (" . count($lista) . ", primeras 30):\n";
            foreach (array_slice($lista, 0, 30) as $texto) {
                echo "  ⚠ $texto\n";
            }
        }
    }

    if (!$confirmar) {
        echo "\nSimulación: no se guardó nada. Agrega --confirmar para guardar.\n";
    }

    registrarEjecucion($conexion, MSS_PROCESO, $confirmar, $inicio, $contadores);
} catch (Throwable $e) {
    if ($conexion instanceof mysqli && $confirmar) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if ($conexion instanceof mysqli) {
        registrarEjecucion($conexion, MSS_PROCESO, $confirmar, $inicio, $contadores, $e->getMessage());
    }

    $codigoSalida = 1;
} finally {
    if (isset($plataforma) && $plataforma instanceof mysqli) {
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
