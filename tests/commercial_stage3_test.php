<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return (bool) $stmt->fetchColumn();
}

require_once __DIR__ . '/../app/currency.php';
require_once __DIR__ . '/../app/commercial.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . PHP_EOL .
            'Esperado: ' . var_export($expected, true) . PHP_EOL .
            'Obtenido: ' . var_export($actual, true)
        );
    }

    echo '[OK] ' . $label . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'PRAGMA foreign_keys = ON;
     CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         username TEXT NOT NULL
     );
     CREATE TABLE country_units (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         name TEXT NOT NULL UNIQUE,
         currency_symbol TEXT NOT NULL,
         currency_code TEXT NOT NULL,
         is_active INTEGER NOT NULL DEFAULT 1,
         created_at TEXT NOT NULL,
         updated_at TEXT NOT NULL
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
         created_by INTEGER,
         UNIQUE (ruc_normalized)
     );
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
         ON contact_emails(contact_id)
         WHERE is_primary = 1;
     CREATE TABLE proformas (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         proforma_number TEXT NOT NULL UNIQUE,
         company_id INTEGER,
         client_id INTEGER NOT NULL,
         contact_id INTEGER,
         client_contact_id INTEGER,
         contact_email_id INTEGER,
         company_name_snapshot TEXT NOT NULL DEFAULT \'\',
         contact_name_snapshot TEXT NOT NULL DEFAULT \'\',
         contact_email_snapshot TEXT NOT NULL DEFAULT \'\',
         validity_days INTEGER NOT NULL DEFAULT 10,
         expires_at TEXT,
         expiration_date TEXT NOT NULL
     );'
);

seedCountryUnits($pdo);
$paraguay = findCountryUnitByName($pdo, 'Paraguay');
$colombia = findCountryUnitByName($pdo, 'Colombia');

$company = resolveCompanyFromQuote(
    $pdo,
    ' 7.524.653 - 8 ',
    'Constructora XYZ',
    'Asunción',
    '0981000000',
    'ventas@xyz.test',
    (int) $paraguay['id'],
    1
);
assertSameValue('75246538', (string) $company['ruc_normalized'], 'normaliza RUC con puntos, guiones y espacios');
assertSameValue('Constructora XYZ', (string) $company['empresa'], 'crea empresa nueva desde el RUC');

$found = findCompanyByRuc($pdo, '7524653-8');
assertSameValue((int) $company['id'], (int) $found['id'], 'busca empresa existente por RUC normalizado');

$sameCompany = resolveCompanyFromQuote(
    $pdo,
    '7.524.653-8',
    'Nombre duplicado',
    '',
    '',
    '',
    (int) $paraguay['id'],
    1
);
assertSameValue((int) $company['id'], (int) $sameCompany['id'], 'evita duplicar empresa con el mismo RUC');

$secondCompany = resolveCompanyFromQuote(
    $pdo,
    '900.555.444-1',
    'Empresa Colombia',
    'Bogotá',
    '',
    'contacto@colombia.test',
    (int) $colombia['id'],
    1
);

$contact = createOrReuseContactForCompany(
    $pdo,
    (int) $company['id'],
    'Juan Pérez',
    '0981222333',
    'Contratista',
    'juan@empresa.test',
    'juan.secundario@empresa.test'
);
assertSameValue('Juan Pérez', (string) $contact['full_name'], 'crea contacto nuevo asociado a empresa');

$emails = contactEmails($pdo, (int) $contact['id']);
assertSameValue(2, count($emails), 'crea email principal y secundario');
assertSameValue(1, (int) $emails[0]['is_primary'], 'marca el primer email como principal');

saveContactEmail($pdo, (int) $contact['id'], 'juan.secundario@empresa.test', true);
$primaryCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM contact_emails
     WHERE contact_id = ' . (int) $contact['id'] . ' AND is_primary = 1'
)->fetchColumn();
assertSameValue(1, $primaryCount, 'mantiene un solo email principal por contacto');

associateContactWithCompany($pdo, (int) $secondCompany['id'], (int) $contact['id']);
$companyCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM company_contacts WHERE contact_id = ' . (int) $contact['id']
)->fetchColumn();
assertSameValue(2, $companyCount, 'asocia un contacto existente a una segunda empresa');

$reused = createOrReuseContactForCompany(
    $pdo,
    (int) $secondCompany['id'],
    'Juan Pérez',
    '',
    '',
    'juan.secundario@empresa.test'
);
assertSameValue((int) $contact['id'], (int) $reused['id'], 'evita duplicar contacto por nombre y email principal');

