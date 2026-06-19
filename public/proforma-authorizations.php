<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/pdf.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$pdo = db();
$currentUser = currentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$isAdminUser = isAdmin($currentUser);
$authorizationStatusLabels = authorizationStatusOptions();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'approve') {
            if (!canDecideProformaAuthorization($currentUser)) {
                throw new RuntimeException('No tenés permisos para aprobar solicitudes.');
            }
            $authorizationId = max(0, (int) ($_POST['authorization_id'] ?? 0));
            $rateMode = (string) ($_POST['rate_mode'] ?? 'GLOBAL');
            $specialRate = null;
            if ($rateMode === 'SPECIAL') {
                $specialRate = parseDecimalInput((string) ($_POST['special_exchange_rate'] ?? '0'));
            } elseif ($rateMode !== 'GLOBAL') {
                throw new RuntimeException('Seleccioná un tipo de cambio válido.');
            }

            createDatabaseBackup();
            $authorization = approveProformaAuthorization($pdo, $authorizationId, $currentUserId, $specialRate);
            try {
                regenerateStoredProformaPdf($pdo, (int) $authorization['proforma_id']);
                setFlash('success', 'Tipo de cambio autorizado y PDF final actualizado.');
            } catch (Throwable $pdfException) {
                setFlash('error', 'La autorización quedó aprobada, pero no se pudo regenerar el PDF: ' . $pdfException->getMessage());
            }
            redirect('/proforma-authorizations.php');
        }

        if ($action === 'reject') {
            if (!canDecideProformaAuthorization($currentUser)) {
                throw new RuntimeException('No tenés permisos para rechazar solicitudes.');
            }
            $authorizationId = max(0, (int) ($_POST['authorization_id'] ?? 0));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            createDatabaseBackup();
            rejectProformaAuthorization($pdo, $authorizationId, $currentUserId, $notes);
            setFlash('success', 'Solicitud rechazada con comentario.');
            redirect('/proforma-authorizations.php');
        }

        throw new RuntimeException('Acción no válida.');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$authorizationSelect = '
    SELECT a.*,
           p.proforma_number, p.project_name, p.currency_mode, p.currency_code, p.currency_symbol,
           p.country_unit_id, p.exchange_rate_used, p.exchange_rate_source AS proforma_exchange_rate_source,
           p.authorization_status, p.total, p.contact_name_snapshot,
           COALESCE(NULLIF(p.company_name_snapshot, \'\'), c.empresa) AS company_name,
           COALESCE(NULLIF(project.name, \'\'), p.project_name) AS canonical_project_name,
           cu.name AS country_unit_name,
           COALESCE(NULLIF(TRIM(requester.first_name || \' \' || requester.last_name), \'\'), requester.username) AS requested_by_name,
           COALESCE(NULLIF(TRIM(target.first_name || \' \' || target.last_name), \'\'), target.username) AS requested_to_name
    FROM proforma_authorizations a
    JOIN proformas p ON p.id = a.proforma_id
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN projects project ON project.id = p.project_id
    LEFT JOIN country_units cu ON cu.id = p.country_unit_id
    JOIN users requester ON requester.id = a.requested_by
    JOIN users target ON target.id = a.requested_to';

$receivedStmt = $pdo->prepare(
    $authorizationSelect . '
     WHERE (a.requested_to = :user_id OR :is_admin = 1)
     ORDER BY CASE a.status WHEN \'PENDING\' THEN 0 ELSE 1 END, a.created_at DESC, a.id DESC'
);
$receivedStmt->execute([
    ':user_id' => $currentUserId,
    ':is_admin' => $isAdminUser ? 1 : 0,
]);
$received = $receivedStmt->fetchAll();
foreach ($received as &$authorization) {
    $authorization['is_latest_version'] = proformaIsLatestVersion(
        $pdo,
        (int) $authorization['proforma_id']
    ) ? 1 : 0;
}
unset($authorization);

$sentStmt = $pdo->prepare(
    $authorizationSelect . '
     WHERE a.requested_by = :user_id
     ORDER BY a.created_at DESC, a.id DESC'
);
$sentStmt->execute([':user_id' => $currentUserId]);
$sent = $sentStmt->fetchAll();

$notificationsStmt = $pdo->prepare(
    'SELECT *
     FROM notifications
     WHERE user_id = :user_id
     ORDER BY created_at DESC, id DESC
     LIMIT 20'
);
$notificationsStmt->execute([':user_id' => $currentUserId]);
$notifications = $notificationsStmt->fetchAll();
$markRead = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
$markRead->execute([':user_id' => $currentUserId]);

renderHeader('Autorizaciones');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
    <h2>Solicitudes recibidas</h2>
    <?php if ($received === []): ?>
        <p class="muted">No tenés solicitudes de autorización asignadas.</p>
    <?php else: ?>
        <?php foreach ($received as $authorization): ?>
            <?php
            $activeRate = findActiveExchangeRate($pdo, (int) $authorization['country_unit_id']);
            $globalRate = $activeRate ? (float) $activeRate['rate_from_usd'] : null;
            $canDecideThis = (string) $authorization['status'] === 'PENDING'
                && (int) $authorization['is_latest_version'] === 1
                && canDecideProformaAuthorization($currentUser)
                && ($isAdminUser || (int) $authorization['requested_to'] === $currentUserId);
            ?>
            <article class="authorization-card">
                <div class="section-title">
                    <h3><?= e($authorization['proforma_number']) ?> · <?= e($authorization['canonical_project_name']) ?></h3>
                    <span class="badge <?= e(match ((string) $authorization['status']) {
                        'APPROVED' => 'success',
                        'REJECTED' => 'danger',
                        default => 'warning',
                    }) ?>"><?= e($authorizationStatusLabels[(string) $authorization['status']] ?? (string) $authorization['status']) ?></span>
                </div>
                <div class="tracking-grid">
                    <div><span class="muted">Empresa</span><strong><?= e($authorization['company_name']) ?></strong></div>
                    <div><span class="muted">Contacto</span><strong><?= e($authorization['contact_name_snapshot'] ?: emptyFieldMarker()) ?></strong></div>
                    <div><span class="muted">Unidad / moneda</span><strong><?= e($authorization['country_unit_name'] . ' · ' . $authorization['currency_symbol']) ?></strong></div>
                    <div><span class="muted">Total USD</span><strong><?= e(formatMoney((float) $authorization['total'], 'USD')) ?></strong></div>
                    <div><span class="muted">Cambio general vigente</span><strong><?= $globalRate !== null ? e('1 US$ = ' . formatNumber($globalRate) . ' ' . $authorization['currency_symbol']) : 'Sin configurar' ?></strong></div>
                    <div><span class="muted">Total convertido</span><strong><?= $globalRate !== null ? e(formatMoneyWithSymbol((float) $authorization['total'] * $globalRate, (string) $authorization['currency_code'], (string) $authorization['currency_symbol'])) : e(emptyFieldMarker()) ?></strong></div>
                    <div><span class="muted">Solicitado por</span><strong><?= e($authorization['requested_by_name']) ?></strong></div>
                    <div><span class="muted">Fecha</span><strong><?= e(formatDateTimeShort($authorization['created_at'])) ?></strong></div>
                </div>

                <?php if ((int) $authorization['is_latest_version'] !== 1): ?>
                    <p class="flash warning">Esta solicitud pertenece a una versión reemplazada y ya no admite decisiones.</p>
                <?php endif; ?>
                <?php if ($canDecideThis): ?>
                    <div class="authorization-actions">
                        <form method="post" class="grid-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="authorization_id" value="<?= (int) $authorization['id'] ?>">
                            <label class="wide">
                                Tipo de cambio a autorizar
                                <select name="rate_mode" class="authorization-rate-mode">
                                    <option value="GLOBAL">Confirmar cambio general vigente</option>
                                    <option value="SPECIAL">Usar cambio especial solo para esta proforma</option>
                                </select>
                            </label>
                            <label class="wide authorization-special-rate" hidden>
                                Tipo de cambio especial
                                <input type="number" name="special_exchange_rate" min="0.000001" step="0.000001" placeholder="Valor de moneda local por 1 US$">
                            </label>
                            <div class="form-actions wide">
                                <button class="button primary" type="submit">Aprobar</button>
                            </div>
                        </form>
                        <form method="post" class="grid-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="authorization_id" value="<?= (int) $authorization['id'] ?>">
                            <label class="wide">
                                Comentario de rechazo
                                <textarea name="notes" maxlength="500" rows="3" required></textarea>
                            </label>
                            <div class="form-actions wide">
                                <button class="button danger" type="submit">Rechazar</button>
                            </div>
                        </form>
                    </div>
                <?php elseif (trim((string) $authorization['notes']) !== ''): ?>
                    <p class="flash <?= (string) $authorization['status'] === 'REJECTED' ? 'error' : 'info' ?>"><?= e($authorization['notes']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Solicitudes enviadas</h2>
    <?php if ($sent === []): ?>
        <p class="muted">Todavía no enviaste solicitudes de autorización.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Proforma</th>
                    <th>Autorizador</th>
                    <th>Estado</th>
                    <th>Tipo de cambio</th>
                    <th>Fecha</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sent as $authorization): ?>
                    <tr>
                        <td><?= e($authorization['proforma_number']) ?></td>
                        <td><?= e(userRoleLabel((string) $authorization['requested_role']) . ' · ' . $authorization['requested_to_name']) ?></td>
                        <td><?= e($authorizationStatusLabels[(string) $authorization['status']] ?? (string) $authorization['status']) ?></td>
                        <td>
                            <?php if ($authorization['approved_exchange_rate'] !== null): ?>
                                <?= e(formatNumber((float) $authorization['approved_exchange_rate']) . ' · ' . (string) $authorization['exchange_rate_source']) ?>
                            <?php else: ?>
                                <?= e(emptyFieldMarker()) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e(formatDateTimeShort($authorization['created_at'])) ?></td>
                        <td class="right"><a class="button small" href="<?= e(publicPath('/proforma-preview.php?id=' . (int) $authorization['proforma_id'])) ?>">Ver</a></td>
                    </tr>
                    <?php if (trim((string) $authorization['notes']) !== ''): ?>
                        <tr><td colspan="6"><span class="muted">Comentario: <?= e($authorization['notes']) ?></span></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Notificaciones internas</h2>
    <?php if ($notifications === []): ?>
        <p class="muted">No hay notificaciones.</p>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <article class="<?= (int) $notification['is_read'] === 0 ? 'unread' : '' ?>">
                    <strong><?= e($notification['title']) ?></strong>
                    <span><?= e($notification['body']) ?></span>
                    <small><?= e(formatDateTimeShort($notification['created_at'])) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.authorization-rate-mode').forEach((select) => {
    const form = select.closest('form');
    const special = form ? form.querySelector('.authorization-special-rate') : null;
    const input = special ? special.querySelector('input') : null;
    const update = () => {
        const enabled = select.value === 'SPECIAL';
        if (special) special.hidden = !enabled;
        if (input) input.required = enabled;
    };
    select.addEventListener('change', update);
    update();
});
</script>
<?php renderFooter(); ?>
