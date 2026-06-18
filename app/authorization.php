<?php

declare(strict_types=1);

function authorizationStatusOptions(): array
{
    return [
        'NOT_REQUIRED' => 'Emitida / No requiere autorización',
        'PENDING' => 'Pendiente de autorización',
        'APPROVED' => 'Autorizada',
        'REJECTED' => 'Rechazada',
    ];
}

function proformaAuthorizationStatus(array $proforma): string
{
    $status = strtoupper(trim((string) ($proforma['authorization_status'] ?? 'NOT_REQUIRED')));
    return array_key_exists($status, authorizationStatusOptions()) ? $status : 'NOT_REQUIRED';
}

function proformaAuthorizationLabel(array $proforma): string
{
    return authorizationStatusOptions()[proformaAuthorizationStatus($proforma)];
}

function proformaAuthorizationBadgeClass(array $proforma): string
{
    return match (proformaAuthorizationStatus($proforma)) {
        'APPROVED', 'NOT_REQUIRED' => 'success',
        'REJECTED' => 'danger',
        default => 'warning',
    };
}

function proformaCanDownloadFinal(array $proforma): bool
{
    $currencyMode = normalizeProformaCurrencyMode((string) ($proforma['currency_mode'] ?? 'USD'));
    $status = proformaAuthorizationStatus($proforma);

    if ($currencyMode === 'USD') {
        return $status === 'NOT_REQUIRED' || $status === 'APPROVED';
    }

    return $status === 'APPROVED';
}

function proformaDownloadBlockMessage(array $proforma): string
{
    return match (proformaAuthorizationStatus($proforma)) {
        'REJECTED' => 'Esta proforma fue rechazada y debe ser editada para generar una nueva versión.',
        'PENDING' => 'La descarga final estará disponible cuando el tipo de cambio sea autorizado.',
        default => 'Esta proforma no está disponible para descarga final.',
    };
}

function authorizationDecisionRoles(): array
{
    return [
        'supervisor' => 'Supervisor',
        'manager' => 'Gerente',
    ];
}

function canDecideProformaAuthorization(?array $user): bool
{
    return $user !== null && in_array(
        (string) ($user['role'] ?? ''),
        ['admin', 'manager', 'supervisor'],
        true
    );
}

function availableProformaAuthorizers(PDO $pdo, array $proforma, int $excludeUserId = 0): array
{
    $countryUnitId = (int) ($proforma['country_unit_id'] ?? 0);
    if ($countryUnitId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.username, u.role, u.first_name, u.last_name, u.email, u.phone,
                u.unit, u.commercial_position, u.signature_image
         FROM users u
         LEFT JOIN user_country_units ucu
           ON ucu.user_id = u.id
          AND ucu.country_unit_id = :country_unit_id
         LEFT JOIN country_units cu ON cu.id = :country_unit_id
         WHERE u.role IN ('supervisor', 'manager')
           AND u.id <> :exclude_user_id
           AND (ucu.id IS NOT NULL OR u.unit = cu.name)
         ORDER BY CASE u.role WHEN 'supervisor' THEN 1 ELSE 2 END,
                  u.first_name COLLATE NOCASE,
                  u.last_name COLLATE NOCASE,
                  u.username COLLATE NOCASE"
    );
    $stmt->execute([
        ':country_unit_id' => $countryUnitId,
        ':exclude_user_id' => max(0, $excludeUserId),
    ]);

    return $stmt->fetchAll();
}

