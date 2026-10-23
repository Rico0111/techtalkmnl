<?php
/** UI-3 account storage. No DDL, credential output or public avatar filesystem access. */
if (!defined('TECHTALK_INIT')) { http_response_code(403); exit; }
require_once __DIR__ . '/db.php';

function admin_account_schema_ready(PDO $pdo, bool $refresh = false): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!$refresh && array_key_exists($key, $cache)) return $cache[$key];
    try {
        $pdo->query('SELECT admin_id, display_name, email, avatar_filename, density, reduce_motion, auth_version, last_login_at FROM admin_account_profiles LIMIT 0');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $engines = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('admin_users', 'admin_account_profiles', 'product_audit_log')")->fetchAll(PDO::FETCH_COLUMN);
            if (count($engines) !== 3 || array_filter($engines, fn($engine) => strtoupper($engine) !== 'INNODB')) return $cache[$key] = false;
        }
        return $cache[$key] = true;
    } catch (PDOException $e) {
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) return $cache[$key] = false;
        throw $e;
    }
}

function admin_account_public(array $row): array {
    return [
        'display_name' => (string)($row['display_name'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'avatar_filename' => admin_account_avatar_filename($row['avatar_filename'] ?? '') ? $row['avatar_filename'] : '',
        'density' => ($row['density'] ?? '') === 'compact' ? 'compact' : 'comfortable',
        'reduce_motion' => (bool)($row['reduce_motion'] ?? false),
    ];
}

function admin_account_avatar_filename($filename): bool {
    return is_string($filename) && preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $filename) === 1;
}

function admin_account_avatar_directory(): string {
    return dirname(__DIR__) . '/storage/admin-avatars';
}

function admin_account_avatar_path(string $filename): ?string {
    if (!admin_account_avatar_filename($filename)) return null;
    $path = admin_account_avatar_directory() . '/' . $filename;
    return is_file($path) && !is_link($path) ? $path : null;
}

function admin_account_validate_session(PDO $pdo, int $id): bool {
    if (!admin_account_schema_ready($pdo)) {
        $_SESSION['admin_profile'] = [];
        return !isset($_SESSION['admin_account_version']);
    }
    $stmt = $pdo->prepare('SELECT u.username, p.display_name, p.email, p.avatar_filename, p.density, p.reduce_motion, COALESCE(p.auth_version, 0) AS auth_version FROM admin_users u LEFT JOIN admin_account_profiles p ON p.admin_id = u.id WHERE u.id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['auth_version'] !== (int)($_SESSION['admin_account_version'] ?? 0)) return false;
    $_SESSION['admin_account_version'] = (int)$row['auth_version'];
    $_SESSION['admin_profile'] = admin_account_public($row);
    $_SESSION['admin_username'] = $row['username'];
    return true;
}

/** Called only after a successful normal password login; all clocks below use UTC. */
function admin_account_record_login(PDO $pdo, array $authenticatedUser): bool {
    if (!admin_account_schema_ready($pdo)) {
        unset($_SESSION['admin_account_version']);
        $_SESSION['admin_profile'] = [];
        return true;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT username, password_hash FROM admin_users WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => (int)$authenticatedUser['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh || !hash_equals($fresh['password_hash'], $authenticatedUser['password_hash']) || $fresh['username'] !== $authenticatedUser['username']) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('INSERT INTO admin_account_profiles (admin_id, last_login_at) VALUES (:id, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_login_at = UTC_TIMESTAMP()');
        $stmt->execute([':id' => (int)$authenticatedUser['id']]);
        $stmt = $pdo->prepare('SELECT display_name, email, avatar_filename, density, reduce_motion, auth_version FROM admin_account_profiles WHERE admin_id = :id');
        $stmt->execute([':id' => (int)$authenticatedUser['id']]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Account metadata unavailable');
        $pdo->commit();
        $_SESSION['admin_account_version'] = (int)$row['auth_version'];
        $_SESSION['admin_profile'] = admin_account_public($row);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function admin_account_get(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.created_at, p.display_name, p.email, p.avatar_filename, p.density, p.reduce_motion, p.last_login_at FROM admin_users u LEFT JOIN admin_account_profiles p ON p.admin_id = u.id WHERE u.id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? array_merge($row, admin_account_public($row)) : null;
}

function admin_account_text(array $input, string $key): string {
    return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
}

function admin_account_secret(array $input, string $key): string {
    return isset($input[$key]) && is_string($input[$key]) ? $input[$key] : '';
}

/** Pure input validation; never returns password values. */
function admin_account_validate(array $input): array {
    $errors = []; $values = [];
    $action = admin_account_text($input, 'action');
    $fields = match ($action) { 'profile'=>['display_name','remove_avatar'], 'identity'=>['username','email','current_password'], 'password'=>['current_password','new_password','confirm_password'], 'preferences'=>['density','reduce_motion'], default=>[] };
    foreach ($fields as $field) if (isset($input[$field]) && !is_string($input[$field])) $errors[$field] = 'Enter a valid value.';
    if ($action === 'profile') {
        $values['display_name'] = admin_account_text($input, 'display_name');
        if (mb_strlen($values['display_name']) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $values['display_name'])) $errors['display_name'] = 'Use a display name of up to 100 characters, without control characters.';
        $values['remove_avatar'] = admin_account_text($input, 'remove_avatar') === '1';
    } elseif ($action === 'identity') {
        $values['username'] = admin_account_text($input, 'username');
        $values['email'] = admin_account_text($input, 'email');
        if (!preg_match('/\A[a-zA-Z0-9_.-]{3,50}\z/', $values['username'])) $errors['username'] = 'Use 3–50 letters, numbers, dots, hyphens or underscores.';
        if ($values['email'] !== '' && (strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL))) $errors['email'] = 'Enter a valid email address or leave it blank.';
    } elseif ($action === 'password') {
        $new = admin_account_secret($input, 'new_password');
        if (mb_strlen($new) < 12 || strlen($new) > 72 || str_contains($new, "\0") || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) $errors['new_password'] = 'Use at least 12 characters with uppercase, lowercase and a number (maximum 72 bytes).';
        if (!hash_equals($new, admin_account_secret($input, 'confirm_password'))) $errors['confirm_password'] = 'The new passwords do not match.';
    } elseif ($action === 'preferences') {
        $values['density'] = admin_account_text($input, 'density');
        if (!in_array($values['density'], ['comfortable', 'compact'], true)) $errors['density'] = 'Choose Comfortable or Compact.';
        $motion = admin_account_text($input, 'reduce_motion');
        if (!in_array($motion, ['', '0', '1'], true)) $errors['reduce_motion'] = 'Choose a valid animation preference.';
        $values['reduce_motion'] = $motion === '1' ? 1 : 0;
    } else {
        $errors['form'] = 'Choose a valid account action.';
    }
    if (in_array($action, ['identity', 'password'], true) && admin_account_secret($input, 'current_password') === '') $errors['current_password'] = 'Enter your current password to confirm this change.';
    return ['action' => $action, 'values' => $values, 'errors' => $errors];
}

function admin_account_avatar_inspect(string $path, int $size): array {
    if ($size < 1 || $size > 2 * 1024 * 1024) throw new RuntimeException('Choose a photograph no larger than 2 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($path);
    if (!isset($extensions[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) throw new RuntimeException('Choose a genuine JPEG, PNG or WebP photograph.');
    [$width, $height] = $dimensions;
    if ($width < 32 || $height < 32 || $width > 4096 || $height > 4096 || $width * $height > 8000000) throw new RuntimeException('Use a photograph at least 32 × 32 pixels, no more than 4096 pixels per side and 8 million pixels total.');
    return ['mime' => $mime, 'extension' => $extensions[$mime], 'width' => $width, 'height' => $height];
}

function admin_account_native_webp(): bool {
    return function_exists('imagecreatefromwebp') && function_exists('imagewebp');
}

function admin_account_encode_avatar(string $source, string $mime, string $path): void {
    require_once __DIR__ . '/products.php';
    $decoded = null;
    try {
        if ($mime === 'image/webp' && !admin_account_native_webp()) {
            if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sips') || !function_exists('proc_open')) throw new RuntimeException('WebP processing is unavailable on this server. Please choose JPEG or PNG.');
            $decoded = $path . '.decode-' . bin2hex(random_bytes(4)) . '.png';
            // Fixed binary and argument array: never interpolate an uploaded name into a shell.
            $process = @proc_open(['/usr/bin/sips', '-s', 'format', 'png', $source, '--out', $decoded], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('The WebP photograph could not be decoded. Please choose JPEG or PNG.');
            $deadline = microtime(true) + 5; $status = proc_get_status($process);
            while ($status['running'] && microtime(true) < $deadline) { usleep(50000); $status = proc_get_status($process); }
            if ($status['running']) proc_terminate($process, 9);
            proc_close($process);
            if ($status['running'] || $status['exitcode'] !== 0 || !is_file($decoded)) throw new RuntimeException('The WebP photograph could not be decoded. Please choose JPEG or PNG.');
            @chmod($decoded, 0600);
            $decodedDimensions = @getimagesize($decoded);
            if (!$decodedDimensions || ($decodedDimensions['mime'] ?? '') !== 'image/png' || max($decodedDimensions[0], $decodedDimensions[1]) > 4096 || $decodedDimensions[0] * $decodedDimensions[1] > 8000000) throw new RuntimeException('The decoded photograph exceeds the supported dimensions.');
            $source = $decoded; $mime = 'image/png';
        }
        if (!process_and_sanitize_image($source, $mime, $path)) throw new RuntimeException('The photograph could not be processed. Choose a different image.');
        $dimensions = @getimagesize($path);
        if (!$dimensions) throw new RuntimeException('The photograph could not be processed.');
        if (max($dimensions[0], $dimensions[1]) > 512) {
            $image = @imagecreatefromstring(file_get_contents($path));
            if (!$image) throw new RuntimeException('The photograph could not be processed.');
            $ratio = 512 / max(imagesx($image), imagesy($image));
            $width = max(1, (int)round(imagesx($image) * $ratio)); $height = max(1, (int)round(imagesy($image) * $ratio));
            $small = imagecreatetruecolor($width, $height);
            imagealphablending($small, false); imagesavealpha($small, true);
            imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            $saved = match ($mime) { 'image/jpeg' => @imagejpeg($small, $path, 88), 'image/png' => @imagepng($small, $path, 6), 'image/webp' => @imagewebp($small, $path, 88) };
            imagedestroy($image); imagedestroy($small);
            if (!$saved) throw new RuntimeException('The photograph could not be processed.');
        }
        if (!@chmod($path, 0600)) throw new RuntimeException('The photograph could not be stored securely.');
    } finally { if ($decoded && is_file($decoded)) @unlink($decoded); }
}

function admin_account_store_avatar(array $file): string {
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('The photograph could not be uploaded. Choose the file again.');
    $size = @filesize($file['tmp_name']);
    $info = admin_account_avatar_inspect($file['tmp_name'], $size === false ? 0 : $size);
    $directory = admin_account_avatar_directory();
    clearstatcache(true, $directory);
    if (!is_dir($directory) || is_link($directory)) throw new RuntimeException('Profile photograph storage is unavailable. Please contact the site administrator.');
    $extension = $info['mime'] === 'image/webp' && !admin_account_native_webp() ? 'png' : $info['extension'];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $directory . '/' . $filename;
    $handle = @fopen($path, 'x+b');
    if ($handle === false) throw new RuntimeException('Profile photograph storage could not be opened for writing. Please contact the site administrator.');
    fclose($handle);
    try {
        if (!@chmod($path,0600)) throw new RuntimeException('Profile photograph staging permissions could not be secured.');
        admin_account_encode_avatar($file['tmp_name'], $info['mime'], $path);
        return $filename;
    } catch (Throwable $e) {
        if (is_file($path)) @unlink($path);
        throw $e;
    }
}

/** Delete only unreferenced, generated files; retain files if database state is uncertain. */
function admin_account_cleanup_avatar(PDO $pdo, string $filename): void {
    if (!admin_account_avatar_filename($filename)) return;
    try {
        $stmt = $pdo->prepare('SELECT admin_id FROM admin_account_profiles WHERE avatar_filename = :filename LIMIT 1');
        $stmt->execute([':filename' => $filename]);
        if ($stmt->fetch()) return;
        if (($path = admin_account_avatar_path($filename)) && !@unlink($path)) error_log('Account avatar cleanup could not remove an unreferenced file.');
    } catch (Throwable $e) { error_log('Account avatar cleanup deferred; reference state unavailable.'); }
}

function admin_account_audit(PDO $pdo, int $id, string $action, array $details = []): void {
    $stmt = $pdo->prepare('INSERT INTO product_audit_log (admin_id, product_id, action, details_json) VALUES (:admin, NULL, :action, :details)');
    if (!$stmt->execute([':admin' => $id, ':action' => $action, ':details' => json_encode($details, JSON_THROW_ON_ERROR)])) throw new RuntimeException('Account audit could not be recorded');
}

/** Every update binds the actor to the authenticated session, never to a posted user ID. */
function admin_account_update(array $input, ?array $file = null): array {
    $failure = fn(array $errors, bool $reauth = false) => ['success' => false, 'errors' => $errors, 'reauthenticate' => $reauth];
    if (!admin_is_authenticated()) return $failure(['form' => 'Your session has expired. Please sign in again.'], true);
    $token = is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : null;
    if (!csrf_validate($token)) return $failure(['form' => 'Security check failed. Reload this page and try again.']);
    $validated = admin_account_validate($input);
    if ($validated['errors']) return $failure($validated['errors']);
    $action = $validated['action']; $values = $validated['values'];
    $id = (int)$_SESSION['admin_user_id']; $pdo = get_db_connection();
    if (!$pdo) return $failure(['form' => 'Account settings are temporarily unavailable.']);
    $newAvatar = ''; $oldAvatar = ''; $revoke = false; $committed = false;
    try {
        if (!admin_account_schema_ready($pdo)) return $failure(['form' => 'Account settings setup is not complete. Please contact the site administrator.']);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, failed_attempts, locked_until FROM admin_users WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]); $user = $stmt->fetch();
        if (!$user || !isset($_SESSION['admin_password_hash_sig']) || !hash_equals($_SESSION['admin_password_hash_sig'], hash('sha256', $user['password_hash']))) {
            $pdo->rollBack(); return $failure(['form' => 'Your session has expired. Please sign in again.'], true);
        }
        $stmt = $pdo->prepare('INSERT INTO admin_account_profiles (admin_id) VALUES (:id) ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id)');
        $stmt->execute([':id' => $id]);
        $stmt = $pdo->prepare('SELECT display_name, email, avatar_filename, density, reduce_motion, auth_version FROM admin_account_profiles WHERE admin_id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]); $profile = $stmt->fetch();
        if (!$profile || (int)$profile['auth_version'] !== (int)($_SESSION['admin_account_version'] ?? 0)) {
            $pdo->rollBack(); return $failure(['form' => 'Your session has expired. Please sign in again.'], true);
        }
        if (in_array($action, ['identity', 'password'], true)) {
            if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                $pdo->rollBack(); return $failure(['current_password' => 'Reauthentication is temporarily locked. Please try again later.']);
            }
            if (!password_verify(admin_account_secret($input, 'current_password'), $user['password_hash'])) {
                $failed = (int)$user['failed_attempts'] + 1;
                $stmt = $pdo->prepare('UPDATE admin_users SET failed_attempts = :failed, locked_until = :locked_until, updated_at = updated_at WHERE id = :id');
                $stmt->execute([':failed' => $failed, ':locked_until' => $failed >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, ':id' => $id]);
                admin_account_audit($pdo, $id, 'ADMIN_REAUTH_FAILED', ['temporarily_locked' => $failed >= 5]);
                $pdo->commit();
                return $failure(['current_password' => $failed >= 5 ? 'Reauthentication is temporarily locked. Please try again later.' : 'Your current password was not accepted.']);
            }
            $stmt = $pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, updated_at = updated_at WHERE id = :id');
            $stmt->execute([':id' => $id]);
        }
        if ($action === 'profile') {
            $hasUpload = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($values['remove_avatar'] && $hasUpload) { $pdo->rollBack(); return $failure(['avatar' => 'Choose a new photograph or remove the current one, not both.']); }
            if ($hasUpload) {
                try { $newAvatar = admin_account_store_avatar($file); }
                catch (RuntimeException $e) { $pdo->rollBack(); return $failure(['avatar' => $e->getMessage()]); }
            }
            $oldAvatar = (string)($profile['avatar_filename'] ?? '');
            $avatar = $newAvatar ?: ($values['remove_avatar'] ? null : ($oldAvatar ?: null));
            $stmt = $pdo->prepare('UPDATE admin_account_profiles SET display_name = :name, avatar_filename = :avatar WHERE admin_id = :id');
            $stmt->execute([':name' => $values['display_name'], ':avatar' => $avatar, ':id' => $id]);
            $changedFields = [];
            if ($values['display_name'] !== (string)$profile['display_name']) $changedFields[] = 'display_name';
            if ((string)$avatar !== $oldAvatar) $changedFields[] = 'avatar';
            admin_account_audit($pdo, $id, 'ADMIN_PROFILE_UPDATED', ['fields' => $changedFields]);
        } elseif ($action === 'identity') {
            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = :username AND id <> :id LIMIT 1');
            $stmt->execute([':username' => $values['username'], ':id' => $id]);
            if ($stmt->fetch()) { $pdo->commit(); return $failure(['username' => 'That username is already in use.']); }
            $changedFields = [];
            if ($values['username'] !== $user['username']) $changedFields[] = 'username';
            if ($values['email'] !== (string)($profile['email'] ?? '')) $changedFields[] = 'email';
            $revoke = (bool)$changedFields;
            if ($revoke) {
                $stmt = $pdo->prepare('UPDATE admin_users SET username = :username, updated_at = NOW() WHERE id = :id');
                $stmt->execute([':username' => $values['username'], ':id' => $id]);
                $stmt = $pdo->prepare('UPDATE admin_account_profiles SET email = :email, auth_version = auth_version + 1 WHERE admin_id = :id');
                $stmt->execute([':email' => $values['email'] ?: null, ':id' => $id]);
                admin_account_audit($pdo, $id, 'ADMIN_IDENTITY_UPDATED', ['fields' => $changedFields, 'sessions_revoked' => true]);
            }
        } elseif ($action === 'password') {
            $new = admin_account_secret($input, 'new_password');
            if (password_verify($new, $user['password_hash'])) { $pdo->commit(); return $failure(['new_password' => 'Choose a password different from your current password.']); }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = :hash, updated_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = :id');
            $stmt->execute([':hash' => $hash, ':id' => $id]);
            $stmt = $pdo->prepare('UPDATE admin_account_profiles SET auth_version = auth_version + 1 WHERE admin_id = :id'); $stmt->execute([':id' => $id]);
            admin_account_audit($pdo, $id, 'ADMIN_PASSWORD_CHANGED', ['sessions_revoked' => true]);
            $revoke = true;
        } else {
            $stmt = $pdo->prepare('UPDATE admin_account_profiles SET density = :density, reduce_motion = :motion WHERE admin_id = :id');
            $stmt->execute([':density' => $values['density'], ':motion' => $values['reduce_motion'], ':id' => $id]);
            admin_account_audit($pdo, $id, 'ADMIN_PREFERENCES_UPDATED', ['density' => $values['density'], 'reduce_motion' => (bool)$values['reduce_motion']]);
        }
        $pdo->commit();
        $committed = true;
        if ($oldAvatar) admin_account_cleanup_avatar($pdo, $oldAvatar);
        if (!$revoke) {
            // A concurrent credential rotation must never refresh this session to its new version.
            if (!admin_account_validate_session($pdo, $id)) return ['success' => true, 'errors' => [], 'reauthenticate' => true];
        }
        return ['success' => true, 'errors' => [], 'reauthenticate' => $revoke];
    } catch (Throwable $e) {
        if ($committed) { error_log('Account changes saved; session refresh unavailable.'); return ['success' => true, 'errors' => [], 'reauthenticate' => true]; }
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $rollbackError) { error_log('Account transaction outcome requires local review.'); }
        if ($newAvatar) admin_account_cleanup_avatar($pdo, $newAvatar);
        error_log('Account settings update failed.');
        if ($action === 'identity' && $e instanceof PDOException && $e->getCode() === '23000') return $failure(['username' => 'That username is already in use.']);
        return $failure(['form' => 'Your account settings could not be saved. Please try again.']);
    }
}
