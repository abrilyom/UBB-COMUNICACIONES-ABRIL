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


/* Búsqueda */

if ($buscar !== "") {

    $buscar_seguro = $conexion->real_escape_string($buscar);

    $condiciones[] = "(
        id_cliente LIKE '%$buscar_seguro%'
        OR referencia LIKE '%$buscar_seguro%'
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

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Movimientos de pagos - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">

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

<main class="contenedor">


    <!-- =================================================
         TÍTULO
    ================================================== -->

    <section class="panel">

        <div class="panel-encabezado">

            <div>

                <span class="etiqueta-seccion">
                    CONTROL DE PAGOS
                </span>

                <h2 class="panel-titulo">
                    Movimientos de pagos
                </h2>

            </div>

        </div>


        <!-- =============================================
             FILTROS
        ============================================== -->

        <form method="GET" class="filtros-clientes">


            <!-- PERÍODO -->

            <select name="periodo" class="filtro">

                <option value="hoy"
                    <?php echo $periodo === "hoy" ? "selected" : ""; ?>>
                    Hoy
                </option>

                <option value="semana"
                    <?php echo $periodo === "semana" ? "selected" : ""; ?>>
                    Esta semana
                </option>

                <option value="mes"
                    <?php echo $periodo === "mes" ? "selected" : ""; ?>>
                    Este mes
                </option>

                <option value="todos"
                    <?php echo $periodo === "todos" ? "selected" : ""; ?>>
                    Todos
                </option>

            </select>


            <!-- MÉTODO -->

            <select name="metodo" class="filtro">

                <option value="todos">
                    Todos los métodos
                </option>

                <option value="EFECTIVO"
                    <?php echo $metodo === "EFECTIVO" ? "selected" : ""; ?>>
                    Efectivo
                </option>

                <option value="TRANSFERENCIA"
                    <?php echo $metodo === "TRANSFERENCIA" ? "selected" : ""; ?>>
                    Transferencia
                </option>

                <option value="DEPOSITO"
                    <?php echo $metodo === "DEPOSITO" ? "selected" : ""; ?>>
                    Depósito
                </option>

                <option value="TARJETA"
                    <?php echo $metodo === "TARJETA" ? "selected" : ""; ?>>
                    Tarjeta
                </option>

            </select>


            <!-- ESTADO -->

            <select name="estado" class="filtro">

                <option value="todos">
                    Todos los estados
                </option>

                <option value="PAGADO"
                    <?php echo $estado === "PAGADO" ? "selected" : ""; ?>>
                    Pagado
                </option>

                <option value="PENDIENTE"
                    <?php echo $estado === "PENDIENTE" ? "selected" : ""; ?>>
                    Pendiente
                </option>

                <option value="CANCELADO"
                    <?php echo $estado === "CANCELADO" ? "selected" : ""; ?>>
                    Cancelado
                </option>

            </select>


            <!-- BÚSQUEDA -->

            <div class="buscador">

                <input
                    type="text"
                    name="buscar"
                    value="<?php echo htmlspecialchars($buscar); ?>"
                    placeholder="Buscar cliente o referencia..."
                >

                <button type="submit">
                    🔎
                </button>

            </div>


        </form>


        <!-- =============================================
             RESUMEN
        ============================================== -->

        <div class="tarjetas">


            <div class="tarjeta">

                <div class="tarjeta-titulo">

                    <span>💳</span>

                    <span>MOVIMIENTOS</span>

                </div>

                <strong class="numero">

                    <?php echo $total_movimientos; ?>

                </strong>

                <small>
                    pagos registrados
                </small>

            </div>



            <div class="tarjeta">

                <div class="tarjeta-titulo">

                    <span>💰</span>

                    <span>RECAUDADO</span>

                </div>

                <strong class="numero">

                    $<?php echo number_format($total_recaudado, 2); ?>

                </strong>

                <small>
                    monto del período
                </small>

            </div>



            <div class="tarjeta">

                <div class="tarjeta-titulo">

                    <span>✅</span>

                    <span>PAGADOS</span>

                </div>

                <strong class="numero">

                    <?php echo $total_pagados; ?>

                </strong>

                <small>
                    pagos confirmados
                </small>

            </div>


        </div>


        <!-- =============================================
             TABLA
        ============================================== -->

        <div class="tabla-contenedor">

            <table>

                <thead>

                    <tr>

                        <th>CLIENTE</th>

                        <th>SERVICIO</th>

                        <th>FECHA</th>

                        <th>MÉTODO</th>

                        <th>REFERENCIA</th>

                        <th>MONTO</th>

                        <th>ESTADO</th>

                        <th>ACCIONES</th>

                    </tr>

                </thead>


                <tbody>


                <?php if (count($pagos) > 0): ?>


                    <?php foreach ($pagos as $pago): ?>


                        <tr>


                            <td>

                                Cliente #

                                <?php
                                echo htmlspecialchars(
                                    $pago["id_cliente"]
                                );
                                ?>

                            </td>

                            <td>
                                <?php
                                echo $pago["id_servicio"] !== null
                                ? "Servicio #" . (int)$pago["id_servicio"]
                                : "Sin servicio";
                                ?>
                                </td>


                            <td>

                                <?php
                                echo date(
                                    "d/m/Y H:i",
                                    strtotime($pago["fecha_pago"])
                                );
                                ?>

                            </td>


                            <td>
                                <span class="metodo-pago">

                                <?php
                                echo htmlspecialchars($pago["metodo_pago"]);?>
                                </span>

                            </td>


                            <td>

                                <?php

                                echo htmlspecialchars(
                                    $pago["referencia"] ?? "Sin referencia"
                                );

                                ?>

                            </td>


                            <td>

                                <strong>

                                    $<?php
                                    echo number_format(
                                        $pago["monto"],
                                        2
                                    );
                                    ?>

                                </strong>

                            </td>


                            <td>

                                <span class="estado estado-<?php echo strtolower ($pago["estado_pago"]); ?>">
                                    <?php echo htmlspecialchars($pago["estado_pago"]); ?>

                                </span>

                            </td>

                            <td>
                                <a href="ver_cliente.php?id=<?php echo (int) $pago['id_cliente']; ?>">
                                    Ver cliente 
                                </a>

                                <?php if (!empty($pago['id_servicio'])): ?>
                                    <br>
                                    <a href="ver_servicio.php?id=<?php echo (int) $pago['id_servicio']; ?>">
                                            Ver servicio
                                            </a>
                                            <?php endif; ?>

                                <?php if (!empty($pago['comprobante'])): ?>
                                    <br>
                                    <a href="<?php echo htmlspecialchars($pago['comprobante'], ENT_QUOTES, 'UTF-8'); ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        📄 Ver comprobante
                                    </a>
                                <?php endif; ?>

                            </td>    


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="6">

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







