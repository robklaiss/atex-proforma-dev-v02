<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

$currentUser = currentUser();
$id = (int) ($_GET['id'] ?? 0);
[$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'view_visible');
$stmt = db()->prepare(
    'SELECT p.proforma_number, p.pdf_path
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE p.id = :id
       AND ' . $visibilitySql
);
$stmt->execute([':id' => $id] + $visibilityParams);
$proforma = $stmt->fetch();

if (!$proforma || empty($proforma['pdf_path'])) {
    http_response_code(404);
    exit('PDF no encontrado.');
}

$filename = safeBasename((string) $proforma['pdf_path']);
$path = PROFORMA_STORAGE_PATH . '/' . $filename;
$realDir = realpath(PROFORMA_STORAGE_PATH);
$realPath = realpath($path);

if (!$realDir || !$realPath || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    http_response_code(404);
    exit('PDF no encontrado.');
}

$displayName = 'Proforma_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $proforma['proforma_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $displayName . '"');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
