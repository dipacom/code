<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';
$tahun = (int)(getSetting($pdo,'penelitian_tahun') ?: date('Y'));

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Upload kontrak
    if ($_POST['action'] === 'upload_kontrak') {
        $pid             = (int)($_POST['proposal_id'] ?? 0);
        $nomor_kontrak   = clean($_POST['nomor_kontrak'] ?? '');
        $tgl_kontrak     = clean($_POST['tgl_kontrak']   ?? '');
        $deadline_laporan= clean($_POST['deadline_laporan'] ?? '');

        if (!$pid) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Proposal tidak valid.'];
            redirect('/admin/penelitian_kontrak.php');
        }

        // Kontrak hanya untuk proposal yang telah DISETUJUI di seleksi substantif
        $p_check = $pdo->prepare("SELECT id, status, user_id, judul FROM usulan_penelitian WHERE id=? AND tahun_anggaran=?");
        $p_check->execute([$pid, $tahun]);
        $prop = $p_check->fetch();

        if (!$prop || !in_array($prop['status'], ['disetujui','penandatanganan_kontrak','kontrak_aktif'])) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Kontrak hanya dapat dibuat untuk proposal yang telah disetujui pada seleksi substantif.'];
            redirect('/admin/penelitian_kontrak.php');
        }

        // Upload file kontrak
        $file_kontrak = null;
        $file_kontrak_name = null;

        if (!empty($_FILES['file_kontrak']['name'])) {
            $f = $_FILES['file_kontrak'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']);
            $allowed_mimes = [
                'application/pdf',
                'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            
            if (!in_array($ext, ['pdf','doc','docx']) || !in_array($mime, $allowed_mimes)) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'File kontrak harus PDF/DOC/DOCX yang valid.'];
                redirect('/admin/penelitian_kontrak.php');
            }
            if ($f['size'] > 20 * 1024 * 1024) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Ukuran file maks. 20 MB.'];
                redirect('/admin/penelitian_kontrak.php');
            }
            $dir = BASE_PATH . '/uploads/kontrak_penelitian/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = time() . '_kontrak_' . $pid . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $f['name']);
            if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                $file_kontrak      = 'uploads/kontrak_penelitian/' . $fname;
                $file_kontrak_name = $f['name'];
            } else {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Gagal menyimpan file kontrak.'];
                redirect('/admin/penelitian_kontrak.php');
            }
        }

        // Upsert kontrak
        $existing = $pdo->prepare("SELECT id, file_kontrak FROM kontrak_penelitian WHERE usulan_id=?");
        $existing->execute([$pid]);
        $ex = $existing->fetch();

        if ($ex) {
            // Hapus file lama jika ada file baru
            if ($file_kontrak && $ex['file_kontrak'] && file_exists(BASE_PATH . '/' . $ex['file_kontrak'])) {
                unlink(BASE_PATH . '/' . $ex['file_kontrak']);
            }
            $pdo->prepare("
                UPDATE kontrak_penelitian SET
                  nomor_kontrak=?, tgl_kontrak=?, deadline_laporan=?,
                  file_kontrak=COALESCE(?,file_kontrak),
                  file_kontrak_name=COALESCE(?,file_kontrak_name),
                  admin_id=?, uploaded_at=NOW()
                WHERE usulan_id=?
            ")->execute([
                $nomor_kontrak ?: null,
                $tgl_kontrak ?: null,
                $deadline_laporan ?: null,
                $file_kontrak,
                $file_kontrak_name,
                $_SESSION['user_id'],
                $pid,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO kontrak_penelitian (usulan_id, nomor_kontrak, tgl_kontrak, deadline_laporan, file_kontrak, file_kontrak_name, admin_id, uploaded_at)
                VALUES (?,?,?,?,?,?,?,NOW())
            ")->execute([
                $pid,
                $nomor_kontrak ?: null,
                $tgl_kontrak ?: null,
                $deadline_laporan ?: null,
                $file_kontrak,
                $file_kontrak_name,
                $_SESSION['user_id'],
            ]);
        }

        // Transisi status: disetujui → penandatanganan_kontrak (menunggu peneliti tanda tangan)
        // Jika sudah kontrak_aktif, tidak perlu mundur; biarkan update saja
        if ($prop['status'] === 'disetujui') {
            $pdo->prepare("UPDATE usulan_penelitian SET status='penandatanganan_kontrak', updated_at=NOW() WHERE id=?")
                ->execute([$pid]);
        }

        // Notifikasi ke dosen
        $jdl_notif = $id?'Kontrak Penelitian Siap':'Research Contract Ready';
        $msg_notif = $id
            ? 'Kontrak penelitian Anda telah disiapkan oleh LPPM. Silakan login untuk melihat dan mengunduh kontrak, kemudian serahkan kontrak yang sudah ditandatangani ke kantor LPPM.'
            : 'Your research contract is ready. Please log in to view and download it, then submit the signed version to the LPPM office.';
        $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
            ->execute([$prop['user_id'], $jdl_notif, $msg_notif, 'sukses']);

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => $id
                ? 'Kontrak berhasil disimpan. Proposal masuk ke tahap penandatanganan kontrak.'
                : 'Contract saved. Proposal moved to contract signing stage.',
        ];
        redirect('/admin/penelitian_kontrak.php');
    }

    // Aktivasi kontrak: setelah peneliti menandatangani & menyerahkan hardcopy ke LPPM
    if ($_POST['action'] === 'aktivasi_kontrak') {
        $pid = (int)($_POST['proposal_id'] ?? 0);

        $p_check = $pdo->prepare("
            SELECT up.id, up.status, up.user_id, up.judul,
                   kp.id AS kp_id, kp.file_kontrak
            FROM usulan_penelitian up
            LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
            WHERE up.id=? AND up.tahun_anggaran=?
        ");
        $p_check->execute([$pid, $tahun]);
        $prop = $p_check->fetch();

        if (!$prop || $prop['status'] !== 'penandatanganan_kontrak') {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Proposal tidak dalam tahap penandatanganan kontrak.'];
            redirect('/admin/penelitian_kontrak.php');
        }
        if (!$prop['kp_id'] || !$prop['file_kontrak']) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'File kontrak belum diupload.'];
            redirect('/admin/penelitian_kontrak.php');
        }

        $pdo->prepare("UPDATE usulan_penelitian SET status='kontrak_aktif', updated_at=NOW() WHERE id=?")
            ->execute([$pid]);

        $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
            ->execute([$prop['user_id'],
                $id?'Kontrak Penelitian Aktif':'Research Contract Active',
                $id?'Kontrak penelitian Anda kini aktif. Silakan mulai pelaksanaan penelitian sesuai dengan ketentuan kontrak.'
                   :'Your research contract is now active. Please begin research according to the contract terms.',
                'sukses']);

        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Kontrak diaktifkan. Proposal memasuki tahap pelaksanaan.':'Contract activated.'];
        redirect('/admin/penelitian_kontrak.php?status=kontrak_aktif');
    }
}

