-- Agrega el estado HISTORICO a la aplicación de pagos de UISP: pagos de un
-- periodo anterior a las mensualidades que existen en UBB. Se guardan pero
-- no se aplican ni se mandan a revisión. No cambia ninguna fila existente.
--
--   mariadb ubb_pagos_pruebas < 2026-11-29_pagos_uisp_historico.sql
--   mariadb ubb_pagos < 2026-11-29_pagos_uisp_historico.sql

ALTER TABLE uisp_pagos_importacion
    MODIFY COLUMN estado_aplicacion ENUM('APLICADO', 'REVISION', 'HISTORICO') NULL;
