<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');

// Khusus dosen
if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';

// Pengaturan umum
$deadline = getSetting($pdo,'pengabdian_deadline') ?: '2026-05-18 16:00:00';
$tahun    = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));
$deadline_ts = strtotime($deadline);
$now_ts      = time();
$deadline_lewat = $deadline_ts && $deadline_ts < $now_ts;
$dl_fmt   = $deadline_ts ? date('d M Y H:i', $deadline_ts) : '-';
$dl_sisa  = max(0, (int)ceil(($deadline_ts - $now_ts) / 86400));

// Load skema dari DB
$skema_rows = [];
try {
    $sq = $pdo->prepare("SELECT * FROM skema_pengabdian WHERE tahun=? ORDER BY urutan ASC, id ASC");
    $sq->execute([$tahun]);
    $skema_rows = $sq->fetchAll();
} catch (\Exception $e) {}

// Helper: status buka skema (gabungan is_open + deadline per-skema + deadline global)
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
// Fallback: jika tabel belum ada, compat dengan pengaturan lama
if (empty($skema_rows)) {
    $gl_buka = (getSetting($pdo,'pengabdian_global_buka') ?: 'buka') === 'buka';
    $ns_buka = (getSetting($pdo,'pengabdian_nasional_buka') ?: 'buka') === 'buka';
    $ada_buka = $gl_buka || $ns_buka;
}

// ── Aksi: hapus / restore / hapus permanen ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $pid = (int)($_POST['pid'] ?? 0);

    if ($act === 'delete' && $pid) {
        // Soft-delete: hanya draft milik user ini
        $pdo->prepare("
            UPDATE usulan_pengabdian
            SET deleted_at=NOW()
            WHERE id=? AND user_id=? AND status='draft' AND deleted_at IS NULL
        ")->execute([$pid, $uid]);
        $_SESSION['flash'] = ['type'=>'info','msg'=>$id?'Draft dipindahkan ke sampah.':'Draft moved to trash.'];
    } elseif ($act === 'restore' && $pid) {
        $pdo->prepare("
            UPDATE usulan_pengabdian
            SET deleted_at=NULL
            WHERE id=? AND user_id=? AND deleted_at IS NOT NULL
        ")->execute([$pid, $uid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Draft berhasil dipulihkan.':'Draft restored successfully.'];
    } elseif ($act === 'perm_delete' && $pid) {
        // Hapus permanen hanya dari sampah
        $row = $pdo->prepare("SELECT file_proposal,file_cek_mandiri FROM usulan_pengabdian WHERE id=? AND user_id=? AND deleted_at IS NOT NULL");
        $row->execute([$pid, $uid]);
        $del = $row->fetch();
        if ($del) {
            foreach (['file_proposal','file_cek_mandiri'] as $col) {
                if (!empty($del[$col]) && file_exists(BASE_PATH.'/'.$del[$col])) {
                    unlink(BASE_PATH.'/'.$del[$col]);
                }
            }
            $pdo->prepare("DELETE FROM usulan_pengabdian WHERE id=? AND user_id=?")->execute([$pid, $uid]);
        }
        $_SESSION['flash'] = ['type'=>'warning','msg'=>$id?'Draft dihapus permanen.':'Draft permanently deleted.'];
    }
    redirect('/modules/pengabdian/index.php');
}

// Auto-purge sampah > 30 hari (jalankan sekali per sesi)
if (empty($_SESSION['trash_purged_'.date('Ymd')])) {
    try {
        $old = $pdo->prepare("SELECT id,file_proposal,file_cek_mandiri FROM usulan_pengabdian WHERE user_id=? AND deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $old->execute([$uid]);
        foreach ($old->fetchAll() as $oi) {
            foreach (['file_proposal','file_cek_mandiri'] as $col) {
                if (!empty($oi[$col]) && file_exists(BASE_PATH.'/'.$oi[$col])) unlink(BASE_PATH.'/'.$oi[$col]);
            }
            $pdo->prepare("DELETE FROM usulan_pengabdian WHERE id=?")->execute([$oi['id']]);
        }
    } catch (\Exception $e) {}
    $_SESSION['trash_purged_'.date('Ymd')] = 1;
}

// Proposals milik dosen ini
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

// ── Reviewer penilaian untuk proposal milik dosen ini (double-blind) ─
// Diambil sekali untuk semua proposal, diurutkan per usulan + waktu penugasan
$rev_map = [];
if (!empty($proposals)) {
    $prop_ids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($prop_ids), '?'));
    try {
        $rq = $pdo->prepare("
            SELECT ra.usulan_id, ra.assigned_at,
                   rp.nilai_total, rp.saran, rp.keputusan, rp.submitted_at
            FROM reviewer_assignment_pengabdian ra
            LEFT JOIN reviewer_penilaian_pengabdian rp
                   ON rp.assignment_id = ra.id AND rp.submitted_at IS NOT NULL
            WHERE ra.usulan_id IN ($ph) AND ra.deleted_at IS NULL
            ORDER BY ra.usulan_id ASC, ra.assigned_at ASC
        ");
        $rq->execute($prop_ids);
        foreach ($rq->fetchAll() as $rv) {
            $rev_map[$rv['usulan_id']][] = $rv;
        }
    } catch (\Exception $e) {}
}

// ── Kontrak pengabdian milik dosen ini (#4) ─────────────────────
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

// Rubrik untuk tampilan akumulasi nilai
$rubrik_raw = getSetting($pdo, 'reviewer_rubrik') ?: null;
$RUBRIK_IDX = [];
if ($rubrik_raw) {
    $dec = json_decode($rubrik_raw, true);
    if (is_array($dec) && count($dec)) $RUBRIK_IDX = $dec;
}
if (empty($RUBRIK_IDX)) {
    $RUBRIK_IDX = [
        ['kriteria'=>'Perumusan Masalah','bobot'=>20,'skor_max'=>5],
        ['kriteria'=>'Manfaat Hasil Pengabdian','bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Tinjauan Pustaka','bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Landasan Teori','bobot'=>20,'skor_max'=>5],
        ['kriteria'=>'Metode Pengabdian','bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Output dan Outcome Pengabdian','bobot'=>15,'skor_max'=>5],
    ];
}
$MAX_NILAI = array_sum(array_column($RUBRIK_IDX, 'bobot')); // = 100

// ── Riwayat revisi ────────────────────────────────────────────
$revisi_map = [];
if (!empty($proposals)) {
    $prop_ids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($prop_ids), '?'));
    try {
        $rvq = $pdo->prepare("
            SELECT id, usulan_id, tipe, round_ke, ringkasan,
                   file_proposal_name, keputusan, admin_catatan, created_at, keputusan_at
            FROM revisi_proposal_pengabdian WHERE usulan_id IN ($ph)
            ORDER BY usulan_id ASC, created_at ASC
        ");
        $rvq->execute($prop_ids);
        foreach ($rvq->fetchAll() as $rv) {
            $revisi_map[$rv['usulan_id']][] = $rv;
        }
    } catch (\Exception $e) {}
}

// Lulus threshold (ambil dari pengaturan, default 60)
$lulus_threshold = (int)(getSetting($pdo,'reviewer_lulus_threshold') ?: 60);

