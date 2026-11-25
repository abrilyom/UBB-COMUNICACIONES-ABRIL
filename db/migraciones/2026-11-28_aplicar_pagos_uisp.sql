-- Aplicación de los pagos de UISP a las mensualidades de UBB Pagos.
-- Agrega a uisp_pagos_importacion el resultado de aplicar cada pago
-- (APLICADO o REVISION), el pago creado en `pagos` y el motivo de revisión.
-- No borra ni cambia ninguna fila existente.
--
--   mariadb ubb_pagos_pruebas < 2026-11-28_aplicar_pagos_uisp.sql
--   mariadb ubb_pagos < 2026-11-28_aplicar_pagos_uisp.sql

ALTER TABLE uisp_pagos_importacion
    ADD COLUMN IF NOT EXISTS estado_aplicacion ENUM('APLICADO', 'REVISION') NULL AFTER origen,
    ADD COLUMN IF NOT EXISTS id_pago_ubb       INT NULL AFTER estado_aplicacion,
    ADD COLUMN IF NOT EXISTS id_cliente_ubb    INT NULL AFTER id_pago_ubb,
    ADD COLUMN IF NOT EXISTS id_servicio_ubb   INT NULL AFTER id_cliente_ubb,
    ADD COLUMN IF NOT EXISTS motivo_revision   VARCHAR(500) NULL AFTER id_servicio_ubb,
    ADD COLUMN IF NOT EXISTS aplicado_en       DATETIME NULL AFTER motivo_revision,
    ADD INDEX IF NOT EXISTS idx_uisp_estado_aplicacion (estado_aplicacion);
