<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function superiorHistoryAssertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . PHP_EOL
            . 'Esperado: ' . var_export($expected, true) . PHP_EOL
            . 'Obtenido: ' . var_export($actual, true)
        );
    }
    echo '[OK] ' . $label . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$legacySchema = (string) file_get_contents(__DIR__ . '/../migrations/init.sql');
$legacySchema = str_replace([
    "    superior_id_snapshot INTEGER,\n",
    "    superior_snapshot_captured INTEGER NOT NULL DEFAULT 0,\n",
    "    FOREIGN KEY (superior_id_snapshot) REFERENCES users(id),\n",
    "CREATE INDEX IF NOT EXISTS idx_proformas_superior_snapshot ON proformas(superior_id_snapshot);\n",
], '', $legacySchema);
$pdo->exec($legacySchema);

$insertUser = $pdo->prepare(
    'INSERT INTO users (username, password_hash, role, unit, reports_to_id, created_at)
     VALUES (:username, :password_hash, :role, :unit, :reports_to_id, :created_at)'
);
$insertUser->execute([
    ':username' => 'superior-anterior',
    ':password_hash' => 'x',
    ':role' => 'supervisor',
    ':unit' => 'Paraguay',
    ':reports_to_id' => null,
    ':created_at' => '2026-06-18 09:00:00',
]);
$oldSuperiorId = (int) $pdo->lastInsertId();
$insertUser->execute([
    ':username' => 'superior-nuevo',
    ':password_hash' => 'x',
    ':role' => 'supervisor',
    ':unit' => 'Paraguay',
    ':reports_to_id' => null,
    ':created_at' => '2026-06-18 09:00:00',
]);
$newSuperiorId = (int) $pdo->lastInsertId();
$insertUser->execute([
    ':username' => 'ejecutivo',
    ':password_hash' => 'x',
    ':role' => 'commercial_executive',
    ':unit' => 'Paraguay',
    ':reports_to_id' => $oldSuperiorId,
    ':created_at' => '2026-06-18 09:00:00',
]);
$sellerId = (int) $pdo->lastInsertId();

$pdo->exec(
    "INSERT INTO clients (empresa, pais, created_at, updated_at, created_by)
     VALUES ('Cliente histórico', 'Paraguay', '2026-06-18 09:00:00', '2026-06-18 09:00:00', $sellerId)"
);
$clientId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO projects (name, normalized_name, prefix, created_at, updated_at)
     VALUES ('Historial superior', 'historial superior', 'HS', '2026-06-18 09:00:00', '2026-06-18 09:00:00')"
);
$projectId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO proformas
     (proforma_number, project_id, version_number, project_sequence, client_id, project_name,
      emission_date, expiration_date, seller_id, created_by, created_at)
     VALUES
     ('HS-20260618-001', $projectId, 1, 1, $clientId, 'Historial superior',
      '2026-06-18', '2026-06-28', $sellerId, $sellerId, '2026-06-18 09:00:00')"
);
$proformaId = (int) $pdo->lastInsertId();

ensureSchemaCompatibility($pdo);
$snapshot = $pdo->query(
    'SELECT superior_id_snapshot, superior_snapshot_captured
     FROM proformas
     WHERE id = ' . $proformaId
)->fetch();
superiorHistoryAssertSame($oldSuperiorId, (int) $snapshot['superior_id_snapshot'], 'migración captura el superior vigente');
superiorHistoryAssertSame(1, (int) $snapshot['superior_snapshot_captured'], 'migración marca el snapshot como capturado');

$pdo->exec("UPDATE users SET reports_to_id = $newSuperiorId WHERE id = $sellerId");
ensureSchemaCompatibility($pdo);
$storedSuperiorId = (int) $pdo->query(
    'SELECT superior_id_snapshot FROM proformas WHERE id = ' . $proformaId
)->fetchColumn();
superiorHistoryAssertSame($oldSuperiorId, $storedSuperiorId, 'cambios posteriores no reescriben el superior histórico');

echo PHP_EOL . 'Pruebas de historial de superiores completadas.' . PHP_EOL;
