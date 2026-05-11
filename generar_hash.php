<?php
// generar_hash.php
// Subir a /movistar/, abrir en el navegador, y luego BORRAR este archivo

require_once __DIR__ . '/config.php';

$password = 'movistar2026';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

// Actualizar en la base de datos
$st = db()->prepare("UPDATE usuarios SET password = ? WHERE username = 'admin'");
$st->execute([$hash]);

echo "<pre>";
echo "Contraseña: $password\n";
echo "Hash generado: $hash\n";
echo "Filas actualizadas: " . $st->rowCount() . "\n";
echo "\nListo. Ahora podés borrar este archivo y probar el login.";
echo "</pre>";
