<?php
/**
 * TechTalk Admin Authentication & Session Management
 * Includes brute force protection, session regeneration, and CSRF defense.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/security.php';
send_security_headers();

/**
 * Safely compute session cookie path without relying on untrusted client HTTP headers.
 * Supports:
 * - Localhost subfolder: /techtok/
 * - Root-domain production: /
 * - Custom explicit environment override: TECHTALK_COOKIE_PATH
 */
function get_admin_cookie_path(): string {
    $envPath = getenv('TECHTALK_COOKIE_PATH');
    if (($envPath === false || $envPath === '') && defined('TECHTALK_COOKIE_PATH')) {
        $envPath = (string)constant('TECHTALK_COOKIE_PATH');
    }
    if ($envPath !== false && $envPath !== '') {
        $trimmed = '/' . trim($envPath, '/');
        return $trimmed === '/' ? '/' : $trimmed . '/';
    }

    // Auto-detect based on web server filesystem mapping (SCRIPT_NAME, NOT client HTTP Host headers)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(/[^/]+)/admin/#', $scriptName, $matches)) {
        return $matches[1] . '/';
    } elseif (preg_match('#^(/[^/]+)/#', $scriptName, $matches) && $matches[1] !== '/admin') {
        return $matches[1] . '/';
    }

    return '/';
}

