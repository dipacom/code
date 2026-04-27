<?php
require_once '../../includes/config.php';
requireLogin('reviewer');

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';

// Rubrik penilaian — loaded dari DB, fallback ke default jika belum dikonfigurasi
$rubrik_raw = getSetting($pdo, "pengabdian_reviewer_rubrik");
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

// ── POST: simpan penilaian ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'simpan_penilaian') {
        $assign_id = (int)($_POST['assign_id'] ?? 0);
        $submit    = isset($_POST['submit_final']);

        // Verifikasi assignment milik reviewer ini
        $asgn = $pdo->prepare("SELECT ra.*, up.id as prop_id FROM reviewer_assignment_pengabdian ra
            JOIN usulan_pengabdian up ON up.id=ra.usulan_id
            WHERE ra.id=? AND ra.reviewer_id=?");
        $asgn->execute([$assign_id, $uid]);
        $asgn = $asgn->fetch();

        if (!$asgn) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Penugasan tidak ditemukan.'];
            redirect('/modules/pengabdian/reviewer.php');
        }

        // Aturan #2: penilaian yang sudah dikirimkan tidak dapat diubah
        $sub_check = $pdo->prepare("SELECT submitted_at FROM reviewer_penilaian_pengabdian WHERE assignment_id=?");
        $sub_check->execute([$assign_id]);
        $existing_sub = $sub_check->fetchColumn();
        if (!empty($existing_sub)) {
            $_SESSION['flash'] = ['type'=>'warning','msg'=>$id
                ? 'Penilaian Anda sudah dikirimkan dan tidak dapat diubah.'
                : 'Your assessment has already been submitted and cannot be modified.'];
            redirect('/modules/pengabdian/reviewer.php?pid='.$asgn['prop_id']);
        }

        $skors = [];
        for ($i = 1; $i <= 6; $i++) {
            if ($i <= $N_KRITERIA) {
                $s = (int)($_POST["skor_$i"] ?? 0);
                $s = max(1, min($SKOR_MAX[$i-1], $s));
                $skors[$i] = $s;
            } else {
                $skors[$i] = 0; // kolom DB yang tidak dipakai
            }
        }
        $nilai_total = 0;
        for ($i = 1; $i <= $N_KRITERIA; $i++) {
            $nilai_total += ($BOBOT[$i-1] / $SKOR_MAX[$i-1]) * $skors[$i];
        }
        $saran     = clean($_POST['saran']     ?? '');
        $keputusan = in_array($_POST['keputusan']??'', ['disetujui','revisi_minor','revisi_mayor','ditolak'])
                     ? $_POST['keputusan'] : null;

        if ($submit && !$keputusan) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Pilih rekomendasi sebelum mengirim.':'Please select a recommendation before submitting.'];
            redirect('/modules/pengabdian/reviewer.php?pid='.$asgn['prop_id']);
        }

        // Upload file review (opsional)
        $file_review = null;
        $file_review_name = null;
        if (!empty($_FILES['file_review']['name'])) {
            $f = $_FILES['file_review'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','doc','docx'])) {
                $dir = BASE_PATH . '/uploads/review_pengabdian/' . $uid . '/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = time() . '_review_' . $assign_id . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $f['name']);
                if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                    $file_review      = 'uploads/review_pengabdian/' . $uid . '/' . $fname;
                    $file_review_name = $f['name'];
                }
            }
        }

        $submitted_at = $submit ? 'NOW()' : 'NULL';

        // Upsert penilaian
        $existing = $pdo->prepare("SELECT id, file_review FROM reviewer_penilaian_pengabdian WHERE assignment_id=?");
        $existing->execute([$assign_id]);
        $ex = $existing->fetch();

        if ($ex) {
            $fr = $file_review ?: $ex['file_review'];
            $pdo->prepare("
                UPDATE reviewer_penilaian_pengabdian SET
                  skor_1=?,skor_2=?,skor_3=?,skor_4=?,skor_5=?,skor_6=?,
                  nilai_total=?, saran=?, file_review=?, file_review_name=?,
                  keputusan=?, submitted_at=" . ($submit ? 'NOW()' : 'submitted_at') . "
                WHERE assignment_id=?
            ")->execute([
                $skors[1],$skors[2],$skors[3],$skors[4],$skors[5],$skors[6],
                $nilai_total, $saran, $fr,
                $file_review_name ?: null,
                $keputusan,
                $assign_id,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO reviewer_penilaian_pengabdian
                  (assignment_id, usulan_id, reviewer_id, skor_1, skor_2, skor_3,
                   skor_4, skor_5, skor_6, nilai_total, saran, file_review, file_review_name,
                   keputusan, submitted_at)
                VALUES (?,?,?, ?,?,?,?,?,?,?, ?,?,?, ?,". ($submit?'NOW()':'NULL') .")"
            )->execute([
                $assign_id, $asgn['prop_id'], $uid,
                $skors[1],$skors[2],$skors[3],$skors[4],$skors[5],$skors[6],
                $nilai_total, $saran,
                $file_review, $file_review_name ?: null,
                $keputusan,
            ]);
        }

        $msg = $submit
            ? ($id?'Penilaian berhasil dikirimkan.':'Assessment submitted successfully.')
            : ($id?'Draft penilaian disimpan.':'Assessment draft saved.');
        $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
        redirect('/modules/pengabdian/reviewer.php' . ($submit ? '' : '?pid='.$asgn['prop_id']));
    }
}

