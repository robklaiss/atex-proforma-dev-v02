<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/pdf.php';

function stage5AssertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . PHP_EOL . 'Esperado: ' . var_export($expected, true) . PHP_EOL . 'Obtenido: ' . var_export($actual, true));
    }
    echo '[OK] ' . $label . PHP_EOL;
}

function stage5AssertTrue(bool $actual, string $label): void
{
    stage5AssertSame(true, $actual, $label);
}

function pdfPageCount(string $path): int
{
    $raw = (string) file_get_contents($path);
    if (preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+(\d+)/s', $raw, $matches) !== 1) {
        throw new RuntimeException('No se pudo leer la cantidad de páginas del PDF.');
    }
    return (int) $matches[1];
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(__DIR__ . '/../migrations/init.sql'));
seedDefaultProformaDisclaimers($pdo);

foreach (['supervisor', 'manager', 'director', 'admin'] as $role) {
    stage5AssertTrue(canManageDisclaimers(['role' => $role]), userRoleLabel($role) . ' puede administrar disclaimers');
}
foreach (['commercial_executive', 'assistant'] as $role) {
    stage5AssertSame(false, canManageDisclaimers(['role' => $role]), userRoleLabel($role) . ' no puede administrar disclaimers');
}

$pdo->exec(
    "INSERT INTO users
     (username, password_hash, role, first_name, last_name, email, phone, unit, commercial_position, signature_image, created_at)
     VALUES ('stage5', 'x', 'supervisor', 'Sara', 'Supervisora', 'sara@atex.test', '0981000000', 'Paraguay', 'Supervisora', '', '2026-06-18 10:00:00')"
);
$userId = (int) $pdo->lastInsertId();
$createdId = createProformaDisclaimer($pdo, [
    'title' => '<b>Seguridad</b>',
    'body' => '<script>alert(1)</script>Aplican condiciones de seguridad.',
    'is_active' => true,
    'is_default' => true,
    'sort_order' => 5,
], $userId);
$created = $pdo->query('SELECT * FROM proforma_disclaimers WHERE id = ' . $createdId)->fetch();
stage5AssertSame('Seguridad', $created['title'], 'crear disclaimer sanitiza HTML');
stage5AssertSame('Aplican condiciones de seguridad.', $created['body'], 'crear disclaimer elimina contenido ejecutable');

updateProformaDisclaimer($pdo, $createdId, [
    'title' => 'Seguridad actualizada',
    'body' => 'Texto actualizado.',
    'is_active' => false,
    'is_default' => true,
    'sort_order' => 7,
], $userId);
$updated = $pdo->query('SELECT * FROM proforma_disclaimers WHERE id = ' . $createdId)->fetch();
stage5AssertSame('Seguridad actualizada', $updated['title'], 'editar disclaimer');
stage5AssertSame(0, (int) $updated['is_active'], 'desactivar disclaimer');
$countBeforeReseed = (int) $pdo->query('SELECT COUNT(*) FROM proforma_disclaimers')->fetchColumn();
seedDefaultProformaDisclaimers($pdo);
stage5AssertSame($countBeforeReseed, (int) $pdo->query('SELECT COUNT(*) FROM proforma_disclaimers')->fetchColumn(), 'no duplica disclaimers base en instalaciones existentes');

$pdo->exec(
    "INSERT INTO clients
     (empresa, ruc, ruc_normalized, direccion, email, telefono, pais, created_at, updated_at, created_by)
     VALUES ('Cliente Etapa 5', '80000000-1', '800000001', 'Asunción', 'cliente@atex.test', '021000000', 'Paraguay', '2026-06-18 10:00:00', '2026-06-18 10:00:00', $userId)"
);
$clientId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO projects (name, normalized_name, prefix, created_at, updated_at)
     VALUES ('Proyecto Notas', 'proyecto notas', 'PN', '2026-06-18 10:00:00', '2026-06-18 10:00:00')"
);
$projectId = (int) $pdo->lastInsertId();

$insertProforma = $pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, project_id, version_number, project_sequence, client_id, company_id,
      company_name_snapshot, project_name, emission_date, expiration_date, validity_days, expires_at,
      commercial_conditions, subtotal, tax_total, total, seller_id, signer_name, signer_position,
      signer_email, signer_phone, signer_unit, created_by, created_at)
     VALUES
     (:number, :project_id, :version_number, :sequence, :client_id, :client_id,
      \'Cliente Etapa 5\', \'Proyecto Notas\', \'2026-06-18\', \'2026-06-28\', 10, :expires_at,
      :observations, 100, 10, 110, :seller_id, \'Sara Supervisora\', \'Supervisora\',
      \'sara@atex.test\', \'0981000000\', \'Paraguay\', :created_by, \'2026-06-18 10:00:00\')'
);
$insertProforma->execute([
    ':number' => 'PN-20260618-001',
    ':project_id' => $projectId,
    ':version_number' => 1,
    ':sequence' => 1,
    ':client_id' => $clientId,
    ':expires_at' => '2026-06-28 23:59:59',
    ':observations' => "Observación corta.\nCon salto de línea.",
    ':seller_id' => $userId,
    ':created_by' => $userId,
]);
$firstId = (int) $pdo->lastInsertId();
$firstSnapshot = snapshotDefaultProformaDisclaimers($pdo, $firstId);
stage5AssertTrue(count($firstSnapshot) >= 4, 'proforma nueva guarda disclaimers activos por defecto');
$originalBody = (string) $firstSnapshot[0]['body_snapshot'];

