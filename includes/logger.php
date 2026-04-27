<?php
/**
 * Activity Logger — LPPM IAKN Toraja
 * Tulis satu baris log ke tabel activity_log.
 * Fire-and-forget: tidak pernah melempar exception.
 *
 * @param PDO         $pdo
 * @param int|null    $user_id   ID pengguna (null = sistem/anonim)
 * @param string      $role      'mahasiswa'|'dosen'|'admin'|'system'
 * @param string      $action    Kode aksi, contoh: 'login', 'permohonan_plagiasi'
 * @param string      $detail    Teks deskripsi bebas (maks ~500 karakter)
 * @param string|null $nama      Nama lengkap (auto dari session jika null)
 * @param string|null $ip        IP address (auto-detect jika null)
 */
function writeLog(
    PDO $pdo,
    ?int $user_id,
    string $role,
    string $action,
    string $detail = '',
    ?string $nama = null,
    ?string $ip = null
): void {
    try {
        if ($nama === null) {
            $nama = $_SESSION['nama'] ?? null;
        }
        if ($ip === null) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
               ?? $_SERVER['HTTP_CLIENT_IP']
               ?? $_SERVER['REMOTE_ADDR']
               ?? null;
            // Ambil IP pertama jika ada proxy chain
            if ($ip && strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
        }
        $pdo->prepare(
            "INSERT INTO activity_log
                (user_id, nama_lengkap, role, action_type, detail, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $user_id,
            $nama ? mb_strimwidth($nama, 0, 150) : null,
            $role,
            mb_strimwidth($action, 0, 80),
            $detail !== '' ? mb_strimwidth($detail, 0, 1000) : null,
            $ip ? mb_strimwidth($ip, 0, 45) : null,
        ]);
    } catch (Throwable) {
        // Jangan pernah crash halaman karena logging
    }
}

/**
 * Label ramah-baca untuk action_type.
 */
function logActionLabel(string $action, string $lang = 'id'): string {
    $map = [
        'login'                    => ['id' => 'Login',                      'en' => 'Login'],
        'login_gagal'              => ['id' => 'Login Gagal',                'en' => 'Failed Login'],
        'logout'                   => ['id' => 'Logout',                     'en' => 'Logout'],
        'permohonan_plagiasi'      => ['id' => 'Ajukan Cek Plagiasi',        'en' => 'Submit Plagiarism Check'],
        'permohonan_publikasi'     => ['id' => 'Ajukan Surat Publikasi',     'en' => 'Submit Publication Letter'],
        'permohonan_ec'            => ['id' => 'Ajukan Ethical Clearance',   'en' => 'Submit Ethical Clearance'],
        'admin_proses_plagiasi'    => ['id' => 'Proses Plagiasi',            'en' => 'Process Plagiarism'],
        'admin_proses_publikasi'   => ['id' => 'Proses Publikasi',           'en' => 'Process Publication'],
        'admin_proses_ec'          => ['id' => 'Proses Ethical Clearance',   'en' => 'Process EC'],
        'admin_hapus_chat'         => ['id' => 'Hapus Percakapan',           'en' => 'Delete Conversation'],
        'admin_kirim_chat'         => ['id' => 'Kirim Pesan (Admin)',        'en' => 'Send Message (Admin)'],
        'user_kirim_chat'          => ['id' => 'Kirim Pesan',                'en' => 'Send Message'],
        'admin_edit_user'          => ['id' => 'Edit Profil Pengguna',       'en' => 'Edit User Profile'],
        'admin_hapus_user'         => ['id' => 'Hapus Akun Pengguna',        'en' => 'Delete User Account'],
        'admin_toggle_user'        => ['id' => 'Toggle Aktif Pengguna',      'en' => 'Toggle User Active'],
        'admin_reset_pw'           => ['id' => 'Reset Password Pengguna',    'en' => 'Reset User Password'],
        'admin_toggle_penerimaan'  => ['id' => 'Toggle Buka/Tutup Penerimaan','en'=> 'Toggle Intake Open/Close'],
        'admin_hapus_log'          => ['id' => 'Hapus Log Aktivitas',        'en' => 'Delete Activity Log'],
    ];
    return $map[$action][$lang] ?? $action;
}

/**
 * Warna badge per kategori aksi.
 */
function logActionColor(string $action): string {
    if (str_starts_with($action, 'login'))          return 'blue';
    if ($action === 'logout')                        return 'gray';
    if (str_starts_with($action, 'permohonan_'))    return 'purple';
    if (str_starts_with($action, 'admin_proses_'))  return 'green';
    if (str_starts_with($action, 'admin_hapus_'))   return 'red';
    if (str_ends_with($action, '_chat'))             return 'indigo';
    if (str_starts_with($action, 'admin_'))          return 'amber';
    return 'gray';
}
