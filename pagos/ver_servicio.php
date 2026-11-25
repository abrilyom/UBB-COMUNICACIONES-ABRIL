<?php
require_once "conexion.php";

$id_servicio = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id_servicio || $id_servicio <=0){
    exit("El identificador del servicio no es válido");
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UFT-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información del servicio | UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css">
</head>
<body>

    <header class="encabezado">
        <div>
            <h1>UBB COMUNICACIONES</h1>
            <p>Gestión de pagos y servicios</p>
        </div>
    </header>

    <main class="contenedor">
        <section class="panel">
            <h2>Información del servicio</h2>

        <p>
            <strong>ID del servicio:</strong>
            <?php echo htmlspecialchars((string) $id_servicio); ?>
</p>

<p>
    Los detalles del servicio aparecerán aquí
</p>

<a> href="historial.php">Volver al historial</a>
</section>
</main>

</body>
<html>