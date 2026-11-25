<?php
require_once __DIR__ . "/conexion.php";

$db = null;

foreach (["conexion", "conn", "mysqli", "con"] as $nombre) {
    if (isset($$nombre) && $$nombre instanceof mysqli) {
        $db = $$nombre;
        break;
    }
}

if (!$db) {
    foreach (get_defined_vars() as $valor) {
        if ($valor instanceof mysqli) {
            $db = $valor;
            break;
        }
    }
}

if (!$db) {
    http_response_code(500);
    exit("No se pudo identificar la conexión MySQL de conexion.php.");
}

$db->set_charset("utf8mb4");

$metodos = [
    "EFECTIVO",
    "TRANSFERENCIA",
    "DEPOSITO",
    "TARJETA",
    "REVISAR"
];

$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";
$metodo = $_GET["metodo"] ?? "";
$pagina = max(1, (int)($_GET["pagina"] ?? 1));
$porPagina = 50;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $desde = "";
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $hasta = "";
}
if (!in_array($metodo, $metodos, true)) {
    $metodo = "";
}

// Aplicación a mensualidades (columnas de la migración 2026-11-28).
$tieneAplicacion = $db->query(
    "SHOW COLUMNS FROM uisp_pagos_importacion LIKE 'estado_aplicacion'"
)->num_rows > 0;
$estadosAplicacion = [
    "APLICADO" => "Aplicado a mensualidades",
    "REVISION" => "En revisión manual",
    "HISTORICO" => "Anterior a las mensualidades (no se aplica)",
    "SIN" => "Sin aplicar todavía",
];
$aplicacion = $_GET["aplicacion"] ?? "";
if (!$tieneAplicacion || !isset($estadosAplicacion[$aplicacion])) {
    $aplicacion = "";
}

$where = ["1=1"];

if ($desde !== "") {
    $where[] = "LEFT(fecha_original_uisp, 10) >= '" .
        $db->real_escape_string($desde) . "'";
}
if ($hasta !== "") {
    $where[] = "LEFT(fecha_original_uisp, 10) <= '" .
        $db->real_escape_string($hasta) . "'";
}
if ($metodo !== "") {
    $where[] = "metodo_normalizado = '" .
        $db->real_escape_string($metodo) . "'";
}

if ($aplicacion === "SIN") {
    $where[] = "uisp_payment_id IS NOT NULL AND estado_aplicacion IS NULL";
} elseif ($aplicacion !== "") {
    $where[] = "estado_aplicacion = '" . $db->real_escape_string($aplicacion) . "'";
}

$filtro = implode(" AND ", $where);

$resumen = $db->query(
    "SELECT COUNT(*) AS cantidad,
            COALESCE(SUM(monto), 0) AS total
     FROM uisp_pagos_importacion
     WHERE $filtro"
);

if (!$resumen) {
    http_response_code(500);
    exit("Error consultando el historial importado.");
}

$datosResumen = $resumen->fetch_assoc();
$totalRegistros = (int)$datosResumen["cantidad"];
$totalImporte = (float)$datosResumen["total"];
$totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$resultado = $db->query(
    "SELECT uisp_client_id, cliente_nombre_uisp, monto, moneda,
            fecha_original_uisp, metodo_original_uisp" .
    ($tieneAplicacion
        ? ", uisp_payment_id, estado_aplicacion, motivo_revision, id_cliente_ubb, id_servicio_ubb"
        : "") . "
     FROM uisp_pagos_importacion
     WHERE $filtro
     ORDER BY fecha_original_uisp DESC
     LIMIT $porPagina OFFSET $offset"
);

if (!$resultado) {
    http_response_code(500);
    exit("Error consultando los movimientos.");
}

