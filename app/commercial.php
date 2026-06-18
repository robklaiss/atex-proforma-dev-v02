<?php

declare(strict_types=1);

function commercialValidityOptions(): array
{
    return [
        10 => '10 días',
        20 => '20 días',
        30 => '30 días',
        45 => '45 días',
    ];
}

function validateValidityDays(int $validityDays): int
{
    if (!array_key_exists($validityDays, commercialValidityOptions())) {
        throw new RuntimeException('Selecciona una validez de 10, 20, 30 o 45 días.');
    }

    return $validityDays;
}

function calculateProformaExpiresAt(string $createdAt, int $validityDays): string
{
    validateValidityDays($validityDays);
    $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAt);
    if (!$created || $created->format('Y-m-d H:i:s') !== $createdAt) {
        throw new RuntimeException('La fecha de creación de la proforma no es válida.');
    }

    return $created->modify('+' . $validityDays . ' days')->format('Y-m-d H:i:s');
}

function normalizeRuc(string $ruc): string
{
    $ascii = strtoupper(projectNameAscii(trim($ruc)));
    return preg_replace('/[^A-Z0-9]/', '', $ascii) ?? '';
}

function normalizeContactName(string $name): string
{
    return normalizeProjectName($name);
}

function normalizeContactEmail(string $email): string
{
    return strtolower(trim($email));
}

function findCompanyById(PDO $pdo, int $companyId): ?array
{
    if ($companyId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $companyId]);
    $company = $stmt->fetch();
    return is_array($company) ? $company : null;
}

function findCompanyByRuc(PDO $pdo, string $ruc): ?array
{
    $normalizedRuc = normalizeRuc($ruc);
    if ($normalizedRuc === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM clients
         WHERE ruc_normalized = :ruc_normalized
         LIMIT 1'
    );
    $stmt->execute([':ruc_normalized' => $normalizedRuc]);
    $company = $stmt->fetch();
    return is_array($company) ? $company : null;
}

function resolveCompanyFromQuote(
    PDO $pdo,
    string $ruc,
    string $name,
    string $address,
    string $phone,
    string $email,
    int $countryUnitId,
    int $createdBy
): array {
    $ruc = trim($ruc);
    $normalizedRuc = normalizeRuc($ruc);
    if ($normalizedRuc === '') {
        throw new RuntimeException('El RUC de la empresa es obligatorio.');
    }

    $existing = findCompanyByRuc($pdo, $ruc);
    if ($existing) {
        return $existing;
    }

    $name = normalizeProjectDisplayName($name);
    $address = trim($address);
    $phone = trim($phone);
    $email = normalizeContactEmail($email);
    if ($name === '') {
        throw new RuntimeException('Completa el nombre de la empresa.');
    }
    if (textLength($name) > 180) {
        throw new RuntimeException('El nombre de la empresa no puede superar 180 caracteres.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El email de la empresa no tiene un formato válido.');
    }

    $countryUnit = function_exists('findCountryUnitById') ? findCountryUnitById($pdo, $countryUnitId) : null;
    if (!$countryUnit) {
        throw new RuntimeException('Selecciona una unidad país válida para la empresa.');
    }

    $now = nowIso();
    $insert = $pdo->prepare(
        'INSERT INTO clients
         (empresa, ruc, ruc_normalized, direccion, email, telefono, pais, country_unit_id,
          created_at, updated_at, created_by)
         VALUES
         (:empresa, :ruc, :ruc_normalized, :direccion, :email, :telefono, :pais, :country_unit_id,
          :created_at, :updated_at, :created_by)'
    );

    try {
        $insert->execute([
            ':empresa' => $name,
            ':ruc' => $ruc,
            ':ruc_normalized' => $normalizedRuc,
            ':direccion' => $address,
            ':email' => $email,
            ':telefono' => $phone,
            ':pais' => (string) $countryUnit['name'],
            ':country_unit_id' => (int) $countryUnit['id'],
            ':created_at' => $now,
            ':updated_at' => $now,
            ':created_by' => $createdBy > 0 ? $createdBy : null,
        ]);
    } catch (PDOException $exception) {
        $existing = findCompanyByRuc($pdo, $ruc);
        if ($existing) {
            return $existing;
        }
        throw $exception;
    }

    $company = findCompanyById($pdo, (int) $pdo->lastInsertId());
    if (!$company) {
        throw new RuntimeException('No se pudo crear la empresa.');
    }

    return $company;
}

function findContactById(PDO $pdo, int $contactId): ?array
{
    if ($contactId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM client_contacts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $contactId]);
    $contact = $stmt->fetch();
    return is_array($contact) ? $contact : null;
}

function associateContactWithCompany(PDO $pdo, int $companyId, int $contactId): void
{
    if (!findCompanyById($pdo, $companyId) || !findContactById($pdo, $contactId)) {
        throw new RuntimeException('La empresa o el contacto seleccionado no existe.');
    }

    $primaryStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM company_contacts
         WHERE company_id = :company_id AND is_primary = 1'
    );
    $primaryStmt->execute([':company_id' => $companyId]);
    $isPrimary = (int) $primaryStmt->fetchColumn() === 0 ? 1 : 0;

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO company_contacts
         (company_id, contact_id, is_primary, created_at)
         VALUES (:company_id, :contact_id, :is_primary, :created_at)'
    );
    $insert->execute([
        ':company_id' => $companyId,
        ':contact_id' => $contactId,
        ':is_primary' => $isPrimary,
        ':created_at' => nowIso(),
    ]);
}

