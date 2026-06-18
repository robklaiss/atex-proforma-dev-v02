<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function stage6AssertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . PHP_EOL . 'Esperado: ' . var_export($expected, true) . PHP_EOL . 'Obtenido: ' . var_export($actual, true));
    }
    echo '[OK] ' . $label . PHP_EOL;
}

function stage6AssertTrue(bool $actual, string $label): void
{
    stage6AssertSame(true, $actual, $label);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/../migrations/init.sql'));
seedCountryUnits($pdo);

foreach (['admin', 'director', 'manager', 'supervisor'] as $role) {
    stage6AssertTrue(canViewManagementDashboard(['role' => $role]), userRoleLabel($role) . ' puede acceder al dashboard gerencial');
}
foreach (['commercial_executive', 'assistant'] as $role) {
    stage6AssertSame(false, canViewManagementDashboard(['role' => $role]), userRoleLabel($role) . ' no puede acceder al dashboard gerencial');
}
stage6AssertSame(false, userAllowedPath(['role' => 'commercial_executive'], '/indicators.php'), 'Ejecutivo comercial queda bloqueado también por URL directa');
stage6AssertSame(false, userAllowedPath(['role' => 'assistant'], '/indicators.php'), 'Asistente comercial queda bloqueado también por URL directa');
stage6AssertTrue(userAllowedPath(['role' => 'assistant'], '/proforma-new.php'), 'Asistente comercial puede acceder a crear proformas');
stage6AssertTrue(userAllowedPath(['role' => 'assistant'], '/proformas.php'), 'Asistente comercial puede acceder al listado de proformas');
stage6AssertTrue(userAllowedPath(['role' => 'assistant'], '/proforma-preview.php'), 'Asistente comercial puede previsualizar sus proformas');
stage6AssertTrue(canCreateProformas(['role' => 'assistant']), 'Asistente comercial puede emitir proformas');
stage6AssertTrue(canChooseProformaSeller(['role' => 'assistant']), 'Asistente comercial selecciona al vendedor firmante');
[$assistantVisibilitySql, $assistantVisibilityParams] = proformaVisibilityClause(
    ['id' => 31, 'role' => 'assistant'],
    'p',
    'seller',
    'c',
    'assistant_visible'
);
stage6AssertSame(
    'p.created_by = :assistant_visible_created_by',
    $assistantVisibilitySql,
    'Asistente comercial limita el listado a las proformas emitidas por su usuario'
);
stage6AssertSame(
    [':assistant_visible_created_by' => 31],
    $assistantVisibilityParams,
    'Asistente comercial aplica su usuario como emisor visible'
);
foreach (['admin', 'director', 'manager', 'supervisor', 'commercial_executive'] as $role) {
    stage6AssertTrue(canUpdateCommercialStatus(['role' => $role]), userRoleLabel($role) . ' puede actualizar estado comercial');
}
stage6AssertSame(false, canUpdateCommercialStatus(['role' => 'assistant']), 'Asistente comercial no puede actualizar estado comercial');

$insertUser = $pdo->prepare(
    'INSERT INTO users
     (username, password_hash, role, first_name, last_name, unit, created_at)
     VALUES (:username, :password_hash, :role, :first_name, :last_name, :unit, :created_at)'
);
$userDefinitions = [
    'admin' => ['admin', 'Administrador', 'Latam', 'Paraguay'],
    'director' => ['director', 'Directora', 'Latam', 'Paraguay'],
    'manager' => ['manager', 'Gerente', 'Paraguay', 'Paraguay'],
    'supervisor' => ['supervisor', 'Supervisor', 'Paraguay', 'Paraguay'],
    'eva' => ['commercial_executive', 'Eva', 'Ejecutiva', 'Paraguay'],
    'carlos' => ['commercial_executive', 'Carlos', 'Ejecutivo', 'Colombia'],
    'assistant' => ['assistant', 'Ana', 'Asistente', 'Paraguay'],
];
$userIds = [];
foreach ($userDefinitions as $key => [$role, $firstName, $lastName, $unit]) {
    $insertUser->execute([
        ':username' => $key,
        ':password_hash' => 'x',
        ':role' => $role,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':unit' => $unit,
        ':created_at' => '2026-06-18 09:00:00',
    ]);
    $userIds[$key] = (int) $pdo->lastInsertId();
}

$paraguay = findCountryUnitByName($pdo, 'Paraguay');
$colombia = findCountryUnitByName($pdo, 'Colombia');
$paraguayId = (int) $paraguay['id'];
$colombiaId = (int) $colombia['id'];
syncUserCountryUnits($pdo, $userIds['manager'], [$paraguayId]);
syncUserCountryUnits($pdo, $userIds['supervisor'], [$paraguayId]);
syncUserCountryUnits($pdo, $userIds['eva'], [$paraguayId]);
syncUserCountryUnits($pdo, $userIds['carlos'], [$colombiaId]);

$insertClient = $pdo->prepare(
    'INSERT INTO clients
     (empresa, pais, country_unit_id, created_at, updated_at, created_by)
     VALUES (:empresa, :pais, :country_unit_id, :created_at, :updated_at, :created_by)'
);
$clientIds = [];
foreach ([['Paraguay', $paraguayId, $userIds['eva']], ['Colombia', $colombiaId, $userIds['carlos']]] as [$country, $unitId, $creatorId]) {
    $insertClient->execute([
        ':empresa' => 'Cliente ' . $country,
        ':pais' => $country,
        ':country_unit_id' => $unitId,
        ':created_at' => '2026-06-01 09:00:00',
        ':updated_at' => '2026-06-01 09:00:00',
        ':created_by' => $creatorId,
    ]);
    $clientIds[$country] = (int) $pdo->lastInsertId();
}

$pdo->exec(
    "INSERT INTO projects (name, normalized_name, prefix, created_at, updated_at)
     VALUES ('Dashboard Stage 6', 'dashboard stage 6', 'DS6', '2026-06-01 09:00:00', '2026-06-01 09:00:00')"
);
$projectId = (int) $pdo->lastInsertId();
$insertProforma = $pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, project_id, version_number, project_sequence, client_id, company_id,
      company_name_snapshot, project_name, emission_date, expiration_date, currency_code,
      currency_mode, currency_symbol, country_unit_id, exchange_rate_used, authorization_status,
      subtotal, tax_total, total, status, commercial_status, seller_id, signer_name, signer_unit,
      created_by, created_at)
     VALUES
     (:number, :project_id, 1, :sequence, :client_id, :client_id,
      :company_name, \'Dashboard Stage 6\', :emission_date, \'2026-07-01\', :currency_code,
      :currency_mode, :currency_symbol, :country_unit_id, :exchange_rate_used, :authorization_status,
      :total, 0, :total, :legacy_status, :commercial_status, :seller_id, :signer_name, :signer_unit,
      :created_by, :created_at)'
);
$rows = [
    ['001', 'Paraguay', $paraguayId, $userIds['eva'], '2026-06-02', 'OPEN', 100.0, 'USD', 'USD', 'US$', 1.0, 'NOT_REQUIRED'],
    ['002', 'Paraguay', $paraguayId, $userIds['eva'], '2026-06-03', 'WON', 200.0, 'PYG', 'LOCAL', '₲', 7500.0, 'APPROVED'],
    ['003', 'Paraguay', $paraguayId, $userIds['eva'], '2026-06-04', 'LOST', 300.0, 'USD', 'USD', 'US$', 1.0, 'NOT_REQUIRED'],
    ['004', 'Paraguay', $paraguayId, $userIds['eva'], '2026-05-15', 'WON', 400.0, 'USD', 'USD', 'US$', 1.0, 'NOT_REQUIRED'],
    ['005', 'Colombia', $colombiaId, $userIds['carlos'], '2026-06-05', 'WON', 500.0, 'COP', 'LOCAL', 'COL$', 4100.0, 'APPROVED'],
    ['006', 'Colombia', $colombiaId, $userIds['carlos'], '2026-06-06', 'CANCELLED', 600.0, 'USD', 'USD', 'US$', 1.0, 'NOT_REQUIRED'],
    ['007', 'Colombia', $colombiaId, $userIds['admin'], '2026-06-07', 'LOST', 700.0, 'USD', 'USD', 'US$', 1.0, 'NOT_REQUIRED'],
];
foreach ($rows as $index => [$suffix, $country, $unitId, $sellerId, $date, $commercialStatus, $total, $currencyCode, $currencyMode, $currencySymbol, $rate, $authorizationStatus]) {
    $insertProforma->execute([
        ':number' => 'DS6-20260618-' . $suffix,
        ':project_id' => $projectId,
        ':sequence' => $index + 1,
        ':client_id' => $clientIds[$country],
        ':company_name' => 'Cliente ' . $country,
        ':emission_date' => $date,
        ':currency_code' => $currencyCode,
        ':currency_mode' => $currencyMode,
        ':currency_symbol' => $currencySymbol,
        ':country_unit_id' => $unitId,
        ':exchange_rate_used' => $rate,
        ':authorization_status' => $authorizationStatus,
        ':total' => $total,
        ':legacy_status' => $commercialStatus === 'WON' ? 'venta_ganada' : 'emitida',
        ':commercial_status' => $commercialStatus,
        ':seller_id' => $sellerId,
        ':signer_name' => $country === 'Paraguay' ? 'Eva Ejecutiva' : 'Carlos Ejecutivo',
        ':signer_unit' => $country,
        ':created_by' => $sellerId,
        ':created_at' => $date . ' 09:00:00',
    ]);
}

