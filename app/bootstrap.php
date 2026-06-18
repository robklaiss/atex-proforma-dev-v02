<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('DATABASE_PATH', STORAGE_PATH . '/database');
define('BACKUP_PATH', STORAGE_PATH . '/backups');
define('PROFORMA_STORAGE_PATH', STORAGE_PATH . '/proformas');
define('SIGNATURE_STORAGE_PATH', STORAGE_PATH . '/signatures');
define('SQLITE_PATH', DATABASE_PATH . '/app.sqlite');
define('TEMPLATE_PDF_PATH', ROOT_PATH . '/Proforma de Factura Atex Paraguay.pdf');

require_once APP_PATH . '/environment.php';
loadEnvironmentFile(ROOT_PATH . '/.env');

defined('PROFORMA_PREFIX') || define('PROFORMA_PREFIX', getenv('PROFORMA_PREFIX') ?: '002');
defined('BACKUP_RETENTION_DAYS') || define('BACKUP_RETENTION_DAYS', 30);
defined('MIN_BACKUPS_TO_KEEP') || define('MIN_BACKUPS_TO_KEEP', 5);
$defaultCurrencyCode = getenv('DEFAULT_CURRENCY_CODE') ?: getenv('CURRENCY_CODE') ?: 'USD';
defined('DEFAULT_CURRENCY_CODE') || define('DEFAULT_CURRENCY_CODE', is_string($defaultCurrencyCode) && trim($defaultCurrencyCode) !== '' ? strtoupper(trim($defaultCurrencyCode)) : 'USD');

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Asuncion');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once APP_PATH . '/helpers.php';
require_once APP_PATH . '/disclaimers.php';
require_once APP_PATH . '/projects.php';
require_once APP_PATH . '/currency.php';
require_once APP_PATH . '/commercial.php';
require_once APP_PATH . '/commercial_status.php';
require_once APP_PATH . '/authorization.php';
require_once APP_PATH . '/db.php';
require_once APP_PATH . '/auth.php';
require_once APP_PATH . '/dashboard.php';
require_once APP_PATH . '/backup.php';
require_once APP_PATH . '/calculations.php';
require_once APP_PATH . '/mailer.php';
require_once APP_PATH . '/proforma_delivery.php';
