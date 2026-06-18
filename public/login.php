<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

if (isLoggedIn()) {
    redirect(userHomePath());
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (attemptLogin($username, $password)) {
            redirect(userHomePath());
        }
        $error = 'Usuario o contraseña incorrectos.';
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

renderHeader('Ingresar', false);
?>
<section class="login-panel">
    <div class="login-logo">
        <img class="brand-logo" src="<?= e(publicPath('/assets/atex_latam_logo.png')) ?>" alt="ATEX LATAM" width="160">
    </div>
    <?php if (!is_file(SQLITE_PATH)): ?>
        <div class="flash error">La base de datos no existe. Ejecuta <code>php scripts/install.php</code>.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="flash error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="form-card">
        <?= csrfField() ?>
        <label>
            Usuario
            <input type="text" name="username" required autofocus autocomplete="username">
        </label>
        <label>
            Contraseña
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="button primary" type="submit">Ingresar</button>
    </form>
</section>
<?php renderFooter(); ?>
