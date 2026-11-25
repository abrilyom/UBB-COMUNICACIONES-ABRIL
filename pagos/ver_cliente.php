<?php
declare(strict_types=1);

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
                revisado
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
            $resumenServicios[$sid]["total_pagado"] += (float)$pago["monto"];
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
    <link rel="stylesheet" href="css/pagos.css">

    <style>
        .expediente { display:grid; gap:22px; padding-bottom:35px; }
        .exp-hero {
            display:flex; justify-content:space-between; align-items:center;
            flex-wrap:wrap; gap:16px;
        }
        .exp-hero h2 { color:#432064; margin:7px 0; }
        .exp-hero p { color:#71677d; margin:4px 0; }
        .exp-acciones { display:flex; flex-wrap:wrap; gap:9px; }
        .exp-btn {
            display:inline-flex; align-items:center; justify-content:center;
            padding:10px 15px; border-radius:9px;
            background:#642c91; color:white; text-decoration:none;
            font-weight:600;
        }
        .exp-btn.secundario { background:#f1eafa; color:#54277e; }
        .exp-datos {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:13px;
        }
        .exp-dato {
            background:#fff; border:1px solid #e9e2f0;
            border-radius:12px; padding:15px;
        }
        .exp-dato span { display:block; color:#71677d; font-size:12px; margin-bottom:7px; }
        .exp-dato strong { color:#432064; overflow-wrap:anywhere; }
        .exp-indicadores {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
        }
        .exp-indicador {
            background:#fff; border:1px solid #e9e2f0;
            border-radius:13px; padding:17px;
        }
        .exp-indicador span { display:block; color:#71677d; font-size:13px; }
        .exp-indicador strong { display:block; color:#51277b; font-size:24px; margin-top:8px; }
        .exp-seccion {
            background:#fff; border:1px solid #e9e2f0;
            border-radius:14px; padding:20px; min-width:0;
        }
        .exp-seccion h3 { color:#432064; margin:0 0 16px; }
        .exp-tabla-scroll { overflow-x:auto; }
        .exp-tabla { width:100%; border-collapse:collapse; min-width:760px; }
        .exp-tabla th,.exp-tabla td {
            padding:12px 10px; border-bottom:1px solid #eee8f3;
            text-align:left; font-size:13px; vertical-align:top;
        }
        .exp-tabla th { background:#f8f5fc; color:#51416c; }
        .exp-tabla small { display:block; color:#77718b; margin-top:4px; }
        .exp-badge {
            display:inline-block; padding:5px 9px; border-radius:20px;
            font-size:11px; font-weight:700; white-space:nowrap;
        }
        .exp-badge.activo,.exp-badge.pagado { background:#e2f5e9; color:#247044; }
        .exp-badge.pendiente { background:#fff2d7; color:#946000; }
        .exp-badge.suspendido,.exp-badge.baja,.exp-badge.cancelado {
            background:#fce8ed; color:#a52c56;
        }
        .exp-badge.inactivo,.exp-badge.neutral { background:#eeeef2; color:#666; }
        .exp-vacio { padding:24px; text-align:center; color:#77718b; background:#f8f5fc; border-radius:10px; }
        .exp-nota {
            padding:13px 15px; border-radius:10px; background:#f8f5fc;
            color:#55476b; font-size:13px; line-height:1.6; margin-bottom:15px;
        }
        .exp-enlace { color:#60358f; font-weight:600; }
        @media(max-width:900px) {
            .exp-datos,.exp-indicadores { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media(max-width:520px) {
            .exp-datos,.exp-indicadores { grid-template-columns:1fr 1fr; gap:8px; }
            .exp-dato,.exp-indicador { padding:12px; }
            .exp-indicador strong { font-size:20px; }
            .exp-seccion { padding:14px; }
        }
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor">
<div class="expediente">

    <?php if ($error): ?>
        <section class="panel">
            <h2>No se pudo cargar el expediente</h2>
            <p><?= exp_h($error) ?></p>
            <a class="exp-btn secundario" href="clientes.php">Volver a clientes</a>
        </section>
    <?php elseif ($cliente): ?>

        <section class="panel">
            <div class="exp-hero">
                <div>
                    <span class="etiqueta-seccion">EXPEDIENTE INDIVIDUAL</span>
                    <h2><?= exp_h($nombreCompleto) ?></h2>
                    <p>
                        Cliente #<?= (int)$cliente["id_cliente"] ?>
                        · Código <?= exp_h($cliente["codigo_cliente"] ?: "Sin código") ?>
                    </p>
                    <span class="exp-badge <?= exp_estado_clase($cliente["estado"]) ?>">
                        <?= exp_h($cliente["estado"]) ?>
                    </span>
                </div>
                <div class="exp-acciones">
                    <a class="exp-btn secundario" href="clientes.php">← Volver a clientes</a>
                    <a class="exp-btn" href="registrar.php">Registrar pago</a>
                </div>
            </div>

            <div class="exp-datos">
                <div class="exp-dato">
                    <span>Teléfono</span>
                    <strong><?= exp_h($cliente["telefono"] ?: "No registrado") ?></strong>
                </div>
                <div class="exp-dato">
                    <span>Correo electrónico</span>
                    <strong><?= exp_h($cliente["correo"] ?: "No registrado") ?></strong>
                </div>
                <div class="exp-dato">
                    <span>Identificador UISP</span>
                    <strong><?= exp_h($cliente["uisp_id"] ?: "Sin vincular") ?></strong>
                </div>
                <div class="exp-dato">
                    <span>Servicios contratados</span>
                    <strong><?= count($servicios) ?></strong>
                </div>
            </div>

            <?php if ((int)$cliente["datos_incompletos"] === 1): ?>
                <div class="exp-nota">Este cliente tiene datos marcados como incompletos en la base de servicios.</div>
            <?php endif; ?>

            <?php if ((int)$cliente["uisp_archivado"] === 1): ?>
                <div class="exp-nota">El registro del cliente aparece archivado en UISP.</div>
            <?php endif; ?>
        </section>

        <section class="exp-indicadores">
            <div class="exp-indicador">
                <span>Pagos registrados UBB</span>
                <strong><?= (int)$resumen["total"] ?></strong>
            </div>
            <div class="exp-indicador">
                <span>Pagos confirmados UBB</span>
                <strong><?= (int)$resumen["pagados"] ?></strong>
            </div>
            <div class="exp-indicador">
                <span>Total pagado en UBB</span>
                <strong>$<?= number_format((float)$resumen["total_pagado"], 2) ?></strong>
            </div>
            <div class="exp-indicador">
                <span>Pagos históricos UISP</span>
                <strong><?= (int)$totalUisp ?></strong>
            </div>
        </section>

        <section class="exp-seccion">
            <h3>Servicios contratados</h3>

            <?php if (!$servicios): ?>
                <div class="exp-vacio">Este cliente no tiene servicios registrados.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Tecnología</th>
                                <th>Paquete</th>
                                <th>Estado</th>
                                <th>Contratación</th>
                                <th>Instalación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$s["id_servicio"] ?></strong>
                                    <small><?= exp_h($s["codigo_servicio"]) ?></small>
                                </td>
                                <td><?= exp_h($s["tecnologia"]) ?></td>
                                <td><?= exp_h($s["paquete_referencia"] ?: "Sin paquete") ?></td>
                                <td>
                                    <span class="exp-badge <?= exp_estado_clase($s["estado"]) ?>">
                                        <?= exp_h($s["estado"]) ?>
                                    </span>
                                </td>
                                <td><?= exp_fecha($s["fecha_contratacion"]) ?></td>
                                <td><?= exp_fecha($s["fecha_instalacion"]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="exp-seccion">
            <h3>Resumen financiero por servicio</h3>
            <div class="exp-nota">
                Los importes se calculan con pagos y obligaciones UBB asociados
                directamente al servicio. Los registros históricos de UISP
                permanecen separados porque no contienen una relación verificable
                con cada servicio.
            </div>

            <?php if (!$servicios): ?>
                <div class="exp-vacio">No hay servicios para resumir.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Servicio</th>
                                <th>Pagos confirmados</th>
                                <th>Total pagado UBB</th>
                                <th>Pagos pendientes</th>
                                <th>Obligaciones pendientes</th>
                                <th>Importe por obligaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($servicios as $s): ?>
                            <?php $fin = $resumenServicios[(int)$s["id_servicio"]]; ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$s["id_servicio"] ?></strong>
                                    <small><?= exp_h($s["codigo_servicio"]) ?></small>
                                </td>
                                <td><?= (int)$fin["pagos_confirmados"] ?></td>
                                <td><strong>$<?= number_format($fin["total_pagado"], 2) ?></strong></td>
                                <td>
                                    <?= (int)$fin["pagos_pendientes"] ?>
                                    <small>$<?= number_format($fin["monto_pagos_pendientes"], 2) ?></small>
                                </td>
                                <td><?= (int)$fin["obligaciones_pendientes"] ?></td>
                                <td><strong>$<?= number_format($fin["monto_obligaciones_pendientes"], 2) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="exp-seccion">
            <h3>Historial de pagos registrados en UBB</h3>
            <div class="exp-nota">
                Estos son los pagos registrados directamente en la plataforma UBB.
                El total de esta sección no incluye el histórico importado de UISP.
            </div>

            <?php if (!$pagos): ?>
                <div class="exp-vacio">Este cliente todavía no tiene pagos registrados en UBB.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Servicio</th>
                                <th>Fecha</th>
                                <th>Importe</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th>Referencia / comprobante</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pagos as $p): ?>
                            <tr>
                                <td>PAG-<?= str_pad((string)$p["id_pago"], 6, "0", STR_PAD_LEFT) ?></td>
                                <td><?= $p["id_servicio"] === null ? "Sin asociar" : "#" . (int)$p["id_servicio"] ?></td>
                                <td><?= exp_h($p["fecha_pago"]) ?></td>
                                <td><strong>$<?= number_format((float)$p["monto"], 2) ?></strong></td>
                                <td><?= exp_h($p["metodo_pago"]) ?></td>
                                <td>
                                    <span class="exp-badge <?= exp_estado_clase($p["estado_pago"]) ?>">
                                        <?= exp_h($p["estado_pago"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= exp_h($p["referencia"] ?: "—") ?>
                                    <?php if (!empty($p["comprobante"])): ?>
                                        <small>
                                            <a class="exp-enlace"
                                               href="<?= exp_h($p["comprobante"]) ?>"
                                               target="_blank" rel="noopener">
                                                Ver comprobante
                                            </a>
                                        </small>
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
            <h3>Historial histórico importado de UISP</h3>
            <div class="exp-nota">
                Registros procedentes de UISP, vinculados mediante el identificador
                original del cliente. Se muestran por separado para evitar duplicar
                importes con los pagos registrados en UBB.
                <br>
                Total histórico de registros: <strong><?= (int)$totalUisp ?></strong>
                · Importe de origen: <strong>$<?= number_format($montoUisp, 2) ?></strong>
            </div>

            <?php if (!$cliente["uisp_id"]): ?>
                <div class="exp-vacio">Este cliente no tiene un identificador UISP vinculado.</div>
            <?php elseif (!$uispPagos): ?>
                <div class="exp-vacio">No se encontraron registros históricos de UISP para este identificador.</div>
            <?php else: ?>
                <div class="exp-tabla-scroll">
                    <table class="exp-tabla">
                        <thead>
                            <tr>
                                <th>Registro</th>
                                <th>Fecha original</th>
                                <th>Importe</th>
                                <th>Método original</th>
                                <th>Método normalizado</th>
                                <th>Organización</th>
                                <th>Revisión</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uispPagos as $u): ?>
                            <tr>
                                <td>#<?= (int)$u["id_importacion"] ?></td>
                                <td><?= exp_h($u["fecha_original_uisp"]) ?></td>
                                <td>
                                    <strong>$<?= number_format((float)$u["monto"], 2) ?></strong>
                                    <small><?= exp_h($u["moneda"]) ?></small>
                                </td>
                                <td><?= exp_h($u["metodo_original_uisp"] ?: "No especificado") ?></td>
                                <td><?= exp_h($u["metodo_normalizado"] ?: "Sin normalizar") ?></td>
                                <td><?= exp_h($u["organizacion"] ?: "—") ?></td>
                                <td><?= (int)$u["revisado"] === 1 ? "Revisado" : "Sin revisar" ?></td>
                            </tr>
                            <?php if ($u["nota"] || $u["numero_cheque"]): ?>
                                <tr>
                                    <td colspan="7">
                                        <small>
                                            <?php if ($u["nota"]): ?>
                                                Nota: <?= exp_h($u["nota"]) ?>
                                            <?php endif; ?>
                                            <?php if ($u["numero_cheque"]): ?>
                                                · Cheque: <?= exp_h($u["numero_cheque"]) ?>
                                            <?php endif; ?>
                                        </small>
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
