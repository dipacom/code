<?php
// logout.php
require_once 'includes/config.php';
require_once 'includes/logger.php';
// Log sebelum session di-destroy
if (isLoggedIn()) {
    writeLog($pdo, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'system', 'logout',
        'Keluar dari sistem', $_SESSION['nama'] ?? null);
}
session_destroy();
setcookie('PHPSESSID', '', time()-3600, '/');
redirect('/login.php');
