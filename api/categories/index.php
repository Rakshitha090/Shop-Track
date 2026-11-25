<?php
/**
 * ShopTrack - Categories API Endpoint
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
    // GET: List Categories with Product Counts
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        $search = trim($_GET['search'] ?? '');

        $sql = "
            SELECT c.*, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.archived_at IS NULL
            WHERE c.shop_id = :shop_id
        ";
        $params = [':shop_id' => $shopId];

        if (!empty($search)) {
            $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $sql .= " AND (c.name {$like} :search OR c.description {$like} :search)";
            $params[':search'] = "%{$search}%";
        }

        $sql .= " GROUP BY c.id ORDER BY c.name ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();

        jsonResponse(['success' => true, 'categories' => $categories]);
    }

    // -------------------------------------------------------------------------
    // POST: Create / Update / Delete
    // -------------------------------------------------------------------------
    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Invalid security token. Please refresh the page.', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $action = $input['action'] ?? 'save';

        // 1. DELETE ACTION
        if ($action === 'delete') {
            $categoryId = $input['id'] ?? '';
            if (empty($categoryId)) {
                jsonError('Category ID is required');
            }

            // Check if category belongs to shop
            $checkStmt = $db->prepare("SELECT id, name FROM categories WHERE id = :id AND shop_id = :shop_id");
            $checkStmt->execute([':id' => $categoryId, ':shop_id' => $shopId]);
            $category = $checkStmt->fetch();
            $checkStmt->closeCursor();
            if (!$category) {
                jsonError('Category not found or unauthorized', 404);
            }

            // Prevent deletion if category contains products
            $countStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id AND archived_at IS NULL");
            $countStmt->execute([':id' => $categoryId]);
            $count = (int)$countStmt->fetchColumn();
            $countStmt->closeCursor();

            if ($count > 0) {
                jsonError("Cannot delete category '{$category['name']}' because it currently contains {$count} active product(s). Please reassign or remove the products first.", 409);
            }

            $delStmt = $db->prepare("DELETE FROM categories WHERE id = :id AND shop_id = :shop_id");
            $delStmt->execute([':id' => $categoryId, ':shop_id' => $shopId]);

            logAudit($db, $shopId, $userId, 'delete_category', 'categories', $categoryId, ['name' => $category['name']]);
            jsonResponse(['success' => true, 'message' => "Category '{$category['name']}' deleted successfully."]);
        }

        // 2. CREATE OR UPDATE ACTION
        $id = trim($input['id'] ?? '');
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');

        if (empty($name)) {
            jsonError('Category name is required.');
        }

        // Check for duplicate category name within same shop
        $dupSql = "SELECT id FROM categories WHERE shop_id = :shop_id AND LOWER(name) = LOWER(:name)";
        $dupParams = [':shop_id' => $shopId, ':name' => $name];
        if (!empty($id)) {
            $dupSql .= " AND id != :id";
            $dupParams[':id'] = $id;
        }
        $dupStmt = $db->prepare($dupSql);
        $dupStmt->execute($dupParams);
        $dupExists = $dupStmt->fetch();
        $dupStmt->closeCursor();
        if ($dupExists) {
            jsonError("A category named '{$name}' already exists in your shop.", 409);
        }

        if (!empty($id)) {
            // Update
            $updateStmt = $db->prepare("
                UPDATE categories 
                SET name = :name, description = :description 
                WHERE id = :id AND shop_id = :shop_id
            ");
            $updateStmt->execute([
                ':name' => $name,
                ':description' => $description ?: null,
                ':id' => $id,
                ':shop_id' => $shopId
            ]);
            $updateStmt->closeCursor();
            logAudit($db, $shopId, $userId, 'update_category', 'categories', $id, ['name' => $name]);
            jsonResponse(['success' => true, 'message' => "Category '{$name}' updated successfully."]);
        } else {
            // Create
            $newId = generateUuid();
            $insertStmt = $db->prepare("
                INSERT INTO categories (id, shop_id, name, description)
                VALUES (:id, :shop_id, :name, :description)
            ");
            $insertStmt->execute([
                ':id' => $newId,
                ':shop_id' => $shopId,
                ':name' => $name,
                ':description' => $description ?: null,
            ]);
            $insertStmt->closeCursor();
            logAudit($db, $shopId, $userId, 'create_category', 'categories', $newId, ['name' => $name]);
            jsonResponse(['success' => true, 'message' => "Category '{$name}' created successfully."]);
        }
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    jsonError('Failed to process category request: ' . $e->getMessage(), 500);
}