$allUnits = countryUnits($pdo);
$allSellers = dashboardSellerOptions($pdo, $allUnits);
$filters = dashboardBuildFilters([
    'date_range' => 'custom',
    'date_from' => '2026-06-01',
    'date_to' => '2026-06-30',
], $allUnits, $allSellers);
$metrics = dashboardMetrics($pdo, $filters);
$byUnit = array_column($metrics['units'], null, 'unit');
stage6AssertSame(3, $byUnit['Paraguay']['emitted'], 'dashboard calcula emitidos por país');
stage6AssertSame(1, $byUnit['Paraguay']['won'], 'dashboard calcula ganados por país');
stage6AssertSame(1, $byUnit['Paraguay']['pending'], 'dashboard calcula pendientes por país');
stage6AssertSame(2, $byUnit['Paraguay']['closed'], 'dashboard calcula cerrados por país');
stage6AssertSame(1, $byUnit['Paraguay']['rejected'], 'dashboard calcula rechazados por país');
stage6AssertSame(33.33333333333333, $byUnit['Paraguay']['effectiveness'], 'dashboard calcula efectividad correctamente');
stage6AssertSame(600.0, $byUnit['Paraguay']['emitted_usd'], 'montos Latam se calculan en USD base');
stage6AssertSame(200.0, $byUnit['Paraguay']['won_usd'], 'proforma local usa total USD guardado');
stage6AssertSame(3, $byUnit['Colombia']['emitted'], 'dashboard agrupa por país aunque el firmante pertenezca a otra unidad');

