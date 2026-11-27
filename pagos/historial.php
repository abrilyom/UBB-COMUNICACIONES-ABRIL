<?php
require_once __DIR__ . "/includes/sesion.php";
require_once "conexion.php";

/* =========================
   FILTROS
========================= */

$periodo = $_GET["periodo"] ?? "todos";
$metodo = $_GET["metodo"] ?? "todos";
$estado = $_GET["estado"] ?? "todos";
$buscar = trim($_GET["buscar"] ?? "");


/* =========================
   CONDICIONES
========================= */

$condiciones = [];

/* Período */

switch ($periodo) {

    case "hoy":
        $condiciones[] = "DATE(fecha_pago) = CURDATE()";
        break;

    case "semana":
        $condiciones[] = "YEARWEEK(fecha_pago, 1) = YEARWEEK(CURDATE(), 1)";
        break;

    case "mes":
        $condiciones[] = "YEAR(fecha_pago) = YEAR(CURDATE())
                          AND MONTH(fecha_pago) = MONTH(CURDATE())";
        break;

    case "todos":
        break;
}


/* Método */

if ($metodo !== "todos") {
    $metodo_seguro = $conexion->real_escape_string($metodo);
    $condiciones[] = "metodo_pago = '$metodo_seguro'";
}


/* Estado */

if ($estado !== "todos") {
    $estado_seguro = $conexion->real_escape_string($estado);
    $condiciones[] = "estado_pago = '$estado_seguro'";
}


/* Conexión de solo lectura a la plataforma (nombres de clientes).
   Si no responde, la página sigue funcionando con el número de cliente. */

$plataforma = null;

try {
    $plataforma = require __DIR__ . "/conexion_plataforma.php";

    if (!$plataforma instanceof mysqli) {
        $plataforma = null;
    }
} catch (Throwable $e) {
    error_log("UBB historial: sin conexión a la plataforma: " . $e->getMessage());
    $plataforma = null;
}


/* Búsqueda */

if ($buscar !== "") {

    $buscar_seguro = $conexion->real_escape_string($buscar);

    $coincide_cliente = "";

    // Clientes cuyo nombre coincide con lo buscado
    if ($plataforma !== null) {
        try {
            $stmt = $plataforma->prepare(
                "SELECT id_cliente
                 FROM clientes
                 WHERE CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) LIKE ?
                 LIMIT 500"
            );
            $patron = "%" . $buscar . "%";
            $stmt->bind_param("s", $patron);
            $stmt->execute();
            $resultado = $stmt->get_result();

            $ids_por_nombre = [];
            while ($fila = $resultado->fetch_assoc()) {
                $ids_por_nombre[] = (int)$fila["id_cliente"];
            }
            $stmt->close();

            if (count($ids_por_nombre) > 0) {
                $coincide_cliente = " OR id_cliente IN (" . implode(",", $ids_por_nombre) . ")";
            }
        } catch (Throwable $e) {
            error_log("UBB historial: búsqueda por nombre: " . $e->getMessage());
        }
    }

    $condiciones[] = "(
        id_cliente LIKE '%$buscar_seguro%'
        OR referencia LIKE '%$buscar_seguro%'
        $coincide_cliente
    )";
}


/* =========================
   WHERE
========================= */

$where = "";

if (count($condiciones) > 0) {
    $where = "WHERE " . implode(" AND ", $condiciones);
}


/* =========================
   CONSULTA
========================= */

$sql = "SELECT
            id_pago,
            id_cliente,
            id_servicio,
            comprobante,
            referencia,
            fecha_pago,
            monto,
            metodo_pago,
            estado_pago
        FROM pagos
        $where
        ORDER BY fecha_pago DESC";

$consulta = $conexion->query($sql);


/* =========================
   ESTADÍSTICAS
========================= */

$total_movimientos = 0;
$total_recaudado = 0;
$total_pagados = 0;

$pagos = [];

if ($consulta) {

    while ($pago = $consulta->fetch_assoc()) {

        $pagos[] = $pago;

        $total_movimientos++;

        // Los créditos de cortesía son descuentos: cubren la mensualidad, no son dinero recibido.
        if ($pago["estado_pago"] === "PAGADO" && $pago["metodo_pago"] !== "CORTESIA") {
            $total_recaudado += (float)$pago["monto"];
            $total_pagados++;
        }
    }
}


/* =========================
   NOMBRES DE CLIENTES
========================= */

$nombres_clientes = [];

$ids_clientes = array_values(array_unique(array_filter(
    array_map(fn($p) => (int)$p["id_cliente"], $pagos)
)));