function contactEmails(PDO $pdo, int $contactId): array
{
    if ($contactId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, contact_id, email, normalized_email, is_primary, created_at
         FROM contact_emails
         WHERE contact_id = :contact_id
         ORDER BY is_primary DESC, id'
    );
    $stmt->execute([':contact_id' => $contactId]);
    return $stmt->fetchAll();
}

function findPrimaryContactEmail(PDO $pdo, int $contactId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, contact_id, email, normalized_email, is_primary, created_at
         FROM contact_emails
         WHERE contact_id = :contact_id
         ORDER BY is_primary DESC, id
         LIMIT 1'
    );
    $stmt->execute([':contact_id' => $contactId]);
    $email = $stmt->fetch();
    return is_array($email) ? $email : null;
}

function saveContactEmail(PDO $pdo, int $contactId, string $email, bool $isPrimary = false): array
{
    $email = normalizeContactEmail($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Uno de los correos del contacto no tiene un formato válido.');
    }
    if (!findContactById($pdo, $contactId)) {
        throw new RuntimeException('El contacto seleccionado no existe.');
    }

    $existingEmails = contactEmails($pdo, $contactId);
    if ($existingEmails === []) {
        $isPrimary = true;
    }
    if ($isPrimary) {
        $clear = $pdo->prepare('UPDATE contact_emails SET is_primary = 0 WHERE contact_id = :contact_id');
        $clear->execute([':contact_id' => $contactId]);
    }

    $normalizedEmail = normalizeContactEmail($email);
    $find = $pdo->prepare(
        'SELECT id FROM contact_emails
         WHERE contact_id = :contact_id AND normalized_email = :normalized_email
         LIMIT 1'
    );
    $find->execute([
        ':contact_id' => $contactId,
        ':normalized_email' => $normalizedEmail,
    ]);
    $emailId = (int) $find->fetchColumn();

    if ($emailId > 0) {
        $update = $pdo->prepare(
            'UPDATE contact_emails
             SET email = :email, is_primary = :is_primary
             WHERE id = :id'
        );
        $update->execute([
            ':email' => $email,
            ':is_primary' => $isPrimary ? 1 : 0,
            ':id' => $emailId,
        ]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO contact_emails
             (contact_id, email, normalized_email, is_primary, created_at)
             VALUES (:contact_id, :email, :normalized_email, :is_primary, :created_at)'
        );
        $insert->execute([
            ':contact_id' => $contactId,
            ':email' => $email,
            ':normalized_email' => $normalizedEmail,
            ':is_primary' => $isPrimary ? 1 : 0,
            ':created_at' => nowIso(),
        ]);
        $emailId = (int) $pdo->lastInsertId();
    }

    if ($isPrimary) {
        $legacy = $pdo->prepare(
            'UPDATE client_contacts
             SET email = :email, updated_at = :updated_at
             WHERE id = :id'
        );
        $legacy->execute([
            ':email' => $email,
            ':updated_at' => nowIso(),
            ':id' => $contactId,
        ]);
    }

    $stmt = $pdo->prepare('SELECT * FROM contact_emails WHERE id = :id');
    $stmt->execute([':id' => $emailId]);
    return $stmt->fetch() ?: [];
}

function parseSecondaryContactEmails(string|array $emails): array
{
    if (is_array($emails)) {
        $parts = $emails;
    } else {
        $parts = preg_split('/[\s,;]+/', trim($emails)) ?: [];
    }

    $normalized = [];
    foreach ($parts as $email) {
        $email = normalizeContactEmail((string) $email);
        if ($email !== '') {
            $normalized[$email] = $email;
        }
    }

    return array_values($normalized);
}

function findDuplicateContact(PDO $pdo, string $fullName, string $primaryEmail): ?array
{
    $normalizedName = normalizeContactName($fullName);
    $normalizedEmail = normalizeContactEmail($primaryEmail);
    if ($normalizedName === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT cc.*
         FROM client_contacts cc
         JOIN contact_emails ce ON ce.contact_id = cc.id
         WHERE cc.normalized_name = :normalized_name
           AND ce.normalized_email = :normalized_email
         LIMIT 1'
    );
    $stmt->execute([
        ':normalized_name' => $normalizedName,
        ':normalized_email' => $normalizedEmail,
    ]);
    $contact = $stmt->fetch();
    return is_array($contact) ? $contact : null;
}

