<?php
/** Shared response hardening for TechTalk HTML and image endpoints. */
if (!defined('TECHTALK_INIT')) {
    http_response_code(403);
    exit;
}

function techtalk_request_is_https(): bool {
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($proto)) {
        $first = strtolower(trim(explode(',', $proto)[0]));
        if ($first === 'https') return true;
    }
    return false;
}

function send_security_headers(): void {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
    if (techtalk_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
