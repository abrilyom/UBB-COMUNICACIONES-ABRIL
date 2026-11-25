<?php

require_once "conexion.php";

$hoy = date("Y-m-d");

/* =========================
   PAGOS DE HOY
========================= */

$sql = "SELECT
            id_pago,
            id_cliente,
            id_servicio,
            monto,
            fecha_pago,
            metodo_pago,
            referencia,
            estado_pago
        FROM pagos
        WHERE DATE(fecha_pago) = ?
        AND estado_pago = 'PAGADO'
        ORDER BY fecha_pago DESC";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $hoy);
$stmt->execute();

$resultado = $stmt->get_result();

/* =========================
   TOTALES
========================= */

$sql_total = "SELECT
                COUNT(*) AS cantidad,
                COALESCE(SUM(monto), 0) AS total
              FROM pagos
              WHERE DATE(fecha_pago) = ?
              AND estado_pago = 'PAGADO'";

$stmt_total = $conexion->prepare($sql_total);
$stmt_total->bind_param("s", $hoy);
$stmt_total->execute();

$resumen = $stmt_total->get_result()->fetch_assoc();

$cantidad_pagos = $resumen["cantidad"];
$total_pagos = $resumen["total"];

$diasSemana = [
    "domingo", "lunes", "martes", "miércoles",
    "jueves", "viernes", "sábado"
];

$meses = [
    1 => "enero", "febrero", "marzo", "abril",
    "mayo", "junio", "julio", "agosto",
    "septiembre", "octubre", "noviembre", "diciembre"
];

$fecha_hoy_texto =
    $diasSemana[(int)date("w")] . ", " .
    date("j") . " de " .
    $meses[(int)date("n")] . " de " .
    date("Y");

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pagos realizados hoy - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css">
    <link rel="stylesheet" href="css/pagos-hoy.css">

    

</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor pagos-hoy-page">
    <div class="ph-contenido">

    <div class="ph-encabezado">

        <div class="ph-titulo">

            <div class="ph-titulo-linea">

                <span class="ph-icono-titulo">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="M3 10h18M7 15h3"/>
                    </svg>
                </span>

                <h1>PAGOS HOY</h1>

            </div>

            <p class="ph-fecha">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <rect x="3" y="5" width="18" height="16" rx="2"/>
                    <path d="M16 3v4M8 3v4M3 10h18"/>
                </svg>
                <?= htmlspecialchars($fecha_hoy_texto, ENT_QUOTES, "UTF-8") ?>
            </p>

        </div>

        <a href="index.php" class="boton-regresar">
            ← Regresar al centro de control
        </a>

    </div>


    <!-- RESUMEN -->

    <div class="resumen">

        <div class="tarjeta">

            <span>Pagos registrados hoy</span>

            <strong>
                <?php echo $cantidad_pagos; ?>
            </strong>

        </div>


        <div class="tarjeta">

            <span>Total recaudado hoy</span>

            <strong>
                $<?php echo number_format($total_pagos, 2); ?>
            </strong>

        </div>

    </div>


    <!-- TABLA -->

    <div class="panel">

        <div class="panel-titulo">

            <h2>Movimientos del día</h2>

            <p>
                Pagos con estado PAGADO registrados durante el día de hoy.
            </p>

        </div>


        <div class="tabla-contenedor">

            <?php if ($resultado->num_rows > 0): ?>

                <table>

                    <thead>

                        <tr>

                            <th>Cliente</th>
                            <th>Servicio</th>
                            <th>Monto</th>
                            <th>Método de pago</th>
                            <th>Referencia</th>
                            <th>Hora</th>
                            <th>Estado</th>
                            <th>Acción</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php while ($pago = $resultado->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    Cliente #<?php echo htmlspecialchars($pago["id_cliente"]); ?>
                                </td>

                                <td>
                                    <?php
                                    echo $pago["id_servicio"]
                                        ? "Servicio #" . htmlspecialchars($pago["id_servicio"])
                                        : "—";
                                    ?>
                                </td>

                                <td>
                                    $<?php echo number_format($pago["monto"], 2); ?>
                                </td>

                                <td>
                                    <span class="metodo-pago">
                                    <?php echo htmlspecialchars($pago["metodo_pago"]); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    echo $pago["referencia"]
                                        ? htmlspecialchars($pago["referencia"])
                                        : "—";
                                    ?>
                                </td>

                                <td>
                                    <?php echo date("H:i", strtotime($pago["fecha_pago"])); ?>
                                </td>

                                <td>
                                    <span class="estado">
                                        PAGADO
                                    </span>
                                </td>

                                <td>
                                    <a href="detalle_pago.php?id=<?php echo $pago["id_pago"]; ?>" class="ph-boton-detalle">
                                        Ver detalle 
                                    </a>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <div class="sin-pagos">

                    <p>💳 No hay pagos registrados hoy.</p>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>
</main>

</body>

</html>