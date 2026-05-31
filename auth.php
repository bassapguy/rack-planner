<?php

function toolboxAppConfig(): array
{
    $config = rackPlannerConfig();
    return $config['app'] ?? [];
}

function toolboxAppName(): string
{
    return (string)(toolboxAppConfig()['name'] ?? 'Toolbox');
}

function toolboxBasePath(): string
{
    $configured = trim((string)(toolboxAppConfig()['base_path'] ?? ''));
    if ($configured === '') {
        return '';
    }

    return '/' . trim($configured, '/');
}

function toolboxUrl(string $path = ''): string
{
    $basePath = toolboxBasePath();
    $cleanPath = ltrim($path, '/');

    if ($cleanPath === '') {
        return $basePath !== '' ? $basePath . '/' : '/';
    }

    return ($basePath !== '' ? $basePath : '') . '/' . $cleanPath;
}

function toolboxStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $sessionName = (string)(toolboxAppConfig()['session_name'] ?? 'toolbox_session');
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => toolboxBasePath() !== '' ? toolboxBasePath() . '/' : '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function toolboxFlash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function toolboxConsumeFlash(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($flash) ? $flash : null;
}

function toolboxCsrfToken(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function toolboxVerifyCsrfToken(?string $token): bool
{
    $current = $_SESSION['_csrf_token'] ?? '';
    return is_string($token) && is_string($current) && $current !== '' && hash_equals($current, $token);
}

function toolboxSanitizeRedirect(?string $redirect, string $fallback = 'index.php'): string
{
    $value = trim((string)$redirect);
    if ($value === '' || strpos($value, '..') !== false || preg_match('#^(?:https?:)?//#i', $value)) {
        return $fallback;
    }

    return ltrim($value, '/');
}

function toolboxCurrentRelativeRequest(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $basePath = toolboxBasePath();

    if ($basePath !== '' && strpos($uri, $basePath) === 0) {
        $uri = substr($uri, strlen($basePath));
    }

    $uri = ltrim($uri, '/');
    return $uri !== '' ? $uri : 'index.php';
}

function toolboxRedirect(string $path): void
{
    header('Location: ' . toolboxUrl($path));
    exit;
}

function toolboxUserDisplayName(array $user): string
{
    $name = trim((string)($user['full_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return (string)($user['email'] ?? 'User');
}

function toolboxUserRoleLabel(?string $role): string
{
    $map = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
    ];

    return $map[$role ?? ''] ?? 'User';
}

function toolboxHasAnyUsers(PDO $pdo): bool
{
    static $hasUsers = null;
    if ($hasUsers !== null) {
        return $hasUsers;
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM toolbox_users')->fetchColumn();
    $hasUsers = $count > 0;
    return $hasUsers;
}

function toolboxLoadUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM toolbox_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function toolboxLoadUserByEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM toolbox_users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => mb_strtolower(trim($email))]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function toolboxCurrentUser(?PDO $pdo = null): ?array
{
    static $cachedUser = false;

    if (is_array($cachedUser)) {
        return $cachedUser;
    }

    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    if ($userId <= 0) {
        return null;
    }

    $pdo = $pdo ?: db();
    $user = toolboxLoadUserById($pdo, $userId);
    if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
        unset($_SESSION['auth_user_id']);
        return null;
    }

    $cachedUser = $user;
    return $cachedUser;
}

function toolboxRequireLogin(): array
{
    $pdo = db();
    if (!toolboxHasAnyUsers($pdo)) {
        toolboxRedirect('setup_admin.php');
    }

    $user = toolboxCurrentUser($pdo);
    if (!$user) {
        $redirect = toolboxCurrentRelativeRequest();
        toolboxRedirect('login.php?redirect=' . rawurlencode($redirect));
    }

    return $user;
}

function toolboxRequireRole(array $roles): array
{
    $user = toolboxRequireLogin();
    if (!in_array((string)($user['role'] ?? ''), $roles, true)) {
        toolboxFlash('error', 'You do not have access to that page.');
        toolboxRedirect('index.php');
    }

    return $user;
}

function toolboxPasswordHash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function toolboxPasswordVerify(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function toolboxAudit(PDO $pdo, ?int $userId, string $action, array $context = []): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO toolbox_audit_log (user_id, action, context_json, ip_address, user_agent)
             VALUES (:user_id, :action, :context_json, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Keep auth flow usable even if logging is not available yet.
    }
}

function toolboxRecordLoginAttempt(PDO $pdo, string $email, bool $wasSuccessful, string $reason = ''): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO toolbox_login_attempts (email, was_successful, reason, ip_address)
             VALUES (:email, :was_successful, :reason, :ip_address)'
        );
        $stmt->execute([
            ':email' => mb_strtolower(trim($email)),
            ':was_successful' => $wasSuccessful ? 1 : 0,
            ':reason' => $reason !== '' ? $reason : null,
            ':ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $e) {
        // Ignore logging errors.
    }
}

function toolboxIsLoginBlocked(PDO $pdo, string $email): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM toolbox_login_attempts
             WHERE email = :email
               AND was_successful = 0
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Throwable $e) {
        return false;
    }
}

