<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang  = $_COOKIE['lang'] ?? 'id';
$id    = $lang === 'id';
$tahun = (int)(getSetting($pdo,'penelitian_tahun') ?: date('Y'));

/* ── POST Actions ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Set/update deadline per proposal
    if ($action === 'set_deadline') {
        $pid      = (int)($_POST['pid'] ?? 0);
        $deadline = clean($_POST['deadline'] ?? '');
        if ($pid && $deadline) {
            // Pastikan ada row kontrak
            $ck = $pdo->prepare("SELECT id FROM kontrak_penelitian WHERE usulan_id=?");
            $ck->execute([$pid]);
            if ($ck->fetch()) {
                $pdo->prepare("UPDATE kontrak_penelitian SET deadline_laporan=? WHERE usulan_id=?")
                    ->execute([$deadline, $pid]);
            } else {
                $pdo->prepare("INSERT INTO kontrak_penelitian (usulan_id,deadline_laporan) VALUES (?,?)")
                    ->execute([$pid, $deadline]);
            }
            // Notifikasi ke pengusul
            $prop = $pdo->prepare("SELECT user_id,judul FROM usulan_penelitian WHERE id=?");
            $prop->execute([$pid]);
            $pdata = $prop->fetch();
            if ($pdata) {
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$pdata['user_id'],
                        $id?'Deadline Laporan Penelitian Ditetapkan':'Research Report Deadline Set',
                        ($id?'Deadline penyerahan laporan penelitian Anda ditetapkan pada: ':'Your research report deadline has been set to: ')
                        . date('d F Y', strtotime($deadline)),
                        'info']);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Deadline berhasil disimpan.':'Deadline saved.'];
        }
        redirect('/admin/penelitian_laporan.php');
    }

    // Review laporan: terima / minta revisi / tolak
    if ($action === 'review_laporan') {
        $lap_id    = (int)($_POST['lap_id'] ?? 0);
        $keputusan = in_array($_POST['keputusan'] ?? '', ['diterima','revisi','ditolak'], true) ? $_POST['keputusan'] : '';
        $catatan   = clean($_POST['catatan'] ?? '');

        if ($lap_id && $keputusan) {
            // Ambil data laporan saat ini (untuk arsipkan ke history)
            $cur = $pdo->prepare("SELECT lp.*, up.id AS usulan_id, up.judul, up.user_id AS pengusul_id
                                  FROM laporan_penelitian lp
                                  JOIN usulan_penelitian up ON up.id = lp.usulan_id
                                  WHERE lp.id = ?");
            $cur->execute([$lap_id]);
            $cur = $cur->fetch();
            if (!$cur) { redirect('/admin/penelitian_laporan.php'); }

            $round = (int)($cur['round_ke'] ?? 1);

            // Catat history (snapshot file + keputusan)
            $pdo->prepare("
                INSERT INTO laporan_revisi
                  (laporan_id, usulan_id, round_ke, file_laporan, file_laporan_name, file_laporan_size,
                   ringkasan, catatan_admin, keputusan, reviewed_by, reviewed_at, submitted_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?)
            ")->execute([
                $lap_id, $cur['usulan_id'], $round,
                $cur['file_laporan'], $cur['file_laporan_name'], $cur['file_laporan_size'],
                null, $catatan ?: null, $keputusan, $_SESSION['user_id'],
                $cur['tanggal_submit'] ?: date('Y-m-d H:i:s'),
            ]);

            // Update laporan utama
            $pdo->prepare("
                UPDATE laporan_penelitian
                SET status=?, catatan_admin=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                WHERE id=?
            ")->execute([$keputusan, $catatan ?: null, $_SESSION['user_id'], $lap_id]);

            // Jika diterima → status proposal naik ke laporan_diterima
            if ($keputusan === 'diterima') {
                $pdo->prepare("UPDATE usulan_penelitian SET status='laporan_diterima', updated_at=NOW()
                               WHERE id=? AND status IN ('kontrak_aktif','penandatanganan_kontrak')")
                    ->execute([$cur['usulan_id']]);
            }

            // Notifikasi ke pengusul
            $jdl = match($keputusan) {
                'diterima' => $id?'Laporan Penelitian Diterima':'Research Report Accepted',
                'revisi'   => $id?'Laporan Perlu Direvisi':'Research Report Needs Revision',
                'ditolak'  => $id?'Laporan Penelitian Ditolak':'Research Report Rejected',
            };
            $msg = match($keputusan) {
                'diterima' => $id?'Laporan penelitian Anda telah diterima oleh LPPM. ':'Your research report has been accepted. ',
                'revisi'   => $id?'Laporan Anda dikembalikan untuk diperbaiki. Silakan upload ulang setelah revisi. ':'Your report was returned for revision. Please re-upload after revision. ',
                'ditolak'  => $id?'Laporan Anda ditolak. ':'Your report was rejected. ',
            };
            $msg .= mb_strimwidth($cur['judul'], 0, 70, '…');
            if ($catatan) $msg .= ' — ' . $catatan;
            $tipe = $keputusan==='diterima'?'sukses':($keputusan==='revisi'?'peringatan':'error');
            $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                ->execute([$cur['pengusul_id'], $jdl, $msg, $tipe]);

            $_SESSION['flash'] = ['type'=>'success','msg'=>$id
                ? 'Keputusan tersimpan. ' . ($keputusan==='revisi'?'Peneliti diminta upload ulang.':'')
                : 'Decision saved.'];
        }
        redirect('/admin/penelitian_laporan.php');
    }

    // Tambah sanksi atas laporan
    if ($action === 'tambah_sanksi') {
        $pid          = (int)($_POST['pid'] ?? 0);
        $jenis        = clean($_POST['jenis_sanksi'] ?? '');
        $deskripsi    = clean($_POST['deskripsi'] ?? '');
        $durasi       = (int)($_POST['durasi_tahun'] ?? 0);
        if ($pid && $deskripsi) {
            $u_q = $pdo->prepare("SELECT user_id, judul FROM usulan_penelitian WHERE id=?");
            $u_q->execute([$pid]);
            $u = $u_q->fetch();
            if ($u) {
                $berlaku_sd = $durasi > 0 ? date('Y-m-d', strtotime("+{$durasi} years")) : null;
                $pdo->prepare("
                    INSERT INTO monev_sanksi
                      (usulan_id,user_id,jenis_sanksi,deskripsi,durasi_tahun,berlaku_sd,admin_id)
                    VALUES (?,?,?,?,?,?,?)
                ")->execute([
                    $pid, $u['user_id'], $jenis ?: 'lainnya', $deskripsi,
                    $durasi ?: null, $berlaku_sd, $_SESSION['user_id'],
                ]);
                $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                    ->execute([$u['user_id'],
                        $id?'Sanksi Laporan Penelitian':'Research Report Sanction',
                        ($id?'Sanksi diterapkan untuk proposal: ':'Sanction applied to proposal: ')
                        . mb_strimwidth($u['judul'],0,70,'…') . ' — ' . $deskripsi,
                        'error']);
                $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Sanksi tercatat.':'Sanction recorded.'];
            }
        }
        redirect('/admin/penelitian_laporan.php?pid=' . $pid);
    }
}

/* ── Load all proposals yang sudah punya kontrak (atau setelahnya) ──── */
$proposals = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.reviewed_at, up.user_id,
           u.nama_lengkap, u.program_studi, u.nidn,
           kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan,
           lp.id as lap_id, lp.round_ke, lp.file_laporan, lp.file_laporan_name,
           lp.file_laporan_size, lp.tanggal_submit,
           lp.status as lap_status, lp.catatan_admin as lap_catatan,
           lp.reviewed_at as lap_reviewed_at,
           (SELECT COUNT(*) FROM laporan_revisi lr WHERE lr.usulan_id = up.id) AS n_history,
           (SELECT COUNT(*) FROM monev_sanksi ms WHERE ms.usulan_id = up.id AND ms.status='aktif' AND ms.deleted_at IS NULL) AS n_sanksi
    FROM usulan_penelitian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
    LEFT JOIN laporan_penelitian lp ON lp.usulan_id = up.id
    WHERE up.tahun_anggaran=? AND up.deleted_at IS NULL
      AND up.status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')
    ORDER BY up.reviewed_at DESC
