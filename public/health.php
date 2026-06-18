<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    require_once __DIR__ . '/../app/bootstrap.php';

    $configuredToken = (string) (getenv('HEALTHCHECK_TOKEN') ?: '');
    if ($configuredToken !== '') {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $providedToken = str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : (string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '');

        if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer');
            echo 'UNAUTHORIZED';
            exit;
        }
    }

    $checks = [
        'app' => true,
        'database' => false,
        'storage' => false,
        'environment' => ENV_FILE_LOADED || getenv('APP_ENV') !== false,
    ];

    if (is_file(SQLITE_PATH) && is_readable(SQLITE_PATH)) {
        $healthPdo = new PDO('sqlite:' . SQLITE_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $integrity = $healthPdo->query('PRAGMA integrity_check')->fetchColumn();
        $checks['database'] = $integrity === 'ok';
        $healthPdo = null;
    }

    $checks['storage'] = is_dir(PROFORMA_STORAGE_PATH)
        && is_writable(PROFORMA_STORAGE_PATH)
        && is_dir(BACKUP_PATH)
        && is_writable(BACKUP_PATH);

    $healthy = !in_array(false, $checks, true);
    http_response_code($healthy ? 200 : 503);

    echo $healthy ? 'OK' : 'DEGRADED';
    echo PHP_EOL . ($checks['database'] ? 'DB OK' : 'DB ERROR');
    echo PHP_EOL . ($checks['storage'] ? 'Storage OK' : 'Storage ERROR');
    echo PHP_EOL . ($checks['environment'] ? 'Environment OK' : 'Environment ERROR');

    if (!$healthy && APP_ENV === 'local' && APP_DEBUG) {
        $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
        echo PHP_EOL . 'Failed checks: ' . implode(', ', $failed);
    }
} catch (Throwable $exception) {
    error_log('Healthcheck failed: ' . $exception->getMessage());
    http_response_code(503);
    echo 'DEGRADED';
    if (defined('APP_ENV') && APP_ENV === 'local' && defined('APP_DEBUG') && APP_DEBUG) {
        echo PHP_EOL . 'App ERROR';
    }
}
