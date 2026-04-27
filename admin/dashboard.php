<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';

// ── Pengguna ────────────────────────────────────────────────────
$total_mhs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='mahasiswa'")->fetchColumn();
$total_dsn = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='dosen'")->fetchColumn();

$preview_mhs = $pdo->query(
    "SELECT id, nama_lengkap, nim, program_studi, foto_profil
     FROM users WHERE role='mahasiswa' AND is_active=1
     ORDER BY created_at DESC LIMIT 5"
)->fetchAll();
$preview_dsn = $pdo->query(
    "SELECT id, nama_lengkap, nidn, program_studi, foto_profil
     FROM users WHERE role='dosen' AND is_active=1
     ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

// ── Statistik permohonan ────────────────────────────────────────

// Plagiasi
$pl_stats = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM skripsi WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $r) {
    $pl_stats[$r['status']] = (int)$r['c'];
}
$pl_total    = array_sum($pl_stats);
$pl_menunggu = $pl_stats['menunggu']  ?? 0;
$pl_selesai  = $pl_stats['selesai']   ?? 0;
$pl_ditolak  = $pl_stats['ditolak']   ?? 0;

// Publikasi
$pb_stats = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM publikasi WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $r) {
    $pb_stats[$r['status']] = (int)$r['c'];
}
$pb_total    = array_sum($pb_stats);
$pb_menunggu = $pb_stats['menunggu'] ?? 0;
$pb_selesai  = $pb_stats['selesai']  ?? 0;
$pb_ditolak  = $pb_stats['ditolak']  ?? 0;

// Ethical Clearance
$ec_stats = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM ethical_clearance WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $r) {
    $ec_stats[$r['status']] = (int)$r['c'];
}
$ec_total    = array_sum($ec_stats);
$ec_menunggu = $ec_stats['menunggu']  ?? 0;
$ec_diproses = $ec_stats['diproses']  ?? 0;
$ec_disetujui = $ec_stats['disetujui'] ?? 0;
$ec_ditolak  = $ec_stats['ditolak']   ?? 0;

// Surat bulan ini & total
$surat_bulan = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT id FROM surat_plagiasi WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())
        UNION ALL
        SELECT id FROM surat_publikasi WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())
    ) s")->fetchColumn();
$surat_total = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT id FROM surat_plagiasi UNION ALL SELECT id FROM surat_publikasi
    ) s")->fetchColumn();

// Usulan Penelitian
$pen_stats = [];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM usulan_penelitian WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $r) {
        $pen_stats[$r['status']] = (int)$r['c'];
    }
} catch (\Exception $e) {}
$pen_total     = array_sum($pen_stats);
$pen_diajukan  = $pen_stats['diajukan']  ?? 0;
$pen_ditinjau  = $pen_stats['ditinjau']  ?? 0;
$pen_disetujui = $pen_stats['disetujui'] ?? 0;
$pen_direvisi  = $pen_stats['direvisi']  ?? 0;
$pen_ditolak   = $pen_stats['ditolak']   ?? 0;
$pen_draft     = $pen_stats['draft']     ?? 0;
$pen_pending   = $pen_diajukan + $pen_ditinjau;

$recent_pen = [];
try {
    $recent_pen = $pdo->query("
        SELECT up.*, u.nama_lengkap, u.nidn, u.program_studi, u.jabatan_fungsional,
               sk.nama AS skema_nama
        FROM usulan_penelitian up
        JOIN users u ON up.user_id = u.id
        LEFT JOIN skema_penelitian sk ON up.skema = sk.kode AND sk.tahun = up.tahun_anggaran
        WHERE up.status IN ('diajukan','ditinjau') AND up.deleted_at IS NULL
        ORDER BY FIELD(up.status,'diajukan','ditinjau'), up.created_at ASC
        LIMIT 10
    ")->fetchAll();
} catch (\Exception $e) {}

$recent_pgb = [];
try {
    $recent_pgb = $pdo->query("
        SELECT up.*, u.nama_lengkap, u.nidn, u.program_studi, u.jabatan_fungsional,
               sk.nama AS skema_nama
        FROM usulan_pengabdian up
        JOIN users u ON up.user_id = u.id
        LEFT JOIN skema_pengabdian sk
               ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci
              AND sk.tahun = up.tahun_anggaran
        WHERE up.status IN ('diajukan','ditinjau','seleksi_admin','seleksi_substansi','perbaikan_substantif')
              AND up.deleted_at IS NULL
        ORDER BY FIELD(up.status,'diajukan','seleksi_admin','seleksi_substansi','perbaikan_substantif','ditinjau'),
                 up.created_at ASC
        LIMIT 10
    ")->fetchAll();
} catch (\Exception $e) {}

// ── Usulan Pengabdian (mirror penelitian) ──────────────────────
$pgb_stats = [];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM usulan_pengabdian WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $r) {
        $pgb_stats[$r['status']] = (int)$r['c'];
    }
} catch (\Exception $e) {}
$pgb_total     = array_sum($pgb_stats);
$pgb_diajukan  = $pgb_stats['diajukan']  ?? 0;
$pgb_ditinjau  = ($pgb_stats['ditinjau']  ?? 0) + ($pgb_stats['seleksi_admin'] ?? 0) + ($pgb_stats['seleksi_substansi'] ?? 0);
$pgb_disetujui = ($pgb_stats['disetujui'] ?? 0) + ($pgb_stats['kontrak_aktif'] ?? 0) + ($pgb_stats['laporan_diterima'] ?? 0) + ($pgb_stats['selesai'] ?? 0);
$pgb_direvisi  = ($pgb_stats['revisi_minor'] ?? 0) + ($pgb_stats['revisi_mayor'] ?? 0) + ($pgb_stats['perbaikan_admin'] ?? 0) + ($pgb_stats['perbaikan_substantif'] ?? 0);
$pgb_ditolak   = ($pgb_stats['ditolak']   ?? 0) + ($pgb_stats['gagal_admin'] ?? 0);
$pgb_draft     = $pgb_stats['draft']      ?? 0;
$pgb_pending   = $pgb_diajukan + ($pgb_stats['lolos_admin'] ?? 0) + ($pgb_stats['seleksi_substansi'] ?? 0);

// Total menunggu (semua jenis) — termasuk penelitian + pengabdian
$total_pending = $pl_menunggu + $pb_menunggu + $ec_menunggu + $pen_pending + $pgb_pending;

// ── Antrian permohonan ──────────────────────────────────────────

