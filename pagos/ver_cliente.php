<?php
declare(strict_types=1);
require_once __DIR__ . "/includes/sesion.php";

require_once __DIR__ . "/conexion.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function exp_h($valor): string {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function exp_fecha($fecha): string {
    if (!$fecha || $fecha === "0000-00-00") {
        return "No registrada";
    }
    return exp_h($fecha);
}

$id_cliente = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id_cliente || $id_cliente <= 0) {
    http_response_code(400);
    exit("El identificador del cliente no es válido.");
}

$error = null;
$cliente = null;
$servicios = [];
$pagos = [];
$uispPagos = [];
$obligaciones = [];

$resumen = [
    "total" => 0,
    "pagados" => 0,
    "pendientes" => 0,
    "cancelados" => 0,
    "total_pagado" => 0,
    "ultimo_pago" => null
];

$totalUisp = 0;
$montoUisp = 0.0;

try {
    // CONEXIÓN DE SOLO LECTURA A CLIENTES Y SERVICIOS
    $remota = require __DIR__ . "/conexion_plataforma.php";

    // 1. DATOS DEL CLIENTE
    $stmt = $remota->prepare("
        SELECT
            id_cliente,
            uisp_id,
            codigo_cliente,
            nombre,
            apellido_paterno,
            apellido_materno,
            telefono,
            correo,
            estado,
            uisp_archivado,
            datos_incompletos
        FROM clientes
        WHERE id_cliente = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cliente) {
        http_response_code(404);
        throw new RuntimeException("No se encontró el cliente solicitado.");
    }

    // 2. SERVICIOS DEL CLIENTE
    $stmt = $remota->prepare("
        SELECT
            id_servicio,
            codigo_servicio,
            tecnologia,
            paquete_referencia,
            estado,
            fecha_contratacion,
            fecha_instalacion,
            observaciones
        FROM servicios
        WHERE id_cliente = ?
        ORDER BY id_servicio DESC
    ");

    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $servicios[] = $fila;
    }
    $stmt->close();
    $remota->close();

    // 3. RESUMEN DE PAGOS UBB
    $stmt = $conexion->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(estado_pago = 'PAGADO'), 0) AS pagados,
            COALESCE(SUM(estado_pago = 'PENDIENTE'), 0) AS pendientes,
            COALESCE(SUM(estado_pago = 'CANCELADO'), 0) AS cancelados,
            COALESCE(
                SUM(CASE WHEN estado_pago = 'PAGADO' AND metodo_pago <> 'CORTESIA'
                    THEN monto ELSE 0 END), 0
            ) AS total_pagado,
            MAX(CASE WHEN estado_pago = 'PAGADO'
                THEN fecha_pago END) AS ultimo_pago
        FROM pagos
        WHERE id_cliente = ?
    ");

    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $resumen = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Saldo a favor: lo que sobró de pagos de UISP y aún no se abona a una mensualidad.
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(p.monto - COALESCE(a.aplicado, 0)), 0)
        FROM pagos p
        LEFT JOIN (
            SELECT id_pago, SUM(monto_aplicado) AS aplicado
            FROM pagos_obligaciones
            GROUP BY id_pago
        ) a ON a.id_pago = p.id_pago
        WHERE p.id_cliente = ?
          AND p.estado_pago = 'PAGADO'
          AND p.referencia LIKE 'UISP-%'
          AND p.monto > COALESCE(a.aplicado, 0)
    ");
    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $saldoAFavor = (float)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    // 4. HISTORIAL DE PAGOS REGISTRADOS EN UBB
    $stmt = $conexion->prepare("
        SELECT
            id_pago,
            id_servicio,
            monto,
            fecha_pago,
            metodo_pago,
            estado_pago,
            referencia,
            comprobante
        FROM pagos
        WHERE id_cliente = ?
        ORDER BY fecha_pago DESC, id_pago DESC
    ");

    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $pagos[] = $fila;
    }
    $stmt->close();

    // 5. HISTORIAL IMPORTADO DE UISP
    // La relación se realiza mediante uisp_id, no mediante el ID interno UBB.
    if (!empty($cliente["uisp_id"])) {
        $uispId = (string)$cliente["uisp_id"];

        $stmt = $conexion->prepare("
            SELECT
                id_importacion,
                uisp_client_id,
                cliente_nombre_uisp,
                monto,
                moneda,
                fecha_original_uisp,
                metodo_original_uisp,
                metodo_normalizado,
                organizacion,
                nota,
                admin_uisp,
                numero_cheque,
                revisado,
                uisp_payment_id,
                id_pago_ubb
            FROM uisp_pagos_importacion
            WHERE uisp_client_id = ?
            ORDER BY
                STR_TO_DATE(
                    LEFT(fecha_original_uisp, 19),
                    '%Y-%m-%dT%H:%i:%s'
                ) DESC,
                id_importacion DESC
        ");

        $stmt->bind_param("s", $uispId);
        $stmt->execute();
        $resultado = $stmt->get_result();

        while ($fila = $resultado->fetch_assoc()) {
            $uispPagos[] = $fila;
            $totalUisp++;

            // Se conserva el importe de la fuente; no se suma al total UBB.
            $montoUisp += (float)$fila["monto"];
        }
        $stmt->close();
    }

    // 6. OBLIGACIONES DE PAGO
    $stmt = $conexion->prepare("
        SELECT
            id_obligacion,
            id_servicio,
            periodo,
            concepto,
            monto,
            fecha_vencimiento,
            referencia_cobro,
            estado
        FROM obligaciones_pago
        WHERE id_cliente = ?
        ORDER BY
            CASE estado
                WHEN 'PENDIENTE' THEN 0
                WHEN 'PAGADA' THEN 1
                ELSE 2
            END,
            fecha_vencimiento DESC
    ");

    $stmt->bind_param("i", $id_cliente);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $obligaciones[] = $fila;
    }
    $stmt->close();

} catch (Throwable $e) {
    error_log("UBB expediente cliente {$id_cliente}: " . $e->getMessage());
    $error = $e instanceof RuntimeException
        ? $e->getMessage()
        : "No fue posible cargar toda la información del expediente.";
}

