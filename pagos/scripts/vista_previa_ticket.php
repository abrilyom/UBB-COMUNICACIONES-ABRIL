<?php
declare(strict_types=1);

/**
 * Vista previa del ticket de pago con datos de ejemplo.
 * No toca ninguna base de datos ni registra pagos.
 *
 * Uso:
 *   php pagos/scripts/vista_previa_ticket.php            (pago confirmado)
 *   php pagos/scripts/vista_previa_ticket.php pendiente  (transferencia con saldo restante)
 *
 * Deja el PDF en pagos/vista_previa_ticket_temporal.pdf (Git lo ignora).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acceso no permitido.\n");
}

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../includes/ticket_pago.php';

$pendiente = ($argv[1] ?? '') === 'pendiente';

$html = construirHtmlTicket([
    'folio' => 'PAG-000540',
    'estado_pago' => $pendiente ? 'PENDIENTE' : 'PAGADO',
    'id_cliente' => 1095,
    'id_servicio' => 1342,
    'nombre' => 'María Guadalupe Hernández López',
    'codigo_cliente' => 'CLI-1095',
    'codigo_servicio' => 'SRV-1342',
    'paquete' => 'Plan 20 Mbps',
    'tecnologia' => 'Fibra óptica',
    'periodo' => '202611',
    'concepto' => 'MENSUALIDAD',
    'saldo_restante_centavos' => $pendiente ? 8900 : 0,
    'monto' => $pendiente ? '200.00' : '289.00',
    'referencia' => $pendiente ? 'UBB1095134211' : 'FAC-000005',
    'metodo_pago' => $pendiente ? 'TRANSFERENCIA' : 'EFECTIVO',
    'fecha_pago' => '2026-11-26 12:00:00',
    'atendio' => 'Ana López · Operador',
    'ruta_logo' => __DIR__ . '/../../assets/img/globo-ubb-transparente.png',
]);

$destino = __DIR__ . '/../vista_previa_ticket_temporal.pdf';

if (file_put_contents($destino, generarPdfTicket($html)) === false) {
    fwrite(STDERR, "No se pudo escribir $destino\n");
    exit(1);
}

echo "Vista previa lista: pagos/vista_previa_ticket_temporal.pdf\n";
