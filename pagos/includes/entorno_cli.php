<?php
declare(strict_types=1);

/**
 * Conexión para scripts de línea de comandos.
 *
 * Usa la variable de entorno UBB_PAGOS_ENTORNO (produccion | pruebas),
 * igual que los scripts de conciliación existentes, y verifica que la
 * base seleccionada corresponda al entorno solicitado.
 *
 * Devuelve la conexión mysqli de UBB Pagos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function conectarPagosCli(): mysqli
{
    $entorno = getenv('UBB_PAGOS_ENTORNO') ?: 'produccion';

    if ($entorno === 'pruebas') {
        require __DIR__ . '/../conexion_pruebas.php';
    } elseif ($entorno === 'produccion') {
        require __DIR__ . '/../conexion.php';
    } else {
        throw new RuntimeException('Entorno inválido. Utiliza produccion o pruebas.');
    }

    if (!isset($conexion) || !$conexion instanceof mysqli) {
        throw new RuntimeException('La conexión local no devolvió una instancia mysqli.');
    }

    $conexion->set_charset('utf8mb4');

    $baseEsperada = $entorno === 'pruebas' ? 'ubb_pagos_pruebas' : 'ubb_pagos';
    $baseSeleccionada = $conexion->query('SELECT DATABASE()')->fetch_row()[0] ?? '';

    if ($baseSeleccionada !== $baseEsperada) {
        throw new RuntimeException('La conexión no corresponde al entorno solicitado.');
    }

    echo "Entorno: $entorno | Base: $baseSeleccionada\n";

    return $conexion;
}

/**
 * Conexión de solo lectura a la plataforma (clientes, servicios).
 */
function conectarPlataformaCli(): mysqli
{
    require __DIR__ . '/../conexion_plataforma.php';

    if (!isset($conexion_plataforma) || !$conexion_plataforma instanceof mysqli) {
        throw new RuntimeException('No se pudo conectar con la plataforma.');
    }

    $conexion_plataforma->set_charset('utf8mb4');

    return $conexion_plataforma;
}

/**
 * Guarda el resultado de una ejecución en automatizacion_ejecuciones para
 * mostrarlo en el panel. Si la tabla no existe, no interrumpe el proceso.
 */
function registrarEjecucion(
    mysqli $conexion,
    string $proceso,
    bool $confirmado,
    DateTimeImmutable $inicio,
    array $resumen,
    ?string $error = null
): void {
    try {
        $modo = $confirmado ? 'CONFIRMADO' : 'SIMULACION';
        $resultado = $error === null ? 'EXITOSA' : 'ERROR';
        $json = json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mensaje = $error !== null ? mb_substr($error, 0, 500, 'UTF-8') : null;
        $fechaInicio = $inicio->format('Y-m-d H:i:s');

        $stmt = $conexion->prepare("
            INSERT INTO automatizacion_ejecuciones
                (proceso, modo, resultado, resumen_json, mensaje, fecha_inicio, fecha_fin)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('ssssss', $proceso, $modo, $resultado, $json, $mensaje, $fechaInicio);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        fwrite(STDERR, "Aviso: no se registró la ejecución ({$e->getMessage()}).\n");
    }
}

/**
 * Bloqueo con nombre para evitar dos ejecuciones simultáneas del mismo script.
 */
function obtenerBloqueoScript(mysqli $conexion, string $nombre): void
{
    $stmt = $conexion->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->bind_param('s', $nombre);
    $stmt->execute();
    $obtenido = (int)$stmt->get_result()->fetch_row()[0] === 1;
    $stmt->close();

    if (!$obtenido) {
        throw new RuntimeException("Ya existe otra ejecución en curso ($nombre).");
    }
}

function liberarBloqueoScript(mysqli $conexion, string $nombre): void
{
    try {
        $stmt = $conexion->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignorado) {
    }
}
