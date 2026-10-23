<?php
/** Public presentation asset identities; independent of Git and deployment timestamps. */
if (!defined('TECHTALK_INIT')) {
    exit;
}

function techtalk_public_asset_hashes(): array {
    static $hashes = null;
    if ($hashes !== null) return $hashes;

    $hashes = [];
    foreach (['style.css', 'storefront.css', 'assets/product-gallery.js', 'config.js',
              'assets/reviews-model.js', 'script.js', 'motion.js', 'storefront.js'] as $path) {
        $file = dirname(__DIR__) . '/' . $path;
        // A partial upload must not silently reuse a previously valid asset identity.
        $hashes[$path] = is_readable($file) ? (hash_file('sha256', $file) ?: 'missing') : 'missing';
    }
    return $hashes;
}

function techtalk_public_asset_url(string $path): string {
    $hashes = techtalk_public_asset_hashes();
    if (!isset($hashes[$path])) throw new InvalidArgumentException('Unknown public asset.');
    return $path . '?v=' . substr($hashes[$path], 0, 16);
}

function techtalk_public_ui_version(): string {
    static $version = null;
    if ($version === null) {
        $identity = [hash_file('sha256', dirname(__DIR__) . '/index.php'),
                     hash_file('sha256', __FILE__), techtalk_public_asset_hashes()];
        $version = substr(hash('sha256', json_encode($identity)), 0, 16);
    }
    return $version;
}
