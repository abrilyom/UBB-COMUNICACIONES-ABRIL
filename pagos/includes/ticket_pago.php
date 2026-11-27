<?php

/*
 * Ticket (comprobante PDF) de pago, papel de 80 mm.
 * Lo usan registrar.php y scripts/vista_previa_ticket.php.
 */

/*
 * Teléfono o WhatsApp de atención que se imprime al pie del ticket.
 * Déjalo vacío ("") para que no aparezca.
 */
if (!defined("UBB_TELEFONO_ATENCION_TICKET")) {
    define("UBB_TELEFONO_ATENCION_TICKET", "238 211 4997");
}

/* Convierte un periodo "202611" en "Noviembre 2026". */
function textoPeriodoTicket(string $periodo): string
{
    $meses = [
        1 => "Enero", 2 => "Febrero", 3 => "Marzo", 4 => "Abril",
        5 => "Mayo", 6 => "Junio", 7 => "Julio", 8 => "Agosto",
        9 => "Septiembre", 10 => "Octubre", 11 => "Noviembre", 12 => "Diciembre",
    ];

    $limpio = preg_replace('/\D/', '', $periodo);

    if (strlen($limpio) >= 6) {
        $anio = (int)substr($limpio, 0, 4);
        $mes = (int)substr($limpio, 4, 2);

        if (isset($meses[$mes]) && $anio > 2000) {
            return $meses[$mes] . " " . $anio;
        }
    }

    return trim($periodo);
}

/*
 * Arma el HTML del ticket. Claves de $t:
 *   folio, estado_pago (PAGADO|PENDIENTE), id_cliente, id_servicio (int|null),
 *   nombre, codigo_cliente, codigo_servicio, paquete, tecnologia,
 *   periodo, concepto, saldo_restante_centavos, monto (texto "289.00"),
 *   referencia, metodo_pago, fecha_pago ("Y-m-d H:i:s"), ruta_logo,
 *   atendio (opcional, p. ej. "Ana López · Operador")
 */
