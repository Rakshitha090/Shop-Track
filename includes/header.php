<?php
/**
 * ShopTrack - Reusable Header Partial
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

// Enforce authentication on all pages that include this header
Auth::requireAuth();

$currentUser = Auth::user();
$shopId = Auth::shopId();
$pageTitle = $pageTitle ?? 'Dashboard';

// Query low-stock alert count for topbar notification badge
$unreadAlertCount = 0;
$dbConnectionError = null;

try {
    $db = getDB();
    $alertStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM products 
        WHERE shop_id = :shop_id 
          AND archived_at IS NULL 
          AND stock_quantity <= min_stock_level
    ");
    $alertStmt->execute([':shop_id' => $shopId]);
    $unreadAlertCount = (int)$alertStmt->fetchColumn();
} catch (Exception $e) {
    $dbConnectionError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <!-- App Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>
<div class="app-container">
    <!-- Reusable Sidebar -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- Mobile Overlay Backdrop -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Main Content Area -->
    <div class="app-main">
        <!-- Sticky Topbar -->
        <header class="app-topbar">
            <div class="topbar-left">
                <button type="button" class="menu-toggle-btn" id="menu-toggle-btn" aria-label="Toggle navigation">
                    <i data-lucide="menu"></i>
                </button>
                <div class="page-breadcrumb">
                    <span><?= h(APP_NAME) ?></span>
                    <span>/</span>
                    <span class="page-title"><?= h($pageTitle) ?></span>
                </div>
            </div>

            <div class="topbar-right">
                <a href="notifications.php" class="topbar-action-btn" title="Inventory Notifications">
                    <i data-lucide="bell"></i>
                    <?php if ($unreadAlertCount > 0): ?>
                        <span class="notif-badge" title="<?= $unreadAlertCount ?> stock alerts"></span>
                    <?php endif; ?>
                </a>

                <div class="shop-mini-profile">
                    <div class="shop-avatar" title="<?= h($currentUser['full_name']) ?>">
                        <?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?>
                    </div>
                    <div class="shop-info-text">
                        <div class="shop-info-name"><?= h($currentUser['shop_name']) ?></div>
                        <div class="shop-info-role"><?= h(ucfirst($currentUser['role'])) ?></div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Wrapper -->
        <main class="content-body">
            <?php if (!empty($dbConnectionError)): ?>
                <div class="alert alert-danger">
                    <i data-lucide="alert-triangle"></i>
                    <div>
                        <strong>Database Configuration Notice:</strong>
                        <div><?= h($dbConnectionError) ?></div>
                        <small>Please ensure your Supabase PostgreSQL credentials are setup in <code>.env</code>.</small>
                    </div>
                </div>
            <?php endif; ?>
