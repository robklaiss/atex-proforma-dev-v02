<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
    http_response_code(404);
    exit('Propuesta no encontrada.');
}

$stmt = db()->prepare(
    'SELECT p.*,
            c.empresa AS client_empresa,
            c.email AS client_email
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     WHERE p.public_token = :token
     LIMIT 1'
);
$stmt->execute([':token' => $token]);
$proforma = $stmt->fetch();

if (!$proforma || empty($proforma['pdf_path'])) {
    http_response_code(404);
    exit('Propuesta no encontrada.');
}

if (!proformaCanDownloadFinal($proforma)) {
    http_response_code(409);
    exit(proformaDownloadBlockMessage($proforma));
}

if (proformaIsExpired($proforma)) {
    http_response_code(410);
    exit('Esta proforma ya no se encuentra disponible porque superó su período de validez.');
}

$filename = safeBasename((string) $proforma['pdf_path']);
$path = PROFORMA_STORAGE_PATH . '/' . $filename;
$realDir = realpath(PROFORMA_STORAGE_PATH);
$realPath = realpath($path);

if (!$realDir || !$realPath || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    http_response_code(404);
    exit('PDF no encontrado.');
}

$update = db()->prepare(
    "UPDATE proformas
     SET customer_downloaded_at = COALESCE(NULLIF(customer_downloaded_at, ''), :downloaded_at)
     WHERE id = :id"
);
$update->execute([
    ':downloaded_at' => nowIso(),
    ':id' => (int) $proforma['id'],
]);
recordProformaEvent(
    db(),
    (int) $proforma['id'],
    null,
    'DOWNLOADED',
    'Descarga pública del PDF final.'
);

$downloadName = 'Propuesta_' . preg_replace('/[^A-Za-z0-9_-]/', '_', proformaProjectName($proforma)) . '.pdf';
if ($downloadName === 'Propuesta_.pdf') {
    $downloadName = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $proforma['proforma_number']) . '.pdf';
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