// ── Proposal detail (pid) ─────────────────────────────────────
$view_pid = (int)($_GET['pid'] ?? 0);
$view_proposal = null;
$view_assignment = null;
$view_penilaian  = null;

if ($view_pid) {
    $vp = $pdo->prepare("
        SELECT up.*, ra.id as assign_id
        FROM usulan_pengabdian up
        JOIN reviewer_assignment_pengabdian ra ON ra.usulan_id=up.id AND ra.reviewer_id=?
        WHERE up.id=?
    ");
    $vp->execute([$uid, $view_pid]);
    $view_proposal = $vp->fetch();

    if ($view_proposal) {
        $view_assignment = $view_proposal['assign_id'];
        $pn = $pdo->prepare("SELECT * FROM reviewer_penilaian_pengabdian WHERE assignment_id=?");
        $pn->execute([$view_assignment]);
        $view_penilaian = $pn->fetch();
    }
}

// ── List assigned proposals ────────────────────────────────────
$my_proposals = $pdo->prepare("
    SELECT up.*, ra.id as assign_id, ra.assigned_at,
           ra.deadline_review, ra.catatan_admin,
           rp.submitted_at, rp.keputusan as keputusan_reviewer, rp.nilai_total
    FROM reviewer_assignment_pengabdian ra
    JOIN usulan_pengabdian up ON up.id = ra.usulan_id
    LEFT JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id = ra.id
    WHERE ra.reviewer_id = ? AND ra.deleted_at IS NULL
    ORDER BY ra.assigned_at DESC
");
$my_proposals->execute([$uid]);
$my_proposals = $my_proposals->fetchAll();

// Detail assignment untuk pid
$view_deadline = null;
$view_catatan_admin = null;
if ($view_pid && $view_proposal) {
    $vd = $pdo->prepare("SELECT deadline_review, catatan_admin FROM reviewer_assignment_pengabdian WHERE id=?");
    $vd->execute([$view_assignment]);
    if ($r = $vd->fetch()) {
        $view_deadline = $r['deadline_review'];
        $view_catatan_admin = $r['catatan_admin'];
    }
}

// Panduan reviewer aktif (#3)
$panduan_list = [];
try {
    $pq = $pdo->query("SELECT id, judul, deskripsi, file_path, file_name, link_url FROM panduan_reviewer_pkm WHERE is_active=1 ORDER BY urutan ASC, id ASC");
    $panduan_list = $pq->fetchAll();
} catch (\Exception $e) {}

$keputusanLabel = fn($k) => match($k) {
    'disetujui'   => ['label'=>'Diterima',     'color'=>'#16a34a','bg'=>'#f0fdf4'],
    'revisi_minor'=> ['label'=>'Revisi Minor',  'color'=>'#ca8a04','bg'=>'#fef9c3'],
    'revisi_mayor'=> ['label'=>'Revisi Mayor',  'color'=>'#ea580c','bg'=>'#fff7ed'],
    'ditolak'     => ['label'=>'Ditolak',       'color'=>'#dc2626','bg'=>'#fef2f2'],
    default       => ['label'=>'—',             'color'=>'#64748b','bg'=>'#f1f5f9'],
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Penilaian Proposal':'Proposal Review' ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.prop-card { background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;
  padding:16px 18px;margin-bottom:12px;display:flex;gap:14px;align-items:flex-start; }
.prop-card-body { flex:1;min-width:0; }
.prop-card-title { font-weight:700;font-size:13.5px;color:var(--text-primary);line-height:1.4;margin-bottom:4px; }
.prop-card-meta  { font-size:11.5px;color:var(--text-muted); }
.rubrik-table { width:100%;border-collapse:collapse;font-size:13px; }
.rubrik-table th,
.rubrik-table td { padding:10px 12px;border:1px solid var(--border); }
.rubrik-table th  { background:var(--bg-field);font-weight:700;font-size:11px; }
.rubrik-table td:nth-child(3) { text-align:center; }
.rubrik-table td:nth-child(4) { text-align:center;font-weight:700; }
.skor-stars { display:flex;gap:4px; }
.skor-radio { display:none; }
.skor-star {
  width:28px;height:28px;border-radius:6px;border:1.5px solid var(--border);
  display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
  cursor:pointer;background:var(--bg-field);color:var(--text-muted);
  transition:all .12s;
}
.skor-radio:checked + .skor-star,
.skor-radio:checked ~ .skor-radio + .skor-star { background:var(--primary);color:#fff;border-color:var(--primary); }
.total-display { font-size:28px;font-weight:900;color:var(--primary);text-align:center;padding:14px; }
.rec-option { display:none; }
.rec-label {
  display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:9px;
  border:1.5px solid var(--border);background:var(--bg-field);cursor:pointer;
  transition:border-color .15s,background .15s;font-size:13px;font-weight:600;
}
.rec-option:checked + .rec-label { border-color:var(--primary);background:var(--primary-xlight); }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('award') ?>
          <?php if ($view_proposal): ?>
          <?= $id?'Penilaian Proposal':'Proposal Review' ?>
          <span class="breadcrumb"><?= htmlspecialchars(mb_strimwidth($view_proposal['judul'],0,40,'…')) ?></span>
          <?php else: ?>
          <?= $id?'Proposal Ditugaskan':'Assigned Proposals' ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-right">
        <?php if ($view_proposal): ?>
        <a href="<?= BASE_URL ?>/modules/pengabdian/reviewer.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
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

      <?php if ($view_proposal): /* ─── Form penilaian ─── */ ?>

      <!-- Info proposal -->
      <div class="card" style="margin-bottom:18px">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:10px 13px;font-size:12px;color:#1e40af;margin-bottom:14px">
          <?= ic('shield','style="width:14px;height:14px"') ?>
          <strong style="margin-left:5px"><?= $id?'Penilaian ini bersifat double blind':'This review is double blind' ?></strong>
          — <?= $id
            ? 'Identitas Anda tidak akan diungkapkan kepada pengusul.'
            : 'Your identity will not be disclosed to the proposer.'
          ?>
        </div>
        <div style="font-size:15px;font-weight:700;margin-bottom:6px"><?= htmlspecialchars($view_proposal['judul']) ?></div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:12px">
          <?= strtoupper($view_proposal['skema']) ?>
          <?php if ($view_proposal['abstrak']): ?>
          <div style="margin-top:8px;font-size:12.5px;color:var(--text-secondary);line-height:1.6">
            <?= htmlspecialchars(mb_strimwidth($view_proposal['abstrak'],0,300,'…')) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($view_proposal['file_proposal']): ?>
        <a href="<?= BASE_URL ?>/<?= htmlspecialchars($view_proposal['file_proposal']) ?>" target="_blank"
           class="btn btn-outline" style="font-size:12.5px">
          <?= ic('download') ?> <?= $id?'Unduh Proposal (DOC/DOCX)':'Download Proposal (DOC/DOCX)' ?>
        </a>
        <?php endif; ?>
      </div>

      <?php
      $submitted = $view_penilaian && $view_penilaian['submitted_at'];
      $pre = $view_penilaian ?? [];
      ?>

      <?php if ($view_deadline || $view_catatan_admin): ?>
      <?php
        $dl_ts = $view_deadline ? strtotime($view_deadline) : 0;
        $sisa  = $dl_ts ? ($dl_ts - time()) : 0;
        $overdue = $dl_ts && $sisa <= 0 && !$submitted;
        $urgent  = $dl_ts && $sisa > 0 && $sisa < 86400 * 2 && !$submitted;
        $bg = $overdue?'#fef2f2':($urgent?'#fef9c3':'#eff6ff');
        $brd = $overdue?'#fecaca':($urgent?'#fde047':'#bfdbfe');
        $col = $overdue?'#991b1b':($urgent?'#713f12':'#1e3a8a');
      ?>
      <div style="background:<?= $bg ?>;border:1.5px solid <?= $brd ?>;border-radius:12px;padding:14px 16px;margin-bottom:14px;color:<?= $col ?>;font-size:12.5px">
        <?php if ($view_deadline): ?>
        <div style="font-weight:700;display:flex;align-items:center;gap:8px;margin-bottom:4px">
          <?= ic('clock','style="width:16px;height:16px"') ?>
          <?= $id?'Batas waktu penilaian':'Review deadline' ?>: <?= date('d M Y · H:i', $dl_ts) ?> WITA
          <?php if (!$submitted): ?>
            · <span id="rev-cd"></span>
          <?php else: ?>
            · <?= $id?'(sudah dikirim)':'(submitted)' ?>
          <?php endif; ?>
        </div>
        <?php if (!$submitted): ?>
        <script>
          (function(){
            const t = <?= $dl_ts*1000 ?>;
            const el = document.getElementById('rev-cd');
            function tick(){
              const d = t - Date.now();
              if (d <= 0) { el.textContent = '<?= $id?"deadline terlewat":"deadline passed" ?>'; el.style.color='#991b1b'; return; }
              const dd=Math.floor(d/86400000), hh=Math.floor(d/3600000)%24, mm=Math.floor(d/60000)%60;
              el.textContent = (dd>0?dd+' hari ':'') + String(hh).padStart(2,'0') + ':' + String(mm).padStart(2,'0') + ' tersisa';
              setTimeout(tick, 30000);
            }
            tick();
          })();
        </script>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($view_catatan_admin): ?>
        <div style="margin-top:6px;padding-top:6px;border-top:1px solid <?= $brd ?>;font-size:12px">
          <strong><?= $id?'Catatan dari admin:':'Note from admin:' ?></strong>
          <?= htmlspecialchars($view_catatan_admin) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($submitted): ?>
      <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#166534;font-weight:600">
        <?= ic('check-circle') ?>
        <?= $id?'Penilaian sudah dikirimkan pada':'Assessment submitted on' ?>
        <?= date('d M Y H:i', strtotime($view_penilaian['submitted_at'])) ?>
        — <?= $id?'tidak dapat diubah, hanya bisa ditinjau ulang.':'cannot be modified, view-only.' ?>
      </div>
      <?php endif; ?>

      <!-- Form penilaian -->
      <form method="POST" enctype="multipart/form-data" id="formPenilaian">
        <input type="hidden" name="action" value="simpan_penilaian">
        <input type="hidden" name="assign_id" value="<?= $view_assignment ?>">

        <!-- Rubrik -->
        <div class="card" style="margin-bottom:18px;padding:0;overflow:hidden">
          <div style="padding:14px 16px;background:var(--bg-field);border-bottom:1.5px solid var(--border);
                      display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#4a1d96,#7c3aed);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= ic('chart','style="width:16px;height:16px;color:#fff"') ?>
            </div>
            <div>
              <div style="font-size:13px;font-weight:700"><?= $id?'Form Penilaian Proposal':'Proposal Assessment Form' ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= $id?'Skor 1–5 untuk setiap kriteria':'Score 1–5 for each criterion' ?></div>
            </div>
          </div>
          <div style="padding:18px">

            <div style="overflow-x:auto">
              <table class="rubrik-table">
                <thead>
                  <tr>
                    <th style="width:38%"><?= $id?'Kriteria Penilaian':'Assessment Criterion' ?></th>
                    <th style="text-align:center;width:10%">Bobot</th>
                    <th style="text-align:center;width:30%">Skor (1–<?= max($SKOR_MAX) ?>)</th>
                    <th style="text-align:center;width:12%">Nilai</th>
                  </tr>
                </thead>
                <tbody>
                <?php for ($ki = 0; $ki < $N_KRITERIA; $ki++):
                  $n = $ki + 1;
                  $pre_skor = (int)($pre["skor_$n"] ?? 0);
                  $sk_max   = $SKOR_MAX[$ki];
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600"><?= htmlspecialchars($KRITERIA[$ki]) ?></div>
                  </td>
                  <td style="text-align:center;font-weight:700"><?= $BOBOT[$ki] ?>%</td>
                  <td>
                    <?php if ($submitted): ?>
                    <div style="font-size:18px;font-weight:800;text-align:center;color:var(--primary)"><?= $pre_skor ?></div>
                    <?php else: ?>
                    <div class="skor-stars" id="stars-<?= $n ?>">
                      <?php for ($s = 1; $s <= $sk_max; $s++): ?>
                      <input type="radio" name="skor_<?= $n ?>" id="s<?= $n ?>_<?= $s ?>"
                             value="<?= $s ?>" class="skor-radio"
                             <?= $pre_skor === $s ? 'checked' : '' ?>
                             onchange="recalc()">
                      <label for="s<?= $n ?>_<?= $s ?>" class="skor-star"><?= $s ?></label>
                      <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                  </td>
                  <td id="val-<?= $n ?>"><?= $pre_skor ? number_format(($BOBOT[$ki]/$sk_max)*$pre_skor,1) : '—' ?></td>
                </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                  <tr style="background:var(--bg-field)">
                    <td colspan="3" style="font-weight:700;text-align:right"><?= $id?'Total Nilai':'Total Score' ?></td>
                    <td style="font-weight:800;text-align:center;font-size:15px;color:var(--primary)" id="total-nilai">
                      <?= $view_penilaian ? number_format((float)($view_penilaian['nilai_total']??0),1) : '—' ?>
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <?php if (!$submitted): ?>
            <!-- Saran/Catatan -->
            <div class="form-group" style="margin-top:16px">
              <label class="form-label"><?= $id?'Saran dan Catatan':'Suggestions and Notes' ?></label>
              <textarea name="saran" class="form-control" rows="4" style="font-size:12.5px"
                placeholder="<?= $id?'Tuliskan saran perbaikan, catatan, atau komentar untuk proposal ini...':'Write improvement suggestions, notes, or comments for this proposal...' ?>"><?= htmlspecialchars($pre['saran'] ?? '') ?></textarea>
            </div>

            <!-- Upload file review (opsional) -->
            <div class="form-group" style="margin-top:12px">
              <label class="form-label">
                <?= $id?'File Review (PDF/DOC/DOCX)':'Review File (PDF/DOC/DOCX)' ?>
                <span style="color:var(--text-muted);font-weight:400"> — <?= $id?'opsional':'optional' ?></span>
              </label>
              <?php if (!empty($pre['file_review'])): ?>
              <div style="font-size:12.5px;margin-bottom:6px;color:var(--text-muted)">
                <?= $id?'File sebelumnya: ':'Previous file: ' ?>
                <a href="<?= BASE_URL ?>/<?= htmlspecialchars($pre['file_review']) ?>" target="_blank" style="color:var(--primary)">
                  <?= htmlspecialchars($pre['file_review_name'] ?? basename($pre['file_review'])) ?>
                </a>
              </div>
              <?php endif; ?>
              <div onclick="document.getElementById('fileReview').click()"
                   style="border:1.5px dashed var(--border);border-radius:9px;padding:14px;text-align:center;cursor:pointer;background:var(--bg-field)">
                <input type="file" id="fileReview" name="file_review"
                       accept=".pdf,.doc,.docx" onchange="showReviewFile(this)" style="display:none">
                <span id="review-file-label" style="font-size:12.5px;color:var(--text-muted)">
                  <?= $id?'Klik untuk lampirkan file review (proposal yang sudah dianotasi)':'Click to attach review file (annotated proposal)' ?>
                </span>
              </div>
            </div>

            <!-- Rekomendasi -->
            <div style="margin-top:16px">
              <label class="form-label"><?= $id?'Rekomendasi':'Recommendation' ?> <span class="required">*</span></label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px">
                <?php
                $recs = [
                    ['disetujui',   'Diterima',    '#16a34a'],
                    ['revisi_minor','Revisi Minor', '#ca8a04'],
                    ['revisi_mayor','Revisi Mayor', '#ea580c'],
                    ['ditolak',     'Ditolak',      '#dc2626'],
                ];
                foreach ($recs as [$rv, $rl, $rc]):
                  $pre_keputusan = $pre['keputusan'] ?? '';
                ?>
                <input type="radio" name="keputusan" id="rec-<?= $rv ?>"
                       value="<?= $rv ?>" class="rec-option"
                       <?= $pre_keputusan === $rv ? 'checked' : '' ?>>
                <label for="rec-<?= $rv ?>" class="rec-label">
                  <span style="width:10px;height:10px;border-radius:50%;background:<?= $rc ?>;flex-shrink:0"></span>
                  <span style="color:<?= $rc ?>;font-weight:700"><?= $rl ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
              <button type="submit" name="submit_final" value="1" class="btn btn-primary btn-lg"
                      onclick="return konfirmSubmit()">
                <?= ic('send') ?> <?= $id?'Kirim Penilaian':'Submit Assessment' ?>
              </button>
              <button type="submit" class="btn btn-outline">
                <?= ic('download') ?> <?= $id?'Simpan Draft':'Save Draft' ?>
              </button>
            </div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:8px">
              <?= $id
                ? '⚠ Setelah dikirim, penilaian tidak dapat diubah. Pastikan semua skor dan rekomendasi sudah benar.'
                : '⚠ Once submitted, the assessment cannot be modified. Ensure all scores and recommendation are correct.'
              ?>
            </div>
            <?php else: // Already submitted — read-only mode ?>
            <div style="margin-top:14px;padding:12px 14px;background:var(--bg-field);border-radius:9px;font-size:12.5px">
              <strong><?= $id?'Saran/Catatan: ':'Notes: ' ?></strong>
              <?= $pre['saran'] ? nl2br(htmlspecialchars($pre['saran'])) : '<span style="color:var(--text-muted)">—</span>' ?>
            </div>
            <?php if (!empty($pre['file_review'])): ?>
            <div style="margin-top:10px;padding:11px 14px;background:var(--bg-field);border-radius:9px;font-size:12.5px;display:flex;align-items:center;gap:9px">
              <?= ic('download','style="width:15px;height:15px;color:#0891b2"') ?>
              <strong><?= $id?'File review:':'Review file:' ?></strong>
              <a href="<?= BASE_URL ?>/<?= htmlspecialchars($pre['file_review']) ?>" target="_blank" style="color:var(--primary);font-weight:600">
                <?= htmlspecialchars($pre['file_review_name'] ?? basename($pre['file_review'])) ?>
              </a>
            </div>
            <?php endif; ?>
            <?php $kl = $keputusanLabel($pre['keputusan']??''); ?>
            <div style="margin-top:10px;display:inline-flex;align-items:center;gap:8px;
                        padding:8px 14px;border-radius:10px;background:<?= $kl['bg'] ?>;
                        color:<?= $kl['color'] ?>;font-weight:700;font-size:13px">
              <?= $id?'Rekomendasi: ':'Recommendation: ' ?><?= $kl['label'] ?>
            </div>
            <div style="margin-top:14px;padding:10px 14px;background:#f1f5f9;border-radius:9px;font-size:11.5px;color:#64748b;display:flex;align-items:center;gap:8px">
              <?= ic('lock','style="width:14px;height:14px"') ?>
              <?= $id?'Penilaian terkunci. Anda tetap dapat membuka halaman ini untuk meninjau ulang riwayat skor & catatan.':'Assessment is locked. You may revisit this page to review historical scores & notes.' ?>
            </div>
            <?php endif; ?>

          </div>
        </div>

      </form>

      <?php else: /* ─── Daftar proposal ─── */ ?>

      <!-- ── Panduan Reviewer (#3) — banner prominent di beranda reviewer ── -->
      <?php if (!empty($panduan_list)): ?>
      <div class="panduan-banner">
        <div class="panduan-head">
          <div class="panduan-head-icon">
            <?= ic('shield','style="width:22px;height:22px;color:#fff"') ?>
          </div>
          <div style="flex:1">
            <div style="font-size:15px;font-weight:800;color:#fff;letter-spacing:.2px">
              <?= $id?'Panduan Reviewer':'Reviewer Guidelines' ?>
            </div>
            <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px">
              <?= $id?'Wajib dibaca sebelum menilai proposal':'Required reading before assessing proposals' ?>
            </div>
          </div>
          <span style="background:rgba(255,255,255,.2);color:#fff;padding:4px 11px;border-radius:14px;font-size:11px;font-weight:700">
            <?= count($panduan_list) ?> <?= $id?'dokumen':'documents' ?>
          </span>
        </div>
        <div class="panduan-grid">
          <?php foreach ($panduan_list as $pd):
            $url  = $pd['file_path'] ? BASE_URL . '/' . $pd['file_path'] : ($pd['link_url'] ?: '');
            $isfile = !empty($pd['file_path']);
          ?>
          <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="panduan-item">
            <div class="panduan-item-icon">
              <?= ic($isfile?'file-text':'link','style="width:18px;height:18px;color:#fff"') ?>
            </div>
            <div style="flex:1;min-width:0">
              <div class="panduan-item-title"><?= htmlspecialchars($pd['judul']) ?></div>
              <?php if (!empty($pd['deskripsi'])): ?>
              <div class="panduan-item-desc"><?= htmlspecialchars(mb_strimwidth($pd['deskripsi'],0,90,'…')) ?></div>
              <?php endif; ?>
            </div>
            <?= ic($isfile?'download':'play','style="width:15px;height:15px;color:#fff;opacity:.85;flex-shrink:0"') ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <style>
      .panduan-banner {
        background: linear-gradient(135deg,#4a1d96 0%,#7c3aed 50%,#0891b2 100%);
        border-radius: 16px; padding: 20px; margin-bottom: 22px;
        box-shadow: 0 10px 30px -8px rgba(124,58,237,.45);
      }
      .panduan-head { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
      .panduan-head-icon {
        width:42px; height:42px; border-radius:11px;
        background:rgba(255,255,255,.15); backdrop-filter:blur(8px);
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
        border:1.5px solid rgba(255,255,255,.25);
      }
      .panduan-grid {
        display:grid; gap:9px;
        grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
      }
      .panduan-item {
        display:flex; align-items:center; gap:11px;
        padding:11px 13px; border-radius:11px;
        background:rgba(255,255,255,.12); backdrop-filter:blur(6px);
        border:1px solid rgba(255,255,255,.2);
        text-decoration:none; transition:all .16s;
      }
      .panduan-item:hover {
        background:rgba(255,255,255,.22);
        transform: translateY(-1px);
      }
      .panduan-item-icon {
        width:30px; height:30px; border-radius:8px;
        background:rgba(255,255,255,.18);
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
      }
      .panduan-item-title { font-size:12.5px; font-weight:700; color:#fff; line-height:1.3; }
      .panduan-item-desc { font-size:10.5px; color:rgba(255,255,255,.78); margin-top:2px; line-height:1.4; }
      </style>
      <?php endif; ?>

      <?php if (empty($my_proposals)): ?>
      <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
        <?= ic('clipboard','style="width:40px;height:40px"') ?>
        <div style="font-size:16px;font-weight:700;margin-top:12px">
          <?= $id?'Belum ada proposal yang ditugaskan':'No proposals assigned yet' ?>
        </div>
        <div style="font-size:13px;margin-top:6px">
          <?= $id?'Admin LPPM akan menugaskan proposal untuk Anda review.':'LPPM admin will assign proposals for you to review.' ?>
        </div>
      </div>
      <?php else: ?>

      <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
        <?= count($my_proposals) ?> <?= $id?'proposal ditugaskan':'proposals assigned' ?>
        · <?= count(array_filter($my_proposals, fn($p) => $p['submitted_at'])) ?> <?= $id?'selesai':'completed' ?>
      </div>

      <?php foreach ($my_proposals as $p):
        $kl = $keputusanLabel($p['keputusan_reviewer']??'');
        $dl_ts = !empty($p['deadline_review']) ? strtotime($p['deadline_review']) : 0;
        $sisa  = $dl_ts ? ($dl_ts - time()) : 0;
        $overdue = $dl_ts && $sisa <= 0 && !$p['submitted_at'];
        $urgent  = $dl_ts && $sisa > 0 && $sisa < 86400 * 2 && !$p['submitted_at'];
      ?>
      <div class="prop-card" style="<?= $overdue?'border-color:#fecaca;background:#fef2f2':'' ?>">
        <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#3b82f6);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <?= ic('doc','style="width:20px;height:20px;color:#fff"') ?>
        </div>
        <div class="prop-card-body">
          <div class="prop-card-title"><?= htmlspecialchars($p['judul']) ?></div>
          <div class="prop-card-meta">
            <?= strtoupper($p['skema']) ?> ·
            <?= $id?'Ditugaskan':'Assigned' ?> <?= date('d M Y', strtotime($p['assigned_at'])) ?>
            <?php if ($dl_ts): ?>
              · <span style="color:<?= $overdue?'#dc2626':($urgent?'#a16207':'#475569') ?>;font-weight:600">
                <?= ic($overdue?'alert':'clock','style="width:11px;height:11px;display:inline;vertical-align:-1px"') ?>
                <?= $id?'Deadline':'Deadline' ?> <?= date('d M Y · H:i', $dl_ts) ?>
                <?php if (!$p['submitted_at'] && $overdue): ?> · <?= $id?'TERLEWAT':'OVERDUE' ?><?php endif; ?>
              </span>
            <?php endif; ?>
          </div>
          <?php if (!empty($p['catatan_admin'])): ?>
          <div style="margin-top:6px;padding:6px 10px;background:#f8fafc;border-radius:7px;font-size:11.5px;color:#475569">
            💬 <?= htmlspecialchars(mb_strimwidth($p['catatan_admin'], 0, 140, '…')) ?>
          </div>
          <?php endif; ?>
          <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <?php if ($p['submitted_at']): ?>
            <span style="background:<?= $kl['bg'] ?>;color:<?= $kl['color'] ?>;padding:3px 10px;border-radius:10px;font-size:11.5px;font-weight:700">
              ✓ <?= $kl['label'] ?> — <?= number_format((float)($p['nilai_total']??0),1) ?> poin
            </span>
            <?php else: ?>
            <span style="background:<?= $overdue?'#fee2e2':'#fef9c3' ?>;color:<?= $overdue?'#991b1b':'#92400e' ?>;padding:3px 10px;border-radius:10px;font-size:11.5px;font-weight:600">
              <?= $id?'Belum dinilai':'Pending review' ?>
            </span>
            <?php endif; ?>
            <a href="?pid=<?= $p['id'] ?>" class="btn btn-outline" style="font-size:12px;padding:5px 12px">
              <?= ic($p['submitted_at']?'eye':'edit') ?>
              <?= $p['submitted_at'] ? ($id?'Tinjau Ulang':'Review Again') : ($id?'Nilai':'Review') ?>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const BOBOT    = <?= json_encode($BOBOT) ?>;
const SKOR_MAX = <?= json_encode($SKOR_MAX) ?>;
const N_KRIT   = <?= $N_KRITERIA ?>;

function recalc() {
  let total = 0;
  for (let i = 1; i <= N_KRIT; i++) {
    const checked = document.querySelector(`[name="skor_${i}"]:checked`);
    const skor  = checked ? parseInt(checked.value) : 0;
    const nilai = skor ? (BOBOT[i-1] / SKOR_MAX[i-1] * skor) : 0;
    const td = document.getElementById('val-' + i);
    if (td) td.textContent = skor ? nilai.toFixed(1) : '—';
    total += nilai;
  }
  const tot = document.getElementById('total-nilai');
  if (tot) tot.textContent = total > 0 ? total.toFixed(1) : '—';
}

function konfirmSubmit() {
  const keputusan = document.querySelector('[name="keputusan"]:checked');
  if (!keputusan) {
    alert('<?= $id?'Pilih rekomendasi terlebih dahulu.':'Please select a recommendation first.' ?>');
    return false;
  }
  // Cek semua skor terisi
  for (let i = 1; i <= N_KRIT; i++) {
    if (!document.querySelector(`[name="skor_${i}"]:checked`)) {
      alert('<?= $id?'Semua skor harus diisi sebelum mengirim penilaian.':'All scores must be filled before submitting.' ?>');
      return false;
    }
  }
  return confirm('<?= $id?'Kirim penilaian? Setelah dikirim tidak dapat diubah.':'Submit assessment? It cannot be changed after submission.' ?>');
}

function showReviewFile(input) {
  const f = input.files[0];
  if (f) document.getElementById('review-file-label').textContent = '✓ ' + f.name;
}

document.addEventListener('DOMContentLoaded', recalc);
</script>
</body>
</html>
