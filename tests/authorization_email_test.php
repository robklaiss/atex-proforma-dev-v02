<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

function authorizationEmailAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        email TEXT NOT NULL
    );
    CREATE TABLE clients (
        id INTEGER PRIMARY KEY,
        empresa TEXT NOT NULL
    );
    CREATE TABLE projects (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
    CREATE TABLE country_units (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
    CREATE TABLE proformas (
        id INTEGER PRIMARY KEY,
        client_id INTEGER NOT NULL,
        project_id INTEGER,
        country_unit_id INTEGER,
        proforma_number TEXT NOT NULL,
        project_name TEXT NOT NULL,
        company_name_snapshot TEXT NOT NULL,
        currency_symbol TEXT NOT NULL,
        exchange_rate_used REAL NOT NULL
    );
    CREATE TABLE proforma_authorizations (
        id INTEGER PRIMARY KEY,
        proforma_id INTEGER NOT NULL,
        requested_by INTEGER NOT NULL,
        requested_to INTEGER NOT NULL
    );
    CREATE TABLE proforma_email_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proforma_id INTEGER,
        event_type TEXT NOT NULL,
        to_email TEXT NOT NULL,
        subject TEXT NOT NULL,
        success INTEGER NOT NULL,
        error_message TEXT NOT NULL,
        sent_at TEXT NOT NULL
    );'
);

$pdo->exec(
    "INSERT INTO users VALUES
        (1, 'ejecutivo', 'Eva', 'Ejecutiva', 'eva@atex.test'),
        (2, 'supervisor', 'Sonia', 'Supervisora', 'sonia@atex.test');
     INSERT INTO clients VALUES (1, 'Cliente SA');
     INSERT INTO projects VALUES (1, 'Proyecto Demo');
     INSERT INTO country_units VALUES (1, 'Paraguay');
     INSERT INTO proformas VALUES
        (1, 1, 1, 1, 'DEMO-001', 'Proyecto anterior', 'Cliente Snapshot', '₲', 7800);
     INSERT INTO proforma_authorizations VALUES (1, 1, 1, 2);"
);

$capturedMessage = null;
sendProformaAuthorizationRequestEmail(
    $pdo,
    1,
    static function (array $message) use (&$capturedMessage): void {
        $capturedMessage = $message;
    }
);

authorizationEmailAssert(is_array($capturedMessage), 'genera el mensaje de solicitud de autorización');
authorizationEmailAssert(
    ($capturedMessage['to_email'] ?? '') === 'sonia@atex.test',
    'envía el correo al supervisor seleccionado'
);
authorizationEmailAssert(
    ($capturedMessage['reply_to_email'] ?? '') === 'eva@atex.test',
    'configura al solicitante como Reply-To'
);
authorizationEmailAssert(
    str_contains((string) ($capturedMessage['html'] ?? ''), 'DEMO-001'),
    'incluye la proforma en el contenido HTML'
);

$log = $pdo->query('SELECT * FROM proforma_email_logs ORDER BY id DESC LIMIT 1')->fetch();
authorizationEmailAssert(
    is_array($log)
        && $log['event_type'] === 'authorization_request'
        && (int) $log['success'] === 1,
    'registra el envío exitoso en el historial'
);

echo PHP_EOL . 'Pruebas de email de autorización completadas.' . PHP_EOL;
