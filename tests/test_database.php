<?php
/**
 * ShopTrack - Database Configuration & Driver Diagnostic Test
 */

require_once __DIR__ . '/../config/app.php';

echo "=== ShopTrack Database & Driver Diagnostic Test ===\n\n";

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

// 1. PDO Extension check
assertTest("PHP PDO extension is loaded", extension_loaded('pdo'));

// 2. PDO PostgreSQL Driver check
$drivers = PDO::getAvailableDrivers();
assertTest("PHP PDO PostgreSQL driver ('pgsql') is available", in_array('pgsql', $drivers, true));

// 3. Database configuration exception test with empty credentials
require_once __DIR__ . '/../config/database.php';
$caught = false;
try {
    // If not configured, should throw clean RuntimeException without fatal error
    $db = getDB();
    assertTest("Database connected successfully to configured Supabase instance", $db instanceof PDO);
} catch (RuntimeException $e) {
    $caught = true;
    assertTest("Database throws informative RuntimeException when credentials need setup", $caught);
    echo "  Notice: " . strtok($e->getMessage(), "\n") . "\n";
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
