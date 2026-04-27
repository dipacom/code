<?php
require_once '../includes/config.php';
require_once '../includes/penerimaan.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$msg  = '';

// ── Proses aksi ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = (int)($_POST['id'] ?? 0);
    $aksi    = clean($_POST['aksi'] ?? '');
    $catatan = clean($_POST['catatan'] ?? '');
    $nomor   = clean($_POST['nomor_surat'] ?? '');

    // Toggle penerimaan
    if ($aksi === 'toggle_penerimaan' || ($_POST['action'] ?? '') === 'toggle_penerimaan') {
        $j       = clean($_POST['jenis'] ?? '');
        $current = getPenerimaan($pdo, $j);
        setPenerimaan($pdo, $j, $current === 'buka' ? 'tutup' : 'buka');
        $newVal = $current === 'buka' ? 'tutup' : 'buka';
        $_SESSION['flash'] = [
            'type' => $newVal === 'buka' ? 'success' : 'warning',
            'msg'  => $newVal === 'buka'
                ? ($lang==='id' ? 'Penerimaan Ethical Clearance berhasil dibuka kembali.' : 'Ethical Clearance intake reopened.')
                : ($lang==='id' ? 'Penerimaan Ethical Clearance berhasil ditutup sementara.' : 'Ethical Clearance intake temporarily closed.'),
        ];
        redirect('/admin/ethical_clearance.php');
    }

    if ($id && in_array($aksi, ['disetujui','ditolak','diproses'])) {
        $stmt = $pdo->prepare("
            UPDATE ethical_clearance
            SET status=?, catatan_admin=?, nomor_surat=?, tanggal_proses=NOW(), updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$aksi, $catatan ?: null, $nomor ?: null, $id]);

        // Ambil data EC + email pemohon
        $ec_stmt = $pdo->prepare("
            SELECT ec.*, u.nama_lengkap, u.email, u.nim, u.nidn
            FROM ethical_clearance ec
            JOIN users u ON ec.user_id = u.id
            WHERE ec.id = ?
        ");
        $ec_stmt->execute([$id]);
        $ec = $ec_stmt->fetch();

        if ($ec) {
            // Notifikasi dalam sistem
            $label_aksi = ['disetujui'=>'disetujui','ditolak'=>'tidak disetujui','diproses'=>'sedang ditinjau'][$aksi] ?? $aksi;
            $tipe_notif = $aksi === 'disetujui' ? 'sukses' : ($aksi === 'ditolak' ? 'error' : 'info');
            $pesan_notif = "Permohonan Ethical Clearance Anda untuk \"{$ec['judul_penelitian']}\" {$label_aksi}."
                         . ($catatan ? " Catatan admin: {$catatan}" : '')
                         . ($aksi === 'disetujui' ? ' Surat siap diunduh dari beranda.' : '');

            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
                ->execute([$ec['user_id'], 'Update Status: Ethical Clearance', $pesan_notif, $tipe_notif]);

            // Email ke pemohon
            if (!empty($ec['email'])) {
                require_once '../includes/email.php';
                kirimEmailStatusEC(
                    $ec['email'],
                    $ec['nama_lengkap'],
                    $aksi,
                    $ec['judul_penelitian'],
                    $catatan,
                    $nomor
                );
            }
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Status permohonan berhasil diperbarui dan notifikasi email telah dikirimkan.'];
        redirect('/admin/ethical_clearance.php');
    }
}

