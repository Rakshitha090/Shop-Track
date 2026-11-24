<?php
/**
 * ShopTrack - Inventory Notifications & System Alerts
 */

$pageTitle = 'Inventory Notifications';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();

$outOfStock = [];
$lowStock = [];
$recentPurchases = [];
$recentAdjustments = [];

try {
    $db = getDB();

    // 1. Out of stock items
    $outStmt = $db->prepare("
        SELECT p.id, p.name, p.sku, p.stock_quantity, p.min_stock_level, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.shop_id = :shop_id 
          AND p.archived_at IS NULL 
          AND p.stock_quantity <= 0
        ORDER BY p.name ASC
    ");
    $outStmt->execute([':shop_id' => $shopId]);
    $outOfStock = $outStmt->fetchAll();

    // 2. Low stock items
    $lowStmt = $db->prepare("
        SELECT p.id, p.name, p.sku, p.stock_quantity, p.min_stock_level, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.shop_id = :shop_id 
          AND p.archived_at IS NULL 
          AND p.stock_quantity > 0 
          AND p.stock_quantity <= p.min_stock_level
        ORDER BY p.stock_quantity ASC
    ");
    $lowStmt->execute([':shop_id' => $shopId]);
    $lowStock = $lowStmt->fetchAll();

    // 3. Recently received purchases (Last 7 days)
    $sevenDaysAgo = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $purStmt = $db->prepare("
        SELECT p.id, p.total_amount, p.supplier_invoice_number, p.received_at, s.name AS supplier_name
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.shop_id = :shop_id 
          AND p.received_at >= :since_date
        ORDER BY p.received_at DESC
        LIMIT 10
    ");
    $purStmt->execute([
        ':shop_id' => $shopId,
        ':since_date' => $sevenDaysAgo
    ]);
    $recentPurchases = $purStmt->fetchAll();

    // 4. Recent stock adjustments (Last 7 days)
    $adjStmt = $db->prepare("
        SELECT sm.*, p.name AS product_name, p.sku AS product_sku
        FROM stock_movements sm
        JOIN products p ON sm.product_id = p.id
        WHERE sm.shop_id = :shop_id 
          AND sm.movement_type IN ('adjustment_add', 'adjustment_remove', 'sale_cancellation')
          AND sm.created_at >= :since_date
        ORDER BY sm.created_at DESC
        LIMIT 10
    ");
    $adjStmt->execute([
        ':shop_id' => $shopId,
        ':since_date' => $sevenDaysAgo
    ]);
    $recentAdjustments = $adjStmt->fetchAll();

} catch (Exception $e) {
    // Handled in header
}

$totalAlerts = count($outOfStock) + count($lowStock);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Inventory Alerts & Notifications</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Real-time stock alerts and recent warehouse events</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="purchases.php" class="btn btn-primary">
            <i data-lucide="truck"></i> Stock Replenishment
        </a>
    </div>
</div>

<!-- Alert Overview -->
<div class="grid-cols-2">
    <!-- Out of Stock Warnings -->
    <div class="card">
        <div class="card-header" style="background: #fff5f5; border-color: #fed7d7;">
            <h3 class="card-title" style="color: var(--rose);">
                <i data-lucide="alert-octagon" style="color: var(--rose);"></i>
                Out of Stock (<?= count($outOfStock) ?>)
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($outOfStock)): ?>
                <div class="empty-state" style="padding: 32px 16px;">
                    <i data-lucide="check-circle" style="width: 32px; height: 32px; color: var(--green); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No Out-of-Stock Items</p>
                    <p style="font-size: 12px; color: var(--text-muted);">All active catalog items currently have stock available.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outOfStock as $item): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--rose);"><?= h($item['name']) ?></div>
                                        <small style="color: var(--text-light);"><?= h($item['sku']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary"><?= h($item['category_name'] ?? 'General') ?></span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="purchases.php" class="btn btn-secondary btn-sm" title="Purchase Stock">
                                            <i data-lucide="plus" style="width: 12px; height: 12px;"></i> Reorder
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Low Stock Warnings -->
    <div class="card">
        <div class="card-header" style="background: #fffbeb; border-color: #fef3c7;">
            <h3 class="card-title" style="color: var(--amber);">
                <i data-lucide="alert-triangle" style="color: var(--amber);"></i>
                Low Stock Threshold Alerts (<?= count($lowStock) ?>)
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($lowStock)): ?>
                <div class="empty-state" style="padding: 32px 16px;">
                    <i data-lucide="check-circle" style="width: 32px; height: 32px; color: var(--green); margin-bottom: 8px;"></i>
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-dark);">No Low Stock Alerts</p>
                    <p style="font-size: 12px; color: var(--text-muted);">All product inventory levels are above their minimum thresholds.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th style="text-align: center;">Stock</th>
                                <th style="text-align: center;">Min Level</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStock as $item): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;"><?= h($item['name']) ?></div>
                                        <small style="color: var(--text-light);"><?= h($item['sku']) ?></small>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge badge-warning"><?= $item['stock_quantity'] ?> left</span>
                                    </td>
                                    <td style="text-align: center; color: var(--text-muted); font-size: 12px;">
                                        <?= $item['min_stock_level'] ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="purchases.php" class="btn btn-secondary btn-sm" title="Purchase Stock">
                                            <i data-lucide="plus" style="width: 12px; height: 12px;"></i> Restock
                                        </a>
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

<!-- Recent Stock Events (Purchases & Adjustments) -->
<div class="grid-cols-2">
    <!-- Recent Purchases -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="truck" style="color: var(--teal);"></i>
                Recent Incoming Purchases (7 Days)
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($recentPurchases)): ?>
                <div class="empty-state" style="padding: 32px 16px;">
                    <p style="font-size: 13px; color: var(--text-muted);">No supplier purchases received in the last 7 days.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Bill #</th>
                                <th>Date</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPurchases as $p): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= h($p['supplier_name']) ?></td>
                                    <td><?= h($p['supplier_invoice_number'] ?? '—') ?></td>
                                    <td style="color: var(--text-muted);"><?= formatDate($p['received_at'], 'd M, h:i A') ?></td>
                                    <td style="text-align: right; font-weight: 700;"><?= formatCurrency($p['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Adjustments -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i data-lucide="sliders-horizontal" style="color: var(--primary);"></i>
                Recent Stock Adjustments & Corrections (7 Days)
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($recentAdjustments)): ?>
                <div class="empty-state" style="padding: 32px 16px;">
                    <p style="font-size: 13px; color: var(--text-muted);">No manual stock adjustments made in the last 7 days.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: center;">Change</th>
                                <th>Reason</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAdjustments as $adj): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= h($adj['product_name']) ?></td>
                                    <td style="text-align: center; font-weight: 700;">
                                        <?= $adj['quantity_change'] > 0 ? "+{$adj['quantity_change']}" : $adj['quantity_change'] ?>
                                    </td>
                                    <td style="color: var(--text-muted); max-width: 180px;"><?= h($adj['reason'] ?? '—') ?></td>
                                    <td style="color: var(--text-muted); font-size: 11px;"><?= formatDate($adj['created_at'], 'd M, h:i A') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
