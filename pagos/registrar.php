<?php
require_once __DIR__ . "/includes/sesion.php";

require_once "conexion.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/includes/ticket_pago.php";

/* Búsqueda de solo lectura de clientes y servicios remotos. */
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["buscar_clientes"])) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    try {
        $termino = trim((string)($_GET["q"] ?? ""));

        if (strlen($termino) < 1) {
            echo json_encode(["ok" => true, "resultados" => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $remota = require __DIR__ . "/conexion_plataforma.php";

        $sqlBusqueda = "
            SELECT
                c.id_cliente,
                c.nombre,
                c.apellido_paterno,
                c.apellido_materno,
                c.telefono,
                s.id_servicio,
                s.codigo_servicio,
                s.estado AS estado_servicio
            FROM clientes c
            LEFT JOIN servicios s ON s.id_cliente = c.id_cliente
            WHERE CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) LIKE ?
               OR c.telefono LIKE ?
               OR CAST(c.id_cliente AS CHAR) LIKE ?
               OR CAST(s.id_servicio AS CHAR) LIKE ?
               OR s.codigo_servicio LIKE ?
            ORDER BY
                CASE
                    WHEN CAST(c.id_cliente AS CHAR) = ? THEN 0
                    ELSE 1
                END,
                c.nombre,
                c.apellido_paterno,
                s.id_servicio
            LIMIT 30
        ";

        $buscar = "%" . $termino . "%";
        $stmtBusqueda = $remota->prepare($sqlBusqueda);

        if (!$stmtBusqueda) {
            throw new RuntimeException("No se pudo preparar la búsqueda.");
        }

        $stmtBusqueda->bind_param(
            "ssssss", $buscar, $buscar, $buscar, $buscar, $buscar, $termino
        );

        if (!$stmtBusqueda->execute()) {
            throw new RuntimeException("No se pudo ejecutar la búsqueda.");
        }

        $resultadoBusqueda = $stmtBusqueda->get_result();
        $resultados = [];

        while ($fila = $resultadoBusqueda->fetch_assoc()) {
            $resultados[] = $fila;
        }

        $stmtBusqueda->close();
        $remota->close();

        echo json_encode(
            ["ok" => true, "resultados" => $resultados],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        error_log("Búsqueda remota UBB: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "mensaje" => "No fue posible consultar los clientes."
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/* Consulta de obligaciones pendientes de un servicio UBB. */
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["obtener_obligaciones"])) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    $idCliente = filter_var($_GET["id_cliente"] ?? null, FILTER_VALIDATE_INT);
    $idServicio = filter_var($_GET["id_servicio"] ?? null, FILTER_VALIDATE_INT);

    if (!$idCliente || !$idServicio || $idCliente <= 0 || $idServicio <= 0) {
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Cliente o servicio no válido."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = null;

    try {
        $sql = "
            SELECT
                o.id_obligacion,
                o.periodo,
                o.concepto,
                o.monto,
                o.fecha_vencimiento,
                o.referencia_cobro,
                COALESCE(SUM(
                    CASE WHEN p.id_pago IS NOT NULL
                    THEN a.monto_aplicado ELSE 0 END
                ), 0) AS aplicado,
                o.monto - COALESCE(SUM(
                    CASE WHEN p.id_pago IS NOT NULL
                    THEN a.monto_aplicado ELSE 0 END
                ), 0) AS saldo
            FROM obligaciones_pago o
            LEFT JOIN pagos_obligaciones a
                ON a.id_obligacion = o.id_obligacion
            LEFT JOIN pagos p
                ON p.id_pago = a.id_pago
               AND p.estado_pago = 'PAGADO'
            WHERE o.id_cliente = ?
              AND o.id_servicio = ?
              AND o.estado = 'PENDIENTE'
            GROUP BY
                o.id_obligacion, o.periodo, o.concepto, o.monto,
                o.fecha_vencimiento, o.referencia_cobro
            HAVING saldo > 0
            ORDER BY o.fecha_vencimiento, o.id_obligacion
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException("No se pudo preparar la consulta de obligaciones.");
        }

        $stmt->bind_param("ii", $idCliente, $idServicio);

        if (!$stmt->execute()) {
            throw new RuntimeException("Falló la consulta de obligaciones.");
        }

        $resultado = $stmt->get_result();

        if (!$resultado) {
            throw new RuntimeException("No se pudo obtener el resultado de obligaciones.");
        }

        $obligaciones = [];

        while ($fila = $resultado->fetch_assoc()) {
            $obligaciones[] = [
                "id_obligacion" => (int)$fila["id_obligacion"],
                "periodo" => $fila["periodo"],
                "concepto" => $fila["concepto"],
                "monto" => (float)$fila["monto"],
                "fecha_vencimiento" => $fila["fecha_vencimiento"],
                "referencia_cobro" => $fila["referencia_cobro"],
                "aplicado" => (float)$fila["aplicado"],
                "saldo" => (float)$fila["saldo"]
            ];
        }

        $stmt->close();
        $stmt = null;

        echo json_encode([
            "ok" => true,
            "obligaciones" => $obligaciones
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        error_log("Consulta de obligaciones: " . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "mensaje" => "No fue posible consultar las mensualidades. Intenta nuevamente."
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

/* Consulta de solo lectura de la referencia permanente UBB. */
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["obtener_referencia_ubb"])) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    $idCliente = filter_var($_GET["id_cliente"] ?? null, FILTER_VALIDATE_INT);
    $idServicio = filter_var($_GET["id_servicio"] ?? null, FILTER_VALIDATE_INT);

    if (!$idCliente || !$idServicio || $idCliente <= 0 || $idServicio <= 0) {
        http_response_code(400);
        echo json_encode([
            "ok" => false,
            "mensaje" => "Cliente o servicio no válido."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = null;

    try {
        $stmt = $conexion->prepare(
            "SELECT referencia_base
             FROM referencias_servicio
             WHERE id_cliente = ? AND id_servicio = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException("No se pudo preparar la consulta de referencia.");
        }

        $stmt->bind_param("ii", $idCliente, $idServicio);

        if (!$stmt->execute()) {
            throw new RuntimeException("Falló la consulta de referencia.");
        }

        $stmt->bind_result($referenciaUbb);

        if ($stmt->fetch()) {
            $respuesta = [
                "ok" => true,
                "referencia_base" => $referenciaUbb
            ];
            http_response_code(200);
        } else {
            $respuesta = [
                "ok" => false,
                "mensaje" => "El servicio no tiene referencia UBB registrada."
            ];
            http_response_code(404);
        }

        $stmt->close();
        $stmt = null;

        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        error_log("Consulta de referencia UBB: " . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "mensaje" => "No fue posible consultar la referencia UBB."
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

function validarClienteServicioRemoto(int $idCliente, ?int $idServicio): bool
{
    $remota = null;
    $stmt = null;

    try {
        $remota = require __DIR__ . "/conexion_plataforma.php";

        if (!$remota instanceof mysqli) {
            throw new RuntimeException("La conexión remota no devolvió una conexión mysqli.");
        }

        if ($idServicio === null) {
            $sql = "
                SELECT id_cliente
                FROM clientes
                WHERE id_cliente = ?
                LIMIT 1
            ";

            $stmt = $remota->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException("No se pudo preparar la validación del cliente.");
            }

            $stmt->bind_param("i", $idCliente);

        } else {
            $sql = "
                SELECT id_servicio
                FROM servicios
                WHERE id_servicio = ?
                  AND id_cliente = ?
                LIMIT 1
            ";

            $stmt = $remota->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException("No se pudo preparar la validación del servicio.");
            }

            $stmt->bind_param("ii", $idServicio, $idCliente);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException("Falló la validación remota del cliente o servicio.");
        }

        $stmt->store_result();
        return $stmt->num_rows === 1;

    } catch (Throwable $e) {
        error_log("Validación remota UBB: " . $e->getMessage());
        return false;

    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        if ($remota instanceof mysqli) {
            $remota->close();
        }
    }
}

/* Comprueba que la referencia UBB corresponda al cliente y servicio. */
function validarReferenciaUbb(int $idCliente, int $idServicio): bool
{
    global $conexion;

    $stmt = null;

    try {
        $stmt = $conexion->prepare(
            "SELECT id_referencia
             FROM referencias_servicio
             WHERE id_cliente = ? AND id_servicio = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException("No se pudo preparar la validación de referencia UBB.");
        }

        $stmt->bind_param("ii", $idCliente, $idServicio);

        if (!$stmt->execute()) {
            throw new RuntimeException("Falló la validación de referencia UBB.");
        }

        $stmt->store_result();
        return $stmt->num_rows === 1;

    } catch (Throwable $e) {
        error_log("Validación de referencia UBB: " . $e->getMessage());
        return false;

    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/*
 * Datos legibles del cliente y servicio para el ticket (nombre, códigos, paquete).
 * Si la base remota no responde, devuelve lo que tenga y el ticket usa los números.
 */
function obtenerDatosTicket(int $idCliente, ?int $idServicio): array
{
    $datos = [
        "nombre" => "",
        "codigo_cliente" => "",
        "codigo_servicio" => "",
        "paquete" => "",
        "tecnologia" => "",
    ];

    $remota = null;

    try {
        $remota = require __DIR__ . "/conexion_plataforma.php";

        if (!$remota instanceof mysqli) {
            throw new RuntimeException("La conexión remota no devolvió una conexión mysqli.");
        }

        $stmt = $remota->prepare(
            "SELECT nombre, apellido_paterno, apellido_materno, codigo_cliente
             FROM clientes
             WHERE id_cliente = ?
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param("i", $idCliente);

            if ($stmt->execute()) {
                $fila = $stmt->get_result()->fetch_assoc();

                if ($fila) {
                    $datos["nombre"] = trim(implode(" ", array_filter([
                        trim((string)$fila["nombre"]),
                        trim((string)$fila["apellido_paterno"]),
                        trim((string)$fila["apellido_materno"]),
                    ], "strlen")));
                    $datos["codigo_cliente"] = trim((string)$fila["codigo_cliente"]);
                }
            }

            $stmt->close();
        }

        if ($idServicio !== null) {
            $stmt = $remota->prepare(
                "SELECT codigo_servicio, paquete_referencia, tecnologia
                 FROM servicios
                 WHERE id_servicio = ? AND id_cliente = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param("ii", $idServicio, $idCliente);

                if ($stmt->execute()) {
                    $fila = $stmt->get_result()->fetch_assoc();

                    if ($fila) {
                        $datos["codigo_servicio"] = trim((string)$fila["codigo_servicio"]);
                        $datos["paquete"] = trim((string)$fila["paquete_referencia"]);
                        $datos["tecnologia"] = trim((string)$fila["tecnologia"]);
                    }
                }

                $stmt->close();
            }
        }

    } catch (Throwable $e) {
        error_log("Datos del ticket UBB: " . $e->getMessage());

    } finally {
        if ($remota instanceof mysqli) {
            $remota->close();
        }
    }

    return $datos;
}


$mensaje = "";
$error = "";
$enlace_comprobante = "";
$id_pago = 0;
$folio_pago = "";
$monto_registrado = 0;
$fecha_registrada = "";
$metodo_registrado = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id_cliente = $_POST["id_cliente"] ?? "";
    $id_servicio = $_POST["id_servicio"] ?? "";
    $monto = $_POST["monto"] ?? "";
    $fecha = $_POST["fecha"] ?? "";
    $referencia = trim($_POST["referencia"] ?? "");
    $observaciones = trim($_POST["observaciones"] ?? "");
    $metodo_pago = strtoupper(trim($_POST["metodo_pago"] ?? "EFECTIVO"));
    $metodos_permitidos = ["EFECTIVO", "TRANSFERENCIA", "DEPOSITO", "TARJETA"];

    $montoTexto = is_string($monto) ? trim($monto) : "";
    $fechaTexto = is_string($fecha) ? trim($fecha) : "";

    $fechaObjeto = DateTimeImmutable::createFromFormat("!Y-m-d", $fechaTexto);
    $erroresFecha = DateTimeImmutable::getLastErrors();

    $fechaValida = (
        $fechaObjeto !== false
        && $fechaObjeto->format("Y-m-d") === $fechaTexto
        && ($erroresFecha === false
            || ($erroresFecha["warning_count"] === 0
                && $erroresFecha["error_count"] === 0))
    );

    if (!in_array($metodo_pago, $metodos_permitidos, true)) {
        $error = "Selecciona un método de pago válido.";

    } elseif ($id_cliente === "" || $montoTexto === "" || $fechaTexto === "") {
        $error = "Completa los campos obligatorios.";

    } elseif (
        !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $montoTexto)
        || !preg_match('/[1-9]/', $montoTexto)
    ) {
        $error = "Ingresa un monto positivo con máximo dos decimales.";

    } elseif (!$fechaValida) {
        $error = "Ingresa una fecha válida.";

    } elseif (!ctype_digit((string)$id_cliente) || (int)$id_cliente <= 0) {
        $error = "El identificador del cliente no es válido.";

    } elseif (
        $id_servicio !== ""
        && (!ctype_digit((string)$id_servicio) || (int)$id_servicio <= 0)
    ) {
        $error = "El identificador del servicio no es válido.";

    } elseif (!validarClienteServicioRemoto(
        (int)$id_cliente,
        $id_servicio === "" ? null : (int)$id_servicio
    )) {
        $error = "El cliente o servicio no existe, no corresponde entre sí o no se pudo validar la base remota.";

    } elseif (
        $id_servicio !== ""
        && !validarReferenciaUbb((int)$id_cliente, (int)$id_servicio)
    ) {
        $error = "El servicio no tiene una referencia UBB registrada para este cliente. No se guardó el pago.";

    } else {

        $id_cliente = (int)$id_cliente;
        $id_servicio = ($id_servicio === "") ? null : (int)$id_servicio;
        $id_obligacion = trim((string)($_POST["id_obligacion"] ?? ""));

        $partesMonto = explode(".", $montoTexto, 2);
        $parteEntera = (int)$partesMonto[0];
        $parteDecimal = isset($partesMonto[1])
            ? (int)str_pad($partesMonto[1], 2, "0", STR_PAD_RIGHT)
            : 0;

        $monto_centavos = ($parteEntera * 100) + $parteDecimal;

        $monto = sprintf(
            "%d.%02d",
            intdiv($monto_centavos, 100),
            $monto_centavos % 100
        );
        $fecha_pago = $fecha . " 12:00:00";

        $registro_ok = false;
        $id_pago = 0;
        $transaccionIniciada = false;

        try {
            if (!$conexion->begin_transaction()) {
                throw new RuntimeException("No fue posible iniciar el registro del pago.");
            }

            $transaccionIniciada = true;
            $saldo_centavos = 0;
            $periodo_obligacion = "";
            $concepto_obligacion = "";

            /*
             * Factura de la plataforma (servicios con factura_en_plataforma = 1).
             * La plataforma de facturación liga el pago a su factura por pagos.id_factura.
             */
            $id_factura = null;

            /*
             * Validar y bloquear la mensualidad seleccionada.
             */
            if ($id_obligacion !== "") {

                if (
                    $id_servicio === null
                    || !ctype_digit($id_obligacion)
                    || (int)$id_obligacion <= 0
                ) {
                    throw new DomainException("La mensualidad seleccionada no es válida.");
                }

                $id_obligacion = (int)$id_obligacion;

                $sqlObligacion = "
                    SELECT
                        o.id_obligacion,
                        o.periodo,
                        o.concepto,
                        o.monto,
                        o.estado,
                        o.id_factura,
                        o.referencia_cobro,
                        COALESCE((
                            SELECT SUM(a.monto_aplicado)
                            FROM pagos_obligaciones a
                            INNER JOIN pagos p ON p.id_pago = a.id_pago
                            WHERE a.id_obligacion = o.id_obligacion
                              AND p.estado_pago = 'PAGADO'
                        ), 0) AS aplicado
                    FROM obligaciones_pago o
                    WHERE o.id_obligacion = ?
                      AND o.id_cliente = ?
                      AND o.id_servicio = ?
                    FOR UPDATE
                ";

                $stmtObligacion = $conexion->prepare($sqlObligacion);

                if (!$stmtObligacion) {
                    throw new RuntimeException("No se pudo validar la mensualidad.");
                }

                $stmtObligacion->bind_param(
                    "iii",
                    $id_obligacion,
                    $id_cliente,
                    $id_servicio
                );

                if (!$stmtObligacion->execute()) {
                    $stmtObligacion->close();
                    throw new RuntimeException("No se pudo consultar la mensualidad.");
                }

                $resultadoObligacion = $stmtObligacion->get_result();

                if (!$resultadoObligacion) {
                    $stmtObligacion->close();
                    throw new RuntimeException("No se pudo obtener el saldo de la mensualidad.");
                }

                $filaObligacion = $resultadoObligacion->fetch_assoc();
                $stmtObligacion->close();

                if (
                    !$filaObligacion
                    || $filaObligacion["estado"] !== "PENDIENTE"
                ) {
                    throw new DomainException("La mensualidad no existe o ya no está pendiente.");
                }

                // Para el ticket: qué periodo se está pagando.
                $periodo_obligacion = (string)($filaObligacion["periodo"] ?? "");
                $concepto_obligacion = (string)($filaObligacion["concepto"] ?? "");

                // Mensualidad que viene de una factura de la plataforma: el pago se liga a esa
                // factura y, si no se capturó referencia, se usa su folio (FAC-000001).
                if ($filaObligacion["id_factura"] !== null) {
                    $id_factura = (int)$filaObligacion["id_factura"];

                    if ($referencia === "") {
                        $referencia = (string)$filaObligacion["referencia_cobro"];
                    }
                }

                /*
                 * DECIMAL de MariaDB se convierte a centavos
                 * sin utilizar operaciones de punto flotante.
                 */
                $aCentavos = static function ($importe): int {
                    $partes = explode(".", (string)$importe, 2);
                    $entero = (int)$partes[0];
                    $decimal = isset($partes[1])
                        ? (int)str_pad(substr($partes[1], 0, 2), 2, "0")
                        : 0;

                    return ($entero * 100) + $decimal;
                };

                $total_centavos = $aCentavos($filaObligacion["monto"]);
                $aplicado_centavos = $aCentavos($filaObligacion["aplicado"]);
                $saldo_centavos = $total_centavos - $aplicado_centavos;

                if ($saldo_centavos <= 0) {
                    throw new DomainException("La mensualidad ya no tiene saldo pendiente.");
                }

                if ($monto_centavos > $saldo_centavos) {
                    throw new DomainException("El monto recibido supera el saldo pendiente de la mensualidad.");
                }

            } elseif ($id_servicio !== null) {

                /*
                 * No permitir pagos sin aplicación cuando existen
                 * mensualidades pendientes para el servicio.
                 */
                $sqlPendientes = "
                    SELECT o.id_obligacion
                    FROM obligaciones_pago o
                    WHERE o.id_cliente = ?
                      AND o.id_servicio = ?
                      AND o.estado = 'PENDIENTE'
                    LIMIT 1
                    FOR UPDATE
                ";

                $stmtPendientes = $conexion->prepare($sqlPendientes);

                if (!$stmtPendientes) {
                    throw new RuntimeException("No se pudieron validar las mensualidades pendientes.");
                }

                $stmtPendientes->bind_param("ii", $id_cliente, $id_servicio);

                if (!$stmtPendientes->execute()) {
                    $stmtPendientes->close();
                    throw new RuntimeException("No se pudieron consultar las mensualidades pendientes.");
                }

                $resultadoPendientes = $stmtPendientes->get_result();

                if (!$resultadoPendientes) {
                    $stmtPendientes->close();
                    throw new RuntimeException("No se pudo obtener el resultado de mensualidades.");
                }

                $hayPendientes = $resultadoPendientes->num_rows > 0;
                $stmtPendientes->close();

                if ($hayPendientes) {
                    throw new DomainException("Selecciona la mensualidad pendiente a la que deseas aplicar el pago.");
                }
            }

            /*
             * Transferencias y depósitos quedan pendientes de confirmación
             * bancaria. Efectivo y tarjeta se registran después de recibir
             * o confirmar el cobro.
             */
            $estadoPago = in_array(
                $metodo_pago,
                ["TRANSFERENCIA", "DEPOSITO"],
                true
            ) ? "PENDIENTE" : "PAGADO";

            $sqlPago = "
                INSERT INTO pagos
                    (
                        id_cliente,
                        id_servicio,
                        monto,
                        fecha_pago,
                        metodo_pago,
                        referencia,
                        observaciones,
                        estado_pago,
                        id_factura
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmtPago = $conexion->prepare($sqlPago);

            if (!$stmtPago) {
                throw new RuntimeException("No se pudo preparar el registro del pago.");
            }

            $stmtPago->bind_param(
                "iissssssi",
                $id_cliente,
                $id_servicio,
                $monto,
                $fecha_pago,
                $metodo_pago,
                $referencia,
                $observaciones,
                $estadoPago,
                $id_factura
            );

            if (!$stmtPago->execute()) {
                $stmtPago->close();
                throw new RuntimeException("No se pudo registrar el pago.");
            }

            $id_pago = (int)$stmtPago->insert_id;
            $stmtPago->close();

            if ($id_pago <= 0) {
                throw new RuntimeException("No se obtuvo el identificador del pago.");
            }

            /*
             * Quién registró el pago (sesión de la plataforma). Si todavía no se
             * aplicó la migración 2026-12-06_pagos_registrado_por.sql, o no hay
             * sesión, el pago se registra igual sin este dato.
             */
            $usuarioRegistro = usuarioSesion();

            if ($usuarioRegistro !== null) {
                try {
                    $columnasRegistro = $conexion->query(
                        "SHOW COLUMNS FROM pagos LIKE 'registrado_por'"
                    );

                    if ($columnasRegistro && $columnasRegistro->num_rows > 0) {
                        $idUsuarioRegistro = (int)$usuarioRegistro["id_usuario"];
                        $textoRegistro = textoUsuarioSesion();

                        $stmtRegistro = $conexion->prepare(
                            "UPDATE pagos
                             SET id_usuario_registro = ?, registrado_por = ?
                             WHERE id_pago = ?"
                        );

                        if ($stmtRegistro) {
                            $stmtRegistro->bind_param("isi", $idUsuarioRegistro, $textoRegistro, $id_pago);
                            $stmtRegistro->execute();
                            $stmtRegistro->close();
                        }
                    }
                } catch (Throwable $errorRegistro) {
                    error_log("No se guardó quién registró el pago: " . $errorRegistro->getMessage());
                }
            }

            /*
             * Aplicar el pago a la mensualidad seleccionada.
             */
            if ($id_obligacion !== "") {

                $sqlAplicacion = "
                    INSERT INTO pagos_obligaciones
                        (id_pago, id_obligacion, monto_aplicado)
                    VALUES (?, ?, ?)
                ";

                $stmtAplicacion = $conexion->prepare($sqlAplicacion);

                if (!$stmtAplicacion) {
                    throw new RuntimeException("No se pudo preparar la aplicación del pago.");
                }

                $stmtAplicacion->bind_param(
                    "iis",
                    $id_pago,
                    $id_obligacion,
                    $monto
                );

                if (!$stmtAplicacion->execute()) {
                    $stmtAplicacion->close();
                    throw new RuntimeException("No se pudo aplicar el pago a la mensualidad.");
                }

                $stmtAplicacion->close();

                /*
                 * Solo los pagos confirmados pueden completar
                 * una obligación mensual.
                 */
                $estadoFinal = (
                    $estadoPago === "PAGADO"
                    && $monto_centavos === $saldo_centavos
                ) ? "PAGADA" : "PENDIENTE";

                $stmtEstado = $conexion->prepare(
                    "UPDATE obligaciones_pago
                     SET estado = ?
                     WHERE id_obligacion = ?
                       AND estado = 'PENDIENTE'"
                );

                if (!$stmtEstado) {
                    throw new RuntimeException("No se pudo preparar la actualización de la mensualidad.");
                }

                $stmtEstado->bind_param("si", $estadoFinal, $id_obligacion);

                if (!$stmtEstado->execute()) {
                    $stmtEstado->close();
                    throw new RuntimeException("No se pudo actualizar el estado de la mensualidad.");
                }

                $stmtEstado->close();
            }

            if (!$conexion->commit()) {
                throw new RuntimeException("No se pudo confirmar el registro del pago.");
            }

            $transaccionIniciada = false;
            $registro_ok = true;

        } catch (Throwable $e) {

            error_log(
                "Error en registro transaccional de pago: "
                . $e->getMessage()
            );

            if ($transaccionIniciada) {
                try {
                    $conexion->rollback();
                } catch (Throwable $errorRollback) {
                    error_log(
                        "Error al revertir transacción de pago: "
                        . $errorRollback->getMessage()
                    );
                }
            }

            $id_pago = 0;
            $registro_ok = false;

            if ($e instanceof DomainException) {
                $error = $e->getMessage();
            } else {
                $error = "No fue posible registrar el pago. Verifica los datos e intenta nuevamente.";
            }
        }

        if ($registro_ok) {

                $folio_pago = "PAG-" . str_pad(
                    (string)$id_pago,
                    6,
                    "0",
                    STR_PAD_LEFT
                );

                $monto_registrado = $monto;
                $fecha_registrada = $fecha;
                $metodo_registrado = $metodo_pago;

                $mensaje = "Pago registrado correctamente.";

                try {

                    $directorio = __DIR__ . "/comprobantes";

                    if (!is_dir($directorio)) {
                        mkdir($directorio, 0775, true);
                    }

                    if (!is_writable($directorio)) {
                        throw new RuntimeException(
                            "La carpeta de comprobantes no permite escritura."
                        );
                    }

                    $datosTicket = obtenerDatosTicket($id_cliente, $id_servicio);

                    $html = construirHtmlTicket([
                        "folio" => $folio_pago,
                        "estado_pago" => $estadoPago,
                        "id_cliente" => $id_cliente,
                        "id_servicio" => $id_servicio,
                        "nombre" => $datosTicket["nombre"],
                        "codigo_cliente" => $datosTicket["codigo_cliente"],
                        "codigo_servicio" => $datosTicket["codigo_servicio"],
                        "paquete" => $datosTicket["paquete"],
                        "tecnologia" => $datosTicket["tecnologia"],
                        "periodo" => $periodo_obligacion,
                        "concepto" => $concepto_obligacion,
                        // Lo que queda por pagar de la mensualidad después de este pago.
                        "saldo_restante_centavos" => $periodo_obligacion !== ""
                            ? $saldo_centavos - $monto_centavos
                            : 0,
                        "monto" => $monto,
                        "referencia" => $referencia,
                        "metodo_pago" => $metodo_pago,
                        "fecha_pago" => $fecha_pago,
                        "ruta_logo" => __DIR__ . "/../assets/img/globo-ubb-transparente.png",
                        "atendio" => textoUsuarioSesion(),
                    ]);

                    $pdf_ticket = generarPdfTicket($html);

                    $nombre_archivo = "comprobante_pago_"
                        . $id_pago . ".pdf";

                    $ruta_absoluta = $directorio
                        . "/" . $nombre_archivo;

                    $ruta_relativa = "comprobantes/"
                        . $nombre_archivo;

                    $resultado_pdf = file_put_contents(
                        $ruta_absoluta,
                        $pdf_ticket
                    );

                    if ($resultado_pdf === false) {
                        throw new RuntimeException(
                            "No se pudo guardar el archivo PDF."
                        );
                    }

                    $actualizar = $conexion->prepare(
                        "UPDATE pagos
                         SET comprobante = ?
                         WHERE id_pago = ?"
                    );

                    if (!$actualizar) {
                        throw new RuntimeException(
                            "No se pudo preparar la actualización del comprobante."
                        );
                    }

                    $actualizar->bind_param(
                        "si",
                        $ruta_relativa,
                        $id_pago
                    );

                    if (!$actualizar->execute()) {
                        $actualizar->close();

                        throw new RuntimeException(
                            "No se pudo guardar la ruta del comprobante."
                        );
                    }

                    $actualizar->close();

                    $enlace_comprobante = $ruta_relativa;

                } catch (\Throwable $e) {

                    error_log(
                        "Error generando comprobante del pago "
                        . $id_pago . ": " . $e->getMessage()
                    );

                    $error = "El pago se guardó, pero no se pudo "
                        . "generar o guardar su comprobante PDF.";
                }

        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Registrar pago - UBB Comunicaciones</title>

    <link rel="stylesheet" href="css/pagos.css?v=<?php echo filemtime(__DIR__ . '/css/pagos.css'); ?>">

</head>

<body>

    <?php require __DIR__ . "/includes/encabezado.php"; ?>


    <?php require __DIR__ . "/includes/panel_lateral.php"; ?>

<main class="contenedor registro-contenedor-tec">




        <?php if ($id_pago > 0): ?>

            <section class="ubb-resultado-pago" aria-live="polite">
                <div class="ubb-resultado-cabecera">
                    <span class="ubb-resultado-icono">✓</span>
                    <div>
                        <span class="ubb-resultado-etiqueta"><?php echo $estadoPago === "PENDIENTE" ? "PENDIENTE DE CONFIRMACIÓN" : "REGISTRO COMPLETADO"; ?></span>
                        <h2><?php echo $estadoPago === "PENDIENTE" ? "Registro pendiente de confirmación bancaria" : "¡Pago registrado correctamente!"; ?></h2>
                        <p><?php echo $estadoPago === "PENDIENTE" ? "El registro quedó guardado, pero todavía no se confirma la recepción del dinero." : "El pago quedó guardado en el sistema UBB."; ?></p>
                    </div>
                </div>

                <div class="ubb-resultado-folio">
                    <span>FOLIO DEL PAGO</span>
                    <strong><?php echo htmlspecialchars($folio_pago, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>

                <div class="ubb-resultado-datos">
                    <div>
                        <span><?php echo $estadoPago === "PENDIENTE" ? "Importe registrado" : "Importe recibido"; ?></span>
                        <strong>$<?php echo number_format((float)$monto_registrado, 2); ?></strong>
                    </div>
                    <div>
                        <span>Método de pago</span>
                        <strong><?php
                            $nombres_metodos = [
                                "EFECTIVO" => "Efectivo",
                                "TRANSFERENCIA" => "Transferencia",
                                "DEPOSITO" => "Depósito",
                                "TARJETA" => "Tarjeta / Terminal"
                            ];
                            echo htmlspecialchars(
                                $nombres_metodos[$metodo_registrado] ?? $metodo_registrado,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                        ?></strong>
                    </div>
                    <div>
                        <span>Fecha del pago</span>
                        <strong><?php echo htmlspecialchars(
                            date("d/m/Y", strtotime($fecha_registrada)),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?></strong>
                    </div>
                </div>

                <div class="ubb-resultado-comprobante">
                    <?php if ($enlace_comprobante !== ""): ?>
                        <span class="ubb-comprobante-icono">PDF</span>
                        <div class="ubb-comprobante-info">
                            <strong>Comprobante generado</strong>
                            <span>El documento está disponible para consultar.</span>
                        </div>
                        <a href="<?php echo htmlspecialchars($enlace_comprobante, ENT_QUOTES, 'UTF-8'); ?>"
                           target="_blank" rel="noopener noreferrer">
                            Abrir comprobante ↗
                        </a>
                    <?php else: ?>
                        <span class="ubb-comprobante-icono ubb-comprobante-pendiente">!</span>
                        <div class="ubb-comprobante-info">
                            <strong>Comprobante pendiente</strong>
                            <span>El pago sí quedó registrado, pero el PDF no pudo generarse.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ubb-resultado-acciones">
                    <a href="#formularioRegistro" class="ubb-resultado-nuevo">
                        Registrar otro pago <span>→</span>
                    </a>
                </div>
            </section>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="mensaje-error">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>


        <section class="tarjeta-formulario registro-ui" id="formularioRegistro">

    <div class="registro-ui__intro registro-ui__intro-redisenado">
        <div>
            <span class="registro-ui__eyebrow">UBB COMUNICACIONES · GESTIÓN DE COBROS</span>
            <h3>Registrar pago</h3>
            <p>Identifica al cliente, captura el pago y confirma el registro.</p>
        </div>
        <span class="registro-ui__estado"><span></span> Nuevo registro</span>
    </div>

    <form class="formulario registro-ui__form" method="POST">

        <div class="registro-ui__pasos">
            <div class="registro-ui__paso registro-ui__paso-activo">
                <span>1</span> Identificación
            </div>
            <i></i>
            <div class="registro-ui__paso">
                <span>2</span> Detalles del pago
            </div>
            <i></i>
            <div class="registro-ui__paso">
                <span>3</span> Confirmación
            </div>
        </div>

        <fieldset class="registro-ui__metodos">
            <legend>Selector de método de pago</legend>

            <label class="registro-ui__metodo-opcion">
                <input type="radio" name="metodo_pago" value="EFECTIVO" checked>
                <span>✓</span> Efectivo
            </label>

            <label class="registro-ui__metodo-opcion">
                <input type="radio" name="metodo_pago" value="TRANSFERENCIA">
                <span>⇄</span> Transferencia
            </label>

            <label class="registro-ui__metodo-opcion">
                <input type="radio" name="metodo_pago" value="DEPOSITO">
                <span>▣</span> Depósito
            </label>

            <label class="registro-ui__metodo-opcion">
                <input type="radio" name="metodo_pago" value="TARJETA">
                <span>▱</span> Tarjeta / Terminal
            </label>
        </fieldset>

        <div class="registro-ui__seccion">
            <div class="registro-ui__titulo-seccion">
                <span class="registro-ui__numero">01</span>
                <div>
                    <h4>Identificación del cliente</h4>
                    <p>Busca al cliente y selecciona el resultado para asociar su servicio al pago.</p>
                </div>
            </div>

            <div class="registro-ui__busqueda">
                <span aria-hidden="true">⌕</span>
                <input
                    type="search"
                    placeholder="Buscar por nombre, ID o teléfono"
                    id="buscarClienteRemoto" aria-label="Buscar cliente por nombre, ID o teléfono"
                >
                <span id="estadoBusquedaCliente" class="registro-ui__pendiente">Escribe un nombre o teléfono</span>
            </div>

            <div id="resultadosClienteRemoto" class="registro-ui__resultados" hidden></div>

            <div id="clienteVacio" class="registro-ui__cliente-vacio">
                <div class="registro-ui__avatar" aria-hidden="true">♙</div>
                <div>
                    <strong>Sin cliente seleccionado</strong>
                    <p>Busca un cliente y selecciona uno de los resultados.</p>
                </div>
            </div>

            <div id="clienteSeleccionado" class="registro-ui__cliente-seleccionado" hidden>
                <div class="registro-ui__seleccionado-cabecera">
                    <span class="registro-ui__seleccionado-titulo">
                        <span class="registro-ui__seleccionado-check">✓</span>
                        Cliente seleccionado
                    </span>
                    <button type="button" id="cambiarCliente" class="registro-ui__cambiar">
                        Cambiar
                    </button>
                </div>
                <div class="registro-ui__seleccionado-datos">
                    <div class="registro-ui__seleccionado-avatar" aria-hidden="true">♙</div>
                    <div class="registro-ui__seleccionado-persona">
                        <strong id="clienteSeleccionadoNombre"></strong>
                        <span id="clienteSeleccionadoId"></span>
                        <span id="clienteSeleccionadoTelefono"></span>
                    </div>
                    <div class="registro-ui__seleccionado-servicio">
                        <strong>Servicio asociado</strong>
                        <span id="clienteSeleccionadoServicio"></span>
                        <span id="clienteSeleccionadoEstado" class="registro-ui__estado-servicio"></span>
                    </div>
                </div>
            </div>

            <div class="registro-ui__grid registro-ui__ids-editables">
                <div class="campo">
                    <label for="id_cliente">ID del cliente <span class="registro-ui__requerido">Obligatorio</span></label>
                    <input
                        type="number"
                        id="id_cliente"
                        name="id_cliente"
                        placeholder="Se completa al seleccionar"
                        min="1"
                        readonly
                        required
                    >
                </div>

                <div class="campo">
                    <label for="id_servicio">ID del servicio <span class="registro-ui__opcional">Opcional</span></label>
                    <input
                        type="number"
                        id="id_servicio"
                        name="id_servicio"
                        placeholder="Se completa al seleccionar"
                        min="1"
                        readonly
                    >
                </div>

                <div class="campo">
                    <label for="referencia_ubb">Referencia UBB <span class="registro-ui__opcional">Automática</span></label>
                    <input
                        type="text"
                        id="referencia_ubb"
                        placeholder="Se completa al seleccionar servicio"
                        readonly
                    >
                </div>
            </div>


            <div class="campo" id="contenedorObligaciones" hidden style="margin: 18px 0;">
                <label for="id_obligacion">Mensualidad pendiente</label>
                <select id="id_obligacion" name="id_obligacion">
                    <option value="">Selecciona una mensualidad</option>
                </select>
                <small id="estadoObligaciones">Selecciona un servicio para consultar sus mensualidades.</small>
            </div>

        </div>

        <div class="registro-ui__seccion">
            <div class="registro-ui__titulo-seccion">
                <span class="registro-ui__numero">02</span>
                <div>
                    <h4>Detalles del pago</h4>
                    <p>Verifica el importe y la fecha antes de confirmar.</p>
                </div>
            </div>

            <div class="registro-ui__grid">
                <div class="campo">
                    <label for="monto">Monto recibido <span class="registro-ui__requerido">Obligatorio</span></label>
                    <div class="input-monto registro-ui__monto">
                        <span>$</span>
                        <input
                            type="text"
                            id="monto"
                            name="monto"
                            inputmode="decimal"
                            placeholder="0.00"
                            autocomplete="off"
                            required
                        >
                    </div>
                </div>

                <div class="campo">
                    <label for="fecha">Fecha del pago <span class="registro-ui__requerido">Obligatorio</span></label>
                    <input
                        type="date"
                        id="fecha"
                        name="fecha"
                        value="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>
            </div>

            <div class="campo registro-ui__campo-referencia">
                <label for="referencia">Folio o referencia bancaria <span class="registro-ui__opcional">Opcional</span></label>
                <input
                    type="text"
                    id="referencia"
                    name="referencia"
                    maxlength="120"
                    placeholder="Ej. Folio de transferencia o depósito"
                >
            </div>

            <div class="campo registro-ui__campo-observaciones">
                <label for="observaciones">Observaciones <span class="registro-ui__opcional">Opcional</span></label>
                <textarea
                    id="observaciones"
                    name="observaciones"
                    maxlength="2000"
                    rows="3"
                    placeholder="Información adicional sobre el pago..."
                ></textarea>
            </div>

            <div class="registro-ui__automatizaciones">
                <h4>Automatizaciones</h4>

                <div class="registro-ui__automatizacion">
                    <span class="registro-ui__auto-icono">⌁</span>
                    <div>
                        <strong>Reactivación de servicio</strong>
                        <p>Integración con MikroTik / RADIUS</p>
                    </div>
                    <span class="registro-ui__estado-pendiente">Pendiente</span>
                </div>

                <div class="registro-ui__automatizacion">
                    <span class="registro-ui__auto-icono">▤</span>
                    <div>
                        <strong>Generar comprobante PDF</strong>
                        <p>Se genera al registrar el pago</p>
                    </div>
                    <span class="registro-ui__estado-activo">Activo</span>
                </div>
            </div>
        </div>



        <div class="registro-ui__acciones">
            <a href="index.php" class="registro-ui__cancelar">Cancelar</a>
            <button type="submit" class="btn-registrar registro-ui__confirmar">
                Confirmar y registrar pago <span aria-hidden="true">→</span>
            </button>
        </div>

    </form>
</section>

    </main>

    <script src="buscar-clientes.js" defer></script>
    <script src="confirmacion-pago.js" defer></script>
</body>

</html>


