<?php
declare(strict_types=1);

/*
 * DIAGNÓSTICO: ¿el id_servicio de Grezz es el mismo que el de la plataforma?
 *
 * SOLO LECTURA. No modifica ninguna base ni envía solicitudes.
 *
 * Para cada servicio que reporta la API de Grezz, busca el servicio con el
 * mismo id_servicio en ubb_plataforma (Jamileth) y compara:
 *   - el plan de Grezz con el paquete de la plataforma,
 *   - la IP y la MAC, si la plataforma o integracion_winbox las tienen.
 *
 * Si los datos no coinciden, los identificadores NO son los mismos y la
 * suspensión automática podría afectar a otro cliente.
 *
 * Uso: php diagnostico_ids_grezz.php
 */

require_once __DIR__ . '/../includes/entorno_cli.php';
require_once __DIR__ . '/../api/cliente_grezz.php';

function normalizarTexto(?string $valor): string
{
    $valor = mb_strtoupper(trim((string)$valor), 'UTF-8');
    return preg_replace('/[^A-Z0-9]/', '', $valor) ?? '';
}

function normalizarMac(?string $valor): string
{
    return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$valor) ?? '');
}

/** Columnas de una tabla, o [] si no existe. */
function columnasTabla(mysqli $conexion, string $tabla): array
{
    try {
        $r = $conexion->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabla) . '`');
        return array_column($r->fetch_all(MYSQLI_ASSOC), 'Field');
    } catch (Throwable $e) {
        return [];
    }
}

/** Primera columna existente de la lista. */
function elegirColumna(array $columnas, array $opciones): ?string
{
    foreach ($opciones as $opcion) {
        if (in_array($opcion, $columnas, true)) {
            return $opcion;
        }
    }
    return null;
}

