<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$currentUser = currentUser();
$visibility = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'dashboard_visible');

$counts = [];
foreach (['clients', 'products', 'taxes'] as $table) {
    $counts[$table] = (int) db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
$countStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE ' . $visibility[0]
);
$countStmt->execute($visibility[1]);
$counts['proformas'] = (int) $countStmt->fetchColumn();

$summaryCards = [];
if (userAllowedPath($currentUser, '/clients.php')) {
    $summaryCards[] = [
        'count' => $counts['clients'],
        'label' => 'Clientes',
        'href' => publicPath('/clients.php'),
    ];
}
if (userAllowedPath($currentUser, '/products.php')) {
    $summaryCards[] = [
        'count' => $counts['products'],
        'label' => 'Productos',
        'href' => publicPath('/products.php'),
    ];
}
if (userAllowedPath($currentUser, '/taxes.php')) {
    $summaryCards[] = [
        'count' => $counts['taxes'],
        'label' => 'Impuestos',
        'href' => publicPath('/taxes.php'),
    ];
}
if (userAllowedPath($currentUser, '/proformas.php')) {
    $summaryCards[] = [
        'count' => $counts['proformas'],
        'label' => 'Proformas',
        'href' => publicPath('/proformas.php'),
    ];
}

$latestStmt = db()->prepare(
    'SELECT p.id, p.proforma_number, p.total, p.currency_code, p.currency_mode,
            p.currency_symbol, p.exchange_rate_used, p.created_at, c.empresa
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE ' . $visibility[0] . '
     ORDER BY p.id DESC
     LIMIT 5'
);
$latestStmt->execute($visibility[1]);
$latest = $latestStmt->fetchAll();

renderHeader('Dashboard');
?>
<section class="stats-grid">
    <?php foreach ($summaryCards as $card): ?>
        <div class="stat">
            <div>
                <strong><?= e(formatInteger((int) $card['count'])) ?></strong>
                <span><?= e($card['label']) ?></span>
            </div>
            <a class="button small stat-action" href="<?= e($card['href']) ?>">Ver todos</a>
        </div>
    <?php endforeach; ?>
</section>

<section class="toolbar">
    <?php if (canCreateProformas($currentUser)): ?>
        <a class="button primary" href="<?= e(publicPath('/proforma-new.php')) ?>">Nueva Proforma</a>
    <?php endif; ?>
    <?php if (userAllowedPath($currentUser, '/clients.php')): ?>
        <a class="button" href="<?= e(publicPath('/clients.php')) ?>">Crear Cliente</a>
    <?php endif; ?>
    <?php if (userAllowedPath($currentUser, '/products.php')): ?>
        <a class="button" href="<?= e(publicPath('/products.php')) ?>">Crear Producto</a>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Ultimas proformas</h2>
    <?php if (!$latest): ?>
        <p class="muted">Todavia no hay proformas.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Total</th>
                    <th>Fecha</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($latest as $row): ?>
                    <tr>
                        <td><?= e($row['proforma_number']) ?></td>
                        <td><?= e($row['empresa']) ?></td>
                        <td class="right"><?= e(formatProformaMoney((float) $row['total'], $row)) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td class="right"><a href="<?= e(publicPath('/proforma-preview.php?id=' . (int) $row['id'])) ?>">Ver</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
