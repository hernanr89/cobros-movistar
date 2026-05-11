-- ============================================================
--  CORRECCION tabla cobros para MySQL < 5.6
--  Ejecutar en phpMyAdmin → pestaña SQL
-- ============================================================

CREATE TABLE IF NOT EXISTS cobros (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    factura_id      INT            NOT NULL,
    pagador         VARCHAR(100)   NOT NULL,
    monto_neto      DECIMAL(10,2)  NOT NULL,
    monto_iva       DECIMAL(10,2)  NOT NULL DEFAULT 0,
    monto_total     DECIMAL(10,2)  NOT NULL,
    cobrado         TINYINT(1)     NOT NULL DEFAULT 0,
    forma_pago      ENUM('efectivo','transferencia','otro') DEFAULT NULL,
    fecha_cobro     DATE           DEFAULT NULL,
    notas           TEXT,
    creado_en       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME       DEFAULT NULL,
    FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE,
    UNIQUE KEY ux_factura_pagador (factura_id, pagador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
