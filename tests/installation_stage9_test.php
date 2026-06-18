<?php

declare(strict_types=1);

function stage9Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function stage9CopyDirectory(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('No se pudo crear el directorio temporal.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException('No se pudo copiar un directorio temporal.');
            }
            continue;
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('No se pudo copiar un archivo temporal.');
        }
    }
}

function stage9RemoveDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/atex-install-stage9-' . bin2hex(random_bytes(6));

try {
    stage9CopyDirectory($root . '/app', $temporaryRoot . '/app');
    stage9CopyDirectory($root . '/migrations', $temporaryRoot . '/migrations');
    if (!mkdir($temporaryRoot . '/scripts', 0775, true)) {
        throw new RuntimeException('No se pudo crear scripts temporal.');
    }
    copy($root . '/scripts/install.php', $temporaryRoot . '/scripts/install.php');
    copy(
        $root . '/Proforma de Factura Atex Paraguay.pdf',
        $temporaryRoot . '/Proforma de Factura Atex Paraguay.pdf'
    );

    $command = [PHP_BINARY, $temporaryRoot . '/scripts/install.php'];
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $temporaryRoot,
        [
            'APP_ENV' => 'preproduction',
            'APP_DEBUG' => '0',
            'APP_URL' => 'https://preproduction.example.test',
            'APP_TIMEZONE' => 'America/Asuncion',
            'INITIAL_ADMIN_USERNAME' => 'stage9-admin',
            'INITIAL_ADMIN_PASSWORD' => 'stage9-temporary-password',
            'HEALTHCHECK_TOKEN' => '0123456789abcdef0123456789abcdef',
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo ejecutar el instalador temporal.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    stage9Assert(
        $exitCode === 0,
        'scripts/install.php completa una instalación sin base previa'
            . ($exitCode === 0 ? '' : PHP_EOL . $stdout . PHP_EOL . $stderr)
    );

    $databasePath = $temporaryRoot . '/storage/database/app.sqlite';
    stage9Assert(is_file($databasePath), 'la instalación limpia crea SQLite');

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach (['users', 'clients', 'proformas', 'country_units', 'exchange_rates'] as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute([':name' => $table]);
        stage9Assert((int) $stmt->fetchColumn() === 1, 'la instalación crea la tabla ' . $table);
    }

    $admin = $pdo->query("SELECT role FROM users WHERE username = 'stage9-admin'")->fetchColumn();
    stage9Assert($admin === 'admin', 'la instalación crea el administrador inicial');
    stage9Assert($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite nuevo supera integrity_check');
    stage9Assert($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'SQLite nuevo no tiene claves foráneas inválidas');
    $pdo = null;
} finally {
    stage9RemoveDirectory($temporaryRoot);
}

echo PHP_EOL . 'Prueba de instalación limpia de Etapa 9 completada.' . PHP_EOL;
