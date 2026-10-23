<?php
/**
 * TechTalk Admin Setup (Hardened Edition)
 * One-time administrator provisioning utility.
 * Permanently locks using a filesystem lockfile (includes/.installed) and database state.
 * Cannot be reopened remotely even if the administrator table is emptied.
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once __DIR__ . '/ui.php';

define('TECHTALK_INSTALL_LOCK', dirname(__DIR__) . '/includes/.installed');

init_admin_session();

$isInstalled = file_exists(TECHTALK_INSTALL_LOCK) || (admin_count_users() > 0);

$error = '';
$success = '';

if (!$isInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($username) < 3 || strlen($username) > 50) {
            $error = 'Username must be between 3 and 50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            $error = 'Username contains invalid characters (allowed: letters, numbers, _, -, .).';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = get_db_connection();
            if ($pdo) {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO `admin_users` (`username`, `password_hash`, `created_at`, `updated_at`)
                        VALUES (:username, :hash, NOW(), NOW())
                    ");
                    $stmt->execute([':username' => $username, ':hash' => $hash]);
                    $newAdminId = (int)$pdo->lastInsertId();

                    // Create permanent installation lockfile
                    @file_put_contents(TECHTALK_INSTALL_LOCK, date('c') . " - Installed by " . $username . "\n");
                    @chmod(TECHTALK_INSTALL_LOCK, 0640);

                    log_product_audit($newAdminId, null, 'ADMIN_SETUP_CREATED', ['username' => $username]);

                    header("Location: login.php?msg=setup_success");
                    exit;
                } catch (PDOException $e) {
                    error_log("Setup creation error: " . $e->getMessage());
                    $error = 'Failed to create administrator account. Please verify database configuration and try again.';
                }
            } else {
                $error = 'Database connection unavailable.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Setup — TechTalk</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="admin.css">
</head>
<body<?= admin_preference_attributes() ?>>
  <main class="login-wrapper" id="main-content">
    <div class="login-shell">
      <?php admin_brand('../index.php', true); ?>
    <div class="login-card">
      <div class="login-header">
        <h1>Administrator setup</h1>
        <p>One-Time Administrator Provisioning</p>
      </div>

      <?php if ($isInstalled): ?>
        <div class="alert alert-danger">
          <strong>Setup Locked:</strong> The application has already been installed and setup is permanently locked. To re-initialize setup, an authorized administrator must remove the lockfile locally via CLI (<code>rm includes/.installed</code>).
        </div>
        <div style="text-align: center; margin-top: 16px;">
          <a href="login.php" class="btn btn-primary" style="width: 100%; justify-content: center;">Proceed to Login</a>
        </div>
      <?php else: ?>
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="setup.php" style="display: flex; flex-direction: column; gap: 16px;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

          <div class="form-group">
            <label for="username">Admin Username</label>
            <input type="text" id="username" name="username" required autofocus placeholder="e.g. techtalk_admin" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="form-group">
            <label for="password">Password (min 8 characters)</label>
            <input type="password" id="password" name="password" required placeholder="••••••••••••">
          </div>

          <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••••••">
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 8px;">
            Create Administrator
          </button>
        </form>
      <?php endif; ?>
    </div>
    </div>
  </main>
</body>
</html>