if ($plataforma !== null && count($ids_clientes) > 0) {
    try {
        foreach (array_chunk($ids_clientes, 500) as $grupo) {
            $resultado = $plataforma->query(
                "SELECT id_cliente, nombre, apellido_paterno, apellido_materno
                 FROM clientes
                 WHERE id_cliente IN (" . implode(",", $grupo) . ")"
            );

            while ($fila = $resultado->fetch_assoc()) {
                $nombre = trim(implode(" ", array_filter([
                    trim((string)$fila["nombre"]),
                    trim((string)$fila["apellido_paterno"]),
                    trim((string)$fila["apellido_materno"]),
                ], "strlen")));

                if ($nombre !== "") {
                    $nombres_clientes[(int)$fila["id_cliente"]] = $nombre;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("UBB historial: nombres de clientes: " . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Movimientos de pagos - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/historial.css?v=<?php echo filemtime(__DIR__ . '/css/historial.css'); ?>">

</head>


<body>


<!-- =====================================================
     ENCABEZADO
===================================================== -->

<?php require __DIR__ . "/includes/encabezado.php"; ?>



<!-- =====================================================
     CONTENIDO
===================================================== -->

<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
            </span>
            <div>
                <h1>Movimientos de pagos</h1>
                <p>Historial de pagos registrados: busca por cliente o referencia y abre su comprobante</p>
            </div>
        </div>
    </header>


    <!-- =================================================
         FILTROS
    ================================================== -->

    <form method="GET" class="cc-panel hist-filtros">

        <label class="hist-campo">
            <span>Período</span>
            <select name="periodo" class="filtro">
                <option value="hoy" <?php echo $periodo === "hoy" ? "selected" : ""; ?>>Hoy</option>
                <option value="semana" <?php echo $periodo === "semana" ? "selected" : ""; ?>>Esta semana</option>
                <option value="mes" <?php echo $periodo === "mes" ? "selected" : ""; ?>>Este mes</option>
                <option value="todos" <?php echo $periodo === "todos" ? "selected" : ""; ?>>Todos</option>
            </select>
        </label>

        <label class="hist-campo">
            <span>Método</span>
            <select name="metodo" class="filtro">
                <option value="todos">Todos los métodos</option>
                <option value="EFECTIVO" <?php echo $metodo === "EFECTIVO" ? "selected" : ""; ?>>Efectivo</option>
                <option value="TRANSFERENCIA" <?php echo $metodo === "TRANSFERENCIA" ? "selected" : ""; ?>>Transferencia</option>
                <option value="DEPOSITO" <?php echo $metodo === "DEPOSITO" ? "selected" : ""; ?>>Depósito</option>
                <option value="TARJETA" <?php echo $metodo === "TARJETA" ? "selected" : ""; ?>>Tarjeta</option>
            </select>
        </label>

        <label class="hist-campo">
            <span>Estado</span>
            <select name="estado" class="filtro">
                <option value="todos">Todos los estados</option>
                <option value="PAGADO" <?php echo $estado === "PAGADO" ? "selected" : ""; ?>>Pagado</option>
                <option value="PENDIENTE" <?php echo $estado === "PENDIENTE" ? "selected" : ""; ?>>Pendiente</option>
                <option value="CANCELADO" <?php echo $estado === "CANCELADO" ? "selected" : ""; ?>>Cancelado</option>
            </select>
        </label>

        <div class="hist-buscador">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input
                type="text"
                name="buscar"
                value="<?php echo htmlspecialchars($buscar); ?>"
                placeholder="Buscar cliente o referencia..."
                aria-label="Buscar cliente o referencia"
            >
            <button type="submit">Buscar</button>
        </div>

        <?php
        // Filtros activos: se muestran como etiquetas con opción de limpiarlos
        $etiquetas_periodo = ["hoy" => "Hoy", "semana" => "Esta semana", "mes" => "Este mes"];
        $etiquetas_metodo = ["EFECTIVO" => "Efectivo", "TRANSFERENCIA" => "Transferencia", "DEPOSITO" => "Depósito", "TARJETA" => "Tarjeta"];
        $etiquetas_estado = ["PAGADO" => "Pagado", "PENDIENTE" => "Pendiente", "CANCELADO" => "Cancelado"];

        $filtros_activos = [];
        if ($buscar !== "") {
            $filtros_activos[] = "Búsqueda: «" . $buscar . "»";
        }
        if (isset($etiquetas_periodo[$periodo])) {
            $filtros_activos[] = "Período: " . $etiquetas_periodo[$periodo];
        }
        if (isset($etiquetas_metodo[$metodo])) {
            $filtros_activos[] = "Método: " . $etiquetas_metodo[$metodo];
        }
        if (isset($etiquetas_estado[$estado])) {
            $filtros_activos[] = "Estado: " . $etiquetas_estado[$estado];
        }
        ?>

        <?php if (count($filtros_activos) > 0): ?>
            <div class="hist-activos">
                <span class="hist-activos-texto">Mostrando resultados filtrados:</span>
                <?php foreach ($filtros_activos as $filtro_activo): ?>
                    <span class="hist-activos-etiqueta"><?php echo htmlspecialchars($filtro_activo); ?></span>
                <?php endforeach; ?>
                <a class="hist-limpiar" href="historial.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    Limpiar filtros
                </a>
            </div>
        <?php endif; ?>

    </form>


    <!-- =================================================
         RESUMEN
    ================================================== -->

    <div class="cc-kpis hist-kpis">

        <div class="cc-kpi cc-kpi--pagos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg></span>
                <span class="cc-kpi-nombre">Movimientos</span>
            </div>
            <strong class="cc-kpi-numero"><?php echo number_format($total_movimientos); ?></strong>
            <span class="cc-kpi-detalle">Pagos registrados con estos filtros</span>
        </div>

        <div class="cc-kpi cc-kpi--activos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                <span class="cc-kpi-nombre">Recaudado</span>
            </div>
            <strong class="cc-kpi-numero">$<?php echo number_format($total_recaudado, 2); ?></strong>
            <span class="cc-kpi-detalle">Monto de los pagos confirmados</span>
        </div>

        <div class="cc-kpi hist-kpi--confirmados">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                <span class="cc-kpi-nombre">Pagados</span>
            </div>
            <strong class="cc-kpi-numero"><?php echo number_format($total_pagados); ?></strong>
            <span class="cc-kpi-detalle">Pagos confirmados (sin créditos de cortesía)</span>
        </div>

    </div>


    <!-- =================================================
         TABLA
    ================================================== -->

    <section class="cc-panel hist-tabla">

        <div class="hist-tabla-scroll">

            <table>

                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Servicio</th>
                        <th>Fecha</th>
                        <th>Método</th>
                        <th>Referencia</th>
                        <th class="hist-num">Monto</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($pagos) > 0): ?>

                    <?php foreach ($pagos as $pago): ?>

                        <tr>

                            <td class="hist-cliente">
                                <?php $nombre_cliente = $nombres_clientes[(int)$pago["id_cliente"]] ?? null; ?>
                                <?php if ($nombre_cliente !== null): ?>
                                    <strong><?php echo htmlspecialchars($nombre_cliente); ?></strong>
                                    <span>Cliente #<?php echo (int)$pago["id_cliente"]; ?></span>
                                <?php else: ?>
                                    <strong>Cliente #<?php echo htmlspecialchars($pago["id_cliente"]); ?></strong>
                                <?php endif; ?>
                            </td>

                            <td class="hist-servicio">
                                <?php
                                echo $pago["id_servicio"] !== null
                                    ? "Servicio #" . (int)$pago["id_servicio"]
                                    : '<span class="hist-suave">Sin servicio</span>';
                                ?>
                            </td>

                            <td class="hist-fecha">
                                <?php echo date("d/m/Y", strtotime($pago["fecha_pago"])); ?>
                                <span><?php echo date("H:i", strtotime($pago["fecha_pago"])); ?></span>
                            </td>

                            <td>
                                <span class="hist-pildora hist-pildora--metodo">
                                    <?php echo htmlspecialchars($pago["metodo_pago"]); ?>
                                </span>
                            </td>

                            <td class="hist-referencia">
                                <?php echo htmlspecialchars($pago["referencia"] ?? "Sin referencia"); ?>
                            </td>

                            <td class="hist-num hist-fuerte">
                                $<?php echo number_format($pago["monto"], 2); ?>
                            </td>

                            <td>
                                <span class="hist-pildora hist-estado-<?php echo htmlspecialchars(strtolower($pago["estado_pago"])); ?>">
                                    <?php echo htmlspecialchars($pago["estado_pago"]); ?>
                                </span>
                            </td>

                            <td>
                                <div class="hist-acciones">
                                    <a class="hist-boton" href="ver_cliente.php?id=<?php echo (int) $pago['id_cliente']; ?>" title="Ver cliente">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                                        Cliente
                                    </a>

                                    <?php if (!empty($pago['id_servicio'])): ?>
                                        <a class="hist-boton" href="ver_servicio.php?id=<?php echo (int) $pago['id_servicio']; ?>" title="Ver servicio">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><path d="M12 19.5h.01"/></svg>
                                            Servicio
                                        </a>
                                    <?php endif; ?>

                                    <a class="hist-boton hist-boton--principal"
                                       href="comprobante.php?id=<?php echo (int) $pago['id_pago']; ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       title="Ver comprobante">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
                                        Comprobante
                                    </a>
                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="hist-vacio">
                            No existen pagos para los filtros seleccionados.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<script>
document.querySelectorAll(".filtro").forEach(function(filtro) {

    filtro.addEventListener("change", function() {
        this.form.submit();
    });

});
</script>


</body>

</html>







