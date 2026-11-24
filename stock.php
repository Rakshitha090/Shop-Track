<?php
/**
 * ShopTrack - Stock Management & Movements Audit
 */

$pageTitle = 'Stock Movements';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$productIdFilter = $_GET['product_id'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$productsList = [];
$movements = [];
$totalMovements = 0;

$outOfStockCount = 0;
$lowStockCount = 0;
$inStockCount = 0;

try {
    $db = getDB();

    // 1. Fetch products list for dropdowns
    $pListStmt = $db->prepare("
        SELECT id, name, sku, stock_quantity, min_stock_level 
        FROM products 
        WHERE shop_id = :shop_id AND archived_at IS NULL 
        ORDER BY name ASC
    ");
    $pListStmt->execute([':shop_id' => $shopId]);
    $productsList = $pListStmt->fetchAll();

    // Stock stats
    foreach ($productsList as $p) {
        if ($p['stock_quantity'] <= 0) {
            $outOfStockCount++;
        } elseif ($p['stock_quantity'] <= $p['min_stock_level']) {
            $lowStockCount++;
        } else {
            $inStockCount++;
        }
    }

    // 2. Build Movements Query
    $where = ["sm.shop_id = :shop_id"];
    $params = [':shop_id' => $shopId];

    if (!empty($productIdFilter)) {
        $where[] = "sm.product_id = :product_id";
        $params[':product_id'] = $productIdFilter;
    }

    if (!empty($typeFilter)) {
        $where[] = "sm.movement_type = :type";
        $params[':type'] = $typeFilter;
    }

    if (!empty($startDate)) {
        $where[] = "sm.created_at >= :start_date";
        $params[':start_date'] = $startDate . ' 00:00:00';
    }

    if (!empty($endDate)) {
        $where[] = "sm.created_at <= :end_date";
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $whereClause = implode(" AND ", $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements sm WHERE {$whereClause}");
    $countStmt->execute($params);
    $totalMovements = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalMovements / $perPage);

    $sql = "
        SELECT sm.*, p.name AS product_name, p.sku AS product_sku, u.full_name AS staff_name
        FROM stock_movements sm
        JOIN products p ON sm.product_id = p.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE {$whereClause}
        ORDER BY sm.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $movements = $stmt->fetchAll();

} catch (Exception $e) {
    // Handled in header
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Stock & Inventory Audit</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Complete transactional ledger of all stock additions, sales, and corrections</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openAdjustmentModal()">
        <i data-lucide="sliders-horizontal"></i> Record Stock Adjustment
    </button>
</div>

<!-- Stock Health Summary Tiles -->
<div class="grid-cols-3">
    <div class="stat-card">
        <div class="stat-icon green">
            <i data-lucide="check-circle" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">In Stock (Healthy)</div>
            <div class="stat-value"><?= $inStockCount ?></div>
            <div class="stat-subtext">Adequate inventory levels</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i data-lucide="alert-circle" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Low Stock</div>
            <div class="stat-value"><?= $lowStockCount ?></div>
            <div class="stat-subtext">At or below reorder threshold</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i data-lucide="x-circle" style="width: 24px; height: 24px;"></i>
        </div>
        <div class="stat-details">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value"><?= $outOfStockCount ?></div>
            <div class="stat-subtext">Need immediate replenishment</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="stock.php" class="filter-bar" style="margin-bottom: 0;">
            <select name="product_id" class="form-control" style="width: auto; min-width: 200px;">
                <option value="">All Products</option>
                <?php foreach ($productsList as $p): ?>
                    <option value="<?= h($p['id']) ?>" <?= $productIdFilter === $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['name']) ?> (<?= h($p['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type" class="form-control" style="width: auto; min-width: 170px;">
                <option value="">All Movement Types</option>
                <option value="sale" <?= $typeFilter === 'sale' ? 'selected' : '' ?>>Sale Checkout</option>
                <option value="purchase" <?= $typeFilter === 'purchase' ? 'selected' : '' ?>>Supplier Purchase</option>
                <option value="adjustment_add" <?= $typeFilter === 'adjustment_add' ? 'selected' : '' ?>>Adjustment (Added)</option>
                <option value="adjustment_remove" <?= $typeFilter === 'adjustment_remove' ? 'selected' : '' ?>>Adjustment (Removed)</option>
                <option value="initial_stock" <?= $typeFilter === 'initial_stock' ? 'selected' : '' ?>>Initial Stock</option>
                <option value="sale_cancellation" <?= $typeFilter === 'sale_cancellation' ? 'selected' : '' ?>>Sale Refund Reversal</option>
            </select>

            <div class="filter-actions">
                <input type="date" name="start_date" class="form-control" style="width: auto;" value="<?= h($startDate) ?>" title="Start Date">
                <span style="color: var(--text-muted); font-size: 12px;">to</span>
                <input type="date" name="end_date" class="form-control" style="width: auto;" value="<?= h($endDate) ?>" title="End Date">

                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if (!empty($productIdFilter) || !empty($typeFilter) || !empty($startDate) || !empty($endDate)): ?>
                    <a href="stock.php" class="btn btn-secondary" title="Reset Filters">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Stock Movements Audit Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($movements)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="arrow-left-right" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No stock movements recorded</h3>
                <p class="empty-state-desc">Inventory movements occur automatically when you complete sales, receive purchases, or record manual stock adjustments.</p>
                <button type="button" class="btn btn-primary" onclick="openAdjustmentModal()">Record Adjustment</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Product Name</th>
                            <th>Movement Type</th>
                            <th style="text-align: center;">Previous</th>
                            <th style="text-align: center;">Quantity Change</th>
                            <th style="text-align: center;">New Stock</th>
                            <th>Reason / Reference</th>
                            <th>Logged By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 12px; white-space: nowrap;">
                                    <?= formatDate($m['created_at']) ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?= h($m['product_name']) ?></div>
                                    <small style="color: var(--text-light);"><?= h($m['product_sku']) ?></small>
                                </td>
                                <td>
                                    <?php
                                        $typeBadge = match($m['movement_type']) {
                                            'sale' => ['label' => 'Sale', 'class' => 'badge-info'],
                                            'purchase' => ['label' => 'Purchase Stock-In', 'class' => 'badge-success'],
                                            'adjustment_add' => ['label' => 'Manual Added', 'class' => 'badge-success'],
                                            'adjustment_remove' => ['label' => 'Manual Removed', 'class' => 'badge-danger'],
                                            'initial_stock' => ['label' => 'Opening Stock', 'class' => 'badge-secondary'],
                                            'sale_cancellation' => ['label' => 'Refund Restored', 'class' => 'badge-warning'],
                                            default => ['label' => $m['movement_type'], 'class' => 'badge-secondary'],
                                        };
                                    ?>
                                    <span class="badge <?= $typeBadge['class'] ?>"><?= $typeBadge['label'] ?></span>
                                </td>
                                <td style="text-align: center; color: var(--text-muted);">
                                    <?= $m['previous_quantity'] ?>
                                </td>
                                <td style="text-align: center; font-weight: 700;">
                                    <?php if ($m['quantity_change'] > 0): ?>
                                        <span style="color: var(--green);">+<?= $m['quantity_change'] ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--rose);"><?= $m['quantity_change'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: var(--text-dark);">
                                    <?= $m['new_quantity'] ?>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted); max-width: 240px;">
                                    <?= h($m['reason'] ?? '—') ?>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?= h($m['staff_name'] ?? 'System') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalMovements) ?> of <?= $totalMovements ?> movement logs
                    </div>
                    <div class="pagination-links">
                        <?php 
                            $queryParams = $_GET;
                            $buildUrl = function($pageNum) use ($queryParams) {
                                $queryParams['page'] = $pageNum;
                                return 'stock.php?' . http_build_query($queryParams);
                            };
                        ?>
                        <a href="<?= $buildUrl(max(1, $page - 1)) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="<?= $buildUrl($i) ?>" class="page-btn <?= $page === $i ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a href="<?= $buildUrl(min($totalPages, $page + 1)) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal-backdrop" id="stockAdjustmentModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Record Stock Adjustment</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('stockAdjustmentModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="adjustment-form" onsubmit="handleAdjustmentSubmit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="adj-product">Select Product <span class="required">*</span></label>
                    <select id="adj-product" name="product_id" class="form-control" required onchange="updateCurrentStockDisplay()">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($productsList as $p): ?>
                            <option value="<?= h($p['id']) ?>" data-stock="<?= $p['stock_quantity'] ?>">
                                <?= h($p['name']) ?> (Current: <?= $p['stock_quantity'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="adj-type">Action <span class="required">*</span></label>
                        <select id="adj-type" name="type" class="form-control" required>
                            <option value="add">Add Stock (+)</option>
                            <option value="remove">Deduct / Remove Stock (-)</option>
                        </select>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="adj-qty">Quantity <span class="required">*</span></label>
                        <input type="number" min="1" id="adj-qty" name="quantity" class="form-control" placeholder="e.g. 5" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="adj-reason">Reason / Note <span class="required">*</span></label>
                    <textarea id="adj-reason" name="reason" class="form-control" rows="3" placeholder="e.g. Damaged during shipment, inventory count correction, expired item discarded" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('stockAdjustmentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="adj-submit-btn">Apply Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAdjustmentModal() {
        document.getElementById('adj-product').value = '';
        document.getElementById('adj-qty').value = '';
        document.getElementById('adj-reason').value = '';
        openModal('stockAdjustmentModal');
    }

    function updateCurrentStockDisplay() {
        // Optional reactive info update
    }

    async function handleAdjustmentSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('adj-submit-btn');
        btn.disabled = true;
        btn.textContent = 'Updating Stock...';

        const formData = new FormData(document.getElementById('adjustment-form'));

        try {
            const res = await apiRequest('api/stock/index.php', {
                method: 'POST',
                body: formData
            });

            showToast(res.message, 'success');
            closeModal('stockAdjustmentModal');
            setTimeout(() => window.location.reload(), 700);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Apply Adjustment';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
