<?php
require_once __DIR__ . "/includes/sesion.php";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set("America/Mexico_City");

function escNotificacion($valor) {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

$notificaciones = [];
$errorCarga = null;

$categorias = [
    "proximo" => 0,
    "hoy" => 0,
    "gracia" => 0,
    "vencido" => 0
];

try {
    require_once __DIR__ . "/conexion.php";
    require_once __DIR__ . "/conexion_plataforma.php";

    /*
     * Consultar obligaciones pendientes y sumar exclusivamente
     * las aplicaciones relacionadas con pagos PAGADOS.
     */
    $sql = "
        SELECT
            o.id_obligacion,
            o.id_cliente,
            o.id_servicio,
            o.monto,
            o.fecha_vencimiento,
            o.referencia_cobro,
            COALESCE(a.total_pagado, 0) AS total_pagado,
            GREATEST(
                o.monto - COALESCE(a.total_pagado, 0),
                0
            ) AS saldo_pendiente
        FROM obligaciones_pago o
        LEFT JOIN (
            SELECT
                po.id_obligacion,
                SUM(po.monto_aplicado) AS total_pagado
            FROM pagos_obligaciones po
            INNER JOIN pagos p
                ON p.id_pago = po.id_pago
            WHERE p.estado_pago = 'PAGADO'
            GROUP BY po.id_obligacion
        ) a ON a.id_obligacion = o.id_obligacion
        WHERE o.concepto = 'MENSUALIDAD'
          AND o.estado = 'PENDIENTE'
          AND o.fecha_vencimiento IS NOT NULL
          AND o.monto > COALESCE(a.total_pagado, 0)
        ORDER BY o.fecha_vencimiento ASC, o.id_servicio ASC
    ";

    $resultado = $conexion->query($sql);
    $obligaciones = $resultado->fetch_all(MYSQLI_ASSOC);

    /*
     * Consultar los clientes y servicios en la plataforma remota.
     */
    $datosServicios = [];

    if ($obligaciones) {
        $idsServicios = array_unique(array_map(
            fn($fila) => (int)$fila["id_servicio"],
            $obligaciones
        ));

        $listaIds = implode(",", $idsServicios);

        $sqlRemoto = "
            SELECT
                s.id_servicio,
                s.id_cliente,
                s.estado AS estado_servicio,
                s.paquete_referencia,
                c.nombre,
                c.apellido_paterno,
                c.apellido_materno,
                c.telefono
            FROM servicios s
            INNER JOIN clientes c
                ON c.id_cliente = s.id_cliente
            WHERE s.id_servicio IN ($listaIds)
        ";

        $resultadoRemoto = $conexion_plataforma->query($sqlRemoto);

        while ($fila = $resultadoRemoto->fetch_assoc()) {
            $datosServicios[(int)$fila["id_servicio"]] = $fila;
        }
    }

    $hoy = new DateTimeImmutable("today");

    foreach ($obligaciones as $obligacion) {
        $idServicio = (int)$obligacion["id_servicio"];
        $idCliente = (int)$obligacion["id_cliente"];

        $cliente = $datosServicios[$idServicio] ?? null;

        // No relacionar datos si los identificadores no coinciden.
        if (
            !$cliente ||
            (int)$cliente["id_cliente"] !== $idCliente
        ) {
            $cliente = null;
        }

        $nombre = $cliente
            ? trim(
                ($cliente["nombre"] ?? "") . " " .
                ($cliente["apellido_paterno"] ?? "") . " " .
                ($cliente["apellido_materno"] ?? "")
            )
            : "Cliente no encontrado";

        $telefono = trim((string)($cliente["telefono"] ?? ""));
        $paquete = trim((string)($cliente["paquete_referencia"] ?? ""));

        $fechaVencimiento = new DateTimeImmutable(
            $obligacion["fecha_vencimiento"]
        );

        $diferencia = (int)$hoy
            ->diff($fechaVencimiento)
            ->format("%r%a");

        if ($diferencia > 0) {
            $tipo = "proximo";
            $situacion = "Próximo a vencer";
            $dias = "Faltan " . $diferencia . " días";
            $inicioMensaje = "Te recordamos que tu mensualidad está próxima a vencer.";
        } elseif ($diferencia === 0) {
            $tipo = "hoy";
            $situacion = "Vence hoy";
            $dias = "Vence hoy";
            $inicioMensaje = "Te recordamos que hoy vence tu mensualidad.";
        } elseif (abs($diferencia) <= 10) {
            $tipo = "gracia";
            $situacion = "En periodo de gracia";
            $dias = abs($diferencia) . " días desde el vencimiento";
            $inicioMensaje = "Tu mensualidad ya venció y todavía se encuentra dentro del periodo de gracia.";
        } else {
            $tipo = "vencido";
            $situacion = "Vencido";
            $dias = (abs($diferencia) - 10) . " días fuera del periodo de gracia";
            $inicioMensaje = "Tu mensualidad está vencida y el periodo de gracia ha concluido.";
        }

        $categorias[$tipo]++;

        $fechaTexto = $fechaVencimiento->format("d/m/Y");
        $fechaLimite = $fechaVencimiento->modify("+10 days");
        $fechaLimiteTexto = $fechaLimite->format("d/m/Y");

        $saldo = (float)$obligacion["saldo_pendiente"];
        $saldoTexto = number_format($saldo, 2, ".", ",");

        $referencia = (string)$obligacion["referencia_cobro"];

        $mensaje =
            "Hola, " . ($nombre ?: "cliente") . ".\n\n" .
            "Te contactamos de UBB Comunicaciones. " .
            $inicioMensaje . "\n\n" .
            "Servicio: " . $idServicio . "\n" .
            "Paquete: " . ($paquete ?: "No especificado") . "\n" .
            "Fecha de vencimiento: " . $fechaTexto . "\n" .
            "Saldo pendiente: $" . $saldoTexto . "\n" .
            "Referencia de pago: " . $referencia . "\n\n" .
            "Si ya realizaste tu pago, por favor ignora este aviso y compártenos tu comprobante para verificarlo.\n\n" .
            "Gracias por tu preferencia.\nUBB Comunicaciones";

        $notificaciones[] = [
            "id_obligacion" => $obligacion["id_obligacion"],
            "id_cliente" => $idCliente,
            "id_servicio" => $idServicio,
            "nombre" => $nombre ?: "Sin nombre",
            "telefono" => $telefono,
            "paquete" => $paquete ?: "Sin información",
            "fecha_vencimiento" => $fechaTexto,
            "fecha_limite" => $fechaLimiteTexto,
            "dias" => $dias,
            "tipo" => $tipo,
            "situacion" => $situacion,
            "saldo" => $saldo,
            "referencia" => $referencia,
            "mensaje" => $mensaje
        ];
    }

} catch (Throwable $e) {
    error_log("Error en módulo de notificaciones: " . $e->getMessage());
    $errorCarga = "No fue posible consultar las obligaciones. Intenta nuevamente más tarde.";
}

$paquetesDisponibles = array_values(array_unique(array_filter(array_map(
    fn($fila) => $fila["paquete"],
    $notificaciones
))));

natcasesort($paquetesDisponibles);
$paquetesDisponibles = array_values($paquetesDisponibles);

$totalNotificaciones = count($notificaciones);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/vencidos.css?v=<?php echo filemtime(__DIR__ . '/css/vencidos.css'); ?>">
    <link rel="stylesheet" href="css/notificaciones.css?v=<?php echo filemtime(__DIR__ . '/css/notificaciones.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist notificaciones">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            </span>
            <div>
                <h1>Notificaciones de pago</h1>
                <p>Prepara recordatorios personalizados según el estado de cada mensualidad.</p>
            </div>
        </div>
    </header>

    <?php if ($errorCarga): ?>

        <div class="cc-panel nt-error-panel">
            <?= escNotificacion($errorCarga) ?>
        </div>

    <?php else: ?>

        <!-- =================================================
             RESUMEN
        ================================================== -->

        <div class="cc-kpis hist-kpis adeudos-kpis">

            <div class="cc-kpi hist-kpi--confirmados">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 13v3l2 1"/></svg></span>
                    <span class="cc-kpi-nombre">Próximos a vencer</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($categorias["proximo"]) ?></strong>
                <span class="cc-kpi-detalle">Recordatorio antes del vencimiento</span>
            </div>

            <div class="cc-kpi cc-kpi--proximos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                    <span class="cc-kpi-nombre">Vencen hoy</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($categorias["hoy"]) ?></strong>
                <span class="cc-kpi-detalle">Último día para pagar a tiempo</span>
            </div>

            <div class="cc-kpi cc-kpi--pagos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9"/><path d="M12 7v5h5"/></svg></span>
                    <span class="cc-kpi-nombre">En periodo de gracia</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($categorias["gracia"]) ?></strong>
                <span class="cc-kpi-detalle">Vencidos, aún sin corte</span>
            </div>

            <div class="cc-kpi cc-kpi--suspendidos">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>
                    <span class="cc-kpi-nombre">Vencidos</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($categorias["vencido"]) ?></strong>
                <span class="cc-kpi-detalle">Pasaron el periodo de gracia</span>
            </div>

        </div>


        <!-- =================================================
             FILTROS (se aplican al momento, sin recargar)
        ================================================== -->

        <div class="cc-panel hist-filtros">

            <div class="hist-buscador adeudos-buscador">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" id="ntBuscar"
                       placeholder="Buscar cliente, teléfono, servicio o referencia..."
                       aria-label="Buscar notificación"
                       autocomplete="off">
            </div>

            <label class="hist-campo">
                <span>Situación</span>
                <select id="ntCategoria">
                    <option value="">Todas las situaciones</option>
                    <option value="proximo">Próximos a vencer</option>
                    <option value="hoy">Vencen hoy</option>
                    <option value="gracia">En periodo de gracia</option>
                    <option value="vencido">Vencidos</option>
                </select>
            </label>

            <label class="hist-campo">
                <span>Paquete</span>
                <select id="ntPaquete">
                    <option value="">Todos los paquetes</option>
                    <?php foreach ($paquetesDisponibles as $paquete): ?>
                        <option value="<?= escNotificacion($paquete) ?>"><?= escNotificacion($paquete) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <span class="adeudos-ayuda">
                <strong><?= number_format($totalNotificaciones) ?></strong> mensualidades con saldo pendiente.
                Los pagos confirmados y sus abonos se descuentan del saldo. La búsqueda y los filtros se aplican al momento.
            </span>

        </div>


        <!-- =================================================
             TABLA
        ================================================== -->

        <section class="cc-panel hist-tabla">

            <div class="hist-tabla-scroll">
                <table id="ntTabla">
                    <thead>
                        <tr>
                            <th class="mens-col-cliente">Cliente</th>
                            <th>Servicio</th>
                            <th>Vencimiento</th>
                            <th class="hist-num">Saldo</th>
                            <th>Situación</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$notificaciones): ?>
                        <tr>
                            <td colspan="6" class="hist-vacio">
                                No hay mensualidades pendientes con saldo por cobrar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($notificaciones as $fila): ?>
                            <?php
                            $textoBusqueda = implode(" ", [
                                $fila["nombre"],
                                $fila["telefono"],
                                $fila["id_cliente"],
                                $fila["id_servicio"],
                                $fila["paquete"],
                                $fila["referencia"]
                            ]);
                            ?>
                            <tr
                                data-registro="1"
                                data-busqueda="<?= escNotificacion($textoBusqueda) ?>"
                                data-categoria="<?= escNotificacion($fila["tipo"]) ?>"
                                data-paquete="<?= escNotificacion($fila["paquete"]) ?>"
                            >
                                <td class="hist-cliente mens-col-cliente">
                                    <strong><?= escNotificacion($fila["nombre"]) ?></strong>
                                    <?php if ($fila["telefono"]): ?>
                                        <span><?= escNotificacion($fila["telefono"]) ?></span>
                                    <?php else: ?>
                                        <span class="nt-sin-telefono">Sin teléfono</span>
                                    <?php endif; ?>
                                </td>
                                <td class="hist-cliente">
                                    <strong>#<?= escNotificacion($fila["id_servicio"]) ?></strong>
                                    <?php if ($fila["paquete"]): ?><span><?= escNotificacion($fila["paquete"]) ?></span><?php endif; ?>
                                    <?php if ($fila["referencia"]): ?><span class="mens-ref"><?= escNotificacion($fila["referencia"]) ?></span><?php endif; ?>
                                </td>
                                <td class="hist-cliente">
                                    <strong><?= escNotificacion($fila["fecha_vencimiento"]) ?></strong>
                                    <span>Gracia hasta <?= escNotificacion($fila["fecha_limite"]) ?></span>
                                </td>
                                <td class="hist-num">
                                    <strong class="px-importe-vencido">$<?= number_format($fila["saldo"], 2) ?></strong>
                                </td>
                                <td>
                                    <span class="nt-estado nt-estado-<?= escNotificacion($fila["tipo"]) ?>">
                                        <?= escNotificacion($fila["situacion"]) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($fila["telefono"] !== ""): ?>
                                        <button
                                            type="button"
                                            class="hist-boton hist-boton--principal nt-btn-copiar"
                                            data-mensaje="<?= escNotificacion($fila["mensaje"]) ?>"
                                        >Copiar mensaje</button>
                                    <?php else: ?>
                                        <span class="nt-falta-telefono">Falta teléfono</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="adeudos-pie">
                Mostrando <strong id="ntFilasVisibles"><?= $totalNotificaciones ?></strong>
                de <?= $totalNotificaciones ?> mensualidades
            </div>

        </section>

    <?php endif; ?>

