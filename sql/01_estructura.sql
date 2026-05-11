-- ============================================================
--  App Cobros Movistar · Rodríguez Hernán
--  Ejecutar en la base de datos: app_movistar
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- ------------------------------------------------------------
-- Tabla: lineas
-- Cada número de línea con su titular y quién paga
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lineas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    numero      VARCHAR(20)  NOT NULL UNIQUE,
    titular     VARCHAR(100) NOT NULL,
    pagador     VARCHAR(100) NOT NULL,   -- quién efectivamente paga
    cobra_iva   TINYINT(1)   NOT NULL DEFAULT 0,
    activa      TINYINT(1)   NOT NULL DEFAULT 1,
    notas       TEXT,
    creado_en   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Líneas actuales de la flota
INSERT INTO lineas (numero, titular, pagador, cobra_iva) VALUES
('1128355450', 'Nacho',    'Nacho',    0),
('1138964519', 'Sin nombre','Sin nombre',0),
('1150558417', 'Sin nombre','Sin nombre',0),
('1167642458', 'Matias',   'Matias',   1),
('2215898969', 'Fernando', 'Flavia',   0),
('2346330100', 'S24',      'S24',      0),
('2346482582', 'Hernán',   'Hernán',   0),
('2346502797', 'Lorenzo',  'Lorenzo',  0),
('2346511132', 'Gustavo',  'Carmen',   0),
('2346563677', 'Federica', 'Federica', 0),
('2346568019', 'Flavia',   'Flavia',   0),
('2346571429', 'Hernán',   'Hernán',   0),
('2346571436', 'Local',    'Local',    0),
('2346597390', 'Carmen',   'Carmen',   0),
('2346652570', 'Sin nombre','Sin nombre',0),
('2346686005', 'Teresa',   'Teresa',   0);

-- ------------------------------------------------------------
-- Tabla: facturas
-- Un registro por mes/factura importada
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS facturas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    periodo         VARCHAR(7)     NOT NULL UNIQUE,  -- ej: '05-2026'
    fecha_emision   DATE,
    fecha_vencimiento DATE,
    total_factura   DECIMAL(12,2)  NOT NULL,
    numero_factura  VARCHAR(50),
    cuenta_cliente  VARCHAR(20),
    importado_en    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: detalle_lineas
-- Detalle por línea de cada factura (un reg por línea por mes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS detalle_lineas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    factura_id      INT            NOT NULL,
    linea_id        INT            NOT NULL,
    plan            VARCHAR(100),
    importe_bruto   DECIMAL(10,2)  DEFAULT 0,
    bonificacion    DECIMAL(10,2)  DEFAULT 0,
    neto_sin_imp    DECIMAL(10,2)  DEFAULT 0,   -- lo que figura en el PDF
    iva_y_percepciones DECIMAL(10,2) DEFAULT 0,
    total_con_imp   DECIMAL(10,2)  DEFAULT 0,   -- lo que figura en la factura general
    consumos_extras DECIMAL(10,2)  DEFAULT 0,
    FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE,
    FOREIGN KEY (linea_id)   REFERENCES lineas(id),
    UNIQUE KEY ux_factura_linea (factura_id, linea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: cobros
-- Un registro por pagador por mes
-- ------------------------------------------------------------
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
    actualizado_en  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE,
    UNIQUE KEY ux_factura_pagador (factura_id, pagador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabla: usuarios
-- Login de la app web
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,   -- bcrypt hash
    nombre      VARCHAR(100),
    activo      TINYINT(1)   DEFAULT 1,
    ultimo_login TIMESTAMP   DEFAULT NULL,
    creado_en   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario inicial: admin / movistar2026
-- (la contraseña es el hash bcrypt de "movistar2026")
INSERT INTO usuarios (username, password, nombre) VALUES (
    'admin',
    '$2y$12$eImiTXuWVxfM37uY4JANjOe5XkLPZ1n1vGDPJiXNfGfFkELRuZ.v6',
    'Rodríguez Hernán'
);
