<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$currentUser = currentUser();
$sellerFilter = max(0, (int) ($_GET['seller_id'] ?? 0));
$unitFilter = trim((string) ($_GET['unit'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$convertedFilter = (string) ($_GET['converted'] ?? 'all');

if ($unitFilter !== '' && !isAllowedCountry($unitFilter)) {
    $unitFilter = '';
}
if ($dateFrom !== '' && !isValidDate($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !isValidDate($dateTo)) {
    $dateTo = '';
}
if (!in_array($convertedFilter, ['all', 'won', 'open'], true)) {
    $convertedFilter = 'all';
}

$sellerRoleParams = [];
$sellerRolePlaceholders = [];
foreach (salesSignerRoles() as $index => $role) {
    $key = ':seller_role_' . $index;
    $sellerRoleParams[$key] = $role;
    $sellerRolePlaceholders[] = $key;
}
[$sellerVisibilitySql, $sellerVisibilityParams] = userVisibilityClause($currentUser, 'u', 'indicator_seller');
$sellersStmt = db()->prepare(
    'SELECT id, username, first_name, last_name
     FROM users u
     WHERE u.role IN (' . implode(', ', $sellerRolePlaceholders) . ')
       AND ' . $sellerVisibilitySql . '
     ORDER BY first_name COLLATE NOCASE, last_name COLLATE NOCASE, username COLLATE NOCASE'
);
$sellersStmt->execute($sellerRoleParams + $sellerVisibilityParams);
$sellers = $sellersStmt->fetchAll();
$visibleSellerIds = array_map(static fn (array $seller): int => (int) $seller['id'], $sellers);
if ($sellerFilter > 0 && !in_array($sellerFilter, $visibleSellerIds, true)) {
    $sellerFilter = 0;
}

[$proformaVisibilitySql, $proformaVisibilityParams] = proformaVisibilityClause($currentUser, 'p', 's', 'c', 'indicator_visible');
$kpiWhere = [$proformaVisibilitySql];
$kpiParams = $proformaVisibilityParams;
if ($sellerFilter > 0) {
    $kpiWhere[] = 'COALESCE(p.seller_id, p.created_by) = :seller_id';
    $kpiParams[':seller_id'] = $sellerFilter;
}
if ($unitFilter !== '') {
    $kpiWhere[] = "COALESCE(NULLIF(p.signer_unit, ''), c.pais) = :unit";
    $kpiParams[':unit'] = $unitFilter;
}
if ($dateFrom !== '') {
    $kpiWhere[] = 'p.emission_date >= :date_from';
    $kpiParams[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $kpiWhere[] = 'p.emission_date <= :date_to';
    $kpiParams[':date_to'] = $dateTo;
}
if ($convertedFilter === 'won') {
    $kpiWhere[] = "COALESCE(p.status, 'emitida') = 'venta_ganada'";
} elseif ($convertedFilter === 'open') {
    $kpiWhere[] = "COALESCE(p.status, 'emitida') <> 'venta_ganada'";
}

$whereSql = implode(' AND ', $kpiWhere);
$kpiStmt = db()->prepare(
    "SELECT 'USD' AS currency_code,
            COUNT(*) AS proforma_count,
            COALESCE(SUM(p.total), 0) AS total_emitido,
            COALESCE(SUM(CASE WHEN COALESCE(p.status, 'emitida') = 'venta_ganada' THEN 1 ELSE 0 END), 0) AS won_count,
            COALESCE(SUM(CASE WHEN COALESCE(p.status, 'emitida') = 'venta_ganada' THEN p.total ELSE 0 END), 0) AS won_total
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users s ON s.id = p.seller_id
     WHERE $whereSql"
);
$kpiStmt->execute($kpiParams);
$kpiRows = $kpiStmt->fetchAll();
$kpis = [
    'proforma_count' => 0,
    'won_count' => 0,
    'total_emitido_by_currency' => [],
    'won_total_by_currency' => [],
];
foreach ($kpiRows as $row) {
    $currencyCode = normalizeCurrencyCode((string) ($row['currency_code'] ?? defaultCurrencyCode()));
    $kpis['proforma_count'] += (int) ($row['proforma_count'] ?? 0);
    $kpis['won_count'] += (int) ($row['won_count'] ?? 0);
    $kpis['total_emitido_by_currency'][$currencyCode] = ($kpis['total_emitido_by_currency'][$currencyCode] ?? 0) + (float) ($row['total_emitido'] ?? 0);
    $kpis['won_total_by_currency'][$currencyCode] = ($kpis['won_total_by_currency'][$currencyCode] ?? 0) + (float) ($row['won_total'] ?? 0);
}
$conversionRate = (int) $kpis['proforma_count'] > 0
    ? ((int) $kpis['won_count'] / (int) $kpis['proforma_count']) * 100
    : 0.0;

$sellerSummaryStmt = db()->prepare(
    "SELECT COALESCE(NULLIF(TRIM(p.signer_name), ''),
                    NULLIF(TRIM(s.first_name || ' ' || s.last_name), ''),
                    s.username,
                    'Sin vendedor') AS seller_name,
            COALESCE(NULLIF(p.signer_unit, ''), c.pais) AS unit,
            'USD' AS currency_code,
            COUNT(*) AS proforma_count,
            COALESCE(SUM(p.total), 0) AS total_emitido,
            COALESCE(SUM(CASE WHEN COALESCE(p.status, 'emitida') = 'venta_ganada' THEN 1 ELSE 0 END), 0) AS won_count,
            COALESCE(SUM(CASE WHEN COALESCE(p.status, 'emitida') = 'venta_ganada' THEN p.total ELSE 0 END), 0) AS won_total
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users s ON s.id = p.seller_id
     WHERE $whereSql
     GROUP BY COALESCE(p.seller_id, p.created_by), seller_name, unit
     ORDER BY seller_name COLLATE NOCASE, total_emitido DESC"
);
$sellerSummaryStmt->execute($kpiParams);
$sellerSummary = $sellerSummaryStmt->fetchAll();

renderHeader('Indicadores');
?>
<section class="panel">
    <div class="section-title">
        <h2>Indicadores comerciales</h2>
        <a class="button small" href="<?= e(publicPath('/indicators.php')) ?>">Limpiar filtros</a>
    </div>
    <form method="get" class="grid-form kpi-filters">
        <label>
            Vendedor
            <select name="seller_id">
                <option value="0">Todos</option>
                <?php foreach ($sellers as $seller): ?>
                    <option value="<?= (int) $seller['id'] ?>" <?= $sellerFilter === (int) $seller['id'] ? 'selected' : '' ?>><?= e(userFullName($seller)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Unidad
            <select name="unit">
                <option value="">Todas</option>
                <?php foreach (countryOptions() as $country): ?>
                    <option value="<?= e($country) ?>" <?= $unitFilter === $country ? 'selected' : '' ?>><?= e($country) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Desde
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        </label>
        <label>
            Hasta
            <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        </label>
        <label>
            Convertidos
            <select name="converted">
                <option value="all" <?= $convertedFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                <option value="won" <?= $convertedFilter === 'won' ? 'selected' : '' ?>>Venta ganada</option>
                <option value="open" <?= $convertedFilter === 'open' ? 'selected' : '' ?>>No convertidos</option>
            </select>
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Filtrar</button>
        </div>
    </form>
    <div class="stats-grid kpi-grid">
        <div class="stat">
            <div class="money-breakdown">
                <?php foreach (formatMoneyBreakdown($kpis['total_emitido_by_currency']) as $line): ?>
                    <strong><?= e($line) ?></strong>
                <?php endforeach; ?>
                <span>Monto total emitido</span>
            </div>
        </div>
        <div class="stat">
            <div>
                <strong><?= e(formatInteger((int) $kpis['proforma_count'])) ?></strong>
                <span>Proformas emitidas</span>
            </div>
        </div>
        <div class="stat">
            <div class="money-breakdown">
                <?php foreach (formatMoneyBreakdown($kpis['won_total_by_currency']) as $line): ?>
                    <strong><?= e($line) ?></strong>
                <?php endforeach; ?>
                <span>Convertidos a venta ganada</span>
            </div>
        </div>
        <div class="stat">
            <div>
                <strong><?= e(formatNumber($conversionRate)) ?>%</strong>
                <span>Tasa de conversion</span>
            </div>
        </div>
    </div>
    <?php if ($sellerSummary): ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Vendedor</th>
                    <th>Unidad</th>
                    <th>Moneda</th>
                    <th>Proformas</th>
                    <th>Monto emitido</th>
                    <th>Venta ganada</th>
                    <th>Monto convertido</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sellerSummary as $row): ?>
                    <tr>
                        <td><?= e($row['seller_name']) ?></td>
                        <td><?= e($row['unit']) ?></td>
                        <td><?= e(normalizeCurrencyCode((string) ($row['currency_code'] ?? defaultCurrencyCode()))) ?></td>
                        <td><?= e(formatInteger((int) $row['proforma_count'])) ?></td>
                        <td class="right"><?= e(formatMoney((float) $row['total_emitido'], (string) ($row['currency_code'] ?? defaultCurrencyCode()))) ?></td>
                        <td><?= e(formatInteger((int) $row['won_count'])) ?></td>
                        <td class="right"><?= e(formatMoney((float) $row['won_total'], (string) ($row['currency_code'] ?? defaultCurrencyCode()))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="muted">No hay proformas para los filtros seleccionados.</p>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
