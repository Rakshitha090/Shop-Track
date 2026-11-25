<?php
/**
 * ShopTrack - Suppliers API Endpoint
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
    // GET: Single or List Suppliers
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        $supplierId = $_GET['id'] ?? null;

        if ($supplierId) {
            $stmt = $db->prepare("SELECT * FROM suppliers WHERE id = :id AND shop_id = :shop_id LIMIT 1");
            $stmt->execute([':id' => $supplierId, ':shop_id' => $shopId]);
            $supplier = $stmt->fetch();

            if (!$supplier) {
                jsonError('Supplier not found', 404);
            }

            jsonResponse(['success' => true, 'supplier' => $supplier]);
        }

        $search = trim($_GET['search'] ?? '');
        $sql = "SELECT * FROM suppliers WHERE shop_id = :shop_id AND archived_at IS NULL";
        $params = [':shop_id' => $shopId];

        if (!empty($search)) {
            $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $sql .= " AND (name {$like} :search OR contact_person {$like} :search OR phone {$like} :search OR email {$like} :search)";
            $params[':search'] = "%{$search}%";
        }

        $sql .= " ORDER BY name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $suppliers = $stmt->fetchAll();

        jsonResponse(['success' => true, 'suppliers' => $suppliers]);
    }

    // -------------------------------------------------------------------------
    // POST: Create / Update / Delete
    // -------------------------------------------------------------------------
    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Security token verification failed', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $action = $input['action'] ?? 'save';

        // DELETE / ARCHIVE
        if ($action === 'delete') {
            $supplierId = $input['id'] ?? '';
            if (empty($supplierId)) {
                jsonError('Supplier ID is required');
            }

            $checkStmt = $db->prepare("SELECT id, name FROM suppliers WHERE id = :id AND shop_id = :shop_id");
            $checkStmt->execute([':id' => $supplierId, ':shop_id' => $shopId]);
            $supplier = $checkStmt->fetch();
            $checkStmt->closeCursor();

            if (!$supplier) {
                jsonError('Supplier not found', 404);
            }

            // Check if supplier has purchases
            $purCheck = $db->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = :id");
            $purCheck->execute([':id' => $supplierId]);
            $hasPurchases = (int)$purCheck->fetchColumn() > 0;
            $purCheck->closeCursor();

            $now = date('Y-m-d H:i:s');
            if ($hasPurchases) {
                // Soft archive to preserve purchase orders
                $upStmt = $db->prepare("UPDATE suppliers SET archived_at = :archived_at WHERE id = :id AND shop_id = :shop_id");
                $upStmt->execute([':archived_at' => $now, ':id' => $supplierId, ':shop_id' => $shopId]);
                logAudit($db, $shopId, $userId, 'archive_supplier', 'suppliers', $supplierId, ['name' => $supplier['name']]);
                jsonResponse(['success' => true, 'message' => "Supplier '{$supplier['name']}' archived to protect historical purchase orders."]);
            } else {
                $delStmt = $db->prepare("DELETE FROM suppliers WHERE id = :id AND shop_id = :shop_id");
                $delStmt->execute([':id' => $supplierId, ':shop_id' => $shopId]);
                logAudit($db, $shopId, $userId, 'delete_supplier', 'suppliers', $supplierId, ['name' => $supplier['name']]);
                jsonResponse(['success' => true, 'message' => "Supplier '{$supplier['name']}' deleted successfully."]);
            }
        }

        // CREATE OR UPDATE
        $id = trim($input['id'] ?? '');
        $name = trim($input['name'] ?? '');
        $contactPerson = trim($input['contact_person'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if (empty($name)) {
            jsonError('Supplier name is required.');
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('Please enter a valid email address.');
        }

        if (!empty($id)) {
            // Update
            $updateStmt = $db->prepare("
                UPDATE suppliers
                SET name = :name,
                    contact_person = :contact_person,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    notes = :notes
                WHERE id = :id AND shop_id = :shop_id
            ");
            $updateStmt->execute([
                ':name' => $name,
                ':contact_person' => $contactPerson ?: null,
                ':phone' => $phone ?: null,
                ':email' => $email ?: null,
                ':address' => $address ?: null,
                ':notes' => $notes ?: null,
                ':id' => $id,
                ':shop_id' => $shopId
            ]);
            $updateStmt->closeCursor();
            logAudit($db, $shopId, $userId, 'update_supplier', 'suppliers', $id, ['name' => $name]);
            jsonResponse(['success' => true, 'message' => "Supplier '{$name}' updated successfully."]);
        } else {
            // Create
            $newId = generateUuid();
            $insertStmt = $db->prepare("
                INSERT INTO suppliers (id, shop_id, name, contact_person, phone, email, address, notes)
                VALUES (:id, :shop_id, :name, :contact_person, :phone, :email, :address, :notes)
            ");
            $insertStmt->execute([
                ':id' => $newId,
                ':shop_id' => $shopId,
                ':name' => $name,
                ':contact_person' => $contactPerson ?: null,
                ':phone' => $phone ?: null,
                ':email' => $email ?: null,
                ':address' => $address ?: null,
                ':notes' => $notes ?: null,
            ]);
            $insertStmt->closeCursor();
            logAudit($db, $shopId, $userId, 'create_supplier', 'suppliers', $newId, ['name' => $name]);
            jsonResponse(['success' => true, 'message' => "Supplier '{$name}' created successfully."]);
        }
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    jsonError('Supplier operation failed: ' . $e->getMessage(), 500);
}
