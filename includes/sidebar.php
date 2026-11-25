<?php
/**
 * ShopTrack - Sidebar Navigation Partial
 */

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentUser = Auth::user();
?>
<aside class="app-sidebar" id="app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <i data-lucide="boxes" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="brand-title">ShopTrack</div>
            <div class="brand-badge">Retail Pro</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Core Management</div>
        <a href="index.php" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <i data-lucide="layout-dashboard"></i>
            <span>Dashboard</span>
        </a>

        <a href="sales.php" class="nav-item <?= $currentPage === 'sales.php' ? 'active' : '' ?>">
            <i data-lucide="shopping-cart"></i>
            <span>Point of Sale (POS)</span>
        </a>

        <a href="sales-history.php" class="nav-item <?= $currentPage === 'sales-history.php' ? 'active' : '' ?>">
            <i data-lucide="receipt"></i>
            <span>Sales History</span>
        </a>

        <div class="nav-section-title">Catalog & Inventory</div>
        <a href="products.php" class="nav-item <?= in_array($currentPage, ['products.php', 'product-form.php']) ? 'active' : '' ?>">
            <i data-lucide="package"></i>
            <span>Products</span>
        </a>

        <a href="categories.php" class="nav-item <?= $currentPage === 'categories.php' ? 'active' : '' ?>">
            <i data-lucide="folder-tree"></i>
            <span>Categories</span>
        </a>

        <a href="stock.php" class="nav-item <?= $currentPage === 'stock.php' ? 'active' : '' ?>">
            <i data-lucide="arrow-left-right"></i>
            <span>Stock Movements</span>
        </a>

        <div class="nav-section-title">Suppliers & Stock-In</div>
        <a href="suppliers.php" class="nav-item <?= $currentPage === 'suppliers.php' ? 'active' : '' ?>">
            <i data-lucide="truck"></i>
            <span>Suppliers</span>
        </a>

        <a href="purchases.php" class="nav-item <?= $currentPage === 'purchases.php' ? 'active' : '' ?>">
            <i data-lucide="clipboard-list"></i>
            <span>Purchases</span>
        </a>

        <div class="nav-section-title">Analytics & Tools</div>
        <a href="reports.php" class="nav-item <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
            <i data-lucide="bar-chart-3"></i>
            <span>Reports</span>
        </a>

        <a href="notifications.php" class="nav-item <?= $currentPage === 'notifications.php' ? 'active' : '' ?>">
            <i data-lucide="bell"></i>
            <span>Notifications</span>
        </a>

        <div class="nav-section-title">System</div>
        <a href="settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <i data-lucide="sliders"></i>
            <span>Shop Settings</span>
        </a>

        <a href="profile.php" class="nav-item <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
            <i data-lucide="user"></i>
            <span>User Profile</span>
        </a>

        <a href="logout.php" class="nav-item" style="color: var(--rose);" onclick="return confirm('Are you sure you want to log out of ShopTrack?');">
            <i data-lucide="log-out" style="color: var(--rose);"></i>
            <span>Logout</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="shop-mini-profile">
            <div class="shop-avatar">
                <?= strtoupper(substr($currentUser['shop_name'] ?? 'S', 0, 1)) ?>
            </div>
            <div class="shop-info-text">
                <div class="shop-info-name"><?= h($currentUser['shop_name'] ?? 'My Shop') ?></div>
                <div class="shop-info-role"><?= h($currentUser['email'] ?? '') ?></div>
            </div>
        </div>
    </div>
</aside>
