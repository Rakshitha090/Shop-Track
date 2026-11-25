<?php
/**
 * ShopTrack - Authentication & Authorization Management
 */

require_once __DIR__ . '/../config/app.php';

class Auth {
    private const SESSION_USER_KEY = 'shoptrack_user';
    private const LOGIN_ATTEMPTS_KEY = 'shoptrack_login_attempts';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300; // 5 minutes

    /**
     * Check if current session has an authenticated user
     */
    public static function check(): bool {
        return !empty($_SESSION[self::SESSION_USER_KEY]['id']) && !empty($_SESSION[self::SESSION_USER_KEY]['shop_id']);
    }

    /**
     * Get authenticated user record
     */
    public static function user(): ?array {
        return self::check() ? $_SESSION[self::SESSION_USER_KEY] : null;
    }

    /**
     * Get current user ID
     */
    public static function id(): ?string {
        return $_SESSION[self::SESSION_USER_KEY]['id'] ?? null;
    }

    /**
     * Get current shop ID (essential for multi-tenant isolation)
     */
    public static function shopId(): ?string {
        return $_SESSION[self::SESSION_USER_KEY]['shop_id'] ?? null;
    }

    /**
     * Require authentication; redirect to login or return 401 for API
     */
    public static function requireAuth(): void {
        if (!self::check()) {
            $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') ||
                     str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

            if ($isApi) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Unauthorized: Please log in to continue.'
                ]);
                exit;
            }

            // Save target URL for redirect after login
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/index.php';
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * Ensure guest-only access (for login/register pages)
     */
    public static function requireGuest(): void {
        if (self::check()) {
            header('Location: /index.php');
            exit;
        }
    }

    /**
     * Establish user session upon valid credentials
     */
    public static function login(array $user, array $shop): void {
        // Prevent session fixation
        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => $user['id'],
            'shop_id' => $user['shop_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'shop_name' => $shop['name'],
            'currency' => $shop['currency'] ?? 'INR',
            'phone' => $user['phone'] ?? $shop['phone'] ?? '',
        ];

        self::clearLoginAttempts();
    }

    /**
     * Clear session and cookies securely
     */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Check if IP / session is currently rate-limited for login attempts
     */
    public static function isRateLimited(): bool {
        if (empty($_SESSION[self::LOGIN_ATTEMPTS_KEY])) {
            return false;
        }

        $attempts = $_SESSION[self::LOGIN_ATTEMPTS_KEY];
        if ($attempts['count'] >= self::MAX_ATTEMPTS) {
            $elapsed = time() - $attempts['last_attempt'];
            if ($elapsed < self::LOCKOUT_SECONDS) {
                return true;
            }
            // Lockout expired, reset
            self::clearLoginAttempts();
        }
        return false;
    }

    /**
     * Record a failed login attempt
     */
    public static function recordFailedLogin(): void {
        if (!isset($_SESSION[self::LOGIN_ATTEMPTS_KEY])) {
            $_SESSION[self::LOGIN_ATTEMPTS_KEY] = [
                'count' => 1,
                'last_attempt' => time(),
            ];
        } else {
            $_SESSION[self::LOGIN_ATTEMPTS_KEY]['count']++;
            $_SESSION[self::LOGIN_ATTEMPTS_KEY]['last_attempt'] = time();
        }
    }

    /**
     * Clear failed login attempts upon successful login
     */
    public static function clearLoginAttempts(): void {
        unset($_SESSION[self::LOGIN_ATTEMPTS_KEY]);
    }

    /**
     * Get remaining lockout seconds
     */
    public static function getLockoutRemaining(): int {
        if (empty($_SESSION[self::LOGIN_ATTEMPTS_KEY])) {
            return 0;
        }
        $elapsed = time() - $_SESSION[self::LOGIN_ATTEMPTS_KEY]['last_attempt'];
        return max(0, self::LOCKOUT_SECONDS - $elapsed);
    }
}
