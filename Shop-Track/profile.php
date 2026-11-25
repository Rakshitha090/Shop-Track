<?php
/**
 * ShopTrack - User Profile
 */

$pageTitle = 'User Profile';
require_once __DIR__ . '/includes/header.php';

$userId = Auth::id();
$shopId = Auth::shopId();

$success = '';
$error = '';
$userData = [];

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT u.*, s.name AS shop_name 
        FROM users u 
        JOIN shops s ON u.shop_id = s.id 
        WHERE u.id = :id AND u.shop_id = :shop_id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId, ':shop_id' => $shopId]);
    $userData = $stmt->fetch() ?: [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!CSRF::verifyRequest()) {
            $error = 'Security session expired. Please refresh and try again.';
        } else {
            $action = $_POST['action'] ?? 'update_profile';

            if ($action === 'update_profile') {
                $fullName = trim($_POST['full_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');

                if (empty($fullName)) {
                    $error = 'Your full name is required.';
                } else {
                    $up = $db->prepare("UPDATE users SET full_name = :name, phone = :phone WHERE id = :id");
                    $up->execute([
                        ':name' => $fullName,
                        ':phone' => $phone ?: null,
                        ':id' => $userId
                    ]);

                    $_SESSION['shoptrack_user']['full_name'] = $fullName;
                    $_SESSION['shoptrack_user']['phone'] = $phone;

                    logAudit($db, $shopId, $userId, 'update_profile', 'users', $userId);
                    $success = 'Profile details updated successfully!';

                    // Re-fetch
                    $stmt->execute([':id' => $userId, ':shop_id' => $shopId]);
                    $userData = $stmt->fetch();
                }
            }
        }
    }

} catch (Exception $e) {
    $error = 'Failed to load profile: ' . $e->getMessage();
}
?>

<div style="max-width: 700px; margin: 0 auto;">
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">User Profile</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Manage your personal account credentials and role</p>
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

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="user" style="color: var(--primary);"></i>
                Personal Information
            </h3>
            <span class="badge badge-info"><?= ucfirst(h($userData['role'] ?? 'owner')) ?></span>
        </div>
        <div class="card-body">
            <!-- Avatar Banner -->
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--teal-light); color: var(--teal); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700;">
                    <?= strtoupper(substr($userData['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; color: var(--text-dark);"><?= h($userData['full_name'] ?? '') ?></h3>
                    <p style="font-size: 12px; color: var(--text-muted);">
                        Belongs to: <strong><?= h($userData['shop_name'] ?? '') ?></strong> • Member since <?= formatDate($userData['created_at'] ?? '', 'M Y') ?>
                    </p>
                </div>
            </div>

            <form method="POST" action="profile.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required value="<?= h($userData['full_name'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" id="email" class="form-control" value="<?= h($userData['email'] ?? '') ?>" disabled title="Email is fixed for shop authentication">
                        <small style="color: var(--text-light); font-size: 11px;">Primary login identity.</small>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" value="<?= h($userData['phone'] ?? '') ?>" placeholder="e.g. 9876543210">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                    <a href="settings.php" class="btn btn-secondary">
                        <i data-lucide="key"></i> Change Password
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="check"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
