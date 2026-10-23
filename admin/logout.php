<?php
/**
 * TechTalk Admin Logout (CSRF-Protected)
 * Strictly requires a POST request with a valid session CSRF token.
 * Rejects GET requests without terminating the administrator session.
 */

define('TECHTALK_INIT', true);
require_once dirname(__DIR__) . '/includes/auth.php';

init_admin_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (csrf_validate($token)) {
        admin_logout();
        header("Location: login.php?msg=logged_out");
        exit;
    } else {
        // Invalid or missing CSRF token: reject logout without terminating session
        if (admin_is_authenticated()) {
            header("Location: index.php?error=csrf_invalid");
            exit;
        } else {
            header("Location: login.php?error=csrf_invalid");
            exit;
        }
    }
}

// Reject GET logout requests: preserve active session
if (admin_is_authenticated()) {
    header("Location: index.php?notice=logout_post_required");
    exit;
} else {
    header("Location: login.php");
    exit;
}
