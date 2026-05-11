<?php
// ============================================================
//  config.php — EJEMPLO (no subir el config.php real al repo)
//  Copiar este archivo como config.php y completar los valores
// ============================================================

define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    3306);
define('DB_NAME',    'app_movistar');
define('DB_USER',    'claude_app');
define('DB_PASS',    'TU_CONTRASEÑA_AQUI');
define('DB_CHARSET', 'utf8mb4');
define('SESSION_NAME', 'movistar_session');

date_default_timezone_set('America/Argentina/Buenos_Aires');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
