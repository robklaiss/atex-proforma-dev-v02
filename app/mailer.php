<?php

declare(strict_types=1);

function smtpEnvValue(string $envKey, string $default = ''): string
{
    $envValue = getenv($envKey);
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    return $default;
}

function smtpConfig(): array
{
    $username = smtpEnvValue('SMTP_USERNAME');

    return [
        'host' => smtpEnvValue('SMTP_HOST'),
        'port' => (int) smtpEnvValue('SMTP_PORT', '587'),
        'secure' => strtolower(smtpEnvValue('SMTP_SECURE', 'tls')),
        'username' => $username,
        'password' => smtpEnvValue('SMTP_PASSWORD'),
        'from_email' => smtpEnvValue('SMTP_FROM_EMAIL', $username),
        'from_name' => smtpEnvValue('SMTP_FROM_NAME', 'ATEX'),
        'timeout' => (int) smtpEnvValue('SMTP_TIMEOUT', '30'),
    ];
}

function mimeEncodeHeader(string $value): string
{
    return preg_match('/^[\x20-\x7E]*$/', $value) === 1 ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function mimeAddress(string $email, string $name = ''): string
{
    $email = trim($email);
    $name = trim($name);

    if ($name === '') {
        return $email;
    }

    return mimeEncodeHeader($name) . ' <' . $email . '>';
}

function smtpReadResponse($stream): array
{
    $response = '';
    while (($line = fgets($stream, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    return [$code, $response];
}

function smtpExpect($stream, array $expectedCodes, string $context): string
{
    [$code, $response] = smtpReadResponse($stream);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error en ' . $context . ': ' . trim($response));
    }

    return $response;
}

function smtpCommand($stream, string $command, array $expectedCodes, string $context): string
{
    if (fwrite($stream, $command . "\r\n") === false) {
        throw new RuntimeException('No se pudo escribir al servidor SMTP.');
    }

    return smtpExpect($stream, $expectedCodes, $context);
}

function smtpDotStuff(string $message): string
{
    $message = str_replace(["\r\n", "\r"], "\n", $message);
    $lines = explode("\n", $message);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function smtpBuildMessage(array $message, array $config): string
{
    $boundary = 'atex_' . bin2hex(random_bytes(12));
    $fromName = (string) ($message['from_name'] ?? $config['from_name']);
    $fromEmail = (string) ($message['from_email'] ?? $config['from_email']);
    $toName = (string) ($message['to_name'] ?? '');
    $toEmail = (string) ($message['to_email'] ?? '');
    $replyToEmail = trim((string) ($message['reply_to_email'] ?? ''));
    $replyToName = trim((string) ($message['reply_to_name'] ?? ''));
    $subject = (string) ($message['subject'] ?? '');
    $text = (string) ($message['text'] ?? '');
    $html = (string) ($message['html'] ?? '');

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mimeAddress($fromEmail, $fromName),
        'To: ' . mimeAddress($toEmail, $toName),
        'Subject: ' . mimeEncodeHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . mimeAddress($replyToEmail, $replyToName);
    }

    $body = [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($text)),
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html)),
        '--' . $boundary . '--',
        '',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
}

function smtpSendMail(array $message): void
{
    $config = smtpConfig();
    $host = (string) $config['host'];
    $port = (int) $config['port'];
    $secure = (string) $config['secure'];
    $username = (string) $config['username'];
    $password = (string) $config['password'];
    $fromEmail = (string) ($message['from_email'] ?? $config['from_email']);
    $toEmail = trim((string) ($message['to_email'] ?? ''));
    $timeout = max(5, (int) $config['timeout']);

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El destinatario no tiene un email valido.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El remitente SMTP no tiene un email valido.');
    }
    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Configura SMTP_HOST, SMTP_USERNAME y SMTP_PASSWORD para enviar correos.');
    }

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout);
    if (!$stream) {
        throw new RuntimeException('No se pudo conectar al servidor SMTP: ' . $errstr);
    }

    stream_set_timeout($stream, $timeout);

    try {
        smtpExpect($stream, [220], 'conexion');
        $domain = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'atex.la')) ?: 'atex.la';
        smtpCommand($stream, 'EHLO ' . $domain, [250], 'EHLO');

        if ($secure === 'tls') {
            smtpCommand($stream, 'STARTTLS', [220], 'STARTTLS');
            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (!stream_socket_enable_crypto($stream, true, $cryptoMethod)) {
                throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
            }
            smtpCommand($stream, 'EHLO ' . $domain, [250], 'EHLO TLS');
        }

        smtpCommand($stream, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        smtpCommand($stream, base64_encode($username), [334], 'SMTP usuario');
        smtpCommand($stream, base64_encode($password), [235], 'SMTP autenticacion');
        smtpCommand($stream, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
        smtpCommand($stream, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
        smtpCommand($stream, 'DATA', [354], 'DATA');

        $rawMessage = smtpBuildMessage($message, $config);
        if (fwrite($stream, smtpDotStuff($rawMessage) . "\r\n.\r\n") === false) {
            throw new RuntimeException('No se pudo enviar el contenido del correo.');
        }
        smtpExpect($stream, [250], 'envio');
        smtpCommand($stream, 'QUIT', [221], 'QUIT');
    } finally {
        fclose($stream);
    }
}
