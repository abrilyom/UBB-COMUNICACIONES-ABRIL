<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Pago - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css">
</head>

<body>

    <header class="encabezado">
        <h1>Registrar pago</h1>
        <p>UBB Comunicaciones</p>
    </header>

    <main class="contenedor">

        <form class="formulario">

            <label for="cliente">
                Cliente
            </label>

            <input
                type="text"
                id="cliente"
                name="cliente"
                placeholder="Nombre del cliente"
            >

            <label for="referencia">
                Número de referencia
            </label>

            <input
                type="text"
                id="referencia"
                name="referencia"
                placeholder="Referencia del pago"
            >

            <label for="monto">
                Monto
            </label>

            <input
                type="number"
                id="monto"
                name="monto"
                placeholder="0.00"
                step="0.01"
            >

            <label for="metodo">
                Método de pago
            </label>

            <select id="metodo" name="metodo">

                <option value="">
                    Seleccionar método
                </option>

                <option value="transferencia">
                    Transferencia
                </option>

                <option value="deposito">
                    Depósito
                </option>

                <option value="efectivo">
                    Efectivo
                </option>

                <option value="tarjeta">
                    Tarjeta por terminal
                </option>

            </select>

            <label for="fecha">
                Fecha del pago
            </label>

            <input
                type="date"
                id="fecha"
                name="fecha"
            >

            <button type="submit">
                Registrar pago
            </button>

        </form>

        <a href="index.php" class="volver">
            ← Regresar al módulo de pagos
        </a>

    </main>

</body>
</html>