<?php
require_once __DIR__ . "/includes/sesion.php";
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
    "SOBRANTE" => ["Sobrante por confirmar", "El pago ya cubrió lo que debía; sobró dinero y UISP lo dejó a favor. ¿Se abona a su siguiente mensualidad?"],
    "DIFERENCIA" => ["Diferencia con UISP", "Pagó más de lo que debe en UBB, pero UISP no lo dejó a favor: UISP cobró otro precio, un cargo extra o un mes que UBB no tiene."],
    "SIN_MENSUALIDADES" => ["Cliente sin mensualidades", "El cliente no tiene mensualidades en UBB (por ejemplo, cliente nuevo)."],
    "METODO" => ["Método no reconocido", "Personalizado, cheque u otro método que no se sabe si es dinero."],
    "FACTURA_PLATAFORMA" => ["Se factura en la plataforma", "El cliente se factura en la plataforma de Jamileth: su pago debe registrarse en UBB con su factura (FAC-…), no en UISP."],
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
        "FACTURA_PLATAFORMA" => "se factura en la plataforma",
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
function decisionesPermitidas(string $categoria, string $estado = "REVISION"): array
{
    // Pago ya aplicado con sobrante: solo se decide si el sobrante se abona.
    if ($estado === "APLICADO") {
        return ["SALDO_FAVOR"];
    }

    return in_array($categoria, ["SOBRANTE", "DIFERENCIA"], true)
        ? ["SALDO_FAVOR", "HISTORICO"]
        : ["HISTORICO"];
}

$mensaje = null;
$error = null;

$tieneDecision = $conexion->query(
    "SHOW COLUMNS FROM uisp_pagos_importacion LIKE 'decision_revision'"
)->num_rows > 0;

