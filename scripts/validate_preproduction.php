<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

/**
 * @return array<string, string|bool>
 */
function validationOptions(array $arguments): array
{
    $options = [
        'url' => trim((string) (getenv('APP_URL') ?: '')),
        'document-root' => '',
        'skip-http' => false,
        'skip-tests' => false,
        'skip-backup' => false,
        'allow-smtp-disabled' => false,
    ];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--skip-http') {
            $options['skip-http'] = true;
            continue;
        }
        if ($argument === '--skip-tests') {
            $options['skip-tests'] = true;
            continue;
        }
        if ($argument === '--skip-backup') {
            $options['skip-backup'] = true;
            continue;
        }
        if ($argument === '--allow-smtp-disabled') {
            $options['allow-smtp-disabled'] = true;
            continue;
        }
        if (str_starts_with($argument, '--url=')) {
            $options['url'] = rtrim(trim(substr($argument, 6)), '/');
            continue;
        }
        if (str_starts_with($argument, '--document-root=')) {
            $options['document-root'] = trim(substr($argument, 16));
            continue;
        }

        throw new InvalidArgumentException('Opción desconocida: ' . $argument);
    }

    return $options;
}

final class PreproductionValidation
{
    private int $passed = 0;
    private int $warnings = 0;
    private int $failed = 0;

    public function pass(string $message): void
    {
        $this->passed++;
        echo '[OK] ' . $message . PHP_EOL;
    }

    public function warn(string $message): void
    {
        $this->warnings++;
        echo '[WARN] ' . $message . PHP_EOL;
    }

    public function fail(string $message): void
    {
        $this->failed++;
        echo '[FAIL] ' . $message . PHP_EOL;
    }

    public function check(bool $condition, string $success, string $failure): bool
    {
        if ($condition) {
            $this->pass($success);
            return true;
        }

        $this->fail($failure);
        return false;
    }

    public function summary(): int
    {
        echo PHP_EOL . sprintf(
            'Resultado: %d OK, %d advertencias, %d fallos.',
            $this->passed,
            $this->warnings,
            $this->failed
        ) . PHP_EOL;

        return $this->failed === 0 ? 0 : 1;
    }
}

/**
 * @return array{status:int, body:string, error:string, location:string}
 */
function validationHttpGet(string $url, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'La extensión curl no está disponible.', 'location' => ''];
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return ['status' => 0, 'body' => '', 'error' => 'No se pudo inicializar curl.', 'location' => ''];
    }

    $location = '';
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Atex-Preproduction-Validator/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
            return strlen($header);
        },
    ]);

    $body = curl_exec($handle);
    $error = $body === false ? curl_error($handle) : '';
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
        'location' => $location,
    ];
}

function validationRunCommand(array $command): int
{
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    passthru($escaped, $exitCode);
    return (int) $exitCode;
}

function validationDatabaseIsHealthy(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['integrity' => false, 'foreign_keys' => false];
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
    $foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll() === [];
    $pdo = null;

    return ['integrity' => $integrity, 'foreign_keys' => $foreignKeys];
}