// Sampah: draft yang dihapus dalam 30 hari terakhir
$trashed = $pdo->prepare("
    SELECT * FROM usulan_pengabdian
    WHERE user_id=? AND deleted_at IS NOT NULL
      AND deleted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY deleted_at DESC
");
$trashed->execute([$uid]);
$trashed = $trashed->fetchAll();

$statusBadge = fn($s) => match($s) {
    'draft'                   => ['bg'=>'#f1f5f9','color'=>'#64748b','label_id'=>'Draft',                'label_en'=>'Draft'],
    'diajukan'                => ['bg'=>'#eff6ff','color'=>'#2563eb','label_id'=>'Diajukan',             'label_en'=>'Submitted'],
    'seleksi_admin_pengabdian'           => ['bg'=>'#fef9c3','color'=>'#b45309','label_id'=>'Seleksi Admin',        'label_en'=>'Admin Review'],
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
<title><?= $id?'Usulan Pengabdian':'Research Proposals' ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
html { scroll-behavior: smooth; }

/* Hero */
.rp-hero {
  background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 60%,#0c1a3d 100%);
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
  background:rgba(59,130,246,.25); border:1.5px solid rgba(147,197,253,.35);
  display:flex; align-items:center; justify-content:center;
}
.rp-hero-ico svg { color:#93c5fd; }
.rp-hero-title { font-size:20px; font-weight:800; color:#fff; line-height:1.2; }
.rp-hero-sub { font-size:12px; color:rgba(147,197,253,.75); margin-top:3px; }
.rp-hero-desc { font-size:12.5px; color:rgba(255,255,255,.65); line-height:1.7; max-width:680px; margin-bottom:16px; }
.rp-hero-pills { display:flex; flex-wrap:wrap; gap:8px; }
.rp-hero-pill {
  display:inline-flex; align-items:center; gap:5px;
  background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18);
  border-radius:20px; padding:4px 12px;
  font-size:11px; font-weight:600; color:rgba(255,255,255,.85);
}
.rp-hero-pill.deadline { background:rgba(239,68,68,.18); border-color:rgba(239,68,68,.35); color:#fca5a5; }
.rp-hero-pill.open   { background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.35); color:#86efac; }
.rp-hero-pill.closed { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.25); color:#fca5a5; }

/* ── Modern Scheme Cards ───────────────────────────── */
.scheme-grid {
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:20px;
  margin-bottom:28px;
}
@media(max-width:600px){ .scheme-grid { grid-template-columns:1fr; } }

.sk-card {
  border-radius:16px; overflow:hidden;
  border:1.5px solid var(--border);
  background:var(--bg-card);
  transition:transform .18s cubic-bezier(.22,1,.36,1), box-shadow .18s, border-color .18s;
  box-shadow:0 1px 6px rgba(0,0,0,.06);
  text-decoration:none; color:inherit; display:block; cursor:pointer;
}
.sk-card.sk-open:hover  { transform:translateY(-3px); box-shadow:0 10px 28px rgba(0,0,0,.12); border-color:var(--primary-light); }
.sk-card.sk-closed      { opacity:.72; cursor:default; }
.sk-card.sk-closed:hover { transform:none; box-shadow:0 1px 6px rgba(0,0,0,.06); }

/* CTA row at card bottom */
.sk-cta {
  padding:10px 16px; border-top:1px solid var(--border);
  display:flex; align-items:center; justify-content:center; gap:6px;
  font-size:12px; font-weight:700;
}
.sk-cta-open   { background:#eff6ff; color:#1d4ed8; }
.sk-cta-closed { background:#f8fafc; color:#94a3b8; font-weight:600; }

/* Coloured banner at top */
.sk-banner { padding:20px 18px 16px; position:relative; overflow:hidden; }
.sk-banner::before {
  content:''; position:absolute; border-radius:50%; pointer-events:none;
  width:110px; height:110px; top:-35px; right:-25px;
  background:rgba(255,255,255,.09);
}
.sk-banner::after {
  content:''; position:absolute; border-radius:50%; pointer-events:none;
  width:65px; height:65px; bottom:-18px; right:38px;
  background:rgba(255,255,255,.06);
}
.sk-banner-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.sk-banner-name { font-size:15px; font-weight:800; color:#fff; line-height:1.3; flex:1; }
.sk-banner-sub  { font-size:11px; color:rgba(255,255,255,.65); margin-top:4px; }

.sk-status-pill {
  display:inline-flex; align-items:center; gap:4px;
  border-radius:20px; padding:4px 11px;
  font-size:10.5px; font-weight:700; white-space:nowrap; flex-shrink:0;
}
.sk-pill-open   { background:rgba(34,197,94,.22);  border:1px solid rgba(34,197,94,.45);  color:#bbf7d0; }
.sk-pill-closed { background:rgba(239,68,68,.18);  border:1px solid rgba(239,68,68,.38);  color:#fca5a5; }

/* Stats row — budget / kuota / jafung */
.sk-stats { display:grid; grid-template-columns:repeat(3,1fr); }
.sk-stat  {
  padding:12px 8px; text-align:center;
  border-right:1px solid var(--border);
}
.sk-stat:last-child { border-right:none; }
.sk-stat-val { font-size:12.5px; font-weight:800; color:var(--text-primary); line-height:1.2; margin-bottom:3px; }
.sk-stat-lbl { font-size:9.5px; color:var(--text-muted); }

/* Checks row — similarity + AI */
.sk-checks { display:grid; grid-template-columns:1fr 1fr; background:var(--bg-field); }
.sk-check  {
  padding:10px 13px; display:flex; align-items:center; gap:9px;
  border-top:1px solid var(--border);
}
.sk-check:first-child { border-right:1px solid var(--border); }
.sk-check-ico {
  width:30px; height:30px; border-radius:8px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.sk-check-val { font-size:13px; font-weight:800; color:var(--text-primary); line-height:1.2; }
.sk-check-lbl { font-size:9.5px; color:var(--text-muted); }

/* Members row */
.sk-members { display:grid; grid-template-columns:1fr 1fr; }
.sk-member  { padding:10px 13px; border-top:1px solid var(--border); }
.sk-member:first-child { border-right:1px solid var(--border); }
.sk-member-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:3px; }
.sk-member-lbl  { font-size:10px; color:var(--text-muted); font-weight:600; }
.sk-member-val  { font-size:13px; font-weight:800; color:var(--text-primary); }
.sk-member-unit { font-size:10px; font-weight:400; color:var(--text-muted); }
.sk-mbadge { font-size:9.5px; font-weight:700; border-radius:4px; padding:1px 6px; }

/* Proposal list */
.rp-card {
  border:1.5px solid var(--border); border-radius:12px;
  background:var(--bg-card); overflow:hidden; margin-bottom:12px;
  transition:border-color .15s, box-shadow .15s;
}
.rp-card:hover { border-color:var(--primary-light); box-shadow:0 2px 12px rgba(79,70,229,.08); }
.rp-card-head {
  padding:14px 16px; display:flex; align-items:flex-start;
  gap:12px; cursor:pointer;
}
.rp-card-ico {
  width:38px; height:38px; border-radius:9px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.rp-card-ico.global   { background:linear-gradient(135deg,#1e3a8a,#3b82f6); }
.rp-card-ico.nasional { background:linear-gradient(135deg,#14532d,#16a34a); }
.rp-card-ico svg { color:#fff; }
.rp-card-body { flex:1; min-width:0; }
.rp-card-title { font-size:13.5px; font-weight:700; color:var(--text-primary); margin-bottom:3px; line-height:1.4; }
.rp-card-meta  { font-size:11.5px; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:10px; }
.rp-card-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.rp-note {
  padding:10px 16px 12px 66px; font-size:12px; color:var(--text-muted);
  background:#fffbeb; border-top:1px solid #fef3c7; line-height:1.6;
}
.rp-note.disetujui { background:#f0fdf4; border-color:#dcfce7; color:#166534; }
.rp-note.ditolak   { background:#fef2f2; border-color:#fee2e2; color:#991b1b; }
.rp-note.direvisi  { background:#fff7ed; border-color:#fed7aa; color:#9a3412; }

/* ── Revision button ── */
.btn-revisi {
  display:inline-flex;align-items:center;gap:6px;
  padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;
  background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;
  border:none;cursor:pointer;text-decoration:none;
  transition:opacity .15s, transform .15s;white-space:nowrap;
}
.btn-revisi:hover { opacity:.88; transform:translateY(-1px); }
.btn-revisi.minor { background:linear-gradient(135deg,#92400e,#d97706); }
.btn-revisi.mayor { background:linear-gradient(135deg,#7c2d12,#ea580c); }

/* ── Revision history ── */
.rv-hist {
  border-top:1px solid var(--border);
  padding:11px 16px;background:#fafbff;
}
.rv-hist-title { font-size:11px;font-weight:700;color:var(--text-muted);
                 text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px; }
.rv-timeline { position:relative;padding-left:20px; }
.rv-timeline::before { content:'';position:absolute;left:6px;top:0;bottom:0;
                        width:1.5px;background:var(--border); }
.rv-tl-item { position:relative;margin-bottom:8px;font-size:12px; }
.rv-tl-item:last-child { margin-bottom:0; }
.rv-tl-dot {
  position:absolute;left:-14px;top:3px;
  width:10px;height:10px;border-radius:50%;border:2px solid;flex-shrink:0;
}
.rv-tl-dot.pending  { background:#f5f3ff;border-color:#7c3aed; }
.rv-tl-dot.diterima { background:#dcfce7;border-color:#16a34a; }
.rv-tl-dot.dikembalikan { background:#fef2f2;border-color:#dc2626; }
.rv-tl-head { font-weight:600;color:var(--text-primary);margin-bottom:2px; }
.rv-tl-meta { color:var(--text-muted);font-size:11px; }
.rv-tl-badge {
  display:inline-flex;align-items:center;padding:1px 7px;border-radius:4px;
  font-size:10px;font-weight:700;margin-left:5px;
}

/* ── Reviewer tracking panel ── */
.rev-panel {
  border-top:1px solid var(--border);
  padding:14px 16px;
  background:#fafbff;
}
.rev-panel-title {
  font-size:11.5px;font-weight:700;color:#4f46e5;
  display:flex;align-items:center;gap:6px;margin-bottom:10px;
  text-transform:uppercase;letter-spacing:.04em;
}
.rev-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
@media(max-width:560px){ .rev-grid { grid-template-columns:1fr; } }
.rev-card {
  border:1.5px solid var(--border);border-radius:10px;
  background:#fff;overflow:hidden;
}
.rev-card-head {
  padding:8px 12px;background:var(--bg-field);
  display:flex;align-items:center;justify-content:space-between;
  border-bottom:1px solid var(--border);
}
.rev-card-label { font-size:11px;font-weight:700;color:var(--text-muted); }
.rev-card-score {
  font-size:18px;font-weight:900;color:var(--primary);
  font-family:monospace;
}
.rev-card-body { padding:10px 12px; }
.rev-rec-badge {
  display:inline-flex;align-items:center;gap:4px;
  border-radius:6px;padding:3px 9px;font-size:11px;font-weight:700;
}
.rev-note-text { font-size:12px;color:var(--text-secondary);line-height:1.6;margin-top:6px; }
.rev-avg-row {
  display:flex;align-items:center;justify-content:space-between;
  background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;
  padding:10px 14px;margin-top:4px;
}
.rev-avg-label { font-size:12px;font-weight:600;color:#1e40af; }
.rev-avg-val   { font-size:22px;font-weight:900;color:#1e3a8a;font-family:monospace; }
.rev-bar-wrap  { height:6px;background:#e2e8f0;border-radius:4px;margin-top:6px;overflow:hidden; }
.rev-bar-fill  { height:100%;border-radius:4px;transition:width .4s; }
.rev-pending {
  font-size:12px;color:var(--text-muted);font-style:italic;
  padding:8px 0;
}
.rev-status-final {
  margin-top:8px;padding:8px 12px;border-radius:8px;font-size:12.5px;font-weight:700;
  display:flex;align-items:center;gap:7px;
}

/* Delete button */
.btn-trash {
  display:inline-flex; align-items:center; justify-content:center; gap:5px;
  padding:5px 10px; border-radius:8px; font-size:12px; font-weight:600;
  border:1px solid #fecaca; background:#fff0f0; color:#dc2626; cursor:pointer;
  transition:background .13s, border-color .13s;
}
.btn-trash:hover { background:#fee2e2; border-color:#f87171; }

/* Trash section */
.trash-section {
  margin-top:32px; border-top:2px dashed #e2e8f0; padding-top:20px;
}
.trash-header {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:14px; flex-wrap:wrap; gap:8px;
}
.trash-title {
  display:flex; align-items:center; gap:8px;
  font-size:13px; font-weight:700; color:#64748b; cursor:pointer;
}
.trash-card {
  border:1.5px solid #fee2e2; border-radius:12px;
  background:#fffafa; overflow:hidden; margin-bottom:10px;
  opacity:.85;
}
.trash-card-head {
  padding:12px 16px; display:flex; align-items:flex-start;
  gap:12px;
}
.trash-expiry {
  font-size:10.5px; font-weight:600; color:#ef4444;
  background:#fef2f2; border:1px solid #fecaca;
  border-radius:6px; padding:2px 8px; white-space:nowrap;
}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg class="ic" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
          <?= $id?'Usulan Pengabdian':'Research Proposals' ?>
          <span class="breadcrumb">LPPM IAKN Toraja — <?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- ── Hero ── -->
      <div class="rp-hero">
        <div class="rp-hero-inner">
          <div class="rp-hero-top">
            <div class="rp-hero-ico">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
              </svg>
            </div>
            <div>
              <div class="rp-hero-title">
                <?= $id?'Penerimaan Proposal Pengabdian':'Research Proposal Submission' ?> <?= $tahun ?>
              </div>
              <div class="rp-hero-sub">
                <?= $id?'Pengumuman No. 20/LPPM/IAKN-T/IV/2026':'Announcement No. 20/LPPM/IAKN-T/IV/2026' ?>
              </div>
            </div>
          </div>
          <div class="rp-hero-desc">
            <?= $id
              ? 'LPPM IAKN Toraja membuka penerimaan proposal pengabdian tahun anggaran 2026. Tersedia dua skema riset: Publikasi Bereputasi Global (Scopus Q1-3) dan Publikasi Bereputasi Nasional (SINTA 1-6).'
              : 'LPPM IAKN Toraja is accepting research proposals for the 2026 budget year. Two research schemes are available: Global Reputation Publication (Scopus Q1-3) and National Reputation Publication (SINTA 1-6).' ?>
          </div>
          <div class="rp-hero-pills">
            <span class="rp-hero-pill deadline">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <?= $id?'Batas':'Deadline' ?>: <?= $dl_fmt ?> WITA
            </span>
            <?php if ($dl_sisa > 0): ?>
            <span class="rp-hero-pill">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?= $dl_sisa ?> <?= $id?'hari lagi':'days left' ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($skema_rows)): ?>
            <?php foreach ($skema_rows as $sk): ?>
            <span class="rp-hero-pill <?= $skemaOpen($sk)?'open':'closed' ?>">
              <?= htmlspecialchars(mb_strimwidth($sk['nama'], 0, 20, '…')) ?>:
              <?= $skemaOpen($sk)?($id?'Buka':'Open'):($id?'Tutup':'Closed') ?>
            </span>
            <?php endforeach; ?>
            <?php else: ?>
            <span class="rp-hero-pill <?= ($gl_buka??false)?'open':'closed' ?>">
              Global: <?= ($gl_buka??false)?($id?'Buka':'Open'):($id?'Tutup':'Closed') ?>
            </span>
            <span class="rp-hero-pill <?= ($ns_buka??false)?'open':'closed' ?>">
              Nasional: <?= ($ns_buka??false)?($id?'Buka':'Open'):($id?'Tutup':'Closed') ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ── Skema ── -->
      <?php
      $jabatan_labels = [
          'asisten_ahli'  => 'Asisten Ahli',
          'lektor'        => 'Lektor',
          'lektor_kepala' => 'Lektor Kepala',
          'guru_besar'    => 'Guru Besar',
      ];
      $sc_colors = [
          ['#1e3a8a','#2563eb'],
          ['#14532d','#16a34a'],
          ['#7c2d12','#c2410c'],
          ['#4a1d96','#7c3aed'],
          ['#0c4a6e','#0ea5e9'],
      ];
      $fmt_short = function(int $n): string {
          if ($n >= 1000000) {
              $v = rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',');
              return 'Rp&nbsp;' . $v . 'jt';
          }
          if ($n >= 1000) return 'Rp&nbsp;' . number_format($n / 1000, 0, ',', '.') . 'rb';
          return 'Rp&nbsp;' . $n;
      };
      ?>
      <div class="scheme-grid">
        <?php if (!empty($skema_rows)): ?>
        <?php foreach ($skema_rows as $i => $sk):
          [$c1, $c2] = $sc_colors[$i % count($sc_colors)];
          $sk_min_d = (int)$sk['min_anggota_dosen'];
          $sk_max_d = $sk['max_anggota_dosen'] !== null ? (int)$sk['max_anggota_dosen'] : null;
          $sk_d_wjb = (bool)$sk['anggota_dosen_wajib'];
          $sk_min_m = (int)$sk['min_anggota_mahasiswa'];
          $sk_max_m = $sk['max_anggota_mahasiswa'] !== null ? (int)$sk['max_anggota_mahasiswa'] : null;
          $sk_m_wjb = (bool)$sk['anggota_mahasiswa_wajib'];
          $jab_lbl  = $jabatan_labels[$sk['jabatan_min']] ?? ucwords(str_replace('_',' ',$sk['jabatan_min']));
          $jab_short = mb_strimwidth($jab_lbl, 0, 14, '…');
        ?>
        <a class="sk-card <?= $skemaOpen($sk)?'sk-open':'sk-closed' ?>"
           href="javascript:void(0)"
           <?= $skemaOpen($sk) ? 'onclick="openCreateModal(\''.htmlspecialchars($sk['kode'],ENT_QUOTES).'\', \''.htmlspecialchars(addslashes($sk['nama']),ENT_QUOTES).'\')"' : '' ?>>
          <!-- Banner -->
          <div class="sk-banner" style="background:linear-gradient(135deg,<?= $c1 ?> 0%,<?= $c2 ?> 100%)">
            <div class="sk-banner-row">
              <div>
                <div class="sk-banner-name"><?= htmlspecialchars($sk['nama']) ?></div>
                <?php if ($sk['target_publikasi']): ?>
                <div class="sk-banner-sub"><?= htmlspecialchars($sk['target_publikasi']) ?></div>
                <?php endif; ?>
              </div>
              <span class="sk-status-pill <?= $skemaOpen($sk)?'sk-pill-open':'sk-pill-closed' ?>">
                <svg width="6" height="6" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3.5" fill="currentColor"/></svg>
                <?= $skemaOpen($sk)?($id?'Dibuka':'Open'):($id?'Ditutup':'Closed') ?>
              </span>
            </div>
          </div>
          <!-- Stats: total budget · kuota · min jafung -->
          <div class="sk-stats">
            <div class="sk-stat">
              <div class="sk-stat-val"><?= $fmt_short((int)$sk['anggaran_total']) ?></div>
              <div class="sk-stat-lbl"><?= $id?'Total Anggaran':'Total Budget' ?></div>
            </div>
            <div class="sk-stat">
              <div class="sk-stat-val"><?= (int)$sk['kuota'] ?></div>
              <div class="sk-stat-lbl"><?= $id?'Kuota Proposal':'Proposal Quota' ?></div>
            </div>
            <div class="sk-stat">
              <div class="sk-stat-val" title="<?= htmlspecialchars($jab_lbl) ?>" style="font-size:11px">
                <?= htmlspecialchars($jab_short) ?>
              </div>
              <div class="sk-stat-lbl"><?= $id?'Min. Jafung':'Min. Rank' ?></div>
            </div>
          </div>
          <!-- Checks: similarity · AI detection -->
          <div class="sk-checks">
            <div class="sk-check">
              <div class="sk-check-ico" style="background:#fff7ed;border:1px solid #fed7aa">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ea580c"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
              </div>
              <div>
                <div class="sk-check-val"><?= (float)$sk['batas_similarity'] ?>%</div>
                <div class="sk-check-lbl"><?= $id?'Maks. Similarity':'Max Similarity' ?></div>
              </div>
            </div>
            <div class="sk-check">
              <div class="sk-check-ico" style="background:#faf5ff;border:1px solid #ddd6fe">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                  <path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
              </div>
              <div>
                <div class="sk-check-val"><?= (float)$sk['batas_ai'] ?>%</div>
                <div class="sk-check-lbl"><?= $id?'Maks. Deteksi AI':'Max AI Detect' ?></div>
              </div>
            </div>
          </div>
          <!-- Members: dosen · mahasiswa -->
          <div class="sk-members">
            <div class="sk-member">
              <div class="sk-member-head">
                <span class="sk-member-lbl"><?= $id?'Dosen Anggota':'Lecturer Mbrs.' ?></span>
                <span class="sk-mbadge" style="background:<?= $sk_d_wjb?'#dbeafe':'#f1f5f9' ?>;color:<?= $sk_d_wjb?'#1d4ed8':'#64748b' ?>">
                  <?= $sk_d_wjb?($id?'Wajib':'Req.'):($id?'Opsional':'Opt.') ?>
                </span>
              </div>
              <div class="sk-member-val">
                <?= $sk_max_d !== null ? "{$sk_min_d}–{$sk_max_d}" : "≥{$sk_min_d}" ?>
                <span class="sk-member-unit"><?= $id?' org':' ppl' ?></span>
              </div>
            </div>
            <div class="sk-member">
              <div class="sk-member-head">
                <span class="sk-member-lbl"><?= $id?'Mahasiswa':'Students' ?></span>
                <span class="sk-mbadge" style="background:<?= $sk_m_wjb?'#dcfce7':'#f1f5f9' ?>;color:<?= $sk_m_wjb?'#15803d':'#64748b' ?>">
                  <?= $sk_m_wjb?($id?'Wajib':'Req.'):($id?'Opsional':'Opt.') ?>
                </span>
              </div>
              <div class="sk-member-val">
                <?= $sk_max_m !== null ? "{$sk_min_m}–{$sk_max_m}" : "≥{$sk_min_m}" ?>
                <span class="sk-member-unit"><?= $id?' org':' ppl' ?></span>
              </div>
            </div>
          </div>
          <!-- CTA -->
          <div class="sk-cta <?= $skemaOpen($sk)?'sk-cta-open':'sk-cta-closed' ?>">
            <?php if ($skemaOpen($sk)): ?>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
            <?= $id?'Ajukan proposal pada skema ini':'Submit proposal for this scheme' ?>
            <?php else: ?>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <?= $id?'Pendaftaran ditutup':'Registration closed' ?>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php else: ?>
        <!-- Fallback: tabel skema_pengabdian belum tersedia -->
        <div class="sk-card">
          <div class="sk-banner" style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%)">
            <div class="sk-banner-row">
              <div>
                <div class="sk-banner-name"><?= $id?'Publikasi Bereputasi Global':'Global Reputation Publication' ?></div>
                <div class="sk-banner-sub">Scopus Q1 – Q3</div>
              </div>
              <span class="sk-status-pill <?= ($gl_buka??false)?'sk-pill-open':'sk-pill-closed' ?>">
                <svg width="6" height="6" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3.5" fill="currentColor"/></svg>
                <?= ($gl_buka??false)?($id?'Dibuka':'Open'):($id?'Ditutup':'Closed') ?>
              </span>
            </div>
          </div>
        </div>
        <div class="sk-card">
          <div class="sk-banner" style="background:linear-gradient(135deg,#14532d 0%,#16a34a 100%)">
            <div class="sk-banner-row">
              <div>
                <div class="sk-banner-name"><?= $id?'Publikasi Bereputasi Nasional':'National Reputation Publication' ?></div>
                <div class="sk-banner-sub">SINTA 1 – 6</div>
              </div>
              <span class="sk-status-pill <?= ($ns_buka??false)?'sk-pill-open':'sk-pill-closed' ?>">
                <svg width="6" height="6" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3.5" fill="currentColor"/></svg>
                <?= ($ns_buka??false)?($id?'Dibuka':'Open'):($id?'Ditutup':'Closed') ?>
              </span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Tombol ajukan ── -->
      <?php
      $lewat_deadline = strtotime($deadline) < time();
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary)">
          <?= $id?'Proposal Saya':'My Proposals' ?>
          <span style="font-size:12px;font-weight:500;color:var(--text-muted);margin-left:4px">
            (<?= count($proposals) ?> <?= $id?'total':'total' ?>)
          </span>
        </div>
        <?php if ($ada_buka && !$lewat_deadline): ?>
        <button type="button" onclick="openCreateModal('','')"
           class="btn btn-primary" style="display:inline-flex;align-items:center;gap:7px;font-size:13px;border:none;cursor:pointer">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          <?= $id?'Ajukan Proposal Baru':'Submit New Proposal' ?>
        </button>
        <?php elseif ($lewat_deadline): ?>
        <span style="font-size:12px;color:#dc2626;font-weight:600;background:#fef2f2;
                     border:1px solid #fecaca;border-radius:8px;padding:6px 12px">
          <?= $id?'Pendaftaran telah berakhir':'Registration has ended' ?>
        </span>
        <?php endif; ?>
      </div>

      <!-- ── List proposals ── -->
      <?php if (empty($proposals)): ?>
      <div style="background:var(--bg-card);border:1.5px dashed var(--border);border-radius:14px;
                  padding:48px 24px;text-align:center">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"
             style="color:var(--text-muted);display:block;margin:0 auto 14px;opacity:.5">
          <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
          <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
        </svg>
        <div style="font-size:14px;font-weight:600;color:var(--text-muted);margin-bottom:6px">
          <?= $id?'Belum ada proposal':'No proposals yet' ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted)">
          <?= $id?'Klik tombol "Ajukan Proposal Baru" untuk memulai.':'Click "Submit New Proposal" to get started.' ?>
        </div>
      </div>
      <?php else: ?>
      <?php
      // Build a lookup map: kode => nama for proposals list
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
          <div class="rp-card-ico" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6)">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
              <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
            </svg>
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
            <a href="<?= BASE_URL ?>/modules/pengabdian/ajukan.php?edit=<?= $p['id'] ?>"
               class="btn btn-outline" style="font-size:12px;padding:6px 12px">
              <?= ic('edit') ?> <?= $id?'Edit':'Edit' ?>
            </a>
            <form method="POST" style="display:inline" onsubmit="return confirmDelete(this)">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="pid" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-trash">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                  <path d="M10 11v6"/><path d="M14 11v6"/>
                  <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                </svg>
                <?= $id?'Hapus':'Delete' ?>
              </button>
            </form>
            <?php endif; ?>
            <?php if (in_array($p['status'], ['gagal_admin','revisi_minor','revisi_mayor'])): ?>
            <a href="<?= BASE_URL ?>/modules/pengabdian/revisi.php?pid=<?= $p['id'] ?>"
               class="btn-revisi <?= $p['status']==='revisi_minor'?'minor':($p['status']==='revisi_mayor'?'mayor':'') ?>">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="1 4 1 10 7 10"/>
                <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
              </svg>
              <?= $id?'Kirim Revisi':'Submit Revision' ?>
            </a>
            <?php elseif (in_array($p['status'], ['perbaikan_admin','perbaikan_substantif'])): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;
                         font-weight:600;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;
                         border-radius:8px;padding:5px 10px">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?= $id?'Menunggu Review':'Awaiting Review' ?>
            </span>
            <?php endif; ?>
            <span class="status-badge" style="background:<?= $b['bg'] ?>;color:<?= $b['color'] ?>;
                  border:1px solid <?= $b['color'] ?>30;font-size:11px;padding:4px 10px;border-radius:8px;
                  font-weight:600;white-space:nowrap">
              <?= $lbl ?>
            </span>
          </div>
        </div>
        <?php
        // ── Reviewer panel: tampil pada status seleksi_substansi ke atas ──
        $show_rev_panel = in_array($p['status'], [
            'seleksi_substansi','disetujui','revisi_minor','revisi_mayor','ditolak'
        ]);
        $show_admin_note = in_array($p['status'], ['gagal_admin','lolos_admin'])
                           && !empty($p['catatan_seleksi_admin']);
        $revs  = $rev_map[$p['id']] ?? [];
        $n_rev = count($revs);

        if ($show_rev_panel || $show_admin_note || !empty($p['catatan_reviewer'])):
        ?>
        <div class="rev-panel">
          <?php if ($show_rev_panel): ?>
          <div class="rev-panel-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= $id?'Hasil Seleksi Substantif':'Substantive Review Result' ?>
          </div>

          <?php if ($n_rev === 0): ?>
          <div class="rev-pending">
            <?= $id?'Reviewer belum ditugaskan.':'Reviewers not yet assigned.' ?>
          </div>
          <?php else: ?>

          <div class="rev-grid">
          <?php foreach ($revs as $ri => $rv):
            $label = chr(65 + $ri); // A, B
            $submitted = !empty($rv['submitted_at']);
            $nt = $submitted ? (float)$rv['nilai_total'] : null;
            $keputusan = $rv['keputusan'] ?? null;
            $kMap = [
              'disetujui'    => ['Diterima',    '#16a34a','#f0fdf4'],
              'revisi_minor' => ['Revisi Minor', '#d97706','#fffbeb'],
              'revisi_mayor' => ['Revisi Mayor', '#ea580c','#fff7ed'],
              'ditolak'      => ['Ditolak',      '#dc2626','#fef2f2'],
            ];
          ?>
          <div class="rev-card">
            <div class="rev-card-head">
              <span class="rev-card-label">Reviewer <?= $label ?></span>
              <?php if ($submitted): ?>
              <span class="rev-card-score"><?= number_format($nt, 1) ?></span>
              <?php else: ?>
              <span style="font-size:11px;color:#94a3b8;font-style:italic"><?= $id?'Belum dinilai':'Pending' ?></span>
              <?php endif; ?>
            </div>
            <div class="rev-card-body">
              <?php if ($submitted): ?>
                <?php if ($keputusan && isset($kMap[$keputusan])): [$kl,$kc,$kb] = $kMap[$keputusan]; ?>
                <span class="rev-rec-badge" style="color:<?= $kc ?>;background:<?= $kb ?>;border:1px solid <?= $kc ?>30">
                  <?= $kl ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($rv['saran'])): ?>
                <div class="rev-note-text">
                  <strong style="color:var(--text-primary)"><?= $id?'Catatan:':'Notes:' ?></strong><br>
                  <?= nl2br(htmlspecialchars(mb_strimwidth($rv['saran'], 0, 300, '…'))) ?>
                </div>
                <?php endif; ?>
              <?php else: ?>
              <div style="font-size:11.5px;color:#94a3b8;font-style:italic;margin-top:2px">
                <?= $id?'Reviewer sedang menilai…':'Reviewer is assessing…' ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          </div><!-- /rev-grid -->

          <?php
          // Hitung akumulasi nilai (rata-rata yang sudah submit)
          $submitted_revs = array_filter($revs, fn($r) => !empty($r['submitted_at']));
          $n_submitted    = count($submitted_revs);
          if ($n_submitted > 0):
            $avg_nilai = array_sum(array_column($submitted_revs, 'nilai_total')) / $n_submitted;
            $pct       = min(100, round($avg_nilai));
            $bar_color = $pct >= $lulus_threshold ? '#16a34a' : '#ef4444';
          ?>
          <div class="rev-avg-row">
            <div>
              <div class="rev-avg-label">
                <?= $id
                    ? "Akumulasi Nilai ({$n_submitted} reviewer)"
                    : "Accumulated Score ({$n_submitted} reviewer)" ?>
              </div>
              <div class="rev-bar-wrap" style="width:160px">
                <div class="rev-bar-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
              </div>
              <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px">
                <?= $id?'Min. lulus':'Pass min.' ?>: <?= $lulus_threshold ?>
              </div>
            </div>
            <div class="rev-avg-val" style="color:<?= $bar_color ?>"><?= number_format($avg_nilai, 1) ?></div>
          </div>
          <?php endif; ?>

          <?php endif; // $n_rev > 0 ?>

          <?php endif; // $show_rev_panel ?>

          <?php // Catatan seleksi administratif ?>
          <?php if ($show_admin_note): ?>
          <div class="rev-status-final" style="
               background:<?= $p['status']==='gagal_admin'?'#fef2f2':'#ecfdf5' ?>;
               color:<?= $p['status']==='gagal_admin'?'#991b1b':'#065f46' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <?= $p['status']==='gagal_admin'
                ? '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'
                : '<circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/>' ?>
            </svg>
            <div>
              <strong><?= $id
                ? ($p['status']==='gagal_admin'?'Tidak lolos seleksi administratif — catatan:':'Lolos seleksi administratif — catatan:')
                : ($p['status']==='gagal_admin'?'Failed admin selection — notes:':'Passed admin selection — notes:') ?></strong>
              <?= nl2br(htmlspecialchars($p['catatan_seleksi_admin'])) ?>
            </div>
          </div>
          <?php endif; ?>

          <?php // ── Kontrak pengabdian (untuk dosen #4) ── ?>
          <?php $kp = $kontrak_map[$p['id']] ?? null; ?>
          <?php if ($kp && in_array($p['status'], ['disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai'], true)): ?>
          <div style="margin-top:10px;padding:14px 16px;border-radius:11px;
                      background:linear-gradient(135deg,#faf5ff 0%,#eff6ff 100%);
                      border:1.5px solid #ddd6fe">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
              <div style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#4a1d96,#7c3aed);
                          display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <?= ic('doc','style="width:15px;height:15px;color:#fff"') ?>
              </div>
              <div style="flex:1">
                <div style="font-size:12.5px;font-weight:700;color:#4a1d96">
                  <?= $id?'Kontrak Pengabdian':'Research Contract' ?>
                </div>
                <div style="font-size:11px;color:#6b21a8">
                  <?= $kp['nomor_kontrak']
                    ? htmlspecialchars($kp['nomor_kontrak'])
                    : ($id?'(nomor belum diisi)':'(no number yet)') ?>
                  <?php if (!empty($kp['tgl_kontrak'])): ?>
                  · <?= date('d M Y', strtotime($kp['tgl_kontrak'])) ?>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($p['status'] === 'penandatanganan_kontrak'): ?>
              <span style="background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;padding:3px 9px;border-radius:9px">
                <?= $id?'PERLU TANDA TANGAN':'NEEDS SIGNATURE' ?>
              </span>
              <?php elseif ($p['status'] === 'kontrak_aktif'): ?>
              <span style="background:#dcfce7;color:#15803d;font-size:10px;font-weight:700;padding:3px 9px;border-radius:9px">
                <?= $id?'AKTIF':'ACTIVE' ?>
              </span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <?php if (!empty($kp['file_kontrak'])): ?>
              <a href="<?= BASE_URL ?>/<?= htmlspecialchars($kp['file_kontrak']) ?>" target="_blank"
                 class="btn btn-primary" style="font-size:11.5px;padding:6px 12px;background:linear-gradient(135deg,#7c3aed,#a78bfa)">
                <?= ic('download','style="width:12px;height:12px"') ?>
                <?= $id?'Unduh Kontrak':'Download Contract' ?>
              </a>
              <?php else: ?>
              <span style="font-size:11.5px;color:#7c3aed;font-style:italic">
                <?= $id?'File kontrak akan tersedia setelah dipersiapkan oleh LPPM.':'Contract file will be available after LPPM prepares it.' ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($kp['deadline_laporan'])): ?>
              <?php
                $dl_ts = strtotime($kp['deadline_laporan']);
                $sisa = $dl_ts - time();
                $kp_overdue = $sisa <= 0;
                $kp_urgent  = $sisa > 0 && $sisa < 86400 * 7;
              ?>
              <span style="display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:8px;
                           background:<?= $kp_overdue?'#fef2f2':($kp_urgent?'#fff7ed':'#ecfdf5') ?>;
                           color:<?= $kp_overdue?'#991b1b':($kp_urgent?'#9a3412':'#15803d') ?>;
                           font-size:11px;font-weight:700">
                <?= ic('clock','style="width:11px;height:11px"') ?>
                <?= $id?'Batas Laporan':'Report Due' ?>: <?= date('d M Y · H:i', $dl_ts) ?>
              </span>
              <?php endif; ?>
            </div>
            <?php if ($p['status'] === 'penandatanganan_kontrak'): ?>
            <div style="margin-top:9px;padding:8px 11px;background:#fffbeb;border:1px solid #fde68a;
                        border-radius:8px;font-size:11.5px;color:#92400e;display:flex;gap:7px;align-items:flex-start">
              <?= ic('alert','style="width:13px;height:13px;flex-shrink:0;margin-top:1px"') ?>
              <div><?= $id
                ? 'Silakan datang ke kantor LPPM IAKN Toraja untuk menandatangani <strong>hardcopy</strong> kontrak ini. Setelah ditandatangani, admin akan mengaktifkan kontrak Anda.'
                : 'Please visit the LPPM IAKN Toraja office to sign the <strong>hardcopy</strong> of this contract. After signing, admin will activate your contract.' ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php // Catatan akhir dari admin (setelah finalisasi) ?>
          <?php if (!empty($p['catatan_reviewer'])): ?>
          <div class="rev-status-final" style="background:<?= in_array($p['status'],['disetujui'])?'#f0fdf4':(in_array($p['status'],['ditolak'])?'#fef2f2':'#fffbeb') ?>;
               color:<?= in_array($p['status'],['disetujui'])?'#166534':(in_array($p['status'],['ditolak'])?'#991b1b':'#92400e') ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/>
              <line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            <div>
              <strong><?= $id?'Catatan Admin LPPM:':'LPPM Admin Notes:' ?></strong>
              <?= nl2br(htmlspecialchars($p['catatan_reviewer'])) ?>
            </div>
          </div>
          <?php endif; ?>
        </div><!-- /rev-panel -->
        <?php endif; ?>

        <?php // ── Riwayat Revisi ── ?>
        <?php $revisi_hist = $revisi_map[$p['id']] ?? []; ?>
        <?php if (!empty($revisi_hist)): ?>
        <div class="rv-hist">
          <div class="rv-hist-title">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-1px">
              <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
            </svg>
            <?= $id?'Riwayat Revisi':'Revision History' ?>
            <span style="margin-left:4px;background:#e0e7ff;color:#3730a3;border-radius:10px;
                         padding:1px 7px;font-size:10px"><?= count($revisi_hist) ?>×</span>
          </div>
          <div class="rv-timeline">
          <?php foreach ($revisi_hist as $rv): ?>
          <?php
            $rv_dot = $rv['keputusan'] === 'diterima' ? 'diterima' : ($rv['keputusan'] === 'dikembalikan' ? 'dikembalikan' : 'pending');
            $rv_badge_map = [
              'diterima'     => ['Diterima',    '#16a34a','#dcfce7'],
              'dikembalikan' => ['Dikembalikan','#dc2626','#fef2f2'],
            ];
          ?>
          <div class="rv-tl-item">
            <div class="rv-tl-dot <?= $rv_dot ?>"></div>
            <div class="rv-tl-head">
              <?= $id?'Revisi ke-'.$rv['round_ke']:'Revision #'.$rv['round_ke'] ?>
              <span style="font-size:10.5px;font-weight:500;color:var(--text-muted);margin-left:4px">
                (<?= $rv['tipe']==='admin'?($id?'Administratif':'Admin'):($id?'Substantif':'Substantive') ?>)
              </span>
              <?php if (isset($rv_badge_map[$rv['keputusan']])): [$bl,$bc,$bb] = $rv_badge_map[$rv['keputusan']]; ?>
              <span class="rv-tl-badge" style="color:<?= $bc ?>;background:<?= $bb ?>"><?= $bl ?></span>
              <?php else: ?>
              <span class="rv-tl-badge" style="color:#7c3aed;background:#f5f3ff"><?= $id?'Sedang Ditinjau':'Under Review' ?></span>
              <?php endif; ?>
            </div>
            <div class="rv-tl-meta">
              <?= date('d M Y H:i', strtotime($rv['created_at'])) ?>
              <?php if ($rv['file_proposal_name']): ?>
              · <?= htmlspecialchars($rv['file_proposal_name']) ?>
              <?php endif; ?>
              <?php if ($rv['admin_catatan'] && $rv['keputusan']): ?>
              <div style="margin-top:3px;color:<?= $rv['keputusan']==='diterima'?'#166534':'#991b1b' ?>">
                <?= htmlspecialchars(mb_strimwidth($rv['admin_catatan'],0,120,'…')) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <!-- ── Sampah ── -->
      <?php if (!empty($trashed)): ?>
      <div class="trash-section">
        <div class="trash-header">
          <div class="trash-title" onclick="toggleTrash()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
              <path d="M10 11v6"/><path d="M14 11v6"/>
              <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
            <?= $id?'Sampah':'Trash' ?>
            <span style="font-size:11px;font-weight:500;background:#fee2e2;color:#dc2626;
                         border-radius:10px;padding:1px 8px">
              <?= count($trashed) ?>
            </span>
            <span style="font-size:11px;font-weight:400;color:#94a3b8">
              — <?= $id?'draft dihapus, otomatis terhapus permanen setelah 30 hari'
                       :'deleted drafts, permanently removed after 30 days' ?>
            </span>
            <svg id="trash-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 style="transition:transform .2s;margin-left:4px">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </div>
        </div>

        <div id="trash-list">
        <?php
        $skema_map2 = [];
        foreach ($skema_rows as $sk) { $skema_map2[$sk['kode']] = $sk['nama']; }
        ?>
        <?php foreach ($trashed as $t):
          $skm2   = $skema_map2[$t['skema']] ?? ucfirst($t['skema']);
          $delAt  = strtotime($t['deleted_at']);
          $expiry = $delAt + 30 * 86400;
          $left   = max(0, (int)ceil(($expiry - time()) / 86400));
        ?>
        <div class="trash-card">
          <div class="trash-card-head">
            <div class="rp-card-ico" style="background:linear-gradient(135deg,#94a3b8,#64748b);opacity:.7">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
              </svg>
            </div>
            <div class="rp-card-body">
              <div class="rp-card-title" style="color:#64748b">
                <?= htmlspecialchars($t['judul'] ?: ($id?'(tanpa judul)':'(untitled)')) ?>
              </div>
              <div class="rp-card-meta">
                <span><?= $id?'Skema':'Scheme' ?>: <?= htmlspecialchars($skm2) ?></span>
                <span><?= $id?'Dihapus':'Deleted' ?>: <?= date('d M Y', $delAt) ?></span>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end">
              <span class="trash-expiry">
                <?= $left > 0
                  ? ($id ? "Hapus permanen dalam {$left} hari" : "Permanent in {$left} days")
                  : ($id ? 'Segera dihapus' : 'Expiring soon') ?>
              </span>
              <!-- Restore -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="act" value="restore">
                <input type="hidden" name="pid" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-outline" style="font-size:12px;padding:5px 11px;color:#16a34a;border-color:#bbf7d0">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10"/>
                    <path d="M3.51 15a9 9 0 1 0 .49-5.1"/>
                  </svg>
                  <?= $id?'Pulihkan':'Restore' ?>
                </button>
              </form>
              <!-- Hapus permanen -->
              <form method="POST" style="display:inline" onsubmit="return confirmPermDelete(this)">
                <input type="hidden" name="act" value="perm_delete">
                <input type="hidden" name="pid" value="<?= $t['id'] ?>">
                <button type="submit" class="btn-trash" style="border-color:#fca5a5">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                  </svg>
                  <?= $id?'Hapus Permanen':'Delete Permanently' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}

function confirmDelete(form) {
  const lang = '<?= $lang ?>';
  return confirm(lang === 'id'
    ? 'Pindahkan draft ini ke sampah? Draft dapat dipulihkan dalam 30 hari.'
    : 'Move this draft to trash? It can be restored within 30 days.');
}

function confirmPermDelete(form) {
  const lang = '<?= $lang ?>';
  return confirm(lang === 'id'
    ? 'Hapus permanen? Data tidak dapat dipulihkan.'
    : 'Delete permanently? This cannot be undone.');
}

let trashOpen = true;
function toggleTrash() {
  const list  = document.getElementById('trash-list');
  const arrow = document.getElementById('trash-arrow');
  trashOpen = !trashOpen;
  list.style.display  = trashOpen ? '' : 'none';
  arrow.style.transform = trashOpen ? '' : 'rotate(-90deg)';
}

/* ── Pop-up usulan PkM (#2) ──────────────────────── */
function openCreateModal(skema_kode, skema_nama) {
  const m = document.getElementById('createPkmModal');
  if (!m) return;
  document.getElementById('cpm_judul').value = '';
  document.getElementById('cpm_judul').focus();
  // Pre-select skema jika user klik dari card
  if (skema_kode) {
    const r = document.querySelector('input[name="skema"][value="' + skema_kode + '"]');
    if (r) r.checked = true;
  }
  m.style.display = 'flex';
}
function closeCreateModal(){
  document.getElementById('createPkmModal').style.display = 'none';
}
</script>

<!-- ══════ MODAL POP-UP USULAN PkM (#2) ══════ -->
<div id="createPkmModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);
     z-index:1200;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(4px)"
     onclick="if(event.target===this) closeCreateModal()">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:92vh;
              overflow-y:auto;box-shadow:0 25px 70px rgba(0,0,0,.32)">
    <form method="POST" action="<?= BASE_URL ?>/modules/pengabdian/ajukan.php">
      <input type="hidden" name="action" value="create_draft_quick">
      <div style="padding:18px 22px;background:linear-gradient(135deg,#4a1d96,#7c3aed);color:#fff;
                  display:flex;align-items:center;gap:11px;border-radius:16px 16px 0 0">
        <?= ic('users','style="width:22px;height:22px;color:#fff"') ?>
        <div style="flex:1">
          <div style="font-size:15px;font-weight:800"><?= $id?'Ajukan Usulan Pengabdian Baru':'Submit New PkM Proposal' ?></div>
          <div style="font-size:11.5px;opacity:.85;margin-top:2px"><?= $id?'Lengkapi 3 informasi awal — detail akan dilengkapi pada halaman berikutnya':'Fill in 3 initial fields — full form on next page' ?></div>
        </div>
        <button type="button" onclick="closeCreateModal()" style="background:rgba(255,255,255,.18);border:none;color:#fff;font-size:18px;cursor:pointer;width:30px;height:30px;border-radius:8px">×</button>
      </div>
      <div style="padding:20px 22px;display:flex;flex-direction:column;gap:14px">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px">
            <?= $id?'Judul Pengabdian':'PkM Title' ?> <span style="color:#dc2626">*</span>
          </label>
          <input type="text" name="judul" id="cpm_judul" required maxlength="500"
                 placeholder="<?= $id?'Tulis judul singkat — bisa diedit nanti':'Brief title — editable later' ?>"
                 style="width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px">
            <?= $id?'Skema Pengabdian':'PkM Scheme' ?> <span style="color:#dc2626">*</span>
          </label>
          <div style="display:flex;flex-direction:column;gap:7px">
            <?php foreach ($skema_rows as $sk):
              if (!$skemaOpen($sk)) continue;
            ?>
            <label style="display:flex;align-items:flex-start;gap:11px;padding:11px 13px;
                          border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;
                          transition:border-color .15s, background .15s">
              <input type="radio" name="skema" value="<?= htmlspecialchars($sk['kode']) ?>" required
                     style="margin-top:3px;width:16px;height:16px;flex-shrink:0;accent-color:#7c3aed">
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:700;color:#1e293b">
                  <?= htmlspecialchars($sk['nama']) ?>
                  <?php if (!(int)$sk['kontrak_wajib']): ?>
                  <span style="background:#dcfce7;color:#15803d;font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:4px;margin-left:4px;text-transform:uppercase">
                    <?= $id?'Kontrak Opsional':'Contract Optional' ?>
                  </span>
                  <?php else: ?>
                  <span style="background:#fef3c7;color:#92400e;font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:4px;margin-left:4px;text-transform:uppercase">
                    <?= $id?'Kontrak Wajib':'Contract Required' ?>
                  </span>
                  <?php endif; ?>
                </div>
                <?php if ($sk['target_publikasi']): ?>
                <div style="font-size:11px;color:#64748b;margin-top:2px">
                  <?= $id?'Target luaran':'Target output' ?>: <?= htmlspecialchars($sk['target_publikasi']) ?>
                </div>
                <?php endif; ?>
                <?php if ($sk['anggaran_total']): ?>
                <div style="font-size:11px;color:#64748b;margin-top:1px">
                  <?= $id?'Anggaran':'Budget' ?>: Rp <?= number_format((int)$sk['anggaran_total'], 0, ',', '.') ?>
                </div>
                <?php endif; ?>
              </div>
            </label>
            <?php endforeach; ?>
            <?php
              $any_open = false;
              foreach ($skema_rows as $sk) if ($skemaOpen($sk)) { $any_open = true; break; }
              if (!$any_open):
            ?>
            <div style="padding:18px;text-align:center;color:#94a3b8;font-size:12px;background:#f8fafc;border-radius:8px">
              <?= $id?'Tidak ada skema yang sedang dibuka.':'No schemes are currently open.' ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px">
            <?= $id?'Tahun Anggaran':'Budget Year' ?>
          </label>
          <input type="number" name="tahun_anggaran" min="2024" max="2099"
                 value="<?= (int)$tahun ?>" readonly
                 style="width:140px;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px;background:#f8fafc;font-weight:700">
        </div>
      </div>
      <div style="padding:14px 22px;border-top:1.5px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;background:#f8fafc;border-radius:0 0 16px 16px">
        <button type="button" onclick="closeCreateModal()" class="btn btn-outline" style="font-size:13px">
          <?= $id?'Batal':'Cancel' ?>
        </button>
        <button type="submit" class="btn btn-primary" style="font-size:13px;background:linear-gradient(135deg,#4a1d96,#7c3aed);border:none">
          <?= ic('plus','style="width:13px;height:13px"') ?>
          <?= $id?'Buat Usulan':'Create Proposal' ?>
        </button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