// En revisión: los que no se aplicaron, y los aplicados cuyo sobrante falta decidir.
$enRevision = "(estado_aplicacion = 'REVISION' OR (estado_aplicacion = 'APLICADO' AND motivo_revision LIKE 'Sobrante de%'"
    . ($tieneDecision ? " AND decision_revision IS NULL" : "") . "))";

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
            SELECT motivo_revision, estado_aplicacion FROM uisp_pagos_importacion
            WHERE id_importacion = ? AND $enRevision
        ");
        $stmt->bind_param("i", $idImportacion);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fila) {
            $error = "Ese pago ya no está en revisión.";
        } elseif ($accion !== "QUITAR"
            && !in_array($accion, decisionesPermitidas(categoriaRevision((string)$fila["motivo_revision"]), (string)$fila["estado_aplicacion"]), true)) {
            $error = "Esa decisión no aplica para este pago.";
        } else {
            $decision = $accion === "QUITAR" ? null : $accion;
            $stmt = $conexion->prepare("
                UPDATE uisp_pagos_importacion
                SET decision_revision = ?, decidido_en = IF(? IS NULL, NULL, NOW())
                WHERE id_importacion = ? AND $enRevision
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
           id_cliente_ubb, id_servicio_ubb, motivo_revision, aplicado_en, estado_aplicacion
           $columnasDecision
    FROM uisp_pagos_importacion
    WHERE $enRevision
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
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
    <style>
        /* Avisos de resultado */
        .rv-aviso{padding:13px 18px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600}
        .rv-aviso.ok{background:var(--cc-ok-fondo);color:var(--cc-ok)}
        .rv-aviso.err{background:var(--cc-error-fondo);color:var(--cc-error)}

        /* Categorías: tarjetas que filtran la tabla */
        .rv-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;margin-bottom:18px}
        .rv-cat{position:relative;display:flex;flex-direction:column;gap:4px;padding:16px 18px;overflow:hidden;
            border:1px solid var(--cc-borde);border-radius:16px;background:#fff;box-shadow:var(--cc-sombra);
            color:var(--cc-texto);text-decoration:none;transition:transform .15s ease,box-shadow .15s ease}
        .rv-cat::before{content:"";position:absolute;inset:0 0 auto 0;height:4px;background:#dcc6e2}
        .rv-cat:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(113,58,124,.12)}
        .rv-cat strong{font-size:30px;font-weight:800;line-height:1.1;color:var(--cc-texto)}
        .rv-cat span{font-size:13px;font-weight:700}
        .rv-cat small{color:var(--cc-texto-suave);font-size:12.5px}
        .rv-cat.activa{border-color:#d9bde0;background:linear-gradient(160deg,#f8effb 0%,#f8effb 60%,#fff 140%)}
        .rv-cat.activa::before{background:linear-gradient(90deg,#a96db5,#8a4a98)}
        .rv-cat.activa strong{color:var(--cc-morado)}

        /* "N por decidir" a la derecha del título */
        .rv-page .cc-cabecera{flex-wrap:nowrap}
        .rv-page .cc-cabecera-texto{flex:1 1 auto}
        .rv-page .cc-cabecera .cc-pildora{flex:0 0 auto}
        @media (max-width:900px){.rv-page .cc-cabecera{flex-wrap:wrap}}

        .rv-descripcion{margin:-4px 0 18px;color:var(--cc-texto-suave);font-size:13.5px;line-height:1.5}

        /* Tabla */
        .rv-page .hist-tabla td{vertical-align:top}
        .rv-page .hist-tabla td small{display:block;margin-top:3px;color:var(--cc-texto-suave);font-size:12px}
        .rv-page .hist-tabla td small a{color:var(--cc-morado);font-weight:700}
        .rv-motivo{max-width:420px;color:var(--cc-texto-suave);line-height:1.45}
        .rv-motivo strong{display:block;margin-bottom:2px;color:var(--cc-texto)}
        .rv-acciones form{display:inline-block;margin:0 6px 6px 0}
        .rv-acciones button{cursor:pointer;font-family:inherit}
        .rv-decision{display:inline-block;margin-bottom:6px;padding:5px 11px;border-radius:999px;font-size:11px;font-weight:800;
            background:var(--cc-atencion-fondo);color:var(--cc-atencion)}
        .rv-vacio{padding:36px;text-align:center;color:var(--cc-texto-suave)}
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist rv-page">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8.5 12 2.5 2.5 4.5-5"/></svg>
            </span>
            <div>
                <h1>Revisión de pagos UISP</h1>
                <p>
                    Pagos registrados en UISP que no se aplicaron solos porque había dudas.
                    Aquí solo se guarda la decisión; el proceso automático la aplica en máximo 15 minutos
                    con las reglas de siempre.
                </p>
            </div>
        </div>
        <span class="cc-pildora <?= $porDecidir > 0 ? "cc-pildora--atencion" : "cc-pildora--ok" ?>">
            <span class="cc-punto"></span><?= (int)$porDecidir ?> por decidir
        </span>
    </header>

    <?php if ($mensaje): ?><div class="rv-aviso ok"><?= h($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="rv-aviso err"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$tieneDecision): ?>
        <div class="rv-aviso err">Falta aplicar la migración 2026-12-02_revision_uisp.sql para poder registrar decisiones.</div>
    <?php endif; ?>


    <!-- =================================================
         CATEGORÍAS (filtran la tabla)
    ================================================== -->

    <section class="rv-cats">
        <a class="rv-cat <?= $filtro === "" ? "activa" : "" ?>" href="revision_uisp.php">
            <span>Todos</span>
            <strong><?= count($pagos) ?></strong>
            <small>Pagos en revisión</small>
        </a>
        <?php foreach (CATEGORIAS_REVISION as $clave => [$titulo, $descripcion]): ?>
            <?php if ($conteo[$clave] === 0) continue; ?>
            <a class="rv-cat <?= $filtro === $clave ? "activa" : "" ?>" href="?categoria=<?= h($clave) ?>"
               title="<?= h($descripcion) ?>">
                <span><?= h($titulo) ?></span>
                <strong><?= $conteo[$clave] ?></strong>
                <small>$<?= number_format($importe[$clave], 2) ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if ($filtro !== "" && CATEGORIAS_REVISION[$filtro][1] !== ""): ?>
        <p class="rv-descripcion"><?= h(CATEGORIAS_REVISION[$filtro][1]) ?></p>
    <?php endif; ?>


    <!-- =================================================
         TABLA
    ================================================== -->

    <section class="cc-panel hist-tabla">
    <?php if (!$visibles): ?>
        <div class="rv-vacio">No hay pagos en revisión<?= $filtro !== "" ? " en esta categoría" : "" ?>.</div>
    <?php else: ?>
        <div class="hist-tabla-scroll">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th class="mens-col-cliente">Cliente</th>
                    <th class="hist-num">Monto</th>
                    <th>Método</th>
                    <th>Motivo</th>
                    <th>Decisión</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($visibles as $p): ?>
                <tr>
                    <td class="hist-fecha">
                        <?= h(substr((string)$p["fecha_original_uisp"], 0, 10)) ?>
                        <span><?= h(substr((string)$p["fecha_original_uisp"], 11, 5)) ?></span>
                        <small>Pago UISP <?= (int)$p["uisp_payment_id"] ?></small>
                    </td>
                    <td class="mens-col-cliente">
                        <strong><?= h($p["cliente_nombre_uisp"]) ?></strong>
                        <small>UISP <?= h($p["uisp_client_id"]) ?>
                        <?php if ($p["id_cliente_ubb"]): ?>
                            · <a href="ver_cliente.php?id=<?= (int)$p["id_cliente_ubb"] ?>">UBB <?= (int)$p["id_cliente_ubb"] ?></a>
                        <?php endif; ?></small>
                    </td>
                    <td class="hist-num"><strong>$<?= number_format((float)$p["monto"], 2) ?></strong>
                        <?php if ((float)$p["credito_uisp"] > 0): ?>
                            <small>UISP a favor: $<?= number_format((float)$p["credito_uisp"], 2) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="hist-pildora hist-pildora--metodo"><?= h($p["metodo_original_uisp"]) ?></span></td>
                    <td class="rv-motivo">
                        <strong><?= h(CATEGORIAS_REVISION[$p["categoria"]][0]) ?></strong>
                        <?= h($p["motivo_revision"]) ?>
                    </td>
                    <td class="rv-acciones">
                        <?php if (!puedeModificar()): ?>
                            <span class="rv-decision">
                                <?php if (!empty($p["decision_revision"])): ?>
                                    <?= $p["decision_revision"] === "SALDO_FAVOR" ? "Saldo a favor" : "Histórico" ?>
                                <?php else: ?>
                                    Solo consulta
                                <?php endif; ?>
                            </span>
                        <?php elseif (!empty($p["decision_revision"])): ?>
                            <span class="rv-decision">
                                <?= $p["decision_revision"] === "SALDO_FAVOR" ? "Saldo a favor" : "Histórico" ?>
                                · se aplica en la siguiente ejecución
                            </span>
                            <form method="post">
                                <input type="hidden" name="id_importacion" value="<?= (int)$p["id_importacion"] ?>">
                                <button name="accion" value="QUITAR" class="hist-boton">Quitar decisión</button>
                            </form>
                        <?php elseif ($tieneDecision): ?>
                            <?php foreach (decisionesPermitidas($p["categoria"], (string)$p["estado_aplicacion"]) as $d): ?>
                                <form method="post"
                                      onsubmit="return confirm('<?= $d === "SALDO_FAVOR"
                                          ? "¿Abonar este pago a lo que debe y dejar el sobrante como saldo a favor para su siguiente mensualidad?"
                                          : "¿Marcar este pago como histórico? No se aplicará a ninguna mensualidad." ?>');">
                                    <input type="hidden" name="id_importacion" value="<?= (int)$p["id_importacion"] ?>">
                                    <button name="accion" value="<?= h($d) ?>" class="hist-boton <?= $d === "SALDO_FAVOR" ? "hist-boton--principal" : "" ?>">
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
    </section>

</main>

</body>
</html>
