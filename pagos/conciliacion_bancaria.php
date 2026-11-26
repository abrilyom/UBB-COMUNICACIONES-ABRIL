<?php
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
    <style>
        .conciliacion-intro {
            color: #77718b;
            font-size: 14px;
            margin-top: 7px;
        }
        .conciliacion-indicadores {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin: 22px 0;
        }
        .conciliacion-indicador {
            background: #fff;
            border: 1px solid #f1e9f2;
            border-radius: 14px;
            padding: 19px;
            box-shadow: 0 3px 12px rgba(89,34,100,.04);
        }
        .conciliacion-indicador span {
            display: block;
            color: #77718b;
            font-size: 13px;
        }
        .conciliacion-indicador strong {
            display: block;
            color: #63336d;
            font-size: 27px;
            margin-top: 8px;
        }
        .conciliacion-filtros {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        .conciliacion-filtros input,
        .conciliacion-filtros select {
            min-height: 42px;
            border: 1px solid #e5d7e8;
            border-radius: 9px;
            padding: 8px 12px;
            background: #fff;
        }
        .conciliacion-filtros button {
            min-height: 42px;
            padding: 8px 18px;
            border: 0;
            border-radius: 9px;
            background: #a974b4;
            color: #fff;
            cursor: pointer;
        }
        .conciliacion-tabla {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #f1e9f2;
            border-radius: 13px;
        }
        .conciliacion-tabla table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }
        .conciliacion-tabla th,
        .conciliacion-tabla td {
            padding: 13px 12px;
            border-bottom: 1px solid #f2ebf3;
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }
        .conciliacion-tabla th {
            background: #faf6fb;
            color: #765a7b;
            font-weight: 700;
        }
        .conciliacion-tabla td small {
            display: block;
            color: #77718b;
            margin-top: 5px;
            overflow-wrap: anywhere;
        }
        .conciliacion-estado {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
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
            color: #875092;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .conciliacion-vacio {
            padding: 35px !important;
            text-align: center !important;
            color: #77718b;
        }
        .conciliacion-ayuda {
            background: #faf6fb;
            border: 1px solid #efe1f2;
            border-radius: 12px;
            padding: 15px 18px;
            margin-bottom: 18px;
            color: #765f7b;
            font-size: 13px;
            line-height: 1.6;
        }
        @media(max-width: 900px) {
            .conciliacion-indicadores {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media(max-width: 520px) {
            .conciliacion-indicadores {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }
            .conciliacion-indicador {
                padding: 13px;
            }
            .conciliacion-indicador strong {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . "/includes/encabezado.php"; ?>
<?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor">
    <section class="panel">
        <div class="panel-encabezado">
            <div>
                <span class="etiqueta-seccion">CONTROL DE PAGOS</span>
                <h2 class="panel-titulo">Conciliación bancaria</h2>
                <p class="conciliacion-intro">
                    Revisión de depósitos y transferencias recibidos en las cuentas de UBB.
                </p>
            </div>
        </div>

        <div class="conciliacion-indicadores">
            <div class="conciliacion-indicador">
                <span>Movimientos pendientes</span>
                <strong><?= $estadisticas["PENDIENTE"] ?></strong>
            </div>
            <div class="conciliacion-indicador">
                <span>En revisión</span>
                <strong><?= $estadisticas["REVISION"] ?></strong>
            </div>
            <div class="conciliacion-indicador">
                <span>Conciliados</span>
                <strong><?= $estadisticas["CONCILIADO"] ?></strong>
            </div>
            <div class="conciliacion-indicador">
                <span>Ignorados</span>
                <strong><?= $estadisticas["IGNORADO"] ?></strong>
            </div>
        </div>

        <div class="conciliacion-ayuda">
            <strong>¿Cómo funciona?</strong><br>
            El sistema busca referencias UBB en los datos bancarios y consulta
            las obligaciones registradas. Una referencia permanente identifica
            el servicio; una referencia mensual permite localizar una obligación.
            La detección no registra ni confirma pagos automáticamente.
        </div>

        <form method="GET" class="conciliacion-filtros">
            <select name="estado" aria-label="Filtrar por estado">
                <?php foreach ($estadosValidos as $opcion): ?>
                    <option value="<?= h($opcion) ?>"
                        <?= $estado === $opcion ? "selected" : "" ?>>
                        <?= h($opcion === "TODOS" ? "Todos los estados" : $opcion) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="buscar"
                   placeholder="Banco, ordenante, referencia o movimiento..."
                   value="<?= h($buscar) ?>">

            <button type="submit">Buscar movimientos</button>
        </form>

        <div class="conciliacion-tabla">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Banco / ordenante</th>
                        <th>Movimiento</th>
                        <th>Importe</th>
                        <th>Referencia detectada</th>
                        <th>Cliente / servicio</th>
                        <th>Obligación</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($movimientos->num_rows === 0): ?>
                    <tr>
                        <td colspan="8" class="conciliacion-vacio">
                            No hay movimientos bancarios para este filtro.
                            Cuando importemos un estado de cuenta, aparecerán aquí.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($movimiento = $movimientos->fetch_assoc()): ?>
                        <?php $deteccion = detectarReferencia($conexion, $movimiento); ?>
                        <tr>
                            <td>
                                <?= h(date("d/m/Y H:i", strtotime($movimiento["fecha_movimiento"]))) ?>
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
                            <td>
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
                                    <span>Sin referencia UBB</span>
                                <?php endif; ?>
                                <small><?= h($movimiento["referencia_bancaria"]) ?></small>
                                <small><?= h($movimiento["concepto"]) ?></small>
                            </td>
                            <td>
                                <?php if ($deteccion["cliente"] !== null): ?>
                                    Cliente #<?= h($deteccion["cliente"]) ?>
                                    <small>Servicio #<?= h($deteccion["servicio"]) ?></small>
                                <?php else: ?>
                                    No identificado
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
                                    <span>Sin coincidencia</span>
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