$recent_pl = $pdo->query("
    SELECT s.*, u.nama_lengkap, u.nim, u.program_studi, u.fakultas,
           cp.id AS cp_id, cp.similarity_score, cp.ai_score, cp.platform_ai, cp.tanggal_cek, cp.catatan
    FROM skripsi s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN cek_plagiasi cp ON s.id = cp.skripsi_id
    WHERE s.status = 'menunggu' AND s.deleted_at IS NULL
    ORDER BY s.created_at ASC
    LIMIT 10
")->fetchAll();

$recent_pub = $pdo->query("
    SELECT p.*, u.nama_lengkap, u.nim, u.nidn, u.program_studi, u.fakultas
    FROM publikasi p
    JOIN users u ON p.user_id = u.id
    WHERE p.status = 'menunggu' AND p.deleted_at IS NULL
    ORDER BY p.created_at ASC
    LIMIT 10
")->fetchAll();

$recent_ec = $pdo->query("
    SELECT ec.*, u.nama_lengkap, u.nim, u.nidn, u.program_studi, u.fakultas
    FROM ethical_clearance ec
    JOIN users u ON ec.user_id = u.id
    WHERE ec.status IN ('menunggu','diproses') AND ec.deleted_at IS NULL
    ORDER BY FIELD(ec.status,'menunggu','diproses'), ec.created_at ASC
    LIMIT 10
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ════════════════════════════════════════════════════════════
   RINGKASAN USULAN — Group + Card Interaktif
════════════════════════════════════════════════════════════ */
.usulan-groups{display:flex;flex-direction:column;gap:18px;margin-bottom:22px}
.usulan-group{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden;
              box-shadow:0 1px 3px rgba(0,0,0,.04)}
.ug-head{padding:13px 18px;display:flex;align-items:center;gap:13px;color:#fff}
.ug-head-icon{width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.18);
              display:flex;align-items:center;justify-content:center;flex-shrink:0;
              border:1.5px solid rgba(255,255,255,.22)}
.ug-head-title{flex:1;min-width:0}
.ug-head-name{font-size:14.5px;font-weight:800;letter-spacing:.2px}
.ug-head-meta{font-size:11.5px;opacity:.85;margin-top:2px}
.ug-head-badge{background:#fff;color:#dc2626;font-weight:800;font-size:13px;
               min-width:32px;height:30px;border-radius:9px;padding:0 10px;
               display:inline-flex;align-items:center;justify-content:center;
               box-shadow:0 2px 8px rgba(0,0,0,.18);animation:pulseB 1.6s ease-in-out infinite}
@keyframes pulseB{0%,100%{transform:scale(1)} 50%{transform:scale(1.06)}}

.ug-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
          gap:1px;background:var(--border)}
.us-card{background:var(--bg-card);padding:16px 18px 14px;text-decoration:none;color:inherit;
         display:flex;flex-direction:column;gap:10px;
         transition:background .15s, transform .12s, box-shadow .15s;
         position:relative;cursor:pointer;border:0;outline:0}
.us-card:hover{background:var(--accent-soft, #f8fafc);transform:translateY(-1px);
               box-shadow:0 4px 16px rgba(0,0,0,.06);z-index:1}
.us-card.us-attention::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;
                              background:var(--accent);border-radius:0 3px 3px 0}

.us-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.us-card-ico{width:38px;height:38px;border-radius:10px;flex-shrink:0;
             background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 70%,#000));
             display:flex;align-items:center;justify-content:center;
             box-shadow:0 4px 12px color-mix(in srgb,var(--accent) 35%,transparent)}
.us-card-pulse{width:10px;height:10px;border-radius:50%;background:#dc2626;flex-shrink:0;
               box-shadow:0 0 0 4px rgba(220,38,38,.18);animation:pulseDot 1.5s ease-in-out infinite}
@keyframes pulseDot{0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.3);opacity:.7}}

.us-card-body{display:flex;flex-direction:column;gap:3px}
.us-card-label{font-size:13px;font-weight:800;color:var(--text-primary);line-height:1.3}
.us-card-sub{font-size:10.5px;color:var(--text-muted);line-height:1.4}
.us-card-num{font-size:30px;font-weight:900;color:var(--accent);line-height:1;margin-top:6px;letter-spacing:-.5px}
.us-card-mini{display:flex;flex-wrap:wrap;gap:4px;margin-top:7px}
.us-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;
         padding:2px 7px;border-radius:5px;line-height:1.4}
