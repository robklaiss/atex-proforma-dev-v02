<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$currentUser = currentUser();
$id = (int) ($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        [$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'preview_mark_visible');
        $stmt = db()->prepare(
            'SELECT p.id, p.status
             FROM proformas p
             JOIN clients c ON c.id = p.client_id
             LEFT JOIN users seller ON seller.id = p.seller_id
             WHERE p.id = :id
               AND ' . $visibilitySql
        );
        $stmt->execute([':id' => $id] + $visibilityParams);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new RuntimeException('La proforma seleccionada no existe.');
        }

        if ($action === 'send_customer_email') {
            if (!canCreateProformas($currentUser)) {
                throw new RuntimeException('No tenes permisos para enviar proformas.');
            }
            sendProformaCustomerEmail(db(), $id);
            setFlash('success', 'Proforma enviada al email del cliente.');
            redirect('/proforma-preview.php?id=' . $id);
        }

        if ($action === 'mark_won') {
            if (!canChangeProformaStatus($currentUser)) {
                throw new RuntimeException('No tenes permisos para modificar proformas.');
            }
            if ((string) ($existing['status'] ?? '') !== 'venta_ganada') {
                createDatabaseBackup();
                $update = db()->prepare(
                    "UPDATE proformas
                     SET status = 'venta_ganada', won_at = :won_at, won_by = :won_by
                     WHERE id = :id"
                );
                $update->execute([
                    ':won_at' => nowIso(),
                    ':won_by' => (int) ($_SESSION['user_id'] ?? 0),
                    ':id' => $id,
                ]);
            }
            setFlash('success', 'Proforma marcada como venta ganada.');
            redirect('/proforma-preview.php?id=' . $id);
        }

        throw new RuntimeException('Accion no valida.');
    } catch (Throwable $exception) {
        setFlash('error', $exception->getMessage());
        redirect('/proforma-preview.php?id=' . $id);
    }
}

