<?php
/**
 * Authorization-aware catalog image delivery.
 * Published images are public; draft/archived images require an admin session.
 */
define('TECHTALK_INIT', true);
require_once __DIR__ . '/includes/products.php';
require_once __DIR__ . '/includes/security.php';
send_security_headers();

$requested = $_GET['f'] ?? '';
if (!is_string($requested) || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|jpeg|png|webp)(?:\.thumb\.jpg)?\z/i', $requested)) {
    http_response_code(404);
    exit;
}

$isThumb = str_ends_with(strtolower($requested), '.thumb.jpg');
$baseFile = $isThumb ? substr($requested, 0, -10) : $requested;
$relative = 'assets/products/' . $baseFile;

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(503);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT p.publication_status
     FROM product_images i
     INNER JOIN products p ON p.id = i.product_id
     WHERE i.image_path = :path
     LIMIT 1'
);
$stmt->execute([':path' => $relative]);
$product = $stmt->fetch();
if (!$product) {
    http_response_code(404);
    exit;
}

$isPublished = ($product['publication_status'] ?? '') === 'PUBLISHED';
if (!$isPublished) {
    // Reuse the hardened admin session; do not reveal whether a private file exists.
    require_once __DIR__ . '/includes/auth.php';
    if (!admin_is_authenticated()) {
        http_response_code(404);
        exit;
    }
}

$path = product_image_path($relative);
if (!$path) {
    http_response_code(404);
    exit;
}
$file = ($isThumb && is_file($path . '.thumb.jpg') && !is_link($path . '.thumb.jpg')) ? $path . '.thumb.jpg' : $path;
if (!is_file($file) || is_link($file)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
$allowed = ['image/jpeg' => 'image/jpeg', 'image/png' => 'image/png', 'image/webp' => 'image/webp'];
if (!isset($allowed[$mime])) {
    http_response_code(404);
    exit;
}

$etag = '"' . dechex((int)filesize($file)) . '-' . dechex((int)filemtime($file)) . '-' . ($isPublished ? 'p' : 'a') . '"';
header('ETag: ' . $etag);
if ($isPublished && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
if ($isPublished) {
    // Publication status can change; revalidate so an archived image cannot
    // keep being served from a stale browser/CDN cache.
    header('Cache-Control: public, max-age=0, must-revalidate');
} else {
    header('Cache-Control: private, no-store, max-age=0');
}
header('Content-Type: ' . $allowed[$mime]);
header('Content-Length: ' . (string)filesize($file));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
readfile($file);
