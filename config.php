<?php
// ============================================================
//  config.php — Configuración central
//  Subir a: /var/www/movistar.sitiospyme.com.ar/config.php
// ============================================================

define('DB_HOST',     '127.0.0.1');   // localhost dentro del servidor
define('DB_PORT',     3306);
define('DB_NAME',     'app_movistar');
define('DB_USER',     'claude_app');
define('DB_PASS',     '32hfF!LTc');            // ← completá con tu contraseña actual
define('DB_CHARSET',  'utf8mb4');

define('SESSION_NAME',    'movistar_session');
define('SESSION_TIMEOUT', 3600 * 8);   // 8 horas

// Zona horaria Argentina
date_default_timezone_set('America/Argentina/Buenos_Aires');

// ─── Conexión PDO ────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── Helpers ────────────────────────────────────────────────
function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $status = 400): void {
    json_out(['ok' => false, 'error' => $msg], $status);
}

function require_auth(): void {
    session_name(SESSION_NAME);
    session_start();
    if (empty($_SESSION['user_id'])) {
        json_err('No autenticado', 401);
    }
    // Renovar timeout
    $_SESSION['last_activity'] = time();
}
