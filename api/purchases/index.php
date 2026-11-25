<?php
/**
 * ShopTrack - Purchases & Stock-In API Endpoint
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

    // -------------------------------------------------------------------------
    // GET: Purchase Details with Items
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        $purchaseId = $_GET['id'] ?? null;
        if (empty($purchaseId)) {
            jsonError('Purchase ID is required');
        }

        $stmt = $db->prepare("
            SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone, u.full_name AS received_by_name
            FROM purchases p
            JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = :id AND p.shop_id = :shop_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $purchaseId, ':shop_id' => $shopId]);
        $purchase = $stmt->fetch();

        if (!$purchase) {
            jsonError('Purchase record not found', 404);
        }

        $itemStmt = $db->prepare("
            SELECT pi.*, pr.name AS product_name, pr.sku AS product_sku
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            WHERE pi.purchase_id = :pid
            ORDER BY pi.created_at ASC
        ");
        $itemStmt->execute([':pid' => $purchaseId]);
        $items = $itemStmt->fetchAll();

        jsonResponse([
            'success' => true,
            'purchase' => $purchase,
            'items' => $items
        ]);
    }

    // -------------------------------------------------------------------------
    // POST: Create & Receive Purchase (Atomic Transaction)
    // -------------------------------------------------------------------------
    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Security verification failed. Please refresh your session.', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $supplierId = trim($input['supplier_id'] ?? '');
        $supplierInvoice = trim($input['supplier_invoice_number'] ?? '');
        $paymentStatus = trim($input['payment_status'] ?? 'paid');
        $notes = trim($input['notes'] ?? '');
        $items = $input['items'] ?? [];

        if (empty($supplierId)) {
            jsonError('Please select a supplier.');
        }

        if (empty($items) || !is_array($items)) {
            jsonError('Please add at least one item to this purchase order.');
        }

        // Verify supplier
        $supCheck = $db->prepare("SELECT id, name FROM suppliers WHERE id = :id AND shop_id = :shop_id");
        $supCheck->execute([':id' => $supplierId, ':shop_id' => $shopId]);
        $supplier = $supCheck->fetch();
        $supCheck->closeCursor();
        if (!$supplier) {
            jsonError('Selected supplier is invalid or unauthorized.');
        }

        $db->beginTransaction();

        $totalAmount = 0.00;
        $processedItems = [];

        foreach ($items as $item) {
            $productId = trim($item['product_id'] ?? '');
            $quantity = (int)($item['quantity'] ?? 0);
            $unitCost = (float)($item['unit_cost'] ?? 0);

            if (empty($productId) || $quantity <= 0 || $unitCost < 0) {
                $db->rollBack();
                jsonError('Invalid product, quantity, or unit cost entered in purchase line items.');
            }

            // Lock product row
            $forUpdate = (Database::getDriver() === 'pgsql') ? ' FOR UPDATE' : '';
            $pStmt = $db->prepare("
                SELECT id, name, sku, stock_quantity, purchase_price 
                FROM products 
                WHERE id = :id AND shop_id = :shop_id 
                {$forUpdate}
            ");
            $pStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
            $prod = $pStmt->fetch();
            $pStmt->closeCursor();

            if (!$prod) {
                $db->rollBack();
                jsonError('Product not found in your shop catalog.');
            }

            $lineTotal = round($unitCost * $quantity, 2);
            $totalAmount += $lineTotal;

            $prevStock = (int)$prod['stock_quantity'];
            $newStock = $prevStock + $quantity;

            $processedItems[] = [
                'product_id' => $prod['id'],
                'name' => $prod['name'],
                'sku' => $prod['sku'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'prev_stock' => $prevStock,
                'new_stock' => $newStock,
            ];
        }

        $totalAmount = round($totalAmount, 2);

        // Insert Purchase Record
        $purchaseId = generateUuid();
        $now = date('Y-m-d H:i:s');
        $purchaseStmt = $db->prepare("
            INSERT INTO purchases (
                id, shop_id, supplier_id, user_id, supplier_invoice_number,
                total_amount, payment_status, status, notes, received_at
            ) VALUES (
                :id, :shop_id, :supplier_id, :user_id, :supplier_invoice,
                :total_amount, :payment_status, 'received', :notes, :received_at
            )
        ");
        $purchaseStmt->execute([
            ':id' => $purchaseId,
            ':shop_id' => $shopId,
            ':supplier_id' => $supplierId,
            ':user_id' => $userId,
            ':supplier_invoice' => $supplierInvoice ?: null,
            ':total_amount' => $totalAmount,
            ':payment_status' => $paymentStatus,
            ':notes' => $notes ?: null,
            ':received_at' => $now,
        ]);
        $purchaseStmt->closeCursor();

        // Insert Line Items and Increase Stock Atomically
        $itemStmt = $db->prepare("
            INSERT INTO purchase_items (id, purchase_id, product_id, quantity, unit_cost, line_total)
            VALUES (:id, :purchase_id, :product_id, :quantity, :unit_cost, :line_total)
        ");

        $stockUpd = $db->prepare("
            UPDATE products 
            SET stock_quantity = :new_stock, purchase_price = :unit_cost 
            WHERE id = :id AND shop_id = :shop_id
        ");

        foreach ($processedItems as $it) {
            $itemStmt->execute([
                ':id' => generateUuid(),
                ':purchase_id' => $purchaseId,
                ':product_id' => $it['product_id'],
                ':quantity' => $it['quantity'],
                ':unit_cost' => $it['unit_cost'],
                ':line_total' => $it['line_total'],
            ]);

            // Update product stock and update latest unit purchase cost
            $stockUpd->execute([
                ':new_stock' => $it['new_stock'],
                ':unit_cost' => $it['unit_cost'],
                ':id' => $it['product_id'],
                ':shop_id' => $shopId,
            ]);

            // Record stock movement
            recordStockMovement(
                $db,
                $shopId,
                $it['product_id'],
                $userId,
                'purchase',
                $it['quantity'],
                $it['prev_stock'],
                $it['new_stock'],
                "Supplier Purchase stock-in (" . ($supplierInvoice ? "Bill #{$supplierInvoice}" : "Supplier: {$supplier['name']}") . ")",
                'purchase',
                $purchaseId
            );
        }

        $itemStmt->closeCursor();
        $stockUpd->closeCursor();

        // Audit Log
        logAudit($db, $shopId, $userId, 'create_purchase', 'purchases', $purchaseId, [
            'supplier_name' => $supplier['name'],
            'total' => $totalAmount,
            'items_count' => count($processedItems)
        ]);

        $db->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Stock purchase received and inventory updated successfully!',
            'purchase_id' => $purchaseId
        ]);
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonError('Failed to record stock purchase: ' . $e->getMessage(), 500);
}
