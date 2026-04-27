<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');
if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';
$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

/* ── POST: Upload laporan ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_laporan') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid) {
        // Verifikasi proposal milik user dan status disetujui
        $chk = $pdo->prepare("SELECT id,judul,status FROM usulan_pengabdian WHERE id=? AND user_id=? AND deleted_at IS NULL");
        $chk->execute([$pid, $uid]);
        $prop = $chk->fetch();

        if (!$prop || !in_array($prop['status'], ['disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai'], true)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id
                ? 'Proposal tidak valid atau belum disetujui / belum berkontrak aktif.'
                : 'Invalid proposal or not yet approved / contract not active.'];
            redirect('/modules/pengabdian/laporan.php');
        }

        // Cek sudah ada laporan sebelumnya yang diterima
        $existing = $pdo->prepare("SELECT id,status,round_ke FROM laporan_pengabdian WHERE usulan_id=?");
        $existing->execute([$pid]);
        $lp = $existing->fetch();
        if ($lp && $lp['status'] === 'diterima') {
            $_SESSION['flash'] = ['type'=>'warning','msg'=>$id
                ? 'Laporan sudah diterima oleh LPPM.'
                : 'Report has already been accepted by LPPM.'];
            redirect('/modules/pengabdian/laporan.php');
        }
        // Status 'menunggu' tidak boleh diunggah ulang sebelum admin mereview
        if ($lp && $lp['status'] === 'menunggu') {
            $_SESSION['flash'] = ['type'=>'warning','msg'=>$id
                ? 'Laporan Anda sedang menunggu verifikasi LPPM. Tunggu keputusan sebelum upload ulang.'
                : 'Your report is awaiting verification. Wait for decision before re-uploading.'];
            redirect('/modules/pengabdian/laporan.php');
        }

        // Upload file
        if (empty($_FILES['file_laporan']['name'])) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Silakan pilih file laporan.':'Please select a report file.'];
            redirect('/modules/pengabdian/laporan.php');
        }

        $file   = $_FILES['file_laporan'];
        $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf'];
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        
        if (!in_array($ext, $allowed) || $mime !== 'application/pdf' || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'File harus berformat PDF yang valid.':'File must be in valid PDF format.'];
            redirect('/modules/pengabdian/laporan.php');
        }
        if ($file['size'] > 30 * 1024 * 1024) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Ukuran file maksimal 30 MB.':'Maximum file size is 30 MB.'];
            redirect('/modules/pengabdian/laporan.php');
        }

        $dir = UPLOAD_PATH . 'laporan/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'laporan_' . $pid . '_' . time() . '.pdf';
        $fpath = $dir . $fname;

        if (!move_uploaded_file($file['tmp_name'], $fpath)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Gagal mengunggah file.':'Failed to upload file.'];
            redirect('/modules/pengabdian/laporan.php');
        }

        $rel_path = 'uploads/laporan/' . $fname;

        // Update / insert. JANGAN hapus file lama: tetap diarsipkan via laporan_pengabdian_revisi snapshot.
        if ($lp) {
            $next_round = (int)($lp['round_ke'] ?? 1) + ($lp['status'] === 'revisi' ? 1 : 0);
            $pdo->prepare("UPDATE laporan_pengabdian SET file_laporan=?,file_laporan_name=?,file_laporan_size=?,tanggal_submit=NOW(),status='menunggu',round_ke=?,catatan_admin=NULL,reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW() WHERE usulan_id=?")
                ->execute([$rel_path, $file['name'], $file['size'], $next_round, $pid]);
        } else {
            $pdo->prepare("INSERT INTO laporan_pengabdian (usulan_id,user_id,file_laporan,file_laporan_name,file_laporan_size,tanggal_submit,status,round_ke) VALUES (?,?,?,?,?,NOW(),'menunggu',1)")
                ->execute([$pid, $uid, $rel_path, $file['name'], $file['size']]);
        }

        // Notifikasi admin
        $admin_ids = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 LIMIT 3")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($admin_ids as $aid) {
            $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                ->execute([$aid,
                    $id?'Laporan Pengabdian Masuk':'Research Report Submitted',
                    ($id?'Laporan pengabdian telah diunggah oleh peneliti untuk proposal: ':'Research report uploaded for proposal: ')
                    . mb_strimwidth($prop['judul'],0,70,'…'),
                    'info']);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>$id
            ? 'Laporan berhasil diunggah dan menunggu verifikasi LPPM.'
            : 'Report uploaded successfully, awaiting LPPM verification.'];
        redirect('/modules/pengabdian/laporan.php');
    }
}

/* ── Load proposals disetujui milik user ─────────────────────── */
$props = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.reviewed_at,
           kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan,
           lp.id as lap_id, lp.file_laporan, lp.file_laporan_name,
           lp.tanggal_submit, lp.status as lap_status, lp.catatan_admin
    FROM usulan_pengabdian up
    LEFT JOIN kontrak_pengabdian kp ON kp.usulan_id = up.id
    LEFT JOIN laporan_pengabdian lp ON lp.usulan_id = up.id
    WHERE up.user_id=? AND up.tahun_anggaran=? AND up.deleted_at IS NULL
      AND up.status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')
    ORDER BY up.reviewed_at DESC
