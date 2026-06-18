<?php

declare(strict_types=1);

function defaultProformaDisclaimers(): array
{
    return [
        ['Disponibilidad', 'Los precios están sujetos a disponibilidad de stock.'],
        ['Validez', 'La validez de esta proforma corresponde al plazo indicado en el documento.'],
        ['Entrega', 'Los tiempos de entrega pueden variar según ubicación y condiciones de obra.'],
        ['Alcance', 'Esta propuesta no constituye factura ni contrato definitivo.'],
    ];
}

function seedDefaultProformaDisclaimers(PDO $pdo): void
{
    if (!tableExists($pdo, 'proforma_disclaimers')) {
        return;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM proforma_disclaimers')->fetchColumn() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO proforma_disclaimers
         (title, body, is_active, is_default, sort_order, created_by, updated_by, created_at, updated_at)
         VALUES (:title, :body, 1, 1, :sort_order, NULL, NULL, :created_at, :updated_at)'
    );
    foreach (defaultProformaDisclaimers() as $index => [$title, $body]) {
        $insert->execute([
            ':title' => $title,
            ':body' => $body,
            ':sort_order' => ($index + 1) * 10,
            ':created_at' => nowIso(),
            ':updated_at' => nowIso(),
        ]);
    }
}

function listProformaDisclaimers(PDO $pdo, bool $defaultsOnly = false): array
{
    $where = $defaultsOnly ? 'WHERE is_active = 1 AND is_default = 1' : '';
    return $pdo->query('SELECT * FROM proforma_disclaimers ' . $where . ' ORDER BY sort_order, id')->fetchAll();
}

function normalizeDisclaimerData(array $data): array
{
    $title = sanitizePlainText((string) ($data['title'] ?? ''));
    $body = sanitizePlainText((string) ($data['body'] ?? ''));
    if ($title === '' || $body === '') {
        throw new RuntimeException('El título y el texto del disclaimer son obligatorios.');
    }
    if (textLength($title) > 120 || textLength($body) > 4000) {
        throw new RuntimeException('El disclaimer supera la longitud permitida.');
    }

    return [
        'title' => $title,
        'body' => $body,
        'is_active' => !empty($data['is_active']) ? 1 : 0,
        'is_default' => !empty($data['is_default']) ? 1 : 0,
        'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
    ];
}

function createProformaDisclaimer(PDO $pdo, array $data, int $userId): int
{
    $data = normalizeDisclaimerData($data);
    $stmt = $pdo->prepare(
        'INSERT INTO proforma_disclaimers
         (title, body, is_active, is_default, sort_order, created_by, updated_by, created_at, updated_at)
         VALUES (:title, :body, :is_active, :is_default, :sort_order, :created_by, :updated_by, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':title' => $data['title'],
        ':body' => $data['body'],
        ':is_active' => $data['is_active'],
        ':is_default' => $data['is_default'],
        ':sort_order' => $data['sort_order'],
        ':created_by' => $userId,
        ':updated_by' => $userId,
        ':created_at' => nowIso(),
        ':updated_at' => nowIso(),
    ]);
    return (int) $pdo->lastInsertId();
}

function updateProformaDisclaimer(PDO $pdo, int $id, array $data, int $userId): void
{
    if ($id <= 0) {
        throw new RuntimeException('Disclaimer inválido.');
    }
    $data = normalizeDisclaimerData($data);
    $stmt = $pdo->prepare(
        'UPDATE proforma_disclaimers
         SET title = :title, body = :body, is_active = :is_active, is_default = :is_default,
             sort_order = :sort_order, updated_by = :updated_by, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':title' => $data['title'],
        ':body' => $data['body'],
        ':is_active' => $data['is_active'],
        ':is_default' => $data['is_default'],
        ':sort_order' => $data['sort_order'],
        ':updated_by' => $userId,
        ':updated_at' => nowIso(),
        ':id' => $id,
    ]);
}

function snapshotDefaultProformaDisclaimers(PDO $pdo, int $proformaId): array
{
    $insert = $pdo->prepare(
        'INSERT INTO proforma_disclaimer_snapshots
         (proforma_id, disclaimer_id, title_snapshot, body_snapshot, sort_order_snapshot, created_at)
         VALUES (:proforma_id, :disclaimer_id, :title_snapshot, :body_snapshot, :sort_order_snapshot, :created_at)'
    );
    foreach (listProformaDisclaimers($pdo, true) as $disclaimer) {
        $insert->execute([
            ':proforma_id' => $proformaId,
            ':disclaimer_id' => (int) $disclaimer['id'],
            ':title_snapshot' => (string) $disclaimer['title'],
            ':body_snapshot' => (string) $disclaimer['body'],
            ':sort_order_snapshot' => (int) $disclaimer['sort_order'],
            ':created_at' => nowIso(),
        ]);
    }
    return loadProformaDisclaimerSnapshots($pdo, $proformaId);
}

function loadProformaDisclaimerSnapshots(PDO $pdo, int $proformaId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM proforma_disclaimer_snapshots
         WHERE proforma_id = :proforma_id
         ORDER BY sort_order_snapshot, id'
    );
    $stmt->execute([':proforma_id' => $proformaId]);
    return $stmt->fetchAll();
}

function proformaObservations(array $proforma): string
{
    return sanitizePlainText((string) ($proforma['commercial_conditions'] ?? ''));
}
