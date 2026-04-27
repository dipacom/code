<?php
require_once '../includes/config.php';
requireLogin('admin');
$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';

/* ════════════════════════════════════════════════════════════
   STATISTIK SISTEMATIS — semua modul + reviewer + surat
════════════════════════════════════════════════════════════ */

// Helper count quick
$cnt = function(string $sql, array $p = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); }
    catch (\Exception $e) { return 0; }
};

// ── User base ────────────────────────────────────────────────
$total_mhs = $cnt("SELECT COUNT(*) FROM users WHERE role='mahasiswa' AND deleted_at IS NULL");
$total_dsn = $cnt("SELECT COUNT(*) FROM users WHERE role='dosen'     AND deleted_at IS NULL");
$total_rev = $cnt("SELECT COUNT(*) FROM users WHERE role='reviewer'  AND deleted_at IS NULL");

// ── Permohonan Mahasiswa ─────────────────────────────────────
$pl_total    = $cnt("SELECT COUNT(*) FROM skripsi   WHERE deleted_at IS NULL");
$pl_menunggu = $cnt("SELECT COUNT(*) FROM skripsi   WHERE deleted_at IS NULL AND status='menunggu'");
$pl_selesai  = $cnt("SELECT COUNT(*) FROM skripsi   WHERE deleted_at IS NULL AND status='selesai'");
$pl_ditolak  = $cnt("SELECT COUNT(*) FROM skripsi   WHERE deleted_at IS NULL AND status='ditolak'");

$pb_total    = $cnt("SELECT COUNT(*) FROM publikasi WHERE deleted_at IS NULL");
$pb_menunggu = $cnt("SELECT COUNT(*) FROM publikasi WHERE deleted_at IS NULL AND status='menunggu'");
$pb_selesai  = $cnt("SELECT COUNT(*) FROM publikasi WHERE deleted_at IS NULL AND status='diverifikasi'");
$pb_ditolak  = $cnt("SELECT COUNT(*) FROM publikasi WHERE deleted_at IS NULL AND status='ditolak'");

// ── Permohonan Dosen ─────────────────────────────────────────
$ec_total     = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE deleted_at IS NULL");
$ec_menunggu  = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE deleted_at IS NULL AND status IN ('menunggu','diproses')");
$ec_disetujui = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE deleted_at IS NULL AND status='disetujui'");
$ec_ditolak   = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE deleted_at IS NULL AND status='ditolak'");

$pen_total     = $cnt("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL");
$pen_pending   = $cnt("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND status IN ('diajukan','seleksi_admin','seleksi_substansi','perbaikan_substantif','lolos_admin')");
$pen_disetujui = $cnt("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')");
$pen_ditolak   = $cnt("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND status IN ('ditolak','gagal_admin')");

$pgb_total     = $cnt("SELECT COUNT(*) FROM usulan_pengabdian WHERE deleted_at IS NULL");
$pgb_pending   = $cnt("SELECT COUNT(*) FROM usulan_pengabdian WHERE deleted_at IS NULL AND status IN ('diajukan','seleksi_admin','seleksi_substansi','perbaikan_substantif','lolos_admin')");
$pgb_disetujui = $cnt("SELECT COUNT(*) FROM usulan_pengabdian WHERE deleted_at IS NULL AND status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')");
$pgb_ditolak   = $cnt("SELECT COUNT(*) FROM usulan_pengabdian WHERE deleted_at IS NULL AND status IN ('ditolak','gagal_admin')");

// ── Reviewer activity ────────────────────────────────────────
$rev_assign_pen = $cnt("SELECT COUNT(*) FROM reviewer_assignment WHERE deleted_at IS NULL");
$rev_done_pen   = $cnt("SELECT COUNT(*) FROM reviewer_penilaian WHERE submitted_at IS NOT NULL");
$rev_assign_pgb = $cnt("SELECT COUNT(*) FROM reviewer_assignment_pengabdian WHERE deleted_at IS NULL");
$rev_done_pgb   = $cnt("SELECT COUNT(*) FROM reviewer_penilaian_pengabdian WHERE submitted_at IS NOT NULL");