function toolboxSetPendingAuth(array $user, string $redirect = 'index.php'): void
{
    $_SESSION['pending_auth'] = [
        'user_id' => (int)$user['id'],
        'redirect' => toolboxSanitizeRedirect($redirect, 'index.php'),
        'created_at' => time(),
    ];
}

function toolboxPendingAuth(): ?array
{
    $pending = $_SESSION['pending_auth'] ?? null;
    if (!is_array($pending)) {
        return null;
    }

    $createdAt = (int)($pending['created_at'] ?? 0);
    if ($createdAt > 0 && (time() - $createdAt) > 900) {
        unset($_SESSION['pending_auth']);
        return null;
    }

    return $pending;
}

function toolboxRequirePendingAuth(): array
{
    $pending = toolboxPendingAuth();
    if (!$pending) {
        toolboxRedirect('login.php');
    }

    return $pending;
}

function toolboxSetPendingMfaSecret(string $secret): void
{
    if (!isset($_SESSION['pending_auth']) || !is_array($_SESSION['pending_auth'])) {
        $_SESSION['pending_auth'] = [];
    }
    $_SESSION['pending_auth']['mfa_secret'] = $secret;
}

function toolboxPendingMfaSecret(): ?string
{
    $pending = toolboxPendingAuth();
    $secret = is_array($pending) ? ($pending['mfa_secret'] ?? null) : null;
    return is_string($secret) && $secret !== '' ? $secret : null;
}

function toolboxClearPendingAuth(): void
{
    unset($_SESSION['pending_auth']);
}

function toolboxCompleteLogin(PDO $pdo, array $user): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = (int)$user['id'];
    unset($_SESSION['pending_auth']);

    $stmt = $pdo->prepare(
        'UPDATE toolbox_users
         SET last_login_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([':id' => (int)$user['id']]);
}

function toolboxLogout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
}

function toolboxGenerateBase32Secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $secret;
}

function toolboxBase32Decode(string $secret): string
{
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($secret) as $char) {
        $position = strpos($alphabet, $char);
        if ($position === false) {
            continue;
        }
        $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    $output = '';
    foreach (str_split($binary, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $output .= chr(bindec($chunk));
        }
    }

    return $output;
}

function toolboxTotpCode(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp = $timestamp ?? time();
    $counter = (int)floor($timestamp / $period);
    $counterBinary = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $counterBinary, toolboxBase32Decode($secret), true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $segment = substr($hash, $offset, 4);
    $value = unpack('N', $segment)[1] & 0x7FFFFFFF;
    $modulo = 10 ** $digits;
    return str_pad((string)($value % $modulo), $digits, '0', STR_PAD_LEFT);
}

function toolboxVerifyTotpCode(string $secret, string $code, int $window = 1): bool
{
    $normalized = preg_replace('/\D+/', '', $code);
    if ($normalized === '' || strlen($normalized) !== 6) {
        return false;
    }

    $now = time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        if (hash_equals(toolboxTotpCode($secret, $now + ($offset * 30)), $normalized)) {
            return true;
        }
    }

    return false;
}

function toolboxBuildOtpAuthUri(string $email, string $secret): string
{
    $issuer = (string)(toolboxAppConfig()['mfa_issuer'] ?? toolboxAppName());
    $label = rawurlencode($issuer . ':' . $email);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

function toolboxEnableMfa(PDO $pdo, int $userId, string $secret): void
{
    $stmt = $pdo->prepare(
        'UPDATE toolbox_users
         SET mfa_secret = :mfa_secret,
             mfa_enabled = 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':mfa_secret' => $secret,
        ':id' => $userId,
    ]);
}

function toolboxCreateUser(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO toolbox_users (email, full_name, password_hash, role, is_active, mfa_enabled)
         VALUES (:email, :full_name, :password_hash, :role, 1, 0)'
    );
    $stmt->execute([
        ':email' => mb_strtolower(trim((string)$payload['email'])),
        ':full_name' => trim((string)($payload['full_name'] ?? '')),
        ':password_hash' => (string)$payload['password_hash'],
        ':role' => (string)($payload['role'] ?? 'viewer'),
    ]);

    return (int)$pdo->lastInsertId();
}
