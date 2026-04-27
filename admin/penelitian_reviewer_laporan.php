<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang  = $_COOKIE['lang'] ?? 'id';
$tahun = (int)(getSetting($pdo, 'penelitian_tahun') ?: date('Y'));

/* ── Rubrik dari DB ──────────────────────────────────────────── */
$rubrik_raw = getSetting($pdo, 'reviewer_rubrik');
$RUBRIK = [];
if ($rubrik_raw) {
    $dec = json_decode($rubrik_raw, true);
    if (is_array($dec) && count($dec) >= 1) $RUBRIK = $dec;
}
if (empty($RUBRIK)) {
    $RUBRIK = [
        ['kriteria'=>'Perumusan Masalah',            'bobot'=>20,'skor_max'=>5],
        ['kriteria'=>'Manfaat Hasil Penelitian',      'bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Tinjauan Pustaka',              'bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Landasan Teori',                'bobot'=>20,'skor_max'=>5],
        ['kriteria'=>'Metode Penelitian',             'bobot'=>15,'skor_max'=>5],
        ['kriteria'=>'Output dan Outcome Penelitian', 'bobot'=>15,'skor_max'=>5],
    ];
}
$N = count($RUBRIK);
$BOBOT    = array_column($RUBRIK,'bobot');
$KRITERIA = array_column($RUBRIK,'kriteria');
$SKOR_MAX = array_column($RUBRIK,'skor_max');
$lulus_threshold = (int)(getSetting($pdo,'lulus_threshold') ?: 60);

/* ── Filter ──────────────────────────────────────────────────── */
$filter_skema = clean($_GET['skema'] ?? '');

/* ── Status yang relevan ─────────────────────────────────────── */
$done_st   = ['disetujui','revisi_minor','revisi_mayor','ditolak','perbaikan_substantif'];
$all_st    = array_merge(['seleksi_substansi'], $done_st);
$in_ph     = implode(',', array_fill(0, count($all_st), '?'));

$params = $all_st;
$extra  = "up.tahun_anggaran=$tahun AND up.deleted_at IS NULL AND up.status IN ($in_ph)";
if ($filter_skema) { $extra .= ' AND up.skema=?'; $params[] = $filter_skema; }

$proposals = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.catatan_reviewer,
           up.reviewed_at, up.created_at,
           u.nama_lengkap, u.nidn, u.program_studi, u.jabatan_fungsional
    FROM usulan_penelitian up
    JOIN users u ON up.user_id = u.id
    WHERE $extra
    ORDER BY up.skema ASC, up.reviewed_at ASC
");
$proposals->execute($params);
$proposals = $proposals->fetchAll();

/* ── Load reviewer scores per proposal ─────────────────────── */
$pids = array_column($proposals, 'id');
$rev_scores = []; // pid => [['nilai_total'=>x,'keputusan'=>y,'saran'=>z,'skors'=>[...]], ...]

