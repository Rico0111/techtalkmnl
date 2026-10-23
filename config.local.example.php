<?php
/**
 * TechTalk Production / Local Configuration Template
 * Copy to config.local.php (or configure environment variables via web host control panel).
 * DO NOT commit actual secrets to version control.
 */

if (!defined('TECHTALK_INIT')) {
    exit;
}

// Runtime environment ('development' | 'production')
// Production package config sets this before loading DB credentials.
// putenv('TECHTALK_ENV=production');

// Database Configuration
// define('TECHTALK_DB_HOST', '127.0.0.1');
// define('TECHTALK_DB_PORT', '3306');
// define('TECHTALK_DB_NAME', 'your_database_name');
// define('TECHTALK_DB_USER', 'your_database_user');
// define('TECHTALK_DB_PASS', 'your_database_password');

// Optional session cookie path override. Root-domain deployment auto-detects '/'.
// define('TECHTALK_COOKIE_PATH', '/');