</main>

<script>
(() => {
    const buscar = document.getElementById("ntBuscar");
    const categoria = document.getElementById("ntCategoria");
    const paquete = document.getElementById("ntPaquete");
    const tabla = document.getElementById("ntTabla");
    const contador = document.getElementById("ntFilasVisibles");

    if (tabla) {
        const filas = [...tabla.querySelectorAll("tbody tr[data-registro]")];

        function aplicarFiltros() {
            const texto = (buscar?.value || "").toLocaleLowerCase("es").trim();
            const tipo = categoria?.value || "";
            const plan = paquete?.value || "";
            let visibles = 0;

            filas.forEach(fila => {
                const contenido = (fila.dataset.busqueda || "")
                    .toLocaleLowerCase("es");

                const coincideTexto = contenido.includes(texto);
                const coincideTipo = !tipo || fila.dataset.categoria === tipo;
                const coincidePaquete = !plan || fila.dataset.paquete === plan;

                const mostrar = coincideTexto && coincideTipo && coincidePaquete;

                fila.hidden = !mostrar;

                if (mostrar) visibles++;
            });

            if (contador) contador.textContent = visibles;
        }

        buscar?.addEventListener("input", aplicarFiltros);
        categoria?.addEventListener("change", aplicarFiltros);
        paquete?.addEventListener("change", aplicarFiltros);
    }

    async function copiarMensaje(boton) {
        const mensaje = boton.dataset.mensaje || "";
        const textoOriginal = boton.textContent;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(mensaje);
            } else {
                const temporal = document.createElement("textarea");
                temporal.value = mensaje;
                temporal.style.position = "fixed";
                temporal.style.opacity = "0";
                document.body.appendChild(temporal);
                temporal.select();

                const copiado = document.execCommand("copy");
                temporal.remove();

                if (!copiado) throw new Error("No se pudo copiar");
            }

            boton.textContent = "¡Copiado!";
            boton.classList.add("nt-copiado");

            setTimeout(() => {
                boton.textContent = textoOriginal;
                boton.classList.remove("nt-copiado");
            }, 1800);

        } catch (error) {
            boton.textContent = "No se pudo copiar";

            setTimeout(() => {
                boton.textContent = textoOriginal;
            }, 2000);
        }
    }

    document.querySelectorAll(".nt-btn-copiar").forEach(boton => {
        boton.addEventListener("click", () => copiarMensaje(boton));
    });
})();
</script>

</body>
</html>
