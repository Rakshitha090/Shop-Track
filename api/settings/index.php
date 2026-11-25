<?php
/**
 * ShopTrack - Settings API Endpoint
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

    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT id, name, owner_name, phone, email, address, currency, low_stock_default FROM shops WHERE id = :id");
        $stmt->execute([':id' => $shopId]);
        $shop = $stmt->fetch();
        jsonResponse(['success' => true, 'shop' => $shop]);
    }

    if ($method === 'POST') {
        if (!CSRF::verifyRequest()) {
            jsonError('Security token verification failed', 403);
        }

        $input = getJsonInput();
        if (empty($input)) {
            $input = $_POST;
        }

        $name = trim($input['name'] ?? '');
        $ownerName = trim($input['owner_name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');
        $address = trim($input['address'] ?? '');
        $currency = trim($input['currency'] ?? 'INR');
        $lowStock = max(1, (int)($input['low_stock_default'] ?? 5));

        if (empty($name)) {
            jsonError('Shop name cannot be empty.');
        }

        $up = $db->prepare("
            UPDATE shops 
            SET name = :name, owner_name = :owner, phone = :phone, email = :email,
                address = :address, currency = :currency, low_stock_default = :low
            WHERE id = :id
        ");
        $up->execute([
            ':name' => $name,
            ':owner' => $ownerName ?: null,
            ':phone' => $phone ?: null,
            ':email' => $email ?: null,
            ':address' => $address ?: null,
            ':currency' => $currency,
            ':low' => $lowStock,
            ':id' => $shopId
        ]);

        $_SESSION['shoptrack_user']['shop_name'] = $name;
        $_SESSION['shoptrack_user']['currency'] = $currency;

        logAudit($db, $shopId, $userId, 'update_shop_api', 'shops', $shopId);

        jsonResponse(['success' => true, 'message' => 'Settings updated successfully.']);
    }

    jsonError('Method not allowed', 405);

} catch (Exception $e) {
    jsonError('Settings API error: ' . $e->getMessage(), 500);
}
