<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

$currentUser = currentUser();
$id = max(0, (int) ($_GET['id'] ?? 0));
[$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'download_visible');
$stmt = db()->prepare(
    'SELECT p.*
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE p.id = :id
       AND ' . $visibilitySql . '
     LIMIT 1'
);
$stmt->execute([':id' => $id] + $visibilityParams);
$proforma = $stmt->fetch();

if (!$proforma || empty($proforma['pdf_path'])) {
    http_response_code(404);
    exit('PDF no encontrado.');
}
if (!proformaCanDownloadFinal($proforma)) {
    http_response_code(409);
    exit(proformaDownloadBlockMessage($proforma));
}

$filename = safeBasename((string) $proforma['pdf_path']);
$path = PROFORMA_STORAGE_PATH . '/' . $filename;
$realDir = realpath(PROFORMA_STORAGE_PATH);
$realPath = realpath($path);

if (!$realDir || !$realPath || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    http_response_code(404);
    exit('PDF no encontrado.');
}

recordProformaEvent(
    db(),
    (int) $proforma['id'],
    (int) ($currentUser['id'] ?? 0),
    'DOWNLOADED',
    'Descarga interna del PDF final.'
);

$displayName = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $proforma['proforma_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $displayName . '"');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
