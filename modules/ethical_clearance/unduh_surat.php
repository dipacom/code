<?php
// ============================================================
// UNDUH SURAT ETHICAL CLEARANCE
// Prioritas: file bertandatangan dari admin → fallback PDF auto-generate
// ============================================================
require_once '../../includes/config.php';
requireLogin();
if (isMahasiswa()) { redirect('/dashboard.php'); }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('ID tidak valid.'); }

$stmt = $pdo->prepare("
    SELECT ec.id, ec.user_id, ec.status, ec.nomor_surat, ec.file_surat_signed
    FROM ethical_clearance ec
    WHERE ec.id = ?
");
$stmt->execute([$id]);
$ec = $stmt->fetch();

if (!$ec) { http_response_code(404); die('Data tidak ditemukan.'); }

// Akses: admin bisa semua; pengguna hanya milik sendiri dan sudah disetujui
if (!isAdmin()) {
    if ($ec['user_id'] !== (int)$_SESSION['user_id']) {
        http_response_code(403); die('Akses ditolak.');
    }
    if ($ec['status'] !== 'disetujui') {
        http_response_code(403); die('Surat belum tersedia.');
    }
}

// ── Prioritas 1: File bertandatangan yang diupload admin ──────
if (!empty($ec['file_surat_signed'])) {
    $full_path = BASE_PATH . '/' . $ec['file_surat_signed'];
    if (file_exists($full_path)) {
        $filename = 'EthicalApproval_Signed_EC' . $id . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($full_path));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($full_path);
        exit;
    }
}

// ── Prioritas 2: Fallback ke PDF auto-generate ────────────────
if (!$ec['nomor_surat']) {
    http_response_code(422);
    die('Surat belum tersedia. Nomor surat belum diisi oleh admin.');
}
// Redirect ke generator PDF (surat.php)
header('Location: ' . BASE_URL . '/modules/ethical_clearance/surat.php?id=' . $id);
exit;
