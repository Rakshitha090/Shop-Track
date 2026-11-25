<?php
/**
 * ShopTrack - Sales & Checkout API Endpoint
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
    // GET: Fetch Sale Details / Invoice
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        $saleId = $_GET['id'] ?? null;
        $invoiceNo = $_GET['invoice'] ?? null;

        if ($saleId || $invoiceNo) {
            $sql = "
                SELECT s.*, u.full_name AS cashier_name, sh.name AS shop_name, sh.phone AS shop_phone, sh.address AS shop_address
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                JOIN shops sh ON s.shop_id = sh.id
                WHERE s.shop_id = :shop_id
            ";
            $params = [':shop_id' => $shopId];

            if ($saleId) {
                $sql .= " AND s.id = :id";
                $params[':id'] = $saleId;
            } else {
                $sql .= " AND s.invoice_number = :inv";
                $params[':inv'] = $invoiceNo;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $sale = $stmt->fetch();

            if (!$sale) {
                jsonError('Sale invoice not found or unauthorized', 404);
            }

            // Fetch sale items
            $itemStmt = $db->prepare("
                SELECT * FROM sale_items 
                WHERE sale_id = :sale_id 
                ORDER BY created_at ASC
            ");
            $itemStmt->execute([':sale_id' => $sale['id']]);
            $items = $itemStmt->fetchAll();

            jsonResponse([
                'success' => true,
                'sale' => $sale,
                'items' => $items
            ]);
        }

        jsonError('Sale ID or invoice number is required');
    }

    // -------------------------------------------------------------------------
    // POST: Create Sale (POS Checkout) or Cancel Sale
    // -------------------------------------------------------------------------
    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Security verification failed. Please refresh your session.', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $action = $input['action'] ?? 'checkout';

        // =====================================================================
        // ACTION: CANCEL / REFUND SALE (REVERSAL WORKFLOW)
        // =====================================================================
        $forUpdate = (Database::getDriver() === 'pgsql') ? ' FOR UPDATE' : '';

        if ($action === 'cancel') {
            $saleId = $input['sale_id'] ?? '';
            $reason = trim($input['reason'] ?? 'Customer return / order cancelled');

            if (empty($saleId)) {
                jsonError('Sale ID is required for cancellation');
            }

            $db->beginTransaction();

            $saleStmt = $db->prepare("
                SELECT * FROM sales 
                WHERE id = :id AND shop_id = :shop_id {$forUpdate}
            ");
            $saleStmt->execute([':id' => $saleId, ':shop_id' => $shopId]);
            $sale = $saleStmt->fetch();
            $saleStmt->closeCursor();

            if (!$sale) {
                $db->rollBack();
                jsonError('Sale not found', 404);
            }

            if ($sale['status'] === 'cancelled') {
                $db->rollBack();
                jsonError('This sale is already cancelled', 400);
            }

            // Fetch line items
            $itemStmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = :sale_id");
            $itemStmt->execute([':sale_id' => $saleId]);
            $items = $itemStmt->fetchAll();
            $itemStmt->closeCursor();

            // Reverse stock for each product
            foreach ($items as $item) {
                $pStmt = $db->prepare("
                    SELECT id, stock_quantity 
                    FROM products 
                    WHERE id = :id AND shop_id = :shop_id {$forUpdate}
                ");
                $pStmt->execute([':id' => $item['product_id'], ':shop_id' => $shopId]);
                $prod = $pStmt->fetch();
                $pStmt->closeCursor();

                if ($prod) {
                    $prevStock = (int)$prod['stock_quantity'];
                    $newStock = $prevStock + (int)$item['quantity'];

                    $upd = $db->prepare("UPDATE products SET stock_quantity = :stock WHERE id = :id");
                    $upd->execute([':stock' => $newStock, ':id' => $prod['id']]);
                    $upd->closeCursor();

                    recordStockMovement(
                        $db,
                        $shopId,
                        $prod['id'],
                        $userId,
                        'sale_cancellation',
                        (int)$item['quantity'],
                        $prevStock,
                        $newStock,
                        "Sale cancellation for invoice {$sale['invoice_number']}: {$reason}",
                        'sale',
                        $sale['id']
                    );
                }
            }

            // Mark sale as cancelled
            $cancelStmt = $db->prepare("UPDATE sales SET status = 'cancelled' WHERE id = :id");
            $cancelStmt->execute([':id' => $saleId]);
            $cancelStmt->closeCursor();

            logAudit($db, $shopId, $userId, 'cancel_sale', 'sales', $saleId, [
                'invoice' => $sale['invoice_number'],
                'amount' => $sale['total_amount'],
                'reason' => $reason
            ]);

            $db->commit();
            jsonResponse([
                'success' => true,
                'message' => "Sale {$sale['invoice_number']} cancelled and stock restored successfully."
            ]);
        }

        // =====================================================================
        // ACTION: CHECKOUT / CREATE SALE
        // =====================================================================
        if ($action === 'checkout') {
            $cart = $input['cart'] ?? [];
            $paymentMethod = trim($input['payment_method'] ?? 'Cash');
            $discountAmount = max(0, (float)($input['discount_amount'] ?? 0));
            $amountReceived = max(0, (float)($input['amount_received'] ?? 0));
            $customerName = trim($input['customer_name'] ?? '');
            $customerPhone = trim($input['customer_phone'] ?? '');

            if (empty($cart) || !is_array($cart)) {
                jsonError('Your checkout cart is empty. Please add products to proceed.');
            }

            $allowedPayments = ['Cash', 'UPI', 'Card', 'Other'];
            if (!in_array($paymentMethod, $allowedPayments, true)) {
                $paymentMethod = 'Cash';
            }

            $db->beginTransaction();

            $subtotal = 0.00;
            $processedItems = [];

            // Validate every item, lock product row with FOR UPDATE, and verify stock
            foreach ($cart as $item) {
                $productId = $item['product_id'] ?? '';
                $quantity = (int)($item['quantity'] ?? 0);

                if (empty($productId) || $quantity <= 0) {
                    $db->rollBack();
                    jsonError('Invalid product or quantity specified.');
                }

                // Lock product record for update to prevent race conditions & overselling
                $stmt = $db->prepare("
                    SELECT id, name, sku, purchase_price, selling_price, stock_quantity, archived_at
                    FROM products
                    WHERE id = :id AND shop_id = :shop_id
                    {$forUpdate}
                ");
                $stmt->execute([':id' => $productId, ':shop_id' => $shopId]);
                $product = $stmt->fetch();
                $stmt->closeCursor();

                if (!$product) {
                    $db->rollBack();
                    jsonError("Product not found in your shop catalog.");
                }

                if ($product['archived_at'] !== null) {
                    $db->rollBack();
                    jsonError("Product '{$product['name']}' has been archived and is not available for sale.");
                }

                $availableStock = (int)$product['stock_quantity'];
                if ($availableStock < $quantity) {
                    $db->rollBack();
                    jsonError("Insufficient stock for '{$product['name']}'. Requested: {$quantity}, Available: {$availableStock}.");
                }

                $unitPrice = (float)$product['selling_price'];
                $unitCost = (float)$product['purchase_price'];
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $processedItems[] = [
                    'product_id' => $product['id'],
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'prev_stock' => $availableStock,
                    'new_stock' => $availableStock - $quantity,
                ];
            }

            // Server-side calculation of totals
            $subtotal = round($subtotal, 2);
            $totalAmount = max(0.00, round($subtotal - $discountAmount, 2));

            if ($paymentMethod === 'Cash' && $amountReceived < $totalAmount) {
                // If payment is cash and user specified amount received less than total
                $amountReceived = $totalAmount;
            }
            $changeDue = max(0.00, round($amountReceived - $totalAmount, 2));

            // Generate unique invoice number and sale ID
            $invoiceNumber = generateInvoiceNumber($db, $shopId);
            $saleId = generateUuid();

            // Insert Sales Master Record
            $saleStmt = $db->prepare("
                INSERT INTO sales (
                    id, shop_id, user_id, invoice_number, subtotal, discount_amount,
                    total_amount, payment_method, amount_received, change_due,
                    customer_name, customer_phone, status
                ) VALUES (
                    :id, :shop_id, :user_id, :invoice_number, :subtotal, :discount_amount,
                    :total_amount, :payment_method, :amount_received, :change_due,
                    :customer_name, :customer_phone, 'completed'
                )
            ");
            $saleStmt->execute([
                ':id' => $saleId,
                ':shop_id' => $shopId,
                ':user_id' => $userId,
                ':invoice_number' => $invoiceNumber,
                ':subtotal' => $subtotal,
                ':discount_amount' => $discountAmount,
                ':total_amount' => $totalAmount,
                ':payment_method' => $paymentMethod,
                ':amount_received' => $amountReceived,
                ':change_due' => $changeDue,
                ':customer_name' => $customerName ?: null,
                ':customer_phone' => $customerPhone ?: null,
            ]);
            $saleStmt->closeCursor();

            // Insert Sale Items and Atomically Deduct Inventory Stock
            $itemInsertStmt = $db->prepare("
                INSERT INTO sale_items (
                    id, sale_id, product_id, product_name_snapshot, sku_snapshot,
                    quantity, unit_price, unit_cost, line_total
                ) VALUES (
                    :id, :sale_id, :product_id, :name_snap, :sku_snap,
                    :quantity, :unit_price, :unit_cost, :line_total
                )
            ");

            $stockUpdateStmt = $db->prepare("
                UPDATE products 
                SET stock_quantity = :new_stock 
                WHERE id = :id AND shop_id = :shop_id
            ");

            foreach ($processedItems as $item) {
                // Insert Item snapshot
                $itemInsertStmt->execute([
                    ':id' => generateUuid(),
                    ':sale_id' => $saleId,
                    ':product_id' => $item['product_id'],
                    ':name_snap' => $item['name'],
                    ':sku_snap' => $item['sku'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':unit_cost' => $item['unit_cost'],
                    ':line_total' => $item['line_total'],
                ]);

                // Deduct stock
                $stockUpdateStmt->execute([
                    ':new_stock' => $item['new_stock'],
                    ':id' => $item['product_id'],
                    ':shop_id' => $shopId,
                ]);

                // Record stock movement
                recordStockMovement(
                    $db,
                    $shopId,
                    $item['product_id'],
                    $userId,
                    'sale',
                    -$item['quantity'],
                    $item['prev_stock'],
                    $item['new_stock'],
                    "Sale checkout #{$invoiceNumber}",
                    'sale',
                    $saleId
                );
            }

            $itemInsertStmt->closeCursor();
            $stockUpdateStmt->closeCursor();

            // Log Audit
            logAudit($db, $shopId, $userId, 'create_sale', 'sales', $saleId, [
                'invoice' => $invoiceNumber,
                'total' => $totalAmount,
                'items_count' => count($processedItems)
            ]);

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => 'Sale completed successfully!',
                'sale_id' => $saleId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'amount_received' => $amountReceived,
                'change_due' => $changeDue,
                'payment_method' => $paymentMethod,
                'items' => $processedItems,
            ]);
        }

        jsonError('Unknown action requested');
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonError('Sale transaction failed: ' . $e->getMessage(), 500);
}
