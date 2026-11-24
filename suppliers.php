<?php
/**
 * ShopTrack - Suppliers Management
 */

$pageTitle = 'Suppliers & Vendors';
require_once __DIR__ . '/includes/header.php';

$shopId = Auth::shopId();
$search = trim($_GET['search'] ?? '');
$suppliers = [];

try {
    $db = getDB();

    $sql = "
        SELECT s.*, COUNT(p.id) AS purchase_count, COALESCE(SUM(p.total_amount), 0) AS total_purchased
        FROM suppliers s
        LEFT JOIN purchases p ON s.id = p.supplier_id
        WHERE s.shop_id = :shop_id AND s.archived_at IS NULL
    ";
    $params = [':shop_id' => $shopId];

    if (!empty($search)) {
        $like = Database::getDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $sql .= " AND (s.name {$like} :search OR s.contact_person {$like} :search OR s.phone {$like} :search OR s.email {$like} :search)";
        $params[':search'] = "%{$search}%";
    }

    $sql .= " GROUP BY s.id ORDER BY s.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll();

} catch (Exception $e) {
    // Header surfaces DB error
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">Suppliers & Vendors</h2>
        <p style="color: var(--text-muted); font-size: 13px;">Manage distributor contacts, purchase histories, and vendor relationships</p>
    </div>
    <button type="button" class="btn btn-primary" onclick="openSupplierModal()">
        <i data-lucide="plus"></i> Add Supplier
    </button>
</div>

<!-- Search -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="suppliers.php" class="filter-bar" style="margin-bottom: 0;">
            <div class="search-box">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Search supplier name, contact person, phone, email..." value="<?= h($search) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-secondary">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="suppliers.php" class="btn btn-secondary">
                        <i data-lucide="rotate-ccw"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Suppliers Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($suppliers)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i data-lucide="truck" style="width: 32px; height: 32px;"></i>
                </div>
                <h3 class="empty-state-title">No suppliers found</h3>
                <p class="empty-state-desc">
                    <?= !empty($search) ? 'No suppliers matched your search query.' : 'Add your suppliers to record purchase orders and incoming inventory stock.' ?>
                </p>
                <button type="button" class="btn btn-primary" onclick="openSupplierModal()">
                    <i data-lucide="plus"></i> Add New Supplier
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier / Company</th>
                            <th>Contact Person</th>
                            <th>Contact Info</th>
                            <th>Address</th>
                            <th style="text-align: center;">Orders</th>
                            <th style="text-align: right;">Total Purchases</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $sup): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?= h($sup['name']) ?></div>
                                    <?php if (!empty($sup['notes'])): ?>
                                        <small style="color: var(--text-light);"><?= h($sup['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h($sup['contact_person'] ?? '—') ?>
                                </td>
                                <td>
                                    <?php if (!empty($sup['phone'])): ?>
                                        <div style="font-size: 13px; font-weight: 500;">
                                            <i data-lucide="phone" style="width: 12px; height: 12px; vertical-align: middle; color: var(--primary);"></i>
                                            <?= h($sup['phone']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($sup['email'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            <?= h($sup['email']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($sup['phone']) && empty($sup['email'])): ?>
                                        <span style="color: var(--text-light);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 220px; font-size: 12px; color: var(--text-muted);">
                                    <?= h($sup['address'] ?? '—') ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge badge-info"><?= $sup['purchase_count'] ?> orders</span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: var(--text-dark);">
                                    <?= formatCurrency($sup['total_purchased']) ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='editSupplier(<?= json_encode($sup) ?>)' title="Edit Supplier">
                                            <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteSupplier('<?= $sup['id'] ?>', '<?= addslashes(h($sup['name'])) ?>')" title="Delete Supplier">
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

<!-- Supplier Add / Edit Modal -->
<div class="modal-backdrop" id="supplierModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="sup-modal-title">Add Supplier</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('supplierModal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form id="supplier-form" onsubmit="handleSupplierSubmit(event)">
            <div class="modal-body">
                <input type="hidden" id="sup-id" name="id" value="">
                
                <div class="form-group">
                    <label class="form-label" for="sup-name">Supplier / Company Name <span class="required">*</span></label>
                    <input type="text" id="sup-name" name="name" class="form-control" placeholder="e.g. Metro Wholesale Distributors" required>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="sup-contact">Contact Person</label>
                        <input type="text" id="sup-contact" name="contact_person" class="form-control" placeholder="e.g. Rajesh Sharma">
                    </div>
                    <div class="form-col form-group">
                        <label class="form-label" for="sup-phone">Phone / WhatsApp</label>
                        <input type="tel" id="sup-phone" name="phone" class="form-control" placeholder="e.g. 9876543210">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sup-email">Email Address</label>
                    <input type="email" id="sup-email" name="email" class="form-control" placeholder="supplier@example.com">
                </div>

                <div class="form-group">
                    <label class="form-label" for="sup-address">Office / Warehouse Address</label>
                    <textarea id="sup-address" name="address" class="form-control" rows="2" placeholder="Street, City, PIN"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="sup-notes">Notes / Terms</label>
                    <textarea id="sup-notes" name="notes" class="form-control" rows="2" placeholder="Payment terms, delivery schedules, tax IDs"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('supplierModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="sup-submit-btn">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openSupplierModal() {
        document.getElementById('sup-modal-title').textContent = 'Add Supplier';
        document.getElementById('sup-id').value = '';
        document.getElementById('sup-name').value = '';
        document.getElementById('sup-contact').value = '';
        document.getElementById('sup-phone').value = '';
        document.getElementById('sup-email').value = '';
        document.getElementById('sup-address').value = '';
        document.getElementById('sup-notes').value = '';
        openModal('supplierModal');
        setTimeout(() => document.getElementById('sup-name').focus(), 100);
    }

    function editSupplier(s) {
        document.getElementById('sup-modal-title').textContent = 'Edit Supplier';
        document.getElementById('sup-id').value = s.id;
        document.getElementById('sup-name').value = s.name;
        document.getElementById('sup-contact').value = s.contact_person || '';
        document.getElementById('sup-phone').value = s.phone || '';
        document.getElementById('sup-email').value = s.email || '';
        document.getElementById('sup-address').value = s.address || '';
        document.getElementById('sup-notes').value = s.notes || '';
        openModal('supplierModal');
        setTimeout(() => document.getElementById('sup-name').focus(), 100);
    }

    async function handleSupplierSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('sup-submit-btn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const formData = new FormData(document.getElementById('supplier-form'));

        try {
            const res = await apiRequest('api/suppliers/index.php', {
                method: 'POST',
                body: formData
            });

            showToast(res.message, 'success');
            closeModal('supplierModal');
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Supplier';
        }
    }

    function deleteSupplier(id, name) {
        confirmDialog(
            'Remove Supplier',
            `Are you sure you want to remove supplier "${name}"? If there are previous purchase records, the supplier will be safely archived without affecting previous orders.`,
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('id', id);

                    const res = await apiRequest('api/suppliers/index.php', {
                        method: 'POST',
                        body: formData
                    });

                    showToast(res.message, 'success');
                    setTimeout(() => window.location.reload(), 600);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            }
        );
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
