<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sanitizePlainText(string $value): string
{
    $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value) ?? $value;
    return trim(strip_tags($value));
}

function nowIso(): string
{
    return date('Y-m-d H:i:s');
}

function normalizeBasePath(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return '';
    }

    return '/' . trim($path, '/');
}

function appBasePath(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $configured = getenv('APP_BASE_PATH');
    if (is_string($configured) && $configured !== '') {
        $basePath = normalizeBasePath($configured);
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = dirname($scriptName);
    if ($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }
    if (str_ends_with($scriptDir, '/public')) {
        $scriptDir = substr($scriptDir, 0, -strlen('/public'));
    }

    $basePath = normalizeBasePath($scriptDir);
    return $basePath;
}

function currentPublicPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    $basePath = appBasePath();

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    if ($path === '/public') {
        return '/';
    }
    if (str_starts_with($path, '/public/')) {
        $path = substr($path, strlen('/public')) ?: '/';
    }

    return '/' . ltrim($path, '/');
}

function publicPath(string $path = ''): string
{
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//|^(?:mailto|tel):|^#~i', $path) === 1) {
        return $path;
    }

    $basePath = appBasePath();
    if ($path === '' || $path === '/') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return ($basePath === '' ? '' : $basePath) . '/' . ltrim($path, '/');
}

function appBaseUrl(): string
{
    static $baseUrl = null;

    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $configured = getenv('APP_URL') ?: getenv('APP_PUBLIC_URL');
    if (is_string($configured) && trim($configured) !== '') {
        $baseUrl = rtrim(trim($configured), '/');
        return $baseUrl;
    }

    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $baseUrl = '';
        return $baseUrl;
    }

    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || $proto === 'https';
    $baseUrl = ($https ? 'https' : 'http') . '://' . $host . appBasePath();
    return rtrim($baseUrl, '/');
}