function createOrReuseContactForCompany(
    PDO $pdo,
    int $companyId,
    string $fullName,
    string $phone,
    string $position,
    string $primaryEmail,
    string|array $secondaryEmails = []
): array {
    if (!findCompanyById($pdo, $companyId)) {
        throw new RuntimeException('La empresa seleccionada no existe.');
    }

    $fullName = normalizeProjectDisplayName($fullName);
    $phone = trim($phone);
    $position = trim($position);
    $primaryEmail = normalizeContactEmail($primaryEmail);
    if ($fullName === '') {
        throw new RuntimeException('Completa el nombre del contacto.');
    }
    if ($primaryEmail !== '' && !filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo principal del contacto no tiene un formato válido.');
    }

    $contact = $primaryEmail !== '' ? findDuplicateContact($pdo, $fullName, $primaryEmail) : null;
    if (!$contact) {
        $now = nowIso();
        $insert = $pdo->prepare(
            'INSERT INTO client_contacts
             (client_id, full_name, normalized_name, email, phone, position, created_at, updated_at)
             VALUES
             (:client_id, :full_name, :normalized_name, :email, :phone, :position, :created_at, :updated_at)'
        );
        $insert->execute([
            ':client_id' => $companyId,
            ':full_name' => $fullName,
            ':normalized_name' => normalizeContactName($fullName),
            ':email' => $primaryEmail,
            ':phone' => $phone,
            ':position' => $position,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $contact = findContactById($pdo, (int) $pdo->lastInsertId());
    }

    if (!$contact) {
        throw new RuntimeException('No se pudo crear el contacto.');
    }

    associateContactWithCompany($pdo, $companyId, (int) $contact['id']);
    if ($primaryEmail !== '') {
        saveContactEmail($pdo, (int) $contact['id'], $primaryEmail, true);
    }
    foreach (parseSecondaryContactEmails($secondaryEmails) as $secondaryEmail) {
        if ($secondaryEmail !== $primaryEmail) {
            saveContactEmail($pdo, (int) $contact['id'], $secondaryEmail, false);
        }
    }

    $contact = findContactById($pdo, (int) $contact['id']) ?: $contact;
    $contact['emails'] = contactEmails($pdo, (int) $contact['id']);
    return $contact;
}

function selectContactForCompany(PDO $pdo, int $companyId, int $contactId, int $contactEmailId = 0): array
{
    $contact = findContactById($pdo, $contactId);
    if (!$contact) {
        throw new RuntimeException('El contacto seleccionado no existe.');
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM company_contacts
         WHERE company_id = :company_id AND contact_id = :contact_id
         LIMIT 1'
    );
    $stmt->execute([
        ':company_id' => $companyId,
        ':contact_id' => $contactId,
    ]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('El contacto seleccionado no está asociado a la empresa.');
    }

    $email = null;
    if ($contactEmailId > 0) {
        $emailStmt = $pdo->prepare(
            'SELECT * FROM contact_emails
             WHERE id = :id AND contact_id = :contact_id
             LIMIT 1'
        );
        $emailStmt->execute([
            ':id' => $contactEmailId,
            ':contact_id' => $contactId,
        ]);
        $email = $emailStmt->fetch() ?: null;
        if (!$email) {
            throw new RuntimeException('El correo seleccionado no pertenece al contacto.');
        }
    } else {
        $email = findPrimaryContactEmail($pdo, $contactId);
    }

    return [
        'contact' => $contact,
        'email' => $email,
    ];
}

function commercialContactsForCompany(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare(
        'SELECT cc.*, link.is_primary AS company_primary
         FROM company_contacts link
         JOIN client_contacts cc ON cc.id = link.contact_id
         WHERE link.company_id = :company_id
         ORDER BY link.is_primary DESC, cc.full_name COLLATE NOCASE'
    );
    $stmt->execute([':company_id' => $companyId]);
    $contacts = $stmt->fetchAll();
    foreach ($contacts as &$contact) {
        $contact['emails'] = contactEmails($pdo, (int) $contact['id']);
    }
    unset($contact);

    return $contacts;
}

function buildProformaCommercialFields(
    array $company,
    ?array $contact,
    ?array $contactEmail,
    int $validityDays,
    string $createdAt
): array {
    $expiresAt = calculateProformaExpiresAt($createdAt, $validityDays);
    $companyName = trim((string) ($company['empresa'] ?? ''));
    $contactName = trim((string) ($contact['full_name'] ?? ''));
    $email = trim((string) ($contactEmail['email'] ?? ''));

    return [
        'company_id' => (int) ($company['id'] ?? 0),
        'client_id' => (int) ($company['id'] ?? 0),
        'contact_id' => $contact ? (int) ($contact['id'] ?? 0) : null,
        'client_contact_id' => $contact ? (int) ($contact['id'] ?? 0) : null,
        'contact_email_id' => $contactEmail ? (int) ($contactEmail['id'] ?? 0) : null,
        'company_name_snapshot' => $companyName,
        'contact_name_snapshot' => $contactName,
        'contact_email_snapshot' => $email,
        'contact_name' => $contactName,
        'contact_email' => $email,
        'contact_phone' => trim((string) ($contact['phone'] ?? '')),
        'validity_days' => validateValidityDays($validityDays),
        'expires_at' => $expiresAt,
        'expiration_date' => substr($expiresAt, 0, 10),
    ];
}
