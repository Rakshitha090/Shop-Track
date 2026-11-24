<?php
/**
 * ShopTrack - Sales History & Invoice Archive
 */

$pageTitle = 'Sales History';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$search = trim($_GET['search'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$sales = [];
$totalSalesCount = 0;
$totalRevenueFiltered = 0.00;

try {
    $db = getDB();

    $where = ["s.shop_id = :shop_id"];
    $params = [':shop_id' => $shopId];

    if (!empty($search)) {
        $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $where[] = "(s.invoice_number {$like} :search OR s.customer_name {$like} :search OR s.customer_phone {$like} :search)";
        $params[':search'] = "%{$search}%";
    }

    if (!empty($startDate)) {
        $where[] = "s.created_at >= :start_date";
        $params[':start_date'] = $startDate . ' 00:00:00';
    }

    if (!empty($endDate)) {
        $where[] = "s.created_at <= :end_date";
        $params[':end_date'] = $endDate . ' 23:59:59';
    }

    if (!empty($paymentMethod)) {
        $where[] = "s.payment_method = :pm";
        $params[':pm'] = $paymentMethod;
    }

    if (!empty($statusFilter)) {
        $where[] = "s.status = :status";
        $params[':status'] = $statusFilter;
    }

    $whereClause = implode(" AND ", $where);

    // Filtered count and total revenue
    $summaryStmt = $db->prepare("
        SELECT COUNT(*) AS count, COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total_amount ELSE 0 END), 0) AS total_revenue
        FROM sales s
        WHERE {$whereClause}
    ");
    $summaryStmt->execute($params);
    $summaryData = $summaryStmt->fetch();
    $totalSalesCount = (int)($summaryData['count'] ?? 0);
    $totalRevenueFiltered = (float)($summaryData['total_revenue'] ?? 0);
    $totalPages = ceil($totalSalesCount / $perPage);

    // Fetch page rows
    $sql = "
        SELECT s.*, u.full_name AS cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE {$whereClause}
        ORDER BY s.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sales = $stmt->fetchAll();

} catch (Exception $e) {
    // Header handles DB error
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Sales Invoices & History</h2>
        <p style="color: var(--text-muted); font-size: 13px;">View historical receipts, filter transactions, reprint, or issue refunds</p>
    </div>
    <a href="sales.php" class="btn btn-primary">
        <i data-lucide="plus"></i> New Sale (POS)
    </a>
</div>

<!-- Filter & Search Controls -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="sales-history.php" class="filter-bar" style="margin-bottom: 0;">
            <div class="search-box">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Search invoice number, customer..." value="<?= h($search) ?>">
            </div>

            <div class="filter-actions">
                <input type="date" name="start_date" class="form-control" style="width: auto;" value="<?= h($startDate) ?>" title="Start Date">
                <span style="color: var(--text-muted); font-size: 12px;">to</span>
                <input type="date" name="end_date" class="form-control" style="width: auto;" value="<?= h($endDate) ?>" title="End Date">

                <select name="payment_method" class="form-control" style="width: auto;" onchange="this.form.submit()">
                    <option value="">All Payment Modes</option>
                    <option value="Cash" <?= $paymentMethod === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="UPI" <?= $paymentMethod === 'UPI' ? 'selected' : '' ?>>UPI</option>
                    <option value="Card" <?= $paymentMethod === 'Card' ? 'selected' : '' ?>>Card</option>
                    <option value="Other" <?= $paymentMethod === 'Other' ? 'selected' : '' ?>>Other</option>
                </select>

                <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled / Refunded</option>
                </select>

                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if (!empty($search) || !empty($startDate) || !empty($endDate) || !empty($paymentMethod) || !empty($statusFilter)): ?>
                    <a href="sales-history.php" class="btn btn-secondary" title="Reset Filters">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Filter Summary Strip -->
<div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 18px; display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Filtered Orders:</span>
        <strong style="color: var(--text-dark); font-size: 14px;"><?= number_format($totalSalesCount) ?></strong>
    </div>
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 18px; display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Filtered Revenue:</span>
        <strong style="color: var(--green); font-size: 15px;"><?= formatCurrency($totalRevenueFiltered) ?></strong>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($sales)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="receipt" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No sales transactions found</h3>
                <p class="empty-state-desc">
                    <?= !empty($search) || !empty($startDate) || !empty($paymentMethod) 
                        ? 'No sales matched your specified filters.' 
                        : 'No sales records exist yet in your shop.' ?>
                </p>
                <a href="sales.php" class="btn btn-primary">Create New Sale</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date & Time</th>
                            <th>Customer</th>
                            <th>Payment Mode</th>
                            <th style="text-align: right;">Subtotal</th>
                            <th style="text-align: right;">Discount</th>
                            <th style="text-align: right;">Total Amount</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td>
                                    <button type="button" class="btn btn-link" style="padding: 0; font-weight: 700; color: var(--primary);" onclick="viewInvoiceModal('<?= $s['id'] ?>')">
                                        <?= h($s['invoice_number']) ?>
                                    </button>
                                </td>
                                <td style="color: var(--text-muted); font-size: 12px;">
                                    <?= formatDate($s['created_at']) ?>
                                </td>
                                <td>
                                    <?= !empty($s['customer_name']) ? h($s['customer_name']) : '<span style="color: var(--text-light);">Walk-in Customer</span>' ?>
                                    <?php if (!empty($s['customer_phone'])): ?>
                                        <div style="font-size: 11px; color: var(--text-light);"><?= h($s['customer_phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?= h($s['payment_method']) ?></span>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">
                                    <?= formatCurrency($s['subtotal']) ?>
                                </td>
                                <td style="text-align: right; color: <?= $s['discount_amount'] > 0 ? 'var(--rose)' : 'var(--text-light)' ?>;">
                                    <?= $s['discount_amount'] > 0 ? '-' . formatCurrency($s['discount_amount']) : '—' ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--text-dark); font-size: 14px;">
                                    <?= formatCurrency($s['total_amount']) ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($s['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="viewInvoiceModal('<?= $s['id'] ?>')" title="View Receipt">
                                            <i data-lucide="receipt" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <?php if ($s['status'] === 'completed'): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="cancelSale('<?= $s['id'] ?>', '<?= addslashes(h($s['invoice_number'])) ?>')" title="Cancel / Refund Sale">
                                                <i data-lucide="rotate-ccw" style="width: 14px; height: 14px;"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalSalesCount) ?> of <?= $totalSalesCount ?> transactions
                    </div>
                    <div class="pagination-links">
                        <?php 
                            $queryParams = $_GET;
                            $buildUrl = function($pageNum) use ($queryParams) {
                                $queryParams['page'] = $pageNum;
                                return 'sales-history.php?' . http_build_query($queryParams);
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

<!-- Receipt Modal -->
<div class="modal-backdrop" id="historyReceiptModal">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header no-print">
            <h3 class="modal-title">Receipt & Invoice</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('historyReceiptModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="modal-body" id="history-receipt-body" style="background: #fff;">
            <!-- Rendered via JS -->
        </div>
        <div class="modal-footer no-print">
            <button type="button" class="btn btn-secondary" onclick="closeModal('historyReceiptModal')">Close</button>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i data-lucide="printer"></i> Print Receipt
            </button>
        </div>
    </div>
</div>

<script>
    async function viewInvoiceModal(saleId) {
        openModal('historyReceiptModal');
        const container = document.getElementById('history-receipt-body');
        container.innerHTML = `<div style="text-align: center; padding: 24px;"><i data-lucide="loader" class="spin"></i> Loading invoice...</div>`;
        if (window.lucide) lucide.createIcons({ root: container });

        try {
            const data = await apiRequest(`api/sales/index.php?id=${encodeURIComponent(saleId)}`);
            const sale = data.sale;
            const items = data.items || [];

            container.innerHTML = `
                <div class="receipt-printable" style="font-family: 'Inter', sans-serif; color: #1e293b; padding: 10px;">
                    <div style="text-align: center; margin-bottom: 16px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 12px;">
                        <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 2px;">${escapeHtml(sale.shop_name)}</h2>
                        ${sale.shop_address ? `<p style="font-size: 11px; color: #64748b;">${escapeHtml(sale.shop_address)}</p>` : ''}
                        ${sale.shop_phone ? `<p style="font-size: 11px; color: #64748b;">Phone: ${escapeHtml(sale.shop_phone)}</p>` : ''}
                        <div style="font-size: 11px; font-weight: 600; margin-top: 6px; background: #f1f5f9; padding: 3px 8px; border-radius: 4px; display: inline-block;">
                            TAX INVOICE: ${escapeHtml(sale.invoice_number)}
                        </div>
                        ${sale.status === 'cancelled' ? `
                            <div style="color: #ef4444; font-weight: 700; font-size: 12px; margin-top: 4px; text-transform: uppercase;">
                                [CANCELLED / REFUNDED]
                            </div>
                        ` : ''}
                    </div>

                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: #64748b; margin-bottom: 12px;">
                        <div>
                            <div><strong>Date:</strong> ${new Date(sale.created_at).toLocaleString('en-IN')}</div>
                            <div><strong>Cashier:</strong> ${escapeHtml(sale.cashier_name || 'Owner')}</div>
                        </div>
                        ${sale.customer_name ? `
                            <div style="text-align: right;">
                                <div><strong>Customer:</strong> ${escapeHtml(sale.customer_name)}</div>
                                ${sale.customer_phone ? `<div>${escapeHtml(sale.customer_phone)}</div>` : ''}
                            </div>
                        ` : ''}
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 14px;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e2e8f0; text-align: left; color: #64748b;">
                                <th style="padding: 6px 0;">Item</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Rate</th>
                                <th style="text-align: right; padding: 6px 0;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map(it => `
                                <tr style="border-bottom: 1px dashed #f1f5f9;">
                                    <td style="padding: 6px 0;">
                                        <div style="font-weight: 600;">${escapeHtml(it.product_name_snapshot)}</div>
                                        <div style="font-size: 10px; color: #94a3b8;">${escapeHtml(it.sku_snapshot)}</div>
                                    </td>
                                    <td style="text-align: center; font-weight: 500;">${it.quantity}</td>
                                    <td style="text-align: right; color: #64748b;">${formatMoney(it.unit_price)}</td>
                                    <td style="text-align: right; font-weight: 600; padding: 6px 0;">${formatMoney(it.line_total)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>

                    <div style="border-top: 1px dashed #cbd5e1; padding-top: 8px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Subtotal:</span>
                            <span>${formatMoney(sale.subtotal)}</span>
                        </div>
                        ${parseFloat(sale.discount_amount) > 0 ? `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: #ef4444;">
                                <span>Discount:</span>
                                <span>-${formatMoney(sale.discount_amount)}</span>
                            </div>
                        ` : ''}
                        <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 15px; margin-top: 6px; padding-top: 6px; border-top: 1px solid #0f172a;">
                            <span>Total Amount:</span>
                            <span>${formatMoney(sale.total_amount)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 11px; color: #64748b;">
                            <span>Payment Mode:</span>
                            <span>${escapeHtml(sale.payment_method)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: #64748b;">
                            <span>Amount Paid:</span>
                            <span>${formatMoney(sale.amount_received)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: #0284c7; font-weight: 600;">
                            <span>Change Returned:</span>
                            <span>${formatMoney(sale.change_due)}</span>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 20px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 11px; color: #64748b;">
                        <p style="font-weight: 600;">Thank you for shopping with us!</p>
                        <p>Powered by ShopTrack</p>
                    </div>
                </div>
            `;
            if (window.lucide) lucide.createIcons({ root: container });
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
        }
    }

    function cancelSale(saleId, invoiceNo) {
        confirmDialog(
            'Cancel & Refund Sale',
            `Are you sure you want to cancel invoice #${invoiceNo}? This will restore all sold items back into inventory stock and record a cancellation audit trail.`,
            async () => {
                try {
                    const res = await apiRequest('api/sales/index.php', {
                        method: 'POST',
                        body: {
                            action: 'cancel',
                            sale_id: saleId,
                            reason: 'Cashier initiated refund'
                        }
                    });

                    showToast(res.message, 'success');
                    setTimeout(() => window.location.reload(), 700);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            }
        );
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
