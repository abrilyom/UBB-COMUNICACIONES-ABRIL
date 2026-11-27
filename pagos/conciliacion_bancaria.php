<?php
require_once __DIR__ . "/includes/sesion.php";
require_once __DIR__ . "/conexion.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$estado = $_GET["estado"] ?? "PENDIENTE";
$buscar = trim($_GET["buscar"] ?? "");

$estadosValidos = ["TODOS", "PENDIENTE", "REVISION", "CONCILIADO", "IGNORADO"];
if (!in_array($estado, $estadosValidos, true)) {
    $estado = "PENDIENTE";
}

$estadisticas = [
    "PENDIENTE" => 0,
    "REVISION" => 0,
    "CONCILIADO" => 0,
    "IGNORADO" => 0
];

$sqlResumen = "
    SELECT estado_conciliacion, COUNT(*) AS total
    FROM movimientos_bancarios
    GROUP BY estado_conciliacion
";
$resumen = $conexion->query($sqlResumen);

while ($fila = $resumen->fetch_assoc()) {
    $estadisticas[$fila["estado_conciliacion"]] = (int)$fila["total"];
}

$sql = "
    SELECT
        id_movimiento,
        banco,
        cuenta_alias,
        fecha_movimiento,
        tipo_movimiento,
        monto,
        moneda,
        ordenante,
        referencia_bancaria,
        concepto,
        clave_rastreo,
        estado_conciliacion,
        id_pago
    FROM movimientos_bancarios
    WHERE 1 = 1
";

$parametros = [];
$tipos = "";

if ($estado !== "TODOS") {
    $sql .= " AND estado_conciliacion = ?";
    $parametros[] = $estado;
    $tipos .= "s";
}

if ($buscar !== "") {
    $sql .= " AND (
        banco LIKE ?
        OR ordenante LIKE ?
        OR referencia_bancaria LIKE ?
        OR concepto LIKE ?
        OR clave_rastreo LIKE ?
        OR CAST(id_movimiento AS CHAR) LIKE ?
    )";

    $termino = "%" . $buscar . "%";
    for ($i = 0; $i < 6; $i++) {
        $parametros[] = $termino;
        $tipos .= "s";
    }
}

$sql .= " ORDER BY fecha_movimiento DESC, id_movimiento DESC LIMIT 300";

$stmt = $conexion->prepare($sql);

if ($parametros) {
    $stmt->bind_param($tipos, ...$parametros);
}

$stmt->execute();
$movimientos = $stmt->get_result();

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ""), ENT_QUOTES, "UTF-8");
}