// ── Upload surat bertandatangan (admin) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_surat_signed') {
    $ec_id = (int)($_POST['ec_id'] ?? 0);
    $file  = $_FILES['file_surat_signed'] ?? null;
    if ($ec_id && $file && $file['error'] === 0) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'File harus berformat PDF.'];
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Ukuran file maksimal 20 MB.'];
        } else {
            // Ambil data lama (untuk hapus file lama & ambil user_id)
            $row_old = $pdo->prepare("SELECT file_surat_signed, user_id, judul_penelitian FROM ethical_clearance WHERE id=?");
            $row_old->execute([$ec_id]);
            $row_old = $row_old->fetch();

            $dir = BASE_PATH . '/uploads/ethical_clearance/signed/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'EC_signed_' . $ec_id . '_' . time() . '.pdf';

            if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                // Hapus file lama jika ada
                if (!empty($row_old['file_surat_signed'])) {
                    $old_path = BASE_PATH . '/' . $row_old['file_surat_signed'];
                    if (file_exists($old_path)) @unlink($old_path);
                }
                $new_path = 'uploads/ethical_clearance/signed/' . $fname;
                $pdo->prepare("UPDATE ethical_clearance SET file_surat_signed=? WHERE id=?")->execute([$new_path, $ec_id]);

                // Notifikasi ke pemohon
                $judul_penelitian = $row_old['judul_penelitian'] ?? '';
                $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'sukses')")
                    ->execute([
                        $row_old['user_id'],
                        'Surat Ethical Clearance Siap Diunduh',
                        "Surat Ethical Clearance untuk penelitian \"" . mb_strimwidth($judul_penelitian,0,60,'…') . "\" (bertandatangan dan berstempel) telah diunggah oleh Tim Etik LPPM dan siap diunduh."
                    ]);

                // Email ke pemohon
                require_once '../includes/logger.php';
                writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_upload_surat_ec',
                    "Upload surat bertandatangan EC id={$ec_id}");

                $user_info = $pdo->prepare("SELECT email, nama_lengkap FROM users WHERE id=?");
                $user_info->execute([$row_old['user_id']]);
                $user_info = $user_info->fetch();
                if (!empty($user_info['email'])) {
                    require_once '../includes/email.php';
                    kirimEmailStatusEC(
                        $user_info['email'],
                        $user_info['nama_lengkap'],
                        'disetujui',
                        $judul_penelitian,
                        'Surat Keterangan Ethical Clearance Anda (bertandatangan dan berstempel) telah diunggah dan dapat diunduh melalui Dashboard.',
                        ''
                    );
                }

                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat bertandatangan berhasil diunggah. Pemohon telah dinotifikasi.'];
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Gagal mengunggah file. Silakan coba lagi.'];
            }
        }
    } elseif ($ec_id && $file && $file['error'] !== 0) {
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Tidak ada file yang dipilih.'];
    }
    redirect('/admin/ethical_clearance.php?status=' . ($_POST['filter_back'] ?? ''));
}

// ── Hapus surat bertandatangan ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_surat_signed') {
    $ec_id = (int)($_POST['ec_id'] ?? 0);
    if ($ec_id) {
        $row_del = $pdo->prepare("SELECT file_surat_signed FROM ethical_clearance WHERE id=?");
        $row_del->execute([$ec_id]);
        $row_del = $row_del->fetch();
        if (!empty($row_del['file_surat_signed'])) {
            $del_path = BASE_PATH . '/' . $row_del['file_surat_signed'];
            if (file_exists($del_path)) @unlink($del_path);
        }
        $pdo->prepare("UPDATE ethical_clearance SET file_surat_signed=NULL WHERE id=?")->execute([$ec_id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat bertandatangan berhasil dihapus.'];
    }
    redirect('/admin/ethical_clearance.php?status=' . ($_POST['filter_back'] ?? ''));
}

// ── Soft delete (pindah ke sampah) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_pengajuan') {
    $hid = (int)($_POST['id'] ?? 0);
    if ($hid) {
        $stmt = $pdo->prepare("UPDATE ethical_clearance SET deleted_at=NOW() WHERE id=? AND status IN ('disetujui','ditolak')");
        $stmt->execute([$hid]);
        if ($stmt->rowCount()) {
            require_once '../includes/logger.php';
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_pengajuan',
                "Pindah ke sampah: ethical clearance id={$hid}");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Pengajuan dipindahkan ke Sampah.'];
        }
    }
    redirect('/admin/ethical_clearance.php?status=' . ($_POST['filter_back'] ?? ''));
}

// ── Filter ────────────────────────────────────────────────────────
$filter_status = clean($_GET['status'] ?? '');
$filter_search = clean($_GET['q'] ?? '');

$where = ['ec.deleted_at IS NULL'];
$params = [];
if ($filter_status) { $where[] = 'ec.status=?'; $params[] = $filter_status; }
if ($filter_search) {
    $where[] = '(u.nama_lengkap LIKE ? OR ec.judul_penelitian LIKE ? OR u.nim LIKE ? OR u.nidn LIKE ?)';
    $kw = '%'.$filter_search.'%';
    $params = array_merge($params, [$kw,$kw,$kw,$kw]);
}

$sql = "
    SELECT ec.*, u.nama_lengkap, u.nim, u.nidn, u.program_studi, u.fakultas, u.email
    FROM ethical_clearance ec
    JOIN users u ON ec.user_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(ec.status,'menunggu','diproses','disetujui','ditolak'), ec.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

