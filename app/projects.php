<?php

declare(strict_types=1);

function findProjectById(PDO $pdo, int $projectId): ?array
{
    if ($projectId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch();
    return is_array($project) ? $project : null;
}

function findProjectByNormalizedName(PDO $pdo, string $normalizedName): ?array
{
    if ($normalizedName === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM projects WHERE normalized_name = :normalized_name LIMIT 1');
    $stmt->execute([':normalized_name' => $normalizedName]);
    $project = $stmt->fetch();
    return is_array($project) ? $project : null;
}

function projectPrefixExists(PDO $pdo, string $prefix): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM projects WHERE prefix = :prefix COLLATE NOCASE LIMIT 1');
    $stmt->execute([':prefix' => $prefix]);
    return (bool) $stmt->fetchColumn();
}

function generateUniqueProjectPrefix(PDO $pdo, string $projectName): string
{
    $candidates = projectPrefixCandidates($projectName);
    if ($candidates === []) {
        throw new RuntimeException('No se pudo generar el prefijo del proyecto.');
    }

    foreach ($candidates as $candidate) {
        if (!projectPrefixExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    $base = $candidates[count($candidates) - 1];
    for ($suffix = 2; $suffix <= 9999; $suffix++) {
        $candidate = $base . $suffix;
        if (!projectPrefixExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('No se pudo generar un prefijo unico para el proyecto.');
}

function resolveProject(PDO $pdo, string $projectName, int $preferredProjectId = 0): array
{
    $displayName = normalizeProjectDisplayName($projectName);
    $normalizedName = normalizeProjectName($displayName);
    if ($displayName === '' || $normalizedName === '') {
        throw new RuntimeException('Completa un nombre de proyecto valido.');
    }

    if ($preferredProjectId > 0) {
        $preferred = findProjectById($pdo, $preferredProjectId);
        if ($preferred && hash_equals((string) $preferred['normalized_name'], $normalizedName)) {
            return $preferred;
        }
    }

    $existing = findProjectByNormalizedName($pdo, $normalizedName);
    if ($existing) {
        return $existing;
    }

    $now = nowIso();
    $prefix = generateUniqueProjectPrefix($pdo, $displayName);
    $insert = $pdo->prepare(
        'INSERT INTO projects (name, normalized_name, prefix, created_at, updated_at)
         VALUES (:name, :normalized_name, :prefix, :created_at, :updated_at)'
    );

    try {
        $insert->execute([
            ':name' => $displayName,
            ':normalized_name' => $normalizedName,
            ':prefix' => $prefix,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } catch (PDOException $exception) {
        $existing = findProjectByNormalizedName($pdo, $normalizedName);
        if ($existing) {
            return $existing;
        }
        throw $exception;
    }

    $project = findProjectById($pdo, (int) $pdo->lastInsertId());
    if (!$project) {
        throw new RuntimeException('No se pudo crear el proyecto.');
    }

    return $project;
}

function allocateProjectProformaNumber(PDO $pdo, int $projectId, string $emissionDate): array
{
    if (!isValidDate($emissionDate)) {
        throw new RuntimeException('La fecha de emision no es valida.');
    }

    $project = findProjectById($pdo, $projectId);
    if (!$project) {
        throw new RuntimeException('El proyecto seleccionado no existe.');
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(project_sequence), 0) + 1
         FROM proformas
         WHERE project_id = :project_id'
    );
    $stmt->execute([':project_id' => $projectId]);
    $sequence = max(1, (int) $stmt->fetchColumn());
    $datePart = str_replace('-', '', $emissionDate);

    return [
        'number' => (string) $project['prefix'] . '-' . $datePart . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'sequence' => $sequence,
        'project' => $project,
    ];
}

function nextProformaVersionNumber(PDO $pdo, int $sourceProformaId): int
{
    if ($sourceProformaId <= 0) {
        return 1;
    }

    $stmt = $pdo->prepare(
        'WITH RECURSIVE family(id, parent_proforma_id, version_number) AS (
             SELECT id, parent_proforma_id, version_number
             FROM proformas
             WHERE id = :source_id
             UNION
             SELECT related.id, related.parent_proforma_id, related.version_number
             FROM proformas related
             JOIN family current
               ON related.id = current.parent_proforma_id
               OR related.parent_proforma_id = current.id
         )
         SELECT COALESCE(MAX(version_number), 0) + 1
         FROM family'
    );
    $stmt->execute([':source_id' => $sourceProformaId]);

    return max(2, (int) $stmt->fetchColumn());
}

function latestProformaVersionId(PDO $pdo, int $sourceProformaId): int
{
    if ($sourceProformaId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'WITH RECURSIVE family(id, parent_proforma_id, version_number, project_sequence) AS (
             SELECT id, parent_proforma_id, version_number, project_sequence
             FROM proformas
             WHERE id = :source_id
             UNION
             SELECT related.id, related.parent_proforma_id, related.version_number, related.project_sequence
             FROM proformas related
             JOIN family current
               ON related.id = current.parent_proforma_id
               OR related.parent_proforma_id = current.id
         )
         SELECT id
         FROM family
         ORDER BY version_number DESC, project_sequence DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':source_id' => $sourceProformaId]);

    return (int) $stmt->fetchColumn();
}

function proformaIsLatestVersion(PDO $pdo, int $proformaId): bool
{
    return $proformaId > 0 && latestProformaVersionId($pdo, $proformaId) === $proformaId;
}

function assertProformaIsLatestVersion(PDO $pdo, int $proformaId): void
{
    if (!proformaIsLatestVersion($pdo, $proformaId)) {
        throw new RuntimeException(
            'Esta proforma fue reemplazada por una versión posterior. La versión anterior solo puede visualizarse y descargarse.'
        );
    }
}

function backfillLegacyProjects(PDO $pdo): void
{
    $rows = $pdo->query(
        'SELECT id, proforma_number, project_name, project_id, project_sequence, version_number
         FROM proformas
         WHERE project_id IS NULL
            OR project_id < 1
            OR project_sequence IS NULL
            OR project_sequence < 1
            OR version_number IS NULL
            OR version_number < 1
         ORDER BY emission_date, id'
    )->fetchAll();

    foreach ($rows as $row) {
        $project = findProjectById($pdo, (int) ($row['project_id'] ?? 0));
        if (!$project) {
            $projectName = normalizeProjectDisplayName((string) ($row['project_name'] ?? ''));
            if ($projectName === '') {
                $projectName = 'Proyecto historico ' . (string) $row['proforma_number'];
            }
            $project = resolveProject($pdo, $projectName);
        }

        $sequence = (int) ($row['project_sequence'] ?? 0);
        if ($sequence <= 0) {
            $sequenceStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(project_sequence), 0) + 1
                 FROM proformas
                 WHERE project_id = :project_id
                   AND project_sequence IS NOT NULL
                   AND project_sequence > 0'
            );
            $sequenceStmt->execute([':project_id' => (int) $project['id']]);
            $sequence = max(1, (int) $sequenceStmt->fetchColumn());
        }

        $update = $pdo->prepare(
            'UPDATE proformas
             SET project_id = :project_id,
                 project_name = :project_name,
                 project_sequence = :project_sequence,
                 version_number = CASE WHEN version_number IS NULL OR version_number < 1 THEN 1 ELSE version_number END
             WHERE id = :id'
        );
        $update->execute([
            ':project_id' => (int) $project['id'],
            ':project_name' => (string) $project['name'],
            ':project_sequence' => $sequence,
            ':id' => (int) $row['id'],
        ]);
    }
}
