<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/projects.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . PHP_EOL .
            'Esperado: ' . var_export($expected, true) . PHP_EOL .
            'Obtenido: ' . var_export($actual, true)
        );
    }

    echo '[OK] ' . $label . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        normalized_name TEXT NOT NULL COLLATE NOCASE UNIQUE,
        prefix TEXT NOT NULL COLLATE NOCASE UNIQUE,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
    CREATE TABLE proformas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proforma_number TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL,
        parent_proforma_id INTEGER,
        version_number INTEGER NOT NULL DEFAULT 1,
        project_sequence INTEGER NOT NULL,
        emission_date TEXT NOT NULL
    );
    CREATE UNIQUE INDEX idx_test_project_sequence ON proformas(project_id, project_sequence);'
);

assertSameValue(
    'edificio puerto ibiza',
    normalizeProjectName('  EDIFICIO   Puerto Ibizá  '),
    'normaliza mayusculas, espacios y acentos'
);
assertSameValue(
    ['torre', 'costa'],
    projectMainWords('Torre de la Costa'),
    'ignora conectores al generar el prefijo'
);

$firstProject = resolveProject($pdo, 'Edificio Puerto Ibiza');
assertSameValue('EPI', $firstProject['prefix'], 'genera prefijo por iniciales');

$sameProject = resolveProject($pdo, '  EDIFICIO puerto ibizá ');
assertSameValue((int) $firstProject['id'], (int) $sameProject['id'], 'reutiliza proyecto existente normalizado');

$collisionProject = resolveProject($pdo, 'Establos Pedro Iriarte');
assertSameValue('EsPI', $collisionProject['prefix'], 'resuelve colision usando dos letras de la primera palabra');

$firstAllocation = allocateProjectProformaNumber($pdo, (int) $firstProject['id'], '2026-06-17');
assertSameValue('EPI-20260617-001', $firstAllocation['number'], 'genera primera numeracion del proyecto');

$insert = $pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, project_id, parent_proforma_id, version_number, project_sequence, emission_date)
     VALUES (:number, :project_id, NULL, 1, :sequence, :emission_date)'
);
$insert->execute([
    ':number' => $firstAllocation['number'],
    ':project_id' => (int) $firstProject['id'],
    ':sequence' => (int) $firstAllocation['sequence'],
    ':emission_date' => '2026-06-17',
]);
$firstProformaId = (int) $pdo->lastInsertId();

$secondAllocation = allocateProjectProformaNumber($pdo, (int) $firstProject['id'], '2026-07-09');
assertSameValue('EPI-20260709-002', $secondAllocation['number'], 'incrementa secuencial por proyecto y no por fecha');

$insertVersion = $pdo->prepare(
    'INSERT INTO proformas
     (proforma_number, project_id, parent_proforma_id, version_number, project_sequence, emission_date)
     VALUES (:number, :project_id, :parent_id, 2, :sequence, :emission_date)'
);
$insertVersion->execute([
    ':number' => $secondAllocation['number'],
    ':project_id' => (int) $firstProject['id'],
    ':parent_id' => $firstProformaId,
    ':sequence' => (int) $secondAllocation['sequence'],
    ':emission_date' => '2026-07-09',
]);
$secondProformaId = (int) $pdo->lastInsertId();

$versions = $pdo->query(
    'SELECT proforma_number, parent_proforma_id, version_number, project_sequence
     FROM proformas
     WHERE project_id = ' . (int) $firstProject['id'] . '
     ORDER BY project_sequence'
)->fetchAll();
assertSameValue(2, count($versions), 'el versionado conserva la emision anterior');
assertSameValue($firstProformaId, (int) $versions[1]['parent_proforma_id'], 'la nueva version referencia a la anterior');
assertSameValue(2, (int) $versions[1]['version_number'], 'incrementa version_number');
assertSameValue(false, proformaIsLatestVersion($pdo, $firstProformaId), 'la versión anterior deja de ser editable');
assertSameValue(true, proformaIsLatestVersion($pdo, $secondProformaId), 'solo la última versión permanece editable');
assertSameValue(3, nextProformaVersionNumber($pdo, $secondProformaId), 'la última versión calcula el siguiente número de versión');

echo PHP_EOL . 'Pruebas de proyectos y numeracion completadas.' . PHP_EOL;
