<?php
/**
 * TechTalk Admin Dashboard Overview
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/products.php';

admin_require_auth();

require_once __DIR__ . '/ui.php';
$statsError = null;
$stats = get_product_stats($statsError);
$auditError = null;
$pdo = get_db_connection();

$recentAudits = [];
if (!$pdo) $auditError = 'Recent activity could not be loaded. Please try again.';
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT a.*, u.username
            FROM product_audit_log a
            LEFT JOIN admin_users u ON a.admin_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 8
        ");
        $recentAudits = $stmt->fetchAll();
    } catch (PDOException $e) {
        $auditError = 'Recent activity could not be loaded. Please try again.';
        error_log('Admin recent activity could not be loaded.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — TechTalk Admin</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main id="main-content" class="admin-container" tabindex="-1">
    <div class="page-header">
      <div>
        <p class="eyebrow">Workspace / Overview</p>
        <h1 class="page-title">Catalog Overview</h1>
        <p class="page-subtitle">Your inventory, publication status and latest activity in one place.</p>
      </div>
      <div class="header-actions">
        <a href="products.php" class="btn btn-secondary">Manage products</a>
        <a href="product_edit.php" class="btn btn-primary">+ Add product</a>
      </div>
    </div>
    <?php if ($statsError): ?>
      <div class="alert alert-danger" role="alert"><?= admin_h($statsError) ?> <a href="index.php">Reload dashboard</a></div>
    <?php endif; ?>
    <div class="stats-grid" aria-label="Catalog statistics">
      <?php foreach ([
        ['total','Total Products','','All catalog records'],
        ['published','Published Products','cyan','Visible on the website'],
        ['available','Available Products','green','Among published products'],
        ['reserved','Reserved Products','yellow','Among published products'],
        ['sold','Sold Products','','Among published products'],
        ['draft','Draft Products','','Private, unpublished records'],
      ] as [$key,$label,$color,$scope]): ?>
        <div class="stat-card">
          <div class="stat-card-label"><?= $label ?></div>
          <div class="stat-card-value <?= $color ?>"><?= $statsError ? '—' : number_format($stats[$key]) ?></div>
          <div class="stat-card-foot"><?= $statsError ? 'Data unavailable' : $scope ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$statsError && $stats['total'] === 0): ?>
      <section class="onboarding" aria-labelledby="onboarding-title">
        <div>
          <span class="eyebrow">Start your catalog</span>
          <h2 id="onboarding-title">Your first product starts here.</h2>
          <p>Add a product, attach its photographs, then publish when it is ready. The public website currently displays the illustrative showcase until you publish genuine inventory.</p>
        </div>
        <a href="product_edit.php" class="btn btn-primary">+ Add First Product</a>
      </section>
    <?php endif; ?>
    <section class="integration-status" aria-labelledby="integration-title">
      <div>
        <div class="status-heading">
          <h2 id="integration-title">Public website</h2>
          <?php if ($statsError): ?>
            <span class="badge badge-reserved">Status unavailable</span>
          <?php elseif ($stats['published'] > 0): ?>
            <span class="badge badge-published">Published catalog active</span>
          <?php else: ?>
            <span class="badge badge-draft">Illustrative showcase</span>
          <?php endif; ?>
        </div>
        <p><?= $statsError ? 'Database data is temporarily unavailable; catalog status cannot be confirmed.' : ($stats['published'] > 0 ? number_format($stats['published']) . ' published product(s) available on the website.' : 'No products published yet. The website uses the illustrative showcase.') ?> Carousell listings are managed separately.</p>
      </div>
      <a href="../index.php" target="_blank" rel="noopener noreferrer">View website ↗<span class="sr-only"> (opens in a new tab)</span></a>
    </section>
    <section class="table-card" aria-labelledby="activity-title">
      <div class="section-header">
        <div><h2 id="activity-title">Recent activity</h2><p>The latest eight catalog and account events.</p></div>
        <a href="audit_log.php" class="btn btn-secondary btn-sm">View audit log →</a>
      </div>
      <?php if ($auditError): ?>
        <div class="empty-state"><p role="alert"><?= admin_h($auditError) ?></p><a href="index.php" class="btn btn-secondary">Try again</a></div>
      <?php elseif (!$recentAudits): ?>
        <div class="empty-state"><h2>No activity yet</h2><p>Catalog changes and administrator account events will appear here.</p></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="responsive-table" role="table">
            <caption class="sr-only">Recent catalog and account activity. Dates are shown as recorded by the database.</caption>
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Activity</th><th role="columnheader" scope="col">Administrator</th><th role="columnheader" scope="col">Recorded at</th><th role="columnheader" scope="col">Details</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($recentAudits as $log): ?>
                <tr role="row">
                  <td role="cell"><strong><?= admin_h(admin_audit_label($log['action'])) ?></strong><?php if ($log['product_id']): ?><span class="cell-note">Product #<?= (int)$log['product_id'] ?></span><?php endif; ?></td>
                  <td role="cell" data-label="Administrator"><?= admin_h($log['username'] ?? 'System') ?></td>
                  <td role="cell" data-label="Recorded at"><?= admin_h($log['created_at']) ?></td>
                  <td role="cell" data-label="Details"><?php admin_event_details($log); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
