<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$error = null;
$edit = null;
$countryOptions = countryOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $porcentaje = (float) str_replace(',', '.', (string) ($_POST['porcentaje'] ?? '0'));
        $paises = trim((string) ($_POST['paises'] ?? ''));
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombre === '') {
            throw new RuntimeException('El nombre del impuesto es obligatorio.');
        }
        if ($porcentaje < 0) {
            throw new RuntimeException('El porcentaje no puede ser negativo.');
        }
        if (!in_array($paises, $countryOptions, true)) {
            throw new RuntimeException('Selecciona un país valido.');
        }

        createDatabaseBackup();
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE taxes SET nombre = :nombre, porcentaje = :porcentaje, paises = :paises, activo = :activo WHERE id = :id');
            $stmt->execute([
                ':nombre' => $nombre,
                ':porcentaje' => $porcentaje,
                ':paises' => $paises,
                ':activo' => $activo,
                ':id' => $id,
            ]);
            setFlash('success', 'Impuesto actualizado.');
        } else {
            $stmt = db()->prepare('INSERT INTO taxes (nombre, porcentaje, paises, activo, created_at) VALUES (:nombre, :porcentaje, :paises, :activo, :created_at)');
            $stmt->execute([
                ':nombre' => $nombre,
                ':porcentaje' => $porcentaje,
                ':paises' => $paises,
                ':activo' => $activo,
                ':created_at' => nowIso(),
            ]);
            setFlash('success', 'Impuesto creado.');
        }
        redirect('/taxes.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM taxes WHERE id = :id');
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$taxes = db()->query('SELECT * FROM taxes ORDER BY activo DESC, porcentaje DESC, nombre COLLATE NOCASE')->fetchAll();

renderHeader('Impuestos');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2><?= $edit ? 'Editar impuesto' : 'Nuevo impuesto' ?></h2>
    <form method="post" class="grid-form compact">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? 0)) ?>">
        <label>
            Nombre
            <input name="nombre" required value="<?= e($edit['nombre'] ?? '') ?>">
        </label>
        <label>
            Porcentaje
            <input type="number" min="0" step="0.01" name="porcentaje" value="<?= e((string) ($edit['porcentaje'] ?? '0')) ?>">
        </label>
        <label>
            País
            <select name="paises" required>
                <option value="">Seleccionar país</option>
                <?php foreach ($countryOptions as $country): ?>
                    <option value="<?= e($country) ?>" <?= ($edit['paises'] ?? '') === $country ? 'selected' : '' ?>><?= e($country) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="check-row">
            <input type="checkbox" name="activo" <?= !isset($edit['activo']) || (int) $edit['activo'] === 1 ? 'checked' : '' ?>>
            Activo
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit"><?= $edit ? 'Guardar cambios' : 'Crear impuesto' ?></button>
            <?php if ($edit): ?><a class="button" href="<?= e(publicPath('/taxes.php')) ?>">Cancelar</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Impuestos configurados</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Nombre</th>
                <th class="right">Porcentaje</th>
                <th>País</th>
                <th>Estado</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($taxes as $tax): ?>
                <tr>
                    <td><?= e($tax['nombre']) ?></td>
                    <td class="right"><?= e(formatNumber((float) $tax['porcentaje'])) ?>%</td>
                    <td><?= e($tax['paises'] ?? '') ?></td>
                    <td><?= (int) $tax['activo'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td class="right"><a href="<?= e(publicPath('/taxes.php?edit=' . (int) $tax['id'])) ?>">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderFooter(); ?>
