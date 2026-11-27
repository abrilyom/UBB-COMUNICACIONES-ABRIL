<?php
require_once __DIR__ . "/includes/sesion.php";
require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/includes/ticket_pago.php";

/*
 * Ticket de cualquier pago: comprobante.php?id=<id_pago>
 *
 * - Si el pago ya tiene su PDF guardado (los de "Registrar pago"), muestra ese,
 *   que es el que se entregó al cliente.
 * - Si no (UISP, conciliación bancaria, anteriores), lo arma en ese momento
 *   con los datos actuales del pago. No guarda nada.
 */

function salirComprobante(int $codigo, string $mensaje): void
{
    http_response_code($codigo);
    header("Content-Type: text/plain; charset=utf-8");
    echo $mensaje;
    exit;
}

$idPago = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$idPago || $idPago <= 0) {
    salirComprobante(400, "Pago no válido.");
}

$columnasRegistro = $conexion->query("SHOW COLUMNS FROM pagos LIKE 'registrado_por'");
$tieneRegistro = $columnasRegistro && $columnasRegistro->num_rows > 0;

$stmt = $conexion->prepare(
    "SELECT id_pago, id_cliente, id_servicio, monto, fecha_pago, metodo_pago,
            referencia, estado_pago, comprobante"
    . ($tieneRegistro ? ", registrado_por" : ", NULL AS registrado_por") . "
     FROM pagos
     WHERE id_pago = ?
     LIMIT 1"
);
$stmt->bind_param("i", $idPago);
$stmt->execute();
$pago = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pago) {
    salirComprobante(404, "No existe el pago #" . $idPago . ".");
}

$nombreDescarga = "comprobante_pago_" . (int)$pago["id_pago"] . ".pdf";

/* PDF original guardado al registrar. */
$guardado = trim((string)($pago["comprobante"] ?? ""));

if ($guardado !== "") {
    $carpeta = realpath(__DIR__ . "/comprobantes");
    $ruta = realpath(__DIR__ . "/" . $guardado);

    if ($carpeta !== false && $ruta !== false && str_starts_with($ruta, $carpeta . DIRECTORY_SEPARATOR) && is_file($ruta)) {
        header("Content-Type: application/pdf");
        header('Content-Disposition: inline; filename="' . $nombreDescarga . '"');
        header("Content-Length: " . filesize($ruta));
        readfile($ruta);
        exit;
    }
}

/* Mensualidades a las que se aplicó el pago. */
$stmt = $conexion->prepare(
    "SELECT o.id_obligacion, o.periodo, o.concepto, o.monto AS monto_obligacion,
            a.monto_aplicado,
            o.monto - COALESCE((
                SELECT SUM(a2.monto_aplicado)
                FROM pagos_obligaciones a2
                INNER JOIN pagos p2 ON p2.id_pago = a2.id_pago
                WHERE a2.id_obligacion = o.id_obligacion
                  AND p2.estado_pago = 'PAGADO'
                  AND p2.id_pago <= ?
            ), 0) AS saldo_despues
     FROM pagos_obligaciones a
     INNER JOIN obligaciones_pago o ON o.id_obligacion = a.id_obligacion
     WHERE a.id_pago = ?
     ORDER BY o.periodo, o.id_obligacion"
);
$stmt->bind_param("ii", $idPago, $idPago);
$stmt->execute();
$aplicaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$idServicio = $pago["id_servicio"] !== null ? (int)$pago["id_servicio"] : null;
$datos = obtenerDatosTicket((int)$pago["id_cliente"], $idServicio);

$referencia = (string)($pago["referencia"] ?? "");

$ticket = [
    "folio" => "PAG-" . str_pad((string)$pago["id_pago"], 6, "0", STR_PAD_LEFT),
    "estado_pago" => (string)$pago["estado_pago"],
    "id_cliente" => (int)$pago["id_cliente"],
    "id_servicio" => $idServicio,
    "nombre" => $datos["nombre"],
    "codigo_cliente" => $datos["codigo_cliente"],
    "codigo_servicio" => $datos["codigo_servicio"],
    "paquete" => $datos["paquete"],
    "tecnologia" => $datos["tecnologia"],
    "periodo" => "",
    "concepto" => "",
    "saldo_restante_centavos" => 0,
    "monto" => (string)$pago["monto"],
    "referencia" => $referencia,
    "metodo_pago" => strtoupper((string)$pago["metodo_pago"]),
    "fecha_pago" => (string)$pago["fecha_pago"],
    "ruta_logo" => __DIR__ . "/../assets/img/globo-ubb-transparente.png",
    "atendio" => (string)($pago["registrado_por"] ?? ""),
    "origen" => str_starts_with(strtoupper($referencia), "UISP-") ? "Pago registrado en UISP" : "",
];

if (count($aplicaciones) === 1) {
    $ticket["periodo"] = (string)$aplicaciones[0]["periodo"];
    $ticket["concepto"] = (string)$aplicaciones[0]["concepto"];
    // Lo que quedaba por pagar de ese mes justo después de este pago.
    $ticket["saldo_restante_centavos"] = $pago["estado_pago"] === "PAGADO"
        ? (int)round(max(0, (float)$aplicaciones[0]["saldo_despues"]) * 100)
        : 0;
} elseif (count($aplicaciones) > 1) {
    $ticket["conceptos"] = array_map(static fn($a) => [
        "periodo" => (string)$a["periodo"],
        "concepto" => (string)$a["concepto"],
        "monto" => (string)$a["monto_aplicado"],
    ], $aplicaciones);
}

try {
    $pdf = generarPdfTicket(construirHtmlTicket($ticket));
} catch (Throwable $e) {
    error_log("Comprobante del pago " . $idPago . ": " . $e->getMessage());
    salirComprobante(500, "No se pudo generar el comprobante. Intenta de nuevo.");
}

header("Content-Type: application/pdf");
header('Content-Disposition: inline; filename="' . $nombreDescarga . '"');
header("Content-Length: " . strlen($pdf));
header("Cache-Control: private, no-store");
echo $pdf;
