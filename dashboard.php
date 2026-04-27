<?php
require_once 'includes/config.php';
require_once 'includes/reminder_luaran.php';
requireLogin('mahasiswa');

$lang    = $_COOKIE['lang'] ?? 'id';
$uid     = $_SESSION['user_id'];
$is_dosen = isDosen();

$batas_sim = (int)(getSetting($pdo, 'batas_similarity') ?: 20);
$batas_ai  = (int)(getSetting($pdo, 'batas_ai') ?: 30);

// ── Statistik plagiasi (hanya mahasiswa) ──
$total_skripsi = $selesai_plagiasi = $menunggu_plagiasi = $ditolak_plagiasi = 0;
$skripsi_list  = [];
if (!$is_dosen) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM skripsi WHERE user_id=? GROUP BY status");
    $stmt->execute([$uid]);
    $pl_stats = [];
    foreach ($stmt->fetchAll() as $r) $pl_stats[$r['status']] = (int)$r['c'];
    $total_skripsi    = array_sum($pl_stats);
    $selesai_plagiasi = $pl_stats['selesai'] ?? 0;
    $menunggu_plagiasi = $pl_stats['menunggu'] ?? 0;
    $ditolak_plagiasi  = ($pl_stats['ditolak'] ?? 0) + ($pl_stats['revisi'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT s.*, cp.similarity_score, cp.ai_score, cp.platform_ai,
               cp.tanggal_cek, cp.catatan,
               sp.id AS surat_id, sp.nomor_surat
        FROM skripsi s
        LEFT JOIN cek_plagiasi cp ON s.id = cp.skripsi_id
        LEFT JOIN surat_plagiasi sp ON cp.id = sp.cek_plagiasi_id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $skripsi_list = $stmt->fetchAll();
}

// ── Statistik publikasi (mahasiswa only) ──
$total_pub = $lulus_pub = $menunggu_pub = $ditolak_pub = 0;
$pub_list  = [];
if (!$is_dosen) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM publikasi WHERE user_id=? GROUP BY status");
    $stmt->execute([$uid]);
    $pub_stats = [];
    foreach ($stmt->fetchAll() as $r) $pub_stats[$r['status']] = (int)$r['c'];
    $total_pub    = array_sum($pub_stats);
    $lulus_pub    = $pub_stats['diverifikasi'] ?? 0;
    $menunggu_pub = $pub_stats['menunggu'] ?? 0;
    $ditolak_pub  = $pub_stats['ditolak'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT p.*, spub.id AS surat_id, spub.nomor_surat
        FROM publikasi p
        LEFT JOIN surat_publikasi spub ON p.id = spub.publikasi_id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $pub_list = $stmt->fetchAll();
}

// ── Statistik Ethical Clearance (dosen; mahasiswa belum punya akses) ──
$total_ec = $disetujui_ec = $menunggu_ec = $ditolak_ec = 0;
$ec_list  = [];
if ($is_dosen) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM ethical_clearance WHERE user_id=? GROUP BY status");
    $stmt->execute([$uid]);
    $ec_stats = [];
    foreach ($stmt->fetchAll() as $r) $ec_stats[$r['status']] = (int)$r['c'];
    $total_ec     = array_sum($ec_stats);
    $disetujui_ec = $ec_stats['disetujui'] ?? 0;
    $menunggu_ec  = $ec_stats['menunggu']  ?? 0;
    $ditolak_ec   = $ec_stats['ditolak']   ?? 0;

    $stmt = $pdo->prepare("
        SELECT id, judul_penelitian, nama_jurnal, status, nomor_surat, file_surat_signed, created_at, tanggal_proses
        FROM ethical_clearance WHERE user_id=? ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $ec_list = $stmt->fetchAll();
}

// ── Statistik Penelitian (hanya dosen) ──
$total_penelitian = $disetujui_penelitian = $menunggu_penelitian = $ditolak_penelitian = 0;
$penelitian_list  = [];
$pengingat_list   = []; // Pengingat: kontrak siap TTD, deadline laporan dekat, dll
if ($is_dosen) {
    try {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM usulan_penelitian WHERE user_id=? AND deleted_at IS NULL GROUP BY status");
        $stmt->execute([$uid]);
        $pen_stats = [];
        foreach ($stmt->fetchAll() as $r) $pen_stats[$r['status']] = (int)$r['c'];
        $total_penelitian    = array_sum($pen_stats);
        $disetujui_penelitian = ($pen_stats['disetujui'] ?? 0)
                              + ($pen_stats['penandatanganan_kontrak'] ?? 0)
                              + ($pen_stats['kontrak_aktif'] ?? 0)
                              + ($pen_stats['laporan_diterima'] ?? 0)
                              + ($pen_stats['selesai'] ?? 0);
        $menunggu_penelitian  = ($pen_stats['draft'] ?? 0)
                              + ($pen_stats['diajukan'] ?? 0)
                              + ($pen_stats['seleksi_admin'] ?? 0)
                              + ($pen_stats['lolos_admin'] ?? 0)
                              + ($pen_stats['seleksi_substansi'] ?? 0);
        $ditolak_penelitian   = ($pen_stats['ditolak'] ?? 0)
                              + ($pen_stats['gagal_admin'] ?? 0)
                              + ($pen_stats['perbaikan_admin'] ?? 0)
                              + ($pen_stats['perbaikan_substantif'] ?? 0)
                              + ($pen_stats['revisi_minor'] ?? 0)
                              + ($pen_stats['revisi_mayor'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT id, skema, judul, status, created_at, updated_at
            FROM usulan_penelitian WHERE user_id=? AND deleted_at IS NULL
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmt->execute([$uid]);
        $penelitian_list = $stmt->fetchAll();

        // ── Pengingat aktif: kontrak siap TTD + deadline laporan (UNION penelitian + pengabdian) ──
        $stmt = $pdo->prepare("
            SELECT up.id, up.judul, up.status, 'penelitian' AS modul,
                   kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan, kp.file_kontrak,
                   lp.id AS lap_id, lp.status AS lap_status
            FROM usulan_penelitian up
            LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
            LEFT JOIN laporan_penelitian lp ON lp.usulan_id = up.id
            WHERE up.user_id = ? AND up.deleted_at IS NULL
              AND up.status IN ('penandatanganan_kontrak','kontrak_aktif')
            UNION ALL
            SELECT up.id, up.judul, up.status, 'pengabdian' AS modul,
                   kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan, kp.file_kontrak,
                   lp.id AS lap_id, lp.status AS lap_status
            FROM usulan_pengabdian up
            LEFT JOIN kontrak_pengabdian kp ON kp.usulan_id = up.id
            LEFT JOIN laporan_pengabdian lp ON lp.usulan_id = up.id
            WHERE up.user_id = ? AND up.deleted_at IS NULL
              AND up.status IN ('penandatanganan_kontrak','kontrak_aktif')
            ORDER BY deadline_laporan ASC
        ");
        $stmt->execute([$uid, $uid]);
        $pengingat_list = $stmt->fetchAll();
    } catch (\Exception $e) {}
}

// ── Pengingat Luaran 3-bulanan (popup) — gabungan penelitian + pengabdian ──
$luaran_reminders = [];
if ($is_dosen) {
    try {
        $pen_rem = processLuaranReminders($pdo, (int)$uid);
        foreach ($pen_rem as &$r) { $r['modul'] = 'penelitian'; }
        unset($r);
        $luaran_reminders = $pen_rem;
    } catch (\Exception $e) {}
    try {
        if (function_exists('processLuaranRemindersPengabdian')) {
            $pgb_rem = processLuaranRemindersPengabdian($pdo, (int)$uid);
        } else {
            require_once __DIR__ . '/includes/reminder_luaran_pengabdian.php';
            $pgb_rem = processLuaranRemindersPengabdian($pdo, (int)$uid);
        }
        foreach ($pgb_rem as &$r) { $r['modul'] = 'pengabdian'; }
        unset($r);
        $luaran_reminders = array_merge($luaran_reminders, $pgb_rem);
    } catch (\Exception $e) {}
}

// ── Surat diterbitkan ──
$total_surat = $selesai_plagiasi + $lulus_pub;

// ── Notifikasi ──
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifikasi WHERE user_id=? AND is_read=0");
$stmt->execute([$uid]);
$notif_count = $stmt->fetchColumn();

$nama_institusi = getSetting($pdo, 'nama_institusi');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Beranda':'Dashboard' ?> — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="assets/css/style.css?v=4">
<style>
/* ── Detail Drawer ── */
#detail-drawer{width:460px;max-width:100%;display:flex;flex-direction:column;animation:slideIn .2s ease}
@keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);position:sticky;top:0;background:#fff;z-index:1}
.drawer-head h3{font-size:15px;font-weight:700;color:var(--primary);margin:0}
.drawer-close{background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;border-radius:6px}
.drawer-close:hover{background:#f1f5f9;color:#475569}
.drawer-body{padding:20px;flex:1}
.detail-section{margin-bottom:18px}
.detail-section-title{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.6px;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border)}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.detail-item{padding:8px 10px;background:#f8fafc;border-radius:8px;border:1px solid var(--border)}
.detail-item-label{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:2px}
.detail-item-value{font-size:13px;font-weight:600;color:var(--text-primary)}
.detail-item.full{grid-column:1/-1}
.score-pair{display:flex;gap:12px;margin-bottom:4px}
.score-box{flex:1;border-radius:10px;padding:12px;text-align:center}
.score-box .num{font-size:26px;font-weight:800;line-height:1}
.score-box .lbl{font-size:10px;font-weight:600;margin-top:4px;text-transform:uppercase}
.catatan-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px 12px;font-size:13px;color:#9a3412}
.nomor-box{background:var(--primary-xlight);border:1px solid var(--primary-light);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--primary);font-weight:600}
@media(max-width:480px){#detail-drawer{width:100%}.detail-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrapper">
  <?php include 'includes/sidebar.php'; ?>

  <div class="main-content">
    <!-- Topbar -->
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= $is_dosen ? ($lang==='id' ? 'Beranda Dosen' : 'Lecturer Dashboard') : ($lang==='id' ? 'Beranda Mahasiswa' : 'Student Dashboard') ?>
          <span class="breadcrumb"><?= htmlspecialchars($_SESSION['nama']) ?> · <?= $is_dosen ? ($_SESSION['nidn'] ?? '') : ($_SESSION['nim'] ?? '') ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <?php if ($notif_count): ?>
        <button class="notif-btn" onclick="toggleNotif()">
          <?= ic('bell') ?> <span class="notif-badge"><?= $notif_count ?></span>
        </button>
        <?php endif; ?>
        <button class="lang-toggle" onclick="toggleLang()">
          <?= ic('globe') ?> <?= $lang==='id' ? 'EN' : 'ID' ?>
        </button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'warning' ?>">
          <?= htmlspecialchars($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <!-- Selamat datang -->
      <div class="welcome-banner">
        <div>
          <h2><?= $lang==='id' ? 'Selamat Datang' : 'Welcome' ?>, <?= htmlspecialchars($_SESSION['nama']) ?>!</h2>
          <p><?= $is_dosen ? ($lang==='id' ? 'Dosen — ' : 'Lecturer — ') : '' ?><?= $_SESSION['prodi'] ?? '' ?></p>
        </div>
        <div class="wic"><?= ic($is_dosen ? 'user' : 'graduation', 'style="width:26px;height:26px"') ?></div>
      </div>

      <!-- Statistik ringkasan -->
      <div class="stats-grid" style="margin-bottom:6px">

        <?php if (!$is_dosen): ?>
        <!-- Mahasiswa: plagiasi -->
        <a href="#sec-plagiasi" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon navy"><?= ic('doc') ?></div>
          <div style="flex:1">
            <div class="stat-label"><?= $lang==='id'?'Cek Plagiasi':'Plagiarism Check' ?></div>
            <div class="stat-value"><?= $total_skripsi ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?php $parts=[];
                if($selesai_plagiasi)  $parts[]='<span style="color:#059669">'.$selesai_plagiasi.' selesai</span>';
                if($menunggu_plagiasi) $parts[]='<span style="color:#d97706">'.$menunggu_plagiasi.' menunggu</span>';
                if($ditolak_plagiasi)  $parts[]='<span style="color:#dc2626">'.$ditolak_plagiasi.' ditolak</span>';
                echo implode(' · ', $parts) ?: '<span style="color:#94a3b8">—</span>'; ?>
            </div>
          </div>
        </a>
        <a href="#sec-plagiasi" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon green"><?= ic('check-circle') ?></div>
          <div>
            <div class="stat-label"><?= $lang==='id'?'Bebas Plagiasi':'Plagiarism-Free' ?></div>
            <div class="stat-value"><?= $selesai_plagiasi ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $lang==='id'?'Surat terbit':'Certificate issued' ?></div>
          </div>
        </a>
        <?php endif; ?>

        <?php if (!$is_dosen): ?>
        <!-- Publikasi (mahasiswa only) -->
        <a href="#sec-publikasi" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon blue"><?= ic('newspaper') ?></div>
          <div style="flex:1">
            <div class="stat-label"><?= $lang==='id'?'Pengajuan Publikasi':'Publication Apps' ?></div>
            <div class="stat-value"><?= $total_pub ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?php $pp=[];
                if($lulus_pub)    $pp[]='<span style="color:#059669">'.$lulus_pub.' terverifikasi</span>';
                if($menunggu_pub) $pp[]='<span style="color:#d97706">'.$menunggu_pub.' menunggu</span>';
                if($ditolak_pub)  $pp[]='<span style="color:#dc2626">'.$ditolak_pub.' ditolak</span>';
                echo implode(' · ', $pp) ?: '<span style="color:#94a3b8">—</span>'; ?>
            </div>
          </div>
        </a>
        <?php endif; ?>

        <!-- Ethical Clearance (dosen; mahasiswa belum punya akses) -->
        <?php if ($is_dosen): ?>
        <a href="#sec-ec" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><?= ic('clipboard') ?></div>
          <div style="flex:1">
            <div class="stat-label"><?= $lang==='id'?'Ethical Clearance':'Ethical Clearance' ?></div>
            <div class="stat-value"><?= $total_ec ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?php $ep=[];
                if($disetujui_ec) $ep[]='<span style="color:#059669">'.$disetujui_ec.' disetujui</span>';
                if($menunggu_ec)  $ep[]='<span style="color:#d97706">'.$menunggu_ec.' menunggu</span>';
                if($ditolak_ec)   $ep[]='<span style="color:#dc2626">'.$ditolak_ec.' ditolak</span>';
                echo implode(' · ', $ep) ?: '<span style="color:#94a3b8">—</span>'; ?>
            </div>
          </div>
        </a>
        <?php endif; // EC dosen only ?>

        <?php if ($is_dosen): ?>
        <!-- Dosen: usulan penelitian -->
        <a href="#sec-penelitian" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon" style="background:#faf5ff;color:#7c3aed"><?= ic('doc') ?></div>
          <div style="flex:1">
            <div class="stat-label"><?= $lang==='id'?'Usulan Penelitian':'Research Proposals' ?></div>
            <div class="stat-value"><?= $total_penelitian ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?php $rp=[];
                if($disetujui_penelitian) $rp[]='<span style="color:#059669">'.$disetujui_penelitian.' disetujui</span>';
                if($menunggu_penelitian)  $rp[]='<span style="color:#d97706">'.$menunggu_penelitian.' diproses</span>';
                if($ditolak_penelitian)   $rp[]='<span style="color:#dc2626">'.$ditolak_penelitian.' revisi/tolak</span>';
                echo implode(' · ', $rp) ?: '<span style="color:#94a3b8">—</span>'; ?>
            </div>
          </div>
        </a>
        <?php else: ?>
        <!-- Mahasiswa: surat diterbitkan -->
        <a href="#sec-publikasi" class="stat-card" style="text-decoration:none;cursor:pointer">
          <div class="stat-icon amber"><?= ic('award') ?></div>
          <div>
            <div class="stat-label"><?= $lang==='id'?'Surat Diterbitkan':'Certificates Issued' ?></div>
            <div class="stat-value"><?= $total_surat ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $selesai_plagiasi ?> PL · <?= $lulus_pub ?> PB</div>
          </div>
        </a>
        <?php endif; ?>

      </div>

      <!-- Quick Actions -->
      <div class="qa-grid" style="margin-bottom:24px;margin-top:20px">
        <?php if (!$is_dosen): ?>
        <a href="<?= BASE_URL ?>/modules/plagiasi/upload.php" class="qa-btn">
          <div class="qa-icon navy"><?= ic('search') ?></div>
          <div>
            <div class="qa-title"><?= $lang==='id' ? 'Upload Tugas Akhir' : 'Upload Final Project' ?></div>
            <div class="qa-sub"><?= $lang==='id' ? 'Ajukan cek bebas plagiasi' : 'Apply for plagiarism check' ?></div>
          </div>
          <div class="qa-arr"><?= ic('doc') ?></div>
        </a>
        <?php endif; ?>
        <?php if (!$is_dosen): ?>
        <a href="<?= BASE_URL ?>/modules/publikasi/upload.php" class="qa-btn">
          <div class="qa-icon blue"><?= ic('newspaper') ?></div>
          <div>
            <div class="qa-title"><?= $lang==='id' ? 'Upload Publikasi' : 'Upload Publication' ?></div>
            <div class="qa-sub"><?= $lang==='id' ? 'Ajukan surat keterangan publikasi' : 'Apply for publication certificate' ?></div>
          </div>
          <div class="qa-arr"><?= ic('award') ?></div>
        </a>
        <?php endif; ?>
        <?php if ($is_dosen): // EC quick action — mahasiswa belum punya akses ?>
        <a href="<?= BASE_URL ?>/modules/ethical_clearance/upload.php" class="qa-btn">
          <div class="qa-icon" style="background:#f0fdf4;color:#16a34a"><?= ic('clipboard') ?></div>
          <div>
            <div class="qa-title"><?= $lang==='id' ? 'Ethical Clearance' : 'Ethical Clearance' ?></div>
            <div class="qa-sub"><?= $lang==='id' ? 'Ajukan persetujuan etik penelitian' : 'Apply for research ethics approval' ?></div>
          </div>
          <div class="qa-arr"><?= ic('check') ?></div>
        </a>
        <?php endif; ?>
        <?php if ($is_dosen): ?>
        <a href="<?= BASE_URL ?>/modules/penelitian/index.php" class="qa-btn">
          <div class="qa-icon" style="background:#faf5ff;color:#7c3aed"><?= ic('edit') ?></div>
          <div>
            <div class="qa-title"><?= $lang==='id' ? 'Usulan Penelitian' : 'Research Proposal' ?></div>
            <div class="qa-sub"><?= $lang==='id' ? 'Ajukan atau kelola proposal penelitian' : 'Submit or manage research proposals' ?></div>
          </div>
          <div class="qa-arr"><?= ic('doc') ?></div>
        </a>
        <?php endif; ?>
      </div>

      <!-- ── Riwayat Plagiasi (mahasiswa only) ───────────────── -->
      <?php if (!$is_dosen): ?>
      <div class="card" style="margin-bottom:20px" id="sec-plagiasi">
        <div class="card-header">
          <span class="card-title">
            <?= ic('search') ?> <?= $lang==='id' ? 'Riwayat Pengajuan Bebas Plagiasi' : 'Plagiarism-Free Application History' ?>
            <?php if ($total_skripsi > count($skripsi_list)): ?>
              <span style="font-size:11px;font-weight:500;color:var(--text-muted);margin-left:6px">(<?= count($skripsi_list) ?> dari <?= $total_skripsi ?> total)</span>
            <?php endif; ?>
          </span>
          <a href="modules/plagiasi/upload.php" class="btn btn-primary btn-sm">
            + <?= $lang==='id' ? 'Upload Baru' : 'New Upload' ?>
          </a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($skripsi_list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= ic('inbox', 'style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id' ? 'Belum ada pengajuan.' : 'No applications yet.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Judul':'Title' ?></th>
                  <th><?= $lang==='id'?'Tgl Upload':'Date' ?></th>
                  <th>Similarity</th>
                  <th><?= $lang==='id'?'Deteksi AI':'AI' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($skripsi_list as $s): ?>
                <tr>
                  <td style="max-width:220px">
                    <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($s['judul_skripsi']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($s['judul_skripsi'], 0, 50, '…')) ?>
                    </div>
                  </td>
                  <td style="white-space:nowrap;font-size:12px;color:#64748b"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                  <td>
                    <?php if ($s['similarity_score'] !== null): ?>
                      <?php $safe = $s['similarity_score'] <= $batas_sim; ?>
                      <span style="font-weight:700;font-size:14px;color:<?= $safe?'#059669':'#dc2626' ?>"><?= $s['similarity_score'] ?>%</span>
                    <?php else: ?><span style="color:#94a3b8;font-size:13px">—</span><?php endif; ?>
                  </td>
                  <td>
                    <?php if ($s['ai_score'] !== null && $s['ai_score'] !== ''): ?>
                      <?php $aiSafe = $s['ai_score'] <= $batas_ai; ?>
                      <span style="font-weight:700;font-size:14px;color:<?= $aiSafe?'#059669':'#dc2626' ?>"><?= $s['ai_score'] ?>%</span>
                    <?php else: ?><span style="color:#94a3b8;font-size:13px">—</span><?php endif; ?>
                  </td>
                  <td><?= badgeStatus($s['status']) ?></td>
                  <td style="white-space:nowrap">
                    <button onclick="openDetail('pl-<?= $s['id'] ?>')" class="btn btn-outline btn-sm" style="margin-right:4px">
                      <?= ic('eye') ?> <?= $lang==='id'?'Lihat':'View' ?>
                    </button>
                    <?php if ($s['surat_id']): ?>
                      <a href="modules/plagiasi/unduh_surat.php?id=<?= $s['surat_id'] ?>" class="btn btn-success btn-sm">
                        <?= ic('download') ?>
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php endif; // end !$is_dosen plagiasi ?>

      <!-- ── Riwayat Publikasi (mahasiswa only) ───────────────── -->
      <?php if (!$is_dosen): ?>
      <div class="card" id="sec-publikasi">
        <div class="card-header">
          <span class="card-title">
            <?= ic('newspaper') ?> <?= $lang==='id' ? 'Riwayat Pengajuan Surat Publikasi' : 'Publication Certificate History' ?>
            <?php if ($total_pub > count($pub_list)): ?>
              <span style="font-size:11px;font-weight:500;color:var(--text-muted);margin-left:6px">(<?= count($pub_list) ?> dari <?= $total_pub ?> total)</span>
            <?php endif; ?>
          </span>
          <a href="modules/publikasi/upload.php" class="btn btn-primary btn-sm">
            + <?= $lang==='id' ? 'Upload Baru' : 'New Upload' ?>
          </a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($pub_list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= ic('inbox', 'style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id' ? 'Belum ada pengajuan.' : 'No applications yet.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></th>
                  <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                  <th><?= $lang==='id'?'Tgl Upload':'Date' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($pub_list as $p): ?>
                <tr>
                  <td style="max-width:220px">
                    <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($p['judul_publikasi']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($p['judul_publikasi'], 0, 48, '…')) ?>
                    </div>
                    <?php if($p['nama_jurnal_penerbit']): ?>
                    <div style="font-size:11px;color:#64748b"><?= htmlspecialchars(mb_strimwidth($p['nama_jurnal_penerbit'],0,36,'…')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge badge-process" style="font-size:11px"><?= labelJenisPublikasi($p['jenis_publikasi']) ?></span></td>
                  <td style="white-space:nowrap;font-size:12px;color:#64748b"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
                  <td><?= badgeStatus($p['status']) ?></td>
                  <td style="white-space:nowrap">
                    <button onclick="openDetail('pb-<?= $p['id'] ?>')" class="btn btn-outline btn-sm" style="margin-right:4px">
                      <?= ic('eye') ?> <?= $lang==='id'?'Lihat':'View' ?>
                    </button>
                    <?php if ($p['surat_id']): ?>
                      <a href="modules/publikasi/unduh_surat.php?id=<?= $p['surat_id'] ?>" class="btn btn-success btn-sm">
                        <?= ic('download') ?>
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; // end !$is_dosen publikasi ?>

      <!-- ── Riwayat Ethical Clearance (dosen; mahasiswa belum punya akses) ── -->
      <?php if ($is_dosen): ?>
      <div class="card" style="margin-top:20px" id="sec-ec">
        <div class="card-header">
          <span class="card-title">
            <?= ic('clipboard') ?> <?= $lang==='id' ? 'Riwayat Permohonan Ethical Clearance' : 'Ethical Clearance Application History' ?>
          </span>
          <a href="modules/ethical_clearance/upload.php" class="btn btn-primary btn-sm">
            + <?= $lang==='id' ? 'Ajukan Baru' : 'New Application' ?>
          </a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($ec_list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= ic('inbox','style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id' ? 'Belum ada permohonan.' : 'No applications yet.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Judul Penelitian':'Research Title' ?></th>
                  <th><?= $lang==='id'?'Jurnal Target':'Target Journal' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Surat':'Letter' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ec_list as $ec):
                  $badge = match($ec['status']) {
                    'disetujui' => 'badge-success',
                    'ditolak'   => 'badge-danger',
                    'diproses'  => 'badge-info',
                    default     => 'badge-warning',
                  };
                  $label_st = match($ec['status']) {
                    'disetujui'=>($lang==='id'?'Disetujui':'Approved'),
                    'ditolak'  =>($lang==='id'?'Ditolak':'Rejected'),
                    'diproses' =>($lang==='id'?'Diproses':'In Review'),
                    default    =>($lang==='id'?'Menunggu':'Pending'),
                  };
                ?>
                <tr>
                  <td style="max-width:260px;font-size:13px" title="<?= htmlspecialchars($ec['judul_penelitian']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($ec['judul_penelitian'], 0, 60, '…')) ?>
                  </td>
                  <td style="font-size:12px;color:var(--text-muted)">
                    <?= htmlspecialchars($ec['nama_jurnal'] ?: '—') ?>
                  </td>
                  <td style="font-size:12px;white-space:nowrap">
                    <?= date('d/m/Y', strtotime($ec['created_at'])) ?>
                  </td>
                  <td><span class="badge <?= $badge ?>"><?= $label_st ?></span></td>
                  <td>
                    <?php if ($ec['status'] === 'disetujui' && ($ec['nomor_surat'] || $ec['file_surat_signed'])): ?>
                      <?php $hasSigned = !empty($ec['file_surat_signed']) && file_exists(BASE_PATH.'/'.$ec['file_surat_signed']); ?>
                      <a href="modules/ethical_clearance/unduh_surat.php?id=<?= $ec['id'] ?>" target="_blank"
                         class="btn btn-sm" style="background:<?= $hasSigned ? '#dcfce7' : '#f0fdf4' ?>;color:#166534;border:1px solid <?= $hasSigned ? '#86efac' : '#bbf7d0' ?>;font-weight:600;gap:4px;display:inline-flex;align-items:center"
                         title="<?= $hasSigned ? ($lang==='id'?'Surat bertandatangan & berstempel':'Signed & stamped letter') : ($lang==='id'?'Surat digital (belum bertandatangan)':'Digital letter (not yet signed)') ?>">
                        <?= ic('download','style="width:12px;height:12px"') ?>
                        <?php if ($hasSigned): ?>
                          <?= $lang==='id'?'Unduh Surat':'Download' ?>
                          <span style="font-size:9px;background:#16a34a;color:#fff;padding:1px 5px;border-radius:8px;margin-left:2px">TTD</span>
                        <?php else: ?>
                          <?= $lang==='id'?'Unduh Surat':'Download' ?>
                        <?php endif; ?>
                      </a>
                    <?php else: ?>
                      <span style="font-size:12px;color:#94a3b8">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; // EC dosen only ?>

      <!-- ── Pengingat Aktif Peneliti (kontrak siap TTD / deadline laporan) ── -->
      <?php if ($is_dosen && !empty($pengingat_list)): ?>
      <div style="margin-top:20px;padding:16px 18px;border-radius:14px;
                  background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);
                  border:1.5px solid #fde68a;box-shadow:0 4px 14px rgba(217,119,6,.12)" id="sec-pengingat">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:11px">
          <div style="width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#d97706,#f59e0b);
                      display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <?= ic('bell','style="width:16px;height:16px;color:#fff"') ?>
          </div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:800;color:#92400e">
              <?= $lang==='id'?'Pengingat Aktif Peneliti':'Active Researcher Reminders' ?>
            </div>
            <div style="font-size:11.5px;color:#a16207">
              <?= $lang==='id'?'Tindakan yang perlu Anda lakukan terkait kontrak & laporan':'Actions required for your contract & report' ?>
            </div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px">
        <?php foreach ($pengingat_list as $pg):
          $dl_ts = !empty($pg['deadline_laporan']) ? strtotime($pg['deadline_laporan']) : 0;
          $sisa = $dl_ts ? ($dl_ts - time()) : 0;
          $dl_lewat = $dl_ts && $sisa <= 0;
          $dl_urgent = $dl_ts && $sisa > 0 && $sisa < 86400 * 7;
          $sudah_lapor = !empty($pg['lap_id']);
        ?>
          <div style="background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:11px 13px;display:flex;gap:11px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:240px">
              <div style="font-size:12.5px;font-weight:700;color:#1e293b;line-height:1.35;margin-bottom:3px">
                <?php $modul_pg = $pg['modul'] ?? 'penelitian'; ?>
                <span style="background:<?= $modul_pg==='pengabdian'?'#faf5ff':'#eff6ff' ?>;color:<?= $modul_pg==='pengabdian'?'#7c3aed':'#1d4ed8' ?>;font-size:9.5px;font-weight:800;padding:2px 6px;border-radius:4px;margin-right:5px;text-transform:uppercase;letter-spacing:.3px;vertical-align:1px">
                  <?= $modul_pg==='pengabdian'?'PkM':'Penelitian' ?>
                </span>
                <?= htmlspecialchars(mb_strimwidth($pg['judul'], 0, 80, '…')) ?>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px;color:#64748b">
                <?php if ($pg['nomor_kontrak']): ?>
                <span><?= ic('doc','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?> <?= htmlspecialchars($pg['nomor_kontrak']) ?></span>
                <?php endif; ?>
                <?php if ($pg['status'] === 'penandatanganan_kontrak'): ?>
                <span style="background:#fef3c7;color:#92400e;font-weight:700;padding:1px 8px;border-radius:5px">
                  <?= $lang==='id'?'PERLU TANDA TANGAN':'NEEDS SIGNATURE' ?>
                </span>
                <?php elseif ($pg['status'] === 'kontrak_aktif'): ?>
                <span style="background:#dcfce7;color:#15803d;font-weight:700;padding:1px 8px;border-radius:5px">
                  <?= $lang==='id'?'KONTRAK AKTIF':'ACTIVE' ?>
                </span>
                <?php endif; ?>
                <?php if ($dl_ts): ?>
                <span style="color:<?= $dl_lewat?'#dc2626':($dl_urgent?'#9a3412':'#15803d') ?>;font-weight:600">
                  <?= ic('clock','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
                  <?= $lang==='id'?'Batas laporan':'Report due' ?>: <?= date('d M Y · H:i', $dl_ts) ?>
                  <?php if ($dl_lewat): ?> — <?= $lang==='id'?'TERLEWAT':'OVERDUE' ?><?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ($sudah_lapor): ?>
                <span style="color:#15803d;font-weight:600">
                  ✓ <?= $lang==='id'?'Laporan sudah diunggah':'Report uploaded' ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if (!empty($pg['file_kontrak'])): ?>
              <a href="<?= BASE_URL ?>/<?= htmlspecialchars($pg['file_kontrak']) ?>" target="_blank"
                 class="btn btn-outline btn-sm" style="font-size:11.5px;padding:5px 10px">
                <?= ic('download','style="width:12px;height:12px"') ?> <?= $lang==='id'?'Kontrak':'Contract' ?>
              </a>
              <?php endif; ?>
              <?php if ($pg['status'] === 'kontrak_aktif' && !$sudah_lapor):
                $modul_dir = ($pg['modul'] ?? 'penelitian') === 'pengabdian' ? 'pengabdian' : 'penelitian';
              ?>
              <a href="<?= BASE_URL ?>/modules/<?= $modul_dir ?>/laporan.php" class="btn btn-primary btn-sm"
                 style="font-size:11.5px;padding:5px 11px;background:linear-gradient(135deg,#d97706,#f59e0b);border:none">
                <?= ic('upload','style="width:12px;height:12px"') ?> <?= $lang==='id'?'Upload Laporan':'Upload Report' ?>
              </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:11px;padding:8px 11px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:11px;color:#9a3412;display:flex;gap:7px;align-items:flex-start">
          <?= ic('alert','style="width:13px;height:13px;flex-shrink:0;margin-top:1px"') ?>
          <span><?= $lang==='id'
            ? '<strong>Pengingat:</strong> Sesuai juknis LPPM, laporan akhir <strong>hardcopy</strong> wajib diserahkan ke kantor LPPM IAKN Toraja sesuai batas waktu kontrak. Upload digital tidak menggantikan kewajiban hardcopy.'
            : '<strong>Reminder:</strong> Per LPPM regulations, the <strong>hardcopy</strong> final report must be submitted to the LPPM IAKN Toraja office by the contract deadline. Digital upload does not replace the hardcopy obligation.' ?></span>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Pengingat Luaran Penelitian (3-bulanan) ───────────── -->
      <?php if ($is_dosen && !empty($luaran_reminders)):
        // Group per usulan_id untuk tampilan ringkas
        $grp = [];
        foreach ($luaran_reminders as $lr) {
          $grp[$lr['usulan_id']]['judul']       = $lr['judul'];
          $grp[$lr['usulan_id']]['skema']       = $lr['skema'];
          $grp[$lr['usulan_id']]['deadline']    = $lr['deadline_laporan'];
          $grp[$lr['usulan_id']]['bulan_batas'] = $lr['batas_luaran_bulan'];
          $grp[$lr['usulan_id']]['wajib']       = $lr['jenis_luaran_wajib'];
          $grp[$lr['usulan_id']]['reminders'][] = $lr;
        }
        $any_overdue = false;
        $any_final   = false;
        foreach ($luaran_reminders as $lr) {
          if ((int)$lr['period_no'] === -1) $any_overdue = true;
          if ((int)$lr['period_no'] === 0)  $any_final = true;
        }
        $banner_bg = $any_overdue ? 'linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%)' : ($any_final ? 'linear-gradient(135deg,#fff7ed 0%,#fed7aa 100%)' : 'linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%)');
        $banner_border = $any_overdue ? '#fecaca' : ($any_final ? '#fed7aa' : '#bbf7d0');
        $banner_text   = $any_overdue ? '#991b1b' : ($any_final ? '#9a3412' : '#14532d');
      ?>
      <div style="margin-top:20px;padding:16px 18px;border-radius:14px;background:<?= $banner_bg ?>;
                  border:1.5px solid <?= $banner_border ?>;box-shadow:0 4px 14px rgba(0,0,0,.06)" id="sec-luaran-pengingat">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:11px">
          <div style="width:34px;height:34px;border-radius:9px;background:<?= $any_overdue?'linear-gradient(135deg,#991b1b,#dc2626)':($any_final?'linear-gradient(135deg,#9a3412,#ea580c)':'linear-gradient(135deg,#14532d,#16a34a)') ?>;
                      display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <?= ic('award','style="width:16px;height:16px;color:#fff"') ?>
          </div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:800;color:<?= $banner_text ?>">
              <?= $any_overdue
                ? ($lang==='id'?'⚠ Luaran Penelitian LEWAT Batas':'⚠ Research Output OVERDUE')
                : ($any_final
                  ? ($lang==='id'?'Batas Akhir Luaran Sudah Dekat':'Output Deadline Near')
                  : ($lang==='id'?'Pengingat Luaran Penelitian':'Research Output Reminder')) ?>
            </div>
            <div style="font-size:11.5px;color:<?= $banner_text ?>;opacity:.85">
              <?= $lang==='id'?'Bukti luaran wajib dilaporkan ke LPPM. Silakan submit di menu Luaran Penelitian.':'Output evidence must be reported. Submit via Research Output menu.' ?>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/modules/penelitian/luaran.php" class="btn btn-primary" style="font-size:12px;padding:6px 12px;background:<?= $any_overdue?'#dc2626':($any_final?'#ea580c':'#16a34a') ?>;border:none;flex-shrink:0">
            <?= ic('upload','style="width:12px;height:12px"') ?> <?= $lang==='id'?'Submit Luaran':'Submit Output' ?>
          </a>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($grp as $uid_g => $g):
          $dl_lap_ts = $g['deadline'] ? strtotime($g['deadline']) : 0;
          $dl_lua_ts = 0;
          if ($dl_lap_ts && $g['bulan_batas']) {
            $dl_lua_ts = strtotime('+' . (int)$g['bulan_batas'] . ' months', $dl_lap_ts);
          }
          // Reminder terbaru
          $latest = $g['reminders'][0];
          $period_no = (int)$latest['period_no'];
        ?>
          <div style="background:#fff;border:1px solid <?= $banner_border ?>;border-radius:10px;padding:10px 13px;
                      display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
              <div style="font-size:12.5px;font-weight:700;color:#1e293b;line-height:1.35">
                <?= htmlspecialchars(mb_strimwidth($g['judul'], 0, 90, '…')) ?>
              </div>
              <div style="font-size:11px;color:#64748b;margin-top:3px;display:flex;gap:8px;flex-wrap:wrap">
                <span style="font-weight:700;color:<?= $g['skema']==='nasional'?'#15803d':'#0369a1' ?>"><?= strtoupper($g['skema']) ?></span>
                <?php if ($dl_lua_ts): ?>
                <span style="color:<?= $dl_lua_ts < time() ? '#dc2626' : '#15803d' ?>;font-weight:600">
                  <?= ic('clock','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
                  <?= $lang==='id'?'Batas Luaran':'Output Due' ?>: <?= date('d M Y', $dl_lua_ts) ?>
                </span>
                <?php endif; ?>
                <span style="background:<?= $period_no === -1 ? '#fee2e2' : ($period_no === 0 ? '#fef3c7' : '#dbeafe') ?>;
                             color:<?= $period_no === -1 ? '#991b1b' : ($period_no === 0 ? '#92400e' : '#1d4ed8') ?>;
                             font-weight:700;padding:1px 7px;border-radius:4px;font-size:10px">
                  <?= $period_no === -1
                    ? ($lang==='id'?'TERLEWAT':'OVERDUE')
                    : ($period_no === 0
                      ? ($lang==='id'?'H-14':'H-14')
                      : ($lang==='id'?'Periode '.$period_no:'Period '.$period_no)) ?>
                </span>
              </div>
              <?php if ($g['wajib']): ?>
              <div style="margin-top:5px;font-size:11px;color:#64748b">
                <strong><?= $lang==='id'?'Luaran wajib:':'Required:' ?></strong> <?= htmlspecialchars($g['wajib']) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <!-- Popup modal pengingat luaran (muncul sekali per session jika ada reminder baru) -->
      <div id="luaranPopup" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.65);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
        <div style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 25px 70px rgba(0,0,0,.35);animation:popIn .25s ease">
          <div style="padding:18px 22px;background:<?= $any_overdue?'linear-gradient(135deg,#991b1b,#dc2626)':($any_final?'linear-gradient(135deg,#9a3412,#ea580c)':'linear-gradient(135deg,#14532d,#16a34a)') ?>;color:#fff">
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:42px;height:42px;border-radius:11px;background:rgba(255,255,255,.18);
                          display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid rgba(255,255,255,.25)">
                <?= ic('award','style="width:20px;height:20px;color:#fff"') ?>
              </div>
              <div>
                <div style="font-size:15px;font-weight:800">
                  <?= $lang==='id'?'Pengingat Luaran Penelitian':'Research Output Reminder' ?>
                </div>
                <div style="font-size:11.5px;opacity:.85;margin-top:2px">
                  <?= count($luaran_reminders) ?> <?= $lang==='id'?'pengingat perlu perhatian Anda':'reminders need your attention' ?>
                </div>
              </div>
            </div>
          </div>
          <div style="padding:18px 22px">
            <p style="font-size:13px;color:#475569;line-height:1.6;margin:0 0 14px">
              <?= $lang==='id'
                ? 'Anda memiliki <strong>kewajiban luaran penelitian</strong> (artikel jurnal, prosiding, dll.) yang belum diverifikasi LPPM. Pengingat ini dikirim setiap <strong>3 bulan</strong> sampai batas akhir luaran.'
                : 'You have <strong>research output obligations</strong> (articles, proceedings, etc.) not yet verified by LPPM. Reminders are sent <strong>every 3 months</strong> until the deadline.' ?>
            </p>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
              <?php foreach (array_slice($luaran_reminders, 0, 4) as $lr):
                $pno = (int)$lr['period_no'];
                $ic_color = $pno === -1 ? '#dc2626' : ($pno === 0 ? '#ea580c' : '#1d4ed8');
                $ic_bg    = $pno === -1 ? '#fef2f2' : ($pno === 0 ? '#fff7ed' : '#eff6ff');
              ?>
              <div style="background:<?= $ic_bg ?>;border:1px solid <?= $ic_color ?>33;border-radius:9px;padding:10px 12px;display:flex;gap:9px;align-items:flex-start">
                <div style="width:24px;height:24px;border-radius:6px;background:<?= $ic_color ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <?= ic('clock','style="width:12px;height:12px;color:'.$ic_color.'"') ?>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:12px;font-weight:700;color:#1e293b;line-height:1.3">
                    <?= htmlspecialchars(mb_strimwidth($lr['judul'], 0, 75, '…')) ?>
                  </div>
                  <div style="font-size:11px;color:<?= $ic_color ?>;margin-top:2px;font-weight:600">
                    <?= $pno === -1
                      ? ($lang==='id'?'⚠ Batas luaran telah terlewati':'⚠ Output deadline passed')
                      : ($pno === 0
                        ? ($lang==='id'?'H-14 batas akhir luaran':'14 days until deadline')
                        : ($lang==='id'?'Sudah '.($pno*3).' bulan sejak batas laporan':'Already '.($pno*3).' months since report deadline')) ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              <?php if (count($luaran_reminders) > 4): ?>
              <div style="font-size:11.5px;color:#64748b;text-align:center">
                + <?= count($luaran_reminders) - 4 ?> <?= $lang==='id'?'pengingat lainnya':'more reminders' ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <div style="padding:14px 22px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end;background:#f8fafc">
            <button onclick="lrDismiss()" style="padding:9px 16px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:12.5px;font-weight:600;cursor:pointer">
              <?= $lang==='id'?'Nanti Saja':'Later' ?>
            </button>
            <a href="<?= BASE_URL ?>/modules/penelitian/luaran.php" onclick="lrMarkRead()"
               style="padding:9px 18px;border-radius:9px;background:linear-gradient(135deg,#4a1d96,#7c3aed);color:#fff;font-size:12.5px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
              <?= ic('upload','style="width:13px;height:13px"') ?> <?= $lang==='id'?'Submit Luaran Sekarang':'Submit Now' ?>
            </a>
          </div>
        </div>
      </div>
      <style>@keyframes popIn { from { transform:scale(.94);opacity:0 } to { transform:scale(1);opacity:1 } }</style>
      <script>
        (function(){
          // Tampilkan popup sekali per session
          if (sessionStorage.getItem('lr_popup_shown') === '1') return;
          const ids = <?= json_encode(array_map(fn($r) => (int)$r['id'], $luaran_reminders)) ?>;
          if (!ids.length) return;
          const el = document.getElementById('luaranPopup');
          el.style.display = 'flex';
          sessionStorage.setItem('lr_popup_shown', '1');
          window._lrIds = ids;
        })();
        function lrDismiss(){
          document.getElementById('luaranPopup').style.display='none';
        }
        function lrMarkRead(){
          // Mark ALL as read via beacon (navigasi tetap lanjut)
          const body = new FormData();
          (window._lrIds || []).forEach(id => body.append('ids[]', id));
          const url = <?= json_encode(BASE_URL . '/modules/penelitian/luaran_reminder_dismiss.php') ?>;
          navigator.sendBeacon ? navigator.sendBeacon(url, body) : fetch(url, {method:'POST', body});
        }
      </script>
      <?php endif; ?>

      <!-- ── Riwayat Usulan Penelitian (dosen only) ───────────── -->
      <?php if ($is_dosen): ?>
      <div class="card" style="margin-top:20px" id="sec-penelitian">
        <div class="card-header">
          <span class="card-title">
            <?= ic('edit') ?> <?= $lang==='id' ? 'Riwayat Usulan Penelitian' : 'Research Proposal History' ?>
            <?php if ($total_penelitian > count($penelitian_list)): ?>
              <span style="font-size:11px;font-weight:500;color:var(--text-muted);margin-left:6px">(<?= count($penelitian_list) ?> dari <?= $total_penelitian ?> total)</span>
            <?php endif; ?>
          </span>
          <a href="<?= BASE_URL ?>/modules/penelitian/index.php" class="btn btn-primary btn-sm">
            + <?= $lang==='id' ? 'Ajukan Baru' : 'New Proposal' ?>
          </a>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($penelitian_list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= ic('inbox','style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id' ? 'Belum ada usulan penelitian.' : 'No research proposals yet.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th><?= $lang==='id'?'Judul Penelitian':'Research Title' ?></th>
                  <th><?= $lang==='id'?'Skema':'Scheme' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($penelitian_list as $pt):
                  $pen_badge = match($pt['status']) {
                    'disetujui','kontrak_aktif','laporan_diterima','selesai'  => 'badge-success',
                    'ditolak','gagal_admin'                                    => 'badge-danger',
                    'revisi_minor','revisi_mayor','direvisi',
                    'perbaikan_admin','perbaikan_substantif'                   => 'badge-wait',
                    'ditinjau','diajukan','seleksi_admin','lolos_admin',
                    'seleksi_substansi','penandatanganan_kontrak'              => 'badge-process',
                    default                                                    => 'badge-wait',
                  };
                  $pen_label = match($pt['status']) {
                    'disetujui'              => ($lang==='id'?'Disetujui':'Approved'),
                    'penandatanganan_kontrak'=> ($lang==='id'?'Tanda Tangan Kontrak':'Contract Signing'),
                    'kontrak_aktif'          => ($lang==='id'?'Kontrak Aktif':'Active'),
                    'laporan_diterima'       => ($lang==='id'?'Laporan Diterima':'Report Accepted'),
                    'selesai'                => ($lang==='id'?'Selesai':'Completed'),
                    'ditolak','gagal_admin'  => ($lang==='id'?'Ditolak':'Rejected'),
                    'revisi_minor'           => ($lang==='id'?'Revisi Minor':'Minor Revision'),
                    'revisi_mayor'           => ($lang==='id'?'Revisi Mayor':'Major Revision'),
                    'perbaikan_admin'        => ($lang==='id'?'Perbaikan Admin':'Admin Revision'),
                    'perbaikan_substantif'   => ($lang==='id'?'Perbaikan Substantif':'Substantive Revision'),
                    'seleksi_admin'          => ($lang==='id'?'Seleksi Admin':'Admin Review'),
                    'lolos_admin'            => ($lang==='id'?'Lolos Admin':'Admin Passed'),
                    'seleksi_substansi'      => ($lang==='id'?'Seleksi Substantif':'Substantive Review'),
                    'ditinjau'               => ($lang==='id'?'Ditinjau':'In Review'),
                    'diajukan'               => ($lang==='id'?'Diajukan':'Submitted'),
                    default                  => ($lang==='id'?'Draft':'Draft'),
                  };
                  $skema_labels = [
                    'pkm'    => 'PKM',
                    'pdm'    => 'PDM',
                    'pdupt'  => 'PDUPT',
                    'phb'    => 'PHB',
                    'ppp'    => 'PPP',
                    'ptnbh'  => 'PTNBH',
                  ];
                  $skema_label = $skema_labels[$pt['skema']] ?? strtoupper($pt['skema']);
                ?>
                <tr>
                  <td style="max-width:260px">
                    <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($pt['judul']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($pt['judul'] ?? '(tanpa judul)', 0, 60, '…')) ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge badge-process" style="font-size:11px"><?= htmlspecialchars($skema_label) ?></span>
                  </td>
                  <td style="white-space:nowrap;font-size:12px;color:#64748b">
                    <?= date('d/m/Y', strtotime($pt['created_at'])) ?>
                  </td>
                  <td><span class="badge <?= $pen_badge ?>"><?= $pen_label ?></span></td>
                  <td style="white-space:nowrap">
                    <a href="<?= BASE_URL ?>/modules/penelitian/index.php" class="btn btn-outline btn-sm">
                      <?= ic('eye') ?> <?= $lang==='id'?'Lihat':'View' ?>
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
      <?php endif; // end $is_dosen penelitian ?>

    </div><!-- end page-content -->
  </div><!-- end main-content -->
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Detail Drawer Overlay                                       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="detail-overlay" onclick="if(event.target===this)closeDetail()">
  <div id="detail-drawer">
    <div class="drawer-head">
      <h3 id="drawer-title"><?= $lang==='id'?'Detail Pengajuan':'Submission Detail' ?></h3>
      <button class="drawer-close" onclick="closeDetail()">
        <svg style="width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="drawer-body" id="drawer-body"></div>
  </div>
</div>

<!-- ── Pre-rendered detail plagiasi (hidden, mahasiswa only) ── -->
<?php if (!$is_dosen): foreach($skripsi_list as $s):
  $jTA = $s['jenis_tugas_akhir'] ?? 'skripsi';
  switch($jTA){ case 'tesis': $taLbl='Tesis'; break; case 'disertasi': $taLbl='Disertasi'; break; default: $taLbl='Skripsi'; }
  $simSafe = $s['similarity_score'] !== null && $s['similarity_score'] <= $batas_sim;
  $aiSafe  = $s['ai_score'] !== null  && $s['ai_score'] !== '' && $s['ai_score'] <= $batas_ai;
  $aiPlatform = !empty($s['platform_ai']) ? ucwords(str_replace(['-','_'],' ',$s['platform_ai'])) . ' AI Detector' : 'Quillbot AI Detector';
?>
<div id="pl-<?= $s['id'] ?>" style="display:none">
  <!-- Jenis + status -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <span style="background:var(--primary-xlight);color:var(--primary);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700"><?= $taLbl ?></span>
    <?= badgeStatus($s['status']) ?>
    <?php if($s['nomor_surat']): ?>
      <span style="background:#f0fdf4;color:#15803d;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600"><?= htmlspecialchars($s['nomor_surat']) ?></span>
    <?php endif; ?>
  </div>

  <!-- Judul -->
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Judul '.$taLbl:'Title' ?></div>
    <div style="font-size:13px;font-weight:600;line-height:1.5;color:var(--text-primary)"><?= htmlspecialchars($s['judul_skripsi']) ?></div>
  </div>

  <!-- Info akademik -->
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Informasi Akademik':'Academic Info' ?></div>
    <div class="detail-grid">
      <?php if(!empty($s['nama_pembimbing1'])): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $jTA==='disertasi'?'Promotor':($jTA==='tesis'?'Pembimbing Utama':'Pembimbing I') ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($s['nama_pembimbing1']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($s['nama_pembimbing2'])): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $jTA==='disertasi'?'Ko-Promotor 1':($jTA==='tesis'?'Pembimbing Pendamping':'Pembimbing II') ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($s['nama_pembimbing2']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($s['nama_pembimbing3'])): ?>
      <div class="detail-item">
        <div class="detail-item-label">Ko-Promotor 2</div>
        <div class="detail-item-value"><?= htmlspecialchars($s['nama_pembimbing3']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($s['tahun_sidang'])): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $lang==='id'?'Tahun Sidang':'Defense Year' ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($s['tahun_sidang']) ?></div>
      </div>
      <?php endif; ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $lang==='id'?'Tanggal Pengajuan':'Submitted' ?></div>
        <div class="detail-item-value"><?= formatTanggal($s['created_at']) ?></div>
      </div>
      <?php if($s['tanggal_cek']): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $lang==='id'?'Tanggal Dicek':'Checked On' ?></div>
        <div class="detail-item-value"><?= formatTanggal($s['tanggal_cek']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Hasil pengecekan -->
  <?php if($s['similarity_score'] !== null): ?>
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Hasil Pengecekan':'Check Results' ?></div>
    <div class="score-pair">
      <div class="score-box" style="background:<?= $simSafe?'#f0fdf4':'#fef2f2' ?>;border:1px solid <?= $simSafe?'#86efac':'#fca5a5' ?>">
        <div class="num" style="color:<?= $simSafe?'#15803d':'#dc2626' ?>"><?= $s['similarity_score'] ?>%</div>
        <div class="lbl" style="color:<?= $simSafe?'#15803d':'#dc2626' ?>">Similarity</div>
        <div style="font-size:10px;color:#94a3b8;margin-top:2px">batas <?= $batas_sim ?>%</div>
      </div>
      <?php if($s['ai_score'] !== null && $s['ai_score'] !== ''): ?>
      <div class="score-box" style="background:<?= $aiSafe?'#f0fdf4':'#fef2f2' ?>;border:1px solid <?= $aiSafe?'#86efac':'#fca5a5' ?>">
        <div class="num" style="color:<?= $aiSafe?'#15803d':'#dc2626' ?>"><?= $s['ai_score'] ?>%</div>
        <div class="lbl" style="color:<?= $aiSafe?'#15803d':'#dc2626' ?>">Deteksi AI</div>
        <div style="font-size:10px;color:#94a3b8;margin-top:2px">batas <?= $batas_ai ?>% · <?= htmlspecialchars($aiPlatform) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Catatan admin jika ditolak -->
  <?php if(!empty($s['catatan'])): ?>
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Catatan Admin':'Admin Notes' ?></div>
    <div class="catatan-box"><?= nl2br(htmlspecialchars($s['catatan'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Unduh surat -->
  <?php if($s['surat_id']): ?>
  <div style="margin-top:4px">
    <a href="modules/plagiasi/unduh_surat.php?id=<?= $s['surat_id'] ?>" class="btn btn-success" style="width:100%;justify-content:center">
      <?= ic('download') ?> <?= $lang==='id'?'Unduh Surat Keterangan':'Download Certificate' ?>
    </a>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; endif; // end !$is_dosen plagiasi detail ?>

<!-- ── Pre-rendered detail publikasi (hidden, mahasiswa only) ── -->
<?php if (!$is_dosen): foreach($pub_list as $p):
  $jenis = $p['jenis_publikasi'] ?? 'jurnal';
  $isBuku = in_array($jenis, ['buku','book_chapter']);
  $isBC   = $jenis === 'book_chapter';
?>
<div id="pb-<?= $p['id'] ?>" style="display:none">
  <!-- Jenis + status -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <span style="background:#f5f3ff;color:#6d28d9;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700"><?= labelJenisPublikasi($jenis) ?></span>
    <?= badgeStatus($p['status']) ?>
    <?php if($p['nomor_surat']): ?>
      <span style="background:#f0fdf4;color:#15803d;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600"><?= htmlspecialchars($p['nomor_surat']) ?></span>
    <?php endif; ?>
  </div>

  <!-- Judul -->
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></div>
    <div style="font-size:13px;font-weight:600;line-height:1.5;color:var(--text-primary)"><?= htmlspecialchars($p['judul_publikasi']) ?></div>
  </div>

  <!-- Info publikasi -->
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Detail Publikasi':'Publication Details' ?></div>
    <div class="detail-grid">
      <?php if(!empty($p['nama_jurnal_penerbit'])): ?>
      <div class="detail-item full">
        <div class="detail-item-label"><?= $isBuku?'Penerbit':($jenis==='prosiding'?'Nama Konferensi':'Nama Jurnal') ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($p['nama_jurnal_penerbit']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($p['issn_isbn'])): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $isBuku?'ISBN':'ISSN/ISBN' ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($p['issn_isbn']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($p['tahun_terbit'])): ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $lang==='id'?'Tahun Terbit':'Pub. Year' ?></div>
        <div class="detail-item-value"><?= htmlspecialchars($p['tahun_terbit']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!$isBuku && !empty($p['akreditasi_jurnal'])): ?>
      <div class="detail-item">
        <div class="detail-item-label">Akreditasi</div>
        <div class="detail-item-value"><?= htmlspecialchars($p['akreditasi_jurnal']) ?></div>
      </div>
      <?php endif; ?>
      <?php if(!empty($p['url_doi'])): ?>
      <div class="detail-item <?= $isBuku?'':'full' ?>">
        <div class="detail-item-label"><?= $isBuku?($lang==='id'?'Kota Penerbit':'Publisher City'):'DOI / URL' ?></div>
        <div class="detail-item-value" style="word-break:break-all">
          <?php if(!$isBuku): ?>
            <a href="<?= htmlspecialchars($p['url_doi']) ?>" target="_blank" style="color:var(--primary)"><?= htmlspecialchars($p['url_doi']) ?></a>
          <?php else: ?>
            <?= htmlspecialchars($p['url_doi']) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if($isBC && !empty($p['nomor_bab'])): ?>
      <div class="detail-item">
        <div class="detail-item-label">Nomor Bab</div>
        <div class="detail-item-value"><?= htmlspecialchars($p['nomor_bab']) ?></div>
      </div>
      <?php endif; ?>
      <?php if($isBC && !empty($p['editor_buku'])): ?>
      <div class="detail-item">
        <div class="detail-item-label">Editor</div>
        <div class="detail-item-value"><?= htmlspecialchars($p['editor_buku']) ?></div>
      </div>
      <?php endif; ?>
      <div class="detail-item">
        <div class="detail-item-label"><?= $lang==='id'?'Tanggal Pengajuan':'Submitted' ?></div>
        <div class="detail-item-value"><?= formatTanggal($p['created_at']) ?></div>
      </div>
    </div>
  </div>

  <!-- Catatan admin -->
  <?php if(!empty($p['catatan_admin'])): ?>
  <div class="detail-section">
    <div class="detail-section-title"><?= $lang==='id'?'Catatan Admin':'Admin Notes' ?></div>
    <div class="catatan-box"><?= nl2br(htmlspecialchars($p['catatan_admin'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Unduh surat -->
  <?php if($p['surat_id']): ?>
  <div style="margin-top:4px">
    <a href="modules/publikasi/unduh_surat.php?id=<?= $p['surat_id'] ?>" class="btn btn-success" style="width:100%;justify-content:center">
      <?= ic('download') ?> <?= $lang==='id'?'Unduh Surat Keterangan':'Download Certificate' ?>
    </a>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; endif; // end !$is_dosen publikasi detail ?>

<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}
function openDetail(id) {
  const src = document.getElementById(id);
  if (!src) return;
  document.getElementById('drawer-body').innerHTML = src.innerHTML;
  document.getElementById('detail-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDetail() {
  document.getElementById('detail-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeDetail(); });
</script>
</body>
</html>
