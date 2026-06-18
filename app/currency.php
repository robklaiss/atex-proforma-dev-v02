<?php

declare(strict_types=1);

function countryUnitDefaults(): array
{
    return [
        [
            'name' => 'Paraguay',
            'currency_symbol' => '₲',
            'currency_code' => 'PYG',
        ],
        [
            'name' => 'República Dominicana',
            'currency_symbol' => 'RD$',
            'currency_code' => 'DOP',
        ],
        [
            'name' => 'Colombia',
            'currency_symbol' => 'COL$',
            'currency_code' => 'COP',
        ],
        [
            'name' => 'Panamá',
            'currency_symbol' => '฿',
            'currency_code' => 'PAB',
        ],
    ];
}

function seedCountryUnits(PDO $pdo): void
{
    if (!tableExists($pdo, 'country_units')) {
        return;
    }

    $select = $pdo->prepare('SELECT id FROM country_units WHERE name = :name LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO country_units
         (name, currency_symbol, currency_code, is_active, created_at, updated_at)
         VALUES (:name, :currency_symbol, :currency_code, 1, :created_at, :updated_at)'
    );
    $update = $pdo->prepare(
        'UPDATE country_units
         SET currency_symbol = :currency_symbol,
             currency_code = :currency_code,
             is_active = 1,
             updated_at = :updated_at
         WHERE name = :name'
    );
    $now = nowIso();

    foreach (countryUnitDefaults() as $unit) {
        $select->execute([':name' => $unit['name']]);
        if ($select->fetchColumn()) {
            $update->execute([
                ':currency_symbol' => $unit['currency_symbol'],
                ':currency_code' => $unit['currency_code'],
                ':updated_at' => $now,
                ':name' => $unit['name'],
            ]);
            continue;
        }

        $insert->execute([
            ':name' => $unit['name'],
            ':currency_symbol' => $unit['currency_symbol'],
            ':currency_code' => $unit['currency_code'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function countryUnits(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    return $pdo->query(
        'SELECT id, name, currency_symbol, currency_code, is_active, created_at, updated_at
         FROM country_units
         ' . $where . '
         ORDER BY name COLLATE NOCASE'
    )->fetchAll();
}

function findCountryUnitById(PDO $pdo, int $countryUnitId): ?array
{
    if ($countryUnitId <= 0 || !tableExists($pdo, 'country_units')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, currency_symbol, currency_code, is_active, created_at, updated_at
         FROM country_units
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $countryUnitId]);
    $unit = $stmt->fetch();
    return is_array($unit) ? $unit : null;
}

function findCountryUnitByName(PDO $pdo, string $name): ?array
{
    $name = trim($name);
    if ($name === '' || !tableExists($pdo, 'country_units')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, currency_symbol, currency_code, is_active, created_at, updated_at
         FROM country_units
         WHERE name = :name
         LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $unit = $stmt->fetch();
    return is_array($unit) ? $unit : null;
}

function normalizeCountryUnitIds(array $countryUnitIds): array
{
    $ids = [];
    foreach ($countryUnitIds as $countryUnitId) {
        $id = (int) $countryUnitId;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function validateCountryUnitIds(PDO $pdo, array $countryUnitIds): array
{
    $ids = normalizeCountryUnitIds($countryUnitIds);
    if ($ids === []) {
        throw new RuntimeException('Selecciona al menos una unidad país.');
    }

    $params = [];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $key = ':country_unit_' . $index;
        $params[$key] = $id;
        $placeholders[] = $key;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM country_units
         WHERE is_active = 1
           AND id IN (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($params);
    $validIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    sort($ids);
    sort($validIds);

    if ($ids !== $validIds) {
        throw new RuntimeException('Una de las unidades país seleccionadas no es válida.');
    }

    return $ids;
}

function userCountryUnits(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !tableExists($pdo, 'user_country_units')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT cu.id, cu.name, cu.currency_symbol, cu.currency_code, cu.is_active
         FROM user_country_units ucu
         JOIN country_units cu ON cu.id = ucu.country_unit_id
         WHERE ucu.user_id = :user_id
           AND cu.is_active = 1
         ORDER BY cu.name COLLATE NOCASE'
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}

function userCountryUnitIds(PDO $pdo, int $userId): array
{
    return array_map('intval', array_column(userCountryUnits($pdo, $userId), 'id'));
}

function syncUserCountryUnits(PDO $pdo, int $userId, array $countryUnitIds): void
{
    if ($userId <= 0) {
        throw new RuntimeException('El usuario seleccionado no es válido.');
    }

    $ids = validateCountryUnitIds($pdo, $countryUnitIds);
    $delete = $pdo->prepare('DELETE FROM user_country_units WHERE user_id = :user_id');
    $delete->execute([':user_id' => $userId]);

    $insert = $pdo->prepare(
        'INSERT INTO user_country_units (user_id, country_unit_id, created_at)
         VALUES (:user_id, :country_unit_id, :created_at)'
    );
    $now = nowIso();
    foreach ($ids as $countryUnitId) {
        $insert->execute([
            ':user_id' => $userId,
            ':country_unit_id' => $countryUnitId,
            ':created_at' => $now,
        ]);
    }
}

function availableCountryUnitsForUser(PDO $pdo, ?array $user): array
{
    if ($user === null) {
        return [];
    }
    if (($user['role'] ?? '') === 'admin') {
        return countryUnits($pdo);
    }

    $units = userCountryUnits($pdo, (int) ($user['id'] ?? 0));
    if ($units !== []) {
        return $units;
    }

    $fallback = findCountryUnitByName($pdo, (string) ($user['unit'] ?? defaultCountry()));
    return $fallback ? [$fallback] : [];
}

function findActiveExchangeRate(PDO $pdo, int $countryUnitId): ?array
{
    if ($countryUnitId <= 0 || !tableExists($pdo, 'exchange_rates')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT er.*, cu.name AS country_unit_name
         FROM exchange_rates er
         JOIN country_units cu ON cu.id = er.country_unit_id
         WHERE er.country_unit_id = :country_unit_id
           AND er.is_active = 1
         ORDER BY er.id DESC
         LIMIT 1'
    );
    $stmt->execute([':country_unit_id' => $countryUnitId]);
    $rate = $stmt->fetch();
    return is_array($rate) ? $rate : null;
}

function saveExchangeRate(PDO $pdo, int $countryUnitId, float $rateFromUsd, int $createdBy): array
{
    if (!is_finite($rateFromUsd) || $rateFromUsd <= 0) {
        throw new RuntimeException('El tipo de cambio debe ser mayor a cero.');
    }

    $countryUnit = findCountryUnitById($pdo, $countryUnitId);
    if (!$countryUnit || (int) ($countryUnit['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('La unidad país seleccionada no es válida.');
    }
    if ($createdBy <= 0) {
        throw new RuntimeException('No se pudo identificar al usuario que registra el tipo de cambio.');
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
    }

    try {
        $deactivate = $pdo->prepare(
            'UPDATE exchange_rates
             SET is_active = 0,
                 updated_at = :updated_at
             WHERE country_unit_id = :country_unit_id
               AND is_active = 1'
        );
        $now = nowIso();
        $deactivate->execute([
            ':updated_at' => $now,
            ':country_unit_id' => $countryUnitId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO exchange_rates
             (country_unit_id, currency_symbol, currency_code, rate_to_usd, rate_from_usd,
              is_active, created_by, created_at, updated_at)
             VALUES
             (:country_unit_id, :currency_symbol, :currency_code, :rate_to_usd, :rate_from_usd,
              1, :created_by, :created_at, :updated_at)'
        );
        $insert->execute([
            ':country_unit_id' => $countryUnitId,
            ':currency_symbol' => (string) $countryUnit['currency_symbol'],
            ':currency_code' => (string) $countryUnit['currency_code'],
            ':rate_to_usd' => 1 / $rateFromUsd,
            ':rate_from_usd' => $rateFromUsd,
            ':created_by' => $createdBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $rateId = (int) $pdo->lastInsertId();

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $stmt = $pdo->prepare('SELECT * FROM exchange_rates WHERE id = :id');
    $stmt->execute([':id' => $rateId]);
    return $stmt->fetch() ?: [];
}

function proformaCurrencyModes(): array
{
    return [
        'USD' => 'US$',
        'LOCAL' => 'Moneda local',
    ];
}

function normalizeProformaCurrencyMode(string $currencyMode): string
{
    $currencyMode = strtoupper(trim($currencyMode));
    return array_key_exists($currencyMode, proformaCurrencyModes()) ? $currencyMode : 'USD';
}

function resolveProformaCurrency(PDO $pdo, int $countryUnitId, string $currencyMode): array
{
    $countryUnit = findCountryUnitById($pdo, $countryUnitId);
    if (!$countryUnit || (int) ($countryUnit['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Selecciona una unidad país válida.');
    }

    $currencyMode = normalizeProformaCurrencyMode($currencyMode);
    if ($currencyMode === 'USD') {
        return [
            'currency_mode' => 'USD',
            'currency_code' => 'USD',
            'currency_symbol' => 'US$',
            'country_unit_id' => (int) $countryUnit['id'],
            'exchange_rate_used' => 1.0,
            'exchange_rate_source' => 'GLOBAL',
            'authorization_status' => 'NOT_REQUIRED',
            'country_unit' => $countryUnit,
            'exchange_rate' => null,
        ];
    }

    $exchangeRate = findActiveExchangeRate($pdo, (int) $countryUnit['id']);
    return [
        'currency_mode' => 'LOCAL',
        'currency_code' => (string) $countryUnit['currency_code'],
        'currency_symbol' => (string) $countryUnit['currency_symbol'],
        'country_unit_id' => (int) $countryUnit['id'],
        'exchange_rate_used' => $exchangeRate ? (float) $exchangeRate['rate_from_usd'] : null,
        'exchange_rate_source' => 'GLOBAL',
        'authorization_status' => 'PENDING',
        'country_unit' => $countryUnit,
        'exchange_rate' => $exchangeRate,
    ];
}

function convertUsdAmount(float $amount, ?float $rateFromUsd): ?float
{
    if ($rateFromUsd === null || !is_finite($rateFromUsd) || $rateFromUsd <= 0) {
        return null;
    }

    return round($amount * $rateFromUsd, 2);
}
