-- Registro de ejecuciones de los procesos automáticos.
-- Permite mostrar en el panel cuándo corrió cada proceso y qué hizo.
-- Solo crea una tabla nueva en ubb_pagos; no modifica información existente.
--
--   mariadb ubb_pagos_pruebas < 2026-11-26_ejecuciones.sql
--   mariadb ubb_pagos < 2026-11-26_ejecuciones.sql

CREATE TABLE IF NOT EXISTS automatizacion_ejecuciones (
    id_ejecucion  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proceso       VARCHAR(40) NOT NULL,
    modo          ENUM('SIMULACION', 'CONFIRMADO') NOT NULL,
    resultado     ENUM('EXITOSA', 'ERROR') NOT NULL,
    resumen_json  TEXT NULL,
    mensaje       VARCHAR(500) NULL,
    fecha_inicio  DATETIME NOT NULL,
    fecha_fin     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ejecucion_proceso (proceso, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