$nombreCompleto = $cliente
    ? trim(
        $cliente["nombre"] . " " .
        $cliente["apellido_paterno"] . " " .
        $cliente["apellido_materno"]
    )
    : "Cliente";


// RESUMEN FINANCIERO INDIVIDUAL POR SERVICIO.
$resumenServicios = [];

foreach ($servicios as $servicio) {
    $sid = (int)$servicio["id_servicio"];

    $resumenServicios[$sid] = [
        "pagos_confirmados" => 0,
        "total_pagado" => 0.0,
        "pagos_pendientes" => 0,
        "monto_pagos_pendientes" => 0.0,
        "obligaciones_pendientes" => 0,
        "monto_obligaciones_pendientes" => 0.0
    ];

    foreach ($pagos as $pago) {
        if ((int)($pago["id_servicio"] ?? 0) !== $sid) {
            continue;
        }

        if ($pago["estado_pago"] === "PAGADO") {
            $resumenServicios[$sid]["pagos_confirmados"]++;
            if ($pago["metodo_pago"] !== "CORTESIA") {
                $resumenServicios[$sid]["total_pagado"] += (float)$pago["monto"];
            }
        } elseif ($pago["estado_pago"] === "PENDIENTE") {
            $resumenServicios[$sid]["pagos_pendientes"]++;
            $resumenServicios[$sid]["monto_pagos_pendientes"] += (float)$pago["monto"];
        }
    }

    foreach ($obligaciones as $obligacion) {
        if ((int)$obligacion["id_servicio"] !== $sid) {
            continue;
        }

        if ($obligacion["estado"] === "PENDIENTE") {
            $resumenServicios[$sid]["obligaciones_pendientes"]++;
            $resumenServicios[$sid]["monto_obligaciones_pendientes"] += (float)$obligacion["monto"];
        }
    }
}

