<?php
/**
 * ShopTrack - CSRF Protection Utilities
 */

require_once __DIR__ . '/../config/app.php';

class CSRF {
    private const SESSION_KEY = '_csrf_token';

    /**
     * Generate or retrieve existing CSRF token for this session
     */
    public static function getToken(): string {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Verify provided token against session token
     */
    public static function verify(?string $token): bool {
        if (empty($token) || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * Verify CSRF token from either POST body or HTTP header
     */
    public static function verifyRequest(): bool {
        // If GET, HEAD, OPTIONS, no CSRF verification required
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        // Check header first (X-CSRF-Token)
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (empty($headerToken) && function_exists('getallheaders')) {
            $allHeaders = array_change_key_case(getallheaders(), CASE_LOWER);
            $headerToken = $allHeaders['x-csrf-token'] ?? null;
        }
        if (!empty($headerToken) && self::verify($headerToken)) {
            return true;
        }

        // Check POST parameter
        $postToken = $_POST['csrf_token'] ?? null;
        if (!empty($postToken) && self::verify($postToken)) {
            return true;
        }

        // Check JSON payload if applicable
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $input = json_decode(file_get_contents('php://input'), true);
            if (is_array($input) && !empty($input['csrf_token']) && self::verify($input['csrf_token'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render hidden HTML form input with CSRF token
     */
    public static function field(): string {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

function csrf_token(): string {
    return CSRF::getToken();
}

function csrf_field(): string {
    return CSRF::field();
}
