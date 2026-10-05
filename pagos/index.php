<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>UBB Comunicaciones - Gestión de Pagos</title>

    <link rel="stylesheet" href="css/pagos.css">
</head>

<body>

<!-- =========================================================
     ENCABEZADO
========================================================= -->

<header class="encabezado">

    <div class="marca">
        <h1>UBB COMUNICACIONES</h1>
        <p>Pagos</p>
    </div>

    <div class="barra-superior">

        <div class="buscador">
            <span>🔍</span>
            <input
                type="text"
                id="buscarCliente"
                placeholder="Buscar cliente..."
            >
        </div>

        <div class="administrador">
            Administrador
            <span>▾</span>
        </div>

    </div>

</header>


<!-- =========================================================
     CONTENIDO PRINCIPAL
========================================================= -->

<main class="contenedor">

    <!-- =====================================================
         CENTRO DE CONTROL
    ====================================================== -->

    <section class="encabezado-control">

        <div>
            <span class="etiqueta-seccion">CENTRO DE CONTROL</span>

            <h2>Monitoreo y automatización de pagos y servicios</h2>
        </div>

        <div class="automatizacion">
            <span class="punto-verde"></span>

            <div>
                <strong>AUTOMATIZACIÓN ACTIVA</strong>
                <small>Última actualización: hace unos segundos</small>
            </div>
        </div>

    </section>


    <!-- =====================================================
         TARJETAS PRINCIPALES
    ====================================================== -->

    <section class="tarjetas">

        <div class="tarjeta tarjeta-activos">
            <div class="tarjeta-titulo">
                <span>🟢</span>
                <span>ACTIVOS</span>
            </div>

            <strong class="numero">842</strong>

            <small>Clientes con servicio activo</small>
        </div>


        <div class="tarjeta tarjeta-pagos">
            <div class="tarjeta-titulo">
                <span>💳</span>
                <span>PAGOS HOY</span>
            </div>

            <strong class="numero">126</strong>

            <small>Pagos registrados hoy</small>
        </div>


        <div class="tarjeta tarjeta-proximos">
            <div class="tarjeta-titulo">
                <span>🟡</span>
                <span>PRÓXIMOS</span>
            </div>

            <strong class="numero">37</strong>

            <small>Próximos vencimientos</small>
        </div>


        <div class="tarjeta tarjeta-suspendidos">
            <div class="tarjeta-titulo">
                <span>🔴</span>
                <span>SUSPENDIDOS</span>
            </div>

            <strong class="numero">18</strong>

            <small>Servicios suspendidos</small>
        </div>

    </section>


    <!-- =====================================================
         CLIENTES Y SERVICIOS
    ====================================================== -->

    <section class="panel">

        <div class="panel-encabezado">

            <div>
                <span class="etiqueta-seccion">CLIENTES Y SERVICIOS</span>
                <h3>Estado actual de los clientes</h3>
            </div>

        </div>


        <!-- FILTROS -->

        <div class="filtros-clientes">

            <button class="filtro activo">
                🟢 Activos <strong>842</strong>
            </button>

            <button class="filtro proximo">
                🟡 Próximos <strong>37</strong>
            </button>

            <button class="filtro suspendido">
                🔴 Suspendidos <strong>18</strong>
            </button>

        </div>


        <!-- TABLA -->

        <div class="tabla-contenedor">

            <table>

                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Referencia</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                    </tr>
                </thead>

                <tbody id="tablaClientes">

                    <tr>
                        <td>Juan Pérez</td>
                        <td>UBB-00125</td>
                        <td>05/10/2026</td>
                        <td>
                            <span class="estado activo">
                                🟢 Activo
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td>Ana Torres</td>
                        <td>UBB-00128</td>
                        <td>04/10/2026</td>
                        <td>
                            <span class="estado activo">
                                🟢 Activo
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td>María López</td>
                        <td>UBB-00126</td>
                        <td>06/10/2026</td>
                        <td>
                            <span class="estado proximo">
                                🟡 Próximo
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td>Pedro García</td>
                        <td>UBB-00127</td>
                        <td>28/09/2026</td>
                        <td>
                            <span class="estado suspendido">
                                🔴 Suspendido
                            </span>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>


        <div class="ver-clientes">
            <button onclick="mostrarClientes()">
                Ver todos los clientes →
            </button>
        </div>

    </section>


    <!-- =====================================================
         PAGOS + ESTADO DE SERVICIOS
    ====================================================== -->

    <section class="dos-columnas">

        <!-- PAGOS -->

        <div class="panel">

            <div class="panel-titulo">
                <span>💳</span>
                <div>
                    <span class="etiqueta-seccion">PAGOS</span>
                    <h3>Pagos del mes</h3>
                </div>
            </div>


            <div class="grafica">

                <div class="barra">
                    <span>Sem 1</span>
                    <div class="barra-fondo">
                        <div class="barra-valor valor-1"></div>
                    </div>
                </div>

                <div class="barra">
                    <span>Sem 2</span>
                    <div class="barra-fondo">
                        <div class="barra-valor valor-2"></div>
                    </div>
                </div>

                <div class="barra">
                    <span>Sem 3</span>
                    <div class="barra-fondo">
                        <div class="barra-valor valor-3"></div>
                    </div>
                </div>

                <div class="barra">
                    <span>Sem 4</span>
                    <div class="barra-fondo">
                        <div class="barra-valor valor-4"></div>
                    </div>
                </div>

            </div>

        </div>


        <!-- ESTADO DE SERVICIOS -->

        <div class="panel">

            <div class="panel-titulo">
                <span>📡</span>

                <div>
                    <span class="etiqueta-seccion">
                        ESTADO DE SERVICIOS
                    </span>

                    <h3>Resumen</h3>
                </div>
            </div>


            <div class="resumen-servicios">

                <div>
                    <span class="estado activo">🟢 Activos</span>
                    <strong>842</strong>
                </div>

                <div>
                    <span class="estado proximo">🟡 Próximos</span>
                    <strong>37</strong>
                </div>

                <div>
                    <span class="estado suspendido">🔴 Suspendidos</span>
                    <strong>18</strong>
                </div>

            </div>

        </div>

    </section>


    <!-- =====================================================
         AUTOMATIZACIÓN + ACCIONES
    ====================================================== -->

    <section class="dos-columnas">

        <!-- AUTOMATIZACIÓN -->

        <div class="panel">

            <div class="panel-titulo">

                <span>⚙️</span>

                <div>
                    <span class="etiqueta-seccion">
                        AUTOMATIZACIÓN
                    </span>

                    <h3>Procesamiento de pagos</h3>
                </div>

            </div>


            <div class="lista-automatizacion">

                <div>
                    <span>✓ Pagos identificados</span>
                    <strong>124</strong>
                </div>

                <div>
                    <span>✓ Pagos validados</span>
                    <strong>121</strong>
                </div>

                <div>
                    <span>✓ Servicios actualizados</span>
                    <strong>118</strong>
                </div>

                <div>
                    <span>◉ Procesando</span>
                    <strong>3</strong>
                </div>

                <div>
                    <span>⚠ Por revisar</span>
                    <strong>2</strong>
                </div>

            </div>

        </div>


        <!-- ACCIONES RÁPIDAS -->

        <div class="panel">

            <div class="panel-titulo">

                <span>⚡</span>

                <div>
                    <span class="etiqueta-seccion">
                        ACCIONES RÁPIDAS
                    </span>

                    <h3>Operaciones</h3>
                </div>

            </div>


            <div class="acciones">

                <a href="registrar.php" class="accion">
                    <span>＋</span>
                    <div>
                        <strong>Registrar pago</strong>
                        <small>Registrar un pago recibido</small>
                    </div>
                </a>


                <a href="historial.php" class="accion">
                    <span>📋</span>
                    <div>
                        <strong>Historial</strong>
                        <small>Consultar pagos realizados</small>
                    </div>
                </a>


                <button class="accion" onclick="mostrarMensaje()">
                    <span>🧾</span>
                    <div>
                        <strong>Referencias</strong>
                        <small>Consultar referencias de clientes</small>
                    </div>
                </button>

            </div>

        </div>

    </section>


    <!-- =====================================================
         ACTIVIDAD DE HOY
    ====================================================== -->

    <section class="panel">

        <div class="panel-titulo">

            <span>🕐</span>

            <div>
                <span class="etiqueta-seccion">
                    ACTIVIDAD DE HOY
                </span>

                <h3>Movimientos recientes</h3>
            </div>

        </div>


        <div class="tabla-contenedor">

            <table>

                <thead>

                    <tr>
                        <th>Hora</th>
                        <th>Cliente</th>
                        <th>Método</th>
                        <th>Monto</th>
                        <th>Estado</th>
                    </tr>

                </thead>


                <tbody>

                    <tr>
                        <td>10:42</td>
                        <td>Juan Pérez</td>
                        <td>Transferencia</td>
                        <td>$500</td>
                        <td>
                            <span class="estado procesado">
                                ✓ Procesado
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td>10:38</td>
                        <td>María López</td>
                        <td>Depósito</td>
                        <td>$450</td>
                        <td>
                            <span class="estado procesado">
                                ✓ Procesado
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td>10:31</td>
                        <td>Cliente #235</td>
                        <td>Transferencia</td>
                        <td>$500</td>
                        <td>
                            <span class="estado revisar">
                                ⚠ Revisar
                            </span>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </section>


    <!-- =====================================================
         PRÓXIMOS VENCIMIENTOS
    ====================================================== -->

    <section class="panel">

        <div class="panel-titulo">

            <span>📅</span>

            <div>
                <span class="etiqueta-seccion">
                    PRÓXIMOS VENCIMIENTOS
                </span>

                <h3>Clientes próximos a vencer</h3>
            </div>

        </div>


        <div class="tabla-contenedor">

            <table>

                <thead>

                    <tr>
                        <th>Cliente</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Recordatorio</th>
                    </tr>

                </thead>


                <tbody>

                    <tr>
                        <td>Juan Pérez</td>
                        <td>05/10</td>

                        <td>
                            <span class="estado proximo">
                                🟡 Próximo
                            </span>
                        </td>

                        <td>
                            <span class="pendiente">
                                Pendiente
                            </span>
                        </td>
                    </tr>


                    <tr>
                        <td>María López</td>
                        <td>06/10</td>

                        <td>
                            <span class="estado proximo">
                                🟡 Próximo
                            </span>
                        </td>

                        <td>
                            <span class="enviado">
                                ✓ Enviado
                            </span>
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </section>


    <!-- =====================================================
         HISTORIAL
    ====================================================== -->

    <div class="botones-historial">

        <a href="historial.php">
            Historial del día
        </a>

        <a href="historial.php">
            Historial del mes
        </a>

    </div>


</main>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

const buscador = document.getElementById("buscarCliente");

buscador.addEventListener("keyup", function () {

    const texto = this.value.toLowerCase();

    const filas = document.querySelectorAll("#tablaClientes tr");

    filas.forEach(function (fila) {

        const contenido = fila.textContent.toLowerCase();

        if (contenido.includes(texto)) {
            fila.style.display = "";
        } else {
            fila.style.display = "none";
        }

    });

});


function mostrarClientes() {

    alert("Aquí se mostrará la lista completa de clientes.");

}


function mostrarMensaje() {

    alert("Módulo de referencias preparado para integrarse con la base de datos.");

}

</script>

</body>
</html>