$evaRows = array_values(array_filter($metrics['sellers'], static fn (array $row): bool => $row['seller'] === 'Eva Ejecutiva'));
stage6AssertSame(1, count($evaRows), 'dashboard agrupa por ejecutivo');
stage6AssertSame(3, $evaRows[0]['emitted'], 'dashboard calcula emitidos por ejecutivo');

$mayFilters = dashboardBuildFilters([
    'date_range' => 'custom',
    'date_from' => '2026-05-01',
    'date_to' => '2026-05-31',
], $allUnits, $allSellers);
stage6AssertSame(1, dashboardTotals(dashboardMetrics($pdo, $mayFilters)['units'])['emitted'], 'filtro por fecha funciona');

$countryFilters = $filters;
$countryFilters['unit'] = 'Colombia';
stage6AssertSame(3, dashboardTotals(dashboardMetrics($pdo, $countryFilters)['units'])['emitted'], 'filtro por país funciona');

$sellerFilters = $filters;
$sellerFilters['seller_id'] = $userIds['carlos'];
stage6AssertSame(2, dashboardTotals(dashboardMetrics($pdo, $sellerFilters)['units'])['emitted'], 'filtro por ejecutivo funciona');

$emptyFilters = $filters;
$emptyFilters['from'] = '2024-01-01';
$emptyFilters['to'] = '2024-01-31';
stage6AssertSame(0.0, dashboardTotals(dashboardMetrics($pdo, $emptyFilters)['units'])['effectiveness'], 'emitidos en cero no rompe efectividad');

$wonId = (int) $pdo->query("SELECT id FROM proformas WHERE proforma_number = 'DS6-20260618-001'")->fetchColumn();
$authorizationBefore = (string) $pdo->query('SELECT authorization_status FROM proformas WHERE id = ' . $wonId)->fetchColumn();
$won = updateProformaCommercialStatus($pdo, $wonId, 'WON', $userIds['eva'], 'Cierre confirmado');
stage6AssertSame('WON', $won['commercial_status'], 'cambio de estado comercial a WON');
stage6AssertSame($authorizationBefore, $won['authorization_status'], 'WON no altera authorization_status');
$lost = updateProformaCommercialStatus($pdo, $wonId, 'LOST', $userIds['eva'], 'Cliente desistió');
stage6AssertSame('LOST', $lost['commercial_status'], 'cambio de estado comercial a LOST');
stage6AssertSame($authorizationBefore, $lost['authorization_status'], 'LOST no altera authorization_status');

saveExchangeRate($pdo, $paraguayId, 9000, $userIds['manager']);
$localStored = $pdo->query("SELECT total, exchange_rate_used FROM proformas WHERE proforma_number = 'DS6-20260618-002'")->fetch();
stage6AssertSame(200.0, (float) $localStored['total'], 'cambio vigente no recalcula monto histórico');
stage6AssertSame(7500.0, (float) $localStored['exchange_rate_used'], 'cambio vigente no altera snapshot histórico');

$manager = $pdo->query('SELECT * FROM users WHERE id = ' . $userIds['manager'])->fetch();
$managerUnits = dashboardAllowedUnits($pdo, $manager);
stage6AssertSame(['Paraguay'], array_column($managerUnits, 'name'), 'Gerente ve solo sus unidades país');

echo PHP_EOL . 'Pruebas del dashboard gerencial Etapa 6 completadas.' . PHP_EOL;
