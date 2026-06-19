<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$path = trim((string) ($argv[1] ?? ''));
if ($path === '') {
    fwrite(STDERR, "Uso: php scripts/import_rental_products.php /ruta/maestro.csv\n");
    exit(1);
}

try {
    $products = readRentalProductCatalogCsv($path);
    $backup = createDatabaseBackup();
    $result = replaceActiveProductsWithRentalCatalog(db(), $products);

    echo 'Productos activos: ' . $result['total'] . PHP_EOL;
    echo 'Productos creados: ' . $result['created'] . PHP_EOL;
    echo 'Productos actualizados: ' . $result['updated'] . PHP_EOL;
    if ($backup !== null) {
        echo 'Backup: ' . basename($backup) . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
