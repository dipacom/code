<?php
require_once '../../includes/config.php';
require_once '../../includes/reminder_luaran.php';
requireLogin('mahasiswa');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'method not allowed']);
    exit;
}

$ids = $_POST['ids'] ?? [];
$ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : [];

$n = markLuaranRemindersRead($pdo, (int)$_SESSION['user_id'], $ids);
echo json_encode(['ok' => true, 'updated' => $n]);
