<?php
require_once '../includes/config.php';
requireAdminOrDelegate('penunjukan_reviewer');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';
$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Tambah reviewer ke proposal
    if ($_POST['action'] === 'assign') {
        $pid         = (int)($_POST['proposal_id']  ?? 0);
        $reviewer_id = (int)($_POST['reviewer_id']  ?? 0);
        $deadline    = clean($_POST['deadline_review'] ?? '');
        $catatan_a   = clean($_POST['catatan_admin']   ?? '');

        if ($pid && $reviewer_id) {
            // Max 2 reviewer per proposal
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM reviewer_assignment_pengabdian WHERE usulan_id=? AND (deleted_at IS NULL OR deleted_at IS NULL)");
            $cnt->execute([$pid]);
            if ((int)$cnt->fetchColumn() >= 2) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Maksimal 2 reviewer per proposal.':'Maximum 2 reviewers per proposal.'];
            } else {
                try {
                    $pdo->prepare("
                        INSERT INTO reviewer_assignment_pengabdian
                            (usulan_id, reviewer_id, assigned_by, deadline_review, catatan_admin)
                        VALUES (?,?,?,?,?)
                    ")->execute([$pid, $reviewer_id, $_SESSION['user_id'], $deadline ?: null, $catatan_a ?: null]);

                    // Auto-advance status: lolos_admin → seleksi_substansi saat reviewer pertama ditugaskan
                    $prop = $pdo->prepare("SELECT judul, status FROM usulan_pengabdian WHERE id=?");
                    $prop->execute([$pid]);
                    $pdata = $prop->fetch();
                    if ($pdata && $pdata['status'] === 'lolos_admin') {
                        $pdo->prepare("UPDATE usulan_pengabdian SET status='seleksi_substansi', updated_at=NOW() WHERE id=?")
                            ->execute([$pid]);
                    }

                    // Notifikasi ke reviewer (termasuk deadline jika ada)
                    $dl_txt = $deadline
                        ? ($id?' Batas waktu: ':' Deadline: ') . date('d M Y H:i', strtotime($deadline)) . ' WITA.'
                        : '';
                    $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                        ->execute([$reviewer_id,
                            $id?'Penugasan Review Proposal':'Research Proposal Assignment',
                            ($id?'Anda ditugaskan sebagai reviewer untuk proposal: ':'You are assigned as reviewer for proposal: ')
                            . mb_strimwidth($pdata['judul']??'',0,80,'…') . $dl_txt,
                            'info']);
                    $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Reviewer berhasil ditugaskan.':'Reviewer assigned successfully.'];
                } catch (\Exception $e) {
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Reviewer sudah ditugaskan untuk proposal ini.':'Reviewer already assigned to this proposal.'];
                }
            }
        }
        redirect('/admin/pengabdian_reviewer.php?pid=' . $pid);
    }

    // Hapus assignment reviewer
    if ($_POST['action'] === 'unassign') {
        $assign_id = (int)($_POST['assign_id'] ?? 0);
        $pid       = (int)($_POST['proposal_id'] ?? 0);
        if ($assign_id) {
            // Jangan hapus jika sudah submit penilaian
            $has_sub = $pdo->prepare("SELECT COUNT(*) FROM reviewer_penilaian_pengabdian WHERE assignment_id=? AND submitted_at IS NOT NULL");
            $has_sub->execute([$assign_id]);
            if ((int)$has_sub->fetchColumn() > 0) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Tidak dapat menghapus reviewer yang sudah mengirim penilaian.':'Cannot remove reviewer who has submitted assessment.'];
            } else {
                $pdo->prepare("DELETE FROM reviewer_assignment_pengabdian WHERE id=?")->execute([$assign_id]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Reviewer dihapus dari proposal.':'Reviewer removed from proposal.'];
            }
        }
        redirect('/admin/pengabdian_reviewer.php?pid=' . $pid);
    }

    // Finalisasi hasil seleksi substantif (setelah kedua reviewer submit)
    if ($_POST['action'] === 'finalize') {
        $pid       = (int)($_POST['proposal_id'] ?? 0);
        $keputusan = in_array($_POST['keputusan'], ['disetujui','revisi_minor','revisi_mayor','ditolak'])
                     ? $_POST['keputusan'] : '';
        $catatan   = clean($_POST['catatan'] ?? '');

        if ($pid && $keputusan) {
            // Cek apakah skema PkM mewajibkan kontrak
            $kontrak_wajib = 0;
            if ($keputusan === 'disetujui') {
                $sk_chk = $pdo->prepare("
                    SELECT COALESCE(sk.kontrak_wajib, 0) AS kontrak_wajib
                    FROM usulan_pengabdian up
                    LEFT JOIN skema_pengabdian sk
                           ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci
                          AND sk.tahun = up.tahun_anggaran
                    WHERE up.id = ?
                ");
                $sk_chk->execute([$pid]);
                $kontrak_wajib = (int)($sk_chk->fetchColumn() ?: 0);
            }

            // Jika disetujui & kontrak opsional → langsung kontrak_aktif (skip step kontrak)
            $final_status = ($keputusan === 'disetujui' && !$kontrak_wajib) ? 'kontrak_aktif' : $keputusan;

            $pdo->prepare("
                UPDATE usulan_pengabdian SET status=?, catatan_reviewer=?, reviewed_by=?, reviewed_at=NOW()
                WHERE id=?
            ")->execute([$final_status, $catatan, $_SESSION['user_id'], $pid]);

            $prop = $pdo->prepare("SELECT user_id, judul FROM usulan_pengabdian WHERE id=?");
            $prop->execute([$pid]);
            $pdata = $prop->fetch();
            if ($pdata) {
                $jdl_map = ['disetujui'=>'Proposal Disetujui','revisi_minor'=>'Proposal Revisi Minor',
                            'revisi_mayor'=>'Proposal Revisi Mayor','ditolak'=>'Proposal Ditolak'];
                $tipe_map = ['disetujui'=>'sukses','revisi_minor'=>'peringatan','revisi_mayor'=>'peringatan','ditolak'=>'error'];
                $extra = ($keputusan==='disetujui' && !$kontrak_wajib)
                       ? ($id?' Kontrak opsional — Anda dapat langsung melaksanakan PkM.':' Contract optional — you may proceed.') : '';
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$pdata['user_id'], $jdl_map[$keputusan],
                        ($id?'Hasil seleksi substantif proposal "':'Substantive review result for proposal "')
                        .mb_strimwidth($pdata['judul'],0,70,'…').'"'
                        .($catatan?' — '.$catatan:'') . $extra, $tipe_map[$keputusan]]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Hasil seleksi substantif berhasil disimpan.':'Substantive review result saved.'];
        }
        redirect('/admin/pengabdian_reviewer.php');
    }

    // Review perbaikan substantif yang dikirim pengusul
    if ($_POST['action'] === 'review_revisi') {
        $pid       = (int)($_POST['proposal_id'] ?? 0);
        $revisi_id = (int)($_POST['revisi_id']   ?? 0);
        $keputusan = in_array($_POST['keputusan'] ?? '', ['diterima','dikembalikan'])
                     ? $_POST['keputusan'] : '';
        $catatan   = clean($_POST['catatan'] ?? '');

        if ($pid && $revisi_id && $keputusan) {
            $prop = $pdo->prepare("SELECT user_id, judul, status FROM usulan_pengabdian WHERE id=?");
            $prop->execute([$pid]);
            $pdata = $prop->fetch();

            if ($pdata) {
                if ($keputusan === 'diterima') {
                    $new_status  = 'seleksi_substansi';
                    $notif_judul = $id ? 'Perbaikan Substantif Diterima' : 'Substantive Revision Accepted';
                    $notif_pesan = ($id
                        ? 'Perbaikan substantif Anda diterima. Proposal dikirim kembali ke reviewer: '
                        : 'Your substantive revision was accepted. Proposal sent back for re-review: ')
                        . mb_strimwidth($pdata['judul'], 0, 70, '…');
                    $notif_tipe  = 'sukses';
                } else {
                    // Kembalikan ke status sebelumnya (revisi_minor / revisi_mayor)
                    $prev_q = $pdo->prepare("SELECT status_sebelum FROM revisi_proposal_pengabdian WHERE id=?");
                    $prev_q->execute([$revisi_id]);
                    $prev_row   = $prev_q->fetch();
                    $new_status = in_array($prev_row['status_sebelum'] ?? '', ['revisi_minor','revisi_mayor'])
                                  ? $prev_row['status_sebelum'] : 'revisi_mayor';
                    $notif_judul = $id ? 'Perbaikan Dikembalikan' : 'Revision Returned';
                    $notif_pesan = ($id
                        ? 'Perbaikan substantif Anda dikembalikan untuk diperbaiki kembali.'
                        : 'Your substantive revision was returned for further improvement.')
                        . ($catatan ? " — $catatan" : '');
                    $notif_tipe  = 'peringatan';
                }

                $pdo->prepare("UPDATE revisi_proposal_pengabdian SET keputusan=?, admin_catatan=?, keputusan_at=NOW() WHERE id=?")
                    ->execute([$keputusan, $catatan, $revisi_id]);

                $pdo->prepare("UPDATE usulan_pengabdian SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$new_status, $_SESSION['user_id'], $pid]);

                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$pdata['user_id'], $notif_judul, $notif_pesan, $notif_tipe]);

                $_SESSION['flash'] = ['type'=>'success','msg'=>$id
                    ? 'Keputusan perbaikan substantif berhasil disimpan.'
                    : 'Substantive revision decision saved.'];
            }
        }
        redirect('/admin/pengabdian_reviewer.php?status=perbaikan_substantif');
    }
}

