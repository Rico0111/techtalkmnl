<?php
/**
 * TechTalk Admin Audit Log Viewer
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

admin_require_auth();
require_once __DIR__ . '/ui.php';
$auditError = null;

$pdo = get_db_connection();
$logs = [];
if (!$pdo) $auditError = 'Activity could not be loaded. Please try again.';

if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT a.*, u.username, p.product_code, p.title AS product_title
            FROM product_audit_log a
            LEFT JOIN admin_users u ON a.admin_id = u.id
            LEFT JOIN products p ON a.product_id = p.id
            ORDER BY a.created_at DESC
            LIMIT 100
        ");
        $logs = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to load audit logs: " . $e->getMessage());
        $auditError = 'Activity could not be loaded. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Audit Log — TechTalk Admin</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main id="main-content" class="admin-container" tabindex="-1">
    <div class="page-header">
      <div><p class="eyebrow">Workspace / History</p><h1 class="page-title">Audit Log</h1>
      <p class="page-subtitle">A record of catalog changes and administrator account events.</p></div>
    </div>
    <section class="table-card" aria-labelledby="audit-title">
      <div class="section-header"><div><h2 id="audit-title">Activity history</h2><p>Latest 100 events · newest first · timestamps as recorded</p></div></div>
      <?php if ($auditError): ?>
        <div class="empty-state"><p role="alert"><?= admin_h($auditError) ?></p><a href="audit_log.php" class="btn btn-secondary">Try again</a></div>
      <?php elseif (!$logs): ?>
        <div class="empty-state"><h2>No activity recorded yet</h2><p>Product updates, photographs and account changes will appear here.</p><a href="products.php" class="btn btn-secondary">Go to products</a></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="responsive-table" role="table">
            <caption class="sr-only">Latest 100 audit events with expandable original codes and structured details.</caption>
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Activity</th><th role="columnheader" scope="col">Product</th><th role="columnheader" scope="col">Administrator</th><th role="columnheader" scope="col">Recorded at</th><th role="columnheader" scope="col">Details</th></tr></thead>
            <tbody role="rowgroup">
              <?php foreach ($logs as $l): ?>
                <tr role="row">
                  <td role="cell"><strong><?= admin_h(admin_audit_label($l['action'])) ?></strong></td>
                  <td role="cell" data-label="Product">
                    <?php if ($l['product_id'] && $l['product_code']): ?>
                      <a href="product_edit.php?id=<?= (int)$l['product_id'] ?>"><?= admin_h($l['product_code']) ?></a>
                    <?php elseif ($l['product_id']): ?>
                      <span class="text-muted">#<?= (int)$l['product_id'] ?> · removed</span>
                    <?php else: ?><?= str_starts_with($l['action'], 'PRODUCT_') || str_starts_with($l['action'], 'IMAGE_') ? 'Removed product · inspect details' : 'Account event' ?><?php endif; ?>
                  </td>
                  <td role="cell" data-label="Administrator"><?= admin_h($l['username'] ?? 'System') ?></td>
                  <td role="cell" data-label="Recorded at"><?= admin_h($l['created_at']) ?></td>
                  <td role="cell" data-label="Details"><?php admin_event_details($l); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="table-footer">Showing <?= number_format(count($logs)) ?> of the latest 100 events. Original event codes and details are preserved.</p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
