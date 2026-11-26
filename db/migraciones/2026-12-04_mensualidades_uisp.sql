-- Mensualidades a partir de las facturas de UISP (clientes que se facturan en UISP).
-- obligaciones_pago.uisp_invoice_id liga cada mensualidad con su factura de UISP
-- (tabla uisp_facturas). Lo llena scripts/mensualidades_desde_uisp.php.
-- Requiere antes 2026-12-01_facturas_uisp.sql.
-- Solo agrega una columna; no cambia ninguna fila.
--
--   mariadb ubb_pagos_pruebas < 2026-12-04_mensualidades_uisp.sql
--   mariadb ubb_pagos < 2026-12-04_mensualidades_uisp.sql

ALTER TABLE obligaciones_pago
    ADD COLUMN IF NOT EXISTS uisp_invoice_id INT UNSIGNED NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_obligacion_uisp_invoice (uisp_invoice_id);
