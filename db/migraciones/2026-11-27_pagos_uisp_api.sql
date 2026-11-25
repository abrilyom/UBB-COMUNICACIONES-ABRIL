-- Sincronización automática de pagos desde la API de UISP.
-- Agrega a uisp_pagos_importacion el ID de pago de UISP (para no duplicar
-- nunca un pago) y los datos que el CSV no traía. No borra ni cambia
-- ninguna fila existente: las filas del CSV quedan con origen = 'CSV'.
--
--   mariadb ubb_pagos_pruebas < 2026-11-27_pagos_uisp_api.sql
--   mariadb ubb_pagos < 2026-11-27_pagos_uisp_api.sql

ALTER TABLE uisp_pagos_importacion
    ADD COLUMN IF NOT EXISTS uisp_payment_id    INT UNSIGNED NULL AFTER id_importacion,
    ADD COLUMN IF NOT EXISTS origen             ENUM('CSV', 'API') NOT NULL DEFAULT 'CSV' AFTER revisado,
    ADD COLUMN IF NOT EXISTS uisp_metodo_id     VARCHAR(36) NULL AFTER metodo_normalizado,
    ADD COLUMN IF NOT EXISTS credito_uisp       DECIMAL(12,2) NULL AFTER moneda,
    ADD COLUMN IF NOT EXISTS facturas_uisp_json TEXT NULL AFTER nota,
    ADD COLUMN IF NOT EXISTS sincronizado_en    DATETIME NULL AFTER importado_en,
    ADD UNIQUE INDEX IF NOT EXISTS uq_uisp_payment_id (uisp_payment_id),
    ADD INDEX IF NOT EXISTS idx_uisp_cliente_fecha (uisp_client_id, fecha_original_uisp);
