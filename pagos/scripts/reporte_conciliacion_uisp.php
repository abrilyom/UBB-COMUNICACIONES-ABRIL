<?php
/**
 * Reporte de conciliacion UISP - UBB
 * SOLO LECTURA: no inserta ni modifica datos.
 */

// Solo se ejecuta desde la terminal (docker exec ... php ...), nunca desde el navegador.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../conexion.php';
$plataforma = require __DIR__ . '/../conexion_plataforma.php';

function limpiar($valor): string {
    return trim((string)($valor ?? ''));
}

function clasificarMetodo(array $p): string {
    $normalizado = strtoupper(limpiar($p['metodo_normalizado']));
    $original = mb_strtolower(limpiar($p['metodo_original_uisp']));

    if (str_contains($original, 'cortesía') ||
        str_contains($original, 'cortesia')) {
        return 'CREDITO_CORTESIA';
    }

    if (str_contains($original, 'personalizado')) {
        return 'METODO_PERSONALIZADO_REVISAR';
    }

    if (str_contains($original, 'cheque')) {
        return 'CHEQUE_REVISAR';
    }

    if (in_array($normalizado, [
        'EFECTIVO', 'TRANSFERENCIA', 'DEPOSITO', 'TARJETA',
        'OXXO', '3B'
    ], true)) {
        return 'METODO_MONETARIO_PENDIENTE_CONCILIACION';
    }

    return 'METODO_NO_CLASIFICADO';
}

