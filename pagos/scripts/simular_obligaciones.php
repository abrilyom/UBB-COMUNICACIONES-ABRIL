<?php
/**
 * Simulador UBB: mensualidad actual e historial teórico.
 * SOLO LECTURA. No inserta ni modifica registros.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../conexion.php';
$plataforma = require __DIR__ . '/../conexion_plataforma.php';
$planes = require __DIR__ . '/../conexion_planes.php';

function fechaAniversario(DateTimeImmutable $mes, int $dia): DateTimeImmutable
{
    $ultimo = (int) $mes->format('t');
    $diaReal = min($dia, $ultimo);

    return new DateTimeImmutable(
        $mes->format('Y-m') . '-' . str_pad((string)$diaReal, 2, '0', STR_PAD_LEFT)
    );
}

function normalizarNombrePlan(?string $nombre): string
{
    $nombre = mb_strtoupper(trim((string)$nombre), 'UTF-8');
    return preg_replace('/\\s+/u', '', $nombre);
}

function esPagoPrueba(array $pago): bool
{
    $texto = mb_strtolower(
        (string)($pago['referencia'] ?? '') . ' ' .
        (string)($pago['observaciones'] ?? ''),
        'UTF-8'
    );

    return str_contains($texto, 'prueba')
        || str_contains($texto, 'no es un pago real')
        || str_contains($texto, 'tecnica');
}

function mostrar(array $dato): void
{
    echo json_encode($dato, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$generar = in_array('--generar', $argv ?? [], true);

try {
    // Fecha actual tomada de la base de servicios.
    $r = $plataforma->query("SELECT CURDATE() AS fecha_actual");
    $hoyTexto = $r->fetch_assoc()['fecha_actual'];
    $r->free();

    $hoy = new DateTimeImmutable($hoyTexto);
    $mesActual = new DateTimeImmutable($hoy->format('Y-m-01'));

    // Servicios remotos.
    $servicios = [];

    $r = $plataforma->query("
        SELECT id_servicio, id_cliente, fecha_instalacion, paquete_referencia, estado
        FROM servicios
        -- Los que se facturan en la plataforma toman su mensualidad de la factura
        -- (scripts/sincronizar_facturas_plataforma.php), no del precio del plan.
        WHERE COALESCE(factura_en_plataforma, 0) = 0
    ");

    while ($f = $r->fetch_assoc()) {
        $servicios[(string)$f['id_servicio']] = $f;
    }
    $r->free();

    // Catálogo BGREZZ: índice por nombre normalizado.
    // Se conservan todas las coincidencias para detectar ambigüedades.
    $catalogo = [];

    $r = $planes->query("
        SELECT id_plan, nombre_plan, precio_mensual, estado
        FROM planes
    ");

    while ($f = $r->fetch_assoc()) {
        $clavePlan = normalizarNombrePlan($f['nombre_plan']);
        $catalogo[$clavePlan][] = $f;
    }
    $r->free();

    // Asignaciones remotas.
    $asignaciones = [];

    $r = $planes->query("
        SELECT
            sp.id_servicio,
            sp.estado_asignacion,
            p.nombre_plan,
            p.precio_mensual,
            p.estado AS estado_plan
        FROM servicios_planes sp
        LEFT JOIN planes p ON p.id_plan = sp.id_plan
    ");

    while ($f = $r->fetch_assoc()) {
        $asignaciones[(string)$f['id_servicio']] = $f;
    }
    $r->free();

    // Obligaciones existentes.
    $obligaciones = [];

    $r = $conexion->query("
        SELECT id_servicio, periodo, concepto
        FROM obligaciones_pago
    ");

    while ($f = $r->fetch_assoc()) {
        $clave = $f['id_servicio'] . '|' . $f['periodo'] . '|' . $f['concepto'];
        $obligaciones[$clave] = true;
    }
    $r->free();

    // Pagos reales y aplicaciones existentes.
    // Un pago confirmado sin aplicación se conserva para revisión manual.
    $pagosPorServicio = [];

    $r = $conexion->query("
        SELECT
            p.id_pago,
            p.id_servicio,
            p.estado_pago,
            p.fecha_pago,
            p.monto,
            p.referencia,
            p.observaciones,
            (
                SELECT COUNT(*)
                FROM pagos_obligaciones po
                WHERE po.id_pago = p.id_pago
            ) AS aplicaciones
        FROM pagos p
        WHERE p.id_servicio IS NOT NULL
    ");

    while ($f = $r->fetch_assoc()) {
        $id = (string)$f['id_servicio'];

        if (!isset($pagosPorServicio[$id])) {
            $pagosPorServicio[$id] = [
                'pruebas' => 0,
                'pagados_reales' => 0,
                'pagos_sin_aplicar' => []
            ];
        }

        if (esPagoPrueba($f)) {
            $pagosPorServicio[$id]['pruebas']++;
        } elseif ($f['estado_pago'] === 'PAGADO') {
            $pagosPorServicio[$id]['pagados_reales']++;

            if ((int)$f['aplicaciones'] === 0 && !empty($f['fecha_pago'])) {
                $f['fecha_pago_objeto'] = new DateTimeImmutable($f['fecha_pago']);
                $pagosPorServicio[$id]['pagos_sin_aplicar'][] = $f;
            }
        }
    }
    $r->free();

    // Servicios locales asignados.
    $r = $conexion->query("
        SELECT id_servicio, id_cliente
        FROM servicios_paquetes
        WHERE estado_asignacion = 'ASIGNADO'
        ORDER BY id_servicio
    ");

    $elegibles = [];
    $exclusiones = [];

    while ($local = $r->fetch_assoc()) {
        $id = (string)$local['id_servicio'];
        $clienteLocal = (string)$local['id_cliente'];
        $motivo = null;

        if (!isset($servicios[$id])) {
            $motivo = 'SERVICIO_NO_ENCONTRADO_EN_PLATAFORMA';
        } else {
            $servicio = $servicios[$id];

            if ($clienteLocal !== (string)$servicio['id_cliente']) {
                $motivo = 'ID_CLIENTE_NO_COINCIDE';
            } elseif (($servicio['estado'] ?? '') === 'BAJA') {
                $motivo = 'SERVICIO_BAJA';
            } elseif (
                empty($servicio['fecha_instalacion']) ||
                $servicio['fecha_instalacion'] === '0000-00-00'
            ) {
                $motivo = 'SIN_FECHA_INSTALACION';
            }
        }

        $asignacion = $asignaciones[$id] ?? null;
        $plan = null;

        // Primera opción: asignación activa y plan activo en BGREZZ.
        if (
            $motivo === null &&
            $asignacion !== null &&
            $asignacion['estado_asignacion'] === 'ACTIVA'
        ) {
            if ($asignacion['estado_plan'] === 'ACTIVO') {
                $plan = [
                    'nombre_plan' => $asignacion['nombre_plan'],
                    'precio_mensual' => $asignacion['precio_mensual'],
                    'estado' => $asignacion['estado_plan']
                ];
            } else {
                $motivo = 'PLAN_ASIGNADO_NO_ACTIVO_O_INEXISTENTE';
            }
        }

        // Segunda opción: referencia del servicio.
        // Se utiliza si no hay asignación activa.
        if (
            $motivo === null &&
            $plan === null
        ) {
            $paquete = trim((string)($servicio['paquete_referencia'] ?? ''));
            $clavePaquete = normalizarNombrePlan($paquete);

            if ($clavePaquete === '') {
                $motivo = 'SERVICIO_SIN_REFERENCIA_DE_PAQUETE';
            } elseif (!isset($catalogo[$clavePaquete])) {
                $motivo = 'PAQUETE_SIN_COINCIDENCIA_NORMALIZADA';
            } elseif (count($catalogo[$clavePaquete]) !== 1) {
                $motivo = 'PAQUETE_CON_COINCIDENCIA_AMBIGUA';
            } else {
                $candidato = $catalogo[$clavePaquete][0];

                if ($candidato['estado'] !== 'ACTIVO') {
                    $motivo = 'PLAN_POR_REFERENCIA_NO_ACTIVO';
                } else {
                    $plan = [
                        'nombre_plan' => $candidato['nombre_plan'],
                        'precio_mensual' => $candidato['precio_mensual'],
                        'estado' => $candidato['estado']
                    ];
                }
            }
        }

        if ($motivo === null && (float)$plan['precio_mensual'] <= 0) {
            $motivo = 'PRECIO_NO_VALIDO';
        }

        if ($motivo !== null) {
            $exclusiones[$motivo] = ($exclusiones[$motivo] ?? 0) + 1;
            continue;
        }

        $instalacion = new DateTimeImmutable($servicio['fecha_instalacion']);
        $diaAncla = (int)$instalacion->format('j');

        // Primer vencimiento: un mes después de la instalación.
        $primerMes = new DateTimeImmutable(
            $instalacion->modify('first day of next month')->format('Y-m-01')
        );

        // Si se instaló antes del mes actual, el ciclo actual vence
        // en el mes actual. Si se instaló durante este mes, vence el próximo.
        $mesCicloActual = $instalacion < $mesActual
            ? $mesActual
            : $mesActual->modify('+1 month');

        $fechaVencimiento = fechaAniversario($mesCicloActual, $diaAncla);
        $periodo = $fechaVencimiento->format('Ym');

        $pagosServicio = $pagosPorServicio[$id] ?? [
            'pruebas' => 0,
            'pagados_reales' => 0,
            'pagos_sin_aplicar' => []
        ];

        $elegibles[$id] = [
            'id_cliente' => $clienteLocal,
            'id_servicio' => $id,
            'instalacion' => $instalacion,
            'dia_ancla' => $diaAncla,
            'primer_mes' => $primerMes,
            'mes_ciclo_actual' => $mesCicloActual,
            'fecha_vencimiento' => $fechaVencimiento,
            'periodo' => $periodo,
            'plan' => $plan['nombre_plan'],
            'monto' => (float)$plan['precio_mensual'],
            'pagos_prueba' => $pagosServicio['pruebas'],
            'pagos_reales_pagados' => $pagosServicio['pagados_reales'],
            'pagos_sin_aplicar' => $pagosServicio['pagos_sin_aplicar']
        ];
    }
    $r->free();

    // ---------------------------------------------------------
    // REPORTE 1: CICLO ACTUAL
    // ---------------------------------------------------------

    $actual = [
        'servicios_elegibles' => count($elegibles),
        'propuestas_actuales' => 0,
        'obligaciones_ya_existentes' => 0,
        'servicios_con_pago_real_para_revision' => 0,
        'pagos_sin_aplicar_para_revision' => 0,
        'importe_propuesto' => 0.00,
        'por_vencer' => 0,
        'en_gracia' => 0,
        'fuera_de_gracia' => 0
    ];

    $muestrasActuales = [];
    $muestrasPagosRevisionActuales = [];
    $propuestasParaInsertar = [];

    foreach ($elegibles as $item) {
        $id = $item['id_servicio'];
        $clave = $id . '|' . $item['periodo'] . '|MENSUALIDAD';

        if (isset($obligaciones[$clave])) {
            $actual['obligaciones_ya_existentes']++;
            continue;
        }

        $vencimiento = $item['fecha_vencimiento'];
        $inicioVentana = $vencimiento->modify('-10 days');
        $finGracia = $vencimiento->modify('+10 days');

        // Buscar pagos sin aplicación cuya fecha pueda corresponder
        // al ciclo actual. Nunca se confirman automáticamente.
        $candidatosPago = [];

        foreach ($item['pagos_sin_aplicar'] as $pagoSinAplicar) {
            $fechaPago = $pagoSinAplicar['fecha_pago_objeto'];

            if ($fechaPago >= $inicioVentana && $fechaPago <= $finGracia) {
                $candidatosPago[] = $pagoSinAplicar;
            }
        }

        if ($candidatosPago) {
            $actual['servicios_con_pago_real_para_revision']++;
            $actual['pagos_sin_aplicar_para_revision'] += count($candidatosPago);

            foreach ($candidatosPago as $pagoCandidato) {
                if (count($muestrasPagosRevisionActuales) < 50) {
                    $muestrasPagosRevisionActuales[] = [
                        'id_pago' => (int)$pagoCandidato['id_pago'],
                        'id_cliente' => $item['id_cliente'],
                        'id_servicio' => $id,
                        'fecha_pago' => $pagoCandidato['fecha_pago'],
                        'monto_pago' => number_format((float)$pagoCandidato['monto'], 2, '.', ''),
                        'periodo_candidato' => $item['periodo'],
                        'monto_mensualidad' => number_format($item['monto'], 2, '.', ''),
                        'referencia_pago' => $pagoCandidato['referencia'],
                        'ventana_revision_desde' => $inicioVentana->format('Y-m-d'),
                        'ventana_revision_hasta' => $finGracia->format('Y-m-d'),
                        'estado' => 'REQUIERE_REVISION_MANUAL'
                    ];
                }
            }

            // No proponer una nueva obligación hasta revisar el pago candidato.
            continue;
        }

        if ($hoy < $vencimiento) {
            $estado = 'POR_VENCER';
            $actual['por_vencer']++;
        } elseif ($hoy <= $finGracia) {
            $estado = 'EN_GRACIA';
            $actual['en_gracia']++;
        } else {
            $estado = 'FUERA_DE_GRACIA';
            $actual['fuera_de_gracia']++;
        }

        $actual['propuestas_actuales']++;
        $actual['importe_propuesto'] += $item['monto'];

        $propuestasParaInsertar[] = [
            'id_cliente' => (int)$item['id_cliente'],
            'id_servicio' => (int)$id,
            'periodo' => $item['periodo'],
            'monto' => (float)$item['monto'],
            'fecha_vencimiento' => $vencimiento->format('Y-m-d'),
            'referencia' => 'UBBC' . $item['id_cliente'] . 'S' . $id . 'M' . $vencimiento->format('Ymd')
        ];

        if (count($muestrasActuales) < 20) {
            $muestrasActuales[] = [
                'id_cliente' => $item['id_cliente'],
                'id_servicio' => $id,
                'plan' => $item['plan'],
                'monto' => number_format($item['monto'], 2, '.', ''),
                'periodo' => $item['periodo'],
                'fecha_vencimiento' => $vencimiento->format('Y-m-d'),
                'fin_periodo_gracia' => $finGracia->format('Y-m-d'),
                'estado_calculado' => $estado,
                'referencia_sugerida' => 'UBBC' . $item['id_cliente'] . 'S' . $id . 'M' . $vencimiento->format('Ymd')
            ];
        }
    }

    // ---------------------------------------------------------
    // REPORTE 2: HISTORIAL TEÓRICO
    // ---------------------------------------------------------

    $historico = [
        'servicios_con_periodos_sin_obligacion_ubb' => 0,
        'periodos_sin_obligacion_ubb' => 0,
        'periodos_ya_registrados' => 0,
        'servicios_con_pagos_confirmados_para_revision' => 0,
        'importe_referencial_no_validado' => 0.00
    ];

    $historicoPorAnio = [];
    $muestrasHistoricas = [];

    foreach ($elegibles as $item) {
        $id = $item['id_servicio'];
        $cursor = $item['primer_mes'];
        $tienePeriodos = false;

        // Solo meses anteriores al ciclo actual.
        while ($cursor < $item['mes_ciclo_actual']) {
            $vencimiento = fechaAniversario($cursor, $item['dia_ancla']);
            $periodo = $vencimiento->format('Ym');
            $clave = $id . '|' . $periodo . '|MENSUALIDAD';

            if (isset($obligaciones[$clave])) {
                $historico['periodos_ya_registrados']++;
            } else {
                $tienePeriodos = true;
                $historico['periodos_sin_obligacion_ubb']++;
                $historico['importe_referencial_no_validado'] += $item['monto'];

                $anio = $vencimiento->format('Y');

                if (!isset($historicoPorAnio[$anio])) {
                    $historicoPorAnio[$anio] = [
                        'periodos' => 0,
                        'importe_teorico' => 0.00
                    ];
                }

                $historicoPorAnio[$anio]['periodos']++;
                $historicoPorAnio[$anio]['importe_teorico'] += $item['monto'];

                if (count($muestrasHistoricas) < 20) {
                    $muestrasHistoricas[] = [
                        'id_cliente' => $item['id_cliente'],
                        'id_servicio' => $id,
                        'periodo' => $periodo,
                        'monto' => number_format($item['monto'], 2, '.', ''),
                        'fecha_vencimiento' => $vencimiento->format('Y-m-d'),
                        'estado' => 'SIN_OBLIGACION_UBB_NO_ES_DEUDA_CONFIRMADA'
                    ];
                }
            }

            $cursor = $cursor->modify('first day of next month');
        }

        if ($tienePeriodos) {
            // Contar una sola vez cada servicio con periodos historicos.
            $historico['servicios_con_periodos_sin_obligacion_ubb']++;

            if ($item['pagos_reales_pagados'] > 0) {
                $historico['servicios_con_pagos_confirmados_para_revision']++;
            }
        }
    }

    $actual['importe_propuesto'] = number_format(
        $actual['importe_propuesto'], 2, '.', ''
    );

    $historico['importe_referencial_no_validado'] = number_format(
        $historico['importe_referencial_no_validado'], 2, '.', ''
    );

    foreach ($historicoPorAnio as &$fila) {
        $fila['importe_teorico'] = number_format(
            $fila['importe_teorico'], 2, '.', ''
        );
    }
    unset($fila);

    // ---------------------------------------------------------
    // GENERACION REAL: SOLO CON --generar
    // ---------------------------------------------------------

    $resultadoGeneracion = null;

    if ($generar) {
        $insertadas = 0;
        $omitidas = 0;

        $conexion->begin_transaction();

        try {
            $stmt = $conexion->prepare("
                INSERT INTO obligaciones_pago
                    (id_cliente, id_servicio, periodo, concepto, monto,
                     fecha_vencimiento, referencia_cobro, estado, observaciones)
                VALUES (?, ?, ?, 'MENSUALIDAD', ?, ?, ?, 'PENDIENTE', ?)
                ON DUPLICATE KEY UPDATE id_obligacion = id_obligacion
            ");

            foreach ($propuestasParaInsertar as $propuesta) {
                $observaciones = 'Generada por UBB para el ciclo ' . $propuesta['periodo'];

                $stmt->bind_param(
                    'iisdsss',
                    $propuesta['id_cliente'],
                    $propuesta['id_servicio'],
                    $propuesta['periodo'],
                    $propuesta['monto'],
                    $propuesta['fecha_vencimiento'],
                    $propuesta['referencia'],
                    $observaciones
                );

                $stmt->execute();

                if ($stmt->affected_rows === 1) {
                    $insertadas++;
                } else {
                    $omitidas++;
                }
            }

            $stmt->close();
            $conexion->commit();

            $resultadoGeneracion = [
                'insertadas' => $insertadas,
                'omitidas_por_duplicado' => $omitidas,
                'total_procesadas' => count($propuestasParaInsertar)
            ];
        } catch (Throwable $e) {
            $conexion->rollback();
            throw $e;
        }
    }

    // ---------------------------------------------------------
    // SALIDA
    // ---------------------------------------------------------

    echo "===== SIMULACION UBB: DOS REPORTES =====\n";
    echo $generar
        ? "Modo: GENERACION REAL DE OBLIGACIONES\\n"
        : "Modo: SOLO LECTURA; NO SE INSERTAN REGISTROS\\n";
    echo "Fecha de referencia: {$hoyTexto}\n";
    echo "Regla: primer vencimiento un mes despues de instalacion\n";
    echo "Los periodos historicos sin obligacion UBB NO son deudas confirmadas.\n";

    echo "\n===== REPORTE 1: CICLO ACTUAL =====\n";
    mostrar($actual);

    echo "\n===== EXCLUSIONES =====\n";
    foreach ($exclusiones as $motivo => $cantidad) {
        echo $motivo . ': ' . $cantidad . PHP_EOL;
    }

    echo "\n===== PAGOS SIN APLICAR PARA REVISION MANUAL =====\n";
    echo "Ventana: 10 dias antes y 10 dias despues del vencimiento actual.\n";
    echo "Estos pagos son candidatos; no se consideran confirmados para una mensualidad.\n";

    foreach ($muestrasPagosRevisionActuales as $fila) {
        mostrar($fila);
    }

    echo "\n===== PRIMERAS 20 PROPUESTAS ACTUALES =====\n";
    foreach ($muestrasActuales as $fila) {
        mostrar($fila);
    }

    if ($resultadoGeneracion !== null) {
        echo "\\n===== RESULTADO DE GENERACION =====\\n";
        mostrar($resultadoGeneracion);
    }

    echo "\\n===== REPORTE 2: HISTORIAL TEORICO =====\\n";
    mostrar($historico);

    echo "\n===== HISTORIAL POR ANIO =====\n";
    ksort($historicoPorAnio);
    foreach ($historicoPorAnio as $anio => $fila) {
        echo $anio . ' ';
        mostrar($fila);
    }

    echo "\n===== PRIMEROS 20 PERIODOS HISTORICOS =====\n";
    foreach ($muestrasHistoricas as $fila) {
        mostrar($fila);
    }

    echo "\n===== FIN DE SIMULACION =====\n";

    $plataforma->close();
    $planes->close();
    $conexion->close();

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);

    if (isset($plataforma) && $plataforma instanceof mysqli) {
        $plataforma->close();
    }
    if (isset($planes) && $planes instanceof mysqli) {
        $planes->close();
    }
    if (isset($conexion) && $conexion instanceof mysqli) {
        $conexion->close();
    }

    exit(1);
}
