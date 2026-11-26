<?php
declare(strict_types=1);

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

    <style>
        .clientes-contenedor {
            max-width: none;
            margin: 0;
            padding: 0 0 40px;
        }

        .clientes-encabezado {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .clientes-encabezado h2 {
            color: #683572;
            margin: 0 0 6px;
        }

        .clientes-encabezado p {
            color: #71677d;
            margin: 0;
        }

        .clientes-resumen {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .cliente-indicador {
            background: #fff;
            border: 1px solid #ede3ef;
            border-radius: 14px;
            padding: 19px;
            box-shadow: 0 3px 12px rgba(85,41,94,.05);
        }

        .cliente-indicador span {
            display: block;
            color: #71677d;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .cliente-indicador strong {
            color: #7c4587;
            font-size: 27px;
        }

        .clientes-filtros {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            background: #fff;
            border: 1px solid #ede3ef;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .clientes-filtros input,
        .clientes-filtros select {
            min-height: 43px;
            border: 1px solid #e4d6e7;
            border-radius: 9px;
            padding: 9px 12px;
            font: inherit;
            background: #fff;
        }

        .clientes-filtros input {
            flex: 1 1 280px;
        }

        .clientes-filtros select {
            flex: 0 1 190px;
        }

        .btn-clientes {
            border: 0;
            border-radius: 9px;
            padding: 11px 18px;
            background: #ac70b7;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-clientes:hover {
            background: #a35ab2;
        }

        .clientes-tabla-contenedor {
            background: #fff;
            border: 1px solid #ede3ef;
            border-radius: 14px;
            overflow: hidden;
        }

        .clientes-tabla-scroll {
            overflow-x: auto;
        }

        .clientes-tabla {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .clientes-tabla th,
        .clientes-tabla td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1eaf2;
            text-align: left;
            font-size: 13px;
        }

        .clientes-tabla th {
            background: #f6f2f7;
            color: #6a4c70;
            font-weight: 700;
        }

        .clientes-tabla tbody tr:hover {
            background: #fbf8fc;
        }

        .cliente-nombre {
            color: #54315b;
            font-weight: 700;
        }

        .cliente-secundario {
            display: block;
            color: #82788d;
            font-size: 12px;
            margin-top: 4px;
        }

        .cliente-estado {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .cliente-estado.activo {
            color: #166534;
            background: #dcfce7;
        }

        .cliente-estado.inactivo {
            color: #991b1b;
            background: #fee2e2;
        }

        .cliente-estado.revisar {
            color: #92400e;
            background: #fef3c7;
        }

        .cliente-servicios {
            text-align: center;
            font-weight: 700;
            color: #7c4587;
        }

        .cliente-accion {
            color: #8b4c97;
            font-weight: 700;
            text-decoration: none;
        }

        .cliente-accion:hover {
            text-decoration: underline;
        }

        .clientes-vacio,
        .clientes-error {
            padding: 28px;
            text-align: center;
            color: #71677d;
        }

        .clientes-error {
            color: #991b1b;
            background: #fef2f2;
            border-radius: 12px;
        }

        .clientes-paginacion {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 18px;
        }

        .clientes-paginacion span {
            color: #71677d;
            font-size: 13px;
        }

        .clientes-paginacion a {
            color: #8b4c97;
            font-weight: 700;
            text-decoration: none;
            margin-left: 14px;
        }

        @media (max-width: 650px) {
            .clientes-resumen {
                grid-template-columns: 1fr;
            }

            .clientes-contenedor {
                padding: 0 12px 30px;
            }
        }
    </style>
</head>
<body>

<?php
require __DIR__ . "/includes/encabezado.php";
require __DIR__ . "/includes/panel_lateral.php";
?>

<main class="contenedor clientes-contenedor">

    <div class="clientes-encabezado">
        <div>
            <h2>Directorio de clientes</h2>
            <p>Información consultada desde la plataforma de servicios.</p>
        </div>

        <a class="btn-clientes" href="index.php">← Volver al centro de control</a>
    </div>

    <?php if ($error !== null): ?>

        <div class="clientes-error">
            <?= escapar($error) ?>
        </div>

    <?php else: ?>

        <section class="clientes-resumen">
            <div class="cliente-indicador">
                <span>Clientes activos</span>
                <strong><?= number_format($conteos['ACTIVO']) ?></strong>
            </div>

            <div class="cliente-indicador">
                <span>Clientes por revisar</span>
                <strong><?= number_format($conteos['POR_REVISAR']) ?></strong>
            </div>

            <div class="cliente-indicador">
                <span>Clientes inactivos</span>
                <strong><?= number_format($conteos['INACTIVO']) ?></strong>
            </div>
        </section>

        <form class="clientes-filtros" id="filtroEstadoClientes" method="GET">
            <label for="estadoClientes">Filtrar por estado:</label>
            <select name="estado" id="estadoClientes">
                <option value="">Todos los estados</option>
                <option value="ACTIVO" <?= $estado === 'ACTIVO' ? 'selected' : '' ?>>Activos</option>
                <option value="INACTIVO" <?= $estado === 'INACTIVO' ? 'selected' : '' ?>>Inactivos</option>
                <option value="POR_REVISAR" <?= $estado === 'POR_REVISAR' ? 'selected' : '' ?>>Por revisar</option>
            </select>
        </form>

        <section class="clientes-tabla-contenedor">

            <?php if (count($clientes) === 0): ?>

                <div class="clientes-vacio">
                    No se encontraron clientes con esos criterios.
                </div>

            <?php else: ?>

                <div class="clientes-tabla-scroll">
                    <table class="clientes-tabla">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Código</th>
                                <th>Teléfono</th>
                                <th>Correo</th>
                                <th>Servicios</th>
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
                                <td>
                                    <span class="cliente-nombre">
                                        <?= escapar($nombre !== '' ? $nombre : 'Sin nombre registrado') ?>
                                    </span>
                                    <span class="cliente-secundario">
                                        ID <?= escapar($cliente['id_cliente']) ?>
                                    </span>
                                </td>

                                <td><?= escapar($cliente['codigo_cliente'] ?: '—') ?></td>
                                <td><?= escapar($cliente['telefono'] ?: '—') ?></td>
                                <td><?= escapar($cliente['correo'] ?: '—') ?></td>

                                <td class="cliente-servicios">
                                    <?= (int)$cliente['total_servicios'] ?>
                                </td>

                                <td>
                                    <span class="cliente-estado <?= escapar($claseEstado) ?>">
                                        <?= escapar(str_replace('_', ' ', $cliente['estado'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <a
                                        class="cliente-accion"
                                        href="ver_cliente.php?id=<?= (int)$cliente['id_cliente'] ?>"
                                    >
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
                        <a href="<?= escapar(urlPagina($pagina - 1, $busqueda, $estado)) ?>">
                            ← Anterior
                        </a>
                    <?php endif; ?>

                    <?php if ($pagina < $totalPaginas): ?>
                        <a href="<?= escapar(urlPagina($pagina + 1, $busqueda, $estado)) ?>">
                            Siguiente →
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    <?php endif; ?>

</main>

<script>
(() => {
    const buscador = document.getElementById("buscarCliente");
    const filtro = document.getElementById("filtroEstadoClientes");
    const estado = document.getElementById("estadoClientes");

    if (!buscador || !filtro || !estado) return;

    const parametros = new URLSearchParams(window.location.search);
    buscador.value = parametros.get("q") || "";

    function aplicarBusqueda() {
        const nuevos = new URLSearchParams();
        const termino = buscador.value.trim();

        if (termino) nuevos.set("q", termino);
        if (estado.value) nuevos.set("estado", estado.value);

        window.location.href = "clientes.php" +
            (nuevos.toString() ? "?" + nuevos.toString() : "");
    }

    buscador.addEventListener("keydown", (evento) => {
        if (evento.key === "Enter") {
            evento.preventDefault();
            aplicarBusqueda();
        }
    });

    estado.addEventListener("change", aplicarBusqueda);
})();
</script>
\n</body>
</html>
