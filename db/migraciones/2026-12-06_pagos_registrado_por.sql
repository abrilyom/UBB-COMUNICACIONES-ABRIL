-- Quién registró cada pago desde "Registrar pago".
-- Los datos vienen de la sesión de la plataforma (pase SSO de Jamileth):
--   id_usuario_registro: id_usuario de la plataforma.
--   registrado_por: nombre y rol tal como se veían al registrar ("Ana López · Operador").
-- Pagos importados (UISP, conciliación) y anteriores quedan en NULL.
--
-- Solo agrega columnas; no cambia ninguna fila. Mientras no se aplique,
-- registrar.php sigue funcionando y solo no guarda este dato.
--
--   mariadb ubb_pagos_pruebas < 2026-12-06_pagos_registrado_por.sql
--   mariadb ubb_pagos < 2026-12-06_pagos_registrado_por.sql

ALTER TABLE pagos
    ADD COLUMN IF NOT EXISTS id_usuario_registro INT UNSIGNED NULL
        COMMENT 'id_usuario de la plataforma que registró el pago',
    ADD COLUMN IF NOT EXISTS registrado_por VARCHAR(160) NULL
        COMMENT 'Nombre y rol de quien registró el pago';
