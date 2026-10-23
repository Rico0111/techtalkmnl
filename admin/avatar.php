<?php
/** Private, own-account photograph; no user-ID or filesystem path is accepted. */
define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';
admin_require_auth();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $pdo = get_db_connection();
    if (!$pdo || !admin_account_schema_ready($pdo)) { http_response_code(404); exit; }
    $account = admin_account_get($pdo, (int)$_SESSION['admin_user_id']);
    $filename = $account['avatar_filename'] ?? '';
    $path = admin_account_avatar_path($filename);
    if (!$path || !is_readable($path)) { http_response_code(404); exit; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $expected = ['jpg'=>'image/jpeg', 'png'=>'image/png', 'webp'=>'image/webp'][pathinfo($filename, PATHINFO_EXTENSION)];
    if ($mime !== $expected) { http_response_code(404); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) { http_response_code(404); }
