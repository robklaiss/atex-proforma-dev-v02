<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$currentUser = currentUser();
$isAdminUser = isAdmin($currentUser);
$sellerFilter = max(0, (int) ($_GET['seller_id'] ?? 0));
$unitFilter = trim((string) ($_GET['unit'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$statusFilter = strtoupper((string) ($_GET['status'] ?? ''));

if ($unitFilter !== '' && !isAllowedCountry($unitFilter)) {
    $unitFilter = '';
}
if ($dateFrom !== '' && !isValidDate($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !isValidDate($dateTo)) {
    $dateTo = '';
}
if ($statusFilter !== '' && !array_key_exists($statusFilter, commercialStatusOptions())) {
    $statusFilter = '';
}

$sellerRoleParams = [];
$sellerRolePlaceholders = [];
foreach (salesSignerRoles() as $index => $role) {
    $key = ':seller_role_' . $index;
    $sellerRoleParams[$key] = $role;
    $sellerRolePlaceholders[] = $key;
}
[$sellerVisibilitySql, $sellerVisibilityParams] = userVisibilityClause($currentUser, 'u', 'proforma_seller');
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'send_customer_email') {
            if (!canCreateProformas($currentUser)) {
                throw new RuntimeException('No tenes permisos para enviar proformas.');
            }
            [$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'send_visible');
            $stmt = db()->prepare(
                'SELECT p.id
                 FROM proformas p
                 JOIN clients c ON c.id = p.client_id
                 LEFT JOIN users seller ON seller.id = p.seller_id
                 WHERE p.id = :id
                   AND ' . $visibilitySql
            );
            $stmt->execute([':id' => $id] + $visibilityParams);
            if (!$stmt->fetch()) {
                throw new RuntimeException('La proforma seleccionada no existe.');
            }

            sendProformaCustomerEmail(db(), $id);
            setFlash('success', 'Proforma enviada al email del cliente.');
            redirect('/proformas.php');
        }

        if ($action === 'mark_won') {
            if (!canChangeProformaStatus($currentUser)) {
                throw new RuntimeException('No tenes permisos para modificar proformas.');
            }
            [$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'mark_won_visible');
            $stmt = db()->prepare(
                'SELECT p.id, p.status
                 FROM proformas p
                 JOIN clients c ON c.id = p.client_id
                 LEFT JOIN users seller ON seller.id = p.seller_id
                 WHERE p.id = :id
                   AND ' . $visibilitySql
            );
            $stmt->execute([':id' => $id] + $visibilityParams);
            $proforma = $stmt->fetch();
            if (!$proforma) {
                throw new RuntimeException('La proforma seleccionada no existe.');
            }
            if (proformaCommercialStatus($proforma) !== 'WON') {
                createDatabaseBackup();
                updateProformaCommercialStatus(db(), $id, 'WON', (int) $currentUser['id']);
            }
            setFlash('success', 'Proforma marcada como venta ganada.');
            redirect('/proformas.php');
        }

        if ($action === 'reopen' && $isAdminUser) {
            createDatabaseBackup();
            updateProformaCommercialStatus(db(), $id, 'OPEN', (int) $currentUser['id']);
            setFlash('success', 'Proforma reabierta.');
            redirect('/proformas.php');
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        setFlash('error', $exception->getMessage());
        redirect('/proformas.php');
    }
}

[$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'proforma_list_visible');
$proformaWhere = [$visibilitySql];
$proformaParams = $visibilityParams;
if ($sellerFilter > 0) {
    $proformaWhere[] = 'COALESCE(p.seller_id, p.created_by) = :seller_id';
    $proformaParams[':seller_id'] = $sellerFilter;
}
if ($unitFilter !== '') {
    $proformaWhere[] = "COALESCE(NULLIF(p.signer_unit, ''), NULLIF(seller.unit, ''), c.pais) = :unit";
    $proformaParams[':unit'] = $unitFilter;
}
if ($dateFrom !== '') {
    $proformaWhere[] = 'p.emission_date >= :date_from';
    $proformaParams[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $proformaWhere[] = 'p.emission_date <= :date_to';
    $proformaParams[':date_to'] = $dateTo;
}
if ($statusFilter !== '') {
    $proformaWhere[] = "COALESCE(NULLIF(p.commercial_status, ''), CASE WHEN p.status = 'venta_ganada' THEN 'WON' ELSE 'OPEN' END) = :commercial_status";
    $proformaParams[':commercial_status'] = $statusFilter;
}
$whereSql = implode(' AND ', $proformaWhere);

$proformasStmt = db()->prepare(
    'SELECT p.*, COALESCE(NULLIF(p.company_name_snapshot, \'\'), c.empresa) AS empresa, c.pais,
            project.name AS canonical_project_name, project.prefix AS project_prefix,
            COALESCE(creator.username, \'Usuario no disponible\') AS created_by_username,
            COALESCE(NULLIF(TRIM(seller.first_name || \' \' || seller.last_name), \'\'), seller.username, p.signer_name, \'Sin vendedor\') AS seller_name
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN projects project ON project.id = p.project_id
     LEFT JOIN users creator ON creator.id = p.created_by
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE ' . $whereSql . '
     ORDER BY p.id DESC'
);
$proformasStmt->execute($proformaParams);
$proformas = $proformasStmt->fetchAll();

$proformaGroups = [];
foreach ($proformas as $proforma) {
    $projectName = proformaProjectName($proforma);
    $projectId = (int) ($proforma['project_id'] ?? 0);
    $projectKey = $projectId > 0 ? 'project:' . $projectId : 'legacy:' . normalizeProjectName($projectName);

    if (!isset($proformaGroups[$projectKey])) {
        $proformaGroups[$projectKey] = [
            'project_name' => $projectName,
            'proformas' => [],
        ];
    }

    $proformaGroups[$projectKey]['proformas'][] = $proforma;
}

uasort(
    $proformaGroups,
    static fn (array $left, array $right): int => strcasecmp((string) $left['project_name'], (string) $right['project_name'])
);

renderHeader('Proformas');
?>
<section class="toolbar">
    <?php if (canCreateProformas($currentUser)): ?>
        <a class="button primary" href="<?= e(publicPath('/proforma-new.php')) ?>">Nueva Proforma</a>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="section-title">
        <h2>Filtros</h2>
        <a class="button small" href="<?= e(publicPath('/proformas.php')) ?>">Limpiar filtros</a>
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
            Seguimiento
            <select name="status">
                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Todos</option>
                <?php foreach (commercialStatusOptions() as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <button class="button primary" type="submit">Filtrar</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Proformas generadas</h2>
    <?php if (!$proformas): ?>
        <p class="muted">No hay proformas para los filtros seleccionados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Numero</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th>Creada por</th>
                    <th>Estado</th>
                    <th>Seguimiento</th>
                    <th>Emision</th>
                    <th>Vencimiento</th>
                    <th>Subtotal</th>
                    <th>Descuento</th>
                    <th>Impuestos</th>
                    <th>Total</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($proformaGroups as $group): ?>
                    <?php
                    $groupCount = count($group['proformas']);
                    $groupCountLabel = $groupCount === 1 ? '1 proforma' : formatInteger($groupCount) . ' proformas';
                    ?>
                    <tr class="project-group-row">
                        <th colspan="13">
                            <span><?= e((string) $group['project_name']) ?></span>
                            <small><?= e($groupCountLabel) ?></small>
                        </th>
                    </tr>
                    <?php foreach ($group['proformas'] as $proforma): ?>
                        <?php $isWon = proformaCommercialStatus($proforma) === 'WON'; ?>
                        <tr>
                            <td><?= e($proforma['proforma_number']) ?></td>
                            <td><?= e($proforma['empresa']) ?></td>
                            <td><?= e($proforma['seller_name']) ?></td>
                            <td><?= e($proforma['created_by_username']) ?></td>
                            <td>
                                <span class="badge <?= e(proformaExpirationBadgeClass($proforma)) ?>"><?= e(proformaExpirationLabel($proforma)) ?></span>
                                <span class="badge <?= e(proformaAuthorizationBadgeClass($proforma)) ?>"><?= e(proformaAuthorizationLabel($proforma)) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= e(commercialStatusBadgeClass($proforma)) ?>"><?= e(commercialStatusLabel($proforma)) ?></span>
                                <span class="tracking-line">Email: <?= e(formatDateTimeShort($proforma['email_sent_at'] ?? null)) ?></span>
                                <span class="tracking-line">Ingreso: <?= e(formatDateTimeShort($proforma['customer_viewed_at'] ?? null)) ?></span>
                                <span class="tracking-line">Descarga: <?= e(formatDateTimeShort($proforma['customer_downloaded_at'] ?? null)) ?></span>
                                <?php if (trim((string) ($proforma['customer_update_requested_at'] ?? '')) !== ''): ?>
                                    <span class="tracking-line">Actualizacion: <?= e(formatDateTimeShort($proforma['customer_update_requested_at'] ?? null)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($proforma['emission_date']) ?></td>
                            <td><?= e($proforma['expiration_date']) ?></td>
                            <td class="right"><?= e(formatProformaMoney((float) $proforma['subtotal'], $proforma)) ?></td>
                            <td class="right">
                                <?php if ((float) ($proforma['discount_amount'] ?? 0) > 0): ?>
                                    <?= e(formatNumber((float) ($proforma['discount_percent'] ?? 0))) ?>%<br>
                                    <span class="muted">-<?= e(formatProformaMoney((float) ($proforma['discount_amount'] ?? 0), $proforma)) ?></span>
                                <?php else: ?>
                                    <?= e(formatNumber(0)) ?>%
                                <?php endif; ?>
                            </td>
                            <td class="right"><?= e(formatProformaMoney((float) $proforma['tax_total'], $proforma)) ?></td>
                            <td class="right"><?= e(formatProformaMoney((float) $proforma['total'], $proforma)) ?></td>
                            <td class="right">
                                <div class="actions-cell">
                                    <?php if (canCreateProformas($currentUser) && (!$isWon || $isAdminUser)): ?>
                                        <a class="button small" href="<?= e(publicPath('/proforma-new.php?edit_id=' . (int) $proforma['id'])) ?>">Editar</a>
                                    <?php endif; ?>
                                    <?php if (canCreateProformas($currentUser)): ?>
                                        <a class="button small" href="<?= e(publicPath('/proforma-new.php?clone_id=' . (int) $proforma['id'])) ?>">Clonar</a>
                                    <?php endif; ?>
                                    <a class="button small" href="<?= e(publicPath('/proforma-preview.php?id=' . (int) $proforma['id'])) ?>">Ver</a>
                                    <?php if (proformaCanDownloadFinal($proforma)): ?>
                                        <a class="button small" href="<?= e(publicPath('/download-proforma.php?id=' . (int) $proforma['id'])) ?>">Descargar</a>
                                    <?php endif; ?>
                                    <?php if (
                                        canCreateProformas($currentUser)
                                        && normalizeProformaCurrencyMode((string) $proforma['currency_mode']) === 'LOCAL'
                                        && proformaAuthorizationStatus($proforma) === 'PENDING'
                                    ): ?>
                                        <a class="button small" href="<?= e(publicPath('/proforma-authorizations.php?proforma_id=' . (int) $proforma['id'])) ?>">Autorizar</a>
                                    <?php endif; ?>
                                    <?php if (canCreateProformas($currentUser) && proformaCanDownloadFinal($proforma)): ?>
                                        <form method="post">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="send_customer_email">
                                            <input type="hidden" name="id" value="<?= (int) $proforma['id'] ?>">
                                            <button class="button small" type="submit"><?= trim((string) ($proforma['email_sent_at'] ?? '')) !== '' ? 'Reenviar' : 'Enviar' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
