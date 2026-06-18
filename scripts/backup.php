<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$backup = createDatabaseBackup();
if ($backup === null) {
    echo 'No existe la base de datos para respaldar.' . PHP_EOL;
    exit(1);
}

echo 'Backup creado: ' . $backup . PHP_EOL;
