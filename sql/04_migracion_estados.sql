-- ============================================================
--  Migración: estados de cobro y comprobantes
--  Ejecutar en phpMyAdmin → SQL
-- ============================================================

-- 1. Agregar columna estado a cobros
--    'pendiente'             = no pagado
--    'esperando_aprobacion'  = usuario marcó que pagó, admin aún no confirmó
--    'cobrado'               = admin aprobó / admin registró directamente
--    'rechazado'             = admin rechazó el pago
ALTER TABLE cobros
    ADD COLUMN estado ENUM('pendiente','esperando_aprobacion','cobrado','rechazado')
    NOT NULL DEFAULT 'pendiente' AFTER cobrado;

-- 2. Migrar datos existentes: si cobrado=1 → estado='cobrado'
UPDATE cobros SET estado = 'cobrado' WHERE cobrado = 1;

-- 3. Tabla de comprobantes de pago
CREATE TABLE IF NOT EXISTS comprobantes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    cobro_id     INT NOT NULL,
    usuario_id   INT NOT NULL,
    archivo      VARCHAR(255) NOT NULL,   -- ruta relativa del archivo subido
    mime_type    VARCHAR(100),
    tamanio      INT,                     -- bytes
    notas        TEXT,                    -- texto opcional del usuario
    creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cobro_id)   REFERENCES cobros(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
