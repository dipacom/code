<?php
// ============================================================
// KONFIGURASI UTAMA - SISTEM LPPM IAKN TORAJA
// Sesuaikan bagian DATABASE dengan data hosting Anda
// ============================================================

// --- Keamanan & Error Logging (Production) ---
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// --- Database (isi sesuai data cPanel Hostinger Anda) ---
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'lppm_iakntoraja');   // Nama database di cPanel
define('DB_USER', 'root');              // Username database
define('DB_PASS', '');                  // Password database

// --- URL & Path ---
// Di localhost subfolder: isi '/lppm'. Di hosting: isi dengan 'https://namadomain.com'
define('BASE_URL', '/lppm');
define('BASE_PATH', dirname(__DIR__));

// --- Email (Gmail SMTP) ---
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'lp2miaknt@gmail.com');   // Gmail LPPM
define('MAIL_PASS', 'vehe hfiw naea mpwj');    // App Password Gmail (bukan password biasa)
define('MAIL_FROM_NAME', 'LPPM IAKN Toraja');

// --- Upload ---
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024); // 20 MB
define('ALLOWED_EXT', ['pdf', 'jpg', 'jpeg', 'png']);

// --- Session ---
define('SESSION_LIFETIME', 3600); // 1 jam

// --- Timezone ---
date_default_timezone_set('Asia/Makassar'); // WIT

// ============================================================
// KONEKSI DATABASE (PDO)
// ============================================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die(json_encode(['error' => 'Koneksi database gagal. Hubungi administrator.']));
}

// ============================================================
// SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params(SESSION_LIFETIME);
    session_start();
}

// ============================================================
// FUNGSI HELPER
// ============================================================

/**
 * Redirect ke URL tertentu
 */
function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit;
}

/**
 * Cek apakah user sudah login
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Cek role user
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isMahasiswa() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'mahasiswa';
}

function isDosen() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'dosen';
}

function isReviewer() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'reviewer';
}

// Mahasiswa ATAU Dosen (bukan admin, bukan reviewer)
function isUser() {
    return isMahasiswa() || isDosen();
}

/**
 * Cek apakah user saat ini adalah admin utama ATAU sub-admin (delegasi aktif).
 * Sub-admin = pengguna non-admin yang memiliki entry aktif di admin_delegasi
 *             (revoked_at IS NULL).
 */