try {
    $plataforma->set_charset('utf8mb4');
    $conexion->set_charset('utf8mb4');

    // Clientes: relacion UISP ID con id_cliente de la plataforma.
    $clientes = [];
    $r = $plataforma->query("
        SELECT id_cliente, uisp_id
        FROM clientes
        WHERE uisp_id IS NOT NULL AND uisp_id <> ''
    ");

    while ($fila = $r->fetch_assoc()) {
        $clientes[limpiar($fila['uisp_id'])] =
            limpiar($fila['id_cliente']);
    }
    $r->free();

    // Servicios asociados a cada cliente.
    $servicios = [];
    $r = $plataforma->query("
        SELECT id_servicio, id_cliente, fecha_instalacion,
               paquete_referencia, estado
        FROM servicios
    ");

    while ($fila = $r->fetch_assoc()) {
        $idCliente = limpiar($fila['id_cliente']);
        $servicios[$idCliente][] = $fila;
    }
    $r->free();

    // Leer importacion UISP local.
    $movimientos = [];
    $r = $conexion->query("
        SELECT id_importacion, uisp_client_id, cliente_nombre_uisp,
               monto, moneda, fecha_original_uisp,
               metodo_original_uisp, metodo_normalizado,
               organizacion, nota, admin_uisp, admin_id_uisp,
               numero_cheque, revisado
        FROM uisp_pagos_importacion
        ORDER BY uisp_client_id, fecha_original_uisp, id_importacion
    ");

    while ($fila = $r->fetch_assoc()) {
        $movimientos[] = $fila;
    }
    $r->free();

    // Detectar coincidencias de cliente, fecha, monto y método.
    // Son posibles duplicados, nunca se eliminan automáticamente.
    $frecuencias = [];

    foreach ($movimientos as $p) {
        $fecha = substr(limpiar($p['fecha_original_uisp']), 0, 10);
        $clave = implode('|', [
            limpiar($p['uisp_client_id']),
            $fecha,
            number_format((float)$p['monto'], 2, '.', ''),
            mb_strtolower(limpiar($p['metodo_original_uisp']))
        ]);
        $frecuencias[$clave] = ($frecuencias[$clave] ?? 0) + 1;
    }

    $archivo = __DIR__ . '/../reportes/conciliacion_uisp_' .
               date('Ymd_His') . '.csv';

    $csv = fopen($archivo, 'w');
    if (!$csv) {
        throw new RuntimeException('No se pudo crear el CSV.');
    }

    // BOM para que Excel reconozca correctamente UTF-8.
    fwrite($csv, "\xEF\xBB\xBF");

    $encabezados = [
        'id_importacion',
        'uisp_client_id',
        'cliente_nombre_uisp',
        'id_cliente_ubb',
        'id_servicio_candidato',
        'estado_servicio',
        'fecha_instalacion',
        'paquete_referencia',
        'monto',
        'moneda',
        'fecha_original_uisp',
        'metodo_original_uisp',
        'metodo_normalizado',
        'clasificacion_metodo',
        'estado_conciliacion',
        'posible_duplicado',
        'revisado',
        'nota_uisp',
        'administrador_uisp',
        'admin_id_uisp',
        'numero_cheque',
        'observacion'
    ];

    fputcsv($csv, $encabezados, ",", chr(34), "");

    $resumen = [];
    $totalImporte = 0;
    $totalRegistros = 0;
    $duplicados = 0;

    foreach ($movimientos as $p) {
        $uispId = limpiar($p['uisp_client_id']);
        $idCliente = $clientes[$uispId] ?? '';
        $listaServicios = $idCliente !== ''
            ? ($servicios[$idCliente] ?? [])
            : [];

        $idServicio = '';
        $estadoServicio = '';
        $instalacion = '';
        $paquete = '';
        $estadoConciliacion = '';
        $observacion = '';

        if ($idCliente === '') {
            $estadoConciliacion = 'CLIENTE_NO_ENCONTRADO';
            $observacion = 'UISP ID sin coincidencia exacta en clientes. Revisar manualmente.';
        } elseif (count($listaServicios) === 0) {
            $estadoConciliacion = 'CLIENTE_SIN_SERVICIO';
            $observacion = 'El cliente existe, pero no tiene servicios registrados.';
        } elseif (count($listaServicios) > 1) {
            $estadoConciliacion = 'VARIOS_SERVICIOS_REVISAR';
            $observacion = 'No se asigna el movimiento a un servicio automáticamente.';
        } else {
            $s = $listaServicios[0];
            $idServicio = limpiar($s['id_servicio']);
            $estadoServicio = limpiar($s['estado']);
            $instalacion = limpiar($s['fecha_instalacion']);
            $paquete = limpiar($s['paquete_referencia']);
            $estadoConciliacion = 'SERVICIO_CANDIDATO_REVISAR';

            if ($estadoServicio === 'BAJA') {
                $observacion = 'Servicio dado de baja; requiere revisión histórica.';
            } elseif ($instalacion === '' || $instalacion === '0000-00-00') {
                $observacion = 'Servicio sin fecha de instalación válida.';
            } else {
                $fechaPago = substr(limpiar($p['fecha_original_uisp']), 0, 10);
                if ($fechaPago !== '' && $fechaPago < $instalacion) {
                    $observacion = 'El movimiento es anterior a la instalación del servicio.';
                } else {
                    $observacion = 'Servicio candidato; falta conciliar el periodo y confirmar el pago.';
                }
            }
        }

        $clasificacion = clasificarMetodo($p);

        $fecha = substr(limpiar($p['fecha_original_uisp']), 0, 10);
        $clave = implode('|', [
            $uispId,
            $fecha,
            number_format((float)$p['monto'], 2, '.', ''),
            mb_strtolower(limpiar($p['metodo_original_uisp']))
        ]);

        $esDuplicado = ($frecuencias[$clave] ?? 0) > 1 ? 'SI' : 'NO';
        if ($esDuplicado === 'SI') {
            $duplicados++;
            $observacion .= ' Posible coincidencia duplicada; no se elimina.';
        }

        $fila = [
            $p['id_importacion'],
            $uispId,
            $p['cliente_nombre_uisp'],
            $idCliente,
            $idServicio,
            $estadoServicio,
            $instalacion,
            $paquete,
            number_format((float)$p['monto'], 2, '.', ''),
            $p['moneda'],
            $p['fecha_original_uisp'],
            $p['metodo_original_uisp'],
            $p['metodo_normalizado'],
            $clasificacion,
            $estadoConciliacion,
            $esDuplicado,
            $p['revisado'],
            $p['nota'],
            $p['admin_uisp'],
            $p['admin_id_uisp'],
            $p['numero_cheque'],
            $observacion
        ];

        fputcsv($csv, $fila, ",", chr(34), "");

        $resumen[$clasificacion]['registros'] =
            ($resumen[$clasificacion]['registros'] ?? 0) + 1;
        $resumen[$clasificacion]['importe'] =
            ($resumen[$clasificacion]['importe'] ?? 0) + (float)$p['monto'];

        $totalImporte += (float)$p['monto'];
        $totalRegistros++;
    }

    fclose($csv);

    echo "===== REPORTE DE CONCILIACION UISP =====\n";
    echo "Modo: SOLO LECTURA\n";
    echo "Registros analizados: {$totalRegistros}\n";
    echo "Importe total importado: $" .
         number_format($totalImporte, 2) . "\n";
    echo "Registros con posible duplicado: {$duplicados}\n";
    echo "Clientes UISP relacionados: " . count($clientes) . "\n";
    echo "\n===== RESUMEN POR CLASIFICACION =====\n";

    foreach ($resumen as $tipo => $datos) {
        echo $tipo . ': ' . $datos['registros'] .
             ' registros | $' .
             number_format($datos['importe'], 2) . "\n";
    }

    echo "\nCSV generado:\n{$archivo}\n";

    $plataforma->close();
    $conexion->close();

} catch (Throwable $e) {
    if (isset($plataforma) && $plataforma instanceof mysqli) {
        $plataforma->close();
    }
    if (isset($conexion) && $conexion instanceof mysqli) {
        $conexion->close();
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
