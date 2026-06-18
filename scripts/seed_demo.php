<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$password = (string) (getenv('DEMO_USER_PASSWORD') ?: '');
if (textLength($password) < 8) {
    fwrite(STDERR, "Define DEMO_USER_PASSWORD con al menos 8 caracteres.\n");
    exit(1);
}

$pdo = db();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) {
    fwrite(STDERR, "Debe existir al menos un usuario administrador.\n");
    exit(1);
}

createDatabaseBackup();
$pdo->beginTransaction();

try {
    $units = countryUnits($pdo);
    $unitIds = array_map('intval', array_column($units, 'id'));
    $paraguay = findCountryUnitByName($pdo, 'Paraguay');
    $colombia = findCountryUnitByName($pdo, 'Colombia');
    if (!$paraguay || !$colombia) {
        throw new RuntimeException('Faltan las unidades país Paraguay o Colombia.');
    }

    $demoUsers = [
        ['qa-director', 'director', 'Diana', 'Directora', 'Dirección comercial', 'Paraguay', null],
        ['qa-manager', 'manager', 'Mario', 'Gerente', 'Gerente comercial', 'Paraguay', null],
        ['qa-supervisor', 'supervisor', 'Sofía', 'Supervisora', 'Supervisora comercial', 'Paraguay', 'qa-manager'],
        ['qa-executive', 'commercial_executive', 'Elena', 'Ejecutiva', 'Ejecutiva comercial', 'Paraguay', 'qa-supervisor'],
        ['qa-assistant', 'assistant', 'Ana', 'Asistente', 'Asistente comercial', 'Paraguay', 'qa-supervisor'],
    ];
    $userIds = [];
    foreach ($demoUsers as [$username, $role, $firstName, $lastName, $position, $unit, $reportsTo]) {
        $find = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $find->execute([':username' => $username]);
        $userId = (int) $find->fetchColumn();
        if ($userId <= 0) {
            $insert = $pdo->prepare(
                'INSERT INTO users
                 (username, password_hash, role, first_name, last_name, email, phone, unit,
                  reports_to_id, commercial_position, signature_image, created_at)
                 VALUES
                 (:username, :password_hash, :role, :first_name, :last_name, :email, :phone, :unit,
                  NULL, :commercial_position, \'\', :created_at)'
            );
            $insert->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $username . '@example.test',
                ':phone' => '0981000200',
                ':unit' => $unit,
                ':commercial_position' => $position,
                ':created_at' => nowIso(),
            ]);
            $userId = (int) $pdo->lastInsertId();
        } else {
            $update = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash, role = :role, first_name = :first_name,
                     last_name = :last_name, email = :email, unit = :unit,
                     commercial_position = :commercial_position
                 WHERE id = :id'
            );
            $update->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $username . '@example.test',
                ':unit' => $unit,
                ':commercial_position' => $position,
                ':id' => $userId,
            ]);
        }
        $userIds[$username] = $userId;
        syncUserCountryUnits(
            $pdo,
            $userId,
            $role === 'director' ? $unitIds : [(int) $paraguay['id']]
        );
    }

    foreach ($demoUsers as [$username, , , , , , $reportsTo]) {
        if ($reportsTo === null) {
            continue;
        }
        $update = $pdo->prepare('UPDATE users SET reports_to_id = :reports_to_id WHERE id = :id');
        $update->execute([
            ':reports_to_id' => $userIds[$reportsTo],
            ':id' => $userIds[$username],
        ]);
    }

    $paraguayCompany = resolveCompanyFromQuote(
        $pdo,
        'QA-7000001-1',
        'Empresa Demo Etapa 7',
        'Dirección demo 123',
        '0981000100',
        'empresa.demo@example.test',
        (int) $paraguay['id'],
        $adminId
    );
    $colombiaCompany = resolveCompanyFromQuote(
        $pdo,
        'QA-7000002-2',
        'Empresa Demo Colombia Etapa 7',
        'Calle demo 456',
        '3000000000',
        'colombia.demo@example.test',
        (int) $colombia['id'],
        $adminId
    );
    $primaryContact = createOrReuseContactForCompany(
        $pdo,
        (int) $paraguayCompany['id'],
        'Contacto Demo Principal',
        '0981000101',
        'Compras',
        'principal.demo@example.test',
        ['secundario.demo@example.test', 'otro.demo@example.test']
    );
    createOrReuseContactForCompany(
        $pdo,
        (int) $paraguayCompany['id'],
        'Contacto Demo Secundario',
        '0981000102',
        'Administración',
        'contacto.secundario@example.test'
    );
    associateContactWithCompany($pdo, (int) $colombiaCompany['id'], (int) $primaryContact['id']);

    $wonStmt = $pdo->prepare(
        "SELECT p.id, p.commercial_status
         FROM proformas p
         JOIN projects project ON project.id = p.project_id
         WHERE project.normalized_name = :project
         ORDER BY p.id DESC LIMIT 1"
    );
    $wonStmt->execute([':project' => normalizeProjectName('QA Etapa 7 USD')]);
    $won = $wonStmt->fetch();
    if ($won && proformaCommercialStatus($won) !== 'WON') {
        updateProformaCommercialStatus($pdo, (int) $won['id'], 'WON', $adminId, 'Dato demo controlado.');
    }

    $lostStmt = $pdo->prepare(
        "SELECT p.id, p.commercial_status
         FROM proformas p
         JOIN projects project ON project.id = p.project_id
         WHERE project.normalized_name = :project
         ORDER BY p.id DESC LIMIT 1"
    );
    $lostStmt->execute([':project' => normalizeProjectName('QA Etapa 7 Colombia Perdida')]);
    $lost = $lostStmt->fetch();
    if ($lost && proformaCommercialStatus($lost) !== 'LOST') {
        updateProformaCommercialStatus($pdo, (int) $lost['id'], 'LOST', $adminId, 'Dato demo controlado.');
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

echo "Datos demo verificados.\n";
echo "Usuarios QA: qa-director, qa-manager, qa-supervisor, qa-executive, qa-assistant.\n";
echo "Empresas demo: Empresa Demo Etapa 7 y Empresa Demo Colombia Etapa 7.\n";
