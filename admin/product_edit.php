<?php
/**
 * TechTalk Admin Product Add / Edit Form
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/products.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/product_uploads.php';

admin_require_auth();
require_once __DIR__ . '/ui.php';

$currentUser = admin_get_current_user();
$categories = get_categories();

$productId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$product = $productId ? get_product_by_id($productId) : null;

$productMissing = $productId !== null && !$product;
if ($productMissing) http_response_code(get_db_connection() ? 404 : 503);
$productReadOnly = $product && !in_array($product['availability_status'], ['AVAILABLE', 'RESERVED', 'SOLD'], true);

$errors = [];
$notice = '';
$photoUploadProblems = false;
$uploadLimits = product_upload_limits();
if (isset($_SESSION['product_photo_flash'][(int)$productId])) {
    $flash = $_SESSION['product_photo_flash'][(int)$productId];
    unset($_SESSION['product_photo_flash'][(int)$productId]);
    $notice = $flash['notice']; $errors = $flash['errors'];
    $photoUploadProblems = !empty($errors);
}
$postTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > product_ini_bytes(ini_get('post_max_size'));
if ($postTooLarge) $errors[] = 'The combined request exceeds the server limit. Your changes were not saved. Select fewer or smaller photographs.';

if (!$notice && isset($_GET['msg']) && $_GET['msg'] === 'saved') {
    $notice = 'Product saved successfully.';
}

// Handle Image Actions (Set Primary, Delete Image)
if (!$postTooLarge && !$productMissing && !$productReadOnly && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image_action'])) {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!csrf_validate($token)) {
        $errors[] = 'Invalid security token.';
    } else {
        $imgAction = $_POST['image_action'];
        $imgId = (int)($_POST['image_id'] ?? 0);

        if ($imgAction === 'set_primary' && $productId && $imgId) {
            if (set_primary_product_image($productId, $imgId)) {
                log_product_audit($currentUser['id'], $productId, 'IMAGE_SET_PRIMARY', ['image_id' => $imgId]);
                header("Location: product_edit.php?id={$productId}&msg=saved");
                exit;
            } else {
                $errors[] = 'Failed to update primary image.';
            }
        } elseif ($imgAction === 'delete_image' && $productId && $imgId) {
            if (delete_product_image($imgId, $productId)) {
                log_product_audit($currentUser['id'], $productId, 'IMAGE_DELETED', ['image_id' => $imgId]);
                header("Location: product_edit.php?id={$productId}&msg=saved");
                exit;
            } else {
                $errors[] = 'Failed to delete image.';
            }
        }
    }
}

// Handle Main Form Submission
if (!$postTooLarge && !$productMissing && !$productReadOnly && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['image_action'])) {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!csrf_validate($token)) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $saveToken = is_string($_POST['product_save_token'] ?? null) ? $_POST['product_save_token'] : '';
        if (!isset($_SESSION['product_save_tokens'][$saveToken]) || $_SESSION['product_save_tokens'][$saveToken] !== (int)$productId) {
            $errors[] = 'This save request has already been used or expired. Review the product and submit the fresh form.';
        } else {
        // Session locking plus one-use token protects retries/double submissions, including new products.
        unset($_SESSION['product_save_tokens'][$saveToken]);
        $data = [
            'product_code'             => $_POST['product_code'] ?? '',
            'title'                    => $_POST['title'] ?? '',
            'category_id'              => $_POST['category_id'] ?? '',
            'price'                    => $_POST['price'] ?? 0,
            'condition_label'          => $_POST['condition_label'] ?? '',
            'availability_status'      => $_POST['availability_status'] ?? 'AVAILABLE',
            'publication_status'       => $_POST['publication_status'] ?? 'DRAFT',
            'carousell_listing_id'     => $_POST['carousell_listing_id'] ?? '',
            'carousell_listing_url'    => $_POST['carousell_listing_url'] ?? '',
            'price_verified_at'        => $_POST['price_verified_at'] ?? '',
            'availability_verified_at' => $_POST['availability_verified_at'] ?? '',
            'description'              => $_POST['description'] ?? '',
        ];

        $res = save_product($data, $productId);
        if ($res['success']) {
            $savedId = $res['product_id'];
            $actionName = $productId ? 'PRODUCT_UPDATED' : 'PRODUCT_CREATED';
            log_product_audit($currentUser['id'], $savedId, $actionName, ['code' => $data['product_code'], 'title' => $data['title']]);
            $productId = $savedId;

            $batch = upload_product_photos($savedId, $data['title'], product_upload_files($_FILES['product_photos'] ?? ($_FILES['product_photo'] ?? [])), (int)$currentUser['id']);
            $_SESSION['product_photo_flash'][$savedId] = [
                'notice'=>'Product details saved. '.$batch['saved'].' new photograph'.($batch['saved'] === 1 ? '' : 's').' saved. Existing photographs were preserved.',
                'errors'=>$batch['errors']
            ];
            // Always PRG after a committed save; retrying photographs never creates another product.
            header("Location: product_edit.php?id={$savedId}");
            exit;

        } else {
            $errors = array_merge($errors, $res['errors']);
        }
        }
    }
}

$formSaveToken = bin2hex(random_bytes(24));
$_SESSION['product_save_tokens'][$formSaveToken] = (int)$productId;
while (count($_SESSION['product_save_tokens']) > 20) array_shift($_SESSION['product_save_tokens']);

// Refresh product after save or when loading
if ($productId) {
    $product = get_product_by_id($productId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $productId ? 'Edit Product #' . $productId : 'Add Product' ?> — TechTalk Admin</title>
  <?php include __DIR__ . '/favicon.php'; ?>
  <link rel="stylesheet" href="<?= admin_h(admin_asset_url('admin.css')) ?>">
  <script src="<?= admin_h(admin_asset_url('admin.js')) ?>" defer></script>
  <script src="<?= admin_h(admin_asset_url('product-photos.js')) ?>" defer></script>
</head>
<body<?= admin_preference_attributes() ?>>
  <?php include __DIR__ . '/navbar.php'; ?>
  <main id="main-content" class="admin-container" tabindex="-1">
    <div class="page-header">
      <div><p class="eyebrow">Inventory / <?= $productId ? 'Edit product' : 'New product' ?></p>
        <h1 class="page-title"><?= $productId ? 'Edit Product #' . $productId : 'Add product' ?></h1>
        <p class="page-subtitle">Add clear details and genuine photographs. Fields marked * are required.</p>
      </div>
      <div class="header-actions"><a href="products.php" class="btn btn-secondary">← Products</a></div>
    </div>
    <?php if ($productMissing): ?>
      <div class="alert alert-danger" role="alert">This product could not be loaded. It may have been removed, or the database may be unavailable. <a href="products.php">Return to products</a>.</div>
    <?php elseif ($productReadOnly): ?>
      <div class="alert alert-danger" role="alert">This product has availability <?= admin_h($product['availability_status']) ?>. The current save service supports Available, Reserved and Sold. Editing is paused to preserve the recorded status. <a href="products.php">Return to products</a>.</div>
    <?php else: ?>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= admin_h($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger" role="alert" tabindex="-1" data-error-summary>
        <strong><?= $photoUploadProblems ? 'Product saved; some photographs need attention' : 'Review the following before saving' ?></strong>
        <?php if ($photoUploadProblems): ?><p>Saved photographs are unchanged. Retry only the files listed below on this product.</p><?php endif; ?>
        <ul><?php foreach ($errors as $err): ?><li><?= admin_h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <form method="POST" action="product_edit.php<?= $productId ? '?id=' . $productId : '' ?>" enctype="multipart/form-data" class="editor-form" data-product-editor>
      <input type="hidden" name="product_save_token" value="<?= admin_h($formSaveToken) ?>">
      <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>">
<fieldset class="editor-section"><legend><span class="section-number">01</span>Basic Information</legend>
<p class="section-description">Give customers a useful title, reference and description.</p><div class="form-grid">
<div class="form-group full-width">
          <label for="title">Product Title *</label>
          <input type="text" id="title" aria-describedby="title-error" name="title" maxlength="255" required placeholder="e.g. MacBook Pro 14 M2 Pro 16GB 512GB Space Gray" value="<?= htmlspecialchars($_POST['title'] ?? ($product['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <span class="field-error" id="title-error" hidden></span></div>
<div class="form-group">
          <label for="product_code">Product Reference Code</label>
          <input type="text" id="product_code" name="product_code" placeholder="Leave empty to auto-generate (TT-XXXXX)" value="<?= htmlspecialchars($_POST['product_code'] ?? ($product['product_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          <span class="help-text">Leave blank to generate a reference. Use this code to identify customer inquiries.</span>
        </div>

<div class="form-group full-width">
          <label for="description">Specifications & Description</label>
          <textarea id="description" name="description" placeholder="Include battery health, cosmetic condition, accessories included, serial/warranty details..."><?= htmlspecialchars($_POST['description'] ?? ($product['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
</div></fieldset>
<fieldset class="editor-section"><legend><span class="section-number">02</span>Pricing &amp; Category</legend>
<p class="section-description">Choose the category, record the actual peso price, and describe the condition.</p><div class="form-grid">
<div class="form-group">
          <label for="category_id">Category *</label>
          <select id="category_id" aria-describedby="category_id-error" name="category_id" required>
            <option value="">Select Category...</option>
            <?php
              $selectedCat = $_POST['category_id'] ?? ($product['category_id'] ?? '');
              foreach ($categories as $cat):
            ?>
              <option value="<?= $cat['id'] ?>" <?= (string)$selectedCat === (string)$cat['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        <span class="field-error" id="category_id-error" hidden></span></div>
<div class="form-group">
          <label for="price">Price (PHP) *</label>
          <input type="number" id="price" aria-describedby="price-error" name="price" step="0.01" min="0" required placeholder="e.g. 85000" value="<?= htmlspecialchars($_POST['price'] ?? ($product['price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="field-error" id="price-error" hidden></span></div>
<div class="form-group">
          <label for="condition_label">Condition Label *</label>
          <select id="condition_label" name="condition_label" required>
            <?php
              $condOptions = ['Brand New', 'Like New', 'Used - Excellent', 'Used - Good', 'Open Box'];
              $selectedCond = $_POST['condition_label'] ?? ($product['condition_label'] ?? 'Used - Excellent');
              foreach ($condOptions as $opt):
            ?>
              <option value="<?= $opt ?>" <?= $selectedCond === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>

</div></fieldset>
<fieldset class="editor-section"><legend><span class="section-number">03</span>Availability and Publication</legend>
<p class="section-description">Availability describes stock. Publication controls visibility on the website.</p><div class="form-grid">
<div class="form-group">
          <label for="availability_status">Availability Status *</label>
          <select id="availability_status" name="availability_status" required>
            <?php
              $availOptions = ['AVAILABLE' => 'Available (In Stock)', 'RESERVED' => 'Reserved', 'SOLD' => 'Sold'];
              $selectedAvail = $_POST['availability_status'] ?? ($product['availability_status'] ?? 'AVAILABLE');
              foreach ($availOptions as $val => $lbl):
            ?>
              <option value="<?= $val ?>" <?= $selectedAvail === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
<div class="form-group">
          <label for="publication_status">Publication Status *</label>
          <select aria-describedby="publication-note" id="publication_status" name="publication_status" required>
            <?php
              $pubOptions = ['DRAFT' => 'Draft (Private)', 'PUBLISHED' => 'Published (Live on Website)', 'ARCHIVED' => 'Archived'];
              $selectedPub = $_POST['publication_status'] ?? ($product['publication_status'] ?? 'DRAFT');
              foreach ($pubOptions as $val => $lbl):
            ?>
              <option value="<?= $val ?>" <?= $selectedPub === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <span class="help-text">Only items marked as PUBLISHED will appear on the public storefront.</span>
        </div>

</div><p class="publish-note" id="publication-note" role="status"><?php if ($selectedPub === 'PUBLISHED'): ?>Saving publishes this product on the public website. Check its details before saving.<?php elseif ($selectedPub === 'ARCHIVED'): ?>Saving archives this product and removes it from the public website.<?php else: ?>Saving keeps this product private. Choose Published when it is ready to appear on the public website.<?php endif; ?> Carousell is managed separately.</p></fieldset>
<fieldset class="editor-section"><legend><span class="section-number">04</span>Product Photographs</legend>
<p class="section-description">Add genuine photographs. Existing photographs stay in place; use Make primary below to change the catalog cover.</p>
<div class="form-grid"><div class="form-group full-width" data-photo-dropzone>
  <?php admin_icon('upload', 'upload-icon'); ?>
  <label for="product_photo">Add product photographs</label>
  <input type="file" id="product_photo" name="product_photos[]" multiple accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" aria-describedby="photo-help photo-feedback" data-max-count="<?= (int)$uploadLimits['count'] ?>" data-max-file="<?= (int)$uploadLimits['file_bytes'] ?>" data-max-batch="<?= (int)$uploadLimits['batch_bytes'] ?>">
  <p class="help-text" id="photo-help">Choose or drop up to <?= (int)$uploadLimits['count'] ?> JPEG, PNG or WebP images. Each up to <?= admin_h(number_format($uploadLimits['file_bytes']/1048576, 1)) ?> MB; combined up to <?= admin_h(number_format($uploadLimits['batch_bytes']/1048576, 1)) ?> MB. Up to 8192 pixels per side / 16 million pixels. Images are sanitized when saved. The first saved photograph becomes primary if none exists.</p>
  <p id="photo-feedback" class="help-text" role="status" aria-live="polite"></p>
  <div id="selected-photos" class="selected-photos" aria-label="Photographs selected for upload"></div>
</div></div>
<?php if ($productId && !empty($product['images'])): ?>
  <h3 style="margin-top:24px">Saved photographs · <?= count($product['images']) ?></h3>
  <p class="help-text">Photo actions take effect immediately. Save any product edits first.</p>
  <div class="image-preview-grid">
    <?php foreach ($product['images'] as $img): ?>
      <div class="image-preview-card <?= $img['is_primary'] ? 'primary' : '' ?>">
        <img loading="lazy" src="<?= admin_h(product_image_url($img['image_path'], true, '../')) ?>" alt="<?= admin_h($img['alt_text'] ?: $product['title']) ?>">
        <?php if ($img['is_primary']): ?><span class="image-preview-badge">Primary photo</span><?php endif; ?>
        <div class="image-preview-actions">
          <?php if (!$img['is_primary']): ?><button type="submit" form="primary-image-<?= (int)$img['id'] ?>" class="btn btn-secondary btn-sm">Make primary</button><?php endif; ?>
          <button type="submit" form="delete-image-<?= (int)$img['id'] ?>" class="btn btn-danger btn-sm" aria-label="Remove photograph <?= (int)$img['id'] ?>">Remove photo</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</fieldset>
<fieldset class="editor-section"><legend><span class="section-number">05</span>Verification &amp; Listing Details</legend>
<p class="section-description">Record verification dates and connect an existing listing. Carousell is managed separately.</p><div class="form-grid">
<div class="form-group">
          <label for="price_verified_at">Price Verified Date</label>
          <input type="datetime-local" step="1" id="price_verified_at" name="price_verified_at" value="<?= admin_h($_POST['price_verified_at'] ?? (!empty($product['price_verified_at']) ? substr(str_replace(' ', 'T', $product['price_verified_at']), 0, 19) : '')) ?>">
          <span class="help-text">Set only after you verify this information. Leave blank if not verified.</span>
        </div>
<div class="form-group">
          <label for="availability_verified_at">Stock Verified Date</label>
          <input type="datetime-local" step="1" id="availability_verified_at" name="availability_verified_at" value="<?= admin_h($_POST['availability_verified_at'] ?? (!empty($product['availability_verified_at']) ? substr(str_replace(' ', 'T', $product['availability_verified_at']), 0, 19) : '')) ?>">
          <span class="help-text">Set only after you verify this information. Leave blank if not verified.</span>
        </div>
<div class="form-group">
          <label for="carousell_listing_url">Carousell Listing URL</label>
          <input type="url" id="carousell_listing_url" aria-describedby="carousell_listing_url-error" name="carousell_listing_url" placeholder="https://www.carousell.ph/p/..." value="<?= htmlspecialchars($_POST['carousell_listing_url'] ?? ($product['carousell_listing_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          <span class="help-text">Optional. Paste the listing link; publishing here does not create or synchronize a Carousell listing.</span>
        <span class="field-error" id="carousell_listing_url-error" hidden></span></div>
<div class="form-group">
          <label for="carousell_listing_id">Carousell Listing ID</label>
          <input type="text" id="carousell_listing_id" name="carousell_listing_id" placeholder="e.g. 1234567890" value="<?= htmlspecialchars($_POST['carousell_listing_id'] ?? ($product['carousell_listing_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
</div></fieldset>

      <div class="form-footer">
        <p class="help-text">Changes apply when you save this product.</p>
        <div class="button-row"><a href="products.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary" data-save-label="<?= $productId ? 'Save product changes' : 'Create draft product' ?>"><?= $selectedPub === 'PUBLISHED' ? 'Save & publish product' : ($selectedPub === 'ARCHIVED' ? 'Save archived product' : ($productId ? 'Save product changes' : 'Create draft product')) ?></button>
        </div>
      </div>
    </form>
    <?php if ($productId && !empty($product['images'])): foreach ($product['images'] as $img): ?>
      <?php foreach (['set_primary' => 'primary', 'delete_image' => 'delete'] as $action => $prefix): ?>
        <form id="<?= $prefix ?>-image-<?= (int)$img['id'] ?>" method="POST" action="product_edit.php?id=<?= (int)$productId ?>" <?php if ($action === 'delete_image'): ?>onsubmit="return confirm('Remove this photograph permanently? Save product edits first to avoid losing them.');"<?php endif; ?>>
          <input type="hidden" name="csrf_token" value="<?= admin_h(csrf_token()) ?>">
          <input type="hidden" name="image_action" value="<?= $action ?>">
          <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
        </form>
      <?php endforeach; ?>
    <?php endforeach; endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
