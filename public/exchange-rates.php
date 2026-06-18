<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireExchangeRateManager();

$pdo = db();
$currentUser = currentUser();
$units = countryUnits($pdo);
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $countryUnitId = (int) ($_POST['country_unit_id'] ?? 0);
        $rateFromUsd = parseDecimalInput((string) ($_POST['rate_from_usd'] ?? '0'));

        createDatabaseBackup();
        saveExchangeRate($pdo, $countryUnitId, $rateFromUsd, (int) ($currentUser['id'] ?? 0));
        setFlash('success', 'Tipo de cambio actualizado. El valor anterior quedó en el historial.');
        redirect('/exchange-rates.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$activeRates = [];
foreach ($units as $unit) {
    $rate = findActiveExchangeRate($pdo, (int) $unit['id']);
    if ($rate) {
        $activeRates[(int) $unit['id']] = $rate;
    }
}

$history = $pdo->query(
    'SELECT er.*, cu.name AS country_unit_name,
            COALESCE(NULLIF(TRIM(u.first_name || \' \' || u.last_name), \'\'), u.username, \'Usuario no disponible\') AS created_by_name
     FROM exchange_rates er
     JOIN country_units cu ON cu.id = er.country_unit_id
     LEFT JOIN users u ON u.id = er.created_by
     ORDER BY er.created_at DESC, er.id DESC'
)->fetchAll();

renderHeader('Cambio de divisas');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2>Tipos de cambio vigentes</h2>
    <p class="muted">La lista de precios está expresada en USD. Registra cuántas unidades de moneda local equivalen a 1 US$.</p>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Unidad país</th>
                <th>Moneda local</th>
                <th>Tipo de cambio vigente</th>
                <th>Actualizar</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($units as $unit): ?>
                <?php $activeRate = $activeRates[(int) $unit['id']] ?? null; ?>
                <tr>
                    <td><?= e($unit['name']) ?></td>
                    <td><?= e($unit['currency_symbol']) ?> · <?= e($unit['currency_code']) ?></td>
                    <td>
                        <?php if ($activeRate): ?>
                            1 US$ = <?= e(formatNumber((float) $activeRate['rate_from_usd'])) ?> <?= e($unit['currency_symbol']) ?>
                        <?php else: ?>
                            <span class="badge danger">Sin configurar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" class="exchange-rate-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="country_unit_id" value="<?= (int) $unit['id'] ?>">
                            <label class="sr-only" for="rate-<?= (int) $unit['id'] ?>">Tipo de cambio para <?= e($unit['name']) ?></label>
                            <input
                                id="rate-<?= (int) $unit['id'] ?>"
                                type="number"
                                name="rate_from_usd"
                                min="0.000001"
                                step="0.000001"
                                required
                                value="<?= e($activeRate ? (string) $activeRate['rate_from_usd'] : '') ?>"
                                placeholder="Ej. 7500"
                            >
                            <button class="button primary small" type="submit">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Historial</h2>
    <?php if ($history === []): ?>
        <p class="muted">Todavía no se registraron tipos de cambio.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Unidad país</th>
                    <th>Valor</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                    <th>Fecha</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $rate): ?>
                    <tr>
                        <td><?= e($rate['country_unit_name']) ?></td>
                        <td>1 US$ = <?= e(formatNumber((float) $rate['rate_from_usd'])) ?> <?= e($rate['currency_symbol']) ?></td>
                        <td>
                            <span class="badge <?= (int) $rate['is_active'] === 1 ? 'success' : '' ?>">
                                <?= (int) $rate['is_active'] === 1 ? 'Vigente' : 'Histórico' ?>
                            </span>
                        </td>
                        <td><?= e($rate['created_by_name']) ?></td>
                        <td><?= e(formatDateTimeShort($rate['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
