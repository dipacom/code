<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');
if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';

$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) { redirect('/modules/pengabdian/index.php'); }

// Load proposal milik user ini
$prop = $pdo->prepare("
    SELECT up.*, u.nama_lengkap, u.nidn, u.jabatan_fungsional,
           sa.checklist AS admin_checklist, sa.catatan AS admin_catatan, sa.keputusan AS admin_keputusan
    FROM usulan_pengabdian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN seleksi_admin_pengabdian sa ON sa.usulan_id = up.id
    WHERE up.id = ? AND up.user_id = ? AND up.deleted_at IS NULL
");
$prop->execute([$pid, $uid]);
$proposal = $prop->fetch();

if (!$proposal) { redirect('/modules/pengabdian/index.php'); }

// Status yang boleh direvisi
$revisable = ['gagal_admin','revisi_minor','revisi_mayor','ditolak','direvisi'];
if (!in_array($proposal['status'], $revisable)) {
    $_SESSION['flash'] = ['type'=>'warning','msg'=>$id
        ? 'Proposal tidak dalam status yang dapat direvisi.'
        : 'Proposal is not in a revisable status.'];
    redirect('/modules/pengabdian/index.php');
}

$tipe_revisi = $proposal['status'] === 'gagal_admin' ? 'admin' : 'substantif';

// Hitung round ke berapa
$round_ke = (int)($pdo->prepare("SELECT COUNT(*) FROM revisi_proposal_pengabdian WHERE usulan_id=? AND tipe=?")
    ->execute([$pid, $tipe_revisi]) ? $pdo->query("SELECT COUNT(*) FROM revisi_proposal_pengabdian WHERE usulan_id=$pid AND tipe='$tipe_revisi'")->fetchColumn() : 0) + 1;
// More reliable round count
$rq = $pdo->prepare("SELECT COUNT(*) FROM revisi_proposal_pengabdian WHERE usulan_id=? AND tipe=?");
$rq->execute([$pid, $tipe_revisi]);
$round_ke = (int)$rq->fetchColumn() + 1;

// Load checklist admin (untuk gagal_admin)
$DEFAULT_CHECKLIST = [
    ['key'=>'file_terupload',   'label'=>'File proposal sudah diunggah'],
    ['key'=>'format_nama_file', 'label'=>'Nama file sesuai format yang ditentukan'],
    ['key'=>'nidn_lengkap',     'label'=>'NIDN/data identitas ketua & anggota lengkap'],
    ['key'=>'scholar_sinta',    'label'=>'Ketua memiliki akun Google Scholar & SINTA'],
    ['key'=>'jabatan_min',      'label'=>'Jabatan fungsional ketua memenuhi syarat minimum skema'],
    ['key'=>'komposisi_tim',    'label'=>'Komposisi tim (jumlah dosen/mahasiswa) sesuai skema'],
    ['key'=>'homebase_sama',    'label'=>'Ketua dan anggota dari homebase/fakultas yang sama'],
    ['key'=>'sesuai_template',  'label'=>'Proposal menggunakan template yang disediakan LPPM'],
    ['key'=>'pernyataan_ok',    'label'=>'Pernyataan kesanggupan sudah disetujui pengusul'],
];
$checklist_data   = [];
$failed_checklist = [];
if ($tipe_revisi === 'admin' && $proposal['admin_checklist']) {
    $checklist_data = json_decode($proposal['admin_checklist'], true) ?? [];
    foreach ($DEFAULT_CHECKLIST as $item) {
        if (!($checklist_data[$item['key']] ?? true) === false) {
            // item is false = failed
        }
        if (!($checklist_data[$item['key']] ?? true)) {
            $failed_checklist[] = $item;
        }
    }
}

// Load reviewer penilaian (untuk revisi_minor/revisi_mayor)
$reviewer_notes = [];
if ($tipe_revisi === 'substantif') {
    $rnq = $pdo->prepare("
        SELECT rp.saran, rp.keputusan, rp.nilai_total, ra.assigned_at
        FROM reviewer_assignment_pengabdian ra
        JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id = ra.id
        WHERE ra.usulan_id = ? AND rp.submitted_at IS NOT NULL
        ORDER BY ra.assigned_at ASC
    ");
    $rnq->execute([$pid]);
    $reviewer_notes = $rnq->fetchAll();
}

// ── POST handler ───────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kirim_revisi') {

    $ringkasan  = trim($_POST['ringkasan'] ?? '');
    $respon_raw = $_POST['respon_isu'] ?? [];

    // Validasi ringkasan
    if (strlen($ringkasan) < 30) {
        $error = $id ? 'Ringkasan perubahan minimal 30 karakter.' : 'Change summary must be at least 30 characters.';
    }

    // Validasi respon isu
    if (!$error) {
        foreach ($respon_raw as $k => $v) {
            if (trim($v) === '') {
                $error = $id
                    ? 'Semua poin yang perlu diperbaiki wajib diisi tanggapannya.'
                    : 'All issues requiring attention must have a response.';
                break;
            }
        }
    }

    // Upload file revisi (wajib)
    $file_path = $file_name = null;
    $file_size = 0;
    if (!$error) {
        if (empty($_FILES['file_revisi']['name'])) {
            $error = $id ? 'File proposal revisi wajib diunggah.' : 'Revised proposal file is required.';
        } else {
            $f   = $_FILES['file_revisi'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']);
            $allowed_mimes = [
                'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            if (!in_array($ext, ['doc','docx']) || !in_array($mime, $allowed_mimes)) {
                $error = $id ? 'File harus berformat DOC atau DOCX yang valid.' : 'File must be a valid DOC or DOCX format.';
            } elseif ($f['size'] > 20 * 1024 * 1024) {
                $error = $id ? 'Ukuran file maks. 20 MB.' : 'Max file size is 20 MB.';
            } else {
                $dir = BASE_PATH . '/uploads/revisi_pengabdian/' . $uid . '/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = time() . '_rev' . $round_ke . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $f['name']);
                if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                    $file_path = 'uploads/revisi_pengabdian/' . $uid . '/' . $fname;
                    $file_name = $f['name'];
                    $file_size = $f['size'];
                } else {
                    $error = $id ? 'Gagal mengunggah file.' : 'File upload failed.';
                }
            }
        }
    }

    if (!$error) {
        // Build respon_isu JSON
        $isu_list = [];
        if ($tipe_revisi === 'admin') {
            foreach ($failed_checklist as $i => $item) {
                $isu_list[] = [
                    'tipe'  => 'checklist_admin',
                    'isu'   => $item['label'],
                    'respon'=> trim($respon_raw['cl_'.$item['key']] ?? ''),
                ];
            }
            if (!empty($proposal['admin_catatan'])) {
                $isu_list[] = [
                    'tipe'  => 'catatan_admin',
                    'isu'   => $proposal['admin_catatan'],
                    'respon'=> trim($respon_raw['catatan_admin'] ?? ''),
                ];
            }
        } else {
            foreach ($reviewer_notes as $ri => $rn) {
                if (!empty($rn['saran'])) {
                    $isu_list[] = [
                        'tipe'  => 'reviewer_' . chr(65+$ri),
                        'isu'   => $rn['saran'],
                        'respon'=> trim($respon_raw['rev_'.$ri] ?? ''),
                    ];
                }
            }
            if (!empty($proposal['catatan_reviewer'])) {
                $isu_list[] = [
                    'tipe'  => 'catatan_lppm',
                    'isu'   => $proposal['catatan_reviewer'],
                    'respon'=> trim($respon_raw['catatan_lppm'] ?? ''),
                ];
            }
        }

        $new_status = $tipe_revisi === 'admin' ? 'perbaikan_admin' : 'perbaikan_substantif';

        // Simpan revisi
        $pdo->prepare("
            INSERT INTO revisi_proposal_pengabdian
              (usulan_id, user_id, tipe, round_ke, ringkasan, respon_isu,
               file_proposal, file_proposal_name, file_proposal_size, status_sebelum)
            VALUES (?,?,?,?,?,?, ?,?,?,?)
        ")->execute([
            $pid, $uid, $tipe_revisi, $round_ke, $ringkasan,
            json_encode($isu_list, JSON_UNESCAPED_UNICODE),
            $file_path, $file_name, $file_size,
            $proposal['status'],
        ]);

        // Update status proposal
        $pdo->prepare("UPDATE usulan_pengabdian SET status=?, updated_at=NOW() WHERE id=?")
            ->execute([$new_status, $pid]);

        // Notifikasi admin
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll();
        foreach ($admins as $adm) {
            $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")->execute([
                $adm['id'],
                $id ? "Perbaikan Proposal Dikirim" : "Proposal Revision Submitted",
                ($id ? 'Dosen ' : 'Lecturer ') . $_SESSION['nama']
                . ($id ? ' mengirimkan perbaikan ' : ' submitted a revision for ')
                . ($tipe_revisi === 'admin' ? ($id ? '(seleksi administratif)' : '(admin selection)') : ($id ? '(seleksi substantif)' : '(substantive review)'))
                . ': ' . mb_strimwidth($proposal['judul'], 0, 70, '…'),
                'info',
            ]);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>$id
            ? "Perbaikan proposal berhasil dikirimkan. Admin LPPM akan segera meninjau ulang."
            : "Proposal revision submitted. LPPM admin will review shortly."];
        redirect('/modules/pengabdian/index.php');
    }
}

