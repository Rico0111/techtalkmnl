<?php
/** Product-only multipart handling. No schema changes or authentication shortcuts. */
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/audit.php';

function product_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1' || $value === '0') return PHP_INT_MAX;
    $unit = strtolower(substr($value, -1));
    return (int)((float)$value * match ($unit) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 });
}

function product_upload_limits(): array {
    $post = product_ini_bytes(ini_get('post_max_size'));
    return ['file_bytes'=>min(5 * 1024 * 1024, product_ini_bytes(ini_get('upload_max_filesize'))),
        'batch_bytes'=>min(32 * 1024 * 1024, max(0, $post - 1024 * 1024)),
        'count'=>min(8, max(0, (int)ini_get('max_file_uploads')))];
}

function product_upload_files(array $files): array {
    if (!$files) return [];
    if (!is_array($files['name'] ?? null)) return [$files]; // legacy single-file form
    $result = [];
    foreach (array_keys($files['name']) as $i) {
        $entry = [];
        foreach (['name','tmp_name','size','error','type'] as $key) $entry[$key] = $files[$key][$i] ?? null;
        $result[] = $entry;
    }
    return $result;
}

function product_upload_error(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit. Choose a smaller image.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Select this image again.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server cannot store uploads. Contact the site administrator.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped this upload. Contact the site administrator.',
        default => 'The image did not upload. Select it again.'
    };
}

