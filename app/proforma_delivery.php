<?php

declare(strict_types=1);

function proformaCustomerName(array $proforma): string
{
    $contactName = trim((string) ($proforma['contact_name'] ?? ''));
    if ($contactName !== '') {
        return $contactName;
    }

    $clientName = trim((string) ($proforma['client_empresa'] ?? $proforma['empresa'] ?? ''));
    return $clientName !== '' ? $clientName : 'cliente';
}

function proformaProjectName(array $proforma): string
{
    $projectName = trim((string) ($proforma['canonical_project_name'] ?? $proforma['project_name'] ?? ''));
    if ($projectName !== '') {
        return $projectName;
    }

    $clientName = trim((string) ($proforma['client_empresa'] ?? $proforma['empresa'] ?? ''));
    if ($clientName !== '') {
        return $clientName;
    }

    return 'proforma ' . trim((string) ($proforma['proforma_number'] ?? ''));
}

function proformaRecipientEmail(array $proforma): string
{
    $contactEmail = trim((string) ($proforma['contact_email'] ?? ''));
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        return $contactEmail;
    }

    $clientEmail = trim((string) ($proforma['client_email'] ?? $proforma['email'] ?? ''));
    if ($clientEmail !== '' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        return $clientEmail;
    }

    return '';
}

