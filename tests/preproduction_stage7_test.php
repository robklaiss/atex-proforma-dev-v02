<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function stage7Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

$roles = [
    'admin',
    'director',
    'manager',
    'supervisor',
    'commercial_executive',
    'assistant',
];
$expected = [
    'admin' => [true, true, true, true, true, true, true],
    'director' => [true, true, true, false, true, true, true],
    'manager' => [true, true, true, true, true, true, true],
    'supervisor' => [true, true, true, true, true, true, true],
    'commercial_executive' => [false, false, false, true, false, true, true],
    'assistant' => [false, false, false, true, false, false, true],
];

foreach ($roles as $role) {
    $user = ['role' => $role];
    $actual = [
        canManageExchangeRates($user),
        canManageDisclaimers($user),
        canViewManagementDashboard($user),
        canCreateProformas($user),
        canDecideProformaAuthorization($user),
        canUpdateCommercialStatus($user),
        userAllowedPath($user, '/download-proforma.php'),
    ];
    stage7Assert($actual === $expected[$role], userRoleLabel($role) . ' coincide con la matriz de permisos');
}

$historicalExpected = [
    '002-000001' => ['USD', 'USD', 7524000.0, 1.0, 'NOT_REQUIRED', '2026-05-25 23:59:59', 'proformas/proforma_002_000001.pdf'],
    '002-000002' => ['USD', 'USD', 3135000.0, 1.0, 'NOT_REQUIRED', '2026-05-25 23:59:59', 'proformas/proforma_002_000002.pdf'],
    '002-000003' => ['USD', 'USD', 0.14, 1.0, 'NOT_REQUIRED', '2026-05-25 23:59:59', 'proformas/proforma_002_000003.pdf'],
    '002-000004' => ['USD', 'USD', 148.8, 1.0, 'NOT_REQUIRED', '2026-05-25 23:59:59', 'proformas/proforma_002_000004.pdf'],
];
$stmt = db()->prepare(
    'SELECT proforma_number, currency_code, currency_mode, total, exchange_rate_used,
            authorization_status, expires_at, pdf_path
     FROM proformas
     WHERE proforma_number = :number'
);
foreach ($historicalExpected as $number => $expectedRow) {
    $stmt->execute([':number' => $number]);
    $row = $stmt->fetch();
    stage7Assert(is_array($row), $number . ' sigue presente');
    $actual = [
        (string) $row['currency_code'],
        (string) $row['currency_mode'],
        (float) $row['total'],
        (float) $row['exchange_rate_used'],
        (string) $row['authorization_status'],
        (string) $row['expires_at'],
        (string) $row['pdf_path'],
    ];
    stage7Assert($actual === $expectedRow, $number . ' conserva moneda, monto, cambio, autorización, vencimiento y PDF');
}

$exampleValues = [];
foreach (file(ROOT_PATH . '/.env.example', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$name, $value] = array_map('trim', explode('=', $line, 2));
    $exampleValues[$name] = $value;
}
foreach (['INITIAL_ADMIN_PASSWORD', 'SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'] as $secretKey) {
    stage7Assert(($exampleValues[$secretKey] ?? '') === '', '.env.example mantiene vacío ' . $secretKey);
}
stage7Assert(ini_get('session.use_strict_mode') === '1', 'sesiones usan modo estricto');
stage7Assert(ini_get('session.use_only_cookies') === '1', 'sesiones usan solo cookies');

echo PHP_EOL . 'Pruebas de estabilización pre-producción completadas.' . PHP_EOL;
