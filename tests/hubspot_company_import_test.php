<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/hubspot_company_import.php';

function hubspotImportAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': esperado ' . var_export($expected, true) . ', recibido ' . var_export($actual, true)
        );
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE country_units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        empresa TEXT NOT NULL,
        ruc TEXT,
        ruc_normalized TEXT,
        direccion TEXT,
        email TEXT,
        telefono TEXT,
        pais TEXT NOT NULL,
        country_unit_id INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        created_by INTEGER
    );
    CREATE UNIQUE INDEX idx_test_clients_ruc
        ON clients(ruc_normalized) WHERE ruc_normalized IS NOT NULL AND ruc_normalized <> \'\';
    CREATE TABLE client_contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        full_name TEXT NOT NULL,
        normalized_name TEXT NOT NULL DEFAULT \'\',
        email TEXT,
        phone TEXT,
        position TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
    CREATE TABLE company_contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL,
        contact_id INTEGER NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        UNIQUE (company_id, contact_id)
    );
    CREATE TABLE contact_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        normalized_email TEXT NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        UNIQUE (contact_id, normalized_email)
    );
    CREATE UNIQUE INDEX idx_test_contact_primary
        ON contact_emails(contact_id) WHERE is_primary = 1;
    INSERT INTO country_units (name, is_active) VALUES (\'Paraguay\', 1);'
);

$rows = [
    [
        'Nombre de la empresa' => 'Empresa Uno',
        'NIT/RUC/RCN' => '12345.0',
        'Dirección' => 'Calle Principal',
        'Ciudad' => 'Asunción',
        'País/región' => 'Paraguay',
        'Número de teléfono' => '+595111',
        'Associated Contact' => 'Ana Pérez (ana@example.com)',
        '_line' => 3,
    ],
    [
        'Nombre de la empresa' => 'Empresa Dos',
        'NIT/RUC/RCN' => '',
        'Dirección' => '',
        'Ciudad' => 'Luque',
        'País/región' => 'Paraguay',
        'Número de teléfono' => '',
        'Associated Contact' => 'Carlos Gómez',
        '_line' => 4,
    ],
    [
        'Nombre de la empresa' => 'Empresa Tres',
        'NIT/RUC/RCN' => '',
        'Dirección' => '',
        'Ciudad' => '',
        'País/región' => 'Paraguay',
        'Número de teléfono' => '',
        'Associated Contact' => 'sin-nombre@example.com;Alexis Chaparro (alexis@example.com)',
        '_line' => 5,
    ],
];

$first = importHubspotCompanies($pdo, $rows);
hubspotImportAssertSame(3, $first['companies_created'], 'crea las empresas');
hubspotImportAssertSame(3, $first['contacts_created'], 'crea los contactos con nombre');
hubspotImportAssertSame(2, $first['emails_saved'], 'guarda correos en campos de contacto');
hubspotImportAssertSame(1, count($first['warnings']), 'reporta el correo huérfano');
hubspotImportAssertSame('12345', (string) $pdo->query('SELECT ruc FROM clients WHERE id = 1')->fetchColumn(), 'limpia RUC numérico');
hubspotImportAssertSame(
    'Calle Principal, Asunción',
    (string) $pdo->query('SELECT direccion FROM clients WHERE id = 1')->fetchColumn(),
    'combina dirección y ciudad'
);
hubspotImportAssertSame(
    '',
    (string) $pdo->query('SELECT email FROM clients WHERE id = 1')->fetchColumn(),
    'no guarda correo del contacto en la empresa'
);
hubspotImportAssertSame(
    'ana@example.com',
    (string) $pdo->query('SELECT email FROM client_contacts WHERE full_name = \'Ana Pérez\'')->fetchColumn(),
    'guarda el correo principal en el contacto'
);

$second = importHubspotCompanies($pdo, $rows);
hubspotImportAssertSame(0, $second['companies_created'], 'la segunda ejecución no duplica empresas');
hubspotImportAssertSame(3, $second['companies_updated'], 'la segunda ejecución actualiza empresas');
hubspotImportAssertSame(0, $second['contacts_created'], 'la segunda ejecución no duplica contactos');
hubspotImportAssertSame(3, $second['contacts_reused'], 'la segunda ejecución reutiliza contactos');
hubspotImportAssertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(), 'mantiene empresas únicas');
hubspotImportAssertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM client_contacts')->fetchColumn(), 'mantiene contactos únicos');

echo "hubspot_company_import_test: OK\n";
