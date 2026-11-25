<?php
/**
 * ShopTrack - General Helper Functions & Utilities
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

/**
 * Escape HTML output for XSS prevention
 */
function h(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency consistently (Default INR ₹1,250.00)
 */
function formatCurrency(float|int|string|null $amount, string $currency = 'INR'): string {
    $num = (float)($amount ?? 0);
    $formatted = number_format($num, 2, '.', ',');
    if ($currency === 'INR' || $currency === '₹') {
        return '₹' . $formatted;
    }
    return $currency . ' ' . $formatted;
}

/**
 * Format date/time readable
 */
function formatDate(?string $datetime, string $format = 'd M Y, h:i A'): string {
    if (empty($datetime)) {
        return '—';
    }
    try {
        $dt = new DateTime($datetime);
        return $dt->format($format);
    } catch (Exception) {
        return (string)$datetime;
    }
}

/**
 * Emit JSON response and exit
 */
function jsonResponse(mixed $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emit structured JSON error response
 */
function jsonError(string $message, int $statusCode = 400, mixed $errors = null): void {
    $payload = [
        'success' => false,
        'error' => $message,
    ];
    if ($errors !== null) {
        $payload['errors'] = $errors;
    }
    jsonResponse($payload, $statusCode);
}

/**
 * Generate a cryptographically secure Version 4 UUID string
 */
function generateUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Record a security or business audit log
 */
function logAudit(
    PDO $db,
    string $shopId,
    ?string $userId,
    string $action,
    string $entityType,
    ?string $entityId = null,
    ?array $details = null
): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (id, shop_id, user_id, action, entity_type, entity_id, details)
            VALUES (:id, :shop_id, :user_id, :action, :entity_type, :entity_id, :details)
        ");
        $stmt->execute([
            ':id' => generateUuid(),
            ':shop_id' => $shopId,
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
        ]);
        $stmt->closeCursor();
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

/**
 * Record stock movement audit entry
 */
function recordStockMovement(
    PDO $db,
    string $shopId,
    string $productId,
    ?string $userId,
    string $movementType,
    int $quantityChange,
    int $prevQuantity,
    int $newQuantity,
    ?string $reason = null,
    ?string $referenceType = null,
    ?string $referenceId = null
): void {
    $stmt = $db->prepare("
        INSERT INTO stock_movements (
            id, shop_id, product_id, user_id, movement_type,
            quantity_change, previous_quantity, new_quantity,
            reason, reference_type, reference_id
        ) VALUES (
            :id, :shop_id, :product_id, :user_id, :movement_type,
            :quantity_change, :previous_quantity, :new_quantity,
            :reason, :reference_type, :reference_id
        )
    ");
    $stmt->execute([
        ':id' => generateUuid(),
        ':shop_id' => $shopId,
        ':product_id' => $productId,
        ':user_id' => $userId,
        ':movement_type' => $movementType,
        ':quantity_change' => $quantityChange,
        ':previous_quantity' => $prevQuantity,
        ':new_quantity' => $newQuantity,
        ':reason' => $reason,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId,
    ]);
    $stmt->closeCursor();
}

/**
 * Generate a clean sequential unique invoice number for the shop
 */
function generateInvoiceNumber(PDO $db, string $shopId): string {
    $datePrefix = date('Ymd');
    $prefix = "INV-{$datePrefix}-";

    // Count today's sales for this shop
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM sales 
        WHERE shop_id = :shop_id 
          AND invoice_number LIKE :prefix
    ");
    $stmt->execute([
        ':shop_id' => $shopId,
        ':prefix' => $prefix . '%'
    ]);
    $count = (int)$stmt->fetchColumn() + 1;
    $stmt->closeCursor();
    $seq = str_pad((string)$count, 4, '0', STR_PAD_LEFT);
    $candidate = $prefix . $seq;

    // Verify uniqueness
    $checkStmt = $db->prepare("SELECT 1 FROM sales WHERE shop_id = :shop_id AND invoice_number = :inv");
    $checkStmt->execute([':shop_id' => $shopId, ':inv' => $candidate]);
    if ($checkStmt->fetch()) {
        // Fallback to random hex suffix if collision
        $candidate .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
    $checkStmt->closeCursor();
    return $candidate;
}

/**
 * Get shop settings/profile
 */
function getShopDetails(PDO $db, string $shopId): ?array {
    $stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $shopId]);
    $res = $stmt->fetch() ?: null;
    $stmt->closeCursor();
    return $res;
}

/**
 * Get Stock Status text and badge CSS class
 */
function getStockBadge(int $quantity, int $minLevel): array {
    if ($quantity <= 0) {
        return [
            'label' => 'Out of Stock',
            'class' => 'badge-danger',
        ];
    }
    if ($quantity <= $minLevel) {
        return [
            'label' => 'Low Stock',
            'class' => 'badge-warning',
        ];
    }
    return [
        'label' => 'In Stock',
        'class' => 'badge-success',
    ];
}

/**
 * Safely parse JSON request body
 */
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
