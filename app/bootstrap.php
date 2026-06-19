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
define('LOG_PATH', STORAGE_PATH . '/logs');
define('SQLITE_PATH', DATABASE_PATH . '/app.sqlite');
define('TEMPLATE_PDF_PATH', ROOT_PATH . '/Proforma de Factura Atex Paraguay.pdf');

require_once APP_PATH . '/environment.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
define('ENV_FILE_LOADED', loadEnvironmentFile(ROOT_PATH . '/.env'));
$runtime = configureApplicationRuntime(LOG_PATH . '/app.log');
define('APP_ENV', $runtime['environment']);
define('APP_DEBUG', $runtime['debug']);

defined('PROFORMA_PREFIX') || define('PROFORMA_PREFIX', getenv('PROFORMA_PREFIX') ?: '002');
defined('BACKUP_RETENTION_DAYS') || define('BACKUP_RETENTION_DAYS', 30);
defined('MIN_BACKUPS_TO_KEEP') || define('MIN_BACKUPS_TO_KEEP', 5);
$defaultCurrencyCode = getenv('DEFAULT_CURRENCY_CODE') ?: getenv('CURRENCY_CODE') ?: 'USD';
defined('DEFAULT_CURRENCY_CODE') || define('DEFAULT_CURRENCY_CODE', is_string($defaultCurrencyCode) && trim($defaultCurrencyCode) !== '' ? strtoupper(trim($defaultCurrencyCode)) : 'USD');

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Asuncion');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionBasePath = trim((string) (getenv('APP_BASE_PATH') ?: ''), '/');
    $sessionCookiePath = $sessionBasePath === '' ? '/' : '/' . $sessionBasePath . '/';
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $configuredUrl = strtolower((string) (getenv('APP_URL') ?: getenv('APP_PUBLIC_URL') ?: ''));
    $secureCookie = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https'
        || str_starts_with($configuredUrl, 'https://');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $sessionCookiePath,
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once APP_PATH . '/helpers.php';
require_once APP_PATH . '/product_catalog.php';
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