// ── Build isu list untuk ditampilkan di form ───────────────────
$isu_display = [];
if ($tipe_revisi === 'admin') {
    foreach ($failed_checklist as $item) {
        $isu_display[] = [
            'id'    => 'cl_' . $item['key'],
            'label' => $item['label'],
            'tipe'  => 'admin',
            'icon'  => 'checklist',
        ];
    }
    if (!empty($proposal['admin_catatan'])) {
        $isu_display[] = [
            'id'    => 'catatan_admin',
            'label' => $proposal['admin_catatan'],
            'tipe'  => 'admin',
            'icon'  => 'note',
        ];
    }
} else {
    foreach ($reviewer_notes as $ri => $rn) {
        if (!empty($rn['saran'])) {
            $isu_display[] = [
                'id'    => 'rev_' . $ri,
                'label' => $rn['saran'],
                'tipe'  => 'Reviewer ' . chr(65+$ri),
                'nilai' => number_format((float)$rn['nilai_total'], 1),
                'keputusan' => $rn['keputusan'],
                'icon'  => 'reviewer',
            ];
        }
    }
    if (!empty($proposal['catatan_reviewer'])) {
        $isu_display[] = [
            'id'    => 'catatan_lppm',
            'label' => $proposal['catatan_reviewer'],
            'tipe'  => 'LPPM',
            'icon'  => 'note',
        ];
    }
}