");
$props->execute([$uid, $tahun]);
$proposals = $props->fetchAll();

// Load history per proposal
$history_map = [];
if (!empty($proposals)) {
    $pids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($pids), '?'));
    try {
        $hq = $pdo->prepare("
            SELECT lr.*, u.nama_lengkap AS reviewer_nama
            FROM laporan_pengabdian_revisi lr
            LEFT JOIN users u ON u.id = lr.reviewed_by
            WHERE lr.usulan_id IN ($ph)
            ORDER BY lr.id DESC
        ");
        $hq->execute($pids);
        foreach ($hq->fetchAll() as $h) {
            $history_map[$h['usulan_id']][] = $h;
        }
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Laporan Pengabdian':'Research Report' ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.lap-card{background:var(--bg-card);border:1.5px solid var(--border);border-radius:14px;padding:20px;margin-bottom:16px}
.lap-card:hover{border-color:var(--primary);box-shadow:0 4px 20px rgba(37,99,235,.08)}
.lap-judul{font-size:14.5px;font-weight:700;line-height:1.4;margin-bottom:6px}
.lap-meta{font-size:12px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.lap-meta span{display:flex;align-items:center;gap:4px}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:700}
.timeline{display:flex;gap:0;margin:14px 0;overflow-x:auto}
.tl-step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:90px}
.tl-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;z-index:1}
.tl-line{width:100%;height:2px;margin-top:13px;flex:1}
.tl-lbl{font-size:10.5px;color:var(--text-muted);text-align:center;margin-top:6px;line-height:1.3}
.tl-wrap{display:flex;align-items:flex-start;width:100%}
.upload-area{border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:.2s;background:var(--bg-field)}
.upload-area:hover{border-color:var(--primary);background:#eff6ff}
.upload-area.dragover{border-color:var(--primary);background:#eff6ff}
.btn-upload{background:linear-gradient(135deg,#1e3a5f,#1565C0);color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
.btn-upload:hover{opacity:.9}
.info-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#0c4a6e;margin-bottom:16px}
.deadline-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:12px;color:#991b1b;margin-top:8px}
.deadline-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;font-size:12px;color:#14532d;margin-top:8px}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          <?= $id?'Laporan Pengabdian':'Research Report' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/modules/pengabdian/index.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
        </a>
        <button class="lang-toggle" onclick="toggleLang()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <?= $id?'EN':'ID' ?>
        </button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:16px">
        <?= htmlspecialchars($fl['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="info-box">
        <strong><?= $id?'Informasi:':'Information:' ?></strong>
        <?= $id
          ? 'Halaman ini untuk mengunggah <strong>laporan akhir pengabdian</strong> bagi proposal yang telah disetujui. Upload file PDF laporan lengkap (maks. 30 MB).'
          : 'This page is for uploading the <strong>final research report</strong> for approved proposals. Upload PDF file (max 30 MB).'
        ?>
      </div>

      <!-- ── Pengingat hardcopy laporan (#6) ── -->
      <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:11px;padding:13px 16px;margin-top:10px;margin-bottom:18px;display:flex;gap:11px;align-items:flex-start;font-size:12.5px;color:#9a3412">
        <?= ic('alert','style="width:18px;height:18px;flex-shrink:0;color:#ea580c"') ?>
        <div>
          <div style="font-weight:700;margin-bottom:4px">
            <?= $id?'Pengingat: Laporan Hardcopy Wajib':'Reminder: Hardcopy Report Required' ?>
          </div>
          <?= $id
            ? 'Sesuai juknis LPPM, laporan akhir <strong>hardcopy</strong> wajib diserahkan ke kantor LPPM IAKN Toraja paling lambat sesuai batas waktu yang tertera pada kartu kontrak Anda. Upload digital di halaman ini <strong>tidak menggantikan</strong> kewajiban penyerahan hardcopy.'
            : 'Per LPPM regulations, the final <strong>hardcopy</strong> report must be submitted to the LPPM IAKN Toraja office by the deadline shown on your contract. The digital upload here <strong>does not replace</strong> the hardcopy submission obligation.'
          ?>
        </div>
      </div>

      <?php if (empty($proposals)): ?>
      <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.4;margin-bottom:12px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <div style="font-size:14px;font-weight:600;margin-bottom:6px">
          <?= $id?'Belum ada proposal yang disetujui':'No approved proposals yet' ?>
        </div>
        <div style="font-size:12.5px">
          <?= $id?'Laporan akhir hanya dapat diunggah untuk proposal yang telah disetujui melalui seleksi substantif.'
                 :'Final reports can only be uploaded for proposals approved through substantive review.' ?>
        </div>
      </div>
      <?php else: ?>

      <?php foreach ($proposals as $p):
        $has_kontrak  = !empty($p['nomor_kontrak']);
        $has_laporan  = !empty($p['lap_id']);
        $lap_st       = $p['lap_status'] ?? '';
        $deadline     = $p['deadline_laporan'];
        $terlambat    = $deadline && $p['tanggal_submit']
                        ? (strtotime($p['tanggal_submit']) > strtotime($deadline))
                        : false;
        $deadline_lewat = $deadline && !$has_laporan && strtotime($deadline) < time();
      ?>
      <div class="lap-card">
        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:0">
            <div class="lap-judul"><?= htmlspecialchars($p['judul']) ?></div>
            <div class="lap-meta">
              <span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                <?= strtoupper($p['skema']) ?>
              </span>
              <span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?= $id?'Disetujui:':'Approved:' ?> <?= $p['reviewed_at'] ? date('d M Y', strtotime($p['reviewed_at'])) : '—' ?>
              </span>
              <?php if ($has_kontrak): ?>
              <span style="color:#7c3aed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                <?= htmlspecialchars($p['nomor_kontrak']) ?>
              </span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($has_laporan): ?>
          <span class="status-badge" style="
            background:<?= $lap_st==='diterima'?'#f0fdf4':($lap_st==='ditolak'?'#fef2f2':($lap_st==='revisi'?'#fefce8':'#fef9c3')) ?>;
            color:<?= $lap_st==='diterima'?'#15803d':($lap_st==='ditolak'?'#dc2626':($lap_st==='revisi'?'#a16207':'#92400e')) ?>">
            <?php if ($lap_st==='diterima'): ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $id?'Laporan Diterima':'Report Accepted' ?>
            <?php elseif ($lap_st==='ditolak'): ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <?= $id?'Laporan Ditolak':'Report Rejected' ?>
            <?php elseif ($lap_st==='revisi'): ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/></svg>
            <?= $id?'Perlu Revisi':'Needs Revision' ?>
            <?php else: ?>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $id?'Menunggu Verifikasi':'Awaiting Verification' ?>
            <?php endif; ?>
          </span>
          <?php endif; ?>
        </div>

        <?php // ── History feedback dari admin ── ?>
        <?php $hist = $history_map[$p['id']] ?? []; ?>
        <?php if (!empty($hist)): ?>
        <details style="margin-top:10px;background:var(--bg-field);border:1px solid var(--border);border-radius:9px;padding:9px 13px">
          <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--text-primary);user-select:none;display:flex;align-items:center;gap:6px">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/></svg>
            <?= $id?'Riwayat Submit & Feedback':'Submit & Feedback History' ?>
            <span style="background:#e0e7ff;color:#3730a3;font-size:10px;padding:1px 8px;border-radius:8px;font-weight:700"><?= count($hist) ?>×</span>
          </summary>
          <div style="margin-top:9px;display:flex;flex-direction:column;gap:7px">
          <?php foreach ($hist as $h):
            $kp_color = match($h['keputusan']) {
              'diterima' => ['#f0fdf4','#16a34a',$id?'Diterima':'Accepted'],
              'revisi'   => ['#fefce8','#a16207',$id?'Revisi':'Revision'],
              'ditolak'  => ['#fef2f2','#dc2626',$id?'Ditolak':'Rejected'],
              default    => ['#f1f5f9','#64748b','—'],
            };
          ?>
          <div style="background:#fff;border:1px solid var(--border);border-radius:7px;padding:8px 11px;font-size:11.5px">
            <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:4px">
              <span style="background:#e0e7ff;color:#3730a3;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px">Round <?= (int)$h['round_ke'] ?></span>
              <span style="background:<?= $kp_color[0] ?>;color:<?= $kp_color[1] ?>;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px"><?= $kp_color[2] ?></span>
              <span style="font-size:10.5px;color:var(--text-muted)"><?= date('d M Y H:i', strtotime($h['submitted_at'])) ?></span>
            </div>
            <?php if (!empty($h['file_laporan'])): ?>
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($h['file_laporan']) ?>" target="_blank" style="font-size:11px;color:var(--primary);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              <?= htmlspecialchars(mb_strimwidth($h['file_laporan_name'] ?? basename($h['file_laporan']), 0, 60, '…')) ?>
            </a>
            <?php endif; ?>
            <?php if ($h['catatan_admin']): ?>
            <div style="margin-top:5px;padding:6px 9px;background:var(--bg-field);border-radius:5px;color:#475569">
              <strong><?= $id?'Catatan LPPM:':'LPPM Note:' ?></strong> <?= nl2br(htmlspecialchars($h['catatan_admin'])) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <!-- Timeline -->
        <div class="timeline">
          <?php
          $steps = [
            ['label'=>$id?'Disetujui':'Approved',     'done'=>true,         'color'=>'#16a34a'],
            ['label'=>$id?'Kontrak':'Contract',        'done'=>$has_kontrak, 'color'=>'#7c3aed'],
            ['label'=>$id?'Pelaksanaan':'Research',    'done'=>$has_kontrak, 'color'=>'#0891b2'],
            ['label'=>$id?'Upload Laporan':'Upload',   'done'=>$has_laporan, 'color'=>'#1565C0'],
            ['label'=>$id?'Verifikasi':'Verified',     'done'=>$lap_st==='diterima', 'color'=>'#16a34a'],
          ];
          foreach ($steps as $si => $step):
            $active = $step['done'];
          ?>
          <div class="tl-wrap">
            <div class="tl-step">
              <div class="tl-dot" style="
                background:<?= $active?$step['color']:'#e2e8f0' ?>;
                color:<?= $active?'#fff':'#94a3b8' ?>">
                <?php if ($active): ?>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: echo $si+1; endif; ?>
              </div>
              <div class="tl-lbl"><?= $step['label'] ?></div>
            </div>
            <?php if ($si < count($steps)-1): ?>
            <div class="tl-line" style="background:<?= $active?$step['color']:'#e2e8f0' ?>;margin-top:13px"></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Deadline info -->
        <?php if ($deadline): $dl_ts = strtotime($deadline); ?>
        <div class="<?= $deadline_lewat?'deadline-warn':($terlambat?'deadline-warn':'deadline-ok') ?>">
          <?php if ($terlambat): ?>
          ⚠️ <?= $id?'Laporan diunggah <strong>terlambat</strong> dari deadline':'Report uploaded <strong>late</strong> past deadline' ?> (<?= date('d M Y · H:i', $dl_ts) ?> WITA)
          <?php elseif ($deadline_lewat): ?>
          ⚠️ <?= $id?'Deadline laporan telah lewat':'Report deadline has passed' ?> (<?= date('d M Y · H:i', $dl_ts) ?> WITA).
          <?= $id?'Segera serahkan hardcopy & upload laporan Anda — sebagian besar penalti ditentukan keterlambatan hardcopy.':'Submit hardcopy & upload your report immediately — most penalties depend on hardcopy lateness.' ?>
          <?php else: ?>
          ✓ <?= $id?'Deadline laporan:':'Report deadline:' ?> <strong><?= date('d M Y · H:i', $dl_ts) ?> WITA</strong>
          <?php if (!$has_laporan): ?>
          — <?= $id?'Sisa waktu':'Time left' ?>: <strong id="cd-lap-<?= (int)$p['id'] ?>" data-target="<?= $dl_ts*1000 ?>"></strong>
          <script>
          (function(){
            const el = document.getElementById('cd-lap-<?= (int)$p['id'] ?>');
            const t = parseInt(el.dataset.target,10);
            function tick(){
              const d = t - Date.now();
              if (d<=0) { el.textContent='<?= $id?"deadline terlewat":"deadline passed" ?>'; return; }
              const dd=Math.floor(d/86400000), hh=Math.floor(d/3600000)%24, mm=Math.floor(d/60000)%60;
              el.textContent = (dd>0?dd+' hari ':'') + String(hh).padStart(2,'0') + ':' + String(mm).padStart(2,'0');
              setTimeout(tick, 30000);
            }
            tick();
          })();
          </script>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Laporan sudah ada -->
        <?php if ($has_laporan && !in_array($lap_st, ['ditolak','revisi'], true)): ?>
        <div style="margin-top:14px;background:var(--bg-field);border-radius:8px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:8px">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <div>
              <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($p['file_laporan_name'] ?? 'laporan.pdf') ?></div>
              <div style="font-size:11px;color:var(--text-muted)">
                <?= $id?'Diunggah:':'Uploaded:' ?> <?= date('d M Y H:i', strtotime($p['tanggal_submit'])) ?>
                <?php if ($terlambat): ?> <span style="color:#dc2626;font-weight:600">— <?= $id?'Terlambat':'Late' ?></span><?php endif; ?>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($p['file_laporan']) ?>" target="_blank"
               class="btn btn-outline" style="font-size:11.5px;padding:6px 12px">
              <?= $id?'Lihat Laporan':'View Report' ?>
            </a>
          </div>
        </div>

        <?php elseif (!$has_laporan || in_array($lap_st, ['ditolak','revisi'], true)): ?>
        <!-- Catatan dari admin (jika revisi/ditolak) -->
        <?php if (in_array($lap_st, ['ditolak','revisi'], true) && $p['catatan_admin']): ?>
        <div style="background:<?= $lap_st==='ditolak'?'#fef2f2':'#fefce8' ?>;border:1px solid <?= $lap_st==='ditolak'?'#fecaca':'#fde68a' ?>;border-radius:8px;padding:10px 14px;margin-top:12px;margin-bottom:12px;font-size:12.5px;color:<?= $lap_st==='ditolak'?'#991b1b':'#854d0e' ?>">
          <strong><?= $lap_st==='ditolak'
            ? ($id?'Laporan ditolak — Catatan LPPM:':'Report rejected — LPPM Notes:')
            : ($id?'Laporan dikembalikan untuk diperbaiki — Catatan LPPM:':'Report returned for revision — LPPM Notes:') ?></strong>
          <?= nl2br(htmlspecialchars($p['catatan_admin'])) ?>
        </div>
        <?php endif; ?>
        <!-- Versi terakhir yang ditolak/revisi tetap dapat dilihat -->
        <?php if ($has_laporan && in_array($lap_st, ['ditolak','revisi'], true) && $p['file_laporan']): ?>
        <div style="margin-bottom:10px;font-size:11.5px;color:var(--text-muted)">
          <?= $id?'Versi terakhir yang Anda upload:':'Your last uploaded version:' ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($p['file_laporan']) ?>" target="_blank" style="color:var(--primary);font-weight:600;text-decoration:none">
            <?= htmlspecialchars($p['file_laporan_name'] ?? 'laporan.pdf') ?>
          </a>
        </div>
        <?php endif; ?>

        <div style="margin-top:14px">
          <button onclick="openUpload(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['judul']),ENT_QUOTES) ?>')"
                  class="btn-upload">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            <?= $id?'Upload Laporan Pengabdian':'Upload Research Report' ?>
          </button>
          <?php if (!$has_kontrak): ?>
          <div style="margin-top:8px;font-size:11.5px;color:var(--text-muted)">
            <?= $id?'Kontrak pengabdian belum tersedia — Hubungi admin LPPM.':'Research contract not yet available — Contact LPPM admin.' ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Upload Modal -->
<div id="uploadModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--bg-card);border-radius:16px;width:100%;max-width:500px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:20px 22px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:15px;font-weight:700"><?= $id?'Upload Laporan Pengabdian':'Upload Research Report' ?></div>
      <button onclick="closeUpload()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px;line-height:1">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <div style="padding:20px 22px">
        <input type="hidden" name="action" value="upload_laporan">
        <input type="hidden" name="pid" id="modalPid">
        <div style="margin-bottom:14px">
          <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Proposal</div>
          <div id="modalJudul" style="font-size:13px;font-weight:600;line-height:1.4"></div>
        </div>
        <div style="margin-bottom:4px;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">
          <?= $id?'File Laporan (PDF, maks. 30 MB)':'Report File (PDF, max 30 MB)' ?>
        </div>
        <label for="fileInput" class="upload-area" id="uploadArea">
          <input type="file" name="file_laporan" id="fileInput" accept=".pdf" style="display:none" onchange="onFileChange(this)">
          <div id="uploadAreaContent">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);margin:0 auto 10px"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            <div style="font-size:13px;font-weight:600"><?= $id?'Klik atau seret file PDF ke sini':'Click or drag PDF file here' ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px"><?= $id?'Hanya format PDF, maks 30 MB':'PDF format only, max 30 MB' ?></div>
          </div>
        </label>
      </div>
      <div style="padding:14px 22px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
        <button type="button" onclick="closeUpload()" class="btn btn-outline" style="font-size:13px">
          <?= $id?'Batal':'Cancel' ?>
        </button>
        <button type="submit" id="submitBtn" class="btn-upload" disabled style="opacity:.5">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
          <?= $id?'Upload Laporan':'Upload Report' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleLang() {
  const curr = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (curr === 'id' ? 'en' : 'id') + ';path=/;max-age=31536000';
  location.reload();
}
function openUpload(pid, judul) {
  document.getElementById('modalPid').value = pid;
  document.getElementById('modalJudul').textContent = judul;
  document.getElementById('fileInput').value = '';
  document.getElementById('uploadAreaContent').innerHTML = `
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--text-muted);margin:0 auto 10px"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
    <div style="font-size:13px;font-weight:600"><?= $id?'Klik atau seret file PDF ke sini':'Click or drag PDF file here' ?></div>
    <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px"><?= $id?'Hanya format PDF, maks 30 MB':'PDF format only, max 30 MB' ?></div>`;
  document.getElementById('submitBtn').disabled = true;
  document.getElementById('submitBtn').style.opacity = '.5';
  const m = document.getElementById('uploadModal');
  m.style.display = 'flex';
}
function closeUpload() {
  document.getElementById('uploadModal').style.display = 'none';
}
function onFileChange(input) {
  const file = input.files[0];
  if (!file) return;
  const area = document.getElementById('uploadAreaContent');
  if (file.type !== 'application/pdf') {
    area.innerHTML = '<div style="color:#dc2626;font-weight:600">⚠ Hanya file PDF yang diizinkan</div>';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').style.opacity = '.5';
    return;
  }
  const mb = (file.size / 1048576).toFixed(1);
  area.innerHTML = `
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" style="margin:0 auto 6px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <div style="font-weight:600;font-size:13px">${file.name}</div>
    <div style="font-size:11.5px;color:var(--text-muted)">${mb} MB</div>`;
  document.getElementById('submitBtn').disabled = false;
  document.getElementById('submitBtn').style.opacity = '1';
}
// Drag-drop
const ua = document.getElementById('uploadArea');
if (ua) {
  ua.addEventListener('dragover', e => { e.preventDefault(); ua.classList.add('dragover'); });
  ua.addEventListener('dragleave', () => ua.classList.remove('dragover'));
  ua.addEventListener('drop', e => {
    e.preventDefault(); ua.classList.remove('dragover');
    const fi = document.getElementById('fileInput');
    fi.files = e.dataTransfer.files;
    onFileChange(fi);
  });
}
// Close on backdrop
document.getElementById('uploadModal').addEventListener('click', function(e) {
  if (e.target === this) closeUpload();
});
</script>
</body>
</html>
