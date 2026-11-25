/**
 * ShopTrack - Point of Sale (POS) Interactions
 */

let cart = [];
let searchDebounceTimeout = null;

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('pos-search-input');
    const categorySelect = document.getElementById('pos-category-select');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimeout);
            searchDebounceTimeout = setTimeout(fetchPosProducts, 250);
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', fetchPosProducts);
    }
});

/**
 * Fetch and filter catalog products for POS checkout
 */
async function fetchPosProducts() {
    const search = document.getElementById('pos-search-input')?.value.trim() || '';
    const categoryId = document.getElementById('pos-category-select')?.value || '';
    const grid = document.getElementById('pos-product-grid');

    try {
        const query = new URLSearchParams({
            pos: '1',
            search: search,
            category_id: categoryId
        });

        const data = await apiRequest(`api/products/index.php?${query.toString()}`);
        renderPosProducts(data.products || []);
    } catch (err) {
        console.error('POS product fetch failed:', err);
    }
}

/**
 * Render product cards into POS grid
 */
function renderPosProducts(products) {
    const grid = document.getElementById('pos-product-grid');
    if (!grid) return;

    if (!products.length) {
        grid.innerHTML = `
            <div class="empty-state" style="grid-column: 1 / -1; background: #fff; border-radius: var(--radius-lg); border: 1px solid var(--border-color);">
                <div class="empty-state-icon">
                    <i data-lucide="package-search" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No matching in-stock products</h3>
                <p class="empty-state-desc">Try searching with a different keyword or category filter.</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons({ root: grid });
        return;
    }

    grid.innerHTML = products.map(p => `
        <div class="pos-product-card" onclick='addToCart(${JSON.stringify(p).replace(/'/g, "&#39;")})'>
            <div>
                <div class="pos-product-name">${escapeHtml(p.name)}</div>
                <div class="pos-product-sku">SKU: ${escapeHtml(p.sku)}</div>
            </div>
            <div class="pos-product-meta">
                <span class="pos-product-price">${formatMoney(p.selling_price)}</span>
                <span class="pos-product-stock">${p.stock_quantity} in stock</span>
            </div>
        </div>
    `).join('');

    if (window.lucide) lucide.createIcons({ root: grid });
}

/**
 * Add product to checkout cart
 */
function addToCart(product) {
    const existing = cart.find(item => item.id === product.id);

    if (existing) {
        if (existing.quantity >= product.stock_quantity) {
            showToast(`Cannot add more. Only ${product.stock_quantity} units available in stock.`, 'warning');
            return;
        }
        existing.quantity++;
    } else {
        if (product.stock_quantity <= 0) {
            showToast('This product is out of stock.', 'warning');
            return;
        }
        cart.push({
            id: product.id,
            name: product.name,
            sku: product.sku,
            price: parseFloat(product.selling_price),
            stock: parseInt(product.stock_quantity, 10),
            quantity: 1
        });
    }

    renderCart();
    showToast(`Added ${product.name} to cart`, 'info');
}

/**
 * Change item quantity
 */
function changeQty(productId, delta) {
    const item = cart.find(i => i.id === productId);
    if (!item) return;

    const newQty = item.quantity + delta;
    if (newQty <= 0) {
        removeCartItem(productId);
        return;
    }

    if (newQty > item.stock) {
        showToast(`Maximum stock limit reached (${item.stock} available)`, 'warning');
        return;
    }

    item.quantity = newQty;
    renderCart();
}

/**
 * Remove an item from the cart
 */
function removeCartItem(productId) {
    cart = cart.filter(i => i.id !== productId);
    renderCart();
}

/**
 * Clear complete cart
 */
function clearCart() {
    if (!cart.length) return;
    cart = [];
    renderCart();
}

/**
 * Render Cart Items
 */
function renderCart() {
    const container = document.getElementById('pos-cart-items');
    const emptyMsg = document.getElementById('cart-empty-message');
    if (!container) return;

    if (!cart.length) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 40px 10px;">
                <i data-lucide="shopping-cart" style="width: 36px; height: 36px; color: var(--text-light); margin-bottom: 8px;"></i>
                <p style="font-weight: 600; font-size: 13px; color: var(--text-dark);">Your cart is empty</p>
                <p style="font-size: 12px; color: var(--text-muted);">Click items on the left to add to sale.</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons({ root: container });
        updateCartTotals();
        return;
    }

    container.innerHTML = cart.map(item => `
        <div class="cart-item-row">
            <div class="cart-item-details">
                <div class="cart-item-name">${escapeHtml(item.name)}</div>
                <div class="cart-item-price">${formatMoney(item.price)} × ${item.quantity} = <strong>${formatMoney(item.price * item.quantity)}</strong></div>
            </div>
            <div class="cart-qty-ctrl">
                <button type="button" class="qty-btn" onclick="changeQty('${item.id}', -1)">-</button>
                <span style="font-weight: 600; min-width: 20px; text-align: center; font-size: 13px;">${item.quantity}</span>
                <button type="button" class="qty-btn" onclick="changeQty('${item.id}', 1)">+</button>
                <button type="button" class="btn btn-sm btn-outline-danger" style="padding: 4px 6px; margin-left: 6px;" onclick="removeCartItem('${item.id}')">
                    <i data-lucide="x" style="width: 12px; height: 12px;"></i>
                </button>
            </div>
        </div>
    `).join('');

    if (window.lucide) lucide.createIcons({ root: container });
    updateCartTotals();
}

/**
 * Calculate totals and auto-update change display
 */
function updateCartTotals() {
    let subtotal = 0;
    cart.forEach(i => {
        subtotal += i.price * i.quantity;
    });

    const discountInput = document.getElementById('cart-discount');
    const discount = Math.max(0, parseFloat(discountInput?.value || 0));
    const grandTotal = Math.max(0, subtotal - discount);

    const subtotalEl = document.getElementById('cart-subtotal');
    const totalEl = document.getElementById('cart-total');
    if (subtotalEl) subtotalEl.textContent = formatMoney(subtotal);
    if (totalEl) totalEl.textContent = formatMoney(grandTotal);

    // If payment method is cash and received input is lower or default, update it
    const payMethod = document.getElementById('payment-method')?.value || 'Cash';
    const receivedInput = document.getElementById('amount-received');
    if (receivedInput && (parseFloat(receivedInput.value) <= 0 || payMethod !== 'Cash')) {
        receivedInput.value = grandTotal.toFixed(2);
    }

    calculateChange();
}

function handlePaymentMethodChange() {
    const payMethod = document.getElementById('payment-method')?.value;
    const tenderBox = document.getElementById('cash-tender-box');
    const receivedInput = document.getElementById('amount-received');
    const total = parseFloat(document.getElementById('cart-total')?.textContent.replace(/[^0-9.]/g, '') || 0);

    if (payMethod === 'Cash') {
        if (tenderBox) tenderBox.style.display = 'block';
    } else {
        if (tenderBox) tenderBox.style.display = 'none';
        if (receivedInput) receivedInput.value = total.toFixed(2);
    }
    calculateChange();
}

function calculateChange() {
    const total = parseFloat(document.getElementById('cart-total')?.textContent.replace(/[^0-9.]/g, '') || 0);
    const received = parseFloat(document.getElementById('amount-received')?.value || 0);
    const change = Math.max(0, received - total);

    const changeEl = document.getElementById('change-due-display');
    if (changeEl) {
        changeEl.textContent = formatMoney(change);
    }
}

/**
 * Complete Sale / Checkout
 */
async function completeSaleCheckout() {
    if (!cart.length) {
        showToast('Please add at least one product to the cart before checking out.', 'warning');
        return;
    }

    const btn = document.getElementById('btn-complete-sale');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader" class="spin"></i> Processing Checkout...`;

    try {
        const payload = {
            action: 'checkout',
            cart: cart.map(i => ({ product_id: i.id, quantity: i.quantity })),
            discount_amount: parseFloat(document.getElementById('cart-discount')?.value || 0),
            amount_received: parseFloat(document.getElementById('amount-received')?.value || 0),
            payment_method: document.getElementById('payment-method')?.value || 'Cash',
            customer_name: document.getElementById('customer-name')?.value.trim() || '',
            customer_phone: document.getElementById('customer-phone')?.value.trim() || '',
        };

        const res = await apiRequest('api/sales/index.php', {
            method: 'POST',
            body: payload
        });

        showToast(res.message, 'success');

        // Fetch full sale invoice details for printable receipt
        await renderReceipt(res.sale_id);

        // Clear cart
        clearCart();
        document.getElementById('cart-discount').value = '0';
        document.getElementById('customer-name').value = '';
        document.getElementById('customer-phone').value = '';

        // Refresh product grid to reflect deducted stock
        fetchPosProducts();

    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (window.lucide) lucide.createIcons({ root: btn });
    }
}

/**
 * Render Receipt HTML into modal
 */
async function renderReceipt(saleId) {
    const container = document.getElementById('receipt-modal-body');
    if (!container) return;

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

        openModal('receiptModal');
    } catch (err) {
        showToast('Failed to load receipt details', 'error');
    }
}
