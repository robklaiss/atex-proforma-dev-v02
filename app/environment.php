<?php

declare(strict_types=1);

function loadEnvironmentFile(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException(sprintf('Línea inválida en %s:%d.', $path, $lineNumber + 1));
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException(sprintf('Variable inválida en %s:%d.', $path, $lineNumber + 1));
        }

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(
                        ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                        ["\n", "\r", "\t", '"', '\\'],
                        $value
                    );
                }
            }
        }

        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    return true;
}

function environmentBoolean(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
        throw new RuntimeException(sprintf('%s debe ser 0 o 1.', $name));
    }

    return $parsed;
}

function applicationEnvironment(): string
{
    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'local')));
    if (!in_array($environment, ['local', 'preproduction', 'production'], true)) {
        throw new RuntimeException('APP_ENV debe ser local, preproduction o production.');
    }

    return $environment;
}

/**
 * @return array{environment:string,debug:bool}
 */
function configureApplicationRuntime(string $logPath): array
{
    $environment = applicationEnvironment();

    if ($environment !== 'local') {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }

    $debug = environmentBoolean('APP_DEBUG', $environment === 'local');

    if ($environment !== 'local') {
        $debug = false;
    }

    $logDirectory = dirname($logPath);
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        throw new RuntimeException('No se pudo crear la carpeta segura de logs.');
    }

    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('display_startup_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $logPath);

    if (PHP_SAPI !== 'cli') {
        set_exception_handler(static function (Throwable $exception) use ($debug): void {
            error_log(sprintf(
                'Uncaught %s: %s in %s:%d%s%s',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                PHP_EOL,
                $exception->getTraceAsString()
            ));

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store');
            }

            if ($debug) {
                echo 'Error interno: ' . $exception->getMessage();
                return;
            }

            echo 'Ocurrió un error interno. Consulte al administrador.';
        });
    }

    return ['environment' => $environment, 'debug' => $debug];
}