$n_isu        = count($isu_display);
$status_colors = [
    'gagal_admin'   => ['#991b1b','#fef2f2','#dc2626'],
    'revisi_minor'  => ['#92400e','#fffbeb','#d97706'],
    'revisi_mayor'  => ['#7c2d12','#fff7ed','#ea580c'],
];
[$st_text, $st_bg, $st_accent] = $status_colors[$proposal['status']] ?? ['#374151','#f1f5f9','#6b7280'];

$revisi_label = match($proposal['status']) {
    'gagal_admin'  => $id ? 'Revisi Seleksi Administratif' : 'Administrative Review Revision',
    'revisi_minor' => $id ? 'Revisi Minor (Substantif)'    : 'Minor Revision (Substantive)',
    'direvisi'     => $id ? 'Revisi (Substantif)'          : 'Revision (Substantive)',
    'revisi_mayor' => $id ? 'Revisi Mayor (Substantif)'    : 'Major Revision (Substantive)',
    'ditolak'      => $id ? 'Perbaikan Usulan Ditolak'     : 'Rejected Proposal Correction',
    default        => 'Revisi',
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Pengajuan Revisi':'Submit Revision' ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
/* ── Hero ── */
.rv-hero {
  border-radius:18px; overflow:hidden; margin-bottom:28px;
  background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 50%,#1e3a8a 100%);
  position:relative;
}
.rv-hero::before {
  content:''; position:absolute; inset:0; pointer-events:none;
  background:url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='40' cy='40' r='30'/%3E%3C/g%3E%3C/svg%3E") repeat;
}
.rv-hero-inner { position:relative;z-index:1;padding:26px 28px 22px; }
.rv-hero-top   { display:flex;align-items:flex-start;gap:14px;margin-bottom:14px; }
.rv-hero-badge {
  display:inline-flex;align-items:center;gap:5px;
  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);
  border-radius:20px;padding:4px 12px;font-size:11.5px;font-weight:700;
  color:rgba(255,255,255,.9);white-space:nowrap;flex-shrink:0;
}
.rv-hero-badge.round { background:rgba(250,204,21,.2);border-color:rgba(250,204,21,.4);color:#fde047; }
.rv-hero-title { font-size:17px;font-weight:800;color:#fff;line-height:1.35;margin-bottom:5px; }
.rv-hero-sub   { font-size:12px;color:rgba(255,255,255,.55);line-height:1.6; }
.rv-hero-pills { display:flex;flex-wrap:wrap;gap:7px;margin-top:14px; }
.rv-hero-pill  {
  display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;
  background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);
  border-radius:6px;padding:3px 10px;color:rgba(255,255,255,.75);
}

/* ── Layout ── */
.rv-layout { display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start; }
@media(max-width:768px){ .rv-layout { grid-template-columns:1fr; } }

/* ── Progress Sidebar ── */
.rv-sidebar {
  position:sticky;top:80px;
  background:var(--bg-card);border:1.5px solid var(--border);
  border-radius:14px;overflow:hidden;
}
.rv-sidebar-head {
  padding:13px 15px;background:var(--bg-field);
  border-bottom:1px solid var(--border);
  font-size:12px;font-weight:700;color:var(--text-primary);
}
.rv-progress-ring { display:flex;justify-content:center;padding:18px 15px 10px; }
.rv-ring-wrap { position:relative;width:100px;height:100px; }
.rv-ring-svg { transform:rotate(-90deg); }
.rv-ring-bg   { fill:none;stroke:#e2e8f0;stroke-width:8; }
.rv-ring-fill { fill:none;stroke:var(--primary);stroke-width:8;stroke-linecap:round;
                transition:stroke-dashoffset .5s cubic-bezier(.4,0,.2,1); }
.rv-ring-text {
  position:absolute;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  font-size:22px;font-weight:900;color:var(--text-primary);line-height:1;
}
.rv-ring-sub { font-size:10px;color:var(--text-muted);font-weight:500; }
.rv-checklist { padding:0 14px 14px; }
.rv-check-item {
  display:flex;align-items:center;gap:8px;
  padding:6px 0;border-bottom:1px solid var(--border);font-size:12px;
}
.rv-check-item:last-child { border-bottom:none; }
.rv-check-dot {
  width:18px;height:18px;border-radius:50%;flex-shrink:0;
  border:2px solid var(--border);background:var(--bg-field);
  display:flex;align-items:center;justify-content:center;
  transition:all .2s;
}
.rv-check-dot.done { background:#16a34a;border-color:#16a34a; }
.rv-check-dot.done svg { opacity:1; }
.rv-check-dot svg { opacity:0;transition:opacity .15s; }
.rv-check-text { flex:1;min-width:0;color:var(--text-muted);line-height:1.3;
                 overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;
                 -webkit-box-orient:vertical; }
.rv-check-text.done { color:var(--text-primary); }

/* ── Section headers ── */
.rv-section-hd {
  display:flex;align-items:center;gap:10px;
  padding:12px 16px;border-radius:11px;
  margin-bottom:14px;
}

/* ── Issue cards ── */
.isu-card {
  border:1.5px solid var(--border);border-radius:12px;
  overflow:hidden;margin-bottom:12px;
  transition:border-color .2s,box-shadow .2s;
}
.isu-card.is-done { border-color:#86efac; }
.isu-card.is-done .isu-card-head { background:#f0fdf4; }
.isu-card-head {
  padding:12px 14px;background:var(--bg-field);
  display:flex;align-items:flex-start;gap:10px;
  border-bottom:1px solid var(--border);cursor:pointer;
  transition:background .15s;
}
.isu-card-head:hover { background:#f1f5f9; }
.isu-type-chip {
  display:inline-flex;align-items:center;gap:4px;
  border-radius:5px;padding:2px 8px;font-size:10.5px;font-weight:700;
  white-space:nowrap;flex-shrink:0;margin-top:2px;
}
.isu-text { font-size:13px;color:var(--text-primary);line-height:1.55;flex:1; }
.isu-status-ico { flex-shrink:0;margin-top:2px;transition:transform .2s; }
.isu-card-body { padding:14px; }
.isu-respon-wrap { position:relative; }
.isu-respon-area {
  width:100%;box-sizing:border-box;padding:10px 12px;padding-right:52px;
  border:1.5px solid var(--border);border-radius:9px;
  font-size:12.5px;line-height:1.6;resize:vertical;min-height:80px;
  font-family:inherit;background:var(--bg-field);
  transition:border-color .15s,box-shadow .15s;
}
.isu-respon-area:focus { outline:none;border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(79,70,229,.1);background:#fff; }
.isu-respon-area.filled { border-color:#86efac;background:#f0fdf4; }
.isu-char-count {
  position:absolute;bottom:8px;right:10px;font-size:10px;
  color:var(--text-muted);font-weight:500;pointer-events:none;
}
.isu-done-badge {
  display:none;position:absolute;top:50%;right:10px;transform:translateY(-50%);
  background:#16a34a;color:#fff;border-radius:5px;padding:2px 7px;
  font-size:10px;font-weight:700;
}
.isu-respon-area.filled ~ .isu-done-badge { display:block; }

/* ── File upload ── */
.rv-drop {
  border:2px dashed var(--border);border-radius:12px;
  padding:28px 20px;text-align:center;cursor:pointer;
  background:var(--bg-field);transition:all .2s;position:relative;
}
.rv-drop:hover,.rv-drop.drag-over {
  border-color:var(--primary);background:var(--primary-xlight);
}
.rv-drop input { display:none; }
.rv-drop-ico { font-size:32px;margin-bottom:8px; }
.rv-drop-label { font-size:13px;font-weight:600;color:var(--text-primary); }
.rv-drop-sub   { font-size:11.5px;color:var(--text-muted);margin-top:3px; }
.rv-file-preview {
  display:none;align-items:center;gap:12px;
  background:#eff6ff;border:1.5px solid #bfdbfe;
  border-radius:10px;padding:11px 14px;margin-top:10px;
}
.rv-file-preview.show { display:flex; }
.rv-file-name  { font-size:13px;font-weight:600;color:#1e40af;flex:1;min-width:0;
                 overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.rv-file-size  { font-size:11px;color:#60a5fa;flex-shrink:0; }
.rv-file-del   { background:none;border:none;cursor:pointer;color:#93c5fd;padding:2px; }
.rv-file-del:hover { color:#1e40af; }

/* ── Submit bar ── */
.rv-submit-bar {
  background:var(--bg-card);border:1.5px solid var(--border);
  border-radius:14px;padding:16px;margin-top:6px;
}
.rv-submit-lock {
  font-size:12px;color:#dc2626;font-weight:600;
  display:flex;align-items:center;gap:5px;
  background:#fef2f2;border:1px solid #fecaca;
  border-radius:8px;padding:8px 12px;margin-bottom:12px;
}
.rv-submit-lock.hidden { display:none; }

/* ── Previous file preview ── */
.rv-prev-file {
  display:flex;align-items:center;gap:8px;font-size:12px;
  background:#f8fafc;border:1px solid var(--border);
  border-radius:8px;padding:8px 12px;margin-bottom:10px;color:var(--text-muted);
}

/* Mobile sticky footer */
@media(max-width:768px){
  .rv-mobile-footer {
    position:fixed;bottom:0;left:0;right:0;
    background:rgba(255,255,255,.95);backdrop-filter:blur(10px);
    border-top:1px solid var(--border);padding:12px 16px;
    display:flex;align-items:center;gap:12px;z-index:200;
  }
  .rv-mobile-prog { flex:1; }
  .rv-mobile-prog-bar { height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-top:3px; }
  .rv-mobile-prog-fill { height:100%;background:var(--primary);border-radius:3px;transition:width .3s; }
  .rv-mobile-lbl { font-size:11.5px;color:var(--text-muted);font-weight:600; }
  .main-content { padding-bottom:80px !important; }
}
@media(min-width:769px){ .rv-mobile-footer { display:none; } }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg class="ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/>
            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
          </svg>
          <?= $revisi_label ?>
          <span class="breadcrumb"><?= htmlspecialchars(mb_strimwidth($proposal['judul'],0,40,'…')) ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/modules/pengabdian/index.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
        </a>
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-bottom:16px">
        <?= ic('alert') ?> <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- ── Hero ── -->
      <div class="rv-hero">
        <div class="rv-hero-inner">
          <div class="rv-hero-top">
            <div style="flex:1;min-width:0">
              <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
                <span class="rv-hero-badge">
                  <?php if ($tipe_revisi==='admin'): ?>
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                  <?= $id?'Revisi Administratif':'Administrative Revision' ?>
                  <?php else: ?>
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <?= $id?'Revisi Substantif':'Substantive Revision' ?>
                  <?php endif; ?>
                </span>
                <span class="rv-hero-badge round">
                  <?= $id?'Putaran ke-'.$round_ke:'Round '.$round_ke ?>
                </span>
                <span class="rv-hero-badge" style="background:rgba(<?= $proposal['status']==='gagal_admin'?'239,68,68':'234,88,12' ?>,.18);border-color:rgba(<?= $proposal['status']==='gagal_admin'?'239,68,68':'234,88,12' ?>,.4);color:<?= $proposal['status']==='gagal_admin'?'#fca5a5':'#fdba74' ?>">
                  <?= $proposal['status']==='gagal_admin'
                    ? ($id?'Tidak Lolos Admin':'Admin Failed')
                    : ($proposal['status']==='revisi_minor'
                        ? ($id?'Revisi Minor':'Minor Rev.')
                        : ($proposal['status']==='direvisi'
                            ? ($id?'Direvisi':'Revised')
                            : ($proposal['status']==='revisi_mayor'
                                ? ($id?'Revisi Mayor':'Major Rev.')
                                : ($id?'Ditolak':'Rejected')))) ?>
                </span>
              </div>
              <div class="rv-hero-title"><?= htmlspecialchars($proposal['judul']) ?></div>
              <div class="rv-hero-sub">
                <?= htmlspecialchars($proposal['nama_lengkap']) ?> ·
                <?= htmlspecialchars($proposal['program_studi'] ?? '') ?> ·
                <?= $id?'Tahun':'Year' ?> <?= $proposal['tahun_anggaran'] ?>
              </div>
            </div>
          </div>

          <div class="rv-hero-pills">
            <span class="rv-hero-pill">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?= $n_isu ?> <?= $id?'poin perlu ditanggapi':'issues to address' ?>
            </span>
            <span class="rv-hero-pill">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <?= $id?'Upload file DOC/DOCX wajib':'DOC/DOCX file upload required' ?>
            </span>
            <span class="rv-hero-pill">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <?= $id?'Review ulang oleh LPPM':'Re-reviewed by LPPM' ?>
            </span>
          </div>
        </div>
      </div>

      <form method="POST" enctype="multipart/form-data" id="formRevisi">
        <input type="hidden" name="action" value="kirim_revisi">

        <div class="rv-layout">
          <!-- ── Main column ── -->
          <div>

            <?php if ($n_isu > 0): ?>
            <!-- BAGIAN 1: Poin yang perlu ditanggapi -->
            <div class="card" style="margin-bottom:20px;padding:0;overflow:hidden">
              <div style="padding:14px 16px;display:flex;align-items:center;gap:10px;border-bottom:1.5px solid var(--border)">
                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#7c3aed,#a855f7);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                </div>
                <div>
                  <div style="font-size:13.5px;font-weight:700"><?= $id?'Tanggapi Setiap Poin':'Address Each Issue' ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= $id?'Semua poin wajib diisi sebelum submit':'All issues must be addressed before submitting' ?></div>
                </div>
                <div style="margin-left:auto;font-size:12px;font-weight:700;color:var(--text-muted)" id="isu-counter-label">
                  <span id="isu-done-count">0</span> / <?= $n_isu ?> <?= $id?'selesai':'done' ?>
                </div>
              </div>
              <div style="padding:16px">

              <?php foreach ($isu_display as $di => $isu):
                $chip_styles = match($isu['icon']) {
                    'checklist' => ['#7c3aed','#f5f3ff'],
                    'reviewer'  => ['#0369a1','#eff6ff'],
                    'note'      => ['#c2410c','#fff7ed'],
                    default     => ['#374151','#f1f5f9'],
                };
                [$chip_c, $chip_bg] = $chip_styles;
              ?>
              <div class="isu-card" id="isu-wrap-<?= $di ?>">
                <div class="isu-card-head" onclick="toggleIsu(<?= $di ?>)">
                  <span class="isu-type-chip" style="color:<?= $chip_c ?>;background:<?= $chip_bg ?>">
                    <?php if ($isu['icon']==='checklist'): ?>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 11l3 3L22 4"/></svg>
                    <?php elseif ($isu['icon']==='reviewer'): ?>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    <?php else: ?>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2V8z"/></svg>
                    <?php endif; ?>
                    <?= htmlspecialchars($isu['tipe']) ?>
                    <?php if (!empty($isu['nilai'])): ?>
                    <span style="font-size:9px;opacity:.8">· <?= $isu['nilai'] ?>pt</span>
                    <?php endif; ?>
                  </span>
                  <div class="isu-text"><?= nl2br(htmlspecialchars(mb_strimwidth($isu['label'],0,200,'…'))) ?></div>
                  <div class="isu-status-ico" id="isu-ico-<?= $di ?>">
                    <svg width="16" height="16" id="isu-check-<?= $di ?>" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" style="display:none">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <svg width="16" height="16" id="isu-caret-<?= $di ?>" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round">
                      <polyline points="6 9 12 15 18 9"/>
                    </svg>
                  </div>
                </div>
                <div class="isu-card-body" id="isu-body-<?= $di ?>" style="display:block">
                  <?php if (strlen($isu['label']) > 200): ?>
                  <div style="font-size:12px;color:var(--text-secondary);background:var(--bg-field);
                              border-radius:7px;padding:9px 12px;margin-bottom:10px;line-height:1.65;
                              border-left:3px solid <?= $chip_c ?>">
                    <?= nl2br(htmlspecialchars($isu['label'])) ?>
                  </div>
                  <?php endif; ?>
                  <label style="font-size:11.5px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px">
                    <?= $id?'Tanggapan / Langkah Perbaikan Anda':'Your Response / Corrective Action' ?>
                    <span style="color:#dc2626">*</span>
                  </label>
                  <div class="isu-respon-wrap">
                    <textarea
                      name="respon_isu[<?= htmlspecialchars($isu['id']) ?>]"
                      id="ta-<?= $di ?>"
                      class="isu-respon-area"
                      placeholder="<?= $id?'Jelaskan apa yang sudah Anda perbaiki sesuai poin ini...':'Explain what you have corrected for this issue...' ?>"
                      rows="3"
                      minlength="10"
                      oninput="onIsuInput(<?= $di ?>)"
                    ></textarea>
                    <span class="isu-char-count" id="cc-<?= $di ?>">0</span>
                    <span class="isu-done-badge">✓</span>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- BAGIAN 2: Upload File Revisi -->
            <div class="card" style="margin-bottom:20px;padding:0;overflow:hidden">
              <div style="padding:14px 16px;display:flex;align-items:center;gap:10px;border-bottom:1.5px solid var(--border)">
                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#0369a1,#0ea5e9);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div>
                  <div style="font-size:13.5px;font-weight:700"><?= $id?'Unggah Proposal Revisi':'Upload Revised Proposal' ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= $id?'DOC / DOCX · Maks. 20 MB':'DOC / DOCX · Max 20 MB' ?></div>
                </div>
                <span style="margin-left:auto;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca">
                  <?= $id?'Wajib':'Required' ?>
                </span>
              </div>
              <div style="padding:16px">

                <?php if ($proposal['file_proposal']): ?>
                <div class="rv-prev-file">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  <?= $id?'File sebelumnya:':'Previous file:' ?>
                  <a href="<?= BASE_URL ?>/<?= htmlspecialchars($proposal['file_proposal']) ?>" target="_blank"
                     style="color:var(--primary);font-weight:600">
                    <?= htmlspecialchars($proposal['file_proposal_name'] ?? basename($proposal['file_proposal'])) ?>
                  </a>
                  <span style="margin-left:auto;font-size:10.5px;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:4px;padding:1px 6px;font-weight:600">
                    v<?= $round_ke - 1 === 0 ? 1 : $round_ke - 1 ?>
                  </span>
                </div>
                <?php endif; ?>

                <div class="rv-drop" id="dropzone" onclick="document.getElementById('fileRevisi').click()"
                     ondragover="ev.preventDefault();this.classList.add('drag-over')"
                     ondragleave="this.classList.remove('drag-over')"
                     ondrop="handleDrop(event)">
                  <input type="file" id="fileRevisi" name="file_revisi"
                         accept=".doc,.docx" onchange="previewFile(this)">
                  <div class="rv-drop-ico" id="drop-ico">📄</div>
                  <div class="rv-drop-label" id="drop-label">
                    <?= $id?'Klik atau seret file ke sini':'Click or drag file here' ?>
                  </div>
                  <div class="rv-drop-sub">DOC · DOCX · <?= $id?'maks. 20 MB':'max 20 MB' ?></div>
                </div>
                <div class="rv-file-preview" id="filePreview">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  <span class="rv-file-name" id="fileName">—</span>
                  <span class="rv-file-size" id="fileSize"></span>
                  <button type="button" class="rv-file-del" onclick="clearFile()" title="Hapus">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- BAGIAN 3: Ringkasan Perubahan -->
            <div class="card" style="margin-bottom:20px;padding:0;overflow:hidden">
              <div style="padding:14px 16px;display:flex;align-items:center;gap:10px;border-bottom:1.5px solid var(--border)">
                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#059669,#10b981);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
                </div>
                <div>
                  <div style="font-size:13.5px;font-weight:700"><?= $id?'Ringkasan Perubahan':'Summary of Changes' ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= $id?'Jelaskan secara keseluruhan apa yang sudah diperbaiki':'Describe overall what has been corrected' ?></div>
                </div>
              </div>
              <div style="padding:16px">
                <div style="position:relative">
                  <textarea name="ringkasan" id="ringkasan"
                    class="form-control" rows="5" style="padding-right:60px;font-size:13px;line-height:1.7"
                    placeholder="<?= $id
                      ? 'Jelaskan perubahan yang sudah Anda lakukan dalam revisi ini. Misalnya: sudah menambahkan referensi terbaru pada bagian tinjauan pustaka, memperjelas rumusan masalah, melengkapi data SINTA, dsb. (minimal 30 karakter)'
                      : 'Describe changes made in this revision. E.g.: added recent references in literature review, clarified problem statement, completed SINTA data, etc. (min 30 characters)' ?>"
                    minlength="30" oninput="updateRingkasan()" required><?= htmlspecialchars($_POST['ringkasan'] ?? '') ?></textarea>
                  <span id="ringkasan-count" style="position:absolute;bottom:10px;right:12px;font-size:10.5px;color:var(--text-muted);font-weight:600;pointer-events:none">0</span>
                </div>
                <div id="ringkasan-warn" style="font-size:11px;color:#dc2626;margin-top:5px;display:none">
                  ⚠ <?= $id?'Minimal 30 karakter diperlukan.':'Minimum 30 characters required.' ?>
                </div>
              </div>
            </div>

            <!-- Submit bar -->
            <div class="rv-submit-bar">
              <div class="rv-submit-lock" id="submit-lock">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                <span id="lock-reason"><?= $id?'Lengkapi semua tanggapan + ringkasan + upload file':'Complete all responses + summary + file upload' ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button type="submit" id="btn-submit" name="submit_revisi" value="1"
                        class="btn btn-primary btn-lg" disabled
                        onclick="return konfirmRevisi()"
                        style="display:inline-flex;align-items:center;gap:8px;min-width:220px;justify-content:center">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/>
                    <path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15"/>
                  </svg>
                  <?= $id?'Kirim Revisi Proposal':'Submit Revised Proposal' ?>
                </button>
                <a href="<?= BASE_URL ?>/modules/pengabdian/index.php" class="btn btn-outline">
                  ← <?= $id?'Batalkan':'Cancel' ?>
                </a>
                <div style="flex:1;font-size:11px;color:var(--text-muted);line-height:1.5">
                  <?= $id
                    ? 'Setelah dikirim, admin LPPM akan meninjau perbaikan Anda dan memberikan keputusan.'
                    : 'After submission, LPPM admin will review your corrections and provide a decision.' ?>
                </div>
              </div>
            </div>

          </div><!-- /main column -->

          <!-- ── Sidebar ── -->
          <div class="rv-sidebar">
            <div class="rv-sidebar-head">
              <?= $id?'Progress Pengisian':'Completion Progress' ?>
            </div>

            <!-- Ring progress -->
            <div class="rv-progress-ring">
              <div class="rv-ring-wrap">
                <svg class="rv-ring-svg" width="100" height="100" viewBox="0 0 100 100">
                  <circle class="rv-ring-bg" cx="50" cy="50" r="41"/>
                  <circle class="rv-ring-fill" id="ringFill" cx="50" cy="50" r="41"
                          stroke-dasharray="257.6"
                          stroke-dashoffset="257.6"/>
                </svg>
                <div class="rv-ring-text">
                  <span id="ring-pct">0%</span>
                  <span class="rv-ring-sub"><?= $id?'siap':'ready' ?></span>
                </div>
              </div>
            </div>

            <!-- Checklist items -->
            <div class="rv-checklist" id="sidebar-checklist">
              <?php foreach ($isu_display as $di => $isu): ?>
              <div class="rv-check-item">
                <div class="rv-check-dot" id="dot-<?= $di ?>">
                  <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="rv-check-text" id="dt-<?= $di ?>">
                  <?= htmlspecialchars(mb_strimwidth($isu['tipe'].' — '.mb_strimwidth($isu['label'],0,40,'…'),0,55,'…')) ?>
                </div>
              </div>
              <?php endforeach; ?>
              <div class="rv-check-item">
                <div class="rv-check-dot" id="dot-file">
                  <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="rv-check-text" id="dt-file">
                  <?= $id?'File revisi diunggah':'Revision file uploaded' ?>
                </div>
              </div>
              <div class="rv-check-item">
                <div class="rv-check-dot" id="dot-ringkasan">
                  <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="rv-check-text" id="dt-ringkasan">
                  <?= $id?'Ringkasan perubahan diisi':'Change summary filled' ?>
                </div>
              </div>
            </div>
          </div><!-- /sidebar -->

        </div><!-- /rv-layout -->
      </form>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->

<!-- Mobile footer -->
<div class="rv-mobile-footer">
  <div class="rv-mobile-prog">
    <div class="rv-mobile-lbl" id="mob-lbl"><?= $id?'0% selesai':'0% complete' ?></div>
    <div class="rv-mobile-prog-bar">
      <div class="rv-mobile-prog-fill" id="mob-fill" style="width:0%"></div>
    </div>
  </div>
  <button type="button" id="mob-submit" disabled
          onclick="document.getElementById('formRevisi').requestSubmit()"
          style="padding:10px 18px;background:var(--primary);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:opacity .2s">
    <?= $id?'Kirim':'Submit' ?>
  </button>
</div>

<script>
const N_ISU   = <?= $n_isu ?>;
const IS_ID   = <?= $id ? 'true' : 'false' ?>;
let isuDone   = new Array(N_ISU).fill(false);
let fileDone  = false;
let ringDone  = false;

// ── Toggle isu body ───────────────────────────────────────────
function toggleIsu(i) {
  const body = document.getElementById('isu-body-'+i);
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  document.getElementById('isu-caret-'+i).style.transform = open ? 'rotate(-90deg)' : '';
}

// ── Input handler per isu ────────────────────────────────────
function onIsuInput(i) {
  const ta  = document.getElementById('ta-'+i);
  const val = ta.value.trim();
  const len = ta.value.length;
  document.getElementById('cc-'+i).textContent = len;

  const filled = val.length >= 10;
  ta.classList.toggle('filled', filled);
  isuDone[i] = filled;

  // Update sidebar dot
  const dot = document.getElementById('dot-'+i);
  const dt  = document.getElementById('dt-'+i);
  dot.classList.toggle('done', filled);
  dt.classList.toggle('done', filled);

  // Show/hide check vs caret
  document.getElementById('isu-check-'+i).style.display = filled ? 'block' : 'none';
  document.getElementById('isu-caret-'+i).style.display = filled ? 'none' : 'block';
  document.getElementById('isu-wrap-'+i).classList.toggle('is-done', filled);

  updateProgress();
}

// ── Ringkasan ────────────────────────────────────────────────
function updateRingkasan() {
  const ta  = document.getElementById('ringkasan');
  const val = ta.value.trim();
  const len = val.length;
  document.getElementById('ringkasan-count').textContent = len;
  ringDone = len >= 30;
  document.getElementById('ringkasan-warn').style.display = len > 0 && len < 30 ? 'block' : 'none';
  document.getElementById('dot-ringkasan').classList.toggle('done', ringDone);
  document.getElementById('dt-ringkasan').classList.toggle('done', ringDone);
  updateProgress();
}

// ── File upload ──────────────────────────────────────────────
function previewFile(input) {
  if (!input.files.length) return;
  const f    = input.files[0];
  const name = f.name;
  const size = (f.size / 1024 / 1024).toFixed(1) + ' MB';

  document.getElementById('fileName').textContent  = name;
  document.getElementById('fileSize').textContent  = size;
  document.getElementById('filePreview').classList.add('show');
  document.getElementById('drop-ico').textContent  = '✅';
  document.getElementById('drop-label').textContent = name;

  fileDone = true;
  document.getElementById('dot-file').classList.add('done');
  document.getElementById('dt-file').classList.add('done');
  updateProgress();
}
function clearFile() {
  document.getElementById('fileRevisi').value = '';
  document.getElementById('filePreview').classList.remove('show');
  document.getElementById('drop-ico').textContent   = '📄';
  document.getElementById('drop-label').textContent = IS_ID ? 'Klik atau seret file ke sini' : 'Click or drag file here';
  fileDone = false;
  document.getElementById('dot-file').classList.remove('done');
  document.getElementById('dt-file').classList.remove('done');
  updateProgress();
}
function handleDrop(ev) {
  ev.preventDefault();
  document.getElementById('dropzone').classList.remove('drag-over');
  const input = document.getElementById('fileRevisi');
  if (ev.dataTransfer.files.length) {
    // Transfer to input
    const dt = new DataTransfer();
    dt.items.add(ev.dataTransfer.files[0]);
    input.files = dt.files;
    previewFile(input);
  }
}

// ── Progress calculation ─────────────────────────────────────
function updateProgress() {
  const done   = isuDone.filter(Boolean).length + (fileDone?1:0) + (ringDone?1:0);
  const total  = N_ISU + 2; // isu + file + ringkasan
  const pct    = total > 0 ? Math.round((done / total) * 100) : 0;
  const allDone= done === total;

  // Ring
  const circ  = 257.6;
  const offset= circ - (pct / 100) * circ;
  const ring  = document.getElementById('ringFill');
  if (ring) { ring.style.strokeDashoffset = offset;
              ring.style.stroke = allDone ? '#16a34a' : 'var(--primary)'; }
  document.getElementById('ring-pct').textContent = pct + '%';
  document.getElementById('ring-pct').style.color = allDone ? '#16a34a' : '';

  // Counter label
  const doneCount = isuDone.filter(Boolean).length;
  document.getElementById('isu-done-count').textContent = doneCount;

  // Submit button
  const btn  = document.getElementById('btn-submit');
  const lock = document.getElementById('submit-lock');
  const mob  = document.getElementById('mob-submit');
  if (btn) { btn.disabled = !allDone; btn.style.opacity = allDone ? '1' : '0.5'; }
  if (mob) { mob.disabled = !allDone; mob.style.opacity = allDone ? '1' : '0.5'; }
  if (lock) lock.classList.toggle('hidden', allDone);

  // Lock reason
  if (!allDone) {
    const reasons = [];
    if (isuDone.filter(Boolean).length < N_ISU) reasons.push(IS_ID ? 'tanggapan isu' : 'issue responses');
    if (!fileDone) reasons.push(IS_ID ? 'file revisi' : 'revision file');
    if (!ringDone) reasons.push(IS_ID ? 'ringkasan perubahan' : 'change summary');
    const lockEl = document.getElementById('lock-reason');
    if (lockEl) lockEl.textContent = (IS_ID ? 'Belum: ' : 'Missing: ') + reasons.join(', ');
  }

  // Mobile
  const mobFill = document.getElementById('mob-fill');
  const mobLbl  = document.getElementById('mob-lbl');
  if (mobFill) mobFill.style.width = pct + '%';
  if (mobLbl)  mobLbl.textContent  = pct + '% ' + (IS_ID ? 'selesai' : 'complete');
}

// ── Confirm submit ───────────────────────────────────────────
function konfirmRevisi() {
  const round = <?= $round_ke ?>;
  return confirm(IS_ID
    ? `Kirim perbaikan putaran ke-${round}? Setelah dikirim, admin LPPM akan meninjau ulang proposal Anda.`
    : `Submit revision round ${round}? LPPM admin will re-review your proposal.`);
}

// ── Language toggle ──────────────────────────────────────────
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1]||'id';
  document.cookie='lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}

// Init
updateProgress();
</script>
</body>
</html>
