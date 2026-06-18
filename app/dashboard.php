<?php

declare(strict_types=1);

function dashboardDateRange(string $preset, string $customFrom = '', string $customTo = '', ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $preset = in_array($preset, ['current_month', 'previous_month', 'last_30_days', 'last_90_days', 'current_year', 'custom'], true)
        ? $preset
        : 'current_month';

    if ($preset === 'custom' && isValidDate($customFrom) && isValidDate($customTo) && $customFrom <= $customTo) {
        return ['preset' => $preset, 'from' => $customFrom, 'to' => $customTo];
    }

    return match ($preset) {
        'previous_month' => ['preset' => $preset, 'from' => $today->modify('first day of previous month')->format('Y-m-d'), 'to' => $today->modify('last day of previous month')->format('Y-m-d')],
        'last_30_days' => ['preset' => $preset, 'from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        'last_90_days' => ['preset' => $preset, 'from' => $today->modify('-89 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        'current_year' => ['preset' => $preset, 'from' => $today->format('Y-01-01'), 'to' => $today->format('Y-12-31')],
        default => ['preset' => 'current_month', 'from' => $today->format('Y-m-01'), 'to' => $today->modify('last day of this month')->format('Y-m-d')],
    };
}

function dashboardAllowedUnits(PDO $pdo, array $user): array
{
    if (in_array((string) ($user['role'] ?? ''), ['admin', 'director'], true)) {
        return countryUnits($pdo);
    }
    return availableCountryUnitsForUser($pdo, $user);
}

function dashboardSellerOptions(PDO $pdo, array $allowedUnits): array
{
    $unitNames = array_values(array_filter(array_map(
        static fn (array $unit): string => trim((string) ($unit['name'] ?? '')),
        $allowedUnits
    )));
    if ($unitNames === []) {
        return [];
    }

    $params = [];
    $unitPlaceholders = [];
    foreach ($unitNames as $index => $name) {
        $key = ':unit_' . $index;
        $params[$key] = $name;
        $unitPlaceholders[] = $key;
    }
    $rolePlaceholders = [];
    foreach (salesSignerRoles() as $index => $role) {
        $key = ':role_' . $index;
        $params[$key] = $role;
        $rolePlaceholders[] = $key;
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.id, u.username, u.first_name, u.last_name, u.unit
         FROM users u
         LEFT JOIN user_country_units ucu ON ucu.user_id = u.id
         LEFT JOIN country_units cu ON cu.id = ucu.country_unit_id
         WHERE u.role IN (' . implode(', ', $rolePlaceholders) . ')
           AND (u.unit IN (' . implode(', ', $unitPlaceholders) . ')
                OR cu.name IN (' . implode(', ', $unitPlaceholders) . '))
         ORDER BY u.first_name COLLATE NOCASE, u.last_name COLLATE NOCASE, u.username COLLATE NOCASE'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dashboardBuildFilters(array $input, array $allowedUnits, array $allowedSellers): array
{
    $range = dashboardDateRange(
        (string) ($input['date_range'] ?? 'current_month'),
        trim((string) ($input['date_from'] ?? '')),
        trim((string) ($input['date_to'] ?? ''))
    );
    $allowedUnitNames = array_column($allowedUnits, 'name');
    $unit = trim((string) ($input['unit'] ?? ''));
    if ($unit !== '' && !in_array($unit, $allowedUnitNames, true)) {
        $unit = '';
    }
    $sellerId = max(0, (int) ($input['seller_id'] ?? 0));
    if ($sellerId > 0 && !in_array($sellerId, array_map('intval', array_column($allowedSellers, 'id')), true)) {
        $sellerId = 0;
    }
    $status = strtoupper(trim((string) ($input['commercial_status'] ?? '')));
    if ($status !== '' && !array_key_exists($status, commercialStatusOptions())) {
        $status = '';
    }
    $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
    if ($currency !== '' && !array_key_exists($currency, currencyOptions())) {
        $currency = '';
    }

    return $range + [
        'unit' => $unit,
        'seller_id' => $sellerId,
        'commercial_status' => $status,
        'currency' => $currency,
        'allowed_unit_names' => $allowedUnitNames,
    ];
}

function dashboardWhere(array $filters, array &$params): string
{
    $unitExpression = "COALESCE(NULLIF(cu.name, ''), NULLIF(p.signer_unit, ''), NULLIF(seller.unit, ''), c.pais)";
    $where = ['p.emission_date >= :date_from', 'p.emission_date <= :date_to'];
    $params = [':date_from' => $filters['from'], ':date_to' => $filters['to']];

    $allowedUnits = $filters['allowed_unit_names'] ?? [];
    if ($allowedUnits === []) {
        $where[] = '1 = 0';
    } else {
        $placeholders = [];
        foreach ($allowedUnits as $index => $unitName) {
            $key = ':allowed_unit_' . $index;
            $params[$key] = $unitName;
            $placeholders[] = $key;
        }
        $where[] = $unitExpression . ' IN (' . implode(', ', $placeholders) . ')';
    }
    if (($filters['unit'] ?? '') !== '') {
        $where[] = $unitExpression . ' = :selected_unit';
        $params[':selected_unit'] = $filters['unit'];
    }
    if ((int) ($filters['seller_id'] ?? 0) > 0) {
        $where[] = 'CAST(COALESCE(p.seller_id, p.created_by) AS INTEGER) = CAST(:seller_id AS INTEGER)';
        $params[':seller_id'] = (int) $filters['seller_id'];
    }
    if (($filters['commercial_status'] ?? '') !== '') {
        $where[] = "COALESCE(NULLIF(p.commercial_status, ''), CASE WHEN p.status = 'venta_ganada' THEN 'WON' ELSE 'OPEN' END) = :commercial_status";
        $params[':commercial_status'] = $filters['commercial_status'];
    }
    if (($filters['currency'] ?? '') !== '') {
        $where[] = 'p.currency_code = :currency';
        $params[':currency'] = $filters['currency'];
    }
    return implode(' AND ', $where);
}

function dashboardMetrics(PDO $pdo, array $filters): array
{
    $params = [];
    $where = dashboardWhere($filters, $params);
    $status = "COALESCE(NULLIF(p.commercial_status, ''), CASE WHEN p.status = 'venta_ganada' THEN 'WON' ELSE 'OPEN' END)";
    $unit = "COALESCE(NULLIF(cu.name, ''), NULLIF(p.signer_unit, ''), NULLIF(seller.unit, ''), c.pais)";
    $baseSql =
        ' FROM proformas p
          JOIN clients c ON c.id = p.client_id
          LEFT JOIN users seller ON seller.id = p.seller_id
          LEFT JOIN country_units cu ON cu.id = COALESCE(p.country_unit_id, c.country_unit_id)
          WHERE ' . $where;

    $unitStmt = $pdo->prepare(
        'SELECT ' . $unit . ' AS unit, COUNT(*) AS emitted,
                SUM(CASE WHEN ' . $status . " = 'WON' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN " . $status . " IN ('WON', 'LOST', 'CANCELLED') THEN 1 ELSE 0 END) AS closed,
                SUM(CASE WHEN " . $status . " = 'LOST' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN " . $status . " = 'OPEN' THEN 1 ELSE 0 END) AS pending,
                COALESCE(SUM(p.total), 0) AS emitted_usd,
                COALESCE(SUM(CASE WHEN " . $status . " = 'WON' THEN p.total ELSE 0 END), 0) AS won_usd" .
        $baseSql . ' GROUP BY ' . $unit . ' ORDER BY unit COLLATE NOCASE'
    );
    $unitStmt->execute($params);
    $units = dashboardNormalizeRows($unitStmt->fetchAll(), 'unit');

    $sellerStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(seller.first_name || ' ' || seller.last_name), ''), seller.username, p.signer_name, 'Sin ejecutivo') AS seller,
                COALESCE(p.seller_id, p.created_by) AS seller_id, " . $unit . ' AS unit, COUNT(*) AS emitted,
                SUM(CASE WHEN ' . $status . " = 'WON' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN " . $status . " IN ('WON', 'LOST', 'CANCELLED') THEN 1 ELSE 0 END) AS closed,
                SUM(CASE WHEN " . $status . " = 'OPEN' THEN 1 ELSE 0 END) AS pending,
                COALESCE(SUM(p.total), 0) AS emitted_usd,
                COALESCE(SUM(CASE WHEN " . $status . " = 'WON' THEN p.total ELSE 0 END), 0) AS won_usd" .
        $baseSql . ' GROUP BY COALESCE(p.seller_id, p.created_by),
                     COALESCE(NULLIF(TRIM(seller.first_name || \' \' || seller.last_name), \'\'), seller.username, p.signer_name, \'Sin ejecutivo\'),
                     ' . $unit . '
                     ORDER BY unit COLLATE NOCASE, seller COLLATE NOCASE'
    );
    $sellerStmt->execute($params);
    $sellers = dashboardNormalizeRows($sellerStmt->fetchAll(), 'seller');

    $evolutionStmt = $pdo->prepare(
        "SELECT strftime('%Y-%m', p.emission_date) AS period, " . $unit . ' AS unit, COUNT(*) AS emitted' .
        $baseSql . " GROUP BY strftime('%Y-%m', p.emission_date), " . $unit . ' ORDER BY period, unit COLLATE NOCASE'
    );
    $evolutionStmt->execute($params);

    return ['units' => $units, 'sellers' => $sellers, 'evolution' => $evolutionStmt->fetchAll()];
}

function dashboardNormalizeRows(array $rows, string $labelKey): array
{
    foreach ($rows as &$row) {
        foreach (['emitted', 'won', 'closed', 'rejected', 'pending'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['emitted_usd'] = (float) ($row['emitted_usd'] ?? 0);
        $row['won_usd'] = (float) ($row['won_usd'] ?? 0);
        $row['effectiveness'] = (int) ($row['emitted'] ?? 0) > 0 ? ((int) $row['won'] / (int) $row['emitted']) * 100 : 0.0;
        $row[$labelKey] = trim((string) ($row[$labelKey] ?? '')) ?: 'Sin unidad';
    }
    unset($row);
    return $rows;
}

function dashboardTotals(array $rows): array
{
    $totals = ['emitted' => 0, 'won' => 0, 'closed' => 0, 'rejected' => 0, 'pending' => 0, 'emitted_usd' => 0.0, 'won_usd' => 0.0];
    foreach ($rows as $row) {
        foreach (['emitted', 'won', 'closed', 'rejected', 'pending'] as $key) {
            $totals[$key] += (int) ($row[$key] ?? 0);
        }
        $totals['emitted_usd'] += (float) ($row['emitted_usd'] ?? 0);
        $totals['won_usd'] += (float) ($row['won_usd'] ?? 0);
    }
    $totals['effectiveness'] = $totals['emitted'] > 0 ? ($totals['won'] / $totals['emitted']) * 100 : 0.0;
    return $totals;
}
