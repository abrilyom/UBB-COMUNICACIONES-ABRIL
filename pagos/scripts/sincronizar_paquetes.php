<?php

// Sincronizador CLI de paquetes UBB.
// Lee ubb_plataforma y escribe exclusivamente en ubb_pagos.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conexion = null;
$conexion_plataforma = null;
$bloqueo = false;
$transaccion = false;

try {
    require __DIR__ . '/../conexion.php';
    require __DIR__ . '/../conexion_plataforma.php';

    echo "===== SINCRONIZACION DE PAQUETES UBB =====\n";
    echo "Inicio: " . date('Y-m-d H:i:s') . "\n";

    // Evitar ejecuciones simultáneas.
    $r = $conexion->query(
        "SELECT GET_LOCK('ubb_sync_paquetes_servicio', 0) AS obtenido"
    );

    $bloqueo = (int)$r->fetch_assoc()['obtenido'] === 1;

    if (!$bloqueo) {
        throw new RuntimeException(
            "Ya existe otra sincronización de paquetes en ejecución."
        );
    }

    // Leer servicios remotos. Esta conexión solo ejecuta SELECT.
    $resultado = $conexion_plataforma->query("
        SELECT id_servicio, id_cliente, paquete_referencia
        FROM servicios
        ORDER BY id_servicio
    ");

    $servicios = [];
    $idsVistos = [];

    while ($fila = $resultado->fetch_assoc()) {
        $idServicio = (string)$fila['id_servicio'];
        $idCliente = (string)$fila['id_cliente'];
        $paqueteOrigen = $fila['paquete_referencia'];

        if (
            !ctype_digit($idServicio) ||
            !ctype_digit($idCliente) ||
            (int)$idServicio <= 0 ||
            (int)$idCliente <= 0
        ) {
            throw new RuntimeException(
                "Se encontró un identificador inválido en la fuente remota."
            );
        }

        if (isset($idsVistos[$idServicio])) {
            throw new RuntimeException(
                "Servicio duplicado en la fuente remota: $idServicio"
            );
        }

        $idsVistos[$idServicio] = true;

        $servicios[] = [
            'servicio' => $idServicio,
            'cliente' => $idCliente,
            'paquete_origen' => $paqueteOrigen
        ];
    }

    $total = count($servicios);

    if ($total === 0) {
        throw new RuntimeException(
            "La consulta remota devolvió cero servicios. Operación cancelada."
        );
    }

    // Leer referencias locales para validar que cada servicio corresponda
    // al mismo cliente. No se crean referencias desde este sincronizador.
    $referencias = [];

    $r = $conexion->query("
        SELECT id_servicio, id_cliente
        FROM referencias_servicio
    ");

    while ($fila = $r->fetch_assoc()) {
        $referencias[(string)$fila['id_servicio']] = (string)$fila['id_cliente'];
    }

    // Leer catálogo local y crear correspondencia por nombre exacto.
    $catalogo = [];

    $r = $conexion->query("
        SELECT id_paquete, nombre_paquete
        FROM catalogo_paquetes
    ");

    while ($fila = $r->fetch_assoc()) {
        $nombre = (string)$fila['nombre_paquete'];

        if (isset($catalogo[$nombre])) {
            throw new RuntimeException(
                "Nombre de paquete duplicado en el catálogo: $nombre"
            );
        }

        $catalogo[$nombre] = (string)$fila['id_paquete'];
    }

    if (count($catalogo) === 0) {
        throw new RuntimeException(
            "El catálogo local está vacío. Operación cancelada."
        );
    }

    // Validar todos los servicios antes de iniciar escrituras locales.
    foreach ($servicios as $item) {
        $idServicio = $item['servicio'];

        if (!isset($referencias[$idServicio])) {
            throw new RuntimeException(
                "El servicio $idServicio no tiene referencia local. " .
                "Primero debe revisarse la sincronización de referencias."
            );
        }

        if ($referencias[$idServicio] !== $item['cliente']) {
            throw new RuntimeException(
                "CONFLICTO: el servicio $idServicio aparece asociado " .
                "a otro cliente en referencias_servicio."
            );
        }
    }

    // Leer asignaciones actuales.
    $existentes = [];

    $r = $conexion->query("
        SELECT id_servicio, id_cliente, id_paquete,
               estado_asignacion, paquete_origen
        FROM servicios_paquetes
    ");

    while ($fila = $r->fetch_assoc()) {
        $existentes[(string)$fila['id_servicio']] = $fila;
    }

    $nuevos = 0;
    $actualizados = 0;
    $sinCambios = 0;
    $pendientes = 0;

    $conexion->begin_transaction();
    $transaccion = true;

    $insertar = $conexion->prepare("
        INSERT INTO servicios_paquetes (
            id_servicio,
            id_cliente,
            id_paquete,
            estado_asignacion,
            paquete_origen,
            ultima_sincronizacion
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $actualizar = $conexion->prepare("
        UPDATE servicios_paquetes
        SET id_paquete = ?,
            estado_asignacion = ?,
            paquete_origen = ?,
            ultima_sincronizacion = NOW()
        WHERE id_servicio = ?
    ");

    foreach ($servicios as $item) {
        $idServicio = $item['servicio'];
        $idCliente = $item['cliente'];

        $paqueteOrigen = $item['paquete_origen'] !== null
            ? trim((string)$item['paquete_origen'])
            : '';

        if ($paqueteOrigen === '') {
            $paqueteOrigen = '(SIN PAQUETE)';
        }

        // Solo se asigna un paquete si coincide exactamente con el catálogo.
        if (isset($catalogo[$paqueteOrigen])) {
            $idPaquete = $catalogo[$paqueteOrigen];
            $estadoAsignacion = 'ASIGNADO';
        } else {
            $idPaquete = null;
            $estadoAsignacion = 'PENDIENTE_REVISION';
            $pendientes++;
        }

        if (isset($existentes[$idServicio])) {
            $local = $existentes[$idServicio];

            if ((string)$local['id_cliente'] !== $idCliente) {
                throw new RuntimeException(
                    "CONFLICTO: la asignación local del servicio " .
                    "$idServicio pertenece a otro cliente."
                );
            }

            $paqueteLocal = $local['id_paquete'] === null
                ? null
                : (string)$local['id_paquete'];

            $paqueteIgual = $paqueteLocal === $idPaquete;
            $estadoIgual = $local['estado_asignacion'] === $estadoAsignacion;
            $origenIgual = $local['paquete_origen'] === $paqueteOrigen;

            if (!$paqueteIgual || !$estadoIgual || !$origenIgual) {
                $actualizar->bind_param(
                    'ssss',
                    $idPaquete,
                    $estadoAsignacion,
                    $paqueteOrigen,
                    $idServicio
                );

                $actualizar->execute();
                $actualizados++;
            } else {
                // Actualizar únicamente la fecha de sincronización.
                $actualizar->bind_param(
                    'ssss',
                    $idPaquete,
                    $estadoAsignacion,
                    $paqueteOrigen,
                    $idServicio
                );

                $actualizar->execute();
                $sinCambios++;
            }

        } else {
            $insertar->bind_param(
                'sssss',
                $idServicio,
                $idCliente,
                $idPaquete,
                $estadoAsignacion,
                $paqueteOrigen
            );

            $insertar->execute();
            $nuevos++;
        }
    }

    $insertar->close();
    $actualizar->close();

    $conexion->commit();
    $transaccion = false;

    echo "\n===== RESULTADO =====\n";
    echo "Servicios leídos: $total\n";
    echo "Asignaciones nuevas: $nuevos\n";
    echo "Asignaciones actualizadas: $actualizados\n";
    echo "Sin cambios de asignación: $sinCambios\n";
    echo "Servicios pendientes de revisión: $pendientes\n";

    $r = $conexion->query("
        SELECT
            COUNT(*) AS total,
            SUM(estado_asignacion = 'ASIGNADO') AS asignados,
            SUM(estado_asignacion = 'PENDIENTE_REVISION') AS pendientes,
            SUM(id_paquete IS NULL) AS sin_paquete
        FROM servicios_paquetes
    ");

    $resumen = $r->fetch_assoc();

    echo "\n===== TOTAL LOCAL =====\n";
    echo "Registros: " . $resumen['total'] . "\n";
    echo "Asignados: " . $resumen['asignados'] . "\n";
    echo "Pendientes: " . $resumen['pendientes'] . "\n";
    echo "Sin paquete: " . $resumen['sin_paquete'] . "\n";

    echo "\nSINCRONIZACION DE PAQUETES COMPLETADA.\n";

} catch (Throwable $e) {
    if ($transaccion && $conexion instanceof mysqli) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "La operación local fue cancelada o revertida.\n");
    exit(1);

} finally {
    if ($bloqueo && $conexion instanceof mysqli) {
        try {
            $conexion->query(
                "SELECT RELEASE_LOCK('ubb_sync_paquetes_servicio')"
            );
        } catch (Throwable $ignorado) {
        }
    }
}