function proformaExecutiveEmail(array $proforma): string
{
    foreach (['signer_email', 'seller_email'] as $key) {
        $email = trim((string) ($proforma[$key] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function proformaExecutiveName(array $proforma): string
{
    foreach (['signer_name', 'seller_name'] as $key) {
        $name = trim((string) ($proforma[$key] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    return 'ejecutivo comercial';
}

function proformaPublicLink(string $token): string
{
    return publicUrl('/propuesta.php?token=' . rawurlencode($token));
}

function proformaIsExpired(array $proforma): bool
{
    $expiresAt = trim((string) ($proforma['expires_at'] ?? ''));
    if ($expiresAt !== '') {
        $timestamp = strtotime($expiresAt);
        if ($timestamp !== false) {
            return $timestamp < time();
        }
    }

    $expirationDate = trim((string) ($proforma['expiration_date'] ?? ''));
    if (!isValidDate($expirationDate)) {
        return false;
    }

    return $expirationDate < date('Y-m-d');
}

function proformaExpirationLabel(array $proforma): string
{
    return proformaIsExpired($proforma) ? 'Vencido' : 'Vigente';
}

function proformaExpirationBadgeClass(array $proforma): string
{
    return proformaIsExpired($proforma) ? 'danger' : 'success';
}

function loadProformaForDelivery(PDO $pdo, int $proformaId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*,
                COALESCE(NULLIF(p.company_name_snapshot, \'\'), c.empresa) AS client_empresa,
                c.ruc AS client_ruc,
                c.email AS client_email,
                c.telefono AS client_telefono,
                c.direccion AS client_direccion,
                c.pais AS client_pais,
                project.name AS canonical_project_name,
                seller.email AS seller_email,
                COALESCE(NULLIF(TRIM(seller.first_name || \' \' || seller.last_name), \'\'), seller.username, \'\') AS seller_name
         FROM proformas p
         JOIN clients c ON c.id = p.client_id
         LEFT JOIN projects project ON project.id = p.project_id
         LEFT JOIN users seller ON seller.id = p.seller_id
         WHERE p.id = :id'
    );
    $stmt->execute([':id' => $proformaId]);
    $proforma = $stmt->fetch();

    return is_array($proforma) ? $proforma : null;
}

function ensureProformaPublicToken(PDO $pdo, int $proformaId): string
{
    $stmt = $pdo->prepare('SELECT public_token FROM proformas WHERE id = :id');
    $stmt->execute([':id' => $proformaId]);
    $existing = trim((string) $stmt->fetchColumn());
    if ($existing !== '') {
        return $existing;
    }

    $update = $pdo->prepare('UPDATE proformas SET public_token = :token WHERE id = :id');
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $token = bin2hex(random_bytes(32));
        try {
            $update->execute([
                ':token' => $token,
                ':id' => $proformaId,
            ]);
            return $token;
        } catch (PDOException $exception) {
            if (stripos($exception->getMessage(), 'unique') === false) {
                throw $exception;
            }
        }
    }

    throw new RuntimeException('No se pudo generar el enlace publico de la proforma.');
}

function proformaTrackingDetails(array $proforma): array
{
    return [
        'Proyecto' => proformaProjectName($proforma),
        'Cliente' => trim((string) ($proforma['client_empresa'] ?? $proforma['empresa'] ?? '')),
        'RUC' => trim((string) ($proforma['client_ruc'] ?? $proforma['ruc'] ?? '')),
        'Pais' => trim((string) ($proforma['client_pais'] ?? $proforma['pais'] ?? '')),
        'Contacto' => trim((string) ($proforma['contact_name'] ?? '')),
        'Proforma' => trim((string) ($proforma['proforma_number'] ?? '')),
        'Emision' => formatDateLong((string) ($proforma['emission_date'] ?? '')),
        'Vencimiento' => formatDateLong((string) ($proforma['expiration_date'] ?? '')),
        'Validez' => ((int) ($proforma['validity_days'] ?? 0) > 0)
            ? (int) $proforma['validity_days'] . ' días'
            : '',
        'Total' => formatProformaMoney((float) ($proforma['total'] ?? 0), $proforma),
        'Vendedor' => trim((string) ($proforma['signer_name'] ?? '')),
    ];
}

function buildProformaEmailHtml(array $proforma, string $link): string
{
    $customerName = proformaCustomerName($proforma);
    $projectName = proformaProjectName($proforma);
    $logoUrl = publicUrl('/assets/atex_latam_logo.png');
    $details = proformaTrackingDetails($proforma);
    $rows = '';

    foreach ($details as $label => $value) {
        $value = trim((string) $value);
        if ($value === '' || $value === emptyFieldMarker()) {
            continue;
        }
        $rows .= '<tr><td style="padding:6px 0;color:#6b7280;">' . e($label) . '</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#1f2328;">' . e($value) . '</td></tr>';
    }

    return '<!doctype html><html><body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2328;">'
        . '<div style="max-width:640px;margin:0 auto;padding:28px 18px;">'
        . '<div style="background:#ffffff;border:1px solid #d7dce2;border-radius:8px;padding:28px;">'
        . '<img src="' . e($logoUrl) . '" alt="ATEX LATAM" width="170" style="display:block;margin:0 0 24px;height:auto;">'
        . '<p style="margin:0 0 14px;font-size:16px;">Estimado cliente ' . e($customerName) . ',</p>'
        . '<p style="margin:0 0 20px;line-height:1.5;">Por favor siga el siguiente enlace para que pueda descargar la propuesta para el proyecto <strong>' . e($projectName) . '</strong>.</p>'
        . '<p style="margin:0 0 24px;"><a href="' . e($link) . '" style="display:inline-block;background:#ff7a14;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:12px 18px;">Ver propuesta</a></p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #d7dce2;border-bottom:1px solid #d7dce2;margin:0 0 20px;padding:10px 0;">' . $rows . '</table>'
        . '<p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">Si el boton no abre, copie este enlace en su navegador:<br><a href="' . e($link) . '" style="color:#d95f00;">' . e($link) . '</a></p>'
        . '</div></div></body></html>';
}

function buildProformaEmailText(array $proforma, string $link): string
{
    $lines = [
        'Estimado cliente ' . proformaCustomerName($proforma) . ',',
        '',
        'Por favor siga el siguiente enlace para que pueda descargar la propuesta para el proyecto ' . proformaProjectName($proforma) . ':',
        $link,
        '',
    ];

    foreach (proformaTrackingDetails($proforma) as $label => $value) {
        $value = trim((string) $value);
        if ($value !== '' && $value !== emptyFieldMarker()) {
            $lines[] = $label . ': ' . $value;
        }
    }

    return implode("\n", $lines);
}

function buildProformaUpdateRequestEmailHtml(array $proforma, string $link): string
{
    $customerName = proformaCustomerName($proforma);
    $projectName = proformaProjectName($proforma);
    $logoUrl = publicUrl('/assets/atex_latam_logo.png');
    $details = proformaTrackingDetails($proforma);
    $rows = '';

    foreach ($details as $label => $value) {
        $value = trim((string) $value);
        if ($value === '' || $value === emptyFieldMarker()) {
            continue;
        }
        $rows .= '<tr><td style="padding:6px 0;color:#6b7280;">' . e($label) . '</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#1f2328;">' . e($value) . '</td></tr>';
    }

    return '<!doctype html><html><body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2328;">'
        . '<div style="max-width:640px;margin:0 auto;padding:28px 18px;">'
        . '<div style="background:#ffffff;border:1px solid #d7dce2;border-radius:8px;padding:28px;">'
        . '<img src="' . e($logoUrl) . '" alt="ATEX LATAM" width="170" style="display:block;margin:0 0 24px;height:auto;">'
        . '<p style="margin:0 0 14px;font-size:16px;">El cliente <strong>' . e($customerName) . '</strong> pidio una cotizacion actualizada.</p>'
        . '<p style="margin:0 0 20px;line-height:1.5;">La solicitud corresponde al proyecto <strong>' . e($projectName) . '</strong>. La proforma actual figura como <strong>' . e(proformaExpirationLabel($proforma)) . '</strong>.</p>'
        . '<p style="margin:0 0 24px;"><a href="' . e($link) . '" style="display:inline-block;background:#ff7a14;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:12px 18px;">Ver proforma</a></p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #d7dce2;border-bottom:1px solid #d7dce2;margin:0 0 20px;padding:10px 0;">' . $rows . '</table>'
        . '<p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">Si el boton no abre, copie este enlace en su navegador:<br><a href="' . e($link) . '" style="color:#d95f00;">' . e($link) . '</a></p>'
        . '</div></div></body></html>';
}

function buildProformaUpdateRequestEmailText(array $proforma, string $link): string
{
    $lines = [
        'Solicitud de cotizacion actualizada',
        '',
        'El cliente ' . proformaCustomerName($proforma) . ' pidio una cotizacion actualizada para el proyecto ' . proformaProjectName($proforma) . '.',
        'Estado actual: ' . proformaExpirationLabel($proforma),
        'Ver proforma: ' . $link,
        '',
    ];

    foreach (proformaTrackingDetails($proforma) as $label => $value) {
        $value = trim((string) $value);
        if ($value !== '' && $value !== emptyFieldMarker()) {
            $lines[] = $label . ': ' . $value;
        }
    }

    return implode("\n", $lines);
}

function logProformaEmailAttempt(
    PDO $pdo,
    ?int $proformaId,
    string $eventType,
    string $toEmail,
    string $subject,
    bool $success,
    string $errorMessage = '',
    ?string $sentAt = null
): void {
    $insert = $pdo->prepare(
        'INSERT INTO proforma_email_logs
         (proforma_id, event_type, to_email, subject, success, error_message, sent_at)
         VALUES
         (:proforma_id, :event_type, :to_email, :subject, :success, :error_message, :sent_at)'
    );
    $insert->execute([
        ':proforma_id' => $proformaId,
        ':event_type' => $eventType,
        ':to_email' => substr($toEmail, 0, 255),
        ':subject' => substr($subject, 0, 255),
        ':success' => $success ? 1 : 0,
        ':error_message' => substr($errorMessage, 0, 500),
        ':sent_at' => $sentAt ?? nowIso(),
    ]);
}

function sendProformaCustomerEmail(PDO $pdo, int $proformaId): void
{
    $proforma = loadProformaForDelivery($pdo, $proformaId);
    if ($proforma === null) {
        throw new RuntimeException('La proforma seleccionada no existe.');
    }
    if (!proformaCanDownloadFinal($proforma)) {
        throw new RuntimeException(proformaDownloadBlockMessage($proforma));
    }

    $recipientEmail = proformaRecipientEmail($proforma);
    $subject = 'Propuesta ATEX - ' . proformaProjectName($proforma);
    if ($recipientEmail === '') {
        logProformaEmailAttempt($pdo, $proformaId, 'customer_proforma', '', $subject, false, 'El cliente o contacto necesita un email valido para enviar la propuesta.');
        throw new RuntimeException('El cliente o contacto necesita un email valido para enviar la propuesta.');
    }

    $token = ensureProformaPublicToken($pdo, $proformaId);
    $proforma['public_token'] = $token;
    $link = proformaPublicLink($token);
    $sentAt = nowIso();

    try {
        smtpSendMail([
            'to_email' => $recipientEmail,
            'to_name' => proformaCustomerName($proforma),
            'reply_to_email' => (string) ($proforma['signer_email'] ?? ''),
            'reply_to_name' => (string) ($proforma['signer_name'] ?? ''),
            'subject' => $subject,
            'html' => buildProformaEmailHtml($proforma, $link),
            'text' => buildProformaEmailText($proforma, $link),
        ]);

        $update = $pdo->prepare(
            "UPDATE proformas
             SET email_sent_at = :sent_at, email_last_error = ''
             WHERE id = :id"
        );
        $update->execute([
            ':sent_at' => $sentAt,
            ':id' => $proformaId,
        ]);
        logProformaEmailAttempt($pdo, $proformaId, 'customer_proforma', $recipientEmail, $subject, true, '', $sentAt);
    } catch (Throwable $exception) {
        $errorMessage = substr($exception->getMessage(), 0, 500);
        $update = $pdo->prepare(
            'UPDATE proformas
             SET email_last_error = :error
             WHERE id = :id'
        );
        $update->execute([
            ':error' => $errorMessage,
            ':id' => $proformaId,
        ]);
        logProformaEmailAttempt($pdo, $proformaId, 'customer_proforma', $recipientEmail, $subject, false, $errorMessage, $sentAt);
        throw $exception;
    }
}

function sendProformaUpdateRequestEmail(PDO $pdo, array $proforma): void
{
    $proformaId = (int) ($proforma['id'] ?? 0);
    if ($proformaId <= 0) {
        throw new RuntimeException('La proforma seleccionada no existe.');
    }

    $executiveEmail = proformaExecutiveEmail($proforma);
    if ($executiveEmail === '') {
        throw new RuntimeException('La proforma no tiene un email valido del ejecutivo comercial.');
    }

    $customerEmail = proformaRecipientEmail($proforma);
    $link = publicUrl('/proforma-preview.php?id=' . $proformaId);
    $subject = 'Solicitud de cotizacion actualizada - ' . proformaProjectName($proforma);
    $sentAt = nowIso();

    try {
        smtpSendMail([
            'to_email' => $executiveEmail,
            'to_name' => proformaExecutiveName($proforma),
            'reply_to_email' => $customerEmail,
            'reply_to_name' => proformaCustomerName($proforma),
            'subject' => $subject,
            'html' => buildProformaUpdateRequestEmailHtml($proforma, $link),
            'text' => buildProformaUpdateRequestEmailText($proforma, $link),
        ]);

        $update = $pdo->prepare(
            "UPDATE proformas
             SET customer_update_requested_at = :requested_at,
                 customer_update_request_error = ''
             WHERE id = :id"
        );
        $update->execute([
            ':requested_at' => $sentAt,
            ':id' => $proformaId,
        ]);
        logProformaEmailAttempt($pdo, $proformaId, 'update_request', $executiveEmail, $subject, true, '', $sentAt);
    } catch (Throwable $exception) {
        $errorMessage = substr($exception->getMessage(), 0, 500);
        $update = $pdo->prepare(
            'UPDATE proformas
             SET customer_update_request_error = :error
             WHERE id = :id'
        );
        $update->execute([
            ':error' => $errorMessage,
            ':id' => $proformaId,
        ]);
        logProformaEmailAttempt($pdo, $proformaId, 'update_request', $executiveEmail, $subject, false, $errorMessage, $sentAt);
        throw $exception;
    }
}
