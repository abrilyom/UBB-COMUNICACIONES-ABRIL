<?php
declare(strict_types=1);
require_once __DIR__ . "/includes/sesion.php";

require_once __DIR__ . '/conexion.php';

function escapar($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

$porPagina = 25;
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$busqueda = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));

$estadosValidos = ['', 'ACTIVO', 'INACTIVO', 'POR_REVISAR'];

if (!in_array($estado, $estadosValidos, true)) {
    $estado = '';
}

$error = null;
$clientes = [];
$totalClientes = 0;
$totalPaginas = 1;
$conteos = [
    'ACTIVO' => 0,
    'INACTIVO' => 0,
    'POR_REVISAR' => 0
];

try {
    // La información de clientes y servicios se consulta en modo lectura.
    $remota = require __DIR__ . '/conexion_plataforma.php';

    // Indicadores generales.
    $resultadoConteos = $remota->query("
        SELECT estado, COUNT(*) AS total
        FROM clientes
        GROUP BY estado
    ");

    while ($fila = $resultadoConteos->fetch_assoc()) {
        if (isset($conteos[$fila['estado']])) {
            $conteos[$fila['estado']] = (int)$fila['total'];
        }
    }

    // Condiciones de búsqueda y filtro.
    $condiciones = [];
    $parametros = [];
    $tipos = '';

    if ($busqueda !== '') {
        $condiciones[] = "(
            CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) LIKE ?
            OR c.telefono LIKE ?
            OR c.correo LIKE ?
            OR c.codigo_cliente LIKE ?
            OR CAST(c.id_cliente AS CHAR) LIKE ?
        )";

        $buscar = '%' . $busqueda . '%';

        for ($i = 0; $i < 5; $i++) {
            $parametros[] = $buscar;
            $tipos .= 's';
        }
    }

    if ($estado !== '') {
        $condiciones[] = 'c.estado = ?';
        $parametros[] = $estado;
        $tipos .= 's';
    }

    $where = $condiciones
        ? 'WHERE ' . implode(' AND ', $condiciones)
        : '';

    // Total de clientes para la paginación.
    $sqlTotal = "
        SELECT COUNT(*) AS total
        FROM clientes c
        $where
    ";

    $stmtTotal = $remota->prepare($sqlTotal);

    if ($tipos !== '') {
        $stmtTotal->bind_param($tipos, ...$parametros);
    }

    $stmtTotal->execute();
    $totalClientes = (int)$stmtTotal->get_result()->fetch_assoc()['total'];
    $stmtTotal->close();

    $totalPaginas = max(1, (int)ceil($totalClientes / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    // Listado con cantidad de servicios por cliente.
    $sqlClientes = "
        SELECT
            c.id_cliente,
            c.codigo_cliente,
            c.nombre,
            c.apellido_paterno,
            c.apellido_materno,
            c.telefono,
            c.correo,
            c.estado,
            COALESCE(s.total_servicios, 0) AS total_servicios
        FROM clientes c
        LEFT JOIN (
            SELECT id_cliente, COUNT(*) AS total_servicios
            FROM servicios
            GROUP BY id_cliente
        ) s ON s.id_cliente = c.id_cliente
        $where
        ORDER BY c.nombre, c.apellido_paterno, c.id_cliente
        LIMIT ? OFFSET ?
    ";

    $stmt = $remota->prepare($sqlClientes);

    $parametrosListado = $parametros;
    $parametrosListado[] = $porPagina;
    $parametrosListado[] = $offset;
    $tiposListado = $tipos . 'ii';

    $stmt->bind_param($tiposListado, ...$parametrosListado);
    $stmt->execute();

    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $clientes[] = $fila;
    }

    $stmt->close();
    $remota->close();

} catch (Throwable $e) {
    error_log('UBB listado de clientes: ' . $e->getMessage());
    $error = 'No fue posible consultar los clientes. Revisa la conexión con la base de servicios.';
}

