<?php
require_once '../includes/config.php';
requireAdminOrDelegate('seleksi_admin_pengabdian');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';
$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

// Default checklist items untuk PkM
$DEFAULT_CHECKLIST = [
    ['key'=>'file_terupload',    'label'=>'File proposal pengabdian sudah diunggah'],
    ['key'=>'format_nama_file',  'label'=>'Nama file sesuai format yang ditentukan LPPM'],
    ['key'=>'nidn_lengkap',      'label'=>'NIDN/data identitas ketua & anggota lengkap'],
    ['key'=>'scholar_sinta',     'label'=>'Ketua memiliki akun Google Scholar & SINTA yang valid'],
    ['key'=>'jabatan_min',       'label'=>'Jabatan fungsional ketua memenuhi syarat minimum skema PkM'],
    ['key'=>'komposisi_tim',     'label'=>'Komposisi tim (jumlah dosen/mahasiswa/mitra) sesuai skema'],
    ['key'=>'homebase_sama',     'label'=>'Ketua dan anggota dari homebase/fakultas yang sama'],
    ['key'=>'sesuai_template',   'label'=>'Proposal menggunakan template PkM yang disediakan LPPM'],
    ['key'=>'pernyataan_ok',     'label'=>'Pernyataan kesanggupan sudah disetujui pengusul'],
];

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Simpan hasil seleksi administratif
    if ($_POST['action'] === 'seleksi') {
        $pid       = (int)($_POST['proposal_id'] ?? 0);
        $keputusan = in_array($_POST['keputusan'], ['lolos','gagal']) ? $_POST['keputusan'] : '';
        $catatan   = clean($_POST['catatan'] ?? '');
        $checked   = $_POST['checklist'] ?? [];

        if ($pid && $keputusan) {
            $cl = [];
            foreach ($DEFAULT_CHECKLIST as $item) {
                $cl[$item['key']] = in_array($item['key'], $checked);
            }

            // Cek kuota jika lolos
            if ($keputusan === 'lolos') {
                $sk_info = $pdo->prepare("
                    SELECT up.skema, sk.kuota, sk.nama as sk_nama,
                           (SELECT COUNT(*) FROM usulan_pengabdian
                            WHERE skema=up.skema AND tahun_anggaran=up.tahun_anggaran
                            AND status='lolos_admin' AND deleted_at IS NULL) as sudah_lolos
                    FROM usulan_pengabdian up
                    JOIN skema_pengabdian sk ON sk.kode=up.skema AND sk.tahun=up.tahun_anggaran
                    WHERE up.id=?
                ");
                $sk_info->execute([$pid]);
                $skdata = $sk_info->fetch();

                if ($skdata && $skdata['sudah_lolos'] >= $skdata['kuota']) {
                    $_SESSION['flash'] = [
                        'type' => 'danger',
                        'msg'  => "Kuota skema \"{$skdata['sk_nama']}\" sudah penuh ({$skdata['kuota']} proposal). Tidak dapat meloloskan proposal ini.",
                    ];
                    header('Content-Type: application/json');
                    echo json_encode(['ok'=>false,'quota_full'=>true,'kuota'=>$skdata['kuota'],'nama'=>$skdata['sk_nama']]);
                    exit;
                }
            }

            $new_status = $keputusan === 'lolos' ? 'lolos_admin' : 'gagal_admin';

            // Upsert seleksi_admin_pengabdian
            $pdo->prepare("
                INSERT INTO seleksi_admin_pengabdian (usulan_id, admin_id, checklist, keputusan, catatan)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  admin_id=VALUES(admin_id), checklist=VALUES(checklist),
                  keputusan=VALUES(keputusan), catatan=VALUES(catatan), updated_at=NOW()
            ")->execute([$pid, $_SESSION['user_id'], json_encode($cl), $keputusan, $catatan]);

            // Update status proposal
            $pdo->prepare("
                UPDATE usulan_pengabdian SET status=?, catatan_reviewer=?, reviewed_by=?, reviewed_at=NOW()
                WHERE id=?
            ")->execute([$new_status, $catatan, $_SESSION['user_id'], $pid]);

            // Notifikasi ke dosen
            $proposal = $pdo->prepare("
                SELECT up.*, u.id as dosen_id, u.nama_lengkap
                FROM usulan_pengabdian up JOIN users u ON up.user_id=u.id
                WHERE up.id=?
            ");
            $proposal->execute([$pid]);
            $prop = $proposal->fetch();
            if ($prop) {
                $jdl  = $keputusan === 'lolos'
                    ? ($id?'Proposal PkM Lolos Seleksi Administratif':'PkM Proposal Passed Administrative Review')
                    : ($id?'Proposal PkM Tidak Lolos Seleksi Administratif':'PkM Proposal Failed Administrative Review');
                $msg  = ($id?'Proposal PkM "':'PkM Proposal "') . mb_strimwidth($prop['judul'],0,70,'...')
                      . '" ' . ($keputusan==='lolos'
                        ? ($id?'lolos seleksi administratif dan akan dilanjutkan ke tahap berikutnya.':'passed administrative review and will proceed to the next stage.')
                        : ($id?'tidak lolos seleksi administratif.':'did not pass administrative review.'));
                if ($catatan) $msg .= ' ' . ($id?'Catatan LPPM: ':'LPPM Note: ') . $catatan;
                $tipe = $keputusan === 'lolos' ? 'sukses' : 'error';
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$prop['dosen_id'], $jdl, $msg, $tipe]);
            }

            header('Content-Type: application/json');
            echo json_encode(['ok'=>true]);
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode(['ok'=>false]);
        exit;
    }
}

// ── Filter & query ─────────────────────────────────────────────
$filter_skema  = clean($_GET['skema']  ?? '');
$filter_status = clean($_GET['status'] ?? 'diajukan');
$q             = clean($_GET['q']      ?? '');

$valid_statuses = ['diajukan','seleksi_admin_pengabdian','lolos_admin','gagal_admin'];
if (!in_array($filter_status, $valid_statuses)) $filter_status = 'diajukan';

$where = ["up.deleted_at IS NULL", "up.tahun_anggaran=$tahun", "up.status=?"];
$params = [$filter_status];
if ($filter_skema) { $where[] = "up.skema=?"; $params[] = $filter_skema; }
if ($q) {
    $where[] = "(u.nama_lengkap LIKE ? OR up.judul LIKE ? OR u.nidn LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}

$proposals = $pdo->prepare("
    SELECT up.*, u.nama_lengkap, u.nidn, u.program_studi, u.fakultas, u.jabatan_fungsional,
           sa.keputusan as sa_keputusan, sa.checklist as sa_checklist, sa.catatan as sa_catatan,
           sa.created_at as sa_created_at
    FROM usulan_pengabdian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN seleksi_admin_pengabdian sa ON sa.usulan_id = up.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY up.created_at ASC
");
$proposals->execute($params);
$proposals = $proposals->fetchAll();

// Statistik per status seleksi
$stats = $pdo->query("
    SELECT
      SUM(status='diajukan')          n_baru,
      SUM(status='lolos_admin')        n_lolos,
      SUM(status='gagal_admin')        n_gagal
    FROM usulan_pengabdian
    WHERE deleted_at IS NULL AND tahun_anggaran=$tahun
")->fetch();

// Kuota per skema PkM
$kuota_data = [];
try {
    $kq = $pdo->prepare("
        SELECT sk.kode, sk.nama, sk.kuota,
               COALESCE(SUM(up.status IN ('lolos_admin','penandatanganan_kontrak','seleksi_substansi','disetujui','revisi_minor','revisi_mayor')),0) as sudah_lolos
        FROM skema_pengabdian sk
        LEFT JOIN usulan_pengabdian up ON up.skema=sk.kode AND up.tahun_anggaran=? AND up.deleted_at IS NULL
        WHERE sk.tahun=?
        GROUP BY sk.id
    ");
    $kq->execute([$tahun, $tahun]);
    foreach ($kq->fetchAll() as $kr) {
        $kuota_data[$kr['kode']] = $kr;
    }
} catch (\Exception $e) {}

// Load skema list
$skema_rows = [];
try {
    $sq = $pdo->prepare("SELECT * FROM skema_pengabdian WHERE tahun=? ORDER BY urutan ASC, id ASC");
    $sq->execute([$tahun]);
    $skema_rows = $sq->fetchAll();
} catch (\Exception $e) {}

$jabatanLabel = fn($j) => match($j) {
    'asisten_ahli'  => 'Asisten Ahli',
    'lektor'        => 'Lektor',
    'lektor_kepala' => 'Lektor Kepala',
    'guru_besar'    => 'Guru Besar',
    default         => $j ?: '-',
};

$statusBadge = fn($s) => match($s) {
    'diajukan'      => ['bg'=>'#eff6ff','color'=>'#2563eb', 'label'=>'Diajukan'],
    'lolos_admin'   => ['bg'=>'#f0fdf4','color'=>'#16a34a', 'label'=>'Lolos Admin'],
    'gagal_admin'   => ['bg'=>'#fef2f2','color'=>'#dc2626', 'label'=>'Gagal Admin'],
    default         => ['bg'=>'#f1f5f9','color'=>'#64748b', 'label'=>$s],
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seleksi Admin PkM — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.sel-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
@media(max-width:900px){ .sel-stats { grid-template-columns:repeat(2,1fr); } }
.sel-stat { background:var(--bg-card); border:1.5px solid var(--border); border-radius:12px; padding:14px 16px; }
.sel-stat-num  { font-size:26px; font-weight:800; line-height:1; }
.sel-stat-lbl  { font-size:11px; color:var(--text-muted); margin-top:4px; }
.sel-tab { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.sel-tab-btn {
  padding:7px 15px; border-radius:20px; font-size:12px; font-weight:600;
  border:1.5px solid var(--border); background:var(--bg-card); cursor:pointer;
  color:var(--text-muted); text-decoration:none; display:inline-flex; align-items:center; gap:5px;
  transition:all .15s;
}
.sel-tab-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.sel-tab-btn.green.active  { background:#16a34a; border-color:#16a34a; }
.sel-tab-btn.red.active    { background:#dc2626; border-color:#dc2626; }
.kuota-bar { height:6px; border-radius:4px; background:#e2e8f0; overflow:hidden; margin-top:4px; }
.kuota-bar-fill { height:100%; border-radius:4px; background:#0d9488; transition:width .3s; }
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

/* Drawer */
.drawer-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:900;
  backdrop-filter:blur(4px); display:none; align-items:flex-start; justify-content:flex-end; }
.drawer-overlay.open { display:flex; }
.drawer { width:560px; max-width:100vw; height:100vh; overflow-y:auto;
  background:var(--bg-card); box-shadow:-4px 0 32px rgba(0,0,0,.18); display:flex; flex-direction:column; }
.drawer-head { padding:18px 20px 14px; border-bottom:1.5px solid var(--border);
  display:flex; align-items:flex-start; gap:12px; position:sticky; top:0;
  background:var(--bg-card); z-index:1; }
.drawer-body { padding:18px 20px; flex:1; }
.drawer-sec { margin-bottom:18px; }
.drawer-sec-title { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;
  letter-spacing:.5px; margin-bottom:8px; }
.dl-row { display:flex; justify-content:space-between; align-items:flex-start;
  padding:6px 0; border-bottom:1px solid var(--border); font-size:12.5px; gap:12px; }
.dl-row:last-child { border-bottom:none; }
.dl-key { color:var(--text-muted); flex-shrink:0; }
.dl-val { font-weight:600; color:var(--text-primary); text-align:right; }

/* Checklist */
.cl-item {
  display:flex; align-items:flex-start; gap:10px; padding:9px 12px;
  border-radius:8px; border:1.5px solid var(--border); background:var(--bg-field);
  margin-bottom:6px; cursor:pointer; transition:border-color .15s, background .15s;
}
.cl-item:hover { border-color:var(--primary-light); }
.cl-item.checked { border-color:#0d9488; background:#f0fdfa; }
.cl-item input[type=checkbox] { margin-top:2px; flex-shrink:0; width:15px; height:15px; accent-color:#0d9488; }
.cl-label { font-size:12.5px; line-height:1.5; color:var(--text-primary); }

/* Quota badges */
.quota-badge { font-size:11px; padding:2px 8px; border-radius:6px; font-weight:700; }
.quota-ok   { background:#f0fdf4; color:#166534; }
.quota-warn { background:#fef9c3; color:#92400e; }
.quota-full { background:#fef2f2; color:#991b1b; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('clipboard') ?>
          <?= $id?'Seleksi Administratif PkM':'PkM Administrative Review' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/admin/pengabdian.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Semua Proposal PkM':'All PkM Proposals' ?>
        </a>
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:16px">
        <?= ic($fl['type']==='success'?'check-circle':($fl['type']==='danger'?'x-circle':'alert')) ?>
        <?= htmlspecialchars($fl['msg']) ?>
      </div>
      <?php endif; ?>

      <!-- Statistik -->
      <div class="sel-stats">
        <div class="sel-stat">
          <div class="sel-stat-num" style="color:#0284c7"><?= (int)($stats['n_baru'] ?? 0) ?></div>
          <div class="sel-stat-lbl"><?= $id?'Menunggu Seleksi':'Awaiting Review' ?></div>
        </div>
        <div class="sel-stat">
          <div class="sel-stat-num" style="color:#16a34a"><?= (int)($stats['n_lolos'] ?? 0) ?></div>
          <div class="sel-stat-lbl"><?= $id?'Lolos Admin':'Passed Admin' ?></div>
        </div>
        <div class="sel-stat">
          <div class="sel-stat-num" style="color:#dc2626"><?= (int)($stats['n_gagal'] ?? 0) ?></div>
          <div class="sel-stat-lbl"><?= $id?'Tidak Lolos':'Not Passed' ?></div>
        </div>
        <div class="sel-stat" style="padding:10px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.4px">
            <?= $id?'Kuota Skema PkM':'PkM Scheme Quota' ?>
          </div>
          <?php foreach ($kuota_data as $kd):
            $pct = $kd['kuota'] > 0 ? min(100, round($kd['sudah_lolos']/$kd['kuota']*100)) : 0;
            $cls = $pct >= 100 ? 'quota-full' : ($pct >= 80 ? 'quota-warn' : 'quota-ok');
          ?>
          <div style="margin-bottom:6px">
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px">
              <span style="color:var(--text-secondary)"><?= htmlspecialchars(mb_strimwidth($kd['nama'],0,20,'…')) ?></span>
              <span class="quota-badge <?= $cls ?>"><?= (int)$kd['sudah_lolos'] ?>/<?= (int)$kd['kuota'] ?></span>
            </div>
            <div class="kuota-bar">
              <div class="kuota-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#dc2626':($pct>=80?'#ca8a04':'#0d9488') ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Status tabs -->
      <div class="sel-tab">
        <?php
        $tabs = [
          ['status'=>'diajukan',    'label'=>$id?'Menunggu':'Pending',    'cls'=>''],
          ['status'=>'lolos_admin', 'label'=>$id?'Lolos':'Passed',        'cls'=>'green'],
          ['status'=>'gagal_admin', 'label'=>$id?'Tidak Lolos':'Failed',  'cls'=>'red'],
        ];
        foreach ($tabs as $tab):
          $cnt_q = $pdo->prepare("SELECT COUNT(*) FROM usulan_pengabdian WHERE deleted_at IS NULL AND tahun_anggaran=$tahun AND status=?");
          $cnt_q->execute([$tab['status']]); $cnt_n = $cnt_q->fetchColumn();
          $active = $filter_status === $tab['status'] ? ' active' : '';
        ?>
        <a href="?status=<?= $tab['status'] ?><?= $filter_skema?"&skema=$filter_skema":'' ?><?= $q?"&q=".urlencode($q):'' ?>"
           class="sel-tab-btn <?= $tab['cls'].$active ?>">
          <?= $tab['label'] ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:10px;font-size:10px"><?= $cnt_n ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
        <select name="skema" class="form-control" style="width:180px;font-size:12.5px" onchange="this.form.submit()">
          <option value=""><?= $id?'Semua Skema':'All Schemes' ?></option>
          <?php foreach ($skema_rows as $sk): ?>
          <option value="<?= htmlspecialchars($sk['kode']) ?>" <?= $filter_skema===$sk['kode']?'selected':'' ?>>
            <?= htmlspecialchars(mb_strimwidth($sk['nama'],0,25,'…')) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" class="form-control" style="width:200px;font-size:12.5px"
               value="<?= htmlspecialchars($q) ?>" placeholder="<?= $id?'Cari nama / judul...':'Search name / title...' ?>">
        <button type="submit" class="btn btn-primary" style="font-size:12px;background:#0d9488;border:none"><?= ic('search') ?> <?= $id?'Cari':'Search' ?></button>
      </form>

      <!-- Tabel proposal -->
      <div class="card" style="padding:0;overflow:hidden">
        <div style="overflow-x:auto">
          <table class="rpa-table">
            <thead>
              <tr>
                <th>#</th>
                <th><?= $id?'Proposal PkM':'PkM Proposal' ?></th>
                <th>Skema</th>
                <th><?= $id?'Pengusul':'Proposer' ?></th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($proposals)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">
              <?= ic('inbox') ?>
              <div style="margin-top:8px"><?= $id?'Tidak ada proposal pada tahap ini.':'No proposals at this stage.' ?></div>
            </td></tr>
            <?php else: foreach ($proposals as $i => $p):
              $badge = $statusBadge($p['status']);
              $skema_kode = strtolower($p['skema'] ?? '');
              $kd = $kuota_data[$p['skema']] ?? null;
            ?>
            <tr>
              <td style="font-size:11px;color:var(--text-muted)"><?= $i+1 ?></td>
              <td>
                <div class="rpa-judul"><?= htmlspecialchars(mb_strimwidth($p['judul'],0,70,'…')) ?></div>
                <div class="rpa-meta">
                  <?= $id?'Diajukan':'Submitted' ?> <?= date('d M Y', strtotime($p['created_at'])) ?>
                  <?php if ($p['sa_created_at']): ?>
                  · Ditinjau <?= date('d M Y', strtotime($p['sa_created_at'])) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:#ccfbf1;color:#0f766e">
                  <?= htmlspecialchars(strtoupper($p['skema'])) ?>
                </span>
                <?php if ($kd): ?>
                <div style="font-size:10px;color:var(--text-muted);margin-top:3px">
                  Kuota: <?= (int)$kd['sudah_lolos'] ?>/<?= (int)$kd['kuota'] ?>
                </div>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($p['nama_lengkap']) ?></div>
                <div class="rpa-meta">
                  <?= $jabatanLabel($p['jabatan_fungsional']) ?> · <?= htmlspecialchars($p['program_studi']??'-') ?>
                </div>
              </td>
              <td>
                <span style="background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;
                             padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">
                  <?= $badge['label'] ?>
                </span>
                <?php if ($p['sa_catatan'] && $p['status']==='gagal_admin'): ?>
                <div style="font-size:10.5px;color:#dc2626;margin-top:3px;max-width:130px">
                  <?= htmlspecialchars(mb_strimwidth($p['sa_catatan'],0,50,'…')) ?>
                </div>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn btn-outline" style="font-size:11.5px;padding:5px 11px"
                        onclick="openDrawer(<?= htmlspecialchars(json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)">
                  <?= ic('eye') ?> <?= $id?'Tinjau':'Review' ?>
                </button>
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

<!-- Drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="if(event.target===this)closeDrawer()">
  <div class="drawer" id="drawer">
    <div class="drawer-head">
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary)" id="drawerTitle">—</div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px" id="drawerMeta">—</div>
      </div>
      <button onclick="closeDrawer()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="drawer-body" id="drawerBody">
      <!-- filled by JS -->
    </div>
  </div>
</div>

<script>
const IS_ID = <?= $id?'true':'false' ?>;
const CHECKLIST_ITEMS = <?= json_encode($DEFAULT_CHECKLIST, JSON_UNESCAPED_UNICODE) ?>;
const KUOTA_DATA = <?= json_encode($kuota_data, JSON_UNESCAPED_UNICODE) ?>;

function openDrawer(p) {
  document.getElementById('drawerTitle').textContent = p.judul;
  document.getElementById('drawerMeta').textContent =
    (IS_ID?'Diajukan ':'Submitted ') + formatDate(p.created_at) + ' · ' + p.nama_lengkap;

  let existingCl = {};
  if (p.sa_checklist) {
    try { existingCl = JSON.parse(p.sa_checklist); } catch(e) {}
  }

  const kd = KUOTA_DATA[p.skema] || null;
  const kuotaFull = kd && parseInt(kd.sudah_lolos) >= parseInt(kd.kuota);

  let html = `<div class="drawer-sec">`;
  html += `<div class="drawer-sec-title">${IS_ID?'Detail Proposal PkM':'PkM Proposal Details'}</div>`;
  html += `<div class="dl-row"><span class="dl-key">Skema</span><span class="dl-val">${p.skema?.toUpperCase()}</span></div>`;
  html += `<div class="dl-row"><span class="dl-key">${IS_ID?'Pengusul':'Proposer'}</span><span class="dl-val">${p.nama_lengkap}</span></div>`;
  html += `<div class="dl-row"><span class="dl-key">${IS_ID?'Jabatan':'Rank'}</span><span class="dl-val">${jabatanLabel(p.jabatan_fungsional)}</span></div>`;
  html += `<div class="dl-row"><span class="dl-key">${IS_ID?'Program Studi':'Study Program'}</span><span class="dl-val">${p.program_studi||'-'}</span></div>`;
  if (p.file_proposal) {
    html += `<div class="dl-row"><span class="dl-key">${IS_ID?'File Proposal':'Proposal File'}</span>
      <span class="dl-val"><a href="<?= BASE_URL ?>/${p.file_proposal}" target="_blank" style="color:#0d9488">
        Unduh ↗</a></span></div>`;
  }
  html += `</div>`;

  if (kd) {
    const pct = Math.min(100, Math.round(kd.sudah_lolos/kd.kuota*100));
    html += `<div class="drawer-sec">
      <div class="drawer-sec-title">${IS_ID?'Status Kuota Skema':'Scheme Quota Status'}</div>
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
        <span>${IS_ID?'Terisi':'Filled'}: ${kd.sudah_lolos} / ${kd.kuota}</span>
        <span class="quota-badge ${pct>=100?'quota-full':pct>=80?'quota-warn':'quota-ok'}">${pct}%</span>
      </div>
      <div class="kuota-bar"><div class="kuota-bar-fill" style="width:${pct}%;background:${pct>=100?'#dc2626':pct>=80?'#ca8a04':'#0d9488'}"></div></div>
      ${kuotaFull?`<div style="color:#dc2626;font-size:12px;margin-top:8px;font-weight:600">⚠ ${IS_ID?'Kuota skema sudah penuh. Proposal baru tidak dapat diloloskan.':'Scheme quota is full. New proposals cannot be passed.'}</div>`:''}
    </div>`;
  }

  html += `<div class="drawer-sec">
    <div class="drawer-sec-title">${IS_ID?'Checklist Administrasi':'Administrative Checklist'}</div>
    <div id="cl-list">`;
  CHECKLIST_ITEMS.forEach(item => {
    const isChecked = existingCl[item.key] === true;
    html += `<label class="cl-item${isChecked?' checked':''}" id="cl-${item.key}">
      <input type="checkbox" id="chk-${item.key}" ${isChecked?'checked':''}
             onchange="toggleCl('${item.key}', this)">
      <span class="cl-label">${item.label}</span>
    </label>`;
  });
  html += `</div></div>`;

  html += `<div class="drawer-sec">
    <div class="drawer-sec-title">${IS_ID?'Catatan Admin':'Admin Notes'}</div>
    <textarea id="catatan-admin" class="form-control" rows="3" style="font-size:12.5px"
      placeholder="${IS_ID?'Alasan keputusan / catatan untuk pengusul...':'Decision reason / notes for proposer...'}"
    >${p.sa_catatan||''}</textarea>
  </div>`;

  if (!kuotaFull || p.sa_keputusan === 'lolos') {
    html += `<button class="btn btn-primary" style="width:100%;margin-bottom:8px;background:#16a34a;border-color:#16a34a"
      onclick="submitSeleksi(${p.id},'lolos')">
      ✓ ${IS_ID?'Loloskan Proposal':'Pass Proposal'}
    </button>`;
  } else {
    html += `<button class="btn" style="width:100%;margin-bottom:8px;background:#e2e8f0;color:#94a3b8;cursor:not-allowed;border:none;padding:10px;border-radius:9px;font-weight:600" disabled>
      ${IS_ID?'Kuota Penuh – Tidak Dapat Diloloskan':'Quota Full – Cannot Pass'}
    </button>`;
  }
  html += `<button class="btn" style="width:100%;background:#dc2626;color:#fff;border:none;padding:10px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer"
    onclick="submitSeleksi(${p.id},'gagal')">
    ✗ ${IS_ID?'Tidak Loloskan':'Reject from Admin Stage'}
  </button>`;

  document.getElementById('drawerBody').innerHTML = html;
  document.getElementById('drawerOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function toggleCl(key, cb) {
  const lbl = document.getElementById('cl-'+key);
  if (lbl) lbl.classList.toggle('checked', cb.checked);
}

function closeDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

async function submitSeleksi(pid, keputusan) {
  const catatan = document.getElementById('catatan-admin').value.trim();
  if (keputusan === 'gagal' && !catatan) {
    alert(IS_ID
      ? 'Mohon isi catatan alasan untuk proposal yang tidak lolos.'
      : 'Please provide a reason note for the rejected proposal.');
    document.getElementById('catatan-admin').focus();
    return;
  }
  const konfirm = confirm(IS_ID
    ? (keputusan==='lolos' ? 'Loloskan proposal ini ke tahap selanjutnya?' : 'Tidak loloskan proposal ini dari seleksi administratif?')
    : (keputusan==='lolos' ? 'Pass this proposal to the next stage?' : 'Reject this proposal from administrative review?'));
  if (!konfirm) return;

  const checked = [];
  CHECKLIST_ITEMS.forEach(item => {
    if (document.getElementById('chk-'+item.key)?.checked) checked.push(item.key);
  });

  const fd = new FormData();
  fd.append('action', 'seleksi');
  fd.append('proposal_id', pid);
  fd.append('keputusan', keputusan);
  fd.append('catatan', catatan);
  checked.forEach(k => fd.append('checklist[]', k));

  const res = await fetch('', { method:'POST', body: fd });
  const data = await res.json();

  if (data.ok) {
    closeDrawer();
    location.reload();
  } else if (data.quota_full) {
    alert(IS_ID
      ? `Kuota skema "${data.nama}" sudah penuh (${data.kuota} proposal). Proposal tidak dapat diloloskan.`
      : `Scheme quota "${data.nama}" is full (${data.kuota}). Cannot pass proposal.`);
  } else {
    alert(IS_ID ? 'Terjadi kesalahan. Silakan coba lagi.' : 'An error occurred. Please try again.');
  }
}

function jabatanLabel(j) {
  const map = {asisten_ahli:'Asisten Ahli',lektor:'Lektor',lektor_kepala:'Lektor Kepala',guru_besar:'Guru Besar'};
  return map[j] || j || '-';
}
function formatDate(s) {
  if (!s) return '-';
  const d = new Date(s);
  const m = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
  return `${d.getDate()} ${m[d.getMonth()]} ${d.getFullYear()}`;
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>