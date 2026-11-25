<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../conexion.php';

function probarReferencia(mysqli $conexion, string $referencia): void
{
    echo "\nREFERENCIA: $referencia\n";

    $cliente = null;
    $servicio = null;
    $periodo = null;
    $fechaCompleta = null;
    $esMensual = false;

    if (preg_match('/^UBBC(\d+)S(\d+)M(\d{8})$/', $referencia, $m)) {
        $cliente = (int)$m[1];
        $servicio = (int)$m[2];
        $fechaCompleta = $m[3];
        $periodo = substr($fechaCompleta, 0, 6);
        $esMensual = true;
        echo "TIPO: MENSUAL NUEVA\n";
    } elseif (preg_match('/^UBB-M-(\d+)-(\d+)-(\d{6})(\d{2})?$/', $referencia, $m)) {
        $cliente = (int)$m[1];
        $servicio = (int)$m[2];
        $periodo = $m[3];
        $fechaCompleta = !empty($m[4]) ? $m[3] . $m[4] : null;
        $esMensual = true;
        echo "TIPO: MENSUAL LEGADA\n";
    } elseif (
        preg_match('/^UBBC(\d+)S(\d+)$/', $referencia, $m) ||
        preg_match('/^UBB-(\d+)-(\d+)$/', $referencia, $m)
    ) {
        $cliente = (int)$m[1];
        $servicio = (int)$m[2];
        echo "TIPO: SERVICIO PERMANENTE\n";
    } else {
        echo "TIPO: FORMATO INVALIDO\n";
        return;
    }

    $stmt = $conexion->prepare("
        SELECT id_cliente, id_servicio, referencia_base, estado_servicio
        FROM referencias_servicio
        WHERE id_cliente = ? AND id_servicio = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $cliente, $servicio);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        echo "RESULTADO: SERVICIO NO ENCONTRADO\n";
        return;
    }

    echo "CLIENTE: " . $fila['id_cliente'] . "\n";
    echo "SERVICIO: " . $fila['id_servicio'] . "\n";
    echo "REFERENCIA BASE REGISTRADA: " . $fila['referencia_base'] . "\n";
    echo "ESTADO DEL SERVICIO: " . $fila['estado_servicio'] . "\n";

    if ($esMensual) {
        $stmt = $conexion->prepare("
            SELECT
                id_obligacion,
                id_cliente,
                id_servicio,
                periodo,
                fecha_vencimiento,
                monto,
                estado
            FROM obligaciones_pago
            WHERE referencia_cobro = ?
              AND id_cliente = ?
              AND id_servicio = ?
              AND periodo = ?
            LIMIT 1
        ");
        $stmt->bind_param('siis', $referencia, $cliente, $servicio, $periodo);
        $stmt->execute();
        $obligacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($obligacion) {
            echo "OBLIGACION: ENCONTRADA\n";
            echo "ID OBLIGACION: " . $obligacion['id_obligacion'] . "\n";
            echo "MONTO: " . $obligacion['monto'] . "\n";
            echo "ESTADO: " . $obligacion['estado'] . "\n";

            if ($fechaCompleta !== null) {
                $fechaReal = (new DateTimeImmutable(
                    $obligacion['fecha_vencimiento']
                ))->format('Ymd');

                echo $fechaReal === $fechaCompleta
                    ? "FECHA: COINCIDE\n"
                    : "FECHA: NO COINCIDE (referencia: " .
                      $fechaCompleta . ", registrada: " .
                      $fechaReal . ")\n";
            } else {
                echo "FECHA: NO INCLUIDA EN REFERENCIA LEGADA\n";
            }
        } else {
            echo "OBLIGACION: NO ENCONTRADA\n";
        }
    }

    echo "RESULTADO: SERVICIO IDENTIFICADO\n";
}

echo "===== PRUEBAS DE REFERENCIAS UBB =====\n";

$r = $conexion->query("
    SELECT referencia_base
    FROM referencias_servicio
    ORDER BY id_referencia
    LIMIT 1
");

$primera = $r->fetch_assoc();

if (!$primera) {
    exit("No hay referencias permanentes disponibles.\n");
}

probarReferencia($conexion, $primera['referencia_base']);

$rMensual = $conexion->query("
    SELECT referencia_cobro
    FROM obligaciones_pago
    WHERE referencia_cobro IS NOT NULL
      AND referencia_cobro <> ''
    ORDER BY id_obligacion DESC
    LIMIT 1
");

$mensual = $rMensual->fetch_assoc();

if ($mensual) {
    probarReferencia($conexion, $mensual['referencia_cobro']);
} else {
    echo "\nNo hay obligaciones mensuales para probar.\n";
}

probarReferencia($conexion, 'UBB-999999-999999');

echo "\n===== FIN DE PRUEBAS =====\n";
echo "No se insertaron ni modificaron registros.\n";
