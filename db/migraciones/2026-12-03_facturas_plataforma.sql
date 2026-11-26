-- Clientes que se facturan en la plataforma de Jamileth (servicios con
-- factura_en_plataforma = 1).
--
-- pagos.id_factura: la plataforma de facturación liga cada pago de UBB con su
--   factura por este campo (ya existía en producción; aquí queda documentado).
--   Ella aplica los pagos PAGADO cada 15 minutos y retira los que pasan a
--   CANCELADO.
-- obligaciones_pago.id_factura: la mensualidad de UBB creada a partir de esa
--   factura (scripts/sincronizar_facturas_plataforma.php). Una por factura.
--
-- Solo agrega columnas e índices; no cambia ninguna fila.
--
--   mariadb ubb_pagos_pruebas < 2026-12-03_facturas_plataforma.sql
--   mariadb ubb_pagos < 2026-12-03_facturas_plataforma.sql

-- (En producción ya tiene su índice; no se agrega otro para no duplicarlo.)
ALTER TABLE pagos
    ADD COLUMN IF NOT EXISTS id_factura BIGINT UNSIGNED NULL;

ALTER TABLE obligaciones_pago
    ADD COLUMN IF NOT EXISTS id_factura BIGINT UNSIGNED NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_obligacion_factura (id_factura);
