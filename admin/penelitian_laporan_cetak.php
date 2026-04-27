<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang  = $_COOKIE['lang'] ?? 'id';
$tahun = (int)($_GET['tahun'] ?? getSetting($pdo,'penelitian_tahun') ?: date('Y'));

/* ── Query proposals + laporan ─────────────────────────────────── */
$rows = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.status, up.user_id,
           u.nama_lengkap, u.nidn, u.program_studi, u.fakultas,
           kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan,
           lp.id AS lap_id, lp.round_ke, lp.tanggal_submit,
           lp.status AS lap_status, lp.catatan_admin AS lap_catatan,
           lp.reviewed_at AS lap_reviewed_at,
           (SELECT COUNT(*) FROM laporan_revisi lr WHERE lr.usulan_id = up.id) AS n_history,
           (SELECT COUNT(*) FROM monev_sanksi ms WHERE ms.usulan_id = up.id AND ms.status='aktif' AND ms.deleted_at IS NULL) AS n_sanksi
    FROM usulan_penelitian up
    JOIN users u ON up.user_id = u.id
    LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
    LEFT JOIN laporan_penelitian lp ON lp.usulan_id = up.id
    WHERE up.tahun_anggaran = ? AND up.deleted_at IS NULL
      AND up.status IN ('disetujui','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai')
    ORDER BY up.skema ASC, u.nama_lengkap ASC
");
$rows->execute([$tahun]);
$rows = $rows->fetchAll();

/* ── Aggregate ──────────────────────────────────────────────────── */
$now_ts = time();
$agg = ['total'=>0,'kontrak'=>0,'submit'=>0,'tepat'=>0,'terlambat'=>0,
        'diterima'=>0,'revisi'=>0,'ditolak'=>0,'menunggu'=>0,'belum'=>0,'sanksi'=>0];
$per_skema = [];

foreach ($rows as $r) {
    $agg['total']++;
    $sk = $r['skema'] ?: '—';
    if (!isset($per_skema[$sk])) $per_skema[$sk] = ['total'=>0,'submit'=>0,'diterima'=>0,'revisi'=>0,'belum'=>0,'sanksi'=>0];
    $per_skema[$sk]['total']++;

    if (!empty($r['nomor_kontrak'])) $agg['kontrak']++;
    if ($r['lap_id']) {
        $agg['submit']++;
        $per_skema[$sk]['submit']++;
        $dl = $r['deadline_laporan'];
        if ($dl && strtotime($r['tanggal_submit']) > strtotime($dl)) $agg['terlambat']++;
        else $agg['tepat']++;
        match($r['lap_status']) {
            'diterima' => $agg['diterima']++,
            'revisi'   => $agg['revisi']++,
            'ditolak'  => $agg['ditolak']++,
            default    => $agg['menunggu']++,
        };
        if ($r['lap_status'] === 'diterima') $per_skema[$sk]['diterima']++;
        if ($r['lap_status'] === 'revisi')   $per_skema[$sk]['revisi']++;
    } else {
        $agg['belum']++;
        $per_skema[$sk]['belum']++;
    }
    if ($r['n_sanksi'] > 0) {
        $agg['sanksi']++;
        $per_skema[$sk]['sanksi']++;
    }
}

/* ── Settings ────────────────────────────────────────────────────── */
$cfg_keys = ['nama_institusi','nama_lppm','alamat_institusi','nama_ketua_lppm','nip_ketua_lppm'];
$cfg = [];
foreach ($cfg_keys as $k) $cfg[$k] = getSetting($pdo, $k) ?: '';
$institusi  = $cfg['nama_institusi'] ?: 'Institut Agama Kristen Negeri (IAKN) Toraja';
$nama_lppm  = $cfg['nama_lppm']      ?: 'Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM)';
$alamat     = $cfg['alamat_institusi'] ?: 'Jl. Nusantara No. 1, Makale, Tana Toraja, Sulawesi Selatan';
$ketua_lppm = $cfg['nama_ketua_lppm'] ?: '____________________';
$nip_ketua  = $cfg['nip_ketua_lppm']  ?: '';

