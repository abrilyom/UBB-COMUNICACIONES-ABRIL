-- No cobrar los meses en que el servicio estuvo cortado.
-- Regla del ingeniero: al reactivar se cobra el mes actual + $39 de reconexión;
-- el mes que provocó el corte y los meses sin servicio no se cobran.
--
-- servicios_cortes: periodos en que cada servicio estuvo cortado, según UISP
--   (estado del servicio en el CRM) y según Grezz (suspensiones COMPLETADAS
--   que pidió UBB Pagos). Lo llena scripts/meses_sin_servicio.php.
-- obligaciones_pago:
--   no_cobro_motivo / id_corte_no_cobro: por qué y por cuál corte una
--     mensualidad quedó CANCELADA (se puede deshacer con --deshacer=<id_corte>).
--   cargo_reconexion: parte del monto que es la reconexión (ya está incluida
--     en monto); las sincronizaciones de facturas la conservan.
-- Agrega el estado CANCELADA a obligaciones_pago.estado si todavía no existe.
--
-- Solo agrega una tabla, columnas y un valor de estado; no cambia ninguna fila.
--
--   mariadb ubb_pagos_pruebas < 2026-12-05_meses_sin_servicio.sql
--   mariadb ubb_pagos < 2026-12-05_meses_sin_servicio.sql

CREATE TABLE IF NOT EXISTS servicios_cortes (
    id_corte                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_servicio              INT UNSIGNED NOT NULL,
    id_cliente               INT UNSIGNED NULL,
    fuente                   ENUM('UISP', 'GREZZ') NOT NULL,
    referencia_fuente        VARCHAR(64) NULL COMMENT 'Servicio de UISP o solicitud de Grezz',
    fecha_inicio             DATE NOT NULL,
    fecha_fin                DATE NULL COMMENT 'NULL = sigue cortado',
    inicio_estimado          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fecha en que se detectó, no la del corte real',
    id_obligacion_reconexion INT UNSIGNED NULL COMMENT 'Mensualidad que lleva el cargo de reconexión',
    reconexion_estado        ENUM('CARGADA', 'EN_FACTURA', 'FALTA_EN_UISP', 'SIN_MENSUALIDAD', 'REVISION') NULL,
    anulado                  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = deshecho a mano; no se vuelve a aplicar',
    creado_en                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_corte (id_servicio, fuente, fecha_inicio),
    KEY idx_corte_abierto (fuente, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE obligaciones_pago
    ADD COLUMN IF NOT EXISTS cargo_reconexion DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS no_cobro_motivo ENUM('MES_DEL_CORTE', 'SERVICIO_CORTADO') NULL,
    ADD COLUMN IF NOT EXISTS id_corte_no_cobro INT UNSIGNED NULL,
    ADD INDEX IF NOT EXISTS idx_obligacion_corte (id_corte_no_cobro);

-- Agrega 'CANCELADA' al final de la lista de estados, conservando los demás,
-- si es NULL o no y su valor por defecto.
SET @tipo = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obligaciones_pago' AND COLUMN_NAME = 'estado');
SET @nulo = (SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obligaciones_pago' AND COLUMN_NAME = 'estado');
SET @defecto = (SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obligaciones_pago' AND COLUMN_NAME = 'estado');
SET @sql = IF(
    @tipo IS NULL OR @tipo NOT LIKE 'enum(%' OR @tipo LIKE '%''CANCELADA''%',
    'DO 0',
    CONCAT('ALTER TABLE obligaciones_pago MODIFY COLUMN estado ',
           INSERT(@tipo, LENGTH(@tipo), 1, ',''CANCELADA'')'),
           IF(@nulo = 'NO', ' NOT NULL', ' NULL'),
           IF(@defecto IS NULL, '', CONCAT(' DEFAULT ', @defecto)))
);
PREPARE agregar_cancelada FROM @sql;
EXECUTE agregar_cancelada;
DEALLOCATE PREPARE agregar_cancelada;