function construirHtmlTicket(array $t): string
{
    $h = static function ($texto): string {
        return htmlspecialchars((string)$texto, ENT_QUOTES, "UTF-8");
    };

    $idCliente = (int)$t["id_cliente"];
    $idServicio = $t["id_servicio"] ?? null;
    $nombre = trim((string)($t["nombre"] ?? ""));
    $codigoCliente = trim((string)($t["codigo_cliente"] ?? ""));
    $codigoServicio = trim((string)($t["codigo_servicio"] ?? ""));
    $paquete = trim((string)($t["paquete"] ?? ""));
    $tecnologia = trim((string)($t["tecnologia"] ?? ""));
    $periodo = trim((string)($t["periodo"] ?? ""));
    $concepto = trim((string)($t["concepto"] ?? ""));
    $referencia = trim((string)($t["referencia"] ?? ""));
    $metodoPago = (string)($t["metodo_pago"] ?? "");
    $esPendiente = (($t["estado_pago"] ?? "") === "PENDIENTE");
    $saldoRestanteCentavos = max(0, (int)($t["saldo_restante_centavos"] ?? 0));

    $folio = $h($t["folio"] ?? "");

    /* Quién registró el pago (nombre y rol de la sesión); si no viene, no se imprime. */
    $atendio = trim((string)($t["atendio"] ?? ""));
    $filaAtendio = $atendio !== ""
        ? '<tr><td>Atendió</td><td class="der">' . $h($atendio) . '</td></tr>'
        : "";

    /* Cliente: nombre completo y número; si no hay nombre, solo el número. */
    $clienteNombre = $nombre !== "" ? $h($nombre) : "Cliente #" . $idCliente;
    $clienteNumero = "No. " . $idCliente
        . ($codigoCliente !== "" ? " · " . $h($codigoCliente) : "");

    /* Servicio: número, código y paquete contratado. */
    if ($idServicio === null) {
        $servicio = "No especificado";
        $filaPaquete = "";
    } else {
        $servicio = "Servicio #" . (int)$idServicio
            . ($codigoServicio !== "" ? " · " . $h($codigoServicio) : "");

        $textoPaquete = trim(
            $paquete
            . ($paquete !== "" && $tecnologia !== "" ? " · " : "")
            . $tecnologia
        );

        $filaPaquete = $textoPaquete !== ""
            ? '<div class="dato-sec">' . $h($textoPaquete) . '</div>'
            : "";
    }

    /* Concepto: mensualidad y periodo pagado, si se eligió uno. */
    if ($periodo !== "") {
        $conceptoBase = ($concepto !== "" && strtoupper($concepto) !== "MENSUALIDAD")
            ? mb_convert_case(mb_strtolower($concepto, "UTF-8"), MB_CASE_TITLE, "UTF-8")
            : "Mensualidad";
        $textoConcepto = $h($conceptoBase . " " . textoPeriodoTicket($periodo));
    } else {
        $textoConcepto = "Pago de servicio de internet";
    }

    $monto = number_format((float)($t["monto"] ?? 0), 2);

    $filaSaldo = $saldoRestanteCentavos > 0
        ? '<tr><td>Saldo pendiente del periodo</td><td class="der"><strong>$'
            . number_format($saldoRestanteCentavos / 100, 2) . '</strong></td></tr>'
        : "";

    $nombresMetodos = [
        "EFECTIVO" => "Efectivo",
        "TRANSFERENCIA" => "Transferencia",
        "DEPOSITO" => "Depósito",
        "TARJETA" => "Tarjeta / terminal",
    ];

    $metodo = $h($nombresMetodos[$metodoPago] ?? $metodoPago);
    $textoReferencia = $h($referencia !== "" ? $referencia : "Sin referencia");

    $zona = new DateTimeZone("America/Mexico_City");
    $fechaPago = (new DateTimeImmutable((string)$t["fecha_pago"], $zona))->format("d/m/Y");
    $emitido = (new DateTimeImmutable("now", $zona))->format("d/m/Y H:i");

    $estado = $esPendiente ? "PENDIENTE DE CONFIRMACIÓN" : "PAGO CONFIRMADO";
    $etiquetaMonto = $esPendiente ? "IMPORTE REGISTRADO" : "IMPORTE RECIBIDO";

    $notaEstado = $esPendiente
        ? '<div class="nota">Este pago se confirmará cuando el banco reporte la recepción del dinero.</div>'
        : "";

    $contacto = UBB_TELEFONO_ATENCION_TICKET !== ""
        ? '<div class="contacto">Atención y reportes: <strong>' . $h(UBB_TELEFONO_ATENCION_TICKET) . '</strong></div>'
        : "";

    $rutaLogo = (string)($t["ruta_logo"] ?? "");

    if (!is_file($rutaLogo)) {
        throw new RuntimeException("No se encontró el logo del comprobante.");
    }

    $logo = "data:image/png;base64," . base64_encode(file_get_contents($rutaLogo));

    return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
    @page { margin: 4mm; }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: DejaVu Sans, sans-serif;
        color: #20202a;
        font-size: 8.5px;
        line-height: 1.35;
    }

    table { width: 100%; border-collapse: collapse; }
    td { padding: 0; border: 0; vertical-align: top; }

    .encabezado { padding-bottom: 6px; border-bottom: 1.5px solid #20202a; }
    .celda-logo { width: 46px; padding-right: 6px; vertical-align: middle; }
    .logo-ticket { display: block; width: 42px; height: auto; }
    .celda-marca { vertical-align: middle; }
    .marca { font-size: 11.5px; font-weight: bold; }
    .lema { font-size: 7px; color: #666666; letter-spacing: 0.6px; }

    .titulo {
        text-align: center;
        font-size: 9.5px;
        font-weight: bold;
        letter-spacing: 0.8px;
        margin: 7px 0 5px;
    }

    .folio td { padding: 1.5px 0; }
    .folio td:first-child { color: #555555; }
    .der { text-align: right; }

    .seccion {
        font-size: 7.5px;
        font-weight: bold;
        color: #555555;
        letter-spacing: 0.6px;
        margin: 8px 0 3px;
        padding-bottom: 2px;
        border-bottom: 1px dashed #999999;
    }

    .dato-principal { font-size: 9.5px; font-weight: bold; }
    .dato-sec { color: #555555; margin-top: 1px; }

    .concepto td { padding: 2px 0; }

    .resumen {
        text-align: center;
        margin: 9px 0 6px;
        padding: 7px 3px 8px;
        border: 1.5px solid #20202a;
    }

    .etiqueta-monto { font-size: 7px; font-weight: bold; color: #555555; letter-spacing: 0.6px; }
    .monto { font-size: 18px; font-weight: bold; margin: 2px 0 4px; }

    .estado {
        display: inline-block;
        padding: 2px 7px;
        font-size: 7.5px;
        font-weight: bold;
        color: #ffffff;
        background: #20202a;
    }

    .estado-pendiente { color: #20202a; background: #ffffff; border: 1px solid #20202a; }

    .nota { font-size: 7px; color: #555555; margin-top: 5px; }

    .detalle td { padding: 1.5px 0; }
    .detalle td:first-child { color: #555555; }

    .pie {
        margin-top: 9px;
        padding-top: 6px;
        border-top: 1px dashed #999999;
        text-align: center;
        color: #444444;
        font-size: 7.5px;
        line-height: 1.5;
    }

    .pie strong { font-size: 8.5px; color: #20202a; }
    .contacto { margin-top: 3px; }
    .emitido { margin-top: 4px; font-size: 6.5px; color: #888888; }
    </style>
</head>
<body>
    <div class="encabezado">
        <table>
            <tr>
                <td class="celda-logo">
                    <img class="logo-ticket" src="' . $logo . '" alt="Logo UBB">
                </td>
                <td class="celda-marca">
                    <div class="marca">UBB COMUNICACIONES</div>
                    <div class="lema">UNIENDO TU MUNDO</div>
                    <div class="lema">Internet · Telefonía · Conectividad</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="titulo">COMPROBANTE DE PAGO</div>

    <table class="folio">
        <tr>
            <td>Folio</td>
            <td class="der"><strong>' . $folio . '</strong></td>
        </tr>
        <tr>
            <td>Fecha de pago</td>
            <td class="der">' . $fechaPago . '</td>
        </tr>
        ' . $filaAtendio . '
    </table>

    <div class="seccion">CLIENTE</div>
    <div class="dato-principal">' . $clienteNombre . '</div>
    <div class="dato-sec">' . $clienteNumero . '</div>

    <div class="seccion">SERVICIO</div>
    <div class="dato-principal">' . $servicio . '</div>
    ' . $filaPaquete . '

    <div class="seccion">CONCEPTO</div>
    <table class="concepto">
        <tr>
            <td>' . $textoConcepto . '</td>
            <td class="der"><strong>$' . $monto . '</strong></td>
        </tr>
        ' . $filaSaldo . '
    </table>

    <div class="resumen">
        <div class="etiqueta-monto">' . $etiquetaMonto . '</div>
        <div class="monto">$' . $monto . ' MXN</div>
        <span class="estado' . ($esPendiente ? " estado-pendiente" : "") . '">' . $estado . '</span>
        ' . $notaEstado . '
    </div>

    <table class="detalle">
        <tr>
            <td>Método de pago</td>
            <td class="der"><strong>' . $metodo . '</strong></td>
        </tr>
        <tr>
            <td>Referencia</td>
            <td class="der">' . $textoReferencia . '</td>
        </tr>
    </table>

    <div class="pie">
        <strong>¡Gracias por tu pago!</strong><br>
        UBB Comunicaciones · Tu mejor conexión, siempre.
        ' . $contacto . '
        <div class="emitido">Emitido el ' . $emitido . ' · Conserva este comprobante</div>
    </div>
</body>
</html>';
}

/*
 * Genera el PDF del ticket con el alto justo del contenido (80 mm de ancho).
 * Primero lo dibuja en una hoja muy larga para medir el cuerpo y luego
 * lo vuelve a dibujar con esa altura, así no queda espacio en blanco.
 */
function generarPdfTicket(string $html): string
{
    $ancho = 226.77; // 80 mm
    $margen = 11.34; // 4 mm, igual que @page
    $altoMedido = 0.0;

    $medidor = new \Dompdf\Dompdf();
    $medidor->setCallbacks([[
        "event" => "end_frame",
        "f" => static function (...$argumentos) use (&$altoMedido) {
            $frame = $argumentos[0] ?? null;

            if (is_array($frame)) {
                $frame = $frame["frame"] ?? null;
            }

            if (
                !is_object($frame)
                || !method_exists($frame, "get_node")
                || strtolower((string)$frame->get_node()->nodeName) !== "body"
            ) {
                return;
            }

            $posicion = $frame->get_position();
            $altoMedido = max(
                $altoMedido,
                (float)($posicion["y"] ?? 0) + (float)$frame->get_margin_height()
            );
        },
    ]]);
    $medidor->loadHtml($html, "UTF-8");
    $medidor->setPaper([0, 0, $ancho, 3000]);
    $medidor->render();

    // Si no se pudo medir, se usa la altura fija de antes.
    $alto = $altoMedido > 0 ? ceil($altoMedido + $margen + 6) : 700;
    $alto = max(250, min(3000, $alto));

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html, "UTF-8");
    $dompdf->setPaper([0, 0, $ancho, $alto]);
    $dompdf->render();

    return $dompdf->output();
}