// ── Surat ─────────────────────────────────────────────────────
$total_pl_surat = $cnt("SELECT COUNT(*) FROM surat_plagiasi");
$total_pb_surat = $cnt("SELECT COUNT(*) FROM surat_publikasi");
$total_ec_surat = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE status='disetujui' AND nomor_surat IS NOT NULL AND deleted_at IS NULL");
$total_surat    = $total_pl_surat + $total_pb_surat + $total_ec_surat;
$surat_bulan    = $cnt("
    SELECT COUNT(*) FROM (
      SELECT id FROM surat_plagiasi  WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())
      UNION ALL
      SELECT id FROM surat_publikasi WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())
      UNION ALL
      SELECT id FROM ethical_clearance WHERE status='disetujui' AND nomor_surat IS NOT NULL
        AND MONTH(tanggal_proses)=MONTH(NOW()) AND YEAR(tanggal_proses)=YEAR(NOW()) AND deleted_at IS NULL
    ) s");

// ── Chart: Surat per bulan (12 bulan) — Plagiasi + Publikasi + EC ──
$bulan_labels = [];
$data_pl = []; $data_pub = []; $data_ec = [];
for ($i = 11; $i >= 0; $i--) {
    $tgl = date('Y-m', strtotime("-{$i} months"));
    $y = substr($tgl, 0, 4); $m = substr($tgl, 5, 2);
    $bulan_labels[] = date('M Y', strtotime($tgl . '-01'));
    $data_pl[]  = $cnt("SELECT COUNT(*) FROM surat_plagiasi  WHERE YEAR(created_at)=? AND MONTH(created_at)=?", [$y, $m]);
    $data_pub[] = $cnt("SELECT COUNT(*) FROM surat_publikasi WHERE YEAR(created_at)=? AND MONTH(created_at)=?", [$y, $m]);
    $data_ec[]  = $cnt("SELECT COUNT(*) FROM ethical_clearance WHERE status='disetujui' AND nomor_surat IS NOT NULL AND deleted_at IS NULL AND YEAR(tanggal_proses)=? AND MONTH(tanggal_proses)=?", [$y, $m]);
}

