<?php
require_once '../includes/config.php';
requireAdminOrDelegate(NULL);

$lang  = $_COOKIE['lang'] ?? 'id';
$id    = $lang === 'id';
$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

/* ── POST handlers ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Tambah catatan monev proses
    if ($action === 'add_proses') {
        $pid     = (int)($_POST['pid'] ?? 0);
        $tahap   = clean($_POST['tahap'] ?? '');
        $tanggal = clean($_POST['tanggal'] ?? '');
        $catatan = clean($_POST['catatan'] ?? '');
        $rekom   = clean($_POST['rekomendasi'] ?? '');

        if ($pid && $tahap && $tanggal && $catatan) {
            // Upload optional file
            $fp = null; $fn = null;
            if (!empty($_FILES['file']['name'])) {
                $f = $_FILES['file'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf','jpg','jpeg','png','doc','docx'])) {
                    $dir = BASE_PATH . '/uploads/monev/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/','_',$f['name']);
                    if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                        $fp = 'uploads/monev/' . $fname;
                        $fn = $f['name'];
                    }
                }
            }
            $pdo->prepare("
                INSERT INTO monev_pengabdian_proses
                  (usulan_id, tahap, tanggal, catatan, rekomendasi, file_path, file_name, admin_id)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$pid, $tahap, $tanggal, $catatan, $rekom ?: null, $fp, $fn, $_SESSION['user_id']]);

            // Notifikasi peneliti
            $u = $pdo->prepare("SELECT user_id, judul FROM usulan_pengabdian WHERE id=?");
            $u->execute([$pid]);
            if ($pd = $u->fetch()) {
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$pd['user_id'],
                        $id?'Catatan Monev Pengabdian':'PkM Monev Note',
                        ($id?'Admin LPPM mencatat hasil monev (':'Admin recorded monev note (').ucwords(str_replace('_',' ',$tahap)).') '
                        . ($id?'untuk proposal: ':'for proposal: ') . mb_strimwidth($pd['judul'],0,70,'…'),
                        'info']);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Catatan monev tersimpan.':'Monev note saved.'];
        }
        redirect('/admin/pengabdian_monev.php?pid=' . $pid . '&tab=proses');
    }

    // Tambah / verifikasi luaran
    if ($action === 'add_luaran' || $action === 'edit_luaran') {
        $luaran_id  = (int)($_POST['luaran_id'] ?? 0);
        $pid        = (int)($_POST['pid'] ?? 0);
        $jenis      = clean($_POST['jenis_luaran'] ?? '');
        $judul      = clean($_POST['judul_luaran'] ?? '');
        $jurnal     = clean($_POST['nama_jurnal'] ?? '');
        $url        = trim($_POST['url_doi'] ?? '');
        $issn       = clean($_POST['issn_isbn'] ?? '');
        $akr        = clean($_POST['akreditasi'] ?? '');
        $tgl_terbit = clean($_POST['tanggal_terbit'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['rencana','submit','terbit','diverifikasi','ditolak'], true) ? $_POST['status'] : 'rencana';
        $catatan    = clean($_POST['catatan_admin'] ?? '');

        if ($pid && $jenis) {
            $up = $pdo->prepare("SELECT user_id FROM usulan_pengabdian WHERE id=?");
            $up->execute([$pid]);
            $u = $up->fetch();
            if (!$u) redirect('/admin/pengabdian_monev.php?pid=' . $pid);

            $fp = null; $fn = null; $fs = null;
            if (!empty($_FILES['file']['name'])) {
                $f = $_FILES['file'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
                    $dir = BASE_PATH . '/uploads/luaran/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/','_',$f['name']);
                    if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                        $fp = 'uploads/luaran/' . $fname;
                        $fn = $f['name'];
                        $fs = $f['size'];
                    }
                }
            }

            if ($action === 'edit_luaran' && $luaran_id) {
                // Pertahankan file lama jika tidak upload baru
                $sql = "UPDATE monev_pengabdian_luaran SET
                          jenis_luaran=?, judul_luaran=?, nama_jurnal=?, url_doi=?, issn_isbn=?,
                          akreditasi=?, tanggal_terbit=?, status=?, catatan_admin=?,
                          verified_by=?, verified_at=NOW()";
                $params = [$jenis, $judul ?: null, $jurnal ?: null, $url ?: null, $issn ?: null,
                           $akr ?: null, $tgl_terbit ?: null, $status, $catatan ?: null,
                           $_SESSION['user_id']];
                if ($fp) {
                    $sql .= ", file_path=?, file_name=?, file_size=?";
                    $params[] = $fp; $params[] = $fn; $params[] = $fs;
                }
                $sql .= " WHERE id=? AND usulan_id=?";
                $params[] = $luaran_id; $params[] = $pid;
                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare("
                    INSERT INTO monev_pengabdian_luaran
                      (usulan_id, user_id, jenis_luaran, judul_luaran, nama_jurnal, url_doi, issn_isbn,
                       akreditasi, tanggal_terbit, file_path, file_name, file_size, status,
                       catatan_admin, verified_by, verified_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ")->execute([
                    $pid, $u['user_id'], $jenis, $judul ?: null, $jurnal ?: null, $url ?: null,
                    $issn ?: null, $akr ?: null, $tgl_terbit ?: null,
                    $fp, $fn, $fs, $status, $catatan ?: null, $_SESSION['user_id'],
                ]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Luaran tersimpan.':'Output saved.'];
        }
        redirect('/admin/pengabdian_monev.php?pid=' . $pid . '&tab=luaran');
    }

    // Hapus monev/luaran
    if ($action === 'del_proses') {
        $mid = (int)($_POST['mid'] ?? 0);
        $pid = (int)($_POST['pid'] ?? 0);
        if ($mid) {
            $pdo->prepare("UPDATE monev_pengabdian_proses SET deleted_at=NOW() WHERE id=?")->execute([$mid]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Catatan dihapus.':'Note deleted.'];
        }
        redirect('/admin/pengabdian_monev.php?pid=' . $pid . '&tab=proses');
    }
    if ($action === 'del_luaran') {
        $lid = (int)($_POST['lid'] ?? 0);
        $pid = (int)($_POST['pid'] ?? 0);
        if ($lid) {
            $pdo->prepare("UPDATE monev_pengabdian_luaran SET deleted_at=NOW() WHERE id=?")->execute([$lid]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Luaran dihapus.':'Output deleted.'];
        }
        redirect('/admin/pengabdian_monev.php?pid=' . $pid . '&tab=luaran');
    }
}

/* ── Load proposals (lolos substantif & seterusnya) ─────────────── */
$proposals = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.user_id, up.tahun_anggaran,
           u.nama_lengkap, u.nidn, u.program_studi,
           kp.nomor_kontrak, kp.deadline_laporan,
           sk.batas_luaran_bulan, sk.nama AS skema_nama, sk.jenis_luaran_wajib,
           lp.status AS lap_status, lp.tanggal_submit AS lap_tgl,
           (SELECT COUNT(*) FROM monev_pengabdian_proses mp WHERE mp.usulan_id = up.id AND mp.deleted_at IS NULL) AS n_proses,
           (SELECT COUNT(*) FROM monev_pengabdian_luaran ml WHERE ml.usulan_id = up.id AND ml.deleted_at IS NULL) AS n_luaran,
           (SELECT COUNT(*) FROM monev_pengabdian_luaran ml WHERE ml.usulan_id = up.id AND ml.status='diverifikasi' AND ml.deleted_at IS NULL) AS n_luaran_terbit
    FROM usulan_pengabdian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN kontrak_pengabdian kp ON kp.usulan_id = up.id
    LEFT JOIN skema_pengabdian sk ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci AND sk.tahun = up.tahun_anggaran
    LEFT JOIN laporan_pengabdian lp ON lp.usulan_id = up.id
    WHERE up.tahun_anggaran=? AND up.deleted_at IS NULL
      AND up.status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')
    ORDER BY up.skema ASC, u.nama_lengkap ASC
