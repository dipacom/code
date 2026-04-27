<?php
require_once '../includes/config.php';
require_once '../includes/penerimaan.php';
requireLogin('admin');

$lang  = $_COOKIE['lang'] ?? 'id';

// Proses verifikasi publikasi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Toggle penerimaan ─────────────────────────────────────
    if ($_POST['action'] === 'toggle_penerimaan') {
        $j       = clean($_POST['jenis'] ?? '');
        $current = getPenerimaan($pdo, $j);
        setPenerimaan($pdo, $j, $current === 'buka' ? 'tutup' : 'buka');
        $newVal = $current === 'buka' ? 'tutup' : 'buka';
        $_SESSION['flash'] = [
            'type' => $newVal === 'buka' ? 'success' : 'warning',
            'msg'  => $newVal === 'buka'
                ? ($lang==='id' ? 'Penerimaan pengajuan berhasil dibuka kembali.' : 'Submission intake reopened.')
                : ($lang==='id' ? 'Penerimaan pengajuan berhasil ditutup sementara.' : 'Submission intake temporarily closed.'),
        ];
        redirect('/admin/publikasi.php');
    }

    if ($_POST['action'] === 'verifikasi') {
        $pub_id   = (int)$_POST['publikasi_id'];
        $status   = clean($_POST['status_verifikasi']); // diverifikasi / ditolak
        $catatan  = clean($_POST['catatan'] ?? '');
        $penanda  = clean($_POST['nama_penandatangan']);
        $jabatan  = clean($_POST['jabatan_penandatangan']);
        $tgl      = clean($_POST['tanggal_surat']);

        // Ambil data publikasi + mahasiswa
        $stmt = $pdo->prepare("SELECT p.*, u.email, u.nama_lengkap, u.nim, u.id as uid FROM publikasi p JOIN users u ON p.user_id=u.id WHERE p.id=?");
        $stmt->execute([$pub_id]);
        $pub = $stmt->fetch();

        if ($status === 'diverifikasi') {
            $pdo->prepare("UPDATE publikasi SET status='diverifikasi', catatan_admin=? WHERE id=?")->execute([$catatan, $pub_id]);

            $prefix   = getSetting($pdo, 'prefix_surat_publikasi') ?: 'LPPM/IAKNT/SK-PB';
            $no_surat = generateNomorSurat($pdo, $prefix, 'surat_publikasi');

            $pdo->prepare("INSERT INTO surat_publikasi (publikasi_id, admin_id, nomor_surat, tanggal_surat, nama_penandatangan, jabatan_penandatangan) VALUES (?,?,?,?,?,?)")
                ->execute([$pub_id, $_SESSION['user_id'], $no_surat, $tgl, $penanda, $jabatan]);

            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'sukses')")
                ->execute([$pub['uid'], 'Surat Keterangan Publikasi Siap Diunduh', "Publikasi Anda telah diverifikasi. Surat keterangan telah diterbitkan."]);

            require_once '../includes/email.php';
            kirimEmailMahasiswa(
                $pub['email'],
                $pub['nama_lengkap'],
                'diverifikasi',
                'Surat Keterangan Publikasi',
                '',
                $pub['nim'] ?? ''
            );

            $_SESSION['flash'] = ['type' => 'success', 'msg' => ($lang==='id' ? "Publikasi diverifikasi! Surat diterbitkan: {$no_surat}" : "Publication verified! Certificate issued: {$no_surat}")];

        } else {
            $pdo->prepare("UPDATE publikasi SET status='ditolak', catatan_admin=? WHERE id=?")->execute([$catatan, $pub_id]);

            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'peringatan')")
                ->execute([$pub['uid'], 'Publikasi Tidak Dapat Diverifikasi', "Publikasi Anda tidak dapat diverifikasi. Catatan: {$catatan}"]);

            require_once '../includes/email.php';
            kirimEmailMahasiswa(
                $pub['email'],
                $pub['nama_lengkap'],
                'ditolak',
                'Surat Keterangan Publikasi',
                $catatan,
                $pub['nim'] ?? ''
            );

            $_SESSION['flash'] = ['type' => 'warning', 'msg' => ($lang==='id' ? 'Publikasi ditolak. Mahasiswa telah dinotifikasi.' : 'Publication rejected. Student has been notified.')];
        }
        redirect('/admin/publikasi.php');
    }
}

