<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

header('Content-Type: application/json; charset=utf-8');

$currentUser = currentUser();
if (!canCreateProformas($currentUser)) {
    http_response_code(403);
    echo json_encode(['projects' => [], 'error' => 'Sin permisos para buscar proyectos.']);
    exit;
}

$query = normalizeProjectName((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['projects' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
$stmt = db()->prepare(
    "SELECT id, name, prefix
     FROM projects
     WHERE normalized_name LIKE :name_query ESCAPE '\\'
        OR LOWER(prefix) LIKE :prefix_query ESCAPE '\\'
     ORDER BY
        CASE WHEN normalized_name = :exact THEN 0 ELSE 1 END,
        name COLLATE NOCASE
     LIMIT 12"
);
$stmt->execute([
    ':name_query' => '%' . $escapedQuery . '%',
    ':prefix_query' => '%' . $escapedQuery . '%',
    ':exact' => $query,
]);

$projects = array_map(
    static fn (array $project): array => [
        'id' => (int) $project['id'],
        'name' => (string) $project['name'],
        'prefix' => (string) $project['prefix'],
    ],
    $stmt->fetchAll()
);

echo json_encode(['projects' => $projects], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
