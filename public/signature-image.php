<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

$userId = max(0, (int) ($_GET['user_id'] ?? 0));
$stmt = db()->prepare('SELECT signature_image FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$path = storedSignatureAbsolutePath((string) $stmt->fetchColumn());

if ($path === null) {
    http_response_code(404);
    exit('Firma no encontrada.');
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
