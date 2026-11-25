<?php
/**
 * ShopTrack - Secure Login Page
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already authenticated
Auth::requireGuest();

$error = '';
$success = '';
$email = '';

if (isset($_GET['registered'])) {
    $success = 'Shop and owner account created successfully! You can now log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::verifyRequest()) {
        $error = 'Invalid security session. Please refresh and try again.';
    } elseif (Auth::isRateLimited()) {
        $remaining = ceil(Auth::getLockoutRemaining() / 60);
        $error = "Too many failed login attempts. Please wait {$remaining} minute(s) before trying again.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both your email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("
                    SELECT u.id, u.shop_id, u.full_name, u.email, u.password_hash, u.role, u.phone,
                           s.name AS shop_name, s.currency
                    FROM users u
                    JOIN shops s ON u.shop_id = s.id
                    WHERE LOWER(u.email) = LOWER(:email)
                    LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();
                $stmt->closeCursor();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Password verified successfully!
                    $shop = [
                        'id' => $user['shop_id'],
                        'name' => $user['shop_name'],
                        'currency' => $user['currency'] ?? 'INR',
                        'phone' => $user['phone'] ?? '',
                    ];

                    Auth::login($user, $shop);
                    logAudit($db, $user['shop_id'], $user['id'], 'user_login', 'users', $user['id'], [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]);

                    $target = $_SESSION['redirect_after_login'] ?? '/index.php';
                    unset($_SESSION['redirect_after_login']);
                    if (!str_starts_with($target, '/')) {
                        $target = '/' . ltrim($target, '/');
                    }
                    header("Location: {$target}");
                    exit;
                } else {
                    Auth::recordFailedLogin();
                    $error = 'Invalid email or password. Please verify your credentials.';
                }
            } catch (Exception $e) {
                $error = 'Unable to log in: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= h(APP_NAME) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-page">

<div class="auth-card">
    <div class="auth-header">
        <div class="auth-logo">
            <div class="brand-icon" style="width: 48px; height: 48px; font-size: 22px; border-radius: 12px; margin: 0 auto;">
                <i data-lucide="boxes" style="width: 26px; height: 26px;"></i>
            </div>
        </div>
        <h1 class="auth-title">Welcome to ShopTrack</h1>
        <p class="auth-subtitle">Sign in to manage your inventory, sales, and shop</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i data-lucide="alert-circle"></i>
            <div style="font-size: 13px; line-height: 1.5;"><?= nl2br(h($error)) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i data-lucide="check-circle-2"></i>
            <div><?= h($success) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php" id="login-form">
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="email">Email Address <span class="required">*</span></label>
            <input type="email" id="email" name="email" class="form-control" placeholder="name@shop.com" required value="<?= h($email) ?>" autocomplete="email" autofocus>
        </div>

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <label class="form-label" for="password" style="margin-bottom: 0;">Password <span class="required">*</span></label>
                <button type="button" id="toggle-pwd-btn" style="background: none; border: none; color: var(--primary); font-size: 12px; cursor: pointer; font-weight: 500;">
                    Show
                </button>
            </div>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; margin-top: 10px;">
            <i data-lucide="log-in"></i> Sign In to ShopTrack
        </button>
    </form>

    <div class="auth-footer">
        Don't have a shop account yet?
        <a href="register.php" style="font-weight: 600;">Register your shop</a>
    </div>
</div>

<script>
    if (window.lucide) lucide.createIcons();

    // Toggle password visibility
    const pwdInput = document.getElementById('password');
    const toggleBtn = document.getElementById('toggle-pwd-btn');
    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', () => {
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                toggleBtn.textContent = 'Hide';
            } else {
                pwdInput.type = 'password';
                toggleBtn.textContent = 'Show';
            }
        });
    }
</script>
</body>
</html>
