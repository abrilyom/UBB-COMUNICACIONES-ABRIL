<?php
declare(strict_types=1);

/*
 * Importador de movimientos bancarios desde CSV.
 *
 * Uso:
 *   php importar_movimientos_bancarios.php archivo.csv
 *   php importar_movimientos_bancarios.php archivo.csv --importar
 *
 * Por defecto solo realiza una simulación.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conexion = null;
$bloqueo = false;
$transaccion = false;

function errorFila(int $numero, string $mensaje): void
{
    fwrite(STDERR, "Fila $numero: $mensaje\n");
}

try {
    if ($argc < 2 || $argc > 3) {
        throw new InvalidArgumentException(
            "Uso: php importar_movimientos_bancarios.php archivo.csv [--importar]"
        );
    }

    $archivo = $argv[1];
    $modoImportacion = isset($argv[2]) && $argv[2] === '--importar';

    if (isset($argv[2]) && !$modoImportacion) {
        throw new InvalidArgumentException("Opción desconocida: {$argv[2]}");
    }

    if (!is_file($archivo) || !is_readable($archivo)) {
        throw new RuntimeException("El CSV no existe o no se puede leer.");
    }

    $columnasEsperadas = [
        'banco',
        'cuenta_alias',
        'fecha_movimiento',
        'tipo_movimiento',
        'monto',
        'moneda',
        'ordenante',
        'referencia_bancaria',
        'concepto',
        'clave_rastreo',
        'identificador_externo'
    ];

    $entornoPagos = getenv('UBB_PAGOS_ENTORNO') ?: 'produccion';

if ($entornoPagos === 'pruebas') {
    require __DIR__ . '/../conexion_pruebas.php';
} elseif ($entornoPagos === 'produccion') {
    require __DIR__ . '/../conexion.php';
} else {
    throw new RuntimeException(
        'Entorno inválido. Utiliza produccion o pruebas.'
    );
}

$baseEsperada = $entornoPagos === 'pruebas'
    ? 'ubb_pagos_pruebas'
    : 'ubb_pagos';

$baseSeleccionada = $conexion->query(
    'SELECT DATABASE()'
)->fetch_row()[0] ?? '';

if ($baseSeleccionada !== $baseEsperada) {
    throw new RuntimeException(
        'La conexión no corresponde al entorno solicitado.'
    );
}

echo "Entorno: $entornoPagos | Base: $baseSeleccionada\n";


    if (!$conexion instanceof mysqli) {
        throw new RuntimeException(
            "La conexión local no devolvió una instancia mysqli."
        );
    }

    $conexion->set_charset('utf8mb4');

    $resultadoBloqueo = $conexion->query(
        "SELECT GET_LOCK('ubb_importar_movimientos_bancarios', 0) AS obtenido"
    );

    if ((int)$resultadoBloqueo->fetch_assoc()['obtenido'] !== 1) {
        throw new RuntimeException(
            "Ya existe otra ejecución del importador."
        );
    }

    $bloqueo = true;

    $handle = fopen($archivo, 'rb');

    if ($handle === false) {
        throw new RuntimeException("No fue posible abrir el CSV.");
    }

    $encabezados = fgetcsv($handle, 0, ',', '"', '');

    if ($encabezados === false) {
        throw new RuntimeException("El CSV está vacío.");
    }

    // Eliminar BOM UTF-8 si existe.
    $encabezados[0] = preg_replace('/^\xEF\xBB\xBF/', '', $encabezados[0]);

    if ($encabezados !== $columnasEsperadas) {
        throw new RuntimeException(
            "Los encabezados no coinciden con el formato esperado.\n" .
            "Esperados: " . implode(',', $columnasEsperadas)
        );
    }

    $validos = [];
    $errores = [];
    $huellasArchivo = [];
    $duplicadosArchivo = 0;
    $numeroFila = 1;

    $consultaDuplicado = $conexion->prepare(
        "SELECT id_movimiento
         FROM movimientos_bancarios
         WHERE huella_deduplicacion = ?
         LIMIT 1"
    );

    $duplicadosBD = 0;

    while (($datos = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $numeroFila++;

        if ($datos === [null] || $datos === []) {
            continue;
        }

        if (count($datos) !== count($columnasEsperadas)) {
            $errores[] = "Fila $numeroFila: número incorrecto de columnas.";
            continue;
        }

        $fila = array_combine($columnasEsperadas, $datos);

        foreach ($fila as $campo => $valor) {
            $fila[$campo] = trim((string)$valor);
        }

        $banco = strtoupper($fila['banco']);
        $cuenta = $fila['cuenta_alias'] !== ''
            ? $fila['cuenta_alias']
            : null;

        $fecha = $fila['fecha_movimiento'];
        $tipo = strtoupper($fila['tipo_movimiento']);
        $monto = $fila['monto'];
        $moneda = strtoupper($fila['moneda']);
        $ordenante = $fila['ordenante'] !== ''
            ? $fila['ordenante']
            : null;
        $referencia = $fila['referencia_bancaria'] !== ''
            ? $fila['referencia_bancaria']
            : null;
        $concepto = $fila['concepto'] !== ''
            ? $fila['concepto']
            : null;
        $clave = $fila['clave_rastreo'] !== ''
            ? $fila['clave_rastreo']
            : null;
        $externo = $fila['identificador_externo'] !== ''
            ? $fila['identificador_externo']
            : null;

        $problemas = [];

        if ($banco === '' || strlen($banco) > 30) {
            $problemas[] = 'banco vacío o demasiado largo';
        }

        if ($cuenta !== null && strlen($cuenta) > 60) {
            $problemas[] = 'cuenta_alias demasiado largo';
        }

        $fechaObjeto = DateTime::createFromFormat(
            '!Y-m-d H:i:s',
            $fecha
        );

        if (
            !$fechaObjeto ||
            $fechaObjeto->format('Y-m-d H:i:s') !== $fecha
        ) {
            $problemas[] = 'fecha inválida; formato requerido Y-m-d H:i:s';
        }

        if (!in_array($tipo, ['ABONO', 'CARGO'], true)) {
            $problemas[] = 'tipo_movimiento debe ser ABONO o CARGO';
        }

        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $monto)) {
            $problemas[] = 'monto inválido; usa punto decimal y máximo 2 decimales';
        } elseif ((float)$monto <= 0) {
            $problemas[] = 'el monto debe ser mayor que cero';
        }

        if (!preg_match('/^[A-Z]{3}$/', $moneda)) {
            $problemas[] = 'moneda inválida; utiliza un código de 3 letras';
        }

        if (
            ($externo === null && $clave === null) ||
            ($externo !== null && strlen($externo) > 150) ||
            ($clave !== null && strlen($clave) > 100)
        ) {
            $problemas[] = 'falta un identificador bancario válido';
        }

        if ($ordenante !== null && strlen($ordenante) > 180) {
            $problemas[] = 'ordenante demasiado largo';
        }

        if ($referencia !== null && strlen($referencia) > 150) {
            $problemas[] = 'referencia_bancaria demasiado larga';
        }

        if ($problemas !== []) {
            $errores[] = "Fila $numeroFila: " . implode('; ', $problemas);
            continue;
        }

        // La huella utiliza identificadores bancarios estables,
        // además del banco y la cuenta para delimitar su ámbito.
        $identificador = $externo !== null
            ? 'EXT:' . $externo
            : 'RASTREO:' . $clave;

        $huella = hash(
            'sha256',
            strtoupper($banco) . '|' .
            ($cuenta ?? '') . '|' .
            $identificador
        );

        if (isset($huellasArchivo[$huella])) {
            $duplicadosArchivo++;
            continue;
        }

        $huellasArchivo[$huella] = true;

        $consultaDuplicado->bind_param('s', $huella);
        $consultaDuplicado->execute();

        $resultado = $consultaDuplicado->get_result();

        if ($resultado->num_rows > 0) {
            $duplicadosBD++;
            continue;
        }

        $validos[] = [
            'banco' => $banco,
            'cuenta' => $cuenta,
            'externo' => $externo,
            'huella' => $huella,
            'fecha' => $fecha,
            'tipo' => $tipo,
            'monto' => $monto,
            'moneda' => $moneda,
            'ordenante' => $ordenante,
            'referencia' => $referencia,
            'concepto' => $concepto,
            'clave' => $clave
        ];
    }

    fclose($handle);
    $consultaDuplicado->close();

    echo "\n===== RESULTADO DE VALIDACIÓN =====\n";
    echo "Registros válidos y nuevos: " . count($validos) . "\n";
    echo "Duplicados dentro del CSV: $duplicadosArchivo\n";
    echo "Duplicados en la base de datos: $duplicadosBD\n";
    echo "Filas con errores: " . count($errores) . "\n";

    if ($errores !== []) {
        echo "\n===== ERRORES =====\n";

        foreach ($errores as $error) {
            echo "$error\n";
        }

        throw new RuntimeException(
            "Importación cancelada: primero corrige las filas inválidas."
        );
    }

    if (!$modoImportacion) {
        echo "\nSIMULACIÓN COMPLETADA. No se guardó ningún movimiento.\n";
        echo "Para importar explícitamente, agrega --importar.\n";
    } else {
        $conexion->begin_transaction();
        $transaccion = true;

        $insertar = $conexion->prepare(
            "INSERT INTO movimientos_bancarios (
                banco,
                cuenta_alias,
                identificador_externo,
                huella_deduplicacion,
                fecha_movimiento,
                tipo_movimiento,
                monto,
                moneda,
                ordenante,
                referencia_bancaria,
                concepto,
                clave_rastreo,
                estado_conciliacion,
                fuente_datos
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', 'CSV')"
        );

        $insertados = 0;

        foreach ($validos as $item) {
            $banco = $item['banco'];
            $cuenta = $item['cuenta'];
            $externo = $item['externo'];
            $huella = $item['huella'];
            $fecha = $item['fecha'];
            $tipo = $item['tipo'];
            $monto = $item['monto'];
            $moneda = $item['moneda'];
            $ordenante = $item['ordenante'];
            $referencia = $item['referencia'];
            $concepto = $item['concepto'];
            $clave = $item['clave'];

            $insertar->bind_param(
                'ssssssssssss',
                $banco,
                $cuenta,
                $externo,
                $huella,
                $fecha,
                $tipo,
                $monto,
                $moneda,
                $ordenante,
                $referencia,
                $concepto,
                $clave
            );

            $insertar->execute();

	    $idMovimientoInsertado = (int)$conexion->insert_id;

	    $auditoria = $conexion->prepare("
		INSERT INTO auditoria_conciliacion_bancaria (
			id_movimiento,
			tipo_evento,
			usuario_id,
			resultado,
			detalle
		  ) VALUES (?, 'IMPORTACION', NULL, 'EXITOSA', ?)
		");

		$detalleAuditoria = sprintf(
			'Movimiento importado desde CSV. Banco: %s. Importe: %s %s. Referencia: %s',
		$banco,
		$monto,
		$moneda, 
		$referencia !== '' ? $referencia : 'Sin referencia');

		$auditoria->bind_param(
		  'is',
		  $idMovimientoInsertado,
		  $detalleAuditoria);

		$auditoria->execute();
		$auditoria->close();

           $insertados++;
        }

        $insertar->close();

        $conexion->commit();
        $transaccion = false;

        echo "\n===== IMPORTACIÓN COMPLETADA =====\n";
        echo "Movimientos insertados: $insertados\n";
        echo "Todos quedaron en estado PENDIENTE.\n";
        echo "No se crearon pagos ni se modificaron servicios.\n";
    }

} catch (Throwable $e) {
    if ($transaccion && $conexion instanceof mysqli) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);

} finally {
    if ($bloqueo && $conexion instanceof mysqli) {
        try {
            $conexion->query(
                "SELECT RELEASE_LOCK('ubb_importar_movimientos_bancarios')"
            );
        } catch (Throwable $ignorado) {
        }
    }
}