$pdo->prepare(
    'DELETE FROM company_contacts
     WHERE company_id = :company_id AND contact_id = :contact_id'
)->execute([
    ':company_id' => (int) $company['id'],
    ':contact_id' => (int) $contact['id'],
]);
$legacyContacts = commercialContactsForCompany($pdo, (int) $company['id']);
assertSameValue(1, count($legacyContacts), 'recupera contactos asociados por la relación histórica con el cliente');
$legacySelection = selectContactForCompany($pdo, (int) $company['id'], (int) $contact['id']);
assertSameValue((int) $contact['id'], (int) $legacySelection['contact']['id'], 'permite seleccionar un contacto histórico de la empresa');

$primaryEmail = findPrimaryContactEmail($pdo, (int) $contact['id']);
$createdAt = '2026-06-17 12:00:00';
foreach ([10, 20, 30, 45] as $days) {
    $fields = buildProformaCommercialFields($company, $contact, $primaryEmail, $days, $createdAt);
    assertSameValue($days, $fields['validity_days'], 'acepta validez de ' . $days . ' días');
    assertSameValue(
        (new DateTimeImmutable($createdAt))->modify('+' . $days . ' days')->format('Y-m-d H:i:s'),
        $fields['expires_at'],
        'calcula expires_at correctamente para ' . $days . ' días'
    );
}

$historicalNumber = '002-000001';
$pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, company_id, client_id, contact_id, client_contact_id, contact_email_id,
      company_name_snapshot, contact_name_snapshot, contact_email_snapshot,
      validity_days, expires_at, expiration_date)
     VALUES
     (:number, NULL, :client_id, NULL, NULL, NULL, \'\', \'\', \'\', 10, NULL, :expiration_date)'
)->execute([
    ':number' => $historicalNumber,
    ':client_id' => (int) $company['id'],
    ':expiration_date' => '2026-05-25',
]);

$fields = buildProformaCommercialFields($company, $contact, $primaryEmail, 10, $createdAt);
$pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, company_id, client_id, contact_id, client_contact_id, contact_email_id,
      company_name_snapshot, contact_name_snapshot, contact_email_snapshot,
      validity_days, expires_at, expiration_date)
     VALUES
     (:number, :company_id, :client_id, :contact_id, :client_contact_id, :contact_email_id,
      :company_name_snapshot, :contact_name_snapshot, :contact_email_snapshot,
      :validity_days, :expires_at, :expiration_date)'
)->execute([
    ':number' => 'XYZ-20260617-001',
    ':company_id' => $fields['company_id'],
    ':client_id' => $fields['client_id'],
    ':contact_id' => $fields['contact_id'],
    ':client_contact_id' => $fields['client_contact_id'],
    ':contact_email_id' => $fields['contact_email_id'],
    ':company_name_snapshot' => $fields['company_name_snapshot'],
    ':contact_name_snapshot' => $fields['contact_name_snapshot'],
    ':contact_email_snapshot' => $fields['contact_email_snapshot'],
    ':validity_days' => $fields['validity_days'],
    ':expires_at' => $fields['expires_at'],
    ':expiration_date' => $fields['expiration_date'],
]);

$stored = $pdo->query("SELECT * FROM proformas WHERE proforma_number = 'XYZ-20260617-001'")->fetch();
assertSameValue((int) $company['id'], (int) $stored['company_id'], 'guarda company_id en la proforma');
assertSameValue((int) $contact['id'], (int) $stored['contact_id'], 'guarda contact_id en la proforma');
assertSameValue((int) $primaryEmail['id'], (int) $stored['contact_email_id'], 'guarda contact_email_id en la proforma');
assertSameValue('Constructora XYZ', $stored['company_name_snapshot'], 'guarda company_name_snapshot');
assertSameValue('Juan Pérez', $stored['contact_name_snapshot'], 'guarda contact_name_snapshot');
assertSameValue((string) $primaryEmail['email'], $stored['contact_email_snapshot'], 'guarda contact_email_snapshot');
assertSameValue('2026-06-27 12:00:00', $stored['expires_at'], 'persiste expires_at calculado');

$historicalStored = $pdo->query('SELECT proforma_number FROM proformas WHERE id = 1')->fetchColumn();
assertSameValue($historicalNumber, $historicalStored, 'la proforma histórica no se renumera');

echo PHP_EOL . 'Pruebas comerciales de Etapa 3 completadas.' . PHP_EOL;
