<?php
/**
 * TechTalk Product Audit Logger
 * Records administrative catalog modifications.
 */

require_once __DIR__ . '/db.php';

function log_product_audit(?int $admin_id, ?int $product_id, string $action, array $details = []): bool {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `product_audit_log` (`admin_id`, `product_id`, `action`, `details_json`, `created_at`)
            VALUES (:admin_id, :product_id, :action, :details, NOW())
        ");
        return $stmt->execute([
            ':admin_id'   => $admin_id,
            ':product_id' => $product_id,
            ':action'     => substr(trim($action), 0, 50),
            ':details'    => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    } catch (PDOException $e) {
        error_log("Audit log failure: " . $e->getMessage());
        return false;
    }
}