");
$proposals->execute([$tahun]);
$proposals = $proposals->fetchAll();

/* ── Load history revisi untuk view detail ─────────────────────── */
$view_pid = (int)($_GET['pid'] ?? 0);
$view_history = [];
$view_sanksi  = [];
if ($view_pid) {
    $hq = $pdo->prepare("
        SELECT lr.*, u.nama_lengkap AS reviewer_nama
        FROM laporan_revisi lr
        LEFT JOIN users u ON u.id = lr.reviewed_by
        WHERE lr.usulan_id = ?
        ORDER BY lr.id DESC
    ");
    $hq->execute([$view_pid]);
    $view_history = $hq->fetchAll();

    $sq = $pdo->prepare("
        SELECT s.*, u.nama_lengkap AS admin_nama
        FROM monev_sanksi s
        LEFT JOIN users u ON u.id = s.admin_id
        WHERE s.usulan_id = ? AND s.deleted_at IS NULL
        ORDER BY s.created_at DESC
    ");
    $sq->execute([$view_pid]);
    $view_sanksi = $sq->fetchAll();
}

/* ── Aggregate stats ─────────────────────────────────────────── */
$total_lolos    = count($proposals);
$n_kontrak      = 0;
$n_submit       = 0;
$n_tepat_waktu  = 0;
$n_terlambat    = 0;
$n_diterima     = 0;
$n_ditolak      = 0;
$n_menunggu     = 0;
$n_belum        = 0;
$now_ts         = time();

foreach ($proposals as $p) {
    if (!empty($p['nomor_kontrak']) || !empty($p['tgl_kontrak'])) $n_kontrak++;
    if ($p['lap_id']) {
        $n_submit++;
        $dl = $p['deadline_laporan'];
        $ts = strtotime($p['tanggal_submit']);
        if ($dl && $ts > strtotime($dl)) $n_terlambat++;
        elseif ($p['tanggal_submit']) $n_tepat_waktu++;
        match($p['lap_status']) {
            'diterima' => $n_diterima++,
            'ditolak'  => $n_ditolak++,
            default    => $n_menunggu++,
        };
    } else {
        $n_belum++;
    }
}

$filter = clean($_GET['filter'] ?? 'semua');
$q      = clean($_GET['q'] ?? '');

$filtered = array_filter($proposals, function($p) use ($filter, $q) {
    if ($q) {
        $hay = strtolower($p['judul'] . ' ' . $p['nama_lengkap']);
        if (strpos($hay, strtolower($q)) === false) return false;
    }
    return match($filter) {
        'belum'    => !$p['lap_id'],
        'menunggu' => $p['lap_status'] === 'menunggu',
        'diterima' => $p['lap_status'] === 'diterima',
        'ditolak'  => $p['lap_status'] === 'ditolak',
        'terlambat'=> $p['lap_id'] && $p['deadline_laporan'] && strtotime($p['tanggal_submit']) > strtotime($p['deadline_laporan']),
        default    => true,
    };
});
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Laporan Penelitian':'Research Report' ?> — LPPM Admin</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.stat-grid{grid-template-columns:1fr 1fr}}
.stat-card{background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;padding:14px 16px}
.stat-num{font-size:30px;font-weight:900;line-height:1}
.stat-lbl{font-size:11px;color:var(--text-muted);margin-top:4px}
.pipeline{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:20px;background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;padding:14px 16px}
.pipe-box{text-align:center;flex:1;min-width:80px}
.pipe-n{font-size:22px;font-weight:900}
.pipe-l{font-size:10.5px;color:var(--text-muted);margin-top:2px}
.pipe-arrow{color:var(--text-muted);font-size:16px;flex-shrink:0}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.ftab{padding:6px 13px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:var(--bg-card);color:var(--text-muted);text-decoration:none}
.ftab.active{background:var(--primary);border-color:var(--primary);color:#fff}
.lap-table{width:100%;border-collapse:collapse;font-size:12.5px}
.lap-table th{background:var(--bg-field);color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:9px 12px;text-align:left;border-bottom:1.5px solid var(--border)}
.lap-table td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.lap-table tr:hover td{background:var(--bg-hover)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:700}
.funnel-bar{height:8px;border-radius:5px;background:#e2e8f0;overflow:hidden;margin-top:3px}
.funnel-fill{height:100%;border-radius:5px;transition:width .3s}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          <?= $id?'Laporan Penelitian':'Research Reports' ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/admin/penelitian.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
        </a>
        <a href="<?= BASE_URL ?>/admin/penelitian_laporan_cetak.php?tahun=<?= $tahun ?>" target="_blank"
           class="btn btn-primary" style="font-size:12px;gap:6px;background:linear-gradient(135deg,#0c4a6e,#0284c7);border:none;display:inline-flex;align-items:center">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          <?= $id?'Cetak Laporan':'Print Report' ?>
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

      <?php if ($view_pid && (!empty($view_history) || !empty($view_sanksi))):
        $vp = null;
        foreach ($proposals as $_p) if ((int)$_p['id'] === $view_pid) { $vp = $_p; break; }
      ?>
      <div class="card" style="margin-bottom:18px;padding:0;overflow:hidden;border-color:#cbd5e1">
        <div style="padding:12px 16px;background:linear-gradient(135deg,#1e3a8a,#3b82f6);color:#fff;display:flex;align-items:center;gap:10px">
          <?= ic('clipboard','style="width:18px;height:18px"') ?>
          <div style="flex:1">
            <div style="font-size:13.5px;font-weight:800"><?= $id?'Detail Riwayat & Sanksi':'History & Sanctions Detail' ?></div>
            <?php if ($vp): ?>
            <div style="font-size:11.5px;opacity:.85"><?= htmlspecialchars(mb_strimwidth($vp['judul'], 0, 90, '…')) ?></div>
            <?php endif; ?>
          </div>
          <a href="<?= BASE_URL ?>/admin/penelitian_laporan.php" style="color:#fff;background:rgba(255,255,255,.18);padding:5px 10px;border-radius:7px;font-size:11.5px;text-decoration:none">
            ✕ <?= $id?'Tutup':'Close' ?>
          </a>
        </div>
        <div style="padding:14px 16px">

          <!-- History submission/feedback -->
          <?php if (!empty($view_history)): ?>
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:9px">
            <?= $id?'Riwayat Submit & Keputusan':'Submit & Decision History' ?>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px">
          <?php foreach ($view_history as $h):
            $kp_color = match($h['keputusan']) {
              'diterima' => ['#f0fdf4','#16a34a'],
              'revisi'   => ['#fefce8','#ca8a04'],
              'ditolak'  => ['#fef2f2','#dc2626'],
              default    => ['#f1f5f9','#64748b'],
            };
            $kp_label = match($h['keputusan']) {
              'diterima' => $id?'Diterima':'Accepted',
              'revisi'   => $id?'Minta Revisi':'Revision Requested',
              'ditolak'  => $id?'Ditolak':'Rejected',
              default    => '—',
            };
          ?>
          <div style="border:1.5px solid var(--border);border-radius:9px;padding:11px 13px;background:var(--bg-card)">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
              <span style="background:#e0e7ff;color:#3730a3;font-weight:700;font-size:10.5px;padding:2px 8px;border-radius:6px">
                Round <?= (int)$h['round_ke'] ?>
              </span>
              <span style="background:<?= $kp_color[0] ?>;color:<?= $kp_color[1] ?>;font-weight:700;font-size:10.5px;padding:2px 8px;border-radius:6px">
                <?= $kp_label ?>
              </span>
              <span style="font-size:11px;color:var(--text-muted)">
                <?= $id?'Submit:':'Submitted:' ?> <?= date('d M Y H:i', strtotime($h['submitted_at'])) ?>
              </span>
              <?php if ($h['reviewed_at']): ?>
              <span style="font-size:11px;color:var(--text-muted)">
                · <?= $id?'Diputuskan:':'Decided:' ?> <?= date('d M Y H:i', strtotime($h['reviewed_at'])) ?>
                <?php if ($h['reviewer_nama']): ?> oleh <?= htmlspecialchars($h['reviewer_nama']) ?><?php endif; ?>
              </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($h['file_laporan'])): ?>
            <div style="margin-bottom:5px">
              <a href="<?= BASE_URL ?>/<?= htmlspecialchars($h['file_laporan']) ?>" target="_blank"
                 style="font-size:11.5px;color:var(--primary);font-weight:600;text-decoration:none">
                <?= ic('download','style="width:11px;height:11px;display:inline;vertical-align:-1px"') ?>
                <?= htmlspecialchars($h['file_laporan_name'] ?? basename($h['file_laporan'])) ?>
                <?php if ($h['file_laporan_size']): ?>
                <span style="color:var(--text-muted);font-weight:400">· <?= formatFileSize((int)$h['file_laporan_size']) ?></span>
                <?php endif; ?>
              </a>
            </div>
            <?php endif; ?>
            <?php if ($h['catatan_admin']): ?>
            <div style="background:var(--bg-field);border-radius:6px;padding:7px 10px;font-size:11.5px;color:#475569">
              <strong><?= $id?'Catatan admin:':'Admin notes:' ?></strong> <?= nl2br(htmlspecialchars($h['catatan_admin'])) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Sanksi -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:9px">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px">
              <?= $id?'Sanksi':'Sanctions' ?> (<?= count($view_sanksi) ?>)
            </div>
            <button onclick="openSanksi(<?= $view_pid ?>)" class="btn btn-outline" style="font-size:11.5px;padding:5px 11px;color:#dc2626;border-color:#fecaca">
              <?= ic('alert','style="width:12px;height:12px"') ?> <?= $id?'Tambah Sanksi':'Add Sanction' ?>
            </button>
          </div>
          <?php if (empty($view_sanksi)): ?>
          <div style="padding:18px;text-align:center;color:var(--text-muted);font-size:11.5px;background:var(--bg-field);border-radius:9px">
            <?= $id?'Tidak ada sanksi tercatat.':'No sanctions recorded.' ?>
          </div>
          <?php else: foreach ($view_sanksi as $s): ?>
          <div style="border:1.5px solid #fecaca;background:#fef2f2;border-radius:9px;padding:10px 13px;margin-bottom:7px">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:5px">
              <div style="font-size:12px;font-weight:700;color:#991b1b">
                <?= htmlspecialchars(ucwords(str_replace('_',' ', $s['jenis_sanksi']))) ?>
              </div>
              <div style="font-size:10.5px;color:#7f1d1d">
                <?= date('d M Y', strtotime($s['created_at'])) ?>
                <?php if ($s['admin_nama']): ?> · <?= htmlspecialchars($s['admin_nama']) ?><?php endif; ?>
              </div>
            </div>
            <div style="font-size:11.5px;color:#7f1d1d;line-height:1.5"><?= nl2br(htmlspecialchars($s['deskripsi'])) ?></div>
            <?php if ($s['durasi_tahun']): ?>
            <div style="font-size:10.5px;color:#7f1d1d;margin-top:3px">
              <?= $id?'Durasi':'Duration' ?>: <?= (int)$s['durasi_tahun'] ?> <?= $id?'tahun':'years' ?>
              <?php if ($s['berlaku_sd']): ?> · <?= $id?'berlaku s/d':'valid until' ?> <?= date('d M Y', strtotime($s['berlaku_sd'])) ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; endif; ?>

        </div>
      </div>
      <?php endif; ?>

      <!-- Pipeline -->
      <div class="pipeline">
        <div class="pipe-box">
          <div class="pipe-n" style="color:#1565C0"><?= $total_lolos ?></div>
          <div class="pipe-l"><?= $id?'Lolos Substantif':'Passed Review' ?></div>
        </div>
        <div class="pipe-arrow">→</div>
        <div class="pipe-box">
          <div class="pipe-n" style="color:#7c3aed"><?= $n_kontrak ?></div>
          <div class="pipe-l"><?= $id?'Punya Kontrak':'Has Contract' ?></div>
          <?php if ($total_lolos > 0): ?>
          <div class="funnel-bar"><div class="funnel-fill" style="width:<?= round($n_kontrak/$total_lolos*100) ?>%;background:#7c3aed"></div></div>
          <?php endif; ?>
        </div>
        <div class="pipe-arrow">→</div>
        <div class="pipe-box">
          <div class="pipe-n" style="color:#0891b2"><?= $n_submit ?></div>
          <div class="pipe-l"><?= $id?'Submit Laporan':'Submitted Report' ?></div>
          <?php if ($total_lolos > 0): ?>
          <div class="funnel-bar"><div class="funnel-fill" style="width:<?= round($n_submit/$total_lolos*100) ?>%;background:#0891b2"></div></div>
          <?php endif; ?>
        </div>
        <div class="pipe-arrow">→</div>
        <div class="pipe-box">
          <div class="pipe-n" style="color:#16a34a"><?= $n_tepat_waktu ?></div>
          <div class="pipe-l"><?= $id?'Tepat Waktu':'On Time' ?></div>
          <?php if ($n_submit > 0): ?>
          <div class="funnel-bar"><div class="funnel-fill" style="width:<?= round($n_tepat_waktu/$n_submit*100) ?>%;background:#16a34a"></div></div>
          <?php endif; ?>
        </div>
        <div class="pipe-arrow">→</div>
        <div class="pipe-box">
          <div class="pipe-n" style="color:#16a34a"><?= $n_diterima ?></div>
          <div class="pipe-l"><?= $id?'Laporan Diterima':'Accepted' ?></div>
          <?php if ($n_submit > 0): ?>
          <div class="funnel-bar"><div class="funnel-fill" style="width:<?= round($n_diterima/$n_submit*100) ?>%;background:#16a34a"></div></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-num" style="color:#dc2626"><?= $n_belum ?></div>
          <div class="stat-lbl"><?= $id?'Belum Submit Laporan':'Not Yet Submitted' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-num" style="color:#ca8a04"><?= $n_menunggu ?></div>
          <div class="stat-lbl"><?= $id?'Menunggu Verifikasi':'Awaiting Verification' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-num" style="color:#ea580c"><?= $n_terlambat ?></div>
          <div class="stat-lbl"><?= $id?'Terlambat':'Late Submission' ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-num" style="color:#16a34a"><?= $n_diterima ?></div>
          <div class="stat-lbl"><?= $id?'Laporan Diterima':'Reports Accepted' ?></div>
        </div>
      </div>

      <!-- Search & filter -->
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <form method="GET" style="display:flex;gap:8px;flex:1;min-width:200px">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                 placeholder="<?= $id?'Cari judul / pengusul…':'Search title / proposer…' ?>"
                 style="flex:1;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--bg-field);color:var(--text-primary)">
          <button type="submit" class="btn btn-outline" style="font-size:12.5px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </button>
        </form>
      </div>
      <div class="filter-tabs">
        <?php
        $tabs = [
          'semua'    => [$id?'Semua':'All',              $total_lolos],
          'belum'    => [$id?'Belum Submit':'Not Submitted', $n_belum],
          'menunggu' => [$id?'Menunggu':'Pending',        $n_menunggu],
          'diterima' => [$id?'Diterima':'Accepted',       $n_diterima],
          'ditolak'  => [$id?'Ditolak':'Rejected',        $n_ditolak],
          'terlambat'=> [$id?'Terlambat':'Late',          $n_terlambat],
        ];
        foreach ($tabs as $key => [$lbl, $cnt]):
        ?>
        <a href="?filter=<?= $key ?><?= $q ? '&q='.urlencode($q) : '' ?>"
           class="ftab <?= $filter===$key?'active':'' ?>">
          <?= $lbl ?> <span style="opacity:.7">(<?= $cnt ?>)</span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Table -->
      <div class="card" style="padding:0;overflow:hidden">
        <?php if (empty($filtered)): ?>
        <div style="text-align:center;padding:50px 20px;color:var(--text-muted)">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.4;margin-bottom:10px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <div><?= $id?'Tidak ada data':'No data found' ?></div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="lap-table">
            <thead>
              <tr>
                <th>#</th>
                <th><?= $id?'Judul & Pengusul':'Title & Proposer' ?></th>
                <th><?= $id?'Kontrak':'Contract' ?></th>
                <th><?= $id?'Deadline Laporan':'Report Deadline' ?></th>
                <th><?= $id?'Status Laporan':'Report Status' ?></th>
                <th><?= $id?'Waktu Submit':'Submit Time' ?></th>
                <th><?= $id?'Aksi':'Action' ?></th>
              </tr>
            </thead>
            <tbody>
            <?php $no = 0; foreach ($filtered as $p):
              $no++;
              $has_lap    = !empty($p['lap_id']);
              $dl         = $p['deadline_laporan'];
              $terlambat  = $has_lap && $dl && strtotime($p['tanggal_submit']) > strtotime($dl);
              $dl_lewat   = $dl && !$has_lap && strtotime($dl) < $now_ts;
            ?>
            <tr>
              <td style="font-size:11px;color:var(--text-muted);font-weight:600"><?= $no ?></td>

              <td style="max-width:220px">
                <div style="font-weight:700;line-height:1.4;font-size:12.5px">
                  <?= htmlspecialchars(mb_strimwidth($p['judul'],0,65,'…')) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap">
                  <span><?= htmlspecialchars($p['nama_lengkap']) ?></span>
                  <span style="font-weight:700;color:<?= $p['skema']==='nasional'?'#15803d':'#1d4ed8' ?>">
                    <?= strtoupper($p['skema']) ?>
                  </span>
                </div>
              </td>

              <td>
                <?php if (!empty($p['nomor_kontrak'])): ?>
                <div style="font-size:11.5px;font-weight:600;color:#7c3aed"><?= htmlspecialchars($p['nomor_kontrak']) ?></div>
                <div style="font-size:10.5px;color:var(--text-muted)"><?= $p['tgl_kontrak'] ? date('d M Y',strtotime($p['tgl_kontrak'])) : '' ?></div>
                <?php else: ?>
                <span style="font-size:11px;color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>

              <td>
                <?php if ($dl): ?>
                <div style="font-size:12px;font-weight:600;color:<?= $dl_lewat?'#dc2626':($terlambat?'#dc2626':'#16a34a') ?>">
                  <?= date('d M Y', strtotime($dl)) ?>
                </div>
                <?php if ($dl_lewat): ?>
                <div style="font-size:10.5px;color:#dc2626"><?= $id?'Lewat deadline':'Past deadline' ?></div>
                <?php endif; ?>
                <?php else: ?>
                <button onclick="openSetDeadline(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['judul']),ENT_QUOTES) ?>')"
                        style="font-size:11px;padding:4px 9px;border-radius:6px;cursor:pointer;border:1px dashed var(--border);background:transparent;color:var(--primary)">
                  + <?= $id?'Set Deadline':'Set Deadline' ?>
                </button>
                <?php endif; ?>
              </td>

              <td>
                <?php if (!$has_lap): ?>
                <span class="badge" style="background:#f1f5f9;color:#64748b">
                  <?= $id?'Belum Diunggah':'Not Uploaded' ?>
                </span>
                <?php else: ?>
                <?php
                $bst = match($p['lap_status']) {
                    'diterima' => ['#f0fdf4','#16a34a',$id?'Diterima':'Accepted'],
                    'ditolak'  => ['#fef2f2','#dc2626',$id?'Ditolak':'Rejected'],
                    default    => ['#fef9c3','#92400e',$id?'Menunggu':'Pending'],
                };
                ?>
                <span class="badge" style="background:<?= $bst[0] ?>;color:<?= $bst[1] ?>">
                  <?= $bst[2] ?>
                </span>
                <?php if ($terlambat): ?>
                <span class="badge" style="background:#fef2f2;color:#dc2626;margin-left:4px;font-size:10.5px">
                  <?= $id?'Terlambat':'Late' ?>
                </span>
                <?php endif; ?>
                <?php if ($p['lap_catatan']): ?>
                <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px;max-width:130px">
                  <?= htmlspecialchars(mb_strimwidth($p['lap_catatan'],0,50,'…')) ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
              </td>

              <td>
                <?php if ($has_lap): ?>
                <div style="font-size:12px"><?= date('d M Y', strtotime($p['tanggal_submit'])) ?></div>
                <div style="font-size:10.5px;color:var(--text-muted)"><?= date('H:i', strtotime($p['tanggal_submit'])) ?></div>
                <?php else: ?>
                <span style="font-size:11px;color:var(--text-muted)">—</span>
                <?php endif; ?>
              </td>

              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                  <?php if ($has_lap): ?>
                  <a href="<?= BASE_URL ?>/<?= htmlspecialchars($p['file_laporan']) ?>" target="_blank"
                     class="btn btn-outline" style="font-size:11px;padding:5px 10px">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <?= $id?'Lihat':'View' ?>
                  </a>
                  <?php if (in_array($p['lap_status'], ['menunggu','revisi'], true)): ?>
                  <button onclick="openReview(<?= $p['lap_id'] ?>, '<?= htmlspecialchars(addslashes($p['judul']),ENT_QUOTES) ?>')"
                          class="btn btn-primary" style="font-size:11px;padding:5px 10px;background:linear-gradient(135deg,#0369a1,#0284c7);border:none">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <?= $id?'Verifikasi':'Verify' ?>
                  </button>
                  <?php endif; ?>
                  <?php if ($p['n_history'] > 0 || $p['n_sanksi'] > 0): ?>
                  <a href="?pid=<?= $p['id'] ?>" class="btn btn-outline" style="font-size:11px;padding:5px 10px"
                     title="<?= $id?'Lihat history & sanksi':'View history & sanctions' ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/></svg>
                    <?= (int)$p['n_history'] ?>×<?php if ($p['n_sanksi']>0): ?> · <span style="color:#dc2626"><?= (int)$p['n_sanksi'] ?>⚠<?php endif; ?></span>
                  </a>
                  <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($dl): ?>
                  <button onclick="openSetDeadline(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['judul']),ENT_QUOTES) ?>', '<?= $dl ?>')"
                          style="font-size:11px;padding:5px 10px;border-radius:6px;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text-muted)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  </button>
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

<!-- Set Deadline Modal -->
<div id="deadlineModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--bg-card);border-radius:14px;width:100%;max-width:420px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:18px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:14px;font-weight:700"><?= $id?'Tetapkan Deadline Laporan':'Set Report Deadline' ?></div>
      <button onclick="closeDeadline()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px;line-height:1">×</button>
    </div>
    <form method="POST">
      <div style="padding:18px 20px">
        <input type="hidden" name="action" value="set_deadline">
        <input type="hidden" name="pid" id="dlPid">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Proposal</div>
        <div id="dlJudul" style="font-size:12.5px;font-weight:600;line-height:1.4;margin-bottom:16px"></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">
          <?= $id?'Tanggal Deadline':'Deadline Date' ?>
        </div>
        <input type="date" name="deadline" id="dlDate" required
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--bg-field);color:var(--text-primary)">
      </div>
      <div style="padding:14px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
        <button type="button" onclick="closeDeadline()" class="btn btn-outline" style="font-size:13px"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary" style="font-size:13px"><?= $id?'Simpan':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Review Laporan Modal -->
<div id="reviewModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--bg-card);border-radius:14px;width:100%;max-width:460px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:18px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:14px;font-weight:700"><?= $id?'Verifikasi Laporan':'Verify Report' ?></div>
      <button onclick="closeReview()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px;line-height:1">×</button>
    </div>
    <form method="POST">
      <div style="padding:18px 20px">
        <input type="hidden" name="action" value="review_laporan">
        <input type="hidden" name="lap_id" id="rvLapId">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Proposal</div>
        <div id="rvJudul" style="font-size:12.5px;font-weight:600;line-height:1.4;margin-bottom:16px"></div>

        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
          <?= $id?'Keputusan':'Decision' ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px">
          <label style="cursor:pointer">
            <input type="radio" name="keputusan" value="diterima" required style="display:none" onclick="setRvDecision('diterima')">
            <div id="rd_diterima" style="border:2px solid var(--border);border-radius:10px;padding:11px;text-align:center;transition:.2s">
              <div style="font-size:17px">✓</div>
              <div style="font-size:11.5px;font-weight:700;color:#16a34a"><?= $id?'Terima':'Accept' ?></div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="keputusan" value="revisi" required style="display:none" onclick="setRvDecision('revisi')">
            <div id="rd_revisi" style="border:2px solid var(--border);border-radius:10px;padding:11px;text-align:center;transition:.2s">
              <div style="font-size:17px">↻</div>
              <div style="font-size:11.5px;font-weight:700;color:#ca8a04"><?= $id?'Minta Revisi':'Request Revision' ?></div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="keputusan" value="ditolak" required style="display:none" onclick="setRvDecision('ditolak')">
            <div id="rd_ditolak" style="border:2px solid var(--border);border-radius:10px;padding:11px;text-align:center;transition:.2s">
              <div style="font-size:17px">✗</div>
              <div style="font-size:11.5px;font-weight:700;color:#dc2626"><?= $id?'Tolak':'Reject' ?></div>
            </div>
          </label>
        </div>

        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">
          <?= $id?'Catatan (opsional)':'Notes (optional)' ?>
        </div>
        <textarea name="catatan" rows="3" placeholder="<?= $id?'Catatan untuk peneliti…':'Notes for the researcher…' ?>"
          style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:12.5px;resize:vertical;background:var(--bg-field);color:var(--text-primary)"></textarea>
      </div>
      <div style="padding:14px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
        <button type="button" onclick="closeReview()" class="btn btn-outline" style="font-size:13px"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" id="rvSubmitBtn" class="btn btn-primary" style="font-size:13px"><?= $id?'Simpan Keputusan':'Save Decision' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Sanksi Modal -->
<div id="sanksiModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--bg-card);border-radius:14px;width:100%;max-width:480px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:18px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:14px;font-weight:700;color:#dc2626">⚠ <?= $id?'Tambah Sanksi':'Add Sanction' ?></div>
      <button onclick="closeSanksi()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:20px">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="tambah_sanksi">
      <input type="hidden" name="pid" id="snsPid">
      <div style="padding:18px 20px">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;margin-bottom:14px;font-size:11.5px;color:#7f1d1d">
          <?= $id
            ? 'Sanksi akan tercatat di profil pengusul dan masuk ke notifikasi mereka. Pertimbangkan untuk memberi kesempatan revisi terlebih dahulu.'
            : 'Sanction will be recorded in proposer profile and notified. Consider giving revision opportunity first.' ?>
        </div>
        <div class="form-group" style="margin-bottom:11px">
          <label style="font-size:11.5px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px">
            <?= $id?'Jenis Sanksi':'Sanction Type' ?>
          </label>
          <select name="jenis_sanksi" required style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:7px;font-size:12.5px;background:var(--bg-field);color:var(--text-primary)">
            <option value="catatan_administratif"><?= $id?'Catatan administratif':'Administrative note' ?></option>
            <option value="tidak_boleh_ajuan"><?= $id?'Tidak boleh mengajukan proposal':'Cannot submit new proposal' ?></option>
            <option value="wajib_kembalikan_dana"><?= $id?'Wajib mengembalikan dana':'Must return funds' ?></option>
            <option value="lainnya"><?= $id?'Lainnya':'Other' ?></option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:11px">
          <label style="font-size:11.5px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px">
            <?= $id?'Deskripsi':'Description' ?> <span style="color:#dc2626">*</span>
          </label>
          <textarea name="deskripsi" required rows="3" placeholder="<?= $id?'Alasan dan dasar sanksi…':'Reason and basis…' ?>"
                    style="width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:7px;font-size:12.5px;resize:vertical;background:var(--bg-field);color:var(--text-primary)"></textarea>
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11.5px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px">
            <?= $id?'Durasi (tahun)':'Duration (years)' ?>
            <span style="color:var(--text-muted);font-weight:400;text-transform:none">— <?= $id?'opsional, kosongkan jika permanen':'optional, leave empty for permanent' ?></span>
          </label>
          <input type="number" name="durasi_tahun" min="0" max="20" placeholder="<?= $id?'mis. 2':'e.g. 2' ?>"
                 style="width:120px;padding:8px 11px;border:1.5px solid var(--border);border-radius:7px;font-size:12.5px;background:var(--bg-field);color:var(--text-primary)">
        </div>
      </div>
      <div style="padding:14px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
        <button type="button" onclick="closeSanksi()" class="btn btn-outline" style="font-size:13px"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary" style="font-size:13px;background:#dc2626;border-color:#dc2626">
          ⚠ <?= $id?'Terapkan Sanksi':'Apply Sanction' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openSanksi(pid){
  document.getElementById('snsPid').value=pid;
  document.getElementById('sanksiModal').style.display='flex';
}
function closeSanksi(){document.getElementById('sanksiModal').style.display='none';}
document.getElementById('sanksiModal').addEventListener('click',function(e){if(e.target===this)closeSanksi();});

function toggleLang(){
  const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';
  document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}
function openSetDeadline(pid,judul,existing){
  document.getElementById('dlPid').value=pid;
  document.getElementById('dlJudul').textContent=judul;
  document.getElementById('dlDate').value=existing||'';
  const m=document.getElementById('deadlineModal');
  m.style.display='flex';
}
function closeDeadline(){document.getElementById('deadlineModal').style.display='none';}
function openReview(lapId,judul){
  document.getElementById('rvLapId').value=lapId;
  document.getElementById('rvJudul').textContent=judul;
  ['rd_diterima','rd_revisi','rd_ditolak'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){el.style.borderColor='var(--border)';el.style.background='';}
  });
  document.querySelectorAll('input[name=keputusan]').forEach(r=>r.checked=false);
  document.getElementById('reviewModal').style.display='flex';
}
function closeReview(){document.getElementById('reviewModal').style.display='none';}
function setRvDecision(v){
  const cfg={diterima:['#16a34a','#f0fdf4'],revisi:['#ca8a04','#fefce8'],ditolak:['#dc2626','#fef2f2']};
  ['diterima','revisi','ditolak'].forEach(k=>{
    const el=document.getElementById('rd_'+k);
    if(!el) return;
    if(k===v){
      el.style.cssText='border:2px solid '+cfg[k][0]+';border-radius:10px;padding:11px;text-align:center;transition:.2s;background:'+cfg[k][1];
    } else {
      el.style.cssText='border:2px solid var(--border);border-radius:10px;padding:11px;text-align:center;transition:.2s';
    }
  });
}
document.getElementById('deadlineModal').addEventListener('click',function(e){if(e.target===this)closeDeadline();});
document.getElementById('reviewModal').addEventListener('click',function(e){if(e.target===this)closeReview();});
</script>
</body>
</html>
