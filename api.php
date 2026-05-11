<?php
// ============================================================
<<<<<<< HEAD
//  api.php v5 — cobros por usuario con líneas asignadas
// ============================================================
require_once __DIR__ . '/config.php';

set_error_handler(function($no, $str) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>"PHP: $str"]); exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
});

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

if ($action !== 'login') { session_name(SESSION_NAME); session_start(); }

function jout(array $d, int $s=200): void {
    http_response_code($s);
    echo json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function jerr(string $m, int $s=400): void { jout(['ok'=>false,'error'=>$m],$s); }
function auth(): void {
    if (empty($_SESSION['user_id'])) jerr('No autenticado',401);
    $_SESSION['last_activity'] = time();
}
function chkadmin(): void {
    if (($_SESSION['rol']??'')==='admin') return;
    jerr('Sin permisos',403);
}
function is_admin(): bool { return ($_SESSION['rol']??'')==='admin'; }

switch ($action) {
    case 'login':           do_login($body); break;
    case 'logout':          do_logout(); break;
    case 'me':              auth(); do_me(); break;
    case 'cambiar_pass':    auth(); do_cambiar_pass($body); break;
    // facturas
    case 'facturas':        auth(); do_facturas(); break;
    case 'factura':         auth(); do_factura($_GET['periodo']??''); break;
    // cobros
    case 'cobro':                auth(); chkadmin(); do_cobro($body); break;
    case 'cobro_multiple':       auth(); do_cobro_multiple($body); break;
    case 'marcar_pagado':        auth(); do_marcar_pagado(); break;         // usuario avisa que pagó
    case 'aprobar_cobro':        auth(); chkadmin(); do_aprobar_cobro($body); break;
    case 'rechazar_cobro':       auth(); chkadmin(); do_rechazar_cobro($body); break;
    case 'pendientes_aprobacion':auth(); chkadmin(); do_pendientes_aprobacion(); break;
    // mi cuenta
    case 'mi_cuenta':            auth(); do_mi_cuenta(); break;
    // admin: resumen de un usuario
    case 'cuenta_usuario':  auth(); chkadmin(); do_cuenta_usuario((int)($_GET['uid']??0)); break;
    // admin: panel de líneas
    case 'lineas':          auth(); chkadmin(); do_lineas(); break;
    // usuarios
    case 'usuarios':        auth(); chkadmin(); do_usuarios(); break;
    case 'crear_usuario':   auth(); chkadmin(); do_crear_usuario($body); break;
    case 'editar_usuario':  auth(); chkadmin(); do_editar_usuario($body); break;
    case 'toggle_usuario':    auth(); chkadmin(); do_toggle_usuario($body); break;
    case 'desbloquear_usuario':auth(); chkadmin(); do_desbloquear_usuario($body); break;
    // asignación de líneas
    case 'asignar_lineas':  auth(); chkadmin(); do_asignar_lineas($body); break;
    default: jerr("Acción desconocida: $action",404);
}

// ── AUTH ──────────────────────────────────────────────────────
function do_login(array $b): void {
    session_name(SESSION_NAME); session_start();
    $u=trim($b['username']??''); $p=$b['password']??'';
    if (!$u||!$p) jerr('Completá usuario y contraseña');



    // Buscar usuario (activo o no para poder dar mensaje de bloqueado)
    $st=db()->prepare('SELECT * FROM usuarios WHERE username=?');
    $st->execute([$u]); $user=$st->fetch();

    if (!$user) jerr('Credenciales incorrectas',401);

    // Verificar si está bloqueado
    if ($user['bloqueado']) {
        jerr('Usuario bloqueado por demasiados intentos fallidos. Contactá al administrador.',403);
    }

    if (!$user['activo']) jerr('Usuario inactivo',401);

    if (!password_verify($p,$user['password'])) {
        // Incrementar intentos fallidos
        $intentos = ($user['intentos_fallidos'] ?? 0) + 1;
        $bloquear = $intentos >= 2 ? 1 : 0;
        db()->prepare('UPDATE usuarios SET intentos_fallidos=?, bloqueado=? WHERE id=?')
            ->execute([$intentos, $bloquear, $user['id']]);
        if ($bloquear) {
            jerr('Usuario bloqueado por demasiados intentos fallidos. Contactá al administrador.',403);
        }
        $restantes = 2 - $intentos;
        jerr("Contraseña incorrecta. " . ($restantes > 0 ? "Te queda $restantes intento." : ''),401);
    }

    // Login exitoso: resetear intentos
    db()->prepare('UPDATE usuarios SET ultimo_login=NOW(), intentos_fallidos=0, bloqueado=0 WHERE id=?')
        ->execute([$user['id']]);

    $_SESSION=['user_id'=>$user['id'],'username'=>$user['username'],
               'nombre'=>$user['nombre'],'rol'=>$user['rol'],
               'last_activity'=>time()];
    jout(['ok'=>true,'nombre'=>$user['nombre'],'rol'=>$user['rol']]);
}
function do_logout(): void {
    session_name(SESSION_NAME);
    session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    jout(['ok'=>true]);
}
function do_me(): void {
    jout(['ok'=>true,'nombre'=>$_SESSION['nombre'],'rol'=>$_SESSION['rol']]);
}
function do_cambiar_pass(array $b): void {
    $actual=$b['actual']??''; $nueva=$b['nueva']??'';
    if (!$actual||!$nueva) jerr('Completá ambas contraseñas');
    if (strlen($nueva)<6) jerr('Mínimo 6 caracteres');
    $st=db()->prepare('SELECT password FROM usuarios WHERE id=?');
    $st->execute([$_SESSION['user_id']]); $row=$st->fetch();
    if (!password_verify($actual,$row['password'])) jerr('Contraseña actual incorrecta');
    $hash=password_hash($nueva,PASSWORD_BCRYPT,['cost'=>10]);
    db()->prepare('UPDATE usuarios SET password=? WHERE id=?')->execute([$hash,$_SESSION['user_id']]);
    jout(['ok'=>true]);
}

// ── FACTURAS ─────────────────────────────────────────────────
function do_facturas(): void {
    $uid = $_SESSION['user_id'];
    $esAdmin = is_admin();

    if ($esAdmin) {
        $rows = db()->query("
            SELECT f.id, f.periodo, f.fecha_emision, f.fecha_vencimiento,
                   f.total_factura, f.numero_factura,
                   COUNT(DISTINCT c.usuario_id) AS total_pagadores,
                   SUM(CASE WHEN c.cobrado=1 THEN 1 ELSE 0 END) AS pagadores_cobrados,
                   SUM(c.monto_total) AS total_a_cobrar,
                   SUM(CASE WHEN c.cobrado=1 THEN c.monto_total ELSE 0 END) AS total_cobrado
            FROM facturas f
            LEFT JOIN cobros c ON c.factura_id=f.id
            GROUP BY f.id
            ORDER BY SUBSTRING(f.periodo,4,4) ASC, SUBSTRING(f.periodo,1,2) ASC
        ")->fetchAll();
    } else {
        $st = db()->prepare("
            SELECT f.id, f.periodo, f.fecha_emision, f.fecha_vencimiento,
                   f.total_factura, f.numero_factura,
                   c.monto_total AS mi_total,
                   c.cobrado AS mi_cobrado
            FROM facturas f
            LEFT JOIN cobros c ON c.factura_id=f.id AND c.usuario_id=?
            ORDER BY SUBSTRING(f.periodo,4,4) ASC, SUBSTRING(f.periodo,1,2) ASC
        ");
        $st->execute([$uid]);
        $rows = $st->fetchAll();
    }
    jout(['ok'=>true,'facturas'=>$rows,'es_admin'=>$esAdmin]);
}

function do_factura(string $periodo): void {
    if (!$periodo) jerr('Período requerido');
    $st=db()->prepare('SELECT * FROM facturas WHERE periodo=?');
    $st->execute([$periodo]); $f=$st->fetch();
    if (!$f) jerr("Período no encontrado",404);

    $uid = $_SESSION['user_id'];

    if (is_admin()) {
        // Admin: ve todas las líneas agrupadas por usuario
        $st=db()->prepare("
            SELECT d.*, l.numero, l.titular, l.numero AS linea_num,
                   ul.usuario_id, ul.cobra, ul.cobra_iva,
                   u.nombre AS usuario_nombre
            FROM detalle_lineas d
            JOIN lineas l ON l.id=d.linea_id
            LEFT JOIN usuario_lineas ul ON ul.linea_id=l.id
            LEFT JOIN usuarios u ON u.id=ul.usuario_id
            WHERE d.factura_id=?
            ORDER BY u.nombre, l.numero
        ");
        $st->execute([$f['id']]); $det=$st->fetchAll();

        $st=db()->prepare("
            SELECT c.*, u.nombre AS usuario_nombre
            FROM cobros c
            JOIN usuarios u ON u.id=c.usuario_id
            WHERE c.factura_id=?
            ORDER BY u.nombre
        ");
        $st->execute([$f['id']]); $cob=$st->fetchAll();
    } else {
        // Usuario: solo ve sus líneas y su cobro
        $st=db()->prepare("
            SELECT d.*, l.numero, l.titular,
                   ul.cobra, ul.cobra_iva
            FROM detalle_lineas d
            JOIN lineas l ON l.id=d.linea_id
            JOIN usuario_lineas ul ON ul.linea_id=l.id AND ul.usuario_id=?
            WHERE d.factura_id=?
            ORDER BY l.numero
        ");
        $st->execute([$uid, $f['id']]); $det=$st->fetchAll();

        $st=db()->prepare("SELECT * FROM cobros WHERE factura_id=? AND usuario_id=?");
        $st->execute([$f['id'], $uid]); $cob=$st->fetchAll();
    }

    jout(['ok'=>true,'factura'=>$f,'detalle'=>$det,'cobros'=>$cob]);
}

// ── COBROS ───────────────────────────────────────────────────
function do_cobro(array $b): void {
    $id=(int)($b['cobro_id']??0); $cobr=(bool)($b['cobrado']??false);
    $fp=$b['forma_pago']??null; $nota=$b['notas']??null;
    $fecha = $b['fecha_cobro']??null;
    if (!$id) jerr('cobro_id requerido');
    if (!in_array($fp,['efectivo','transferencia','otro',null],true)) jerr('forma_pago inválida');
    // Validar fecha si viene
    if ($fecha && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = null;
    $fechaFinal = $fecha ?: date('Y-m-d');
    if ($cobr)
        db()->prepare("UPDATE cobros SET cobrado=1,estado='cobrado',forma_pago=?,fecha_cobro=?,notas=? WHERE id=?")->execute([$fp,$fechaFinal,$nota,$id]);
    else
        db()->prepare("UPDATE cobros SET cobrado=0,estado='pendiente',forma_pago=NULL,fecha_cobro=NULL,notas=NULL WHERE id=?")->execute([$id]);
    $st=db()->prepare('SELECT * FROM cobros WHERE id=?'); $st->execute([$id]);
    jout(['ok'=>true,'cobro'=>$st->fetch()]);
}

function do_cobro_multiple(array $b): void {
    $ids=$b['cobro_ids']??[]; $fp=$b['forma_pago']??null;
    $nota=$b['notas']??null; $cobr=(bool)($b['cobrado']??true);
    if (!$ids||!is_array($ids)) jerr('cobro_ids requerido');
    if (!in_array($fp,['efectivo','transferencia','otro',null],true)) jerr('forma_pago inválida');

    $uid = $_SESSION['user_id'];
    foreach ($ids as $id) {
        $id=(int)$id; if (!$id) continue;
        // Si no es admin, verificar que el cobro le pertenece
        if (!is_admin()) {
            $st=db()->prepare('SELECT usuario_id FROM cobros WHERE id=?');
            $st->execute([$id]); $row=$st->fetch();
            if (!$row || $row['usuario_id'] != $uid) continue;
        }
        $fecha2 = $b['fecha_cobro']??null;
        if ($fecha2 && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fecha2)) $fecha2 = null;
        $fechaFinal2 = $fecha2 ?: date('Y-m-d');
        if ($cobr)
            db()->prepare("UPDATE cobros SET cobrado=1,estado='cobrado',forma_pago=?,fecha_cobro=?,notas=? WHERE id=?")->execute([$fp,$fechaFinal2,$nota,$id]);
        else
            db()->prepare("UPDATE cobros SET cobrado=0,estado='pendiente',forma_pago=NULL,fecha_cobro=NULL,notas=NULL WHERE id=?")->execute([$id]);
    }
    jout(['ok'=>true,'updated'=>count($ids)]);
}

// ── MI CUENTA / CUENTA USUARIO ───────────────────────────────
function do_mi_cuenta(): void {
    do_cuenta_usuario($_SESSION['user_id']);
}

function do_cuenta_usuario(int $uid): void {
    if (!$uid) jerr('uid requerido');

    // Info del usuario
    $st=db()->prepare('SELECT id,nombre,username,rol FROM usuarios WHERE id=?');
    $st->execute([$uid]); $usr=$st->fetch();
    if (!$usr) jerr('Usuario no encontrado',404);

    // Sus líneas asignadas
    $st=db()->prepare("
        SELECT ul.*, l.numero, l.titular
        FROM usuario_lineas ul
        JOIN lineas l ON l.id=ul.linea_id
        WHERE ul.usuario_id=?
        ORDER BY l.titular
    ");
    $st->execute([$uid]); $lineas=$st->fetchAll();

    // Historial de cobros
    $st=db()->prepare("
        SELECT c.*, f.periodo, f.fecha_vencimiento
        FROM cobros c
        JOIN facturas f ON f.id=c.factura_id
        WHERE c.usuario_id=?
        ORDER BY SUBSTRING(f.periodo,4,4) ASC, SUBSTRING(f.periodo,1,2) ASC
    ");
    $st->execute([$uid]); $cuenta=$st->fetchAll();

    $total_deuda = 0; $total_pagado = 0;
    foreach ($cuenta as $c) {
        if ($c['cobrado']) $total_pagado += $c['monto_total'];
        else               $total_deuda  += $c['monto_total'];
    }

    jout(['ok'=>true,'usuario'=>$usr,'lineas'=>$lineas,'cuenta'=>$cuenta,
          'total_deuda'=>$total_deuda,'total_pagado'=>$total_pagado]);
}

// ── LÍNEAS (panel admin) ─────────────────────────────────────
function do_lineas(): void {
    $rows = db()->query("
        SELECT l.*,
               ul.usuario_id, ul.cobra, ul.cobra_iva,
               u.nombre AS usuario_nombre
        FROM lineas l
        LEFT JOIN usuario_lineas ul ON ul.linea_id=l.id
        LEFT JOIN usuarios u ON u.id=ul.usuario_id
        ORDER BY l.titular
    ")->fetchAll();
    jout(['ok'=>true,'lineas'=>$rows]);
}

// ── USUARIOS ─────────────────────────────────────────────────
function do_usuarios(): void {
    $rows = db()->query("
        SELECT u.id, u.username, u.nombre, u.rol, u.activo, u.ultimo_login,
               COUNT(ul.linea_id) AS cant_lineas,
               GROUP_CONCAT(l.numero ORDER BY l.numero SEPARATOR ',') AS numeros
        FROM usuarios u
        LEFT JOIN usuario_lineas ul ON ul.usuario_id=u.id
        LEFT JOIN lineas l ON l.id=ul.linea_id
        GROUP BY u.id
        ORDER BY u.nombre
    ")->fetchAll();
    jout(['ok'=>true,'usuarios'=>$rows]);
}

function do_crear_usuario(array $b): void {
    $u=trim($b['username']??''); $p=$b['password']??'';
    $n=trim($b['nombre']??''); $r=$b['rol']??'readonly';
    if (!$u||!$p||!$n) jerr('Usuario, contraseña y nombre requeridos');
    if (strlen($p)<6) jerr('Contraseña mínimo 6 caracteres');
    if (!in_array($r,['admin','readonly'],true)) jerr('Rol inválido');
    $st=db()->prepare('SELECT id FROM usuarios WHERE username=?'); $st->execute([$u]);
    if ($st->fetch()) jerr('El usuario ya existe');
    $hash=password_hash($p,PASSWORD_BCRYPT,['cost'=>10]);
    db()->prepare('INSERT INTO usuarios (username,password,nombre,rol) VALUES (?,?,?,?)')->execute([$u,$hash,$n,$r]);
    $newId = db()->lastInsertId();

    // Asignar líneas si vienen
    if (!empty($b['lineas'])) {
        _asignar_lineas((int)$newId, $b['lineas']);
    }
    jout(['ok'=>true,'id'=>$newId]);
}

function do_editar_usuario(array $b): void {
    $id=(int)($b['id']??0); if (!$id) jerr('id requerido');
    $fields=[]; $params=[];
    if (isset($b['nombre']))  { $fields[]='nombre=?';  $params[]=$b['nombre']; }
    if (isset($b['rol']))     { $fields[]='rol=?';     $params[]=$b['rol']; }
    if (isset($b['password'])&&strlen($b['password'])>=6) {
        $fields[]='password=?';
        $params[]=password_hash($b['password'],PASSWORD_BCRYPT,['cost'=>10]);
    }
    if ($fields) {
        $params[]=$id;
        db()->prepare("UPDATE usuarios SET ".implode(',',$fields)." WHERE id=?")->execute($params);
    }
    // Reasignar líneas si vienen
    if (isset($b['lineas'])) {
        _asignar_lineas($id, $b['lineas']);
    }
    jout(['ok'=>true]);
}

function do_toggle_usuario(array $b): void {
    $id=(int)($b['id']??0); if (!$id) jerr('id requerido');
    if ($id===$_SESSION['user_id']) jerr('No podés desactivarte a vos mismo');
    db()->prepare('UPDATE usuarios SET activo=NOT activo WHERE id=?')->execute([$id]);
    jout(['ok'=>true]);
}

function do_asignar_lineas(array $b): void {
    $id=(int)($b['usuario_id']??0); if (!$id) jerr('usuario_id requerido');
    _asignar_lineas($id, $b['lineas']??[]);
    jout(['ok'=>true]);
}

function _asignar_lineas(int $uid, array $lineas): void {
    // Eliminar asignaciones actuales de este usuario
    db()->prepare('DELETE FROM usuario_lineas WHERE usuario_id=?')->execute([$uid]);
    // Insertar nuevas
    foreach ($lineas as $lin) {
        $lid      = (int)($lin['linea_id']??0);
        $cobra    = (int)($lin['cobra']??1);
        $cobraIva = (int)($lin['cobra_iva']??0);
        if (!$lid) continue;
        db()->prepare("
            INSERT INTO usuario_lineas (usuario_id, linea_id, cobra, cobra_iva)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE cobra=VALUES(cobra), cobra_iva=VALUES(cobra_iva)
        ")->execute([$uid, $lid, $cobra, $cobraIva]);
    }
}

// ── MARCAR PAGADO (usuario) ───────────────────────────────────
// Recibe multipart/form-data con cobro_id, notas, y opcionalmente archivo
function do_marcar_pagado(): void {
    $uid      = $_SESSION['user_id'];
    $cobro_id = (int)($_POST['cobro_id'] ?? 0);
    $notas    = trim($_POST['notas'] ?? '');

    if (!$cobro_id) jerr('cobro_id requerido');

    // Verificar que el cobro le pertenece
    $st = db()->prepare('SELECT * FROM cobros WHERE id=? AND usuario_id=?');
    $st->execute([$cobro_id, $uid]);
    $cobro = $st->fetch();
    if (!$cobro) jerr('Cobro no encontrado o sin permisos', 403);
    if ($cobro['estado'] === 'cobrado') jerr('Este cobro ya fue aprobado');

    // Procesar archivo si viene
    $archivoRuta = null;
    if (!empty($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['comprobante'];
        $maxSize  = 5 * 1024 * 1024; // 5MB
        $allowed  = ['image/jpeg','image/png','image/webp','application/pdf'];

        if ($file['size'] > $maxSize) jerr('El archivo no puede superar 5MB');
        if (!in_array($file['type'], $allowed, true)) jerr('Solo se aceptan imágenes o PDF');

        $dir = __DIR__ . '/comprobantes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $nombre   = 'cobro_' . $cobro_id . '_' . $uid . '_' . time() . '.' . strtolower($ext);
        $destino  = $dir . $nombre;

        if (!move_uploaded_file($file['tmp_name'], $destino)) jerr('Error al guardar el archivo');
        $archivoRuta = 'comprobantes/' . $nombre;

        // Guardar en tabla comprobantes
        db()->prepare("INSERT INTO comprobantes (cobro_id, usuario_id, archivo, mime_type, tamanio, notas)
            VALUES (?,?,?,?,?,?)")->execute([$cobro_id, $uid, $archivoRuta, $file['type'], $file['size'], $notas]);
    }

    // Actualizar estado
    db()->prepare("UPDATE cobros SET estado='esperando_aprobacion', notas=? WHERE id=?")
        ->execute([$notas ?: null, $cobro_id]);

    $st = db()->prepare('SELECT * FROM cobros WHERE id=?');
    $st->execute([$cobro_id]);
    jout(['ok'=>true, 'cobro'=>$st->fetch(), 'archivo'=>$archivoRuta]);
}

// ── APROBAR COBRO (admin) ─────────────────────────────────────
function do_aprobar_cobro(array $b): void {
    $id    = (int)($b['cobro_id'] ?? 0);
    $fp    = $b['forma_pago']  ?? 'transferencia';
    $fecha = $b['fecha_cobro'] ?? null;
    $notas = $b['notas']       ?? null;
    if (!$id) jerr('cobro_id requerido');
    if ($fecha && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = null;
    $fechaFinal = $fecha ?: date('Y-m-d');
    db()->prepare("UPDATE cobros SET cobrado=1, estado='cobrado', forma_pago=?, fecha_cobro=?, notas=? WHERE id=?")
        ->execute([$fp, $fechaFinal, $notas, $id]);
    $st = db()->prepare('SELECT * FROM cobros WHERE id=?'); $st->execute([$id]);
    jout(['ok'=>true, 'cobro'=>$st->fetch()]);
}

// ── RECHAZAR COBRO (admin) ────────────────────────────────────
function do_rechazar_cobro(array $b): void {
    $id    = (int)($b['cobro_id'] ?? 0);
    $notas = $b['notas'] ?? null;
    if (!$id) jerr('cobro_id requerido');
    db()->prepare("UPDATE cobros SET estado='rechazado', notas=? WHERE id=?")
        ->execute([$notas, $id]);
    $st = db()->prepare('SELECT * FROM cobros WHERE id=?'); $st->execute([$id]);
    jout(['ok'=>true, 'cobro'=>$st->fetch()]);
}

// ── PENDIENTES DE APROBACIÓN (admin) ─────────────────────────
function do_pendientes_aprobacion(): void {
    $rows = db()->query("
        SELECT c.*, f.periodo, u.nombre AS usuario_nombre,
               comp.archivo, comp.notas AS comp_notas, comp.creado_en AS comp_fecha
        FROM cobros c
        JOIN facturas f ON f.id = c.factura_id
        JOIN usuarios u ON u.id = c.usuario_id
        LEFT JOIN comprobantes comp ON comp.cobro_id = c.id
        WHERE c.estado = 'esperando_aprobacion'
        ORDER BY comp.creado_en DESC
    ")->fetchAll();
    jout(['ok'=>true, 'pendientes'=>$rows]);
}

// ── DESBLOQUEAR USUARIO (admin) ───────────────────────────────
function do_desbloquear_usuario(array $b): void {
    $id = (int)($b['id'] ?? 0);
    if (!$id) jerr('id requerido');
    db()->prepare('UPDATE usuarios SET bloqueado=0, intentos_fallidos=0 WHERE id=?')->execute([$id]);
    jout(['ok' => true]);
=======
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
>>>>>>> 400cee7bf2efdb6a38e96c0f6c20c184808ca007
}
