-- UI-3 additive migration. Use admin/migrate-account.php to verify live prerequisites first.
-- No administrator, password, role, product or audit records are rewritten.
CREATE TABLE IF NOT EXISTS admin_account_profiles (
    admin_id INT NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(254) NULL,
    avatar_filename VARCHAR(80) NULL,
    density ENUM('comfortable', 'compact') NOT NULL DEFAULT 'comfortable',
    reduce_motion TINYINT(1) NOT NULL DEFAULT 0,
    auth_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL COMMENT 'UTC; recorded only after successful login',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id),
    UNIQUE KEY uq_admin_avatar (avatar_filename),
    CONSTRAINT fk_account_profile_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