$printer    = $_SESSION['nama'] ?? 'Admin';
$cetak_tgl  = date('d F Y');
$logo_src   = BASE_URL . '/assets/img/logo_kiri.jpg';

$bln_id = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tgl_indo = $bln_id[(int)date('m')] ? date('d').' '.$bln_id[(int)date('m')].' '.date('Y') : $cetak_tgl;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Penelitian — <?= $tahun ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:13px}
body{font-family:'Segoe UI',Calibri,Arial,sans-serif;color:#1a1a2e;background:#eef2f7;line-height:1.5}

/* Toolbar */
#toolbar{position:fixed;top:0;left:0;right:0;z-index:999;
  background:linear-gradient(135deg,#0d1b3e 0%,#0369a1 65%,#0284c7 100%);
  padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 2px 20px rgba(2,132,199,.4)}
#toolbar .tb-left{display:flex;align-items:center;gap:14px}
#toolbar .tb-icon{width:34px;height:34px;border-radius:8px;overflow:hidden;flex-shrink:0;background:#fff;display:flex;align-items:center;justify-content:center}
#toolbar .tb-icon img{width:100%;height:100%;object-fit:cover}
#toolbar h1{font-size:13.5px;font-weight:700;color:#fff;letter-spacing:.2px}
#toolbar small{display:block;font-size:10.5px;color:rgba(255,255,255,.65);margin-top:1px}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;
  font-size:12.5px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s;margin-left:6px}
.tb-btn:hover{opacity:.88}
.tb-print{background:#fff;color:#0284c7}
.tb-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)}

/* Page */
#wrap{max-width:1020px;margin:72px auto 48px;padding:0 16px}
.sheet{background:#fff;border-radius:4px;
  box-shadow:0 1px 3px rgba(0,0,0,.08),0 8px 40px rgba(0,0,0,.10);
  margin-bottom:20px;overflow:hidden;position:relative}
