<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function installLine(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[OK] ' : '[WARN] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
}

ensureStorageDirectories();
installLine('Carpetas storage verificadas', true);

$storageHtaccess = STORAGE_PATH . '/.htaccess';
if (!is_file($storageHtaccess)) {
    if (file_put_contents($storageHtaccess, "Require all denied\n") === false) {
        throw new RuntimeException('No se pudo crear la protección de storage.');
    }
}
installLine('Protección de storage verificada', true);

$pdo = db();
runMigrations($pdo);
installLine('Migraciones ejecutadas', true);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$adminUsername = trim((string) (getenv('INITIAL_ADMIN_USERNAME') ?: 'proforma-admin'));
$adminPassword = (string) (getenv('INITIAL_ADMIN_PASSWORD') ?: '');
$stmt->execute([':username' => $adminUsername]);
if (!$stmt->fetchColumn()) {
    if ($adminPassword === '') {
        throw new RuntimeException('Define INITIAL_ADMIN_PASSWORD en .env antes de crear el usuario administrador.');
    }

    $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, :created_at)');
    $insert->execute([
        ':username' => $adminUsername,
        ':password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':created_at' => nowIso(),
    ]);
    installLine('Usuario administrador creado', true);
} else {
    installLine('Usuario administrador existente', true);
}

$adminStmt = $pdo->prepare('SELECT id, unit FROM users WHERE username = :username LIMIT 1');
$adminStmt->execute([':username' => $adminUsername]);
$adminUser = $adminStmt->fetch();
if ($adminUser && userCountryUnitIds($pdo, (int) $adminUser['id']) === []) {
    $adminUnit = findCountryUnitByName($pdo, (string) ($adminUser['unit'] ?? defaultCountry()))
        ?: findCountryUnitByName($pdo, defaultCountry());
    if ($adminUnit) {
        syncUserCountryUnits($pdo, (int) $adminUser['id'], [(int) $adminUnit['id']]);
    }
}
installLine('Unidades país del administrador verificadas', true);

$initialTaxes = [
    ['IVA 10%', 10.0, 'Paraguay'],
    ['IVA 5%', 5.0, 'Paraguay'],
    ['Exento', 0.0, 'Paraguay'],
];
$taxStmt = $pdo->prepare('SELECT id FROM taxes WHERE nombre = :nombre LIMIT 1');
$taxInsert = $pdo->prepare('INSERT INTO taxes (nombre, porcentaje, paises, activo, created_at) VALUES (:nombre, :porcentaje, :paises, 1, :created_at)');
foreach ($initialTaxes as [$name, $rate, $country]) {
    $taxStmt->execute([':nombre' => $name]);
    if (!$taxStmt->fetchColumn()) {
        $taxInsert->execute([
            ':nombre' => $name,
            ':porcentaje' => $rate,
            ':paises' => $country,
            ':created_at' => nowIso(),
        ]);
    }
}
installLine('Impuestos iniciales verificados', true);

$unwritablePaths = [];
foreach ([DATABASE_PATH, BACKUP_PATH, PROFORMA_STORAGE_PATH, SIGNATURE_STORAGE_PATH, LOG_PATH] as $path) {
    $writable = is_writable($path);
    installLine('Permiso escritura ' . storageRelativePath($path), $writable);
    if (!$writable) {
        $unwritablePaths[] = storageRelativePath($path);
    }
}

installLine('Template PDF de referencia', is_file(TEMPLATE_PDF_PATH), basename(TEMPLATE_PDF_PATH));

if ($unwritablePaths !== []) {
    throw new RuntimeException('Faltan permisos de escritura en: ' . implode(', ', $unwritablePaths) . '.');
}

echo PHP_EOL . 'Instalacion lista. Usuario: ' . $adminUsername . PHP_EOL;