/* =========================================================
   HISTORIAL ÚNICO DE PAGOS
   Junta los pagos de UBB y los registros importados de UISP en una
   sola lista por fecha. Un registro de UISP que ya se aplicó en UBB
   (id_pago_ubb, o referencia "UISP-<id>" en el pago) no se repite.
========================================================= */
$historialPagos = [];
$pagosUbbIds = [];
$referenciasUbb = [];

foreach ($pagos as $p) {
    $pagosUbbIds[(int)$p["id_pago"]] = true;
    if ($p["referencia"]) {
        $referenciasUbb[(string)$p["referencia"]] = true;
    }

    $historialPagos[] = [
        "origen" => "UBB",
        "momento" => strtotime((string)$p["fecha_pago"]) ?: 0,
        "pago" => $p,
    ];
}

$uispSoloHistorico = 0;

foreach ($uispPagos as $u) {
    $yaEnUbb = (!empty($u["id_pago_ubb"]) && isset($pagosUbbIds[(int)$u["id_pago_ubb"]]))
        || (!empty($u["uisp_payment_id"]) && isset($referenciasUbb["UISP-" . $u["uisp_payment_id"]]));

    if ($yaEnUbb) {
        continue;
    }

    $uispSoloHistorico++;
    $historialPagos[] = [
        "origen" => "UISP",
        "momento" => strtotime(substr((string)$u["fecha_original_uisp"], 0, 19)) ?: 0,
        "uisp" => $u,
    ];
}

usort($historialPagos, fn($a, $b) => $b["momento"] <=> $a["momento"]);