// ── Statistik ─────────────────────────────────────────────────────
$stats = $pdo->query("SELECT status, COUNT(*) c FROM ethical_clearance WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pending_ec = (int)($stats['menunggu'] ?? 0);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$jenis_label = [
    'eksperimental'      => 'Eksperimental',
    'non_eksperimental'  => 'Non-Eksperimental',
    'studi_kasus'        => 'Studi Kasus',
    'survei'             => 'Survei / Kuesioner',
    'tinjauan_literatur' => 'Tinjauan Literatur',
    'lainnya'            => 'Lainnya',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ethical Clearance — Admin LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          Ethical Clearance
          <span class="breadcrumb">Kelola Permohonan Ethical Clearance</span>
        </div>
      </div>
      <div class="topbar-right"></div>
    </div>

    <div class="page-content">

      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:16px"><?= $flash['msg'] ?></div>
      <?php endif; ?>

      <?php renderPenerimaanBar($pdo, 'ec',
            'Ethical Clearance', 'Ethical Clearance',
            $pending_ec, $lang, '/admin/ethical_clearance.php'); ?>

      <!-- Stats -->
      <div class="stats-grid" style="margin-bottom:20px">
        <?php
        $st = [
          ['menunggu',  $lang==='id'?'Menunggu Review':'Pending Review', 'amber', 'clock'],
          ['diproses',  $lang==='id'?'Sedang Diproses':'Processing',     'blue',  'refresh'],
          ['disetujui', $lang==='id'?'Disetujui':'Approved',             'green', 'check'],
          ['ditolak',   $lang==='id'?'Ditolak':'Rejected',               'red',   'x'],
        ];
        foreach ($st as [$key, $lbl, $color, $icon]):
          $n = $stats[$key] ?? 0;
          $isActive = ($filter_status === $key);
        ?>
        <a href="?status=<?= $key ?>" class="stat-card"
           style="text-decoration:none<?= $isActive ? ';outline:2px solid var(--primary);outline-offset:1px' : '' ?>">
          <div class="stat-icon <?= $color ?>"><?= ic($icon) ?></div>
          <div>
            <div class="stat-label"><?= $lbl ?></div>
            <div class="stat-value"><?= $n ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="padding:14px 16px">
          <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">Cari nama / judul / NIM / NIDN</label>
              <input type="text" name="q" class="form-control" style="height:36px;font-size:13px"
                     value="<?= htmlspecialchars($filter_search) ?>" placeholder="Cari...">
            </div>
            <div style="min-width:160px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">Status</label>
              <select name="status" class="form-control" style="height:36px;font-size:13px">
                <option value="">Semua Status</option>
                <option value="menunggu"  <?= $filter_status==='menunggu' ?'selected':'' ?>>Menunggu</option>
                <option value="diproses"  <?= $filter_status==='diproses' ?'selected':'' ?>>Diproses</option>
                <option value="disetujui" <?= $filter_status==='disetujui'?'selected':'' ?>>Disetujui</option>
                <option value="ditolak"   <?= $filter_status==='ditolak'  ?'selected':'' ?>>Ditolak</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px">Cari</button>
            <?php if ($filter_status || $filter_search): ?>
              <a href="ethical_clearance.php" class="btn btn-outline btn-sm" style="height:36px">Reset</a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="card">
        <div class="card-header">
          <span class="card-title"><?= ic('clipboard') ?> Daftar Permohonan
            <span style="font-size:12px;font-weight:400;color:var(--text-muted);margin-left:6px"><?= count($list) ?> permohonan</span>
          </span>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($list)): ?>
            <div style="padding:40px;text-align:center;color:#94a3b8">
              <?= ic('inbox','style="width:32px;height:32px;display:block;margin:0 auto 10px;opacity:.4"') ?>
              Belum ada permohonan.
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="resp-table">
              <thead>
                <tr>
                  <th>Pemohon</th>
                  <th>Judul Penelitian</th>
                  <th>Jenis</th>
                  <th>Jurnal Target</th>
                  <th>Tanggal</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($list as $ec):
                  $badge = match($ec['status']) {
                    'disetujui' => 'badge-success',
                    'ditolak'   => 'badge-danger',
                    'diproses'  => 'badge-info',
                    default     => 'badge-warning',
                  };
                  $label_status = match($ec['status']) {
                    'disetujui' => 'Disetujui',
                    'ditolak'   => 'Ditolak',
                    'diproses'  => 'Diproses',
                    default     => 'Menunggu',
                  };
                  $id_user_label = $ec['nidn'] ? 'NIDN '.$ec['nidn'] : ($ec['nim'] ? 'NIM '.$ec['nim'] : '—');
                ?>
                <tr>
                  <td class="td-nama">
                    <div class="td-nama-inner">
                      <div>
                        <span><?= htmlspecialchars($ec['nama_lengkap']) ?></span>
                        <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:1px"><?= htmlspecialchars($id_user_label) ?></div>
                        <div style="font-size:10px;color:rgba(255,255,255,.45)"><?= htmlspecialchars($ec['program_studi'] ?? '—') ?></div>
                      </div>
                    </div>
                  </td>
                  <td data-label="Judul">
                    <div>
                      <div style="font-size:13px;line-height:1.4" title="<?= htmlspecialchars($ec['judul_penelitian']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($ec['judul_penelitian'], 0, 70, '…')) ?>
                      </div>
                      <?php if ($ec['melibatkan_manusia'] || $ec['melibatkan_hewan']): ?>
                      <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
                        <?php if ($ec['melibatkan_manusia']): ?>
                          <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px">👤 Manusia</span>
                        <?php endif; ?>
                        <?php if ($ec['melibatkan_hewan']): ?>
                          <span style="font-size:10px;background:#fce7f3;color:#9d174d;padding:1px 6px;border-radius:4px">🐾 Hewan</span>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td data-label="Jenis"><?= htmlspecialchars($jenis_label[$ec['jenis_penelitian']] ?? $ec['jenis_penelitian'] ?? '—') ?></td>
                  <td data-label="Jurnal"><?= htmlspecialchars(mb_strimwidth($ec['nama_jurnal'] ?? '—', 0, 40, '…')) ?></td>
                  <td data-label="Tanggal"><?= date('d/m/Y', strtotime($ec['created_at'])) ?></td>
                  <td data-label="Status"><span class="badge <?= $badge ?>"><?= $label_status ?></span></td>
                  <td class="td-aksi">
                    <div class="aksi-wrap">
                      <button class="btn btn-outline btn-sm" onclick="openDetail(<?= $ec['id'] ?>)">
                        <?= ic('search','style="width:13px;height:13px"') ?> Tinjau
                      </button>
                      <?php if ($ec['status'] === 'disetujui' && $ec['nomor_surat']): ?>
                        <a href="<?= BASE_URL ?>/modules/ethical_clearance/surat.php?id=<?= $ec['id'] ?>" target="_blank"
                           class="btn btn-sm" style="background:#dcfce7;color:#166534;border:none;font-weight:600"
                           title="Preview Surat Digital">
                          <?= ic('printer','style="width:13px;height:13px"') ?>
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($ec['file_surat_signed'])): ?>
                        <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_surat_signed']) ?>" target="_blank"
                           class="btn btn-sm" style="background:#166534;color:#fff;border:none;font-weight:600"
                           title="Unduh Surat Bertandatangan">
                          <?= ic('download','style="width:13px;height:13px"') ?>
                        </a>
                      <?php endif; ?>
                      <?php if (in_array($ec['status'], ['disetujui','ditolak'])): ?>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Pindahkan ke Sampah? Data masih tersimpan 30 hari.')">
                        <input type="hidden" name="action" value="hapus_pengajuan">
                        <input type="hidden" name="id" value="<?= $ec['id'] ?>">
                        <input type="hidden" name="filter_back" value="<?= htmlspecialchars($filter_status) ?>">
                        <button type="submit" class="btn btn-sm" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3" title="Pindah ke Sampah">
                          <?= ic('trash','style="width:13px;height:13px"') ?>
                        </button>
                      </form>
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

