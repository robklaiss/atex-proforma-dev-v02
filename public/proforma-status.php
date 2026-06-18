<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$currentUser = currentUser();
$proformaId = (int) ($_POST['id'] ?? 0);
$returnTo = (string) ($_POST['return_to'] ?? '/proformas.php');
if (preg_match('~^/(?:proformas\.php|proforma-preview\.php\?id=\d+)$~', $returnTo) !== 1) {
    $returnTo = '/proformas.php';
}

try {
    verifyCsrf();
    if (!canUpdateCommercialStatus($currentUser)) {
        throw new RuntimeException('No tenés permisos para actualizar el estado comercial.');
    }

    [$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'status_visible');
    $stmt = db()->prepare(
        'SELECT p.id
         FROM proformas p
         JOIN clients c ON c.id = p.client_id
         LEFT JOIN users seller ON seller.id = p.seller_id
         WHERE p.id = :id AND ' . $visibilitySql
    );
    $stmt->execute([':id' => $proformaId] + $visibilityParams);
    if (!$stmt->fetch()) {
        throw new RuntimeException('La proforma seleccionada no existe o no está dentro de tu alcance.');
    }

    createDatabaseBackup();
    $updated = updateProformaCommercialStatus(
        db(),
        $proformaId,
        (string) ($_POST['commercial_status'] ?? 'OPEN'),
        (int) $currentUser['id'],
        (string) ($_POST['commercial_status_notes'] ?? '')
    );
    setFlash('success', 'Estado comercial actualizado a ' . commercialStatusLabel($updated) . '.');
} catch (Throwable $exception) {
    setFlash('error', $exception->getMessage());
}

redirect($returnTo);