");
$proposals->execute([$tahun]);
$proposals = $proposals->fetchAll();

/* ── Helper: hitung deadline luaran per proposal ──────────────── */
$now_ts = time();
function deadlineLuaran(array $p): ?int {
    if (empty($p['deadline_laporan']) || empty($p['batas_luaran_bulan'])) return null;
    $base = strtotime($p['deadline_laporan']);
    if (!$base) return null;
    $bulan = (int)$p['batas_luaran_bulan'];
    return strtotime("+{$bulan} months", $base);
}

/* ── Single proposal view ──────────────────────────────────────── */
$view_pid = (int)($_GET['pid'] ?? 0);
$tab = clean($_GET['tab'] ?? 'proses');
if (!in_array($tab, ['proses','luaran'])) $tab = 'proses';

$view_p = null;
$view_proses = [];
$view_luaran = [];
if ($view_pid) {
    foreach ($proposals as $_p) if ((int)$_p['id'] === $view_pid) { $view_p = $_p; break; }
    if ($view_p) {
        $pq = $pdo->prepare("
            SELECT mp.*, u.nama_lengkap AS admin_nama
            FROM monev_pengabdian_proses mp LEFT JOIN users u ON u.id = mp.admin_id
            WHERE mp.usulan_id = ? AND mp.deleted_at IS NULL
            ORDER BY mp.tanggal DESC, mp.id DESC
        ");
        $pq->execute([$view_pid]);
        $view_proses = $pq->fetchAll();

        $lq = $pdo->prepare("
            SELECT ml.*, u.nama_lengkap AS verifier_nama
            FROM monev_pengabdian_luaran ml LEFT JOIN users u ON u.id = ml.verified_by
            WHERE ml.usulan_id = ? AND ml.deleted_at IS NULL
            ORDER BY ml.created_at DESC
        ");
        $lq->execute([$view_pid]);
        $view_luaran = $lq->fetchAll();
    }
}

/* ── Aggregate statistics ───────────────────────────────────── */
$agg = ['total'=>0,'monev_aktif'=>0,'luaran_terbit'=>0,'belum_luaran'=>0,'lewat_luaran'=>0];
foreach ($proposals as $p) {
    $agg['total']++;
    if ($p['n_proses'] > 0) $agg['monev_aktif']++;
    if ($p['n_luaran_terbit'] > 0) $agg['luaran_terbit']++;
    if ($p['n_luaran'] == 0) $agg['belum_luaran']++;
    $dl = deadlineLuaran($p);
    if ($dl && $dl < $now_ts && $p['n_luaran_terbit'] == 0) $agg['lewat_luaran']++;
}

$jenis_luaran_opts = [
    'jurnal_sinta'         => 'Jurnal Sinta',
    'jurnal_scopus'        => 'Jurnal Scopus',
    'jurnal_internasional' => 'Jurnal Internasional (non-Scopus)',
    'jurnal_nasional'      => 'Jurnal Nasional (non-Sinta)',
    'prosiding'            => 'Prosiding Konferensi',
    'book_chapter'         => 'Book Chapter',
    'buku'                 => 'Buku',
    'hki'                  => 'HKI / Paten',
    'lainnya'              => 'Lainnya',
];

$tahap_opts = [
    'kunjungan_lapangan' => 'Kunjungan Lapangan',
    'seminar_progres'    => 'Seminar Progres',
    'review_log_book'    => 'Review Log Book',
    'konsultasi'         => 'Konsultasi / Diskusi',
    'lainnya'            => 'Lainnya',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Monev Pengabdian':'PkM Monev' ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:11px;margin-bottom:18px}
@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
.stat-card{background:var(--bg-card);border:1.5px solid var(--border);border-radius:11px;padding:13px 15px}
.stat-num{font-size:26px;font-weight:900;line-height:1}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:4px}

.mtab-bar{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.mtab{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;
  border:1.5px solid var(--border);background:var(--bg-card);color:var(--text-muted);text-decoration:none}
.mtab.active{background:var(--primary);color:#fff;border-color:var(--primary)}

.prop-row{background:var(--bg-card);border:1.5px solid var(--border);border-radius:11px;padding:12px 15px;
  margin-bottom:9px;display:flex;gap:13px;align-items:center;transition:border-color .15s}
.prop-row:hover{border-color:#cbd5e1;box-shadow:0 2px 12px rgba(0,0,0,.04)}
.prop-row .av{width:34px;height:34px;border-radius:8px;flex-shrink:0;
  background:linear-gradient(135deg,#0369a1,#0891b2);color:#fff;
  display:flex;align-items:center;justify-content:center;font-weight:800}
.prop-row.overdue{border-color:#fecaca;background:#fef2f2}
.prop-row.has-luaran{border-color:#bbf7d0;background:#f0fdf4}

.dl-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;
  font-size:10.5px;font-weight:700}
.dl-ok{background:#dcfce7;color:#15803d}
.dl-soon{background:#fef9c3;color:#854d0e}
.dl-late{background:#fee2e2;color:#991b1b}

/* Modal */
.mvl-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:900;display:none;
  align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)}
.mvl-modal.open{display:flex}
.mvl-box{background:var(--bg-card);border-radius:14px;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.25)}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('chart') ?>
          <?= $id?'Monev Pengabdian':'PkM Monev' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/admin/pengabdian.php" class="btn btn-outline" style="font-size:12px">← <?= $id?'Kembali':'Back' ?></a>
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:14px"><?= htmlspecialchars($fl['msg']) ?></div>
      <?php endif; ?>

      <?php if (!$view_pid): ?>
      <!-- ════════════════════════════════ LIST VIEW ════════════════════════════════ -->

      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:11px;padding:13px 16px;margin-bottom:16px;font-size:12.5px;color:#1e40af">
        <?= ic('info','style="width:15px;height:15px"') ?>
        <span style="margin-left:5px"><?= $id
          ? 'Halaman ini melacak <strong>monitoring proses pelaksanaan</strong> dan <strong>luaran (publikasi)</strong> tiap proposal yang telah disetujui. Batas akhir luaran dihitung otomatis dari deadline laporan + batas bulan luaran skema (lihat <a href="' . BASE_URL . '/admin/pengabdian_setting.php" style="color:#1e40af;text-decoration:underline">Pengaturan Pengabdian</a>).'
          : 'This page tracks <strong>execution monitoring</strong> and <strong>publication outputs</strong> for each approved proposal. Output deadlines are auto-calculated from report deadline + scheme output months.' ?></span>
      </div>

      <!-- Stats -->
      <div class="stat-grid">
        <div class="stat-card"><div class="stat-num" style="color:#0369a1"><?= $agg['total'] ?></div><div class="stat-lbl"><?= $id?'Total Proposal':'Total Proposals' ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#0891b2"><?= $agg['monev_aktif'] ?></div><div class="stat-lbl"><?= $id?'Sudah Dimonev':'Has Monev' ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#16a34a"><?= $agg['luaran_terbit'] ?></div><div class="stat-lbl"><?= $id?'Punya Luaran Terbit':'Published Output' ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#a16207"><?= $agg['belum_luaran'] ?></div><div class="stat-lbl"><?= $id?'Belum Catat Luaran':'No Output Yet' ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#dc2626"><?= $agg['lewat_luaran'] ?></div><div class="stat-lbl"><?= $id?'Lewat Deadline Luaran':'Output Overdue' ?></div></div>
      </div>

      <!-- List proposal -->
      <?php foreach ($proposals as $p):
        $dl_lap = $p['deadline_laporan'] ? strtotime($p['deadline_laporan']) : 0;
        $dl_lua = deadlineLuaran($p);
        $lua_terbit = $p['n_luaran_terbit'] > 0;
        $lua_overdue = $dl_lua && $dl_lua < $now_ts && !$lua_terbit;
        $lua_soon    = $dl_lua && !$lua_terbit && $dl_lua > $now_ts && ($dl_lua - $now_ts) < 86400 * 60;
        $row_cls = $lua_overdue ? 'overdue' : ($lua_terbit ? 'has-luaran' : '');
      ?>
      <div class="prop-row <?= $row_cls ?>">
        <div class="av"><?= strtoupper(mb_substr($p['nama_lengkap'],0,1)) ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:700;color:var(--text-primary);line-height:1.3">
            <?= htmlspecialchars(mb_strimwidth($p['judul'], 0, 110, '…')) ?>
          </div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px;display:flex;flex-wrap:wrap;gap:9px">
            <span><?= htmlspecialchars($p['nama_lengkap']) ?></span>
            <span style="font-weight:700;color:<?= $p['skema']==='nasional'?'#15803d':'#0369a1' ?>"><?= strtoupper($p['skema']) ?></span>
            <span><?= htmlspecialchars($p['program_studi'] ?? '—') ?></span>
            <?php if ($p['nomor_kontrak']): ?>
            <span style="color:#7c3aed">📄 <?= htmlspecialchars($p['nomor_kontrak']) ?></span>
            <?php endif; ?>
          </div>
          <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;font-size:10.5px">
            <?php if ($dl_lap): ?>
            <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:5px">
              <?= $id?'Batas Laporan':'Report Due' ?>: <?= date('d M Y', $dl_lap) ?>
            </span>
            <?php endif; ?>
            <?php if ($dl_lua): ?>
            <span class="dl-pill <?= $lua_overdue?'dl-late':($lua_soon?'dl-soon':'dl-ok') ?>">
              <?= $id?'Batas Luaran':'Output Due' ?>: <?= date('d M Y', $dl_lua) ?>
              <?php if ($lua_overdue): ?>· <?= $id?'TERLEWAT':'OVERDUE' ?><?php endif; ?>
            </span>
            <?php endif; ?>
            <span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:5px">
              <?= ic('chart','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
              <?= (int)$p['n_proses'] ?> <?= $id?'monev':'monev' ?>
            </span>
            <span style="background:<?= $lua_terbit?'#dcfce7':'#fef3c7' ?>;color:<?= $lua_terbit?'#15803d':'#854d0e' ?>;padding:2px 8px;border-radius:5px;font-weight:700">
              📚 <?= (int)$p['n_luaran'] ?> luaran <?= $lua_terbit?'('.(int)$p['n_luaran_terbit'].' terbit)':'' ?>
            </span>
          </div>
        </div>
        <a href="?pid=<?= $p['id'] ?>" class="btn btn-primary" style="font-size:12px;padding:6px 13px;flex-shrink:0">
          <?= ic('eye','style="width:12px;height:12px"') ?> <?= $id?'Detail Monev':'Open' ?>
        </a>
      </div>
      <?php endforeach; ?>
      <?php if (empty($proposals)): ?>
      <div style="padding:50px 20px;text-align:center;color:var(--text-muted)">
        <?= ic('inbox','style="width:42px;height:42px;opacity:.4"') ?>
        <div style="margin-top:10px;font-size:13px"><?= $id?'Belum ada proposal yang lolos seleksi substantif tahun ini.':'No approved proposals this year yet.' ?></div>
      </div>
      <?php endif; ?>

      <?php else: /* ════════════════ DETAIL VIEW ════════════════ */ ?>

      <a href="<?= BASE_URL ?>/admin/pengabdian_monev.php" style="font-size:12px;color:var(--text-muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px;margin-bottom:11px">
        ← <?= $id?'Kembali ke daftar':'Back to list' ?>
      </a>

      <!-- Proposal header -->
      <div class="card" style="margin-bottom:14px;padding:0;overflow:hidden">
        <div style="padding:14px 18px;background:linear-gradient(135deg,#0d1b3e,#0369a1);color:#fff">
          <div style="font-size:14px;font-weight:800;line-height:1.35"><?= htmlspecialchars($view_p['judul']) ?></div>
          <div style="font-size:11.5px;opacity:.85;margin-top:4px">
            <?= htmlspecialchars($view_p['nama_lengkap']) ?> · NIDN <?= htmlspecialchars($view_p['nidn'] ?: '—') ?> · <?= strtoupper($view_p['skema']) ?> <?= $view_p['tahun_anggaran'] ?>
          </div>
        </div>
        <div style="padding:11px 18px;display:flex;flex-wrap:wrap;gap:10px;font-size:11.5px;background:var(--bg-field)">
          <?php
            $dl_lap = $view_p['deadline_laporan'] ? strtotime($view_p['deadline_laporan']) : 0;
            $dl_lua = deadlineLuaran($view_p);
          ?>
          <?php if ($view_p['nomor_kontrak']): ?>
          <span><strong>Kontrak:</strong> <?= htmlspecialchars($view_p['nomor_kontrak']) ?></span>
          <?php endif; ?>
          <?php if ($dl_lap): ?>
          <span><strong><?= $id?'Batas Laporan':'Report Deadline' ?>:</strong> <?= date('d M Y H:i', $dl_lap) ?></span>
          <?php endif; ?>
          <?php if ($dl_lua): ?>
          <?php $lua_overdue = $dl_lua < $now_ts && $view_p['n_luaran_terbit']==0; ?>
          <span style="color:<?= $lua_overdue?'#dc2626':'#15803d' ?>;font-weight:700">
            <strong><?= $id?'Batas Luaran':'Output Deadline' ?>:</strong> <?= date('d M Y', $dl_lua) ?>
            (<?= (int)$view_p['batas_luaran_bulan'] ?> <?= $id?'bulan setelah laporan':'months after report' ?>)
          </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tabs -->
      <div class="mtab-bar">
        <a href="?pid=<?= $view_pid ?>&tab=proses" class="mtab <?= $tab==='proses'?'active':'' ?>">
          <?= ic('chart','style="width:13px;height:13px"') ?> <?= $id?'Monev Proses':'Process Monev' ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:9px;font-size:10px;margin-left:3px"><?= count($view_proses) ?></span>
        </a>
        <a href="?pid=<?= $view_pid ?>&tab=luaran" class="mtab <?= $tab==='luaran'?'active':'' ?>">
          <?= ic('award','style="width:13px;height:13px"') ?> <?= $id?'Monev Luaran':'Output Monev' ?>
          <span style="background:rgba(255,255,255,.25);padding:1px 7px;border-radius:9px;font-size:10px;margin-left:3px"><?= count($view_luaran) ?></span>
        </a>
      </div>

      <?php if ($tab === 'proses'): ?>
      <!-- ── MONEV PROSES ── -->
      <button onclick="openProsesModal()" class="btn btn-primary" style="font-size:12.5px;margin-bottom:14px">
        <?= ic('plus','style="width:13px;height:13px"') ?> <?= $id?'Tambah Catatan Monev':'Add Monev Note' ?>
      </button>

      <?php if (empty($view_proses)): ?>
      <div style="padding:35px 20px;text-align:center;color:var(--text-muted);background:var(--bg-field);border-radius:11px">
        <?= ic('inbox','style="width:36px;height:36px;opacity:.4"') ?>
        <div style="margin-top:8px;font-size:12.5px"><?= $id?'Belum ada catatan monev untuk proposal ini.':'No monev notes yet.' ?></div>
      </div>
      <?php else: foreach ($view_proses as $mp): ?>
      <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:11px;padding:13px 15px;margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:7px">
          <div>
            <span style="background:#e0e7ff;color:#3730a3;font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:6px">
              <?= htmlspecialchars($tahap_opts[$mp['tahap']] ?? ucwords(str_replace('_',' ',$mp['tahap']))) ?>
            </span>
            <span style="font-size:11.5px;color:var(--text-muted);margin-left:6px">
              <?= date('d M Y', strtotime($mp['tanggal'])) ?>
              <?php if ($mp['admin_nama']): ?> · <?= htmlspecialchars($mp['admin_nama']) ?><?php endif; ?>
            </span>
          </div>
          <form method="POST" onsubmit="return confirm('<?= $id?'Hapus catatan ini?':'Delete this note?' ?>')">
            <input type="hidden" name="action" value="del_proses">
            <input type="hidden" name="mid" value="<?= $mp['id'] ?>">
            <input type="hidden" name="pid" value="<?= $view_pid ?>">
            <button type="submit" class="btn btn-outline" style="font-size:10.5px;padding:3px 8px;color:#dc2626;border-color:#fecaca">
              <?= ic('trash','style="width:11px;height:11px"') ?>
            </button>
          </form>
        </div>
        <div style="font-size:12.5px;line-height:1.55;color:#334155">
          <?= nl2br(htmlspecialchars($mp['catatan'])) ?>
        </div>
        <?php if ($mp['rekomendasi']): ?>
        <div style="margin-top:7px;padding:7px 11px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:5px;font-size:11.5px;color:#854d0e">
          <strong><?= $id?'Rekomendasi:':'Recommendation:' ?></strong> <?= nl2br(htmlspecialchars($mp['rekomendasi'])) ?>
        </div>
        <?php endif; ?>
        <?php if ($mp['file_path']): ?>
        <a href="<?= BASE_URL ?>/<?= htmlspecialchars($mp['file_path']) ?>" target="_blank"
           style="display:inline-flex;align-items:center;gap:5px;margin-top:7px;font-size:11px;color:var(--primary);font-weight:600;text-decoration:none">
          <?= ic('download','style="width:11px;height:11px"') ?> <?= htmlspecialchars($mp['file_name']) ?>
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>

      <?php else: /* tab=luaran */ ?>
      <!-- ── MONEV LUARAN ── -->
      <button onclick="openLuaranModal()" class="btn btn-primary" style="font-size:12.5px;margin-bottom:14px">
        <?= ic('plus','style="width:13px;height:13px"') ?> <?= $id?'Tambah / Verifikasi Luaran':'Add / Verify Output' ?>
      </button>

      <?php if (!empty($view_p['jenis_luaran_wajib'])): ?>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:9px 13px;margin-bottom:14px;font-size:11.5px;color:#854d0e">
        <strong><?= $id?'Luaran wajib skema:':'Required outputs:' ?></strong>
        <?= htmlspecialchars($view_p['jenis_luaran_wajib']) ?>
      </div>
      <?php endif; ?>

      <?php if (empty($view_luaran)): ?>
      <div style="padding:35px 20px;text-align:center;color:var(--text-muted);background:var(--bg-field);border-radius:11px">
        <?= ic('inbox','style="width:36px;height:36px;opacity:.4"') ?>
        <div style="margin-top:8px;font-size:12.5px"><?= $id?'Belum ada luaran tercatat.':'No outputs recorded yet.' ?></div>
      </div>
      <?php else: foreach ($view_luaran as $ll):
        $st_color = match($ll['status']) {
          'rencana'      => ['#f1f5f9','#64748b','Rencana'],
          'submit'       => ['#dbeafe','#1d4ed8','Submitted'],
          'terbit'       => ['#dcfce7','#15803d','Terbit'],
          'diverifikasi' => ['#bbf7d0','#14532d','Diverifikasi LPPM'],
          'ditolak'      => ['#fee2e2','#991b1b','Ditolak'],
        };
      ?>
      <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:11px;padding:13px 15px;margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:7px">
          <div style="flex:1;min-width:0">
            <span style="background:#e0e7ff;color:#3730a3;font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:6px">
              <?= htmlspecialchars($jenis_luaran_opts[$ll['jenis_luaran']] ?? $ll['jenis_luaran']) ?>
            </span>
            <span style="background:<?= $st_color[0] ?>;color:<?= $st_color[1] ?>;font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:6px;margin-left:5px">
              <?= $st_color[2] ?>
            </span>
            <?php if ($ll['akreditasi']): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;padding:2px 8px;border-radius:5px;margin-left:5px;text-transform:uppercase">
              <?= htmlspecialchars($ll['akreditasi']) ?>
            </span>
            <?php endif; ?>
            <div style="font-size:13px;font-weight:700;margin-top:5px;line-height:1.4"><?= htmlspecialchars($ll['judul_luaran'] ?: '(judul belum diisi)') ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">
              <?php if ($ll['nama_jurnal']): ?><?= htmlspecialchars($ll['nama_jurnal']) ?><?php endif; ?>
              <?php if ($ll['tanggal_terbit']): ?> · <?= date('d M Y', strtotime($ll['tanggal_terbit'])) ?><?php endif; ?>
              <?php if ($ll['issn_isbn']): ?> · ISSN/ISBN: <?= htmlspecialchars($ll['issn_isbn']) ?><?php endif; ?>
            </div>
            <?php if ($ll['url_doi']): ?>
            <a href="<?= htmlspecialchars($ll['url_doi']) ?>" target="_blank" style="font-size:11px;color:var(--primary);text-decoration:none">
              <?= ic('link','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
              <?= htmlspecialchars(mb_strimwidth($ll['url_doi'],0,80,'…')) ?>
            </a>
            <?php endif; ?>
            <?php if ($ll['file_path']): ?>
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ll['file_path']) ?>" target="_blank"
               style="display:inline-flex;align-items:center;gap:5px;margin-top:5px;font-size:11px;color:var(--primary);font-weight:600;text-decoration:none">
              <?= ic('download','style="width:10px;height:10px"') ?> <?= htmlspecialchars($ll['file_name']) ?>
            </a>
            <?php endif; ?>
            <?php if ($ll['catatan_admin']): ?>
            <div style="margin-top:6px;padding:6px 10px;background:var(--bg-field);border-radius:5px;font-size:11.5px;color:#475569">
              <strong><?= $id?'Catatan:':'Note:' ?></strong> <?= nl2br(htmlspecialchars($ll['catatan_admin'])) ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:5px">
          <button onclick="openLuaranModal(<?= htmlspecialchars(json_encode($ll, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)" class="btn btn-outline" style="font-size:10.5px;padding:4px 9px">
              <?= ic('edit','style="width:11px;height:11px"') ?>
            </button>
            <form method="POST" onsubmit="return confirm('<?= $id?'Hapus luaran?':'Delete output?' ?>')">
              <input type="hidden" name="action" value="del_luaran">
              <input type="hidden" name="lid" value="<?= $ll['id'] ?>">
              <input type="hidden" name="pid" value="<?= $view_pid ?>">
              <button type="submit" class="btn btn-outline" style="font-size:10.5px;padding:4px 9px;color:#dc2626;border-color:#fecaca">
                <?= ic('trash','style="width:11px;height:11px"') ?>
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>

      <?php endif; // tab ?>

      <?php endif; // view_pid ?>

    </div>
  </div>
</div>

<?php if ($view_pid): ?>
<!-- Modal Tambah Monev Proses -->
<div class="mvl-modal" id="prosesModal" onclick="if(event.target===this)closeProsesModal()">
  <div class="mvl-box">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_proses">
      <input type="hidden" name="pid" value="<?= $view_pid ?>">
      <div style="padding:16px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:14px;font-weight:700"><?= $id?'Tambah Catatan Monev Proses':'Add Process Monev Note' ?></div>
        <button type="button" onclick="closeProsesModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">×</button>
      </div>
      <div style="padding:16px 20px;display:grid;gap:11px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Tahap Monev':'Stage' ?> *</label>
            <select name="tahap" required class="form-control" style="font-size:12.5px">
              <?php foreach ($tahap_opts as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Tanggal':'Date' ?> *</label>
            <input type="date" name="tanggal" required value="<?= date('Y-m-d') ?>" class="form-control" style="font-size:12.5px">
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Catatan Hasil Monev':'Monev Findings' ?> *</label>
          <textarea name="catatan" required rows="4" class="form-control" style="font-size:12.5px;resize:vertical"
                    placeholder="<?= $id?'Apa yang ditemukan, diobservasi, atau didiskusikan…':'What was found, observed, or discussed…' ?>"></textarea>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Rekomendasi (opsional)':'Recommendation (optional)' ?></label>
          <textarea name="rekomendasi" rows="2" class="form-control" style="font-size:12.5px;resize:vertical"
                    placeholder="<?= $id?'Tindak lanjut yang disarankan…':'Suggested follow-up…' ?>"></textarea>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Lampiran (PDF/JPG/PNG/DOC) — opsional':'Attachment — optional' ?></label>
          <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="form-control" style="font-size:12px">
        </div>
      </div>
      <div style="padding:13px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="closeProsesModal()" class="btn btn-outline"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><?= $id?'Simpan':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Tambah/Edit Luaran -->
<div class="mvl-modal" id="luaranModal" onclick="if(event.target===this)closeLuaranModal()">
  <div class="mvl-box">
    <form method="POST" enctype="multipart/form-data" id="luaranForm">
      <input type="hidden" name="action" value="add_luaran" id="lvAction">
      <input type="hidden" name="luaran_id" value="" id="lvId">
      <input type="hidden" name="pid" value="<?= $view_pid ?>">
      <div style="padding:16px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:14px;font-weight:700" id="lvTitle"><?= $id?'Tambah Luaran':'Add Output' ?></div>
        <button type="button" onclick="closeLuaranModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">×</button>
      </div>
      <div style="padding:16px 20px;display:grid;gap:11px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Jenis Luaran':'Output Type' ?> *</label>
            <select name="jenis_luaran" id="lvJenis" required class="form-control" style="font-size:12.5px">
              <?php foreach ($jenis_luaran_opts as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px">Status *</label>
            <select name="status" id="lvStatus" required class="form-control" style="font-size:12.5px">
              <option value="rencana"><?= $id?'Rencana':'Planned' ?></option>
              <option value="submit"><?= $id?'Submitted':'Submitted' ?></option>
              <option value="terbit"><?= $id?'Terbit':'Published' ?></option>
              <option value="diverifikasi"><?= $id?'Diverifikasi LPPM':'Verified by LPPM' ?></option>
              <option value="ditolak"><?= $id?'Ditolak':'Rejected' ?></option>
            </select>
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Judul Luaran':'Output Title' ?></label>
          <input type="text" name="judul_luaran" id="lvJudul" class="form-control" style="font-size:12.5px" maxlength="500">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Nama Jurnal/Penerbit':'Journal/Publisher' ?></label>
            <input type="text" name="nama_jurnal" id="lvJurnal" class="form-control" style="font-size:12.5px" maxlength="255">
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Tanggal Terbit':'Publication Date' ?></label>
            <input type="date" name="tanggal_terbit" id="lvTgl" class="form-control" style="font-size:12.5px">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px">ISSN / ISBN</label>
            <input type="text" name="issn_isbn" id="lvIssn" class="form-control" style="font-size:12.5px" maxlength="50">
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Akreditasi':'Accreditation' ?></label>
            <select name="akreditasi" id="lvAkr" class="form-control" style="font-size:12.5px">
              <option value="">—</option>
              <?php foreach (['sinta1','sinta2','sinta3','sinta4','sinta5','sinta6','scopusQ1','scopusQ2','scopusQ3'] as $a): ?>
              <option value="<?= $a ?>"><?= strtoupper($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px">URL / DOI</label>
          <input type="url" name="url_doi" id="lvUrl" class="form-control" style="font-size:12.5px" placeholder="https://doi.org/...">
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Bukti (PDF/JPG) — opsional':'Evidence — optional' ?></label>
          <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control" style="font-size:12px">
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Catatan Admin':'Admin Note' ?></label>
          <textarea name="catatan_admin" id="lvCatatan" rows="2" class="form-control" style="font-size:12.5px;resize:vertical"></textarea>
        </div>
      </div>
      <div style="padding:13px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="closeLuaranModal()" class="btn btn-outline"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><?= $id?'Simpan':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openProsesModal(){document.getElementById('prosesModal').classList.add('open')}
function closeProsesModal(){document.getElementById('prosesModal').classList.remove('open')}
function openLuaranModal(data){
  const f = document.getElementById('luaranForm');
  f.reset();
  if (data) {
    document.getElementById('lvAction').value='edit_luaran';
    document.getElementById('lvId').value=data.id;
    document.getElementById('lvTitle').textContent=<?= json_encode($id?'Edit Luaran':'Edit Output') ?>;
    document.getElementById('lvJenis').value=data.jenis_luaran||'';
    document.getElementById('lvStatus').value=data.status||'rencana';
    document.getElementById('lvJudul').value=data.judul_luaran||'';
    document.getElementById('lvJurnal').value=data.nama_jurnal||'';
    document.getElementById('lvTgl').value=data.tanggal_terbit||'';
    document.getElementById('lvIssn').value=data.issn_isbn||'';
    document.getElementById('lvAkr').value=data.akreditasi||'';
    document.getElementById('lvUrl').value=data.url_doi||'';
    document.getElementById('lvCatatan').value=data.catatan_admin||'';
  } else {
    document.getElementById('lvAction').value='add_luaran';
    document.getElementById('lvId').value='';
    document.getElementById('lvTitle').textContent=<?= json_encode($id?'Tambah Luaran':'Add Output') ?>;
  }
  document.getElementById('luaranModal').classList.add('open');
}
function closeLuaranModal(){document.getElementById('luaranModal').classList.remove('open')}
</script>
<?php endif; ?>
</body>
</html>
