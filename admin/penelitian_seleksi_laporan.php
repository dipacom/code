<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang  = $_COOKIE['lang'] ?? 'id';
$tahun = (int)(getSetting($pdo, 'penelitian_tahun') ?: date('Y'));

/* ── Checklist items ─────────────────────────────────────────── */
$CL = [
    ['key'=>'file_terupload',   'short'=>'File Upload',    'label'=>'File proposal sudah diunggah'],
    ['key'=>'format_nama_file', 'short'=>'Format File',    'label'=>'Nama file sesuai format yang ditentukan'],
    ['key'=>'nidn_lengkap',     'short'=>'NIDN Lengkap',   'label'=>'NIDN / data identitas ketua & anggota lengkap'],
    ['key'=>'scholar_sinta',    'short'=>'Scholar/SINTA',  'label'=>'Ketua memiliki akun Google Scholar & SINTA'],
    ['key'=>'jabatan_min',      'short'=>'Jabatan',        'label'=>'Jabatan fungsional ketua memenuhi syarat minimum skema'],
    ['key'=>'komposisi_tim',    'short'=>'Tim',            'label'=>'Komposisi tim (dosen/mahasiswa) sesuai skema'],
    ['key'=>'homebase_sama',    'short'=>'Homebase',       'label'=>'Ketua dan anggota dari homebase/fakultas yang sama'],
    ['key'=>'sesuai_template',  'short'=>'Template',       'label'=>'Proposal menggunakan template yang disediakan LPPM'],
    ['key'=>'pernyataan_ok',    'short'=>'Pernyataan',     'label'=>'Pernyataan kesanggupan sudah disetujui pengusul'],
];

/* ── Filter ──────────────────────────────────────────────────── */
$filter_skema = clean($_GET['skema'] ?? '');

/* ── Query semua proposal yang telah diseleksi ───────────────── */
$lanjut_st = ['penandatanganan_kontrak','seleksi_substansi','disetujui',
              'revisi_minor','revisi_mayor','ditolak','perbaikan_substantif'];
$all_done  = array_merge(['lolos_admin','gagal_admin','perbaikan_admin'], $lanjut_st);
$in_ph     = implode(',', array_fill(0, count($all_done), '?'));

$params = $all_done;
$extra  = "up.tahun_anggaran=$tahun AND up.deleted_at IS NULL AND up.status IN ($in_ph)";
if ($filter_skema) { $extra .= " AND up.skema=?"; $params[] = $filter_skema; }

$proposals = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.created_at, up.file_proposal,
           u.nama_lengkap, u.nidn, u.program_studi, u.jabatan_fungsional,
           sa.keputusan AS sa_keputusan, sa.checklist AS sa_checklist,
           sa.catatan AS sa_catatan, sa.created_at AS sa_tgl
    FROM usulan_penelitian up
    JOIN  users u  ON up.user_id  = u.id
    LEFT JOIN seleksi_admin sa ON sa.usulan_id = up.id
    WHERE $extra
    ORDER BY up.skema ASC, sa.created_at ASC
");
$proposals->execute($params);
$proposals = $proposals->fetchAll();

/* ── Agregat ──────────────────────────────────────────────────── */
$agg = ['total'=>0,'lolos'=>0,'gagal'=>0,'perbaikan'=>0,'lanjut'=>0];
$per_skema = [];
$cl_fail   = array_fill_keys(array_column($CL,'key'), 0);

foreach ($proposals as $p) {
    $agg['total']++;
    $sk = $p['skema'] ?: '—';
    if (!isset($per_skema[$sk])) $per_skema[$sk] = ['lolos'=>0,'gagal'=>0,'perbaikan'=>0,'lanjut'=>0,'total'=>0];
    $per_skema[$sk]['total']++;

    if (in_array($p['status'], $lanjut_st))       { $agg['lanjut']++;     $per_skema[$sk]['lanjut']++; }
    elseif ($p['status']==='lolos_admin')          { $agg['lolos']++;      $per_skema[$sk]['lolos']++; }
    elseif ($p['status']==='gagal_admin')          { $agg['gagal']++;      $per_skema[$sk]['gagal']++; }
    elseif ($p['status']==='perbaikan_admin')      { $agg['perbaikan']++;  $per_skema[$sk]['perbaikan']++; }

    if ($p['sa_checklist']) {
        $cl = json_decode($p['sa_checklist'], true) ?? [];
        foreach ($cl as $k => $v) if ($v===false && isset($cl_fail[$k])) $cl_fail[$k]++;
    }
}
$lolos_total = $agg['lolos'] + $agg['lanjut'];

/* ── Institusi settings ───────────────────────────────────────── */
$cfg_keys = ['nama_institusi','nama_lppm','alamat_institusi','nama_ketua_lppm','nip_ketua_lppm'];
$cfg = [];
foreach ($cfg_keys as $k) $cfg[$k] = getSetting($pdo, $k) ?: '';
$institusi   = $cfg['nama_institusi'] ?: 'Institut Agama Kristen Negeri (IAKN) Toraja';
$nama_lppm   = $cfg['nama_lppm']      ?: 'Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM)';
$alamat      = $cfg['alamat_institusi'] ?: 'Jl. Nusantara No. 1, Makale, Tana Toraja, Sulawesi Selatan';
$ketua_lppm  = $cfg['nama_ketua_lppm'] ?: '____________________';
$nip_ketua   = $cfg['nip_ketua_lppm']  ?: '';