// ── Surat per program studi (semua jenis aggregated) ──
$per_prodi = $pdo->query("
    SELECT u.program_studi, COUNT(*) as total FROM (
      SELECT s.user_id FROM surat_plagiasi sp
        JOIN cek_plagiasi cp ON sp.cek_plagiasi_id=cp.id
        JOIN skripsi s ON cp.skripsi_id=s.id
      UNION ALL
      SELECT p.user_id FROM surat_publikasi sp
        JOIN publikasi p ON sp.publikasi_id=p.id
      UNION ALL
      SELECT user_id FROM ethical_clearance
        WHERE status='disetujui' AND nomor_surat IS NOT NULL AND deleted_at IS NULL
    ) x
    JOIN users u ON x.user_id=u.id
    WHERE u.program_studi IS NOT NULL AND u.program_studi <> ''
    GROUP BY u.program_studi
    ORDER BY total DESC
    LIMIT 10
")->fetchAll();

// Jenis publikasi breakdown
$per_jenis = $pdo->query("
    SELECT p.jenis_publikasi, COUNT(*) as total
    FROM surat_publikasi sp
    JOIN publikasi p ON sp.publikasi_id=p.id
    GROUP BY p.jenis_publikasi
    ORDER BY total DESC
")->fetchAll();

// 10 surat terbaru gabungan
$recent = $pdo->query("
    SELECT * FROM (
      SELECT 'plagiasi' as tipe, sp.id, sp.nomor_surat, sp.tanggal_surat,
             u.nama_lengkap, COALESCE(u.nim,u.nidn,'-') as id_user, sp.created_at
      FROM surat_plagiasi sp
      JOIN cek_plagiasi cp ON sp.cek_plagiasi_id=cp.id
      JOIN skripsi s ON cp.skripsi_id=s.id
      JOIN users u ON s.user_id=u.id
      UNION ALL
      SELECT 'publikasi', sp.id, sp.nomor_surat, sp.tanggal_surat,
             u.nama_lengkap, COALESCE(u.nim,u.nidn,'-'), sp.created_at
      FROM surat_publikasi sp
      JOIN publikasi p ON sp.publikasi_id=p.id
      JOIN users u ON p.user_id=u.id
      UNION ALL
      SELECT 'ec', ec.id, ec.nomor_surat, ec.tanggal_proses,
             u.nama_lengkap, COALESCE(u.nidn,u.nim,'-'), ec.created_at
      FROM ethical_clearance ec
      JOIN users u ON ec.user_id=u.id
      WHERE ec.status='disetujui' AND ec.nomor_surat IS NOT NULL AND ec.deleted_at IS NULL
    ) x
    ORDER BY created_at DESC LIMIT 12
")->fetchAll();

// Helper card config
$groups = [
  [
    'title' => $id?'Permohonan Mahasiswa':'Student Submissions',
    'icon'  => 'graduation',
    'grad'  => 'linear-gradient(135deg,#1e3a5f,#2563eb)',
    'cards' => [
      ['plagiasi.php',  'search',     $id?'Bebas Plagiasi':'Plagiarism-Free',
       $pl_total, $pl_menunggu, $pl_selesai, $pl_ditolak, '#2563eb'],
      ['publikasi.php', 'newspaper',  $id?'Surat Publikasi':'Publication Letter',
       $pb_total, $pb_menunggu, $pb_selesai, $pb_ditolak, '#0d9488'],
    ],
  ],
  [
    'title' => $id?'Permohonan Dosen':'Lecturer Submissions',
    'icon'  => 'briefcase',
    'grad'  => 'linear-gradient(135deg,#7c2d12,#ea580c)',
    'cards' => [
      ['ethical_clearance.php', 'clipboard', 'Ethical Clearance',
       $ec_total, $ec_menunggu, $ec_disetujui, $ec_ditolak, '#9a3412'],
      ['penelitian.php', 'award', $id?'Usulan Penelitian':'Research Proposals',
       $pen_total, $pen_pending, $pen_disetujui, $pen_ditolak, '#1e40af'],
      ['pengabdian.php', 'users', $id?'Usulan Pengabdian':'Community Service',
       $pgb_total, $pgb_pending, $pgb_disetujui, $pgb_ditolak, '#7c3aed'],
    ],
  ],
  [
    'title' => $id?'Aktivitas Reviewer':'Reviewer Activity',
    'icon'  => 'shield',
    'grad'  => 'linear-gradient(135deg,#064e3b,#059669)',
    'cards' => [
      ['penelitian_reviewer.php', 'award', $id?'Review Penelitian':'Research Reviews',
       $rev_assign_pen, max(0,$rev_assign_pen-$rev_done_pen), $rev_done_pen, 0, '#059669'],
      ['pengabdian_reviewer.php', 'users', $id?'Review Pengabdian':'PkM Reviews',
       $rev_assign_pgb, max(0,$rev_assign_pgb-$rev_done_pgb), $rev_done_pgb, 0, '#7c3aed'],
    ],
  ],
  [
    'title' => $id?'Arsip Surat Terbit':'Letters Issued',
    'icon'  => 'archive',
    'grad'  => 'linear-gradient(135deg,#3b0764,#7c3aed)',
    'cards' => [
      ['surat.php?tab=plagiasi',  'search',    $id?'Surat Plagiasi':'Plagiarism Letters',
       $total_pl_surat, 0, $total_pl_surat, 0, '#2563eb'],
      ['surat.php?tab=publikasi', 'newspaper', $id?'Surat Publikasi':'Publication Letters',
       $total_pb_surat, 0, $total_pb_surat, 0, '#0d9488'],
      ['surat.php?tab=ec',        'clipboard', $id?'Surat EC':'EC Letters',
       $total_ec_surat, 0, $total_ec_surat, 0, '#0d9488'],
    ],
  ],
];

$total_pending = $pl_menunggu + $pb_menunggu + $ec_menunggu + $pen_pending + $pgb_pending;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Laporan & Statistik':'Reports & Statistics' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.lp-hero{background:linear-gradient(135deg,#0d0428 0%,#1a0a3d 60%,#130a38 100%);
         color:#fff;border-radius:16px;padding:20px 24px;margin-bottom:18px;display:flex;
         align-items:center;gap:16px;box-shadow:0 6px 24px rgba(74,29,150,.18)}
.lp-hero-icon{width:48px;height:48px;border-radius:13px;background:rgba(255,255,255,.16);
              display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid rgba(255,255,255,.22)}
.lp-hero h1{font-size:18px;font-weight:800;letter-spacing:.2px;margin:0}
.lp-hero p{font-size:12.5px;opacity:.82;margin:3px 0 0}
.lp-hero-stat{margin-left:auto;text-align:center;background:rgba(255,255,255,.12);padding:11px 18px;border-radius:11px;border:1.5px solid rgba(255,255,255,.18)}
.lp-hero-stat .num{font-size:24px;font-weight:900;line-height:1}
.lp-hero-stat .lbl{font-size:10.5px;opacity:.85;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}

.lp-base{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
@media(max-width:760px){.lp-base{grid-template-columns:1fr}}
.lp-base-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:11px;padding:13px 16px;display:flex;align-items:center;gap:13px}
.lp-base-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.lp-base-num{font-size:22px;font-weight:900;line-height:1;color:#1e293b}
.lp-base-lbl{font-size:11px;color:#64748b;margin-top:2px;text-transform:uppercase;letter-spacing:.3px}

.lp-group{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.lp-group-head{padding:13px 18px;color:#fff;display:flex;align-items:center;gap:13px}
.lp-group-icon{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.18);
               display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid rgba(255,255,255,.22)}
.lp-group-title{font-size:14px;font-weight:800;letter-spacing:.2px}
.lp-group-meta{font-size:11.5px;opacity:.82;margin-top:1px}
.lp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1px;background:#e2e8f0}
.lp-card{background:#fff;padding:14px 16px;text-decoration:none;color:inherit;
         display:flex;flex-direction:column;gap:7px;transition:background .15s, transform .12s}
.lp-card:hover{background:var(--accent-soft, #f8fafc);transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.05);z-index:1}
.lp-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.lp-card-ico{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;
             background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 75%,#000))}
.lp-card-name{font-size:12.5px;font-weight:800;color:#1e293b;line-height:1.3}
.lp-card-num{font-size:24px;font-weight:900;color:var(--accent);line-height:1;margin-top:3px}
.lp-card-mini{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.lp-chip{font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px}
.lp-chip-pen{background:#fef9c3;color:#854d0e}
.lp-chip-ok {background:#dcfce7;color:#166534}
.lp-chip-rej{background:#fee2e2;color:#991b1b}
.lp-card-foot{display:flex;align-items:center;gap:5px;padding-top:8px;border-top:1px dashed #e2e8f0;
              margin-top:auto;font-size:11px;font-weight:700;color:var(--accent)}
.lp-card:hover .lp-card-foot svg{transform:translateX(3px)}
.lp-card-foot svg{transition:transform .18s}

.lp-charts{display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-bottom:16px}
@media(max-width:900px){.lp-charts{grid-template-columns:1fr}}
.lp-chart-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px}
.lp-chart-card h3{font-size:13px;font-weight:800;color:#1e293b;margin:0 0 11px;display:flex;align-items:center;gap:7px}

.lp-table{width:100%;border-collapse:collapse;font-size:12px}
.lp-table th{background:#f8fafc;color:#64748b;font-weight:700;font-size:10.5px;text-transform:uppercase;
             letter-spacing:.4px;padding:8px 11px;text-align:left;border-bottom:1.5px solid #e2e8f0}
.lp-table td{padding:9px 11px;border-bottom:1px solid #e2e8f0}
.lp-table tr:last-child td{border-bottom:none}
.tipe-pill{display:inline-flex;align-items:center;gap:4px;padding:1px 8px;border-radius:5px;font-size:10px;font-weight:700;text-transform:uppercase}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title"><?= ic('chart') ?> <?= $id?'Laporan & Statistik':'Reports & Statistics' ?></div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- Hero ringkasan total -->
      <div class="lp-hero">
        <div class="lp-hero-icon"><?= ic('chart','style="width:24px;height:24px;color:#fff"') ?></div>
        <div>
          <h1><?= $id?'Rangkuman Aktivitas LPPM':'LPPM Activity Summary' ?></h1>
          <p><?= $id?'Statistik real-time semua permohonan, review, dan surat yang diterbitkan':'Real-time stats of all submissions, reviews, and letters issued' ?></p>
        </div>
        <div class="lp-hero-stat">
          <div class="num"><?= $total_pending ?></div>
          <div class="lbl"><?= $id?'Perlu Diproses':'Need Processing' ?></div>
        </div>
      </div>

      <!-- Base counts: User -->
      <div class="lp-base">
        <a href="pengguna.php?tab=mahasiswa" class="lp-base-card" style="text-decoration:none">
          <div class="lp-base-ico" style="background:#dbeafe;color:#1e40af"><?= ic('graduation') ?></div>
          <div><div class="lp-base-num"><?= $total_mhs ?></div><div class="lp-base-lbl"><?= $id?'Mahasiswa':'Students' ?></div></div>
        </a>
        <a href="pengguna.php?tab=dosen" class="lp-base-card" style="text-decoration:none">
          <div class="lp-base-ico" style="background:#fef3c7;color:#a16207"><?= ic('briefcase') ?></div>
          <div><div class="lp-base-num"><?= $total_dsn ?></div><div class="lp-base-lbl"><?= $id?'Dosen':'Lecturers' ?></div></div>
        </a>
        <a href="pengguna.php?tab=reviewer" class="lp-base-card" style="text-decoration:none">
          <div class="lp-base-ico" style="background:#dcfce7;color:#15803d"><?= ic('shield') ?></div>
          <div><div class="lp-base-num"><?= $total_rev ?></div><div class="lp-base-lbl"><?= $id?'Reviewer':'Reviewers' ?></div></div>
        </a>
      </div>

      <!-- Per-group cards -->
      <?php foreach ($groups as $g):
        $g_total   = array_sum(array_column($g['cards'], 3));
        $g_pending = array_sum(array_column($g['cards'], 4));
      ?>
      <section class="lp-group">
        <div class="lp-group-head" style="background:<?= $g['grad'] ?>">
          <div class="lp-group-icon"><?= ic($g['icon'],'style="width:18px;height:18px;color:#fff"') ?></div>
          <div style="flex:1">
            <div class="lp-group-title"><?= htmlspecialchars($g['title']) ?></div>
            <div class="lp-group-meta"><?= $g_total ?> <?= $id?'total':'total' ?><?= $g_pending ? ' · ' . $g_pending . ' ' . ($id?'menunggu':'pending') : '' ?></div>
          </div>
        </div>
        <div class="lp-cards">
          <?php foreach ($g['cards'] as $c): list($href,$icon,$lbl,$tot,$pen,$ok,$rej,$accent) = $c; ?>
          <a href="<?= $href ?>" class="lp-card" style="--accent:<?= $accent ?>;--accent-soft:<?= $accent ?>11">
            <div class="lp-card-top">
              <div class="lp-card-ico"><?= ic($icon,'style="width:16px;height:16px;color:#fff"') ?></div>
              <?php if ($pen > 0): ?>
              <span style="background:#fef3c7;color:#854d0e;font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:5px"><?= $pen ?> <?= $id?'PENDING':'PENDING' ?></span>
              <?php endif; ?>
            </div>
            <div class="lp-card-name"><?= htmlspecialchars($lbl) ?></div>
            <div class="lp-card-num"><?= $tot ?></div>
            <div class="lp-card-mini">
              <?php if ($pen): ?><span class="lp-chip lp-chip-pen"><?= $pen ?> <?= $id?'pending':'pending' ?></span><?php endif; ?>
              <?php if ($ok):  ?><span class="lp-chip lp-chip-ok"><?= $ok ?> <?= $id?'selesai':'done' ?></span><?php endif; ?>
              <?php if ($rej): ?><span class="lp-chip lp-chip-rej"><?= $rej ?> <?= $id?'ditolak':'rejected' ?></span><?php endif; ?>
              <?php if (!$pen && !$ok && !$rej): ?><span style="color:#94a3b8;font-size:10.5px;font-style:italic"><?= $id?'belum ada data':'no data' ?></span><?php endif; ?>
            </div>
            <div class="lp-card-foot"><?= $id?'Buka menu':'Open menu' ?>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <!-- Charts: trend bulanan + top prodi -->
      <div class="lp-charts">
        <div class="lp-chart-card">
          <h3><?= ic('chart') ?> <?= $id?'Tren Surat Terbit (12 Bulan Terakhir)':'Letter Trend (Last 12 Months)' ?></h3>
          <canvas id="chartTrend" height="100"></canvas>
        </div>
        <div class="lp-chart-card">
          <h3><?= ic('graduation') ?> <?= $id?'Top Program Studi':'Top Study Programs' ?></h3>
          <?php if (empty($per_prodi)): ?>
            <div style="text-align:center;color:#94a3b8;font-size:12px;padding:30px"><?= $id?'Belum ada data.':'No data yet.' ?></div>
          <?php else: ?>
          <table class="lp-table">
            <thead><tr><th><?= $id?'Program Studi':'Program' ?></th><th style="text-align:right;width:60px">Total</th></tr></thead>
            <tbody>
            <?php foreach ($per_prodi as $p): ?>
            <tr>
              <td style="font-weight:600;color:#1e293b"><?= htmlspecialchars($p['program_studi']) ?></td>
              <td style="text-align:right;font-weight:800;color:var(--primary)"><?= $p['total'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent surat -->
      <div class="lp-chart-card" style="margin-bottom:16px">
        <h3><?= ic('archive') ?> <?= $id?'Surat Terbaru':'Recent Letters' ?> (12)
          <a href="surat.php" style="margin-left:auto;font-size:11.5px;color:var(--primary);text-decoration:none;font-weight:600"><?= $id?'Lihat semua arsip':'View all archive' ?> →</a>
        </h3>
        <?php if (empty($recent)): ?>
          <div style="text-align:center;color:#94a3b8;font-size:12px;padding:30px"><?= $id?'Belum ada surat diterbitkan.':'No letters issued yet.' ?></div>
        <?php else: ?>
        <table class="lp-table">
          <thead><tr>
            <th>Tipe</th><th><?= $id?'No. Surat':'No.' ?></th><th><?= $id?'Penerima':'Recipient' ?></th>
            <th><?= $id?'ID':'ID' ?></th><th><?= $id?'Tanggal':'Date' ?></th><th><?= $id?'Aksi':'Action' ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($recent as $r):
            $tipe_cfg = match($r['tipe']) {
              'plagiasi'  => ['#dbeafe','#1e40af','Plagiasi','plagiasi'],
              'publikasi' => ['#ccfbf1','#0f766e','Publikasi','publikasi'],
              'ec'        => ['#fef3c7','#a16207','Ethical Clearance','ec'],
            };
            $url_unduh = match($r['tipe']) {
              'plagiasi'  => '../modules/plagiasi/unduh_surat.php?id=' . $r['id'],
              'publikasi' => '../modules/publikasi/unduh_surat.php?id=' . $r['id'],
              'ec'        => '../modules/ethical_clearance/unduh_surat.php?id=' . $r['id'],
            };
          ?>
          <tr>
            <td><span class="tipe-pill" style="background:<?= $tipe_cfg[0] ?>;color:<?= $tipe_cfg[1] ?>"><?= $tipe_cfg[2] ?></span></td>
            <td style="font-weight:600;color:var(--primary);font-size:11.5px;white-space:nowrap"><?= htmlspecialchars($r['nomor_surat']) ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['nama_lengkap']) ?></td>
            <td style="color:#64748b;font-size:11px"><?= htmlspecialchars($r['id_user']) ?></td>
            <td style="color:#64748b;font-size:11px;white-space:nowrap"><?= $r['tanggal_surat']?formatTanggal($r['tanggal_surat']):'—' ?></td>
            <td>
              <a href="<?= $url_unduh ?>" target="_blank" class="btn btn-outline" style="font-size:10.5px;padding:4px 9px">
                <?= ic('download','style="width:11px;height:11px"') ?> <?= $id?'Unduh':'Download' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}

const ctx = document.getElementById('chartTrend');
if (ctx && typeof Chart !== 'undefined') {
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($bulan_labels) ?>,
      datasets: [
        { label: 'Plagiasi',  data: <?= json_encode($data_pl)  ?>, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', tension: .3, fill: true },
        { label: 'Publikasi', data: <?= json_encode($data_pub) ?>, borderColor: '#0d9488', backgroundColor: 'rgba(13,148,136,.12)', tension: .3, fill: true },
        { label: 'Ethical Clearance', data: <?= json_encode($data_ec) ?>, borderColor: '#a16207', backgroundColor: 'rgba(161,98,7,.12)', tension: .3, fill: true },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
}
</script>
</body>
</html>