try {
    $options = validationOptions($argv);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

$validation = new PreproductionValidation();
$requiredExtensions = ['pdo_sqlite', 'sqlite3', 'mbstring', 'gd', 'openssl', 'session', 'filter', 'json', 'fileinfo'];

echo 'Validación técnica de pre-producción' . PHP_EOL;
echo '====================================' . PHP_EOL;

$validation->check(
    version_compare(PHP_VERSION, '8.2.0', '>='),
    'PHP ' . PHP_VERSION . ' es compatible',
    'PHP 8.2 o superior es obligatorio; versión detectada: ' . PHP_VERSION
);
foreach ($requiredExtensions as $extension) {
    $validation->check(
        extension_loaded($extension),
        'Extensión PHP disponible: ' . $extension,
        'Falta la extensión PHP requerida: ' . $extension
    );
}

$validation->check(
    APP_ENV === 'preproduction',
    'APP_ENV=preproduction',
    'APP_ENV debe ser preproduction; valor detectado: ' . APP_ENV
);
$validation->check(
    APP_DEBUG === false,
    'APP_DEBUG está deshabilitado',
    'APP_DEBUG debe estar deshabilitado'
);

$appUrl = (string) $options['url'];
$validation->check(
    filter_var($appUrl, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($appUrl), 'https://'),
    'APP_URL usa HTTPS',
    'APP_URL debe ser una URL HTTPS válida'
);

$healthToken = (string) (getenv('HEALTHCHECK_TOKEN') ?: '');
$validation->check(
    strlen($healthToken) >= 32,
    'HEALTHCHECK_TOKEN está configurado',
    'HEALTHCHECK_TOKEN debe tener al menos 32 caracteres'
);

$documentRoot = (string) $options['document-root'];
if ($documentRoot === '') {
    $validation->warn('Document root no verificado; ejecutar con --document-root=/ruta/al/proyecto/public.');
} else {
    $validation->check(
        realpath($documentRoot) !== false && realpath($documentRoot) === realpath(PUBLIC_PATH),
        'Document root apunta a public/',
        'El document root indicado no apunta a ' . PUBLIC_PATH
    );
}

foreach ([DATABASE_PATH, BACKUP_PATH, PROFORMA_STORAGE_PATH, SIGNATURE_STORAGE_PATH, LOG_PATH] as $path) {
    $validation->check(
        is_dir($path) && is_writable($path),
        'Escritura disponible en ' . storageRelativePath($path),
        'Falta carpeta o permiso de escritura en ' . storageRelativePath($path)
    );
}

$smtpKeys = ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'];
$smtpConfigured = [];
foreach ($smtpKeys as $key) {
    $smtpConfigured[$key] = trim((string) (getenv($key) ?: '')) !== '';
}
$smtpComplete = !in_array(false, $smtpConfigured, true);
$smtpDisabled = !in_array(true, $smtpConfigured, true);
if ($smtpComplete) {
    $validation->pass('Configuración SMTP completa');
} elseif ($smtpDisabled && (bool) $options['allow-smtp-disabled']) {
    $validation->warn('SMTP deshabilitado de forma explícita; la aceptación final requiere configurarlo y probarlo.');
} else {
    $validation->fail('SMTP está incompleto. Configurar todas las credenciales o usar --allow-smtp-disabled durante una validación sin envíos.');
}

if (!(bool) $options['skip-tests']) {
    echo PHP_EOL . 'PHP lint' . PHP_EOL;
    $phpFiles = [];
    foreach (['app', 'public', 'scripts', 'tests'] as $directory) {
        foreach (glob(ROOT_PATH . '/' . $directory . '/*.php') ?: [] as $file) {
            $phpFiles[] = $file;
        }
    }
    sort($phpFiles);
    foreach ($phpFiles as $file) {
        $relative = substr($file, strlen(ROOT_PATH) + 1);
        $validation->check(
            validationRunCommand([PHP_BINARY, '-l', $file]) === 0,
            'Lint correcto: ' . $relative,
            'Error de sintaxis: ' . $relative
        );
    }

    echo PHP_EOL . 'Suite funcional automatizada' . PHP_EOL;
    $testFiles = [
        'tests/project_stage1_test.php',
        'tests/currency_stage2_test.php',
        'tests/commercial_stage3_test.php',
        'tests/authorization_stage4_test.php',
        'tests/pdf_notes_stage5_test.php',
        'tests/dashboard_stage6_test.php',
        'tests/installation_stage9_test.php',
    ];
    foreach ($testFiles as $relative) {
        $validation->check(
            validationRunCommand([PHP_BINARY, ROOT_PATH . '/' . $relative]) === 0,
            'Test correcto: ' . $relative,
            'Falló el test: ' . $relative
        );
    }
} else {
    $validation->warn('Lint y tests omitidos por --skip-tests.');
}

$databaseHealth = validationDatabaseIsHealthy(SQLITE_PATH);
$validation->check(
    $databaseHealth['integrity'],
    'SQLite integrity_check: ok',
    'SQLite integrity_check falló'
);
$validation->check(
    $databaseHealth['foreign_keys'],
    'SQLite foreign_key_check: sin resultados',
    'SQLite foreign_key_check detectó referencias inválidas'
);

if (!(bool) $options['skip-backup']) {
    try {
        $backup = createDatabaseBackup();
        if ($backup === null) {
            $validation->fail('No se pudo crear el backup porque no existe la base.');
        } else {
            $backupHealth = validationDatabaseIsHealthy($backup);
            $validation->check(
                $backupHealth['integrity'] && $backupHealth['foreign_keys'],
                'Backup creado y verificable: ' . storageRelativePath($backup),
                'El backup creado no superó la validación de SQLite'
            );
        }
    } catch (Throwable $exception) {
        $validation->fail('Falló la creación del backup: ' . $exception->getMessage());
    }
} else {
    $validation->warn('Backup omitido por --skip-backup.');
}

if (!(bool) $options['skip-http'] && $appUrl !== '') {
    echo PHP_EOL . 'Validación HTTP' . PHP_EOL;
    $unauthorized = validationHttpGet($appUrl . '/health.php');
    $validation->check(
        $unauthorized['status'] === 401,
        'Healthcheck sin token responde 401',
        'Healthcheck sin token respondió ' . $unauthorized['status']
            . ($unauthorized['error'] !== '' ? ': ' . $unauthorized['error'] : '')
    );

    $authorized = validationHttpGet(
        $appUrl . '/health.php',
        ['Authorization: Bearer ' . $healthToken]
    );
    $healthyBody = str_contains($authorized['body'], 'OK')
        && str_contains($authorized['body'], 'DB OK')
        && str_contains($authorized['body'], 'Storage OK')
        && str_contains($authorized['body'], 'Environment OK');
    $validation->check(
        $authorized['status'] === 200 && $healthyBody,
        'Healthcheck autenticado responde 200 y todos los estados están OK',
        'Healthcheck autenticado no está sano; HTTP ' . $authorized['status']
            . ($authorized['error'] !== '' ? ': ' . $authorized['error'] : '')
    );

    $sensitivePaths = [
        '/.env',
        '/storage/',
        '/storage/database/app.sqlite',
        '/storage/backups/',
        '/app/',
        '/migrations/',
        '/tests/',
        '/docs/',
        '/scripts/',
    ];
    foreach ($sensitivePaths as $path) {
        $response = validationHttpGet($appUrl . $path);
        $blockedStatus = in_array($response['status'], [403, 404], true);
        $redirectedToLogin = in_array($response['status'], [301, 302, 307, 308], true)
            && str_contains(strtolower($response['location']), 'login.php');
        $validation->check(
            $blockedStatus || $redirectedToLogin,
            'Ruta sensible bloqueada: ' . $path,
            'Ruta sensible ' . $path . ' respondió HTTP ' . $response['status']
        );
    }
} elseif ((bool) $options['skip-http']) {
    $validation->warn('Healthcheck y seguridad HTTP omitidos por --skip-http.');
} else {
    $validation->fail('No se puede validar HTTP sin APP_URL o --url.');
}

exit($validation->summary());
