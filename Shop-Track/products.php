<?php
/**
 * ShopTrack - Product Management
 */

$pageTitle = 'Products & Inventory';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$search = trim($_GET['search'] ?? '');
$categoryId = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$sortBy = $_GET['sort'] ?? 'name_asc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$categories = [];
$products = [];
$totalProducts = 0;

try {
    $db = getDB();

    // 1. Fetch categories for filter dropdown
    $catStmt = $db->prepare("SELECT id, name FROM categories WHERE shop_id = :shop_id ORDER BY name ASC");
    $catStmt->execute([':shop_id' => $shopId]);
    $categories = $catStmt->fetchAll();

    // 2. Build Query
    $where = ["p.shop_id = :shop_id", "p.archived_at IS NULL"];
    $params = [':shop_id' => $shopId];

    if (!empty($search)) {
        $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $where[] = "(p.name {$like} :search OR p.sku {$like} :search OR p.barcode {$like} :search)";
        $params[':search'] = "%{$search}%";
    }

    if (!empty($categoryId)) {
        $where[] = "p.category_id = :category_id";
        $params[':category_id'] = $categoryId;
    }

    if ($stockFilter === 'in_stock') {
        $where[] = "p.stock_quantity > p.min_stock_level";
    } elseif ($stockFilter === 'low_stock') {
        $where[] = "p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_level";
    } elseif ($stockFilter === 'out_of_stock') {
        $where[] = "p.stock_quantity <= 0";
    }

    $whereClause = implode(" AND ", $where);

    // Sorting
    $orderBy = match ($sortBy) {
        'name_desc' => 'p.name DESC',
        'qty_asc'   => 'p.stock_quantity ASC',
        'qty_desc'  => 'p.stock_quantity DESC',
        'price_asc' => 'p.selling_price ASC',
        'price_desc'=> 'p.selling_price DESC',
        'date_desc' => 'p.created_at DESC',
        'date_asc'  => 'p.created_at ASC',
        default     => 'p.name ASC',
    };

    // Total Count for Pagination
    $countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereClause}");
    $countStmt->execute($params);
    $totalProducts = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalProducts / $perPage);

    // Fetch Page Items
    $sql = "
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();

} catch (Exception $e) {
    // DB error surfaced via header
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Products Catalog</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Manage inventory items, pricing, SKUs, and stock levels</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="product-form.php" class="btn btn-primary">
            <i data-lucide="plus"></i> Add New Product
        </a>
    </div>
</div>

<!-- Search & Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="products.php" class="filter-bar" style="margin-bottom: 0;">
            <div class="search-box">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Search by name, SKU, or barcode..." value="<?= h($search) ?>">
            </div>

            <div class="filter-actions">
                <select name="category" class="form-control" style="width: auto; min-width: 160px;" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat['id']) ?>" <?= $categoryId === $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="stock" class="form-control" style="width: auto; min-width: 140px;" onchange="this.form.submit()">
                    <option value="">All Stock Status</option>
                    <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>

                <select name="sort" class="form-control" style="width: auto; min-width: 150px;" onchange="this.form.submit()">
                    <option value="name_asc" <?= $sortBy === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                    <option value="name_desc" <?= $sortBy === 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                    <option value="qty_asc" <?= $sortBy === 'qty_asc' ? 'selected' : '' ?>>Lowest Stock</option>
                    <option value="qty_desc" <?= $sortBy === 'qty_desc' ? 'selected' : '' ?>>Highest Stock</option>
                    <option value="price_asc" <?= $sortBy === 'price_asc' ? 'selected' : '' ?>>Price (Low-High)</option>
                    <option value="price_desc" <?= $sortBy === 'price_desc' ? 'selected' : '' ?>>Price (High-Low)</option>
                    <option value="date_desc" <?= $sortBy === 'date_desc' ? 'selected' : '' ?>>Recently Added</option>
                </select>

                <button type="submit" class="btn btn-secondary">Filter</button>
                <?php if (!empty($search) || !empty($categoryId) || !empty($stockFilter) || $sortBy !== 'name_asc'): ?>
                    <a href="products.php" class="btn btn-secondary" title="Reset Filters">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($products)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="package-search" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No products found</h3>
                <p class="empty-state-desc">
                    <?= !empty($search) || !empty($categoryId) || !empty($stockFilter) 
                        ? 'No catalog items match your search or filter parameters.' 
                        : 'Your inventory catalog is currently empty. Add your first product to get started.' ?>
                </p>
                <a href="product-form.php" class="btn btn-primary">
                    <i data-lucide="plus"></i> Add Product
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>SKU / Barcode</th>
                            <th>Category</th>
                            <th style="text-align: right;">Cost Price</th>
                            <th style="text-align: right;">Selling Price</th>
                            <th style="text-align: center;">Stock</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?= h($p['name']) ?></div>
                                    <?php if (!empty($p['expiry_date'])): ?>
                                        <small style="color: var(--amber); font-weight: 500;">
                                            Exp: <?= formatDate($p['expiry_date'], 'd M Y') ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-size: 12px; background: var(--border-light); padding: 2px 6px; border-radius: 4px;">
                                        <?= h($p['sku']) ?>
                                    </span>
                                    <?php if (!empty($p['barcode'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            <i data-lucide="scan-barcode" style="width: 12px; height: 12px; vertical-align: middle;"></i> <?= h($p['barcode']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?= h($p['category_name'] ?? 'Uncategorized') ?></span>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">
                                    <?= formatCurrency($p['purchase_price']) ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--text-dark);">
                                    <?= formatCurrency($p['selling_price']) ?>
                                </td>
                                <td style="text-align: center; font-weight: 600;">
                                    <?= $p['stock_quantity'] ?>
                                    <div style="font-size: 10px; color: var(--text-light); font-weight: normal;">Min: <?= $p['min_stock_level'] ?></div>
                                </td>
                                <td style="text-align: center;">
                                    <?php $badge = getStockBadge($p['stock_quantity'], $p['min_stock_level']); ?>
                                    <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="viewProductModal('<?= $p['id'] ?>')" title="View Details">
                                            <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <a href="product-form.php?id=<?= urlencode($p['id']) ?>" class="btn btn-secondary btn-sm" title="Edit Product">
                                            <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="archiveProduct('<?= $p['id'] ?>', '<?= addslashes(h($p['name'])) ?>')" title="Archive / Delete">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
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
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalProducts) ?> of <?= $totalProducts ?> products
                    </div>
                    <div class="pagination-links">
                        <?php 
                            $queryParams = $_GET;
                            $buildUrl = function($pageNum) use ($queryParams) {
                                $queryParams['page'] = $pageNum;
                                return 'products.php?' . http_build_query($queryParams);
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

<!-- Product Details Modal -->
<div class="modal-backdrop" id="productDetailsModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="pdm-name">Product Details</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('productDetailsModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="modal-body" id="pdm-body">
            <div style="text-align: center; padding: 20px;">
                <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('productDetailsModal')">Close</button>
            <a href="#" id="pdm-edit-link" class="btn btn-primary">Edit Product</a>
        </div>
    </div>
</div>

<script>
    async function viewProductModal(productId) {
        openModal('productDetailsModal');
        const body = document.getElementById('pdm-body');
        const editLink = document.getElementById('pdm-edit-link');
        editLink.href = `product-form.php?id=${encodeURIComponent(productId)}`;

        try {
            const data = await apiRequest(`api/products/index.php?id=${encodeURIComponent(productId)}`);
            const p = data.product;
            document.getElementById('pdm-name').textContent = p.name;

            body.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">SKU</div>
                        <div style="font-weight: 600; font-family: monospace;">${escapeHtml(p.sku)}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Barcode</div>
                        <div style="font-weight: 600;">${escapeHtml(p.barcode || '—')}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Category</div>
                        <div style="font-weight: 600;">${escapeHtml(p.category_name || 'Uncategorized')}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Expiry Date</div>
                        <div style="font-weight: 600;">${escapeHtml(p.expiry_date || '—')}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Cost Price</div>
                        <div style="font-weight: 700; color: var(--text-muted);">${formatMoney(p.purchase_price)}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Selling Price</div>
                        <div style="font-weight: 700; color: var(--primary); font-size: 16px;">${formatMoney(p.selling_price)}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Available Stock</div>
                        <div style="font-weight: 700; font-size: 16px;">${p.stock_quantity} units</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Min Alert Level</div>
                        <div style="font-weight: 600;">${p.min_stock_level} units</div>
                    </div>
                </div>
                ${p.description ? `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color);">
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px;">Description</div>
                        <div style="font-size: 13px; color: var(--text-main);">${escapeHtml(p.description)}</div>
                    </div>
                ` : ''}
            `;
            if (window.lucide) lucide.createIcons({ root: body });
        } catch (err) {
            body.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
        }
    }

    function archiveProduct(productId, productName) {
        confirmDialog(
            'Remove Product',
            `Are you sure you want to remove "${productName}"? If it has historical sales, it will be safely archived without corrupting previous invoices.`,
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'archive');
                    formData.append('id', productId);

                    const res = await apiRequest('api/products/index.php', {
                        method: 'POST',
                        body: formData
                    });

                    showToast(res.message || 'Product removed successfully');
                    setTimeout(() => window.location.reload(), 800);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            }
        );
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
