<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$error = null;
$currentUser = currentUser();
$countryUnits = countryUnits(db());
$currentUser['country_unit_ids'] = userCountryUnitIds(db(), (int) ($currentUser['id'] ?? 0));
$defaultCountryUnit = findCountryUnitByName(db(), defaultCountry());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();

        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $commercialPosition = trim((string) ($_POST['commercial_position'] ?? ''));
        $signatureImage = storeSignatureUpload(
            is_array($_FILES['signature_image'] ?? null) ? $_FILES['signature_image'] : [],
            (string) ($currentUser['signature_image'] ?? '')
        );
        $primaryCountryUnitId = (int) ($_POST['primary_country_unit_id'] ?? 0);
        $additionalCountryUnitIds = is_array($_POST['country_unit_ids'] ?? null) ? $_POST['country_unit_ids'] : [];
        $countryUnitIds = validateCountryUnitIds(db(), array_merge([$primaryCountryUnitId], $additionalCountryUnitIds));
        $primaryCountryUnit = findCountryUnitById(db(), $primaryCountryUnitId);
        if (!$primaryCountryUnit) {
            throw new RuntimeException('La unidad principal seleccionada no es válida.');
        }
        $unit = (string) $primaryCountryUnit['name'];
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo electronico no tiene un formato valido.');
        }
        if ($newPassword !== '' || $confirmPassword !== '' || $currentPassword !== '') {
            if ($newPassword === '' || $confirmPassword === '' || $currentPassword === '') {
                throw new RuntimeException('Completa la contrasena actual, la nueva y la confirmacion.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('La nueva contrasena y su confirmacion no coinciden.');
            }
            if (textLength($newPassword) < 8) {
                throw new RuntimeException('La nueva contrasena debe tener al menos 8 caracteres.');
            }

            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute([':id' => (int) ($currentUser['id'] ?? 0)]);
            $passwordHash = (string) $stmt->fetchColumn();
            if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
                throw new RuntimeException('La contrasena actual no es correcta.');
            }
        }

        createDatabaseBackup();
        if ($newPassword !== '') {
            $stmt = db()->prepare(
                'UPDATE users
                 SET first_name = :first_name, last_name = :last_name, email = :email,
                     phone = :phone, unit = :unit, commercial_position = :commercial_position,
                     signature_image = :signature_image, password_hash = :password_hash,
                     auth_version = auth_version + 1
                 WHERE id = :id'
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':unit' => $unit,
                ':commercial_position' => $commercialPosition,
                ':signature_image' => $signatureImage,
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => (int) ($currentUser['id'] ?? 0),
            ]);
            $authVersionStmt = db()->prepare('SELECT auth_version FROM users WHERE id = :id');
            $authVersionStmt->execute([':id' => (int) ($currentUser['id'] ?? 0)]);
            $_SESSION['auth_version'] = (int) $authVersionStmt->fetchColumn();
        } else {
            $stmt = db()->prepare(
                'UPDATE users
                 SET first_name = :first_name, last_name = :last_name, email = :email,
                     phone = :phone, unit = :unit, commercial_position = :commercial_position,
                     signature_image = :signature_image
                 WHERE id = :id'
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':unit' => $unit,
                ':commercial_position' => $commercialPosition,
                ':signature_image' => $signatureImage,
                ':id' => (int) ($currentUser['id'] ?? 0),
            ]);
        }

        syncUserCountryUnits(db(), (int) ($currentUser['id'] ?? 0), $countryUnitIds);
        setFlash('success', 'Perfil actualizado.');
        redirect('/profile.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $currentUser = array_merge($currentUser ?: [], [
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'commercial_position' => $_POST['commercial_position'] ?? '',
            'signature_image' => $signatureImage ?? ($currentUser['signature_image'] ?? ''),
            'unit' => $unit ?? defaultCountry(),
            'country_unit_ids' => $countryUnitIds ?? normalizeCountryUnitIds(array_merge(
                [(int) ($_POST['primary_country_unit_id'] ?? 0)],
                is_array($_POST['country_unit_ids'] ?? null) ? $_POST['country_unit_ids'] : []
            )),
        ]);
    }
}

