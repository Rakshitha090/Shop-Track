<?php
/**
 * ShopTrack - Reports & CSV Export API Endpoint
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireAuth();

$shopId = Auth::shopId();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    // -------------------------------------------------------------------------
    // CSV Export Handler
    // -------------------------------------------------------------------------
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $type = $_GET['type'] ?? 'sales';
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        if ($type === 'sales') {
            $filename = "shoptrack_sales_{$startDate}_to_{$endDate}.csv";
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");

            $output = fopen('php://output', 'w');
            // Write UTF-8 BOM
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, [
                'Invoice Number',
                'Date',
                'Customer Name',
                'Customer Phone',
                'Payment Method',
                'Subtotal (INR)',
                'Discount (INR)',
                'Total Amount (INR)',
                'Status'
            ]);

            $stmt = $db->prepare("
                SELECT invoice_number, created_at, customer_name, customer_phone,
                       payment_method, subtotal, discount_amount, total_amount, status
                FROM sales
                WHERE shop_id = :shop_id 
                  AND created_at >= :start_date 
                  AND created_at <= :end_date
                ORDER BY created_at DESC
            ");
            $stmt->execute([
                ':shop_id' => $shopId,
                ':start_date' => $startDate . ' 00:00:00',
                ':end_date' => $endDate . ' 23:59:59'
            ]);

            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['invoice_number'],
                    date('Y-m-d H:i:s', strtotime($row['created_at'])),
                    $row['customer_name'] ?? 'Walk-in',
                    $row['customer_phone'] ?? '',
                    $row['payment_method'],
                    $row['subtotal'],
                    $row['discount_amount'],
                    $row['total_amount'],
                    $row['status']
                ]);
            }

            fclose($output);
            exit;
        }

        if ($type === 'inventory') {
            $filename = "shoptrack_inventory_valuation_" . date('Y-m-d') . ".csv";
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, [
                'Product Name',
                'SKU',
                'Barcode',
                'Category',
                'Cost Price (INR)',
                'Selling Price (INR)',
                'Stock Quantity',
                'Min Alert Level',
                'Total Cost Valuation (INR)',
                'Total Retail Valuation (INR)'
            ]);

            $stmt = $db->prepare("
                SELECT p.name, p.sku, p.barcode, c.name AS category_name,
                       p.purchase_price, p.selling_price, p.stock_quantity, p.min_stock_level,
                       (p.purchase_price * p.stock_quantity) AS total_cost_val,
                       (p.selling_price * p.stock_quantity) AS total_retail_val
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.shop_id = :shop_id AND p.archived_at IS NULL
                ORDER BY p.name ASC
            ");
            $stmt->execute([':shop_id' => $shopId]);

            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['name'],
                    $row['sku'],
                    $row['barcode'] ?? '',
                    $row['category_name'] ?? 'Uncategorized',
                    $row['purchase_price'],
                    $row['selling_price'],
                    $row['stock_quantity'],
                    $row['min_stock_level'],
                    $row['total_cost_val'],
                    $row['total_retail_val']
                ]);
            }

            fclose($output);
            exit;
        }

        jsonError('Unknown export type requested');
    }

    jsonError('Invalid reports request');

} catch (Exception $e) {
    jsonError('Report generation failed: ' . $e->getMessage(), 500);
}
