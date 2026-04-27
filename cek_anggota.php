<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');
if (!isDosen()) { exit; }

$tahun   = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));
$tipe    = $_GET['tipe'] ?? ''; // 'nidn' atau 'nim'
$value   = preg_replace('/[\s\-\.]/', '', strtolower(trim($_GET['value'] ?? '')));
$excl_id = (int)($_GET['excl_id'] ?? 0);

if (!$tipe || !$value) {
    echo json_encode(['konflik' => []]);
    exit;
}

// Ambil semua proposal PkM tahun berjalan (kecuali yg sedang diedit)
$others = $pdo->prepare("
    SELECT up.judul, u.nama_lengkap AS pengusul, up.nidn_ketua, u.nidn AS user_nidn,
           up.anggota_dosen, up.anggota_mahasiswa
    FROM usulan_pengabdian up
    JOIN users u ON u.id = up.user_id
    WHERE up.tahun_anggaran = ?
      AND up.deleted_at IS NULL
      AND up.status NOT IN ('draft','ditolak','gagal_admin')
      AND up.id <> ?
");
$others->execute([$tahun, $excl_id]);

$konflik = [];
while ($o = $others->fetch()) {
    $found = false;
    if ($tipe === 'nidn') {
        $k_ketua = preg_replace('/[\s\-\.]/', '', strtolower(trim((string)($o['nidn_ketua'] ?: $o['user_nidn']))));
        if ($k_ketua === $value) { $found = true; }
        else {
            $ad_list = json_decode($o['anggota_dosen'] ?? '[]', true) ?: [];
            foreach ($ad_list as $ad) { if (preg_replace('/[\s\-\.]/', '', strtolower(trim((string)($ad['nidn']??'')))) === $value) { $found = true; break; } }
        }
    } else if ($tipe === 'nim') {
        $am_list = json_decode($o['anggota_mahasiswa'] ?? '[]', true) ?: [];
        foreach ($am_list as $am) { if (preg_replace('/[\s\-\.]/', '', strtolower(trim((string)($am['nim']??'')))) === $value) { $found = true; break; } }
    }

    if ($found) {
        $konflik[] = [
            'judul'    => mb_strimwidth($o['judul'], 0, 50, '…'),
            'pengusul' => $o['pengusul']
        ];
    }
}

header('Content-Type: application/json');
echo json_encode(['konflik' => $konflik]);