$selectedCountryUnitIds = normalizeCountryUnitIds(
    is_array($_POST['country_unit_ids'] ?? null)
        ? $_POST['country_unit_ids']
        : ($currentUser['country_unit_ids'] ?? [($defaultCountryUnit['id'] ?? 0)])
);
$selectedPrimaryCountryUnitId = (int) (
    $_POST['primary_country_unit_id']
    ?? (findCountryUnitByName(db(), (string) ($currentUser['unit'] ?? defaultCountry()))['id'] ?? ($defaultCountryUnit['id'] ?? 0))
);
$selectedAdditionalCountryUnitIds = array_values(array_filter(
    $selectedCountryUnitIds,
    static fn (int $countryUnitId): bool => $countryUnitId !== $selectedPrimaryCountryUnitId
));

renderHeader('Perfil');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2>Datos de firma</h2>
    <form method="post" enctype="multipart/form-data" class="grid-form">
        <?= csrfField() ?>
        <label>
            Usuario
            <input value="<?= e($currentUser['username'] ?? '') ?>" readonly>
        </label>
        <label>
            Rol
            <input value="<?= e(userRoleLabel((string) ($currentUser['role'] ?? 'commercial_executive'))) ?>" readonly>
        </label>
        <label>
            Nombre
            <input name="first_name" value="<?= e($currentUser['first_name'] ?? '') ?>">
        </label>
        <label>
            Apellido
            <input name="last_name" value="<?= e($currentUser['last_name'] ?? '') ?>">
        </label>
        <label>
            Correo electronico
            <input type="email" name="email" value="<?= e($currentUser['email'] ?? '') ?>">
        </label>
        <label>
            Celular
            <input name="phone" value="<?= e($currentUser['phone'] ?? '') ?>">
        </label>
        <label>
            Cargo comercial
            <input name="commercial_position" value="<?= e($currentUser['commercial_position'] ?? '') ?>" placeholder="Ej. Ejecutivo comercial">
        </label>
        <label>
            Imagen de firma
            <input type="file" name="signature_image" accept="image/png">
            <span class="field-hint">PNG de hasta 2 MB. Si no cargas una nueva imagen, se conserva la actual.</span>
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
        <div class="wide signature-preview">
            <strong>Vista de firma</strong>
            <?php $signature = userSignature($currentUser ?: []); ?>
            <span><?= e($signature['name'] !== '' ? $signature['name'] : emptyFieldMarker()) ?></span>
            <span><?= e($signature['position'] !== '' ? $signature['position'] : emptyFieldMarker()) ?></span>
            <span><?= e($signature['email'] !== '' ? $signature['email'] : emptyFieldMarker()) ?></span>
            <span><?= e($signature['phone'] !== '' ? $signature['phone'] : emptyFieldMarker()) ?></span>
            <span><?= e($signature['unit'] !== '' ? $signature['unit'] : emptyFieldMarker()) ?></span>
            <?php if ($signature['signature_image'] !== ''): ?>
                <img class="signature-image-preview" src="<?= e(publicPath('/signature-image.php?user_id=' . (int) ($currentUser['id'] ?? 0))) ?>" alt="Firma manuscrita">
            <?php else: ?>
                <span>Firma manuscrita: <?= e(emptyFieldMarker()) ?></span>
            <?php endif; ?>
        </div>
        <label>
            Contrasena actual
            <input type="password" name="current_password" autocomplete="current-password">
        </label>
        <label>
            Nueva contrasena
            <input type="password" name="new_password" autocomplete="new-password">
        </label>
        <label>
            Confirmar nueva contrasena
            <input type="password" name="confirm_password" autocomplete="new-password">
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit">Guardar perfil</button>
        </div>
    </form>
</section>
<script src="<?= e(publicPath('/assets/unit-picker.js')) ?>" defer></script>
<?php renderFooter(); ?>
