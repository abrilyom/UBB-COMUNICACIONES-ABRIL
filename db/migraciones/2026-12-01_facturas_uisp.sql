-- Facturas del CRM de UISP, copiadas en UBB Pagos (solo lectura sobre UISP).
-- Las llena scripts/sincronizar_facturas_uisp.php. Sirven para saber cuánto
-- debe realmente cada cliente que se factura en UISP (la factura puede
-- incluir más que el precio del plan) y qué periodo cubre cada pago.
-- Solo crea una tabla nueva; no modifica información existente.
--
--   mariadb ubb_pagos_pruebas < 2026-12-01_facturas_uisp.sql
--   mariadb ubb_pagos < 2026-12-01_facturas_uisp.sql

CREATE TABLE IF NOT EXISTS uisp_facturas (
    uisp_invoice_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    numero            VARCHAR(30) NULL,
    uisp_client_id    VARCHAR(50) NOT NULL,
    uisp_service_id   INT UNSIGNED NULL COMMENT 'Servicio del primer concepto de tipo service',
    fecha_emision     DATETIME NOT NULL,
    fecha_limite      DATETIME NULL,
    periodo_inicio    DATE NULL COMMENT 'Leído del concepto del servicio, p. ej. "1 Oct 2026 – 31 Oct 2026"',
    periodo_fin       DATE NULL,
    total             DECIMAL(12,2) NOT NULL,
    descuento         DECIMAL(12,2) NOT NULL DEFAULT 0,
    pagado            DECIMAL(12,2) NOT NULL DEFAULT 0,
    saldo             DECIMAL(12,2) NOT NULL DEFAULT 0,
    moneda            VARCHAR(10) NOT NULL,
    estado            ENUM('BORRADOR','PENDIENTE','PARCIAL','PAGADA','ANULADA','PROFORMA') NOT NULL,
    estado_uisp       TINYINT NOT NULL COMMENT 'Código de UISP: 0 borrador, 1 sin pagar, 2 parcial, 3 pagada, 4 anulada, 5 proforma procesada',
    proforma          TINYINT(1) NOT NULL DEFAULT 0,
    conceptos_json    TEXT NULL COMMENT 'Conceptos: tipo, descripción, servicio, precio, cantidad, total, descuento',
    pagos_json        TEXT NULL COMMENT 'Pagos de UISP que la cubren: pago, monto',
    sincronizado_en   DATETIME NOT NULL,
    INDEX idx_factura_cliente (uisp_client_id, fecha_emision),
    INDEX idx_factura_servicio (uisp_service_id, periodo_inicio),
    INDEX idx_factura_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
