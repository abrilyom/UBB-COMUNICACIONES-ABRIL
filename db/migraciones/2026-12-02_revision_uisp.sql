-- Decisiones del módulo "Revisión UISP" (revision_uisp.php).
-- El personal marca qué hacer con un pago en revisión y aplicar_pagos_uisp.php
-- lo ejecuta en su siguiente corrida:
--   SALDO_FAVOR  cubre lo que debe y el sobrante se abona a la siguiente mensualidad
--   HISTORICO    no se aplica (p. ej. pago de un mes que no existe en UBB)
-- Si el pago vuelve a quedar en revisión por otro motivo, la decisión se borra.
-- No cambia ninguna fila existente.
--
--   mariadb ubb_pagos_pruebas < 2026-12-02_revision_uisp.sql
--   mariadb ubb_pagos < 2026-12-02_revision_uisp.sql

ALTER TABLE uisp_pagos_importacion
    ADD COLUMN IF NOT EXISTS decision_revision ENUM('SALDO_FAVOR', 'HISTORICO') NULL AFTER motivo_revision,
    ADD COLUMN IF NOT EXISTS decidido_en DATETIME NULL AFTER decision_revision;
