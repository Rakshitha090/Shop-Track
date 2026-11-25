<?php
/**
 * ShopTrack - Stock Adjustments API Endpoint
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireAuth();

$shopId = Auth::shopId();
$userId = Auth::id();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Security verification failed. Please refresh your session.', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $productId = trim($input['product_id'] ?? '');
        $adjustmentType = trim($input['type'] ?? 'add'); // 'add' or 'remove'
        $quantity = (int)($input['quantity'] ?? 0);
        $reason = trim($input['reason'] ?? '');

        if (empty($productId)) {
            jsonError('Please select a valid product.');
        }

        if ($quantity <= 0) {
            jsonError('Adjustment quantity must be greater than zero.');
        }

        if (empty($reason)) {
            jsonError('Please provide a reason for this stock adjustment.');
        }

        $db->beginTransaction();

        // Lock product
        $forUpdate = (Database::getDriver() === 'pgsql') ? ' FOR UPDATE' : '';
        $pStmt = $db->prepare("
            SELECT id, name, sku, stock_quantity 
            FROM products 
            WHERE id = :id AND shop_id = :shop_id 
            {$forUpdate}
        ");
        $pStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
        $product = $pStmt->fetch();
        $pStmt->closeCursor();

        if (!$product) {
            $db->rollBack();
            jsonError('Product not found in your shop catalog.', 404);
        }

        $prevStock = (int)$product['stock_quantity'];

        if ($adjustmentType === 'remove') {
            if ($prevStock < $quantity) {
                $db->rollBack();
                jsonError("Cannot remove {$quantity} units. Current stock is only {$prevStock}. Negative stock is prevented.", 400);
            }
            $newStock = $prevStock - $quantity;
            $qtyChange = -$quantity;
            $movementType = 'adjustment_remove';
        } else {
            $newStock = $prevStock + $quantity;
            $qtyChange = $quantity;
            $movementType = 'adjustment_add';
        }

        // Update product stock
        $upStmt = $db->prepare("UPDATE products SET stock_quantity = :stock WHERE id = :id AND shop_id = :shop_id");
        $upStmt->execute([':stock' => $newStock, ':id' => $productId, ':shop_id' => $shopId]);
        $upStmt->closeCursor();

        // Record stock movement
        recordStockMovement(
            $db,
            $shopId,
            $productId,
            $userId,
            $movementType,
            $qtyChange,
            $prevStock,
            $newStock,
            $reason,
            'manual_adjustment',
            $productId
        );

        // Audit log
        logAudit($db, $shopId, $userId, 'stock_adjustment', 'products', $productId, [
            'product_name' => $product['name'],
            'type' => $movementType,
            'change' => $qtyChange,
            'prev' => $prevStock,
            'new' => $newStock,
            'reason' => $reason
        ]);

        $db->commit();

        jsonResponse([
            'success' => true,
            'message' => "Stock for '{$product['name']}' adjusted successfully. New stock: {$newStock} units.",
            'new_stock' => $newStock
        ]);
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonError('Stock adjustment failed: ' . $e->getMessage(), 500);
}
