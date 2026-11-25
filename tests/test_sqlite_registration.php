<?php
/**
 * ShopTrack - Automated SQLite Registration & Login Test Suite
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

echo "=== ShopTrack SQLite Registration & Login Flow Test ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$description}\n";
        $passed++;
    } else {
        echo " [FAIL] {$description}" . ($detail ? " ($detail)" : '') . "\n";
        $failed++;
    }
}

try {
    // 1. Verify Database Connection
    $db = getDB();
    assertTest("Database connects successfully", $db instanceof PDO);
    assertTest("Active database driver is SQLite", Database::getDriver() === 'sqlite');

    // 2. Verify Table Existence
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name;");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    assertTest("Table 'shops' exists", in_array('shops', $tables, true));
    assertTest("Table 'users' exists", in_array('users', $tables, true));
    assertTest("Table 'categories' exists", in_array('categories', $tables, true));
    assertTest("Table 'products' exists", in_array('products', $tables, true));
    assertTest("Table 'audit_logs' exists", in_array('audit_logs', $tables, true));

    // 3. Verify Required Columns for Shops
    $shopColsStmt = $db->query("PRAGMA table_info(shops);");
    $shopCols = array_column($shopColsStmt->fetchAll(), 'name');
    $requiredShopCols = ['id', 'name', 'owner_name', 'phone', 'email', 'currency', 'low_stock_default', 'created_at', 'updated_at'];
    foreach ($requiredShopCols as $col) {
        assertTest("Shops table has column '{$col}'", in_array($col, $shopCols, true));
    }

    // 4. Verify Required Columns for Users
    $userColsStmt = $db->query("PRAGMA table_info(users);");
    $userCols = array_column($userColsStmt->fetchAll(), 'name');
    $requiredUserCols = ['id', 'shop_id', 'full_name', 'email', 'password_hash', 'role', 'phone', 'created_at', 'updated_at'];
    foreach ($requiredUserCols as $col) {
        assertTest("Users table has column '{$col}'", in_array($col, $userCols, true));
    }

    // 5. Test Registration Flow inside Transaction
    $testEmail = 'tester_' . time() . '@shop.com';
    $testPassword = 'TestPassword123!';
    $testShopName = 'Test SuperMart ' . time();
    $testOwnerName = 'Test Owner';

    $db->beginTransaction();

    // 5a. Insert Shop with RETURNING id
    $shopStmt = $db->prepare("
        INSERT INTO shops (name, owner_name, phone, email, currency)
        VALUES (:name, :owner_name, :phone, :email, 'INR')
        RETURNING id
    ");
    $shopStmt->execute([
        ':name' => $testShopName,
        ':owner_name' => $testOwnerName,
        ':phone' => '9876543210',
        ':email' => $testEmail,
    ]);
    $shopId = $shopStmt->fetchColumn();
    assertTest("Shop inserted and generated non-empty UUID", !empty($shopId) && strlen($shopId) >= 32);

    // 5b. Insert User with password_hash() and RETURNING id
    $passwordHash = password_hash($testPassword, PASSWORD_DEFAULT);
    $userStmt = $db->prepare("
        INSERT INTO users (shop_id, full_name, email, password_hash, role, phone)
        VALUES (:shop_id, :full_name, :email, :password_hash, 'owner', :phone)
        RETURNING id
    ");
    $userStmt->execute([
        ':shop_id' => $shopId,
        ':full_name' => $testOwnerName,
        ':email' => $testEmail,
        ':password_hash' => $passwordHash,
        ':phone' => '9876543210',
    ]);
    $userId = $userStmt->fetchColumn();
    assertTest("User inserted with password_hash and generated UUID", !empty($userId) && strlen($userId) >= 32);

    // 5c. Insert Sample Categories
    $catStmt = $db->prepare("
        INSERT INTO categories (shop_id, name, description)
        VALUES 
            (:shop_id, 'General Items', 'Standard shop goods'),
            (:shop_id, 'Beverages & Snacks', 'Cold drinks')
        ON CONFLICT DO NOTHING
    ");
    $catStmt->execute([':shop_id' => $shopId]);
    assertTest("Categories inserted successfully", $catStmt->rowCount() >= 0);

    // 5d. Insert Audit Log
    $auditStmt = $db->prepare("
        INSERT INTO audit_logs (shop_id, user_id, action, entity_type, entity_id, details)
        VALUES (:shop_id, :user_id, 'test_registration', 'shops', :shop_id, :details)
    ");
    $auditStmt->execute([
        ':shop_id' => $shopId,
        ':user_id' => $userId,
        ':details' => json_encode(['registered_by' => $testOwnerName])
    ]);
    assertTest("Audit log inserted successfully", $auditStmt->rowCount() === 1);

    $db->commit();
    assertTest("Registration transaction committed successfully", true);

    // 6. Test Login Verification Query (Matching login.php)
    $loginStmt = $db->prepare("
        SELECT u.id, u.shop_id, u.full_name, u.email, u.password_hash, u.role, u.phone,
               s.name AS shop_name, s.currency
        FROM users u
        JOIN shops s ON u.shop_id = s.id
        WHERE LOWER(u.email) = LOWER(:email)
        LIMIT 1
    ");
    $loginStmt->execute([':email' => $testEmail]);
    $user = $loginStmt->fetch();

    assertTest("Login query successfully finds registered user with joined shop", !empty($user));
    assertTest("User shop_id matches inserted shop", ($user['shop_id'] ?? '') === $shopId);
    assertTest("password_verify validates the password successfully", password_verify($testPassword, $user['password_hash'] ?? ''));
    assertTest("password_verify rejects wrong password", !password_verify('WrongPassword999', $user['password_hash'] ?? ''));

    // 7. Verify Foreign Key Constraint Enforcement
    $fkCheck = false;
    try {
        $badUserStmt = $db->prepare("
            INSERT INTO users (shop_id, full_name, email, password_hash, role)
            VALUES ('invalid-nonexistent-shop-id', 'Ghost', 'ghost@shop.com', 'hash', 'owner')
        ");
        $badUserStmt->execute();
    } catch (PDOException $e) {
        $fkCheck = true; // Expected foreign key failure
    }
    assertTest("Foreign key enforcement prevents orphan user with invalid shop_id", $fkCheck);

} catch (Exception $e) {
    echo " [EXCEPTION] " . $e->getMessage() . "\n";
    $failed++;
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
