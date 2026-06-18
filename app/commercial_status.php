<?php

declare(strict_types=1);

function commercialStatusOptions(): array
{
    return [
        'OPEN' => 'Pendiente',
        'WON' => 'Ganada',
        'LOST' => 'Perdida',
        'CANCELLED' => 'Cancelada',
    ];
}

function normalizeCommercialStatus(string $status): string
{
    $status = strtoupper(trim($status));
    return array_key_exists($status, commercialStatusOptions()) ? $status : 'OPEN';
}

function proformaCommercialStatus(array $proforma): string
{
    $status = trim((string) ($proforma['commercial_status'] ?? ''));
    if ($status !== '') {
        return normalizeCommercialStatus($status);
    }

    return (string) ($proforma['status'] ?? '') === 'venta_ganada' ? 'WON' : 'OPEN';
}

function commercialStatusLabel(array|string $proformaOrStatus): string
{
    $status = is_array($proformaOrStatus)
        ? proformaCommercialStatus($proformaOrStatus)
        : normalizeCommercialStatus($proformaOrStatus);

    return commercialStatusOptions()[$status];
}

function commercialStatusBadgeClass(array|string $proformaOrStatus): string
{
    $status = is_array($proformaOrStatus)
        ? proformaCommercialStatus($proformaOrStatus)
        : normalizeCommercialStatus($proformaOrStatus);

    return match ($status) {
        'WON' => 'success',
        'LOST', 'CANCELLED' => 'danger',
        default => 'warning',
    };
}

function updateProformaCommercialStatus(
    PDO $pdo,
    int $proformaId,
    string $status,
    int $updatedBy,
    string $notes = ''
): array {
    if ($proformaId <= 0 || $updatedBy <= 0) {
        throw new RuntimeException('No se pudo identificar la proforma o el usuario.');
    }

    $status = strtoupper(trim($status));
    if (!array_key_exists($status, commercialStatusOptions())) {
        throw new RuntimeException('El estado comercial seleccionado no es válido.');
    }
    $notes = sanitizePlainText($notes);
    if (textLength($notes) > 2000) {
        throw new RuntimeException('Las notas del estado comercial no pueden superar 2.000 caracteres.');
    }

    $beforeStmt = $pdo->prepare(
        'SELECT id, commercial_status, authorization_status
         FROM proformas
         WHERE id = :id'
    );
    $beforeStmt->execute([':id' => $proformaId]);
    $before = $beforeStmt->fetch();
    if (!$before) {
        throw new RuntimeException('La proforma seleccionada no existe.');
    }

    $now = nowIso();
    $update = $pdo->prepare(
        "UPDATE proformas
         SET commercial_status = :commercial_status,
             commercial_status_updated_by = :updated_by,
             commercial_status_updated_at = :updated_at,
             commercial_status_notes = :notes,
             status = CASE WHEN :commercial_status = 'WON' THEN 'venta_ganada' ELSE 'emitida' END,
             won_at = CASE WHEN :commercial_status = 'WON' THEN :updated_at ELSE NULL END,
             won_by = CASE WHEN :commercial_status = 'WON' THEN :updated_by ELSE NULL END
         WHERE id = :id"
    );
    $update->execute([
        ':commercial_status' => $status,
        ':updated_by' => $updatedBy,
        ':updated_at' => $now,
        ':notes' => $notes,
        ':id' => $proformaId,
    ]);

    if (tableExists($pdo, 'proforma_events')) {
        $event = $pdo->prepare(
            'INSERT INTO proforma_events
             (proforma_id, user_id, event_type, event_detail, created_at)
             VALUES (:proforma_id, :user_id, :event_type, :event_detail, :created_at)'
        );
        $event->execute([
            ':proforma_id' => $proformaId,
            ':user_id' => $updatedBy,
            ':event_type' => 'commercial_status_updated',
            ':event_detail' => $status . ($notes !== '' ? ' · ' . $notes : ''),
            ':created_at' => $now,
        ]);
    }

    $afterStmt = $pdo->prepare('SELECT * FROM proformas WHERE id = :id');
    $afterStmt->execute([':id' => $proformaId]);
    $after = $afterStmt->fetch() ?: [];
    if (($after['authorization_status'] ?? null) !== ($before['authorization_status'] ?? null)) {
        throw new RuntimeException('El estado de autorización fue alterado inesperadamente.');
    }

    return $after;
}
