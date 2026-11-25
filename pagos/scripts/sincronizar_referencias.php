<?php

// Sincronizador CLI de referencias permanentes UBB.
// Lee ubb_plataforma y escribe exclusivamente en ubb_pagos.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

$modo = $argv[1] ?? '';

if (!in_array($modo, ['--simular', '--aplicar'], true)) {
    fwrite(
        STDERR,
        "Uso: php sincronizar_referencias.php --simular|--aplicar\n"
    );
    exit(2);
}

$soloSimular = ($modo === '--simular');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conexion = null;
$conexion_plataforma = null;
$bloqueo = false;

try {
    require __DIR__ . '/../conexion.php';
    require __DIR__ . '/../conexion_plataforma.php';

    echo "===== SINCRONIZACION DE REFERENCIAS UBB =====\n";
    echo "Inicio: " . date('Y-m-d H:i:s') . "\n";

    // Evitar dos sincronizaciones simultáneas.
    $r = $conexion->query(
        "SELECT GET_LOCK('ubb_sync_referencias_servicio', 0) AS obtenido"
    );

    $bloqueo = (int)$r->fetch_assoc()['obtenido'] === 1;

    if (!$bloqueo) {
        throw new RuntimeException(
            "Ya existe otra sincronización en ejecución."
        );
    }

    // Leer los servicios remotos. No se ejecuta ninguna escritura remota.
    $resultado = $conexion_plataforma->query("
        SELECT id_cliente, id_servicio, estado
        FROM servicios
        ORDER BY id_servicio
    ");

    $servicios = [];
    $idsVistos = [];

    $estadosValidos = [
        'PENDIENTE_INSTALACION',
        'ACTIVO',
        'SUSPENDIDO',
        'BAJA'
    ];

    while ($fila = $resultado->fetch_assoc()) {
        $cliente = (string)$fila['id_cliente'];
        $servicio = (string)$fila['id_servicio'];
        $estado = (string)$fila['estado'];

        if (
            !ctype_digit($cliente) ||
            !ctype_digit($servicio) ||
            (int)$cliente <= 0 ||
            (int)$servicio <= 0
        ) {
            throw new RuntimeException(
                "Se encontró un identificador inválido."
            );
        }

        if (!in_array($estado, $estadosValidos, true)) {
            throw new RuntimeException(
                "Estado no reconocido para servicio $servicio: $estado"
            );
        }

        if (isset($idsVistos[$servicio])) {
            throw new RuntimeException(
                "Servicio duplicado en la fuente remota: $servicio"
            );
        }

        $idsVistos[$servicio] = true;

        $servicios[] = [
            'cliente' => $cliente,
            'servicio' => $servicio,
            'referencia' => "UBBC{$cliente}S{$servicio}",
            'estado' => $estado
        ];
    }

    $total = count($servicios);

    // Una respuesta vacía nunca debe provocar una sincronización.
    if ($total === 0) {
        throw new RuntimeException(
            "La consulta remota devolvió cero servicios. Operación cancelada."
        );
    }

    // Leer las referencias locales existentes.
    $existentes = [];

    $r = $conexion->query("
        SELECT id_cliente, id_servicio, referencia_base, estado_servicio
        FROM referencias_servicio
    ");

    while ($fila = $r->fetch_assoc()) {
        $existentes[(string)$fila['id_servicio']] = $fila;
    }

    $nuevos = 0;
    $referenciasMigradas = 0;
    $estadosActualizados = 0;
    $sinCambios = 0;
    $serviciosLocalesAusentes = 0;

    // Detectar servicios locales que no aparecen en la fuente remota.
    foreach ($existentes as $idLocal => $local) {
        if (!isset($idsVistos[$idLocal])) {
            $serviciosLocalesAusentes++;
        }
    }

    // Preparar un índice de referencias locales para detectar colisiones.
    $referenciasLocales = [];

    foreach ($existentes as $local) {
        $referenciasLocales[$local['referencia_base']] =
            (string)$local['id_servicio'];
    }

    // Validar que ninguna referencia nueva esté ocupada por otro servicio.
    $referenciasRemotas = [];

    foreach ($servicios as $item) {
        $referencia = $item['referencia'];
        $idServicio = $item['servicio'];

        if (isset($referenciasRemotas[$referencia])) {
            throw new RuntimeException(
                "CONFLICTO: referencia duplicada en la fuente remota: $referencia"
            );
        }

        $referenciasRemotas[$referencia] = $idServicio;

        if (
            isset($referenciasLocales[$referencia]) &&
            $referenciasLocales[$referencia] !== $idServicio
        ) {
            throw new RuntimeException(
                "CONFLICTO: la referencia $referencia pertenece al servicio " .
                $referenciasLocales[$referencia] .
                " y no puede asignarse al servicio $idServicio."
            );
        }
    }

    // Preparar los cambios sin escribir todavía.
    $cambios = [];

    foreach ($servicios as $item) {
        $idServicio = $item['servicio'];

        if (isset($existentes[$idServicio])) {
            $local = $existentes[$idServicio];

            if ((string)$local['id_cliente'] !== $item['cliente']) {
                throw new RuntimeException(
                    "CONFLICTO: el servicio $idServicio aparece asociado " .
                    "a otro cliente. Requiere revisión manual."
                );
            }

            $referenciaCambio =
                $local['referencia_base'] !== $item['referencia'];

            $estadoCambio =
                $local['estado_servicio'] !== $item['estado'];

            if ($referenciaCambio) {
                $referenciasMigradas++;
            }

            if ($estadoCambio) {
                $estadosActualizados++;
            }

            if (!$referenciaCambio && !$estadoCambio) {
                $sinCambios++;
            }

            $cambios[] = [
                'tipo' => 'existente',
                'item' => $item,
                'referencia_cambio' => $referenciaCambio,
                'estado_cambio' => $estadoCambio
            ];

        } else {
            $nuevos++;

            $cambios[] = [
                'tipo' => 'nuevo',
                'item' => $item
            ];
        }
    }

    // Solo --aplicar puede iniciar una transacción y escribir.
    if (!$soloSimular) {
        $conexion->begin_transaction();

        $insertar = $conexion->prepare("
            INSERT INTO referencias_servicio (
                id_cliente,
                id_servicio,
                referencia_base,
                estado_servicio,
                ultima_sincronizacion
            ) VALUES (?, ?, ?, ?, NOW())
        ");

        $actualizar = $conexion->prepare("
            UPDATE referencias_servicio
            SET referencia_base = ?,
                estado_servicio = ?,
                ultima_sincronizacion = NOW()
            WHERE id_servicio = ?
        ");

        foreach ($cambios as $cambio) {
            $item = $cambio['item'];

            if ($cambio['tipo'] === 'nuevo') {
                $cliente = $item['cliente'];
                $idServicio = $item['servicio'];
                $referencia = $item['referencia'];
                $estado = $item['estado'];

                $insertar->bind_param(
                    'ssss',
                    $cliente,
                    $idServicio,
                    $referencia,
                    $estado
                );

                $insertar->execute();

            } elseif (
                $cambio['referencia_cambio'] ||
                $cambio['estado_cambio']
            ) {
                $referencia = $item['referencia'];
                $estado = $item['estado'];
                $idServicio = $item['servicio'];

                $actualizar->bind_param(
                    'sss',
                    $referencia,
                    $estado,
                    $idServicio
                );

                $actualizar->execute();
            }
        }

        $insertar->close();
        $actualizar->close();

        $conexion->commit();
    }

    echo "\n===== RESULTADO =====\n";
    echo "Modo: " . ($soloSimular ? "SIMULACION" : "APLICACION") . "\n";
    echo "Servicios leídos: $total\n";
    echo "Referencias por migrar: $referenciasMigradas\n";
    echo "Estados por actualizar: $estadosActualizados\n";
    echo "Servicios nuevos: $nuevos\n";
    echo "Sin cambios: $sinCambios\n";
    echo "Servicios locales ausentes en plataforma: " .
        "$serviciosLocalesAusentes\n";
    echo "Referencias locales actuales: " . count($existentes) . "\n";
    echo "Total local previsto después de aplicar: " .
        (count($existentes) + $nuevos) . "\n";

    if ($soloSimular) {
        echo "\nSIMULACION COMPLETADA. NO SE MODIFICARON REGISTROS.\n";
    } else {
        echo "\nSINCRONIZACION COMPLETADA.\n";
    }

} catch (Throwable $e) {
    if ($conexion instanceof mysqli) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "La operación fue cancelada o revertida.\n");
    exit(1);

} finally {
    if ($bloqueo && $conexion instanceof mysqli) {
        try {
            $conexion->query(
                "SELECT RELEASE_LOCK('ubb_sync_referencias_servicio')"
            );
        } catch (Throwable $ignorado) {
        }
    }
}