function urlPagina(int $pagina, string $busqueda, string $estado): string {
    return '?' . http_build_query([
        'pagina' => $pagina,
        'q' => $busqueda,
        'estado' => $estado
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">

    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
    <style>
        /* Estado del cliente */
        .cliente-estado {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .3px;
            white-space: nowrap;
        }
        .cliente-estado.activo { color: #166534; background: #dcfce7; }
        .cliente-estado.inactivo { color: #991b1b; background: #fee2e2; }
        .cliente-estado.revisar { color: #92400e; background: #fef3c7; }

        /* Tarjetas que filtran por estado */
        .clientes-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .hist a.cc-kpi:hover { transform: translateY(-2px); }
        .clientes-kpi--activa { box-shadow: 0 0 0 2px var(--cc-acento), var(--cc-sombra) !important; }
        .clientes-kpi--inactivos {
            --cc-acento: #8a8a96;
            --cc-acento-fondo: #f4f4f7;
            --cc-acento-borde: #e3e3ea;
            border-color: var(--cc-acento-borde);
            background: linear-gradient(160deg, var(--cc-acento-fondo) 0%, var(--cc-acento-fondo) 60%, #ffffff 140%);
        }

        .cliente-servicios { text-align: center; font-weight: 700; }
        .cliente-correo { color: var(--cc-texto-suave); overflow-wrap: anywhere; }

        /* Paginación */
        .clientes-paginacion {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 13px 16px;
            border-top: 1px solid var(--cc-borde);
            background: #fcf8fd;
            color: var(--cc-texto-suave);
            font-size: 13px;
        }
        .clientes-paginacion div { display: flex; gap: 8px; }

        @media (max-width: 900px) {
            .clientes-kpis { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body>

<?php
require __DIR__ . "/includes/encabezado.php";
require __DIR__ . "/includes/panel_lateral.php";
?>

<main class="contenedor cc hist clientes">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 3.2a4 4 0 0 1 0 7.6M20 21v-2a4 4 0 0 0-3-3.9"/></svg>
            </span>
            <div>
                <h1>Directorio de clientes</h1>
                <p>Información consultada desde la plataforma de servicios.</p>
            </div>
        </div>
    </header>

    <?php if ($error !== null): ?>

        <div class="cc-panel nt-error-panel">
            <?= escapar($error) ?>
        </div>

    <?php else: ?>

        <!-- =================================================
             RESUMEN (cada tarjeta filtra por su estado)
        ================================================== -->

        <div class="cc-kpis hist-kpis clientes-kpis">

            <a class="cc-kpi cc-kpi--activos <?= $estado === 'ACTIVO' ? 'clientes-kpi--activa' : '' ?>"
               href="<?= escapar(urlPagina(1, $busqueda, $estado === 'ACTIVO' ? '' : 'ACTIVO')) ?>">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                    <span class="cc-kpi-nombre">Clientes activos</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($conteos['ACTIVO']) ?></strong>
                <span class="cc-kpi-detalle"><?= $estado === 'ACTIVO' ? 'Mostrando solo activos · clic para quitar' : 'Clic para ver solo activos' ?></span>
            </a>

            <a class="cc-kpi cc-kpi--proximos <?= $estado === 'POR_REVISAR' ? 'clientes-kpi--activa' : '' ?>"
               href="<?= escapar(urlPagina(1, $busqueda, $estado === 'POR_REVISAR' ? '' : 'POR_REVISAR')) ?>">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v3M11 14h.01"/></svg></span>
                    <span class="cc-kpi-nombre">Clientes por revisar</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($conteos['POR_REVISAR']) ?></strong>
                <span class="cc-kpi-detalle"><?= $estado === 'POR_REVISAR' ? 'Mostrando solo por revisar · clic para quitar' : 'Clic para ver solo por revisar' ?></span>
            </a>

            <a class="cc-kpi clientes-kpi--inactivos <?= $estado === 'INACTIVO' ? 'clientes-kpi--activa' : '' ?>"
               href="<?= escapar(urlPagina(1, $busqueda, $estado === 'INACTIVO' ? '' : 'INACTIVO')) ?>">
                <div class="cc-kpi-cabeza">
                    <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg></span>
                    <span class="cc-kpi-nombre">Clientes inactivos</span>
                </div>
                <strong class="cc-kpi-numero"><?= number_format($conteos['INACTIVO']) ?></strong>
                <span class="cc-kpi-detalle"><?= $estado === 'INACTIVO' ? 'Mostrando solo inactivos · clic para quitar' : 'Clic para ver solo inactivos' ?></span>
            </a>

        </div>


        <!-- =================================================
             FILTROS (buscan solos al escribir)
        ================================================== -->

        <form class="cc-panel hist-filtros" id="filtroEstadoClientes" method="GET" action="clientes.php">

            <label class="hist-campo">
                <span>Estado</span>
                <select name="estado" id="estadoClientes">
                    <option value="">Todos los estados</option>
                    <option value="ACTIVO" <?= $estado === 'ACTIVO' ? 'selected' : '' ?>>Activos</option>
                    <option value="INACTIVO" <?= $estado === 'INACTIVO' ? 'selected' : '' ?>>Inactivos</option>
                    <option value="POR_REVISAR" <?= $estado === 'POR_REVISAR' ? 'selected' : '' ?>>Por revisar</option>
                </select>
            </label>

            <div class="hist-buscador">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" id="buscarCliente"
                       value="<?= escapar($busqueda) ?>"
                       placeholder="Buscar por nombre, teléfono, correo, código o ID..."
                       aria-label="Buscar cliente"
                       autocomplete="off">
                <button type="submit">Buscar</button>
            </div>

            <?php if ($busqueda !== '' || $estado !== ''): ?>
                <div class="hist-activos">
                    <span class="hist-activos-texto">Mostrando resultados filtrados:</span>
                    <?php if ($busqueda !== ''): ?>
                        <span class="hist-activos-etiqueta">Búsqueda: «<?= escapar($busqueda) ?>»</span>
                    <?php endif; ?>
                    <?php if ($estado !== ''): ?>
                        <span class="hist-activos-etiqueta">Estado: <?= escapar(str_replace('_', ' ', $estado)) ?></span>
                    <?php endif; ?>
                    <a class="hist-limpiar" href="clientes.php">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        Limpiar filtros
                    </a>
                </div>
            <?php endif; ?>

        </form>


        <!-- =================================================
             TABLA
        ================================================== -->

        <section class="cc-panel hist-tabla clientes-tabla">

            <?php if (count($clientes) === 0): ?>

                <div class="hist-vacio">
                    No se encontraron clientes con esos criterios.
                </div>

            <?php else: ?>

                <div class="hist-tabla-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th class="mens-col-cliente">Cliente</th>
                                <th>Código</th>
                                <th>Teléfono</th>
                                <th>Correo</th>
                                <th class="cliente-servicios">Servicios</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <?php
                            $nombre = trim(
                                ($cliente['nombre'] ?? '') . ' ' .
                                ($cliente['apellido_paterno'] ?? '') . ' ' .
                                ($cliente['apellido_materno'] ?? '')
                            );

                            $claseEstado = [
                                'ACTIVO' => 'activo',
                                'INACTIVO' => 'inactivo',
                                'POR_REVISAR' => 'revisar'
                            ][$cliente['estado']] ?? 'revisar';
                            ?>
                            <tr>
                                <td class="hist-cliente mens-col-cliente">
                                    <strong><?= escapar($nombre !== '' ? $nombre : 'Sin nombre registrado') ?></strong>
                                    <span>ID <?= escapar($cliente['id_cliente']) ?></span>
                                </td>

                                <td class="hist-referencia"><?= escapar($cliente['codigo_cliente'] ?: '—') ?></td>
                                <td class="hist-fecha"><?= escapar($cliente['telefono'] ?: '—') ?></td>
                                <td class="cliente-correo"><?= escapar($cliente['correo'] ?: '—') ?></td>

                                <td class="cliente-servicios">
                                    <?= (int)$cliente['total_servicios'] ?>
                                </td>

                                <td>
                                    <span class="cliente-estado <?= escapar($claseEstado) ?>">
                                        <?= escapar(str_replace('_', ' ', $cliente['estado'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <a class="hist-boton hist-boton--principal"
                                       href="ver_cliente.php?id=<?= (int)$cliente['id_cliente'] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
                                        Ver pagos
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

            <div class="clientes-paginacion">
                <span>
                    Página <?= $pagina ?> de <?= $totalPaginas ?> ·
                    <?= number_format($totalClientes) ?> clientes encontrados
                </span>

                <div>
                    <?php if ($pagina > 1): ?>
                        <a class="hist-boton" href="<?= escapar(urlPagina($pagina - 1, $busqueda, $estado)) ?>">
                            ← Anterior
                        </a>
                    <?php endif; ?>

                    <?php if ($pagina < $totalPaginas): ?>
                        <a class="hist-boton" href="<?= escapar(urlPagina($pagina + 1, $busqueda, $estado)) ?>">
                            Siguiente →
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    <?php endif; ?>

</main>

<script>
/* Búsqueda automática: al escribir (tras una pausa) o al cambiar el estado
   se piden los resultados y solo se reemplazan los filtros activos y la
   tabla, sin recargar ni perder el cursor. */
(function () {
    const formulario = document.getElementById("filtroEstadoClientes");
    if (!formulario) return;

    const buscador = document.getElementById("buscarCliente");
    const estado = document.getElementById("estadoClientes");
    let espera = null;
    let peticion = null;

    async function actualizar() {
        const datos = new URLSearchParams();
        if (buscador.value.trim()) datos.set("q", buscador.value.trim());
        if (estado.value) datos.set("estado", estado.value);
        const url = "clientes.php" + (datos.toString() ? "?" + datos.toString() : "");

        if (peticion) peticion.abort();
        peticion = new AbortController();
        document.body.classList.add("hist-cargando");

        try {
            const respuesta = await fetch(url, { signal: peticion.signal, credentials: "same-origin" });
            if (!respuesta.ok) throw new Error("HTTP " + respuesta.status);

            const nuevoDoc = new DOMParser().parseFromString(await respuesta.text(), "text/html");

            const avisoActual = formulario.querySelector(".hist-activos");
            const avisoNuevo = nuevoDoc.querySelector(".hist-activos");
            if (avisoActual) avisoActual.remove();
            if (avisoNuevo) formulario.appendChild(avisoNuevo);

            ["main .clientes-kpis", "main .clientes-tabla"].forEach(function (selector) {
                const actual = document.querySelector(selector);
                const nuevo = nuevoDoc.querySelector(selector);
                if (actual && nuevo) actual.replaceWith(nuevo);
            });

            history.replaceState(null, "", url);
        } catch (error) {
            if (error.name !== "AbortError") {
                window.location.href = url; // Si algo falla, recarga la página normal
            }
        } finally {
            document.body.classList.remove("hist-cargando");
        }
    }

    buscador.addEventListener("input", function () {
        clearTimeout(espera);
        espera = setTimeout(actualizar, 400);
    });

    estado.addEventListener("change", function () {
        clearTimeout(espera);
        actualizar();
    });

    formulario.addEventListener("submit", function (evento) {
        evento.preventDefault();
        clearTimeout(espera);
        actualizar();
    });
})();
</script>

</body>
</html>
