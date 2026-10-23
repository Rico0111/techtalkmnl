<?php
/**
 * TechTalk Database Connection Singleton
 * Secure PDO connection with utf8mb4 and prepared statement enforcement.
 */

if (!defined('TECHTALK_INIT')) {
    define('TECHTALK_INIT', true);
}

function get_db_connection(): ?PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // Credentials can live outside public_html (recommended on Hostinger) or in
    // the project root for local development. The first readable file wins.
    $configCandidates = [];
    $envConfig = getenv('TECHTALK_CONFIG_FILE');
    if (is_string($envConfig) && $envConfig !== '') $configCandidates[] = $envConfig;
    $configCandidates[] = dirname(__DIR__, 2) . '/private/config.local.php';
    $configCandidates[] = dirname(__DIR__) . '/config.local.php';
    foreach (array_unique($configCandidates) as $localConfigFile) {
        if (is_file($localConfigFile) && is_readable($localConfigFile)) {
            require_once $localConfigFile;
            break;
        }
    }

    // Evaluate after config loading so config.local.php can putenv('TECHTALK_ENV=production').
    $environment = getenv('TECHTALK_ENV') ?: 'development';
    $isProduction = in_array(strtolower($environment), ['production', 'prod'], true);

    $host = getenv('TECHTALK_DB_HOST') ?: (defined('TECHTALK_DB_HOST') ? constant('TECHTALK_DB_HOST') : null);
    $port = getenv('TECHTALK_DB_PORT') ?: (defined('TECHTALK_DB_PORT') ? constant('TECHTALK_DB_PORT') : '3306');
    $dbname = getenv('TECHTALK_DB_NAME') ?: (defined('TECHTALK_DB_NAME') ? constant('TECHTALK_DB_NAME') : null);
    $user = getenv('TECHTALK_DB_USER') ?: (defined('TECHTALK_DB_USER') ? constant('TECHTALK_DB_USER') : null);
    $pass = getenv('TECHTALK_DB_PASS') !== false ? getenv('TECHTALK_DB_PASS') : (defined('TECHTALK_DB_PASS') ? constant('TECHTALK_DB_PASS') : null);

    if ($isProduction) {
        // Enforce strict production requirements: refuse default/missing credentials
        if (empty($host) || empty($dbname) || empty($user) || $pass === null) {
            error_log("TechTalk DB Error: Production requires explicit non-empty host, dbname, user, and password.");
            return null;
        }
        if (strtolower($user) === 'root' || $pass === '') {
            error_log("TechTalk DB Error: Production requires a non-root database account with a non-empty password.");
            return null;
        }
    } else {
        // Safe localhost development fallbacks
        $host = $host ?: '127.0.0.1';
        $dbname = $dbname ?: 'techtalk_db';
        $user = $user ?: 'root';
        $pass = $pass !== null ? $pass : '';
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Log error securely without exposing credentials or internal traces to visitors
        error_log("TechTalk DB Connection Failure: " . $e->getMessage());
        return null;
    }
}
