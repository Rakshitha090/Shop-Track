<?php
/**
 * ShopTrack - Add / Edit Product Form
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAuth();

$shopId = Auth::shopId();
$userId = Auth::id();

$productId = $_GET['id'] ?? null;
$isEdit = !empty($productId);
$pageTitle = $isEdit ? 'Edit Product' : 'Add New Product';

$errors = [];
$categories = [];

// Form data defaults
$formData = [
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'category_id' => '',
    'description' => '',
    'purchase_price' => '0.00',
    'selling_price' => '0.00',
    'stock_quantity' => '0',
    'min_stock_level' => '5',
    'expiry_date' => '',
];

try {
    $db = getDB();

    // Fetch categories
    $catStmt = $db->prepare("SELECT id, name FROM categories WHERE shop_id = :shop_id ORDER BY name ASC");
    $catStmt->execute([':shop_id' => $shopId]);
    $categories = $catStmt->fetchAll();

    // If edit mode, populate existing product details
    if ($isEdit) {
        $pStmt = $db->prepare("SELECT * FROM products WHERE id = :id AND shop_id = :shop_id LIMIT 1");
        $pStmt->execute([':id' => $productId, ':shop_id' => $shopId]);
        $existing = $pStmt->fetch();

        if (!$existing) {
            header('Location: 404.php');
            exit;
        }

        $formData = [
            'name' => $existing['name'],
            'sku' => $existing['sku'],
            'barcode' => $existing['barcode'] ?? '',
            'category_id' => $existing['category_id'] ?? '',
            'description' => $existing['description'] ?? '',
            'purchase_price' => (string)$existing['purchase_price'],
            'selling_price' => (string)$existing['selling_price'],
            'stock_quantity' => (string)$existing['stock_quantity'],
            'min_stock_level' => (string)$existing['min_stock_level'],
            'expiry_date' => $existing['expiry_date'] ?? '',
        ];
    }
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyRequest()) {
        $errors[] = 'Invalid security session token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $categoryId = trim($_POST['category_id'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $purchasePrice = trim($_POST['purchase_price'] ?? '0');
        $sellingPrice = trim($_POST['selling_price'] ?? '0');
        $stockQuantity = (int)($_POST['stock_quantity'] ?? 0);
        $minStockLevel = (int)($_POST['min_stock_level'] ?? 5);
        $expiryDate = trim($_POST['expiry_date'] ?? '');

        // Update form data so inputs are preserved on error
        $formData = [
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'category_id' => $categoryId,
            'description' => $description,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'stock_quantity' => (string)$stockQuantity,
            'min_stock_level' => (string)$minStockLevel,
            'expiry_date' => $expiryDate,
        ];

        // Validation
        if (empty($name)) {
            $errors['name'] = 'Product name is required.';
        }

        if (empty($sku)) {
            // Auto generate SKU if empty
            $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $formData['sku'] = $sku;
        }

        if (!is_numeric($purchasePrice) || (float)$purchasePrice < 0) {
            $errors['purchase_price'] = 'Cost price must be a valid positive number.';
        }

        if (!is_numeric($sellingPrice) || (float)$sellingPrice < 0) {
            $errors['selling_price'] = 'Selling price must be a valid positive number.';
        }

        if ($stockQuantity < 0) {
            $errors['stock_quantity'] = 'Stock quantity cannot be negative.';
        }

        if ($minStockLevel < 0) {
            $errors['min_stock_level'] = 'Minimum stock level cannot be negative.';
        }

        if (!empty($categoryId)) {
            // Verify category belongs to shop
            $catCheck = $db->prepare("SELECT 1 FROM categories WHERE id = :id AND shop_id = :shop_id");
            $catCheck->execute([':id' => $categoryId, ':shop_id' => $shopId]);
            $catValid = $catCheck->fetch();
            $catCheck->closeCursor();
            if (!$catValid) {
                $errors['category_id'] = 'Invalid category selected.';
            }
        }

        // Verify SKU uniqueness within this shop
        $skuCheckSql = "SELECT id FROM products WHERE shop_id = :shop_id AND LOWER(sku) = LOWER(:sku)";
        $skuParams = [':shop_id' => $shopId, ':sku' => $sku];
        if ($isEdit) {
            $skuCheckSql .= " AND id != :id";
            $skuParams[':id'] = $productId;
        }
        $skuCheck = $db->prepare($skuCheckSql);
        $skuCheck->execute($skuParams);
        $skuExists = $skuCheck->fetch();
        $skuCheck->closeCursor();
        if ($skuExists) {
            $errors['sku'] = "A product with SKU '{$sku}' already exists in your shop.";
        }

        // If no errors, save
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                if ($isEdit) {
                    // Update
                    $prevStock = (int)$existing['stock_quantity'];

                    $updateStmt = $db->prepare("
                        UPDATE products
                        SET name = :name,
                            sku = :sku,
                            barcode = :barcode,
                            category_id = :category_id,
                            description = :description,
                            purchase_price = :purchase_price,
                            selling_price = :selling_price,
                            stock_quantity = :stock_quantity,
                            min_stock_level = :min_stock_level,
                            expiry_date = :expiry_date
                        WHERE id = :id AND shop_id = :shop_id
                    ");
                    $updateStmt->execute([
                        ':name' => $name,
                        ':sku' => $sku,
                        ':barcode' => $barcode ?: null,
                        ':category_id' => $categoryId ?: null,
                        ':description' => $description ?: null,
                        ':purchase_price' => $purchasePrice,
                        ':selling_price' => $sellingPrice,
                        ':stock_quantity' => $stockQuantity,
                        ':min_stock_level' => $minStockLevel,
                        ':expiry_date' => $expiryDate ?: null,
                        ':id' => $productId,
                        ':shop_id' => $shopId,
                    ]);

                    // If stock was directly adjusted on edit, record movement
                    if ($stockQuantity !== $prevStock) {
                        $diff = $stockQuantity - $prevStock;
                        recordStockMovement(
                            $db,
                            $shopId,
                            $productId,
                            $userId,
                            $diff > 0 ? 'adjustment_add' : 'adjustment_remove',
                            $diff,
                            $prevStock,
                            $stockQuantity,
                            'Manual stock adjustment in product settings',
                            'manual_adjustment',
                            $productId
                        );
                    }

                    logAudit($db, $shopId, $userId, 'update_product', 'products', $productId, ['name' => $name, 'sku' => $sku]);

                } else {
                    // Insert
                    $newId = generateUuid();
                    $insertStmt = $db->prepare("
                        INSERT INTO products (
                            id, shop_id, category_id, name, sku, barcode, description,
                            purchase_price, selling_price, stock_quantity, min_stock_level, expiry_date
                        ) VALUES (
                            :id, :shop_id, :category_id, :name, :sku, :barcode, :description,
                            :purchase_price, :selling_price, :stock_quantity, :min_stock_level, :expiry_date
                        )
                    ");
                    $insertStmt->execute([
                        ':id' => $newId,
                        ':shop_id' => $shopId,
                        ':category_id' => $categoryId ?: null,
                        ':name' => $name,
                        ':sku' => $sku,
                        ':barcode' => $barcode ?: null,
                        ':description' => $description ?: null,
                        ':purchase_price' => $purchasePrice,
                        ':selling_price' => $sellingPrice,
                        ':stock_quantity' => $stockQuantity,
                        ':min_stock_level' => $minStockLevel,
                        ':expiry_date' => $expiryDate ?: null,
                    ]);
                    $insertStmt->closeCursor();

                    // Record initial inventory movement if quantity > 0
                    if ($stockQuantity > 0) {
                        recordStockMovement(
                            $db,
                            $shopId,
                            $newId,
                            $userId,
                            'initial_stock',
                            $stockQuantity,
                            0,
                            $stockQuantity,
                            'Initial opening inventory stock on product creation',
                            'initial_stock',
                            $newId
                        );
                    }

                    logAudit($db, $shopId, $userId, 'create_product', 'products', $newId, ['name' => $name, 'sku' => $sku]);
                }

                $db->commit();
                header('Location: products.php');
                exit;

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = 'Failed to save product: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: var(--text-dark);">
                <?= $isEdit ? 'Edit Product' : 'Add New Product' ?>
            </h2>
            <p style="color: var(--text-muted); font-size: 13px;">
                <?= $isEdit ? 'Update product pricing, category, and specifications' : 'Register a new stock item in your shop catalog' ?>
            </p>
        </div>
        <a href="products.php" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Back to Products
        </a>
    </div>

    <?php if (!empty($errors) && isset($errors[0])): ?>
        <div class="alert alert-danger">
            <i data-lucide="alert-circle"></i>
            <div><?= h($errors[0]) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="product-form.php<?= $isEdit ? '?id=' . urlencode($productId) : '' ?>">
        <?= csrf_field() ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i data-lucide="package" style="color: var(--primary);"></i>
                    Basic Product Information
                </h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="name">Product Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" placeholder="e.g. Parle-G Biscuit 250g" required value="<?= h($formData['name']) ?>">
                    <?php if (isset($errors['name'])): ?>
                        <div class="form-feedback invalid"><?= h($errors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="sku">SKU (Stock Keeping Unit) <span class="required">*</span></label>
                        <input type="text" id="sku" name="sku" class="form-control <?= isset($errors['sku']) ? 'is-invalid' : '' ?>" placeholder="e.g. PRD-00123" required value="<?= h($formData['sku']) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Must be unique across your shop. Leave blank to auto-generate.</small>
                        <?php if (isset($errors['sku'])): ?>
                            <div class="form-feedback invalid"><?= h($errors['sku']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="barcode">Barcode / EAN</label>
                        <input type="text" id="barcode" name="barcode" class="form-control" placeholder="e.g. 8901234567890" value="<?= h($formData['barcode']) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Scannable at checkout POS.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="category_id">Category</label>
                        <select id="category_id" name="category_id" class="form-control">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat['id']) ?>" <?= $formData['category_id'] === $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="expiry_date">Expiry Date (Optional)</label>
                        <input type="date" id="expiry_date" name="expiry_date" class="form-control" value="<?= h($formData['expiry_date']) ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="description">Product Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3" placeholder="Optional notes, size, variant, or manufacturer details"><?= h($formData['description']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i data-lucide="tag" style="color: var(--teal);"></i>
                    Pricing & Inventory Stock
                </h3>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="purchase_price">Purchase Cost (₹) <span class="required">*</span></label>
                        <input type="number" step="0.01" min="0" id="purchase_price" name="purchase_price" class="form-control <?= isset($errors['purchase_price']) ? 'is-invalid' : '' ?>" required value="<?= h($formData['purchase_price']) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Unit acquisition price paid to supplier.</small>
                        <?php if (isset($errors['purchase_price'])): ?>
                            <div class="form-feedback invalid"><?= h($errors['purchase_price']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="selling_price">Selling Retail Price (₹) <span class="required">*</span></label>
                        <input type="number" step="0.01" min="0" id="selling_price" name="selling_price" class="form-control <?= isset($errors['selling_price']) ? 'is-invalid' : '' ?>" required value="<?= h($formData['selling_price']) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Default price displayed in POS.</small>
                        <?php if (isset($errors['selling_price'])): ?>
                            <div class="form-feedback invalid"><?= h($errors['selling_price']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col form-group">
                        <label class="form-label" for="stock_quantity">Available Quantity <span class="required">*</span></label>
                        <input type="number" min="0" id="stock_quantity" name="stock_quantity" class="form-control <?= isset($errors['stock_quantity']) ? 'is-invalid' : '' ?>" required value="<?= h($formData['stock_quantity']) ?>">
                        <?php if (isset($errors['stock_quantity'])): ?>
                            <div class="form-feedback invalid"><?= h($errors['stock_quantity']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-col form-group">
                        <label class="form-label" for="min_stock_level">Low-Stock Alert Level <span class="required">*</span></label>
                        <input type="number" min="0" id="min_stock_level" name="min_stock_level" class="form-control <?= isset($errors['min_stock_level']) ? 'is-invalid' : '' ?>" required value="<?= h($formData['min_stock_level']) ?>">
                        <small style="color: var(--text-light); font-size: 11px;">Triggers alert when inventory drops to or below this.</small>
                        <?php if (isset($errors['min_stock_level'])): ?>
                            <div class="form-feedback invalid"><?= h($errors['min_stock_level']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 30px;">
            <a href="products.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                <i data-lucide="check"></i> <?= $isEdit ? 'Save Changes' : 'Create Product' ?>
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