// ── Load data ──────────────────────────────────────────────────
$filter_status = clean($_GET['status'] ?? 'disetujui');
$q = clean($_GET['q'] ?? '');

$valid_statuses = ['disetujui','penandatanganan_kontrak','kontrak_aktif'];
if (!in_array($filter_status, $valid_statuses)) $filter_status = 'disetujui';

$where = ["up.deleted_at IS NULL", "up.tahun_anggaran=$tahun", "up.status=?"];
$params = [$filter_status];
if ($q) {
    $where[] = "(u.nama_lengkap LIKE ? OR up.judul LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}

$proposals = $pdo->prepare("
    SELECT up.*, u.nama_lengkap, u.nidn, u.program_studi,
           kp.id as kp_id, kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan,
           kp.file_kontrak, kp.file_kontrak_name, kp.uploaded_at as kp_uploaded
    FROM usulan_penelitian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id AND (kp.deleted_at IS NULL OR kp.deleted_at IS NULL)
    WHERE ".implode(' AND ',$where)."
    ORDER BY up.reviewed_at DESC
");
$proposals->execute($params);
$proposals = $proposals->fetchAll();

// Count per status
$cnt_disetujui = $pdo->query("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND tahun_anggaran=$tahun AND status='disetujui'")->fetchColumn();
$cnt_kontrak   = $pdo->query("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND tahun_anggaran=$tahun AND status='penandatanganan_kontrak'")->fetchColumn();
$cnt_aktif     = $pdo->query("SELECT COUNT(*) FROM usulan_penelitian WHERE deleted_at IS NULL AND tahun_anggaran=$tahun AND status='kontrak_aktif'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kontrak Penelitian — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.ktab { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.ktab-btn {
  padding:7px 15px; border-radius:20px; font-size:12px; font-weight:600;
  border:1.5px solid var(--border); background:var(--bg-card); cursor:pointer;
  color:var(--text-muted); text-decoration:none; display:inline-flex; align-items:center; gap:5px;
}
.ktab-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.rpa-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.rpa-table th {
  background:var(--bg-field); color:var(--text-muted); font-weight:600;
  font-size:11px; text-transform:uppercase; letter-spacing:.4px;
  padding:9px 12px; text-align:left; border-bottom:1.5px solid var(--border); white-space:nowrap;
}
.rpa-table td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
.rpa-table tr:hover td { background:var(--bg-hover); }
.rpa-judul { font-weight:600; color:var(--text-primary); max-width:240px; line-height:1.4; }
.rpa-meta  { font-size:11px; color:var(--text-muted); margin-top:2px; }

/* Upload modal */
.modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:900;
  backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border-radius:16px; width:480px; max-width:95vw;
  box-shadow:0 8px 40px rgba(0,0,0,.18); }
.modal-head { padding:18px 20px 14px; border-bottom:1.5px solid var(--border);
  display:flex; align-items:center; gap:12px; }
.modal-body { padding:20px; }
.modal-foot { padding:14px 20px; border-top:1.5px solid var(--border);
  display:flex; gap:8px; justify-content:flex-end; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('doc') ?>
          <?= $id?'Kontrak Penelitian':'Research Contracts' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/admin/penelitian.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Semua Proposal':'All Proposals' ?>
        </a>
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:16px">
        <?= ic($fl['type']==='success'?'check-circle':'alert') ?>
        <?= htmlspecialchars($fl['msg']) ?>
      </div>
      <?php endif; ?>

      <!-- Info -->
      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:12.5px;color:#1e40af">
        <?= ic('info','style="width:15px;height:15px;flex-shrink:0"') ?>
        <div style="display:inline">
          <?= $id
            ? 'Kontrak penelitian hanya dibuat untuk proposal yang telah <b>disetujui</b> pada tahap seleksi substantif. Alur: <b>Disetujui</b> → admin upload kontrak → <b>Penandatanganan</b> (peneliti datang ke LPPM menandatangani kontrak) → admin aktivasi → <b>Kontrak Aktif</b> (penelitian dilaksanakan).'
            : 'Contracts are only created for proposals <b>approved</b> in the substantive review. Flow: <b>Approved</b> → admin uploads contract → <b>Signing</b> (proposer visits LPPM to sign) → admin activates → <b>Active</b> (research underway).'
          ?>
        </div>
      </div>

      <!-- Tabs -->
      <div class="ktab">
        <a href="?status=disetujui" class="ktab-btn <?= $filter_status==='disetujui'?'active':'' ?>">
          <?= $id?'Menunggu Kontrak':'Awaiting Contract' ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:10px;font-size:10px"><?= $cnt_disetujui ?></span>
        </a>
        <a href="?status=penandatanganan_kontrak" class="ktab-btn <?= $filter_status==='penandatanganan_kontrak'?'active':'' ?>">
          <?= $id?'Proses Tanda Tangan':'Signing Process' ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:10px;font-size:10px"><?= $cnt_kontrak ?></span>
        </a>
        <a href="?status=kontrak_aktif" class="ktab-btn <?= $filter_status==='kontrak_aktif'?'active':'' ?>">
          <?= $id?'Kontrak Aktif':'Active Contract' ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:10px;font-size:10px"><?= $cnt_aktif ?></span>
        </a>
      </div>

      <!-- Filter -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
        <input type="text" name="q" class="form-control" style="width:220px;font-size:12.5px"
               value="<?= htmlspecialchars($q) ?>" placeholder="<?= $id?'Cari nama / judul...':'Search...' ?>">
        <button type="submit" class="btn btn-outline" style="font-size:12px"><?= ic('search') ?></button>
      </form>

      <!-- Table -->
      <div class="card" style="padding:0;overflow:hidden">
        <div style="overflow-x:auto">
          <table class="rpa-table">
            <thead>
              <tr>
                <th>#</th>
                <th><?= $id?'Proposal':'Proposal' ?></th>
                <th><?= $id?'Pengusul':'Proposer' ?></th>
                <th><?= $id?'Nomor & Tgl Kontrak':'Contract No. & Date' ?></th>
                <th><?= $id?'Deadline Laporan':'Report Deadline' ?></th>
                <th><?= $id?'File Kontrak':'Contract File' ?></th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($proposals)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">
              <?= ic('inbox') ?><div style="margin-top:8px"><?= $id?'Tidak ada data.':'No data.' ?></div>
            </td></tr>
            <?php else: foreach ($proposals as $i => $p): ?>
            <tr>
              <td style="font-size:11px;color:var(--text-muted)"><?= $i+1 ?></td>
              <td>
                <div class="rpa-judul"><?= htmlspecialchars(mb_strimwidth($p['judul'],0,65,'…')) ?></div>
                <div class="rpa-meta">
                  <?= htmlspecialchars(strtoupper($p['skema'])) ?> · <?= $tahun ?>
                </div>
              </td>
              <td>
                <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($p['nama_lengkap']) ?></div>
                <div class="rpa-meta"><?= htmlspecialchars($p['program_studi']??'-') ?></div>
              </td>
              <td>
                <?php if ($p['nomor_kontrak']): ?>
                <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($p['nomor_kontrak']) ?></div>
                <?php if ($p['tgl_kontrak']): ?>
                <div class="rpa-meta"><?= date('d M Y', strtotime($p['tgl_kontrak'])) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($p['deadline_laporan'])): ?>
                  <?php
                    $dl_ts = strtotime($p['deadline_laporan']);
                    $now_ts = time();
                    $overdue = $dl_ts < $now_ts;
                  ?>
                  <div style="font-size:12px;font-weight:600;color:<?= $overdue?'#dc2626':'#065f46' ?>">
                    <?= date('d M Y', $dl_ts) ?>
                  </div>
                  <div class="rpa-meta"><?= date('H:i', $dl_ts) ?> WITA<?= $overdue?' · '.($id?'Terlewat':'Overdue'):'' ?></div>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($p['file_kontrak']): ?>
                <a href="<?= BASE_URL ?>/<?= htmlspecialchars($p['file_kontrak']) ?>" target="_blank"
                   class="btn btn-outline" style="font-size:11.5px;padding:4px 10px">
                  <?= ic('download') ?> <?= htmlspecialchars(mb_strimwidth($p['file_kontrak_name']??'Kontrak',0,25,'…')) ?>
                </a>
                <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px"><?= $id?'Belum diupload':'Not uploaded' ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <button class="btn btn-outline" style="font-size:11.5px;padding:5px 11px"
                          onclick="openUpload(<?= htmlspecialchars(json_encode([
                            'id'=>(int)$p['id'],
                            'judul'=>$p['judul'],
                            'nomor_kontrak'=>$p['nomor_kontrak']??'',
                            'tgl_kontrak'=>$p['tgl_kontrak']??'',
                            'deadline_laporan'=>$p['deadline_laporan']??'',
                            'has_file'=>(bool)$p['file_kontrak'],
                          ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)">
                    <?= ic('upload') ?> <?= $p['file_kontrak']?($id?'Perbarui':'Update'):($id?'Upload':'Upload') ?>
                  </button>
                  <?php if ($p['status']==='penandatanganan_kontrak' && $p['file_kontrak']): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('<?= $id?'Aktifkan kontrak ini? Proposal akan masuk tahap pelaksanaan.':'Activate contract? Proposal will move to execution phase.' ?>')">
                    <input type="hidden" name="action" value="aktivasi_kontrak">
                    <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="font-size:11.5px;padding:5px 11px">
                      <?= ic('check') ?> <?= $id?'Aktivasi':'Activate' ?>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal-overlay" id="uploadModal" onclick="if(event.target===this)closeUpload()">
  <div class="modal-box">
    <div class="modal-head">
      <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#3b82f6);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <?= ic('upload','style="width:18px;height:18px;color:#fff"') ?>
      </div>
      <div>
        <div style="font-size:14px;font-weight:700"><?= $id?'Upload Kontrak Penelitian':'Upload Research Contract' ?></div>
        <div id="modal-subtitle" style="font-size:11.5px;color:var(--text-muted)"></div>
      </div>
      <button onclick="closeUpload()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--text-muted)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="action" value="upload_kontrak">
      <input type="hidden" name="proposal_id" id="modal-pid">
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label"><?= $id?'Nomor Kontrak':'Contract Number' ?></label>
          <input type="text" name="nomor_kontrak" id="modal-nomor" class="form-control"
                 placeholder="<?= $id?'Contoh: 001/LPPM/IAKNT/2026':'e.g. 001/LPPM/IAKNT/2026' ?>">
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label"><?= $id?'Tanggal Kontrak':'Contract Date' ?></label>
          <input type="date" name="tgl_kontrak" id="modal-tgl" class="form-control">
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">
            <?= $id?'Deadline Laporan Penelitian':'Research Report Deadline' ?>
            <span style="color:var(--text-muted);font-weight:400"> — <?= $id?'dengan jam & menit':'with time' ?></span>
          </label>
          <input type="datetime-local" name="deadline_laporan" id="modal-deadline" class="form-control">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
            <?= $id?'Batas akhir pengusul mengirim laporan hasil penelitian. Akan tampil pada dashboard peneliti.':'Deadline for proposer to submit research report. Displayed on researcher dashboard.' ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">
            <?= $id?'File Kontrak (PDF/DOC/DOCX)':'Contract File (PDF/DOC/DOCX)' ?>
            <span style="color:var(--text-muted);font-weight:400"> — <?= $id?'opsional':'optional' ?></span>
          </label>
          <div onclick="document.getElementById('kontrakFile').click()"
               style="border:1.5px dashed var(--border);border-radius:9px;padding:16px;text-align:center;cursor:pointer;background:var(--bg-field)">
            <input type="file" id="kontrakFile" name="file_kontrak"
                   accept=".pdf,.doc,.docx" onchange="showKontrakFile(this)" style="display:none">
            <div><?= ic('upload','style="color:var(--text-muted)"') ?></div>
            <div id="kontrak-file-label" style="font-size:12.5px;color:var(--text-muted);margin-top:6px">
              <?= $id?'Klik untuk memilih file kontrak yang sudah ditandatangani':'Click to select the signed contract file' ?>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px">PDF / DOC / DOCX · Maks. 20 MB</div>
          </div>
        </div>
        <div style="background:#fef9c3;border:1px solid #fde047;border-radius:9px;padding:10px 13px;font-size:12px;color:#713f12;margin-top:12px">
          <?= ic('alert','style="width:14px;height:14px;flex-shrink:0"') ?>
          <span style="margin-left:6px"><?= $id
            ? 'Setelah kontrak disimpan, status proposal bergerak ke Penandatanganan Kontrak. Peneliti wajib datang ke LPPM, menandatangani hardcopy kontrak, lalu admin klik tombol "Aktivasi" agar kontrak aktif.'
            : 'After saving, proposal moves to Contract Signing. Proposer must visit LPPM to sign the hardcopy; admin then clicks "Activate" to make the contract active.'
          ?></span>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeUpload()" class="btn btn-outline"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><?= ic('upload') ?> <?= $id?'Simpan':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openUpload(data) {
  document.getElementById('modal-pid').value = data.id;
  document.getElementById('modal-subtitle').textContent = data.judul.substring(0,60) + (data.judul.length>60?'…':'');
  document.getElementById('modal-nomor').value = data.nomor_kontrak || '';
  document.getElementById('modal-tgl').value = data.tgl_kontrak || '';
  // deadline_laporan format: YYYY-MM-DD HH:MM:SS → YYYY-MM-DDTHH:MM (datetime-local)
  const dl = (data.deadline_laporan || '').replace(' ', 'T').slice(0, 16);
  document.getElementById('modal-deadline').value = dl;
  document.getElementById('uploadModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeUpload() {
  document.getElementById('uploadModal').classList.remove('open');
  document.body.style.overflow = '';
  document.getElementById('uploadForm').reset();
  document.getElementById('kontrak-file-label').textContent = '<?= $id?'Klik untuk memilih file kontrak yang sudah ditandatangani':'Click to select the signed contract file' ?>';
}
function showKontrakFile(input) {
  const f = input.files[0];
  if (f) document.getElementById('kontrak-file-label').textContent = '✓ ' + f.name;
}
document.addEventListener('keydown', e => { if (e.key==='Escape') closeUpload(); });
</script>
</body>
</html>
