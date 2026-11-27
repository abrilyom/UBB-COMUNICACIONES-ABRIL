<?php
require_once __DIR__ . "/includes/sesion.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conexion_plataforma = require __DIR__ . "/conexion_plataforma.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/includes/estado_pagos.php";

// Situación de pago según mensualidades y pagos reales.
$estadoPagos = estadoPagosPorServicio($conexion);

date_default_timezone_set("America/Mexico_City");

function escaparActivo($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function calcularFechasActivo($fechaInstalacion) {
    if (!$fechaInstalacion || $fechaInstalacion === "0000-00-00") {
        return [null, null];
    }

    try {
        $instalacion = new DateTimeImmutable($fechaInstalacion);
        $hoy = new DateTimeImmutable("today");

        $anio = (int)$hoy->format("Y");
        $mes = (int)$hoy->format("n");
        $dia = (int)$instalacion->format("j");

        $ultimoDia = (int)(
            new DateTimeImmutable(sprintf("%04d-%02d-01", $anio, $mes))
        )->format("t");

        $dia = min($dia, $ultimoDia);

        $fechaPago = new DateTimeImmutable(
            sprintf("%04d-%02d-%02d", $anio, $mes, $dia)
        );

        $limite = $fechaPago->modify("+10 days");

        return [$fechaPago, $limite];
    } catch (Exception $e) {
        return [null, null];
    }
}

function urlClientesActivos($pagina, $buscar, $paquete, $porPagina) {
    return "?" . http_build_query([
        "pagina" => $pagina,
        "buscar" => $buscar,
        "paquete" => $paquete,
        "por_pagina" => $porPagina
    ]);
}

/* CONSULTA DE ESTADOS REALES DESDE GREZZ */
require_once __DIR__ . "/api/cliente_grezz.php";

$consultaGrezz = listarServiciosGrezz();

if (
    empty($consultaGrezz["transporte_ok"]) ||
    (int)($consultaGrezz["codigo_http"] ?? 0) !== 200 ||
    !is_array($consultaGrezz["respuesta"]["servicios"] ?? null)
) {
    error_log("Error al consultar servicios activos en Grezz: " . ($consultaGrezz["error"] ?? "Respuesta inválida."));
    http_response_code(503);
    exit("No fue posible verificar los servicios con Grezz. Intenta nuevamente más tarde.");
}

$serviciosGrezz = $consultaGrezz["respuesta"]["servicios"];
$activosGrezz = [];

foreach ($serviciosGrezz as $servicioGrezz) {
    $id = filter_var(
        $servicioGrezz["id_servicio"] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $id &&
        ($servicioGrezz["estado_actual"] ?? null) === "ACTIVO"
    ) {
        $activosGrezz[$id] = $servicioGrezz;
    }
}

$activos = [];

if ($activosGrezz) {
    $ids = array_keys($activosGrezz);
    $idsSql = implode(",", array_map("intval", $ids));

    $sql = "
        SELECT
            s.id_servicio,
            s.id_cliente,
            c.nombre,
            c.apellido_paterno,
            c.apellido_materno,
            c.telefono,
            d.calle,
            d.numero_exterior,
            d.numero_interior,
            d.colonia,
            d.municipio,
            d.estado AS estado_direccion,
            d.codigo_postal,
            s.paquete_referencia,
            s.fecha_instalacion
        FROM servicios s
        INNER JOIN clientes c ON c.id_cliente = s.id_cliente
        LEFT JOIN direcciones_servicio d
            ON d.id_direccion = s.id_direccion
           AND d.id_cliente = s.id_cliente
        WHERE s.id_servicio IN ($idsSql)
        ORDER BY c.nombre, c.apellido_paterno, s.id_servicio
    ";

    $resultado = $conexion_plataforma->query($sql);

    while ($fila = $resultado->fetch_assoc()) {
        $idServicio = (int)$fila["id_servicio"];
        $datosGrezz = $activosGrezz[$idServicio] ?? null;

        if (!$datosGrezz) {
            continue;
        }

        $fila["estado"] = $datosGrezz["estado_actual"];
        $fila["tecnologia"] = $datosGrezz["tipo"] ?? "Sin información";
        $fila["ip"] = $datosGrezz["ip"] ?? null;
        $fila["mac"] = $datosGrezz["mac"] ?? null;
        $fila["plan_grezz"] = $datosGrezz["plan"] ?? null;
        $fila["vlan"] = $datosGrezz["vlan"] ?? null;
        $fila["suspendido_por"] = $datosGrezz["suspendido_por"] ?? null;

        $activos[] = $fila;
    }
}

$totalActivos = count($activos);

/* PARAMETROS DE BUSQUEDA Y PAGINACION */
$buscar = trim((string)($_GET["buscar"] ?? ""));
$paqueteSeleccionado = trim((string)($_GET["paquete"] ?? ""));

$porPagina = filter_var(
    $_GET["por_pagina"] ?? 7,
    FILTER_VALIDATE_INT
);

if (!in_array($porPagina, [7, 10, 15, 25], true)) {
    $porPagina = 7;
}

$paquetes = [];

foreach ($activos as $fila) {
    $paquete = trim((string)($fila["paquete_referencia"] ?? ""));

    if ($paquete !== "") {
        $paquetes[$paquete] = $paquete;
    }
}

$paquetes = array_values($paquetes);
sort($paquetes, SORT_NATURAL | SORT_FLAG_CASE);

/* APLICAR FILTROS */
$activosFiltrados = [];

foreach ($activos as $fila) {
    $nombre = trim(
        ($fila["nombre"] ?? "") . " " .
        ($fila["apellido_paterno"] ?? "") . " " .
        ($fila["apellido_materno"] ?? "")
    );

    $paquete = trim((string)($fila["paquete_referencia"] ?? ""));

    $coincideBusqueda = true;

    if ($buscar !== "") {
        $texto = $nombre . " " .
            $fila["id_servicio"] . " " .
            $fila["id_cliente"] . " " .
            $paquete;

        $coincideBusqueda = stripos($texto, $buscar) !== false;
    }

    $coincidePaquete = (
        $paqueteSeleccionado === "" ||
        $paquete === $paqueteSeleccionado
    );

    if ($coincideBusqueda && $coincidePaquete) {
        $activosFiltrados[] = $fila;
    }
}

/* PAGINACION */
$totalFiltrados = count($activosFiltrados);
$totalPaginas = max(1, (int)ceil($totalFiltrados / $porPagina));

$pagina = filter_var(
    $_GET["pagina"] ?? 1,
    FILTER_VALIDATE_INT
);

if (!$pagina || $pagina < 1) {
    $pagina = 1;
}

$pagina = min($pagina, $totalPaginas);

$inicio = ($pagina - 1) * $porPagina;

$registrosPagina = array_slice(
    $activosFiltrados,
    $inicio,
    $porPagina
);

$desde = $totalFiltrados > 0 ? $inicio + 1 : 0;
$hasta = min($inicio + $porPagina, $totalFiltrados);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes activos - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/clientes-activos.css?v=<?php echo filemtime(__DIR__ . '/css/clientes-activos.css'); ?>">
</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor clientes-activos-page">

    <section class="panel clientes-activos-panel">

        <header class="ca-encabezado">

            <div class="ca-titulo-bloque">

                <div class="ca-icono-principal">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>

                <div>
                    <span class="ca-eyebrow">CLIENTES</span>
                    <h1>Clientes activos</h1>
                    <p>Listado de clientes con servicio vigente en UBB Comunicaciones.</p>
                </div>

            </div>

            <div class="ca-total">
                <span>Total de clientes activos</span>
                <strong><?= number_format($totalActivos) ?></strong>
            </div>

        </header>

        <form method="GET" action="activos.php" class="ca-filtros" id="caFormularioFiltros">

            <div class="ca-busqueda">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="m20 20-4-4"/>
                </svg>

                <input
                    type="search"
                    name="buscar"
                    id="caBuscar"
                    placeholder="Buscar por nombre, ID, servicio o paquete..."
                    value="<?= escaparActivo($buscar) ?>"
                    autocomplete="off"
                >
            </div>

            <select name="paquete" id="caPaquete" class="ca-select">
                <option value="">Todos los paquetes</option>

                <?php foreach ($paquetes as $paquete): ?>
                    <option
                        value="<?= escaparActivo($paquete) ?>"
                        <?= $paqueteSeleccionado === $paquete ? "selected" : "" ?>
                    >
                        <?= escaparActivo($paquete) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="hidden" name="por_pagina"
                   value="<?= $porPagina ?>">

            <a href="activos.php" class="ca-btn ca-btn-secundario">
                Limpiar
            </a>

        </form>

        <div class="ca-tabla-contenedor">

            <table class="ca-tabla">

                <thead>
                    <tr>
                        <th>CLIENTE</th>
                        <th>ID SERVICIO</th>
                        <th>PAQUETE</th>
                        <th>PRÓXIMO VENCIMIENTO</th>
                        <th>LÍMITE</th>
                        <th>ESTADO / PAGO</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($registrosPagina as $fila): ?>

                    <?php
                    $nombre = trim(
                        ($fila["nombre"] ?? "") . " " .
                        ($fila["apellido_paterno"] ?? "") . " " .
                        ($fila["apellido_materno"] ?? "")
                    );

                    // Situación según mensualidades y pagos reales.
                    $situacionPago = $estadoPagos[(int)$fila["id_servicio"]] ?? null;
                    $fechaPago = $situacionPago["fecha_pago"] ?? null;
                    $fechaLimite = $situacionPago["limite"] ?? null;
                    $textoSituacion = $situacionPago
                        ? $situacionPago["situacion"] . " · " . textoAdeudo($situacionPago)
                        : "Al corriente";
                    $fechaInstalacion = $fila["fecha_instalacion"] ?? "";
                    $fechaInstalacionMostrar = $fechaInstalacion && $fechaInstalacion !== "0000-00-00"
                        ? date("d/m/Y", strtotime($fechaInstalacion))
                        : "Sin información";

                    $partesDireccion = [
                        $fila["calle"] ?? "",
                        !empty($fila["numero_exterior"]) ? "No. " . $fila["numero_exterior"] : "",
                        !empty($fila["numero_interior"]) ? "Int. " . $fila["numero_interior"] : "",
                        $fila["colonia"] ?? "",
                        $fila["municipio"] ?? "",
                        $fila["estado_direccion"] ?? "",
                        !empty($fila["codigo_postal"]) ? "C.P. " . $fila["codigo_postal"] : ""
                    ];

                    $direccionMostrar = implode(", ", array_filter(
                        $partesDireccion,
                        fn($parte) => trim((string)$parte) !== ""
                    ));

                    if ($direccionMostrar === "") {
                        $direccionMostrar = "Sin información";
                    }
                    ?>

                    <tr>

                        <td>
                            <div class="ca-cliente">
                                <span class="ca-avatar">
                                    <svg viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.8"
                                         stroke-linecap="round"
                                         aria-hidden="true">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                                    </svg>
                                </span>

                                <strong><?= escaparActivo($nombre) ?></strong>
                            </div>
                        </td>

                        <td><?= escaparActivo($fila["id_servicio"]) ?></td>

                        <td>
                            <?= escaparActivo(
                                $fila["paquete_referencia"] ?: "Sin información"
                            ) ?>
                        </td>

                        <td>
                            <?= $fechaPago
                                ? $fechaPago->format("d/m/Y")
                                : "Sin adeudo" ?>
                        </td>

                        <td>
                            <?= $fechaLimite
                                ? $fechaLimite->format("d/m/Y")
                                : "—" ?>
                        </td>

                        <td>
                            <span class="ca-estado">
                                <?= escaparActivo($fila["estado"]) ?>
                            </span>
                            <small class="ca-situacion-pago"><?= escaparActivo($textoSituacion) ?></small>
                        </td>

                        <td class="ca-acciones">
                            <button type="button" class="ca-btn-ver"
                                aria-label="Ver detalles de <?= escaparActivo($nombre) ?>"
                                data-nombre="<?= escaparActivo($nombre) ?>"
                                data-telefono="<?= escaparActivo($fila["telefono"] ?: "Sin información") ?>"
                                data-direccion="<?= escaparActivo($direccionMostrar) ?>"
                                data-id-cliente="<?= escaparActivo($fila["id_cliente"]) ?>"
                                data-id-servicio="<?= escaparActivo($fila["id_servicio"]) ?>"
                                data-paquete="<?= escaparActivo($fila["paquete_referencia"] ?: "Sin información") ?>"
                                data-instalacion="<?= escaparActivo($fechaInstalacionMostrar) ?>"
                                data-pago="<?= escaparActivo($fechaPago ? $fechaPago->format("d/m/Y") : "Sin información") ?>"
                                data-limite="<?= escaparActivo($fechaLimite ? $fechaLimite->format("d/m/Y") : "Sin información") ?>"
                                data-estado="<?= escaparActivo($fila["estado"]) ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                Ver detalles
                            </button>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if ($totalFiltrados === 0): ?>
                    <tr>
                        <td colspan="7" class="ca-vacio">
                            No se encontraron clientes con los criterios seleccionados.
                        </td>
                    </tr>
                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <div class="ca-modal" id="caModalDetalles" aria-hidden="true">
            <div class="ca-modal-fondo" data-cerrar-modal></div>
            <section class="ca-modal-contenido" role="dialog" aria-modal="true"
                     aria-labelledby="caModalTitulo">
                <header class="ca-modal-encabezado">
                    <div>
                        <span class="ca-modal-etiqueta">INFORMACIÓN DEL CLIENTE</span>
                        <h2 id="caModalTitulo">Detalles del servicio</h2>
                    </div>
                    <button type="button" class="ca-modal-cerrar" data-cerrar-modal aria-label="Cerrar">×</button>
                </header>
                <div class="ca-modal-datos">
                    <div class="ca-modal-dato ca-modal-dato-principal"><span>Cliente</span><strong id="caDetalleNombre"></strong></div>
                    <div class="ca-modal-dato"><span>Teléfono</span><strong id="caDetalleTelefono"></strong></div>
                    <div class="ca-modal-dato ca-modal-dato-principal"><span>Dirección</span><strong id="caDetalleDireccion"></strong></div>
                    <div class="ca-modal-dato"><span>ID cliente</span><strong id="caDetalleIdCliente"></strong></div>
                    <div class="ca-modal-dato"><span>ID servicio</span><strong id="caDetalleIdServicio"></strong></div>
                    <div class="ca-modal-dato"><span>Paquete</span><strong id="caDetallePaquete"></strong></div>
                    <div class="ca-modal-dato"><span>Fecha de instalación</span><strong id="caDetalleInstalacion"></strong></div>
                    <div class="ca-modal-dato"><span>Fecha de pago</span><strong id="caDetallePago"></strong></div>
                    <div class="ca-modal-dato"><span>Fecha límite</span><strong id="caDetalleLimite"></strong></div>
                    <div class="ca-modal-dato"><span>Estado</span><strong id="caDetalleEstado"></strong></div>
                </div>
                <footer class="ca-modal-pie">
                    <button type="button" class="ca-btn-modal-cerrar" data-cerrar-modal>Cerrar</button>
                </footer>
            </section>
        </div>

        <footer class="ca-pie-tabla">

            <span class="ca-resumen">
                Mostrando <?= $desde ?> – <?= $hasta ?>
                de <?= number_format($totalFiltrados) ?> clientes
            </span>

            <nav class="ca-paginacion" aria-label="Paginación">

                <?php if ($pagina > 1): ?>
                    <a class="ca-pagina"
                       href="<?= escaparActivo(urlClientesActivos(
                           $pagina - 1, $buscar, $paqueteSeleccionado, $porPagina
                       )) ?>">
                        ‹
                    </a>
                <?php endif; ?>

                <?php
                $inicioPaginas = max(1, $pagina - 2);
                $finPaginas = min($totalPaginas, $pagina + 2);
                ?>

                <?php if ($inicioPaginas > 1): ?>
                    <a class="ca-pagina"
                       href="<?= escaparActivo(urlClientesActivos(
                           1, $buscar, $paqueteSeleccionado, $porPagina
                       )) ?>">1</a>

                    <?php if ($inicioPaginas > 2): ?>
                        <span class="ca-puntos">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $inicioPaginas; $i <= $finPaginas; $i++): ?>
                    <a
                        class="ca-pagina <?= $i === $pagina ? "activa" : "" ?>"
                        href="<?= escaparActivo(urlClientesActivos(
                            $i, $buscar, $paqueteSeleccionado, $porPagina
                        )) ?>"
                        <?= $i === $pagina ? 'aria-current="page"' : "" ?>
                    >
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($finPaginas < $totalPaginas): ?>

                    <?php if ($finPaginas < $totalPaginas - 1): ?>
                        <span class="ca-puntos">…</span>
                    <?php endif; ?>

                    <a class="ca-pagina"
                       href="<?= escaparActivo(urlClientesActivos(
                           $totalPaginas, $buscar, $paqueteSeleccionado, $porPagina
                       )) ?>">
                        <?= $totalPaginas ?>
                    </a>

                <?php endif; ?>

                <?php if ($pagina < $totalPaginas): ?>
                    <a class="ca-pagina"
                       href="<?= escaparActivo(urlClientesActivos(
                           $pagina + 1, $buscar, $paqueteSeleccionado, $porPagina
                       )) ?>">
                        ›
                    </a>
                <?php endif; ?>

            </nav>

            <form method="GET" action="activos.php" class="ca-paginas-form">

                <input type="hidden" name="buscar"
                       value="<?= escaparActivo($buscar) ?>">

                <input type="hidden" name="paquete"
                       value="<?= escaparActivo($paqueteSeleccionado) ?>">

                <label for="caPorPagina">Por página</label>

                <select name="por_pagina" id="caPorPagina"
                        onchange="this.form.submit()">

                    <?php foreach ([7, 10, 15, 25] as $cantidad): ?>
                        <option value="<?= $cantidad ?>"
                            <?= $porPagina === $cantidad ? "selected" : "" ?>>
                            <?= $cantidad ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </form>

        </footer>

    </section>