function exp_estado_clase(string $estado): string {
    $clases = [
        "ACTIVO" => "activo",
        "PAGADO" => "pagado",
        "PAGADA" => "pagado",
        "PENDIENTE" => "pendiente",
        "SUSPENDIDO" => "suspendido",
        "BAJA" => "baja",
        "CANCELADO" => "cancelado",
        "CANCELADA" => "cancelado",
        "INACTIVO" => "inactivo"
    ];

    return $clases[$estado] ?? "neutral";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expediente de cliente | UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">

    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">

    <style>
        .expediente { display:grid; gap:18px; padding-bottom:35px; }

        /* Cabecera: nombre del cliente, estado y acciones */
        .exp-cabecera { margin-bottom:0 !important; }
        .exp-cabecera .cc-cabecera-texto p { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
        .exp-acciones { display:flex; flex-wrap:wrap; gap:8px; }
        .exp-acciones .hist-boton { padding:9px 14px; font-size:13px; }

        /* Datos de contacto */
        .exp-datos {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:0; padding:0; overflow:hidden;
        }
        .exp-dato { display:flex; gap:12px; align-items:center; padding:16px 18px; min-width:0; }
        .exp-dato + .exp-dato { border-left:1px solid var(--cc-borde); }
        .exp-dato-icono {
            display:grid; place-items:center; flex:0 0 38px; width:38px; height:38px;
            border-radius:50%; background:var(--cc-morado-suave); color:var(--cc-morado-medio);
        }
        .exp-dato-icono svg { width:18px; height:18px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .exp-dato > div > span { display:block; color:var(--cc-texto-suave); font-size:11px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; margin-bottom:3px; }
        .exp-dato > div > strong { display:block; color:var(--cc-texto); font-size:14px; overflow-wrap:anywhere; }

        /* Indicadores */
        .exp-kpis { grid-template-columns:repeat(5,minmax(0,1fr)); margin-bottom:0 !important; }
        .exp-kpis .cc-kpi-numero { font-size:28px; }
        .exp-kpi--gris {
            --cc-acento:#8a8a96; --cc-acento-fondo:#f4f4f7; --cc-acento-borde:#e3e3ea;
            border-color:var(--cc-acento-borde);
            background:linear-gradient(160deg,var(--cc-acento-fondo) 0%,var(--cc-acento-fondo) 60%,#fff 140%);
        }

        /* Secciones con tabla: panel blanco como en los listados */
        .exp-seccion {
            min-width:0; overflow:hidden;
            background:#fff; border:1px solid var(--cc-borde);
            border-radius:16px; box-shadow:var(--cc-sombra);
        }
        .cc .exp-seccion h3 {
            margin:0; padding:18px 20px 0;
            color:var(--cc-texto); font-size:17px; font-weight:800;
        }
        .exp-nota {
            margin:10px 20px 0; padding:12px 15px; border-radius:11px;
            background:#fcf8fd; border:1px solid #f0e4f3;
            color:var(--cc-texto-suave); font-size:13px; line-height:1.6;
        }
        .exp-nota strong { color:var(--cc-texto); }
        .exp-vacio {
            margin:16px 20px 20px; padding:24px; border-radius:11px;
            background:#fcf8fd; text-align:center; color:var(--cc-texto-suave);
        }
        .exp-tabla-scroll { overflow-x:auto; margin-top:16px; border-top:1px solid var(--cc-borde); }
        .exp-tabla { width:100%; border-collapse:collapse; min-width:760px; }
        .exp-tabla thead th {
            padding:13px 16px; border-bottom:1px solid var(--cc-borde);
            background:var(--cc-morado-suave); color:var(--cc-morado);
            font-size:11px; font-weight:800; letter-spacing:.5px; text-align:left;
            text-transform:uppercase; white-space:nowrap;
        }
        .exp-tabla tbody td {
            padding:13px 16px; border-bottom:1px solid #f3eaf5;
            color:var(--cc-texto); font-size:13.5px; text-align:left; vertical-align:top;
        }
        .exp-tabla tbody tr:last-child td { border-bottom:0; }
        .exp-tabla tbody tr:hover { background:#fcf8fd; }
        .exp-tabla small { display:block; color:var(--cc-texto-suave); font-size:12px; margin-top:3px; }
        .exp-codigo { display:block; font-family:ui-monospace,"Cascadia Mono",Consolas,monospace; font-size:12.5px; color:var(--cc-texto-suave); }

        /* Estados */
        .exp-badge {
            display:inline-block; padding:5px 11px; border-radius:999px;
            font-size:11px; font-weight:800; letter-spacing:.3px; white-space:nowrap;
        }
        .exp-badge.activo,.exp-badge.pagado { background:var(--cc-ok-fondo); color:var(--cc-ok); }
        .exp-badge.pendiente { background:var(--cc-atencion-fondo); color:var(--cc-atencion); }
        .exp-badge.suspendido,.exp-badge.baja,.exp-badge.cancelado { background:var(--cc-error-fondo); color:var(--cc-error); }
        .exp-badge.inactivo,.exp-badge.neutral { background:#eeeef2; color:#666; }

        /* Origen del pago en el historial */
        .exp-origen { display:inline-block; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:800; letter-spacing:.3px; }
        .exp-origen--ubb { background:var(--cc-morado-suave); color:var(--cc-morado); }
        .exp-origen--uisp { background:#e8eefc; color:#3d5aa8; }
        .exp-debe { color:var(--cc-error); }
        .exp-por-confirmar { color:var(--cc-atencion); }

        @media(max-width:1200px) {
            .exp-kpis { grid-template-columns:repeat(3,minmax(0,1fr)); }
        }
        @media(max-width:900px) {
            .exp-datos { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .exp-dato + .exp-dato { border-left:0; }
            .exp-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media(max-width:520px) {
            .exp-datos,.exp-kpis { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist">
<div class="expediente">

    <?php if ($error): ?>
        <header class="cc-cabecera exp-cabecera">
            <div class="cc-cabecera-texto">
                <span class="cc-cabecera-icono" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                </span>
                <div>
                    <h1>No se pudo cargar el expediente</h1>
                    <p><?= exp_h($error) ?></p>
                </div>
            </div>
            <div class="exp-acciones">
                <a class="hist-boton" href="clientes.php">← Volver a clientes</a>
            </div>
        </header>
    <?php elseif ($cliente): ?>

        <!-- =================================================
             CABECERA: cliente, estado y acciones
        ================================================== -->

        <header class="cc-cabecera exp-cabecera">
            <div class="cc-cabecera-texto">
                <span class="cc-cabecera-icono" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                </span>
                <div>
                    <h1><?= exp_h($nombreCompleto) ?></h1>
                    <p>
                        Cliente #<?= (int)$cliente["id_cliente"] ?>
                        · <?= $cliente["codigo_cliente"] ? "Código " . exp_h($cliente["codigo_cliente"]) : "Sin código" ?>
                        <span class="exp-badge <?= exp_estado_clase($cliente["estado"]) ?>">
                            <?= exp_h($cliente["estado"]) ?>
                        </span>
                    </p>
                </div>
            </div>
            <div class="exp-acciones">
                <a class="hist-boton" href="clientes.php">← Volver a clientes</a>
                <?php if (puedeModificar()): ?>
                    <a class="hist-boton hist-boton--principal" href="registrar.php">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
                        Registrar pago
                    </a>
                <?php endif; ?>
            </div>
        </header>


        <!-- =================================================
             DATOS DE CONTACTO
        ================================================== -->

        <section class="cc-panel exp-datos">
            <div class="exp-dato">
                <span class="exp-dato-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg></span>
                <div>
                    <span>Teléfono</span>
                    <strong><?= exp_h($cliente["telefono"] ?: "No registrado") ?></strong>
                </div>
            </div>
            <div class="exp-dato">
                <span class="exp-dato-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
                <div>
                    <span>Correo electrónico</span>
                    <strong><?= exp_h($cliente["correo"] ?: "No registrado") ?></strong>
                </div>
            </div>
            <div class="exp-dato">
                <span class="exp-dato-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
                <div>
                    <span>Identificador UISP</span>
                    <strong><?= exp_h($cliente["uisp_id"] ?: "Sin vincular") ?></strong>
                </div>
            </div>
            <div class="exp-dato">
                <span class="exp-dato-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><path d="M12 19.5h.01"/></svg></span>
                <div>
                    <span>Servicios contratados</span>
                    <strong><?= count($servicios) ?></strong>
                </div>
            </div>
        </section>

        <?php if ((int)$cliente["datos_incompletos"] === 1): ?>
            <div class="exp-nota" style="margin:0">Este cliente tiene datos marcados como incompletos en la base de servicios.</div>
        <?php endif; ?>

        <?php if ((int)$cliente["uisp_archivado"] === 1): ?>
            <div class="exp-nota" style="margin:0">El registro del cliente aparece archivado en UISP.</div>
        <?php endif; ?>


        <!-- =================================================
             INDICADORES
        ================================================== -->

        <div class="cc-kpis hist-kpis exp-kpis">
            <div class="cc-kpi cc-kpi--pagos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg></span>
                    <span class="cc-kpi-nombre">Pagos registrados UBB</span>
                </div>
                <strong class="cc-kpi-numero"><?= (int)$resumen["total"] ?></strong>
            </div>
            <div class="cc-kpi cc-kpi--activos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                    <span class="cc-kpi-nombre">Pagos confirmados UBB</span>
                </div>
                <strong class="cc-kpi-numero"><?= (int)$resumen["pagados"] ?></strong>
            </div>
            <div class="cc-kpi hist-kpi--confirmados">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                    <span class="cc-kpi-nombre">Total pagado en UBB</span>
                </div>
                <strong class="cc-kpi-numero">$<?= number_format((float)$resumen["total_pagado"], 2) ?></strong>
            </div>
            <div class="cc-kpi cc-kpi--proximos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 12v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8"/><path d="M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7ZM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7Z"/></svg></span>
                    <span class="cc-kpi-nombre">Saldo a favor</span>
                </div>
                <strong class="cc-kpi-numero">$<?= number_format($saldoAFavor ?? 0, 2) ?></strong>
            </div>
            <div class="cc-kpi exp-kpi--gris">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg></span>
                    <span class="cc-kpi-nombre">Pagos históricos UISP</span>
                </div>
                <strong class="cc-kpi-numero"><?= (int)$totalUisp ?></strong>
            </div>
        </div>


        <section class="exp-seccion">
            <h3>Servicios contratados</h3>
            <div class="exp-nota">
                Lo pagado, lo que falta confirmar y lo que debe de cada servicio, según los pagos y
                mensualidades registrados en UBB. Los registros viejos que solo están en UISP no se
                cuentan aquí porque no dicen a qué servicio corresponden.
            </div>

            <?php if (!$servicios): ?>
                <div class="exp-vacio">Este cliente no tiene servicios registrados.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Paquete</th>
                                <th>Estado</th>
                                <th>Instalación</th>
                                <th>Pagado</th>
                                <th>Por confirmar</th>
                                <th>Debe</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($servicios as $s): ?>
                            <?php
                            $fin = $resumenServicios[(int)$s["id_servicio"]];
                            $tecnologia = (string)$s["tecnologia"];
                            $tecnologiaVisible = $tecnologia !== "" && $tecnologia !== "POR_DEFINIR";
                            ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$s["id_servicio"] ?></strong>
                                    <small><?= exp_h($s["codigo_servicio"]) ?></small>
                                </td>
                                <td>
                                    <?= exp_h($s["paquete_referencia"] ?: "Sin paquete") ?>
                                    <?php if ($tecnologiaVisible): ?><small><?= exp_h($tecnologia) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <span class="exp-badge <?= exp_estado_clase($s["estado"]) ?>">
                                        <?= exp_h($s["estado"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= exp_fecha($s["fecha_instalacion"]) ?>
                                    <small>Contratado: <?= exp_fecha($s["fecha_contratacion"]) ?></small>
                                </td>
                                <td>
                                    <strong>$<?= number_format($fin["total_pagado"], 2) ?></strong>
                                    <small><?= (int)$fin["pagos_confirmados"] ?> pago<?= (int)$fin["pagos_confirmados"] === 1 ? "" : "s" ?> confirmado<?= (int)$fin["pagos_confirmados"] === 1 ? "" : "s" ?></small>
                                </td>
                                <td>
                                    <?php if ((int)$fin["pagos_pendientes"] > 0): ?>
                                        <strong class="exp-por-confirmar">$<?= number_format($fin["monto_pagos_pendientes"], 2) ?></strong>
                                        <small><?= (int)$fin["pagos_pendientes"] ?> pago<?= (int)$fin["pagos_pendientes"] === 1 ? "" : "s" ?> sin confirmar</small>
                                    <?php else: ?>
                                        <span class="hist-suave">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$fin["obligaciones_pendientes"] > 0): ?>
                                        <strong class="exp-debe">$<?= number_format($fin["monto_obligaciones_pendientes"], 2) ?></strong>
                                        <small><?= (int)$fin["obligaciones_pendientes"] ?> mensualidad<?= (int)$fin["obligaciones_pendientes"] === 1 ? "" : "es" ?> sin pagar</small>
                                    <?php else: ?>
                                        <span class="exp-badge pagado">Al corriente</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="exp-seccion">
            <h3>Historial de pagos</h3>
            <div class="exp-nota">
                Pagos registrados en UBB y registros importados de UISP, en una sola lista por fecha.
                Los pagos de UISP que ya se aplicaron en UBB aparecen una sola vez, como pago UBB.
                <br>
                <strong><?= count($pagos) ?></strong> pagos en UBB
                · <strong><?= (int)$uispSoloHistorico ?></strong> registros solo en UISP (histórico)
            </div>

            <?php if (!$historialPagos): ?>
                <div class="exp-vacio">Este cliente todavía no tiene pagos registrados.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Origen</th>
                                <th>Servicio</th>
                                <th>Fecha</th>
                                <th>Importe</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th>Referencia / comprobante</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historialPagos as $h): ?>
                            <?php if ($h["origen"] === "UBB"): $p = $h["pago"]; ?>
                                <tr>
                                    <td><strong>PAG-<?= str_pad((string)$p["id_pago"], 6, "0", STR_PAD_LEFT) ?></strong></td>
                                    <td>
                                        <span class="exp-origen exp-origen--ubb">UBB</span>
                                        <?php if (str_starts_with((string)$p["referencia"], "UISP-")): ?>
                                            <small>Desde UISP</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $p["id_servicio"] === null ? "Sin asociar" : "#" . (int)$p["id_servicio"] ?></td>
                                    <td>
                                        <?= $h["momento"] ? date("d/m/Y", $h["momento"]) : exp_h($p["fecha_pago"]) ?>
                                        <?php if ($h["momento"]): ?><small><?= date("H:i", $h["momento"]) ?></small><?php endif; ?>
                                    </td>
                                    <td><strong>$<?= number_format((float)$p["monto"], 2) ?></strong></td>
                                    <td><?= exp_h($p["metodo_pago"]) ?></td>
                                    <td>
                                        <span class="exp-badge <?= exp_estado_clase($p["estado_pago"]) ?>">
                                            <?= exp_h($p["estado_pago"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="exp-codigo"><?= exp_h($p["referencia"] ?: "—") ?></span>
                                        <a class="hist-boton hist-boton--principal" style="margin-top:6px"
                                           href="comprobante.php?id=<?= (int)$p["id_pago"] ?>"
                                           target="_blank" rel="noopener">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
                                            Comprobante
                                        </a>
                                    </td>
                                </tr>
                            <?php else: $u = $h["uisp"]; ?>
                                <tr>
                                    <td>
                                        <strong>UISP #<?= (int)$u["id_importacion"] ?></strong>
                                        <?php if (!empty($u["uisp_payment_id"])): ?><small>Pago UISP <?= (int)$u["uisp_payment_id"] ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="exp-origen exp-origen--uisp">UISP</span>
                                        <small>Histórico</small>
                                    </td>
                                    <td><span class="hist-suave">—</span></td>
                                    <td>
                                        <?= $h["momento"] ? date("d/m/Y", $h["momento"]) : exp_h($u["fecha_original_uisp"]) ?>
                                        <?php if ($h["momento"]): ?><small><?= date("H:i", $h["momento"]) ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>$<?= number_format((float)$u["monto"], 2) ?></strong>
                                        <small><?= exp_h($u["moneda"]) ?></small>
                                    </td>
                                    <td>
                                        <?= exp_h($u["metodo_original_uisp"] ?: "No especificado") ?>
                                        <?php if ($u["organizacion"]): ?><small><?= exp_h($u["organizacion"]) ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="exp-badge neutral">
                                            <?= (int)$u["revisado"] === 1 ? "Revisado" : "Sin revisar" ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u["nota"] || $u["numero_cheque"]): ?>
                                            <small>
                                                <?php if ($u["nota"]): ?>Nota: <?= exp_h($u["nota"]) ?><?php endif; ?>
                                                <?php if ($u["numero_cheque"]): ?> · Cheque: <?= exp_h($u["numero_cheque"]) ?><?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="hist-suave">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="exp-seccion">
            <h3>Obligaciones y mensualidades</h3>

            <?php if (!$obligaciones): ?>
                <div class="exp-vacio">Todavía no hay obligaciones de pago generadas para este cliente.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Referencia de cobro</th>
                                <th>Servicio</th>
                                <th>Periodo</th>
                                <th>Concepto</th>
                                <th>Importe</th>
                                <th>Vencimiento</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($obligaciones as $o): ?>
                            <tr>
                                <td><?= exp_h($o["referencia_cobro"]) ?></td>
                                <td>#<?= (int)$o["id_servicio"] ?></td>
                                <td><?= exp_h($o["periodo"] ?: "—") ?></td>
                                <td><?= exp_h($o["concepto"]) ?></td>
                                <td>$<?= number_format((float)$o["monto"], 2) ?></td>
                                <td><?= exp_fecha($o["fecha_vencimiento"]) ?></td>
                                <td>
                                    <span class="exp-badge <?= exp_estado_clase($o["estado"]) ?>">
                                        <?= exp_h($o["estado"]) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    <?php endif; ?>
</div>
</main>
</body>
</html>
