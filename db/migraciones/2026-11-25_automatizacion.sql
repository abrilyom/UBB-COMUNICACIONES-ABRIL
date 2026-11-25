-- Automatización de pagos y servicios UBB
-- Tablas nuevas para la conciliación automática y las notificaciones.
-- Solo crea tablas: no modifica ni borra información existente.
--
-- Aplicar en pruebas primero:
--   mysql -u USUARIO -p ubb_pagos_pruebas < 2026-11-25_automatizacion.sql
-- Después en producción:
--   mysql -u USUARIO -p ubb_pagos < 2026-11-25_automatizacion.sql

-- Motivos por los que un movimiento bancario pasó a revisión manual.
CREATE TABLE IF NOT EXISTS conciliacion_revisiones (
    id_revision          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_movimiento        INT NOT NULL,
    motivo               VARCHAR(500) NOT NULL,
    referencia_detectada VARCHAR(60) NULL,
    fecha_revision       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_revision_movimiento (id_movimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de avisos a clientes por WhatsApp o SMS.
CREATE TABLE IF NOT EXISTS notificaciones_cola (
    id_notificacion     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente          INT NOT NULL,
    id_servicio         INT NOT NULL,
    id_obligacion       INT NULL,
    tipo                ENUM(
                            'RECORDATORIO_PREVIO',
                            'RECORDATORIO_HOY',
                            'ULTIMO_AVISO',
                            'SUSPENSION',
                            'PAGO_RECIBIDO'
                        ) NOT NULL,
    clave_unica         VARCHAR(150) NOT NULL,
    datos_json          TEXT NOT NULL,
    estado              ENUM('PENDIENTE', 'ENVIADA', 'ERROR', 'SIN_TELEFONO')
                            NOT NULL DEFAULT 'PENDIENTE',
    canal               VARCHAR(30) NULL,
    telefono            VARCHAR(20) NULL,
    mensaje             TEXT NULL,
    respuesta_proveedor TEXT NULL,
    intentos            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_envio         DATETIME NULL,
    UNIQUE KEY uq_notificacion_clave (clave_unica),
    INDEX idx_notificacion_estado (estado, fecha_creacion),
    INDEX idx_notificacion_servicio (id_servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
