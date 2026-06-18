<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireManagementDashboard();

$currentUser = currentUser();
$allowedUnits = dashboardAllowedUnits(db(), $currentUser);
$sellers = dashboardSellerOptions(db(), $allowedUnits);
$filters = dashboardBuildFilters($_GET, $allowedUnits, $sellers);
$metrics = dashboardMetrics(db(), $filters);
$totals = dashboardTotals($metrics['units']);

function renderBarChart(array $rows, string $labelKey, array $series): void
{
    $max = 1.0;
    foreach ($rows as $row) {
        foreach ($series as $key => $_series) {
            $max = max($max, (float) ($row[$key] ?? 0));
        }
    }
    ?>
    <div class="bar-chart" role="img">
        <?php foreach ($rows as $row): ?>
            <div class="bar-chart-row">
                <strong><?= e((string) $row[$labelKey]) ?></strong>
                <div class="bar-chart-series">
                    <?php foreach ($series as $key => $definition): ?>
                        <?php $value = (float) ($row[$key] ?? 0); ?>
                        <div class="bar-chart-line">
                            <span><?= e($definition['label']) ?></span>
                            <div class="bar-chart-track">
                                <i style="width: <?= e((string) (($value / $max) * 100)) ?>%; background: <?= e($definition['color']) ?>"></i>
                            </div>
                            <b><?= e(formatNumber($value)) ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderEvolutionChart(array $rows): void
{
    $periods = array_values(array_unique(array_column($rows, 'period')));
    $units = array_values(array_unique(array_column($rows, 'unit')));
    $colors = ['#ff7a14', '#2563eb', '#059669', '#9333ea', '#dc2626', '#0891b2'];
    $values = [];
    $max = 1;
    foreach ($rows as $row) {
        $value = (int) ($row['emitted'] ?? 0);
        $values[(string) $row['unit']][(string) $row['period']] = $value;
        $max = max($max, $value);
    }
    $width = 760;
    $height = 260;
    $left = 42;
    $top = 18;
    $plotWidth = 690;
    $plotHeight = 190;
    ?>
    <?php if ($periods === []): ?>
        <p class="muted">No hay datos de evolución para el período seleccionado.</p>
    <?php else: ?>
        <div class="chart-legend">
            <?php foreach ($units as $index => $unit): ?>
                <span><i style="background: <?= e($colors[$index % count($colors)]) ?>"></i><?= e($unit) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="line-chart-wrap">
            <svg class="line-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Evolución de proformas emitidas por unidad país">
                <?php for ($line = 0; $line <= 4; $line++): ?>
                    <?php $y = $top + ($plotHeight * $line / 4); ?>
                    <line x1="<?= $left ?>" y1="<?= $y ?>" x2="<?= $left + $plotWidth ?>" y2="<?= $y ?>" class="chart-grid-line"/>
                    <text x="<?= $left - 8 ?>" y="<?= $y + 4 ?>" text-anchor="end"><?= e(formatNumber($max * (4 - $line) / 4)) ?></text>
                <?php endfor; ?>
                <?php foreach ($periods as $index => $period): ?>
                    <?php $x = count($periods) === 1 ? $left + ($plotWidth / 2) : $left + ($plotWidth * $index / (count($periods) - 1)); ?>
                    <text x="<?= $x ?>" y="<?= $top + $plotHeight + 28 ?>" text-anchor="middle"><?= e($period) ?></text>
                <?php endforeach; ?>
                <?php foreach ($units as $unitIndex => $unit): ?>
                    <?php
                    $points = [];
                    foreach ($periods as $index => $period) {
                        $x = count($periods) === 1 ? $left + ($plotWidth / 2) : $left + ($plotWidth * $index / (count($periods) - 1));
                        $value = (int) ($values[$unit][$period] ?? 0);
                        $y = $top + $plotHeight - (($value / $max) * $plotHeight);
                        $points[] = $x . ',' . $y;
                    }
                    $color = $colors[$unitIndex % count($colors)];
                    ?>
                    <polyline points="<?= e(implode(' ', $points)) ?>" fill="none" stroke="<?= e($color) ?>" stroke-width="3"/>
                    <?php foreach ($points as $point): ?>
                        <?php [$cx, $cy] = explode(',', $point); ?>
                        <circle cx="<?= e($cx) ?>" cy="<?= e($cy) ?>" r="4" fill="<?= e($color) ?>"/>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </svg>
        </div>
    <?php endif; ?>
    <?php
}

renderHeader('Dashboard gerencial');
?>
<section class="panel">
    <div class="section-title">
        <div>
            <h2>Indicadores comerciales Latam</h2>
            <span class="muted">Todos los montos comparativos se calculan sobre el total base guardado en USD.</span>
        </div>
        <a class="button small" href="<?= e(publicPath('/indicators.php')) ?>">Limpiar filtros</a>
    </div>
    <form method="get" class="grid-form dashboard-filters">
        <label>
            Rango de fecha
            <select name="date_range" id="date-range">
                <?php foreach ([
                    'current_month' => 'Este mes',
                    'previous_month' => 'Mes anterior',
                    'last_30_days' => 'Últimos 30 días',
                    'last_90_days' => 'Últimos 90 días',
                    'current_year' => 'Año actual',
                    'custom' => 'Personalizado',
                ] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['preset'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Desde
            <input type="date" name="date_from" value="<?= e($filters['from']) ?>">
        </label>
        <label>
            Hasta
            <input type="date" name="date_to" value="<?= e($filters['to']) ?>">
        </label>
        <label>
            Unidad país
            <select name="unit">
                <option value="">Todas las permitidas</option>
                <?php foreach ($allowedUnits as $unit): ?>
                    <option value="<?= e($unit['name']) ?>" <?= $filters['unit'] === $unit['name'] ? 'selected' : '' ?>><?= e($unit['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Ejecutivo comercial
            <select name="seller_id">
                <option value="0">Todos</option>
                <?php foreach ($sellers as $seller): ?>
                    <option value="<?= (int) $seller['id'] ?>" <?= $filters['seller_id'] === (int) $seller['id'] ? 'selected' : '' ?>><?= e(userFullName($seller)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Estado comercial
            <select name="commercial_status">
                <option value="">Todos</option>
                <?php foreach (commercialStatusOptions() as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['commercial_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Moneda emitida
            <select name="currency">
                <option value="">Todas</option>
                <?php foreach (currencyOptions() as $value => $currency): ?>
                    <option value="<?= e($value) ?>" <?= $filters['currency'] === $value ? 'selected' : '' ?>><?= e($currency['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Aplicar filtros</button>
        </div>
    </form>
</section>

<section class="stats-grid management-stats">
    <?php foreach ([
        ['value' => formatInteger($totals['emitted']), 'label' => 'Emitidos'],
        ['value' => formatInteger($totals['closed']), 'label' => 'Cerrados'],
        ['value' => formatInteger($totals['won']), 'label' => 'Ganados'],
        ['value' => formatInteger($totals['rejected']), 'label' => 'Rechazados / perdidos'],
        ['value' => formatInteger($totals['pending']), 'label' => 'Pendientes'],
        ['value' => formatNumber($totals['effectiveness']) . '%', 'label' => 'Índice de efectividad'],
        ['value' => formatMoney($totals['emitted_usd'], 'USD'), 'label' => 'Monto total emitido'],
        ['value' => formatMoney($totals['won_usd'], 'USD'), 'label' => 'Monto total ganado'],
    ] as $card): ?>
        <div class="stat"><div><strong><?= e($card['value']) ?></strong><span><?= e($card['label']) ?></span></div></div>
    <?php endforeach; ?>
</section>

<div class="dashboard-chart-grid">
    <section class="panel chart-panel">
        <h2>Emitidos por país</h2>
        <?php renderBarChart($metrics['units'], 'unit', ['emitted' => ['label' => 'Emitidos', 'color' => '#ff7a14']]); ?>
    </section>
    <section class="panel chart-panel">
        <h2>Ganados y cerrados por país</h2>
        <?php renderBarChart($metrics['units'], 'unit', [
            'won' => ['label' => 'Ganados', 'color' => '#059669'],
            'closed' => ['label' => 'Cerrados', 'color' => '#2563eb'],
        ]); ?>
    </section>
</div>

<section class="panel chart-panel">
    <h2>Evolución por país</h2>
    <?php renderEvolutionChart($metrics['evolution']); ?>
</section>

<section class="panel chart-panel">
    <h2>Efectividad por ejecutivo</h2>
    <?php renderBarChart($metrics['sellers'], 'seller', ['effectiveness' => ['label' => 'Efectividad %', 'color' => '#9333ea']]); ?>
</section>

<section class="panel">
    <h2>Resumen Latam por país</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>País / unidad</th><th>Emitidos</th><th>Ganados</th><th>Cerrados</th><th>Rechazados</th><th>Pendientes</th><th>Monto emitido</th><th>Monto ganado</th><th>Efectividad</th></tr></thead>
            <tbody>
            <?php foreach ($metrics['units'] as $row): ?>
                <tr>
                    <td><?= e($row['unit']) ?></td>
                    <td><?= e(formatInteger($row['emitted'])) ?></td>
                    <td><?= e(formatInteger($row['won'])) ?></td>
                    <td><?= e(formatInteger($row['closed'])) ?></td>
                    <td><?= e(formatInteger($row['rejected'])) ?></td>
                    <td><?= e(formatInteger($row['pending'])) ?></td>
                    <td class="right"><?= e(formatMoney($row['emitted_usd'], 'USD')) ?></td>
                    <td class="right"><?= e(formatMoney($row['won_usd'], 'USD')) ?></td>
                    <td><?= e(formatNumber($row['effectiveness'])) ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if ($metrics['units'] === []): ?><tr><td colspan="9" class="muted">Sin datos para los filtros seleccionados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Detalle por país y ejecutivo comercial</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>País / unidad</th><th>Ejecutivo</th><th>Emitidos</th><th>Ganados</th><th>Cerrados</th><th>Pendientes</th><th>Monto emitido</th><th>Monto ganado</th><th>Efectividad</th></tr></thead>
            <tbody>
            <?php foreach ($metrics['sellers'] as $row): ?>
                <tr>
                    <td><?= e($row['unit']) ?></td>
                    <td><?= e($row['seller']) ?></td>
                    <td><?= e(formatInteger($row['emitted'])) ?></td>
                    <td><?= e(formatInteger($row['won'])) ?></td>
                    <td><?= e(formatInteger($row['closed'])) ?></td>
                    <td><?= e(formatInteger($row['pending'])) ?></td>
                    <td class="right"><?= e(formatMoney($row['emitted_usd'], 'USD')) ?></td>
                    <td class="right"><?= e(formatMoney($row['won_usd'], 'USD')) ?></td>
                    <td><?= e(formatNumber($row['effectiveness'])) ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if ($metrics['sellers'] === []): ?><tr><td colspan="9" class="muted">Sin datos para los filtros seleccionados.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php renderFooter(); ?>
