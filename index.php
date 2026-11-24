<?php
/**
 * ShopTrack - Main Dashboard
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

// Prepare metrics with safe defaults
$totalProducts = 0;
$totalCategories = 0;
$salesTodayCount = 0;
$revenueToday = 0.00;
$lowStockCount = 0;
$outOfStockCount = 0;


$last7DaysLabels = [];
$last7DaysSales = [];
$topProducts = [];
$recentSales = [];
$lowStockItems = [];

try {
    $db = getDB();
    $shopId = Auth::shopId();

    // 1. Total Products (non-archived)
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE shop_id = :shop_id AND archived_at IS NULL");
    $stmt->execute([':shop_id' => $shopId]);
    $totalProducts = (int)$stmt->fetchColumn();

    // 2. Total Categories
    $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE shop_id = :shop_id");
    $stmt->execute([':shop_id' => $shopId]);
    $totalCategories = (int)$stmt->fetchColumn();

    // 3. Today's Sales Count & Revenue (Completed only)
    $stmt = $db->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS revenue
        FROM sales
        WHERE shop_id = :shop_id 
          AND status = 'completed'
          AND created_at >= CURRENT_DATE
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $todayData = $stmt->fetch();
    $salesTodayCount = (int)($todayData['count'] ?? 0);
    $revenueToday = (float)($todayData['revenue'] ?? 0);

    // 4. Low stock (stock > 0 and stock <= min_level)
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM products 
        WHERE shop_id = :shop_id 
          AND archived_at IS NULL 
          AND stock_quantity > 0 
          AND stock_quantity <= min_stock_level
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $lowStockCount = (int)$stmt->fetchColumn();

    // 5. Out of stock (stock <= 0)
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM products 
        WHERE shop_id = :shop_id 
          AND archived_at IS NULL 
          AND stock_quantity <= 0
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $outOfStockCount = (int)$stmt->fetchColumn();

    // 6. Last 7 Days Sales Trend
    // Generate dates for past 7 days
    $datesMap = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $label = date('D, M j', strtotime("-$i days"));
        $datesMap[$d] = [
            'label' => $label,
            'total' => 0.00
        ];
    }

    $sixDaysAgo = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $stmt = $db->prepare("
        SELECT TO_CHAR(created_at, 'YYYY-MM-DD') AS day, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE shop_id = :shop_id 
          AND status = 'completed'
          AND created_at >= :six_days_ago
        GROUP BY day
        ORDER BY day ASC
    ");
    $stmt->execute([
        ':shop_id' => $shopId,
        ':six_days_ago' => $sixDaysAgo
    ]);
    $dailyResults = $stmt->fetchAll();

    foreach ($dailyResults as $row) {
        $dayKey = $row['day'];
        if (isset($datesMap[$dayKey])) {
            $datesMap[$dayKey]['total'] = (float)$row['total'];
        }
    }

    foreach ($datesMap as $item) {
        $last7DaysLabels[] = $item['label'];
        $last7DaysSales[] = $item['total'];
    }

    // 7. Top 5 Selling Products
    $stmt = $db->prepare("
        SELECT si.product_name_snapshot AS name, SUM(si.quantity) AS qty_sold, SUM(si.line_total) AS total_revenue
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE s.shop_id = :shop_id AND s.status = 'completed'
        GROUP BY si.product_name_snapshot
        ORDER BY qty_sold DESC
        LIMIT 5
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $topProducts = $stmt->fetchAll();

    // 8. Recent 5 Sales
    $stmt = $db->prepare("
        SELECT invoice_number, total_amount, payment_method, status, created_at, customer_name
        FROM sales
        WHERE shop_id = :shop_id
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $recentSales = $stmt->fetchAll();

    // 9. Low Stock Items (top 5 to display)
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.sku, p.stock_quantity, p.min_stock_level, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.shop_id = :shop_id 
          AND p.archived_at IS NULL 
          AND p.stock_quantity <= p.min_stock_level
        ORDER BY p.stock_quantity ASC
        LIMIT 5
    ");
    $stmt->execute([':shop_id' => $shopId]);
    $lowStockItems = $stmt->fetchAll();

} catch (Exception $e) {
    // Already surfaced via header's $dbConnectionError
}
?>

<!-- Quick Action Shortcuts -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">
            Shop Overview
        </h2>
        <p style="color: var(--text-muted); font-size: 13px;">Real-time inventory status, daily revenue, and operations</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="sales.php" class="btn btn-primary">
            <i data-lucide="shopping-cart"></i> New Sale (POS)
        </a>
        <a href="product-form.php" class="btn btn-secondary">
            <i data-lucide="plus"></i> Add Product
        </a>
        <a href="purchases.php" class="btn btn-secondary">
            <i data-lucide="truck"></i> Stock Purchase
        </a>
        <a href="reports.php" class="btn btn-secondary">
            <i data-lucide="file-text"></i> Reports
        </a>
    </div>
</div>

<!-- Metric Stat Cards -->
<div class="grid-cols-3">
    <!-- Today Revenue -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i data-lucide="banknote" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Today's Revenue</div>
            <div class="stat-value"><?= formatCurrency($revenueToday) ?></div>
            <div class="stat-subtext"><?= $salesTodayCount ?> completed sale(s) today</div>
        </div>
    </div>

    <!-- Total Products -->
    <div class="stat-card">
        <div class="stat-icon blue">
            <i data-lucide="boxes" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Catalog Products</div>
            <div class="stat-value"><?= number_format($totalProducts) ?></div>
            <div class="stat-subtext">Across <?= $totalCategories ?> categories</div>
        </div>
    </div>

    <!-- Low & Out of Stock -->
    <div class="stat-card">
        <div class="stat-icon <?= ($outOfStockCount > 0) ? 'rose' : (($lowStockCount > 0) ? 'amber' : 'teal') ?>">
            <i data-lucide="alert-triangle" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Stock Alerts</div>
            <div class="stat-value"><?= ($lowStockCount + $outOfStockCount) ?></div>
            <div class="stat-subtext">
                <span style="color: var(--rose); font-weight: 600;"><?= $outOfStockCount ?> out of stock</span>, 
                <span style="color: var(--amber); font-weight: 600;"><?= $lowStockCount ?> low</span>
            </div>
        </div>
    </div>
</div>

<!-- Main Section: Sales Chart & Low Stock Alerts -->
<div class="grid-cols-3">
    <!-- 7-Day Sales Trend (2 Cols) -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="trending-up" style="color: var(--primary);"></i>
                Sales Trend (Last 7 Days)
            </h3>
            <span class="badge badge-info">Completed Sales</span>
        </div>
        <div class="card-body">
            <div style="height: 280px; position: relative;">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Low Stock Quick Alert Pane (1 Col) -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="alert-circle" style="color: var(--amber);"></i>
                Attention Required
            </h3>
            <a href="notifications.php" style="font-size: 12px; font-weight: 600;">View All</a>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($lowStockItems)): ?>
                <div class="empty-state" style="padding: 36px 16px;">
                    <i data-lucide="check-circle" style="width: 36px; height: 36px; color: var(--green); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">Healthy Stock</p>
                    <p style="font-size: 12px; color: var(--text-muted);">No products are below minimum thresholds.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: right;">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockItems as $item): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= h($item['name']) ?></div>
                                        <small style="color: var(--text-light);"><?= h($item['sku']) ?></small>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php $badge = getStockBadge($item['stock_quantity'], $item['min_stock_level']); ?>
                                        <span class="badge <?= $badge['class'] ?>">
                                            <?= $item['stock_quantity'] ?> left
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lower Section: Recent Sales & Top Products -->
<div class="grid-cols-2">
    <!-- Recent Sales -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="clock" style="color: var(--teal);"></i>
                Recent Sales Transactions
            </h3>
            <a href="sales-history.php" style="font-size: 12px; font-weight: 600;">View All</a>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($recentSales)): ?>
                <div class="empty-state" style="padding: 36px 16px;">
                    <i data-lucide="shopping-bag" style="width: 36px; height: 36px; color: var(--text-light); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No Sales Yet</p>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Start by ringing up your first sale at POS.</p>
                    <a href="sales.php" class="btn btn-primary btn-sm">Go to POS</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Payment</th>
                                <th>Date</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td>
                                        <a href="sales-history.php?search=<?= urlencode($sale['invoice_number']) ?>" style="font-weight: 600;">
                                            <?= h($sale['invoice_number']) ?>
                                        </a>
                                        <?php if (!empty($sale['customer_name'])): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);"><?= h($sale['customer_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary"><?= h($sale['payment_method']) ?></span>
                                    </td>
                                    <td style="color: var(--text-muted); font-size: 12px;">
                                        <?= formatDate($sale['created_at'], 'd M, h:i A') ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: var(--text-dark);">
                                        <?= formatCurrency($sale['total_amount']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Selling Products -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="award" style="color: var(--primary);"></i>
                Top Selling Items
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($topProducts)): ?>
                <div class="empty-state" style="padding: 36px 16px;">
                    <i data-lucide="package-search" style="width: 36px; height: 36px; color: var(--text-light); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No Data Yet</p>
                    <p style="font-size: 12px; color: var(--text-muted);">Top items will automatically calculate as sales are recorded.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: center;">Units Sold</th>
                                <th style="text-align: right;">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $top): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= h($top['name']) ?></td>
                                    <td style="text-align: center;">
                                        <span class="badge badge-info"><?= $top['qty_sold'] ?> units</span>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: var(--green);">
                                        <?= formatCurrency($top['total_revenue']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Initialize 7-Day Chart
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('salesTrendChart');
        if (ctx) {
            const labels = <?= json_encode($last7DaysLabels) ?>;
            const data = <?= json_encode($last7DaysSales) ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Sales Revenue (₹)',
                        data: data,
                        borderColor: '#0284c7',
                        backgroundColor: 'rgba(2, 132, 199, 0.08)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#0284c7',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ' ₹' + Number(context.parsed.y).toLocaleString('en-IN', { minimumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(val) {
                                    return '₹' + val.toLocaleString('en-IN');
                                }
                            },
                            grid: { color: '#f1f5f9' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
