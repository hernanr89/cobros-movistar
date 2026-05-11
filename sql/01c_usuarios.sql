-- Crear tabla usuarios y usuario inicial
CREATE TABLE IF NOT EXISTS usuarios (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50)  NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    nombre       VARCHAR(100),
    activo       TINYINT(1)   DEFAULT 1,
    ultimo_login DATETIME     DEFAULT NULL,
    creado_en    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario: admin / movistar2026
INSERT INTO usuarios (username, password, nombre) VALUES (
    'admin',
    '$2y$12$eImiTXuWVxfM37uY4JANjOe5XkLPZ1n1vGDPJiXNfGfFkELRuZ.v6',
    'Rodríguez Hernán'
);
