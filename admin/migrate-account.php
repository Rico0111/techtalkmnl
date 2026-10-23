<?php
/** Read-only preflight by default; explicit --apply creates only the additive UI-3 table. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI access only.'); }
define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/account.php';
$mode = $argv[1] ?? '--check';
if ($argc > 2 || !in_array($mode, ['--check', '--apply'], true)) { fwrite(STDERR, "Usage: php admin/migrate-account.php [--check|--apply]\n"); exit(1); }
$config = dirname(__DIR__) . '/config.local.php';
if (is_file($config)) require_once $config;
$host = getenv('TECHTALK_DB_HOST') ?: (defined('TECHTALK_DB_HOST') ? TECHTALK_DB_HOST : '127.0.0.1');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || in_array(strtolower(getenv('TECHTALK_ENV') ?: ''), ['production', 'prod'], true)) { fwrite(STDERR, "This migration is restricted to a localhost development database.\n"); exit(1); }
$pdo = get_db_connection();
if (!$pdo) { fwrite(STDERR, "Database unavailable; no migration applied.\n"); exit(2); }
try {
    $columns = $pdo->query('SHOW COLUMNS FROM admin_users')->fetchAll();
    $byName = array_column($columns, null, 'Field');
    foreach (['id','username','password_hash','failed_attempts','locked_until','created_at','updated_at'] as $field) {
        if (!isset($byName[$field])) throw new RuntimeException('Unexpected admin schema; migration was not applied.');
    }
    if (!preg_match('/\Aint(?:\(\d+\))?\z/i', $byName['id']['Type']) || $byName['id']['Key'] !== 'PRI') throw new RuntimeException('The account ID type differs from the reviewed signed-INT schema; migration was not applied.');
    if (strtolower($byName['username']['Type']) !== 'varchar(50)' || strtolower($byName['password_hash']['Type']) !== 'varchar(255)') throw new RuntimeException('Credential field types differ from the reviewed schema; migration was not applied.');
    $stmt = $pdo->query('SHOW INDEX FROM admin_users'); $indexes = [];
    foreach ($stmt->fetchAll() as $index) if ((int)$index['Non_unique'] === 0) $indexes[$index['Key_name']][] = $index['Column_name'];
    if (!in_array(['username'], $indexes, true)) throw new RuntimeException('A unique username index is required; migration was not applied.');
    $auditFields = array_column($pdo->query('SHOW COLUMNS FROM product_audit_log')->fetchAll(), null, 'Field');
    foreach (['id','admin_id','product_id','action','details_json','created_at'] as $field) if (!isset($auditFields[$field])) throw new RuntimeException('The audit schema differs from the reviewed baseline; no migration applied.');
    $stmt = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('admin_users', 'product_audit_log')");
    $engines = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($engines) !== 2 || array_filter($engines, fn($engine) => strtoupper($engine) !== 'INNODB')) throw new RuntimeException('Transactional account/audit tables are required; migration was not applied.');
    if (admin_account_schema_ready($pdo)) { echo "Account settings schema is already available; no changes made.\n"; exit; }
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_account_profiles'");
    if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('A partial account settings table exists; review it before migrating. No changes made.');
    echo "Live base schema preflight passed.\n";
    if ($mode === '--check') { echo "Pending additive migration. Run with --apply to create admin_account_profiles. No changes made.\n"; exit; }
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/20261022_admin_account_profiles.sql'));
    if (!admin_account_schema_ready($pdo, true)) throw new RuntimeException('Migration verification failed; review database state.');
    echo "Account settings table created. Existing users, credentials, roles, inventory and audit records preserved.\n";
} catch (Throwable $e) {
    error_log('Account settings migration preflight/apply failed.');
    fwrite(STDERR, $e instanceof PDOException ? "Database operation failed. Review the local database state; no reset was attempted.\n" : $e->getMessage() . "\n");
    exit(1);
}
