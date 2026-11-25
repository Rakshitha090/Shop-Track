<?php
/**
 * ShopTrack - Secure Logout
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

Auth::logout();
header('Location: login.php');
exit;
