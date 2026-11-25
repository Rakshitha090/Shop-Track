<?php
/**
 * ShopTrack - Category Management
 */

$pageTitle = 'Product Categories';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$search = trim($_GET['search'] ?? '');
$categories = [];

try {
    $db = getDB();

    $sql = "
        SELECT c.*, COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.archived_at IS NULL
        WHERE c.shop_id = :shop_id
    ";
    $params = [':shop_id' => $shopId];

    if (!empty($search)) {
        $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $sql .= " AND (c.name {$like} :search OR c.description {$like} :search)";
        $params[':search'] = "%{$search}%";
    }

    $sql .= " GROUP BY c.id ORDER BY c.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll();

} catch (Exception $e) {
    // Handled in header
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Product Categories</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Organize and group items in your inventory catalog</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openCategoryModal()">
        <i data-lucide="plus"></i> Add Category
    </button>
</div>

<!-- Search Filter -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="categories.php" class="filter-bar" style="margin-bottom: 0;">
            <div class="search-box">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Search categories..." value="<?= h($search) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-secondary">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="categories.php" class="btn btn-secondary">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Categories Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="folder-plus" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No categories found</h3>
                <p class="empty-state-desc">
                    <?= !empty($search) ? 'No categories matched your search term.' : 'Create categories like Groceries, Snacks, Beverages, or Stationery to group products.' ?>
                </p>
                <button type="button" class="btn btn-primary" onclick="openCategoryModal()">
                    <i data-lucide="plus"></i> Create Category
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th style="text-align: center;">Assigned Products</th>
                            <th style="text-align: right;">Created Date</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark); display: flex; align-items: center; gap: 8px;">
                                        <i data-lucide="folder" style="width: 16px; height: 16px; color: var(--primary);"></i>
                                        <?= h($cat['name']) ?>
                                    </div>
                                </td>
                                <td style="color: var(--text-muted); max-width: 300px;">
                                    <?= h($cat['description'] ?? '—') ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="products.php?category=<?= urlencode($cat['id']) ?>" class="badge badge-info" title="View products in this category">
                                        <?= $cat['product_count'] ?> products
                                    </a>
                                </td>
                                <td style="text-align: right; color: var(--text-muted); font-size: 12px;">
                                    <?= formatDate($cat['created_at'], 'd M Y') ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='editCategory(<?= json_encode($cat) ?>)' title="Edit Category">
                                            <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteCategory('<?= $cat['id'] ?>', '<?= addslashes(h($cat['name'])) ?>', <?= (int)$cat['product_count'] ?>)" title="Delete Category">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Category Add / Edit Modal -->
<div class="modal-backdrop" id="categoryModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="cat-modal-title">Add Category</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('categoryModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="category-form" onsubmit="handleCategorySubmit(event)">
            <div class="modal-body">
                <input type="hidden" id="cat-id" name="id" value="">
                
                <div class="form-group">
                    <label class="form-label" for="cat-name">Category Name <span class="required">*</span></label>
                    <input type="text" id="cat-name" name="name" class="form-control" placeholder="e.g. Beverages, Dairy, Snacks" required>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="cat-desc">Description</label>
                    <textarea id="cat-desc" name="description" class="form-control" rows="3" placeholder="Optional notes about this product group"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="cat-submit-btn">Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCategoryModal() {
        document.getElementById('cat-modal-title').textContent = 'Add Category';
        document.getElementById('cat-id').value = '';
        document.getElementById('cat-name').value = '';
        document.getElementById('cat-desc').value = '';
        openModal('categoryModal');
        setTimeout(() => document.getElementById('cat-name').focus(), 100);
    }

    function editCategory(cat) {
        document.getElementById('cat-modal-title').textContent = 'Edit Category';
        document.getElementById('cat-id').value = cat.id;
        document.getElementById('cat-name').value = cat.name;
        document.getElementById('cat-desc').value = cat.description || '';
        openModal('categoryModal');
        setTimeout(() => document.getElementById('cat-name').focus(), 100);
    }

    async function handleCategorySubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('cat-submit-btn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const formData = new FormData(document.getElementById('category-form'));

        try {
            const res = await apiRequest('api/categories/index.php', {
                method: 'POST',
                body: formData
            });

            showToast(res.message);
            closeModal('categoryModal');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Category';
        }
    }

    function deleteCategory(id, name, count) {
        if (count > 0) {
            showToast(`Cannot delete category "${name}" because it contains ${count} active product(s). Please reassign them first.`, 'warning');
            return;
        }

        confirmDialog(
            'Delete Category',
            `Are you sure you want to delete category "${name}"? This action cannot be undone.`,
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('id', id);

                    const res = await apiRequest('api/categories/index.php', {
                        method: 'POST',
                        body: formData
                    });

                    showToast(res.message);
                    setTimeout(() => window.location.reload(), 600);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            }
        );
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
