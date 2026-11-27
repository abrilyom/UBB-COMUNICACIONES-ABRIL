<?php
require_once __DIR__ . "/includes/sesion.php";
require_once "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    exit("Método de solicitud no permitido.");
}

$id_pago = filter_input(INPUT_POST, "id_pago", FILTER_VALIDATE_INT);

if (!$id_pago || $id_pago <= 0) {
    exit("El identificador del pago no es válido.");
}

if (!isset($_FILES["comprobante"]) ||
    $_FILES["comprobante"]["error"] !== UPLOAD_ERR_OK) {
    exit("Selecciona un archivo válido para subir.");
}

$archivo = $_FILES["comprobante"];

// Límite de 5 MB
if ($archivo["size"] > 5 * 1024 * 1024) {
    exit("El archivo supera el límite de 5 MB.");
}

// Comprobar que el pago existe
$stmt = $conexion->prepare(
    "SELECT id_pago FROM pagos WHERE id_pago = ?"
);
$stmt->bind_param("i", $id_pago);
$stmt->execute();
$resultado = $stmt->get_result();
$pago = $resultado->fetch_assoc();
$stmt->close();

if (!$pago) {
    exit("No se encontró el pago indicado.");
}

// Validar el tipo real del archivo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$tipo = $finfo->file($archivo["tmp_name"]);

$tiposPermitidos = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
    "application/pdf" => "pdf"
];

if (!isset($tiposPermitidos[$tipo])) {
    exit("Formato no permitido. Sube una imagen JPG, PNG, WEBP o un PDF.");
}

// Nombre aleatorio para evitar sobrescribir archivos
$nombreArchivo = bin2hex(random_bytes(16))
    . "." . $tiposPermitidos[$tipo];

$directorio = __DIR__ . "/comprobantes/";

if (!is_dir($directorio) || !is_writable($directorio)) {
    exit("La carpeta de comprobantes no existe o no permite guardar archivos.");
}

$rutaDestino = $directorio . $nombreArchivo;

if (!move_uploaded_file($archivo["tmp_name"], $rutaDestino)) {
    exit("No se pudo guardar el comprobante.");
}

// Guardar la ruta relativa en la base de datos
$rutaBD = "comprobantes/" . $nombreArchivo;

$stmt = $conexion->prepare(
    "UPDATE pagos SET comprobante = ? WHERE id_pago = ?"
);
$stmt->bind_param("si", $rutaBD, $id_pago);

if ($stmt->execute()) {
    echo "Comprobante guardado correctamente. ";
    echo '<a href="historial.php">Volver al historial</a>';
} else {
    // Si falla la actualización, eliminar el archivo recién subido
    if (is_file($rutaDestino)) {
        unlink($rutaDestino);
    }
    echo "No se pudo registrar la ruta del comprobante.";
}

$stmt->close();
$conexion->close();
?>