try {
    echo "===== DIAGNÓSTICO DE IDENTIFICADORES GREZZ ↔ PLATAFORMA =====\n";
    echo "Modo: SOLO LECTURA\n\n";

    $consulta = listarServiciosGrezz();

    if (
        empty($consulta['transporte_ok']) ||
        (int)($consulta['codigo_http'] ?? 0) !== 200 ||
        !is_array($consulta['respuesta']['servicios'] ?? null)
    ) {
        throw new RuntimeException('No fue posible obtener los servicios de Grezz.');
    }

    $grezz = [];
    foreach ($consulta['respuesta']['servicios'] as $s) {
        $id = filter_var($s['id_servicio'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $grezz[(int)$id] = $s;
        }
    }

    echo "Servicios en Grezz: " . count($grezz) . "\n";

    $plataforma = conectarPlataformaCli();

    // --- Tabla servicios de la plataforma -------------------------------
    $colsServicios = columnasTabla($plataforma, 'servicios');
    $colIp = elegirColumna($colsServicios, ['ip', 'direccion_ip', 'ip_asignada', 'ip_cliente']);
    $colMac = elegirColumna($colsServicios, ['mac', 'mac_address', 'direccion_mac']);

    echo "Columnas de IP/MAC en servicios: "
        . ($colIp ?? 'sin IP') . " / " . ($colMac ?? 'sin MAC') . "\n";

    $campos = ['s.id_servicio', 's.id_cliente', 's.paquete_referencia', 's.estado'];
    if ($colIp) { $campos[] = "s.`$colIp` AS ip_plataforma"; }
    if ($colMac) { $campos[] = "s.`$colMac` AS mac_plataforma"; }

    $ids = implode(',', array_keys($grezz));
    $servicios = [];

    if ($ids !== '') {
        $r = $plataforma->query(
            'SELECT ' . implode(', ', $campos) . " FROM servicios s WHERE s.id_servicio IN ($ids)"
        );
        while ($f = $r->fetch_assoc()) {
            $servicios[(int)$f['id_servicio']] = $f;
        }
    }

    // --- integracion_winbox (de Jamileth, solo lectura) -----------------
    $colsWinbox = columnasTabla($plataforma, 'integracion_winbox');
    $winbox = [];

    if ($colsWinbox) {
        $wId = elegirColumna($colsWinbox, ['id_servicio']);
        $wIp = elegirColumna($colsWinbox, ['ip', 'direccion_ip', 'address', 'ip_asignada']);
        $wMac = elegirColumna($colsWinbox, ['mac', 'mac_address', 'direccion_mac']);

        echo "integracion_winbox: columnas " . implode(', ', $colsWinbox) . "\n";

        if ($wId && ($wIp || $wMac) && $ids !== '') {
            $sel = ["`$wId` AS id_servicio"];
            if ($wIp) { $sel[] = "`$wIp` AS ip"; }
            if ($wMac) { $sel[] = "`$wMac` AS mac"; }

            $r = $plataforma->query(
                'SELECT ' . implode(', ', $sel) . " FROM integracion_winbox WHERE `$wId` IN ($ids)"
            );
            while ($f = $r->fetch_assoc()) {
                $winbox[(int)$f['id_servicio']] = $f;
            }
        }
    } else {
        echo "integracion_winbox: no existe o no se puede leer.\n";
    }

    echo "\n";

    // --- Comparación ----------------------------------------------------
    $t = [
        'no_existe' => 0,
        'plan_igual' => 0,
        'plan_distinto' => 0,
        'plan_sin_dato' => 0,
        'red_igual' => 0,
        'red_distinta' => 0,
        'red_sin_dato' => 0,
    ];
    $ejemplos = [];

    foreach ($grezz as $id => $g) {
        $p = $servicios[$id] ?? null;

        if ($p === null) {
            $t['no_existe']++;
            if (count($ejemplos) < 15) {
                $ejemplos[] = "Servicio $id: existe en Grezz pero NO en la plataforma.";
            }
            continue;
        }

        // Plan
        $planG = normalizarTexto($g['plan'] ?? '');
        $planP = normalizarTexto($p['paquete_referencia'] ?? '');

        if ($planG === '' || $planP === '') {
            $t['plan_sin_dato']++;
        } elseif ($planG === $planP || str_contains($planG, $planP) || str_contains($planP, $planG)) {
            $t['plan_igual']++;
        } else {
            $t['plan_distinto']++;
            if (count($ejemplos) < 15) {
                $ejemplos[] = sprintf(
                    'Servicio %d: plan Grezz "%s" ≠ paquete plataforma "%s" (cliente %s).',
                    $id,
                    $g['plan'] ?? '',
                    $p['paquete_referencia'] ?? '',
                    $p['id_cliente']
                );
            }
        }

        // IP / MAC
        $ipG = trim((string)($g['ip'] ?? ''));
        $macG = normalizarMac($g['mac'] ?? '');
        $ipP = trim((string)($p['ip_plataforma'] ?? $winbox[$id]['ip'] ?? ''));
        $macP = normalizarMac($p['mac_plataforma'] ?? $winbox[$id]['mac'] ?? '');

        $comparables = 0;
        $iguales = 0;

        if ($ipG !== '' && $ipP !== '') {
            $comparables++;
            $iguales += $ipG === $ipP ? 1 : 0;
        }
        if ($macG !== '' && $macP !== '') {
            $comparables++;
            $iguales += $macG === $macP ? 1 : 0;
        }

        if ($comparables === 0) {
            $t['red_sin_dato']++;
        } elseif ($iguales === $comparables) {
            $t['red_igual']++;
        } else {
            $t['red_distinta']++;
            if (count($ejemplos) < 15) {
                $ejemplos[] = sprintf(
                    'Servicio %d: IP/MAC de Grezz (%s / %s) no coinciden con la plataforma (%s / %s).',
                    $id,
                    $ipG ?: '-',
                    $macG ?: '-',
                    $ipP ?: '-',
                    $macP ?: '-'
                );
            }
        }
    }

    $existentes = count($grezz) - $t['no_existe'];

    echo "===== RESULTADO =====\n";
    echo "Servicios de Grezz que no existen en la plataforma: {$t['no_existe']}\n";
    echo "Servicios con el mismo id en ambos lados:          $existentes\n\n";
    echo "Comparación de plan:\n";
    echo "  coinciden:     {$t['plan_igual']}\n";
    echo "  NO coinciden:  {$t['plan_distinto']}\n";
    echo "  sin dato:      {$t['plan_sin_dato']}\n\n";
    echo "Comparación de IP / MAC:\n";
    echo "  coinciden:     {$t['red_igual']}\n";
    echo "  NO coinciden:  {$t['red_distinta']}\n";
    echo "  sin dato:      {$t['red_sin_dato']}\n\n";

    if ($ejemplos) {
        echo "===== EJEMPLOS DE DIFERENCIAS =====\n";
        foreach ($ejemplos as $e) {
            echo "- $e\n";
        }
        echo "\n";
    }

    $comparadosPlan = $t['plan_igual'] + $t['plan_distinto'];
    $porcentaje = $comparadosPlan > 0 ? round($t['plan_igual'] * 100 / $comparadosPlan) : 0;

    echo "===== CONCLUSIÓN =====\n";

    if ($comparadosPlan === 0 && $t['red_igual'] + $t['red_distinta'] === 0) {
        echo "No hay datos suficientes para comparar. Pide a Grezz que confirme de qué tabla\n";
        echo "toma su id_servicio.\n";
    } elseif ($porcentaje >= 90 && $t['red_distinta'] === 0) {
        echo "Los identificadores parecen ser los MISMOS ($porcentaje% de planes coinciden).\n";
    } else {
        echo "⚠ Los identificadores parecen ser DIFERENTES (solo $porcentaje% de planes coinciden).\n";
        echo "NO actives la suspensión real hasta acordar con Grezz cómo relacionar los servicios.\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
