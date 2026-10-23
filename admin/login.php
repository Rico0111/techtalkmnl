<?php
/**
 * TechTalk Admin Login
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';

require_once __DIR__ . '/ui.php';
init_admin_session();

if (admin_is_authenticated()) {
    header("Location: index.php");
    exit;
}

$error = '';
$notice = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'setup_success') {
    $notice = 'Administrator created successfully. You may now log in.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $notice = 'You have been securely logged out.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'password_changed') {
    $notice = 'Password updated. All sessions have ended. Sign in with your new password.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'account_updated') {
    $notice = 'Account details updated. All sessions have ended. Sign in with your updated username and password.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'settings_saved') {
    $notice = 'Your account settings were saved. Please sign in again to continue.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'account_reauthenticate') {
    $notice = 'Please sign in again to continue.';
}

$userCount = admin_count_users();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Security check failed (invalid session token). Please refresh and try again.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $result = admin_attempt_login($username, $password);
        if ($result['success']) {
            header("Location: index.php");
            exit;
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — TechTalk</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <main class="login-wrapper" id="main-content">
    <div class="login-shell">
      <?php admin_brand('../index.php', true); ?>
      <div class="login-card">
        <div class="login-header"><p class="eyebrow">Administrator access</p><h1>Welcome back.</h1><p>Sign in to manage your products and catalog.</p></div>
        <?php if ($userCount === 0): ?>
          <?php if (file_exists(dirname(__DIR__) . '/includes/.installed')): ?>
            <div class="alert alert-danger" role="alert">Sign-in is temporarily unavailable. Please contact the site administrator or try again later.</div>
            <a href="login.php" class="btn btn-secondary">Try again</a>
          <?php else: ?>
            <div class="alert alert-danger" role="alert">No administrator account is configured. Contact the site owner to provision access.</div>
            <?php if (is_file(__DIR__ . '/setup.php')): ?><a href="setup.php" class="btn btn-primary">Initial Setup</a><?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($notice): ?><div class="alert alert-success" role="status"><?= admin_h($notice) ?></div><?php endif; ?>
          <?php if ($error): ?><div class="alert alert-danger" id="login-error" role="alert" tabindex="-1" data-error-summary><?= admin_h($error) ?></div><?php endif; ?>
          <form method="POST" action="login.php" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>">
            <div class="form-group">
              <label for="username">Username</label>
              <input type="text" id="username" name="username" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Your admin username" <?= $error ? 'aria-describedby="login-error"' : '' ?> value="<?= admin_h($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password" <?= $error ? 'aria-describedby="login-error"' : '' ?>>
            </div>
            <button type="submit" class="btn btn-primary">Log In →</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="login-footer"><a href="../index.php">← Back to TechTalk</a><p>Catalog administration / Authorized access only</p></div>
    </div>
  </main>
</body>
</html>
