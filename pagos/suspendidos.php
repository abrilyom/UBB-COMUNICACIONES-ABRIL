<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");

function escaparSuspendido($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function urlClientesSuspendidos($pagina, $buscar, $paquete, $porPagina) {
    return "?" . http_build_query([
        "pagina" => $pagina,
        "buscar" => $buscar,
        "paquete" => $paquete,
        "por_pagina" => $porPagina
    ]);
}

/* CONSULTA DE ESTADOS REALES DESDE GREZZ */
$serviciosSuspendidos = [];
$errorCarga = null;

try {
    require_once __DIR__ . "/api/cliente_grezz.php";

    $consultaGrezz = listarServiciosGrezz();

    if (
        empty($consultaGrezz["transporte_ok"]) ||
        (int)($consultaGrezz["codigo_http"] ?? 0) !== 200 ||
        !is_array($consultaGrezz["respuesta"]["servicios"] ?? null)
    ) {
        throw new RuntimeException("No fue posible obtener el listado de servicios de Grezz.");
    }

    $serviciosGrezz = $consultaGrezz["respuesta"]["servicios"];
    $suspendidosGrezz = [];

    foreach ($serviciosGrezz as $servicioGrezz) {
        $id = filter_var(
            $servicioGrezz["id_servicio"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $id &&
            ($servicioGrezz["estado_actual"] ?? null) === "SUSPENDIDO"
        ) {
            $suspendidosGrezz[$id] = $servicioGrezz;
        }
    }

    if ($suspendidosGrezz) {
        $ids = array_keys($suspendidosGrezz);
        $idsSql = implode(",", array_map("intval", $ids));

        $conexion_plataforma = require __DIR__ . "/conexion_plataforma.php";

        $sql = "
            SELECT
                s.id_servicio,
                s.id_cliente,
                c.nombre,
                c.apellido_paterno,
                c.apellido_materno,
                c.telefono,
                c.correo,
                d.calle,
                d.numero_exterior,
                d.numero_interior,
                d.colonia,
                d.municipio,
                d.estado AS estado_direccion,
                d.codigo_postal,
                s.paquete_referencia,
                s.fecha_instalacion,
                s.fecha_contratacion
            FROM servicios s
            INNER JOIN clientes c
                ON c.id_cliente = s.id_cliente
            LEFT JOIN direcciones_servicio d
                ON d.id_direccion = s.id_direccion
               AND d.id_cliente = s.id_cliente
            WHERE s.id_servicio IN ($idsSql)
            ORDER BY c.nombre, c.apellido_paterno, s.id_servicio
        ";

        $resultado = $conexion_plataforma->query($sql);

        while ($fila = $resultado->fetch_assoc()) {
            $idServicio = (int)$fila["id_servicio"];
            $datosGrezz = $suspendidosGrezz[$idServicio] ?? null;

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

            $serviciosSuspendidos[] = $fila;
        }
    }

} catch (Throwable $e) {
    error_log("Error en módulo Suspendidos / Grezz: " . $e->getMessage());
    $errorCarga = "No fue posible verificar los servicios suspendidos con Grezz. Intenta nuevamente más tarde.";
}

/* TOTAL REAL */
$totalSuspendidos = count($serviciosSuspendidos);

/* PARÁMETROS DE BÚSQUEDA Y PAGINACIÓN */
$buscar = trim((string)($_GET["buscar"] ?? ""));
$paqueteSeleccionado = trim((string)($_GET["paquete"] ?? ""));

$porPagina = filter_var(
    $_GET["por_pagina"] ?? 7,
    FILTER_VALIDATE_INT
);

if (!in_array($porPagina, [7, 10, 15, 25], true)) {
    $porPagina = 7;
}

/* PAQUETES DISPONIBLES */
$paquetes = [];

foreach ($serviciosSuspendidos as $fila) {
    $paquete = trim((string)($fila["paquete_referencia"] ?? ""));

    if ($paquete !== "") {
        $paquetes[$paquete] = $paquete;
    }
}

$paquetes = array_values($paquetes);
sort($paquetes, SORT_NATURAL | SORT_FLAG_CASE);

/* APLICAR FILTROS */
$serviciosFiltrados = [];

foreach ($serviciosSuspendidos as $fila) {
    $nombre = trim(
        ($fila["nombre"] ?? "") . " " .
        ($fila["apellido_paterno"] ?? "") . " " .
        ($fila["apellido_materno"] ?? "")
    );

    $paquete = trim((string)($fila["paquete_referencia"] ?? ""));

    $textoBusqueda = implode(" ", [
        $nombre,
        $fila["id_cliente"],
        $fila["id_servicio"],
        $fila["telefono"],
        $fila["correo"],
        $paquete
    ]);

    $coincideBusqueda = (
        $buscar === "" ||
        stripos($textoBusqueda, $buscar) !== false
    );

    $coincidePaquete = (
        $paqueteSeleccionado === "" ||
        $paquete === $paqueteSeleccionado
    );

    if ($coincideBusqueda && $coincidePaquete) {
        $serviciosFiltrados[] = $fila;
    }
}

/* PAGINACIÓN */
$totalFiltrados = count($serviciosFiltrados);
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
    $serviciosFiltrados,
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

    <title>Clientes suspendidos - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/suspendidos.css?v=<?php echo filemtime(__DIR__ . '/css/suspendidos.css'); ?>">
</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor clientes-suspendidos-page">

    <section class="panel cs-panel">

        <header class="cs-encabezado">

            <div class="cs-titulo-bloque">

                <div class="cs-icono-principal" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                        <path d="M12 9v4M12 17h.01"/>
                    </svg>
                </div>

                <div>
                    <span class="cs-eyebrow">SERVICIOS</span>
                    <h1>Clientes suspendidos</h1>
                    <p>Consulta los clientes cuyos servicios se encuentran suspendidos.</p>
                </div>

            </div>

            <div class="cs-total">
                <span>Total de servicios suspendidos</span>
                <strong>
                    <?= $errorCarga ? "—" : number_format($totalSuspendidos) ?>
                </strong>
            </div>

        </header>

        <?php if ($errorCarga): ?>

            <div class="cs-alerta" role="alert">
                <?= escaparSuspendido($errorCarga) ?>
            </div>

        <?php else: ?>

            <form method="GET" action="suspendidos.php"
                  class="cs-filtros" id="csFormularioFiltros">

                <div class="cs-busqueda">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-4-4"/>
                    </svg>

                    <input
                        type="search"
                        name="buscar"
                        id="csBuscar"
                        placeholder="Buscar por cliente, teléfono, ID o paquete..."
                        value="<?= escaparSuspendido($buscar) ?>"
                        autocomplete="off"
                    >
                </div>

                <select name="paquete" id="csPaquete" class="cs-select">
                    <option value="">Todos los paquetes</option>

                    <?php foreach ($paquetes as $paquete): ?>
                        <option
                            value="<?= escaparSuspendido($paquete) ?>"
                            <?= $paqueteSeleccionado === $paquete ? "selected" : "" ?>
                        >
                            <?= escaparSuspendido($paquete) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="hidden" name="por_pagina"
                       value="<?= $porPagina ?>">

                <a href="suspendidos.php" class="cs-btn cs-btn-secundario">
                    Limpiar
                </a>

            </form>

            <div class="cs-tabla-contenedor">

                <table class="cs-tabla">

                    <thead>
                        <tr>
                            <th>CLIENTE</th>
                            <th>ID SERVICIO</th>
                            <th>PAQUETE</th>
                            <th>TECNOLOGÍA</th>
                            <th>FECHA DE SUSPENSIÓN</th>
                            <th>ESTADO</th>
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

                        $fechaInstalacion = $fila["fecha_instalacion"] ?? "";
                        $fechaInstalacionMostrar =
                            $fechaInstalacion && $fechaInstalacion !== "0000-00-00"
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
                                <div class="cs-cliente">
                                    <span class="cs-avatar" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="1.8"
                                             stroke-linecap="round">
                                            <circle cx="12" cy="8" r="4"/>
                                            <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                                        </svg>
                                    </span>

                                    <strong><?= escaparSuspendido($nombre) ?></strong>
                                </div>
                            </td>

                            <td><?= escaparSuspendido($fila["id_servicio"]) ?></td>

                            <td>
                                <?= escaparSuspendido(
                                    $fila["paquete_referencia"] ?: "Sin información"
                                ) ?>
                            </td>

                            <td>
                                <?= escaparSuspendido($fila["tecnologia"] ?: "Sin información") ?>
                            </td>

                            <td>No registrada</td>

                            <td>
                                <span class="cs-estado">
                                    <?= escaparSuspendido($fila["estado"]) ?>
                                </span>
                            </td>

                            <td class="cs-acciones">
                                <button type="button" class="cs-btn-ver"
                                    aria-label="Ver detalles de <?= escaparSuspendido($nombre) ?>"
                                    data-nombre="<?= escaparSuspendido($nombre) ?>"
                                    data-telefono="<?= escaparSuspendido($fila["telefono"] ?: "Sin información") ?>"
                                    data-correo="<?= escaparSuspendido($fila["correo"] ?: "Sin información") ?>"
                                    data-direccion="<?= escaparSuspendido($direccionMostrar) ?>"
                                    data-id-cliente="<?= escaparSuspendido($fila["id_cliente"]) ?>"
                                    data-id-servicio="<?= escaparSuspendido($fila["id_servicio"]) ?>"
                                    data-paquete="<?= escaparSuspendido($fila["paquete_referencia"] ?: "Sin información") ?>"
                                    data-tecnologia="<?= escaparSuspendido($fila["tecnologia"] ?: "Sin información") ?>"
                                    data-instalacion="<?= escaparSuspendido($fechaInstalacionMostrar) ?>"
                                    data-contratacion="<?= escaparSuspendido(
                                        !empty($fila["fecha_contratacion"])
                                        ? date("d/m/Y", strtotime($fila["fecha_contratacion"]))
                                        : "Sin información"
                                    ) ?>"
                                    data-estado="<?= escaparSuspendido($fila["estado"]) ?>">

                                    <svg viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.8"
                                         stroke-linecap="round" stroke-linejoin="round"
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
                            <td colspan="7" class="cs-vacio">
                                No se encontraron servicios suspendidos con los criterios seleccionados.
                            </td>
                        </tr>
                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <div class="cs-modal" id="csModalDetalles" aria-hidden="true">
                <div class="cs-modal-fondo" data-cerrar-modal></div>

                <section class="cs-modal-contenido" role="dialog"
                         aria-modal="true" aria-labelledby="csModalTitulo">

                    <header class="cs-modal-encabezado">
                        <div>
                            <span class="cs-modal-etiqueta">INFORMACIÓN DEL CLIENTE</span>
                            <h2 id="csModalTitulo">Detalles del servicio suspendido</h2>
                        </div>

                        <button type="button" class="cs-modal-cerrar"
                                data-cerrar-modal aria-label="Cerrar">×</button>
                    </header>

                    <div class="cs-modal-datos">
                        <div class="cs-modal-dato cs-modal-dato-principal">
                            <span>Cliente</span>
                            <strong id="csDetalleNombre"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Teléfono</span>
                            <strong id="csDetalleTelefono"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Correo</span>
                            <strong id="csDetalleCorreo"></strong>
                        </div>

                        <div class="cs-modal-dato cs-modal-dato-principal">
                            <span>Dirección</span>
                            <strong id="csDetalleDireccion"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>ID cliente</span>
                            <strong id="csDetalleIdCliente"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>ID servicio</span>
                            <strong id="csDetalleIdServicio"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Paquete</span>
                            <strong id="csDetallePaquete"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Tecnología</span>
                            <strong id="csDetalleTecnologia"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Fecha de contratación</span>
                            <strong id="csDetalleContratacion"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Fecha de instalación</span>
                            <strong id="csDetalleInstalacion"></strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Fecha de suspensión</span>
                            <strong>No registrada</strong>
                        </div>

                        <div class="cs-modal-dato">
                            <span>Estado</span>
                            <strong id="csDetalleEstado"></strong>
                        </div>
                    </div>

                    <footer class="cs-modal-pie">
                        <button type="button" class="cs-btn-modal-cerrar"
                                data-cerrar-modal>Cerrar</button>
                    </footer>

                </section>
            </div>

            <footer class="cs-pie-tabla">

                <span class="cs-resumen">
                    Mostrando <?= $desde ?> – <?= $hasta ?>
                    de <?= number_format($totalFiltrados) ?> servicios
                </span>

                <nav class="cs-paginacion" aria-label="Paginación">

                    <?php if ($pagina > 1): ?>
                        <a class="cs-pagina"
                           href="<?= escaparSuspendido(urlClientesSuspendidos(
                               $pagina - 1, $buscar, $paqueteSeleccionado, $porPagina
                           )) ?>">‹</a>
                    <?php endif; ?>

                    <?php
                    $inicioPaginas = max(1, $pagina - 2);
                    $finPaginas = min($totalPaginas, $pagina + 2);
                    ?>

                    <?php if ($inicioPaginas > 1): ?>
                        <a class="cs-pagina"
                           href="<?= escaparSuspendido(urlClientesSuspendidos(
                               1, $buscar, $paqueteSeleccionado, $porPagina
                           )) ?>">1</a>

                        <?php if ($inicioPaginas > 2): ?>
                            <span class="cs-puntos">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $inicioPaginas; $i <= $finPaginas; $i++): ?>
                        <a class="cs-pagina <?= $i === $pagina ? "activa" : "" ?>"
                           href="<?= escaparSuspendido(urlClientesSuspendidos(
                               $i, $buscar, $paqueteSeleccionado, $porPagina
                           )) ?>"
                           <?= $i === $pagina ? 'aria-current="page"' : "" ?>>
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($finPaginas < $totalPaginas): ?>

                        <?php if ($finPaginas < $totalPaginas - 1): ?>
                            <span class="cs-puntos">…</span>
                        <?php endif; ?>

                        <a class="cs-pagina"
                           href="<?= escaparSuspendido(urlClientesSuspendidos(
                               $totalPaginas, $buscar, $paqueteSeleccionado, $porPagina
                           )) ?>">
                            <?= $totalPaginas ?>
                        </a>

                    <?php endif; ?>

                    <?php if ($pagina < $totalPaginas): ?>
                        <a class="cs-pagina"
                           href="<?= escaparSuspendido(urlClientesSuspendidos(
                               $pagina + 1, $buscar, $paqueteSeleccionado, $porPagina
                           )) ?>">›</a>
                    <?php endif; ?>

                </nav>

                <form method="GET" action="suspendidos.php" class="cs-paginas-form">

                    <input type="hidden" name="buscar"
                           value="<?= escaparSuspendido($buscar) ?>">

                    <input type="hidden" name="paquete"
                           value="<?= escaparSuspendido($paqueteSeleccionado) ?>">

                    <label for="csPorPagina">Por página</label>

                    <select name="por_pagina" id="csPorPagina"
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

        <?php endif; ?>

    </section>

