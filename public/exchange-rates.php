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
        createDatabaseBackup();
        $createdBy = (int) ($currentUser['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'create') {
            $rateFromUsd = parseOptionalExchangeRate($_POST['rate_from_usd'] ?? null);
            if ($rateFromUsd === null) {
                throw new RuntimeException('La cotización ante el dólar es obligatoria.');
            }

            saveCountryUnit(
                $pdo,
                0,
                [
                    'name' => $_POST['name'] ?? '',
                    'currency_symbol' => $_POST['currency_symbol'] ?? '',
                    'currency_code' => $_POST['currency_code'] ?? '',
                ],
                $rateFromUsd,
                $createdBy
            );
            setFlash('success', 'Unidad organizativa creada.');
        } elseif ($action === 'delete') {
            deactivateCountryUnit($pdo, (int) ($_POST['country_unit_id'] ?? 0));
            setFlash('success', 'Unidad organizativa eliminada.');
        } elseif ($action === 'save') {
            $submittedUnits = $_POST['units'] ?? [];
            $submittedRates = $_POST['rates'] ?? [];
            if (!is_array($submittedUnits) || !is_array($submittedRates)) {
                throw new RuntimeException('Los datos enviados no son válidos.');
            }

            $pdo->beginTransaction();
            try {
                $updated = 0;
                foreach ($submittedUnits as $countryUnitId => $submittedUnit) {
                    if (!is_array($submittedUnit)) {
                        throw new RuntimeException('Los datos de una unidad país no son válidos.');
                    }

                    $result = saveCountryUnit(
                        $pdo,
                        (int) $countryUnitId,
                        $submittedUnit,
                        parseOptionalExchangeRate($submittedRates[$countryUnitId] ?? null),
                        $createdBy
                    );
                    if ($result['unit_changed'] || $result['rate_changed']) {
                        $updated++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            setFlash(
                'success',
                $updated > 0
                    ? ($updated === 1 ? 'Se actualizó 1 unidad organizativa.' : 'Se actualizaron ' . $updated . ' unidades organizativas.')
                    : 'No había cambios para guardar.'
            );
        } else {
            throw new RuntimeException('Acción no válida.');
        }

        redirect('/exchange-rates.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$units = countryUnits($pdo);
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

renderHeader('Unidad Organizativa');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2>Nueva unidad organizativa</h2>
    <p class="muted">Agrega una unidad país con su moneda local y la cotización de esa moneda ante 1 US$.</p>
    <form method="post" class="grid-form compact">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label>
            Unidad país
            <input name="name" maxlength="120" required placeholder="Ej. Perú">
        </label>
        <label>
            Símbolo de moneda
            <input name="currency_symbol" maxlength="16" required placeholder="Ej. S/">
        </label>
        <label>
            Código de moneda
            <input name="currency_code" maxlength="3" pattern="[A-Za-z]{3}" required placeholder="Ej. PEN">
        </label>
        <label>
            Cotización ante el dólar
            <input type="number" name="rate_from_usd" min="0.000001" step="0.000001" required placeholder="Moneda local por 1 US$">
        </label>
        <div class="form-actions wide">
            <button class="button primary" type="submit">Agregar unidad</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Unidades organizativas</h2>
    <p class="muted">La lista de precios está expresada en USD. Puedes editar los datos y guardar todas las modificaciones en conjunto.</p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="table-wrap">
            <table class="organizational-unit-table">
                <thead>
                <tr>
                    <th>Unidad país</th>
                    <th>Símbolo</th>
                    <th>Código</th>
                    <th>Cotización ante 1 US$</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($units as $unit): ?>
                    <?php $activeRate = $activeRates[(int) $unit['id']] ?? null; ?>
                    <tr>
                        <td>
                            <label class="sr-only" for="unit-name-<?= (int) $unit['id'] ?>">Unidad país</label>
                            <input
                                id="unit-name-<?= (int) $unit['id'] ?>"
                                name="units[<?= (int) $unit['id'] ?>][name]"
                                maxlength="120"
                                required
                                value="<?= e($unit['name']) ?>"
                            >
                        </td>
                        <td>
                            <label class="sr-only" for="unit-symbol-<?= (int) $unit['id'] ?>">Símbolo de moneda</label>
                            <input
                                id="unit-symbol-<?= (int) $unit['id'] ?>"
                                name="units[<?= (int) $unit['id'] ?>][currency_symbol]"
                                maxlength="16"
                                required
                                value="<?= e($unit['currency_symbol']) ?>"
                            >
                        </td>
                        <td>
                            <label class="sr-only" for="unit-code-<?= (int) $unit['id'] ?>">Código de moneda</label>
                            <input
                                id="unit-code-<?= (int) $unit['id'] ?>"
                                name="units[<?= (int) $unit['id'] ?>][currency_code]"
                                maxlength="3"
                                pattern="[A-Za-z]{3}"
                                required
                                value="<?= e($unit['currency_code']) ?>"
                            >
                        </td>
                        <td>
                            <label class="sr-only" for="rate-<?= (int) $unit['id'] ?>">Cotización para <?= e($unit['name']) ?></label>
                            <input
                                id="rate-<?= (int) $unit['id'] ?>"
                                type="number"
                                name="rates[<?= (int) $unit['id'] ?>]"
                                min="0.000001"
                                step="0.000001"
                                value="<?= e($activeRate ? (string) $activeRate['rate_from_usd'] : '') ?>"
                                placeholder="Ej. 7500"
                            >
                        </td>
                        <td class="right">
                            <button
                                class="button danger small"
                                type="submit"
                                form="delete-unit-<?= (int) $unit['id'] ?>"
                                formnovalidate
                                onclick="return window.confirm('¿Eliminar esta unidad organizativa? El historial de cotizaciones se conservará.');"
                            >Eliminar</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions">
            <button class="button primary" type="submit">Guardar cambios</button>
        </div>
    </form>
    <?php foreach ($units as $unit): ?>
        <form method="post" id="delete-unit-<?= (int) $unit['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="country_unit_id" value="<?= (int) $unit['id'] ?>">
        </form>
    <?php endforeach; ?>
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