// ── Single proposal view (pid) ────────────────────────────────
$view_pid = (int)($_GET['pid'] ?? 0);
$view_proposal = null;
$view_assignments = [];
if ($view_pid) {
    $vp = $pdo->prepare("
        SELECT up.*, u.nama_lengkap, u.program_studi, u.jabatan_fungsional
        FROM usulan_pengabdian up JOIN users u ON up.user_id=u.id
        WHERE up.id=? AND up.tahun_anggaran=?
    ");
    $vp->execute([$view_pid, $tahun]);
    $view_proposal = $vp->fetch();

    if ($view_proposal) {
        $va = $pdo->prepare("
            SELECT ra.id, ra.usulan_id, ra.reviewer_id, ra.assigned_by, ra.assigned_at,
                   ra.deadline_review, ra.catatan_admin,
                   u.nama_lengkap as reviewer_nama, u.email as reviewer_email,
                   rp.id as penilaian_id, rp.skor_1, rp.skor_2, rp.skor_3,
                   rp.skor_4, rp.skor_5, rp.skor_6, rp.nilai_total,
                   rp.keputusan as keputusan_reviewer, rp.saran, rp.submitted_at,
                   rp.file_review, rp.file_review_name
            FROM reviewer_assignment_pengabdian ra
            JOIN users u ON ra.reviewer_id = u.id
            LEFT JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id = ra.id
            WHERE ra.usulan_id = ? AND ra.deleted_at IS NULL
        ");
        $va->execute([$view_pid]);
        $view_assignments = $va->fetchAll();
    }
}

// ── List proposals ─────────────────────────────────────────────
$filter_status = clean($_GET['status'] ?? 'seleksi_substansi');
$q = clean($_GET['q'] ?? '');

$where = ["up.deleted_at IS NULL", "up.tahun_anggaran=$tahun"];
$params = [];
if ($filter_status) { $where[] = "up.status=?"; $params[] = $filter_status; }
if ($q) {
    $where[] = "(u.nama_lengkap LIKE ? OR up.judul LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}

$proposals = $pdo->prepare("
    SELECT up.*, u.nama_lengkap,
           (SELECT COUNT(*) FROM reviewer_assignment_pengabdian WHERE usulan_id=up.id) as n_reviewer,
           (SELECT COUNT(*) FROM reviewer_assignment_pengabdian ra
            JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id=ra.id
            WHERE ra.usulan_id=up.id AND rp.submitted_at IS NOT NULL) as n_done
    FROM usulan_pengabdian up
    JOIN users u ON up.user_id = u.id
    WHERE ".implode(' AND ',$where)."
    ORDER BY up.reviewed_at DESC
");
$proposals->execute($params);
$proposals = $proposals->fetchAll();

// Load revisi data untuk perbaikan_substantif
$revisi_data = [];
if ($filter_status === 'perbaikan_substantif' && !empty($proposals)) {
    $pids = array_column($proposals, 'id');
    $in   = implode(',', array_fill(0, count($pids), '?'));
    $rvq  = $pdo->prepare("
        SELECT rp.*
        FROM revisi_proposal_pengabdian rp
        WHERE rp.usulan_id IN ($in) AND rp.tipe='substantif'
        ORDER BY rp.created_at DESC
    ");
    $rvq->execute($pids);
    foreach ($rvq->fetchAll() as $rv) {
        if (!isset($revisi_data[$rv['usulan_id']])) {
            $revisi_data[$rv['usulan_id']] = $rv;
        }
    }
}

// Load daftar reviewer
$reviewers = $pdo->query("SELECT id, nama_lengkap, email FROM users WHERE role='reviewer' AND is_active=1 ORDER BY nama_lengkap")->fetchAll();

// Rubrik penilaian — load dari DB, fallback ke default
$rubrik_raw = getSetting($pdo, 'reviewer_rubrik');
$RUBRIK = [];
if ($rubrik_raw) {
    $dec = json_decode($rubrik_raw, true);
    if (is_array($dec) && count($dec) >= 1 && count($dec) <= 6) $RUBRIK = $dec;
}
if (empty($RUBRIK)) {
    $RUBRIK = [
        ['kriteria'=>'Perumusan Masalah',             'bobot'=>20, 'skor_max'=>5],
        ['kriteria'=>'Manfaat Hasil Pengabdian',       'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Tinjauan Pustaka',               'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Landasan Teori',                 'bobot'=>20, 'skor_max'=>5],
        ['kriteria'=>'Metode Pengabdian',              'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Output dan Outcome Pengabdian',  'bobot'=>15, 'skor_max'=>5],
    ];
}
$BOBOT      = array_column($RUBRIK, 'bobot');
$KRITERIA   = array_column($RUBRIK, 'kriteria');
$SKOR_MAX   = array_column($RUBRIK, 'skor_max');
$N_KRITERIA = count($RUBRIK);

$keputusanLabel = fn($k) => match($k) {
    'disetujui'   => ['label'=>'Diterima',     'color'=>'#16a34a','bg'=>'#f0fdf4'],
    'revisi_minor'=> ['label'=>'Revisi Minor',  'color'=>'#ca8a04','bg'=>'#fef9c3'],
    'revisi_mayor'=> ['label'=>'Revisi Mayor',  'color'=>'#ea580c','bg'=>'#fff7ed'],
    'ditolak'     => ['label'=>'Ditolak',       'color'=>'#dc2626','bg'=>'#fef2f2'],
    default       => ['label'=>'-',             'color'=>'#64748b','bg'=>'#f1f5f9'],
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seleksi Substantif & Reviewer — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.rpa-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.rpa-table th { background:var(--bg-field); color:var(--text-muted); font-weight:600;
  font-size:11px; text-transform:uppercase; letter-spacing:.4px;
  padding:9px 12px; text-align:left; border-bottom:1.5px solid var(--border); white-space:nowrap; }
.rpa-table td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:top; }
.rpa-table tr:hover td { background:var(--bg-hover); }
.rpa-judul { font-weight:600; color:var(--text-primary); max-width:240px; line-height:1.4; }
.rpa-meta  { font-size:11px; color:var(--text-muted); margin-top:2px; }
.rev-chip { display:inline-flex;align-items:center;gap:5px;padding:3px 9px;
  background:var(--bg-field);border:1px solid var(--border);border-radius:6px;font-size:11.5px;margin:2px; }
.rubrik-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.rubrik-table th,
.rubrik-table td { padding:8px 10px; border:1px solid var(--border); }
.rubrik-table th { background:var(--bg-field); font-weight:700; font-size:11px; }
.rev-card { background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px; }
.rev-card-head { display:flex;align-items:center;gap:10px;margin-bottom:14px; }
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
          <?php if ($view_proposal): ?>
          Seleksi Substantif
          <span class="breadcrumb"><?= htmlspecialchars(mb_strimwidth($view_proposal['judul'],0,50,'…')) ?></span>
          <?php else: ?>
          <?= $id?'Reviewer & Seleksi Substantif':'Reviewer & Substantive Review' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-right">
        <?php if ($view_proposal): ?>
        <a href="<?= BASE_URL ?>/admin/pengabdian_reviewer.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
        </a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/admin/pengabdian.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Semua Proposal':'All Proposals' ?>
        </a>
        <a href="<?= BASE_URL ?>/admin/pengabdian_reviewer_laporan.php" target="_blank"
           class="btn btn-primary" style="font-size:12px;gap:6px;background:linear-gradient(135deg,#064e3b,#059669);border:none;display:inline-flex;align-items:center">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          <?= $id?'Cetak Laporan':'Print Report' ?>
        </a>
        <?php endif; ?>
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

      <?php if ($view_proposal): /* ── Detail proposal & reviewer assignment ── */ ?>

      <!-- Proposal info -->
      <div class="card" style="margin-bottom:18px">
        <div style="font-size:15px;font-weight:700;margin-bottom:8px"><?= htmlspecialchars($view_proposal['judul']) ?></div>
        <div style="font-size:12.5px;color:var(--text-muted)">
          <?= htmlspecialchars($view_proposal['nama_lengkap']) ?> ·
          <?= htmlspecialchars($view_proposal['program_studi']??'-') ?> ·
          <?= strtoupper($view_proposal['skema']) ?>
        </div>
        <?php if ($view_proposal['file_proposal']): ?>
        <div style="margin-top:10px">
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($view_proposal['file_proposal']) ?>" target="_blank"
             class="btn btn-outline" style="font-size:12px">
            <?= ic('download') ?> <?= $id?'Unduh Proposal':'Download Proposal' ?>
          </a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Assign reviewer -->
      <?php $assigned_ids = array_column($view_assignments, 'reviewer_id'); ?>
      <div class="card" style="margin-bottom:18px">
        <div style="font-size:13px;font-weight:700;margin-bottom:12px">
          <?= ic('users') ?> <?= $id?'Penugasan Reviewer':'Reviewer Assignment' ?>
          <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:4px">(<?= $id?'Maks. 2 reviewer':'Max 2 reviewers' ?>)</span>
        </div>

        <?php if (empty($view_assignments)): ?>
        <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:12px">
          <?= $id?'Belum ada reviewer yang ditugaskan.':'No reviewers assigned yet.' ?>
        </div>
        <?php else: foreach ($view_assignments as $a): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
          <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#4a1d96,#7c3aed);
                      display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;flex-shrink:0">
            <?= strtoupper(mb_substr($a['reviewer_nama'],0,1)) ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($a['reviewer_nama']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($a['reviewer_email']) ?></div>
          </div>
          <div>
            <?php if ($a['submitted_at']): ?>
            <?php $kl = $keputusanLabel($a['keputusan_reviewer']); ?>
            <span style="background:<?= $kl['bg'] ?>;color:<?= $kl['color'] ?>;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700">
              ✓ <?= $kl['label'] ?>
            </span>
            <?php else: ?>
            <span style="background:#fef9c3;color:#92400e;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600">
              <?= $id?'Belum dinilai':'Pending' ?>
            </span>
            <?php endif; ?>
          </div>
          <?php if (!$a['submitted_at']): ?>
          <form method="POST" onsubmit="return confirm('<?= $id?'Hapus reviewer ini?':'Remove this reviewer?' ?>')">
            <input type="hidden" name="action" value="unassign">
            <input type="hidden" name="assign_id" value="<?= $a['id'] ?>">
            <input type="hidden" name="proposal_id" value="<?= $view_pid ?>">
            <button type="submit" class="btn btn-outline" style="font-size:11.5px;padding:4px 9px;color:#dc2626;border-color:#fecaca">
              <?= ic('trash') ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>

        <?php
        // Tampilkan info deadline setiap assignment (jika ada)
        foreach ($view_assignments as $a) {
            if (!empty($a['deadline_review']) || !empty($a['catatan_admin'])) {
                $dl = $a['deadline_review'] ?? null;
                $dl_ts = $dl ? strtotime($dl) : 0;
                $now_ts = time();
                $overdue = $dl_ts && $dl_ts < $now_ts && !$a['submitted_at'];
                $remain  = $dl_ts ? ($dl_ts - $now_ts) : 0;
                echo '<div style="font-size:11.5px;color:'. ($overdue?'#dc2626':'#475569') .';margin-top:4px;padding:6px 10px;background:'. ($overdue?'#fef2f2':'#f8fafc') .';border-radius:7px">';
                echo '<b>' . htmlspecialchars($a['reviewer_nama']) . ':</b> ';
                if ($dl) {
                    echo ($id?'Deadline ':'Deadline ') . date('d M Y H:i', $dl_ts) . ' WITA';
                    if (!$a['submitted_at']) {
                        if ($overdue) echo ' · <b>'.($id?'TERLEWAT':'OVERDUE').'</b>';
                        elseif ($remain < 86400*2) echo ' · <b>'.($id?'kurang dari 2 hari':'less than 2 days').'</b>';
                    }
                }
                if (!empty($a['catatan_admin'])) {
                    if ($dl) echo ' · ';
                    echo htmlspecialchars(mb_strimwidth($a['catatan_admin'], 0, 100, '…'));
                }
                echo '</div>';
            }
        }
        ?>

        <?php if (count($view_assignments) < 2): ?>
        <form method="POST" style="display:grid;gap:8px;margin-top:14px;grid-template-columns:1fr 1fr;">
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="proposal_id" value="<?= $view_pid ?>">
          <div style="grid-column:1/-1">
            <label class="form-label" style="font-size:11.5px"><?= $id?'Reviewer':'Reviewer' ?></label>
            <select name="reviewer_id" class="form-control" style="font-size:12.5px" required>
              <option value=""><?= $id?'-- Pilih Reviewer --':'-- Select Reviewer --' ?></option>
              <?php foreach ($reviewers as $rv):
                if (in_array($rv['id'], $assigned_ids)) continue; ?>
              <option value="<?= $rv['id'] ?>"><?= htmlspecialchars($rv['nama_lengkap']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Deadline Penilaian':'Review Deadline' ?></label>
            <input type="datetime-local" name="deadline_review" class="form-control" style="font-size:12.5px"
                   value="<?= date('Y-m-d\TH:i', strtotime('+14 days')) ?>">
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Catatan untuk Reviewer':'Note for Reviewer' ?> <span style="color:var(--text-muted)">(<?= $id?'opsional':'optional' ?>)</span></label>
            <input type="text" name="catatan_admin" class="form-control" style="font-size:12.5px"
                   placeholder="<?= $id?'Fokus review...':'Review focus...' ?>" maxlength="500">
          </div>
          <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:6px;margin-top:4px">
            <button type="submit" class="btn btn-primary" style="font-size:12.5px">
              <?= ic('plus') ?> <?= $id?'Tugaskan Reviewer':'Assign Reviewer' ?>
            </button>
          </div>
        </form>
        <?php if (empty($reviewers)): ?>
        <div style="font-size:12px;color:#dc2626;margin-top:8px">
          <?= $id?'Belum ada akun reviewer. Buat akun dengan role "Reviewer" di menu Pengguna.':'No reviewer accounts yet. Create user with role "Reviewer" in Users menu.' ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Hasil penilaian -->
      <?php
      $all_submitted = count($view_assignments) >= 1
        && count(array_filter($view_assignments, fn($a) => $a['submitted_at'])) === count($view_assignments)
        && count($view_assignments) > 0;
      ?>
      <?php if (!empty($view_assignments)): ?>
      <div class="card" style="margin-bottom:18px">
        <div style="font-size:13px;font-weight:700;margin-bottom:14px">
          <?= ic('chart') ?> <?= $id?'Hasil Penilaian Reviewer':'Reviewer Assessment Results' ?>
        </div>

        <?php foreach ($view_assignments as $a): ?>
        <?php if (!$a['submitted_at']) continue; ?>
        <div class="rev-card">
          <div class="rev-card-head">
            <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#4a1d96,#7c3aed);
                        display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;flex-shrink:0">
              <?= strtoupper(mb_substr($a['reviewer_nama'],0,1)) ?>
            </div>
            <div>
              <div style="font-size:13px;font-weight:700;color:var(--primary)">
                Reviewer <?= $id?'(identitas tersembunyi dari pengusul)':'(identity hidden from proposer)' ?>
              </div>
              <div style="font-size:11px;color:var(--text-muted)"><?= date('d M Y', strtotime($a['submitted_at'])) ?></div>
            </div>
            <?php $kl = $keputusanLabel($a['keputusan_reviewer']); ?>
            <span style="margin-left:auto;background:<?= $kl['bg'] ?>;color:<?= $kl['color'] ?>;
                         padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700">
              <?= $kl['label'] ?>
            </span>
          </div>
          <table class="rubrik-table">
            <thead>
              <tr>
                <th style="width:50%"><?= $id?'Kriteria':'Criterion' ?></th>
                <th style="text-align:center">Bobot</th>
                <th style="text-align:center">Skor (1–5)</th>
                <th style="text-align:center">Nilai</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $skors = [$a['skor_1'],$a['skor_2'],$a['skor_3'],$a['skor_4'],$a['skor_5'],$a['skor_6']];
            $total_nilai = 0;
            for ($ki = 0; $ki < $N_KRITERIA; $ki++):
              $skor  = (int)($skors[$ki] ?? 0);
              $nilai = ($BOBOT[$ki] / $SKOR_MAX[$ki]) * $skor;
              $total_nilai += $nilai;
            ?>
            <tr>
              <td><?= htmlspecialchars($KRITERIA[$ki]) ?></td>
              <td style="text-align:center"><?= $BOBOT[$ki] ?>%</td>
              <td style="text-align:center;font-weight:700"><?= $skor ?: '-' ?></td>
              <td style="text-align:center;font-weight:700"><?= $skor ? number_format($nilai, 1) : '-' ?></td>
            </tr>
            <?php endfor; ?>
            </tbody>
            <tfoot>
              <tr style="background:var(--bg-field)">
                <td colspan="3" style="font-weight:700;text-align:right"><?= $id?'Total Nilai':'Total Score' ?></td>
                <td style="text-align:center;font-weight:800;font-size:14px;color:var(--primary)">
                  <?= number_format((float)($a['nilai_total'] ?? $total_nilai), 1) ?>
                </td>
              </tr>
            </tfoot>
          </table>
          <?php if ($a['saran']): ?>
          <div style="margin-top:10px;padding:10px 13px;background:var(--bg-field);border-radius:8px;font-size:12.5px">
            <strong><?= $id?'Saran/Catatan: ':'Notes/Suggestions: ' ?></strong><?= nl2br(htmlspecialchars($a['saran'])) ?>
          </div>
          <?php endif; ?>
          <?php if ($a['file_review']): ?>
          <div style="margin-top:8px">
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($a['file_review']) ?>" target="_blank"
               class="btn btn-outline" style="font-size:12px">
              <?= ic('download') ?> <?= htmlspecialchars($a['file_review_name']??'File Review') ?>
            </a>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Finalize -->
        <?php if ($all_submitted && !in_array($view_proposal['status'], ['disetujui','revisi_minor','revisi_mayor','ditolak'])): ?>
        <div style="border-top:1.5px solid var(--border);padding-top:16px;margin-top:4px">
          <div style="font-size:13px;font-weight:700;margin-bottom:10px">
            <?= $id?'Keputusan Final Admin':'Final Admin Decision' ?>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="finalize">
            <input type="hidden" name="proposal_id" value="<?= $view_pid ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
              <?php
              $decisions = ['disetujui'=>'Diterima','revisi_minor'=>'Revisi Minor','revisi_mayor'=>'Revisi Mayor','ditolak'=>'Ditolak'];
              $dec_colors = ['disetujui'=>['#f0fdf4','#16a34a'],'revisi_minor'=>['#fef9c3','#ca8a04'],
                             'revisi_mayor'=>['#fff7ed','#ea580c'],'ditolak'=>['#fef2f2','#dc2626']];
              foreach ($decisions as $dv => $dl):
                [$dbg,$dcl] = $dec_colors[$dv];
              ?>
              <label style="display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:9px;
                            border:1.5px solid var(--border);cursor:pointer;background:var(--bg-field)">
                <input type="radio" name="keputusan" value="<?= $dv ?>" style="accent-color:<?= $dcl ?>" required>
                <span style="font-size:13px;font-weight:700;color:<?= $dcl ?>"><?= $dl ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <div class="form-group" style="margin-bottom:12px">
              <label class="form-label"><?= $id?'Catatan Keputusan':'Decision Notes' ?></label>
              <textarea name="catatan" class="form-control" rows="2" style="font-size:12.5px"
                placeholder="<?= $id?'Catatan tambahan untuk pengusul...':'Additional notes for proposer...' ?>"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
              <?= ic('check') ?> <?= $id?'Simpan Keputusan Final':'Save Final Decision' ?>
            </button>
          </form>
        </div>
        <?php elseif (in_array($view_proposal['status'], ['disetujui','revisi_minor','revisi_mayor','ditolak'])): ?>
        <?php $kl = $keputusanLabel($view_proposal['status']); ?>
        <div style="padding:12px 14px;border-radius:10px;background:<?= $kl['bg'] ?>;
                    border:1.5px solid <?= $kl['color'] ?>30;margin-top:8px">
          <div style="font-size:13px;font-weight:700;color:<?= $kl['color'] ?>">
            <?= ic('check-circle','style="width:16px;height:16px"') ?> <?= $id?'Keputusan Final: ':'Final Decision: ' ?><?= $kl['label'] ?>
          </div>
          <?php if ($view_proposal['catatan_reviewer']): ?>
          <div style="font-size:12px;color:var(--text-secondary);margin-top:4px">
            <?= htmlspecialchars($view_proposal['catatan_reviewer']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php else: /* ── List all proposals ── */ ?>

      <!-- Filter -->
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
        <select name="status" class="form-control" style="width:200px;font-size:12.5px" onchange="this.form.submit()">
          <option value="seleksi_substansi" <?= $filter_status==='seleksi_substansi'?'selected':'' ?>><?= $id?'Seleksi Substantif (aktif)':'Substantive Review (active)' ?></option>
          <option value="disetujui"         <?= $filter_status==='disetujui'?'selected':'' ?>><?= $id?'Diterima':'Approved' ?></option>
          <option value="revisi_minor"      <?= $filter_status==='revisi_minor'?'selected':'' ?>>Revisi Minor</option>
          <option value="revisi_mayor"      <?= $filter_status==='revisi_mayor'?'selected':'' ?>>Revisi Mayor</option>
          <option value="ditolak"           <?= $filter_status==='ditolak'?'selected':'' ?>><?= $id?'Ditolak':'Rejected' ?></option>
          <option value="perbaikan_substantif" <?= $filter_status==='perbaikan_substantif'?'selected':'' ?>><?= $id?'Perbaikan Substantif':'Substantive Revision' ?></option>
        </select>
        <input type="text" name="q" class="form-control" style="width:200px;font-size:12.5px"
               value="<?= htmlspecialchars($q) ?>" placeholder="<?= $id?'Cari...':'Search...' ?>">
        <button type="submit" class="btn btn-outline" style="font-size:12px"><?= ic('search') ?></button>
      </form>

      <div class="card" style="padding:0;overflow:hidden">
        <div style="overflow-x:auto">
          <table class="rpa-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Proposal</th>
                <th><?= $id?'Pengusul':'Proposer' ?></th>
                <th>Reviewer</th>
                <th>Progress</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($proposals)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">
              <?= ic('inbox') ?><div style="margin-top:8px"><?= $id?'Tidak ada proposal.':'No proposals.' ?></div>
            </td></tr>
            <?php else: foreach ($proposals as $i => $p): ?>
            <tr>
              <td style="font-size:11px;color:var(--text-muted)"><?= $i+1 ?></td>
              <td>
                <div class="rpa-judul"><?= htmlspecialchars(mb_strimwidth($p['judul'],0,60,'…')) ?></div>
                <div class="rpa-meta"><?= strtoupper($p['skema']) ?> · <?= $tahun ?></div>
              </td>
              <td>
                <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($p['nama_lengkap']) ?></div>
              </td>
              <td>
                <?php if ($p['n_reviewer']): ?>
                <span style="background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:8px;font-size:11.5px;font-weight:600">
                  <?= $p['n_reviewer'] ?> <?= $id?'reviewer':'reviewer' ?>
                </span>
                <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px"><?= $id?'Belum ditugaskan':'Not assigned' ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php $all_done = $p['n_reviewer'] > 0 && $p['n_done'] == $p['n_reviewer']; ?>
                <div style="font-size:11.5px">
                  <?= $p['n_done'] ?>/<?= $p['n_reviewer'] ?> <?= $id?'selesai':'done' ?>
                </div>
                <?php if ($all_done): ?>
                <div style="font-size:10.5px;color:#16a34a;font-weight:600;margin-top:2px">✓ <?= $id?'Siap finalisasi':'Ready to finalize' ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($p['status'] === 'perbaikan_substantif' && isset($revisi_data[$p['id']])): ?>
                <?php $rv = $revisi_data[$p['id']]; ?>
                <button onclick="openRevisiDrawer(
                    <?= $p['id'] ?>,
                    <?= htmlspecialchars(json_encode($rv, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode(['judul'=>$p['judul'],'nama'=>$p['nama_lengkap'],'skema'=>$p['skema']], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>
                  )"
                  class="btn" style="font-size:11.5px;padding:5px 11px;background:linear-gradient(135deg,#4a1d96,#7c3aed);color:#fff;border:none;cursor:pointer">
                  <?= ic('check') ?> <?= $id?'Review Perbaikan':'Review Revision' ?>
                </button>
                <?php else: ?>
                <a href="?pid=<?= $p['id'] ?>" class="btn btn-outline" style="font-size:11.5px;padding:5px 11px">
                  <?= ic('users') ?> <?= $id?'Kelola':'Manage' ?>
                </a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php endif; ?>

    </div>
  </div>
</div>

<!-- ── Revision Review Overlay ── -->
<div id="revisiOverlay" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.55);
     backdrop-filter:blur(4px);overflow-y:auto;padding:24px 16px" onclick="if(event.target===this)closeRevisiOverlay()">
  <div style="max-width:740px;margin:0 auto;background:var(--bg-card);border-radius:18px;
              overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.35)">

    <!-- Header -->
    <div id="rovHeader" style="padding:24px 28px;background:linear-gradient(135deg,#1e1b4b 0%,#4a1d96 60%,#7c3aed 100%);color:#fff">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
        <div style="flex:1">
          <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.7;margin-bottom:6px">
            Perbaikan Substantif
          </div>
          <div id="rovJudul" style="font-size:16px;font-weight:700;line-height:1.4;margin-bottom:6px"></div>
          <div id="rovMeta" style="font-size:12px;opacity:.8"></div>
        </div>
        <button onclick="closeRevisiOverlay()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                width:34px;height:34px;border-radius:50%;font-size:18px;cursor:pointer;flex-shrink:0">×</button>
      </div>
      <div id="rovRound" style="margin-top:10px;display:inline-block;padding:3px 10px;border-radius:8px;
           background:rgba(255,255,255,.15);font-size:11.5px;font-weight:600"></div>
    </div>

    <div style="padding:24px 28px">

      <!-- File download -->
      <div style="margin-bottom:18px">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
                    letter-spacing:.5px;margin-bottom:8px">File Proposal Perbaikan</div>
        <div id="rovFileLink"></div>
      </div>

      <!-- Ringkasan -->
      <div style="margin-bottom:18px">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
                    letter-spacing:.5px;margin-bottom:8px">Ringkasan Perubahan</div>
        <div id="rovRingkasan" style="padding:12px 14px;background:var(--bg-field);border-radius:9px;
             font-size:13px;line-height:1.6;border:1px solid var(--border)"></div>
      </div>

      <!-- Respon isu -->
      <div style="margin-bottom:20px">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
                    letter-spacing:.5px;margin-bottom:10px">Respon Pengusul terhadap Catatan Reviewer</div>
        <div id="rovIsuList"></div>
      </div>

      <!-- Decision form -->
      <form id="rovForm" method="POST">
        <input type="hidden" name="action" value="review_revisi">
        <input type="hidden" id="rovPid" name="proposal_id" value="">
        <input type="hidden" id="rovRevisiId" name="revisi_id" value="">

        <div style="border-top:1.5px solid var(--border);padding-top:18px;margin-top:4px">
          <div style="font-size:13px;font-weight:700;margin-bottom:12px">Keputusan</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <label id="rov-lbl-diterima" style="display:flex;align-items:center;gap:9px;padding:12px 14px;
                   border-radius:10px;border:2px solid var(--border);cursor:pointer;transition:.2s"
                   onclick="rovHighlight('diterima')">
              <input type="radio" name="keputusan" value="diterima" style="accent-color:#16a34a" required>
              <div>
                <div style="font-size:13px;font-weight:700;color:#16a34a">✓ Terima & Kirim Ulang ke Reviewer</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Status → Seleksi Substantif</div>
              </div>
            </label>
            <label id="rov-lbl-dikembalikan" style="display:flex;align-items:center;gap:9px;padding:12px 14px;
                   border-radius:10px;border:2px solid var(--border);cursor:pointer;transition:.2s"
                   onclick="rovHighlight('dikembalikan')">
              <input type="radio" name="keputusan" value="dikembalikan" style="accent-color:#ea580c" required>
              <div>
                <div style="font-size:13px;font-weight:700;color:#ea580c">↩ Kembalikan untuk Diperbaiki</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Status → Revisi Minor/Mayor semula</div>
              </div>
            </label>
          </div>

          <div class="form-group" style="margin-bottom:16px">
            <label class="form-label" style="font-size:12.5px">
              Catatan Admin
              <span id="rov-catatan-hint" style="color:#dc2626;font-size:11px;display:none"> (wajib jika dikembalikan)</span>
            </label>
            <textarea name="catatan" id="rovCatatan" class="form-control" rows="3" style="font-size:12.5px"
              placeholder="Tuliskan catatan atau alasan keputusan untuk pengusul..."></textarea>
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" onclick="closeRevisiOverlay()" class="btn btn-outline" style="font-size:12.5px">
              Batal
            </button>
            <button type="button" onclick="validateRevisiDecision()" class="btn btn-primary"
                    style="font-size:12.5px;background:linear-gradient(135deg,#4a1d96,#7c3aed);border:none">
              <?= ic('check') ?> Simpan Keputusan
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleLang() {
    const c = document.cookie.match(/lang=(\w+)/);
    const next = (c && c[1] === 'en') ? 'id' : 'en';
    document.cookie = 'lang=' + next + ';path=/;max-age=31536000';
    location.reload();
}

function openRevisiDrawer(pid, rev, prop) {
    document.getElementById('rovPid').value    = pid;
    document.getElementById('rovRevisiId').value = rev.id;
    document.getElementById('rovJudul').textContent = prop.judul;
    document.getElementById('rovMeta').textContent  = prop.nama + ' · ' + prop.skema.toUpperCase();
    document.getElementById('rovRound').textContent = 'Perbaikan Putaran ke-' + rev.round_ke;

    // File link
    const fileEl = document.getElementById('rovFileLink');
    if (rev.file_proposal) {
        fileEl.innerHTML = '<a href="<?= BASE_URL ?>/' + rev.file_proposal + '" target="_blank" '
            + 'class="btn btn-outline" style="font-size:12.5px">'
            + '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
            + (rev.file_proposal_name || 'Unduh File Perbaikan') + '</a>';
    } else {
        fileEl.innerHTML = '<span style="color:var(--text-muted);font-size:12.5px">—</span>';
    }

    // Ringkasan
    document.getElementById('rovRingkasan').textContent = rev.ringkasan || '—';

    // Respon isu
    const isuEl = document.getElementById('rovIsuList');
    isuEl.innerHTML = '';
    let isuList = [];
    try { isuList = JSON.parse(rev.respon_isu || '[]'); } catch(e) {}
    if (isuList.length === 0) {
        isuEl.innerHTML = '<div style="color:var(--text-muted);font-size:12.5px">Tidak ada isu yang direspons.</div>';
    } else {
        isuList.forEach(function(item, i) {
            const card = document.createElement('div');
            card.style.cssText = 'margin-bottom:10px;border-radius:10px;overflow:hidden;border:1.5px solid var(--border)';
            card.innerHTML = '<div style="padding:10px 14px;background:var(--bg-field);border-bottom:1px solid var(--border)">'
                + '<div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px">'
                + 'Catatan Reviewer ' + (i+1) + '</div>'
                + '<div style="font-size:12.5px;font-weight:600;color:var(--text-primary)">'
                + escHtml(item.isu || '') + '</div></div>'
                + '<div style="padding:10px 14px">'
                + '<div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:4px">Respon Pengusul</div>'
                + '<div style="font-size:12.5px;line-height:1.6;color:var(--text-primary)">' + escHtml(item.respon || '') + '</div>'
                + '</div>';
            isuEl.appendChild(card);
        });
    }

    // Reset form
    document.getElementById('rovForm').querySelectorAll('input[type=radio]').forEach(r => r.checked = false);
    document.getElementById('rovCatatan').value = '';
    rovHighlight(null);
    document.getElementById('rov-catatan-hint').style.display = 'none';

    document.getElementById('revisiOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeRevisiOverlay() {
    document.getElementById('revisiOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

function rovHighlight(val) {
    const lblD = document.getElementById('rov-lbl-diterima');
    const lblK = document.getElementById('rov-lbl-dikembalikan');
    lblD.style.borderColor = (val === 'diterima')    ? '#16a34a' : 'var(--border)';
    lblD.style.background  = (val === 'diterima')    ? '#f0fdf4' : '';
    lblK.style.borderColor = (val === 'dikembalikan') ? '#ea580c' : 'var(--border)';
    lblK.style.background  = (val === 'dikembalikan') ? '#fff7ed' : '';
    const hint = document.getElementById('rov-catatan-hint');
    hint.style.display = (val === 'dikembalikan') ? 'inline' : 'none';
}

function validateRevisiDecision() {
    const form = document.getElementById('rovForm');
    const keputusan = form.querySelector('input[name=keputusan]:checked');
    const catatan   = document.getElementById('rovCatatan').value.trim();
    if (!keputusan) { alert('Pilih keputusan terlebih dahulu.'); return; }
    if (keputusan.value === 'dikembalikan' && catatan.length < 10) {
        alert('Catatan wajib diisi (min. 10 karakter) jika perbaikan dikembalikan.');
        document.getElementById('rovCatatan').focus();
        return;
    }
    if (!confirm(keputusan.value === 'diterima'
        ? 'Terima perbaikan dan kirim kembali ke reviewer untuk seleksi substantif?'
        : 'Kembalikan perbaikan ke pengusul untuk diperbaiki kembali?')) return;
    form.submit();
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
