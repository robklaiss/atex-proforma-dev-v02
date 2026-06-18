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
require_once __DIR__ . '/../app/auth.php';

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

function assertThrows(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (Throwable) {
        echo '[OK] ' . $label . PHP_EOL;
        return;
    }

    throw new RuntimeException($label . PHP_EOL . 'Se esperaba una excepción.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'PRAGMA foreign_keys = ON;
     CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         username TEXT NOT NULL,
         role TEXT NOT NULL,
         unit TEXT NOT NULL DEFAULT \'Paraguay\'
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
     CREATE TABLE user_country_units (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         user_id INTEGER NOT NULL,
         country_unit_id INTEGER NOT NULL,
         created_at TEXT NOT NULL,
         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
         FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
         UNIQUE (user_id, country_unit_id)
     );
     CREATE TABLE exchange_rates (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         country_unit_id INTEGER NOT NULL,
         currency_symbol TEXT NOT NULL,
         currency_code TEXT NOT NULL,
         rate_to_usd REAL NOT NULL,
         rate_from_usd REAL NOT NULL CHECK (rate_from_usd > 0),
         is_active INTEGER NOT NULL DEFAULT 1,
         created_by INTEGER NOT NULL,
         created_at TEXT NOT NULL,
         updated_at TEXT NOT NULL,
         FOREIGN KEY (country_unit_id) REFERENCES country_units(id),
         FOREIGN KEY (created_by) REFERENCES users(id)
     );
     CREATE UNIQUE INDEX idx_test_active_rate
         ON exchange_rates(country_unit_id)
         WHERE is_active = 1;
     CREATE TABLE proformas (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         proforma_number TEXT NOT NULL UNIQUE,
         currency_mode TEXT NOT NULL DEFAULT \'USD\',
         currency_code TEXT NOT NULL DEFAULT \'USD\',
         currency_symbol TEXT NOT NULL DEFAULT \'US$\',
         country_unit_id INTEGER,
         exchange_rate_used REAL DEFAULT 1,
         exchange_rate_source TEXT NOT NULL DEFAULT \'GLOBAL\',
         authorization_status TEXT NOT NULL DEFAULT \'NOT_REQUIRED\',
         total REAL NOT NULL DEFAULT 0
     );'
);

seedCountryUnits($pdo);
$pdo->exec(
    "INSERT INTO users (username, role, unit) VALUES
     ('admin', 'admin', 'Paraguay'),
     ('supervisor', 'supervisor', 'Paraguay'),
     ('ejecutivo', 'commercial_executive', 'Paraguay')"
);

$units = [];
foreach (countryUnits($pdo) as $unit) {
    $units[$unit['name']] = $unit;
}

assertSameValue('US$ 100.00', formatMoney(100, 'USD'), 'formatea USD con símbolo y decimales correctos');
assertSameValue('₲', $units['Paraguay']['currency_symbol'], 'usa el símbolo exacto de Paraguay');
assertSameValue('RD$', $units['República Dominicana']['currency_symbol'], 'usa el símbolo exacto de República Dominicana');
assertSameValue('COL$', $units['Colombia']['currency_symbol'], 'usa el símbolo exacto de Colombia');
assertSameValue('฿', $units['Panamá']['currency_symbol'], 'usa el símbolo exacto de Panamá');

syncUserCountryUnits($pdo, 3, [
    (int) $units['Paraguay']['id'],
    (int) $units['Colombia']['id'],
]);
assertSameValue(2, count(userCountryUnits($pdo, 3)), 'un usuario puede pertenecer a múltiples unidades país');

assertSameValue(true, canManageExchangeRates(['role' => 'admin']), 'Administrador puede gestionar cambio de divisas');
assertSameValue(true, canManageExchangeRates(['role' => 'supervisor']), 'Supervisor puede gestionar cambio de divisas');
assertSameValue(false, canManageExchangeRates(['role' => 'commercial_executive']), 'Ejecutivo Comercial no puede gestionar cambio de divisas');
assertSameValue(false, canManageExchangeRates(['role' => 'assistant']), 'Asistente Comercial no puede gestionar cambio de divisas');

$paraguayId = (int) $units['Paraguay']['id'];
$panamaSnapshot = resolveProformaCurrency($pdo, (int) $units['Panamá']['id'], 'LOCAL');
assertSameValue('PENDING', $panamaSnapshot['authorization_status'], 'moneda local sin cotización queda PENDING');
assertSameValue(null, $panamaSnapshot['exchange_rate_used'], 'moneda local sin cotización no inventa un tipo de cambio');

assertThrows(
    static fn (): array => saveExchangeRate($pdo, $paraguayId, 0, 1),
    'rechaza tipos de cambio menores o iguales a cero'
);

saveExchangeRate($pdo, $paraguayId, 7500, 2);
$usdSnapshot = resolveProformaCurrency($pdo, $paraguayId, 'USD');
assertSameValue('NOT_REQUIRED', $usdSnapshot['authorization_status'], 'proforma USD queda NOT_REQUIRED');
assertSameValue(1.0, $usdSnapshot['exchange_rate_used'], 'proforma USD usa tipo de cambio 1');

$localSnapshot = resolveProformaCurrency($pdo, $paraguayId, 'LOCAL');
assertSameValue('PENDING', $localSnapshot['authorization_status'], 'proforma local queda PENDING');
assertSameValue(7500.0, $localSnapshot['exchange_rate_used'], 'proforma local guarda el tipo de cambio vigente');
assertSameValue(
    '₲ 750.000',
    formatProformaMoney(100, $localSnapshot),
    'convierte USD a moneda local usando el snapshot'
);

$pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, currency_mode, currency_code, currency_symbol, country_unit_id,
      exchange_rate_used, exchange_rate_source, authorization_status, total)
     VALUES
     (:number, :currency_mode, :currency_code, :currency_symbol, :country_unit_id,
      :exchange_rate_used, :exchange_rate_source, :authorization_status, :total)'
)->execute([
    ':number' => 'EPI-20260617-001',
    ':currency_mode' => $localSnapshot['currency_mode'],
    ':currency_code' => $localSnapshot['currency_code'],
    ':currency_symbol' => $localSnapshot['currency_symbol'],
    ':country_unit_id' => $localSnapshot['country_unit_id'],
    ':exchange_rate_used' => $localSnapshot['exchange_rate_used'],
    ':exchange_rate_source' => $localSnapshot['exchange_rate_source'],
    ':authorization_status' => $localSnapshot['authorization_status'],
    ':total' => 100,
]);

saveExchangeRate($pdo, $paraguayId, 7600, 1);
$activeRateCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM exchange_rates WHERE country_unit_id = ' . $paraguayId . ' AND is_active = 1'
)->fetchColumn();
assertSameValue(1, $activeRateCount, 'mantiene un solo tipo de cambio activo por unidad país');

$stored = $pdo->query("SELECT * FROM proformas WHERE proforma_number = 'EPI-20260617-001'")->fetch();
assertSameValue('EPI-20260617-001', $stored['proforma_number'], 'no renumera proformas históricas');
assertSameValue(7500.0, (float) $stored['exchange_rate_used'], 'no recalcula el snapshot de una proforma emitida');
assertSameValue('₲ 750.000', formatProformaMoney((float) $stored['total'], $stored), 'mantiene el importe histórico convertido');

echo PHP_EOL . 'Pruebas de monedas, unidades y cambio de divisas completadas.' . PHP_EOL;
