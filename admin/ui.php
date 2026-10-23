<?php
/** Presentation helpers only; authentication and storage remain in includes/. */
if (!defined('TECHTALK_INIT')) {
    http_response_code(403);
    exit;
}

function admin_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_preference_attributes(): string {
    $profile = $_SESSION['admin_profile'] ?? [];
    return ' data-density="' . (($profile['density'] ?? '') === 'compact' ? 'compact' : 'comfortable')
        . '" data-reduced-motion="' . (!empty($profile['reduce_motion']) ? 'true' : 'false') . '"';
}

function admin_avatar(array $account, bool $large = false): void {
    $name = ($account['display_name'] ?? '') ?: ($account['username'] ?? 'Administrator');
    $filename = $account['avatar_filename'] ?? '';
    ?>
    <span class="account-avatar<?= $large ? ' account-avatar-large' : '' ?>">
      <?php if (preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $filename)): ?>
        <img src="avatar.php?v=<?= admin_h($filename) ?>" alt="Profile photograph" width="<?= $large ? 88 : 40 ?>" height="<?= $large ? 88 : 40 ?>">
      <?php else: ?><span aria-label="Default avatar"><?= admin_h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?>
    </span>
    <?php
}

function admin_brand(string $href = 'index.php', bool $login = false): void {
    ?>
    <div class="admin-brand<?= $login ? ' login-brand' : '' ?>">
      <a href="<?= admin_h($href) ?>" class="admin-brand-logo" aria-label="<?= $login ? 'Back to TechTalk website' : 'TechTalk dashboard' ?>">
        <img src="../assets/techtalk-logo-opt.png" srcset="../assets/techtalk-logo-opt.png 512w, ../assets/techtalk-logo.png 2048w" sizes="(max-width: 540px) 228px, 256px" alt="TechTalk — Buy Sell Trade" width="512" height="341" decoding="async">
      </a>
      <span class="admin-brand-badge">SIGNAL / CATALOG EDITION</span>
    </div>
    <?php
}

function admin_audit_label(string $action): string {
    $labels = [
        'ADMIN_PASSWORD_ROTATED_CLI' => 'Administrator password updated',
        'ADMIN_CREATED_CLI' => 'Administrator account created',
        'ADMIN_PROFILE_UPDATED' => 'Administrator profile updated',
        'ADMIN_IDENTITY_UPDATED' => 'Administrator login details updated',
        'ADMIN_PASSWORD_CHANGED' => 'Administrator password changed',
        'ADMIN_PREFERENCES_UPDATED' => 'Administrator preferences updated',
        'ADMIN_REAUTH_FAILED' => 'Account change verification failed',
        'ADMIN_SETUP_CREATED' => 'Administrator account configured',
        'PRODUCT_CREATED' => 'Product added', 'PRODUCT_UPDATED' => 'Product updated',
        'PRODUCT_ARCHIVED' => 'Product archived', 'PRODUCT_DELETED' => 'Product permanently deleted',
        'IMAGE_UPLOADED' => 'Product photograph uploaded', 'IMAGE_DELETED' => 'Product photograph removed',
        'IMAGE_SET_PRIMARY' => 'Primary photograph changed',
    ];
    return $labels[$action] ?? ucfirst(strtolower(str_replace('_', ' ', $action)));
}

function admin_status_class(string $status): string {
    return [
        'AVAILABLE' => 'badge-available', 'RESERVED' => 'badge-reserved', 'SOLD' => 'badge-sold',
        'WITHDRAWN' => 'badge-archived', 'UNKNOWN' => 'badge-draft',
        'DRAFT' => 'badge-draft', 'PUBLISHED' => 'badge-published', 'ARCHIVED' => 'badge-archived',
    ][$status] ?? 'badge-draft';
}

function admin_listing_url(?string $url): string {
    require_once dirname(__DIR__).'/includes/products.php';
    return product_listing_url($url);
}

function admin_event_details(array $event): void {
    $raw = (string)($event['details_json'] ?? '');
    $decoded = json_decode($raw, true);
    ?>
    <details class="event-details">
      <summary>Inspect event<span class="sr-only">: <?= admin_h(admin_audit_label($event['action'])) ?></span></summary>
      <div class="event-content">
        <p class="help-text">Original event code</p><code><?= admin_h($event['action']) ?></code>
        <?php if (is_array($decoded) && $decoded): ?>
          <dl class="event-fields">
            <?php foreach ($decoded as $key => $value): ?>
              <div><dt><?= admin_h(ucfirst(str_replace('_', ' ', (string)$key))) ?></dt>
              <dd><?= admin_h(is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></dd></div>
            <?php endforeach; ?>
          </dl>
        <?php else: ?><p class="help-text">No structured details.</p><?php endif; ?>
        <details><summary>Original JSON</summary><pre><?= admin_h($raw ?: '{}') ?></pre></details>
      </div>
    </details>
    <?php
}
