<?php
/**
 * TechTalk Product Data Access Layer
 * Safe, prepared queries for both public storefront and administrative dashboard.
 */

require_once __DIR__ . '/db.php';

/**
 * Retrieve all published products for the public website.
 * Orders by availability (AVAILABLE first, then RESERVED, then SOLD) and created_at DESC.
 */
function get_published_products(): array {
    $pdo = get_db_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $sql = "
            SELECT
                p.id,
                p.product_code,
                p.carousell_listing_id,
                p.title,
                p.description,
                p.price,
                p.currency,
                p.condition_label,
                p.availability_status,
                p.publication_status,
                p.carousell_listing_url,
                p.price_verified_at,
                p.availability_verified_at,
                p.created_at,
                c.id AS category_id,
                c.name AS category_name,
                c.slug AS category_slug,
                (
                    SELECT pi.image_path
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC
                    LIMIT 1
                ) AS primary_image,
                (
                    SELECT pi.alt_text
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC
                    LIMIT 1
                ) AS primary_image_alt
            FROM products p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE p.publication_status = 'PUBLISHED'
            ORDER BY
                CASE p.availability_status
                    WHEN 'AVAILABLE' THEN 1
                    WHEN 'RESERVED' THEN 2
                    WHEN 'SOLD' THEN 3
                    ELSE 4
                END ASC,
                p.created_at DESC
        ";

        $stmt = $pdo->query($sql);
        $products = $stmt->fetchAll();

        // One additional published-only query; never expose draft photographs.
        $images = $pdo->query("SELECT pi.product_id, pi.image_path, pi.alt_text FROM product_images pi INNER JOIN products p ON p.id = pi.product_id WHERE p.publication_status = 'PUBLISHED' ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC")->fetchAll();
        $byProduct = [];
        foreach ($images as $image) {
            if (product_image_path($image['image_path'])) $byProduct[(int)$image['product_id']][] = $image;
        }

        // Enhance with formatted fields
        foreach ($products as &$p) {
            $p['formatted_price'] = '₱' . number_format((float)$p['price'], 2, '.', ',');
            $p['images'] = $byProduct[(int)$p['id']] ?? [];
            $p['is_available'] = ($p['availability_status'] === 'AVAILABLE');
            $p['is_reserved'] = ($p['availability_status'] === 'RESERVED');
            $p['is_sold'] = ($p['availability_status'] === 'SOLD');
        }
        unset($p);

        return $products;
    } catch (PDOException $e) {
        error_log("Failed to fetch published products: " . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve product categories.
 */
function get_categories(): array {
    $pdo = get_db_connection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("SELECT * FROM `categories` ORDER BY `display_order` ASC, `name` ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to fetch categories: " . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve products with administrative filters and search.
 */
function get_admin_products(array $filters = [], ?string &$loadError = null): array {
    $loadError = null;
    $pdo = get_db_connection();
    if (!$pdo) {
        $loadError = 'Products could not be loaded. Please try again.';
        return [];
    }

    try {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['publication_status'])) {
            $where[] = "p.publication_status = :pub_status";
            $params[':pub_status'] = $filters['publication_status'];
        }

        if (!empty($filters['availability_status'])) {
            $where[] = "p.availability_status = :avail_status";
            $params[':avail_status'] = $filters['availability_status'];
        }

        if (!empty($filters['category_id']) && is_numeric($filters['category_id'])) {
            $where[] = "p.category_id = :cat_id";
            $params[':cat_id'] = (int)$filters['category_id'];
        }

        if (!empty($filters['search'])) {
            // Native PDO prepares require a distinct placeholder per occurrence.
            $where[] = "(p.title LIKE :search_title OR p.product_code LIKE :search_code OR p.description LIKE :search_description)";
            foreach ([':search_title', ':search_code', ':search_description'] as $placeholder) {
                $params[$placeholder] = '%' . $filters['search'] . '%';
            }
        }

        $whereClause = implode(" AND ", $where);

        $sql = "
            SELECT
                p.*,
                c.name AS category_name,
                c.slug AS category_slug,
                (
                    SELECT pi.image_path
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.display_order ASC, pi.id ASC
                    LIMIT 1
                ) AS primary_image,
                (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS total_images
            FROM products p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE {$whereClause}
            ORDER BY p.updated_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Failed to fetch admin products: " . $e->getMessage());
        $loadError = 'Products could not be loaded. Please try again.';
        return [];
    }
}

/**
 * Retrieve a single product by ID with all associated images.
 */
function get_product_by_id(int $id): ?array {
    $pdo = get_db_connection();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p
            INNER JOIN categories c ON p.category_id = c.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        // Fetch associated images
        $imgStmt = $pdo->prepare("
            SELECT * FROM product_images
            WHERE product_id = :product_id
            ORDER BY is_primary DESC, display_order ASC, id ASC
        ");
        $imgStmt->execute([':product_id' => $id]);
        $product['images'] = $imgStmt->fetchAll();

        return $product;
    } catch (PDOException $e) {
        error_log("Failed to get product {$id}: " . $e->getMessage());
        return null;
    }
}

/**
 * Save product (Create or Update).
 */
function save_product(array $data, ?int $id = null): array {
    $pdo = get_db_connection();
    if (!$pdo) {
        return ['success' => false, 'errors' => ['Database connection failed.']];
    }

    $errors = [];

    foreach (['title','product_code','category_id','price','condition_label','availability_status','publication_status','carousell_listing_url','description','carousell_listing_id','price_verified_at','availability_verified_at'] as $field) {
        if (isset($data[$field]) && !is_scalar($data[$field])) return ['success'=>false,'errors'=>['Enter a valid value for '.str_replace('_',' ',$field).'.']];
    }
    $title = trim($data['title'] ?? '');
    if ($title === '') {
        $errors[] = 'Product title is required.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Product title cannot exceed 255 characters.';
    }

    $categoryId = (int)($data['category_id'] ?? 0);
    if ($categoryId <= 0) {
        $errors[] = 'Please select a valid category.';
    }

    $price = filter_var($data['price'] ?? 0, FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0) {
        $errors[] = 'Please enter a valid non-negative price.';
    }

    $condition = trim($data['condition_label'] ?? 'Used - Excellent');
    if ($condition === '') {
        $condition = 'Used - Excellent';
    }

    $availStatus = $data['availability_status'] ?? 'AVAILABLE';
    if (!in_array($availStatus, ['AVAILABLE', 'RESERVED', 'SOLD'], true)) {
        $errors[] = 'Choose a supported availability status. The recorded status has not been changed.';
    }

    $pubStatus = $data['publication_status'] ?? 'DRAFT';
    if (!in_array($pubStatus, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
        $errors[] = 'Choose Draft, Published or Archived.';
    }

    $carousellUrl = trim($data['carousell_listing_url'] ?? '');
    if ($carousellUrl !== '' && product_listing_url($carousellUrl) === '') $errors[] = 'Use an HTTPS listing URL on carousell.ph or carousell.app.link.';
    $carousellUrl = $carousellUrl ?: null;

    $description = trim($data['description'] ?? '');
    $carousellListingId = trim($data['carousell_listing_id'] ?? '') ?: null;

    try {
        $priceVerifiedAt = product_verification_date((string)($data['price_verified_at'] ?? ''));
        $availVerifiedAt = product_verification_date((string)($data['availability_verified_at'] ?? ''));
    } catch (InvalidArgumentException $e) {
        $errors[] = 'Enter valid verification dates, or leave them blank.';
        $priceVerifiedAt = null; $availVerifiedAt = null;
    }
    if (!is_finite((float)$price) || (float)$price > 99999999.99 || abs((float)$price * 100 - round((float)$price * 100)) > 0.00001) $errors[] = 'Use a price up to 99,999,999.99 with no more than two decimal places.';
    foreach (['product_code'=>50,'carousell_listing_id'=>100,'condition_label'=>50,'carousell_listing_url'=>500] as $field=>$max) if (mb_strlen((string)($data[$field] ?? '')) > $max) $errors[] = ucfirst(str_replace('_',' ',$field)).' is too long.';
    if ($errors) return ['success'=>false,'errors'=>$errors];

    // Generate product_code if blank
    $productCode = trim($data['product_code'] ?? '');
    if ($productCode === '') {
        $productCode = 'TT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    try {
    // Check code uniqueness
    $checkCodeSql = "SELECT id FROM products WHERE product_code = :code" . ($id ? " AND id != :id" : "");
    $checkParams = [':code' => $productCode];
    if ($id) {
        $checkParams[':id'] = $id;
    }
    $checkStmt = $pdo->prepare($checkCodeSql);
    $checkStmt->execute($checkParams);
    if ($checkStmt->fetch()) {
        $errors[] = "Product code '{$productCode}' is already in use. Please specify a unique code.";
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

        if ($id) {
            // Update
            $sql = "
                UPDATE products SET
                    product_code = :code,
                    carousell_listing_id = :carousell_id,
                    title = :title,
                    description = :description,
                    category_id = :category_id,
                    price = :price,
                    condition_label = :condition_label,
                    availability_status = :avail_status,
                    publication_status = :pub_status,
                    carousell_listing_url = :carousell_url,
                    price_verified_at = :price_verified,
                    availability_verified_at = :avail_verified,
                    updated_at = NOW()
                WHERE id = :id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':code'             => $productCode,
                ':carousell_id'     => $carousellListingId,
                ':title'            => $title,
                ':description'      => $description,
                ':category_id'      => $categoryId,
                ':price'            => $price,
                ':condition_label'  => $condition,
                ':avail_status'     => $availStatus,
                ':pub_status'       => $pubStatus,
                ':carousell_url'    => $carousellUrl,
                ':price_verified'   => $priceVerifiedAt,
                ':avail_verified'   => $availVerifiedAt,
                ':id'               => $id
            ]);
            $productId = $id;
        } else {
            // Insert
            $sql = "
                INSERT INTO products (
                    product_code, carousell_listing_id, title, description,
                    category_id, price, currency, condition_label,
                    availability_status, publication_status, carousell_listing_url,
                    price_verified_at, availability_verified_at, created_at, updated_at
                ) VALUES (
                    :code, :carousell_id, :title, :description,
                    :category_id, :price, 'PHP', :condition_label,
                    :avail_status, :pub_status, :carousell_url,
                    :price_verified, :avail_verified, NOW(), NOW()
                )
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':code'             => $productCode,
                ':carousell_id'     => $carousellListingId,
                ':title'            => $title,
                ':description'      => $description,
                ':category_id'      => $categoryId,
                ':price'            => $price,
                ':condition_label'  => $condition,
                ':avail_status'     => $availStatus,
                ':pub_status'       => $pubStatus,
                ':carousell_url'    => $carousellUrl,
                ':price_verified'   => $priceVerifiedAt,
                ':avail_verified'   => $availVerifiedAt
            ]);
            $productId = (int)$pdo->lastInsertId();
        }

        return ['success' => true, 'product_id' => $productId, 'errors' => []];
    } catch (PDOException $e) {
        error_log("Failed to save product: " . $e->getMessage());
        return ['success' => false, 'errors' => ['A database error occurred while saving the product. Please check the system log or try again.']];
    }
}

/**
 * Add an image for a product.
 * Transactional: ensures primary image state remains consistent.
 */
function add_product_image(int $productId, string $imagePath, string $altText = '', bool $isPrimary = false, int $displayOrder = 0): bool {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
        $lock->execute([':id'=>$productId]);
        if (!$lock->fetchColumn()) { $pdo->rollBack(); return false; }
        $existing = $pdo->prepare('SELECT image_path, is_primary, display_order FROM product_images WHERE product_id = :pid');
        $existing->execute([':pid'=>$productId]);
        $images = $existing->fetchAll();
        $newPath = product_image_path($imagePath);
        if (!$newPath || !is_file($newPath)) { $pdo->rollBack(); return false; }
        $digest = hash_file('sha256', $newPath);
        foreach ($images as $image) {
            $oldPath = product_image_path($image['image_path']);
            if ($oldPath && is_file($oldPath) && hash_file('sha256', $oldPath) === $digest) { $pdo->rollBack(); return false; }
        }
        $isPrimary = $isPrimary || !array_filter($images, fn($image) => (int)$image['is_primary'] === 1);
        $displayOrder = $images ? max(array_column($images, 'display_order')) + 1 : 0;
        if ($isPrimary) {
            // Demote other primary images atomically
            $demote = $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid");
            $demote->execute([':pid' => $productId]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO product_images (product_id, image_path, alt_text, display_order, is_primary, created_at)
            VALUES (:pid, :path, :alt, :order, :primary, NOW())
        ");
        $res = $stmt->execute([
            ':pid'     => $productId,
            ':path'    => $imagePath,
            ':alt'     => $altText,
            ':order'   => $displayOrder,
            ':primary' => $isPrimary ? 1 : 0
        ]);

        $pdo->commit();
        return $res;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to add product image: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a product image.
 * Promotes next available image if primary is deleted, and cleans up storage.
 */
function delete_product_image(int $imageId, ?int $expectedProductId = null): bool {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $owner = $pdo->prepare('SELECT product_id FROM product_images WHERE id = :id');
        $owner->execute([':id'=>$imageId]);
        $ownerId = (int)$owner->fetchColumn();
        if (!$ownerId || ($expectedProductId !== null && $ownerId !== $expectedProductId)) { $pdo->rollBack(); return false; }
        $lock = $pdo->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
        $lock->execute([':id'=>$ownerId]);
        $stmt = $pdo->prepare("SELECT * FROM product_images WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $imageId]);
        $image = $stmt->fetch();

        if (!$image) {
            $pdo->rollBack();
            return false;
        }

        $productId = (int)$image['product_id'];
        $wasPrimary = (int)$image['is_primary'] === 1;

        $delStmt = $pdo->prepare("DELETE FROM product_images WHERE id = :id");
        $delStmt->execute([':id' => $imageId]);

        // If deleted image was primary, automatically promote next available image
        if ($wasPrimary) {
            $nextStmt = $pdo->prepare("
                SELECT id FROM product_images
                WHERE product_id = :pid
                ORDER BY display_order ASC, id ASC
                LIMIT 1
            ");
            $nextStmt->execute([':pid' => $productId]);
            $nextImg = $nextStmt->fetch();
            if ($nextImg) {
                $promote = $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id");
                $promote->execute([':id' => $nextImg['id']]);
            }
        }

        $pdo->commit();

        // Safely unlink file from storage after database transaction completes
        product_image_unlink($image['image_path']);

        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to delete product image: " . $e->getMessage());
        return false;
    }
}

/**
 * Set primary image for a product.
 */
function set_primary_product_image(int $productId, int $imageId): bool {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
        $lock->execute([':id'=>$productId]);
        $check = $pdo->prepare('SELECT id FROM product_images WHERE id = :id AND product_id = :pid');
        $check->execute([':id'=>$imageId, ':pid'=>$productId]);
        if (!$check->fetchColumn()) { $pdo->rollBack(); return false; }
        $reset = $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid");
        $reset->execute([':pid' => $productId]);

        $set = $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_id = :pid");
        $set->execute([':id' => $imageId, ':pid' => $productId]);
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to set primary image: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete or archive product.
 */
function delete_product(int $id, bool $permanent = false): bool {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        if ($permanent) {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
            $lock->execute([':id'=>$id]);
            $imgStmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = :id");
            $imgStmt->execute([':id'=>$id]);
            $paths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
            $stmt->execute([':id'=>$id]);
            $pdo->commit();
            foreach ($paths as $path) product_image_unlink($path);
            return true;
        } else {
            // Soft archive
            $stmt = $pdo->prepare("UPDATE products SET publication_status = 'ARCHIVED', updated_at = NOW() WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Failed to delete/archive product: " . $e->getMessage());
        return false;
    }
}

/**
 * Catalog counts for admin dashboard summary.
 */
function get_product_stats(?string &$loadError = null): array {
    $loadError = null;
    $pdo = get_db_connection();
    if (!$pdo) {
        $loadError = 'Catalog data is temporarily unavailable. Please try again.';
        return [
            'total' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0,
            'available' => 0, 'reserved' => 0, 'sold' => 0
        ];
    }

    try {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN publication_status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN publication_status = 'DRAFT' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN publication_status = 'ARCHIVED' THEN 1 ELSE 0 END) AS archived,
                SUM(CASE WHEN availability_status = 'AVAILABLE' AND publication_status = 'PUBLISHED' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN availability_status = 'RESERVED' AND publication_status = 'PUBLISHED' THEN 1 ELSE 0 END) AS reserved,
                SUM(CASE WHEN availability_status = 'SOLD' AND publication_status = 'PUBLISHED' THEN 1 ELSE 0 END) AS sold
            FROM products
        ";
        $stmt = $pdo->query($sql);
        $res = $stmt->fetch();
        return [
            'total'     => (int)($res['total'] ?? 0),
            'published' => (int)($res['published'] ?? 0),
            'draft'     => (int)($res['draft'] ?? 0),
            'archived'  => (int)($res['archived'] ?? 0),
            'available' => (int)($res['available'] ?? 0),
            'reserved'  => (int)($res['reserved'] ?? 0),
            'sold'      => (int)($res['sold'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log("Failed to get product stats: " . $e->getMessage());
        $loadError = 'Catalog data is temporarily unavailable. Please try again.';
        return [
            'total' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0,
            'available' => 0, 'reserved' => 0, 'sold' => 0
        ];
    }
}

/**
 * Decode, orient, resize if oversized, and re-encode an image using GD.
 * Strips all EXIF, GPS, camera, and author metadata while preserving visual quality.
 */
function process_and_sanitize_image(string $tmpPath, string $mime, string $destPath): bool {
    if (!extension_loaded('gd') || ($mime === 'image/webp' && (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')))) return false;
    // 1. Detect orientation if JPEG and EXIF is available
    $orientation = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        if (!empty($exif['Orientation'])) {
            $orientation = (int)$exif['Orientation'];
        }
    }

    // 2. Decode image via GD
    $img = false;
    switch ($mime) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            $img = @imagecreatefromwebp($tmpPath);
            break;
    }

    if (!$img) {
        return false;
    }

    // 3. Apply camera orientation correction before stripping EXIF
    if ($orientation > 1) {
        if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
        $deg = 0;
        if ($orientation === 3 || $orientation === 4) $deg = 180;
        elseif ($orientation === 6 || $orientation === 7) $deg = -90;
        elseif ($orientation === 8 || $orientation === 5) $deg = 90;

        if ($deg !== 0) {
            $rotated = @imagerotate($img, $deg, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
    }

    // 4. Enforce max dimension limits (max 2400px width/height)
    $origW = imagesx($img);
    $origH = imagesy($img);
    $maxDim = 2400;

    if ($origW > $maxDim || $origH > $maxDim) {
        $ratio = min($maxDim / $origW, $maxDim / $origH);
        $newW = max(1, (int)round($origW * $ratio));
        $newH = max(1, (int)round($origH * $ratio));

        $resized = imagecreatetruecolor($newW, $newH);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($img);
        $img = $resized;
    }

    // 5. Re-encode to destination file without metadata
    $saved = false;
    switch ($mime) {
        case 'image/jpeg':
            $saved = @imagejpeg($img, $destPath, 88);
            break;
        case 'image/png':
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $saved = @imagepng($img, $destPath, 6);
            break;
        case 'image/webp':
            $saved = @imagewebp($img, $destPath, 88);
            break;
    }

    imagedestroy($img);

    // A securely reserved file may have cached zero-byte metadata from before encoding.
    clearstatcache(true, $destPath);
    if ($saved && file_exists($destPath) && filesize($destPath) > 0) {
        @chmod($destPath, 0644);
        return true;
    }

    return false;
}

/** Accept local catalog files only, with no traversal or symlink following. */
function product_image_path(string $relative): ?string {
    if (!preg_match('~\Aassets/products/[a-zA-Z0-9_-][a-zA-Z0-9._-]*\.(?:jpg|jpeg|png|webp)\z~i', $relative)) return null;
    $path = dirname(__DIR__).'/'.$relative;
    return is_link($path) ? null : $path;
}
function product_image_preview(string $relative): string {
    $path = product_image_path($relative);
    return $path && is_file($path.'.thumb.jpg') && !is_link($path.'.thumb.jpg') ? $relative.'.thumb.jpg' : $relative;
}

/**
 * Public/admin URL for a catalog photograph. Files live under a denied
 * directory; product-image.php authorizes drafts/archived records.
 */
function product_image_url(string $relative, bool $thumbnail = false, string $prefix = ''): string {
    if (!product_image_path($relative)) return '';
    $file = basename($relative);
    if (!preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)\z/i', $file)) return '';
    return $prefix . 'product-image.php?f=' . rawurlencode($file . ($thumbnail ? '.thumb.jpg' : ''));
}
function product_image_unlink(string $relative): void {
    $path = product_image_path($relative);
    if (!$path) return;
    foreach ([$path, $path.'.thumb.jpg'] as $file) if (is_file($file) && !is_link($file)) {
        if (!@unlink($file)) error_log('Product photograph cleanup was deferred; check storage permissions.');
    }
}

/** One allowlist for both saved and pre-existing public/admin listing links. */
function product_listing_url(?string $url): string {
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    return strtolower($parts['scheme'] ?? '') === 'https'
        && !isset($parts['user']) && !isset($parts['pass'])
        && (!isset($parts['port']) || $parts['port'] === 443)
        && ($host === 'carousell.ph' || str_ends_with($host, '.carousell.ph') || $host === 'carousell.app.link') ? $url : '';
}
function product_verification_date(string $raw): ?string {
    if ($raw === '') return null;
    foreach (['!Y-m-d\TH:i:s','!Y-m-d\TH:i','!Y-m-d H:i:s','!Y-m-d H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format,$raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format(substr($format,1)) === $raw) return $date->format('Y-m-d H:i:s');
    }
    throw new InvalidArgumentException('Invalid verification date.');
}
