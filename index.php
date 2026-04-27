<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');

if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';

// Pengaturan umum PkM
$deadline = getSetting($pdo,'pengabdian_deadline');
$tahun    = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));
$deadline_ts = $deadline ? strtotime($deadline) : 0;
$now_ts      = time();
$deadline_lewat = $deadline_ts && $deadline_ts < $now_ts;
$dl_fmt   = $deadline_ts ? date('d M Y H:i', $deadline_ts) : '-';
$dl_sisa  = $deadline_ts ? max(0, (int)ceil(($deadline_ts - $now_ts) / 86400)) : 0;

// Load skema pengabdian
$skema_rows = [];
try {
    $sq = $pdo->prepare("SELECT * FROM skema_pengabdian WHERE tahun=? ORDER BY urutan ASC, id ASC");
    $sq->execute([$tahun]);
    $skema_rows = $sq->fetchAll();
} catch (\Exception $e) {}

$skemaOpen = function($sk) use ($deadline_lewat, $now_ts) {
    if (!(int)$sk['is_open']) return false;
    if ($deadline_lewat) return false;
    if (!empty($sk['deadline_pengajuan'])) {
        $dl_ts = strtotime($sk['deadline_pengajuan']);
        if ($dl_ts && $dl_ts < $now_ts) return false;
    }
    return true;
};

$ada_buka = false;
foreach ($skema_rows as $sk) { if ($skemaOpen($sk)) { $ada_buka = true; break; } }

