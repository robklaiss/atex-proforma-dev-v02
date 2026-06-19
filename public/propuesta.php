<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || preg_match('/^[a-f0-9]{64}$/i', $token) !== 1) {
    http_response_code(404);
    exit('Propuesta no encontrada.');
}

$stmt = db()->prepare(
    'SELECT p.*,
            COALESCE(NULLIF(p.company_name_snapshot, \'\'), c.empresa) AS client_empresa,
            c.ruc AS client_ruc,
            c.email AS client_email,
            c.telefono AS client_telefono,
            c.direccion AS client_direccion,
            c.pais AS client_pais,
            seller.email AS seller_email,
            COALESCE(NULLIF(TRIM(seller.first_name || \' \' || seller.last_name), \'\'), seller.username, \'\') AS seller_name
     FROM proformas p
     JOIN clients c ON c.id = p.client_id
     LEFT JOIN users seller ON seller.id = p.seller_id
     WHERE p.public_token = :token
     LIMIT 1'
);
$stmt->execute([':token' => $token]);
$proforma = $stmt->fetch();

if (!$proforma || empty($proforma['pdf_path'])) {
    http_response_code(404);
    exit('Propuesta no encontrada.');
}

$proforma['is_latest_version'] = proformaIsLatestVersion(db(), (int) $proforma['id']) ? 1 : 0;
$isSuperseded = proformaIsSuperseded($proforma);
$isExpired = proformaIsExpired($proforma);
$canDownloadFinal = proformaCanDownloadFinal($proforma);
$authorizationStatus = proformaAuthorizationStatus($proforma);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'request_update') {
            throw new RuntimeException('Accion no valida.');
        }
        if ($isSuperseded) {
            throw new RuntimeException('Esta propuesta fue reemplazada por una versión posterior.');
        }
        if (!$isExpired) {
            throw new RuntimeException('La propuesta todavia esta vigente.');
        }

        sendProformaUpdateRequestEmail(db(), $proforma);
        setFlash('success', 'Solicitud enviada. Su ejecutivo comercial recibio el aviso.');
    } catch (Throwable $exception) {
        setFlash('error', 'No se pudo enviar la solicitud. Por favor contacte a su ejecutivo comercial.');
    }

    redirect('/propuesta.php?token=' . rawurlencode($token));
}

$update = db()->prepare(
    "UPDATE proformas
     SET customer_viewed_at = COALESCE(NULLIF(customer_viewed_at, ''), :viewed_at)
     WHERE id = :id"
);
$update->execute([
    ':viewed_at' => nowIso(),
    ':id' => (int) $proforma['id'],
]);
recordProformaEvent(
    db(),
    (int) $proforma['id'],
    null,
    $isExpired ? 'EXPIRED_LINK_VIEWED' : 'PUBLIC_LINK_VIEWED',
    $isExpired ? 'Se abrió el enlace público vencido.' : 'Se abrió el enlace público.'
);

$downloadUrl = publicPath('/propuesta-download.php?token=' . rawurlencode($token));
$customerName = proformaCustomerName($proforma);
$projectName = proformaProjectName($proforma);
$details = proformaTrackingDetails($proforma);
$disclaimerSnapshots = loadProformaDisclaimerSnapshots(db(), (int) $proforma['id']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Propuesta <?= e($projectName) ?> | ATEX</title>
    <link rel="stylesheet" href="<?= e(publicPath('/assets/style.css')) ?>">
</head>
<body class="customer-proposal-body">
    <main class="customer-proposal">
        <section class="customer-proposal-panel">
            <img class="customer-logo" src="<?= e(publicPath('/assets/atex_latam_logo.png')) ?>" alt="ATEX LATAM" width="180">
            <?php if ($isSuperseded): ?>
                <span class="badge <?= e(proformaExpirationBadgeClass($proforma)) ?> customer-status"><?= e(proformaExpirationLabel($proforma)) ?></span>
                <h1>Estimado cliente <?= e($customerName) ?></h1>
                <p class="customer-lead">
                    Esta es una versión anterior de la proforma. Se conserva disponible para consulta y descarga.
                </p>
            <?php elseif ($isExpired): ?>
                <span class="badge <?= e(proformaExpirationBadgeClass($proforma)) ?> customer-status"><?= e(proformaExpirationLabel($proforma)) ?></span>
                <h1>Estimado cliente <?= e($customerName) ?></h1>
                <p class="customer-lead">
                    Esta proforma ya no se encuentra disponible porque superó su período de validez.
                </p>
            <?php elseif (!$canDownloadFinal): ?>
                <span class="badge <?= e(proformaAuthorizationBadgeClass($proforma)) ?> customer-status"><?= e(proformaAuthorizationLabel($proforma)) ?></span>
                <h1>Estimado cliente <?= e($customerName) ?></h1>
                <p class="customer-lead">
                    <?= e($authorizationStatus === 'REJECTED'
                        ? 'Esta proforma fue rechazada y no está disponible como documento final.'
                        : 'Esta proforma está pendiente de autorización de tipo de cambio.') ?>
                </p>
            <?php else: ?>
                <span class="badge <?= e(proformaExpirationBadgeClass($proforma)) ?> customer-status"><?= e(proformaExpirationLabel($proforma)) ?></span>
                <h1>Estimado cliente <?= e($customerName) ?></h1>
                <p class="customer-lead">
                    Aqui esta el enlace para que pueda descargar el PDF de su proyecto <?= e($projectName) ?>.
                </p>
            <?php endif; ?>

            <?php renderFlashMessages(); ?>

            <dl class="proposal-details">
                <?php foreach ($details as $label => $value): ?>
                    <?php $value = trim((string) $value); ?>
                    <?php if ($value !== '' && $value !== emptyFieldMarker()): ?>
                        <div>
                            <dt><?= e($label) ?></dt>
                            <dd><?= e($value) ?></dd>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (trim((string) ($proforma['client_direccion'] ?? '')) !== ''): ?>
                    <div>
                        <dt>Direccion</dt>
                        <dd><?= e((string) $proforma['client_direccion']) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($proforma['client_telefono'] ?? '')) !== ''): ?>
                    <div>
                        <dt>Telefono</dt>
                        <dd><?= e((string) $proforma['client_telefono']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if (proformaObservations($proforma) !== '' || $disclaimerSnapshots !== []): ?>
                <section class="customer-proposal-notes">
                    <?php if (proformaObservations($proforma) !== ''): ?>
                        <h2>Observaciones</h2>
                        <p class="proforma-notes"><?= e(proformaObservations($proforma)) ?></p>
                    <?php endif; ?>
                    <?php if ($disclaimerSnapshots !== []): ?>
                        <h2><?= e(proformaDisclaimersHeading()) ?></h2>
                        <ul>
                            <?php foreach ($disclaimerSnapshots as $disclaimer): ?>
                                <li><strong><?= e($disclaimer['title_snapshot']) ?>:</strong> <?= e($disclaimer['body_snapshot']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="customer-actions">
                <?php if ($isSuperseded): ?>
                    <?php if (!$isExpired && $canDownloadFinal): ?>
                        <a class="button primary customer-download-button" href="<?= e($downloadUrl) ?>">Descargar PDF</a>
                    <?php elseif ($isExpired): ?>
                        <p class="muted">Esta versión histórica superó su período de validez.</p>
                    <?php else: ?>
                        <p class="muted"><?= e(proformaDownloadBlockMessage($proforma)) ?></p>
                    <?php endif; ?>
                <?php elseif ($isExpired): ?>
                    <form method="post" class="customer-update-form">
                        <input type="hidden" name="action" value="request_update">
                        <button class="button primary customer-download-button" type="submit">Pedir cotizacion actualizada</button>
                    </form>
                    <?php if (trim((string) ($proforma['customer_update_requested_at'] ?? '')) !== ''): ?>
                        <p class="muted">Ultima solicitud: <?= e(formatDateTimeShort($proforma['customer_update_requested_at'] ?? null)) ?></p>
                    <?php endif; ?>
                <?php elseif ($canDownloadFinal): ?>
                    <a class="button primary customer-download-button" href="<?= e($downloadUrl) ?>">Descargar PDF</a>
                <?php else: ?>
                    <p class="muted"><?= e(proformaDownloadBlockMessage($proforma)) ?></p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