if ($pids) {
    $rph = implode(',', array_fill(0, count($pids), '?'));
    $rq  = $pdo->prepare("
        SELECT ra.usulan_id, ra.assigned_at,
               rp.nilai_total, rp.keputusan as rv_keputusan, rp.saran, rp.submitted_at,
               rp.skor_1, rp.skor_2, rp.skor_3, rp.skor_4, rp.skor_5, rp.skor_6
        FROM reviewer_assignment ra
        JOIN reviewer_penilaian rp ON rp.assignment_id = ra.id
        WHERE ra.usulan_id IN ($rph) AND rp.submitted_at IS NOT NULL
        ORDER BY ra.usulan_id ASC, ra.assigned_at ASC
    ");
    $rq->execute($pids);
    foreach ($rq->fetchAll() as $rv) {
        $pid = $rv['usulan_id'];
        $rev_scores[$pid][] = [
            'nilai_total' => (float)$rv['nilai_total'],
            'keputusan'   => $rv['rv_keputusan'],
            'saran'       => $rv['saran'],
            'skors'       => [(float)$rv['skor_1'],(float)$rv['skor_2'],(float)$rv['skor_3'],
                              (float)$rv['skor_4'],(float)$rv['skor_5'],(float)$rv['skor_6']],
        ];
    }
}

/* ── Agregat ─────────────────────────────────────────────────── */
$agg = ['total'=>0,'disetujui'=>0,'revisi_minor'=>0,'revisi_mayor'=>0,
        'ditolak'=>0,'proses'=>0,'perbaikan'=>0];
$per_skema   = [];
$rubrik_skor_sum = array_fill(0, $N, 0.0); // sum of avg scores per criterion
$rubrik_skor_cnt = 0;
$nilai_dist  = ['<50'=>0,'50–59'=>0,'60–69'=>0,'70–79'=>0,'80–89'=>0,'≥90'=>0];
$all_avg_scores = []; // for calculating overall avg

foreach ($proposals as $p) {
    $agg['total']++;
    $sk = $p['skema'] ?: '—';
    if (!isset($per_skema[$sk])) $per_skema[$sk] = ['total'=>0,'disetujui'=>0,'revisi_minor'=>0,
                                                     'revisi_mayor'=>0,'ditolak'=>0,'proses'=>0];
    $per_skema[$sk]['total']++;

    if ($p['status'] === 'disetujui') {
        $agg['disetujui']++; $per_skema[$sk]['disetujui']++;
    } elseif ($p['status'] === 'revisi_minor') {
        $agg['revisi_minor']++; $per_skema[$sk]['revisi_minor']++;
    } elseif ($p['status'] === 'revisi_mayor') {
        $agg['revisi_mayor']++; $per_skema[$sk]['revisi_mayor']++;
    } elseif ($p['status'] === 'ditolak') {
        $agg['ditolak']++; $per_skema[$sk]['ditolak']++;
    } elseif ($p['status'] === 'perbaikan_substantif') {
        $agg['perbaikan']++; $per_skema[$sk]['proses']++;
    } else {
        $agg['proses']++; $per_skema[$sk]['proses']++;
    }

    // Score aggregation
    $pid = $p['id'];
    if (!empty($rev_scores[$pid])) {
        $avg_nilai = array_sum(array_column($rev_scores[$pid],'nilai_total'))
                     / count($rev_scores[$pid]);
        $all_avg_scores[] = $avg_nilai;

        // Rubrik
        $rv_count = count($rev_scores[$pid]);
        for ($ki = 0; $ki < $N; $ki++) {
            $sk_sum = 0;
            foreach ($rev_scores[$pid] as $rv) $sk_sum += ($rv['skors'][$ki] ?? 0);
            $rubrik_skor_sum[$ki] += ($rv_count > 0 ? $sk_sum / $rv_count : 0);
        }
        $rubrik_skor_cnt++;

        // Distribution
        if ($avg_nilai < 50)      $nilai_dist['<50']++;
        elseif ($avg_nilai < 60)  $nilai_dist['50–59']++;
        elseif ($avg_nilai < 70)  $nilai_dist['60–69']++;
        elseif ($avg_nilai < 80)  $nilai_dist['70–79']++;
        elseif ($avg_nilai < 90)  $nilai_dist['80–89']++;
        else                      $nilai_dist['≥90']++;
    }
}
$overall_avg = count($all_avg_scores) > 0
    ? round(array_sum($all_avg_scores) / count($all_avg_scores), 1) : 0;

/* ── Institusi ───────────────────────────────────────────────── */
$cfg_keys  = ['nama_institusi','nama_lppm','alamat_institusi','nama_ketua_lppm','nip_ketua_lppm'];
$cfg = [];
foreach ($cfg_keys as $k) $cfg[$k] = getSetting($pdo, $k) ?: '';
$institusi  = $cfg['nama_institusi'] ?: 'Institut Agama Kristen Negeri (IAKN) Toraja';
$nama_lppm  = $cfg['nama_lppm']      ?: 'Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM)';
$alamat     = $cfg['alamat_institusi'] ?: 'Jl. Nusantara No. 1, Makale, Tana Toraja, Sulawesi Selatan';
$ketua_lppm = $cfg['nama_ketua_lppm']  ?: '____________________';
$nip_ketua  = $cfg['nip_ketua_lppm']   ?: '';
$printer    = $_SESSION['nama'] ?? 'Admin';
$cetak_tgl  = date('d F Y');
$cetak_loc  = 'Tana Toraja';

$logo_src = BASE_URL . '/assets/img/logo_kiri.jpg';

/* ── SVG helpers ─────────────────────────────────────────────── */
function rvArc(float $val, float $tot, float $a0, string $col,
               float $r=62, float $cx=82, float $cy=82, float $sw=22): string {
    if ($tot<=0||$val<=0) return '';
    $deg = min(359.99, $val/$tot*360);
    $r1=deg2rad($a0); $r2=deg2rad($a0+$deg);
    [$x1,$y1]=[$cx+$r*cos($r1),$cy+$r*sin($r1)];
    [$x2,$y2]=[$cx+$r*cos($r2),$cy+$r*sin($r2)];
    return "<path d='M $x1 $y1 A $r $r 0 ".($deg>180?1:0)." 1 $x2 $y2'"
          ." fill='none' stroke='$col' stroke-width='$sw' stroke-linecap='butt'/>";
}

// Score-to-color gradient: green (≥lulus_threshold) or red/amber
function scoreColor(float $v, int $thr=60): string {
    if ($v >= 80) return '#15803d';
    if ($v >= $thr) return '#16a34a';
    if ($v >= 50) return '#d97706';
    return '#dc2626';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laporan Seleksi Substantif — <?= $tahun ?></title>
<style>
/* ════════════════════════════════════════════
   RESET & BASE
════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:13px}
body{font-family:'Segoe UI',Calibri,Arial,sans-serif;color:#1a1a2e;background:#f0f4f8;line-height:1.5}

/* ════════════════════════════════════════════
   TOOLBAR
════════════════════════════════════════════ */
#toolbar{
  position:fixed;top:0;left:0;right:0;z-index:999;height:56px;
  background:linear-gradient(135deg,#064e3b 0%,#059669 60%,#10b981 100%);
  padding:0 24px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 2px 20px rgba(5,150,105,.45);
}
.tb-brand{display:flex;align-items:center;gap:12px}
.tb-logo{width:34px;height:34px;border-radius:8px;overflow:hidden;flex-shrink:0}
.tb-logo img{width:100%;height:100%;object-fit:cover}
.tb-title{font-size:13.5px;font-weight:700;color:#fff}
.tb-sub{font-size:10.5px;color:rgba(255,255,255,.65);margin-top:1px}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;
  font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
.tb-btn:hover{opacity:.85}
.tb-print{background:#fff;color:#059669}
.tb-back {background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)}

/* ════════════════════════════════════════════
   WRAP
════════════════════════════════════════════ */
#wrap{max-width:1040px;margin:72px auto 48px;padding:0 16px}

/* ════════════════════════════════════════════
   SHEET
════════════════════════════════════════════ */
.sheet{
  background:#fff;border-radius:4px;
  box-shadow:0 2px 4px rgba(0,0,0,.06),0 8px 40px rgba(0,0,0,.09);
  margin-bottom:24px;overflow:hidden;position:relative;
}
.sheet::before{
  content:'';display:block;height:5px;
  background:linear-gradient(90deg,#064e3b 0%,#059669 35%,#D97706 65%,#F59E0B 100%);
}

/* ════════════════════════════════════════════
   KOP SURAT
════════════════════════════════════════════ */
.kop{
  padding:20px 36px 16px;
  border-bottom:3px double #059669;
  display:grid;grid-template-columns:76px 1fr 96px;align-items:center;gap:16px;
}
.kop-logo{width:76px;height:76px;object-fit:contain}
.kop-center{text-align:center}
.kop-inst{font-size:16px;font-weight:800;letter-spacing:.3px;color:#0d1b3e;text-transform:uppercase}
.kop-lppm{font-size:11.5px;font-weight:600;color:#059669;margin-top:3px}
.kop-addr{font-size:10px;color:#64748b;margin-top:2px}
.kop-badge{
  text-align:center;padding:8px 10px;border-radius:10px;
  background:linear-gradient(135deg,#064e3b,#059669);color:#fff;
}
.kop-badge-n{font-size:19px;font-weight:900;line-height:1}
.kop-badge-l{font-size:9px;opacity:.8;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ════════════════════════════════════════════
   BANNER
════════════════════════════════════════════ */
.banner{
  background:linear-gradient(135deg,#064e3b 0%,#065f46 40%,#059669 75%,#10b981 100%);
  color:#fff;padding:22px 36px;
  display:flex;align-items:center;justify-content:space-between;gap:14px;
  position:relative;overflow:hidden;
}
.banner::after{content:'';position:absolute;right:-28px;top:-28px;
  width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.05)}
.banner::before{content:'';position:absolute;right:120px;bottom:-35px;
  width:90px;height:90px;border-radius:50%;background:rgba(217,119,6,.1)}
.banner-title{font-size:16.5px;font-weight:800;letter-spacing:.3px;line-height:1.3}
.banner-sub{font-size:11.5px;opacity:.75;margin-top:4px}
.banner-stamp{
  flex-shrink:0;background:rgba(255,255,255,.13);
  border:1.5px solid rgba(255,255,255,.25);border-radius:10px;
  padding:10px 16px;text-align:center;z-index:1;
}
.banner-stamp-top{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:.8px}
.banner-stamp-n{font-size:22px;font-weight:900;line-height:1;margin:2px 0}
.banner-stamp-bot{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:.5px}

/* ════════════════════════════════════════════
   META STRIP
════════════════════════════════════════════ */
.meta{background:#f8fffe;border-bottom:1.5px solid #d1fae5;
  padding:9px 36px;display:flex;gap:0;flex-wrap:wrap}
.meta-p{display:flex;align-items:center;gap:7px;
  padding:0 18px 0 0;border-right:1px solid #d1fae5;margin-right:18px;
  font-size:11px;color:#64748b}
.meta-p:last-child{border-right:none}
.meta-v{font-weight:700;color:#064e3b}

/* ════════════════════════════════════════════
   SECTION
════════════════════════════════════════════ */
.sec{padding:22px 36px;border-bottom:1px solid #f0fdf4}
.sec:last-child{border-bottom:none}
.sec-hd{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.sec-bar{width:4px;height:20px;border-radius:2px;flex-shrink:0;
  background:linear-gradient(to bottom,#059669,#D97706)}
.sec-title{font-size:12px;font-weight:800;color:#064e3b;
  text-transform:uppercase;letter-spacing:.8px}
.sec-line{flex:1;height:1px;background:linear-gradient(to right,#a7f3d0,transparent)}

/* ════════════════════════════════════════════
   STAT CARDS  (2×2 + 1 wide + score card)
════════════════════════════════════════════ */
.stat-master{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:11px;margin-bottom:22px}
.sc{border-radius:11px;padding:16px;position:relative;overflow:hidden;color:#fff}
.sc::after{content:'';position:absolute;right:-12px;top:-12px;
  width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.1)}
.sc-n{font-size:30px;font-weight:900;line-height:1}
.sc-l{font-size:10.5px;font-weight:600;opacity:.85;margin-top:5px;line-height:1.3}
.sc-avg{font-size:9.5px;opacity:.6;margin-top:3px}
.sc-icon{position:absolute;bottom:10px;right:12px;opacity:.18;width:26px;height:26px}

/* ════════════════════════════════════════════
   DONUT + LEGEND
════════════════════════════════════════════ */
.donut-wrap{display:grid;grid-template-columns:164px 1fr;gap:28px;align-items:center}
.dr{position:relative;width:164px;height:164px}
.dc{position:absolute;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:1px}
.dc-n{font-size:26px;font-weight:900;color:#064e3b}
.dc-s{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.lg{display:flex;flex-direction:column;gap:11px}
.lg-r{display:flex;align-items:center;gap:10px}
.lg-d{width:11px;height:11px;border-radius:3px;flex-shrink:0}
.lg-n{font-size:12px;font-weight:600;color:#1e293b;min-width:130px}
.lg-c{font-size:12px;font-weight:800;min-width:28px;text-align:right}
.lg-p{font-size:10.5px;color:#94a3b8;min-width:38px;text-align:right}
.lg-bg{flex:1;height:8px;border-radius:4px;background:#f1f5f9;overflow:hidden}
.lg-bar{height:100%;border-radius:4px}

/* ════════════════════════════════════════════
   SCORE DISTRIBUTION (histogram)
════════════════════════════════════════════ */
.hist{display:flex;align-items:flex-end;gap:8px;height:90px;margin-top:14px}
.hist-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1}
.hist-bar{width:100%;border-radius:4px 4px 0 0;min-height:4px;transition:height .3s}
.hist-lbl{font-size:9.5px;color:#64748b;white-space:nowrap}
.hist-cnt{font-size:10.5px;font-weight:700}

/* ════════════════════════════════════════════
   RUBRIK PERFORMANCE
════════════════════════════════════════════ */
.rubrik-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rub-row{display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:9px;border:1px solid #d1fae5;background:#f8fffe}
.rub-num{width:24px;height:24px;border-radius:6px;flex-shrink:0;
  background:linear-gradient(135deg,#064e3b,#059669);
  color:#fff;font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center}
.rub-body{flex:1;min-width:0}
.rub-name{font-size:11.5px;font-weight:600;color:#1e293b;line-height:1.3}
.rub-bar-wrap{height:5px;border-radius:3px;background:#e5e7eb;overflow:hidden;margin-top:5px}
.rub-bar{height:100%;border-radius:3px}
.rub-score{font-size:11px;font-weight:800;flex-shrink:0;min-width:38px;text-align:right}
.rub-max{font-size:9.5px;color:#94a3b8;text-align:right}

/* ════════════════════════════════════════════
   SKEMA TABLE
════════════════════════════════════════════ */
.sk-tbl{width:100%;border-collapse:collapse;font-size:12px}
.sk-tbl th{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;
  letter-spacing:.5px;padding:8px 10px;border-bottom:2px solid #d1fae5;text-align:left;white-space:nowrap}
.sk-tbl td{padding:10px 10px;border-bottom:1px solid #f0fdf4;vertical-align:middle}
.sk-tbl tr:last-child td{border-bottom:none}
.sk-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:10.5px;font-weight:700;text-transform:uppercase}
.bar-wrap{display:flex;align-items:center;gap:7px}
.bar-bg{flex:1;height:8px;border-radius:4px;background:#f1f5f9;overflow:hidden;min-width:50px}
.bar-fill{height:100%;border-radius:4px}
.bar-n{font-size:11.5px;font-weight:800;min-width:22px;text-align:right}

/* ════════════════════════════════════════════
   PROPOSAL DETAIL TABLE
════════════════════════════════════════════ */
.prop-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
.prop-tbl thead tr{background:linear-gradient(135deg,#064e3b,#059669)}
.prop-tbl th{padding:10px 10px;text-align:left;color:#fff;
  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
.prop-tbl td{padding:10px 10px;border-bottom:1px solid #f0fdf4;vertical-align:top}
.prop-tbl tbody tr:nth-child(even) td{background:#f8fffe}
.p-n{width:26px;height:26px;border-radius:6px;flex-shrink:0;
  background:linear-gradient(135deg,#059669,#10b981);
  color:#fff;font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center}
.p-judul{font-weight:700;color:#064e3b;line-height:1.4;max-width:220px}
.p-meta{font-size:10px;color:#94a3b8;margin-top:2px}
/* Score bar inline */
.score-bar-wrap{display:flex;align-items:center;gap:6px}
.score-bar-bg{flex:1;height:7px;border-radius:4px;background:#f1f5f9;overflow:hidden;min-width:60px}
.score-bar-fill{height:100%;border-radius:4px}
.score-val{font-size:12px;font-weight:800;min-width:36px;text-align:right}
/* Reviewer mini chips */
.rv-chip{display:inline-flex;align-items:center;gap:4px;
  padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;
  background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;margin:1px}
/* Decision badge */
.dec{display:inline-flex;align-items:center;gap:4px;
  padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap}
.dec-ok  {background:#dcfce7;color:#15803d}
.dec-rm  {background:#fef9c3;color:#92400e}
.dec-rM  {background:#ffedd5;color:#c2410c}
.dec-out {background:#fee2e2;color:#b91c1c}
.dec-proses{background:#d1fae5;color:#065f46}
.dec-perbaikan{background:#ede9fe;color:#5b21b6}

/* ════════════════════════════════════════════
   SIGN-OFF
════════════════════════════════════════════ */
.signoff{padding:24px 36px 28px;background:#f8fffe}
.ttd-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:4px}
.ttd-box{text-align:center}
.ttd-role{font-size:11px;color:#64748b;margin-bottom:52px}
.ttd-line{border-top:1.5px solid #1e293b;padding-top:5px}
.ttd-name{font-size:12.5px;font-weight:700;color:#064e3b}
.ttd-nip{font-size:10.5px;color:#64748b;margin-top:2px}
.sf-footer{display:flex;align-items:center;justify-content:center;gap:10px;
  margin-top:20px;padding-top:14px;border-top:1px solid #d1fae5;
  font-size:10px;color:#94a3b8}
.sf-logo{width:20px;height:20px;object-fit:contain;opacity:.45}

/* ════════════════════════════════════════════
   PRINT
════════════════════════════════════════════ */
@media print{
  @page{size:A4 portrait;margin:10mm 12mm 14mm 12mm}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body{background:#fff;font-size:11px}
  #toolbar{display:none!important}
  #wrap{margin:0;padding:0;max-width:100%}
  .sheet{box-shadow:none;border-radius:0;margin-bottom:0;page-break-after:always}
  .sheet:last-child{page-break-after:avoid}
  .sheet::before{display:none}
  tr,td{page-break-inside:avoid}
  .no-break{page-break-inside:avoid}
  .page-break{page-break-before:always}
  a{color:inherit!important;text-decoration:none!important}
}
</style>
</head>
<body>

<!-- TOOLBAR -->
<div id="toolbar">
  <div class="tb-brand">
    <div class="tb-logo"><img src="<?= $logo_src ?>" alt="IAKN"></div>
    <div>
      <div class="tb-title">Laporan Seleksi Substantif — Usulan Penelitian</div>
      <div class="tb-sub"><?= htmlspecialchars($institusi) ?> &nbsp;·&nbsp; Tahun Anggaran <?= $tahun ?></div>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="penelitian_reviewer.php" class="tb-btn tb-back">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
      Kembali
    </a>
    <button class="tb-btn tb-print" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Cetak / Ekspor PDF
    </button>
  </div>
</div>

<div id="wrap">

<!-- ══════════════════════════════════════════════════
     SHEET 1 — RINGKASAN EKSEKUTIF SUBSTANTIF
══════════════════════════════════════════════════ -->
<div class="sheet">

  <!-- KOP -->
  <div class="kop">
    <img class="kop-logo" src="<?= $logo_src ?>" alt="Logo IAKN Toraja">
    <div class="kop-center">
      <div class="kop-inst"><?= htmlspecialchars($institusi) ?></div>
      <div class="kop-lppm"><?= htmlspecialchars($nama_lppm) ?></div>
      <div class="kop-addr"><?= htmlspecialchars($alamat) ?></div>
    </div>
    <div class="kop-badge">
      <div class="kop-badge-n"><?= $tahun ?></div>
      <div class="kop-badge-l">Tahun<br>Anggaran</div>
    </div>
  </div>

  <!-- BANNER -->
  <div class="banner">
    <div>
      <div class="banner-title">LAPORAN HASIL SELEKSI SUBSTANTIF</div>
      <div class="banner-sub">Penilaian Reviewer &amp; Keputusan Final — Usulan Penelitian Dosen TA <?= $tahun ?></div>
    </div>
    <div class="banner-stamp">
      <div class="banner-stamp-top">Terseleksi</div>
      <div class="banner-stamp-n"><?= $agg['total'] ?></div>
      <div class="banner-stamp-bot">Proposal</div>
    </div>
  </div>

  <!-- META -->
  <div class="meta">
    <div class="meta-p">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Tanggal Cetak: <span class="meta-v"><?= $cetak_tgl ?></span>
    </div>
    <div class="meta-p">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Dicetak oleh: <span class="meta-v"><?= htmlspecialchars($printer) ?></span>
    </div>
    <div class="meta-p">Nilai Rata-Rata: <span class="meta-v"><?= $overall_avg ?> / 100</span></div>
    <div class="meta-p">Ambang Lulus: <span class="meta-v"><?= $lulus_threshold ?></span></div>
    <?php if ($filter_skema): ?>
    <div class="meta-p">Skema: <span class="meta-v"><?= htmlspecialchars(strtoupper($filter_skema)) ?></span></div>
    <?php endif; ?>
  </div>

  <!-- ── A. STATISTIK KEPUTUSAN ── -->
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-bar"></div>
      <div class="sec-title">A. Ringkasan Hasil Keputusan Substantif</div>
      <div class="sec-line"></div>
    </div>

    <!-- 5 stat cards -->
    <div class="stat-master">
      <?php
      $scards = [
        ['n'=>$agg['total'],        'l'=>'Total Proposal',        'sub'=>'memasuki seleksi substantif',
         'bg'=>'linear-gradient(135deg,#064e3b,#059669)',
         'ic'=>'<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>'],
        ['n'=>$agg['disetujui'],    'l'=>'Disetujui',             'sub'=>'lolos tanpa revisi',
         'bg'=>'linear-gradient(135deg,#14532d,#16a34a)',
         'ic'=>'<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'],
        ['n'=>$agg['revisi_minor'], 'l'=>'Revisi Minor',          'sub'=>'perlu penyempurnaan kecil',
         'bg'=>'linear-gradient(135deg,#713f12,#d97706)',
         'ic'=>'<path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>'],
        ['n'=>$agg['revisi_mayor'], 'l'=>'Revisi Mayor',          'sub'=>'perlu perbaikan signifikan',
         'bg'=>'linear-gradient(135deg,#7c2d12,#ea580c)',
         'ic'=>'<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
        ['n'=>$agg['ditolak']+$agg['proses']+$agg['perbaikan'],
         'l'=>'Ditolak / Proses',  'sub'=>'ditolak atau masih berjalan',
         'bg'=>'linear-gradient(135deg,#450a0a,#b91c1c)',
         'ic'=>'<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'],
      ];
      foreach ($scards as $c): ?>
      <div class="sc" style="background:<?= $c['bg'] ?>">
        <div class="sc-n"><?= $c['n'] ?></div>
        <div class="sc-l"><?= $c['l'] ?></div>
        <div class="sc-avg"><?= $c['sub'] ?></div>
        <svg class="sc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><?= $c['ic'] ?></svg>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Donut + legend -->
    <div class="donut-wrap">
      <div class="dr">
        <svg width="164" height="164" viewBox="0 0 164 164">
          <circle cx="82" cy="82" r="62" fill="none" stroke="#f0fdf4" stroke-width="22"/>
          <?php
          $tot = max(1,$agg['total']);
          $segs=[[$agg['disetujui'],'#16a34a'],[$agg['revisi_minor'],'#d97706'],
                 [$agg['revisi_mayor'],'#ea580c'],[$agg['ditolak'],'#b91c1c'],
                 [$agg['proses']+$agg['perbaikan'],'#059669']];
          $ang=-90.0;
          foreach($segs as [$sv,$sc]){echo rvArc($sv,$tot,$ang,$sc); $ang+=$sv/$tot*360;}
          ?>
        </svg>
        <div class="dc">
          <div class="dc-n"><?= $overall_avg ?></div>
          <div class="dc-s">Rata-rata</div>
        </div>
      </div>
      <div class="lg">
        <?php
        $legs=[
          ['Disetujui',    $agg['disetujui'],    '#16a34a'],
          ['Revisi Minor', $agg['revisi_minor'],  '#d97706'],
          ['Revisi Mayor', $agg['revisi_mayor'],  '#ea580c'],
          ['Ditolak',      $agg['ditolak'],       '#b91c1c'],
          ['Sedang Proses',$agg['proses']+$agg['perbaikan'],'#059669'],
        ];
        foreach($legs as[$ln,$lv,$lc]):
          $lp=$tot>0?round($lv/$tot*100):0;
        ?>
        <div class="lg-r">
          <div class="lg-d" style="background:<?=$lc?>"></div>
          <div class="lg-n"><?=$ln?></div>
          <div class="lg-bg"><div class="lg-bar" style="width:<?=$lp?>%;background:<?=$lc?>"></div></div>
          <div class="lg-c" style="color:<?=$lc?>"><?=$lv?></div>
          <div class="lg-p"><?=$lp?>%</div>
        </div>
        <?php endforeach;?>
      </div>
    </div>
  </div>

  <!-- ── B. DISTRIBUSI NILAI ── -->
  <?php if (!empty($all_avg_scores)): ?>
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-bar"></div>
      <div class="sec-title">B. Distribusi Nilai Rata-Rata Reviewer</div>
      <div class="sec-line"></div>
    </div>
    <div style="font-size:11.5px;color:#64748b;margin-bottom:12px">
      Distribusi <?= count($all_avg_scores) ?> proposal yang telah mendapatkan nilai dari reviewer.
      Ambang lulus: <strong style="color:#059669"><?= $lulus_threshold ?></strong>.
    </div>
    <?php
    $dist_max = max(1, max(array_values($nilai_dist)));
    $dist_colors = ['<50'=>'#b91c1c','50–59'=>'#ea580c','60–69'=>'#d97706',
                    '70–79'=>'#16a34a','80–89'=>'#15803d','≥90'=>'#064e3b'];
    ?>
    <div class="hist">
      <?php foreach ($nilai_dist as $range => $cnt):
        $h = $dist_max>0 ? round($cnt/$dist_max*72) : 0;
        $clr = $dist_colors[$range];
      ?>
      <div class="hist-col">
        <div class="hist-cnt" style="color:<?=$clr?>"><?=$cnt?></div>
        <div class="hist-bar" style="height:<?=max(4,$h)?>px;background:<?=$clr?>"></div>
        <div class="hist-lbl"><?=$range?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:center;gap:4px;margin-top:10px;flex-wrap:wrap">
      <?php foreach ($nilai_dist as $range => $cnt): ?>
      <span style="font-size:10.5px;padding:2px 8px;border-radius:10px;
        background:<?=$dist_colors[$range]?>20;color:<?=$dist_colors[$range]?>;font-weight:600">
        <?=$range?>: <?=$cnt?> proposal
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── C. ANALISIS RUBRIK ── -->
  <?php if ($rubrik_skor_cnt > 0): ?>
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-bar"></div>
      <div class="sec-title">C. Kinerja Rata-Rata per Kriteria Penilaian</div>
      <div class="sec-line"></div>
    </div>
    <div style="font-size:11.5px;color:#64748b;margin-bottom:14px">
      Rata-rata skor tiap kriteria dari <?=$rubrik_skor_cnt?> proposal yang telah dinilai reviewer.
    </div>
    <div class="rubrik-grid">
      <?php for($ki=0;$ki<$N;$ki++):
        $avg_skor = $rubrik_skor_cnt>0 ? $rubrik_skor_sum[$ki]/$rubrik_skor_cnt : 0;
        $skor_max = $SKOR_MAX[$ki];
        $bobot    = $BOBOT[$ki];
        $pct_bar  = $skor_max>0 ? round($avg_skor/$skor_max*100) : 0;
        $nilai_w  = $skor_max>0 ? round(($bobot/$skor_max)*$avg_skor,1) : 0;
        $bar_col  = $pct_bar>=80?'#16a34a':($pct_bar>=60?'#d97706':'#dc2626');
      ?>
      <div class="rub-row">
        <div class="rub-num"><?=$ki+1?></div>
        <div class="rub-body">
          <div class="rub-name"><?=htmlspecialchars($KRITERIA[$ki])?></div>
          <div style="font-size:9.5px;color:#94a3b8;margin-top:1px">Bobot <?=$bobot?>%</div>
          <div class="rub-bar-wrap">
            <div class="rub-bar" style="width:<?=$pct_bar?>%;background:<?=$bar_col?>"></div>
          </div>
        </div>
        <div>
          <div class="rub-score" style="color:<?=$bar_col?>"><?=round($avg_skor,1)?></div>
          <div class="rub-max">/ <?=$skor_max?></div>
          <div style="font-size:9px;color:#94a3b8;text-align:right;margin-top:1px"><?=$nilai_w?> poin</div>
        </div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── D. PER SKEMA ── -->
  <?php if (count($per_skema)>=1): ?>
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-bar"></div>
      <div class="sec-title">D. Distribusi Hasil per Skema</div>
      <div class="sec-line"></div>
    </div>
    <table class="sk-tbl">
      <thead>
        <tr>
          <th>Skema</th><th style="text-align:center">Total</th>
          <th style="min-width:110px">Disetujui</th>
          <th style="min-width:110px">Revisi Minor</th>
          <th style="min-width:110px">Revisi Mayor</th>
          <th style="min-width:110px">Ditolak</th>
          <th style="text-align:center">% Lolos</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($per_skema as $sk=>$sd):
        $sk_lolos=$sd['disetujui']+$sd['revisi_minor'];
        $sk_pct=$sd['total']>0?round($sk_lolos/$sd['total']*100):0;
        $sk_bg=strtolower($sk)==='nasional'?'#dcfce7':'#dbeafe';
        $sk_cl=strtolower($sk)==='nasional'?'#15803d':'#1e40af';
      ?>
      <tr>
        <td><span class="sk-pill" style="background:<?=$sk_bg?>;color:<?=$sk_cl?>"><?=htmlspecialchars(strtoupper($sk))?></span></td>
        <td style="text-align:center;font-weight:800;font-size:13px"><?=$sd['total']?></td>
        <td><div class="bar-wrap"><div class="bar-bg"><div class="bar-fill" style="width:<?=$sd['total']>0?round($sd['disetujui']/$sd['total']*100):0?>%;background:#16a34a"></div></div><div class="bar-n" style="color:#15803d"><?=$sd['disetujui']?></div></div></td>
        <td><div class="bar-wrap"><div class="bar-bg"><div class="bar-fill" style="width:<?=$sd['total']>0?round($sd['revisi_minor']/$sd['total']*100):0?>%;background:#d97706"></div></div><div class="bar-n" style="color:#92400e"><?=$sd['revisi_minor']?></div></div></td>
        <td><div class="bar-wrap"><div class="bar-bg"><div class="bar-fill" style="width:<?=$sd['total']>0?round($sd['revisi_mayor']/$sd['total']*100):0?>%;background:#ea580c"></div></div><div class="bar-n" style="color:#c2410c"><?=$sd['revisi_mayor']?></div></div></td>
        <td><div class="bar-wrap"><div class="bar-bg"><div class="bar-fill" style="width:<?=$sd['total']>0?round($sd['ditolak']/$sd['total']*100):0?>%;background:#dc2626"></div></div><div class="bar-n" style="color:#b91c1c"><?=$sd['ditolak']?></div></div></td>
        <td style="text-align:center">
          <span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:800;
            background:<?=$sk_pct>=70?'#dcfce7':($sk_pct>=40?'#fef9c3':'#fee2e2')?>;
            color:<?=$sk_pct>=70?'#15803d':($sk_pct>=40?'#92400e':'#b91c1c')?>"><?=$sk_pct?>%</span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- SIGN-OFF sheet 1 -->
  <div class="signoff no-break">
    <div style="text-align:right;font-size:12px;color:#64748b;margin-bottom:20px">
      <?=$cetak_loc?>, <strong style="color:#064e3b"><?=$cetak_tgl?></strong>
    </div>
    <div class="ttd-grid">
      <div class="ttd-box">
        <div class="ttd-role">Diperiksa oleh,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?=htmlspecialchars($printer)?></div>
          <div class="ttd-nip">Admin LPPM</div>
        </div>
      </div>
      <div class="ttd-box">
        <div class="ttd-role">Mengetahui,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?=htmlspecialchars($ketua_lppm)?></div>
          <div class="ttd-nip"><?=$nip_ketua?'NIP. '.htmlspecialchars($nip_ketua):'Ketua LPPM IAKN Toraja'?></div>
        </div>
      </div>
    </div>
    <div class="sf-footer">
      <img class="sf-logo" src="<?=$logo_src?>" alt="">
      <?=htmlspecialchars($institusi)?> — <?=htmlspecialchars($nama_lppm)?>
      &nbsp;·&nbsp; Dicetak: <?=$cetak_tgl?>
    </div>
  </div>

</div><!-- /sheet 1 -->

<!-- ══════════════════════════════════════════════════
     SHEET 2 — DETAIL PROPOSAL & NILAI REVIEWER
══════════════════════════════════════════════════ -->
<div class="sheet page-break">

  <!-- KOP mini -->
  <div class="kop" style="padding:14px 36px 12px">
    <img class="kop-logo" src="<?=$logo_src?>" alt="Logo IAKN Toraja" style="width:54px;height:54px">
    <div class="kop-center">
      <div class="kop-inst" style="font-size:13.5px"><?=htmlspecialchars($institusi)?></div>
      <div class="kop-lppm" style="font-size:11px"><?=htmlspecialchars($nama_lppm)?></div>
    </div>
    <div class="kop-badge" style="padding:6px 8px">
      <div class="kop-badge-n" style="font-size:16px"><?=$tahun?></div>
      <div class="kop-badge-l" style="font-size:8.5px">Tahun<br>Anggaran</div>
    </div>
  </div>

  <div class="banner" style="padding:14px 36px">
    <div>
      <div class="banner-title" style="font-size:14px">DAFTAR PENILAIAN REVIEWER DAN KEPUTUSAN SUBSTANTIF</div>
      <div class="banner-sub">Nilai per Proposal (Reviewer A &amp; B, Anonim) — TA <?=$tahun?> &nbsp;·&nbsp; Ambang Lulus: <?=$lulus_threshold?></div>
    </div>
    <div class="banner-stamp"><div class="banner-stamp-top">Halaman</div><div class="banner-stamp-n">2</div><div class="banner-stamp-bot">Lampiran</div></div>
  </div>

  <div class="sec" style="padding-bottom:8px">

    <!-- Rubrik legend -->
    <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <span style="font-size:11px;color:#064e3b;font-weight:700;flex-shrink:0;padding-top:2px">Kriteria:</span>
      <?php for($ki=0;$ki<$N;$ki++): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;color:#475569">
        <span style="background:linear-gradient(135deg,#064e3b,#059669);color:#fff;
               width:16px;height:16px;border-radius:4px;display:inline-flex;
               align-items:center;justify-content:center;font-size:9px;font-weight:800"><?=$ki+1?></span>
        <?=htmlspecialchars($KRITERIA[$ki])?>
        <span style="color:#94a3b8">(B<?=$BOBOT[$ki]?>%)</span>
      </span>
      <?php endfor; ?>
    </div>

    <table class="prop-tbl">
      <thead>
        <tr>
          <th style="width:30px">#</th>
          <th style="min-width:200px">Judul Proposal</th>
          <th>Skema</th>
          <th>Ketua</th>
          <th style="min-width:200px">Nilai Reviewer (A / B)</th>
          <th style="min-width:90px">Nilai Rata²</th>
          <th>Keputusan</th>
        </tr>
      </thead>
      <tbody>
      <?php if(empty($proposals)): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:#94a3b8">
        Tidak ada data proposal seleksi substantif untuk tahun <?=$tahun?>.
      </td></tr>
      <?php else: foreach($proposals as $i=>$p):
        $rvs = $rev_scores[$p['id']] ?? [];
        $has_scores = !empty($rvs);
        $avg_nilai  = $has_scores
            ? round(array_sum(array_column($rvs,'nilai_total'))/count($rvs),1)
            : null;
        $bar_clr    = $avg_nilai!==null ? scoreColor($avg_nilai,$lulus_threshold) : '#94a3b8';
        $bar_pct    = $avg_nilai!==null ? min(100,$avg_nilai) : 0;

        [$dc,$dl,$di] = match($p['status']) {
            'disetujui'            => ['dec-ok',      'Disetujui',    '✓'],
            'revisi_minor'         => ['dec-rm',      'Revisi Minor', '↻'],
            'revisi_mayor'         => ['dec-rM',      'Revisi Mayor', '↻'],
            'ditolak'              => ['dec-out',     'Ditolak',      '✗'],
            'perbaikan_substantif' => ['dec-perbaikan','Perbaikan',   '↩'],
            default                => ['dec-proses',  'Proses',       '⋯'],
        };
      ?>
      <tr>
        <td><div class="p-n"><?=$i+1?></div></td>
        <td>
          <div class="p-judul"><?=htmlspecialchars(mb_strimwidth($p['judul'],0,80,'…'))?></div>
          <div class="p-meta">
            <?=htmlspecialchars($p['program_studi']??'-')?>
            <?php if($p['reviewed_at']): ?> · <?=date('d/m/Y',strtotime($p['reviewed_at']))?><?php endif; ?>
          </div>
        </td>
        <td>
          <span class="sk-pill" style="font-size:10px;
            background:<?=strtolower($p['skema'])=='nasional'?'#dcfce7':'#dbeafe'?>;
            color:<?=strtolower($p['skema'])=='nasional'?'#15803d':'#1e40af'?>">
            <?=htmlspecialchars(strtoupper($p['skema']))?>
          </span>
        </td>
        <td>
          <div style="font-weight:700;font-size:11.5px;color:#064e3b"><?=htmlspecialchars($p['nama_lengkap'])?></div>
          <div style="font-size:10px;color:#94a3b8"><?=htmlspecialchars($p['nidn']??'-')?></div>
        </td>
        <td>
          <?php if (!$has_scores): ?>
          <span style="color:#94a3b8;font-size:10.5px">Belum dinilai</span>
          <?php else: ?>
          <?php foreach($rvs as $ri=>$rv): ?>
          <div style="margin-bottom:<?=$ri<count($rvs)-1?'6':'0'?>px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
              <span style="background:#d1fae5;color:#065f46;padding:1px 7px;border-radius:10px;
                     font-size:9.5px;font-weight:700">Rev.<?=chr(65+$ri)?></span>
              <span style="font-size:12px;font-weight:800;color:<?=scoreColor((float)$rv['nilai_total'],$lulus_threshold)?>"><?=round($rv['nilai_total'],1)?></span>
              <?php if($rv['keputusan']): ?>
              <span style="font-size:9.5px;color:#94a3b8">(<?=htmlspecialchars($rv['keputusan'])?>)</span>
              <?php endif; ?>
            </div>
            <!-- Per-kriteria mini dots -->
            <div style="display:flex;gap:2px;flex-wrap:wrap">
              <?php for($ki=0;$ki<$N;$ki++):
                $sk = $rv['skors'][$ki]??0;
                $sk_max = $SKOR_MAX[$ki];
                $sk_pct2 = $sk_max>0?round($sk/$sk_max*100):0;
                $sk_c = $sk_pct2>=80?'#15803d':($sk_pct2>=60?'#d97706':'#dc2626');
              ?>
              <span title="<?=htmlspecialchars($KRITERIA[$ki])?>: <?=$sk?>/<?=$sk_max?>"
                style="font-size:9px;padding:1px 4px;border-radius:3px;font-weight:700;
                       background:<?=$sk_c?>20;color:<?=$sk_c?>;border:1px solid <?=$sk_c?>30">
                <?=$ki+1?>:<?=$sk?>
              </span>
              <?php endfor; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if($avg_nilai!==null): ?>
          <div class="score-bar-wrap">
            <div class="score-bar-bg">
              <div class="score-bar-fill" style="width:<?=$bar_pct?>%;background:<?=$bar_clr?>"></div>
            </div>
          </div>
          <div class="score-val" style="color:<?=$bar_clr?>"><?=$avg_nilai?></div>
          <div style="font-size:9.5px;color:#94a3b8;text-align:right">
            <?=$avg_nilai>=$lulus_threshold?'✓ Lulus':'✗ Tidak Lulus'?>
          </div>
          <?php else: ?>
          <span style="color:#94a3b8;font-size:10.5px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="dec <?=$dc?>"><?=$di?> <?=$dl?></span>
          <?php if($p['catatan_reviewer']): ?>
          <div style="font-size:10px;color:#64748b;margin-top:4px;line-height:1.4;max-width:130px">
            <?=htmlspecialchars(mb_strimwidth($p['catatan_reviewer'],0,80,'…'))?>
          </div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- SIGN-OFF sheet 2 -->
  <div class="signoff no-break">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:18px">
      <div style="font-size:11.5px;color:#475569;line-height:1.8">
        <strong style="color:#064e3b">Keterangan:</strong><br>
        · Rev.A / Rev.B = Reviewer pertama dan kedua (anonim)<br>
        · Angka di sel kriteria = Skor mentah (misal: 1:4 = Kriteria 1 skor 4)<br>
        · Nilai rata-rata = Rata-rata dari semua reviewer yang menilai
      </div>
      <div style="text-align:right">
        <div style="font-size:12px;color:#64748b">
          <?=$cetak_loc?>, <strong style="color:#064e3b"><?=$cetak_tgl?></strong>
        </div>
      </div>
    </div>
    <div class="ttd-grid">
      <div class="ttd-box">
        <div class="ttd-role">Diperiksa oleh,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?=htmlspecialchars($printer)?></div>
          <div class="ttd-nip">Admin LPPM</div>
        </div>
      </div>
      <div class="ttd-box">
        <div class="ttd-role">Mengetahui,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?=htmlspecialchars($ketua_lppm)?></div>
          <div class="ttd-nip"><?=$nip_ketua?'NIP. '.htmlspecialchars($nip_ketua):'Ketua LPPM IAKN Toraja'?></div>
        </div>
      </div>
    </div>
    <div class="sf-footer">
      <img class="sf-logo" src="<?=$logo_src?>" alt="">
      <?=htmlspecialchars($institusi)?> — <?=htmlspecialchars($nama_lppm)?>
      &nbsp;·&nbsp; Dicetak oleh <?=htmlspecialchars($printer)?> pada <?=$cetak_tgl?>
    </div>
  </div>

</div><!-- /sheet 2 -->

</div><!-- /wrap -->
</body>
</html>
