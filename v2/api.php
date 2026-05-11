<?php
// ============================================================
//  api.php — compatible con cualquier hosting PHP
//  Ruteo por ?action=login en lugar de PATH_INFO
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Iniciar sesión para todas las rutas excepto login
if ($action !== 'login') {
    session_name(SESSION_NAME);
    session_start();
}

switch ($action) {
    case 'login':   ruta_login($body);   break;
    case 'logout':  ruta_logout();       break;
    case 'me':      require_auth(); ruta_me(); break;
    case 'facturas': require_auth(); ruta_facturas(); break;
    case 'factura':  require_auth(); ruta_factura($_GET['periodo'] ?? ''); break;
    case 'cobro':    require_auth(); require_admin(); ruta_cobro($body); break;
    default: json_err("Acción no encontrada: $action", 404);
}

// ── HELPERS ──────────────────────────────────────────────────
function json_out(array $d, int $s = 200): void {
    http_response_code($s);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_err(string $m, int $s = 400): void { json_out(['ok' => false, 'error' => $m], $s); }

function require_auth(): void {
    if (empty($_SESSION['user_id'])) json_err('No autenticado', 401);
    $_SESSION['last_activity'] = time();
}
function require_admin(): void {
    if (($_SESSION['rol'] ?? '') !== 'admin') json_err('Sin permisos', 403);
}
function is_admin(): bool { return ($_SESSION['rol'] ?? '') === 'admin'; }

// ── AUTH ─────────────────────────────────────────────────────
function ruta_login(array $b): void {
    session_name(SESSION_NAME);
    session_start();

    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';
    if (!$username || !$password) json_err('Usuario y contraseña requeridos');

    $st = db()->prepare('SELECT * FROM usuarios WHERE username = ? AND activo = 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_err('Credenciales incorrectas', 401);
    }

    db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([$user['id']]);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['nombre']        = $user['nombre'];
    $_SESSION['rol']           = $user['rol'];
    $_SESSION['pagador']       = $user['pagador'];
    $_SESSION['last_activity'] = time();

    json_out(['ok' => true, 'nombre' => $user['nombre'],
              'rol' => $user['rol'], 'pagador' => $user['pagador']]);
}

function ruta_logout(): void {
    session_destroy();
    json_out(['ok' => true]);
}

function ruta_me(): void {
    json_out(['ok' => true, 'nombre' => $_SESSION['nombre'],
              'rol' => $_SESSION['rol'], 'pagador' => $_SESSION['pagador']]);
}

// ── FACTURAS ─────────────────────────────────────────────────
function ruta_facturas(): void {
    $rows = db()->query("
        SELECT f.id, f.periodo, f.fecha_emision, f.fecha_vencimiento,
               f.total_factura, f.numero_factura,
               COUNT(DISTINCT c.id) AS total_pagadores,
               SUM(CASE WHEN c.cobrado=1 THEN 1 ELSE 0 END) AS pagadores_cobrados,
               SUM(c.monto_total) AS total_a_cobrar,
               SUM(CASE WHEN c.cobrado=1 THEN c.monto_total ELSE 0 END) AS total_cobrado
        FROM facturas f
        LEFT JOIN cobros c ON c.factura_id = f.id
        GROUP BY f.id ORDER BY f.periodo DESC
    ")->fetchAll();
    json_out(['ok' => true, 'facturas' => $rows]);
}

function ruta_factura(string $periodo): void {
    if (!$periodo) json_err('Período requerido');

    $st = db()->prepare('SELECT * FROM facturas WHERE periodo = ?');
    $st->execute([$periodo]);
    $factura = $st->fetch();
    if (!$factura) json_err("Período no encontrado", 404);

    $pagador = $_SESSION['pagador'] ?? null;

    if (!is_admin() && $pagador) {
        $st = db()->prepare("
            SELECT d.*, l.numero, l.titular, l.pagador, l.cobra_iva
            FROM detalle_lineas d JOIN lineas l ON l.id = d.linea_id
            WHERE d.factura_id = ? AND l.pagador = ? ORDER BY l.numero
        ");
        $st->execute([$factura['id'], $pagador]);
        $detalle = $st->fetchAll();

        $st = db()->prepare("SELECT * FROM cobros WHERE factura_id = ? AND pagador = ?");
        $st->execute([$factura['id'], $pagador]);
        $cobros = $st->fetchAll();
    } else {
        $st = db()->prepare("
            SELECT d.*, l.numero, l.titular, l.pagador, l.cobra_iva
            FROM detalle_lineas d JOIN lineas l ON l.id = d.linea_id
            WHERE d.factura_id = ? ORDER BY l.pagador, l.numero
        ");
        $st->execute([$factura['id']]);
        $detalle = $st->fetchAll();

        $st = db()->prepare("SELECT * FROM cobros WHERE factura_id = ? ORDER BY pagador");
        $st->execute([$factura['id']]);
        $cobros = $st->fetchAll();
    }

    json_out(['ok' => true, 'factura' => $factura, 'detalle' => $detalle, 'cobros' => $cobros]);
}

// ── COBROS ───────────────────────────────────────────────────
function ruta_cobro(array $b): void {
    $id         = (int)($b['cobro_id']   ?? 0);
    $cobrado    = (bool)($b['cobrado']   ?? false);
    $forma_pago = $b['forma_pago'] ?? null;
    $notas      = $b['notas']      ?? null;

    if (!$id) json_err('cobro_id requerido');
    if (!in_array($forma_pago, ['efectivo','transferencia','otro',null], true)) json_err('forma_pago inválida');

    if ($cobrado) {
        db()->prepare("UPDATE cobros SET cobrado=1, forma_pago=?, fecha_cobro=CURDATE(), notas=? WHERE id=?")
            ->execute([$forma_pago, $notas, $id]);
    } else {
        db()->prepare("UPDATE cobros SET cobrado=0, forma_pago=NULL, fecha_cobro=NULL, notas=NULL WHERE id=?")
            ->execute([$id]);
    }

    $st = db()->prepare('SELECT * FROM cobros WHERE id = ?');
    $st->execute([$id]);
    json_out(['ok' => true, 'cobro' => $st->fetch()]);
}