$printer     = $_SESSION['nama'] ?? 'Admin';
$cetak_tgl   = date('d F Y');
$cetak_loc   = 'Tana Toraja';

/* ── SVG Donut arc ────────────────────────────────────────────── */
function arc(float $val, float $tot, float $a0, string $col, float $r=60,$cx=80,$cy=80,$sw=20): string {
    if ($tot<=0||$val<=0) return '';
    $deg = min(359.99, $val/$tot*360);
    $r1  = deg2rad($a0); $r2 = deg2rad($a0+$deg);
    [$x1,$y1] = [$cx+$r*cos($r1), $cy+$r*sin($r1)];
    [$x2,$y2] = [$cx+$r*cos($r2), $cy+$r*sin($r2)];
    $lf = $deg>180?1:0;
    return "<path d='M $x1 $y1 A $r $r 0 $lf 1 $x2 $y2'"
          ." fill='none' stroke='$col' stroke-width='$sw' stroke-linecap='butt'/>";
}

$logo_src = BASE_URL . '/assets/img/logo_kiri.jpg';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laporan Seleksi Administratif — <?= $tahun ?></title>
<style>
/* ════════════════════════════════════════
   BASE
════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:13px}
body{font-family:'Segoe UI',Calibri,Arial,sans-serif;color:#1a1a2e;background:#eef2f7;line-height:1.5}

/* ════════════════════════════════════════
   SCREEN: FLOATING TOOLBAR
════════════════════════════════════════ */
#toolbar{
  position:fixed;top:0;left:0;right:0;z-index:999;
  background:linear-gradient(135deg,#0d1b3e 0%,#1565C0 65%,#1976D2 100%);
  padding:0 24px;height:56px;
  display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 2px 20px rgba(21,101,192,.4);
}
#toolbar .tb-left{display:flex;align-items:center;gap:14px}
#toolbar .tb-icon{width:34px;height:34px;border-radius:8px;overflow:hidden;flex-shrink:0}
#toolbar .tb-icon img{width:100%;height:100%;object-fit:cover}
#toolbar h1{font-size:13.5px;font-weight:700;color:#fff;letter-spacing:.2px}
#toolbar small{display:block;font-size:10.5px;color:rgba(255,255,255,.65);margin-top:1px}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;
  font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
.tb-btn:hover{opacity:.88}
.tb-print{background:#fff;color:#1565C0}
.tb-back {background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)}

/* ════════════════════════════════════════
   PAGE WRAP
════════════════════════════════════════ */
#wrap{max-width:1020px;margin:72px auto 48px;padding:0 16px}

/* ════════════════════════════════════════
   REPORT SHEET (simulates A4 paper)
════════════════════════════════════════ */
.sheet{
  background:#fff;
  border-radius:4px;
  box-shadow:0 1px 3px rgba(0,0,0,.08),0 8px 40px rgba(0,0,0,.10);
  margin-bottom:20px;
  overflow:hidden;
  position:relative;
}

