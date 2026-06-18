<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireDisclaimerManager();

$pdo = db();
$currentUser = currentUser();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        createDatabaseBackup();
        $action = (string) ($_POST['action'] ?? '');
        $payload = [
            'title' => $_POST['title'] ?? '',
            'body' => $_POST['body'] ?? '',
            'is_active' => isset($_POST['is_active']),
            'is_default' => isset($_POST['is_default']),
            'sort_order' => $_POST['sort_order'] ?? 0,
        ];
        if ($action === 'create') {
            createProformaDisclaimer($pdo, $payload, (int) $currentUser['id']);
            setFlash('success', 'Disclaimer creado.');
        } elseif ($action === 'update') {
            updateProformaDisclaimer($pdo, (int) ($_POST['id'] ?? 0), $payload, (int) $currentUser['id']);
            setFlash('success', 'Disclaimer actualizado.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        redirect('/disclaimers.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$disclaimers = listProformaDisclaimers($pdo);
renderHeader('Notas y disclaimers');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2>Nuevo disclaimer</h2>
    <form method="post" class="grid-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label>
            Título
            <input name="title" maxlength="120" required>
        </label>
        <label>
            Orden
            <input type="number" name="sort_order" min="0" value="<?= (count($disclaimers) + 1) * 10 ?>">
        </label>
        <label class="wide">
            Texto
            <textarea name="body" maxlength="4000" rows="4" required></textarea>
        </label>
        <label class="checkbox-row"><input type="checkbox" name="is_active" checked> Activo</label>
        <label class="checkbox-row"><input type="checkbox" name="is_default" checked> Incluir por defecto</label>
        <div class="wide"><button class="button primary" type="submit">Crear disclaimer</button></div>
    </form>
</section>

<section class="panel">
    <h2>Disclaimers configurados</h2>
    <?php if ($disclaimers === []): ?>
        <p class="muted">No hay disclaimers configurados.</p>
    <?php endif; ?>
    <div class="settings-card-list">
        <?php foreach ($disclaimers as $disclaimer): ?>
            <form method="post" class="settings-card">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $disclaimer['id'] ?>">
                <div class="grid-form">
                    <label>
                        Título
                        <input name="title" maxlength="120" required value="<?= e($disclaimer['title']) ?>">
                    </label>
                    <label>
                        Orden
                        <input type="number" name="sort_order" min="0" value="<?= (int) $disclaimer['sort_order'] ?>">
                    </label>
                    <label class="wide">
                        Texto
                        <textarea name="body" maxlength="4000" rows="4" required><?= e($disclaimer['body']) ?></textarea>
                    </label>
                    <label class="checkbox-row"><input type="checkbox" name="is_active" <?= (int) $disclaimer['is_active'] === 1 ? 'checked' : '' ?>> Activo</label>
                    <label class="checkbox-row"><input type="checkbox" name="is_default" <?= (int) $disclaimer['is_default'] === 1 ? 'checked' : '' ?>> Incluir por defecto</label>
                    <div class="wide"><button class="button" type="submit">Guardar cambios</button></div>
                </div>
            </form>
        <?php endforeach; ?>
    </div>
</section>
<?php renderFooter(); ?>
