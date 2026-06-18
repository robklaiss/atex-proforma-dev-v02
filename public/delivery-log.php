<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/layout.php';

requireAuth();

$currentUser = currentUser();
$statusFilter = (string) ($_GET['status'] ?? 'all');
$eventFilter = (string) ($_GET['event_type'] ?? 'all');
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$emailSearch = trim((string) ($_GET['email'] ?? ''));

if (!in_array($statusFilter, ['all', 'success', 'error'], true)) {
    $statusFilter = 'all';
}
if (!in_array($eventFilter, ['all', 'customer_proforma', 'update_request'], true)) {
    $eventFilter = 'all';
}
if ($dateFrom !== '' && !isValidDate($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !isValidDate($dateTo)) {
    $dateTo = '';
}

$eventLabels = [
    'customer_proforma' => 'Proforma al cliente',
    'update_request' => 'Solicitud de actualizacion',
];

[$visibilitySql, $visibilityParams] = proformaVisibilityClause($currentUser, 'p', 'seller', 'c', 'log_visible');
$where = [$visibilitySql];
$params = $visibilityParams;

if ($statusFilter === 'success') {
    $where[] = 'l.success = 1';
} elseif ($statusFilter === 'error') {
    $where[] = 'l.success = 0';
}
if ($eventFilter !== 'all') {
    $where[] = 'l.event_type = :event_type';
    $params[':event_type'] = $eventFilter;
}
if ($dateFrom !== '') {
    $where[] = 'date(l.sent_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'date(l.sent_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}
if ($emailSearch !== '') {
    $where[] = 'l.to_email LIKE :email';
    $params[':email'] = '%' . $emailSearch . '%';
}

$stmt = db()->prepare(
    'SELECT l.*, p.proforma_number, p.project_name, c.empresa,
            COALESCE(NULLIF(TRIM(seller.first_name || \' \' || seller.last_name), \'\'), seller.username, p.signer_name, \'Sin vendedor\') AS seller_name
     FROM proforma_email_logs l
     JOIN proformas p ON p.id = l.proforma_id
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY l.sent_at DESC, l.id DESC
     LIMIT 500'
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

renderHeader('Log');
?>
<section class="panel">
    <div class="section-title">
        <h2>Envios de email</h2>
        <a class="button small" href="<?= e(publicPath('/delivery-log.php')) ?>">Limpiar filtros</a>
    </div>
    <form method="get" class="grid-form kpi-filters">
        <label>
            Email
            <input type="search" name="email" value="<?= e($emailSearch) ?>" placeholder="destinatario@dominio.com">
        </label>
        <label>
            Tipo
            <select name="event_type">
                <option value="all" <?= $eventFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($eventLabels as $eventType => $label): ?>
                    <option value="<?= e($eventType) ?>" <?= $eventFilter === $eventType ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Estado
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Enviados</option>
                <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Con error</option>
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
        <div class="form-actions">
            <button class="button primary" type="submit">Filtrar</button>
        </div>
    </form>
</section>

<section class="panel">
    <?php if (!$logs): ?>
        <p class="muted">No hay envios para los filtros seleccionados.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Tipo</th>
                    <th>Proforma</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $success = (int) ($log['success'] ?? 0) === 1;
                    $eventType = (string) ($log['event_type'] ?? '');
                    ?>
                    <tr>
                        <td><?= e(formatDateTimeShort($log['sent_at'] ?? null)) ?></td>
                        <td><?= e(displayOrMarker((string) ($log['to_email'] ?? ''))) ?></td>
                        <td>
                            <span class="badge <?= $success ? 'success' : 'danger' ?>"><?= $success ? 'Enviado' : 'Error' ?></span>
                        </td>
                        <td><?= e($eventLabels[$eventType] ?? $eventType) ?></td>
                        <td>
                            <a href="<?= e(publicPath('/proforma-preview.php?id=' . (int) ($log['proforma_id'] ?? 0))) ?>"><?= e((string) ($log['proforma_number'] ?? '')) ?></a>
                            <span class="tracking-line"><?= e(proformaProjectName($log)) ?></span>
                        </td>
                        <td><?= e((string) ($log['empresa'] ?? '')) ?></td>
                        <td><?= e((string) ($log['seller_name'] ?? '')) ?></td>
                        <td><?= e(displayOrMarker((string) ($log['error_message'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php renderFooter(); ?>
