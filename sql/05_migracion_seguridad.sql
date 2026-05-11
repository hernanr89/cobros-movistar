-- ============================================================
--  Migración: seguridad de login
--  Ejecutar en phpMyAdmin → SQL
-- ============================================================

ALTER TABLE usuarios
    ADD COLUMN intentos_fallidos TINYINT NOT NULL DEFAULT 0 AFTER activo,
    ADD COLUMN bloqueado TINYINT(1) NOT NULL DEFAULT 0 AFTER intentos_fallidos,
    ADD COLUMN patron VARCHAR(255) DEFAULT NULL AFTER bloqueado;
    -- patron: hash del patrón de puntos (para futura implementación)
