<?php
/**
 * ShopTrack - Point of Sale (POS)
 */

$pageTitle = 'Point of Sale (POS)';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$categories = [];
$initialProducts = [];

try {
    $db = getDB();

    // Fetch categories
    $catStmt = $db->prepare("SELECT id, name FROM categories WHERE shop_id = :shop_id ORDER BY name ASC");
    $catStmt->execute([':shop_id' => $shopId]);
    $categories = $catStmt->fetchAll();

    // Fetch available products (stock > 0 and not archived)
    $prodStmt = $db->prepare("
        SELECT p.id, p.name, p.sku, p.barcode, p.selling_price, p.stock_quantity, p.min_stock_level, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.shop_id = :shop_id 
          AND p.archived_at IS NULL 
          AND p.stock_quantity > 0
        ORDER BY p.name ASC
        LIMIT 100
    ");
    $prodStmt->execute([':shop_id' => $shopId]);
    $initialProducts = $prodStmt->fetchAll();

} catch (Exception $e) {
    // DB error in header
}
?>

<div class="pos-container">
    <!-- Left Pane: Product Catalog & Search -->
    <div class="pos-products-pane">
        <!-- POS Search & Filter Header -->
        <div style="margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
            <div class="search-box" style="flex: 1; min-width: 260px;">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" id="pos-search-input" class="form-control" placeholder="Search product name, SKU, or scan barcode..." autofocus>
            </div>
            <select id="pos-category-select" class="form-control" style="width: auto; min-width: 160px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['id']) ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Product Cards Grid -->
        <div class="pos-product-grid" id="pos-product-grid">
            <?php if (empty($initialProducts)): ?>
                <div class="empty-state" style="grid-column: 1 / -1; background: #fff; border-radius: var(--radius-lg); border: 1px solid var(--border-color);">
                    <div class="empty-state-icon">
                        <i data-lucide="package-x" style="width: 32px; height: 32px;"></i>
                    </div>
                    <h3 class="empty-state-title">No items available for checkout</h3>
                    <p class="empty-state-desc">All products are either out of stock or have not been added yet.</p>
                    <a href="product-form.php" class="btn btn-primary btn-sm">Add New Product</a>
                </div>
            <?php else: ?>
                <?php foreach ($initialProducts as $p): ?>
                    <div class="pos-product-card" onclick='addToCart(<?= json_encode($p) ?>)'>
                        <div>
                            <div class="pos-product-name"><?= h($p['name']) ?></div>
                            <div class="pos-product-sku">SKU: <?= h($p['sku']) ?></div>
                        </div>
                        <div class="pos-product-meta">
                            <span class="pos-product-price"><?= formatCurrency($p['selling_price']) ?></span>
                            <span class="pos-product-stock"><?= $p['stock_quantity'] ?> in stock</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Pane: Cart & Checkout -->
    <div class="pos-cart-pane">
        <div class="pos-cart-header">
            <h3 style="font-size: 15px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 8px;">
                <i data-lucide="shopping-bag" style="color: var(--primary); width: 18px; height: 18px;"></i>
                Current Sale
            </h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="clearCart()" title="Empty Cart">
                <i data-lucide="trash" style="width: 14px; height: 14px;"></i> Clear
            </button>
        </div>

        <!-- Cart Items List -->
        <div class="pos-cart-items" id="pos-cart-items">
            <div class="empty-state" id="cart-empty-message" style="padding: 40px 10px;">
                <i data-lucide="shopping-cart" style="width: 36px; height: 36px; color: var(--text-light); margin-bottom: 8px;"></i>
                <p style="font-weight: 600; font-size: 13px; color: var(--text-dark);">Your cart is empty</p>
                <p style="font-size: 12px; color: var(--text-muted);">Click items on the left to add to sale.</p>
            </div>
            <!-- Dynamic Cart Items Rendered Here -->
        </div>

        <!-- Cart Checkout Summary & Tender Controls -->
        <div class="pos-cart-summary">
            <!-- Customer Details (Optional) -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                <input type="text" id="customer-name" class="form-control" style="padding: 6px 10px; font-size: 12px;" placeholder="Customer Name (optional)">
                <input type="tel" id="customer-phone" class="form-control" style="padding: 6px 10px; font-size: 12px;" placeholder="Phone / WhatsApp">
            </div>

            <!-- Discount Row -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Discount (₹):</span>
                <input type="number" min="0" step="1" id="cart-discount" class="form-control" style="width: 100px; padding: 4px 8px; font-size: 12px; text-align: right;" value="0" oninput="updateCartTotals()">
            </div>

            <!-- Payment Method -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Payment Mode:</span>
                <select id="payment-method" class="form-control" style="width: 130px; padding: 4px 8px; font-size: 12px;" onchange="handlePaymentMethodChange()">
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI / QR</option>
                    <option value="Card">Credit/Debit Card</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <!-- Cash Received & Change Due -->
            <div id="cash-tender-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Amount Received (₹):</span>
                    <input type="number" min="0" step="1" id="amount-received" class="form-control" style="width: 100px; padding: 4px 8px; font-size: 12px; text-align: right;" value="0" oninput="calculateChange()">
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 12px;">
                    <span style="color: var(--text-muted); font-weight: 500;">Change Due:</span>
                    <span id="change-due-display" style="font-weight: 700; color: var(--primary);">₹0.00</span>
                </div>
            </div>

            <!-- Subtotal & Grand Total -->
            <div class="summary-row">
                <span>Subtotal:</span>
                <span id="cart-subtotal" style="font-weight: 600;">₹0.00</span>
            </div>
            <div class="summary-row total-row">
                <span>Grand Total:</span>
                <span id="cart-total" style="color: var(--green); font-size: 18px;">₹0.00</span>
            </div>

            <!-- Complete Sale Button -->
            <button type="button" id="btn-complete-sale" class="btn btn-success" style="width: 100%; padding: 12px; margin-top: 10px; font-size: 14px;" onclick="completeSaleCheckout()">
                <i data-lucide="check-circle-2"></i> Complete Sale
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal-backdrop" id="receiptModal">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header no-print">
            <h3 class="modal-title">Receipt & Invoice</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('receiptModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="modal-body" id="receipt-modal-body" style="background: #fff;">
            <!-- Dynamic Receipt Injected Here -->
        </div>
        <div class="modal-footer no-print">
            <button type="button" class="btn btn-secondary" onclick="closeModal('receiptModal')">Done</button>
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i data-lucide="printer"></i> Print Receipt
            </button>
        </div>
    </div>
</div>

<?php 
$extraScripts = ['assets/js/sales.js'];
require_once __DIR__ . '/includes/footer.php'; 
?>
