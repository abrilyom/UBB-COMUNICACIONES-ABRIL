<?php
/*
 * REVISIÓN DE PAGOS UISP
 *
 * Lista los pagos de UISP que el proceso automático no aplicó porque había
 * dudas, agrupados por motivo. El personal registra una decisión por pago:
 *   - Abonar como saldo a favor: cubre lo que debe y el sobrante pasa a la
 *     siguiente mensualidad.
 *   - Histórico: no se aplica (p. ej. pago de un mes que no existe en UBB).
 *
 * Esta página NO mueve dinero: solo guarda la decisión. aplicar_pagos_uisp.php
 * la ejecuta en su siguiente corrida (cada 15 minutos) con las mismas reglas y
 * validaciones de siempre, y se puede revertir con deshacer_pagos_uisp.php.
 */
require_once __DIR__ . "/conexion.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");
$conexion->set_charset("utf8mb4");

function h($valor): string
{
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

/** Categoría a partir del motivo que escribe aplicar_pagos_uisp.php. */
const CATEGORIAS_REVISION = [
    "SOBRANTE" => ["Sobrante por confirmar", "Pagó más de lo que debe y UISP lo dejó a favor. ¿Se abona a su siguiente mensualidad?"],
    "DIFERENCIA" => ["Diferencia con UISP", "Pagó más de lo que debe en UBB, pero UISP no lo dejó a favor: UISP cobró otro precio, un cargo extra o un mes que UBB no tiene."],
    "SIN_MENSUALIDADES" => ["Cliente sin mensualidades", "El cliente no tiene mensualidades en UBB (por ejemplo, cliente nuevo)."],
    "METODO" => ["Método no reconocido", "Personalizado, cheque u otro método que no se sabe si es dinero."],
    "SIN_LIGAR" => ["Cliente no ligado", "El cliente de UISP no está ligado (o está ligado a varios) en la plataforma."],
    "VARIOS_SERVICIOS" => ["Varios servicios", "No se sabe a cuál servicio corresponde el pago."],
    "ADELANTADO" => ["Pago muy adelantado", "Llegó más de 20 días antes del vencimiento; puede ser de otro periodo."],
    "DUPLICADO" => ["Posible duplicado", "Ya hay un pago parecido registrado en UBB."],
    "OTRO" => ["Otros", ""],
];

function categoriaRevision(string $motivo): string
{
    $reglas = [
        "SOBRANTE" => "Sobrante de",
        "DIFERENCIA" => "UISP solo registró",
        "SIN_MENSUALIDADES" => "no tiene mensualidades registradas",
        "METODO" => "Método \"",
        "SIN_LIGAR" => ["no está ligado", "ligado a varios clientes"],
        "VARIOS_SERVICIOS" => "varios servicios",
        "ADELANTADO" => "días antes del vencimiento",
        "DUPLICADO" => ["pago pendiente en UBB", "pago igual registrado"],
    ];

    foreach ($reglas as $categoria => $textos) {
        foreach ((array)$textos as $texto) {
            if (mb_stripos($motivo, $texto) !== false) {
                return $categoria;
            }
        }
    }

    return "OTRO";
}

/** Decisiones que se pueden tomar según la categoría. */
function decisionesPermitidas(string $categoria): array
{
    return in_array($categoria, ["SOBRANTE", "DIFERENCIA"], true)
        ? ["SALDO_FAVOR", "HISTORICO"]
        : ["HISTORICO"];
}

$mensaje = null;
$error = null;

$tieneDecision = $conexion->query(
    "SHOW COLUMNS FROM uisp_pagos_importacion LIKE 'decision_revision'"
)->num_rows > 0;

/* ===== Guardar una decisión ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Solo formularios enviados desde esta misma plataforma.
    $origen = parse_url($_SERVER["HTTP_ORIGIN"] ?? $_SERVER["HTTP_REFERER"] ?? "", PHP_URL_HOST);
    $propio = parse_url("http://" . ($_SERVER["HTTP_HOST"] ?? ""), PHP_URL_HOST);

    $idImportacion = filter_var($_POST["id_importacion"] ?? null, FILTER_VALIDATE_INT);
    $accion = $_POST["accion"] ?? "";

    if (!$tieneDecision) {
        $error = "Falta aplicar la migración 2026-12-02_revision_uisp.sql.";
    } elseif (!$origen || $origen !== $propio) {
        $error = "Solicitud no válida.";
    } elseif (!$idImportacion || !in_array($accion, ["SALDO_FAVOR", "HISTORICO", "QUITAR"], true)) {
        $error = "Datos no válidos.";
    } else {
        $stmt = $conexion->prepare("
            SELECT motivo_revision FROM uisp_pagos_importacion
            WHERE id_importacion = ? AND estado_aplicacion = 'REVISION'
        ");
        $stmt->bind_param("i", $idImportacion);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fila) {
            $error = "Ese pago ya no está en revisión.";
        } elseif ($accion !== "QUITAR"
            && !in_array($accion, decisionesPermitidas(categoriaRevision((string)$fila["motivo_revision"])), true)) {
            $error = "Esa decisión no aplica para este pago.";
        } else {
            $decision = $accion === "QUITAR" ? null : $accion;
            $stmt = $conexion->prepare("
                UPDATE uisp_pagos_importacion
                SET decision_revision = ?, decidido_en = IF(? IS NULL, NULL, NOW())
                WHERE id_importacion = ? AND estado_aplicacion = 'REVISION'
            ");
            $stmt->bind_param("ssi", $decision, $decision, $idImportacion);
            $stmt->execute();
            $stmt->close();

            $mensaje = $decision === null
                ? "Decisión quitada."
                : "Decisión guardada. Se aplicará en la siguiente ejecución automática (máximo 15 minutos).";
        }
    }
}

/* ===== Consultar los pagos en revisión ===== */
$filtro = $_GET["categoria"] ?? "";
if (!isset(CATEGORIAS_REVISION[$filtro])) {
    $filtro = "";
}

$columnasDecision = $tieneDecision ? ", decision_revision, decidido_en" : "";
$pagos = $conexion->query("
    SELECT id_importacion, uisp_payment_id, uisp_client_id, cliente_nombre_uisp,
           monto, credito_uisp, fecha_original_uisp, metodo_original_uisp,
           id_cliente_ubb, id_servicio_ubb, motivo_revision, aplicado_en
           $columnasDecision
    FROM uisp_pagos_importacion
    WHERE estado_aplicacion = 'REVISION'
    ORDER BY fecha_original_uisp DESC
")->fetch_all(MYSQLI_ASSOC);

$conteo = array_fill_keys(array_keys(CATEGORIAS_REVISION), 0);
$importe = array_fill_keys(array_keys(CATEGORIAS_REVISION), 0.0);
$porDecidir = 0;

foreach ($pagos as &$p) {
    $p["categoria"] = categoriaRevision((string)$p["motivo_revision"]);
    $conteo[$p["categoria"]]++;
    $importe[$p["categoria"]] += (float)$p["monto"];
    if (empty($p["decision_revision"])) {
        $porDecidir++;
    }
}
unset($p);

$visibles = $filtro === ""
    ? $pagos
    : array_values(array_filter($pagos, fn($p) => $p["categoria"] === $filtro));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revisión de pagos UISP | UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css">
    <style>
        .rv-page h1{margin:0 0 6px;color:#3d1d5c}
        .rv-page .rv-sub{margin:0 0 20px;color:#6f6680;line-height:1.5}
        .rv-aviso{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
        .rv-aviso.ok{background:#e3f4e8;color:#1f7a3d}
        .rv-aviso.err{background:#fdecea;color:#b3261e}
        .rv-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:20px}
        .rv-cat{display:block;padding:14px;border:1px solid #e5e1ee;border-radius:12px;background:#fff;text-decoration:none;color:#29233d}
        .rv-cat.activa{border-color:#5b2a86;box-shadow:0 0 0 2px #5b2a8633}
        .rv-cat strong{display:block;font-size:24px;color:#5b2a86}
        .rv-cat span{font-size:13px;font-weight:bold}
        .rv-cat small{display:block;color:#706780;margin-top:4px}
        .rv-tabla{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:13px}
        .rv-tabla th,.rv-tabla td{padding:10px;border-bottom:1px solid #eee9f3;text-align:left;vertical-align:top}
        .rv-tabla th{background:#f7f4fa;color:#5b2a86}
        .rv-tabla td.num{text-align:right;white-space:nowrap}
        .rv-motivo{color:#4d4560;max-width:420px}
        .rv-acciones form{display:inline-block;margin:0 4px 4px 0}
        .rv-acciones button{padding:6px 10px;border-radius:8px;border:1px solid #d8d0e2;background:#fff;cursor:pointer;font-size:12px}
        .rv-acciones button.favor{background:#5b2a86;color:#fff;border-color:#5b2a86}
        .rv-decision{display:inline-block;padding:3px 8px;border-radius:10px;font-size:11px;font-weight:bold;background:#fff4d6;color:#8a5a00}
        .rv-vacio{padding:30px;text-align:center;color:#706780;background:#fff;border-radius:12px}
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor rv-page">
    <h1>Revisión de pagos UISP</h1>
    <p class="rv-sub">
        Pagos registrados en UISP que no se aplicaron solos porque había dudas.
        Aquí solo se guarda la decisión; el proceso automático la aplica en máximo 15 minutos
        con las reglas de siempre. <strong><?= (int)$porDecidir ?></strong> por decidir.
    </p>

    <?php if ($mensaje): ?><div class="rv-aviso ok"><?= h($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="rv-aviso err"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$tieneDecision): ?>
        <div class="rv-aviso err">Falta aplicar la migración 2026-12-02_revision_uisp.sql para poder registrar decisiones.</div>
    <?php endif; ?>

    <section class="rv-cats">
        <a class="rv-cat <?= $filtro === "" ? "activa" : "" ?>" href="revision_uisp.php">
            <strong><?= count($pagos) ?></strong>
            <span>Todos</span>
        </a>
        <?php foreach (CATEGORIAS_REVISION as $clave => [$titulo, $descripcion]): ?>
            <?php if ($conteo[$clave] === 0) continue; ?>
            <a class="rv-cat <?= $filtro === $clave ? "activa" : "" ?>" href="?categoria=<?= h($clave) ?>"
               title="<?= h($descripcion) ?>">
                <strong><?= $conteo[$clave] ?></strong>
                <span><?= h($titulo) ?></span>
                <small>$<?= number_format($importe[$clave], 2) ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if ($filtro !== "" && CATEGORIAS_REVISION[$filtro][1] !== ""): ?>
        <p class="rv-sub"><?= h(CATEGORIAS_REVISION[$filtro][1]) ?></p>
    <?php endif; ?>

    <?php if (!$visibles): ?>
        <div class="rv-vacio">No hay pagos en revisión<?= $filtro !== "" ? " en esta categoría" : "" ?>.</div>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table class="rv-tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th style="text-align:right">Monto</th>
                    <th>Método</th>
                    <th>Motivo</th>
                    <th>Decisión</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($visibles as $p): ?>
                <tr>
                    <td><?= h(substr((string)$p["fecha_original_uisp"], 0, 16)) ?><br>
                        <small>Pago UISP <?= (int)$p["uisp_payment_id"] ?></small></td>
                    <td>
                        <?= h($p["cliente_nombre_uisp"]) ?><br>
                        <small>UISP <?= h($p["uisp_client_id"]) ?>
                        <?php if ($p["id_cliente_ubb"]): ?>
                            · <a href="ver_cliente.php?id=<?= (int)$p["id_cliente_ubb"] ?>">UBB <?= (int)$p["id_cliente_ubb"] ?></a>
                        <?php endif; ?></small>
                    </td>
                    <td class="num">$<?= number_format((float)$p["monto"], 2) ?>
                        <?php if ((float)$p["credito_uisp"] > 0): ?>
                            <br><small>UISP a favor: $<?= number_format((float)$p["credito_uisp"], 2) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= h($p["metodo_original_uisp"]) ?></td>
                    <td class="rv-motivo">
                        <strong><?= h(CATEGORIAS_REVISION[$p["categoria"]][0]) ?></strong><br>
                        <?= h($p["motivo_revision"]) ?>
                    </td>
                    <td class="rv-acciones">
                        <?php if (!empty($p["decision_revision"])): ?>
                            <span class="rv-decision">
                                <?= $p["decision_revision"] === "SALDO_FAVOR" ? "Saldo a favor" : "Histórico" ?>
                                · se aplica en la siguiente ejecución
                            </span>
                            <form method="post">
                                <input type="hidden" name="id_importacion" value="<?= (int)$p["id_importacion"] ?>">
                                <button name="accion" value="QUITAR">Quitar decisión</button>
                            </form>
                        <?php elseif ($tieneDecision): ?>
                            <?php foreach (decisionesPermitidas($p["categoria"]) as $d): ?>
                                <form method="post"
                                      onsubmit="return confirm('<?= $d === "SALDO_FAVOR"
                                          ? "¿Abonar este pago a lo que debe y dejar el sobrante como saldo a favor para su siguiente mensualidad?"
                                          : "¿Marcar este pago como histórico? No se aplicará a ninguna mensualidad." ?>');">
                                    <input type="hidden" name="id_importacion" value="<?= (int)$p["id_importacion"] ?>">
                                    <button name="accion" value="<?= h($d) ?>" class="<?= $d === "SALDO_FAVOR" ? "favor" : "" ?>">
                                        <?= $d === "SALDO_FAVOR" ? "Abonar como saldo a favor" : "Histórico (no aplica)" ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