function recordProformaEvent(
    PDO $pdo,
    int $proformaId,
    ?int $userId,
    string $eventType,
    string $eventDetail = ''
): int {
    if ($proformaId <= 0 || trim($eventType) === '') {
        throw new RuntimeException('No se pudo registrar el evento de la proforma.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO proforma_events
         (proforma_id, user_id, event_type, event_detail, created_at)
         VALUES (:proforma_id, :user_id, :event_type, :event_detail, :created_at)'
    );
    $insert->execute([
        ':proforma_id' => $proformaId,
        ':user_id' => $userId !== null && $userId > 0 ? $userId : null,
        ':event_type' => strtoupper(trim($eventType)),
        ':event_detail' => trim($eventDetail),
        ':created_at' => nowIso(),
    ]);

    return (int) $pdo->lastInsertId();
}

function createInternalNotification(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $body,
    string $relatedEntityType,
    int $relatedEntityId
): int {
    if ($userId <= 0) {
        throw new RuntimeException('No se pudo identificar al destinatario de la notificación.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO notifications
         (user_id, type, title, body, related_entity_type, related_entity_id, is_read, created_at)
         VALUES
         (:user_id, :type, :title, :body, :related_entity_type, :related_entity_id, 0, :created_at)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':type' => trim($type),
        ':title' => trim($title),
        ':body' => trim($body),
        ':related_entity_type' => trim($relatedEntityType),
        ':related_entity_id' => $relatedEntityId,
        ':created_at' => nowIso(),
    ]);

    return (int) $pdo->lastInsertId();
}

function findProformaAuthorizationById(PDO $pdo, int $authorizationId): ?array
{
    if ($authorizationId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT a.*, p.proforma_number, p.currency_mode, p.currency_code, p.currency_symbol,
                p.country_unit_id, p.exchange_rate_used, p.authorization_status, p.total,
                p.created_by, p.seller_id
         FROM proforma_authorizations a
         JOIN proformas p ON p.id = a.proforma_id
         WHERE a.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $authorizationId]);
    $authorization = $stmt->fetch();

    return is_array($authorization) ? $authorization : null;
}

function requestProformaAuthorization(
    PDO $pdo,
    int $proformaId,
    int $requestedBy,
    int $requestedTo
): array {
    if ($requestedBy <= 0 || $requestedTo <= 0) {
        throw new RuntimeException('Selecciona un superior válido.');
    }

    $proformaStmt = $pdo->prepare('SELECT * FROM proformas WHERE id = :id LIMIT 1');
    $proformaStmt->execute([':id' => $proformaId]);
    $proforma = $proformaStmt->fetch();
    if (!$proforma) {
        throw new RuntimeException('La proforma seleccionada no existe.');
    }
    if (normalizeProformaCurrencyMode((string) $proforma['currency_mode']) !== 'LOCAL') {
        throw new RuntimeException('Esta proforma está en dólares y no requiere autorización de tipo de cambio.');
    }
    if (proformaAuthorizationStatus($proforma) !== 'PENDING') {
        throw new RuntimeException('La proforma no está pendiente de autorización.');
    }

    $authorizers = availableProformaAuthorizers($pdo, $proforma, $requestedBy);
    $selected = null;
    foreach ($authorizers as $authorizer) {
        if ((int) $authorizer['id'] === $requestedTo) {
            $selected = $authorizer;
            break;
        }
    }
    if (!$selected) {
        throw new RuntimeException('El supervisor o gerente seleccionado no está disponible para esta unidad país.');
    }

    $pending = $pdo->prepare(
        "SELECT * FROM proforma_authorizations
         WHERE proforma_id = :proforma_id
           AND status = 'PENDING'
         LIMIT 1"
    );
    $pending->execute([':proforma_id' => $proformaId]);
    $existing = $pending->fetch();
    if ($existing) {
        throw new RuntimeException('Esta proforma ya tiene una solicitud de autorización pendiente.');
    }

    $activeRate = findActiveExchangeRate($pdo, (int) $proforma['country_unit_id']);
    $now = nowIso();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO proforma_authorizations
             (proforma_id, requested_by, requested_to, requested_role, status,
              current_exchange_rate, approved_exchange_rate, exchange_rate_source,
              notes, created_at, updated_at, decided_at)
             VALUES
             (:proforma_id, :requested_by, :requested_to, :requested_role, \'PENDING\',
              :current_exchange_rate, NULL, NULL, \'\', :created_at, :updated_at, NULL)'
        );
        $insert->execute([
            ':proforma_id' => $proformaId,
            ':requested_by' => $requestedBy,
            ':requested_to' => $requestedTo,
            ':requested_role' => (string) $selected['role'],
            ':current_exchange_rate' => $activeRate ? (float) $activeRate['rate_from_usd'] : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $authorizationId = (int) $pdo->lastInsertId();

        createInternalNotification(
            $pdo,
            $requestedTo,
            'PROFORMA_AUTHORIZATION_REQUESTED',
            'Autorización de tipo de cambio',
            'La proforma ' . (string) $proforma['proforma_number'] . ' requiere autorización de tipo de cambio.',
            'proforma_authorization',
            $authorizationId
        );
        recordProformaEvent(
            $pdo,
            $proformaId,
            $requestedBy,
            'AUTHORIZATION_REQUESTED',
            'Solicitud enviada a ' . userFullName($selected) . ' (' . userRoleLabel((string) $selected['role']) . ').'
        );

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && stripos($exception->getMessage(), 'unique') !== false) {
            throw new RuntimeException('Esta proforma ya tiene una solicitud de autorización pendiente.');
        }
        throw $exception;
    }

    return findProformaAuthorizationById($pdo, $authorizationId) ?? [];
}

