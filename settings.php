<?php
/**
 * ShopTrack - Shop Settings & Configurations
 */

$pageTitle = 'Shop Settings';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$userId = Auth::id();

$success = '';
$error = '';

$shop = [];

try {
    $db = getDB();

    // Fetch existing shop details
    $sStmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
    $sStmt->execute([':id' => $shopId]);
    $shop = $sStmt->fetch() ?: [];

    // Handle POST submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!CSRF::verifyRequest()) {
            $error = 'Security session expired. Please refresh and try again.';
        } else {
            $action = $_POST['action'] ?? 'update_shop';

            // 1. UPDATE SHOP PROFILE
            if ($action === 'update_shop') {
                $name = trim($_POST['name'] ?? '');
                $ownerName = trim($_POST['owner_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $currency = trim($_POST['currency'] ?? 'INR');
                $lowStockDefault = max(1, (int)($_POST['low_stock_default'] ?? 5));

                if (empty($name)) {
                    $error = 'Shop name is required.';
                } else {
                    $upStmt = $db->prepare("
                        UPDATE shops
                        SET name = :name,
                            owner_name = :owner_name,
                            phone = :phone,
                            email = :email,
                            address = :address,
                            currency = :currency,
                            low_stock_default = :low_stock_default
                        WHERE id = :id
                    ");
                    $upStmt->execute([
                        ':name' => $name,
                        ':owner_name' => $ownerName ?: null,
                        ':phone' => $phone ?: null,
                        ':email' => $email ?: null,
                        ':address' => $address ?: null,
                        ':currency' => $currency,
                        ':low_stock_default' => $lowStockDefault,
                        ':id' => $shopId
                    ]);

                    // Refresh session
                    $_SESSION['shoptrack_user']['shop_name'] = $name;
                    $_SESSION['shoptrack_user']['currency'] = $currency;

                    logAudit($db, $shopId, $userId, 'update_shop_settings', 'shops', $shopId, ['name' => $name]);

                    $success = 'Shop settings updated successfully!';

                    // Re-fetch updated shop
                    $sStmt->execute([':id' => $shopId]);
                    $shop = $sStmt->fetch();
                }
            }

            // 2. CHANGE PASSWORD
            if ($action === 'change_password') {
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (empty($currentPassword) || empty($newPassword)) {
                    $error = 'Please fill in all password fields.';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'New password must be at least 8 characters long.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'New password and confirmation password do not match.';
                } else {
                    // Verify current password
                    $uStmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id AND shop_id = :shop_id");
                    $uStmt->execute([':id' => $userId, ':shop_id' => $shopId]);
                    $userHash = $uStmt->fetchColumn();

                    if (!password_verify($currentPassword, $userHash)) {
                        $error = 'Current password is incorrect. Verification failed.';
                    } else {
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $pwUpdate = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                        $pwUpdate->execute([':hash' => $newHash, ':id' => $userId]);

                        logAudit($db, $shopId, $userId, 'change_password', 'users', $userId);
                        $success = 'Password changed successfully!';
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    $error = 'Failed to load or save settings: ' . $e->getMessage();
}
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Shop & Account Settings</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Manage store branding, receipt info, default thresholds, and security</p>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle-2"></i>
            <div><?= h($success) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i data-lucide="alert-circle"></i>
            <div><?= h($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Shop Details Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="store" style="color: var(--primary);"></i>
                Store Information
            </h3>
        </div>
        <div class="card-body">
            <form method="POST" action="settings.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_shop">

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="name">Shop / Store Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" required value="<?= h($shop['name'] ?? '') ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Displayed on receipts and dashboard.</small>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="owner_name">Owner / Manager Name</label>
                        <input type="text" id="owner_name" name="owner_name" class="form-control" value="<?= h($shop['owner_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="phone">Phone / WhatsApp</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= h($shop['phone'] ?? '') ?>">
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="email">Public Store Email</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= h($shop['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="address">Store Address (Printed on Receipts)</label>
                    <textarea id="address" name="address" class="form-control" rows="2" placeholder="Street, Market, City, PIN"><?= h($shop['address'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="currency">Operating Currency</label>
                        <select id="currency" name="currency" class="form-control">
                            <option value="INR" <?= ($shop['currency'] ?? 'INR') === 'INR' ? 'selected' : '' ?>>INR (₹ - Indian Rupee)</option>
                            <option value="USD" <?= ($shop['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD ($ - US Dollar)</option>
                            <option value="EUR" <?= ($shop['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR (€ - Euro)</option>
                            <option value="GBP" <?= ($shop['currency'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP (£ - British Pound)</option>
                        </select>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="low_stock_default">Default Low Stock Threshold</label>
                        <input type="number" min="1" id="low_stock_default" name="low_stock_default" class="form-control" value="<?= h((string)($shop['low_stock_default'] ?? 5)) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Default alert quantity for newly registered products.</small>
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="check"></i> Save Store Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Security & Password Change -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="lock" style="color: var(--rose);"></i>
                Change Password
            </h3>
        </div>
        <div class="card-body">
            <form method="POST" action="settings.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label class="form-label" for="current_password">Current Password <span class="required">*</span></label>
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="new_password">New Password <span class="required">*</span></label>
                        <input type="password" id="new_password" name="new_password" class="form-control" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password <span class="required">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter new password" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" class="btn btn-secondary">
                        <i data-lucide="key"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
