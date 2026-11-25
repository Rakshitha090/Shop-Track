<?php
/**
 * ShopTrack - Automated Auth & CSRF Unit Tests
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

echo "=== ShopTrack Auth & Security Test Suite ===\n\n";

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

// 1. Password Hashing & Verification
$rawPassword = 'SecurePassword123!';
$hash = password_hash($rawPassword, PASSWORD_DEFAULT);
assertTest("password_hash generates valid bcrypt/argon hash", !empty($hash));
assertTest("password_verify validates correct password", password_verify($rawPassword, $hash) === true);
assertTest("password_verify rejects incorrect password", password_verify('WrongPassword', $hash) === false);

// 2. CSRF Token Generation & Verification
$token = CSRF::getToken();
assertTest("CSRF::getToken returns 64-character hex string", strlen($token) === 64);
assertTest("CSRF::verify succeeds with matching token", CSRF::verify($token) === true);
assertTest("CSRF::verify fails with mismatched token", CSRF::verify('invalid_token_123') === false);
assertTest("CSRF::verify fails with empty token", CSRF::verify('') === false);
assertTest("CSRF::verify fails with null token", CSRF::verify(null) === false);

// 3. Login Rate Limiting Simulation
Auth::clearLoginAttempts();
assertTest("Rate limit is initially false", Auth::isRateLimited() === false);

for ($i = 1; $i <= 4; $i++) {
    Auth::recordFailedLogin();
}
assertTest("Rate limit is false after 4 attempts", Auth::isRateLimited() === false);

Auth::recordFailedLogin(); // 5th attempt
assertTest("Rate limit triggers after 5 failed attempts", Auth::isRateLimited() === true);
assertTest("Lockout remaining is positive", Auth::getLockoutRemaining() > 0);

Auth::clearLoginAttempts();
assertTest("clearLoginAttempts successfully resets lockout", Auth::isRateLimited() === false);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
