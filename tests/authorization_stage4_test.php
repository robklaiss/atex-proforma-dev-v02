<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);
    return (bool) $stmt->fetchColumn();
}

require_once __DIR__ . '/../app/projects.php';
require_once __DIR__ . '/../app/currency.php';
require_once __DIR__ . '/../app/authorization.php';
require_once __DIR__ . '/../app/proforma_delivery.php';

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

function assertTrueValue(bool $actual, string $label): void
{
    assertSameValue(true, $actual, $label);
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
$pdo->exec((string) file_get_contents(__DIR__ . '/../migrations/init.sql'));
seedCountryUnits($pdo);

$insertUser = $pdo->prepare(
    'INSERT INTO users
     (username, password_hash, role, first_name, last_name, email, phone, unit,
      reports_to_id, commercial_position, signature_image, created_at)
     VALUES
     (:username, :password_hash, :role, :first_name, :last_name, :email, :phone, :unit,
      :reports_to_id, :commercial_position, :signature_image, :created_at)'
);
$users = [
    ['executive', 'commercial_executive', 'Eva', 'Ejecutiva', 'eva@atex.test', '0981000001', null, 'Ejecutiva comercial', 'signatures/eva.png'],
    ['supervisor', 'supervisor', 'Sonia', 'Supervisora', 'sonia@atex.test', '0981000002', null, 'Supervisora comercial', ''],
    ['manager', 'manager', 'Gabriel', 'Gerente', 'gabriel@atex.test', '0981000003', null, 'Gerente comercial', ''],
];
$userIds = [];
foreach ($users as $user) {
    $insertUser->execute([
        ':username' => $user[0],
        ':password_hash' => password_hash('stage4-test', PASSWORD_DEFAULT),
        ':role' => $user[1],
        ':first_name' => $user[2],
        ':last_name' => $user[3],
        ':email' => $user[4],
        ':phone' => $user[5],
        ':unit' => 'Paraguay',
        ':reports_to_id' => $user[6],
        ':commercial_position' => $user[7],
        ':signature_image' => $user[8],
        ':created_at' => '2026-06-17 12:00:00',
    ]);
    $userIds[$user[0]] = (int) $pdo->lastInsertId();
}

$paraguay = findCountryUnitByName($pdo, 'Paraguay');
$paraguayId = (int) $paraguay['id'];
foreach ($userIds as $userId) {
    syncUserCountryUnits($pdo, $userId, [$paraguayId]);
}
saveExchangeRate($pdo, $paraguayId, 7500, $userIds['supervisor']);

$pdo->prepare(
    'INSERT INTO clients
     (empresa, ruc, ruc_normalized, direccion, email, telefono, pais, country_unit_id,
      created_at, updated_at, created_by)
     VALUES
     (\'Empresa Etapa 4\', \'80012345-6\', \'800123456\', \'Asunción\', \'cliente@stage4.test\',
      \'021000000\', \'Paraguay\', :country_unit_id, :created_at, :updated_at, :created_by)'
)->execute([
    ':country_unit_id' => $paraguayId,
    ':created_at' => '2026-06-17 12:00:00',
    ':updated_at' => '2026-06-17 12:00:00',
    ':created_by' => $userIds['executive'],
]);
$clientId = (int) $pdo->lastInsertId();
$project = resolveProject($pdo, 'Edificio Puerto Ibiza');

$insertProforma = static function (
    PDO $pdo,
    string $number,
    int $projectId,
    int $sequence,
    int $clientId,
    int $sellerId,
    array $currency,
    ?int $parentId = null,
    int $version = 1,
    string $expiresAt = '2026-06-27 12:00:00'
): int {
    $signatureStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $signatureStmt->execute([':id' => $sellerId]);
    $signer = $signatureStmt->fetch() ?: [];
    $signature = userSignature($signer);
    $stmt = $pdo->prepare(
        'INSERT INTO proformas
         (proforma_number, project_id, parent_proforma_id, version_number, project_sequence,
          client_id, company_id, company_name_snapshot, contact_name_snapshot, contact_email_snapshot,
          project_name, emission_date, expiration_date, validity_days, expires_at,
          currency_code, currency_mode, currency_symbol, country_unit_id, exchange_rate_used,
          exchange_rate_source, authorization_status, subtotal, tax_total, total,
          seller_id, signer_role, signer_name, signer_position, signer_email, signer_phone, signer_unit,
          signer_signature_image, created_by, created_at)
         VALUES
         (:number, :project_id, :parent_id, :version_number, :project_sequence,
          :client_id, :company_id, \'Empresa Etapa 4\', \'Cliente Test\', \'cliente@stage4.test\',
          \'Edificio Puerto Ibiza\', \'2026-06-17\', \'2026-06-27\', 10, :expires_at,
          :currency_code, :currency_mode, :currency_symbol, :country_unit_id, :exchange_rate_used,
          :exchange_rate_source, :authorization_status, 100, 10, 110,
          :seller_id, :signer_role, :signer_name, :signer_position, :signer_email, :signer_phone, :signer_unit,
          :signer_signature_image, :created_by, \'2026-06-17 12:00:00\')'
    );
    $stmt->execute([
        ':number' => $number,
        ':project_id' => $projectId,
        ':parent_id' => $parentId,
        ':version_number' => $version,
        ':project_sequence' => $sequence,
        ':client_id' => $clientId,
        ':company_id' => $clientId,
        ':expires_at' => $expiresAt,
        ':currency_code' => $currency['currency_code'],
        ':currency_mode' => $currency['currency_mode'],
        ':currency_symbol' => $currency['currency_symbol'],
        ':country_unit_id' => $currency['country_unit_id'],
        ':exchange_rate_used' => $currency['exchange_rate_used'],
        ':exchange_rate_source' => $currency['exchange_rate_source'],
        ':authorization_status' => $currency['authorization_status'],
        ':seller_id' => $sellerId,
        ':signer_role' => (string) ($signer['role'] ?? ''),
        ':signer_name' => $signature['name'],
        ':signer_position' => $signature['position'],
        ':signer_email' => $signature['email'],
        ':signer_phone' => $signature['phone'],
        ':signer_unit' => $signature['unit'],
        ':signer_signature_image' => $signature['signature_image'],
        ':created_by' => $sellerId,
    ]);

    return (int) $pdo->lastInsertId();
};

$usdSnapshot = resolveProformaCurrency($pdo, $paraguayId, 'USD');
$usdId = $insertProforma(
    $pdo,
    'EPI-20260617-001',
    (int) $project['id'],
    1,
    $clientId,
    $userIds['executive'],
    $usdSnapshot
);
$usd = $pdo->query('SELECT * FROM proformas WHERE id = ' . $usdId)->fetch();
assertSameValue('NOT_REQUIRED', $usd['authorization_status'], 'proforma USD queda NOT_REQUIRED');
assertTrueValue(proformaCanDownloadFinal($usd), 'proforma USD permite descarga final');
assertThrows(
    static fn (): array => requestProformaAuthorization($pdo, $usdId, $userIds['executive'], $userIds['supervisor']),
    'proforma USD no inicia autorización'
);

$localSnapshot = resolveProformaCurrency($pdo, $paraguayId, 'LOCAL');
$managerLocalSnapshot = $localSnapshot;
$managerLocalSnapshot['authorization_status'] = proformaAuthorizationStatusForSigner(
    $localSnapshot,
    ['role' => 'manager']
);
$managerSignedId = $insertProforma(
    $pdo,
    'EPI-20260617-002',
    (int) $project['id'],
    2,
    $clientId,
    $userIds['manager'],
    $managerLocalSnapshot
);
$managerSigned = $pdo->query('SELECT * FROM proformas WHERE id = ' . $managerSignedId)->fetch();
assertSameValue('NOT_REQUIRED', $managerSigned['authorization_status'], 'proforma LOCAL firmada por Gerente no requiere autorización');
assertTrueValue(proformaCanDownloadFinal($managerSigned), 'proforma LOCAL firmada por Gerente permite descarga final');
assertThrows(
    static fn (): array => requestProformaAuthorization(
        $pdo,
        $managerSignedId,
        $userIds['manager'],
        $userIds['supervisor']
    ),
    'proforma firmada por Gerente no inicia autorización'
);

$localSupervisorId = $insertProforma(
    $pdo,
    'EPI-20260617-003',
    (int) $project['id'],
    3,
    $clientId,
    $userIds['executive'],
    $localSnapshot
);
$localSupervisor = $pdo->query('SELECT * FROM proformas WHERE id = ' . $localSupervisorId)->fetch();
assertSameValue('PENDING', $localSupervisor['authorization_status'], 'proforma LOCAL queda PENDING');
assertSameValue(false, proformaCanDownloadFinal($localSupervisor), 'proforma LOCAL pendiente bloquea descarga final');

$supervisorRequest = requestProformaAuthorization(
    $pdo,
    $localSupervisorId,
    $userIds['executive'],
    $userIds['supervisor']
);
assertSameValue('supervisor', $supervisorRequest['requested_role'], 'solicita autorización a Supervisor');
assertSameValue(
    1,
    pendingProformaAuthorizationCount($pdo, $userIds['supervisor']),
    'contador muestra la autorización pendiente del supervisor'
);
assertThrows(
    static fn (): array => requestProformaAuthorization(
        $pdo,
        $localSupervisorId,
        $userIds['executive'],
        $userIds['supervisor']
    ),
    'no duplica solicitud pendiente'
);

$approvedGlobal = approveProformaAuthorization(
    $pdo,
    (int) $supervisorRequest['id'],
    $userIds['supervisor']
);
assertSameValue('APPROVED', $approvedGlobal['status'], 'Supervisor aprueba con cambio general');
assertSameValue(
    0,
    pendingProformaAuthorizationCount($pdo, $userIds['supervisor']),
    'contador se limpia después de decidir la autorización'
);
$storedGlobal = $pdo->query('SELECT * FROM proformas WHERE id = ' . $localSupervisorId)->fetch();
assertSameValue('APPROVED', $storedGlobal['authorization_status'], 'proforma aprobada queda APPROVED');
assertSameValue('GLOBAL', $storedGlobal['exchange_rate_source'], 'aprobación general marca GLOBAL');
assertSameValue(7500.0, (float) $storedGlobal['exchange_rate_used'], 'aprobación general usa cambio vigente');
assertTrueValue(proformaCanDownloadFinal($storedGlobal), 'proforma LOCAL aprobada permite descarga final');

$localManagerId = $insertProforma(
    $pdo,
    'EPI-20260617-004',
    (int) $project['id'],
    4,
    $clientId,
    $userIds['executive'],
    $localSnapshot
);
$managerRequest = requestProformaAuthorization(
    $pdo,
    $localManagerId,
    $userIds['executive'],
    $userIds['manager']
);
assertSameValue('manager', $managerRequest['requested_role'], 'solicita autorización a Gerente');
$globalBeforeSpecial = (float) findActiveExchangeRate($pdo, $paraguayId)['rate_from_usd'];
$approvedSpecial = approveProformaAuthorization(
    $pdo,
    (int) $managerRequest['id'],
    $userIds['manager'],
    7300
);
assertSameValue('APPROVED', $approvedSpecial['status'], 'Gerente aprueba con cambio especial');
$storedSpecial = $pdo->query('SELECT * FROM proformas WHERE id = ' . $localManagerId)->fetch();
assertSameValue('SPECIAL', $storedSpecial['exchange_rate_source'], 'cambio especial marca SPECIAL');
assertSameValue(7300.0, (float) $storedSpecial['exchange_rate_used'], 'cambio especial se guarda solo en la proforma');
assertSameValue(
    $globalBeforeSpecial,
    (float) findActiveExchangeRate($pdo, $paraguayId)['rate_from_usd'],
    'cambio especial no modifica el cambio general'
);

$localRejectedId = $insertProforma(
    $pdo,
    'EPI-20260617-005',
    (int) $project['id'],
    5,
    $clientId,
    $userIds['executive'],
    $localSnapshot
);
$rejectionRequest = requestProformaAuthorization(
    $pdo,
    $localRejectedId,
    $userIds['executive'],
    $userIds['supervisor']
);
$rejected = rejectProformaAuthorization(
    $pdo,
    (int) $rejectionRequest['id'],
    $userIds['supervisor'],
    'Revisar la condición comercial antes de autorizar.'
);
assertSameValue('REJECTED', $rejected['status'], 'proforma rechazada queda REJECTED');
assertSameValue('Revisar la condición comercial antes de autorizar.', $rejected['notes'], 'rechazo guarda comentario');
$storedRejected = $pdo->query('SELECT * FROM proformas WHERE id = ' . $localRejectedId)->fetch();
assertSameValue(false, proformaCanDownloadFinal($storedRejected), 'proforma rechazada bloquea descarga final');

$secondAllocation = allocateProjectProformaNumber($pdo, (int) $project['id'], '2026-06-18');
$versionNumber = nextProformaVersionNumber($pdo, $usdId);
$versionId = $insertProforma(
    $pdo,
    $secondAllocation['number'],
    (int) $project['id'],
    (int) $secondAllocation['sequence'],
    $clientId,
    $userIds['executive'],
    $usdSnapshot,
    $usdId,
    $versionNumber
);
$originalAfterEdit = $pdo->query('SELECT * FROM proformas WHERE id = ' . $usdId)->fetch();
$version = $pdo->query('SELECT * FROM proformas WHERE id = ' . $versionId)->fetch();
assertSameValue('EPI-20260617-001', $originalAfterEdit['proforma_number'], 'edición no sobrescribe la original');
assertSameValue($usdId, (int) $version['parent_proforma_id'], 'edición mantiene parent_proforma_id');
assertSameValue(2, (int) $version['version_number'], 'edición crea la siguiente versión');
assertTrueValue((int) $version['project_sequence'] > (int) $originalAfterEdit['project_sequence'], 'edición aumenta secuencial del proyecto');
assertSameValue(3, nextProformaVersionNumber($pdo, $usdId), 'editar una versión antigua calcula la siguiente versión real');

$historicalProject = resolveProject($pdo, 'Proyecto Histórico Etapa 4');
$historicalId = $insertProforma(
    $pdo,
    '002-000001',
    (int) $historicalProject['id'],
    1,
    $clientId,
    $userIds['executive'],
    $usdSnapshot
);
assertSameValue(
    '002-000001',
    $pdo->query('SELECT proforma_number FROM proformas WHERE id = ' . $historicalId)->fetchColumn(),
    'proformas históricas no se renumeran'
);

$signatureStored = $pdo->query(
    'SELECT signer_name, signer_position, signer_email, signer_phone, signer_signature_image
     FROM proformas WHERE id = ' . $usdId
)->fetch();
assertSameValue('Eva Ejecutiva', $signatureStored['signer_name'], 'snapshot guarda nombre comercial');
assertSameValue('Ejecutiva comercial', $signatureStored['signer_position'], 'snapshot guarda cargo comercial');
assertSameValue('eva@atex.test', $signatureStored['signer_email'], 'snapshot guarda email comercial');
assertSameValue('0981000001', $signatureStored['signer_phone'], 'snapshot guarda teléfono comercial');
assertSameValue('signatures/eva.png', $signatureStored['signer_signature_image'], 'snapshot guarda referencia de firma');

assertTrueValue(
    proformaIsExpired(['expires_at' => '2026-06-16 12:00:00', 'expiration_date' => '2026-06-16']),
    'link vencido de Etapa 3 sigue detectándose'
);
assertTrueValue(
    (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() >= 6,
    'solicitudes y decisiones generan notificaciones internas'
);
assertTrueValue(
    (int) $pdo->query('SELECT COUNT(*) FROM proforma_events')->fetchColumn() >= 6,
    'solicitudes y decisiones registran trazabilidad'
);
assertSameValue('Creado', proformaEventLabel('CREATED'), 'traduce evento de creación');
assertSameValue('Editado por', proformaEventLabel('EDITED_FROM'), 'traduce evento de edición');
assertSameValue('Autorización Solicitada', proformaEventLabel('AUTHORIZATION_REQUESTED'), 'traduce solicitud de autorización');
assertSameValue('Autorización Denegada', proformaEventLabel('AUTHORIZATION_REJECTED'), 'traduce rechazo de autorización');

echo PHP_EOL . 'Pruebas de autorización, acciones, versionado y firma de Etapa 4 completadas.' . PHP_EOL;
