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
$error = null;

$loadVisibleProforma = static function (int $proformaId) use ($pdo, $currentUser): ?array {
    [$visibilitySql, $visibilityParams] = proformaVisibilityClause(
        $currentUser,
        'p',
        'seller',
        'c',
        'authorization_visible'
    );
    $stmt = $pdo->prepare(
        'SELECT p.*, c.empresa, c.pais, project.name AS canonical_project_name,
                cu.name AS country_unit_name
         FROM proformas p
         JOIN clients c ON c.id = p.client_id
         LEFT JOIN users seller ON seller.id = p.seller_id
         LEFT JOIN projects project ON project.id = p.project_id
         LEFT JOIN country_units cu ON cu.id = p.country_unit_id
         WHERE p.id = :id
           AND ' . $visibilitySql . '
         LIMIT 1'
    );
    $stmt->execute([':id' => $proformaId] + $visibilityParams);
    $proforma = $stmt->fetch();

    return is_array($proforma) ? $proforma : null;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'request') {
            $proformaId = max(0, (int) ($_POST['proforma_id'] ?? 0));
            $requestedTo = max(0, (int) ($_POST['requested_to'] ?? 0));
            $proforma = $loadVisibleProforma($proformaId);
            if (!$proforma) {
                throw new RuntimeException('La proforma seleccionada no existe o no está visible.');
            }

            createDatabaseBackup();
            $authorization = requestProformaAuthorization($pdo, $proformaId, $currentUserId, $requestedTo);
            try {
                sendProformaAuthorizationRequestEmail($pdo, (int) $authorization['id']);
                setFlash('success', 'Solicitud de autorización creada y enviada por email.');
            } catch (Throwable $mailException) {
                setFlash('success', 'Solicitud de autorización creada y visible en el sistema.');
                setFlash('error', 'No se pudo enviar el email al autorizador: ' . $mailException->getMessage());
            }
            redirect('/proforma-preview.php?id=' . $proformaId);
        }

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

$requestProformaId = max(0, (int) ($_GET['proforma_id'] ?? 0));
$requestProforma = $requestProformaId > 0 ? $loadVisibleProforma($requestProformaId) : null;
$requestAuthorizers = $requestProforma
    ? availableProformaAuthorizers($pdo, $requestProforma, $currentUserId)
    : [];

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

<?php if ($requestProforma): ?>
    <section class="panel">
        <h2>Solicitar autorización</h2>
        <?php if (proformaIsManagerSigned($requestProforma)): ?>
            <p class="flash info">Esta proforma está firmada por un gerente y no requiere autorización, sin importar la moneda.</p>
        <?php elseif (normalizeProformaCurrencyMode((string) $requestProforma['currency_mode']) !== 'LOCAL'): ?>
            <p class="flash info">Esta proforma está en dólares y no requiere autorización de tipo de cambio.</p>
        <?php elseif (proformaAuthorizationStatus($requestProforma) !== 'PENDING'): ?>
            <p class="flash info">La proforma ya no está pendiente de autorización.</p>
        <?php elseif ($requestAuthorizers === []): ?>
            <p class="flash warning">No hay supervisores, gerentes o directores disponibles para la unidad país de esta proforma.</p>
        <?php else: ?>
            <div class="tracking-grid authorization-summary">
                <div><span class="muted">Proforma</span><strong><?= e($requestProforma['proforma_number']) ?></strong></div>
                <div><span class="muted">Proyecto</span><strong><?= e(proformaProjectName($requestProforma)) ?></strong></div>
                <div><span class="muted">Empresa</span><strong><?= e($requestProforma['company_name_snapshot'] ?: $requestProforma['empresa']) ?></strong></div>
                <div><span class="muted">Unidad país</span><strong><?= e($requestProforma['country_unit_name'] ?? '') ?></strong></div>
            </div>
            <form method="post" class="grid-form authorization-request-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request">
                <input type="hidden" name="proforma_id" value="<?= (int) $requestProforma['id'] ?>">
                <label class="wide">
                    Supervisor, Gerente o Director
                    <select name="requested_to" required>
                        <option value="">Seleccionar superior</option>
                        <?php foreach ($requestAuthorizers as $authorizer): ?>
                            <option value="<?= (int) $authorizer['id'] ?>">
                                <?= e(userRoleLabel((string) $authorizer['role']) . ' · ' . userFullName($authorizer)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions wide">
                    <button class="button primary" type="submit">Enviar solicitud</button>
                    <a class="button" href="<?= e(publicPath('/proforma-preview.php?id=' . (int) $requestProforma['id'])) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php elseif ($requestProformaId > 0): ?>
    <div class="flash error">La proforma seleccionada no existe o no está visible.</div>
<?php endif; ?>

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
                    }) ?>"><?= e((string) $authorization['status']) ?></span>
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
                        <td><?= e($authorization['status']) ?></td>
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
