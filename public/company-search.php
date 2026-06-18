<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$currentUser = currentUser();
if (!canCreateProformas($currentUser)) {
    http_response_code(403);
    echo json_encode(['found' => false, 'error' => 'Sin permisos para buscar empresas.']);
    exit;
}

$ruc = trim((string) ($_GET['ruc'] ?? ''));
$normalizedRuc = normalizeRuc($ruc);
if ($normalizedRuc === '') {
    echo json_encode([
        'found' => false,
        'normalized_ruc' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$company = findCompanyByRuc(db(), $ruc);
if (!$company) {
    echo json_encode([
        'found' => false,
        'normalized_ruc' => $normalizedRuc,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$contacts = commercialContactsForCompany(db(), (int) $company['id']);
$contactPayload = array_map(
    static fn (array $contact): array => [
        'id' => (int) $contact['id'],
        'full_name' => (string) $contact['full_name'],
        'phone' => (string) ($contact['phone'] ?? ''),
        'position' => (string) ($contact['position'] ?? ''),
        'is_primary' => (int) ($contact['company_primary'] ?? 0) === 1,
        'emails' => array_map(
            static fn (array $email): array => [
                'id' => (int) $email['id'],
                'email' => (string) $email['email'],
                'is_primary' => (int) $email['is_primary'] === 1,
            ],
            $contact['emails'] ?? []
        ),
    ],
    $contacts
);

echo json_encode([
    'found' => true,
    'normalized_ruc' => $normalizedRuc,
    'company' => [
        'id' => (int) $company['id'],
        'ruc' => (string) ($company['ruc'] ?? ''),
        'name' => (string) $company['empresa'],
        'address' => (string) ($company['direccion'] ?? ''),
        'phone' => (string) ($company['telefono'] ?? ''),
        'email' => (string) ($company['email'] ?? ''),
        'country' => (string) ($company['pais'] ?? ''),
        'country_unit_id' => (int) ($company['country_unit_id'] ?? 0),
    ],
    'contacts' => $contactPayload,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
