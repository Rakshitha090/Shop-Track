<?php
/**
 * ShopTrack - Business Rules & Utility Unit Tests
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== ShopTrack Business Rules Test Suite ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$description}\n";
        $passed++;
    } else {
        echo " [FAIL] {$description}\n";
        $failed++;
    }
}

// 1. Currency Formatting
assertTest("formatCurrency formats integer INR correctly", formatCurrency(1250) === '₹1,250.00');
assertTest("formatCurrency formats float INR with precision", formatCurrency(45.5) === '₹45.50');
assertTest("formatCurrency handles zero", formatCurrency(0) === '₹0.00');
assertTest("formatCurrency handles null gracefully", formatCurrency(null) === '₹0.00');

// 2. HTML Escaping for XSS Prevention
$dirtyXss = '<script>alert("hack")</script>&"\'';
$escaped = h($dirtyXss);
assertTest("h() neutralizes script tags", !str_contains($escaped, '<script>'));
assertTest("h() escapes double quotes", str_contains($escaped, '&quot;'));
assertTest("h() escapes single quotes", str_contains($escaped, '&apos;') || str_contains($escaped, '&#039;'));
assertTest("h() handles null cleanly", h(null) === '');

// 3. Stock Status Badge Calculation
$statusOut = getStockBadge(0, 5);
assertTest("Stock = 0 returns 'Out of Stock'", $statusOut['label'] === 'Out of Stock' && $statusOut['class'] === 'badge-danger');

$statusNegative = getStockBadge(-2, 5);
assertTest("Stock < 0 returns 'Out of Stock'", $statusNegative['label'] === 'Out of Stock' && $statusNegative['class'] === 'badge-danger');

$statusLow = getStockBadge(4, 5);
assertTest("Stock <= min_stock_level returns 'Low Stock'", $statusLow['label'] === 'Low Stock' && $statusLow['class'] === 'badge-warning');

$statusEqualMin = getStockBadge(5, 5);
assertTest("Stock equal min_stock_level returns 'Low Stock'", $statusEqualMin['label'] === 'Low Stock' && $statusEqualMin['class'] === 'badge-warning');

$statusHealthy = getStockBadge(12, 5);
assertTest("Stock > min_stock_level returns 'In Stock'", $statusHealthy['label'] === 'In Stock' && $statusHealthy['class'] === 'badge-success');

// 4. Date formatting
$formattedDate = formatDate('2026-10-16 14:30:00', 'd M Y');
assertTest("formatDate formats valid date", $formattedDate === '16 Oct 2026');
assertTest("formatDate handles null", formatDate(null) === '—');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
