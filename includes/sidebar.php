<?php
// includes/sidebar.php — dipanggil di setiap halaman
$lang    = $_COOKIE['lang'] ?? 'id';
$role    = $_SESSION['role'] ?? '';
$nama    = $_SESSION['nama'] ?? '';
$current = basename($_SERVER['PHP_SELF']);
$dir     = basename(dirname($_SERVER['PHP_SELF']));

// Delegate (#8): non-admin user dengan akses admin aktif → render sebagai admin
$is_delegate = function_exists('isAdminOrDelegate') && isAdminOrDelegate() && !isAdmin();
if ($is_delegate) { $role = 'admin'; }

function isActive(array $pages, string $current, string $dir = ''): string {
    foreach ($pages as $p) {
        if (str_contains($p, '/')) {
            [$d, $f] = explode('/', $p, 2);
            if ($dir === $d && $current === $f) return 'active';
        } elseif ($current === $p) return 'active';
    }
    return '';
}

$inisial = strtoupper(mb_substr($nama, 0, 1));

// ── Notifikasi admin ──────────────────────────────────────────
$notif_items  = [];
$notif_count  = 0;
$n_chat_admin = 0;
$n_chat_user  = 0;
$total_bell   = 0;

if ($role === 'admin') {
    global $pdo;
    $ns = $pdo->prepare("
        SELECT id, judul, pesan, tipe, is_read, created_at
        FROM notifikasi
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 15
    ");
    $ns->execute([$_SESSION['user_id'] ?? 0]);
    $notif_items  = $ns->fetchAll();
    $notif_count  = count($notif_items);
    $n_chat_admin = (int)$pdo->query("SELECT COUNT(*) FROM pesan WHERE pengirim_role='user' AND dibaca=0")->fetchColumn();
    $total_bell   = $notif_count + $n_chat_admin;
} elseif ($role === 'mahasiswa' || $role === 'dosen') {
    global $pdo;
    $n_chat_user = (int)$pdo->query("SELECT COUNT(*) FROM pesan WHERE user_id=" . (int)($_SESSION['user_id'] ?? 0) . " AND pengirim_role='admin' AND dibaca=0")->fetchColumn();
} elseif ($role === 'reviewer') {
    global $pdo;
    // Reviewer: notifikasi penugasan baru
}

// Helper waktu relatif
function waktuLalu(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff <    60) return 'Baru saja';
    if ($diff <  3600) return floor($diff/60) . ' menit lalu';
    if ($diff < 86400) return floor($diff/3600) . ' jam lalu';
    return floor($diff/86400) . ' hari lalu';
}

// Routing notifikasi ke halaman yang relevan
function notifUrl(string $judul): string {
    $b = BASE_URL;
    if (stripos($judul, 'plagiasi')    !== false) return "$b/admin/plagiasi.php";
    if (stripos($judul, 'publikasi')   !== false) return "$b/admin/publikasi.php";
    if (stripos($judul, 'ethical')     !== false) return "$b/admin/ethical_clearance.php";
    if (stripos($judul, 'laporan')     !== false) return "$b/admin/penelitian_laporan.php";
    if (stripos($judul, 'kontrak')     !== false) return "$b/admin/penelitian_kontrak.php";
    if (stripos($judul, 'substantif')  !== false) return "$b/admin/penelitian_reviewer.php";
    if (stripos($judul, 'perbaikan')   !== false) return "$b/admin/penelitian_seleksi.php?status=perbaikan_admin";
    if (stripos($judul, 'disetujui')   !== false) return "$b/admin/penelitian_kontrak.php?status=disetujui";
    if (stripos($judul, 'penelitian')  !== false) return "$b/admin/penelitian_seleksi.php";
    if (stripos($judul, 'reviewer')    !== false) return "$b/admin/penelitian_reviewer.php";
    if (stripos($judul, 'revisi')      !== false) return "$b/admin/penelitian_seleksi.php";
    return "$b/admin/dashboard.php";
}
?>

<?php if ($role === 'admin' && ($notif_count > 0 || $n_chat_admin > 0)): ?>
<!-- ── Notification dropdown panel (di-inject ke topbar oleh JS) ── -->
<div id="notif-panel" style="display:none">
  <div class="ndrop-header">
    <span class="ndrop-title">
      <svg class="ic" viewBox="0 0 24 24" style="width:15px;height:15px"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
      Notifikasi &amp; Pesan
    </span>
    <span class="ndrop-badge"><?= $total_bell ?> baru</span>
  </div>
  <div class="ndrop-list">
    <?php if ($n_chat_admin > 0): ?>
    <a class="ndrop-item" href="<?= BASE_URL ?>/admin/chat.php" data-chat="1">
      <span class="ndrop-icon" style="background:#eff6ff;color:#2563eb">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <span class="ndrop-body">
        <span class="ndrop-judul"><?= $lang==='id'?'Pesan Masuk':'New Messages' ?></span>
        <span class="ndrop-pesan"><?= $n_chat_admin ?> <?= $lang==='id'?'pesan belum dibaca dari pengguna':'unread messages from users' ?></span>
        <span class="ndrop-waktu"><?= $lang==='id'?'Klik untuk membalas →':'Click to reply →' ?></span>
      </span>
    </a>
    <?php endif; ?>
    <?php foreach ($notif_items as $n):
        $tipeColor = match($n['tipe']) {
            'sukses'    => '#16a34a',
            'peringatan'=> '#d97706',
            'error'     => '#dc2626',
            default     => '#2563eb',
        };
        $tipeIcon = match($n['tipe']) {
            'sukses'     => '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'peringatan' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'error'      => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
            default      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        };
    ?>
    <a class="ndrop-item" href="<?= notifUrl($n['judul']) ?>"
       data-id="<?= $n['id'] ?>" onclick="markOne(this)">
      <span class="ndrop-icon" style="background:<?= $tipeColor ?>20;color:<?= $tipeColor ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"
             style="width:14px;height:14px"><?= $tipeIcon ?></svg>
      </span>
      <span class="ndrop-body">
        <span class="ndrop-judul"><?= htmlspecialchars($n['judul']) ?></span>
        <span class="ndrop-pesan"><?= htmlspecialchars(mb_strimwidth($n['pesan'], 0, 72, '...')) ?></span>
        <span class="ndrop-waktu"><?= waktuLalu($n['created_at']) ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="ndrop-footer">
    <button onclick="markAllRead()" class="ndrop-mark-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           style="width:13px;height:13px"><polyline points="20 6 9 17 4 12"/></svg>
      Tandai semua sudah dibaca
    </button>
  </div>
</div>
<?php elseif ($role === 'admin'): ?>
<!-- Panel kosong (tidak ada notif baru) -->
<div id="notif-panel" style="display:none">
  <div class="ndrop-header">
    <span class="ndrop-title">
      <svg class="ic" viewBox="0 0 24 24" style="width:15px;height:15px"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
      Notifikasi
    </span>
  </div>
  <div style="padding:28px 16px;text-align:center;color:var(--text-muted);font-size:13px">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
         style="width:32px;height:32px;display:block;margin:0 auto 10px;opacity:.35"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
    Tidak ada notifikasi baru
  </div>
