<?php
// ============================================================
//  api.php — API REST con soporte de roles
//  Subir a: /movistar/api.php
// ============================================================

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$path   = trim($_SERVER['PATH_INFO'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ("$method $path") {
    case 'POST login':   ruta_login($body);   break;
    case 'POST logout':  ruta_logout();        break;
    case 'GET me':       require_auth(); ruta_me(); break;
    case 'GET facturas': require_auth(); ruta_facturas(); break;
    case 'GET factura':  require_auth(); ruta_factura_detalle($_GET['periodo'] ?? ''); break;
    case 'POST cobro':   require_auth(); require_admin(); ruta_actualizar_cobro($body); break;
    case 'GET lineas':   require_auth(); ruta_lineas(); break;
    default: json_err("Ruta no encontrada: $method $path", 404);
}

function ruta_login(array $b): void {
    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';
    if (!$username || !$password) json_err('Usuario y contraseña requeridos');

    $st = db()->prepare('SELECT * FROM usuarios WHERE username = ? AND activo = 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_err('Credenciales incorrectas', 401);
    }

    db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    session_name(SESSION_NAME);
    session_start();
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['nombre']        = $user['nombre'];
    $_SESSION['rol']           = $user['rol'];
    $_SESSION['pagador']       = $user['pagador'];
    $_SESSION['last_activity'] = time();

    json_out([
        'ok'      => true,
        'nombre'  => $user['nombre'],
        'rol'     => $user['rol'],
        'pagador' => $user['pagador'],
    ]);
}

function ruta_logout(): void {
    session_name(SESSION_NAME);
    session_start();
    session_destroy();
    json_out(['ok' => true]);
}

function ruta_me(): void {
    json_out([
        'ok'      => true,
        'nombre'  => $_SESSION['nombre'],
        'rol'     => $_SESSION['rol'],
        'pagador' => $_SESSION['pagador'],
    ]);
}

function require_auth(): void {
    session_name(SESSION_NAME);
    session_start();
    if (empty($_SESSION['user_id'])) json_err('No autenticado', 401);
    $_SESSION['last_activity'] = time();
}

function require_admin(): void {
    if (($_SESSION['rol'] ?? '') !== 'admin') {
        json_err('Sin permisos para esta acción', 403);
    }
}

function is_admin(): bool {
    return ($_SESSION['rol'] ?? '') === 'admin';
}

function ruta_facturas(): void {
    $rows = db()->query("
        SELECT f.id, f.periodo, f.fecha_emision, f.fecha_vencimiento,
               f.total_factura, f.numero_factura,
               COUNT(DISTINCT c.id) AS total_pagadores,
               SUM(CASE WHEN c.cobrado = 1 THEN 1 ELSE 0 END) AS pagadores_cobrados,
               SUM(c.monto_total) AS total_a_cobrar,
               SUM(CASE WHEN c.cobrado = 1 THEN c.monto_total ELSE 0 END) AS total_cobrado
        FROM facturas f
        LEFT JOIN cobros c ON c.factura_id = f.id
        GROUP BY f.id
        ORDER BY f.periodo DESC
    ")->fetchAll();

    json_out(['ok' => true, 'facturas' => $rows]);
}

function ruta_factura_detalle(string $periodo): void {
    if (!$periodo) json_err('Período requerido');

    $st = db()->prepare('SELECT * FROM facturas WHERE periodo = ?');
    $st->execute([$periodo]);
    $factura = $st->fetch();
    if (!$factura) json_err("Período $periodo no encontrado", 404);

    $pagadorFiltro = $_SESSION['pagador'] ?? null;

    if (!is_admin() && $pagadorFiltro) {
        $st = db()->prepare("
            SELECT d.*, l.numero, l.titular, l.pagador, l.cobra_iva
            FROM detalle_lineas d
            JOIN lineas l ON l.id = d.linea_id
            WHERE d.factura_id = ? AND l.pagador = ?
            ORDER BY l.numero
        ");
        $st->execute([$factura['id'], $pagadorFiltro]);
    } else {
        $st = db()->prepare("
            SELECT d.*, l.numero, l.titular, l.pagador, l.cobra_iva
            FROM detalle_lineas d
            JOIN lineas l ON l.id = d.linea_id
            WHERE d.factura_id = ?
            ORDER BY l.pagador, l.numero
        ");
        $st->execute([$factura['id']]);
    }
    $detalle = $st->fetchAll();

    if (!is_admin() && $pagadorFiltro) {
        $st = db()->prepare("SELECT * FROM cobros WHERE factura_id = ? AND pagador = ?");
        $st->execute([$factura['id'], $pagadorFiltro]);
    } else {
        $st = db()->prepare("SELECT * FROM cobros WHERE factura_id = ? ORDER BY pagador");
        $st->execute([$factura['id']]);
    }
    $cobros = $st->fetchAll();

    json_out(['ok' => true, 'factura' => $factura, 'detalle' => $detalle, 'cobros' => $cobros]);
}

function ruta_actualizar_cobro(array $b): void {
    $cobro_id   = (int)($b['cobro_id']   ?? 0);
    $cobrado    = (bool)($b['cobrado']   ?? false);
    $forma_pago = $b['forma_pago'] ?? null;
    $notas      = $b['notas']      ?? null;

    if (!$cobro_id) json_err('cobro_id requerido');
    if (!in_array($forma_pago, ['efectivo', 'transferencia', 'otro', null], true)) {
        json_err('forma_pago inválida');
    }

    if ($cobrado) {
        $st = db()->prepare("
            UPDATE cobros SET cobrado = 1, forma_pago = ?, fecha_cobro = CURDATE(), notas = ?
            WHERE id = ?
        ");
        $st->execute([$forma_pago, $notas, $cobro_id]);
    } else {
        $st = db()->prepare("
            UPDATE cobros SET cobrado = 0, forma_pago = NULL, fecha_cobro = NULL, notas = NULL
            WHERE id = ?
        ");
        $st->execute([$cobro_id]);
    }

    $st = db()->prepare('SELECT * FROM cobros WHERE id = ?');
    $st->execute([$cobro_id]);
    json_out(['ok' => true, 'cobro' => $st->fetch()]);
}

function ruta_lineas(): void {
    $rows = db()->query("SELECT * FROM lineas ORDER BY titular")->fetchAll();
    json_out(['ok' => true, 'lineas' => $rows]);
}
