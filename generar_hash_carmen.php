<?php
// generar_hash_carmen.php
// 1. Subir a /movistar/
// 2. Abrir en el navegador
// 3. Copiar el hash
// 4. BORRAR este archivo

$password = 'carmen2026';   // ← cambiá esto por la contraseña que quieras
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "<pre>";
echo "Contraseña: $password\n";
echo "Hash: $hash\n\n";
echo "Copiá el hash y pegalo en el SQL donde dice EL_HASH_AQUI";
echo "</pre>";
