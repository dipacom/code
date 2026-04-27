<?php
/**
 * admin/notif_action.php — AJAX endpoint untuk notifikasi admin.
 * POST action=mark_read  → tandai semua notif sebagai dibaca
 * POST action=mark_one   → tandai satu notif (id=N) sebagai dibaca
 */
require_once '../includes/config.php';
requireLogin('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'mark_read') {
    $pdo->prepare("UPDATE notifikasi SET is_read=1 WHERE user_id=? AND is_read=0")
        ->execute([$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'mark_one') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare("UPDATE notifikasi SET is_read=1 WHERE id=? AND user_id=?")
            ->execute([$id, $_SESSION['user_id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
