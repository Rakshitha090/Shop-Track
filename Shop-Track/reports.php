<?php
/**
 * ShopTrack - Comprehensive Reports & Financial Analytics
 */

$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();

// Date range (defaults to current month: 1st of month to today)
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Financial summary metrics
$grossSales = 0.00;
$totalDiscounts = 0.00;
$netSales = 0.00;
$totalCostOfGoodsSold = 0.00;
$estimatedProfit = 0.00;
$completedOrdersCount = 0;

$inventoryCostValuation = 0.00;
$inventoryRetailValuation = 0.00;

$paymentBreakdown = [];
$dailyChartLabels = [];
$dailyChartData = [];
$topProductsList = [];
$categoryValuation = [];

try {
    $db = getDB();

    // 1. Sales & Profit Analytics within Date Range
    $salesSummaryStmt = $db->prepare("
        SELECT 
            COUNT(id) AS orders_count,
            COALESCE(SUM(subtotal), 0) AS gross,
            COALESCE(SUM(discount_amount), 0) AS discount,
            COALESCE(SUM(total_amount), 0) AS net
        FROM sales
        WHERE shop_id = :shop_id 
          AND status = 'completed'
          AND created_at >= :start_date 
          AND created_at <= :end_date
    ");
    $salesSummaryStmt->execute([
        ':shop_id' => $shopId,
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $summary = $salesSummaryStmt->fetch();

    $completedOrdersCount = (int)($summary['orders_count'] ?? 0);
    $grossSales = (float)($summary['gross'] ?? 0);
    $totalDiscounts = (float)($summary['discount'] ?? 0);
    $netSales = (float)($summary['net'] ?? 0);

    // Cost of goods sold (COGS) for completed sales in this range
    $cogsStmt = $db->prepare("
        SELECT COALESCE(SUM(si.unit_cost * si.quantity), 0) AS total_cost
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE s.shop_id = :shop_id 
          AND s.status = 'completed'
          AND s.created_at >= :start_date 
          AND s.created_at <= :end_date
    ");
    $cogsStmt->execute([
        ':shop_id' => $shopId,
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $totalCostOfGoodsSold = (float)$cogsStmt->fetchColumn();
    $estimatedProfit = max(0.00, round($netSales - $totalCostOfGoodsSold, 2));

    // 2. Current Inventory Valuation (Cost & Retail)
    $invValStmt = $db->prepare("
        SELECT 
            COALESCE(SUM(purchase_price * stock_quantity), 0) AS cost_valuation,
            COALESCE(SUM(selling_price * stock_quantity), 0) AS retail_valuation
        FROM products
        WHERE shop_id = :shop_id AND archived_at IS NULL
    ");
    $invValStmt->execute([':shop_id' => $shopId]);
    $invVal = $invValStmt->fetch();
    $inventoryCostValuation = (float)($invVal['cost_valuation'] ?? 0);
    $inventoryRetailValuation = (float)($invVal['retail_valuation'] ?? 0);

    // 3. Payment Method Breakdown
    $pmStmt = $db->prepare("
        SELECT payment_method, COUNT(*) AS count, SUM(total_amount) AS total
        FROM sales
        WHERE shop_id = :shop_id 
          AND status = 'completed'
          AND created_at >= :start_date 
          AND created_at <= :end_date
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $pmStmt->execute([
        ':shop_id' => $shopId,
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $paymentBreakdown = $pmStmt->fetchAll();

    // 4. Daily Sales Chart
    $dailyStmt = $db->prepare("
        SELECT TO_CHAR(created_at, 'YYYY-MM-DD') AS day, COALESCE(SUM(total_amount), 0) AS daily_total
        FROM sales
        WHERE shop_id = :shop_id 
          AND status = 'completed'
          AND created_at >= :start_date 
          AND created_at <= :end_date
        GROUP BY day
        ORDER BY day ASC
    ");
    $dailyStmt->execute([
        ':shop_id' => $shopId,
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $dailyRows = $dailyStmt->fetchAll();
    foreach ($dailyRows as $r) {
        $dailyChartLabels[] = date('M j', strtotime($r['day']));
        $dailyChartData[] = (float)$r['daily_total'];
    }

    // 5. Top 10 Products by Revenue in this Period
    $topStmt = $db->prepare("
        SELECT 
            si.product_name_snapshot AS name,
            si.sku_snapshot AS sku,
            SUM(si.quantity) AS qty_sold,
            SUM(si.line_total) AS total_rev,
            SUM((si.unit_price - si.unit_cost) * si.quantity) AS gross_margin
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE s.shop_id = :shop_id 
          AND s.status = 'completed'
          AND s.created_at >= :start_date 
          AND s.created_at <= :end_date
        GROUP BY si.product_name_snapshot, si.sku_snapshot
        ORDER BY total_rev DESC
        LIMIT 10
    ");
    $topStmt->execute([
        ':shop_id' => $shopId,
        ':start_date' => $startDate . ' 00:00:00',
        ':end_date' => $endDate . ' 23:59:59'
    ]);
    $topProductsList = $topStmt->fetchAll();

    // 6. Inventory Valuation by Category
    $catValStmt = $db->prepare("
        SELECT 
            COALESCE(c.name, 'Uncategorized') AS category_name,
            COUNT(p.id) AS item_count,
            SUM(p.stock_quantity) AS total_units,
            SUM(p.purchase_price * p.stock_quantity) AS cost_value,
            SUM(p.selling_price * p.stock_quantity) AS retail_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.shop_id = :shop_id AND p.archived_at IS NULL
        GROUP BY c.name
        ORDER BY cost_value DESC
    ");
    $catValStmt->execute([':shop_id' => $shopId]);
    $categoryValuation = $catValStmt->fetchAll();

} catch (Exception $e) {
    // Handled in header
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Financial & Inventory Reports</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Audited revenue metrics, sales trends, inventory valuations, and CSV exports</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;" class="no-print">
        <a href="api/reports/index.php?export=csv&type=sales&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>" class="btn btn-secondary">
            <i data-lucide="download"></i> Export Sales CSV
        </a>
        <a href="api/reports/index.php?export=csv&type=inventory" class="btn btn-secondary">
            <i data-lucide="download"></i> Export Inventory CSV
        </a>
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i data-lucide="printer"></i> Print Report
        </button>
    </div>
</div>

<!-- Date Filter Ribbon -->
<div class="card no-print" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="reports.php" class="filter-bar" style="margin-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="font-weight: 600; font-size: 13px; color: var(--text-dark);">Reporting Period:</span>
                <input type="date" name="start_date" class="form-control" style="width: auto;" value="<?= h($startDate) ?>" required>
                <span style="color: var(--text-muted); font-size: 13px;">to</span>
                <input type="date" name="end_date" class="form-control" style="width: auto;" value="<?= h($endDate) ?>" required>
                <button type="submit" class="btn btn-secondary">Generate Report</button>
            </div>

            <!-- Preset Shortcuts -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                <a href="reports.php?start_date=<?= date('Y-m-d') ?>&end_date=<?= date('Y-m-d') ?>" class="page-btn">Today</a>
                <a href="reports.php?start_date=<?= date('Y-m-d', strtotime('-6 days')) ?>&end_date=<?= date('Y-m-d') ?>" class="page-btn">Last 7 Days</a>
                <a href="reports.php?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-d') ?>" class="page-btn">This Month</a>
                <a href="reports.php?start_date=2020-01-01&end_date=<?= date('Y-m-d') ?>" class="page-btn">All Time</a>
            </div>
        </form>
    </div>
</div>

<!-- Executive Summary Metric Cards -->
<div class="grid-cols-4">
    <!-- Gross Sales -->
    <div class="stat-card">
        <div class="stat-icon blue">
            <i data-lucide="calculator" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Gross Sales</div>
            <div class="stat-value"><?= formatCurrency($grossSales) ?></div>
            <div class="stat-subtext"><?= $completedOrdersCount ?> completed orders</div>
        </div>
    </div>

    <!-- Total Discounts -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i data-lucide="percent" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Discounts Deducted</div>
            <div class="stat-value" style="color: var(--rose);">-<?= formatCurrency($totalDiscounts) ?></div>
            <div class="stat-subtext">Promotion price cuts</div>
        </div>
    </div>

    <!-- Net Revenue -->
    <div class="stat-card">
        <div class="stat-icon green">
            <i data-lucide="wallet" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Net Sales Revenue</div>
            <div class="stat-value" style="color: var(--green);"><?= formatCurrency($netSales) ?></div>
            <div class="stat-subtext">Actual money received</div>
        </div>
    </div>

    <!-- Estimated Gross Profit -->
    <div class="stat-card">
        <div class="stat-icon teal">
            <i data-lucide="trending-up" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Est. Gross Margin</div>
            <div class="stat-value"><?= formatCurrency($estimatedProfit) ?></div>
            <div class="stat-subtext" title="Net Sales minus recorded Product Purchase Cost. Excludes rent/utilities.">
                Based on purchase costs*
            </div>
        </div>
    </div>
</div>

<!-- Inventory Asset Valuation Notice Strip -->
<div class="card" style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-color: #bbf7d0; margin-bottom: 24px;">
    <div class="card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="boxes" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <h3 style="font-size: 15px; font-weight: 700; color: #065f46;">Current Inventory Asset Valuation</h3>
                <p style="font-size: 12px; color: #047857;">Total warehouse & shop stock evaluated at current supplier cost and retail sale value.</p>
            </div>
        </div>
        <div style="display: flex; gap: 24px;">
            <div>
                <span style="font-size: 11px; text-transform: uppercase; color: #047857; font-weight: 600;">Cost Valuation</span>
                <div style="font-size: 18px; font-weight: 700; color: #065f46;"><?= formatCurrency($inventoryCostValuation) ?></div>
            </div>
            <div>
                <span style="font-size: 11px; text-transform: uppercase; color: #047857; font-weight: 600;">Retail Market Value</span>
                <div style="font-size: 18px; font-weight: 700; color: var(--primary);"><?= formatCurrency($inventoryRetailValuation) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Section: Daily Sales & Payment Breakdown -->
<div class="grid-cols-3">
    <!-- Daily Trend Chart (2 Cols) -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="bar-chart-2" style="color: var(--primary);"></i>
                Daily Sales Revenue
            </h3>
            <span class="badge badge-info"><?= formatDate($startDate, 'd M Y') ?> – <?= formatDate($endDate, 'd M Y') ?></span>
        </div>
        <div class="card-body">
            <div style="height: 280px; position: relative;">
                <canvas id="dailyRevenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Payment Modes Chart (1 Col) -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="pie-chart" style="color: var(--teal);"></i>
                Sales by Payment Mode
            </h3>
        </div>
        <div class="card-body">
            <div style="height: 220px; position: relative; margin-bottom: 12px;">
                <canvas id="paymentPieChart"></canvas>
            </div>
            <div style="border-top: 1px solid var(--border-color); padding-top: 10px;">
                <?php foreach ($paymentBreakdown as $pm): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                        <span><?= h($pm['payment_method']) ?> (<?= $pm['count'] ?> orders)</span>
                        <strong><?= formatCurrency($pm['total']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tables: Top Selling Products & Category Inventory Breakdown -->
<div class="grid-cols-2">
    <!-- Top Selling Products -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="award" style="color: var(--amber);"></i>
                Top Performing Products
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($topProductsList)): ?>
                <div class="empty-state" style="padding: 36px 16px;">
                    <i data-lucide="package-search" style="width: 32px; height: 32px; color: var(--text-light); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No product sales recorded in this date range</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Revenue</th>
                                <th style="text-align: right;">Est. Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProductsList as $tp): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= h($tp['name']) ?></div>
                                        <small style="color: var(--text-light);"><?= h($tp['sku']) ?></small>
                                    </td>
                                    <td style="text-align: center; font-weight: 600;">
                                        <?= $tp['qty_sold'] ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: var(--text-dark);">
                                        <?= formatCurrency($tp['total_rev']) ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 600; color: var(--green);">
                                        <?= formatCurrency($tp['gross_margin']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inventory Valuation by Category -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="layers" style="color: var(--primary);"></i>
                Inventory Valuation by Category
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($categoryValuation)): ?>
                <div class="empty-state" style="padding: 36px 16px;">
                    <i data-lucide="folder-x" style="width: 32px; height: 32px; color: var(--text-light); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No inventory records available</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th style="text-align: center;">Items</th>
                                <th style="text-align: right;">Cost Value</th>
                                <th style="text-align: right;">Retail Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryValuation as $cv): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= h($cv['category_name']) ?></td>
                                    <td style="text-align: center;">
                                        <?= $cv['item_count'] ?> (<?= (int)$cv['total_units'] ?> units)
                                    </td>
                                    <td style="text-align: right; color: var(--text-muted);">
                                        <?= formatCurrency($cv['cost_value']) ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: var(--text-dark);">
                                        <?= formatCurrency($cv['retail_value']) ?>
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

<p style="font-size: 11px; color: var(--text-light); margin-top: 10px; margin-bottom: 24px;">
    * <strong>Disclaimer:</strong> Estimated Gross Margin is calculated as <code>Net Sales − Recorded Cost of Goods Sold</code>. It does not include shop expenses, salaries, rent, taxes, or depreciation untracked by the application.
</p>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Daily Chart
        const dailyCanvas = document.getElementById('dailyRevenueChart');
        if (dailyCanvas) {
            const labels = <?= json_encode($dailyChartLabels) ?>;
            const data = <?= json_encode($dailyChartData) ?>;

            new Chart(dailyCanvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Net Sales',
                        data: data,
                        backgroundColor: '#0284c7',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: val => '₹' + Number(val).toLocaleString('en-IN')
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

        // Payment Pie Chart
        const pieCanvas = document.getElementById('paymentPieChart');
        if (pieCanvas) {
            const pmData = <?= json_encode($paymentBreakdown) ?>;
            const labels = pmData.map(p => p.payment_method);
            const totals = pmData.map(p => parseFloat(p.total));

            new Chart(pieCanvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: totals,
                        backgroundColor: ['#10b981', '#0284c7', '#0d9488', '#f59e0b', '#8b5cf6'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