/** Content inspection is separate so synthetic images can exercise the actual validator. */
function product_photo_inspect(string $path, int $size): array {
    $limit = product_upload_limits()['file_bytes'];
    if ($size < 1 || $size > $limit || !is_file($path) || filesize($path) !== $size) throw new RuntimeException('Choose an image within the displayed file-size limit.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $info = @getimagesize($path);
    if (!isset($ext[$mime]) || !$info || ($info['mime'] ?? '') !== $mime) throw new RuntimeException('Choose a genuine JPEG, PNG or WebP image. This file is invalid or corrupted.');
    if (min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 8192 || $info[0] * $info[1] > 16000000) throw new RuntimeException('Use images up to 8192 pixels per side and 16 million pixels total.');
    $memory = product_ini_bytes(ini_get('memory_limit'));
    if ($info[0] * $info[1] * 12 + 32 * 1024 * 1024 > $memory - memory_get_usage(true)) throw new RuntimeException('This image needs more processing memory than the server allows. Resize it before uploading.');
    if (!extension_loaded('gd')) throw new RuntimeException('Image processing is unavailable on the server. Contact the site administrator.');
    return ['mime'=>$mime,'extension'=>$ext[$mime]];
}

/** Same bounded fixed-argument macOS fallback as account avatars, before GD re-encoding. */
function product_photo_encode(string $source, string $mime, string $destination): void {
    $decoded = null;
    try {
        if ($mime === 'image/webp' && (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp'))) {
            if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sips') || !function_exists('proc_open')) throw new RuntimeException('WebP processing is unavailable. Choose JPEG or PNG on this server.');
            $decoded = tempnam(sys_get_temp_dir(), 'techtalk-product-decode-');
            if ($decoded === false) { $decoded = null; throw new RuntimeException('Temporary image storage is unavailable. Contact the site administrator.'); }
            $process = @proc_open(['/usr/bin/sips','-s','format','png',$source,'--out',$decoded], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('WebP decoding failed. Choose JPEG or PNG.');
            $deadline = microtime(true) + 5; $status = proc_get_status($process);
            while ($status['running'] && microtime(true) < $deadline) { usleep(50000); $status = proc_get_status($process); }
            if ($status['running']) proc_terminate($process, 9);
            proc_close($process);
            if ($status['running'] || $status['exitcode'] !== 0 || !is_file($decoded)) throw new RuntimeException('This WebP image could not be decoded. Choose another image.');
            @chmod($decoded, 0600);
            $info = @getimagesize($decoded);
            if (!$info || ($info['mime'] ?? '') !== 'image/png' || max($info[0], $info[1]) > 8192 || $info[0] * $info[1] > 16000000) throw new RuntimeException('The decoded image exceeds the supported dimensions.');
            $source = $decoded; $mime = 'image/png';
        }
        if (!process_and_sanitize_image($source, $mime, $destination)) throw new RuntimeException('This image could not be decoded and sanitized. Select an undamaged JPEG, PNG or WebP.');
    } finally { if ($decoded && is_file($decoded)) @unlink($decoded); }
}

function product_photo_thumbnail(string $source, string $destination): bool {
    $image = @imagecreatefromstring(file_get_contents($source));
    if (!$image) return false;
    $ratio = min(1, 640 / max(imagesx($image), imagesy($image)));
    $w = max(1, (int)round(imagesx($image) * $ratio)); $h = max(1, (int)round(imagesy($image) * $ratio));
    $small = imagecreatetruecolor($w, $h);
    imagefill($small, 0, 0, imagecolorallocate($small, 248, 251, 252));
    imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));
    $ok = @imagejpeg($small, $destination, 82);
    imagedestroy($image); imagedestroy($small);
    if ($ok) @chmod($destination, 0644);
    return $ok;
}

/** Actual exclusive creation is authoritative; access()/ACL hints may disagree on macOS. */
function product_photo_reserve(string $directory, string $extension): array {
    if (!in_array($extension, ['jpg','png','webp'], true)) throw new RuntimeException('Unsupported output format.');
    clearstatcache(true, $directory);
    if (!@is_dir($directory) || @is_link($directory)) throw new RuntimeException('Product photograph storage is missing or unsafe. Contact the site administrator.');
    $filename = bin2hex(random_bytes(16)).'.'.$extension;
    $path = $directory.'/'.$filename;
    $handle = @fopen($path, 'x+b');
    if ($handle === false) throw new RuntimeException('Product photograph storage could not be opened for writing. Contact the site administrator.');
    fclose($handle);
    // Only this request owns the reserved file. Keep staging private until sanitization succeeds.
    if (!@chmod($path, 0600)) {
        @unlink($path);
        throw new RuntimeException('Product photograph staging permissions could not be secured. Contact the site administrator.');
    }
    if (!@is_writable($directory)) {
        $context = product_photo_storage_context($directory);
        $context['event'] = 'PRODUCT_PHOTO_STORAGE_ACL_WRITE_CONFIRMED';
        error_log('TechTalk upload diagnostic '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    return ['filename'=>$filename,'path'=>$path];
}

/** No credentials, session tokens, upload names or image contents in diagnostics. */
function product_photo_storage_context(string $directory): array {
    clearstatcache(true, $directory);
    $stat = @stat($directory);
    $temporary = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    return ['sapi'=>PHP_SAPI,'euid'=>function_exists('posix_geteuid') ? posix_geteuid() : null,
        'egid'=>function_exists('posix_getegid') ? posix_getegid() : null,
        'directory_exists'=>@is_dir($directory),'directory_is_link'=>@is_link($directory),
        'directory_mode'=>$stat ? sprintf('%04o', $stat['mode'] & 0777) : null,
        'directory_uid'=>$stat['uid'] ?? null,'directory_gid'=>$stat['gid'] ?? null,
        'writable_hint'=>@is_writable($directory),'upload_tmp_writable'=>@is_writable($temporary),
        'system_tmp_writable'=>@is_writable(sys_get_temp_dir()),'open_basedir_set'=>ini_get('open_basedir') !== '',
        'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size'),
        'max_file_uploads'=>ini_get('max_file_uploads'),'memory_limit'=>ini_get('memory_limit'),
        'gd'=>extension_loaded('gd'),'jpeg'=>function_exists('imagejpeg'),'png'=>function_exists('imagepng'),
        'native_webp'=>function_exists('imagecreatefromwebp') && function_exists('imagewebp')];
}

function product_photo_log_failure(string $reference, string $stage, string $directory, ?array $lastError): void {
    $context = product_photo_storage_context($directory);
    $context['event'] = 'PRODUCT_PHOTO_UPLOAD_FAILED';
    $context['reference'] = $reference; $context['stage'] = $stage;
    // Deliberately omit warning text: PHP warning strings can include uploaded/client names.
    $context['io_warning_type'] = $lastError['type'] ?? null;
    $message = strtolower($lastError['message'] ?? '');
    $context['io_reason'] = 'not_reported';
    foreach (['permission denied'=>'permission_denied','operation not permitted'=>'operation_not_permitted',
        'read-only'=>'read_only_filesystem','no space'=>'disk_full','quota'=>'quota_exceeded',
        'open_basedir'=>'open_basedir','no such file'=>'missing_directory','failed to open stream'=>'open_failed'] as $needle=>$reason) {
        if (str_contains($message, $needle)) { $context['io_reason'] = $reason; break; }
    }
    error_log('TechTalk upload diagnostic '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Each accepted file commits independently; existing images are never replaced implicitly. */
function upload_product_photos(int $productId, string $title, array $files, int $adminId): array {
    $errors = []; $saved = 0;
    $files = array_values(array_filter($files, fn($f) => ($f['error'] ?? null) !== UPLOAD_ERR_NO_FILE));
    $limits = product_upload_limits();
    if (count($files) > $limits['count']) return ['saved'=>0,'errors'=>['Too many photographs. Select no more than '.$limits['count'].' at once.']];
    $total = 0;
    foreach ($files as $file) $total += is_int($file['size'] ?? null) ? max(0, $file['size']) : 0;
    if ($total > $limits['batch_bytes']) return ['saved'=>0,'errors'=>['The combined upload is too large. Select fewer photographs.']];
    foreach ($files as $i => $file) {
        $name = is_string($file['name'] ?? null) ? mb_substr(basename(str_replace('\\','/',$file['name'])), 0, 100) : 'Photograph '.($i + 1);
        $destination = null;
        $stage = 'http_upload';
        $directory = dirname(__DIR__).'/assets/products';
        error_clear_last();
        try {
            if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(product_upload_error((int)($file['error'] ?? -1)));
            if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('The file is not a valid HTTP upload. Select it again.');
            $stage = 'content_inspection';
            $info = product_photo_inspect($file['tmp_name'], is_int($file['size'] ?? null) ? $file['size'] : 0);
            $extension = $info['mime'] === 'image/webp' && (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) ? 'png' : $info['extension'];
            $stage = 'storage_reservation';
            $reserved = product_photo_reserve($directory, $extension);
            $filename = $reserved['filename']; $destination = $reserved['path'];
            $stage = 'image_encoding';
            product_photo_encode($file['tmp_name'], $info['mime'], $destination);
            $stage = 'thumbnail_encoding';
            if (!product_photo_thumbnail($destination, $destination.'.thumb.jpg')) throw new RuntimeException('The image preview could not be stored. Contact the site administrator.');
            $stage = 'image_record';
            if (!add_product_image($productId, 'assets/products/'.$filename, $title)) throw new RuntimeException('This photograph is already saved or could not be recorded. Existing photographs were preserved.');
            // Database commit precedes audit/response work; never unlink a committed image.
            $destination = null; $saved++;
            $stage = 'audit';
            log_product_audit($adminId, $productId, 'IMAGE_UPLOADED', ['file'=>$filename]);
        } catch (Throwable $e) {
            $lastError = error_get_last();
            $reference = bin2hex(random_bytes(4));
            product_photo_log_failure($reference, $stage, $directory, $lastError);
            if ($destination) { @unlink($destination); @unlink($destination.'.thumb.jpg'); }
            $errors[] = $name.': '.($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'The image could not be stored. Contact the site administrator.').' Reference: '.$reference.'.';
        }
    }
    return ['saved'=>$saved,'errors'=>$errors];
}
