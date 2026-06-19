<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAdmin();

function fetchUserById(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, username, role, first_name, last_name, email, phone, unit, reports_to_id,
                commercial_position, signature_image, created_at
         FROM users
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    $user['country_unit_ids'] = userCountryUnitIds(db(), $id);
    return $user;
}

function adminUserCount(?int $excludeId = null): int
{
    if ($excludeId === null) {
        return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> :id");
    $stmt->execute([':id' => $excludeId]);

    return (int) $stmt->fetchColumn();
}

function wouldCreateReportingCycle(int $userId, int $reportsToId): bool
{
    if ($userId <= 0 || $reportsToId <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        'WITH RECURSIVE chain(id, reports_to_id) AS (
             SELECT id, reports_to_id
             FROM users
             WHERE id = :reports_to_id
             UNION
             SELECT u.id, u.reports_to_id
             FROM users u
             JOIN chain c ON u.id = c.reports_to_id
             WHERE c.reports_to_id IS NOT NULL
         )
         SELECT 1 FROM chain WHERE id = :user_id LIMIT 1'
    );
    $stmt->execute([
        ':reports_to_id' => $reportsToId,
        ':user_id' => $userId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function deleteSystemUser(int $userId, int $replacementUserId): void
{
    $pdo = db();
    $user = fetchUserById($userId);
    if (!$user) {
        throw new RuntimeException('El usuario seleccionado no existe.');
    }
    if ($userId === $replacementUserId) {
        throw new RuntimeException('No podes eliminar tu propio usuario.');
    }
    if ((string) $user['role'] === 'admin' && adminUserCount($userId) < 1) {
        throw new RuntimeException('El sistema debe conservar al menos un administrador.');
    }

    createDatabaseBackup();
    $pdo->beginTransaction();
    try {
        $updates = [
            ['UPDATE users SET reports_to_id = NULL WHERE reports_to_id = :user_id', [':user_id' => $userId]],
            ['UPDATE clients SET created_by = NULL WHERE created_by = :user_id', [':user_id' => $userId]],
            ['UPDATE exchange_rates SET created_by = :replacement_id WHERE created_by = :user_id', [
                ':replacement_id' => $replacementUserId,
                ':user_id' => $userId,
            ]],
            ['UPDATE proformas
              SET created_by = CASE WHEN created_by = :user_id THEN :replacement_id ELSE created_by END,
                  seller_id = CASE WHEN seller_id = :user_id THEN NULL ELSE seller_id END,
                  superior_id_snapshot = CASE WHEN superior_id_snapshot = :user_id THEN NULL ELSE superior_id_snapshot END,
                  won_by = CASE WHEN won_by = :user_id THEN NULL ELSE won_by END,
                  commercial_status_updated_by = CASE WHEN commercial_status_updated_by = :user_id THEN NULL ELSE commercial_status_updated_by END,
                  exchange_rate_authorized_by = CASE WHEN exchange_rate_authorized_by = :user_id THEN NULL ELSE exchange_rate_authorized_by END
              WHERE created_by = :user_id
                 OR seller_id = :user_id
                 OR superior_id_snapshot = :user_id
                 OR won_by = :user_id
                 OR commercial_status_updated_by = :user_id
                 OR exchange_rate_authorized_by = :user_id', [
                ':replacement_id' => $replacementUserId,
                ':user_id' => $userId,
            ]],
            ['UPDATE proforma_authorizations
              SET requested_by = CASE WHEN requested_by = :user_id THEN :replacement_id ELSE requested_by END,
                  requested_to = CASE WHEN requested_to = :user_id THEN :replacement_id ELSE requested_to END
              WHERE requested_by = :user_id OR requested_to = :user_id', [
                ':replacement_id' => $replacementUserId,
                ':user_id' => $userId,
            ]],
            ['DELETE FROM notifications WHERE user_id = :user_id', [':user_id' => $userId]],
            ['UPDATE proforma_events SET user_id = NULL WHERE user_id = :user_id', [':user_id' => $userId]],
            ['UPDATE proforma_disclaimers
              SET created_by = CASE WHEN created_by = :user_id THEN NULL ELSE created_by END,
                  updated_by = CASE WHEN updated_by = :user_id THEN NULL ELSE updated_by END
              WHERE created_by = :user_id OR updated_by = :user_id', [':user_id' => $userId]],
            ['DELETE FROM user_country_units WHERE user_id = :user_id', [':user_id' => $userId]],
        ];

        foreach ($updates as [$sql, $params]) {
            if (preg_match('/(?:UPDATE|FROM)\s+([a-z_]+)/i', $sql, $match) === 1 && !tableExists($pdo, $match[1])) {
                continue;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        $delete = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute([':id' => $userId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('No se pudo eliminar el usuario.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$currentAdmin = currentUser();
$allowedRoles = array_keys(roleOptions());
$countryUnits = countryUnits(db());
$defaultCountryUnit = findCountryUnitByName(db(), defaultCountry());
$error = null;
$edit = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();

        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'delete') {
            $deleteId = max(0, (int) ($_POST['id'] ?? 0));
            deleteSystemUser($deleteId, (int) ($currentAdmin['id'] ?? 0));
            setFlash('success', 'Usuario eliminado. Sus referencias históricas fueron preservadas o reasignadas.');
            redirect('/users.php');
        }
        if ($action !== 'save') {
            throw new RuntimeException('Acción no válida.');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'commercial_executive');
        $password = (string) ($_POST['password'] ?? '');
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $primaryCountryUnitId = (int) ($_POST['primary_country_unit_id'] ?? 0);
        $additionalCountryUnitIds = is_array($_POST['country_unit_ids'] ?? null) ? $_POST['country_unit_ids'] : [];
        $countryUnitIds = validateCountryUnitIds(db(), array_merge([$primaryCountryUnitId], $additionalCountryUnitIds));
        $primaryCountryUnit = findCountryUnitById(db(), $primaryCountryUnitId);
        if (!$primaryCountryUnit) {
            throw new RuntimeException('La unidad principal seleccionada no es válida.');
        }
        $unit = (string) $primaryCountryUnit['name'];
        $reportsToId = max(0, (int) ($_POST['reports_to_id'] ?? 0));

        if ($username === '') {
            throw new RuntimeException('El usuario es obligatorio.');
        }
        if (textLength($username) > 80 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new RuntimeException('El usuario tiene un formato invalido.');
        }
        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('El rol seleccionado no es valido.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo electronico no tiene un formato valido.');
        }
        if ($id > 0 && $currentAdmin && $id === (int) $currentAdmin['id'] && $role !== 'admin') {
            throw new RuntimeException('No podes quitarte permisos de administrador desde tu propio usuario.');
        }
        if ($reportsToId > 0) {
            if ($id > 0 && $reportsToId === $id) {
                throw new RuntimeException('Un usuario no puede reportarse a si mismo.');
            }
            $leader = fetchUserById($reportsToId);
            if (!$leader) {
                throw new RuntimeException('El responsable seleccionado no existe.');
            }
            if (!in_array((string) $leader['role'], ['admin', 'director', 'manager', 'supervisor'], true)) {
                throw new RuntimeException('El responsable debe ser administrador, director, gerente o supervisor.');
            }
            if ($id > 0 && wouldCreateReportingCycle($id, $reportsToId)) {
                throw new RuntimeException('La relacion de equipo seleccionada crea un ciclo.');
            }
        }

        $duplicate = db()->prepare('SELECT id FROM users WHERE username COLLATE NOCASE = :username AND id <> :id LIMIT 1');
        $duplicate->execute([
            ':username' => $username,
            ':id' => $id,
        ]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('Ya existe un usuario con ese nombre.');
        }

        if ($id > 0) {
            $existing = fetchUserById($id);
            if (!$existing) {
                throw new RuntimeException('El usuario seleccionado no existe.');
            }
            if ($existing['role'] === 'admin' && $role !== 'admin' && adminUserCount((int) $existing['id']) < 1) {
                throw new RuntimeException('El sistema debe conservar al menos un administrador.');
            }
            if ($password !== '' && textLength($password) < 8) {
                throw new RuntimeException('La contrasena debe tener al menos 8 caracteres.');
            }
            $commercialPosition = trim((string) ($_POST['commercial_position'] ?? ''));
            $signatureImage = storeSignatureUpload(
                is_array($_FILES['signature_image'] ?? null) ? $_FILES['signature_image'] : [],
                (string) ($existing['signature_image'] ?? '')
            );
            $losesAuthorizationPermission = canDecideProformaAuthorization($existing)
                && !canDecideProformaAuthorization(['role' => $role]);
            $reassignedAuthorizationCount = 0;

            createDatabaseBackup();
            db()->beginTransaction();
            try {
                if ($password !== '') {
                    $stmt = db()->prepare(
                        'UPDATE users
                         SET username = :username, role = :role, first_name = :first_name, last_name = :last_name,
                             email = :email, phone = :phone, unit = :unit, reports_to_id = :reports_to_id,
                             commercial_position = :commercial_position, signature_image = :signature_image,
                             password_hash = :password_hash, auth_version = auth_version + 1
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username' => $username,
                        ':role' => $role,
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':unit' => $unit,
                        ':reports_to_id' => $reportsToId > 0 ? $reportsToId : null,
                        ':commercial_position' => $commercialPosition,
                        ':signature_image' => $signatureImage,
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':id' => $id,
                    ]);
                } else {
                    $stmt = db()->prepare(
                        'UPDATE users
                         SET username = :username, role = :role, first_name = :first_name, last_name = :last_name,
                             email = :email, phone = :phone, unit = :unit, reports_to_id = :reports_to_id
                             , commercial_position = :commercial_position, signature_image = :signature_image
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':username' => $username,
                        ':role' => $role,
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':unit' => $unit,
                        ':reports_to_id' => $reportsToId > 0 ? $reportsToId : null,
                        ':commercial_position' => $commercialPosition,
                        ':signature_image' => $signatureImage,
                        ':id' => $id,
                    ]);
                }
                if ($losesAuthorizationPermission) {
                    $reassignedAuthorizationCount = reassignPendingProformaAuthorizationsForDemotion(db(), $id);
                }
                syncUserCountryUnits(db(), $id, $countryUnitIds);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }

            if ($currentAdmin && $id === (int) $currentAdmin['id']) {
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $role;
                $authVersionStmt = db()->prepare('SELECT auth_version FROM users WHERE id = :id');
                $authVersionStmt->execute([':id' => $id]);
                $_SESSION['auth_version'] = (int) $authVersionStmt->fetchColumn();
            }
            setFlash(
                'success',
                ($password !== ''
                    ? 'Usuario y contraseña actualizados. Las sesiones anteriores del usuario fueron cerradas.'
                    : 'Usuario actualizado.')
                . ($reassignedAuthorizationCount > 0
                    ? ' Se reasignaron ' . $reassignedAuthorizationCount . ' autorizaciones pendientes.'
                    : '')
            );
        } else {
            if ($password === '') {
                throw new RuntimeException('La contrasena inicial es obligatoria.');
            }
            if (textLength($password) < 8) {
                throw new RuntimeException('La contrasena debe tener al menos 8 caracteres.');
            }
            $commercialPosition = trim((string) ($_POST['commercial_position'] ?? ''));
            $signatureImage = storeSignatureUpload(
                is_array($_FILES['signature_image'] ?? null) ? $_FILES['signature_image'] : []
            );

            createDatabaseBackup();
            $stmt = db()->prepare(
                'INSERT INTO users
                 (username, password_hash, role, first_name, last_name, email, phone, unit,
                  reports_to_id, commercial_position, signature_image, created_at)
                 VALUES
                 (:username, :password_hash, :role, :first_name, :last_name, :email, :phone, :unit,
                  :reports_to_id, :commercial_position, :signature_image, :created_at)'
            );
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':unit' => $unit,
                ':reports_to_id' => $reportsToId > 0 ? $reportsToId : null,
                ':commercial_position' => $commercialPosition,
                ':signature_image' => $signatureImage,
                ':created_at' => nowIso(),
            ]);
            syncUserCountryUnits(db(), (int) db()->lastInsertId(), $countryUnitIds);
            setFlash('success', 'Usuario creado.');
        }

        redirect('/users.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['edit'])) {
    $edit = fetchUserById((int) $_GET['edit']);
}

$reportingOptions = db()->query(
    'SELECT id, username, role, first_name, last_name, unit
     FROM users
     WHERE role IN (\'admin\', \'director\', \'manager\', \'supervisor\')
     ORDER BY first_name COLLATE NOCASE, last_name COLLATE NOCASE, username COLLATE NOCASE'
)->fetchAll();

$users = db()->query(
    'SELECT u.id, u.username, u.role, u.first_name, u.last_name, u.email, u.phone, u.unit,
            u.commercial_position, u.signature_image,
            u.reports_to_id, u.created_at,
            COALESCE(NULLIF(TRIM(leader.first_name || \' \' || leader.last_name), \'\'), leader.username, \'\') AS reports_to_name,
            COALESCE((
                SELECT GROUP_CONCAT(cu.name, \', \')
                FROM user_country_units ucu
                JOIN country_units cu ON cu.id = ucu.country_unit_id
                WHERE ucu.user_id = u.id
            ), u.unit) AS country_unit_names,
            (SELECT COUNT(*) FROM proformas p WHERE p.seller_id = u.id) AS proforma_count
     FROM users u
     LEFT JOIN users leader ON leader.id = u.reports_to_id
     ORDER BY u.username COLLATE NOCASE'
)->fetchAll();

$formRole = (string) ($edit['role'] ?? 'commercial_executive');
$isEditingCurrentUser = $edit && $currentAdmin && (int) $edit['id'] === (int) $currentAdmin['id'];
$selectedCountryUnitIds = normalizeCountryUnitIds(
    is_array($_POST['country_unit_ids'] ?? null)
        ? $_POST['country_unit_ids']
        : ($edit['country_unit_ids'] ?? [($defaultCountryUnit['id'] ?? 0)])
);
$selectedPrimaryCountryUnitId = (int) (
    $_POST['primary_country_unit_id']
    ?? (findCountryUnitByName(db(), (string) ($edit['unit'] ?? defaultCountry()))['id'] ?? ($defaultCountryUnit['id'] ?? 0))
);
$selectedAdditionalCountryUnitIds = array_values(array_filter(
    $selectedCountryUnitIds,
    static fn (int $countryUnitId): bool => $countryUnitId !== $selectedPrimaryCountryUnitId
));

renderHeader('Usuarios');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2><?= $edit ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
    <form method="post" enctype="multipart/form-data" class="grid-form">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? 0)) ?>">
        <label>
            Usuario
            <input name="username" required autocomplete="username" value="<?= e($edit['username'] ?? '') ?>">
        </label>
        <label>
            Rol
            <?php if ($isEditingCurrentUser): ?>
                <input type="hidden" name="role" value="<?= e($formRole) ?>">
                <select disabled>
                    <option value="<?= e($formRole) ?>"><?= e(userRoleLabel($formRole)) ?></option>
                </select>
            <?php else: ?>
                <select name="role" required>
                    <?php foreach ($allowedRoles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $formRole === $role ? 'selected' : '' ?>><?= e(userRoleLabel($role)) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </label>
        <label>
            Nombre
            <input name="first_name" value="<?= e($edit['first_name'] ?? '') ?>">
        </label>
        <label>
            Apellido
            <input name="last_name" value="<?= e($edit['last_name'] ?? '') ?>">
        </label>
        <label>
            Correo electronico
            <input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>">
        </label>
        <label>
            Celular
            <input name="phone" value="<?= e($edit['phone'] ?? '') ?>">
        </label>
        <label>
            Cargo comercial
            <input name="commercial_position" value="<?= e($edit['commercial_position'] ?? '') ?>" placeholder="Ej. Ejecutivo comercial">
        </label>
        <label>
            Imagen de firma
            <input type="file" name="signature_image" accept="image/png">
            <span class="field-hint">PNG de hasta 2 MB. Se conserva la imagen actual si no cargas otra.</span>
        </label>
        <div class="wide unit-assignment" data-unit-picker>
            <div class="unit-assignment-heading">
                <label>
                    Unidad principal
                    <select name="primary_country_unit_id" required data-primary-unit>
                        <?php foreach ($countryUnits as $countryUnit): ?>
                            <option value="<?= (int) $countryUnit['id'] ?>" <?= $selectedPrimaryCountryUnitId === (int) $countryUnit['id'] ? 'selected' : '' ?>>
                                <?= e($countryUnit['name']) ?> · <?= e($countryUnit['currency_symbol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button unit-add-button" type="button" data-add-unit>
                    <span aria-hidden="true">+</span> Agregar unidad
                </button>
            </div>
            <span class="field-hint">La unidad principal se usa en la firma y los filtros. Puedes agregar otras unidades al usuario.</span>
            <div class="unit-assignment-list" data-additional-units>
                <?php foreach ($selectedAdditionalCountryUnitIds as $selectedCountryUnitId): ?>
                    <div class="unit-assignment-row" data-unit-row>
                        <label>
                            <span>Unidad adicional</span>
                            <select name="country_unit_ids[]" data-additional-unit>
                                <option value="">Seleccionar unidad</option>
                                <?php foreach ($countryUnits as $countryUnit): ?>
                                    <option value="<?= (int) $countryUnit['id'] ?>" <?= $selectedCountryUnitId === (int) $countryUnit['id'] ? 'selected' : '' ?>>
                                        <?= e($countryUnit['name']) ?> · <?= e($countryUnit['currency_symbol']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="button danger small" type="button" data-remove-unit>Quitar</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-unit-row-template>
                <div class="unit-assignment-row" data-unit-row>
                    <label>
                        <span>Unidad adicional</span>
                        <select name="country_unit_ids[]" data-additional-unit>
                            <option value="">Seleccionar unidad</option>
                            <?php foreach ($countryUnits as $countryUnit): ?>
                                <option value="<?= (int) $countryUnit['id'] ?>">
                                    <?= e($countryUnit['name']) ?> · <?= e($countryUnit['currency_symbol']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button danger small" type="button" data-remove-unit>Quitar</button>
                </div>
            </template>
        </div>
        <label>
            Reporta a
            <?php $selectedLeaderId = (int) ($edit['reports_to_id'] ?? 0); ?>
            <select name="reports_to_id">
                <option value="0">Sin superior</option>
                <?php foreach ($reportingOptions as $leader): ?>
                    <?php if ($edit && (int) $leader['id'] === (int) $edit['id']) {
                        continue;
                    } ?>
                    <option value="<?= (int) $leader['id'] ?>" <?= $selectedLeaderId === (int) $leader['id'] ? 'selected' : '' ?>>
                        <?= e(userFullName($leader)) ?> - <?= e(userRoleLabel((string) $leader['role'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="wide">
            <?= $edit ? 'Nueva contrasena (opcional)' : 'Contraseña inicial' ?>
            <input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>>
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear usuario' ?></button>
            <?php if ($edit): ?><a class="button" href="<?= e(publicPath('/users.php')) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Usuarios del sistema</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Usuario</th>
                <th>Firma</th>
                <th>Rol</th>
                <th>Reporta a</th>
                <th>Proformas</th>
                <th>Creado</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <?= e($user['username']) ?>
                        <?php if ($currentAdmin && (int) $user['id'] === (int) $currentAdmin['id']): ?>
                            <span class="badge">Actual</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e(userFullName($user)) ?></strong><br>
                        <span class="muted"><?= e($user['commercial_position'] ?? '') ?></span><br>
                        <span class="muted"><?= e($user['email'] ?? '') ?></span><br>
                        <span class="muted"><?= e(trim((string) ($user['phone'] ?? '')) . (($user['phone'] ?? '') !== '' && ($user['country_unit_names'] ?? '') !== '' ? ' - ' : '') . trim((string) ($user['country_unit_names'] ?? ''))) ?></span>
                    </td>
                    <td><?= e(userRoleLabel((string) $user['role'])) ?></td>
                    <td><?= e($user['reports_to_name'] !== '' ? $user['reports_to_name'] : 'Sin superior') ?></td>
                    <td><?= e(formatInteger((int) $user['proforma_count'])) ?></td>
                    <td><?= e($user['created_at']) ?></td>
                    <td class="right">
                        <div class="table-actions">
                            <a href="<?= e(publicPath('/users.php?edit=' . (int) $user['id'])) ?>">Editar</a>
                            <?php if (!$currentAdmin || (int) $user['id'] !== (int) $currentAdmin['id']): ?>
                                <form method="post" onsubmit="return confirm('¿Eliminar este usuario? Esta acción no se puede deshacer.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                    <button class="button danger small" type="submit">Eliminar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script src="<?= e(publicPath('/assets/unit-picker.js')) ?>" defer></script>
<?php renderFooter(); ?>
