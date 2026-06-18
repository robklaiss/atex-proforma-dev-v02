<?php

declare(strict_types=1);

function renderHeader(string $title, bool $showPageHeader = true): void
{
    $currentUser = isLoggedIn() ? currentUser() : null;
    $isAdmin = isAdmin($currentUser);
    if (isCommercialAssistant($currentUser)) {
        $nav = [];
    } elseif (isDirector($currentUser)) {
        $nav = [
            '/dashboard.php' => 'Dashboard',
            '/indicators.php' => 'Indicadores',
            '/proformas.php' => 'Proformas',
            '/proforma-authorizations.php' => 'Autorizaciones',
        ];
    } else {
        $nav = [
            '/dashboard.php' => 'Dashboard',
            '/clients.php' => 'Clientes',
            '/products.php' => 'Productos',
            '/taxes.php' => 'Impuestos',
            '/proforma-new.php' => 'Nueva Proforma',
            '/proformas.php' => 'Proformas',
            '/proforma-authorizations.php' => 'Autorizaciones',
        ];
        if (canViewManagementDashboard($currentUser)) {
            $nav = array_slice($nav, 0, 1, true)
                + ['/indicators.php' => 'Indicadores']
                + array_slice($nav, 1, null, true);
        }
    }
    $configurationNav = [
        '/profile.php' => 'Perfil',
    ];
    if ($currentUser && !isCommercialAssistant($currentUser)) {
        $configurationNav += [
            '/delivery-log.php' => 'Log',
        ];
    }
    if (canManageExchangeRates($currentUser)) {
        $configurationNav += [
            '/exchange-rates.php' => 'Unidad Organizativa',
        ];
    }
    if (canManageDisclaimers($currentUser)) {
        $configurationNav += [
            '/disclaimers.php' => 'Notas y disclaimers',
        ];
    }
    if ($isAdmin) {
        $configurationNav += [
            '/users.php' => 'Usuarios',
            '/backups.php' => 'Backups',
        ];
    }
    $current = currentPublicPath();
    $configurationActive = array_key_exists($current, $configurationNav);
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | ATEX Proforma</title>
    <link rel="stylesheet" href="<?= e(publicPath('/assets/style.css')) ?>">
</head>
<body>
    <?php if ($currentUser): ?>
    <aside class="sidebar">
        <div class="sidebar-header">
            <a class="brand" href="<?= e(publicPath(userHomePath($currentUser))) ?>">
                <img class="brand-logo" src="<?= e(publicPath('/assets/atex_latam_logo.png')) ?>" alt="ATEX LATAM" width="160">
            </a>
            <button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="main-navigation">
                <span class="mobile-menu-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
                <span class="sr-only">Abrir menú</span>
            </button>
        </div>
        <nav id="main-navigation">
            <?php foreach ($nav as $href => $label): ?>
                <a href="<?= e(publicPath($href)) ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($configurationNav): ?>
                <details class="nav-group nav-settings" <?= $configurationActive ? 'open' : '' ?>>
                    <summary class="nav-group-title">
                        <span>Configuración</span>
                        <span class="nav-group-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="nav-subnav">
                        <?php foreach ($configurationNav as $href => $label): ?>
                            <a href="<?= e(publicPath($href)) ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
    </aside>
    <?php endif; ?>
<main class="<?= $currentUser ? 'content' : 'auth-content' ?>">
    <?php if ($showPageHeader): ?>
        <header class="page-header">
            <div class="page-header-title">
                <h1><?= e($title) ?></h1>
            </div>
            <?php if ($currentUser): ?>
                <div class="page-header-actions">
                    <span class="page-user"><?= e($currentUser['username']) ?></span>
                    <a class="button header-logout" href="<?= e(publicPath('/logout.php')) ?>">Salir</a>
                </div>
            <?php endif; ?>
        </header>
    <?php endif; ?>
    <?php renderFlashMessages(); ?>
<?php
}

function renderFooter(): void
{
    ?>
</main>
<?php if (isLoggedIn()): ?>
<script>
(function () {
    var toggle = document.querySelector('.mobile-menu-toggle');
    var nav = document.getElementById('main-navigation');

    if (!toggle || !nav) {
        return;
    }

    toggle.addEventListener('click', function () {
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        nav.classList.toggle('is-open', !expanded);
    });

    var settings = nav.querySelector('.nav-settings');
    if (settings) {
        settings.addEventListener('toggle', function () {
            if (!settings.open) {
                return;
            }

            window.requestAnimationFrame(function () {
                settings.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest'
                });
            });
        });
    }

    var activeItem = nav.querySelector('a.active');
    if (activeItem) {
        window.requestAnimationFrame(function () {
            activeItem.scrollIntoView({
                block: 'nearest',
                inline: 'nearest'
            });
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
}
