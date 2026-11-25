-- Método de pago CORTESIA: descuentos que UISP registra como "Crédito de
-- cortesía" (por ejemplo, por una falla del servicio). Cubren la mensualidad
-- pero no son dinero recibido: los totales de ingresos los excluyen.
-- Solo agrega un valor al final de la lista; no cambia ninguna fila.
--
--   mariadb ubb_pagos_pruebas < 2026-11-30_pago_cortesia.sql
--   mariadb ubb_pagos < 2026-11-30_pago_cortesia.sql

ALTER TABLE pagos
    MODIFY COLUMN metodo_pago
        ENUM('EFECTIVO', 'TRANSFERENCIA', 'DEPOSITO', 'TARJETA', 'OXXO', '3B', 'OTRO', 'CORTESIA') NOT NULL;
