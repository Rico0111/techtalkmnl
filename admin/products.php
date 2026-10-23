<?php
/**
 * TechTalk Admin Product Management Listing
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/products.php';
require_once dirname(__DIR__) . '/includes/audit.php';

admin_require_auth();
require_once __DIR__ . '/ui.php';

$currentUser = admin_get_current_user();
$categories = get_categories();

$notice = '';
$error = '';

// POST-redirect-GET flash for quick actions (avoids duplicate submissions on refresh).
if (isset($_SESSION['products_flash']) && is_array($_SESSION['products_flash'])) {
    $flash = $_SESSION['products_flash'];
    unset($_SESSION['products_flash']);
    if (isset($flash['notice']) && is_string($flash['notice'])) $notice = $flash['notice'];
    if (isset($flash['error']) && is_string($flash['error'])) $error = $flash['error'];
}

// Handle quick actions: Archive, Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flashNotice = '';
    $flashError = '';
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $flashError = 'Invalid security token. Action rejected.';
    } else {
        $action = $_POST['action'] ?? '';
        $productId = (int)($_POST['product_id'] ?? 0);

        if ($productId <= 0) {
            $flashError = 'No product was selected.';
        } elseif ($action === 'archive') {
            $existing = get_product_by_id($productId);
            if (!$existing) {
                $flashError = "Failed to archive product #{$productId}.";
            } elseif ($existing['publication_status'] === 'ARCHIVED') {
                $flashNotice = "Product #{$productId} is already archived.";
            } elseif (delete_product($productId, false)) {
                log_product_audit($currentUser['id'], $productId, 'PRODUCT_ARCHIVED', ['id' => $productId]);
                $flashNotice = "Product #{$productId} has been archived.";
            } else {
                $flashError = "Failed to archive product #{$productId}.";
            }
        } elseif ($action === 'delete') {
            if (delete_product($productId, true)) {
                log_product_audit($currentUser['id'], null, 'PRODUCT_DELETED', ['id' => $productId]);
                $flashNotice = "Product #{$productId} and all associated photos permanently removed.";
            } else {
                $flashError = "Failed to delete product #{$productId}.";
            }
        } else {
            $flashError = 'Unknown action.';
        }
    }
    $_SESSION['products_flash'] = ['notice' => $flashNotice, 'error' => $flashError];
    header('Location: products.php');
    exit;
}

if (isset($_GET['msg']) && $_GET['msg'] === 'saved') {
    $notice = 'Product saved successfully.';
}

// Gather filters
$filters = [
    'search'              => trim($_GET['search'] ?? ''),
    'category_id'         => trim($_GET['category_id'] ?? ''),
    'availability_status' => trim($_GET['availability_status'] ?? ''),
    'publication_status'  => trim($_GET['publication_status'] ?? '')
];

$productsError = null;
$products = get_admin_products($filters, $productsError);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Products — TechTalk Admin</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="<?= admin_h(admin_asset_url('admin.css')) ?>">
  <script src="<?= admin_h(admin_asset_url('admin.js')) ?>" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main id="main-content" class="admin-container" tabindex="-1">
    <div class="page-header">
      <div><p class="eyebrow">Workspace / Inventory</p><h1 class="page-title">Products</h1>
        <p class="page-subtitle">Manage stock availability and website publication independently.</p>
      </div>
      <div class="header-actions"><a href="product_edit.php" class="btn btn-primary">+ Add product</a></div>
    </div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= admin_h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= admin_h($error) ?></div><?php endif; ?>
    <section class="form-card filter-bar" aria-label="Filter products">
      <form method="GET" action="products.php" class="filters">
        <div class="form-group">
          <label for="search">Search products</label>
          <input type="search" id="search" name="search" placeholder="Title, reference or description" value="<?= admin_h($filters['search']) ?>">
        </div>
        <div class="form-group">
          <label for="category_id">Category</label>
          <select id="category_id" name="category_id"><option value="">All categories</option>
            <?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= admin_h($cat['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="availability_status">Availability</label>
          <select id="availability_status" name="availability_status"><option value="">All availability</option>
            <?php foreach (['AVAILABLE'=>'Available','RESERVED'=>'Reserved','SOLD'=>'Sold','WITHDRAWN'=>'Withdrawn','UNKNOWN'=>'Unknown'] as $value=>$label): ?>
              <option value="<?= $value ?>" <?= $filters['availability_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="publication_status">Publication</label>
          <select id="publication_status" name="publication_status"><option value="">All publication</option>
            <?php foreach (['DRAFT'=>'Draft','PUBLISHED'=>'Published','ARCHIVED'=>'Archived'] as $value=>$label): ?>
              <option value="<?= $value ?>" <?= $filters['publication_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="button-row">
          <?php if (array_filter($filters)): ?><a href="products.php" class="btn btn-secondary">Clear filters</a><?php endif; ?>
          <button type="submit" class="btn btn-secondary">Apply filters</button>
        </div>
      </form>
    </section>
    <section class="table-card" aria-labelledby="inventory-title">
      <div class="section-header"><div><h2 id="inventory-title">Inventory<?= !$productsError ? ' · ' . number_format(count($products)) : '' ?></h2><p>Most recently updated first<?= array_filter($filters) ? ' · filters applied' : '' ?></p></div></div>
      <?php if ($productsError): ?>
        <div class="empty-state"><p role="alert"><?= admin_h($productsError) ?></p><a href="products.php?<?= admin_h(http_build_query($filters)) ?>" class="btn btn-secondary">Try again</a></div>
      <?php elseif (!$products): ?>
        <div class="empty-state">
          <h2><?= array_filter($filters) ? 'No matching products' : 'Make room for your first product.' ?></h2>
          <p><?= array_filter($filters) ? 'Try a different search or clear your filters to see the full inventory.' : 'Create a draft, add the details and publish when you are ready. The public website uses the illustrative showcase until a product is published.' ?></p>
          <a href="<?= array_filter($filters) ? 'products.php' : 'product_edit.php' ?>" class="btn <?= array_filter($filters) ? 'btn-secondary' : 'btn-primary' ?>"><?= array_filter($filters) ? 'Clear filters' : '+ Add First Product' ?></a>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="responsive-table" role="table">
            <caption class="sr-only">Filtered product inventory. Availability is stock status; publication determines website visibility.</caption>
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Product</th><th role="columnheader" scope="col">Price</th><th role="columnheader" scope="col">Availability</th><th role="columnheader" scope="col">Publication</th><th role="columnheader" scope="col">Actions</th></tr></thead>
            <tbody role="rowgroup">
            <?php foreach ($products as $p): ?>
              <tr role="row">
                <td role="cell">
                  <div class="product-identity">
                    <?php if (!empty($p['primary_image'])): ?>
                      <img class="product-thumb" src="<?= admin_h(product_image_url($p['primary_image'], true, '../')) ?>" alt="" loading="lazy" width="52" height="52">
                    <?php else: ?><span class="no-photo" aria-label="No product photograph">No photo</span><?php endif; ?>
                    <div><a class="product-name" href="product_edit.php?id=<?= (int)$p['id'] ?>"><?= admin_h($p['title']) ?></a>
                      <span class="code-pill"><?= admin_h($p['product_code']) ?></span>
                      <span class="cell-note"><?= admin_h($p['category_name']) ?> · <?= admin_h($p['condition_label']) ?></span>
                      <span class="cell-note">Updated: <?= admin_h($p['updated_at'] ?? 'Not recorded') ?></span>
                    </div>
                  </div>
                </td>
                <td role="cell" data-label="Price"><div><span class="price">₱<?= number_format((float)$p['price'], 2, '.', ',') ?></span>
                  <span class="cell-note">Verified: <?= admin_h($p['price_verified_at'] ?: 'Not recorded') ?></span></div></td>
                <td role="cell" data-label="Availability"><div><span class="badge <?= admin_status_class($p['availability_status']) ?>"><?= admin_h($p['availability_status']) ?></span>
                  <span class="cell-note">Verified: <?= admin_h($p['availability_verified_at'] ?: 'Not recorded') ?></span></div></td>
                <td role="cell" data-label="Publication"><span class="badge <?= admin_status_class($p['publication_status']) ?>"><?= admin_h($p['publication_status']) ?></span></td>
                <td role="cell" data-label="Actions">
                  <div class="row-actions">
                    <a href="product_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">Edit<span class="sr-only"> <?= admin_h($p['product_code']) ?></span></a>
                    <details><summary>More<span class="sr-only"> actions for <?= admin_h($p['product_code']) ?></span></summary>
                      <div class="more-actions">
                        <?php if ($listingUrl = admin_listing_url($p['carousell_listing_url'])): ?>
                          <a href="<?= admin_h($listingUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">View listing ↗<span class="sr-only"> (opens in a new tab)</span></a>
                        <?php endif; ?>
                        <?php if ($p['publication_status'] !== 'ARCHIVED'): ?>
                          <form method="POST" action="products.php" onsubmit="return confirm('Archive this product? It will be removed from the public website and remain available for editing.');">
                            <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Archive</button>
                          </form>
                        <?php endif; ?>
                        <form method="POST" action="products.php" onsubmit="return confirm('Permanently delete this product and all its photographs? This cannot be undone. Choose Cancel to keep it.');">
                          <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Delete permanently</button>
                        </form>
                      </div>
                    </details>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="table-footer">Showing <?= number_format(count($products)) ?> product(s). Draft and archived products are private.</p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
