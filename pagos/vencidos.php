<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");

function escaparProximo($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

$serviciosVencidos = [];
$errorCarga = null;
$totalVencidos = 0;

require_once __DIR__ . "/includes/estado_pagos.php";

try {
    $conexion_plataforma = require __DIR__ . "/conexion_plataforma.php";
    require_once __DIR__ . "/conexion.php";

    // Situación según las mensualidades y pagos reales (no la fecha de instalación).
    $estadoPagos = estadoPagosPorServicio($conexion);

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
            s.fecha_instalacion,
            s.estado
        FROM servicios s
        INNER JOIN clientes c ON c.id_cliente = s.id_cliente
        LEFT JOIN direcciones_servicio d
            ON d.id_direccion = s.id_direccion
           AND d.id_cliente = s.id_cliente
        WHERE s.estado = 'ACTIVO'
        ORDER BY c.nombre, c.apellido_paterno, s.id_servicio
    ";

    $resultado = $conexion_plataforma->query($sql);
    $hoy = new DateTimeImmutable("today");

    while ($fila = $resultado->fetch_assoc()) {
        $ciclo = $estadoPagos[(int)$fila["id_servicio"]] ?? null;

        if ($ciclo === null || ($ciclo["tipo"] ?? "") !== "vencido") {
            continue;
        }

        $fila["nombre_completo"] = trim(
            ($fila["nombre"] ?? "") . " " .
            ($fila["apellido_paterno"] ?? "") . " " .
            ($fila["apellido_materno"] ?? "")
        );

        $fila["ciclo"] = $ciclo;
        $serviciosVencidos[] = $fila;
    }

    $totalVencidos = count($serviciosVencidos);

} catch (Throwable $e) {
    error_log("Error en módulo Próximos a vencer: " . $e->getMessage());
    $errorCarga = "No fue posible consultar los servicios. Intenta nuevamente más tarde.";
}

$paquetesDisponibles = array_values(array_unique(array_filter(array_map(
    fn($fila) => trim((string)($fila["paquete_referencia"] ?? "")),
    $serviciosVencidos
))));
natcasesort($paquetesDisponibles);
$paquetesDisponibles = array_values($paquetesDisponibles);

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes vencidos - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/vencidos.css?v=<?php echo filemtime(__DIR__ . '/css/vencidos.css'); ?>">
</head>

<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor proximos-page">
    <section class="px-panel">
        <header class="px-encabezado">
            <div class="px-titulo">
                <div class="px-icono" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M12 7v5l3 2"/>
                    </svg>
                </div>
                <div>
                    <span class="px-etiqueta">CLIENTES</span>
                    <h1>Clientes vencidos</h1>
                    <p>Servicios con una mensualidad sin pagar después de los 10 días de gracia. Se suspenden automáticamente el día 11.</p>
                </div>
            </div>
            <a href="index.php" class="px-regresar">← Regresar al centro de control</a>
        </header>

        <div class="px-resumen">
            <div class="px-indicador">
                <span>Servicios con plazo vencido</span>
                <strong><?= number_format($totalVencidos) ?></strong>
            </div>
            <div class="px-indicador px-indicador-info">
                <span>Fecha de consulta</span>
                <strong><?= (new DateTimeImmutable("today"))->format("d/m/Y") ?></strong>
            </div>
        </div>

        <div class="px-filtros">
            <label class="px-busqueda">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="m20 20-4-4"/>
                </svg>
                <input type="search" id="buscarProximo"
                       placeholder="Buscar cliente, teléfono, servicio o paquete..."
                       autocomplete="off">
            </label>

            <div class="px-filtros-secundarios">
                <select id="filtroPaquete" aria-label="Filtrar por paquete">
                    <option value="">Todos los paquetes</option>
                    <?php foreach ($paquetesDisponibles as $paquete): ?>
                        <option value="<?= escaparProximo($paquete) ?>">
                            <?= escaparProximo($paquete) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="ordenarProximo" aria-label="Ordenar servicios">
                    <option value="urgencia">Ordenar: Mayor urgencia</option>
                    <option value="alfabetico">Ordenar: Alfabético (A–Z)</option>
                    <option value="fecha">Ordenar: Fecha de pago</option>
                </select>
            </div>
            <span class="px-ayuda">La búsqueda y los filtros se aplican automáticamente</span>
        </div>

        <div class="px-tabla-contenedor">
            <table class="px-tabla" id="tablaProximos">
                <thead>
                    <tr>
                        <th>CLIENTE</th>
                        <th>ID SERVICIO</th>
                        <th>PAQUETE</th>
                        <th>VENCIMIENTO</th>
                        <th>LÍMITE</th>
                        <th>DÍAS</th>
                        <th>ADEUDO</th>
                        <th>SITUACIÓN</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($errorCarga): ?>
                    <tr><td colspan="9" class="px-vacio"><?= escaparProximo($errorCarga) ?></td></tr>
                <?php elseif (!$serviciosVencidos): ?>
                    <tr><td colspan="9" class="px-vacio">No hay servicios que mostrar en este periodo.</td></tr>
                <?php else: ?>
                    <?php foreach ($serviciosVencidos as $fila): ?>
                        <?php
                        $ciclo = $fila["ciclo"];
                        $fechaInstalacion = $fila["fecha_instalacion"] ?? "";
                        $instalacionMostrar = $fechaInstalacion && $fechaInstalacion !== "0000-00-00"
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
                        $direccion = implode(", ", array_filter(
                            $partesDireccion,
                            fn($v) => trim((string)$v) !== ""
                        ));
                        $direccion = $direccion ?: "Sin información";
                        ?>
                        <?php
                        $urgencias = [
                            "vencido" => 1,
                            "gracia" => 2,
                            "hoy" => 3,
                            "proximo" => 4,
                            "revisar" => 5
                        ];
                        $fechaOrden = $ciclo["fecha_pago"]
                            ? $ciclo["fecha_pago"]->format("Y-m-d")
                            : "9999-12-31";
                        $textoBusqueda = implode(" ", [
                            $fila["nombre_completo"],
                            $fila["telefono"] ?? "",
                            $fila["id_cliente"],
                            $fila["id_servicio"],
                            $fila["paquete_referencia"] ?? ""
                        ]);
                        ?>
                        <tr data-registro="1"
                            data-busqueda="<?= escaparProximo($textoBusqueda) ?>"
                            data-paquete="<?= escaparProximo($fila["paquete_referencia"] ?? "") ?>"
                            data-nombre="<?= escaparProximo($fila["nombre_completo"]) ?>"
                            data-fecha="<?= escaparProximo($fechaOrden) ?>"
                            data-urgencia="<?= $urgencias[$ciclo["tipo"]] ?? 5 ?>">
                            <td><strong class="px-nombre"><?= escaparProximo($fila["nombre_completo"]) ?></strong></td>
                            <td><?= escaparProximo($fila["id_servicio"]) ?></td>
                            <td><?= escaparProximo($fila["paquete_referencia"] ?: "Sin información") ?></td>
                            <td><?= $ciclo["fecha_pago"] ? $ciclo["fecha_pago"]->format("d/m/Y") : "Sin información" ?></td>
                            <td><?= $ciclo["limite"] ? $ciclo["limite"]->format("d/m/Y") : "Sin información" ?></td>
                            <td><?= escaparProximo($ciclo["dias"]) ?></td>
                            <td><strong><?= escaparProximo(textoAdeudo($ciclo)) ?></strong></td>
                            <td>
                                <span class="px-estado px-estado-<?= escaparProximo($ciclo["tipo"]) ?>">
                                    <?= escaparProximo($ciclo["situacion"]) ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="px-btn-ver"
                                    data-nombre="<?= escaparProximo($fila["nombre_completo"]) ?>"
                                    data-telefono="<?= escaparProximo($fila["telefono"] ?: "Sin información") ?>"
                                    data-direccion="<?= escaparProximo($direccion) ?>"
                                    data-id-cliente="<?= escaparProximo($fila["id_cliente"]) ?>"
                                    data-id-servicio="<?= escaparProximo($fila["id_servicio"]) ?>"
                                    data-paquete="<?= escaparProximo($fila["paquete_referencia"] ?: "Sin información") ?>"
                                    data-instalacion="<?= escaparProximo($instalacionMostrar) ?>"
                                    data-pago="<?= escaparProximo($ciclo["fecha_pago"] ? $ciclo["fecha_pago"]->format("d/m/Y") : "Sin información") ?>"
                                    data-limite="<?= escaparProximo($ciclo["limite"] ? $ciclo["limite"]->format("d/m/Y") : "Sin información") ?>"
                                    data-dias="<?= escaparProximo($ciclo["dias"]) ?>"
                                    data-adeudo="<?= escaparProximo(textoAdeudo($ciclo)) ?>"
                                    data-referencia="<?= escaparProximo($ciclo["referencia"]) ?>"
                                    data-situacion="<?= escaparProximo($ciclo["situacion"]) ?>">
                                    Ver detalles
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-pie">
            Mostrando <strong id="pxFilasVisibles"><?= count($serviciosVencidos) ?></strong> servicios
        </div>
    </section>

    <div class="px-modal" id="pxModal" aria-hidden="true">
        <div class="px-modal-fondo" data-cerrar-modal></div>
        <section class="px-modal-contenido" role="dialog" aria-modal="true"
                 aria-labelledby="pxModalTitulo">
            <header class="px-modal-encabezado">
                <div>
                    <span class="px-modal-etiqueta">INFORMACIÓN DEL CLIENTE</span>
                    <h2 id="pxModalTitulo">Detalles del servicio</h2>
                </div>
                <button type="button" class="px-modal-x" data-cerrar-modal aria-label="Cerrar">×</button>
            </header>
            <div class="px-modal-datos">
                <div class="px-dato px-dato-ancho"><span>Cliente</span><strong id="pxNombre"></strong></div>
                <div class="px-dato"><span>Teléfono</span><strong id="pxTelefono"></strong></div>
                <div class="px-dato px-dato-ancho"><span>Dirección</span><strong id="pxDireccion"></strong></div>
                <div class="px-dato"><span>ID cliente</span><strong id="pxIdCliente"></strong></div>
                <div class="px-dato"><span>ID servicio</span><strong id="pxIdServicio"></strong></div>
                <div class="px-dato"><span>Paquete</span><strong id="pxPaquete"></strong></div>
                <div class="px-dato"><span>Fecha de instalación</span><strong id="pxInstalacion"></strong></div>
                <div class="px-dato"><span>Fecha de pago</span><strong id="pxPago"></strong></div>
                <div class="px-dato"><span>Fecha límite</span><strong id="pxLimite"></strong></div>
                <div class="px-dato"><span>Días</span><strong id="pxDias"></strong></div>
                <div class="px-dato"><span>Adeudo</span><strong id="pxAdeudo"></strong></div>
                <div class="px-dato"><span>Referencia de pago</span><strong id="pxReferencia"></strong></div>
                <div class="px-dato px-dato-ancho"><span>Situación</span><strong id="pxSituacion"></strong></div>
            </div>
            <footer class="px-modal-pie">
                <button type="button" class="px-modal-cerrar" data-cerrar-modal>Cerrar</button>
            </footer>
        </section>
    </div>
</main>

<script>
(() => {
    const buscador = document.getElementById("buscarProximo");
    const filtroPaquete = document.getElementById("filtroPaquete");
    const ordenar = document.getElementById("ordenarProximo");
    const tabla = document.getElementById("tablaProximos");
    const contador = document.getElementById("pxFilasVisibles");

    if (!tabla) return;

    const cuerpo = tabla.querySelector("tbody");
    const filas = [...cuerpo.querySelectorAll("tr[data-registro]")];

    function aplicarFiltros() {
        const texto = (buscador?.value || "").toLocaleLowerCase("es").trim();
        const paquete = filtroPaquete?.value || "";
        const criterio = ordenar?.value || "urgencia";

        let visibles = filas.filter(fila => {
            const busqueda = (fila.dataset.busqueda || "")
                .toLocaleLowerCase("es");
            const coincideTexto = busqueda.includes(texto);
            const coincidePaquete = !paquete || fila.dataset.paquete === paquete;

            fila.hidden = !(coincideTexto && coincidePaquete);
            return coincideTexto && coincidePaquete;
        });

        visibles.sort((a, b) => {
            if (criterio === "alfabetico") {
                return (a.dataset.nombre || "").localeCompare(
                    b.dataset.nombre || "", "es", { sensitivity: "base" }
                );
            }

            if (criterio === "fecha") {
                return (a.dataset.fecha || "").localeCompare(b.dataset.fecha || "");
            }

            return Number(a.dataset.urgencia) - Number(b.dataset.urgencia)
                || (a.dataset.fecha || "").localeCompare(b.dataset.fecha || "");
        });

        visibles.forEach(fila => cuerpo.appendChild(fila));

        if (contador) contador.textContent = visibles.length;
    }

    buscador?.addEventListener("input", aplicarFiltros);
    filtroPaquete?.addEventListener("change", aplicarFiltros);
    ordenar?.addEventListener("change", aplicarFiltros);

    aplicarFiltros();

    const modal = document.getElementById("pxModal");
    if (!modal) return;

    const campos = {
        nombre: "pxNombre", telefono: "pxTelefono",
        direccion: "pxDireccion", idCliente: "pxIdCliente",
        idServicio: "pxIdServicio", paquete: "pxPaquete",
        instalacion: "pxInstalacion", pago: "pxPago",
        limite: "pxLimite", dias: "pxDias", situacion: "pxSituacion",
        adeudo: "pxAdeudo", referencia: "pxReferencia"
    };

    let botonAnterior = null;

    document.querySelectorAll(".px-btn-ver").forEach(boton => {
        boton.addEventListener("click", () => {
            botonAnterior = boton;
            Object.entries(campos).forEach(([dato, id]) => {
                document.getElementById(id).textContent =
                    boton.dataset[dato] || "Sin información";
            });
            modal.classList.add("px-modal-abierto");
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("px-modal-activo");
            modal.querySelector(".px-modal-x").focus();
        });
    });

    function cerrarModal() {
        modal.classList.remove("px-modal-abierto");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("px-modal-activo");
        if (botonAnterior) botonAnterior.focus();
    }

    modal.querySelectorAll("[data-cerrar-modal]").forEach(boton => {
        boton.addEventListener("click", cerrarModal);
    });

    document.addEventListener("keydown", evento => {
        if (evento.key === "Escape" && modal.classList.contains("px-modal-abierto")) {
            cerrarModal();
        }
    });
})();
</script>

</body>
</html>
