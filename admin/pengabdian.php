<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';

$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

// ── AJAX/POST actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Toggle skema buka/tutup
    if ($_POST['action'] === 'toggle_skema') {
        $skema_id = (int)($_POST['skema_id'] ?? 0);
        if ($skema_id) {
            $cur = (int)$pdo->query("SELECT is_open FROM skema_pengabdian WHERE id=$skema_id")->fetchColumn();
            $pdo->prepare("UPDATE skema_pengabdian SET is_open=? WHERE id=?")->execute([$cur ? 0 : 1, $skema_id]);
        }
        redirect('/admin/pengabdian.php');
    }

    // Review: approve / revisi / tolak
    if ($_POST['action'] === 'review') {
        $pid    = (int)$_POST['proposal_id'];
        $status = in_array($_POST['status'],['ditinjau','disetujui','direvisi','ditolak'])
                  ? $_POST['status'] : '';
        $catatan = clean($_POST['catatan'] ?? '');
        if ($pid && $status) {
            $pdo->prepare("
                UPDATE usulan_pengabdian SET status=?, catatan_reviewer=?,
                  reviewed_by=?, reviewed_at=NOW()
                WHERE id=?
            ")->execute([$status, $catatan, $_SESSION['user_id'], $pid]);

            // Notifikasi ke dosen pengusul
            $proposal = $pdo->query("
                SELECT up.*, u.email, u.nama_lengkap, u.id as dosen_id
                FROM usulan_pengabdian up JOIN users u ON up.user_id=u.id
                WHERE up.id=$pid")->fetch();

            if ($proposal) {
                $judul_notif = match($status) {
                    'disetujui' => $id?'Proposal Pengabdian Disetujui':'Community Service Proposal Approved',
                    'direvisi'  => $id?'Proposal PkM Perlu Direvisi':'PkM Proposal Requires Revision',
                    'ditolak'   => $id?'Proposal Pengabdian Ditolak':'Community Service Proposal Rejected',
                    default     => $id?'Status Proposal PkM Diperbarui':'PkM Proposal Status Updated',
                };
                $tipe = match($status) {
                    'disetujui' => 'sukses',
                    'direvisi'  => 'peringatan',
                    'ditolak'   => 'error',
                    default     => 'info',
                };
                $pesan = ($id?'Proposal PkM "':'PkM Proposal "').mb_strimwidth($proposal['judul'],0,80,'...')
                       . '"'. ($id?' telah diperbarui menjadi: ':' has been updated to: ')
                       . ucfirst($status) . '.';
                if ($catatan) $pesan .= ' '.$catatan;
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$proposal['dosen_id'], $judul_notif, $pesan, $tipe]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true]);
        exit;
    }
}

// ── Filter & query ────────────────────────────────────────────
$filter_status = clean($_GET['status'] ?? '');
$filter_skema  = clean($_GET['skema']  ?? '');
$q             = clean($_GET['q']      ?? '');

$where = ["up.deleted_at IS NULL", "up.tahun_anggaran=$tahun"];
$params = [];
if ($filter_status) { $where[] = "up.status=?"; $params[] = $filter_status; }
if ($filter_skema)  { $where[] = "up.skema=?";  $params[] = $filter_skema; }
if ($q) {
    $where[] = "(u.nama_lengkap LIKE ? OR u.nidn LIKE ? OR MATCH(up.judul, up.abstrak) AGAINST(? IN NATURAL LANGUAGE MODE))";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = $q;
}

$proposals = [];
try {
    $sql = "SELECT up.*, u.nama_lengkap, u.nidn, u.program_studi, u.fakultas, u.jabatan_fungsional
            FROM usulan_pengabdian up
            JOIN users u ON up.user_id = u.id
            WHERE ".implode(' AND ', $where)."
            ORDER BY up.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $proposals = $stmt->fetchAll();
} catch (\Exception $e) {
    error_log("Error fetching pengabdian proposals: " . $e->getMessage());
}

