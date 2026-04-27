<?php
// Endpoint AJAX: cek apakah NIDN/NIM sudah dipakai di proposal tahun berjalan.
// Dipakai di modules/pengabdian/ajukan.php untuk feedback realtime.

require_once '../../includes/config.php';
requireLogin('mahasiswa'); // role 'mahasiswa' gate di config.php = mahasiswa atau dosen

header('Content-Type: application/json; charset=utf-8');

$tahun    = (int)(getSetting($pdo, 'pengabdian_tahun') ?: date('Y'));
$tipe     = $_GET['tipe']    ?? '';  // 'nidn' | 'nim'
$value    = trim($_GET['value'] ?? '');
$excl_id  = (int)($_GET['excl_id'] ?? 0);

if (!in_array($tipe, ['nidn','nim'], true) || $value === '') {
    echo json_encode(['ok' => true, 'konflik' => []]);
    exit;
}

$norm = fn($v) => preg_replace('/[\s\-\.]/', '', strtolower(trim((string)$v)));
$target = $norm($value);

$rows = $pdo->prepare("
    SELECT up.id, up.judul, up.nama_ketua, up.nidn_ketua,
           up.anggota_dosen, up.anggota_mahasiswa,
           u.nama_lengkap AS user_nama, u.nidn AS user_nidn
    FROM usulan_pengabdian up
    JOIN users u ON u.id = up.user_id
    WHERE up.tahun_anggaran = ?
      AND up.deleted_at IS NULL
      AND up.status NOT IN ('draft','ditolak','gagal_admin')
      AND up.id <> ?
");
$rows->execute([$tahun, $excl_id]);

$konflik = [];
while ($o = $rows->fetch()) {
    $hit = false;
    if ($tipe === 'nidn') {
        // Cek nidn ketua (explicit atau dari user_nidn)
        $k = $norm($o['nidn_ketua'] ?? '');
        if (!$k) $k = $norm($o['user_nidn'] ?? '');
        if ($k === $target) $hit = true;
        if (!$hit) {
            $ad = json_decode($o['anggota_dosen'] ?? '[]', true) ?: [];
            foreach ($ad as $a) {
                if ($norm($a['nidn'] ?? '') === $target) { $hit = true; break; }
            }
        }
    } else { // nim
        $am = json_decode($o['anggota_mahasiswa'] ?? '[]', true) ?: [];
        foreach ($am as $a) {
            if ($norm($a['nim'] ?? '') === $target) { $hit = true; break; }
        }
    }
    if ($hit) {
        $konflik[] = [
            'pengusul' => $o['user_nama'],
            'judul'    => mb_strimwidth($o['judul'] ?? '', 0, 80, '…'),
        ];
    }
}

echo json_encode(['ok' => true, 'konflik' => $konflik], JSON_UNESCAPED_UNICODE);