$visibility = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'preview_visible');
$stmt = db()->prepare(
    'SELECT p.*,
            COALESCE(NULLIF(p.company_name_snapshot, \'\'), c.empresa) AS empresa,
            c.email AS client_email,
            c.ruc AS client_ruc,
            cu.name AS country_unit_name,
            project.name AS canonical_project_name
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     LEFT JOIN country_units cu ON cu.id = p.country_unit_id
     LEFT JOIN projects project ON project.id = p.project_id
     WHERE p.id = :id
       AND ' . $visibility[0]
);
$stmt->execute([':id' => $id] + $visibility[1]);
$proforma = $stmt->fetch();

if (!$proforma || empty($proforma['pdf_path'])) {
    http_response_code(404);
    renderHeader('Previsualizar Proforma');
    ?>
    <section class="panel">
        <p class="muted">PDF no encontrado.</p>
        <a class="button" href="<?= e(publicPath('/proformas.php')) ?>">Volver a proformas</a>
    </section>
    <?php
    renderFooter();
    exit;
}

$filename = safeBasename((string) $proforma['pdf_path']);
$path = PROFORMA_STORAGE_PATH . '/' . $filename;
$realDir = realpath(PROFORMA_STORAGE_PATH);
$realPath = realpath($path);

if (!$realDir || !$realPath || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    http_response_code(404);
    renderHeader('Previsualizar Proforma');
    ?>
    <section class="panel">
        <p class="muted">PDF no encontrado.</p>
        <a class="button" href="<?= e(publicPath('/proformas.php')) ?>">Volver a proformas</a>
    </section>
    <?php
    renderFooter();
    exit;
}

$publicToken = trim((string) ($proforma['public_token'] ?? ''));
if ($publicToken === '') {
    $publicToken = ensureProformaPublicToken(db(), $id);
    $proforma['public_token'] = $publicToken;
}
$pendingAuthorizationStmt = db()->prepare(
    "SELECT a.*, COALESCE(NULLIF(TRIM(u.first_name || ' ' || u.last_name), ''), u.username) AS requested_to_name
     FROM proforma_authorizations a
     JOIN users u ON u.id = a.requested_to
     WHERE a.proforma_id = :proforma_id
       AND a.status = 'PENDING'
     LIMIT 1"
);
$pendingAuthorizationStmt->execute([':proforma_id' => $id]);
$pendingAuthorization = $pendingAuthorizationStmt->fetch() ?: null;
$eventsStmt = db()->prepare(
    'SELECT e.*, COALESCE(NULLIF(TRIM(u.first_name || \' \' || u.last_name), \'\'), u.username, \'Sistema\') AS user_name
     FROM proforma_events e
     LEFT JOIN users u ON u.id = e.user_id
     WHERE e.proforma_id = :proforma_id
     ORDER BY e.created_at DESC, e.id DESC
     LIMIT 30'
);
$eventsStmt->execute([':proforma_id' => $id]);
$events = $eventsStmt->fetchAll();
$canDownloadFinal = proformaCanDownloadFinal($proforma);
$authorizationStatus = proformaAuthorizationStatus($proforma);
$isLocalCurrency = normalizeProformaCurrencyMode((string) $proforma['currency_mode']) === 'LOCAL';

renderHeader('Previsualizar Proforma');
$pdfUrl = publicPath('/proforma-view.php?id=' . $id);
$pdfViewerUrl = $pdfUrl . '#toolbar=0&navpanes=0';
$publicLink = proformaPublicLink($publicToken);
?>
<section class="toolbar">
    <?php if ($canDownloadFinal): ?>
        <a class="button primary" href="<?= e(publicPath('/download-proforma.php?id=' . $id)) ?>">Descargar</a>
    <?php else: ?>
        <span class="button disabled" title="<?= e(proformaDownloadBlockMessage($proforma)) ?>">Descargar</span>
    <?php endif; ?>
    <?php if (canCreateProformas($currentUser)): ?>
        <a class="button" href="<?= e(publicPath('/proforma-new.php?edit_id=' . $id)) ?>">Editar</a>
    <?php endif; ?>
    <?php if ($isLocalCurrency && $authorizationStatus === 'PENDING' && canCreateProformas($currentUser)): ?>
        <?php if ($pendingAuthorization): ?>
            <a class="button" href="<?= e(publicPath('/proforma-authorizations.php')) ?>">Solicitud enviada</a>
        <?php else: ?>
            <a class="button" href="<?= e(publicPath('/proforma-authorizations.php?proforma_id=' . $id)) ?>">Autorizar</a>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (canCreateProformas($currentUser) && $canDownloadFinal): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_customer_email">
            <button class="button primary" type="submit"><?= trim((string) ($proforma['email_sent_at'] ?? '')) !== '' ? 'Reenviar al cliente' : 'Enviar al cliente' ?></button>
        </form>
    <?php endif; ?>
    <?php $isWon = ($proforma['status'] ?? 'emitida') === 'venta_ganada'; ?>
    <?php if (!$isWon && $canDownloadFinal && canChangeProformaStatus($currentUser)): ?>
        <form method="post" onsubmit="return confirm('Marcar esta proforma como venta ganada?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_won">
            <button class="button primary" type="submit">Venta ganada</button>
        </form>
    <?php elseif ($isWon): ?>
        <span class="badge success">Venta ganada</span>
    <?php endif; ?>
    <?php if (canCreateProformas($currentUser)): ?>
        <a class="button" href="<?= e(publicPath('/proforma-new.php')) ?>">Nueva Proforma</a>
    <?php endif; ?>
    <a class="button" href="<?= e(publicPath('/proformas.php')) ?>">Volver a proformas</a>
</section>

<?php if (!$canDownloadFinal): ?>
    <div class="flash <?= $authorizationStatus === 'REJECTED' ? 'error' : 'warning' ?>"><?= e(proformaDownloadBlockMessage($proforma)) ?></div>
<?php elseif (!$isLocalCurrency): ?>
    <div class="flash info">Esta proforma está en dólares y no requiere autorización de tipo de cambio.</div>
<?php endif; ?>

<section class="panel">
    <h2>Resumen de proforma</h2>
    <div class="tracking-grid">
        <div>
            <span class="muted">Número de proforma</span>
            <strong><?= e($proforma['proforma_number']) ?></strong>
        </div>
        <div>
            <span class="muted">Estado</span>
            <strong><span class="badge <?= e(proformaAuthorizationBadgeClass($proforma)) ?>"><?= e(proformaAuthorizationLabel($proforma)) ?></span></strong>
        </div>
        <div>
            <span class="muted">Moneda</span>
            <strong><?= e($proforma['currency_mode'] === 'USD' ? 'US$' : $proforma['currency_symbol'] . ' · ' . $proforma['currency_code']) ?></strong>
        </div>
        <div>
            <span class="muted">Empresa</span>
            <strong><?= e($proforma['empresa']) ?></strong>
        </div>
        <div>
            <span class="muted">Contacto</span>
            <strong><?= e($proforma['contact_name_snapshot'] ?: emptyFieldMarker()) ?></strong>
        </div>
        <div>
            <span class="muted">Validez</span>
            <strong><?= (int) $proforma['validity_days'] ?> días</strong>
        </div>
        <div>
            <span class="muted">Fecha de vencimiento</span>
            <strong><?= e(formatDateTimeShort($proforma['expires_at'] ?? null)) ?></strong>
        </div>
        <div>
            <span class="muted">Total</span>
            <strong><?= e(formatProformaMoney((float) $proforma['total'], $proforma)) ?></strong>
        </div>
        <div class="wide">
            <span class="muted">Link público</span>
            <strong><a href="<?= e($publicLink) ?>" target="_blank" rel="noopener"><?= e($publicLink) ?></a></strong>
        </div>
    </div>
    <?php if ($pendingAuthorization): ?>
        <p class="flash info">Solicitud enviada a <?= e($pendingAuthorization['requested_to_name']) ?> el <?= e(formatDateTimeShort($pendingAuthorization['created_at'])) ?>.</p>
    <?php endif; ?>
    <?php if (trim((string) ($proforma['email_last_error'] ?? '')) !== ''): ?>
        <p class="flash error"><?= e((string) $proforma['email_last_error']) ?></p>
    <?php endif; ?>
    <?php if (trim((string) ($proforma['customer_update_request_error'] ?? '')) !== ''): ?>
        <p class="flash error"><?= e((string) $proforma['customer_update_request_error']) ?></p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Seguimiento</h2>
    <div class="tracking-grid">
        <div><span class="muted">Email enviado</span><strong><?= e(formatDateTimeShort($proforma['email_sent_at'] ?? null)) ?></strong></div>
        <div><span class="muted">Ingreso público</span><strong><?= e(formatDateTimeShort($proforma['customer_viewed_at'] ?? null)) ?></strong></div>
        <div><span class="muted">Descarga pública</span><strong><?= e(formatDateTimeShort($proforma['customer_downloaded_at'] ?? null)) ?></strong></div>
        <div><span class="muted">Pidió actualización</span><strong><?= e(formatDateTimeShort($proforma['customer_update_requested_at'] ?? null)) ?></strong></div>
    </div>
</section>

<section class="panel">
    <h2>Trazabilidad de acciones</h2>
    <?php if ($events === []): ?>
        <p class="muted">No hay eventos registrados para esta proforma.</p>
    <?php else: ?>
        <div class="event-list">
            <?php foreach ($events as $event): ?>
                <article>
                    <strong><?= e($event['event_type']) ?></strong>
                    <span><?= e($event['event_detail']) ?></span>
                    <small><?= e($event['user_name']) ?> · <?= e(formatDateTimeShort($event['created_at'])) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel pdf-preview-panel">
    <div class="section-title">
        <h2><?= e($proforma['proforma_number']) ?> - <?= e($proforma['empresa']) ?></h2>
    </div>
    <iframe class="pdf-preview-frame" src="<?= e($pdfViewerUrl) ?>" title="Vista previa de la proforma <?= e($proforma['proforma_number']) ?>"></iframe>
</section>
<?php renderFooter(); ?>