function detectarReferencia(mysqli $conexion, array $movimiento): array {
    static $stmtMensual = null;
    static $stmtPermanente = null;

    $texto = strtoupper(
        ($movimiento["referencia_bancaria"] ?? "") . " " .
        ($movimiento["concepto"] ?? "")
    );

    preg_match_all(
        '/(?<![A-Z0-9])(?:UBBC\d+S\d+M\d{8}|UBBC\d+S\d+)(?![A-Z0-9])/i',
        $texto,
        $coincidencias
    );

    $referencias = array_unique($coincidencias[0] ?? []);

    foreach ($referencias as $referencia) {
        $referencia = strtoupper($referencia);

        $cliente = null;
        $servicio = null;
        $periodo = null;
        $fechaCompleta = null;
        $esMensual = false;

        if (preg_match('/^UBBC(\d+)S(\d+)M(\d{8})$/', $referencia, $m)) {
            $cliente = (int)$m[1];
            $servicio = (int)$m[2];
            $fechaCompleta = $m[3];

            $anio = (int)substr($fechaCompleta, 0, 4);
            $mes = (int)substr($fechaCompleta, 4, 2);
            $dia = (int)substr($fechaCompleta, 6, 2);

            if (!checkdate($mes, $dia, $anio)) {
                continue;
            }

            $periodo = substr($fechaCompleta, 0, 6);
            $esMensual = true;
        }

        if ($esMensual) {
            if ($stmtMensual === null) {
                $stmtMensual = $conexion->prepare("
                    SELECT
                        o.id_obligacion,
                        o.id_cliente,
                        o.id_servicio,
                        o.periodo,
                        o.concepto,
                        o.monto,
                        o.estado,
                        o.fecha_vencimiento
                    FROM obligaciones_pago o
                    WHERE o.referencia_cobro = ?
                      AND o.id_cliente = ?
                      AND o.id_servicio = ?
                      AND o.periodo = ?
                    LIMIT 1
                ");
            }

            $stmtMensual->bind_param("siis", $referencia, $cliente, $servicio, $periodo);
            $stmtMensual->execute();
            $obligacion = $stmtMensual->get_result()->fetch_assoc();

            $fechaCoincide = !$obligacion || $fechaCompleta === null ||
                (new DateTimeImmutable($obligacion['fecha_vencimiento']))->format('Ymd') === $fechaCompleta;

            if ($obligacion && $fechaCoincide) {
                return [
                    "tipo" => "MENSUAL",
                    "referencia" => $referencia,
                    "cliente" => $obligacion["id_cliente"],
                    "servicio" => $obligacion["id_servicio"],
                    "obligacion" => $obligacion
                ];
            }

            if ($obligacion && !$fechaCoincide) {
                return [
                    "tipo" => "FECHA_VENCIMIENTO_NO_COINCIDE",
                    "referencia" => $referencia,
                    "cliente" => $obligacion["id_cliente"],
                    "servicio" => $obligacion["id_servicio"],
                    "obligacion" => $obligacion
                ];
            }

            return [
                "tipo" => "MENSUAL_SIN_OBLIGACION",
                "referencia" => $referencia,
                "cliente" => $cliente,
                "servicio" => $servicio,
                "obligacion" => null
            ];
        }

        if (
            preg_match('/^UBBC(\d+)S(\d+)$/', $referencia, $m)
        ) {
            $cliente = (int)$m[1];
            $servicio = (int)$m[2];

            if ($stmtPermanente === null) {
                $stmtPermanente = $conexion->prepare("
                    SELECT id_cliente, id_servicio, estado_servicio
                    FROM referencias_servicio
                    WHERE referencia_base = ?
                      AND id_cliente = ?
                      AND id_servicio = ?
                    LIMIT 1
                ");
            }

            $stmtPermanente->bind_param("sii", $referencia, $cliente, $servicio);
            $stmtPermanente->execute();
            $servicioEncontrado = $stmtPermanente->get_result()->fetch_assoc();

            if ($servicioEncontrado) {
                return [
                    "tipo" => "PERMANENTE",
                    "referencia" => $referencia,
                    "cliente" => $servicioEncontrado["id_cliente"],
                    "servicio" => $servicioEncontrado["id_servicio"],
                    "obligacion" => null,
                    "estado_servicio" => $servicioEncontrado["estado_servicio"]
                ];
            }
        }
    }

    return [
        "tipo" => "NO_IDENTIFICADA",
        "referencia" => "",
        "cliente" => null,
        "servicio" => null,
        "obligacion" => null
    ];
}

function etiquetaEstado(string $estado): string {
    $clases = [
        "PENDIENTE" => "pendiente",
        "REVISION" => "revision",
        "CONCILIADO" => "conciliado",
        "IGNORADO" => "ignorado"
    ];

    return $clases[$estado] ?? "pendiente";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación bancaria - UBB Comunicaciones</title>
    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">
    <link rel="stylesheet" href="css/tablero.css?v=<?php echo filemtime(__DIR__ . '/css/tablero.css'); ?>">
    <link rel="stylesheet" href="css/listados.css?v=<?php echo filemtime(__DIR__ . '/css/listados.css'); ?>">
    <style>
        /* Celdas de la tabla: datos secundarios debajo del principal */
        .conciliacion-tabla td small {
            display: block;
            color: var(--cc-texto-suave);
            font-size: 12px;
            margin-top: 4px;
            overflow-wrap: anywhere;
        }
        .conciliacion-tabla td {
            vertical-align: top !important;
        }
        .conciliacion-estado {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .3px;
        }
        .conciliacion-estado.pendiente {
            background: #fff3d9;
            color: #946000;
        }
        .conciliacion-estado.revision {
            background: #fce8ef;
            color: #a52c56;
        }
        .conciliacion-estado.conciliado {
            background: #e4f6eb;
            color: #247044;
        }
        .conciliacion-estado.ignorado {
            background: #eeeef2;
            color: #666;
        }
        .conciliacion-referencia {
            color: var(--cc-morado);
            font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
            font-size: 12.5px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        /* Explicación de cómo funciona */
        .conciliacion-ayuda {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 16px 20px;
            margin-bottom: 18px;
            color: var(--cc-texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }
        .conciliacion-ayuda strong {
            color: var(--cc-texto);
        }
        .conciliacion-ayuda-icono {
            display: grid;
            place-items: center;
            flex: 0 0 36px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--cc-morado-suave);
            color: var(--cc-morado-medio);
        }
        .conciliacion-ayuda-icono svg {
            width: 19px;
            height: 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        /* Tarjeta gris para "Ignorados" */
        .conciliacion-kpi--ignorados {
            --cc-acento: #8a8a96;
            --cc-acento-fondo: #f4f4f7;
            --cc-acento-borde: #e3e3ea;
            border-color: var(--cc-acento-borde);
            background: linear-gradient(160deg, var(--cc-acento-fondo) 0%, var(--cc-acento-fondo) 60%, #ffffff 140%);
        }
        .conciliacion .hist-tabla table {
            min-width: 1050px;
        }
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor cc hist conciliacion">

    <!-- =================================================
         CABECERA
    ================================================== -->

    <header class="cc-cabecera">
        <div class="cc-cabecera-texto">
            <span class="cc-cabecera-icono" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/></svg>
            </span>
            <div>
                <h1>Conciliación bancaria</h1>
                <p>Revisión de depósitos y transferencias recibidos en las cuentas de UBB.</p>
            </div>
        </div>
    </header>


    <!-- =================================================
         RESUMEN
    ================================================== -->

    <div class="cc-kpis hist-kpis adeudos-kpis">

        <div class="cc-kpi cc-kpi--proximos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                <span class="cc-kpi-nombre">Pendientes</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$estadisticas["PENDIENTE"]) ?></strong>
            <span class="cc-kpi-detalle">Movimientos por conciliar</span>
        </div>

        <div class="cc-kpi cc-kpi--suspendidos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M11 8v3M11 14h.01"/></svg></span>
                <span class="cc-kpi-nombre">En revisión</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$estadisticas["REVISION"]) ?></strong>
            <span class="cc-kpi-detalle">Requieren revisión manual</span>
        </div>

        <div class="cc-kpi cc-kpi--activos">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                <span class="cc-kpi-nombre">Conciliados</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$estadisticas["CONCILIADO"]) ?></strong>
            <span class="cc-kpi-detalle">Asociados a un pago</span>
        </div>

        <div class="cc-kpi conciliacion-kpi--ignorados">
            <div class="cc-kpi-cabeza">
                <span class="cc-kpi-icono" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg></span>
                <span class="cc-kpi-nombre">Ignorados</span>
            </div>
            <strong class="cc-kpi-numero"><?= number_format((int)$estadisticas["IGNORADO"]) ?></strong>
            <span class="cc-kpi-detalle">No corresponden a pagos</span>
        </div>

    </div>


    <!-- =================================================
         CÓMO FUNCIONA
    ================================================== -->

    <div class="cc-panel conciliacion-ayuda">
        <span class="conciliacion-ayuda-icono" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        </span>
        <div>
            <strong>¿Cómo funciona?</strong><br>
            El sistema busca referencias UBB en los datos bancarios y consulta
            las obligaciones registradas. Una referencia permanente identifica
            el servicio; una referencia mensual permite localizar una obligación.
            La detección no registra ni confirma pagos automáticamente.
        </div>
    </div>


    <!-- =================================================
         FILTROS
    ================================================== -->

    <form method="GET" class="cc-panel hist-filtros">

        <label class="hist-campo">
            <span>Estado</span>
            <select name="estado" onchange="this.form.submit()">
                <?php foreach ($estadosValidos as $opcion): ?>
                    <option value="<?= h($opcion) ?>"
                        <?= $estado === $opcion ? "selected" : "" ?>>
                        <?= h($opcion === "TODOS" ? "Todos los estados" : $opcion) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="hist-buscador">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="buscar"
                   placeholder="Banco, ordenante, referencia o movimiento..."
                   aria-label="Buscar movimientos"
                   value="<?= h($buscar) ?>">
            <button type="submit">Buscar</button>
        </div>

        <?php if ($estado !== "PENDIENTE" || $buscar !== ""): ?>
            <div class="hist-activos">
                <span class="hist-activos-texto">Mostrando resultados filtrados:</span>
                <?php if ($buscar !== ""): ?>
                    <span class="hist-activos-etiqueta">Búsqueda: «<?= h($buscar) ?>»</span>
                <?php endif; ?>
                <?php if ($estado !== "PENDIENTE"): ?>
                    <span class="hist-activos-etiqueta">Estado: <?= h($estado) ?></span>
                <?php endif; ?>
                <a class="hist-limpiar" href="conciliacion_bancaria.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    Limpiar filtros
                </a>
            </div>
        <?php endif; ?>

    </form>


    <!-- =================================================
         TABLA
    ================================================== -->

    <section class="cc-panel hist-tabla">
        <div class="hist-tabla-scroll conciliacion-tabla">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Banco / ordenante</th>
                        <th>Movimiento</th>
                        <th class="hist-num">Importe</th>
                        <th>Referencia detectada</th>
                        <th>Cliente / servicio</th>
                        <th>Obligación</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($movimientos->num_rows === 0): ?>
                    <tr>
                        <td colspan="8" class="hist-vacio">
                            No hay movimientos bancarios para este filtro.
                            Cuando importemos un estado de cuenta, aparecerán aquí.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($movimiento = $movimientos->fetch_assoc()): ?>
                        <?php $deteccion = detectarReferencia($conexion, $movimiento); ?>
                        <tr>
                            <td class="hist-fecha">
                                <?= h(date("d/m/Y", strtotime($movimiento["fecha_movimiento"]))) ?>
                                <span><?= h(date("H:i", strtotime($movimiento["fecha_movimiento"]))) ?></span>
                            </td>
                            <td>
                                <strong><?= h($movimiento["banco"]) ?></strong>
                                <small><?= h($movimiento["cuenta_alias"]) ?></small>
                                <?= h($movimiento["ordenante"]) ?>
                            </td>
                            <td>
                                <?= h($movimiento["tipo_movimiento"]) ?>
                                <small><?= h($movimiento["clave_rastreo"]) ?></small>
                            </td>
                            <td class="hist-num">
                                <strong>
                                    $<?= number_format((float)$movimiento["monto"], 2) ?>
                                    <?= h($movimiento["moneda"]) ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($deteccion["referencia"] !== ""): ?>
                                    <span class="conciliacion-referencia">
                                        <?= h($deteccion["referencia"]) ?>
                                    </span>
                                    <small><?= h($deteccion["tipo"]) ?></small>
                                <?php else: ?>
                                    <span class="hist-suave">Sin referencia UBB</span>
                                <?php endif; ?>
                                <small><?= h($movimiento["referencia_bancaria"]) ?></small>
                                <small><?= h($movimiento["concepto"]) ?></small>
                            </td>
                            <td>
                                <?php if ($deteccion["cliente"] !== null): ?>
                                    <strong>Cliente #<?= h($deteccion["cliente"]) ?></strong>
                                    <small>Servicio #<?= h($deteccion["servicio"]) ?></small>
                                <?php else: ?>
                                    <span class="hist-suave">No identificado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($deteccion["obligacion"] && $deteccion["tipo"] === "MENSUAL"): ?>
                                    $<?= number_format((float)$deteccion["obligacion"]["monto"], 2) ?>
                                    <small>
                                        <?= h($deteccion["obligacion"]["periodo"]) ?>
                                        · <?= h($deteccion["obligacion"]["estado"]) ?>
                                    </small>
                                <?php elseif ($deteccion["tipo"] === "FECHA_VENCIMIENTO_NO_COINCIDE"): ?>
                                    <span>La fecha de vencimiento no coincide</span>
                                    <small>
                                        Vencimiento registrado:
                                        <?= h($deteccion["obligacion"]["fecha_vencimiento"]) ?>
                                    </small>
                                <?php elseif ($deteccion["tipo"] === "MENSUAL_SIN_OBLIGACION"): ?>
                                    <span>Obligación no registrada</span>
                                <?php elseif ($deteccion["tipo"] === "PERMANENTE"): ?>
                                    <span>Referencia de servicio</span>
                                    <small>No identifica una mensualidad por sí sola.</small>
                                <?php else: ?>
                                    <span class="hist-suave">Sin coincidencia</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="conciliacion-estado <?= h(etiquetaEstado($movimiento["estado_conciliacion"])) ?>">
                                    <?= h($movimiento["estado_conciliacion"]) ?>
                                </span>
                                <?php if ($movimiento["id_pago"]): ?>
                                    <small>Pago #<?= h($movimiento["id_pago"]) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
