<?php
/**
 * ShopTrack - Shop & Owner Registration Page
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already authenticated
Auth::requireGuest();

$error = '';
$fullName = '';
$shopName = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyRequest()) {
        $error = 'Invalid security session. Please refresh the page and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $shopName = trim($_POST['shop_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($fullName) || empty($shopName) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation password do not match.';
        } else {
            try {
                $db = getDB();

                // Check for duplicate email
                $checkStmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
                $checkStmt->execute([':email' => $email]);
                $existingUser = $checkStmt->fetch();
                $checkStmt->closeCursor();

                if ($existingUser) {
                    $error = 'An account with this email address already exists. Please log in instead.';
                } else {
                    // Create Shop & Owner User inside a database transaction
                    $db->beginTransaction();

                    $shopId = generateUuid();
                    $userId = generateUuid();

                    // 1. Insert Shop
                    $shopStmt = $db->prepare("
                        INSERT INTO shops (id, name, owner_name, phone, email, currency)
                        VALUES (:id, :name, :owner_name, :phone, :email, 'INR')
                    ");
                    $shopStmt->execute([
                        ':id' => $shopId,
                        ':name' => $shopName,
                        ':owner_name' => $fullName,
                        ':phone' => $phone ?: null,
                        ':email' => $email,
                    ]);
                    $shopStmt->closeCursor();

                    // 2. Insert User (Role: owner)
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $userStmt = $db->prepare("
                        INSERT INTO users (id, shop_id, full_name, email, password_hash, role, phone)
                        VALUES (:id, :shop_id, :full_name, :email, :password_hash, 'owner', :phone)
                    ");
                    $userStmt->execute([
                        ':id' => $userId,
                        ':shop_id' => $shopId,
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':phone' => $phone ?: null,
                    ]);
                    $userStmt->closeCursor();

                    // 3. Create default sample categories to help the shop get started
                    $catStmt = $db->prepare("
                        INSERT INTO categories (id, shop_id, name, description)
                        VALUES 
                            (:id1, :shop_id, 'General Items', 'Standard shop goods and essentials'),
                            (:id2, :shop_id, 'Beverages & Snacks', 'Cold drinks, juices, and packaged snacks'),
                            (:id3, :shop_id, 'Groceries', 'Daily grocery and food essentials')
                        ON CONFLICT DO NOTHING
                    ");
                    $catStmt->execute([
                        ':id1' => generateUuid(),
                        ':id2' => generateUuid(),
                        ':id3' => generateUuid(),
                        ':shop_id' => $shopId,
                    ]);
                    $catStmt->closeCursor();

                    // 4. Log Audit
                    logAudit($db, $shopId, $userId, 'shop_registration', 'shops', $shopId, [
                        'shop_name' => $shopName,
                        'owner_name' => $fullName,
                        'email' => $email
                    ]);

                    $db->commit();

                    header('Location: /login.php?registered=1');
                    exit;
                }
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Shop — <?= h(APP_NAME) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-page">

<div class="auth-card" style="max-width: 520px;">
    <div class="auth-header">
        <div class="auth-logo">
            <div class="brand-icon" style="width: 48px; height: 48px; font-size: 22px; border-radius: 12px; margin: 0 auto;">
                <i data-lucide="store" style="width: 26px; height: 26px;"></i>
            </div>
        </div>
        <h1 class="auth-title">Create Shop Account</h1>
        <p class="auth-subtitle">Set up your smart inventory management in minutes</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i data-lucide="alert-circle"></i>
            <div style="font-size: 13px; line-height: 1.5;"><?= nl2br(h($error)) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php" id="register-form">
        <?= csrf_field() ?>

        <div class="form-row">
            <div class="form-col form-group">
                <label class="form-label" for="full_name">Your Full Name <span class="required">*</span></label>
                <input type="text" id="full_name" name="full_name" class="form-control" placeholder="e.g. Ramesh Kumar" required value="<?= h($fullName) ?>">
            </div>
            <div class="form-col form-group">
                <label class="form-label" for="shop_name">Shop / Store Name <span class="required">*</span></label>
                <input type="text" id="shop_name" name="shop_name" class="form-control" placeholder="e.g. Super Mart" required value="<?= h($shopName) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-col form-group">
                <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" class="form-control" placeholder="owner@shop.com" required value="<?= h($email) ?>">
            </div>
            <div class="form-col form-group">
                <label class="form-label" for="phone">Phone / WhatsApp</label>
                <input type="tel" id="phone" name="phone" class="form-control" placeholder="e.g. 9876543210" value="<?= h($phone) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-col form-group">
                <label class="form-label" for="password">Password <span class="required">*</span></label>
                <input type="password" id="password" name="password" class="form-control" placeholder="At least 8 chars" required minlength="8">
            </div>
            <div class="form-col form-group">
                <label class="form-label" for="confirm_password">Confirm Password <span class="required">*</span></label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter password" required minlength="8">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; margin-top: 10px;">
            <i data-lucide="check"></i> Create My Shop Account
        </button>
    </form>

    <div class="auth-footer">
        Already registered?
        <a href="login.php" style="font-weight: 600;">Sign in to your shop</a>
    </div>
</div>

<script>
    if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