.us-chip .dot{width:5px;height:5px;border-radius:50%}
.us-chip-pending{background:#fef9c3;color:#854d0e}
.us-chip-pending .dot{background:#ca8a04}
.us-chip-done{background:#dcfce7;color:#166534}
.us-chip-done .dot{background:#16a34a}
.us-chip-rejected{background:#fee2e2;color:#991b1b}
.us-chip-rejected .dot{background:#dc2626}
.us-chip-empty{background:#f1f5f9;color:#64748b;font-style:italic;font-weight:500}

.us-card-foot{display:flex;align-items:center;justify-content:space-between;
              padding-top:8px;border-top:1px dashed var(--border);
              font-size:11px;font-weight:700;color:var(--accent);
              margin-top:auto}
.us-card:hover .us-card-foot svg{transform:translateX(3px)}
.us-card-foot svg{transition:transform .18s}

@media(max-width:520px){
  .ug-cards{grid-template-columns:1fr}
  .us-card-num{font-size:24px}
}

/* ── Stat sub ────────────────────────────────────────────────── */
.stat-sub{font-size:11px;color:#94a3b8;margin-top:3px;line-height:1.8}
.stat-sub span{display:inline-flex;align-items:center;gap:3px;margin-right:8px}
.stat-sub .s-wait{color:#f59e0b}
.stat-sub .s-done{color:#22c55e}
.stat-sub .s-rej {color:#ef4444}
.stat-sub .s-proc{color:#3b82f6}

/* ── User cards ──────────────────────────────────────────────── */
.user-cards-row{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
  margin-bottom:16px
}
.user-card{
  background:var(--bg-card);
  border-radius:var(--radius-lg);
  padding:18px 20px;
  box-shadow:var(--shadow-sm);
  border:1px solid var(--border);
  cursor:pointer;
  position:relative;
  transition:box-shadow .15s,transform .15s;
}
.user-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.user-card-head{display:flex;align-items:center;gap:14px}
.user-card-count{
  font-size:36px;font-weight:800;line-height:1;
  margin-top:4px;letter-spacing:-.5px
}
.user-card-label{
  font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.07em;margin-top:3px;opacity:.7
}
.user-card-chv{
  margin-left:auto;flex-shrink:0;
  transition:transform .2s;color:#cbd5e1
}
.user-card-preview{
  display:none;
  border-top:1px solid var(--border);
  margin-top:12px;padding-top:10px
}
.user-prev-item{
  display:flex;align-items:center;gap:8px;
  padding:5px 6px;border-radius:7px;
  text-decoration:none;color:var(--text-primary);
  transition:background .1s;margin-bottom:1px
}
.user-prev-item:hover{background:var(--bg-field)}
.user-prev-av{
  width:26px;height:26px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden
}
.user-prev-name{font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-prev-id{font-size:10px;color:var(--text-muted)}
.user-all-link{
  display:block;text-align:center;margin-top:8px;
  font-size:11px;font-weight:600;text-decoration:none;
  padding:5px 8px;border-radius:6px
}

/* ── stat-icon colour additions ─────────────────────────────── */
.stat-icon.teal  {background:#f0fdfa;color:#0d9488}
.stat-icon.red   {background:#fff1f2;color:#e11d48}

/* ── Drawer ──────────────────────────────────────────────────── */
#detail-drawer{width:500px;max-width:100%;animation:slideIn .2s ease}
@keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
.drawer-header{
  display:flex;align-items:center;justify-content:space-between;
  padding:18px 22px;border-bottom:1px solid #e2e8f0;
  position:sticky;top:0;background:#fff;z-index:1
}
.drawer-header h3{font-size:16px;font-weight:700;color:#1e293b;margin:0}
.drawer-close{background:none;border:none;cursor:pointer;color:#64748b;padding:4px;border-radius:6px;display:flex;align-items:center}
.drawer-close:hover{background:#f1f5f9;color:#1e293b}
#drawer-body{padding:20px 22px}

/* ── Detail sections ─────────────────────────────────────────── */
.det-section{margin-bottom:18px}
.det-section-title{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8;margin-bottom:8px}
.det-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.det-grid.full{grid-template-columns:1fr}
.det-item{background:#f8fafc;border-radius:8px;padding:10px 12px}
.det-item .lbl{font-size:11px;color:#94a3b8;margin-bottom:3px}
.det-item .val{font-size:13px;color:#1e293b;font-weight:600;line-height:1.4}
.det-item .val.mono{font-family:monospace;font-size:12px}
.score-row{display:flex;gap:10px;margin-bottom:18px}
.score-box{flex:1;border-radius:10px;padding:12px 14px;text-align:center;border:1.5px solid transparent}
.score-box .s-val{font-size:22px;font-weight:800;line-height:1}
.score-box .s-lbl{font-size:11px;margin-top:4px;opacity:.75}
.score-ok {background:#f0fdf4;border-color:#86efac;color:#16a34a}
.score-bad{background:#fff1f2;border-color:#fca5a5;color:#dc2626}
.det-catatan{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;font-size:13px;color:#92400e;line-height:1.6}
.badge-jenis{display:inline-block;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;margin-right:6px}
.badge-jenis.ec{background:#f0fdfa;color:#0d9488}
.badge-status-menunggu{display:inline-block;background:#fef9c3;color:#a16207;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.badge-status-diproses{display:inline-block;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.badge-status-selesai{display:inline-block;background:#dcfce7;color:#15803d;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.badge-status-ditolak{display:inline-block;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.det-src{display:none}

/* ── Skema badge penelitian ──────────────────────────────────── */
.skema-pill {
  display:inline-block; font-size:10px; font-weight:700;
  padding:2px 8px; border-radius:12px; white-space:nowrap;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media(max-width:1024px){
  .user-cards-row{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  .user-cards-row{grid-template-columns:1fr}
  .user-card-count{font-size:28px}
}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          Dashboard Admin
          <span class="breadcrumb">LPPM IAKN Toraja · <?= date('d F Y') ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- ── Welcome banner ──────────────────────────────────── -->
      <div class="welcome-banner">
        <div>
          <h2><?= $lang==='id'?'Selamat Datang, Admin':'Welcome, Admin' ?></h2>
          <p><?= $lang==='id'?'Kelola permohonan pengguna ke LPPM IAKN Toraja':'Manage user submissions to LPPM IAKN Toraja' ?></p>
        </div>
        <?php if ($total_pending > 0): ?>
        <div class="alert-chip">
          <div class="num"><?= $total_pending ?></div>
          <div class="lbl"><?= $lang==='id'?'Perlu Diproses':'Need Processing' ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ════════════════════════════════════════════════════════
           RINGKASAN USULAN — Card Interaktif (Mahasiswa & Dosen)
      ════════════════════════════════════════════════════════ -->
      <?php
      $usulan_groups = [
        [
          'key'   => 'mhs',
          'title' => $lang==='id' ? 'Usulan Mahasiswa' : 'Student Submissions',
          'icon'  => 'graduation',
          'color_from' => '#1e3a5f',
          'color_to'   => '#2563eb',
          'cards' => [
            [
              'href'    => 'plagiasi.php',
              'icon'    => 'search',
              'label'   => $lang==='id'?'Bebas Plagiasi':'Plagiarism-Free',
              'sublbl'  => $lang==='id'?'Cek Turnitin & surat keterangan':'Turnitin check & certificate',
              'total'   => $pl_total,
              'pending' => $pl_menunggu,
              'done'    => $pl_selesai,
              'rejected'=> $pl_ditolak,
              'accent'  => '#2563eb',
              'bgsoft'  => '#eff6ff',
            ],
            [
              'href'    => 'publikasi.php',
              'icon'    => 'newspaper',
              'label'   => $lang==='id'?'Surat Publikasi':'Publication Letter',
              'sublbl'  => $lang==='id'?'Verifikasi jurnal/prosiding':'Verify journals/proceedings',
              'total'   => $pb_total,
              'pending' => $pb_menunggu,
              'done'    => $pb_selesai,
              'rejected'=> $pb_ditolak,
              'accent'  => '#0d9488',
              'bgsoft'  => '#ccfbf1',
            ],
          ],
        ],
        [
          'key'   => 'dsn',
          'title' => $lang==='id' ? 'Usulan Dosen' : 'Lecturer Submissions',
          'icon'  => 'briefcase',
          'color_from' => '#7c2d12',
          'color_to'   => '#ea580c',
          'cards' => [
            [
              'href'    => 'ethical_clearance.php',
              'icon'    => 'clipboard',
              'label'   => 'Ethical Clearance',
              'sublbl'  => $lang==='id'?'Persetujuan etik penelitian':'Research ethics approval',
              'total'   => $ec_total,
              'pending' => $ec_menunggu + $ec_diproses,
              'done'    => $ec_disetujui,
              'rejected'=> $ec_ditolak,
              'accent'  => '#9a3412',
              'bgsoft'  => '#fff7ed',
            ],
            [
              'href'    => 'penelitian.php',
              'icon'    => 'award',
              'label'   => $lang==='id'?'Usulan Penelitian':'Research Proposals',
              'sublbl'  => $lang==='id'?'Hibah penelitian dosen':'Lecturer research grants',
              'total'   => $pen_total,
              'pending' => $pen_pending,
              'done'    => $pen_disetujui,
              'rejected'=> $pen_ditolak,
              'accent'  => '#1e40af',
              'bgsoft'  => '#eff6ff',
            ],
            [
              'href'    => 'pengabdian.php',
              'icon'    => 'users',
              'label'   => $lang==='id'?'Usulan Pengabdian':'Community Service',
              'sublbl'  => $lang==='id'?'Pengabdian kepada masyarakat':'Community engagement (PkM)',
              'total'   => $pgb_total,
              'pending' => $pgb_pending,
              'done'    => $pgb_disetujui,
              'rejected'=> $pgb_ditolak,
              'accent'  => '#7c3aed',
              'bgsoft'  => '#faf5ff',
            ],
          ],
        ],
      ];
      ?>
      <div class="usulan-groups">
        <?php foreach ($usulan_groups as $g):
          $g_total = array_sum(array_column($g['cards'], 'total'));
          $g_pending = array_sum(array_column($g['cards'], 'pending'));
        ?>
        <section class="usulan-group">
          <div class="ug-head" style="background:linear-gradient(135deg, <?= $g['color_from'] ?>, <?= $g['color_to'] ?>)">
            <div class="ug-head-icon"><?= ic($g['icon'],'style="width:18px;height:18px;color:#fff"') ?></div>
            <div class="ug-head-title">
              <div class="ug-head-name"><?= htmlspecialchars($g['title']) ?></div>
              <div class="ug-head-meta"><?= $g_total ?> <?= $lang==='id'?'total':'total' ?> · <?= $g_pending ?> <?= $lang==='id'?'menunggu':'pending' ?></div>
            </div>
            <?php if ($g_pending > 0): ?>
            <div class="ug-head-badge"><?= $g_pending ?></div>
            <?php endif; ?>
          </div>
          <div class="ug-cards">
            <?php foreach ($g['cards'] as $c):
              $needs_attention = $c['pending'] > 0;
            ?>
            <a href="<?= $c['href'] ?>" class="us-card <?= $needs_attention?'us-attention':'' ?>"
               style="--accent:<?= $c['accent'] ?>;--accent-soft:<?= $c['bgsoft'] ?>">
              <div class="us-card-top">
                <div class="us-card-ico"><?= ic($c['icon'],'style="width:20px;height:20px;color:#fff"') ?></div>
                <?php if ($needs_attention): ?>
                <span class="us-card-pulse"></span>
                <?php endif; ?>
              </div>
              <div class="us-card-body">
                <div class="us-card-label"><?= htmlspecialchars($c['label']) ?></div>
                <div class="us-card-sub"><?= htmlspecialchars($c['sublbl']) ?></div>
                <div class="us-card-num"><?= $c['total'] ?></div>
                <div class="us-card-mini">
                  <?php if ($c['pending']): ?>
                  <span class="us-chip us-chip-pending"><span class="dot"></span><?= $c['pending'] ?> <?= $lang==='id'?'pending':'pending' ?></span>
                  <?php endif; ?>
                  <?php if ($c['done']): ?>
                  <span class="us-chip us-chip-done"><span class="dot"></span><?= $c['done'] ?> <?= $lang==='id'?'selesai':'done' ?></span>
                  <?php endif; ?>
                  <?php if ($c['rejected']): ?>
                  <span class="us-chip us-chip-rejected"><span class="dot"></span><?= $c['rejected'] ?> <?= $lang==='id'?'ditolak':'rejected' ?></span>
                  <?php endif; ?>
                  <?php if (!$c['pending'] && !$c['done'] && !$c['rejected']): ?>
                  <span class="us-chip us-chip-empty"><?= $lang==='id'?'belum ada usulan':'no submissions' ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="us-card-foot">
                <span><?= $lang==='id'?'Kelola':'Manage' ?></span>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                </svg>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>

      <!-- ── User cards ─────────────────────────────────────── -->
      <div class="user-cards-row">

        <!-- Mahasiswa -->
        <div class="user-card" id="card-mhs" onclick="toggleUserPreview('mhs')">
          <div class="user-card-head">
            <div class="stat-icon navy"><?= ic('users') ?></div>
            <div style="flex:1;min-width:0">
              <div class="user-card-label" style="color:#2563eb"><?= $lang==='id'?'Mahasiswa':'Students' ?></div>
              <div class="user-card-count" style="color:#1e3a5f"><?= $total_mhs ?></div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                <?= $lang==='id'?'akun terdaftar':'registered accounts' ?>
              </div>
            </div>
            <svg class="user-card-chv" id="chv-mhs" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 9l6 6 6-6"/>
            </svg>
          </div>
          <div class="user-card-preview" id="prev-mhs" onclick="event.stopPropagation()">
            <?php if (empty($preview_mhs)): ?>
              <p style="font-size:12px;color:#94a3b8;margin:4px 0 8px"><?= $lang==='id'?'Belum ada mahasiswa terdaftar.':'No students registered yet.' ?></p>
            <?php else: ?>
              <?php foreach ($preview_mhs as $m): ?>
              <a href="pengguna.php?action=lihat&id=<?= $m['id'] ?>&tab=mahasiswa" class="user-prev-item">
                <div class="user-prev-av" style="background:#1e3a5f">
                  <?php if (!empty($m['foto_profil']) && file_exists(BASE_PATH.'/'.$m['foto_profil'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($m['foto_profil']) ?>" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <?= strtoupper(mb_substr($m['nama_lengkap'],0,1)) ?>
                  <?php endif; ?>
                </div>
                <div style="min-width:0">
                  <div class="user-prev-name"><?= htmlspecialchars(mb_strimwidth($m['nama_lengkap'],0,28,'…')) ?></div>
                  <div class="user-prev-id"><?= htmlspecialchars($m['nim']??'-') ?></div>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <a href="pengguna.php?tab=mahasiswa" class="user-all-link"
               style="color:#2563eb;background:#eff6ff">
              <?= $lang==='id'?'Lihat semua mahasiswa →':'View all students →' ?>
            </a>
          </div>
        </div>

        <!-- Dosen -->
        <div class="user-card" id="card-dsn" onclick="toggleUserPreview('dsn')">
          <div class="user-card-head">
            <div class="stat-icon green"><?= ic('graduation') ?></div>
            <div style="flex:1;min-width:0">
              <div class="user-card-label" style="color:#16a34a"><?= $lang==='id'?'Dosen':'Lecturers' ?></div>
              <div class="user-card-count" style="color:#14532d"><?= $total_dsn ?></div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                <?= $lang==='id'?'akun terdaftar':'registered accounts' ?>
              </div>
            </div>
            <svg class="user-card-chv" id="chv-dsn" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 9l6 6 6-6"/>
            </svg>
          </div>
          <div class="user-card-preview" id="prev-dsn" onclick="event.stopPropagation()">
            <?php if (empty($preview_dsn)): ?>
              <p style="font-size:12px;color:#94a3b8;margin:4px 0 8px"><?= $lang==='id'?'Belum ada dosen terdaftar.':'No lecturers registered yet.' ?></p>
            <?php else: ?>
              <?php foreach ($preview_dsn as $d): ?>
              <a href="pengguna.php?action=lihat&id=<?= $d['id'] ?>&tab=dosen" class="user-prev-item">
                <div class="user-prev-av" style="background:#15803d">
                  <?php if (!empty($d['foto_profil']) && file_exists(BASE_PATH.'/'.$d['foto_profil'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($d['foto_profil']) ?>" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <?= strtoupper(mb_substr($d['nama_lengkap'],0,1)) ?>
                  <?php endif; ?>
                </div>
                <div style="min-width:0">
                  <div class="user-prev-name"><?= htmlspecialchars(mb_strimwidth($d['nama_lengkap'],0,28,'…')) ?></div>
                  <div class="user-prev-id">NIDN <?= htmlspecialchars($d['nidn']??'-') ?></div>
                </div>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <a href="pengguna.php?tab=dosen" class="user-all-link"
               style="color:#16a34a;background:#f0fdf4">
              <?= $lang==='id'?'Lihat semua dosen →':'View all lecturers →' ?>
            </a>
          </div>
        </div>

      </div><!-- /user-cards-row -->

      <!-- ══ ANTRIAN PLAGIASI ═══════════════════════════════════ -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-header">
          <span class="card-title">
            <?= ic('search') ?>
            <?= $lang==='id'?'Antrian Cek Plagiasi':'Plagiarism Check Queue' ?>
            <?php if($pl_menunggu > 0): ?>
              <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:600;
                           padding:2px 8px;border-radius:12px;margin-left:4px"><?= $pl_menunggu ?></span>
            <?php endif; ?>
          </span>
          <a href="plagiasi.php" class="btn btn-outline btn-sm"><?= $lang==='id'?'Lihat Semua':'View All' ?> →</a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($recent_pl)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8">
              <?= ic('check-circle','style="width:28px;height:28px;margin:0 auto 8px;display:block;color:#86efac"') ?>
              <?= $lang==='id'?'Tidak ada antrian saat ini.':'Queue is clear.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Pemohon':'Applicant' ?></th>
                  <th>NIM</th>
                  <th><?= $lang==='id'?'Judul':'Title' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent_pl as $r): ?>
                <?php $jta_label = match($r['jenis_tugas_akhir']??'skripsi'){ 'tesis'=>'Tesis','disertasi'=>'Disertasi',default=>'Skripsi' }; ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['program_studi']??'') ?></div>
                  </td>
                  <td style="font-size:12px;color:#64748b;white-space:nowrap"><?= htmlspecialchars($r['nim']) ?></td>
                  <td style="max-width:200px;font-size:12px">
                    <span class="badge-jenis" style="font-size:10px"><?= $jta_label ?></span>
                    <?= htmlspecialchars(mb_strimwidth($r['judul_skripsi'],0,50,'…')) ?>
                  </td>
                  <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= formatTanggal($r['created_at']) ?></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" onclick="openDetail('adpl-<?= $r['id'] ?>')" style="margin-right:4px">
                      <?= ic('eye') ?>
                    </button>
                    <a href="plagiasi.php?proses=<?= $r['id'] ?>" class="btn btn-primary btn-sm">
                      <?= ic('play') ?> <?= $lang==='id'?'Proses':'Process' ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ ANTRIAN PUBLIKASI ══════════════════════════════════ -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-header">
          <span class="card-title">
            <?= ic('newspaper') ?>
            <?= $lang==='id'?'Antrian Verifikasi Publikasi':'Publication Verification Queue' ?>
            <?php if($pb_menunggu > 0): ?>
              <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:600;
                           padding:2px 8px;border-radius:12px;margin-left:4px"><?= $pb_menunggu ?></span>
            <?php endif; ?>
          </span>
          <a href="publikasi.php" class="btn btn-outline btn-sm"><?= $lang==='id'?'Lihat Semua':'View All' ?> →</a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($recent_pub)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8">
              <?= ic('check-circle','style="width:28px;height:28px;margin:0 auto 8px;display:block;color:#86efac"') ?>
              <?= $lang==='id'?'Tidak ada antrian saat ini.':'Queue is clear.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Pemohon':'Applicant' ?></th>
                  <th><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></th>
                  <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent_pub as $r): ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['program_studi']??'') ?></div>
                  </td>
                  <td style="max-width:200px;font-size:12px"><?= htmlspecialchars(mb_strimwidth($r['judul_publikasi'],0,50,'…')) ?></td>
                  <td>
                    <span class="badge badge-process" style="font-size:11px"><?= labelJenisPublikasi($r['jenis_publikasi']) ?></span>
                  </td>
                  <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= formatTanggal($r['created_at']) ?></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" onclick="openDetail('adpb-<?= $r['id'] ?>')" style="margin-right:4px">
                      <?= ic('eye') ?>
                    </button>
                    <a href="publikasi.php?proses=<?= $r['id'] ?>" class="btn btn-gold btn-sm">
                      <?= ic('play') ?> <?= $lang==='id'?'Proses':'Process' ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ ANTRIAN ETHICAL CLEARANCE ═════════════════════════ -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">
            <?= ic('clipboard') ?>
            <?= $lang==='id'?'Antrian Ethical Clearance':'Ethical Clearance Queue' ?>
            <?php if(($ec_menunggu + $ec_diproses) > 0): ?>
              <span style="background:#ccfbf1;color:#0f766e;font-size:11px;font-weight:600;
                           padding:2px 8px;border-radius:12px;margin-left:4px"><?= $ec_menunggu + $ec_diproses ?></span>
            <?php endif; ?>
          </span>
          <a href="ethical_clearance.php" class="btn btn-outline btn-sm"><?= $lang==='id'?'Lihat Semua':'View All' ?> →</a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($recent_ec)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8">
              <?= ic('check-circle','style="width:28px;height:28px;margin:0 auto 8px;display:block;color:#86efac"') ?>
              <?= $lang==='id'?'Tidak ada antrian saat ini.':'Queue is clear.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Pemohon':'Applicant' ?></th>
                  <th><?= $lang==='id'?'Judul Penelitian':'Research Title' ?></th>
                  <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent_ec as $r):
                  $jenis_label_map = [
                    'eksperimental'=>'Eksperimental','non_eksperimental'=>'Non-Eksperimental',
                    'studi_kasus'=>'Studi Kasus','survei'=>'Survei','tinjauan_literatur'=>'Tinjauan Literatur',
                    'lainnya'=>'Lainnya',
                  ];
                  $jlabel = $jenis_label_map[$r['jenis_penelitian']??''] ?? ($r['jenis_penelitian']??'-');
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($r['program_studi']??'') ?></div>
                  </td>
                  <td style="max-width:200px;font-size:12px"><?= htmlspecialchars(mb_strimwidth($r['judul_penelitian'],0,50,'…')) ?></td>
                  <td>
                    <span class="badge-jenis ec" style="font-size:10px"><?= htmlspecialchars($jlabel) ?></span>
                  </td>
                  <td>
                    <?php if($r['status']==='menunggu'): ?>
                      <span class="badge-status-menunggu"><?= $lang==='id'?'Menunggu':'Pending' ?></span>
                    <?php elseif($r['status']==='diproses'): ?>
                      <span class="badge-status-diproses"><?= $lang==='id'?'Diproses':'Processing' ?></span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= formatTanggal($r['created_at']) ?></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-outline btn-sm" onclick="openDetail('adec-<?= $r['id'] ?>')" style="margin-right:4px">
                      <?= ic('eye') ?>
                    </button>
                    <a href="ethical_clearance.php" class="btn btn-sm"
                       style="background:#f0fdfa;color:#0d9488;border:1px solid #99f6e4;font-weight:600">
                      <?= ic('play') ?> <?= $lang==='id'?'Proses':'Process' ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ ANTRIAN USULAN PENELITIAN ════════════════════════ -->
      <div class="card" style="margin-top:20px">
        <div class="card-header">
          <span class="card-title">
            <?= ic('edit') ?>
            <?= $lang==='id'?'Antrian Usulan Penelitian':'Research Proposal Queue' ?>
            <?php if($pen_pending > 0): ?>
              <span style="background:#ede9fe;color:#6d28d9;font-size:11px;font-weight:600;
                           padding:2px 8px;border-radius:12px;margin-left:4px"><?= $pen_pending ?></span>
            <?php endif; ?>
          </span>
          <a href="penelitian.php" class="btn btn-outline btn-sm"><?= $lang==='id'?'Lihat Semua':'View All' ?> →</a>
        </div>

        <?php if($pen_draft > 0 || $pen_disetujui > 0): ?>
        <!-- Ringkasan status semua proposal -->
        <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-field)">
          <?php
          $status_summary = [
            'draft'     => [$pen_draft,     '#f1f5f9','#64748b', $lang==='id'?'Draft':'Draft'],
            'diajukan'  => [$pen_diajukan,  '#fef9c3','#92400e', $lang==='id'?'Diajukan':'Submitted'],
            'ditinjau'  => [$pen_ditinjau,  '#dbeafe','#1d4ed8', $lang==='id'?'Ditinjau':'In Review'],
            'disetujui' => [$pen_disetujui, '#dcfce7','#166534', $lang==='id'?'Disetujui':'Approved'],
            'direvisi'  => [$pen_direvisi,  '#ffedd5','#9a3412', $lang==='id'?'Revisi':'Revision'],
            'ditolak'   => [$pen_ditolak,   '#fee2e2','#991b1b', $lang==='id'?'Ditolak':'Rejected'],
          ];
          foreach ($status_summary as [$count, $bg, $col, $lbl]): if (!$count) continue; ?>
          <span class="skema-pill" style="background:<?= $bg ?>;color:<?= $col ?>">
            <?= $count ?> <?= $lbl ?>
          </span>
          <?php endforeach; ?>
          <?php if (!$pen_total): ?>
          <span style="font-size:12px;color:#94a3b8"><?= $lang==='id'?'Belum ada usulan.':'No proposals yet.' ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card-body" style="padding:0">
          <?php if (empty($recent_pen)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8">
              <?= ic('check-circle','style="width:28px;height:28px;margin:0 auto 8px;display:block;color:#86efac"') ?>
              <?= $lang==='id'?'Tidak ada proposal yang perlu ditinjau saat ini.':'No proposals need review right now.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Pengusul':'Proposer' ?></th>
                  <th><?= $lang==='id'?'Judul Penelitian':'Research Title' ?></th>
                  <th><?= $lang==='id'?'Skema':'Scheme' ?></th>
                  <th><?= $lang==='id'?'Tahun':'Year' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Diajukan':'Submitted' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent_pen as $r):
                  $pen_badge_map = [
                    'diajukan' => ['bg'=>'#fef9c3','col'=>'#92400e','label'=>$lang==='id'?'Diajukan':'Submitted'],
                    'ditinjau' => ['bg'=>'#dbeafe','col'=>'#1d4ed8','label'=>$lang==='id'?'Ditinjau':'In Review'],
                  ];
                  $pb = $pen_badge_map[$r['status']] ?? ['bg'=>'#f1f5f9','col'=>'#64748b','label'=>$r['status']];
                  $skema_display = $r['skema_nama'] ?: strtoupper($r['skema']);
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:#94a3b8">
                      NIDN <?= htmlspecialchars($r['nidn']??'-') ?>
                      <?php if($r['jabatan_fungsional']): ?>
                       · <?= htmlspecialchars($r['jabatan_fungsional']) ?>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td style="max-width:220px">
                    <div style="font-size:12.5px;font-weight:600;line-height:1.4;white-space:normal">
                      <?= htmlspecialchars(mb_strimwidth($r['judul']??'(tanpa judul)',0,60,'…')) ?>
                    </div>
                    <?php if(!empty($r['abstrak'])): ?>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                      <?= htmlspecialchars(mb_strimwidth($r['abstrak'],0,80,'…')) ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="skema-pill" style="background:#ede9fe;color:#6d28d9;font-size:11px">
                      <?= htmlspecialchars($skema_display) ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:#64748b;white-space:nowrap"><?= $r['tahun_anggaran'] ?></td>
                  <td>
                    <span class="skema-pill" style="background:<?= $pb['bg'] ?>;color:<?= $pb['col'] ?>">
                      <?= $pb['label'] ?>
                    </span>
                  </td>
                  <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= formatTanggal($r['created_at']) ?></td>
                  <td style="white-space:nowrap">
                    <a href="penelitian.php?id=<?= $r['id'] ?>" class="btn btn-lg"
                       style="padding:5px 12px;font-size:12px;background:#faf5ff;color:#7c3aed;border:1px solid #ddd6fe;font-weight:600">
                      <?= ic('play','style="width:13px;height:13px"') ?>
                      <?= $lang==='id'?'Tinjau':'Review' ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ ANTRIAN USULAN PENGABDIAN ════════════════════════ -->
      <div class="card" style="margin-top:20px">
        <div class="card-header">
          <span class="card-title">
            <?= ic('users') ?>
            <?= $lang==='id'?'Antrian Usulan Pengabdian':'Community Service Queue' ?>
            <?php if($pgb_pending > 0): ?>
              <span style="background:#faf5ff;color:#7c3aed;font-size:11px;font-weight:600;
                           padding:2px 8px;border-radius:12px;margin-left:4px"><?= $pgb_pending ?></span>
            <?php endif; ?>
          </span>
          <a href="pengabdian.php" class="btn btn-outline btn-sm"><?= $lang==='id'?'Lihat Semua':'View All' ?> →</a>
        </div>

        <?php if($pgb_draft > 0 || $pgb_disetujui > 0 || $pgb_total > 0): ?>
        <!-- Ringkasan status semua proposal pengabdian -->
        <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-field)">
          <?php
          $pgb_status_summary = [
            'draft'      => [$pgb_draft,     '#f1f5f9','#64748b', $lang==='id'?'Draft':'Draft'],
            'diajukan'   => [$pgb_diajukan,  '#fef9c3','#92400e', $lang==='id'?'Diajukan':'Submitted'],
            'ditinjau'   => [$pgb_ditinjau,  '#dbeafe','#1d4ed8', $lang==='id'?'Ditinjau':'In Review'],
            'disetujui'  => [$pgb_disetujui, '#dcfce7','#166534', $lang==='id'?'Disetujui':'Approved'],
            'direvisi'   => [$pgb_direvisi,  '#ffedd5','#9a3412', $lang==='id'?'Revisi':'Revision'],
            'ditolak'    => [$pgb_ditolak,   '#fee2e2','#991b1b', $lang==='id'?'Ditolak':'Rejected'],
          ];
          $any_shown = false;
          foreach ($pgb_status_summary as [$count, $bg, $col, $lbl]): if (!$count) continue; $any_shown = true; ?>
          <span class="skema-pill" style="background:<?= $bg ?>;color:<?= $col ?>">
            <?= $count ?> <?= $lbl ?>
          </span>
          <?php endforeach; ?>
          <?php if (!$any_shown): ?>
          <span style="font-size:12px;color:#94a3b8"><?= $lang==='id'?'Belum ada usulan pengabdian.':'No PkM submissions yet.' ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card-body" style="padding:0">
          <?php if (empty($recent_pgb)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8">
              <?= ic('check-circle','style="width:28px;height:28px;margin:0 auto 8px;display:block;color:#86efac"') ?>
              <?= $lang==='id'?'Tidak ada proposal pengabdian yang perlu ditinjau saat ini.':'No PkM proposals need review right now.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Pengusul':'Proposer' ?></th>
                  <th><?= $lang==='id'?'Judul Pengabdian':'PkM Title' ?></th>
                  <th><?= $lang==='id'?'Skema':'Scheme' ?></th>
                  <th><?= $lang==='id'?'Tahun':'Year' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Diajukan':'Submitted' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($recent_pgb as $r):
                  $pgb_badge_map = [
                    'diajukan'             => ['bg'=>'#fef9c3','col'=>'#92400e','label'=>$lang==='id'?'Diajukan':'Submitted',           'href'=>'pengabdian_seleksi.php'],
                    'seleksi_admin'        => ['bg'=>'#fed7aa','col'=>'#9a3412','label'=>$lang==='id'?'Seleksi Admin':'Admin Review',   'href'=>'pengabdian_seleksi.php'],
                    'seleksi_substansi'    => ['bg'=>'#bfdbfe','col'=>'#1e40af','label'=>$lang==='id'?'Seleksi Substantif':'Sub. Review','href'=>'pengabdian_reviewer.php'],
                    'perbaikan_substantif' => ['bg'=>'#fbcfe8','col'=>'#9d174d','label'=>$lang==='id'?'Perbaikan':'Revision',           'href'=>'pengabdian_reviewer.php'],
                    'ditinjau'             => ['bg'=>'#dbeafe','col'=>'#1d4ed8','label'=>$lang==='id'?'Ditinjau':'In Review',           'href'=>'pengabdian_seleksi.php'],
                  ];
                  $pb = $pgb_badge_map[$r['status']] ?? ['bg'=>'#f1f5f9','col'=>'#64748b','label'=>$r['status'],'href'=>'pengabdian.php'];
                  $skema_display = $r['skema_nama'] ?: strtoupper($r['skema']);
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:#94a3b8">
                      NIDN <?= htmlspecialchars($r['nidn']??'-') ?>
                      <?php if($r['jabatan_fungsional']): ?>
                       · <?= htmlspecialchars($r['jabatan_fungsional']) ?>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td style="max-width:220px">
                    <div style="font-size:12.5px;font-weight:600;line-height:1.4;white-space:normal">
                      <?= htmlspecialchars(mb_strimwidth($r['judul']??'(tanpa judul)',0,60,'…')) ?>
                    </div>
                    <?php if(!empty($r['abstrak'])): ?>
                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                      <?= htmlspecialchars(mb_strimwidth($r['abstrak'],0,80,'…')) ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="skema-pill" style="background:#faf5ff;color:#7c3aed;font-size:11px">
                      <?= htmlspecialchars($skema_display) ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:#64748b;white-space:nowrap"><?= $r['tahun_anggaran'] ?></td>
                  <td>
                    <span class="skema-pill" style="background:<?= $pb['bg'] ?>;color:<?= $pb['col'] ?>">
                      <?= $pb['label'] ?>
                    </span>
                  </td>
                  <td style="white-space:nowrap;font-size:11px;color:#64748b"><?= formatTanggal($r['created_at']) ?></td>
                  <td style="white-space:nowrap">
                    <a href="<?= $pb['href'] ?>?id=<?= $r['id'] ?>" class="btn btn-lg"
                       style="padding:5px 12px;font-size:12px;background:#faf5ff;color:#7c3aed;border:1px solid #ddd6fe;font-weight:600">
                      <?= ic('play','style="width:13px;height:13px"') ?>
                      <?= $lang==='id'?'Tinjau':'Review' ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->

<!-- ══ SLIDE-IN DRAWER ══════════════════════════════════════════ -->
<div id="detail-overlay" onclick="overlayClick(event)">
  <div id="detail-drawer">
    <div class="drawer-header">
      <h3 id="drawer-title">Detail Pengajuan</h3>
      <button class="drawer-close" onclick="closeDetail()">
        <?= ic('x','style="width:20px;height:20px"') ?>
      </button>
    </div>
    <div id="drawer-body"></div>
  </div>
</div>

<!-- ══ DETAIL SOURCES — PLAGIASI ════════════════════════════════ -->
<?php
$batas_sim = (int)(getSetting($pdo,'batas_similarity') ?: 20);
$batas_ai  = (int)(getSetting($pdo,'batas_ai') ?: 30);
foreach($recent_pl as $r):
  $jta_label = match($r['jenis_tugas_akhir']??'skripsi'){ 'tesis'=>'Tesis','disertasi'=>'Disertasi',default=>'Skripsi' };
  $pem2 = !empty($r['nama_pembimbing2']) ? htmlspecialchars($r['nama_pembimbing2']) : '<em style="color:#94a3b8">—</em>';
?>
<div id="adpl-<?= $r['id'] ?>" class="det-src">
  <div style="margin-bottom:14px">
    <span class="badge-jenis"><?= $jta_label ?></span>
    <span class="badge-status-menunggu"><?= ic('clock','style="width:10px;height:10px"') ?> Menunggu</span>
  </div>
  <div class="det-section">
    <div class="det-section-title">Judul <?= $jta_label ?></div>
    <div style="font-size:14px;font-weight:600;color:#1e293b;line-height:1.5"><?= htmlspecialchars($r['judul_skripsi']) ?></div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Identitas Pemohon</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Nama Lengkap</div><div class="val"><?= htmlspecialchars($r['nama_lengkap']) ?></div></div>
      <div class="det-item"><div class="lbl">NIM</div><div class="val mono"><?= htmlspecialchars($r['nim']) ?></div></div>
      <div class="det-item"><div class="lbl">Fakultas</div><div class="val"><?= htmlspecialchars($r['fakultas']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Program Studi</div><div class="val"><?= htmlspecialchars($r['program_studi']??'-') ?></div></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <a href="pengguna.php?action=edit&id=<?= $r['user_id'] ?>&tab=mahasiswa" class="btn btn-outline btn-sm" style="flex:1;justify-content:center"><?= ic('edit') ?> Edit Profil</a>
      <a href="pengguna.php?action=reset_pw&id=<?= $r['user_id'] ?>&tab=mahasiswa" class="btn btn-sm" style="flex:1;justify-content:center;background:#fef3c7;color:#92400e;border:1px solid #fde68a"><?= ic('lock') ?> Reset Password</a>
    </div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Info Akademik</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Pembimbing 1</div><div class="val"><?= htmlspecialchars($r['nama_pembimbing1']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Pembimbing 2</div><div class="val"><?= $pem2 ?></div></div>
      <div class="det-item"><div class="lbl">Tahun Sidang</div><div class="val"><?= htmlspecialchars($r['tahun_sidang']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Tanggal Pengajuan</div><div class="val"><?= formatTanggal($r['created_at']) ?></div></div>
    </div>
  </div>
  <?php if(!empty($r['cp_id'])): ?>
  <div class="det-section">
    <div class="det-section-title">Hasil Pemeriksaan</div>
    <div class="score-row">
      <?php $sim_ok = ($r['similarity_score'] !== null && $r['similarity_score'] <= $batas_sim); ?>
      <div class="score-box <?= $sim_ok?'score-ok':'score-bad' ?>">
        <div class="s-val"><?= $r['similarity_score'] ?? '—' ?><?= $r['similarity_score']!==null?'%':'' ?></div>
        <div class="s-lbl">Similarity</div>
      </div>
      <?php if($r['ai_score'] !== null && $r['ai_score'] !== ''): ?>
      <div class="score-box <?= $r['ai_score'] <= $batas_ai?'score-ok':'score-bad' ?>">
        <div class="s-val"><?= $r['ai_score'] ?>%</div>
        <div class="s-lbl">Deteksi AI</div>
      </div>
      <?php endif; ?>
    </div>
    <?php if(!empty($r['catatan'])): ?>
    <div class="det-catatan"><?= ic('info','style="width:13px;height:13px"') ?> <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($r['catatan'])) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div style="margin-top:16px">
    <a href="plagiasi.php?proses=<?= $r['id'] ?>" class="btn btn-primary" style="width:100%;justify-content:center"><?= ic('play') ?> Proses Pengajuan Ini</a>
  </div>
</div>
<?php endforeach; ?>

<!-- ══ DETAIL SOURCES — PUBLIKASI ═══════════════════════════════ -->
<?php foreach($recent_pub as $r): ?>
<div id="adpb-<?= $r['id'] ?>" class="det-src">
  <div style="margin-bottom:14px">
    <span class="badge-jenis"><?= labelJenisPublikasi($r['jenis_publikasi']) ?></span>
    <span class="badge-status-menunggu"><?= ic('clock','style="width:10px;height:10px"') ?> Menunggu</span>
  </div>
  <div class="det-section">
    <div class="det-section-title">Judul Publikasi</div>
    <div style="font-size:14px;font-weight:600;color:#1e293b;line-height:1.5"><?= htmlspecialchars($r['judul_publikasi']) ?></div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Identitas Pemohon</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Nama Lengkap</div><div class="val"><?= htmlspecialchars($r['nama_lengkap']) ?></div></div>
      <div class="det-item"><div class="lbl">NIM / NIDN</div><div class="val mono"><?= htmlspecialchars($r['nim']??$r['nidn']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Fakultas</div><div class="val"><?= htmlspecialchars($r['fakultas']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Program Studi</div><div class="val"><?= htmlspecialchars($r['program_studi']??'-') ?></div></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <a href="pengguna.php?action=edit&id=<?= $r['user_id'] ?>" class="btn btn-outline btn-sm" style="flex:1;justify-content:center"><?= ic('edit') ?> Edit Profil</a>
      <a href="pengguna.php?action=reset_pw&id=<?= $r['user_id'] ?>" class="btn btn-sm" style="flex:1;justify-content:center;background:#fef3c7;color:#92400e;border:1px solid #fde68a"><?= ic('lock') ?> Reset Password</a>
    </div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Detail Publikasi</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Jurnal / Penerbit</div><div class="val"><?= htmlspecialchars($r['nama_jurnal_penerbit']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Tahun Terbit</div><div class="val"><?= htmlspecialchars($r['tahun_terbit']??'-') ?></div></div>
      <?php if(!empty($r['issn_isbn'])): ?>
      <div class="det-item"><div class="lbl">ISSN / ISBN</div><div class="val mono"><?= htmlspecialchars($r['issn_isbn']) ?></div></div>
      <?php endif; ?>
      <?php if(!empty($r['akreditasi_jurnal'])): ?>
      <div class="det-item">
        <div class="lbl">Akreditasi</div>
        <div class="val"><?= match($r['akreditasi_jurnal']){'sinta1'=>'Sinta 1','sinta2'=>'Sinta 2','sinta3'=>'Sinta 3','sinta4'=>'Sinta 4','sinta5'=>'Sinta 5','sinta6'=>'Sinta 6','scopusQ1'=>'Scopus Q1','scopusQ2'=>'Scopus Q2','scopusQ3'=>'Scopus Q3',default=>htmlspecialchars($r['akreditasi_jurnal'])} ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php if(!empty($r['url_doi'])): ?>
    <div class="det-item" style="margin-top:8px"><div class="lbl">DOI / URL</div><div class="val mono" style="word-break:break-all;font-size:12px"><?= htmlspecialchars($r['url_doi']) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="det-section">
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Tanggal Pengajuan</div><div class="val"><?= formatTanggal($r['created_at']) ?></div></div>
    </div>
  </div>
  <div style="margin-top:16px">
    <a href="publikasi.php?proses=<?= $r['id'] ?>" class="btn btn-gold" style="width:100%;justify-content:center"><?= ic('play') ?> Proses Pengajuan Ini</a>
  </div>
</div>
<?php endforeach; ?>

<!-- ══ DETAIL SOURCES — ETHICAL CLEARANCE ═══════════════════════ -->
<?php foreach($recent_ec as $r):
  $jenis_label_map = [
    'eksperimental'=>'Eksperimental','non_eksperimental'=>'Non-Eksperimental',
    'studi_kasus'=>'Studi Kasus','survei'=>'Survei / Kuesioner',
    'tinjauan_literatur'=>'Tinjauan Literatur','lainnya'=>'Lainnya',
  ];
  $jlabel = $jenis_label_map[$r['jenis_penelitian']??''] ?? ($r['jenis_penelitian']??'-');
?>
<div id="adec-<?= $r['id'] ?>" class="det-src">
  <div style="margin-bottom:14px">
    <span class="badge-jenis ec"><?= htmlspecialchars($jlabel) ?></span>
    <?php if($r['status']==='menunggu'): ?>
      <span class="badge-status-menunggu"><?= ic('clock','style="width:10px;height:10px"') ?> Menunggu</span>
    <?php else: ?>
      <span class="badge-status-diproses"><?= ic('refresh','style="width:10px;height:10px"') ?> Diproses</span>
    <?php endif; ?>
  </div>
  <div class="det-section">
    <div class="det-section-title">Judul Penelitian</div>
    <div style="font-size:14px;font-weight:600;color:#1e293b;line-height:1.5"><?= htmlspecialchars($r['judul_penelitian']) ?></div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Identitas Pemohon</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Nama Lengkap</div><div class="val"><?= htmlspecialchars($r['nama_lengkap']) ?></div></div>
      <div class="det-item"><div class="lbl">NIM / NIDN</div><div class="val mono"><?= htmlspecialchars($r['nim']??$r['nidn']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Fakultas</div><div class="val"><?= htmlspecialchars($r['fakultas']??'-') ?></div></div>
      <div class="det-item"><div class="lbl">Program Studi</div><div class="val"><?= htmlspecialchars($r['program_studi']??'-') ?></div></div>
    </div>
  </div>
  <div class="det-section">
    <div class="det-section-title">Detail Penelitian</div>
    <div class="det-grid">
      <div class="det-item"><div class="lbl">Ketua Peneliti</div><div class="val"><?= htmlspecialchars($r['ketua_peneliti']??'-') ?></div></div>
      <?php if(!empty($r['nama_jurnal'])): ?>
      <div class="det-item"><div class="lbl">Nama Jurnal</div><div class="val"><?= htmlspecialchars($r['nama_jurnal']) ?></div></div>
      <?php endif; ?>
      <div class="det-item">
        <div class="lbl">Melibatkan Subjek</div>
        <div class="val" style="font-size:12px">
          <?php $subj = []; if($r['melibatkan_manusia']) $subj[]='Manusia'; if($r['melibatkan_hewan']) $subj[]='Hewan'; echo $subj ? implode(', ',$subj) : '—'; ?>
        </div>
      </div>
      <div class="det-item"><div class="lbl">Tanggal Pengajuan</div><div class="val"><?= formatTanggal($r['created_at']) ?></div></div>
    </div>
    <?php if(!empty($r['deskripsi'])): ?>
    <div class="det-catatan" style="margin-top:8px;background:#f0fdfa;border-color:#99f6e4;color:#0d9488">
      <?= ic('info','style="width:13px;height:13px"') ?> <?= nl2br(htmlspecialchars(mb_strimwidth($r['deskripsi'],0,200,'…'))) ?>
    </div>
    <?php endif; ?>
  </div>
  <div style="margin-top:16px">
    <a href="ethical_clearance.php" class="btn btn-lg"
       style="width:100%;justify-content:center;background:#f0fdfa;color:#0d9488;border:1.5px solid #99f6e4;font-weight:600">
      <?= ic('play') ?> <?= $lang==='id'?'Proses di Halaman EC':'Process in EC Page' ?>
    </a>
  </div>
</div>
<?php endforeach; ?>

<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}

// ── User card preview ─────────────────────────────────────────
function toggleUserPreview(key) {
  const prev = document.getElementById('prev-' + key);
  const chv  = document.getElementById('chv-' + key);
  if (!prev) return;
  const isOpen = prev.style.display !== 'none';
  // Close the other one
  ['mhs','dsn'].forEach(k => {
    if (k !== key) {
      const p = document.getElementById('prev-' + k);
      const c = document.getElementById('chv-' + k);
      if (p) p.style.display = 'none';
      if (c) c.style.transform = '';
    }
  });
  prev.style.display = isOpen ? 'none' : 'block';
  chv.style.transform = isOpen ? '' : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
  ['mhs','dsn'].forEach(key => {
    const card = document.getElementById('card-' + key);
    if (card && !card.contains(e.target)) {
      const p = document.getElementById('prev-' + key);
      const c = document.getElementById('chv-' + key);
      if (p) p.style.display = 'none';
      if (c) c.style.transform = '';
    }
  });
});

// ── Detail drawer ─────────────────────────────────────────────
function openDetail(id) {
  const src = document.getElementById(id);
  if (!src) return;
  const type = id.startsWith('adpl-') ? 'Detail Plagiasi'
             : id.startsWith('adpb-') ? 'Detail Publikasi'
             : 'Detail Ethical Clearance';
  document.getElementById('drawer-title').textContent = type;
  document.getElementById('drawer-body').innerHTML = src.innerHTML;
  document.getElementById('detail-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDetail() {
  document.getElementById('detail-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
function overlayClick(e) {
  if (e.target === document.getElementById('detail-overlay')) closeDetail();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetail(); });
</script>
</body>
</html>
