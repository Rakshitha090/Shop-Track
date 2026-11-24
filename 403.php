<?php
/**
 * ShopTrack - 403 Access Denied
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-page">
    <div class="auth-card" style="text-align: center;">
        <div class="empty-state-icon" style="background: var(--rose-light); color: var(--rose); margin: 0 auto 16px auto;">
            <i data-lucide="shield-alert" style="width: 32px; height: 32px;"></i>
        </div>
        <h1 class="auth-title" style="color: var(--rose);">403 — Access Denied</h1>
        <p class="auth-subtitle" style="margin-bottom: 24px;">
            You do not have permission to access the requested resource or shop domain.
        </p>
        <a href="index.php" class="btn btn-primary">
            <i data-lucide="arrow-left"></i> Return to Dashboard
        </a>
    </div>
    <script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
