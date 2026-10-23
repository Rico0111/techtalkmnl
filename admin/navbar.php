<?php
/** Shared navigation: native disclosure remains usable without JavaScript. */
if (!defined('TECHTALK_INIT')) exit;
require_once __DIR__ . '/ui.php';
$currentUser = admin_get_current_user();
$currentScript = basename($_SERVER['PHP_SELF']);
$navItems = [
    ['index.php', 'Dashboard', 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z'],
    ['products.php', 'Products', 'M3 7l9-4 9 4-9 5z M3 7v10l9 4 9-4V7 M12 12v9'],
    ['audit_log.php', 'Audit Log', 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h4'],
    ['account.php', 'Account Settings', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M4 21v-2a8 8 0 0 1 16 0v2'],
];
?>
<a class="skip-link" href="#main-content">Skip to content</a>
<nav class="admin-navbar" aria-label="Administration">
  <?php admin_brand(); ?>
  <details class="nav-disclosure" open>
    <summary aria-controls="admin-navigation-panel">
      <span class="menu-context">Workspace navigation</span>
      <span class="menu-action"><span data-menu-label>Menu</span><svg class="menu-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></span>
    </summary>
    <div class="nav-menu-panel" id="admin-navigation-panel">
    <div class="nav-section-label eyebrow">Workspace</div>
    <ul class="admin-nav-links">
      <?php foreach ($navItems as [$url, $label, $path]):
        $active = $currentScript === $url || ($url === 'products.php' && $currentScript === 'product_edit.php'); ?>
        <li><a href="<?= $url ?>" <?= $active ? 'class="active" aria-current="page"' : '' ?>>
          <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $path ?>" /></svg><?= $label ?>
        </a></li>
      <?php endforeach; ?>
      <li><a href="../index.php" target="_blank" rel="noopener noreferrer">
        <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7 M21 3L10 14 M10 3H3v18h18v-7" /></svg>
        View Website<span class="sr-only"> (opens in a new tab)</span>
      </a></li>
    </ul>
    </div>
  </details>
  <?php if ($currentUser): ?>
    <div class="nav-account">
      <span class="eyebrow">Signed in</span>
      <a class="account-profile-link" href="account.php" aria-label="Open your account settings">
        <?php admin_avatar($currentUser); ?>
        <span class="account-name"><?= admin_h(($currentUser['display_name'] ?? '') ?: $currentUser['username']) ?></span>
      </a>
      <form action="logout.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>">
        <button type="submit" class="btn btn-secondary">Log Out</button>
      </form>
    </div>
  <?php endif; ?>
</nav>
