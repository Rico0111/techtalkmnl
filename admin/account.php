<?php
/** Own-account settings. Actors are always resolved from the authenticated session. */
define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once __DIR__ . '/ui.php';
admin_require_auth();
header('Cache-Control: private, no-store');
$errors = []; $notice = ''; $account = null; $activity = []; $activityError = false; $unavailable = false;
$postedAction = admin_account_text($_POST, 'action');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = admin_account_update($_POST, isset($_FILES['avatar']) && is_array($_FILES['avatar']) ? $_FILES['avatar'] : null);
    if ($result['reauthenticate']) {
        admin_logout();
        $message = $result['success'] ? ($postedAction === 'password' ? 'password_changed' : ($postedAction === 'identity' ? 'account_updated' : 'settings_saved')) : 'account_reauthenticate';
        header('Location: login.php?msg=' . $message); exit;
    }
    if ($result['success']) {
        $_SESSION['account_flash'] = 'Your ' . ['profile'=>'profile','identity'=>'account information','password'=>'password','preferences'=>'preferences'][$postedAction] . ' has been saved.';
        header('Location: account.php#' . $postedAction); exit;
    }
    $errors = $result['errors'];
}
if (isset($_SESSION['account_flash'])) { $notice = $_SESSION['account_flash']; unset($_SESSION['account_flash']); }
try {
    $pdo = get_db_connection();
    if (!$pdo || !admin_account_schema_ready($pdo)) $unavailable = true;
    else {
        $account = admin_account_get($pdo, (int)$_SESSION['admin_user_id']);
        if (!$account) $unavailable = true;
        else {
            try {
                $stmt = $pdo->prepare("SELECT action, details_json, created_at FROM product_audit_log WHERE admin_id = :id AND LEFT(action, 6) = 'ADMIN_' ORDER BY created_at DESC, id DESC LIMIT 8");
                $stmt->execute([':id' => (int)$_SESSION['admin_user_id']]); $activity = $stmt->fetchAll();
            } catch (PDOException $e) { $activityError = true; }
        }
    }
} catch (Throwable $e) { $unavailable = true; error_log('Account settings could not be loaded.'); }
if ($unavailable) http_response_code(503);
$value = function(string $action, string $key, $fallback = '') use ($postedAction) {
    return $postedAction === $action && isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $fallback;
};
$fieldError = function(string $action, string $key) use ($postedAction, $errors): string {
    return $postedAction === $action ? ($errors[$key] ?? '') : '';
};
$invalid = fn(string $action, string $key) => $fieldError($action, $key) ? ' aria-invalid="true"' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Settings — TechTalk Admin</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
  <script src="account.js" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main class="admin-container" id="main-content" tabindex="-1">
    <div class="page-header"><div><p class="eyebrow">Workspace / Your account</p><h1 class="page-title">Account Settings</h1><p class="page-subtitle">Your profile, sign-in security and workspace preferences.</p></div></div>
    <?php if ($unavailable): ?>
      <div class="alert alert-danger" role="alert">Account settings are not available yet. Ask the site administrator to finish account settings setup, or try again later.</div>
      <a href="account.php" class="btn btn-secondary">Try again</a>
    <?php else: ?>
      <?php if ($notice): ?><div class="alert alert-success" role="status"><?= admin_h($notice) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1" data-error-summary><strong>Your changes were not saved</strong><ul><?php foreach ($errors as $key => $error): ?><li><?php if ($key !== 'form'): ?><a href="#<?= admin_h($postedAction . '-' . $key) ?>"><?= admin_h($error) ?></a><?php else: ?><?= admin_h($error) ?><?php endif; ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <nav class="settings-tabs" aria-label="Account sections">
        <a href="#profile">My Profile</a><a href="#identity">Account Information</a><a href="#password">Security &amp; Password</a><a href="#preferences">Appearance</a><a href="#activity">Account Activity</a>
      </nav>
      <div class="settings-stack">
        <section class="editor-section" id="profile" aria-labelledby="profile-title">
          <h2 id="profile-title">My Profile</h2><p class="section-description">Choose how you appear in the admin workspace. Your display name is separate from your sign-in username.</p>
          <form action="account.php" method="POST" enctype="multipart/form-data" data-account-form data-saved-values="<?= admin_h(json_encode(['display_name'=>$account['display_name'],'remove_avatar'=>null])) ?>">
            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
            <div class="profile-hero"><?php admin_avatar($account, true); ?><div><h3><?= admin_h($account['display_name'] ?: $account['username']) ?></h3><p class="help-text">@<?= admin_h($account['username']) ?></p></div></div>
            <div class="form-grid">
              <div class="form-group full-width"><label for="profile-display_name">Display name</label><input id="profile-display_name" name="display_name" type="text" maxlength="100" autocomplete="name" value="<?= admin_h($value('profile', 'display_name', $account['display_name'])) ?>" aria-describedby="profile-name-help profile-display_name-error"<?= $invalid('profile','display_name') ?>><span class="help-text" id="profile-name-help">Leave blank to show your username in the sidebar.</span><span class="field-error" id="profile-display_name-error"<?= $fieldError('profile','display_name') ? '' : ' hidden' ?>><?= admin_h($fieldError('profile','display_name')) ?></span></div>
              <div class="form-group full-width"><label for="profile-avatar">Profile photograph</label><input id="profile-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar-help profile-avatar-error avatar-feedback"<?= $invalid('profile','avatar') ?>><span class="help-text" id="avatar-help">JPEG, PNG or WebP · up to 2 MB · 32–4096 pixels per side, at most 8 million pixels. Stored photographs are resized to 512 pixels and stripped of metadata.</span><span class="field-error" id="profile-avatar-error"<?= $fieldError('profile','avatar') ? '' : ' hidden' ?>><?= admin_h($fieldError('profile','avatar')) ?></span><p class="help-text" id="avatar-feedback" role="status"></p><img id="avatar-preview" class="avatar-upload-preview" alt="Selected profile photograph preview" hidden></div>
              <?php if ($account['avatar_filename']): ?><label class="checkbox-row full-width"><input type="checkbox" name="remove_avatar" value="1" <?= $value('profile','remove_avatar') === '1' ? 'checked' : '' ?>>Remove current photograph and use a default avatar</label><?php endif; ?>
            </div>
            <div class="form-footer"><p class="help-text">Photograph changes apply when you save.</p><div class="button-row"><button class="btn btn-secondary" type="reset">Cancel</button><button class="btn btn-primary" type="submit">Save profile</button></div></div>
          </form>
        </section>
        <section class="editor-section" id="identity" aria-labelledby="identity-title">
          <h2 id="identity-title">Account Information</h2><p class="section-description">Update your sign-in username or contact email. Confirm your current password to protect these details.</p>
          <form action="account.php" method="POST" data-account-form data-saved-values="<?= admin_h(json_encode(['username'=>$account['username'],'email'=>$account['email']])) ?>">
            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="identity">
            <div class="form-grid">
              <div class="form-group"><label for="identity-username">Username <span aria-hidden="true">*</span></label><input id="identity-username" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\\-]{3,50}" value="<?= admin_h($value('identity','username',$account['username'])) ?>" aria-describedby="username-help identity-username-error"<?= $invalid('identity','username') ?>><span class="help-text" id="username-help">3–50 letters, numbers, dots, hyphens or underscores.</span><span class="field-error" id="identity-username-error"<?= $fieldError('identity','username') ? '' : ' hidden' ?>><?= admin_h($fieldError('identity','username')) ?></span></div>
              <div class="form-group"><label for="identity-email">Email address</label><input id="identity-email" type="email" name="email" autocomplete="email" maxlength="254" value="<?= admin_h($value('identity','email',$account['email'])) ?>" aria-describedby="email-help identity-email-error"<?= $invalid('identity','email') ?>><span class="help-text" id="email-help">Contact information only. Email sign-in and password recovery are not enabled.</span><span class="field-error" id="identity-email-error"<?= $fieldError('identity','email') ? '' : ' hidden' ?>><?= admin_h($fieldError('identity','email')) ?></span></div>
              <div class="form-group full-width"><label for="identity-current_password">Current password <span aria-hidden="true">*</span></label><input id="identity-current_password" type="password" name="current_password" autocomplete="current-password" required aria-describedby="identity-current_password-error"<?= $invalid('identity','current_password') ?>><span class="field-error" id="identity-current_password-error"<?= $fieldError('identity','current_password') ? '' : ' hidden' ?>><?= admin_h($fieldError('identity','current_password')) ?></span></div>
            </div>
            <p class="publish-note">Changing your username or email ends all sessions, including this one. Sign in again to continue.</p>
            <div class="form-footer"><p class="help-text">* Required for this change.</p><div class="button-row"><button class="btn btn-secondary" type="reset">Cancel</button><button class="btn btn-primary" type="submit">Save account information</button></div></div>
          </form>
        </section>
        <section class="editor-section" id="password" aria-labelledby="password-title">
          <h2 id="password-title">Security &amp; Password</h2><p class="section-description">Use a unique password. All three fields are required.</p>
          <form action="account.php" method="POST" data-account-form data-saved-values="<?= admin_h(json_encode([])) ?>">
            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="password">
            <div class="form-grid">
              <div class="form-group full-width"><label for="password-current_password">Current password</label><input id="password-current_password" type="password" name="current_password" autocomplete="current-password" required aria-describedby="password-current_password-error"<?= $invalid('password','current_password') ?>><span class="field-error" id="password-current_password-error"<?= $fieldError('password','current_password') ? '' : ' hidden' ?>><?= admin_h($fieldError('password','current_password')) ?></span></div>
              <div class="form-group"><label for="password-new_password">New password</label><input id="password-new_password" type="password" name="new_password" autocomplete="new-password" minlength="12" required aria-describedby="password-help password-new_password-error"<?= $invalid('password','new_password') ?>><span class="help-text" id="password-help">At least 12 characters with uppercase, lowercase and a number. Maximum 72 bytes.</span><span class="field-error" id="password-new_password-error"<?= $fieldError('password','new_password') ? '' : ' hidden' ?>><?= admin_h($fieldError('password','new_password')) ?></span></div>
              <div class="form-group"><label for="password-confirm_password">Confirm new password</label><input id="password-confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required aria-describedby="password-confirm_password-error"<?= $invalid('password','confirm_password') ?>><span class="field-error" id="password-confirm_password-error"<?= $fieldError('password','confirm_password') ? '' : ' hidden' ?>><?= admin_h($fieldError('password','confirm_password')) ?></span></div>
            </div>
            <p class="publish-note">Saving a new password signs out every session. You will need to sign in again.</p>
            <div class="form-footer"><p class="help-text">Use a password you do not use elsewhere.</p><div class="button-row"><button class="btn btn-secondary" type="reset">Cancel</button><button class="btn btn-primary" type="submit">Update password &amp; sign out</button></div></div>
          </form>
        </section>
        <section class="editor-section" id="preferences" aria-labelledby="preferences-title">
          <h2 id="preferences-title">Appearance &amp; Preferences</h2><p class="section-description">Customize your workspace while keeping TechTalk’s navy/cyan identity. Preferences belong to your account.</p>
          <form action="account.php" method="POST" data-account-form data-saved-values="<?= admin_h(json_encode(['density'=>$account['density'],'reduce_motion'=>$account['reduce_motion'] ? '1' : null])) ?>">
            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="preferences">
            <div class="form-grid">
              <div class="form-group"><label for="preferences-density">Layout density</label><select id="preferences-density" name="density" aria-describedby="preferences-density-error"<?= $invalid('preferences','density') ?>><?php foreach (['comfortable'=>'Comfortable','compact'=>'Compact'] as $key=>$label): ?><option value="<?= $key ?>" <?= $value('preferences','density',$account['density']) === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><span class="field-error" id="preferences-density-error"<?= $fieldError('preferences','density') ? '' : ' hidden' ?>><?= admin_h($fieldError('preferences','density')) ?></span></div>
              <div class="form-group"><label class="checkbox-row" for="preferences-reduce_motion"><input id="preferences-reduce_motion" type="checkbox" name="reduce_motion" value="1" <?= ($postedAction === 'preferences' ? $value('preferences','reduce_motion') === '1' : $account['reduce_motion']) ? 'checked' : '' ?> aria-describedby="motion-help preferences-reduce_motion-error">Reduce interface animation</label><span class="help-text" id="motion-help">Your device’s reduced-motion setting is always respected.</span><span class="field-error" id="preferences-reduce_motion-error"<?= $fieldError('preferences','reduce_motion') ? '' : ' hidden' ?>><?= admin_h($fieldError('preferences','reduce_motion')) ?></span></div>
            </div>
            <div class="form-footer"><p class="help-text">Preferences remain after logout and sign-in.</p><div class="button-row"><button class="btn btn-secondary" type="reset">Cancel</button><button class="btn btn-primary" type="submit">Save preferences</button></div></div>
          </form>
        </section>
        <section class="editor-section" id="activity" aria-labelledby="activity-title">
          <h2 id="activity-title">Account Activity</h2><p class="section-description">Your account history and the latest eight recorded account events.</p>
          <dl class="account-facts"><div><dt>Account created</dt><dd><?= admin_h($account['created_at'] ?: 'Not recorded') ?><span class="cell-note">As recorded by the database</span></dd></div><div><dt>Last recorded sign-in</dt><dd><?= admin_h($account['last_login_at'] ? $account['last_login_at'] . ' UTC' : 'Not recorded yet') ?></dd></div><div><dt>Current session began</dt><dd><?= isset($_SESSION['login_time']) ? admin_h(gmdate('Y-m-d H:i:s', $_SESSION['login_time']) . ' UTC') : 'Not recorded' ?></dd></div></dl>
          <?php if ($activityError): ?><p class="help-text" role="status">Account activity is temporarily unavailable.</p><?php elseif (!$activity): ?><p class="help-text">No account events recorded yet.</p><?php else: ?>
            <ul class="account-activity"><?php foreach ($activity as $event): ?><li><div><strong><?= admin_h(admin_audit_label($event['action'])) ?></strong><span class="cell-note"><?= admin_h($event['created_at']) ?> · as recorded</span></div><?php admin_event_details($event); ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
        </section>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
