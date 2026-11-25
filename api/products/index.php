<?php
/**
 * ShopTrack - Products API Endpoint
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
    // GET: Search / List / Single Product
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        $productId = $_GET['id'] ?? null;

        if ($productId) {
            $stmt = $db->prepare("
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = :id AND p.shop_id = :shop_id
                LIMIT 1
            ");
            $stmt->execute([':id' => $productId, ':shop_id' => $shopId]);
            $product = $stmt->fetch();

            if (!$product) {
                jsonError('Product not found', 404);
            }

            jsonResponse(['success' => true, 'product' => $product]);
        }

        // Search query for POS or filters
        $search = trim($_GET['search'] ?? '');
        $categoryId = $_GET['category_id'] ?? '';
        $status = $_GET['status'] ?? ''; // 'in_stock', 'low_stock', 'out_of_stock'
        $forPos = isset($_GET['pos']); // Only available unarchived items for sale

        $sql = "
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.shop_id = :shop_id AND p.archived_at IS NULL
        ";
        $params = [':shop_id' => $shopId];

        if ($forPos) {
            $sql .= " AND p.stock_quantity > 0";
        }

        if (!empty($search)) {
            $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $sql .= " AND (p.name {$like} :search OR p.sku {$like} :search OR p.barcode {$like} :search)";
            $params[':search'] = "%{$search}%";
        }

        if (!empty($categoryId)) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        if ($status === 'in_stock') {
            $sql .= " AND p.stock_quantity > p.min_stock_level";
        } elseif ($status === 'low_stock') {
            $sql .= " AND p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_level";
        } elseif ($status === 'out_of_stock') {
            $sql .= " AND p.stock_quantity <= 0";
        }

        $sql .= " ORDER BY p.name ASC LIMIT 100";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        jsonResponse(['success' => true, 'products' => $products]);
    }

    // -------------------------------------------------------------------------
    // POST: Archive / Delete Product
    // -------------------------------------------------------------------------
    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Invalid security token. Please refresh the page.', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $action = $input['action'] ?? '';
        $productId = $input['id'] ?? '';

        if (empty($productId)) {
            jsonError('Product ID is required');
        }

        // Verify product belongs to this shop
        $checkStmt = $db->prepare("SELECT id, name, sku FROM products WHERE id = :id AND shop_id = :shop_id");
        $checkStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
        $existing = $checkStmt->fetch();
        $checkStmt->closeCursor();

        if (!$existing) {
            jsonError('Product not found or unauthorized', 404);
        }

        if ($action === 'archive' || $action === 'delete') {
            // Check if product is referenced in sales or purchases
            $refStmt = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = :id");
            $refStmt->execute([':id' => $productId]);
            $hasSales = (int)$refStmt->fetchColumn() > 0;
            $refStmt->closeCursor();

            $purStmt = $db->prepare("SELECT COUNT(*) FROM purchase_items WHERE product_id = :id");
            $purStmt->execute([':id' => $productId]);
            $hasPurchases = (int)$purStmt->fetchColumn() > 0;
            $purStmt->closeCursor();

            $now = date('Y-m-d H:i:s');

            if ($hasSales || $hasPurchases) {
                // Soft archive to protect sales & purchase history integrity
                $upStmt = $db->prepare("UPDATE products SET archived_at = :archived_at WHERE id = :id AND shop_id = :shop_id");
                $upStmt->execute([':archived_at' => $now, ':id' => $productId, ':shop_id' => $shopId]);
                $upStmt->closeCursor();

                logAudit($db, $shopId, $userId, 'archive_product', 'products', $productId, ['sku' => $existing['sku']]);
                jsonResponse(['success' => true, 'message' => "Product '{$existing['name']}' has been removed from catalog."]);
            } else {
                // Safe hard delete: first clean up any stock movements for this product, then delete the product
                $db->beginTransaction();

                $delMovStmt = $db->prepare("DELETE FROM stock_movements WHERE product_id = :id AND shop_id = :shop_id");
                $delMovStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
                $delMovStmt->closeCursor();

                $delStmt = $db->prepare("DELETE FROM products WHERE id = :id AND shop_id = :shop_id");
                $delStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
                $delStmt->closeCursor();

                logAudit($db, $shopId, $userId, 'delete_product', 'products', $productId, ['sku' => $existing['sku']]);

                $db->commit();
                jsonResponse(['success' => true, 'message' => "Product '{$existing['name']}' deleted successfully."]);
            }
        }

        jsonError('Unknown action requested');
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonError('API operation failed: ' . $e->getMessage(), 500);
}
