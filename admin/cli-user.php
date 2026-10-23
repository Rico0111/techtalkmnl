<?php
/**
 * TechTalk CLI Administrator Tool (Hardened Edition)
 * Run exclusively via CLI to create or rotate an administrator account safely.
 * Usage: php admin/cli-user.php <username>
 * Passwords are read securely via hidden interactive prompt, never via CLI arguments.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI access only.\n");
}

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/audit.php';

// Reject insecure invocation if password is provided on command line
if ($argc > 2) {
    fwrite(STDERR, "Error: For security, passwords must not be passed as command-line arguments.\n");
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <username>\n");
    exit(1);
}

if ($argc < 2) {
    echo "Usage: /Applications/XAMPP/xamppfiles/bin/php " . basename(__FILE__) . " <username>\n";
    exit(1);
}

$username = trim($argv[1]);

if (strlen($username) < 3 || strlen($username) > 50) {
    fwrite(STDERR, "Error: Username must be 3-50 characters.\n");
    exit(1);
}

if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
    fwrite(STDERR, "Error: Username contains invalid characters (allowed: letters, numbers, _, -, .).\n");
    exit(1);
}

/**
 * Prompt for password with hidden terminal echo.
 * Ensures terminal echo is restored upon exit or interrupt.
 */
function prompt_hidden_password(string $prompt): string {
    $envPassword = getenv('TECHTALK_CLI_PASSWORD');
    if ($envPassword !== false && $envPassword !== '') {
        return $envPassword;
    }

    $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);

    if ($isTty && DIRECTORY_SEPARATOR === '/') {
        echo $prompt;
        // Disable terminal echo
        shell_exec('stty -echo');
        register_shutdown_function(function() {
            shell_exec('stty echo');
        });

        $input = fgets(STDIN);

        // Restore terminal echo
        shell_exec('stty echo');
        echo "\n";

        return trim($input ?? '');
    } else {
        echo $prompt;
        $input = fgets(STDIN);
        return trim($input ?? '');
    }
}

$password = prompt_hidden_password("Enter new password: ");
if (strlen($password) < 8) {
    fwrite(STDERR, "Error: Password must be at least 8 characters long.\n");
    exit(1);
}

// For interactive TTY, require confirmation if not set via environment variable
if (function_exists('posix_isatty') && posix_isatty(STDIN) && (getenv('TECHTALK_CLI_PASSWORD') === false || getenv('TECHTALK_CLI_PASSWORD') === '')) {
    $confirm = prompt_hidden_password("Confirm new password: ");
    if ($password !== $confirm) {
        fwrite(STDERR, "Error: Passwords do not match.\n");
        exit(1);
    }
}

$pdo = get_db_connection();
if (!$pdo) {
    fwrite(STDERR, "Error: Database connection failed.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare("
            UPDATE admin_users
            SET password_hash = :hash, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([':hash' => $hash, ':id' => $existing['id']]);
        log_product_audit((int)$existing['id'], null, 'ADMIN_PASSWORD_ROTATED_CLI', ['username' => $username]);
        echo "Success: Administrator password rotated for '{$username}'. Existing sessions invalidated.\n";
    } else {
        $insert = $pdo->prepare("
            INSERT INTO admin_users (username, password_hash, created_at, updated_at)
            VALUES (:username, :hash, NOW(), NOW())
        ");
        $insert->execute([':username' => $username, ':hash' => $hash]);
        $newId = (int)$pdo->lastInsertId();
        log_product_audit($newId, null, 'ADMIN_CREATED_CLI', ['username' => $username]);
        echo "Success: Administrator account '{$username}' created successfully.\n";
    }
} catch (PDOException $e) {
    error_log("CLI Admin error: " . $e->getMessage());
    fwrite(STDERR, "Error: A database error occurred during account update.\n");
    exit(1);
}
