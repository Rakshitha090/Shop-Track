<?php
/**
 * ShopTrack - 404 Page Not Found
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-page">
    <div class="auth-card" style="text-align: center;">
        <div class="empty-state-icon" style="background: var(--primary-light); color: var(--primary); margin: 0 auto 16px auto;">
            <i data-lucide="help-circle" style="width: 32px; height: 32px;"></i>
        </div>
        <h1 class="auth-title">404 — Page Not Found</h1>
        <p class="auth-subtitle" style="margin-bottom: 24px;">
            The page you are looking for does not exist or may have been moved.
        </p>
        <a href="index.php" class="btn btn-primary">
            <i data-lucide="home"></i> Return to ShopTrack
        </a>
    </div>
    <script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