<!-- ── Detail + Aksi Modal (Modern) ─────────────────────────── -->
<div id="ec-modal" class="ec-modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="ec-modal__overlay" onclick="closeDetail()"></div>
  <div class="ec-modal__dialog" role="document">
    <div class="ec-modal__header">
      <div class="ec-modal__title">
        <span class="ec-modal__title-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        </span>
        <div>
          <div class="ec-modal__title-main">Tinjau Permohonan Ethical Clearance</div>
          <div class="ec-modal__title-sub">Detail lengkap pengajuan dan aksi persetujuan</div>
        </div>
      </div>
      <button type="button" class="ec-modal__close" onclick="closeDetail()" aria-label="Tutup">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="ec-modal__body" id="detail-content"></div>
    <div class="ec-modal__footer">
      <button type="button" class="btn btn-outline" onclick="closeDetail()">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Tutup
      </button>
    </div>
  </div>
</div>
<!-- legacy placeholders untuk script lama (tidak dipakai tampilan baru) -->
<div id="detail-overlay" style="display:none" onclick="closeDetail()"></div>
<div id="detail-drawer" style="display:none"></div>

<!-- Pre-rendered detail cards -->
<?php foreach ($list as $ec):
  $id_user_label = $ec['nidn'] ? 'NIDN '.$ec['nidn'] : ($ec['nim'] ? 'NIM '.$ec['nim'] : '—');
  $badge = match($ec['status']) {
    'disetujui' => 'badge-success', 'ditolak' => 'badge-danger',
    'diproses'  => 'badge-info',    default   => 'badge-warning',
  };
  $label_status = match($ec['status']) {
    'disetujui'=>'Disetujui','ditolak'=>'Ditolak','diproses'=>'Diproses',default=>'Menunggu',
  };