function h($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function urlPagina($numero) {
    $parametros = $_GET;
    $parametros["pagina"] = $numero;
    return "?" . http_build_query($parametros);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial UISP | UBB Comunicaciones</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f3f8;color:#29233d;
font-family:Arial,Helvetica,sans-serif}
header{background:#5b2a86;color:#fff;padding:24px 5%}
header h1{margin:0 0 7px;font-size:25px}
header p{margin:0;color:#eee2fa}
main{max-width:1450px;margin:28px auto;padding:0 20px}
.panel{background:#fff;border:1px solid #e5e1ee;border-radius:14px;
padding:22px;margin-bottom:20px;box-shadow:0 4px 15px #36204d0b}
.filtros{display:flex;flex-wrap:wrap;align-items:end;gap:14px}
.campo{display:flex;flex-direction:column;gap:7px}
label{font-size:13px;font-weight:bold}
input,select,button,.limpiar{padding:11px;border:1px solid #d8d0e2;
border-radius:8px;font-size:14px}
button{background:#5b2a86;color:#fff;border:0;font-weight:bold;cursor:pointer}
.limpiar{color:#5b2a86;text-decoration:none}
.tarjetas{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.tarjeta{background:#fff;border:1px solid #e5e1ee;border-radius:12px;padding:20px}
.tarjeta small{color:#706780}
.tarjeta strong{display:block;font-size:26px;color:#5b2a86;margin-top:8px}
.tabla{overflow-x:auto}
table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:13px}
th,td{padding:12px 10px;text-align:left;border-bottom:1px solid #eee9f3}
th{background:#f7f4fa;color:#5b2a86}
td.numero{text-align:right;font-variant-numeric:tabular-nums}
.pie{display:flex;justify-content:space-between;align-items:center;
gap:12px;flex-wrap:wrap;margin-top:18px}
.pie a{color:#5b2a86;text-decoration:none;font-weight:bold}
.nota{color:#706780;font-size:13px;line-height:1.5}
td.aplicacion{white-space:normal;min-width:260px;color:#4d4560}
.etiqueta{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;
font-weight:bold;background:#eee9f3;color:#5b2a86;margin-right:4px}
.etiqueta.ok{background:#e3f4e8;color:#1f7a3d}
.etiqueta.revisar{background:#fdecea;color:#b3261e}
@media(max-width:650px){.tarjetas{grid-template-columns:1fr}
header h1{font-size:21px}.panel{padding:15px}}
</style>
</head>
<body>
<header>
  <h1>UBB COMUNICACIONES</h1>
  <p>Historial de pagos importado desde UISP</p>
</header>
<main>
  <section class="panel">
    <h2>Consultar movimientos</h2>
    <form method="get" class="filtros">
      <div class="campo">
        <label for="desde">Fecha inicial</label>
        <input type="date" id="desde" name="desde" value="<?= h($desde) ?>">
      </div>
      <div class="campo">
        <label for="hasta">Fecha final</label>
        <input type="date" id="hasta" name="hasta" value="<?= h($hasta) ?>">
      </div>
      <div class="campo">
        <label for="metodo">Método de pago</label>
        <select id="metodo" name="metodo">
          <option value="">Todos los métodos</option>
          <?php foreach ($metodos as $opcion): ?>
          <option value="<?= h($opcion) ?>" <?= $metodo === $opcion ? "selected" : "" ?>>
            <?= h($opcion) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($tieneAplicacion): ?>
      <div class="campo">
        <label for="aplicacion">Aplicación a mensualidades</label>
        <select id="aplicacion" name="aplicacion">
          <option value="">Todos</option>
          <?php foreach ($estadosAplicacion as $valor => $texto): ?>
          <option value="<?= h($valor) ?>" <?= $aplicacion === $valor ? "selected" : "" ?>>
            <?= h($texto) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="submit">Aplicar filtros</button>
      <a class="limpiar" href="reporte_uisp.php">Limpiar</a>
    </form>
  </section>

  <section class="tarjetas">
    <div class="tarjeta">
      <small>Movimientos encontrados</small>
      <strong><?= number_format($totalRegistros) ?></strong>
    </div>
    <div class="tarjeta">
      <small>Importe acumulado (MXN)</small>
      <strong>$<?= number_format($totalImporte, 2) ?></strong>
    </div>
  </section>

  <section class="panel" style="margin-top:20px">
    <h2>Movimientos históricos</h2>
    <p class="nota">
      Datos importados desde UISP. Esta consulta no modifica la tabla
      definitiva de pagos ni cambia el estado de los servicios.
    </p>
    <div class="tabla">
      <table>
        <thead>
          <tr>
            <th>ID cliente UISP</th>
            <th>Cliente</th>
            <th>Fecha original</th>
            <th>Método</th>
            <th>Moneda</th>
            <th style="text-align:right">Importe</th>
            <?php if ($tieneAplicacion): ?>
            <th>Aplicación</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php if ($resultado->num_rows === 0): ?>
          <tr><td colspan="<?= $tieneAplicacion ? 7 : 6 ?>">No hay movimientos con esos filtros.</td></tr>
        <?php else: ?>
          <?php while ($fila = $resultado->fetch_assoc()): ?>
          <tr>
            <td><?= h($fila["uisp_client_id"]) ?></td>
            <td><?= h($fila["cliente_nombre_uisp"]) ?></td>
            <td><?= h($fila["fecha_original_uisp"]) ?></td>
            <td><?= h($fila["metodo_original_uisp"]) ?></td>
            <td><?= h($fila["moneda"]) ?></td>
            <td class="numero">$<?= number_format((float)$fila["monto"], 2) ?></td>
            <?php if ($tieneAplicacion): ?>
            <td class="aplicacion">
              <?php if ($fila["estado_aplicacion"] === "APLICADO"): ?>
                <span class="etiqueta ok">Aplicado</span>
                cliente <?= h($fila["id_cliente_ubb"]) ?> · servicio <?= h($fila["id_servicio_ubb"]) ?>
              <?php elseif ($fila["estado_aplicacion"] === "REVISION"): ?>
                <span class="etiqueta revisar">Revisar</span>
                <?= h($fila["motivo_revision"]) ?>
              <?php elseif ($fila["estado_aplicacion"] === "HISTORICO"): ?>
                <span class="etiqueta">Histórico</span>
                <?= h($fila["motivo_revision"]) ?>
              <?php elseif ($fila["uisp_payment_id"] !== null): ?>
                <span class="etiqueta">Pendiente</span>
              <?php else: ?>
                <span class="etiqueta">Histórico CSV</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="pie">
      <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
      <span>
        <?php if ($pagina > 1): ?>
          <a href="<?= h(urlPagina($pagina - 1)) ?>">← Anterior</a>
        <?php endif; ?>
        &nbsp;&nbsp;
        <?php if ($pagina < $totalPaginas): ?>
          <a href="<?= h(urlPagina($pagina + 1)) ?>">Siguiente →</a>
        <?php endif; ?>
      </span>
    </div>
  </section>
</main>
</body>
</html>