.sheet::before{content:'';display:block;height:5px;
  background:linear-gradient(90deg,#0d1b3e 0%,#0369a1 40%,#16a34a 60%,#0891b2 100%)}

/* Kop */
.kop{padding:20px 36px 16px;border-bottom:3px double #0369a1;
  display:grid;grid-template-columns:72px 1fr 90px;align-items:center;gap:16px}
.kop-logo{width:72px;height:72px;object-fit:contain}
.kop-text{text-align:center}
.kop-inst{font-size:16.5px;font-weight:800;letter-spacing:.4px;color:#0d1b3e;text-transform:uppercase;line-height:1.25}
.kop-lppm{font-size:12px;font-weight:600;color:#0369a1;margin-top:3px}
.kop-alamat{font-size:10px;color:#475569;margin-top:6px;font-style:italic}

/* Title */
.title-wrap{padding:24px 36px 14px;text-align:center}
.title-tag{display:inline-block;background:linear-gradient(135deg,#0369a1,#0891b2);color:#fff;
  font-size:9.5px;font-weight:700;padding:3px 12px;border-radius:11px;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:9px}
.title-main{font-size:18px;font-weight:800;color:#0d1b3e;text-transform:uppercase;letter-spacing:.5px;line-height:1.3}
.title-sub{font-size:12.5px;color:#0369a1;margin-top:5px;font-weight:600}
.title-meta{margin-top:10px;font-size:10.5px;color:#64748b}

/* Section header */
.sec{padding:18px 36px 14px}
.sec-h{display:flex;align-items:center;gap:9px;margin-bottom:11px;
  border-bottom:2px solid #e2e8f0;padding-bottom:6px}
.sec-h-num{width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,#0369a1,#0891b2);
  color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}
.sec-h-title{font-size:12.5px;font-weight:800;color:#0d1b3e;text-transform:uppercase;letter-spacing:.4px}

/* Stat cards */
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin-bottom:14px}
.stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:11px 13px;text-align:center}
.stat-n{font-size:22px;font-weight:900;line-height:1}
.stat-l{font-size:9.5px;color:#64748b;margin-top:4px;text-transform:uppercase;letter-spacing:.3px}

/* Per-skema table */
.skema-table,.lap-table{width:100%;border-collapse:collapse;font-size:11px}
.skema-table th,.lap-table th{background:#0d1b3e;color:#fff;padding:7px 9px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px}
.skema-table td,.lap-table td{padding:8px 9px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.skema-table tr:last-child td,.lap-table tr:last-child td{border-bottom:none}
.skema-table .num,.lap-table .num{text-align:center;font-weight:700}

/* Status badge */
.bdg{display:inline-block;padding:2px 7px;border-radius:5px;font-size:9.5px;font-weight:700;text-transform:uppercase}
.bdg-ok{background:#dcfce7;color:#15803d}
.bdg-rev{background:#fef3c7;color:#92400e}
.bdg-tolak{background:#fee2e2;color:#991b1b}
.bdg-wait{background:#fef9c3;color:#a16207}
.bdg-blm{background:#f1f5f9;color:#64748b}
.bdg-late{background:#fef2f2;color:#dc2626}
.bdg-sanksi{background:#7f1d1d;color:#fff}

/* TTD */
.ttd-wrap{padding:30px 36px 36px;display:grid;grid-template-columns:1fr 1fr;gap:30px}
.ttd-box{text-align:center;font-size:11.5px}
.ttd-box .role{font-weight:700;color:#0d1b3e;margin-bottom:60px}
.ttd-box .name{font-weight:800;color:#0d1b3e;font-size:12.5px;text-decoration:underline;text-underline-offset:3px}
.ttd-box .nip{font-size:10.5px;color:#475569;margin-top:3px}
.ttd-loc{text-align:right;font-size:11px;color:#475569;padding:0 36px 6px;font-style:italic}

@media print{
  #toolbar{display:none}
  body{background:#fff}
  #wrap{margin:0;max-width:none;padding:0}
  .sheet{box-shadow:none;border-radius:0;margin:0;page-break-after:always}
  .sheet::before{display:none}
}
</style>
</head>
<body>

<div id="toolbar">
  <div class="tb-left">
    <div class="tb-icon"><img src="<?= htmlspecialchars($logo_src) ?>" alt="logo" onerror="this.style.display='none'"></div>
    <div>
      <h1>Laporan Penelitian — Tahun Anggaran <?= $tahun ?></h1>
      <small>Dicetak: <?= $cetak_tgl ?> · oleh <?= htmlspecialchars($printer) ?></small>
    </div>
  </div>
  <div>
    <a href="<?= BASE_URL ?>/admin/penelitian_laporan.php" class="tb-btn tb-back">← Kembali</a>
    <button onclick="window.print()" class="tb-btn tb-print">🖨 Cetak / Simpan PDF</button>
  </div>
</div>

<div id="wrap">
  <div class="sheet">

    <!-- Kop Surat -->
    <div class="kop">
      <img src="<?= htmlspecialchars($logo_src) ?>" class="kop-logo" alt="logo" onerror="this.style.display='none'">
      <div class="kop-text">
        <div class="kop-inst">Kementerian Agama Republik Indonesia</div>
        <div class="kop-inst" style="font-size:14px;color:#0369a1;margin-top:2px"><?= htmlspecialchars($institusi) ?></div>
        <div class="kop-lppm"><?= htmlspecialchars($nama_lppm) ?></div>
        <div class="kop-alamat"><?= htmlspecialchars($alamat) ?></div>
      </div>
      <div></div>
    </div>

    <!-- Judul Laporan -->
    <div class="title-wrap">
      <span class="title-tag">Laporan Internal · Bukan Surat Resmi</span>
      <div class="title-main">Rekap Pelaksanaan & Pelaporan Penelitian</div>
      <div class="title-sub">Tahun Anggaran <?= $tahun ?></div>
      <div class="title-meta">Total Proposal Lolos: <strong><?= $agg['total'] ?></strong> · Mencakup proses kontrak, submit laporan, feedback admin, & sanksi</div>
    </div>

    <!-- ① Stats -->
    <div class="sec">
      <div class="sec-h">
        <div class="sec-h-num">1</div>
        <div class="sec-h-title">Ringkasan Statistik</div>
      </div>
      <div class="stats">
        <div class="stat"><div class="stat-n" style="color:#0369a1"><?= $agg['total'] ?></div><div class="stat-l">Lolos Substantif</div></div>
        <div class="stat"><div class="stat-n" style="color:#7c3aed"><?= $agg['kontrak'] ?></div><div class="stat-l">Punya Kontrak</div></div>
        <div class="stat"><div class="stat-n" style="color:#0891b2"><?= $agg['submit'] ?></div><div class="stat-l">Submit Laporan</div></div>
        <div class="stat"><div class="stat-n" style="color:#16a34a"><?= $agg['diterima'] ?></div><div class="stat-l">Diterima</div></div>
        <div class="stat"><div class="stat-n" style="color:#dc2626"><?= $agg['belum'] ?></div><div class="stat-l">Belum Submit</div></div>
      </div>
      <div class="stats" style="grid-template-columns:repeat(4,1fr);margin-top:10px">
        <div class="stat"><div class="stat-n" style="color:#16a34a"><?= $agg['tepat'] ?></div><div class="stat-l">Tepat Waktu</div></div>
        <div class="stat"><div class="stat-n" style="color:#dc2626"><?= $agg['terlambat'] ?></div><div class="stat-l">Terlambat</div></div>
        <div class="stat"><div class="stat-n" style="color:#a16207"><?= $agg['revisi'] ?></div><div class="stat-l">Perlu Revisi</div></div>
        <div class="stat"><div class="stat-n" style="color:#7f1d1d"><?= $agg['sanksi'] ?></div><div class="stat-l">Bersanksi</div></div>
      </div>
    </div>

    <!-- ② Per-skema -->
    <div class="sec">
      <div class="sec-h">
        <div class="sec-h-num">2</div>
        <div class="sec-h-title">Rekap per Skema</div>
      </div>
      <table class="skema-table">
        <thead>
          <tr>
            <th>Skema</th>
            <th class="num">Total</th>
            <th class="num">Submit</th>
            <th class="num">Diterima</th>
            <th class="num">Revisi</th>
            <th class="num">Belum</th>
            <th class="num">Sanksi</th>
            <th class="num">% Diterima</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($per_skema as $sk => $s): ?>
        <tr>
          <td style="font-weight:700;color:#0d1b3e"><?= htmlspecialchars(strtoupper($sk)) ?></td>
          <td class="num"><?= $s['total'] ?></td>
          <td class="num"><?= $s['submit'] ?></td>
          <td class="num" style="color:#16a34a"><?= $s['diterima'] ?></td>
          <td class="num" style="color:#a16207"><?= $s['revisi'] ?></td>
          <td class="num" style="color:#dc2626"><?= $s['belum'] ?></td>
          <td class="num" style="color:#7f1d1d"><?= $s['sanksi'] ?></td>
          <td class="num"><?= $s['total']>0 ? round($s['diterima']/$s['total']*100) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ③ Detail per proposal -->
    <div class="sec">
      <div class="sec-h">
        <div class="sec-h-num">3</div>
        <div class="sec-h-title">Detail Status Laporan per Proposal</div>
      </div>
      <table class="lap-table">
        <thead>
          <tr>
            <th class="num" style="width:30px">#</th>
            <th>Pengusul / Judul</th>
            <th style="width:55px">Skema</th>
            <th style="width:90px">Kontrak</th>
            <th style="width:75px">Deadline</th>
            <th style="width:75px">Submit</th>
            <th style="width:60px">Round</th>
            <th style="width:90px">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r):
          $dl = $r['deadline_laporan'];
          $ts = $r['tanggal_submit'];
          $terlambat = $ts && $dl && strtotime($ts) > strtotime($dl);
          $dl_lewat  = $dl && !$ts && strtotime($dl) < $now_ts;
          $st_class = match($r['lap_status']) {
            'diterima' => 'bdg-ok',
            'revisi'   => 'bdg-rev',
            'ditolak'  => 'bdg-tolak',
            'menunggu' => 'bdg-wait',
            default    => 'bdg-blm',
          };
          $st_label = match($r['lap_status']) {
            'diterima' => 'Diterima',
            'revisi'   => 'Revisi',
            'ditolak'  => 'Ditolak',
            'menunggu' => 'Menunggu',
            default    => 'Belum',
          };
        ?>
        <tr>
          <td class="num" style="color:#94a3b8"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:700;color:#0d1b3e;font-size:11px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
            <div style="font-size:10px;color:#64748b">NIDN <?= htmlspecialchars($r['nidn'] ?: '—') ?> · <?= htmlspecialchars($r['program_studi'] ?? '—') ?></div>
            <div style="font-size:10.5px;color:#334155;margin-top:2px;line-height:1.4"><?= htmlspecialchars(mb_strimwidth($r['judul'],0,90,'…')) ?></div>
          </td>
          <td style="font-weight:700;color:<?= $r['skema']==='nasional'?'#15803d':'#0369a1' ?>"><?= strtoupper($r['skema']) ?></td>
          <td>
            <?php if ($r['nomor_kontrak']): ?>
            <div style="font-size:10px;font-weight:600"><?= htmlspecialchars($r['nomor_kontrak']) ?></div>
            <?php if ($r['tgl_kontrak']): ?>
            <div style="font-size:9.5px;color:#64748b"><?= date('d/m/y', strtotime($r['tgl_kontrak'])) ?></div>
            <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= $dl ? date('d/m/y', strtotime($dl)) . '<br><span style="font-size:9px;color:#64748b">' . date('H:i', strtotime($dl)) . '</span>' : '—' ?></td>
          <td>
            <?php if ($ts): ?>
            <?= date('d/m/y', strtotime($ts)) ?>
            <?php if ($terlambat): ?><br><span class="bdg bdg-late">LATE</span><?php endif; ?>
            <?php else: ?>
            <?php if ($dl_lewat): ?><span class="bdg bdg-late">LEWAT</span><?php else: ?>—<?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int)($r['round_ke'] ?? 0) ?></td>
          <td>
            <span class="bdg <?= $st_class ?>"><?= $st_label ?></span>
            <?php if ($r['n_history'] > 1): ?><br><span style="font-size:9px;color:#64748b"><?= (int)$r['n_history'] ?>× hist.</span><?php endif; ?>
            <?php if ($r['n_sanksi'] > 0): ?><br><span class="bdg bdg-sanksi"><?= (int)$r['n_sanksi'] ?> sanksi</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">Tidak ada data.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- TTD -->
    <div class="ttd-loc">Tana Toraja, <?= $tgl_indo ?></div>
    <div class="ttd-wrap">
      <div class="ttd-box">
        <div class="role">Disusun oleh,<br>Admin LPPM</div>
        <div class="name"><?= htmlspecialchars($printer) ?></div>
      </div>
      <div class="ttd-box">
        <div class="role">Mengetahui,<br>Ketua LPPM</div>
        <div class="name"><?= htmlspecialchars($ketua_lppm) ?></div>
        <?php if ($nip_ketua): ?>
        <div class="nip"><?= htmlspecialchars($nip_ketua) ?></div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

</body>
</html>