?>
<div id="det-<?= $ec['id'] ?>" style="display:none">
  <div class="ec-topbar">
    <div class="ec-topbar__meta">
      <div class="ec-topbar__idlabel">Ethical Clearance #<?= $ec['id'] ?></div>
      <div class="ec-topbar__judul"><?= htmlspecialchars(mb_strimwidth($ec['judul_penelitian'], 0, 90, '…')) ?></div>
    </div>
    <span class="badge <?= $badge ?>" style="font-size:12px"><?= $label_status ?></span>
  </div>

  <!-- Section: Identitas Pemohon -->
  <section class="ec-section">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </span>
      <span class="ec-section__title">Identitas Pemohon</span>
    </div>
    <div class="ec-section__body">
      <div class="ec-grid">
        <div class="ec-field"><div class="ec-field__label">Nama</div><div class="ec-field__value ec-field__value--strong"><?= htmlspecialchars($ec['nama_lengkap']) ?></div></div>
        <div class="ec-field"><div class="ec-field__label">Identitas</div><div class="ec-field__value ec-field__value--strong"><?= htmlspecialchars($id_user_label) ?></div></div>
        <div class="ec-field"><div class="ec-field__label">Program Studi</div><div class="ec-field__value"><?= htmlspecialchars($ec['program_studi'] ?? '—') ?></div></div>
        <div class="ec-field"><div class="ec-field__label">Fakultas</div><div class="ec-field__value"><?= htmlspecialchars($ec['fakultas'] ?? '—') ?></div></div>
        <div class="ec-field ec-field--full"><div class="ec-field__label">Email</div><div class="ec-field__value"><?= htmlspecialchars($ec['email'] ?? '—') ?></div></div>
      </div>
    </div>
  </section>

  <!-- Section: Data Penelitian -->
  <section class="ec-section">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
      </span>
      <span class="ec-section__title">Data Penelitian</span>
    </div>
    <div class="ec-section__body">
      <div class="ec-field ec-field--full">
        <div class="ec-field__label">Judul</div>
        <div class="ec-field__value ec-field__value--strong" style="line-height:1.55"><?= htmlspecialchars($ec['judul_penelitian']) ?></div>
      </div>
      <div class="ec-grid" style="margin-top:10px">
        <div class="ec-field"><div class="ec-field__label">Ketua Peneliti (PI)</div><div class="ec-field__value ec-field__value--strong"><?= htmlspecialchars($ec['ketua_peneliti'] ?? $ec['nama_ketua'] ?? '—') ?></div></div>
        <div class="ec-field"><div class="ec-field__label">Jurnal Target</div><div class="ec-field__value"><?= htmlspecialchars($ec['nama_jurnal'] ?? '—') ?></div></div>
        <div class="ec-field">
          <div class="ec-field__label">Subjek Manusia</div>
          <div class="ec-field__value">
            <?php if (($ec['melibatkan_manusia'] ?? $ec['melibatkan_subjek_manusia'] ?? 0)): ?>
              <span class="ec-chip ec-chip--ok"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Ya</span>
            <?php else: ?>
              <span class="ec-chip ec-chip--muted"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg> Tidak</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="ec-field">
          <div class="ec-field__label">Hewan Percobaan</div>
          <div class="ec-field__value">
            <?php if ($ec['melibatkan_hewan'] ?? 0): ?>
              <span class="ec-chip ec-chip--ok"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Ya</span>
            <?php else: ?>
              <span class="ec-chip ec-chip--muted"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg> Tidak</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($ec['lokasi_penelitian'])): ?>
        <div class="ec-field ec-field--full"><div class="ec-field__label">Lokasi Penelitian</div><div class="ec-field__value"><?= htmlspecialchars($ec['lokasi_penelitian']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($ec['tgl_mulai']) || !empty($ec['tgl_selesai'])): ?>
        <div class="ec-field"><div class="ec-field__label">Tgl Mulai</div><div class="ec-field__value"><?= !empty($ec['tgl_mulai']) ? date('d/m/Y', strtotime($ec['tgl_mulai'])) : '—' ?></div></div>
        <div class="ec-field"><div class="ec-field__label">Tgl Selesai</div><div class="ec-field__value"><?= !empty($ec['tgl_selesai']) ? date('d/m/Y', strtotime($ec['tgl_selesai'])) : '—' ?></div></div>
        <?php endif; ?>
        <?php if (!empty($ec['anggota_tim'])): ?>
        <div class="ec-field ec-field--full"><div class="ec-field__label">Anggota Tim</div><div class="ec-field__value" style="line-height:1.55"><?= nl2br(htmlspecialchars($ec['anggota_tim'])) ?></div></div>
        <?php endif; ?>
        <?php if (($ec['deskripsi'] ?? $ec['abstrak'] ?? null)): ?>
        <div class="ec-field ec-field--full"><div class="ec-field__label">Deskripsi / Abstrak</div><div class="ec-field__value" style="line-height:1.6"><?= nl2br(htmlspecialchars($ec['deskripsi'] ?? $ec['abstrak'])) ?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($ec['catatan_admin']): ?>
  <section class="ec-section ec-section--note">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      </span>
      <span class="ec-section__title">Catatan Sebelumnya</span>
    </div>
    <div class="ec-section__body" style="font-size:13px;line-height:1.55"><?= nl2br(htmlspecialchars($ec['catatan_admin'])) ?></div>
  </section>
  <?php endif; ?>

  <!-- Section: Update Status -->
  <section class="ec-section ec-section--action">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </span>
      <span class="ec-section__title">Update Status</span>
    </div>
  <form method="POST" class="ec-section__body" style="padding-top:4px">
    <input type="hidden" name="id" value="<?= $ec['id'] ?>">
    <div class="form-group" style="margin-bottom:12px">
      <label class="form-label" style="font-size:12px">Nomor Surat (jika disetujui)</label>
      <?php
        $prefix_ec    = getSetting($pdo, 'prefix_surat_ec') ?: 'LPPM/IAKNT/EC';
        $bln_romawi   = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'][(int)date('m')-1];
        $contoh_nomor = '001/' . $prefix_ec . '/' . $bln_romawi . '/' . date('Y');
      ?>
      <input type="text" name="nomor_surat" class="form-control" style="font-size:13px"
             placeholder="Contoh: <?= htmlspecialchars($contoh_nomor) ?>"
             value="<?= htmlspecialchars($ec['nomor_surat'] ?? '') ?>">
    </div>
    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label" style="font-size:12px">Catatan / Keterangan</label>
      <textarea name="catatan" class="form-control" rows="2" style="font-size:13px"
                placeholder="Catatan untuk pemohon (opsional)..."><?= htmlspecialchars($ec['catatan_admin'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="submit" name="aksi" value="diproses" class="ec-btn ec-btn--info">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Tandai Diproses
      </button>
      <button type="submit" name="aksi" value="disetujui" class="ec-btn ec-btn--success">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        Setujui
      </button>
      <button type="submit" name="aksi" value="ditolak" class="ec-btn ec-btn--danger" onclick="return confirm('Tolak permohonan ini?')">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Tolak
      </button>
    </div>
  </form>
  </section>

  <?php
    $file_utama = $ec['file_path'] ?? $ec['file_laporan'] ?? $ec['file_proposal'] ?? null;
    $has_lampiran = $file_utama
      || !empty($ec['file_surat_permohonan'])
      || !empty($ec['file_surat_pernyataan'])
      || !empty($ec['file_persetujuan_subjek'])
      || !empty($ec['file_proposal'])
      || !empty($ec['file_surat_izin']);
  ?>
  <?php if ($has_lampiran): ?>
  <section class="ec-section">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
      </span>
      <span class="ec-section__title">Lampiran Dokumen</span>
    </div>
    <div class="ec-section__body">
      <?php if ($file_utama): ?>
      <a href="<?= BASE_URL ?>/<?= htmlspecialchars($file_utama) ?>" target="_blank" class="ec-btn ec-btn--outline" style="width:100%;justify-content:center">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Unduh Dokumen Utama
      </a>
      <?php endif; ?>
      <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
        <?php if (!empty($ec['file_surat_permohonan'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_surat_permohonan']) ?>" target="_blank" class="ec-btn ec-btn--outline ec-btn--sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Surat Permohonan
          </a>
        <?php endif; ?>
        <?php if (!empty($ec['file_surat_pernyataan'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_surat_pernyataan']) ?>" target="_blank" class="ec-btn ec-btn--outline ec-btn--sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Surat Pernyataan
          </a>
        <?php endif; ?>
        <?php if (!empty($ec['file_persetujuan_subjek'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_persetujuan_subjek']) ?>" target="_blank" class="ec-btn ec-btn--outline ec-btn--sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Informed Consent
          </a>
        <?php endif; ?>
        <?php if (!empty($ec['file_proposal'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_proposal']) ?>" target="_blank" class="ec-btn ec-btn--outline ec-btn--sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Proposal
          </a>
        <?php endif; ?>
        <?php if (!empty($ec['file_surat_izin'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_surat_izin']) ?>" target="_blank" class="ec-btn ec-btn--outline ec-btn--sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Surat Izin
          </a>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Section: Surat Bertandatangan (Upload Admin) -->
  <section class="ec-section ec-section--signed <?= !empty($ec['file_surat_signed']) ? 'is-ok' : '' ?>">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </span>
      <span class="ec-section__title">Surat Bertandatangan & Berstempel</span>
      <?php if (!empty($ec['file_surat_signed'])): ?>
        <span class="ec-chip ec-chip--ok" style="margin-left:auto">Sudah diunggah</span>
      <?php else: ?>
        <span class="ec-chip ec-chip--muted" style="margin-left:auto">Belum diunggah</span>
      <?php endif; ?>
    </div>
    <div class="ec-section__body">
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px">
        <?= !empty($ec['file_surat_signed']) ? 'Sudah diunggah — pemohon dapat mengunduh versi bertandatangan.' : 'Belum diunggah — pemohon hanya mendapat surat digital otomatis.' ?>
      </div>

    <?php if (!empty($ec['file_surat_signed'])): ?>
    <!-- File sudah ada -->
    <div style="background:#fff;border:1px solid #86efac;border-radius:8px;padding:10px 12px;margin-bottom:10px;display:flex;align-items:center;gap:10px">
      <?= ic('doc','style="width:16px;height:16px;color:#16a34a;flex-shrink:0"') ?>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:600;color:#166534;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= htmlspecialchars(basename($ec['file_surat_signed'])) ?>
        </div>
        <div style="font-size:10px;color:var(--text-muted)">Surat bertandatangan tersedia untuk diunduh pemohon</div>
      </div>
      <a href="<?= BASE_URL ?>/<?= htmlspecialchars($ec['file_surat_signed']) ?>" target="_blank"
         class="btn btn-sm" style="background:#dcfce7;color:#166534;border:none;font-size:11px;white-space:nowrap;flex-shrink:0">
        <?= ic('download','style="width:11px;height:11px"') ?> Lihat
      </a>
    </div>
    <?php endif; ?>

    <!-- Form upload -->
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateSignedUpload(this)">
      <input type="hidden" name="action" value="upload_surat_signed">
      <input type="hidden" name="ec_id" value="<?= $ec['id'] ?>">
      <input type="hidden" name="filter_back" value="<?= htmlspecialchars($filter_status) ?>">
      <div style="display:flex;gap:8px;align-items:flex-end">
        <div style="flex:1">
          <label style="display:flex;align-items:center;gap:6px;padding:9px 12px;border:1.5px dashed <?= !empty($ec['file_surat_signed']) ? '#86efac' : '#cbd5e1' ?>;border-radius:8px;cursor:pointer;background:#fff;transition:border-color .15s"
                 onmouseover="this.style.borderColor='#4a1d96'" onmouseout="this.style.borderColor='<?= !empty($ec['file_surat_signed']) ? '#86efac' : '#cbd5e1' ?>'">
            <?= ic('upload','style="width:13px;height:13px;color:var(--text-muted);flex-shrink:0"') ?>
            <span id="sf-lbl-<?= $ec['id'] ?>" style="font-size:12px;color:var(--text-muted)">
              <?= !empty($ec['file_surat_signed']) ? 'Ganti dengan file baru (PDF)…' : 'Pilih file surat bertandatangan (PDF)…' ?>
            </span>
            <input type="file" name="file_surat_signed" accept=".pdf"
                   style="display:none"
                   onchange="updateSignedLabel(this, '<?= $ec['id'] ?>')">
          </label>
        </div>
        <button type="submit" class="btn btn-sm" style="background:var(--primary);color:#fff;border:none;white-space:nowrap;height:38px">
          <?= ic('upload','style="width:12px;height:12px"') ?>
          <?= !empty($ec['file_surat_signed']) ? 'Ganti' : 'Unggah' ?>
        </button>
      </div>
      <div style="font-size:10px;color:var(--text-muted);margin-top:5px">Format: PDF · Maks. 20 MB · Surat ditandatangani dan distempel oleh Tim Etik</div>
    </form>

    <?php if (!empty($ec['file_surat_signed'])): ?>
    <form method="POST" style="margin-top:8px"
          onsubmit="return confirm('Hapus surat bertandatangan? Pemohon tidak akan bisa mengunduh versi bertandatangan.')">
      <input type="hidden" name="action" value="hapus_surat_signed">
      <input type="hidden" name="ec_id" value="<?= $ec['id'] ?>">
      <input type="hidden" name="filter_back" value="<?= htmlspecialchars($filter_status) ?>">
      <button type="submit" class="ec-btn ec-btn--danger-outline ec-btn--sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        Hapus Surat Bertandatangan
      </button>
    </form>
    <?php endif; ?>
    </div>
  </section>

  <!-- Section: Surat Digital (Auto-Generate) -->
  <?php if ($ec['status'] === 'disetujui' && $ec['nomor_surat']): ?>
  <section class="ec-section">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      </span>
      <span class="ec-section__title">Surat Digital (Auto-Generate)</span>
    </div>
    <div class="ec-section__body">
      <a href="<?= BASE_URL ?>/modules/ethical_clearance/surat.php?id=<?= $ec['id'] ?>" target="_blank"
         class="ec-btn ec-btn--outline" style="width:100%;justify-content:center">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Preview / Cetak Letter of Ethical Approval
      </a>
    </div>
  </section>
  <?php elseif ($ec['status'] === 'disetujui' && !$ec['nomor_surat']): ?>
  <section class="ec-section ec-section--warn">
    <div class="ec-section__head">
      <span class="ec-section__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </span>
      <span class="ec-section__title">Perhatian</span>
    </div>
    <div class="ec-section__body" style="font-size:12px">
      Isi <strong>Nomor Surat</strong> pada form <em>Update Status</em> di atas lalu simpan untuk mengaktifkan cetak surat.
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
function openDetail(id) {
  const src = document.getElementById('det-' + id);
  if (!src) return;
  const modal   = document.getElementById('ec-modal');
  const content = document.getElementById('detail-content');
  content.innerHTML = src.innerHTML;
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden','false');
  document.body.style.overflow = 'hidden';
  content.scrollTop = 0;
}
function closeDetail() {
  const modal = document.getElementById('ec-modal');
  if (!modal) return;
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden','true');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeDetail();
});
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}
function updateSignedLabel(input, ecId) {
  const lbl = document.getElementById('sf-lbl-' + ecId);
  if (!lbl) return;
  if (input.files && input.files[0]) {
    const f = input.files[0];
    const kb = (f.size / 1024).toFixed(0);
    const size = kb > 1024 ? (kb/1024).toFixed(1)+' MB' : kb+' KB';
    lbl.textContent = f.name + ' (' + size + ')';
    lbl.style.color = '#166534';
    lbl.style.fontWeight = '600';
  }
}
function validateSignedUpload(form) {
  const inp = form.querySelector('input[type="file"]');
  if (!inp || !inp.files || inp.files.length === 0) {
    alert('Pilih file PDF terlebih dahulu.');
    return false;
  }
  const f = inp.files[0];
  if (!f.name.toLowerCase().endsWith('.pdf')) {
    alert('File harus berformat PDF.');
    return false;
  }
  if (f.size > 20 * 1024 * 1024) {
    alert('Ukuran file maksimal 20 MB.');
    return false;
  }
  return true;
}
</script>
</body>
</html>
