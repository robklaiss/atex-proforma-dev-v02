<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAdmin();

if (isset($_GET['download'])) {
    $path = resolveBackupPath((string) $_GET['download']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $backup = createDatabaseBackup();
            setFlash('success', $backup ? 'Backup creado: ' . basename($backup) : 'No existe base de datos para respaldar.');
        } elseif ($action === 'restore') {
            $filename = (string) ($_POST['filename'] ?? '');
            restoreDatabaseBackup($filename);
            setFlash('success', 'Backup restaurado: ' . safeBasename($filename));
        }
        redirect('/backups.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$backups = listDatabaseBackups();

renderHeader('Backups');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="toolbar">
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <button class="button primary" type="submit">Crear backup ahora</button>
    </form>
</section>

<section class="panel">
    <h2>Backups disponibles</h2>
    <?php if (!$backups): ?>
        <p class="muted">Todavia no hay backups.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Fecha</th>
                    <th>Tamano</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?= e($backup['name']) ?></td>
                        <td><?= e(date('Y-m-d H:i:s', $backup['mtime'])) ?></td>
                        <td><?= e(formatFileSize((int) $backup['size'])) ?></td>
                        <td class="actions-cell">
                            <a class="button small" href="<?= e(publicPath('/backups.php?download=' . urlencode($backup['name']))) ?>">Descargar</a>
                            <form method="post" onsubmit="return confirm('Restaurar este backup reemplazara la base actual. Se creara un backup previo. Continuar?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="filename" value="<?= e($backup['name']) ?>">
                                <button class="button danger small" type="submit">Restaurar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