function publicUrl(string $path = ''): string
{
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//|^(?:mailto|tel):|^#~i', $path) === 1) {
        return $path;
    }

    $baseUrl = appBaseUrl();
    if ($baseUrl === '') {
        return publicPath($path);
    }

    if ($path === '' || $path === '/') {
        return $baseUrl . '/';
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . publicPath($path));
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function currencyOptions(): array
{
    return [
        'USD' => [
            'label' => 'Dólar (USD)',
            'symbol' => 'US$',
            'decimals' => 2,
            'decimal_separator' => '.',
            'thousands_separator' => ',',
        ],
        'PYG' => [
            'label' => 'Guaraní paraguayo (PYG)',
            'symbol' => '₲',
            'decimals' => 0,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
        'COP' => [
            'label' => 'Pesos colombianos (COP)',
            'symbol' => 'COL$',
            'decimals' => 0,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
        'DOP' => [
            'label' => 'Pesos dominicanos (DOP)',
            'symbol' => 'RD$',
            'decimals' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
        'PAB' => [
            'label' => 'Moneda local de Panamá (PAB)',
            'symbol' => '฿',
            'decimals' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
    ];
}

function defaultCurrencyCode(): string
{
    $configured = defined('DEFAULT_CURRENCY_CODE') ? (string) constant('DEFAULT_CURRENCY_CODE') : 'USD';
    return normalizeCurrencyCode($configured);
}

function normalizeCurrencyCode(string $currencyCode): string
{
    $currencyCode = strtoupper(trim($currencyCode));
    return array_key_exists($currencyCode, currencyOptions()) ? $currencyCode : 'USD';
}

function currencyCodeForCountry(string $country): string
{
    $country = trim($country);
    $map = [
        'Paraguay' => 'PYG',
        'Colombia' => 'COP',
        'República Dominicana' => 'DOP',
        'Panamá' => 'PAB',
    ];

    return normalizeCurrencyCode($map[$country] ?? 'USD');
}

function proformaCurrencyCode(array $proforma): string
{
    return normalizeCurrencyCode((string) ($proforma['currency_code'] ?? defaultCurrencyCode()));
}

function formatMoney(float $amount, ?string $currencyCode = null): string
{
    $currencyCode = $currencyCode === null ? defaultCurrencyCode() : normalizeCurrencyCode($currencyCode);
    $currency = currencyOptions()[$currencyCode];
    return formatMoneyWithSymbol($amount, $currencyCode, (string) $currency['symbol']);
}

function formatUsdUnitPrice(float $amount): string
{
    $decimals = 4;
    while ($decimals > 2 && abs($amount - round($amount, $decimals - 1)) < 0.00000001) {
        $decimals--;
    }

    return 'US$ ' . number_format($amount, $decimals, '.', ',');
}

function formatMoneyWithSymbol(float $amount, string $currencyCode, string $currencySymbol): string
{
    $currencyCode = strtoupper(trim($currencyCode));
    $currency = currencyOptions()[$currencyCode] ?? [
        'symbol' => $currencyCode !== '' ? $currencyCode : 'US$',
        'decimals' => 2,
        'decimal_separator' => ',',
        'thousands_separator' => '.',
    ];
    $decimals = max(0, (int) ($currency['decimals'] ?? 2));
    $currencySymbol = html_entity_decode(trim($currencySymbol), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($currencySymbol === '') {
        $currencySymbol = (string) ($currency['symbol'] ?? ($currencyCode !== '' ? $currencyCode : 'US$'));
    }

    return $currencySymbol . ' ' . number_format(
        $amount,
        $decimals,
        (string) ($currency['decimal_separator'] ?? '.'),
        (string) ($currency['thousands_separator'] ?? ',')
    );
}

function formatProformaMoney(float $amount, array $proforma): string
{
    $currencyMode = normalizeProformaCurrencyMode((string) ($proforma['currency_mode'] ?? 'USD'));
    if ($currencyMode === 'LOCAL') {
        $converted = convertUsdAmount($amount, isset($proforma['exchange_rate_used']) ? (float) $proforma['exchange_rate_used'] : null);
        if ($converted === null) {
            $symbol = trim((string) ($proforma['currency_symbol'] ?? currencySymbol(proformaCurrencyCode($proforma))));
            return ($symbol !== '' ? $symbol : currencySymbol(proformaCurrencyCode($proforma))) . ' pendiente';
        }
        return formatMoneyWithSymbol(
            $converted,
            (string) ($proforma['currency_code'] ?? ''),
            (string) ($proforma['currency_symbol'] ?? '')
        );
    }

    return formatMoney($amount, 'USD');
}

function formatProformaUnitPrice(float $amount, array $proforma): string
{
    if (normalizeProformaCurrencyMode((string) ($proforma['currency_mode'] ?? 'USD')) === 'LOCAL') {
        return formatProformaMoney($amount, $proforma);
    }

    return formatUsdUnitPrice($amount);
}

function formatMoneyBreakdown(array $amountsByCurrency): array
{
    $lines = [];
    foreach (currencyOptions() as $currencyCode => $_currency) {
        if (!array_key_exists($currencyCode, $amountsByCurrency)) {
            continue;
        }

        $amount = round((float) $amountsByCurrency[$currencyCode], 2);
        $lines[] = formatMoney($amount, $currencyCode);
    }

    return $lines !== [] ? $lines : [formatMoney(0.0)];
}

function currencySymbol(?string $currencyCode = null): string
{
    $currencyCode = $currencyCode === null ? defaultCurrencyCode() : normalizeCurrencyCode($currencyCode);
    $symbol = (string) (currencyOptions()[$currencyCode]['symbol'] ?? 'US$');
    $symbol = html_entity_decode(trim($symbol), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($symbol === '' || preg_match('/^\d+$/', $symbol) === 1) {
        return 'US$';
    }

    return $symbol;
}

function emptyFieldMarker(): string
{
    return '----';
}

function defaultCountry(): string
{
    return 'Paraguay';
}

function countryOptions(): array
{
    return ['Colombia', 'Paraguay', 'Panamá', 'República Dominicana'];
}

function roleOptions(): array
{
    return [
        'admin' => 'Administrador',
        'director' => 'Director',
        'manager' => 'Gerente',
        'supervisor' => 'Supervisor',
        'commercial_executive' => 'Ejecutivo comercial',
        'assistant' => 'Asistente comercial',
    ];
}

function userRoleLabel(string $role): string
{
    if ($role === 'user') {
        return 'Ejecutivo comercial';
    }

    return roleOptions()[$role] ?? 'Usuario';
}

function salesSignerRoles(): array
{
    return ['admin', 'manager', 'supervisor', 'commercial_executive'];
}

function isAllowedCountry(string $country): bool
{
    return in_array($country, countryOptions(), true);
}

function isAllowedRole(string $role): bool
{
    return array_key_exists($role, roleOptions());
}

function displayOrMarker(?string $value): string
{
    $value = trim((string) $value);
    return $value === '' ? emptyFieldMarker() : $value;
}

function parseDecimalInput(string $value): float
{
    $normalized = trim($value);
    if ($normalized === '') {
        return 0.0;
    }

    $normalized = preg_replace('/[^\d,.\-]/', '', $normalized) ?? '';
    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($lastComma !== false) {
        $normalized = str_replace(',', '.', $normalized);
    }

    return (float) $normalized;
}

function formatNumber(float $amount): string
{
    $formatted = number_format($amount, 2, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

function formatInteger(int $amount): string
{
    return number_format($amount, 0, ',', '.');
}

function formatDateLong(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    return (int) date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp)] . ' de ' . date('Y', $timestamp);
}

function formatDateTimeShort(?string $dateTime): string
{
    $value = trim((string) $dateTime);
    if ($value === '') {
        return emptyFieldMarker();
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

function userFullName(array $user): string
{
    $name = trim(trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? '')));
    if ($name !== '') {
        return $name;
    }

    return trim((string) ($user['username'] ?? ''));
}

function userSignature(array $user): array
{
    return [
        'name' => userFullName($user),
        'position' => trim((string) ($user['commercial_position'] ?? '')),
        'email' => trim((string) ($user['email'] ?? '')),
        'phone' => trim((string) ($user['phone'] ?? '')),
        'unit' => trim((string) ($user['unit'] ?? '')),
        'signature_image' => trim((string) ($user['signature_image'] ?? '')),
    ];
}

function signatureMissingFields(array $signature): array
{
    $labels = [
        'name' => 'nombre y apellido',
        'position' => 'cargo comercial',
        'email' => 'correo electronico',
        'phone' => 'celular',
        'unit' => 'unidad',
    ];
    $missing = [];

    foreach ($labels as $key => $label) {
        if (trim((string) ($signature[$key] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    return $missing;
}

function storeSignatureUpload(array $file, string $existingPath = ''): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return trim($existingPath);
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo cargar la imagen de firma.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_file($tmpName) || $size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('La imagen de firma debe ser un PNG válido de hasta 2 MB.');
    }

    $imageInfo = @getimagesize($tmpName);
    if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== 'image/png') {
        throw new RuntimeException('La imagen de firma debe estar en formato PNG.');
    }

    if (!is_dir(SIGNATURE_STORAGE_PATH) && !mkdir(SIGNATURE_STORAGE_PATH, 0775, true) && !is_dir(SIGNATURE_STORAGE_PATH)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de firmas.');
    }

    $filename = 'signature_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.png';
    $target = SIGNATURE_STORAGE_PATH . '/' . $filename;
    $moved = is_uploaded_file($tmpName)
        ? move_uploaded_file($tmpName, $target)
        : copy($tmpName, $target);
    if (!$moved) {
        throw new RuntimeException('No se pudo guardar la imagen de firma.');
    }

    return 'signatures/' . $filename;
}

function storedSignatureAbsolutePath(?string $relativePath): ?string
{
    $filename = safeBasename((string) $relativePath);
    if ($filename === '') {
        return null;
    }

    $path = SIGNATURE_STORAGE_PATH . '/' . $filename;
    $realDir = realpath(SIGNATURE_STORAGE_PATH);
    $realPath = realpath($path);
    if (!$realDir || !$realPath || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
        return null;
    }

    return $realPath;
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function normalizeProjectDisplayName(string $name): string
{
    $name = trim($name);
    $normalized = preg_replace('/\s+/u', ' ', $name);
    return is_string($normalized) ? trim($normalized) : $name;
}

function projectNameAscii(string $name): string
{
    $name = strtr($name, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ñ' => 'N', 'ñ' => 'n', 'Ç' => 'C', 'ç' => 'c',
    ]);

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($converted) && $converted !== '') {
            $name = $converted;
        }
    }

    return $name;
}

function normalizeProjectName(string $name): string
{
    $name = strtolower(projectNameAscii(normalizeProjectDisplayName($name)));
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

function projectMainWords(string $name): array
{
    $normalized = normalizeProjectName($name);
    if ($normalized === '') {
        return [];
    }

    $words = explode(' ', $normalized);
    $connectors = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'en'];
    $mainWords = array_values(array_filter(
        $words,
        static fn (string $word): bool => $word !== '' && !in_array($word, $connectors, true)
    ));

    return $mainWords !== [] ? $mainWords : array_values(array_filter($words));
}

function projectPrefixCandidates(string $name): array
{
    $words = projectMainWords($name);
    if ($words === []) {
        return [];
    }

    $suffix = '';
    foreach (array_slice($words, 1) as $word) {
        $suffix .= strtoupper(substr($word, 0, 1));
    }

    $candidates = [];
    $base = '';
    foreach ($words as $word) {
        $base .= strtoupper(substr($word, 0, 1));
    }
    if ($base !== '') {
        $candidates[] = $base;
    }

    $firstWord = $words[0];
    $firstWordLength = strlen($firstWord);
    for ($length = 2; $length <= $firstWordLength; $length++) {
        $firstPart = ucfirst(strtolower(substr($firstWord, 0, $length)));
        $candidate = $firstPart . $suffix;
        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }

    return $candidates;
}

function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function storageRelativePath(string $absolutePath): string
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $storage = str_replace('\\', '/', STORAGE_PATH) . '/';
    if (str_starts_with($absolutePath, $storage)) {
        return substr($absolutePath, strlen($storage));
    }
    return basename($absolutePath);
}

function safeBasename(string $filename): string
{
    return basename(str_replace('\\', '/', $filename));
}

function renderFlashMessages(): void
{
    foreach (getFlashes() as $flash) {
        $type = $flash['type'] === 'error' ? 'error' : 'success';
        echo '<div class="flash ' . e($type) . '">' . e($flash['message']) . '</div>';
    }
}