// ── Soft delete (pindah ke sampah) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_pengajuan') {
    $hid = (int)($_POST['id'] ?? 0);
    if ($hid) {
        $stmt = $pdo->prepare("UPDATE publikasi SET deleted_at=NOW() WHERE id=? AND status IN ('diverifikasi','ditolak')");
        $stmt->execute([$hid]);
        if ($stmt->rowCount()) {
            require_once '../includes/logger.php';
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_pengajuan',
                "Pindah ke sampah: publikasi id={$hid}");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Pengajuan dipindahkan ke Sampah.'];
        }
    }
    redirect('/admin/publikasi.php?filter=' . ($_POST['filter_back'] ?? 'semua'));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Publikasi yang sedang diproses
$proses_id = (int)($_GET['proses'] ?? 0);
$pub_proses = null;
if ($proses_id) {
    $stmt = $pdo->prepare("
        SELECT p.*, u.nama_lengkap, u.nim, u.program_studi, u.email,
               p.nomor_bab, p.editor_buku
        FROM publikasi p JOIN users u ON p.user_id=u.id
        WHERE p.id=?
    ");
    $stmt->execute([$proses_id]);
    $pub_proses = $stmt->fetch();
}

$pending_publikasi = (int)$pdo->query("SELECT COUNT(*) FROM publikasi WHERE status='menunggu' AND deleted_at IS NULL")->fetchColumn();

// Stats per status untuk summary cards
$pb_stats_all = $pdo->query("SELECT status, COUNT(*) c FROM publikasi WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pb_stat_menunggu     = (int)($pb_stats_all['menunggu']     ?? 0);
$pb_stat_diverifikasi = (int)($pb_stats_all['diverifikasi'] ?? 0);
$pb_stat_ditolak      = (int)($pb_stats_all['ditolak']      ?? 0);
$pb_stat_semua        = array_sum($pb_stats_all);

// Daftar semua publikasi
$filter        = $_GET['filter'] ?? 'menunggu';
$filter_search = clean($_GET['q'] ?? '');
$allowed = ['menunggu','diverifikasi','ditolak','semua'];
if (!in_array($filter, $allowed)) $filter = 'menunggu';

$where_parts = ['p.deleted_at IS NULL'];
$params      = [];
if ($filter !== 'semua') {
    $where_parts[] = 'p.status = ?';
    $params[]      = $filter;
}
if ($filter_search !== '') {
    $where_parts[] = '(u.nama_lengkap LIKE ? OR p.judul_publikasi LIKE ? OR u.nim LIKE ?)';
    $kw = '%' . $filter_search . '%';
    array_push($params, $kw, $kw, $kw);
}
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : 'WHERE 1=1';

$stmt = $pdo->prepare("
    SELECT p.*, u.nama_lengkap, u.nim, u.program_studi, sp.nomor_surat
    FROM publikasi p
    JOIN users u ON p.user_id=u.id
    LEFT JOIN surat_publikasi sp ON p.id=sp.publikasi_id
    $where
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$list = $stmt->fetchAll();

$ketua = getSetting($pdo, 'nama_ketua_lppm');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Verifikasi Publikasi':'Verify Publication' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('newspaper') ?> <?= $lang==='id'?'Verifikasi Publikasi Mahasiswa':'Student Publication Verification' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Periksa & terbitkan surat keterangan publikasi':'Review & issue publication certificates' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type']==='success'?'success':'warning' ?>"><?= $flash['msg'] ?></div>
      <?php endif; ?>

      <?php renderPenerimaanBar($pdo, 'publikasi',
            'Surat Rekomendasi Publikasi', 'Publication Recommendation Letter',
            $pending_publikasi, $lang, '/admin/publikasi.php'); ?>

      <!-- Panel Verifikasi -->
      <?php if ($pub_proses): ?>
      <div class="card" style="margin-bottom:24px;border:2px solid #c9952a">
        <div class="card-header" style="background:#fffbeb">
          <span class="card-title"><?= ic('play') ?> <?= $lang==='id'?'Verifikasi Publikasi':'Verify Publication' ?></span>
          <a href="publikasi.php" class="btn btn-outline btn-sm"><?= ic('x') ?> <?= $lang==='id'?'Tutup':'Close' ?></a>
        </div>
        <div class="card-body">

          <!-- Info pengguna + info publikasi (kiri | kanan) -->
          <div class="det-grid" style="margin-bottom:16px;gap:14px;align-items:start">

            <!-- KIRI: Identitas Pengguna -->
            <div style="padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px"><?= $lang==='id'?'Identitas Pengguna':'User Identity' ?></div>
              <div class="det-grid" style="gap:8px">
                <div>
                  <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:2px"><?= $lang==='id'?'Nama':'Name' ?></div>
                  <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($pub_proses['nama_lengkap']) ?></div>
                </div>
                <div>
                  <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:2px">NIM/NIDN</div>
                  <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($pub_proses['nim'] ?? '—') ?></div>
                </div>
                <div style="grid-column:1/-1">
                  <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:2px"><?= $lang==='id'?'Program Studi':'Study Program' ?></div>
                  <div style="font-size:13px"><?= htmlspecialchars($pub_proses['program_studi']??'—') ?></div>
                </div>
                <?php if(!empty($pub_proses['fakultas'])): ?>
                <div style="grid-column:1/-1">
                  <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:2px"><?= $lang==='id'?'Fakultas':'Faculty' ?></div>
                  <div style="font-size:13px"><?= htmlspecialchars($pub_proses['fakultas']) ?></div>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- KANAN: Detail Publikasi -->
            <div style="padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:10px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:10px"><?= $lang==='id'?'Detail Publikasi':'Publication Detail' ?></div>
              <div style="display:grid;grid-template-columns:120px 1fr;gap:5px;font-size:12px">
                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Judul':'Title' ?>:</span>
                <span style="font-weight:600;line-height:1.5"><?= htmlspecialchars($pub_proses['judul_publikasi']) ?></span>

                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Jenis':'Type' ?>:</span>
                <span><span class="badge badge-process"><?= labelJenisPublikasi($pub_proses['jenis_publikasi']) ?></span></span>

                <?php $isBukuJenis = in_array($pub_proses['jenis_publikasi'],['buku','book_chapter']); ?>
                <?php if($pub_proses['nama_jurnal_penerbit']): ?>
                <span style="color:#64748b;font-weight:600"><?= $isBukuJenis?($lang==='id'?'Penerbit':'Publisher'):($lang==='id'?'Jurnal':'Journal') ?>:</span>
                <span><?= htmlspecialchars($pub_proses['nama_jurnal_penerbit']) ?></span>
                <?php endif; ?>
                <?php if($pub_proses['jenis_publikasi']==='book_chapter' && !empty($pub_proses['nomor_bab'])): ?>
                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Bab':'Chapter' ?>:</span>
                <span><?= $lang==='id'?'Bab':'Chapter' ?> <?= (int)$pub_proses['nomor_bab'] ?></span>
                <?php endif; ?>
                <?php if($pub_proses['jenis_publikasi']==='book_chapter' && !empty($pub_proses['editor_buku'])): ?>
                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Editor':'Editor' ?>:</span>
                <span><?= htmlspecialchars($pub_proses['editor_buku']) ?></span>
                <?php endif; ?>
                <?php if($pub_proses['tahun_terbit']): ?>
                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Tahun':'Year' ?>:</span>
                <span><?= $pub_proses['tahun_terbit'] ?></span>
                <?php endif; ?>
                <?php if($pub_proses['url_doi']): ?>
                <?php $isBukuProses = in_array($pub_proses['jenis_publikasi'],['buku','book_chapter']); ?>
                <span style="color:#64748b;font-weight:600"><?= $isBukuProses ? ($lang==='id'?'Kota':'City') : 'DOI/URL' ?>:</span>
                <span><?php if($isBukuProses): ?>
                  <?= htmlspecialchars($pub_proses['url_doi']) ?>
                <?php else: ?>
                  <a href="<?= htmlspecialchars($pub_proses['url_doi']) ?>" target="_blank" style="color:#1e3a5f;word-break:break-all"><?= htmlspecialchars(mb_strimwidth($pub_proses['url_doi'],0,40,'…')) ?></a>
                <?php endif; ?></span>
                <?php endif; ?>
                <?php if($pub_proses['issn_isbn']): ?>
                <span style="color:#64748b;font-weight:600"><?= in_array($pub_proses['jenis_publikasi'],['buku','book_chapter']) ? 'ISBN' : 'ISSN/ISBN' ?>:</span>
                <span><?= htmlspecialchars($pub_proses['issn_isbn']) ?></span>
                <?php endif; ?>
                <?php if($pub_proses['akreditasi_jurnal']): ?>
                <span style="color:#64748b;font-weight:600"><?= $lang==='id'?'Akreditasi':'Accreditation' ?>:</span>
                <span><?php
                  $akr = $pub_proses['akreditasi_jurnal'];
                  $isScopus = str_starts_with($akr, 'scopus');
                  $label = match($akr) {
                      'sinta1'=>'Sinta 1','sinta2'=>'Sinta 2','sinta3'=>'Sinta 3',
                      'sinta4'=>'Sinta 4','sinta5'=>'Sinta 5','sinta6'=>'Sinta 6',
                      'scopusQ1'=>'Scopus Q1','scopusQ2'=>'Scopus Q2','scopusQ3'=>'Scopus Q3',
                      default=>$akr,
                  };
                  $style = $isScopus ? 'background:#faeeda;color:#7a3f0e;border:1px solid #fbbf24' : 'background:#dbeafe;color:#1e3a5f;border:1px solid #93c5fd';
                ?><span style="display:inline-flex;align-items:center;padding:1px 8px;border-radius:8px;font-size:11px;font-weight:600;<?= $style ?>"><?= $isScopus?'◆':'✦' ?> <?= $label ?></span></span>
                <?php endif; ?>
              </div>
              <div style="margin-top:10px">
                <a href="../<?= htmlspecialchars($pub_proses['file_path']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px">
                  <?= ic('doc') ?> <?= $lang==='id'?'Buka File Bukti':'Open Proof File' ?>
                </a>
              </div>
            </div>

          </div>

          <!-- Form verifikasi -->
          <form method="POST">
            <input type="hidden" name="action" value="verifikasi">
            <input type="hidden" name="publikasi_id" value="<?= $pub_proses['id'] ?>">

            <!-- Keputusan -->
            <div class="form-group">
              <label class="form-label"><?= $lang==='id'?'Keputusan Verifikasi':'Verification Decision' ?> <span class="required">*</span></label>
              <div style="display:flex;gap:12px">
                <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;flex:1;transition:.2s" id="lbl_terima">
                  <input type="radio" name="status_verifikasi" value="diverifikasi" required onchange="setDecision('terima')">
                  <?= ic('check-circle', 'style="width:22px;height:22px;color:#16a34a;flex-shrink:0"') ?>
                  <div>
                    <div style="font-weight:600;font-size:14px"><?= $lang==='id'?'Terima & Terbitkan Surat':'Accept & Issue Certificate' ?></div>
                    <div style="font-size:12px;color:#64748b"><?= $lang==='id'?'Publikasi valid, surat akan diterbitkan':'Publication valid, certificate will be issued' ?></div>
                  </div>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;flex:1;transition:.2s" id="lbl_tolak">
                  <input type="radio" name="status_verifikasi" value="ditolak" onchange="setDecision('tolak')">
                  <?= ic('x-circle', 'style="width:22px;height:22px;color:#dc2626;flex-shrink:0"') ?>
                  <div>
                    <div style="font-weight:600;font-size:14px"><?= $lang==='id'?'Tolak':'Reject' ?></div>
                    <div style="font-size:12px;color:#64748b"><?= $lang==='id'?'Publikasi tidak memenuhi syarat':'Publication does not meet requirements' ?></div>
                  </div>
                </label>
              </div>
            </div>

            <!-- Data surat (muncul saat terima) -->
            <div id="data_surat" style="display:none">
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Tanggal Surat':'Letter Date' ?> <span class="required">*</span></label>
                  <input type="date" name="tanggal_surat" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Nama Penandatangan':'Signatory Name' ?> <span class="required">*</span></label>
                  <input type="text" name="nama_penandatangan" class="form-control" value="<?= htmlspecialchars($ketua??'') ?>">
                </div>
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Jabatan':'Position' ?> <span class="required">*</span></label>
                  <input type="text" name="jabatan_penandatangan" class="form-control" value="Ketua LPPM">
                </div>
              </div>
            </div>

            <!-- Catatan -->
            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Catatan':'Notes' ?>
                <span id="catatan_hint" style="font-size:11px;color:#ef4444;font-weight:400"><?= $lang==='id'?'(wajib diisi jika menolak)':'(required if rejecting)' ?></span>
              </label>
              <textarea name="catatan" id="catatan_input" class="form-control" rows="2"
                placeholder="<?= $lang==='id'?'Alasan penolakan atau catatan tambahan...':'Rejection reason or additional notes...' ?>"></textarea>
            </div>

            <div style="display:flex;gap:10px">
              <button type="submit" class="btn btn-primary" id="submit_btn">
                <?= ic('check-circle') ?> <?= $lang==='id'?'Simpan Keputusan':'Save Decision' ?>
              </button>
              <a href="publikasi.php" class="btn btn-outline"><?= $lang==='id'?'Batal':'Cancel' ?></a>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- Stats & Filter Cards -->
      <div class="stats-grid" style="margin-bottom:20px">
        <?php
        $pbCards = [
          ['menunggu',     $lang==='id'?'Menunggu Verifikasi':'Pending Verification', 'amber', 'clock',  $pb_stat_menunggu],
          ['diverifikasi', $lang==='id'?'Diverifikasi':'Verified',                   'green', 'check',  $pb_stat_diverifikasi],
          ['ditolak',      $lang==='id'?'Ditolak':'Rejected',                        'red',   'x',      $pb_stat_ditolak],
          ['semua',        $lang==='id'?'Semua Pengajuan':'All Submissions',          'navy',  'doc',    $pb_stat_semua],
        ];
        foreach ($pbCards as [$key, $lbl, $color, $icon, $n]):
          $isActive = ($filter === $key);
        ?>
        <a href="publikasi.php?filter=<?= $key ?>" class="stat-card"
           style="text-decoration:none<?= $isActive ? ';outline:2px solid var(--primary);outline-offset:1px' : '' ?>">
          <div class="stat-icon <?= $color ?>"><?= ic($icon) ?></div>
          <div>
            <div class="stat-label"><?= $lbl ?></div>
            <div class="stat-value"><?= $n ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter Pencarian -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="padding:14px 16px">
          <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">
                <?= $lang==='id'?'Cari nama / judul publikasi / NIM':'Search name / publication title / NIM' ?>
              </label>
              <input type="text" name="q" class="form-control" style="height:36px;font-size:13px"
                     value="<?= htmlspecialchars($filter_search) ?>"
                     placeholder="<?= $lang==='id'?'Cari...':'Search...' ?>">
            </div>
            <div style="min-width:160px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">Status</label>
              <select name="filter" class="form-control" style="height:36px;font-size:13px">
                <option value="semua"        <?= $filter==='semua'       ?'selected':'' ?>><?= $lang==='id'?'Semua Status':'All Status' ?></option>
                <option value="menunggu"     <?= $filter==='menunggu'    ?'selected':'' ?>><?= $lang==='id'?'Menunggu':'Pending' ?></option>
                <option value="diverifikasi" <?= $filter==='diverifikasi'?'selected':'' ?>><?= $lang==='id'?'Diverifikasi':'Verified' ?></option>
                <option value="ditolak"      <?= $filter==='ditolak'     ?'selected':'' ?>><?= $lang==='id'?'Ditolak':'Rejected' ?></option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px">
              <?= ic('search') ?> <?= $lang==='id'?'Cari':'Search' ?>
            </button>
            <?php if ($filter_search || $filter !== 'menunggu'): ?>
              <a href="publikasi.php" class="btn btn-outline btn-sm" style="height:36px">Reset</a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="card">
        <div class="card-header">
          <span class="card-title"><?= ic('clipboard') ?> <?= $lang==='id'?'Daftar Publikasi':'Publication List' ?> (<?= count($list) ?>)</span>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= $lang==='id'?'Tidak ada data.':'No data found.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="resp-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th><?= $lang==='id'?'Mahasiswa':'Student' ?></th>
                  <th><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></th>
                  <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'No. Surat':'Letter No.' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($list as $i=>$r): ?>
                <tr>
                  <td class="td-no"><?= $i+1 ?></td>
                  <td class="td-nama">
                    <div class="td-nama-inner">
                      <div>
                        <span><?= htmlspecialchars($r['nama_lengkap']) ?></span>
                        <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:1px"><?= htmlspecialchars($r['nim'] ?? '') ?></div>
                        <?php if (!empty($r['program_studi'])): ?>
                        <div style="font-size:10px;color:rgba(255,255,255,.45)"><?= htmlspecialchars(mb_strimwidth($r['program_studi'],0,24,'…')) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td data-label="<?= $lang==='id'?'Judul':'Title' ?>">
                    <div>
                      <?= htmlspecialchars(mb_strimwidth($r['judul_publikasi'],0,50,'…')) ?>
                      <br><a href="../<?= htmlspecialchars($r['file_path']) ?>" target="_blank" style="font-size:10px;color:var(--primary-mid);display:inline-flex;align-items:center;gap:2px;margin-top:3px"><?= ic('doc') ?> <?= $lang==='id'?'Lihat file':'View file' ?></a>
                    </div>
                  </td>
                  <td data-label="<?= $lang==='id'?'Jenis':'Type' ?>"><span class="badge badge-process" style="font-size:11px"><?= labelJenisPublikasi($r['jenis_publikasi']) ?></span></td>
                  <td data-label="Status"><?= badgeStatus($r['status']) ?></td>
                  <td data-label="<?= $lang==='id'?'No. Surat':'Letter No.' ?>"><?= $r['nomor_surat'] ?? '—' ?></td>
                  <td data-label="<?= $lang==='id'?'Tanggal':'Date' ?>"><?= formatTanggal($r['created_at']) ?></td>
                  <td class="td-aksi">
                    <div class="aksi-wrap">
                      <?php if ($r['status'] === 'menunggu'): ?>
                        <a href="publikasi.php?proses=<?= $r['id'] ?>" class="btn btn-gold btn-sm"><?= ic('play') ?> <?= $lang==='id'?'Proses':'Process' ?></a>
                      <?php else: ?>
                        <?php if ($r['nomor_surat']): ?>
                          <a href="../modules/publikasi/unduh_surat.php?nomor=<?= urlencode($r['nomor_surat']) ?>" class="btn btn-success btn-sm"><?= ic('download') ?> Surat</a>
                        <?php endif; ?>
                        <?php if (in_array($r['status'], ['diverifikasi','ditolak'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('<?= $lang==='id'?'Pindahkan ke Sampah? Data masih tersimpan 30 hari.':'Move to Trash? Data is kept for 30 days.' ?>')">
                          <input type="hidden" name="action" value="hapus_pengajuan">
                          <input type="hidden" name="id" value="<?= $r['id'] ?>">
                          <input type="hidden" name="filter_back" value="<?= htmlspecialchars($filter) ?>">
                          <button type="submit" class="btn btn-sm" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3" title="<?= $lang==='id'?'Pindah ke Sampah':'Move to Trash' ?>">
                            <?= ic('trash') ?>
                          </button>
                        </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
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
</div>

<script>
function setDecision(type) {
  const lblTerima = document.getElementById('lbl_terima');
  const lblTolak  = document.getElementById('lbl_tolak');
  const dataSurat = document.getElementById('data_surat');
  const catHint   = document.getElementById('catatan_hint');

  if (type === 'terima') {
    lblTerima.style.borderColor = '#059669';
    lblTerima.style.background  = '#d1fae5';
    lblTolak.style.borderColor  = '#e2e8f0';
    lblTolak.style.background   = '#fff';
    dataSurat.style.display = 'block';
    catHint.style.display   = 'none';
  } else {
    lblTolak.style.borderColor  = '#dc2626';
    lblTolak.style.background   = '#fee2e2';
    lblTerima.style.borderColor = '#e2e8f0';
    lblTerima.style.background  = '#fff';
    dataSurat.style.display = 'none';
    catHint.style.display   = 'inline';
  }
}
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