// ── Aksi: hapus / restore / hapus permanen ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $pid = (int)($_POST['pid'] ?? 0);

    if ($act === 'delete' && $pid) {
        $pdo->prepare("UPDATE usulan_pengabdian SET deleted_at=NOW() WHERE id=? AND user_id=? AND status='draft' AND deleted_at IS NULL")->execute([$pid, $uid]);
        $_SESSION['flash'] = ['type'=>'info','msg'=>$id?'Draft dipindahkan ke sampah.':'Draft moved to trash.'];
    } elseif ($act === 'restore' && $pid) {
        $pdo->prepare("UPDATE usulan_pengabdian SET deleted_at=NULL WHERE id=? AND user_id=? AND deleted_at IS NOT NULL")->execute([$pid, $uid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Draft berhasil dipulihkan.':'Draft restored successfully.'];
    } elseif ($act === 'perm_delete' && $pid) {
        $row = $pdo->prepare("SELECT file_proposal FROM usulan_pengabdian WHERE id=? AND user_id=? AND deleted_at IS NOT NULL");
        $row->execute([$pid, $uid]);
        $del = $row->fetch();
        if ($del) {
            if (!empty($del['file_proposal']) && file_exists(BASE_PATH.'/'.$del['file_proposal'])) {
                unlink(BASE_PATH.'/'.$del['file_proposal']);
            }
            $pdo->prepare("DELETE FROM usulan_pengabdian WHERE id=? AND user_id=?")->execute([$pid, $uid]);
        }
        $_SESSION['flash'] = ['type'=>'warning','msg'=>$id?'Draft dihapus permanen.':'Draft permanently deleted.'];
    }
    redirect('/modules/pengabdian/index.php');
}

// Auto-purge sampah > 30 hari
if (empty($_SESSION['trash_purged_pkm_'.date('Ymd')])) {
    try {
        $old = $pdo->prepare("SELECT id,file_proposal FROM usulan_pengabdian WHERE user_id=? AND deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $old->execute([$uid]);
        foreach ($old->fetchAll() as $oi) {
            if (!empty($oi['file_proposal']) && file_exists(BASE_PATH.'/'.$oi['file_proposal'])) unlink(BASE_PATH.'/'.$oi['file_proposal']);
            $pdo->prepare("DELETE FROM usulan_pengabdian WHERE id=?")->execute([$oi['id']]);
        }
    } catch (\Exception $e) {}
    $_SESSION['trash_purged_pkm_'.date('Ymd')] = 1;
}

// Proposals PkM milik dosen ini
$proposals = $pdo->prepare("
    SELECT up.*,
           sa.catatan AS catatan_seleksi_admin, sa.keputusan AS keputusan_admin
    FROM usulan_pengabdian up
    LEFT JOIN seleksi_admin_pengabdian sa ON sa.usulan_id = up.id
    WHERE up.user_id=? AND up.deleted_at IS NULL
    ORDER BY up.created_at DESC
");
$proposals->execute([$uid]);
$proposals = $proposals->fetchAll();

// Reviewer penilaian untuk PkM
$rev_map = [];
if (!empty($proposals)) {
    $prop_ids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($prop_ids), '?'));
    try {
        $rq = $pdo->prepare("
            SELECT ra.usulan_id, ra.assigned_at,
                   rp.nilai_total, rp.saran, rp.keputusan, rp.submitted_at
            FROM reviewer_assignment_pengabdian ra
            LEFT JOIN reviewer_penilaian_pengabdian rp ON rp.assignment_id = ra.id AND rp.submitted_at IS NOT NULL
            WHERE ra.usulan_id IN ($ph) AND ra.deleted_at IS NULL
            ORDER BY ra.usulan_id ASC, ra.assigned_at ASC
        ");
        $rq->execute($prop_ids);
        foreach ($rq->fetchAll() as $rv) {
            $rev_map[$rv['usulan_id']][] = $rv;
        }
    } catch (\Exception $e) {}
}

// Kontrak PkM
$kontrak_map = [];
if (!empty($proposals)) {
    $prop_ids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($prop_ids), '?'));
    try {
        $kq = $pdo->prepare("
            SELECT usulan_id, nomor_kontrak, tgl_kontrak, deadline_laporan,
                   file_kontrak, file_kontrak_name, uploaded_at
            FROM kontrak_pengabdian
            WHERE usulan_id IN ($ph) AND (deleted_at IS NULL OR deleted_at IS NULL)
        ");
        $kq->execute($prop_ids);
        foreach ($kq->fetchAll() as $kr) {
            $kontrak_map[$kr['usulan_id']] = $kr;
        }
    } catch (\Exception $e) {}
}

$lulus_threshold = 60; // Default PkM
$jabatan_labels = ['asisten_ahli'=>'Asisten Ahli', 'lektor'=>'Lektor', 'lektor_kepala'=>'Lektor Kepala', 'guru_besar'=>'Guru Besar'];

$trashed = $pdo->prepare("SELECT * FROM usulan_pengabdian WHERE user_id=? AND deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY deleted_at DESC");
$trashed->execute([$uid]);
$trashed = $trashed->fetchAll();

$statusBadge = fn($s) => match($s) {
    'draft'                   => ['bg'=>'#f1f5f9','color'=>'#64748b','label_id'=>'Draft',                'label_en'=>'Draft'],
    'diajukan'                => ['bg'=>'#eff6ff','color'=>'#2563eb','label_id'=>'Diajukan',             'label_en'=>'Submitted'],
    'seleksi_admin'           => ['bg'=>'#fef9c3','color'=>'#b45309','label_id'=>'Seleksi Admin',        'label_en'=>'Admin Review'],
    'lolos_admin'             => ['bg'=>'#ecfdf5','color'=>'#059669','label_id'=>'Lolos Admin',          'label_en'=>'Admin Passed'],
    'gagal_admin'             => ['bg'=>'#fef2f2','color'=>'#dc2626','label_id'=>'Tidak Lolos Admin',    'label_en'=>'Admin Failed'],
    'penandatanganan_kontrak' => ['bg'=>'#fff7ed','color'=>'#c2410c','label_id'=>'Tanda Tangan Kontrak', 'label_en'=>'Contract Signing'],
    'perbaikan_admin'         => ['bg'=>'#faf5ff','color'=>'#7c3aed','label_id'=>'Perbaikan Dikirim',    'label_en'=>'Revision Submitted'],
    'perbaikan_substantif'    => ['bg'=>'#faf5ff','color'=>'#7c3aed','label_id'=>'Perbaikan Dikirim',    'label_en'=>'Revision Submitted'],
    'seleksi_substansi'       => ['bg'=>'#f0f9ff','color'=>'#0369a1','label_id'=>'Seleksi Substantif',   'label_en'=>'Substantive Review'],
    'disetujui'               => ['bg'=>'#f0fdf4','color'=>'#16a34a','label_id'=>'Disetujui',           'label_en'=>'Approved'],
    'revisi_minor'            => ['bg'=>'#fffbeb','color'=>'#d97706','label_id'=>'Revisi Minor',         'label_en'=>'Minor Revision'],
    'revisi_mayor'            => ['bg'=>'#fff7ed','color'=>'#ea580c','label_id'=>'Revisi Mayor',         'label_en'=>'Major Revision'],
    'ditolak'                 => ['bg'=>'#fef2f2','color'=>'#dc2626','label_id'=>'Ditolak',             'label_en'=>'Rejected'],
    'ditinjau'                => ['bg'=>'#fef9c3','color'=>'#ca8a04','label_id'=>'Ditinjau',            'label_en'=>'Under Review'],
    'direvisi'                => ['bg'=>'#fff7ed','color'=>'#ea580c','label_id'=>'Revisi',              'label_en'=>'Revision'],
    default                   => ['bg'=>'#f1f5f9','color'=>'#64748b','label_id'=>$s,                    'label_en'=>$s],
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Usulan Pengabdian':'Community Service Proposals' ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
/* Reusing rp-hero & sk-card from penelitian but customized for PkM */
.rp-hero {
  background: linear-gradient(135deg,#052e16 0%,#15803d 60%,#064e3b 100%);
  border-radius:16px; padding:28px 28px 24px; margin-bottom:24px;
  position:relative; overflow:hidden;
}
.rp-hero::before {
  content:''; position:absolute; inset:0; pointer-events:none;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/svg%3E") repeat;
}
.rp-hero-inner { position:relative; z-index:1; }
.rp-hero-top { display:flex; align-items:center; gap:14px; margin-bottom:12px; }
.rp-hero-ico {
  width:50px; height:50px; border-radius:13px; flex-shrink:0;
  background:rgba(34,197,94,.25); border:1.5px solid rgba(134,239,172,.35);
  display:flex; align-items:center; justify-content:center;
}
.rp-hero-ico svg { color:#86efac; }
.rp-hero-title { font-size:20px; font-weight:800; color:#fff; line-height:1.2; }
.rp-hero-sub { font-size:12px; color:rgba(134,239,172,.75); margin-top:3px; }
.rp-hero-desc { font-size:12.5px; color:rgba(255,255,255,.7); line-height:1.7; max-width:680px; margin-bottom:16px; }

.sk-card { border-radius:16px; overflow:hidden; border:1.5px solid var(--border); background:var(--bg-card); transition:transform .18s, box-shadow .18s; box-shadow:0 1px 6px rgba(0,0,0,.06); text-decoration:none; color:inherit; display:block; cursor:pointer; }
.sk-card.sk-open:hover { transform:translateY(-3px); box-shadow:0 10px 28px rgba(0,0,0,.12); border-color:#86efac; }
.sk-card.sk-closed { opacity:.72; cursor:default; }
.sk-banner { padding:20px 18px 16px; position:relative; overflow:hidden; }
.sk-banner-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.sk-banner-name { font-size:15px; font-weight:800; color:#fff; line-height:1.3; flex:1; }
.sk-banner-sub { font-size:11px; color:rgba(255,255,255,.65); margin-top:4px; }
.sk-stats { display:grid; grid-template-columns:repeat(3,1fr); }
.sk-stat { padding:12px 8px; text-align:center; border-right:1px solid var(--border); }
.sk-stat:last-child { border-right:none; }
.sk-stat-val { font-size:12.5px; font-weight:800; color:var(--text-primary); line-height:1.2; margin-bottom:3px; }
.sk-stat-lbl { font-size:9.5px; color:var(--text-muted); }
.sk-cta { padding:10px 16px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:center; gap:6px; font-size:12px; font-weight:700; }
.sk-cta-open { background:#f0fdf4; color:#15803d; }
.sk-cta-closed { background:#f8fafc; color:#94a3b8; font-weight:600; }

.rp-card { border:1.5px solid var(--border); border-radius:12px; background:var(--bg-card); overflow:hidden; margin-bottom:12px; transition:border-color .15s, box-shadow .15s; }
.rp-card:hover { border-color:#86efac; box-shadow:0 2px 12px rgba(22,163,74,.08); }
.rp-card-head { padding:14px 16px; display:flex; align-items:flex-start; gap:12px; }
.rp-card-ico { width:38px; height:38px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#14532d,#16a34a); }
.rp-card-ico svg { color:#fff; }
.rp-card-body { flex:1; min-width:0; }
.rp-card-title { font-size:13.5px; font-weight:700; color:var(--text-primary); margin-bottom:3px; line-height:1.4; }
.rp-card-meta { font-size:11.5px; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:10px; }
.rp-card-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }

.btn-trash { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:8px; font-size:12px; font-weight:600; border:1px solid #fecaca; background:#fff0f0; color:#dc2626; cursor:pointer; }
.btn-trash:hover { background:#fee2e2; border-color:#f87171; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg class="ic" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <?= $id?'Usulan Pengabdian':'Community Service Proposals' ?>
          <span class="breadcrumb">LPPM IAKN Toraja — <?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:16px"><?= htmlspecialchars($fl['msg']) ?></div>
      <?php endif; ?>

      <!-- ── Hero ── -->
      <div class="rp-hero">
        <div class="rp-hero-inner">
          <div class="rp-hero-top">
            <div class="rp-hero-ico">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
              <div class="rp-hero-title">
                <?= $id?'Penerimaan Proposal Pengabdian Masyarakat':'Community Service Proposal Submission' ?> <?= $tahun ?>
              </div>
              <div class="rp-hero-sub">
                <?= $id?'Program Hibah Internal LPPM':'LPPM Internal Grant Program' ?>
              </div>
            </div>
          </div>
          <div class="rp-hero-desc">
            <?= $id
              ? 'LPPM IAKN Toraja membuka penerimaan proposal Pengabdian kepada Masyarakat (PkM) tahun anggaran '.$tahun.'. Tingkatkan tridharma perguruan tinggi melalui program pengabdian yang berdampak bagi masyarakat luas.'
              : 'LPPM IAKN Toraja is accepting Community Service (PkM) proposals for the '.$tahun.' budget year. Enhance the tri-dharma of higher education through impactful community service programs.' ?>
          </div>
        </div>
      </div>

      <!-- ── Skema ── -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:28px">
        <?php if (!empty($skema_rows)): ?>
        <?php foreach ($skema_rows as $i => $sk):
          $c1 = '#064e3b'; $c2 = '#15803d'; // Green theme for PkM
          $jab_lbl = $jabatan_labels[$sk['jabatan_min']] ?? ucwords(str_replace('_',' ',$sk['jabatan_min']));
        ?>
        <a class="sk-card <?= $skemaOpen($sk)?'sk-open':'sk-closed' ?>"
           href="<?= BASE_URL ?>/modules/pengabdian/ajukan.php?skema=<?= urlencode($sk['kode']) ?>">
          <div class="sk-banner" style="background:linear-gradient(135deg,<?= $c1 ?> 0%,<?= $c2 ?> 100%)">
            <div class="sk-banner-row">
              <div>
                <div class="sk-banner-name"><?= htmlspecialchars($sk['nama']) ?></div>
                <?php if ($sk['target_luaran']): ?>
                <div class="sk-banner-sub"><?= htmlspecialchars($sk['target_luaran']) ?></div>
                <?php endif; ?>
              </div>
              <span style="display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:4px 11px;font-size:10.5px;font-weight:700;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff">
                <?= $skemaOpen($sk)?($id?'Dibuka':'Open'):($id?'Ditutup':'Closed') ?>
              </span>
            </div>
          </div>
          <div class="sk-stats">
            <div class="sk-stat">
              <div class="sk-stat-val" style="font-size:11px">Rp <?= number_format((int)$sk['anggaran_total'], 0, ',', '.') ?></div>
              <div class="sk-stat-lbl"><?= $id?'Max Anggaran':'Max Budget' ?></div>
            </div>
            <div class="sk-stat">
              <div class="sk-stat-val"><?= (int)$sk['kuota'] ?></div>
              <div class="sk-stat-lbl"><?= $id?'Kuota':'Quota' ?></div>
            </div>
            <div class="sk-stat">
              <div class="sk-stat-val" style="font-size:11px" title="<?= htmlspecialchars($jab_lbl) ?>">
                <?= htmlspecialchars(mb_strimwidth($jab_lbl,0,14,'…')) ?>
              </div>
              <div class="sk-stat-lbl"><?= $id?'Min. Jafung':'Min. Rank' ?></div>
            </div>
          </div>
          <div class="sk-cta <?= $skemaOpen($sk)?'sk-cta-open':'sk-cta-closed' ?>">
            <?= $skemaOpen($sk)?($id?'Ajukan proposal pada skema ini':'Submit proposal for this scheme'):($id?'Pendaftaran ditutup':'Registration closed') ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="grid-column:1/-1;text-align:center;padding:30px;border:1.5px dashed var(--border);border-radius:12px;color:var(--text-muted)">
            <?= $id?'Skema pengabdian belum dikonfigurasi oleh Admin LPPM.':'Community service schemes have not been configured by LPPM Admin.' ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Tombol ajukan & Header list ── -->
      <?php $lewat_deadline = $deadline_ts && strtotime($deadline) < time(); ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary)">
          <?= $id?'Proposal PkM Saya':'My PkM Proposals' ?>
          <span style="font-size:12px;font-weight:500;color:var(--text-muted);margin-left:4px">
            (<?= count($proposals) ?> <?= $id?'total':'total' ?>)
          </span>
        </div>
        <?php if ($ada_buka && !$lewat_deadline): ?>
        <a href="<?= BASE_URL ?>/modules/pengabdian/ajukan.php"
           class="btn btn-primary" style="background:#16a34a;display:inline-flex;align-items:center;gap:7px;font-size:13px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <?= $id?'Ajukan Proposal Baru':'Submit New Proposal' ?>
        </a>
        <?php elseif ($lewat_deadline): ?>
        <span style="font-size:12px;color:#dc2626;font-weight:600;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:6px 12px">
          <?= $id?'Pendaftaran telah berakhir':'Registration has ended' ?>
        </span>
        <?php endif; ?>
      </div>

      <!-- ── List proposals ── -->
      <?php if (empty($proposals)): ?>
      <div style="background:var(--bg-card);border:1.5px dashed var(--border);border-radius:14px;padding:48px 24px;text-align:center">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);display:block;margin:0 auto 14px;opacity:.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <div style="font-size:14px;font-weight:600;color:var(--text-muted);margin-bottom:6px"><?= $id?'Belum ada proposal PkM':'No PkM proposals yet' ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= $id?'Klik tombol "Ajukan Proposal Baru" untuk memulai.':'Click "Submit New Proposal" to get started.' ?></div>
      </div>
      <?php else: ?>
      <?php
      $skema_map = [];
      foreach ($skema_rows as $sk) { $skema_map[$sk['kode']] = $sk['nama']; }
      ?>
      <?php foreach ($proposals as $p):
        $b   = $statusBadge($p['status']);
        $lbl = $id ? $b['label_id'] : $b['label_en'];
        $skm = $skema_map[$p['skema']] ?? ucfirst($p['skema']);
      ?>
      <div class="rp-card">
        <div class="rp-card-head">
          <div class="rp-card-ico">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          </div>
          <div class="rp-card-body">
            <div class="rp-card-title"><?= htmlspecialchars($p['judul']) ?></div>
            <div class="rp-card-meta">
              <span><?= $id?'Skema':'Scheme' ?>: <strong><?= htmlspecialchars($skm) ?></strong></span>
              <span><?= $id?'Tahun':'Year' ?>: <?= $p['tahun_anggaran'] ?></span>
              <span><?= $id?'Diajukan':'Submitted' ?>: <?= date('d M Y', strtotime($p['created_at'])) ?></span>
            </div>
          </div>
          <div class="rp-card-right">
            <?php if ($p['status'] === 'draft'): ?>
            <a href="<?= BASE_URL ?>/modules/pengabdian/ajukan.php?edit=<?= $p['id'] ?>" class="btn btn-outline" style="font-size:12px;padding:6px 12px">
              <?= ic('edit') ?> <?= $id?'Edit':'Edit' ?>
            </a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Yakin pindahkan draft ke sampah?');">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="pid" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-trash">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </form>
            <?php endif; ?>
            <span style="background:<?= $b['bg'] ?>;color:<?= $b['color'] ?>;border:1px solid <?= $b['color'] ?>30;font-size:11px;padding:4px 10px;border-radius:8px;font-weight:600;white-space:nowrap">
              <?= $lbl ?>
            </span>
          </div>
        </div>
        
        <?php // Tampilkan Kontrak jika disetujui (Sama persis seperti penelitian) ?>
        <?php $kp = $kontrak_map[$p['id']] ?? null; ?>
        <?php if ($kp && in_array($p['status'], ['disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai'], true)): ?>
        <div style="border-top:1px solid var(--border);padding:14px 16px;background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%)">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#166534,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= ic('doc','style="width:15px;height:15px;color:#fff"') ?>
            </div>
            <div style="flex:1">
              <div style="font-size:12.5px;font-weight:700;color:#166534"><?= $id?'Kontrak Pengabdian':'Service Contract' ?></div>
              <div style="font-size:11px;color:#15803d">
                <?= $kp['nomor_kontrak'] ? htmlspecialchars($kp['nomor_kontrak']) : ($id?'(nomor belum diisi)':'(no number yet)') ?>
              </div>
            </div>
          </div>
          <?php if (!empty($kp['file_kontrak'])): ?>
          <a href="<?= BASE_URL ?>/<?= htmlspecialchars($kp['file_kontrak']) ?>" target="_blank" class="btn btn-primary" style="font-size:11.5px;padding:6px 12px;background:#15803d">
            <?= ic('download','style="width:12px;height:12px"') ?> <?= $id?'Unduh Kontrak':'Download Contract' ?>
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <!-- ── Sampah ── -->
      <?php if (!empty($trashed)): ?>
      <div style="margin-top:32px; border-top:2px dashed #e2e8f0; padding-top:20px;">
        <div style="font-size:13px; font-weight:700; color:#64748b; margin-bottom:14px;">
          <?= ic('trash','style="width:14px;height:14px;vertical-align:-2px"') ?> <?= $id?'Sampah Draft':'Draft Trash' ?>
        </div>
        <?php foreach ($trashed as $t): ?>
        <div style="border:1.5px solid #fee2e2; border-radius:12px; background:#fffafa; padding:12px 16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
          <div style="font-size:13px; color:#64748b; font-weight:600"><?= htmlspecialchars($t['judul'] ?: '(tanpa judul)') ?></div>
          <div style="display:flex; gap:8px">
            <form method="POST" style="display:inline"><input type="hidden" name="act" value="restore"><input type="hidden" name="pid" value="<?= $t['id'] ?>"><button type="submit" class="btn btn-outline" style="font-size:11px;padding:4px 8px;color:#16a34a;border-color:#bbf7d0"><?= $id?'Pulihkan':'Restore' ?></button></form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Hapus permanen?');"><input type="hidden" name="act" value="perm_delete"><input type="hidden" name="pid" value="<?= $t['id'] ?>"><button type="submit" class="btn-trash" style="padding:4px 8px;font-size:11px"><?= $id?'Hapus Permanen':'Perm. Delete' ?></button></form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}</script>
</body>
</html>