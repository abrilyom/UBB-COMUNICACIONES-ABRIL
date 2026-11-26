<?php
declare(strict_types=1);

/**
 * Avisos que la plataforma (Jamileth) deja para el módulo de Pagos:
 * factura nueva, factura vencida, corte por adeudo, cambios de servicio.
 *
 *   GET  {URL}                 → avisos pendientes de este módulo
 *   POST {URL}?revisada=<id>   → marca un aviso como revisado
 *   Encabezado X-API-Key con la llave de Pagos.
 *
 * Configuración (fuera del repositorio):
 *   UBB_PLATAFORMA_AVISOS_URL       por defecto http://192.168.100.221:8081/api/v1/notificaciones.php
 *   UBB_PLATAFORMA_AVISOS_KEY_FILE  por defecto /etc/ubb-plataforma-avisos.key
 *
 * La campanita del encabezado aparece en todas las páginas, así que la
 * lista se guarda 60 s en caché y las llamadas tienen tiempos cortos: si la
 * plataforma no responde, el módulo sigue funcionando sin avisos.
 */

const UBB_AVISOS_CACHE_SEGUNDOS = 60;

function urlAvisosPlataforma(): string
{
    return trim((string)(getenv('UBB_PLATAFORMA_AVISOS_URL')
        ?: 'http://192.168.100.221:8081/api/v1/notificaciones.php'));
}

function archivoCacheAvisosPlataforma(): string
{
    return sys_get_temp_dir() . '/ubb_avisos_plataforma_' . substr(hash('sha256', urlAvisosPlataforma()), 0, 12) . '.json';
}

function llaveAvisosPlataforma(): string
{
    $archivo = getenv('UBB_PLATAFORMA_AVISOS_KEY_FILE') ?: '/etc/ubb-plataforma-avisos.key';
    $llave = is_readable($archivo) ? trim((string)file_get_contents($archivo)) : '';

    if ($llave === '') {
        throw new RuntimeException('Falta la llave de avisos de la plataforma.');
    }

    return $llave;
}

/** Llamada HTTP a la API de avisos. Devuelve [código HTTP, JSON decodificado o null]. */
function llamarApiAvisosPlataforma(string $metodo, array $consulta = []): array
{
    $url = urlAvisosPlataforma();

    if ($consulta) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($consulta);
    }

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('No se pudo iniciar la conexión HTTP.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . llaveAvisosPlataforma(),
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);

    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }

    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if ($respuesta === false) {
        throw new RuntimeException('La plataforma no respondió: ' . $error);
    }

    $datos = json_decode($respuesta, true);

    return [$codigo, is_array($datos) ? $datos : null];
}

/** Primer valor no vacío entre varias claves posibles. */
function primerCampoAviso(array $fila, array $claves): ?string
{
    foreach ($claves as $clave) {
        if (isset($fila[$clave]) && is_scalar($fila[$clave]) && trim((string)$fila[$clave]) !== '') {
            return trim((string)$fila[$clave]);
        }
    }

    return null;
}

/** Título legible para el tipo de aviso (CAMBIO_ESTADO_SERVICIO → "Cambio de estado del servicio"). */
function tituloTipoAviso(?string $tipo): string
{
    $conocidos = [
        'CAMBIO_ESTADO_SERVICIO' => 'Cambio de estado del servicio',
        'CAMBIO_SERVICIO' => 'Cambio de servicio',
        'FACTURA_INSTALACION' => 'Factura de instalación',
        'FACTURA_NUEVA' => 'Factura nueva',
        'FACTURA_VENCIDA' => 'Factura vencida',
        'CORTE_ADEUDO' => 'Corte por adeudo',
        'PAGO_RETIRADO' => 'Pago retirado',
    ];

    if ($tipo === null) {
        return 'Aviso de la plataforma';
    }

    $clave = strtoupper($tipo);

    if (isset($conocidos[$clave])) {
        return $conocidos[$clave];
    }

    // Tipo nuevo: "ALGO_NUEVO" → "Algo nuevo".
    $texto = mb_strtolower(str_replace('_', ' ', $tipo), 'UTF-8');

    return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
}

/**
 * Convierte la respuesta de la plataforma a una lista uniforme:
 * [id, tipo, titulo, mensaje, fecha, cliente, codigo_servicio, id_cliente, id_servicio].
 * La plataforma responde {"ok": true, "avisos": [{id, tipo, mensaje,
 * fecha_creacion, codigo_servicio, uisp_service_id, cliente}, ...]}.
 */
function normalizarAvisosPlataforma(array $datos): array
{
    $lista = $datos['notificaciones'] ?? $datos['avisos'] ?? $datos['data'] ?? $datos;

    if (!is_array($lista) || !array_is_list($lista)) {
        return [];
    }

    $avisos = [];

    foreach ($lista as $fila) {
        if (!is_array($fila)) {
            continue;
        }

        $id = primerCampoAviso($fila, ['id', 'id_notificacion', 'id_aviso']);

        if ($id === null) {
            continue;
        }

        $tipo = primerCampoAviso($fila, ['tipo', 'evento', 'categoria']);
        $mensaje = primerCampoAviso($fila, ['mensaje', 'descripcion', 'detalle', 'texto']);

        $avisos[] = [
            'id' => $id,
            'tipo' => $tipo,
            'titulo' => primerCampoAviso($fila, ['titulo', 'asunto']) ?? tituloTipoAviso($tipo),
            'mensaje' => $mensaje,
            'fecha' => primerCampoAviso($fila, ['fecha_creacion', 'fecha', 'creado', 'created_at']),
            'cliente' => primerCampoAviso($fila, ['cliente', 'nombre_cliente']),
            'codigo_servicio' => primerCampoAviso($fila, ['codigo_servicio']),
            'id_cliente' => primerCampoAviso($fila, ['id_cliente']),
            'id_servicio' => primerCampoAviso($fila, ['id_servicio']),
        ];
    }

    return $avisos;
}

/**
 * Avisos pendientes. Devuelve ['ok' => bool, 'avisos' => [...], 'error' => ?string].
 * Usa la caché salvo que $refrescar sea true.
 */
function avisosPlataformaPendientes(bool $refrescar = false): array
{
    $cache = archivoCacheAvisosPlataforma();

    if (!$refrescar && is_file($cache) && (time() - (int)filemtime($cache)) < UBB_AVISOS_CACHE_SEGUNDOS) {
        $guardado = json_decode((string)@file_get_contents($cache), true);

        if (is_array($guardado) && isset($guardado['ok'])) {
            return $guardado;
        }
    }

    try {
        [$codigo, $datos] = llamarApiAvisosPlataforma('GET');

        if ($codigo !== 200 || $datos === null) {
            throw new RuntimeException("La plataforma respondió HTTP $codigo.");
        }

        $resultado = ['ok' => true, 'avisos' => normalizarAvisosPlataforma($datos), 'error' => null];
    } catch (Throwable $e) {
        error_log('Avisos de la plataforma: ' . $e->getMessage());
        // El fallo también se guarda en caché para no esperar en cada página.
        $resultado = ['ok' => false, 'avisos' => [], 'error' => 'No se pudieron consultar los avisos de la plataforma.'];
    }

    @file_put_contents($cache, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $resultado;
}

/** Marca un aviso como revisado en la plataforma. */
function marcarAvisoPlataformaRevisado(string $id): bool
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
        throw new InvalidArgumentException('Identificador de aviso inválido.');
    }

    [$codigo] = llamarApiAvisosPlataforma('POST', ['revisada' => $id]);
    @unlink(archivoCacheAvisosPlataforma());

    return $codigo >= 200 && $codigo < 300;
}
