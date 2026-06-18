<?php

declare(strict_types=1);

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        throw new RuntimeException('Token de seguridad invalido. Volve a intentar.');
    }
}

function attemptLogin(string $username, string $password): bool
{
    if (!tableExists(db(), 'users')) {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    if (!tableExists(db(), 'users')) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, username, role, first_name, last_name, email, phone, unit, reports_to_id,
                commercial_position, signature_image, created_at
         FROM users
         WHERE id = :id'
    );
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    return $user;
}

function isAdmin(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && $user['role'] === 'admin';
}

function isCommercialAssistant(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && $user['role'] === 'assistant';
}

function isDirector(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && $user['role'] === 'director';
}

function isCommercialExecutive(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && in_array($user['role'], ['commercial_executive', 'user'], true);
}

function userHomePath(?array $user = null): string
{
    $user ??= currentUser();
    if (isCommercialAssistant($user)) {
        return '/profile.php';
    }

    return isDirector($user) ? '/indicators.php' : '/dashboard.php';
}

function userAllowedPath(?array $user, string $path): bool
{
    if ($user === null || isAdmin($user)) {
        return true;
    }

    if (isCommercialAssistant($user)) {
        return in_array($path, [
            '/profile.php',
        ], true);
    }

    if (isDirector($user)) {
        return in_array($path, [
            '/dashboard.php',
            '/indicators.php',
            '/proformas.php',
            '/proforma-preview.php',
            '/proforma-view.php',
            '/download-proforma.php',
            '/proforma-authorizations.php',
            '/signature-image.php',
            '/delivery-log.php',
            '/exchange-rates.php',
            '/profile.php',
        ], true);
    }

    return true;
}

function canChooseProformaSeller(?array $user = null): bool
{
    $user ??= currentUser();
    return isAdmin($user);
}

function canCreateProformas(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && in_array($user['role'], ['admin', 'manager', 'supervisor', 'commercial_executive', 'user'], true);
}

function canChangeProformaStatus(?array $user = null): bool
{
    return canCreateProformas($user);
}

function canManageExchangeRates(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && in_array(
        (string) ($user['role'] ?? ''),
        ['admin', 'director', 'manager', 'supervisor'],
        true
    );
}

function requireExchangeRateManager(): void
{
    requireAuth();
    if (!canManageExchangeRates()) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

function commercialVisibilityMode(?array $user): string
{
    if ($user === null) {
        return 'none';
    }

    return match ((string) ($user['role'] ?? '')) {
        'admin', 'director' => 'all',
        'manager' => 'unit_team',
        'supervisor' => 'team',
        'commercial_executive', 'user' => 'self',
        default => 'none',
    };
}

function visibleTeamUserIds(int $userId): array
{
    static $cache = [];

    if ($userId <= 0) {
        return [];
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    if (!tableExists(db(), 'users')) {
        return [$userId];
    }

    $stmt = db()->prepare(
        'WITH RECURSIVE team(id) AS (
             SELECT :id
             UNION
             SELECT u.id
             FROM users u
             JOIN team t ON u.reports_to_id = t.id
             WHERE u.id <> u.reports_to_id
         )
         SELECT id FROM team'
    );
    $stmt->execute([':id' => $userId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (!in_array($userId, $ids, true)) {
        $ids[] = $userId;
    }

    $cache[$userId] = array_values(array_unique($ids));
    return $cache[$userId];
}

function sqlInPlaceholders(array $values, string $prefix, array &$params): string
{
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $key = ':' . $prefix . '_' . $index;
        $params[$key] = (int) $value;
        $placeholders[] = $key;
    }

    return implode(', ', $placeholders);
}

function userVisibilityClause(?array $user, string $userAlias = 'u', string $paramPrefix = 'visible_user'): array
{
    $params = [];
    $mode = commercialVisibilityMode($user);
    $userId = (int) ($user['id'] ?? 0);

    if ($mode === 'all') {
        return ['1 = 1', []];
    }
    if ($mode === 'none' || $userId <= 0) {
        return ['1 = 0', []];
    }
    if ($mode === 'self') {
        $params[':' . $paramPrefix . '_id'] = $userId;
        return [$userAlias . '.id = :' . $paramPrefix . '_id', $params];
    }

    $teamIds = visibleTeamUserIds($userId);
    if ($teamIds === []) {
        return ['1 = 0', []];
    }
    $teamSql = $userAlias . '.id IN (' . sqlInPlaceholders($teamIds, $paramPrefix . '_team', $params) . ')';

    if ($mode === 'team') {
        return [$teamSql, $params];
    }

    $params[':' . $paramPrefix . '_unit'] = trim((string) ($user['unit'] ?? defaultCountry()));
    return ['(' . $userAlias . '.unit = :' . $paramPrefix . '_unit OR ' . $teamSql . ')', $params];
}

function proformaVisibilityClause(
    ?array $user,
    string $proformaAlias = 'p',
    string $sellerAlias = 'seller',
    ?string $clientAlias = 'c',
    string $paramPrefix = 'visible_proforma'
): array {
    $params = [];
    $mode = commercialVisibilityMode($user);
    $userId = (int) ($user['id'] ?? 0);
    $sellerIdSql = 'COALESCE(' . $proformaAlias . '.seller_id, ' . $proformaAlias . '.created_by)';

    if ($mode === 'all') {
        return ['1 = 1', []];
    }
    if ($mode === 'none' || $userId <= 0) {
        return ['1 = 0', []];
    }
    if ($mode === 'self') {
        $params[':' . $paramPrefix . '_id'] = $userId;
        return [$sellerIdSql . ' = :' . $paramPrefix . '_id', $params];
    }

    $teamIds = visibleTeamUserIds($userId);
    if ($teamIds === []) {
        return ['1 = 0', []];
    }
    $teamSql = $sellerIdSql . ' IN (' . sqlInPlaceholders($teamIds, $paramPrefix . '_team', $params) . ')';

    if ($mode === 'team') {
        return [$teamSql, $params];
    }

    $unitParts = [
        'NULLIF(' . $proformaAlias . ".signer_unit, '')",
        'NULLIF(' . $sellerAlias . ".unit, '')",
    ];
    if ($clientAlias !== null && $clientAlias !== '') {
        $unitParts[] = $clientAlias . '.pais';
    }
    $unitSql = 'COALESCE(' . implode(', ', $unitParts) . ", '')";
    $params[':' . $paramPrefix . '_unit'] = trim((string) ($user['unit'] ?? defaultCountry()));

    return ['(' . $unitSql . ' = :' . $paramPrefix . '_unit OR ' . $teamSql . ')', $params];
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        redirect('/login.php');
    }

    $user = currentUser();
    if ($user === null) {
        logout();
        redirect('/login.php');
    }

    if (!userAllowedPath($user, currentPublicPath())) {
        redirect(userHomePath($user));
    }
}

function requireAdmin(): void
{
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}
