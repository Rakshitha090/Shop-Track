<?php
/**
 * ShopTrack - Database Connection Handler
 * Supports Supabase PostgreSQL (default) and local SQLite.
 */

require_once __DIR__ . '/app.php';

class Database {
    private static ?PDO $instance = null;
    private static string $activeDriver = 'pgsql';

    /**
     * Get singleton PDO connection
     *
     * @throws RuntimeException
     * @return PDO
     */
    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Verify PDO availability
        if (!extension_loaded('pdo')) {
            throw new RuntimeException("PHP PDO extension is not loaded. Please enable 'pdo' in your php.ini.");
        }

        $connectionType = strtolower((string)env('DB_CONNECTION', 'supabase'));

        if ($connectionType === 'sqlite') {
            return self::connectSqlite();
        }

        return self::connectSupabase();
    }

    /**
     * Connect to Supabase PostgreSQL (Default)
     */
    private static function connectSupabase(): PDO {
        self::$activeDriver = 'pgsql';

        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                "PHP PostgreSQL PDO driver ('pdo_pgsql') is not enabled in your PHP installation.\n" .
                "To enable it, uncomment 'extension=pdo_pgsql' and 'extension=pgsql' in your php.ini file and restart your PHP server."
            );
        }

        $host = env('SUPABASE_DB_HOST');
        $port = env('SUPABASE_DB_PORT', '5432');
        $dbname = env('SUPABASE_DB_NAME', 'postgres');
        $user = env('SUPABASE_DB_USER', 'postgres');
        $password = env('SUPABASE_DB_PASSWORD');
        $sslmode = env('SUPABASE_DB_SSLMODE', 'require');

        // Also support full connection URI (e.g., DATABASE_URL or SUPABASE_DB_URL)
        $dbUrl = env('DATABASE_URL') ?: env('SUPABASE_DB_URL');
        if (!empty($dbUrl)) {
            $parsed = parse_url($dbUrl);
            if (!empty($parsed['host'])) {
                $host = $parsed['host'];
                $port = $parsed['port'] ?? $port;
                $user = isset($parsed['user']) ? urldecode($parsed['user']) : $user;
                $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : $password;
                if (!empty($parsed['path'])) {
                    $dbname = ltrim($parsed['path'], '/');
                }
                if (!empty($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                    if (!empty($queryParams['sslmode'])) {
                        $sslmode = $queryParams['sslmode'];
                    }
                }
            }
        }

        if (empty($host) || empty($password)) {
            throw new RuntimeException(
                "Database configuration missing: SUPABASE_DB_HOST or SUPABASE_DB_PASSWORD is not set.\n\n" .
                "How to resolve this:\n" .
                "Option A (Supabase PostgreSQL): Create a .env file from .env.example and fill in your Supabase database host and password.\n" .
                "Option B (Local SQLite): Set DB_CONNECTION=sqlite in your .env file to use a local SQLite database."
            );
        }

        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s",
            $host,
            $port,
            $dbname,
            $sslmode
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $password, $options);
            self::$instance->exec("SET search_path TO public;");
            return self::$instance;
        } catch (PDOException $e) {
            error_log("Supabase Connection Error: " . $e->getMessage());
            $appEnv = env('APP_ENV', 'development');
            $safeMsg = "Failed to connect to Supabase PostgreSQL database. ";
            if ($appEnv === 'development') {
                $safeMsg .= "Details: " . htmlspecialchars($e->getMessage());
            } else {
                $safeMsg .= "Please check your network and Supabase connection settings.";
            }
            throw new RuntimeException($safeMsg, (int)$e->getCode(), $e);
        }
    }

    /**
     * Connect to local SQLite database
     */
    private static function connectSqlite(): PDO {
        self::$activeDriver = 'sqlite';

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                "PHP SQLite PDO driver ('pdo_sqlite') is not enabled in your PHP installation.\n" .
                "To enable it, uncomment 'extension=pdo_sqlite' in your php.ini file and restart your PHP server."
            );
        }

        $dbPath = env('DB_DATABASE', 'database/shoptrack.sqlite');

        // Always resolve relative paths absolutely from project root directory
        if (!preg_match('/^([a-zA-Z]:[\\\\\/]|\/)/', $dbPath)) {
            $dbPath = dirname(__DIR__) . '/' . ltrim($dbPath, '/\\');
        }
        $dbPath = str_replace('\\', '/', $dbPath);

        // Ensure database directory exists
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $dsn = 'sqlite:' . $dbPath;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        try {
            self::$instance = new PDO($dsn, null, null, $options);
            self::$instance->exec("PRAGMA foreign_keys = ON;");
            self::$instance->exec("PRAGMA journal_mode = WAL;");

            // Register SQLite compatibility helper functions for PostgreSQL parity
            self::$instance->sqliteCreateFunction('now', function() {
                return date('Y-m-d H:i:s');
            });

            self::$instance->sqliteCreateFunction('gen_random_uuid', function() {
                return sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
            });

            self::$instance->sqliteCreateFunction('ilike', function($pattern, $value) {
                if ($value === null || $pattern === null) {
                    return 0;
                }
                $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
                return preg_match($regex, (string)$value) ? 1 : 0;
            });

            // Register TO_CHAR function for SQL dialect compatibility
            self::$instance->sqliteCreateFunction('to_char', function($val, $fmt = 'YYYY-MM-DD') {
                if (empty($val)) return null;
                $t = strtotime($val);
                if (!$t) return (string)$val;
                if ($fmt === 'YYYY-MM-DD') return date('Y-m-d', $t);
                if ($fmt === 'YYYY-MM') return date('Y-m', $t);
                if ($fmt === 'YYYY') return date('Y', $t);
                return date('Y-m-d', $t);
            }, 2);

            // Safe Auto-Initialization: Create tables if missing (never resets existing data)
            self::ensureSqliteSchema(self::$instance);

            return self::$instance;
        } catch (PDOException $e) {
            error_log("SQLite Connection Error: " . $e->getMessage());
            throw new RuntimeException("Failed to connect to SQLite database: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Safely initialize SQLite tables from schema_sqlite.sql if missing.
     * Preserves existing tables and data.
     */
    private static function ensureSqliteSchema(PDO $pdo): void {
        try {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shops' LIMIT 1");
            $exists = $stmt && $stmt->fetchColumn();
            if ($stmt) {
                $stmt->closeCursor();
            }
            if (!$exists) {
                $schemaFile = dirname(__DIR__) . '/database/schema_sqlite.sql';
                if (file_exists($schemaFile) && is_readable($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    $pdo->exec($sql);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to initialize SQLite schema: " . $e->getMessage());
        }
    }

    /**
     * Get active database driver name ('pgsql' or 'sqlite')
     */
    public static function getDriver(): string {
        return self::$activeDriver;
    }
}

/**
 * Global helper to retrieve PDO instance
 */
function getDB(): PDO {
    return Database::getConnection();
}