function validateAuthorizationDecisionUser(array $authorization, int $decidedBy, ?array $decider): void
{
    if ($decidedBy <= 0 || !$decider || !canDecideProformaAuthorization($decider)) {
        throw new RuntimeException('No tenés permisos para decidir esta autorización.');
    }
    if ((string) ($decider['role'] ?? '') !== 'admin' && (int) $authorization['requested_to'] !== $decidedBy) {
        throw new RuntimeException('Esta solicitud fue asignada a otro autorizador.');
    }
    if ((string) $authorization['status'] !== 'PENDING') {
        throw new RuntimeException('La solicitud ya fue decidida.');
    }
}

function approveProformaAuthorization(
    PDO $pdo,
    int $authorizationId,
    int $decidedBy,
    ?float $specialExchangeRate = null
): array {
    $authorization = findProformaAuthorizationById($pdo, $authorizationId);
    if (!$authorization) {
        throw new RuntimeException('La solicitud de autorización no existe.');
    }

    $deciderStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $deciderStmt->execute([':id' => $decidedBy]);
    $decider = $deciderStmt->fetch() ?: null;
    validateAuthorizationDecisionUser($authorization, $decidedBy, $decider);

    $source = 'GLOBAL';
    $approvedRate = null;
    if ($specialExchangeRate !== null) {
        if (!is_finite($specialExchangeRate) || $specialExchangeRate <= 0) {
            throw new RuntimeException('El tipo de cambio especial debe ser mayor a cero.');
        }
        $source = 'SPECIAL';
        $approvedRate = $specialExchangeRate;
    } else {
        $activeRate = findActiveExchangeRate($pdo, (int) $authorization['country_unit_id']);
        if (!$activeRate) {
            throw new RuntimeException('No hay un tipo de cambio general vigente para esta unidad.');
        }
        $approvedRate = (float) $activeRate['rate_from_usd'];
    }

    $now = nowIso();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
    }

    try {
        $updateAuthorization = $pdo->prepare(
            "UPDATE proforma_authorizations
             SET status = 'APPROVED',
                 approved_exchange_rate = :approved_exchange_rate,
                 exchange_rate_source = :exchange_rate_source,
                 notes = '',
                 updated_at = :updated_at,
                 decided_at = :decided_at
             WHERE id = :id
               AND status = 'PENDING'"
        );
        $updateAuthorization->execute([
            ':approved_exchange_rate' => $approvedRate,
            ':exchange_rate_source' => $source,
            ':updated_at' => $now,
            ':decided_at' => $now,
            ':id' => $authorizationId,
        ]);
        if ($updateAuthorization->rowCount() !== 1) {
            throw new RuntimeException('La solicitud ya fue decidida.');
        }

        $updateProforma = $pdo->prepare(
            "UPDATE proformas
             SET authorization_status = 'APPROVED',
                 exchange_rate_used = :exchange_rate_used,
                 exchange_rate_source = :exchange_rate_source,
                 exchange_rate_authorized_by = :authorized_by,
                 exchange_rate_authorized_at = :authorized_at
             WHERE id = :id"
        );
        $updateProforma->execute([
            ':exchange_rate_used' => $approvedRate,
            ':exchange_rate_source' => $source,
            ':authorized_by' => $decidedBy,
            ':authorized_at' => $now,
            ':id' => (int) $authorization['proforma_id'],
        ]);

        createInternalNotification(
            $pdo,
            (int) $authorization['requested_by'],
            'PROFORMA_AUTHORIZATION_APPROVED',
            'Proforma autorizada',
            'La proforma ' . (string) $authorization['proforma_number'] . ' fue autorizada.',
            'proforma_authorization',
            $authorizationId
        );
        recordProformaEvent(
            $pdo,
            (int) $authorization['proforma_id'],
            $decidedBy,
            'AUTHORIZATION_APPROVED',
            'Tipo de cambio ' . ($source === 'SPECIAL' ? 'especial' : 'general') . ': ' . formatNumber($approvedRate) . '.'
        );

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return findProformaAuthorizationById($pdo, $authorizationId) ?? [];
}