function isAdminOrDelegate(?string $scope = null): bool {
    global $pdo;
    if (isAdmin()) return true;
    if (!isLoggedIn()) return false;
    try {
        $sql = "SELECT scope FROM admin_delegasi
                WHERE user_id = ? AND revoked_at IS NULL";
        $st = $pdo->prepare($sql);
        $st->execute([$_SESSION['user_id']]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($rows)) return false;
        if ($scope === null) return true;
        // Scope match: 'penuh' selalu lolos, atau scope eksak match
        foreach ($rows as $r) {
            if ($r === 'penuh' || $r === $scope) return true;
        }
        return false;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Versi requireLogin yang menerima admin utama ATAU sub-admin delegasi.
 */
function requireAdminOrDelegate(?string $scope = null): void {
    if (!isLoggedIn()) redirect('/login.php');
    if (!isAdminOrDelegate($scope)) {
        redirect(isUser() ? '/dashboard.php' : '/login.php');
    }
}

/**
 * Paksa login jika belum.
 * $role = 'admin'    → hanya admin
 * $role = 'mahasiswa'→ mahasiswa ATAU dosen (user biasa)
 * $role = 'reviewer' → hanya reviewer
 */
function requireLogin($role = null) {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
    if ($role === 'admin' && !isAdmin()) {
        redirect('/dashboard.php');
    }
    // 'mahasiswa' berarti semua non-admin (mahasiswa + dosen)
    if ($role === 'mahasiswa' && !isUser()) {
        if (isReviewer()) redirect('/modules/penelitian/reviewer.php');
        redirect('/admin/dashboard.php');
    }
    if ($role === 'reviewer' && !isReviewer()) {
        redirect('/dashboard.php');
    }
}

/**
 * Sanitasi input
 */
function clean($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Format ukuran file
 */
function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Format tanggal Indonesia
 */
function formatTanggal($date, $long = true) {
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni',
              'Juli','Agustus','September','Oktober','November','Desember'];
    $t = strtotime($date);
    if ($long) {
        return date('d', $t) . ' ' . $bulan[(int)date('m', $t)] . ' ' . date('Y', $t);
    }
    return date('d', $t) . '-' . $bulan[(int)date('m', $t)] . '-' . date('Y', $t);
}

/**
 * Generate nomor surat otomatis
 * Format: 001/LPPM/IAKNT/SK-PL/III/2026
 *   seq    = dari tabel surat_counter — bisa di-reset manual oleh admin
 *   prefix = nilai dari pengaturan DB, mis. "LPPM/IAKNT/SK-PL"
 *   roman  = bulan Romawi saat surat di-generate
 *   year   = tahun saat surat di-generate
 *
 * @param $table  'surat_plagiasi' | 'surat_publikasi'
 */
function generateNomorSurat($pdo, $prefix, $table = 'surat_plagiasi') {
    $tahun = (int)date('Y');
    $bulan = (int)date('m');

    $roman      = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    $romanBulan = $roman[$bulan - 1];

    $allowed = ['surat_plagiasi', 'surat_publikasi'];
    $table   = in_array($table, $allowed) ? $table : 'surat_plagiasi';

    // Increment counter secara atomik
    // Jika baris belum ada (awal tahun / setelah reset+delete), buat baru dengan counter=1
    $pdo->prepare("
        INSERT INTO surat_counter (tabel, tahun, counter)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE counter = counter + 1
    ")->execute([$table, $tahun]);

    $st = $pdo->prepare("SELECT counter FROM surat_counter WHERE tabel = ? AND tahun = ?");
    $st->execute([$table, $tahun]);
    $seq = (int)$st->fetchColumn();

    // Fallback ke COUNT jika tabel surat_counter belum ada (migrasi)
    if ($seq === 0) {
        $fb = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE YEAR(tanggal_surat) = ?");
        $fb->execute([$tahun]);
        $seq = (int)$fb->fetchColumn();
    }

    return str_pad($seq, 3, '0', STR_PAD_LEFT) . '/' . $prefix . '/' . $romanBulan . '/' . $tahun;
}

/**
 * Ambil pengaturan dari DB
 */
function getSetting($pdo, $kunci) {
    $stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = ?");
    $stmt->execute([$kunci]);
    $row = $stmt->fetch();
    return $row ? $row['nilai'] : null;
}

/**
 * SVG icon helper — Feather/Lucide outline, 1.5px stroke
 * Didefinisikan di config.php agar tersedia di SEMUA halaman
 */
function ic(string $name, string $extra = ''): string {
    $icons = [
        'home'         => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'building'     => '<path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
        'logout'       => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'chart'        => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'archive'      => '<path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>',
        'user'         => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users'        => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'graduation'   => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'doc'          => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'newspaper'    => '<path d="M4 22h16a2 2 0 002-2V4a2 2 0 00-2-2H8a2 2 0 00-2 2v16a4 4 0 01-4-4V6"/><path d="M2 13.5V18a2 2 0 002 2"/><path d="M8 7h8M8 11h5"/>',
        'award'        => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>',
        'clipboard'    => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="12" y2="16"/>',
        'printer'      => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'edit'         => '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'search'       => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'info'         => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'download'     => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'upload'       => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'play'         => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        'lock'         => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>',
        'globe'        => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>',
        'check'        => '<path d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'x'            => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'x-circle'     => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'clock'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'refresh'      => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>',
        'alert'        => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'bell'         => '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>',
        'inbox'        => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/>',
        'trash'        => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>',
        'plus'         => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'eye'          => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'      => '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'briefcase'    => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>',
        'chat'         => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
        'send'         => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'file-text'    => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'shield'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'link'         => '<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>',
        'trash'        => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>',
        'rotate-ccw'   => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/>',
    ];
    $path = $icons[$name] ?? '<circle cx="12" cy="12" r="3"/>';
    return '<svg class="ic" viewBox="0 0 24 24"' . ($extra ? ' '.$extra : '') . '>' . $path . '</svg>';
}

/**
 * Badge status (HTML)
 */
function badgeStatus($status) {
    $map = [
        'menunggu'    => ['bg' => '#FEF3C7', 'color' => '#92400E', 'label_id' => 'Menunggu',    'label_en' => 'Pending'],
        'diproses'    => ['bg' => '#DBEAFE', 'color' => '#1E40AF', 'label_id' => 'Diproses',    'label_en' => 'Processing'],
        'diverifikasi'=> ['bg' => '#D1FAE5', 'color' => '#065F46', 'label_id' => 'Diverifikasi','label_en' => 'Verified'],
        'selesai'     => ['bg' => '#D1FAE5', 'color' => '#065F46', 'label_id' => 'Selesai',     'label_en' => 'Completed'],
        'ditolak'     => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'label_id' => 'Ditolak',     'label_en' => 'Rejected'],
    ];
    $s = $map[$status] ?? ['bg' => '#F3F4F6', 'color' => '#374151', 'label_id' => $status, 'label_en' => $status];
    return "<span style='background:{$s['bg']};color:{$s['color']};padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600'>{$s['label_id']}</span>";
}

/**
 * Label jenis publikasi
 */
function labelJenisPublikasi($jenis) {
    $map = [
        'jurnal'       => 'Artikel Jurnal',
        'book_chapter' => 'Book Chapter',
        'buku'         => 'Buku',
        'prosiding'    => 'Prosiding Konferensi',
    ];
    return $map[$jenis] ?? $jenis;
}