</div>
<?php endif; ?>

<!-- ── CSS notifikasi ── -->
<style>
/* Bell button (global) */
.notif-btn {
  position: relative;
  background: none; border: none; cursor: pointer;
  padding: 6px 8px; border-radius: 8px;
  display: inline-flex; align-items: center; gap: 6px;
  color: var(--text-secondary);
  transition: background .15s;
}
.notif-btn:hover { background: var(--bg-hover); }
.notif-badge {
  position: absolute; top: 2px; right: 2px;
  background: #ef4444; color: #fff;
  font-size: 10px; font-weight: 700; line-height: 1;
  min-width: 16px; height: 16px;
  border-radius: 8px; padding: 0 4px;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--bg-topbar, #fff);
  animation: pulse-badge .4s ease;
}
@keyframes pulse-badge {
  0%   { transform: scale(0.6); }
  60%  { transform: scale(1.2); }
  100% { transform: scale(1); }
}

/* Dropdown panel */
#notif-panel {
  position: fixed;
  top: 0; right: 0;          /* diposisikan ulang oleh JS */
  width: 340px;
  max-height: calc(100vh - 80px);
  background: var(--bg-card, #fff);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,.14);
  z-index: 1200;
  overflow: hidden;
  display: flex; flex-direction: column;
}
.ndrop-header {
  padding: 14px 16px 10px;
  border-bottom: 1px solid var(--border, #e2e8f0);
  display: flex; align-items: center; justify-content: space-between;
}
.ndrop-title {
  font-weight: 700; font-size: 13px;
  display: flex; align-items: center; gap: 6px;
  color: var(--text-primary, #1e293b);
}
.ndrop-badge {
  background: #fef3c7; color: #92400e;
  font-size: 11px; font-weight: 600;
  padding: 2px 8px; border-radius: 10px;
}
.ndrop-list { overflow-y: auto; flex: 1; }
.ndrop-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 11px 14px; text-decoration: none; color: inherit;
  border-bottom: 1px solid var(--border, #f1f5f9);
  transition: background .12s;
  cursor: pointer;
}
.ndrop-item:hover { background: var(--bg-hover, #f8fafc); }
.ndrop-icon {
  flex-shrink: 0; width: 30px; height: 30px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin-top: 1px;
}
.ndrop-body {
  display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0;
}
.ndrop-judul {
  font-size: 12px; font-weight: 600;
  color: var(--text-primary, #1e293b);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ndrop-pesan {
  font-size: 11px; color: var(--text-muted, #94a3b8);
  line-height: 1.4;
}
.ndrop-waktu {
  font-size: 10px; color: #94a3b8; margin-top: 2px;
}
.ndrop-footer {
  padding: 10px 14px;
  border-top: 1px solid var(--border, #e2e8f0);
  background: var(--bg-hover, #f8fafc);
}
.ndrop-mark-btn {
  background: none; border: none; cursor: pointer;
  font-size: 12px; color: var(--primary, #1e3a5f);
  font-weight: 600; display: flex; align-items: center; gap: 5px;
  padding: 4px 0;
  transition: opacity .15s;
}
.ndrop-mark-btn:hover { opacity: .7; }

/* Overlay saat dropdown terbuka */
#notif-overlay {
  display: none; position: fixed; inset: 0; z-index: 1190;
}
#notif-overlay.show { display: block; }

@media (max-width: 480px) {
  #notif-panel { width: calc(100vw - 24px); }
}

/* ── User menu (topbar) ── */
.um-btn {
  background:none; border:none; cursor:pointer;
  display:flex; align-items:center; gap:7px;
  padding:5px 8px; border-radius:9px;
  color:var(--text-secondary,#475569);
  transition:background .15s; flex-shrink:0;
}
.um-btn:hover { background:var(--bg-hover,#f1f5f9); }
.um-av {
  width:30px; height:30px; border-radius:50%;
  background:linear-gradient(135deg,#4a1d96,#6d28d9); color:#fff; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:12px; font-weight:700; overflow:hidden;
}
.um-name {
  font-size:13px; font-weight:600; color:var(--text-primary,#1e293b);
  max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
@media(max-width:640px) { .um-name { display:none; } }
.um-drop {
  position:absolute; top:calc(100% + 8px); right:0;
  width:224px; background:#fff;
  border:1.5px solid #e2e8f0; border-radius:13px;
  box-shadow:0 8px 28px rgba(0,0,0,.12);
  z-index:1300; overflow:hidden;
  animation:umSlideDown .15s ease;
}
@keyframes umSlideDown {
  from { opacity:0; transform:translateY(-6px); }
  to   { opacity:1; transform:translateY(0); }
}
.um-info {
  padding:14px 14px 12px;
  display:flex; align-items:center; gap:10px;
  background:#f8fafc; border-bottom:1.5px solid #f1f5f9;
}
.um-av-lg {
  width:40px; height:40px; border-radius:50%; flex-shrink:0;
  background:linear-gradient(135deg,#4a1d96,#6d28d9); color:#fff; overflow:hidden;
  display:flex; align-items:center; justify-content:center;
  font-size:15px; font-weight:700;
}
.um-info-name { font-size:13px; font-weight:700; color:#1e293b; line-height:1.3; }
.um-info-role { font-size:11px; color:#64748b; margin-top:2px; }
.um-sep { height:1px; background:#f1f5f9; }
.um-item {
  display:flex; align-items:center; gap:9px;
  padding:10px 14px; font-size:13px; color:#374151;
  text-decoration:none; transition:background .12s;
}
.um-item:hover { background:#f8fafc; color:#1e293b; }
.um-item svg { flex-shrink:0; opacity:.7; }
.um-item-danger { color:#dc2626; }
.um-item-danger:hover { background:#fff1f2; color:#b91c1c; }

/* ── Collapsible nav groups (admin sidebar) ──────────────── */
.nav-group { margin: 2px 0; }
.nav-group-head {
  display: flex; align-items: center; gap: 9px;
  width: calc(100% - 16px); margin: 1px 8px;
  padding: 8px 12px; border-radius: 7px;
  background: transparent; border: none; cursor: pointer;
  color: #ffffff; font-size: 12px; font-weight: 500;
  text-align: left;
  font-family: inherit;
  transition: background .15s, color .15s;
}
.nav-group-head:hover {
  background: rgba(251, 191, 36, 0.10);
  color: #fde047;
}
.nav-group-head:hover .ic,
.nav-group-head:hover .nav-group-chev { color: #fde047; }
.nav-group-head .ic { flex-shrink: 0; color: inherit; }
.nav-group-lbl { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: inherit; }
.nav-group-chev {
  margin-left: 4px;
  color: #ffffff;
  opacity: .65;
  transition: transform .22s ease, opacity .15s, color .15s;
  flex-shrink: 0;
}
.nav-group.open > .nav-group-head {
  color: #fde047;
}
.nav-group.open > .nav-group-head .ic,
.nav-group.open > .nav-group-head .nav-group-chev {
  color: #fde047;
  opacity: 1;
}
.nav-group.open > .nav-group-head .nav-group-chev { transform: rotate(90deg); }
.nav-group-badge {
  background: rgba(239, 68, 68, .9);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  padding: 0 6px;
  margin-left: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.nav-group.open > .nav-group-head .nav-group-badge {
  background: rgba(239, 68, 68, .7);
}
.nav-group-body {
  display: none;
  padding-left: 10px;
  margin-top: 1px;
  border-left: 1.5px solid rgba(251, 191, 36, 0.18);
  margin-left: 22px;
  margin-right: 8px;
}
.nav-group.open > .nav-group-body { display: block; animation: sbSlideIn .18s ease; }
@keyframes sbSlideIn {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}
.nav-group-body .nav-item {
  font-size: 12.5px;
  padding: 7px 10px;
  margin: 1px 0;
}
.nav-group-body .nav-item .ic {
  width: 15px; height: 15px;
}
/* Sub-group inside group (3rd level, e.g. Usulan Penelitian)
   — dibuat SEJAJAR dengan .nav-item di level yang sama (Ethical Clearance). */
.nav-subgroup { margin: 1px 0; }
.nav-subgroup > .nav-group-head {
  font-size: 12.5px;
  padding: 7px 10px;
  margin: 1px 0;
  width: 100%;
  color: #ffffff;
}
.nav-subgroup > .nav-group-head .ic { width: 15px; height: 15px; }
.nav-subgroup > .nav-group-body {
  padding-left: 10px;
  margin-left: 12px;
  margin-right: 0;
}
.nav-subgroup > .nav-group-body .nav-item {
  font-size: 12px;
  padding: 6px 9px;
}
</style>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sb-overlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebar">

  <!-- Logo -->
  <div class="sb-logo">
    <div class="sb-logo-ring"><?= ic('building') ?></div>
    <div class="sb-logo-name">LPPM IAKN Toraja</div>
    <div class="sb-logo-sub">
      <?= $lang === 'id' ? 'Sistem Informasi LPPM' : 'LPPM Information System' ?>
    </div>
  </div>

  <?php if ($is_delegate): ?>
  <div style="margin:10px 14px 0;padding:8px 11px;border-radius:9px;background:rgba(124,58,237,.18);border:1px solid rgba(167,139,250,.5);font-size:11px;color:#e9d5ff;display:flex;align-items:center;gap:7px">
    <?= ic('shield','style="width:13px;height:13px;flex-shrink:0"') ?>
    <span><?= $lang==='id'?'Mode delegasi admin aktif':'Admin delegation mode' ?></span>
  </div>
  <?php endif; ?>

  <!-- Navigation -->
  <nav class="sb-nav">

    <?php if ($role === 'mahasiswa' || $role === 'dosen'): ?>

      <!-- ─ Beranda ─ -->
      <a href="<?= BASE_URL ?>/dashboard.php"
         class="nav-item <?= isActive(['dashboard.php'], $current) ?>">
        <?= ic('home') ?>
        <?= $lang==='id' ? 'Beranda' : 'Home' ?>
      </a>

      <a href="<?= BASE_URL ?>/modules/panduan.php"
         class="nav-item <?= isActive(['panduan.php'], $current, $dir) ?>">
        <?= ic('doc') ?>
        <?= $lang==='id' ? 'Panduan' : 'Guide' ?>
      </a>

      <!-- ─ Permohonan ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Permohonan' : 'Applications' ?></div>

      <?php if ($role === 'mahasiswa'): ?>

      <a href="<?= BASE_URL ?>/modules/plagiasi/upload.php"
         class="nav-item <?= isActive(['plagiasi/upload.php'], $current, $dir) ?>">
        <?= ic('search') ?>
        <?= $lang==='id' ? 'Bebas Plagiasi' : 'Plagiarism-Free' ?>
      </a>

      <a href="<?= BASE_URL ?>/modules/publikasi/upload.php"
         class="nav-item <?= isActive(['publikasi/upload.php'], $current, $dir) ?>">
        <?= ic('newspaper') ?>
        <?= $lang==='id' ? 'Surat Publikasi' : 'Publication Letter' ?>
      </a>

      <?php elseif ($role === 'dosen'): ?>

      <a href="<?= BASE_URL ?>/modules/ethical_clearance/upload.php"
         class="nav-item <?= isActive(['ethical_clearance/upload.php'], $current, $dir) ?>">
        <?= ic('clipboard') ?>
        Ethical Clearance
      </a>

      <a href="<?= BASE_URL ?>/modules/penelitian/index.php"
         class="nav-item <?= ($dir==='penelitian' && !in_array($current,['laporan.php','luaran.php'])) ? 'active' : '' ?>">
        <svg class="ic" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
        <?= $lang==='id' ? 'Usulan Penelitian' : 'Research Proposals' ?>
      </a>
      <a href="<?= BASE_URL ?>/modules/penelitian/laporan.php"
         class="nav-item <?= ($dir==='penelitian' && $current==='laporan.php') ? 'active' : '' ?>"
         style="padding-left:32px;font-size:12px">
        <?= ic('file-text','style="width:14px;height:14px"') ?>
        <?= $lang==='id' ? 'Laporan Penelitian' : 'Research Report' ?>
      </a>
      <a href="<?= BASE_URL ?>/modules/penelitian/luaran.php"
         class="nav-item <?= ($dir==='penelitian' && $current==='luaran.php') ? 'active' : '' ?>"
         style="padding-left:32px;font-size:12px">
        <?= ic('award','style="width:14px;height:14px"') ?>
        <?= $lang==='id' ? 'Luaran Penelitian' : 'Research Output' ?>
      </a>

      <a href="<?= BASE_URL ?>/modules/pengabdian/index.php"
         class="nav-item <?= ($dir==='pengabdian' && !in_array($current,['laporan.php','luaran.php'])) ? 'active' : '' ?>">
        <?= ic('users') ?>
        <?= $lang==='id' ? 'Usulan Pengabdian' : 'Community Service' ?>
      </a>
      <a href="<?= BASE_URL ?>/modules/pengabdian/laporan.php"
         class="nav-item <?= ($dir==='pengabdian' && $current==='laporan.php') ? 'active' : '' ?>"
         style="padding-left:32px;font-size:12px">
        <?= ic('file-text','style="width:14px;height:14px"') ?>
        <?= $lang==='id' ? 'Laporan Pengabdian' : 'PkM Report' ?>
      </a>
      <a href="<?= BASE_URL ?>/modules/pengabdian/luaran.php"
         class="nav-item <?= ($dir==='pengabdian' && $current==='luaran.php') ? 'active' : '' ?>"
         style="padding-left:32px;font-size:12px">
        <?= ic('award','style="width:14px;height:14px"') ?>
        <?= $lang==='id' ? 'Luaran Pengabdian' : 'PkM Output' ?>
      </a>

      <?php endif; ?>

      <!-- ─ Akun & Komunikasi ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Akun' : 'Account' ?></div>

      <a href="<?= BASE_URL ?>/profil.php"
         class="nav-item <?= isActive(['profil.php'], $current) ?>">
        <?= ic('user') ?>
        <?= $lang==='id' ? 'Profil Saya' : 'My Profile' ?>
      </a>

      <a href="<?= BASE_URL ?>/modules/chat/"
         class="nav-item <?= isActive(['chat/index.php'], $current, $dir) ?>">
        <?= ic('chat') ?>
        <?= $lang==='id' ? 'Chat dengan Admin' : 'Chat with Admin' ?>
        <?php if ($n_chat_user): ?><span class="nav-badge"><?= $n_chat_user ?></span><?php endif; ?>
      </a>

    <?php elseif ($role === 'admin'): ?>

      <?php
      global $pdo;
      $n_pl = (int)$pdo->query("SELECT COUNT(*) FROM skripsi           WHERE status='menunggu' AND deleted_at IS NULL")->fetchColumn();
      $n_pb = (int)$pdo->query("SELECT COUNT(*) FROM publikasi          WHERE status='menunggu' AND deleted_at IS NULL")->fetchColumn();
      $n_ec = (int)$pdo->query("SELECT COUNT(*) FROM ethical_clearance  WHERE status='menunggu' AND deleted_at IS NULL")->fetchColumn();
      $n_sampah = (int)$pdo->query("
          SELECT SUM(c) FROM (
              SELECT COUNT(*) c FROM skripsi          WHERE deleted_at IS NOT NULL
              UNION ALL
              SELECT COUNT(*) c FROM publikasi         WHERE deleted_at IS NOT NULL
              UNION ALL
              SELECT COUNT(*) c FROM ethical_clearance WHERE deleted_at IS NOT NULL
          ) t")->fetchColumn();
      ?>

      <!-- ─ Beranda ─ -->
      <a href="<?= BASE_URL ?>/admin/dashboard.php"
         class="nav-item <?= isActive(['dashboard.php'], $current) ?>">
        <?= ic('home') ?>
        Dashboard
      </a>

      <a href="<?= BASE_URL ?>/admin/panduan.php"
         class="nav-item <?= isActive(['panduan.php'], $current) ?>">
        <?= ic('doc') ?>
        <?= $lang==='id' ? 'Panduan Admin' : 'Admin Guide' ?>
      </a>

      <?php
      // ── Badge counts penelitian ─────────────────────────────────
      $n_penelitian   = 0;
      $n_seleksi      = 0;
      $n_kontrak_wait = 0;
      $n_rev_pending  = 0;
      try {
          $n_penelitian   = (int)$pdo->query("SELECT COUNT(*) FROM usulan_penelitian WHERE status='diajukan' AND deleted_at IS NULL")->fetchColumn();
          $n_seleksi      = $n_penelitian;
          $n_rev_pending  = (int)$pdo->query("
              SELECT COUNT(*) FROM usulan_penelitian
              WHERE status IN ('lolos_admin','seleksi_substansi','perbaikan_substantif')
                AND deleted_at IS NULL
          ")->fetchColumn();
          $n_kontrak_wait = (int)$pdo->query("
              SELECT COUNT(*) FROM usulan_penelitian
              WHERE status IN ('disetujui','penandatanganan_kontrak')
                AND deleted_at IS NULL
          ")->fetchColumn();
      } catch (\Exception $e) {}

      // ── Aggregate untuk parent group ───────────────────────────
      $n_grp_mhs           = $n_pl + $n_pb;
      $n_grp_penelitian    = $n_penelitian + $n_rev_pending + $n_kontrak_wait;
      $n_grp_dosen         = $n_ec + $n_grp_penelitian;

      // ── Auto-expand detection ──────────────────────────────────
      $pages_mhs      = ['plagiasi.php','publikasi.php'];
      $pages_penelitian = ['penelitian.php','penelitian_seleksi.php','penelitian_reviewer.php',
                           'penelitian_kontrak.php','penelitian_laporan.php','penelitian_monev.php',
                           'penelitian_setting.php','panduan_reviewer.php',
                           'penelitian_seleksi_laporan.php','penelitian_reviewer_laporan.php',
                           'penelitian_laporan_cetak.php'];
      $pages_pengabdian = ['pengabdian.php','pengabdian_seleksi.php','pengabdian_reviewer.php',
                           'pengabdian_kontrak.php','pengabdian_laporan.php','pengabdian_monev.php',
                           'pengabdian_setting.php','panduan_reviewer_pkm.php'];
      $pages_dosen    = array_merge(['ethical_clearance.php'], $pages_penelitian, $pages_pengabdian);
      $open_mhs        = in_array($current, $pages_mhs, true);
      $open_penelitian = in_array($current, $pages_penelitian, true);
      $open_pengabdian = in_array($current, $pages_pengabdian, true);
      $open_dosen      = in_array($current, $pages_dosen, true);
      ?>

      <!-- ─ Permohonan Mahasiswa (collapsible) ─────────────────── -->
      <div class="nav-group <?= $open_mhs?'open':'' ?>" data-group="mhs">
        <button type="button" class="nav-group-head" onclick="sbToggle('mhs')">
          <?= ic('graduation') ?>
          <span class="nav-group-lbl"><?= $lang==='id' ? 'Permohonan Mahasiswa' : 'Student Applications' ?></span>
          <?php if ($n_grp_mhs): ?><span class="nav-badge nav-group-badge"><?= $n_grp_mhs ?></span><?php endif; ?>
          <svg class="nav-group-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <div class="nav-group-body">
          <a href="<?= BASE_URL ?>/admin/plagiasi.php"
             class="nav-item <?= isActive(['plagiasi.php'], $current) ?>">
            <?= ic('search') ?>
            <?= $lang==='id' ? 'Bebas Plagiasi' : 'Plagiarism-Free' ?>
            <?php if ($n_pl): ?><span class="nav-badge"><?= $n_pl ?></span><?php endif; ?>
          </a>
          <a href="<?= BASE_URL ?>/admin/publikasi.php"
             class="nav-item <?= isActive(['publikasi.php'], $current) ?>">
            <?= ic('newspaper') ?>
            <?= $lang==='id' ? 'Surat Publikasi' : 'Publication Letter' ?>
            <?php if ($n_pb): ?><span class="nav-badge"><?= $n_pb ?></span><?php endif; ?>
          </a>
        </div>
      </div>

      <!-- ─ Permohonan Dosen (collapsible) ────────────────────── -->
      <div class="nav-group <?= $open_dosen?'open':'' ?>" data-group="dosen">
        <button type="button" class="nav-group-head" onclick="sbToggle('dosen')">
          <?= ic('briefcase') ?>
          <span class="nav-group-lbl"><?= $lang==='id' ? 'Permohonan Dosen' : 'Lecturer Applications' ?></span>
          <?php if ($n_grp_dosen): ?><span class="nav-badge nav-group-badge"><?= $n_grp_dosen ?></span><?php endif; ?>
          <svg class="nav-group-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <div class="nav-group-body">
          <a href="<?= BASE_URL ?>/admin/ethical_clearance.php"
             class="nav-item <?= isActive(['ethical_clearance.php'], $current) ?>">
            <?= ic('clipboard') ?>
            Ethical Clearance
            <?php if ($n_ec): ?><span class="nav-badge"><?= $n_ec ?></span><?php endif; ?>
          </a>

          <!-- ── Sub-group: Usulan Penelitian ── -->
          <div class="nav-group nav-subgroup <?= $open_penelitian?'open':'' ?>" data-group="penelitian">
            <button type="button" class="nav-group-head" onclick="sbToggle('penelitian')">
              <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
              </svg>
              <span class="nav-group-lbl"><?= $lang==='id' ? 'Usulan Penelitian' : 'Research Proposals' ?></span>
              <?php if ($n_grp_penelitian): ?><span class="nav-badge nav-group-badge"><?= $n_grp_penelitian ?></span><?php endif; ?>
              <svg class="nav-group-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <div class="nav-group-body">
              <a href="<?= BASE_URL ?>/admin/penelitian.php"
                 class="nav-item <?= isActive(['penelitian.php'], $current) ?>">
                <?= ic('home','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Dashboard Penelitian' : 'Research Dashboard' ?>
                <?php if ($n_penelitian): ?><span class="nav-badge"><?= $n_penelitian ?></span><?php endif; ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_seleksi.php"
                 class="nav-item <?= isActive(['penelitian_seleksi.php'], $current) ?>">
                <?= ic('clipboard','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Seleksi Administratif' : 'Admin Review' ?>
                <?php if ($n_seleksi): ?><span class="nav-badge"><?= $n_seleksi ?></span><?php endif; ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_reviewer.php"
                 class="nav-item <?= isActive(['penelitian_reviewer.php'], $current) ?>">
                <?= ic('users','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Seleksi Substantif' : 'Substantive Review' ?>
                <?php if ($n_rev_pending): ?><span class="nav-badge"><?= $n_rev_pending ?></span><?php endif; ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_kontrak.php"
                 class="nav-item <?= isActive(['penelitian_kontrak.php'], $current) ?>">
                <?= ic('doc','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Kontrak Penelitian' : 'Research Contract' ?>
                <?php if ($n_kontrak_wait): ?><span class="nav-badge"><?= $n_kontrak_wait ?></span><?php endif; ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_laporan.php"
                 class="nav-item <?= isActive(['penelitian_laporan.php'], $current) ?>">
                <?= ic('chart','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Laporan Penelitian' : 'Research Report' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_monev.php"
                 class="nav-item <?= isActive(['penelitian_monev.php'], $current) ?>">
                <?= ic('award','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Monev Penelitian' : 'Research Monev' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/penelitian_setting.php"
                 class="nav-item <?= isActive(['penelitian_setting.php'], $current) ?>">
                <?= ic('settings','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Pengaturan Penelitian' : 'Research Settings' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/panduan_reviewer.php"
                 class="nav-item <?= isActive(['panduan_reviewer.php'], $current) ?>">
                <?= ic('shield','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Panduan Reviewer' : 'Reviewer Guidelines' ?>
              </a>
            </div>
          </div>

          <!-- ── Sub-group: Usulan Pengabdian ── -->
          <div class="nav-group nav-subgroup <?= $open_pengabdian?'open':'' ?>" data-group="pengabdian">
            <button type="button" class="nav-group-head" onclick="sbToggle('pengabdian')">
              <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span class="nav-group-lbl"><?= $lang==='id' ? 'Usulan Pengabdian' : 'Community Service' ?></span>
              <svg class="nav-group-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <div class="nav-group-body">
              <a href="<?= BASE_URL ?>/admin/pengabdian.php"
                 class="nav-item <?= isActive(['pengabdian.php'], $current) ?>">
                <?= ic('home','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Dashboard Pengabdian' : 'Service Dashboard' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_seleksi.php"
                 class="nav-item <?= isActive(['pengabdian_seleksi.php'], $current) ?>">
                <?= ic('clipboard','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Seleksi Administratif' : 'Admin Review' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_reviewer.php"
                 class="nav-item <?= isActive(['pengabdian_reviewer.php'], $current) ?>">
                <?= ic('users','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Seleksi Substantif' : 'Substantive Review' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_kontrak.php"
                 class="nav-item <?= isActive(['pengabdian_kontrak.php'], $current) ?>">
                <?= ic('doc','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Kontrak Pengabdian' : 'PkM Contract' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_laporan.php"
                 class="nav-item <?= isActive(['pengabdian_laporan.php'], $current) ?>">
                <?= ic('chart','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Laporan Pengabdian' : 'PkM Report' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_monev.php"
                 class="nav-item <?= isActive(['pengabdian_monev.php'], $current) ?>">
                <?= ic('award','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Monev Pengabdian' : 'PkM Monev' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/pengabdian_setting.php"
                 class="nav-item <?= isActive(['pengabdian_setting.php'], $current) ?>">
                <?= ic('settings','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Pengaturan PkM' : 'PkM Settings' ?>
              </a>
              <a href="<?= BASE_URL ?>/admin/panduan_reviewer_pkm.php"
                 class="nav-item <?= isActive(['panduan_reviewer_pkm.php'], $current) ?>">
                <?= ic('shield','style="width:14px;height:14px"') ?>
                <?= $lang==='id' ? 'Panduan Reviewer PkM' : 'PkM Reviewer Guidelines' ?>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- ─ Arsip & Laporan ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Arsip & Laporan' : 'Archive & Reports' ?></div>

      <a href="<?= BASE_URL ?>/admin/surat.php"
         class="nav-item <?= isActive(['surat.php'], $current) ?>">
        <?= ic('archive') ?>
        <?= $lang==='id' ? 'Arsip Surat' : 'Letter Archive' ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/laporan.php"
         class="nav-item <?= isActive(['laporan.php'], $current) ?>">
        <?= ic('chart') ?>
        <?= $lang==='id' ? 'Laporan & Statistik' : 'Reports & Statistics' ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/ekspor.php"
         class="nav-item <?= isActive(['ekspor.php'], $current) ?>">
        <?= ic('download') ?>
        <?= $lang==='id' ? 'Ekspor Data' : 'Export Data' ?>
      </a>

      <!-- ─ Pengguna & Komunikasi ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Pengguna & Komunikasi' : 'Users & Communication' ?></div>

      <a href="<?= BASE_URL ?>/admin/pengguna.php"
         class="nav-item <?= isActive(['pengguna.php','mahasiswa.php'], $current) ?>">
        <?= ic('users') ?>
        <?= $lang==='id' ? 'Kelola Pengguna' : 'Manage Users' ?>
      </a>

      <?php if (!$is_delegate): /* Delegasi hanya admin utama */ ?>
      <a href="<?= BASE_URL ?>/admin/delegasi.php"
         class="nav-item <?= isActive(['delegasi.php'], $current) ?>">
        <?= ic('shield') ?>
        <?= $lang==='id' ? 'Delegasi Admin' : 'Admin Delegation' ?>
      </a>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/admin/chat.php"
         class="nav-item <?= isActive(['chat.php'], $current) ?>">
        <?= ic('chat') ?>
        <?= $lang==='id' ? 'Pesan Masuk' : 'Inbox' ?>
        <?php if ($n_chat_admin): ?><span class="nav-badge"><?= $n_chat_admin ?></span><?php endif; ?>
      </a>

      <!-- ─ Konfigurasi & Sistem ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Konfigurasi & Sistem' : 'Config & System' ?></div>

      <a href="<?= BASE_URL ?>/admin/ref_akademik.php"
         class="nav-item <?= isActive(['ref_akademik.php'], $current) ?>">
        <?= ic('building') ?>
        <?= $lang==='id' ? 'Referensi Akademik' : 'Academic Reference' ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/pengaturan.php"
         class="nav-item <?= isActive(['pengaturan.php'], $current) ?>">
        <?= ic('settings') ?>
        <?= $lang==='id' ? 'Pengaturan Sistem' : 'System Settings' ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/log.php"
         class="nav-item <?= isActive(['log.php'], $current) ?>">
        <?= ic('shield') ?>
        <?= $lang==='id' ? 'Log Aktivitas' : 'Activity Log' ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/sampah.php"
         class="nav-item <?= isActive(['sampah.php'], $current) ?>">
        <?= ic('trash') ?>
        <?= $lang==='id' ? 'Sampah' : 'Trash' ?>
        <?php if ($n_sampah): ?><span class="nav-badge" style="background:#ef4444"><?= $n_sampah ?></span><?php endif; ?>
      </a>

    <?php elseif ($role === 'reviewer'): ?>

      <!-- ─ Beranda Reviewer ─ -->
      <a href="<?= BASE_URL ?>/modules/penelitian/reviewer.php"
         class="nav-item <?= isActive(['reviewer.php'], $current) ?>">
        <?= ic('home') ?>
        <?= $lang==='id' ? 'Beranda' : 'Home' ?>
      </a>

      <!-- ─ Review ─ -->
      <div class="sb-section"><?= $lang==='id' ? 'Penilaian' : 'Assessment' ?></div>

      <?php
      global $pdo;
      $rid = (int)($_SESSION['user_id'] ?? 0);
      $n_pen_rev = 0; $n_pgb_rev = 0;
      try {
          $stmt = $pdo->prepare("
              SELECT COUNT(*) FROM reviewer_assignment ra
              LEFT JOIN reviewer_penilaian rp ON rp.assignment_id=ra.id
              WHERE ra.reviewer_id=? AND (rp.submitted_at IS NULL OR rp.id IS NULL)
          ");
          $stmt->execute([$rid]);
          $n_pen_rev = (int)$stmt->fetchColumn();
      } catch (\Exception $e) {}
      try {
          $stmt = $pdo->prepare("
              SELECT COUNT(*) FROM reviewer_assignment_pengabdian ra
              LEFT JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id=ra.id
              WHERE ra.reviewer_id=? AND (rp.submitted_at IS NULL OR rp.id IS NULL)
                AND ra.deleted_at IS NULL
          ");
          $stmt->execute([$rid]);
          $n_pgb_rev = (int)$stmt->fetchColumn();
      } catch (\Exception $e) {}
      $n_pending_rev = $n_pen_rev + $n_pgb_rev;
      ?>
      <a href="<?= BASE_URL ?>/modules/penelitian/reviewer.php"
         class="nav-item <?= ($dir==='penelitian' && $current==='reviewer.php')?'active':'' ?>">
        <?= ic('award') ?>
        <?= $lang==='id' ? 'Proposal Penelitian' : 'Research Proposals' ?>
        <?php if ($n_pen_rev): ?><span class="nav-badge"><?= $n_pen_rev ?></span><?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/modules/pengabdian/reviewer.php"
         class="nav-item <?= ($dir==='pengabdian' && $current==='reviewer.php')?'active':'' ?>">
        <?= ic('users') ?>
        <?= $lang==='id' ? 'Proposal Pengabdian' : 'PkM Proposals' ?>
        <?php if ($n_pgb_rev): ?><span class="nav-badge"><?= $n_pgb_rev ?></span><?php endif; ?>
      </a>

      <div class="sb-section"><?= $lang==='id' ? 'Akun' : 'Account' ?></div>
      <a href="<?= BASE_URL ?>/profil.php"
         class="nav-item <?= isActive(['profil.php'], $current) ?>">
        <?= ic('user') ?>
        <?= $lang==='id' ? 'Profil Saya' : 'My Profile' ?>
      </a>

    <?php endif; ?>

    <!-- Logout -->
    <a href="<?= BASE_URL ?>/logout.php" class="nav-item" style="margin-top:8px;border-top:1px solid rgba(255,255,255,.06);padding-top:12px">
      <?= ic('logout') ?>
      <?= $lang==='id' ? 'Keluar' : 'Logout' ?>
    </a>

  </nav>

  <!-- Footer: avatar + edit profil -->
  <?php
    $profil_url = $role === 'admin' ? BASE_URL . '/admin/profil.php' : BASE_URL . '/profil.php';
    $foto = $_SESSION['foto_profil'] ?? null;
    // Reviewer sidebar info
    if ($role === 'reviewer') $profil_url = BASE_URL . '/profil.php';
  ?>
  <a href="<?= $profil_url ?>" class="sb-footer" title="<?= $lang==='id'?'Edit Profil':'Edit Profile' ?>" style="text-decoration:none">
    <div class="sb-avatar">
      <?php if ($foto): ?>
        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($foto) ?>" alt="avatar"
             style="width:100%;height:100%;object-fit:cover;border-radius:50%">
      <?php else: ?>
        <?= $inisial ?>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div class="sb-username"><?= htmlspecialchars(mb_strimwidth($nama, 0, 20, '...')) ?></div>
      <div class="sb-userinfo">
        <?php
          if ($role === 'admin') {
              echo 'Admin LPPM';
          } elseif ($role === 'dosen') {
              $nidn_sb = htmlspecialchars($_SESSION['nidn'] ?? '');
              echo $nidn_sb ? 'Dosen — NIDN ' . $nidn_sb : 'Dosen';
          } else {
              echo htmlspecialchars($_SESSION['nim'] ?? '');
          }
        ?>
      </div>
    </div>
    <div style="flex-shrink:0;opacity:0.45;padding-right:2px">
      <?= ic('edit') ?>
    </div>
  </a>

</div>

<!-- Overlay klik-luar untuk menutup dropdown notifikasi -->
<div id="notif-overlay" onclick="closeNotif()"></div>

<script>
// ─────────────────────────────────────────────────────────────
// Notifikasi bell — inject ke topbar + dropdown
// ─────────────────────────────────────────────────────────────
(function () {
  const COUNT = <?= (int)$total_bell ?>;
  const IS_ADMIN = <?= $role === 'admin' ? 'true' : 'false' ?>;

  if (!IS_ADMIN) return;

  document.addEventListener('DOMContentLoaded', function () {
    // Hapus bell lama yang ditaruh manual di halaman (dead button)
    document.querySelectorAll('.notif-btn').forEach(b => b.remove());

    const topbarRight = document.querySelector('.topbar-right');
    if (!topbarRight) return;

    // Buat bell button
    const btn = document.createElement('button');
    btn.className = 'notif-btn';
    btn.id        = 'notif-toggle';
    btn.title     = 'Notifikasi';
    btn.setAttribute('aria-label', 'Notifikasi');
    btn.innerHTML =
      '<svg class="ic" viewBox="0 0 24 24" style="width:18px;height:18px" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
        '<path d="M13.73 21a2 2 0 01-3.46 0"/>' +
      '</svg>' +
      (COUNT > 0 ? '<span class="notif-badge">' + (COUNT > 99 ? '99+' : COUNT) + '</span>' : '');

    btn.addEventListener('click', toggleNotif);

    // Sisipkan sebelum lang-toggle
    const langBtn = topbarRight.querySelector('.lang-toggle');
    topbarRight.insertBefore(btn, langBtn || topbarRight.firstChild);
  });
})();

// ── Toggle buka/tutup dropdown ──────────────────────────────
function toggleNotif(e) {
  e.stopPropagation();
  const panel   = document.getElementById('notif-panel');
  const overlay = document.getElementById('notif-overlay');
  const btn     = document.getElementById('notif-toggle');
  if (!panel) return;

  const isOpen = panel.style.display !== 'none';
  if (isOpen) {
    closeNotif();
  } else {
    // Posisikan dropdown di bawah bell button
    const rect = btn.getBoundingClientRect();
    panel.style.display = 'flex';
    panel.style.top     = (rect.bottom + 8) + 'px';
    // Jaga agar tidak keluar layar kanan
    const right = window.innerWidth - rect.right;
    panel.style.right = Math.max(8, right) + 'px';
    panel.style.left  = 'auto';
    overlay.classList.add('show');
  }
}

function closeNotif() {
  const panel = document.getElementById('notif-panel');
  if (panel) panel.style.display = 'none';
  const overlay = document.getElementById('notif-overlay');
  if (overlay) overlay.classList.remove('show');
}

// ── Tandai satu notif sebagai dibaca lalu navigasi ───────────
function markOne(el) {
  const id   = el.dataset.id;
  const url  = <?= json_encode(BASE_URL . '/admin/notif_action.php') ?>;
  const body = new URLSearchParams({ action: 'mark_one', id });
  navigator.sendBeacon ? navigator.sendBeacon(url, body) : fetch(url, { method:'POST', body });
  el.remove();
  refreshBadge();
}

// ── Tandai semua notif sebagai dibaca (chat item dipertahankan) ──
function markAllRead() {
  fetch(<?= json_encode(BASE_URL . '/admin/notif_action.php') ?>, {
    method: 'POST',
    body: new URLSearchParams({ action: 'mark_read' })
  })
  .then(r => r.json())
  .then(() => {
    const list = document.querySelector('.ndrop-list');
    if (list) {
      // Pertahankan chat item jika ada
      const chatItem = list.querySelector('[data-chat]');
      list.innerHTML =
        '<div style="padding:20px 16px;text-align:center;color:#94a3b8;font-size:13px">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:26px;height:26px;display:block;margin:0 auto 8px;opacity:.35">' +
        '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>' +
        'Tidak ada notifikasi baru</div>';
      if (chatItem) list.prepend(chatItem);
    }
    refreshBadge();
    const footerBtn = document.querySelector('.ndrop-mark-btn');
    if (footerBtn) footerBtn.disabled = true;
    if (!document.querySelector('.ndrop-item:not([data-chat])')) {
      setTimeout(closeNotif, 600);
    }
  });
}

// ── Update badge count (hanya hitung notif system, bukan chat) ──
function refreshBadge() {
  // Hitung notif system saja (exclude chat item)
  const systemItems = document.querySelectorAll('.ndrop-item:not([data-chat])');
  const chatItem    = document.querySelector('.ndrop-item[data-chat]');
  const chatCount   = chatItem ? 1 : 0;   // simplified: chat row counts as 1 group
  const remaining   = systemItems.length + chatCount;
  const badge       = document.querySelector('#notif-toggle .notif-badge');
  if (remaining === 0) {
    if (badge) badge.remove();
    setTimeout(closeNotif, 400);
  } else if (badge) {
    badge.textContent = remaining > 99 ? '99+' : remaining;
  }
}

// ─────────────────────────────────────────────────────────────
// Collapsible admin nav groups — auto-collapse saat navigasi ke menu lain.
// Group hanya open berdasarkan halaman aktif (PHP auto-detect).
// Toggle manual bersifat sementara (hanya untuk session visual halaman ini);
// saat user klik menu baru, page reload, PHP tentukan ulang group yang terbuka.
// ─────────────────────────────────────────────────────────────
function sbToggle(key) {
  const el = document.querySelector('.nav-group[data-group="' + key + '"]');
  if (!el) return;
  // Accordion behavior: tutup semua group lain di level yang sama sebelum buka ini
  const siblings = el.parentElement.querySelectorAll(':scope > .nav-group');
  siblings.forEach(s => { if (s !== el) s.classList.remove('open'); });
  el.classList.toggle('open');
}

// Pembersihan localStorage lama (migrasi dari behavior sebelumnya)
(function(){
  try {
    Object.keys(localStorage).forEach(k => {
      if (k.indexOf('sb-grp-') === 0) localStorage.removeItem(k);
    });
  } catch(e){}
})();

// ─────────────────────────────────────────────────────────────
// Sidebar mobile
// ─────────────────────────────────────────────────────────────
function openSidebar()  {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sb-overlay').classList.add('show');
  var cb = document.getElementById('float-chat-btn');
  if (cb) cb.style.display = 'none';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sb-overlay').classList.remove('show');
  var cb = document.getElementById('float-chat-btn');
  if (cb) cb.style.display = '';
}
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}

// ── Profile avatar + dropdown (semua role) ───────────────────
(function(){
  const NAMA  = <?= json_encode(mb_strimwidth($nama, 0, 26, '…')) ?>;
  const INIT  = <?= json_encode($inisial) ?>;
  const FOTO  = <?= json_encode($_SESSION['foto_profil'] ? BASE_URL . '/' . $_SESSION['foto_profil'] : null) ?>;
  const PURL  = <?= json_encode($role === 'admin' ? BASE_URL . '/admin/profil.php' : BASE_URL . '/profil.php') ?>;
  const RLBL  = <?= json_encode(
      $role === 'admin'
          ? 'Admin LPPM'
          : ($role === 'reviewer'
              ? 'Reviewer'
              : ($role === 'dosen'
                  ? 'Dosen' . (isset($_SESSION['nidn']) && $_SESSION['nidn'] ? ' · NIDN ' . $_SESSION['nidn'] : '')
                  : (isset($_SESSION['nim']) && $_SESSION['nim'] ? $_SESSION['nim'] : 'Mahasiswa')))
  ) ?>;
  const LNG   = <?= json_encode($lang) ?>;

  const avHTML = FOTO
    ? `<img src="${FOTO}" style="width:100%;height:100%;object-fit:cover;border-radius:50%" alt="">`
    : INIT;

  // Add CSS for um-wrap (position:relative needs to be inline since it's a JS element)
  document.addEventListener('DOMContentLoaded', function(){
    const topbarRight = document.querySelector('.topbar-right');
    if (!topbarRight) return;

    // Container
    const wrap = document.createElement('div');
    wrap.id = 'um-wrap';
    wrap.style.cssText = 'position:relative;display:inline-flex;align-items:center;flex-shrink:0';

    // Button
    const btn = document.createElement('button');
    btn.className = 'um-btn';
    btn.id = 'um-btn';
    btn.setAttribute('aria-label', LNG==='id'?'Menu akun':'Account menu');
    btn.innerHTML =
      `<div class="um-av">${avHTML}</div>` +
      `<span class="um-name">${NAMA}</span>` +
      `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" ` +
        `stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">` +
        `<path d="M6 9l6 6 6-6"/></svg>`;
    btn.addEventListener('click', function(e){ e.stopPropagation(); toggleUserMenu(); });

    // Dropdown
    const drop = document.createElement('div');
    drop.className = 'um-drop';
    drop.id = 'um-drop';
    drop.style.display = 'none';
    drop.innerHTML =
      `<div class="um-info">` +
        `<div class="um-av-lg">${avHTML}</div>` +
        `<div>` +
          `<div class="um-info-name">${NAMA}</div>` +
          `<div class="um-info-role">${RLBL}</div>` +
        `</div>` +
      `</div>` +
      `<div class="um-sep"></div>` +
      `<a href="${PURL}" class="um-item">` +
        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">` +
          `<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>` +
          `<circle cx="12" cy="7" r="4"/>` +
        `</svg>` +
        `${LNG==='id'?'Profil Saya':'My Profile'}` +
      `</a>` +
      `<div class="um-sep"></div>` +
      `<a href="<?= BASE_URL ?>/logout.php" class="um-item um-item-danger">` +
        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">` +
          `<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>` +
          `<polyline points="16 17 21 12 16 7"/>` +
          `<line x1="21" y1="12" x2="9" y2="12"/>` +
        `</svg>` +
        `${LNG==='id'?'Keluar':'Logout'}` +
      `</a>`;

    wrap.appendChild(btn);
    wrap.appendChild(drop);
    topbarRight.appendChild(wrap);
  });
})();

function toggleUserMenu(){
  const drop = document.getElementById('um-drop');
  if (!drop) return;
  const isOpen = drop.style.display !== 'none';
  drop.style.display = isOpen ? 'none' : 'block';
}

// Tutup user menu saat klik di luar
document.addEventListener('click', function(e){
  const wrap = document.getElementById('um-wrap');
  const drop = document.getElementById('um-drop');
  if (drop && wrap && !wrap.contains(e.target)) {
    drop.style.display = 'none';
  }
});

// ── Floating chat button (semua role) ─────────────────────────
(function(){
  <?php
    $chat_url    = $role === 'admin'
                     ? BASE_URL . '/admin/chat.php'
                     : BASE_URL . '/modules/chat/';
    $chat_unread = $role === 'admin' ? (int)$n_chat_admin : (int)($n_chat_user ?? 0);
    $chat_title  = $role === 'admin'
                     ? ($lang==='id' ? 'Pesan Masuk' : 'Inbox')
                     : ($lang==='id' ? 'Chat dengan Admin' : 'Chat with Admin');
    // Deteksi apakah sudah di halaman chat (sembunyikan tombol)
    $on_chat = ($role === 'admin' && $current === 'chat.php')
            || ($role !== 'admin' && $dir === 'chat');
  ?>
  const CHAT_URL  = <?= json_encode($chat_url) ?>;
  const UNREAD    = <?= $chat_unread ?>;
  const TITLE     = <?= json_encode($chat_title) ?>;
  const ON_CHAT   = <?= $on_chat ? 'true' : 'false' ?>;

  if (ON_CHAT) return; // sudah di halaman chat, sembunyikan tombol

  document.addEventListener('DOMContentLoaded', function(){
    const btn = document.createElement('a');
    btn.href      = CHAT_URL;
    btn.title     = TITLE;
    btn.id        = 'float-chat-btn';
    btn.style.cssText =
      'position:fixed;bottom:28px;right:28px;z-index:190;' +
      'width:48px;height:48px;border-radius:50%;' +
      'background:linear-gradient(135deg,#4a1d96,#7c3aed);color:#fff;' +
      'display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 4px 18px rgba(74,29,150,.45);' +
      'text-decoration:none;transition:transform .18s,box-shadow .18s;';
    btn.innerHTML =
      '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
        ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' +
      '</svg>' +
      (UNREAD > 0
        ? '<span style="position:absolute;top:-3px;right:-3px;background:#ef4444;color:#fff;' +
          'font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;' +
          'padding:0 4px;display:flex;align-items:center;justify-content:center;' +
          'border:2px solid #fff;line-height:1">' + (UNREAD > 9 ? '9+' : UNREAD) + '</span>'
        : '');
    btn.addEventListener('mouseenter', function(){
      btn.style.transform = 'scale(1.1)';
      btn.style.boxShadow = '0 6px 24px rgba(74,29,150,.55)';
    });
    btn.addEventListener('mouseleave', function(){
      btn.style.transform = '';
      btn.style.boxShadow = '0 4px 18px rgba(74,29,150,.45)';
    });
    document.body.appendChild(btn);
  });
})();

// Auto-inject site footer (Copyright)
document.addEventListener('DOMContentLoaded', function () {
  const mc = document.querySelector('.main-content');
  if (mc && !mc.querySelector('.site-footer')) {
    const yr = new Date().getFullYear();
    const ft = document.createElement('div');
    ft.className = 'site-footer';
    // Gunakan format yang lebih institusional
    ft.innerHTML = '&copy; ' + yr + ' LPPM IAKN Toraja. &nbsp;<span>Powered by <strong>DIPACOM</strong></span>';
    mc.appendChild(ft);
  }
});

// Auto-inject favicon
document.addEventListener('DOMContentLoaded', function () {
  if (!document.querySelector('link[rel="icon"]')) {
    var lnk = document.createElement('link');
    lnk.rel = 'icon'; lnk.type = 'image/svg+xml';
    lnk.href = <?= json_encode(BASE_URL . '/assets/img/favicon.svg') ?>;
    document.head.appendChild(lnk);
  }
});

// Auto-inject hamburger
document.addEventListener('DOMContentLoaded', function () {
  var topbar = document.querySelector('.topbar');
  if (!topbar || topbar.querySelector('.sidebar-toggle')) return;
  var btn = document.createElement('button');
  btn.className = 'sidebar-toggle';
  btn.setAttribute('onclick', 'openSidebar()');
  btn.setAttribute('aria-label', 'Open menu');
  btn.innerHTML = '<svg class="ic" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
  topbar.insertBefore(btn, topbar.firstChild);
});
</script>