function rejectProformaAuthorization(
    PDO $pdo,
    int $authorizationId,
    int $decidedBy,
    string $notes
): array {
    $authorization = findProformaAuthorizationById($pdo, $authorizationId);
    if (!$authorization) {
        throw new RuntimeException('La solicitud de autorización no existe.');
    }

    $deciderStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $deciderStmt->execute([':id' => $decidedBy]);
    $decider = $deciderStmt->fetch() ?: null;
    validateAuthorizationDecisionUser($authorization, $decidedBy, $decider);

    $notes = trim($notes);
    if ($notes === '') {
        throw new RuntimeException('Ingresá un comentario para rechazar la solicitud.');
    }
    if (textLength($notes) > 500) {
        throw new RuntimeException('El comentario de rechazo no puede superar 500 caracteres.');
    }

    $now = nowIso();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
    }

    try {
        $updateAuthorization = $pdo->prepare(
            "UPDATE proforma_authorizations
             SET status = 'REJECTED',
                 approved_exchange_rate = NULL,
                 exchange_rate_source = NULL,
                 notes = :notes,
                 updated_at = :updated_at,
                 decided_at = :decided_at
             WHERE id = :id
               AND status = 'PENDING'"
        );
        $updateAuthorization->execute([
            ':notes' => $notes,
            ':updated_at' => $now,
            ':decided_at' => $now,
            ':id' => $authorizationId,
        ]);
        if ($updateAuthorization->rowCount() !== 1) {
            throw new RuntimeException('La solicitud ya fue decidida.');
        }

        $updateProforma = $pdo->prepare(
            "UPDATE proformas
             SET authorization_status = 'REJECTED',
                 exchange_rate_authorized_by = :authorized_by,
                 exchange_rate_authorized_at = :authorized_at
             WHERE id = :id"
        );
        $updateProforma->execute([
            ':authorized_by' => $decidedBy,
            ':authorized_at' => $now,
            ':id' => (int) $authorization['proforma_id'],
        ]);

        createInternalNotification(
            $pdo,
            (int) $authorization['requested_by'],
            'PROFORMA_AUTHORIZATION_REJECTED',
            'Proforma rechazada',
            'La proforma ' . (string) $authorization['proforma_number'] . ' fue rechazada: ' . $notes,
            'proforma_authorization',
            $authorizationId
        );
        recordProformaEvent(
            $pdo,
            (int) $authorization['proforma_id'],
            $decidedBy,
            'AUTHORIZATION_REJECTED',
            $notes
        );

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return findProformaAuthorizationById($pdo, $authorizationId) ?? [];
}