$globalId = (int) $firstSnapshot[0]['disclaimer_id'];
$pdo->exec("UPDATE proforma_disclaimers SET body = 'Texto global modificado' WHERE id = $globalId");
$historicalSnapshot = loadProformaDisclaimerSnapshots($pdo, $firstId);
stage5AssertSame($originalBody, $historicalSnapshot[0]['body_snapshot'], 'editar disclaimer global no modifica snapshot histórico');

$insertProforma->execute([
    ':number' => 'PN-20260618-002',
    ':project_id' => $projectId,
    ':version_number' => 2,
    ':sequence' => 2,
    ':client_id' => $clientId,
    ':expires_at' => '2026-06-28 23:59:59',
    ':observations' => 'Nueva versión.',
    ':seller_id' => $userId,
    ':created_by' => $userId,
]);
$secondId = (int) $pdo->lastInsertId();
$secondSnapshot = snapshotDefaultProformaDisclaimers($pdo, $secondId);
stage5AssertSame('Texto global modificado', $secondSnapshot[0]['body_snapshot'], 'nueva versión toma disclaimers globales actuales');

$item = [
    'description' => 'Servicio de prueba',
    'quantity' => 1,
    'rental_days' => 1,
    'unit_price' => 100,
    'subtotal' => 100,
    'tax_amount' => 10,
    'total' => 110,
];
$baseProforma = [
    'proforma_number' => 'PN-20260618-001',
    'project_name' => 'Proyecto Notas',
    'emission_date' => '2026-06-18',
    'expiration_date' => '2026-06-28',
    'commercial_conditions' => "Observación corta.\nCon salto de línea.",
    'disclaimers' => $historicalSnapshot,
    'currency_code' => 'USD',
    'currency_mode' => 'USD',
    'currency_symbol' => 'US$',
    'exchange_rate_used' => 1,
    'authorization_status' => 'NOT_REQUIRED',
    'format_type' => 'detallado',
    'subtotal' => 100,
    'discount_percent' => 0,
    'discount_amount' => 0,
    'tax_total' => 10,
    'total' => 110,
    'signer_name' => 'Sara Supervisora',
    'signer_position' => 'Supervisora',
    'signer_email' => 'sara@atex.test',
    'signer_phone' => '0981000000',
    'signer_unit' => 'Paraguay',
    'signer_signature_image' => '',
];
$client = ['empresa' => 'Cliente Etapa 5', 'ruc' => '80000000-1', 'direccion' => 'Asunción', 'telefono' => '021000000'];
$taxSummary = [['label' => 'IVA 10%']];
$pdfOutputDir = ROOT_PATH . '/tmp/pdfs';
if (!is_dir($pdfOutputDir)) {
    mkdir($pdfOutputDir, 0775, true);
}
$shortPdf = $pdfOutputDir . '/atex-stage5-short.pdf';
generateProformaPdf($baseProforma, $client, [$item], $taxSummary, $shortPdf);
stage5AssertSame(1, pdfPageCount($shortPdf), 'observaciones cortas no fuerzan una segunda hoja');
$shortRaw = (string) file_get_contents($shortPdf);
stage5AssertTrue(str_contains($shortRaw, 'Observaci'), 'observaciones cortas aparecen en PDF');
stage5AssertTrue(str_contains($shortRaw, 'Notas y disclaimers'), 'disclaimers aparecen en PDF');

$longProforma = $baseProforma;
$longProforma['commercial_conditions'] = str_repeat(
    "Detalle técnico extenso que debe conservarse completo y continuar sin invadir el pie de página.\n",
    180
) . 'FIN_OBSERVACIONES_LARGAS';
$longPdf = $pdfOutputDir . '/atex-stage5-long.pdf';
generateProformaPdf($longProforma, $client, [$item], $taxSummary, $longPdf);
stage5AssertTrue(pdfPageCount($longPdf) > 1, 'observaciones largas generan PDF multipágina');
$longRaw = (string) file_get_contents($longPdf);
stage5AssertTrue(str_contains($longRaw, 'FIN_OBSERVACIONES_LARGAS'), 'observaciones largas no se cortan');
stage5AssertTrue(str_contains($longRaw, 'Proyecto: Proyecto Notas'), 'hoja adicional identifica el proyecto');

stage5AssertTrue(proformaIsExpired(['expires_at' => '2020-01-01 00:00:00']), 'proforma vencida conserva estado de vencimiento');
stage5AssertSame(false, proformaCanDownloadFinal(['currency_mode' => 'LOCAL', 'authorization_status' => 'PENDING']), 'proforma local pendiente bloquea descarga');
stage5AssertTrue(proformaCanDownloadFinal(['currency_mode' => 'LOCAL', 'authorization_status' => 'APPROVED']), 'proforma local aprobada permite descarga');

if (getenv('STAGE5_KEEP_PDFS') !== '1') {
    @unlink($shortPdf);
    @unlink($longPdf);
}
echo PHP_EOL . 'Pruebas de notas, disclaimers y PDF multipágina completadas.' . PHP_EOL;
