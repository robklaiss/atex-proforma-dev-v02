<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/hubspot_company_import.php';

$path = trim((string) ($argv[1] ?? ''));
if ($path === '') {
    fwrite(STDERR, "Uso: php scripts/import_hubspot_companies.php /ruta/export-hubspot.csv\n");
    exit(1);
}

try {
    $rows = readHubspotCompaniesCsv($path);
    $backup = createDatabaseBackup();
    $result = importHubspotCompanies(db(), $rows);

    echo 'Empresas leídas: ' . count($rows) . PHP_EOL;
    echo 'Empresas creadas: ' . $result['companies_created'] . PHP_EOL;
    echo 'Empresas actualizadas: ' . $result['companies_updated'] . PHP_EOL;
    echo 'Contactos creados: ' . $result['contacts_created'] . PHP_EOL;
    echo 'Contactos reutilizados: ' . $result['contacts_reused'] . PHP_EOL;
    echo 'Correos guardados: ' . $result['emails_saved'] . PHP_EOL;
    echo 'Filas sin contacto importable: ' . $result['rows_without_contact'] . PHP_EOL;
    if ($backup !== null) {
        echo 'Backup: ' . basename($backup) . PHP_EOL;
    }
    foreach ($result['warnings'] as $warning) {
        fwrite(STDERR, 'Advertencia: ' . $warning . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