// Statistik
$stats = ['total'=>0,'n_baru'=>0,'n_tinjauan'=>0,'n_lolos_admin'=>0,'n_gagal_admin'=>0,'n_proses'=>0,'n_setuju'=>0,'n_revisi'=>0,'n_tolak'=>0];
try {
    $st = $pdo->query("
        SELECT
          COUNT(*) total,
          SUM(status='diajukan')  n_baru,
          SUM(status IN ('seleksi_admin_pengabdian','lolos_admin')) n_tinjauan,
          SUM(status='lolos_admin') n_lolos_admin,
          SUM(status='gagal_admin') n_gagal_admin,
          SUM(status IN ('penandatanganan_kontrak','seleksi_substansi')) n_proses,
          SUM(status='disetujui') n_setuju,
          SUM(status IN ('revisi_minor','revisi_mayor','direvisi')) n_revisi,
          SUM(status='ditolak')   n_tolak
        FROM usulan_pengabdian
        WHERE deleted_at IS NULL AND tahun_anggaran=$tahun
    ")->fetch();
    if ($st) $stats = $st;
} catch (\Exception $e) {
    error_log("Error fetching pengabdian stats: " . $e->getMessage());
}

$deadline = getSetting($pdo,'pengabdian_deadline') ?: date('Y').'-12-31';

// Load skema pengabdian dari DB
$skema_rows_admin = [];
try {
    $sq = $pdo->prepare("SELECT * FROM skema_pengabdian WHERE tahun=? ORDER BY urutan ASC, id ASC");
    $sq->execute([$tahun]);
    $skema_rows_admin = $sq->fetchAll();
} catch (\Exception $e) {}

$statusBadge = fn($s) => match($s) {
    'draft'                    => ['bg'=>'#f1f5f9','color'=>'#64748b', 'label_id'=>'Draft',              'label_en'=>'Draft'],
    'diajukan'                 => ['bg'=>'#eff6ff','color'=>'#2563eb', 'label_id'=>'Diajukan',           'label_en'=>'Submitted'],
    'seleksi_admin_pengabdian'            => ['bg'=>'#fef9c3','color'=>'#92400e', 'label_id'=>'Seleksi Admin',      'label_en'=>'Admin Review'],
    'lolos_admin'              => ['bg'=>'#ecfdf5','color'=>'#065f46', 'label_id'=>'Lolos Admin',        'label_en'=>'Passed Admin'],
    'gagal_admin'              => ['bg'=>'#fef2f2','color'=>'#991b1b', 'label_id'=>'Gagal Admin',        'label_en'=>'Failed Admin'],
    'penandatanganan_kontrak'  => ['bg'=>'#eff6ff','color'=>'#1e40af', 'label_id'=>'Tanda Tangan Kontrak','label_en'=>'Sign Contract'],
    'seleksi_substansi'        => ['bg'=>'#faf5ff','color'=>'#5b21b6', 'label_id'=>'Seleksi Substantif', 'label_en'=>'Substantive Review'],
    'ditinjau'                 => ['bg'=>'#fef9c3','color'=>'#ca8a04', 'label_id'=>'Ditinjau',           'label_en'=>'Under Review'],
    'disetujui'                => ['bg'=>'#f0fdf4','color'=>'#16a34a', 'label_id'=>'Disetujui',          'label_en'=>'Approved'],
    'direvisi'                 => ['bg'=>'#fff7ed','color'=>'#ea580c', 'label_id'=>'Revisi',             'label_en'=>'Revision'],
    'revisi_minor'             => ['bg'=>'#fef9c3','color'=>'#ca8a04', 'label_id'=>'Revisi Minor',       'label_en'=>'Minor Revision'],
    'revisi_mayor'             => ['bg'=>'#fff7ed','color'=>'#ea580c', 'label_id'=>'Revisi Mayor',       'label_en'=>'Major Revision'],
    'ditolak'                  => ['bg'=>'#fef2f2','color'=>'#dc2626', 'label_id'=>'Ditolak',            'label_en'=>'Rejected'],
    default                    => ['bg'=>'#f1f5f9','color'=>'#64748b', 'label_id'=>$s,                   'label_en'=>$s],
};
$jabatanLabel = fn($j) => match($j) {
    'asisten_ahli'  => 'Asisten Ahli',
    'lektor'        => 'Lektor',
    'lektor_kepala' => 'Lektor Kepala',
    'guru_besar'    => 'Guru Besar',
    default         => '-',
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Usulan Pengabdian':'Community Service Proposals' ?> (Admin) — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
/* Stat cards */
.rpa-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
@media(max-width:900px){ .rpa-stats { grid-template-columns:repeat(3,1fr); } }
@media(max-width:560px){ .rpa-stats { grid-template-columns:1fr 1fr; } }
.rpa-stat { background:var(--bg-card); border:1.5px solid var(--border); border-radius:12px; padding:14px 16px; }
.rpa-stat-num  { font-size:26px; font-weight:800; line-height:1; }
.rpa-stat-lbl  { font-size:11px; color:var(--text-muted); margin-top:4px; }

/* Filter bar */
.rpa-filters { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; align-items:center; }
.rpa-filters select, .rpa-filters input { font-size:12.5px; }

/* Toggle switches */
.toggle-wrap {
  display:flex; align-items:center; gap:8px;
  background:var(--bg-card); border:1.5px solid var(--border);
  border-radius:10px; padding:10px 14px; margin-bottom:16px; flex-wrap:wrap; gap:12px;
}
.tgl-item { display:flex; align-items:center; gap:8px; font-size:12.5px; }
.tgl-sw {
  position:relative; display:inline-block; width:40px; height:22px;
}
.tgl-sw input { opacity:0; width:0; height:0; }
.tgl-slider {
  position:absolute; inset:0; background:#cbd5e1; border-radius:22px;
  cursor:pointer; transition:background .2s;
}
.tgl-slider::before {
  content:''; position:absolute; width:16px; height:16px; border-radius:50%;
  background:#fff; left:3px; bottom:3px; transition:transform .2s;
  box-shadow:0 1px 3px rgba(0,0,0,.2);
}
.tgl-sw input:checked + .tgl-slider { background:#0d9488; }
.tgl-sw input:checked + .tgl-slider::before { transform:translateX(18px); }

/* Table */
.rpa-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.rpa-table th {
  background:var(--bg-field); color:var(--text-muted); font-weight:600;
  font-size:11px; text-transform:uppercase; letter-spacing:.4px;
  padding:9px 12px; text-align:left; border-bottom:1.5px solid var(--border);
  white-space:nowrap;
}
.rpa-table td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
.rpa-table tr:hover td { background:var(--bg-hover); }
.rpa-judul { font-weight:600; color:var(--text-primary); max-width:260px; line-height:1.4; }
.rpa-meta  { font-size:11px; color:var(--text-muted); margin-top:2px; }
.skema-tag {
  display:inline-block; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;
  background:#ccfbf1; color:#0f766e;
}

/* Drawer */
.drawer-overlay {
  position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:900;
  backdrop-filter:blur(4px); display:none; align-items:flex-start; justify-content:flex-end;
}
.drawer-overlay.open { display:flex; }
.drawer {
  width:520px; max-width:100vw; height:100vh; overflow-y:auto;
  background:var(--bg-card); box-shadow:-4px 0 32px rgba(0,0,0,.18);
  display:flex; flex-direction:column;
}
.drawer-head {
  padding:18px 20px 14px; border-bottom:1.5px solid var(--border);
  display:flex; align-items:flex-start; gap:12px; position:sticky; top:0;
  background:var(--bg-card); z-index:1;
}
.drawer-body { padding:18px 20px; flex:1; }
.drawer-sec { margin-bottom:18px; }
.drawer-sec-title {
  font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;
  letter-spacing:.5px; margin-bottom:8px;
}
.dl-row { display:flex; justify-content:space-between; align-items:flex-start;
  padding:6px 0; border-bottom:1px solid var(--border); font-size:12.5px; gap:12px; }
.dl-row:last-child { border-bottom:none; }
.dl-key { color:var(--text-muted); flex-shrink:0; }
.dl-val { font-weight:600; color:var(--text-primary); text-align:right; }
.team-chip {
  display:inline-flex; align-items:center; gap:5px; padding:4px 9px;
  background:var(--bg-field); border:1px solid var(--border); border-radius:7px;
  font-size:11.5px; margin:3px;
}

/* Action buttons in drawer */
.action-btn {
  flex:1; display:flex; align-items:center; justify-content:center; gap:7px;
  padding:10px 14px; border-radius:9px; font-size:12.5px; font-weight:600;
  border:none; cursor:pointer; transition:filter .15s;
}
.action-btn:hover { filter:brightness(.9); }
.btn-setuju  { background:#16a34a; color:#fff; }
.btn-revisi  { background:#ea580c; color:#fff; }
.btn-tolak   { background:#dc2626; color:#fff; }
.btn-tinjauan{ background:#ca8a04; color:#fff; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('users') ?>
          <?= $id?'Usulan Pengabdian':'Community Service Proposals' ?>
          <span class="breadcrumb">LPPM IAKN Toraja — <?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- ── Stats ── -->
      <div class="rpa-stats">
        <div class="rpa-stat">
          <div class="rpa-stat-num" style="color:#0d9488"><?= (int)($stats['total'] ?? 0) ?></div>
          <div class="rpa-stat-lbl"><?= $id?'Total Proposal':'Total Proposals' ?></div>
        </div>
        <div class="rpa-stat">
          <div class="rpa-stat-num" style="color:#0284c7"><?= (int)($stats['n_baru'] ?? 0) ?></div>
          <div class="rpa-stat-lbl"><?= $id?'Diajukan':'Submitted' ?></div>
        </div>
        <div class="rpa-stat">
          <div class="rpa-stat-num" style="color:#ca8a04"><?= (int)($stats['n_tinjauan'] ?? 0) ?></div>
          <div class="rpa-stat-lbl"><?= $id?'Ditinjau':'Under Review' ?></div>
        </div>
        <div class="rpa-stat">
          <div class="rpa-stat-num" style="color:#16a34a"><?= (int)($stats['n_setuju'] ?? 0) ?></div>
          <div class="rpa-stat-lbl"><?= $id?'Disetujui':'Approved' ?></div>
        </div>
        <div class="rpa-stat">
          <div class="rpa-stat-num" style="color:#dc2626"><?= (int)($stats['n_tolak'] ?? 0) + (int)($stats['n_revisi'] ?? 0) ?></div>
          <div class="rpa-stat-lbl"><?= $id?'Ditolak/Revisi':'Rejected/Revision' ?></div>
        </div>
      </div>

      <!-- ── Toggle penerimaan ── -->
      <div class="toggle-wrap">
        <span style="font-size:12.5px;font-weight:700;color:var(--text-primary)">
          <?= $id?'Penerimaan Pengabdian:':'PkM Intake:' ?>
        </span>
        <?php if (!empty($skema_rows_admin)): ?>
        <?php foreach ($skema_rows_admin as $sk): ?>
        <form method="POST" style="display:inline-flex;align-items:center;gap:0">
          <input type="hidden" name="action" value="toggle_skema">
          <input type="hidden" name="skema_id" value="<?= $sk['id'] ?>">
          <div class="tgl-item">
            <label class="tgl-sw">
              <input type="checkbox" <?= $sk['is_open']?'checked':'' ?> onchange="this.form.submit()">
              <span class="tgl-slider"></span>
            </label>
            <span><?= htmlspecialchars(mb_strimwidth($sk['nama'],0,28,'…')) ?></span>
            <span style="font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:6px;
                         background:<?= $sk['is_open']?'#ccfbf1':'#fee2e2' ?>;
                         color:<?= $sk['is_open']?'#0f766e':'#dc2626' ?>">
              <?= $sk['is_open']?($id?'Buka':'Open'):($id?'Tutup':'Closed') ?>
            </span>
          </div>
        </form>
        <?php endforeach; ?>
        <?php else: ?>
        <span style="font-size:12px;color:var(--text-muted);font-style:italic">
          <?= $id?'Belum ada skema pengabdian dikonfigurasi.':'No community service schemes configured.' ?>
        </span>
        <?php endif; ?>
        <span style="font-size:11.5px;color:var(--text-muted);margin-left:auto">
          <?= $id?'Deadline':'Deadline' ?>: <strong><?= date('d M Y', strtotime($deadline)) ?></strong>
        </span>
      </div>

      <!-- ── Filter ── -->
      <form method="GET" class="rpa-filters">
        <input type="text" name="q" class="form-control" style="width:200px"
               value="<?= htmlspecialchars($q) ?>" placeholder="<?= $id?'Cari nama/judul...':'Search name/title...' ?>">
        <select name="status" class="form-control" style="width:140px" onchange="this.form.submit()">
          <option value=""><?= $id?'Semua Status':'All Statuses' ?></option>
          <option value="diajukan"  <?= $filter_status==='diajukan' ?'selected':'' ?>>Diajukan</option>
          <option value="ditinjau"  <?= $filter_status==='ditinjau' ?'selected':'' ?>>Ditinjau</option>
          <option value="disetujui" <?= $filter_status==='disetujui'?'selected':'' ?>>Disetujui</option>
          <option value="direvisi"  <?= $filter_status==='direvisi' ?'selected':'' ?>>Revisi</option>
          <option value="ditolak"   <?= $filter_status==='ditolak'  ?'selected':'' ?>>Ditolak</option>
          <option value="draft"     <?= $filter_status==='draft'    ?'selected':'' ?>>Draft</option>
        </select>
        <select name="skema" class="form-control" style="width:160px" onchange="this.form.submit()">
          <option value=""><?= $id?'Semua Skema':'All Schemes' ?></option>
          <?php foreach ($skema_rows_admin as $sk): ?>
          <option value="<?= htmlspecialchars($sk['kode']) ?>" <?= $filter_skema===$sk['kode']?'selected':'' ?>>
            <?= htmlspecialchars(mb_strimwidth($sk['nama'],0,30,'…')) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="font-size:12px;background:#0d9488;border:none">
          <?= ic('search') ?> <?= $id?'Cari':'Search' ?>
        </button>
        <?php if ($q||$filter_status||$filter_skema): ?>
        <a href="<?= BASE_URL ?>/admin/pengabdian.php" class="btn btn-outline" style="font-size:12px">
          <?= ic('x') ?> Reset
        </a>
        <?php endif; ?>
      </form>

      <!-- ── Table ── -->
      <div class="card" style="padding:0;border-radius:12px;overflow:hidden">
        <?php if (empty($proposals)): ?>
        <div style="padding:48px 24px;text-align:center;color:var(--text-muted);font-size:13px">
          <?= ic('clipboard') ?>
          <div style="margin-top:10px"><?= $id?'Belum ada proposal PkM.':'No PkM proposals found.' ?></div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="rpa-table">
          <thead>
            <tr>
              <th>#</th>
              <th><?= $id?'Ketua & Judul':'Lead & Title' ?></th>
              <th><?= $id?'Skema':'Scheme' ?></th>
              <th><?= $id?'Jabatan':'Rank' ?></th>
              <th><?= $id?'Tanggal':'Date' ?></th>
              <th><?= $id?'Status':'Status' ?></th>
              <th><?= $id?'Aksi':'Action' ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($proposals as $i => $p):
            $b = $statusBadge($p['status']);
            $lbl = $id ? $b['label_id'] : $b['label_en'];
          ?>
          <tr>
            <td style="color:var(--text-muted);font-size:11.5px"><?= $i+1 ?></td>
            <td>
              <div class="rpa-judul"><?= htmlspecialchars(mb_strimwidth($p['judul'],0,80,'...')) ?></div>
              <div class="rpa-meta">
                <?= htmlspecialchars($p['nama_lengkap']) ?>
                <?= $p['nidn']?'· NIDN: '.htmlspecialchars($p['nidn']):'' ?>
              </div>
              <?php if ($p['program_studi']): ?>
              <div class="rpa-meta"><?= htmlspecialchars($p['program_studi']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="skema-tag">
                <?= htmlspecialchars(strtoupper($p['skema'])) ?>
              </span>
            </td>
            <td style="font-size:12px"><?= $jabatanLabel($p['jabatan_ketua']??'') ?></td>
            <td style="font-size:11.5px;color:var(--text-muted);white-space:nowrap">
              <?= date('d M Y', strtotime($p['created_at'])) ?>
            </td>
            <td>
              <span style="background:<?= $b['bg'] ?>;color:<?= $b['color'] ?>;
                    border:1px solid <?= $b['color'] ?>30;
                    font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:7px">
                <?= $lbl ?>
              </span>
            </td>
            <td>
              <button class="btn btn-outline" style="font-size:11.5px;padding:5px 11px"
                      onclick="openDrawer(<?= htmlspecialchars(json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)">
                <?= ic('eye') ?> <?= $id?'Detail':'Detail' ?>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ── Drawer ── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer(event)">
  <div class="drawer" id="drawer">
    <div class="drawer-head">
      <div style="flex:1">
        <div style="font-size:15px;font-weight:800;color:var(--text-primary)" id="dTitle">—</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px" id="dSub">—</div>
      </div>
      <button onclick="closeDrawerBtn()" style="background:none;border:none;cursor:pointer;
              color:var(--text-muted);padding:4px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="drawer-body">

      <!-- Info proposal -->
      <div class="drawer-sec">
        <div class="drawer-sec-title"><?= $id?'Informasi Proposal':'Proposal Information' ?></div>
        <div id="dInfoRows"></div>
      </div>

      <!-- Tim pengusul -->
      <div class="drawer-sec">
        <div class="drawer-sec-title"><?= $id?'Tim Pengusul':'Proposer Team' ?></div>
        <div id="dTeam"></div>
      </div>

      <!-- File proposal -->
      <div class="drawer-sec" id="dFileSec" style="display:none">
        <div class="drawer-sec-title"><?= $id?'File Proposal':'Proposal File' ?></div>
        <div id="dFile"></div>
      </div>

      <!-- Abstrak -->
      <div class="drawer-sec" id="dAbstrakSec" style="display:none">
        <div class="drawer-sec-title"><?= $id?'Abstrak':'Abstract' ?></div>
        <div id="dAbstrak" style="font-size:12.5px;color:var(--text-primary);line-height:1.7;
             background:var(--bg-field);border-radius:8px;padding:10px 12px"></div>
      </div>

      <!-- Catatan reviewer sebelumnya -->
      <div class="drawer-sec" id="dNoteSec" style="display:none">
        <div class="drawer-sec-title"><?= $id?'Catatan LPPM':'LPPM Notes' ?></div>
        <div id="dNote" style="font-size:12.5px;color:var(--text-primary);line-height:1.7;
             background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px"></div>
      </div>

      <!-- Form review -->
      <div class="drawer-sec" id="dReviewForm">
        <div class="drawer-sec-title"><?= $id?'Tindakan Review':'Review Action' ?></div>
        <div class="form-group" style="margin-bottom:10px">
          <label class="form-label" style="font-size:12px"><?= $id?'Catatan untuk Pengusul (opsional)':'Notes for Proposer (optional)' ?></label>
          <textarea id="reviewCatatan" class="form-control" rows="3" style="resize:vertical;font-size:12.5px"
            placeholder="<?= $id?'Alasan keputusan, saran perbaikan, dll.':'Reason for decision, improvement suggestions, etc.' ?>"></textarea>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="action-btn btn-tinjauan" onclick="doReview('ditinjau')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
            <?= $id?'Tandai Ditinjau':'Mark Under Review' ?>
          </button>
          <button class="action-btn btn-setuju" onclick="doReview('disetujui')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <?= $id?'Setujui':'Approve' ?>
          </button>
          <button class="action-btn btn-revisi" onclick="doReview('direvisi')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            <?= $id?'Minta Revisi':'Request Revision' ?>
          </button>
          <button class="action-btn btn-tolak" onclick="doReview('ditolak')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
            <?= $id?'Tolak':'Reject' ?>
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode(BASE_URL) ?>;
const LANG = '<?= $lang ?>';
let currentId = null;

const jabMap = {
  asisten_ahli:'Asisten Ahli', lektor:'Lektor',
  lektor_kepala:'Lektor Kepala', guru_besar:'Guru Besar'
};
const statusMap = {
  draft:{id:'Draft',en:'Draft'},
  diajukan:{id:'Diajukan',en:'Submitted'},
  ditinjau:{id:'Ditinjau',en:'Under Review'},
  disetujui:{id:'Disetujui',en:'Approved'},
  direvisi:{id:'Revisi',en:'Revision'},
  ditolak:{id:'Ditolak',en:'Rejected'},
};

function openDrawer(p) {
  currentId = p.id;
  document.getElementById('dTitle').textContent = p.judul;
  document.getElementById('dSub').textContent =
    p.nama_lengkap + (p.nidn ? ' · NIDN: '+p.nidn : '') +
    ' · ' + p.skema.toUpperCase();

  // Info rows
  const sl = LANG==='id';
  const rows = [
    [sl?'Skema':'Scheme',     p.skema.toUpperCase()],
    [sl?'Tahun':'Year',       p.tahun_anggaran],
    [sl?'Fakultas':'Faculty', p.fakultas || '-'],
    [sl?'Prodi':'Program',    p.program_studi || '-'],
    ['NIDN',                  p.nidn || '-'],
    [sl?'Jabatan Ketua':'Chair Rank', jabMap[p.jabatan_ketua] || '-'],
    ['Google Scholar',        p.google_scholar_ketua
      ? '<a href="'+p.google_scholar_ketua+'" target="_blank" style="color:var(--primary)">'+p.google_scholar_ketua.substring(0,50)+'...</a>'
      : '-'],
    ['SINTA ID',              p.sinta_id_ketua || '-'],
    [sl?'Status':'Status',    '<span style="background:'+(statusMap[p.status]?'#ccfbf1':'#f1f5f9')+';color:'+(p.status==='disetujui'?'#0d9488':p.status==='ditolak'?'#dc2626':p.status==='diajukan'?'#0f766e':'#64748b')+';padding:2px 9px;border-radius:6px;font-size:11px;font-weight:700">'+(statusMap[p.status]?statusMap[p.status][sl?'id':'en']:p.status)+'</span>'],
    [sl?'Diajukan':'Submitted', p.created_at ? p.created_at.substring(0,10) : '-'],
  ];
  document.getElementById('dInfoRows').innerHTML = rows.map(([k,v])=>
    `<div class="dl-row"><span class="dl-key">${k}</span><span class="dl-val">${v}</span></div>`
  ).join('');

  // Tim
  let teamHtml = '';
  // Ketua
  teamHtml += `<div style="font-size:12px;color:var(--text-muted);margin-bottom:5px">${sl?'Ketua:':'Chair:'}</div>`;
  teamHtml += `<div class="team-chip" style="background:#f0fdfa;border-color:#ccfbf1">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    <span style="font-weight:700;color:#0d9488">${p.nama_ketua || p.nama_lengkap}</span>
  </div><br>`;
  // Anggota dosen
  let ad = [];
  try { ad = JSON.parse(p.anggota_dosen || '[]'); } catch(e) {}
  if (ad.length) {
    teamHtml += `<div style="font-size:12px;color:var(--text-muted);margin:8px 0 5px">${sl?'Anggota Dosen:':'Lecturer Members:'}</div>`;
    ad.forEach(d => {
      teamHtml += `<div class="team-chip">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        ${d.nama} ${d.nidn?'('+d.nidn+')':''} — ${jabMap[d.jabatan]||d.jabatan||''}
      </div>`;
    });
    teamHtml += '<br>';
  }
  // Anggota mahasiswa
  let am = [];
  try { am = JSON.parse(p.anggota_mahasiswa || '[]'); } catch(e) {}
  if (am.length) {
    teamHtml += `<div style="font-size:12px;color:var(--text-muted);margin:8px 0 5px">${sl?'Mahasiswa:':'Students:'}</div>`;
    am.forEach(m => {
      teamHtml += `<div class="team-chip">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        ${m.nama} ${m.nim?'('+m.nim+')':''} — Sem. ${m.semester||'-'}
      </div>`;
    });
  }
  // Anggota Mitra
  let mb = [];
  try { mb = JSON.parse(p.anggota_mitra || '[]'); } catch(e) {}
  if (mb.length) {
    teamHtml += `<div style="font-size:12px;color:var(--text-muted);margin:8px 0 5px">${sl?'Mitra Bestari:':'External Members:'}</div>`;
    mb.forEach(m => {
      teamHtml += `<div class="team-chip">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        ${m.nama} ${m.instansi?'('+m.instansi+')':''}
      </div>`;
    });
  }
  document.getElementById('dTeam').innerHTML = teamHtml;

  // File
  if (p.file_proposal) {
    document.getElementById('dFileSec').style.display = 'block';
    const sizeMB = p.file_proposal_size ? (p.file_proposal_size/1048576).toFixed(2)+' MB' : '-';
    document.getElementById('dFile').innerHTML = `
      <div class="dl-row" style="border:none">
        <span class="dl-key">${p.file_proposal_name || (sl?'File Proposal':'Proposal File')}</span>
        <a href="${BASE}/${p.file_proposal}" target="_blank"
           class="btn btn-outline" style="font-size:11.5px;padding:5px 11px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          ${sl?'Unduh':'Download'} (${sizeMB})
        </a>
      </div>`;
  } else {
    document.getElementById('dFileSec').style.display = 'none';
  }

  // Abstrak
  if (p.abstrak) {
    document.getElementById('dAbstrakSec').style.display = 'block';
    document.getElementById('dAbstrak').textContent = p.abstrak;
  } else {
    document.getElementById('dAbstrakSec').style.display = 'none';
  }

  // Catatan reviewer
  if (p.catatan_reviewer) {
    document.getElementById('dNoteSec').style.display = 'block';
    document.getElementById('dNote').textContent = p.catatan_reviewer;
  } else {
    document.getElementById('dNoteSec').style.display = 'none';
  }

  document.getElementById('reviewCatatan').value = '';
  document.getElementById('drawerOverlay').classList.add('open');
}

function closeDrawer(e) {
  if (e.target === document.getElementById('drawerOverlay')) closeDrawerBtn();
}
function closeDrawerBtn() {
  document.getElementById('drawerOverlay').classList.remove('open');
}

async function doReview(status) {
  const catatan = document.getElementById('reviewCatatan').value.trim();
  const confirm_msgs = {
    disetujui: LANG==='id'?'Setujui proposal ini?':'Approve this proposal?',
    direvisi:  LANG==='id'?'Minta revisi untuk proposal ini?':'Request revision for this proposal?',
    ditolak:   LANG==='id'?'Tolak proposal ini? Tindakan ini tidak dapat dibatalkan.':'Reject this proposal? This action cannot be undone.',
    ditinjau:  LANG==='id'?'Tandai sebagai sedang ditinjau?':'Mark as under review?',
  };
  if (!confirm(confirm_msgs[status])) return;

  const fd = new FormData();
  fd.append('action', 'review');
  fd.append('proposal_id', currentId);
  fd.append('status', status);
  fd.append('catatan', catatan);

  try {
    const res = await fetch(window.location.href, {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      closeDrawerBtn();
      location.reload();
    }
  } catch(e) {
    alert(LANG==='id'?'Gagal menyimpan. Coba lagi.':'Failed to save. Please try again.');
  }
}
</script>
<script>function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}</script>
</body>
</html>
