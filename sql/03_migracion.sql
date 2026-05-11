-- ============================================================
--  Migración: modelo de cobros por usuario
--  Ejecutar en phpMyAdmin → SQL
-- ============================================================

-- 1. Tabla de asignación de líneas a usuarios
CREATE TABLE IF NOT EXISTS usuario_lineas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    linea_id    INT NOT NULL,
    cobra       TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = no cobrar esta línea
    cobra_iva   TINYINT(1) NOT NULL DEFAULT 0,
    creado_en   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (linea_id)   REFERENCES lineas(id),
    UNIQUE KEY ux_linea (linea_id)               -- una línea solo a un usuario
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Agregar usuario_id a cobros (además del pagador actual)
ALTER TABLE cobros
    ADD COLUMN usuario_id INT DEFAULT NULL AFTER factura_id,
    ADD FOREIGN KEY fk_cobro_usuario (usuario_id) REFERENCES usuarios(id);

-- 3. Agregar usuario_id a detalle_lineas para facilitar joins
ALTER TABLE detalle_lineas
    ADD COLUMN usuario_id INT DEFAULT NULL AFTER factura_id;