function init_admin_session(): void {
    // Current admin forms have scalar GET/POST controls; nested file lists live in $_FILES.
    if (PHP_SAPI !== 'cli') {
        foreach ([$_GET, $_POST] as $request) foreach ($request as $value) {
            if (!is_string($value)) { http_response_code(400); exit('Invalid request. Reload the page and use the form controls.'); }
        }
    }
    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = [
            'lifetime' => 0,
            'path'     => get_admin_cookie_path(),
            'domain'   => '',
            'secure'   => techtalk_request_is_https(),
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        session_set_cookie_params($cookieParams);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

function csrf_token(): string {
    init_admin_session();
    if (empty($_SESSION['techtalk_csrf_token'])) {
        $_SESSION['techtalk_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['techtalk_csrf_token'];
}

function csrf_validate($token): bool {
    init_admin_session();
    if (!is_string($token) || $token === '' || !isset($_SESSION['techtalk_csrf_token']) || !is_string($_SESSION['techtalk_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['techtalk_csrf_token'], $token);
}

function admin_is_authenticated(): bool {
    init_admin_session();
    if (empty($_SESSION['admin_user_id'])) {
        return false;
    }

    // 30 minute session idle timeout
    $maxIdleSeconds = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleSeconds)) {
        admin_logout();
        return false;
    }

    // Verify user still exists and password was not rotated after this session was issued
    $pdo = get_db_connection();
    if (!$pdo) {
        admin_logout();
        return false;
    }
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, password_hash, UNIX_TIMESTAMP(updated_at) AS updated_ts
                FROM `admin_users`
                WHERE `id` = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => (int)$_SESSION['admin_user_id']]);
            $userMeta = $stmt->fetch();
            if (!$userMeta) {
                admin_logout();
                return false;
            }

            // 1. Monotonic password hash signature validation (immune to server timezone drift)
            if (isset($_SESSION['admin_password_hash_sig'])) {
                if (!hash_equals($_SESSION['admin_password_hash_sig'], hash('sha256', $userMeta['password_hash']))) {
                    admin_logout();
                    return false;
                }
            }

            // 2. Monotonic integer timestamp comparison directly from MySQL UNIX_TIMESTAMP()
            if (isset($_SESSION['admin_auth_timestamp']) && isset($userMeta['updated_ts'])) {
                $userUpdatedTs = (int)$userMeta['updated_ts'];
                $sessionAuthTs = (int)$_SESSION['admin_auth_timestamp'];
                if ($userUpdatedTs > $sessionAuthTs) {
                    admin_logout();
                    return false;
                }
            }
            // UI-3: revoke same-second identity/email changes and refresh only public preferences.
            if (!admin_account_validate_session($pdo, (int)$_SESSION['admin_user_id'])) {
                admin_logout();
                return false;
            }
        } catch (PDOException $e) {
            error_log("Session validation check failed: " . $e->getMessage());
            admin_logout();
            return false;
        }
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function admin_require_auth(): void {
    if (!admin_is_authenticated()) {
        header("Location: login.php");
        exit;
    }
}

function admin_get_current_user(): ?array {
    if (!admin_is_authenticated()) {
        return null;
    }
    return array_merge([
        'id'       => (int)$_SESSION['admin_user_id'],
        'username' => $_SESSION['admin_username'] ?? 'admin'
    ], admin_account_public($_SESSION['admin_profile'] ?? []));
}

function admin_attempt_login(string $username, string $password): array {
    init_admin_session();
    $pdo = get_db_connection();
    if (!$pdo) {
        return ['success' => false, 'error' => 'Database connection failed. Please contact technical support.'];
    }

    $invalidLogin = ['success'=>false,'error'=>'Invalid username or password. If sign-in is temporarily locked, try again in 15 minutes.'];
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'error' => 'Username and password are required.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT *, UNIX_TIMESTAMP(updated_at) AS updated_ts FROM `admin_users` WHERE `username` = :username LIMIT 1 FOR UPDATE");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->commit();
            // Constant-time dummy verify to prevent user enumeration timing attacks
            password_verify($password, '$2y$10$me/MSKH3uH7puDQCRxeUyui8Ukb30JOingJdLtH58I0x7EG68tKAK');
            return $invalidLogin;
        }

        // Evaluate a password hash even for a locked account; responses do not reveal account state.
        $passwordAccepted = password_verify($password, $user['password_hash']);
        // Check account lock
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $pdo->commit();
            return $invalidLogin;
        }

        if (!$passwordAccepted) {
            $failed = (int)$user['failed_attempts'] + 1;
            $lockedUntil = null;
            if ($failed >= 5) {
                // Lock for 15 minutes
                $lockedUntil = date('Y-m-d H:i:s', time() + 900);
            }

            $update = $pdo->prepare("
                UPDATE `admin_users`
                SET `failed_attempts` = :failed, `locked_until` = :locked, updated_at = updated_at
                WHERE `id` = :id
            ");
            $update->execute([
                ':failed' => $failed,
                ':locked' => $lockedUntil,
                ':id'     => $user['id']
            ]);
            $pdo->commit();

            return $invalidLogin;
        }

        // Login success: reset lockout and failures without modifying account update timestamp
        $reset = $pdo->prepare("
            UPDATE `admin_users`
            SET `failed_attempts` = 0, `locked_until` = NULL, updated_at = updated_at
            WHERE `id` = :id
        ");
        $reset->execute([':id' => $user['id']]);
        $pdo->commit();

        // Regenerate session to prevent fixation
        if (!session_regenerate_id(true)) throw new RuntimeException('Session could not be renewed.');
        if (!admin_account_record_login($pdo, $user)) {
            admin_logout();
            return ['success' => false, 'error' => 'Account credentials changed. Please sign in again.'];
        }
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_password_hash_sig'] = hash('sha256', $user['password_hash']);
        $_SESSION['admin_auth_timestamp'] = max(time(), (int)($user['updated_ts'] ?? time()));
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['techtalk_csrf_token'] = bin2hex(random_bytes(32));

        return ['success' => true, 'user' => $user];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Login error: " . $e->getMessage());
        admin_logout();
        return ['success' => false, 'error' => 'An authentication error occurred. Please try again.'];
    }
}

function admin_logout(): void {
    init_admin_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}

function admin_count_users(): int {
    $pdo = get_db_connection();
    if (!$pdo) {
        return 0;
    }
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM `admin_users`");
        $row = $stmt->fetch();
        return (int)($row['total'] ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}
