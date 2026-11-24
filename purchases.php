<?php
/**
 * ShopTrack - Purchases & Incoming Stock
 */

$pageTitle = 'Stock Purchases';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$search = trim($_GET['search'] ?? '');
$supplierFilter = $_GET['supplier_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$suppliers = [];
$products = [];
$purchases = [];
$totalPurchasesCount = 0;
$totalPurchasesSum = 0.00;

try {
    $db = getDB();

    // 1. Fetch active suppliers
    $supStmt = $db->prepare("SELECT id, name FROM suppliers WHERE shop_id = :shop_id AND archived_at IS NULL ORDER BY name ASC");
    $supStmt->execute([':shop_id' => $shopId]);
    $suppliers = $supStmt->fetchAll();

    // 2. Fetch catalog products for purchase creation
    $prodStmt = $db->prepare("SELECT id, name, sku, purchase_price, stock_quantity FROM products WHERE shop_id = :shop_id AND archived_at IS NULL ORDER BY name ASC");
    $prodStmt->execute([':shop_id' => $shopId]);
    $products = $prodStmt->fetchAll();

    // 3. Purchases Query
    $where = ["p.shop_id = :shop_id"];
    $params = [':shop_id' => $shopId];

    if (!empty($search)) {
        $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $where[] = "(p.supplier_invoice_number {$like} :search OR s.name {$like} :search)";
        $params[':search'] = "%{$search}%";
    }

    if (!empty($supplierFilter)) {
        $where[] = "p.supplier_id = :sup_id";
        $params[':sup_id'] = $supplierFilter;
    }

    if (!empty($statusFilter)) {
        $where[] = "p.payment_status = :status";
        $params[':status'] = $statusFilter;
    }

    if (!empty($startDate)) {
        $where[] = "p.received_at >= :start_date";
        $params[':start_date'] = $startDate . ' 00:00:00';
    }

    if (!empty($endDate)) {
        $where[] = "p.received_at <= :end_date";
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    $whereClause = implode(" AND ", $where);

    $summaryStmt = $db->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(p.total_amount), 0) AS total_sum
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        WHERE {$whereClause}
    ");
    $summaryStmt->execute($params);
    $sumData = $summaryStmt->fetch();
    $totalPurchasesCount = (int)($sumData['count'] ?? 0);
    $totalPurchasesSum = (float)($sumData['total_sum'] ?? 0);
    $totalPages = ceil($totalPurchasesCount / $perPage);

    $sql = "
        SELECT p.*, s.name AS supplier_name, u.full_name AS received_by_name
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE {$whereClause}
        ORDER BY p.received_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $purchases = $stmt->fetchAll();

} catch (Exception $e) {
    // Handled in header
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Stock Purchases & Inward Goods</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Record incoming supplier shipments to replenish your inventory stock</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openPurchaseModal()">
        <i data-lucide="plus"></i> Record Stock Purchase
    </button>
</div>

<!-- Search & Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="purchases.php" class="filter-bar" style="margin-bottom: 0;">
            <div class="search-box">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Search bill number, supplier..." value="<?= h($search) ?>">
            </div>

            <div class="filter-actions">
                <select name="supplier_id" class="form-control" style="width: auto; min-width: 170px;" onchange="this.form.submit()">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= h($sup['id']) ?>" <?= $supplierFilter === $sup['id'] ? 'selected' : '' ?>>
                            <?= h($sup['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
                    <option value="">All Payment Statuses</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                </select>

                <input type="date" name="start_date" class="form-control" style="width: auto;" value="<?= h($startDate) ?>" title="Start Date">
                <span style="color: var(--text-muted); font-size: 12px;">to</span>
                <input type="date" name="end_date" class="form-control" style="width: auto;" value="<?= h($endDate) ?>" title="End Date">

                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if (!empty($search) || !empty($supplierFilter) || !empty($statusFilter) || !empty($startDate) || !empty($endDate)): ?>
                    <a href="purchases.php" class="btn btn-secondary" title="Reset Filters">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Purchases Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($purchases)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="clipboard-list" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No purchases recorded</h3>
                <p class="empty-state-desc">Record purchases from your suppliers to automatically increase inventory levels.</p>
                <button type="button" class="btn btn-primary" onclick="openPurchaseModal()">
                    <i data-lucide="plus"></i> Record New Purchase
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date Received</th>
                            <th>Supplier</th>
                            <th>Bill / Invoice #</th>
                            <th style="text-align: right;">Total Amount</th>
                            <th style="text-align: center;">Payment Status</th>
                            <th>Received By</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purchases as $pur): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-size: 12px; white-space: nowrap;">
                                    <?= formatDate($pur['received_at']) ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?= h($pur['supplier_name']) ?></div>
                                    <?php if (!empty($pur['notes'])): ?>
                                        <small style="color: var(--text-light);"><?= h($pur['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-size: 12px; background: var(--border-light); padding: 2px 6px; border-radius: 4px;">
                                        <?= h($pur['supplier_invoice_number'] ?? '—') ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--text-dark); font-size: 14px;">
                                    <?= formatCurrency($pur['total_amount']) ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php
                                        $badgeClass = match($pur['payment_status']) {
                                            'paid' => 'badge-success',
                                            'pending' => 'badge-danger',
                                            'partial' => 'badge-warning',
                                            default => 'badge-secondary',
                                        };
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= ucfirst(h($pur['payment_status'])) ?></span>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted);">
                                    <?= h($pur['received_by_name'] ?? 'Owner') ?>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="viewPurchaseDetails('<?= $pur['id'] ?>')" title="View Purchase Details">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i> Details
                                    </button>
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
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalPurchasesCount) ?> of <?= $totalPurchasesCount ?> purchase records
                    </div>
                    <div class="pagination-links">
                        <?php 
                            $queryParams = $_GET;
                            $buildUrl = function($pageNum) use ($queryParams) {
                                $queryParams['page'] = $pageNum;
                                return 'purchases.php?' . http_build_query($queryParams);
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

<!-- Record Purchase Modal -->
<div class="modal-backdrop" id="purchaseModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Record Stock Purchase</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('purchaseModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="purchase-form" onsubmit="handlePurchaseSubmit(event)">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="pur-supplier">Supplier <span class="required">*</span></label>
                        <select id="pur-supplier" name="supplier_id" class="form-control" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= h($s['id']) ?>"><?= h($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="pur-invoice">Supplier Bill / Invoice #</label>
                        <input type="text" id="pur-invoice" name="supplier_invoice_number" class="form-control" placeholder="e.g. BILL-98214">
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="pur-pay-status">Payment Status</label>
                        <select id="pur-pay-status" name="payment_status" class="form-control">
                            <option value="paid">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                        </select>
                    </div>
                </div>

                <!-- Line Items Table -->
                <div style="margin: 16px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 600; font-size: 13px; color: var(--text-dark);">Items Received</span>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addPurchaseRow()">
                            <i data-lucide="plus" style="width: 14px; height: 14px;"></i> Add Item
                        </button>
                    </div>

                    <div class="table-responsive" style="border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                        <table class="table" style="margin-bottom: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 45%;">Product</th>
                                    <th style="width: 20%; text-align: center;">Qty</th>
                                    <th style="width: 25%; text-align: right;">Unit Cost (₹)</th>
                                    <th style="width: 10%;"></th>
                                </tr>
                            </thead>
                            <tbody id="purchase-items-table-body">
                                <!-- Dynamic Rows -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #fafbfd; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 16px;">
                    <span style="font-weight: 600; color: var(--text-dark);">Total Purchase Value:</span>
                    <span id="purchase-grand-total" style="font-size: 18px; font-weight: 700; color: var(--primary);">₹0.00</span>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="pur-notes">Notes / Remarks</label>
                    <input type="text" id="pur-notes" name="notes" class="form-control" placeholder="Optional delivery notes or batch details">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('purchaseModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="pur-submit-btn">Receive & Update Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Purchase View Modal -->
<div class="modal-backdrop" id="purchaseViewModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Purchase Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('purchaseViewModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="modal-body" id="pur-view-body">
            <!-- Dynamic Content -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('purchaseViewModal')">Close</button>
        </div>
    </div>
</div>

<script>
    const availableProducts = <?= json_encode($products) ?>;

    function openPurchaseModal() {
        document.getElementById('pur-supplier').value = '';
        document.getElementById('pur-invoice').value = '';
        document.getElementById('pur-pay-status').value = 'paid';
        document.getElementById('pur-notes').value = '';
        document.getElementById('purchase-items-table-body').innerHTML = '';
        addPurchaseRow();
        recalcPurchaseTotal();
        openModal('purchaseModal');
    }

    function addPurchaseRow() {
        const tbody = document.getElementById('purchase-items-table-body');
        const tr = document.createElement('tr');
        tr.className = 'purchase-item-row';
        tr.innerHTML = `
            <td>
                <select class="form-control pur-row-prod" required onchange="handleProductSelect(this)">
                    <option value="">-- Choose Item --</option>
                    ${availableProducts.map(p => `
                        <option value="${p.id}" data-cost="${p.purchase_price}">${escapeHtml(p.name)} (${escapeHtml(p.sku)})</option>
                    `).join('')}
                </select>
            </td>
            <td>
                <input type="number" min="1" value="1" class="form-control pur-row-qty" style="text-align: center;" required oninput="recalcPurchaseTotal()">
            </td>
            <td>
                <input type="number" step="0.01" min="0" value="0.00" class="form-control pur-row-cost" style="text-align: right;" required oninput="recalcPurchaseTotal()">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); recalcPurchaseTotal();" title="Remove Item">
                    <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
        if (window.lucide) lucide.createIcons({ root: tr });
    }

    function handleProductSelect(selectEl) {
        const opt = selectEl.options[selectEl.selectedIndex];
        const cost = opt.getAttribute('data-cost') || '0.00';
        const row = selectEl.closest('tr');
        const costInput = row.querySelector('.pur-row-cost');
        if (costInput) {
            costInput.value = parseFloat(cost).toFixed(2);
        }
        recalcPurchaseTotal();
    }

    function recalcPurchaseTotal() {
        let total = 0;
        document.querySelectorAll('.purchase-item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.pur-row-qty')?.value || 0);
            const cost = parseFloat(row.querySelector('.pur-row-cost')?.value || 0);
            total += (qty * cost);
        });
        const totalEl = document.getElementById('purchase-grand-total');
        if (totalEl) totalEl.textContent = formatMoney(total);
    }

    async function handlePurchaseSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('pur-submit-btn');
        btn.disabled = true;
        btn.textContent = 'Receiving Stock...';

        const items = [];
        document.querySelectorAll('.purchase-item-row').forEach(row => {
            const prodId = row.querySelector('.pur-row-prod')?.value;
            const qty = parseInt(row.querySelector('.pur-row-qty')?.value || 0, 10);
            const cost = parseFloat(row.querySelector('.pur-row-cost')?.value || 0);
            if (prodId && qty > 0) {
                items.push({
                    product_id: prodId,
                    quantity: qty,
                    unit_cost: cost
                });
            }
        });

        if (!items.length) {
            showToast('Please add at least one product with valid quantity.', 'warning');
            btn.disabled = false;
            btn.textContent = 'Receive & Update Stock';
            return;
        }

        const payload = {
            supplier_id: document.getElementById('pur-supplier').value,
            supplier_invoice_number: document.getElementById('pur-invoice').value.trim(),
            payment_status: document.getElementById('pur-pay-status').value,
            notes: document.getElementById('pur-notes').value.trim(),
            items: items
        };

        try {
            const res = await apiRequest('api/purchases/index.php', {
                method: 'POST',
                body: payload
            });

            showToast(res.message, 'success');
            closeModal('purchaseModal');
            setTimeout(() => window.location.reload(), 700);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Receive & Update Stock';
        }
    }

    async function viewPurchaseDetails(purchaseId) {
        openModal('purchaseViewModal');
        const body = document.getElementById('pur-view-body');
        body.innerHTML = `<div style="text-align: center; padding: 24px;"><i data-lucide="loader" class="spin"></i> Loading purchase...</div>`;
        if (window.lucide) lucide.createIcons({ root: body });

        try {
            const data = await apiRequest(`api/purchases/index.php?id=${encodeURIComponent(purchaseId)}`);
            const p = data.purchase;
            const items = data.items || [];

            body.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Supplier</div>
                        <div style="font-weight: 700; color: var(--text-dark);">${escapeHtml(p.supplier_name)}</div>
                        ${p.supplier_phone ? `<div style="font-size: 11px; color: var(--text-muted);">${escapeHtml(p.supplier_phone)}</div>` : ''}
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Supplier Bill / Invoice</div>
                        <div style="font-weight: 600; font-family: monospace;">${escapeHtml(p.supplier_invoice_number || '—')}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Date Received</div>
                        <div style="font-size: 13px;">${new Date(p.received_at).toLocaleString('en-IN')}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Payment Status</div>
                        <div><span class="badge badge-info">${escapeHtml(p.payment_status)}</span></div>
                    </div>
                </div>

                <table class="table" style="font-size: 12px; margin-bottom: 12px;">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="text-align: center;">Qty</th>
                            <th style="text-align: right;">Unit Cost</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(it => `
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">${escapeHtml(it.product_name)}</div>
                                    <small style="color: var(--text-light);">${escapeHtml(it.product_sku)}</small>
                                </td>
                                <td style="text-align: center; font-weight: 600;">+${it.quantity}</td>
                                <td style="text-align: right;">${formatMoney(it.unit_cost)}</td>
                                <td style="text-align: right; font-weight: 700;">${formatMoney(it.line_total)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>

                <div style="display: flex; justify-content: space-between; padding-top: 10px; border-top: 1px dashed var(--border-color); font-weight: 700; font-size: 15px;">
                    <span>Total Purchase:</span>
                    <span style="color: var(--primary);">${formatMoney(p.total_amount)}</span>
                </div>
            `;
            if (window.lucide) lucide.createIcons({ root: body });
        } catch (err) {
            body.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