/* ── Top accent bar ── */
.sheet::before{
  content:'';display:block;height:5px;
  background:linear-gradient(90deg,#0d1b3e 0%,#1565C0 40%,#D97706 60%,#F59E0B 100%);
}

/* ════════════════════════════════════════
   KOP SURAT (Letterhead)
════════════════════════════════════════ */
.kop{
  padding:20px 36px 16px;
  border-bottom:3px double #1565C0;
  display:grid;
  grid-template-columns:72px 1fr 90px;
  align-items:center;
  gap:16px;
}
.kop-logo{width:72px;height:72px;object-fit:contain}
.kop-text{text-align:center}
.kop-inst{
  font-size:16.5px;font-weight:800;letter-spacing:.4px;
  color:#0d1b3e;text-transform:uppercase;line-height:1.25;
}
.kop-lppm{font-size:12px;font-weight:600;color:#1565C0;margin-top:3px}
.kop-addr{font-size:10px;color:#64748b;margin-top:2px;line-height:1.4}
.kop-tahun{
  text-align:center;
  background:linear-gradient(135deg,#0d1b3e,#1565C0);
  color:#fff;border-radius:8px;padding:8px 10px;
}
.kop-tahun-num{font-size:20px;font-weight:900;line-height:1}
.kop-tahun-lbl{font-size:9.5px;margin-top:3px;opacity:.8;text-transform:uppercase;letter-spacing:.5px}

/* ════════════════════════════════════════
   REPORT TITLE BANNER
════════════════════════════════════════ */
.rpt-banner{
  background:linear-gradient(135deg,#0d1b3e 0%,#1565C0 50%,#1976D2 100%);
  color:#fff;padding:22px 36px;
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  position:relative;overflow:hidden;
}
.rpt-banner::after{
  content:'';position:absolute;right:-30px;top:-30px;
  width:160px;height:160px;border-radius:50%;
  background:rgba(255,255,255,.05);
}
.rpt-banner::before{
  content:'';position:absolute;right:80px;bottom:-40px;
  width:100px;height:100px;border-radius:50%;
  background:rgba(245,158,11,.08);
}
.rpt-title{font-size:17px;font-weight:800;letter-spacing:.3px;line-height:1.3}
.rpt-subtitle{font-size:11.5px;opacity:.75;margin-top:4px}
.rpt-stamp{
  flex-shrink:0;background:rgba(255,255,255,.12);
  border:1.5px solid rgba(255,255,255,.25);
  border-radius:10px;padding:10px 16px;text-align:center;z-index:1;
}
.rpt-stamp-top{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:.8px}
.rpt-stamp-num{font-size:22px;font-weight:900;line-height:1;margin:2px 0}
.rpt-stamp-bot{font-size:9px;opacity:.7;text-transform:uppercase;letter-spacing:.5px}

/* ════════════════════════════════════════
   META STRIP
════════════════════════════════════════ */
.meta-strip{
  background:#f8fafc;border-bottom:1.5px solid #e2e8f0;
  padding:9px 36px;display:flex;gap:0;flex-wrap:wrap;
}
.meta-pill{
  display:flex;align-items:center;gap:7px;
  padding:0 18px 0 0;border-right:1px solid #e2e8f0;margin-right:18px;
  font-size:11px;color:#64748b;
}
.meta-pill:last-child{border-right:none}
.meta-pill svg{opacity:.5}
.meta-val{font-weight:700;color:#1e293b}

/* ════════════════════════════════════════
   SECTION
════════════════════════════════════════ */
.sec{padding:24px 36px;border-bottom:1px solid #f1f5f9}
.sec:last-child{border-bottom:none}
.sec-hd{
  display:flex;align-items:center;gap:10px;margin-bottom:18px;
}
.sec-hd-bar{width:4px;height:20px;border-radius:2px;flex-shrink:0;
  background:linear-gradient(to bottom,#1565C0,#D97706)}
.sec-hd-title{
  font-size:12.5px;font-weight:800;color:#0d1b3e;
  text-transform:uppercase;letter-spacing:.7px;
}
.sec-hd-line{flex:1;height:1px;background:linear-gradient(to right,#dbeafe,transparent)}

/* ════════════════════════════════════════
   STAT CARDS
════════════════════════════════════════ */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.stat-card{
  border-radius:12px;padding:18px 16px;
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;
}
.stat-card::after{
  content:'';position:absolute;right:-14px;top:-14px;
  width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.12)
}
.stat-n{font-size:36px;font-weight:900;color:#fff;line-height:1}
.stat-l{font-size:11px;font-weight:600;color:rgba(255,255,255,.85);margin-top:5px;line-height:1.3}
.stat-p{font-size:10px;color:rgba(255,255,255,.6);margin-top:3px}
.stat-icon{
  position:absolute;bottom:10px;right:12px;
  width:28px;height:28px;opacity:.2;
}

/* ════════════════════════════════════════
   DONUT CHART
════════════════════════════════════════ */
.chart-wrap{display:grid;grid-template-columns:160px 1fr;gap:28px;align-items:center}
.donut-rel{position:relative;width:160px;height:160px}
.donut-ctr{
  position:absolute;inset:0;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:2px;
}
.donut-n{font-size:28px;font-weight:900;color:#0d1b3e}
.donut-s{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.legend{display:flex;flex-direction:column;gap:12px}
.leg-row{display:flex;align-items:center;gap:10px}
.leg-dot{width:11px;height:11px;border-radius:3px;flex-shrink:0}
.leg-name{font-size:12.5px;font-weight:600;color:#1e293b;min-width:140px}
.leg-cnt{font-size:11.5px;font-weight:800;min-width:30px;text-align:right}
.leg-pct{font-size:10.5px;color:#94a3b8;min-width:38px;text-align:right}
.leg-bar-bg{flex:1;height:8px;border-radius:4px;background:#f1f5f9;overflow:hidden}
.leg-bar{height:100%;border-radius:4px;transition:width .5s}

/* ════════════════════════════════════════
   SKEMA TABLE (Visual bars)
════════════════════════════════════════ */
.sk-tbl{width:100%;border-collapse:collapse;font-size:12px}
.sk-tbl th{
  font-size:10.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;
  padding:8px 10px;border-bottom:2px solid #e2e8f0;text-align:left;white-space:nowrap;
}
.sk-tbl td{padding:10px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.sk-tbl tr:last-child td{border-bottom:none}
.sk-pill{
  display:inline-block;padding:2px 10px;border-radius:20px;
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;
}
.sk-bar-wrap{display:flex;align-items:center;gap:8px}
.sk-bar-outer{flex:1;height:9px;border-radius:5px;background:#f1f5f9;overflow:hidden;min-width:60px}
.sk-bar-inner{height:100%;border-radius:5px}
.sk-num{font-size:12px;font-weight:800;min-width:24px;text-align:right}

/* ════════════════════════════════════════
   CHECKLIST FAIL GRID
════════════════════════════════════════ */
.cl-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.cl-row{
  display:flex;align-items:center;gap:10px;
  padding:9px 12px;border-radius:8px;
  border:1px solid #e2e8f0;background:#fafafa;
}
.cl-idx{
  width:22px;height:22px;border-radius:5px;flex-shrink:0;
  background:#1565C0;color:#fff;font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
}
.cl-body{flex:1;min-width:0}
.cl-lbl{font-size:11.5px;font-weight:600;color:#1e293b;line-height:1.3}
.cl-bar-wrap{height:5px;border-radius:3px;background:#fee2e2;overflow:hidden;margin-top:5px}
.cl-bar{height:100%;border-radius:3px;background:#ef4444}
.cl-num{font-size:12px;font-weight:800;color:#dc2626;flex-shrink:0}

/* ════════════════════════════════════════
   PROPOSAL TABLE
════════════════════════════════════════ */
.prop-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
.prop-tbl thead tr{background:linear-gradient(135deg,#0d1b3e,#1565C0)}
.prop-tbl th{
  padding:10px 10px;text-align:left;color:#fff;
  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;
  white-space:nowrap;
}
.prop-tbl td{padding:10px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.prop-tbl tbody tr:nth-child(even) td{background:#f8fafc}
.prop-tbl tbody tr:hover td{background:#eff6ff}
.p-num{
  width:26px;height:26px;border-radius:6px;
  background:linear-gradient(135deg,#1565C0,#1976D2);
  color:#fff;font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
}
.p-judul{font-weight:700;color:#0d1b3e;line-height:1.4;max-width:230px}
.p-meta{font-size:10px;color:#94a3b8;margin-top:2px}
.p-nidn{font-size:10.5px;color:#64748b}
/* Checklist micro-grid */
.cl-micro{display:flex;flex-wrap:wrap;gap:3px;max-width:120px}
.cl-dot{
  width:14px;height:14px;border-radius:3px;
  display:flex;align-items:center;justify-content:center;
  font-size:8.5px;font-weight:800;flex-shrink:0;
}
.cl-dot-ok{background:#dcfce7;color:#15803d}
.cl-dot-no{background:#fee2e2;color:#b91c1c}
.cl-dot-na{background:#f1f5f9;color:#94a3b8}
.cl-score{font-size:9.5px;color:#94a3b8;margin-top:3px}
/* Decision badge */
.dec{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap;
}
.dec-lolos   {background:#dcfce7;color:#15803d}
.dec-lanjut  {background:#dbeafe;color:#1e40af}
.dec-gagal   {background:#fee2e2;color:#b91c1c}
.dec-perbaikan{background:#ede9fe;color:#5b21b6}

/* ════════════════════════════════════════
   SIGN-OFF
════════════════════════════════════════ */
.signoff{padding:28px 36px 32px;background:#fafbfc}
.signoff-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:28px;
}
.signoff-place{font-size:12.5px;color:#64748b}
.signoff-place strong{color:#0d1b3e}
.ttd-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px}
.ttd-box{text-align:center}
.ttd-role{font-size:11px;color:#64748b;margin-bottom:56px}
.ttd-line{border-top:1.5px solid #1e293b;padding-top:5px}
.ttd-name{font-size:12.5px;font-weight:700;color:#0d1b3e}
.ttd-nip {font-size:10.5px;color:#64748b;margin-top:2px}
.signoff-footer{
  display:flex;align-items:center;justify-content:center;gap:10px;
  margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;
  font-size:10px;color:#94a3b8;
}
.signoff-footer .sf-logo{
  width:22px;height:22px;opacity:.5;object-fit:contain;
}

/* ════════════════════════════════════════
   PRINT
════════════════════════════════════════ */
@media print {
  @page{size:A4 portrait;margin:10mm 12mm 14mm 12mm}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body{background:#fff;font-size:11px}
  #toolbar{display:none!important}
  #wrap{margin:0;padding:0;max-width:100%}
  .sheet{box-shadow:none;border-radius:0;margin-bottom:0;page-break-after:always}
  .sheet:last-child{page-break-after:avoid}
  .sheet::before{display:none}  /* no top gradient bar on print */
  .stat-card,.rpt-banner,.kop-tahun{-webkit-print-color-adjust:exact!important}
  .prop-tbl thead tr{-webkit-print-color-adjust:exact!important}
  tr,td{page-break-inside:avoid}
  .no-break{page-break-inside:avoid}
  .page-break{page-break-before:always}
  a{color:inherit!important;text-decoration:none!important}
}
</style>
</head>
<body>

<!-- ════════ TOOLBAR ════════ -->
<div id="toolbar">
  <div class="tb-left">
    <div class="tb-icon"><img src="<?= $logo_src ?>" alt="logo"></div>
    <div>
      <h1>Laporan Seleksi Administratif — Usulan Penelitian</h1>
      <small><?= htmlspecialchars($institusi) ?> &nbsp;·&nbsp; Tahun Anggaran <?= $tahun ?></small>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="penelitian_seleksi.php" class="tb-btn tb-back">
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

<!-- ═══════════════════════════════════════════
     SHEET 1 — RINGKASAN EKSEKUTIF
═══════════════════════════════════════════ -->
<div class="sheet">

  <!-- KOP SURAT -->
  <div class="kop">
    <img class="kop-logo" src="<?= $logo_src ?>" alt="Logo IAKN Toraja">
    <div class="kop-text">
      <div class="kop-inst"><?= htmlspecialchars($institusi) ?></div>
      <div class="kop-lppm"><?= htmlspecialchars($nama_lppm) ?></div>
      <div class="kop-addr"><?= htmlspecialchars($alamat) ?></div>
    </div>
    <div class="kop-tahun">
      <div class="kop-tahun-num"><?= $tahun ?></div>
      <div class="kop-tahun-lbl">Tahun<br>Anggaran</div>
    </div>
  </div>

  <!-- REPORT BANNER -->
  <div class="rpt-banner">
    <div>
      <div class="rpt-title">LAPORAN HASIL SELEKSI ADMINISTRATIF</div>
      <div class="rpt-subtitle">Usulan Penelitian Dosen &nbsp;·&nbsp; Tahun Anggaran <?= $tahun ?></div>
    </div>
    <div class="rpt-stamp">
      <div class="rpt-stamp-top">Total Terseleksi</div>
      <div class="rpt-stamp-num"><?= $agg['total'] ?></div>
      <div class="rpt-stamp-bot">Proposal</div>
    </div>
  </div>

  <!-- META STRIP -->
  <div class="meta-strip">
    <div class="meta-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Tanggal Cetak: <span class="meta-val"><?= $cetak_tgl ?></span>
    </div>
    <div class="meta-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Dicetak oleh: <span class="meta-val"><?= htmlspecialchars($printer) ?></span>
    </div>
    <div class="meta-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
      Total Proposal: <span class="meta-val"><?= $agg['total'] ?> proposal</span>
    </div>
    <?php if ($filter_skema): ?>
    <div class="meta-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filter Skema: <span class="meta-val"><?= htmlspecialchars(strtoupper($filter_skema)) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── RINGKASAN STATISTIK ── -->
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-hd-bar"></div>
      <div class="sec-hd-title">A. Ringkasan Hasil Keputusan</div>
      <div class="sec-hd-line"></div>
    </div>

    <!-- Stat cards -->
    <div class="stat-row">
      <?php
      $cards = [
        ['n'=>$agg['total'],      'l'=>'Total Proposal Terseleksi', 'p'=>'100% dari pengajuan',
         'bg'=>'linear-gradient(135deg,#0d1b3e,#1565C0)',
         'ic'=>'<path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/>'],
        ['n'=>$lolos_total,       'l'=>'Lolos / Dilanjutkan',       'p'=>($agg['total']>0?round($lolos_total/$agg['total']*100):'0').'% tingkat kelulusan',
         'bg'=>'linear-gradient(135deg,#14532d,#16a34a)',
         'ic'=>'<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'],
        ['n'=>$agg['gagal'],      'l'=>'Tidak Lolos Administrasi',  'p'=>($agg['total']>0?round($agg['gagal']/$agg['total']*100):'0').'% ditolak',
         'bg'=>'linear-gradient(135deg,#7f1d1d,#dc2626)',
         'ic'=>'<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'],
        ['n'=>$agg['perbaikan'],  'l'=>'Sedang dalam Perbaikan',    'p'=>($agg['total']>0?round($agg['perbaikan']/$agg['total']*100):'0').'% menunggu revisi',
         'bg'=>'linear-gradient(135deg,#3b0764,#7c3aed)',
         'ic'=>'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'],
      ];
      foreach ($cards as $c): ?>
      <div class="stat-card" style="background:<?= $c['bg'] ?>">
        <div class="stat-n"><?= $c['n'] ?></div>
        <div class="stat-l"><?= $c['l'] ?></div>
        <div class="stat-p"><?= $c['p'] ?></div>
        <svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <?= $c['ic'] ?>
        </svg>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Donut + Legend -->
    <div class="chart-wrap">
      <div class="donut-rel">
        <svg width="160" height="160" viewBox="0 0 160 160">
          <circle cx="80" cy="80" r="60" fill="none" stroke="#f1f5f9" stroke-width="20"/>
          <?php
          $tot = max(1, $agg['total']);
          $segs = [
            [$lolos_total,      '#16a34a'],
            [$agg['gagal'],     '#dc2626'],
            [$agg['perbaikan'], '#7c3aed'],
          ];
          $ang = -90.0;
          foreach ($segs as [$sv, $sc]):
              echo arc($sv, $tot, $ang, $sc);
              $ang += $sv/$tot*360;
          endforeach;
          ?>
        </svg>
        <div class="donut-ctr">
          <div class="donut-n"><?= $agg['total'] ?></div>
          <div class="donut-s">Proposal</div>
        </div>
      </div>
      <div class="legend">
        <?php
        $legs = [
          ['Lolos / Dilanjutkan', $lolos_total,      '#16a34a'],
          ['Tidak Lolos',         $agg['gagal'],     '#dc2626'],
          ['Sedang Diperbaiki',   $agg['perbaikan'], '#7c3aed'],
        ];
        foreach ($legs as [$ln,$lv,$lc]):
          $lp = $agg['total']>0 ? round($lv/$agg['total']*100) : 0;
        ?>
        <div class="leg-row">
          <div class="leg-dot" style="background:<?= $lc ?>"></div>
          <div class="leg-name"><?= $ln ?></div>
          <div class="leg-bar-bg"><div class="leg-bar" style="width:<?= $lp ?>%;background:<?= $lc ?>"></div></div>
          <div class="leg-cnt" style="color:<?= $lc ?>"><?= $lv ?></div>
          <div class="leg-pct"><?= $lp ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── PER SKEMA ── -->
  <?php if (count($per_skema) >= 1): ?>
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-hd-bar"></div>
      <div class="sec-hd-title">B. Distribusi Hasil per Skema Penelitian</div>
      <div class="sec-hd-line"></div>
    </div>
    <table class="sk-tbl">
      <thead>
        <tr>
          <th>Skema</th>
          <th style="text-align:center">Total</th>
          <th style="min-width:130px">Lolos / Lanjut</th>
          <th style="min-width:130px">Tidak Lolos</th>
          <th style="min-width:130px">Perbaikan</th>
          <th style="text-align:center">% Kelulusan</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($per_skema as $sk => $sd):
        $sk_lolos = $sd['lolos'] + $sd['lanjut'];
        $sk_pct   = $sd['total']>0 ? round($sk_lolos/$sd['total']*100) : 0;
        $sk_bg    = strtolower($sk)==='nasional' ? '#dcfce7' : '#dbeafe';
        $sk_cl    = strtolower($sk)==='nasional' ? '#15803d' : '#1e40af';
      ?>
      <tr>
        <td><span class="sk-pill" style="background:<?= $sk_bg ?>;color:<?= $sk_cl ?>"><?= htmlspecialchars(strtoupper($sk)) ?></span></td>
        <td style="text-align:center;font-weight:800;font-size:13px"><?= $sd['total'] ?></td>
        <td>
          <div class="sk-bar-wrap">
            <div class="sk-bar-outer"><div class="sk-bar-inner" style="width:<?= $sd['total']>0?round($sk_lolos/$sd['total']*100):0 ?>%;background:#16a34a"></div></div>
            <div class="sk-num" style="color:#15803d"><?= $sk_lolos ?></div>
          </div>
        </td>
        <td>
          <div class="sk-bar-wrap">
            <div class="sk-bar-outer"><div class="sk-bar-inner" style="width:<?= $sd['total']>0?round($sd['gagal']/$sd['total']*100):0 ?>%;background:#dc2626"></div></div>
            <div class="sk-num" style="color:#b91c1c"><?= $sd['gagal'] ?></div>
          </div>
        </td>
        <td>
          <div class="sk-bar-wrap">
            <div class="sk-bar-outer"><div class="sk-bar-inner" style="width:<?= $sd['total']>0?round($sd['perbaikan']/$sd['total']*100):0 ?>%;background:#7c3aed"></div></div>
            <div class="sk-num" style="color:#5b21b6"><?= $sd['perbaikan'] ?></div>
          </div>
        </td>
        <td style="text-align:center">
          <span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:800;
            background:<?= $sk_pct>=70?'#dcfce7':($sk_pct>=40?'#fef9c3':'#fee2e2') ?>;
            color:<?= $sk_pct>=70?'#15803d':($sk_pct>=40?'#92400e':'#b91c1c') ?>">
            <?= $sk_pct ?>%
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── CHECKLIST ANALYSIS ── -->
  <?php if (array_sum($cl_fail) > 0): ?>
  <div class="sec no-break">
    <div class="sec-hd">
      <div class="sec-hd-bar"></div>
      <div class="sec-hd-title">C. Temuan Ketidaksesuaian Checklist Administrasi</div>
      <div class="sec-hd-line"></div>
    </div>
    <p style="font-size:11.5px;color:#64748b;margin-bottom:14px">
      Jumlah proposal yang <strong>tidak memenuhi</strong> tiap kriteria dari <strong><?= $agg['total'] ?></strong> proposal yang telah diseleksi.
      Semakin panjang bar merah, semakin banyak proposal yang tidak memenuhi kriteria tersebut.
    </p>
    <div class="cl-grid">
      <?php foreach ($CL as $i => $ci):
        $fail = $cl_fail[$ci['key']] ?? 0;
        $fp   = $agg['total']>0 ? round($fail/$agg['total']*100) : 0;
      ?>
      <div class="cl-row">
        <div class="cl-idx"><?= $i+1 ?></div>
        <div class="cl-body">
          <div class="cl-lbl"><?= htmlspecialchars($ci['label']) ?></div>
          <div class="cl-bar-wrap"><div class="cl-bar" style="width:<?= $fp ?>%"></div></div>
        </div>
        <div style="text-align:right;flex-shrink:0;padding-left:8px">
          <div class="cl-num"><?= $fail ?></div>
          <div style="font-size:9.5px;color:#94a3b8"><?= $fp ?>%</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- SIGN-OFF (Sheet 1) -->
  <div class="signoff no-break">
    <div class="signoff-head">
      <div></div>
      <div class="signoff-place">
        <?= $cetak_loc ?>, <strong><?= $cetak_tgl ?></strong>
      </div>
    </div>
    <div class="ttd-grid">
      <div class="ttd-box">
        <div class="ttd-role">Diperiksa oleh,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?= htmlspecialchars($printer) ?></div>
          <div class="ttd-nip">Admin LPPM</div>
        </div>
      </div>
      <div class="ttd-box">
        <div class="ttd-role">Mengetahui,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?= htmlspecialchars($ketua_lppm) ?></div>
          <div class="ttd-nip"><?= $nip_ketua ? 'NIP. ' . htmlspecialchars($nip_ketua) : 'Ketua LPPM IAKN Toraja' ?></div>
        </div>
      </div>
    </div>
    <div class="signoff-footer">
      <img class="sf-logo" src="<?= $logo_src ?>" alt="">
      <?= htmlspecialchars($institusi) ?> — <?= htmlspecialchars($nama_lppm) ?>
      &nbsp;·&nbsp; Dicetak: <?= $cetak_tgl ?>
    </div>
  </div>

</div><!-- /sheet 1 -->

<!-- ═══════════════════════════════════════════
     SHEET 2 — DETAIL PROPOSAL (untuk pengusul)
═══════════════════════════════════════════ -->
<div class="sheet page-break">

  <!-- KOP mini repeat -->
  <div class="kop" style="padding:14px 36px 12px">
    <img class="kop-logo" src="<?= $logo_src ?>" alt="Logo IAKN Toraja" style="width:52px;height:52px">
    <div class="kop-text">
      <div class="kop-inst" style="font-size:13.5px"><?= htmlspecialchars($institusi) ?></div>
      <div class="kop-lppm" style="font-size:11px"><?= htmlspecialchars($nama_lppm) ?></div>
    </div>
    <div class="kop-tahun" style="padding:6px 8px">
      <div class="kop-tahun-num" style="font-size:16px"><?= $tahun ?></div>
      <div class="kop-tahun-lbl" style="font-size:8.5px">Tahun<br>Anggaran</div>
    </div>
  </div>

  <div class="rpt-banner" style="padding:14px 36px">
    <div>
      <div class="rpt-title" style="font-size:14px">DAFTAR PROPOSAL DAN HASIL PEMERIKSAAN ADMINISTRASI</div>
      <div class="rpt-subtitle">Rincian Checklist, Catatan, dan Keputusan per Proposal — TA <?= $tahun ?></div>
    </div>
    <div class="rpt-stamp">
      <div class="rpt-stamp-top">Halaman</div>
      <div class="rpt-stamp-num">2</div>
      <div class="rpt-stamp-bot">Lampiran</div>
    </div>
  </div>

  <div class="sec" style="padding-bottom:8px">
    <!-- Checklist legend -->
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;flex-wrap:wrap">
      <span style="font-size:11px;color:#64748b;font-weight:600">Keterangan checklist:</span>
      <?php foreach ($CL as $i => $ci): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;color:#475569">
        <span style="background:#1565C0;color:#fff;width:16px;height:16px;border-radius:4px;
               display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:800">
          <?= $i+1 ?>
        </span>
        <?= htmlspecialchars($ci['short']) ?>
      </span>
      <?php endforeach; ?>
    </div>

    <table class="prop-tbl">
      <thead>
        <tr>
          <th style="width:30px">#</th>
          <th style="min-width:220px">Judul Proposal &amp; Program Studi</th>
          <th>Skema</th>
          <th>Ketua Pengusul</th>
          <th style="min-width:120px">Checklist (1–<?= count($CL) ?>)</th>
          <th style="min-width:140px">Catatan Administratif</th>
          <th>Keputusan</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($proposals)): ?>
      <tr>
        <td colspan="7" style="text-align:center;padding:32px;color:#94a3b8">
          Tidak ada data proposal yang telah diseleksi untuk tahun <?= $tahun ?>.
        </td>
      </tr>
      <?php else: foreach ($proposals as $i => $p):
        $cl_data = [];
        if ($p['sa_checklist']) try { $cl_data = json_decode($p['sa_checklist'], true)??[]; } catch(Exception $e){}
        $ok_cnt = count(array_filter($cl_data, fn($v)=>$v===true));
        $tot_cl = count($cl_data);

        if (in_array($p['status'], $lanjut_st))  { $dc='dec-lanjut';    $dl='Lolos → Lanjut'; }
        elseif ($p['status']==='lolos_admin')     { $dc='dec-lolos';     $dl='Lolos Admin'; }
        elseif ($p['status']==='gagal_admin')     { $dc='dec-gagal';     $dl='Tidak Lolos'; }
        elseif ($p['status']==='perbaikan_admin') { $dc='dec-perbaikan'; $dl='Perbaikan'; }
        else                                      { $dc='';              $dl=$p['status']; }
      ?>
      <tr>
        <td>
          <div class="p-num"><?= $i+1 ?></div>
        </td>
        <td>
          <div class="p-judul"><?= htmlspecialchars(mb_strimwidth($p['judul'],0,85,'…')) ?></div>
          <div class="p-meta">
            <?= htmlspecialchars($p['program_studi']??'-') ?>
            <?php if ($p['sa_tgl']): ?> · <?= date('d/m/Y', strtotime($p['sa_tgl'])) ?><?php endif; ?>
          </div>
        </td>
        <td>
          <span class="sk-pill" style="font-size:10px;
            background:<?= strtolower($p['skema'])=='nasional'?'#dcfce7':'#dbeafe' ?>;
            color:<?= strtolower($p['skema'])=='nasional'?'#15803d':'#1e40af' ?>">
            <?= htmlspecialchars(strtoupper($p['skema'])) ?>
          </span>
        </td>
        <td>
          <div style="font-weight:700;color:#0d1b3e;font-size:11.5px"><?= htmlspecialchars($p['nama_lengkap']) ?></div>
          <div class="p-nidn"><?= htmlspecialchars($p['nidn']??'-') ?></div>
        </td>
        <td>
          <?php if (empty($cl_data)): ?>
          <span style="color:#94a3b8;font-size:10.5px">Belum diperiksa</span>
          <?php else: ?>
          <div class="cl-micro">
            <?php foreach ($CL as $ci):
              $v = $cl_data[$ci['key']] ?? null;
              if ($v===true)       { $cls='cl-dot-ok'; $sym='✓'; $tt='Terpenuhi'; }
              elseif ($v===false)  { $cls='cl-dot-no'; $sym='✗'; $tt='Tidak terpenuhi'; }
              else                 { $cls='cl-dot-na'; $sym='·'; $tt='Tidak diperiksa'; }
            ?>
            <div class="cl-dot <?= $cls ?>" title="<?= $tt ?>: <?= htmlspecialchars($ci['label']) ?>"><?= $sym ?></div>
            <?php endforeach; ?>
          </div>
          <div class="cl-score"><?= $ok_cnt ?>/<?= count($CL) ?> terpenuhi</div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($p['sa_catatan']): ?>
          <span style="font-size:11px;color:#475569;line-height:1.5">
            <?= htmlspecialchars(mb_strimwidth($p['sa_catatan'],0,120,'…')) ?>
          </span>
          <?php else: ?>
          <span style="color:#94a3b8;font-size:10.5px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php
          $dec_icon = match($dc) {
            'dec-lolos','dec-lanjut' => '✓',
            'dec-gagal'              => '✗',
            'dec-perbaikan'          => '↩',
            default                  => '·',
          };
          ?>
          <span class="dec <?= $dc ?>"><?= $dec_icon ?> <?= $dl ?></span>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div><!-- /sec -->

  <!-- SIGN-OFF Sheet 2 -->
  <div class="signoff no-break">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:20px">
      <div style="font-size:12px;color:#475569;line-height:1.7">
        <strong style="color:#0d1b3e">Catatan:</strong><br>
        · ✓ = Kriteria terpenuhi &nbsp;·&nbsp; ✗ = Tidak terpenuhi &nbsp;·&nbsp; · = Belum diperiksa<br>
        · Nomor checklist sesuai keterangan di atas tabel.<br>
        · Dokumen ini merupakan bagian dari arsip administrasi LPPM.
      </div>
      <div style="text-align:right">
        <div style="font-size:12px;color:#64748b">
          <?= $cetak_loc ?>, <strong style="color:#0d1b3e"><?= $cetak_tgl ?></strong>
        </div>
      </div>
    </div>
    <div class="ttd-grid">
      <div class="ttd-box">
        <div class="ttd-role">Diperiksa oleh,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?= htmlspecialchars($printer) ?></div>
          <div class="ttd-nip">Admin LPPM</div>
        </div>
      </div>
      <div class="ttd-box">
        <div class="ttd-role">Mengetahui,</div>
        <div class="ttd-line">
          <div class="ttd-name"><?= htmlspecialchars($ketua_lppm) ?></div>
          <div class="ttd-nip"><?= $nip_ketua ? 'NIP. '.htmlspecialchars($nip_ketua) : 'Ketua LPPM IAKN Toraja' ?></div>
        </div>
      </div>
    </div>
    <div class="signoff-footer">
      <img class="sf-logo" src="<?= $logo_src ?>" alt="">
      <?= htmlspecialchars($institusi) ?> — <?= htmlspecialchars($nama_lppm) ?>
      &nbsp;·&nbsp; Dicetak oleh <?= htmlspecialchars($printer) ?> pada <?= $cetak_tgl ?>
    </div>
  </div>

</div><!-- /sheet 2 -->

</div><!-- /wrap -->
</body>
</html>