</main>

<script>
(() => {
    const formulario = document.getElementById("csFormularioFiltros");
    const buscador = document.getElementById("csBuscar");
    const paquete = document.getElementById("csPaquete");

    if (formulario && buscador && paquete) {
        let temporizador = null;

        buscador.addEventListener("input", () => {
            clearTimeout(temporizador);
            temporizador = setTimeout(() => formulario.requestSubmit(), 700);
        });

        paquete.addEventListener("change", () => {
            clearTimeout(temporizador);
            formulario.requestSubmit();
        });
    }

    const modal = document.getElementById("csModalDetalles");
    if (!modal) return;

    const campos = {
        nombre: "csDetalleNombre",
        telefono: "csDetalleTelefono",
        correo: "csDetalleCorreo",
        direccion: "csDetalleDireccion",
        idCliente: "csDetalleIdCliente",
        idServicio: "csDetalleIdServicio",
        paquete: "csDetallePaquete",
        tecnologia: "csDetalleTecnologia",
        contratacion: "csDetalleContratacion",
        instalacion: "csDetalleInstalacion",
        estado: "csDetalleEstado"
    };

    let botonAnterior = null;

    document.querySelectorAll(".cs-btn-ver").forEach(boton => {
        boton.addEventListener("click", () => {
            botonAnterior = boton;

            Object.entries(campos).forEach(([dato, id]) => {
                document.getElementById(id).textContent =
                    boton.dataset[dato] || "Sin información";
            });

            modal.classList.add("cs-modal-abierto");
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("cs-modal-activo");
            modal.querySelector(".cs-modal-cerrar").focus();
        });
    });

    function cerrarModal() {
        modal.classList.remove("cs-modal-abierto");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("cs-modal-activo");

        if (botonAnterior) botonAnterior.focus();
    }

    modal.querySelectorAll("[data-cerrar-modal]").forEach(boton => {
        boton.addEventListener("click", cerrarModal);
    });

    document.addEventListener("keydown", evento => {
        if (
            evento.key === "Escape" &&
            modal.classList.contains("cs-modal-abierto")
        ) {
            cerrarModal();
        }
    });
})();
</script>

</body>
</html>