</main>

<script>
(() => {
    // BUSQUEDA Y FILTROS AUTOMATICOS
    const formulario = document.getElementById("caFormularioFiltros");
    const buscador = document.getElementById("caBuscar");
    const paquete = document.getElementById("caPaquete");

    if (formulario && buscador && paquete) {
        let temporizador = null;

        buscador.addEventListener("input", () => {
            clearTimeout(temporizador);
            temporizador = setTimeout(() => {
                formulario.requestSubmit();
            }, 1000);
        });

        paquete.addEventListener("change", () => {
            clearTimeout(temporizador);
            formulario.requestSubmit();
        });
    }

    const modal = document.getElementById("caModalDetalles");
    if (!modal) return;
    const campos = {
        nombre: "caDetalleNombre", telefono: "caDetalleTelefono",
        direccion: "caDetalleDireccion", idCliente: "caDetalleIdCliente",
        idServicio: "caDetalleIdServicio", paquete: "caDetallePaquete",
        instalacion: "caDetalleInstalacion", pago: "caDetallePago",
        limite: "caDetalleLimite", estado: "caDetalleEstado"
    };
    let botonAnterior = null;
    document.querySelectorAll(".ca-btn-ver").forEach(boton => {
        boton.addEventListener("click", () => {
            botonAnterior = boton;
            Object.entries(campos).forEach(([dato, id]) => {
                document.getElementById(id).textContent = boton.dataset[dato] || "Sin información";
            });
            modal.classList.add("ca-modal-abierto");
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("ca-modal-activo");
            modal.querySelector(".ca-modal-cerrar").focus();
        });
    });
    function cerrarModal() {
        modal.classList.remove("ca-modal-abierto");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("ca-modal-activo");
        if (botonAnterior) botonAnterior.focus();
    }
    modal.querySelectorAll("[data-cerrar-modal]").forEach(boton => {
        boton.addEventListener("click", cerrarModal);
    });
    document.addEventListener("keydown", evento => {
        if (evento.key === "Escape" && modal.classList.contains("ca-modal-abierto")) cerrarModal();
    });
})();
</script>

</body>
</html>
