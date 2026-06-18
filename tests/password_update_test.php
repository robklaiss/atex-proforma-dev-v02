<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../app/bootstrap.php';

function passwordUpdateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Fallo: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        auth_version INTEGER NOT NULL DEFAULT 1,
        role TEXT NOT NULL,
        first_name TEXT NOT NULL DEFAULT \'\',
        last_name TEXT NOT NULL DEFAULT \'\',
        email TEXT NOT NULL DEFAULT \'\',
        phone TEXT NOT NULL DEFAULT \'\',
        unit TEXT NOT NULL DEFAULT \'Paraguay\',
        reports_to_id INTEGER,
        commercial_position TEXT NOT NULL DEFAULT \'\',
        signature_image TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL
    )'
);
$stmt = $pdo->prepare(
    'INSERT INTO users (username, password_hash, role, created_at)
     VALUES (:username, :password_hash, :role, :created_at)'
);
$stmt->execute([
    ':username' => 'password-regression',
    ':password_hash' => password_hash('OldPassword-2026', PASSWORD_DEFAULT),
    ':role' => 'commercial_executive',
    ':created_at' => nowIso(),
]);

$GLOBALS['app_pdo'] = $pdo;
$_SESSION = [];

passwordUpdateAssert(
    attemptLogin('password-regression', 'OldPassword-2026'),
    'la contraseña inicial permite iniciar sesión'
);
passwordUpdateAssert(
    (int) ($_SESSION['auth_version'] ?? 0) === 1,
    'la sesión conserva la versión de credenciales'
);

$update = $pdo->prepare(
    'UPDATE users
     SET password_hash = :password_hash, auth_version = auth_version + 1
     WHERE username = :username'
);
$update->execute([
    ':password_hash' => password_hash('NewPassword-2026', PASSWORD_DEFAULT),
    ':username' => 'password-regression',
]);

passwordUpdateAssert(
    currentUser() === null && !isLoggedIn(),
    'una sesión anterior queda invalidada al cambiar la contraseña'
);
passwordUpdateAssert(
    !attemptLogin('password-regression', 'OldPassword-2026'),
    'la contraseña anterior deja de funcionar'
);
passwordUpdateAssert(
    attemptLogin('password-regression', 'NewPassword-2026'),
    'la contraseña nueva permite iniciar sesión'
);

echo 'Prueba de actualización de contraseña completada.' . PHP_EOL;
ob_end_flush();
