<?php
// cetak_surat.php — Tampilkan surat di browser untuk dicetak
// Bisa diakses mahasiswa (surat miliknya) atau admin (semua surat)
require_once 'includes/config.php';
requireLogin();

$tipe = clean($_GET['tipe'] ?? ''); // plagiasi | publikasi
$id   = (int)($_GET['id']   ?? 0);

if (!in_array($tipe, ['plagiasi','publikasi']) || !$id) {
    http_response_code(400); die('Parameter tidak valid.');
}

$lang           = $_COOKIE['lang'] ?? 'id';
$nama_inst      = getSetting($pdo, 'nama_institusi');
$nama_lppm      = getSetting($pdo, 'nama_lppm');
$alamat         = getSetting($pdo, 'alamat_institusi');

if ($tipe === 'plagiasi') {
    $stmt = $pdo->prepare("
        SELECT sp.*, cp.similarity_score, cp.tanggal_cek,
               s.judul_skripsi, s.nama_pembimbing1, s.nama_pembimbing2, s.tahun_sidang,
               u.nama_lengkap, u.nim, u.program_studi, s.user_id
        FROM surat_plagiasi sp
        JOIN cek_plagiasi cp ON sp.cek_plagiasi_id=cp.id
        JOIN skripsi s ON cp.skripsi_id=s.id
        JOIN users u ON s.user_id=u.id
        WHERE sp.id=?
    ");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("
        SELECT sp.*, p.judul_publikasi, p.jenis_publikasi, p.nama_jurnal_penerbit,
               p.tahun_terbit, p.url_doi, p.issn_isbn, p.user_id,
               u.nama_lengkap, u.nim, u.program_studi
        FROM surat_publikasi sp
        JOIN publikasi p ON sp.publikasi_id=p.id
        JOIN users u ON p.user_id=u.id
        WHERE sp.id=?
    ");
    $stmt->execute([$id]);
}

$data = $stmt->fetch();
if (!$data) { http_response_code(404); die('Surat tidak ditemukan.'); }
if (isMahasiswa() && $data['user_id'] !== $_SESSION['user_id']) {
    http_response_code(403); die('Akses ditolak.');
}

// Increment download/print count
if ($tipe === 'plagiasi') {
    $pdo->prepare("UPDATE surat_plagiasi SET download_count=download_count+1 WHERE id=?")->execute([$id]);
} else {
    $pdo->prepare("UPDATE surat_publikasi SET download_count=download_count+1 WHERE id=?")->execute([$id]);
}

$nip_ketua = getSetting($pdo, 'nip_ketua_lppm');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>
  <?= $tipe==='plagiasi'?'Surat Keterangan Bebas Plagiasi':'Surat Keterangan Publikasi' ?> —
  <?= htmlspecialchars($data['nomor_surat']) ?>
</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Poppins', Arial, sans-serif;
    font-size: 12pt;
    color: #000;
    background: #f0f0f0;
  }

  /* Tombol print/download — tidak tampil saat cetak */
  .print-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #1a3354;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 999;
  }
  .print-bar h3 { color: #fff; font-size: 14px; flex: 1; }
  .btn-print {
    background: #c9952a; color: #fff;
    border: none; border-radius: 6px;
    padding: 7px 18px; font-size: 12px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins', Arial, sans-serif;
  }
  .btn-download {
    background: transparent; color: #fff;
    border: 1px solid rgba(255,255,255,.5); border-radius: 6px;
    padding: 7px 14px; font-size: 12px;
    cursor: pointer; font-family: 'Poppins', Arial, sans-serif;
  }
  .btn-back {
    background: transparent; color: rgba(255,255,255,.7);
    border: none; font-size: 12px;
    cursor: pointer; font-family: 'Poppins', Arial, sans-serif;
  }

  /* Kertas A4 */
  .paper {
    width: 210mm;
    min-height: 297mm;
    margin: 70px auto 30px;
    background: #fff;
    padding: 20mm 25mm 20mm;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
  }

  /* Kop surat */
  .kop {
    border-bottom: 3px solid #1a3354;
    padding-bottom: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
  }
  .kop-logo {
    width: 70px; height: 70px;
    flex-shrink: 0;
  }
  .kop-logo img { width: 100%; height: 100%; object-fit: contain; }
  .kop-logo-placeholder {
    width: 70px; height: 70px;
    background: #1a3354;
    border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    color: #c9952a; font-size: 28px; font-weight: 700;
  }
  .kop-text { flex: 1; text-align: center; }
  .kop-text .inst   { font-size: 14pt; font-weight: 700; color: #1a3354; line-height: 1.3; }
  .kop-text .lppm   { font-size: 11pt; font-weight: 600; margin-top: 2px; }
  .kop-text .alamat { font-size: 9pt; color: #475569; margin-top: 3px; line-height: 1.4; }
  .kop-right { width: 70px; }

  /* Judul surat */
  .judul-surat {
    text-align: center;
    margin: 22px 0 6px;
  }
  .judul-surat .judul {
    font-size: 13pt;
    font-weight: 700;
    text-decoration: underline;
    text-transform: uppercase;
    letter-spacing: .05em;
  }
  .judul-surat .nomor {
    font-size: 11pt;
    margin-top: 4px;
    color: #475569;
  }

  /* Body surat */
  .surat-body { margin-top: 22px; line-height: 1.8; font-size: 11pt; }
  .surat-body p { margin-bottom: 12px; text-align: justify; }

  /* Tabel data */
  table.data-surat {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
    font-size: 11pt;
  }
  .data-surat td { padding: 5px 8px; vertical-align: top; }
  .data-surat .lbl { width: 40%; font-weight: 600; }
  .data-surat .sep { width: 4%; }

  /* Skor similarity */
  .skor-box {
    text-align: center;
    margin: 16px 0;
    padding: 12px;
    background: #f0fdf4;
    border-radius: 8px;
    border: 1.5px solid #86efac;
  }
  .skor-angka { font-size: 32pt; font-weight: 700; color: #27500a; }
  .skor-label { font-size: 10pt; color: #475569; margin-top: 2px; }

  /* TTD area */
  .ttd-area {
    margin-top: 30px;
    display: flex;
    justify-content: flex-end;
  }
  .ttd-box { text-align: center; min-width: 200px; }
  .ttd-box .kota { font-size: 11pt; margin-bottom: 3px; }
  .ttd-box .jabatan { font-size: 11pt; margin-bottom: 60px; }
  .ttd-box .ttd-line { border-top: 1px solid #000; padding-top: 4px; margin-top: 4px; }
  .ttd-box .nama { font-size: 11pt; font-weight: 600; text-decoration: underline; }
  .ttd-box .nip  { font-size: 10pt; }

  /* Stempel placeholder */
  .stempel-note {
    margin-top: 14px;
    padding: 8px 12px;
    background: #fffbeb;
    border: 1px dashed #f59e0b;
    border-radius: 6px;
    font-size: 9pt;
    color: #78350f;
    text-align: center;
  }

  /* Footer surat */
  .surat-footer {
    margin-top: 30px;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    font-size: 8pt;
    color: #94a3b8;
    text-align: center;
  }

  /* Print styles */
  @media print {
    body { background: #fff; }
    .print-bar { display: none !important; }
    .paper { margin: 0; box-shadow: none; width: 100%; padding: 15mm 20mm; }
    .stempel-note { display: none; }
  }

  @page { size: A4; margin: 0; }
</style>
</head>
<body>

<!-- Toolbar (tidak tercetak) -->
<div class="print-bar">
  <h3>
    <?= $tipe==='plagiasi'?'Surat Keterangan Bebas Plagiasi':'Surat Keterangan Publikasi' ?>
    — <?= htmlspecialchars($data['nomor_surat']) ?>
  </h3>
  <button class="btn-back" onclick="history.back()">← Kembali</button>
  <a href="<?= BASE_URL ?>/<?= $tipe==='plagiasi'?'modules/plagiasi':'modules/publikasi' ?>/unduh_surat.php?id=<?= $id ?>"
     class="btn-download"><?= ic('download') ?> Unduh PDF</a>
  <button class="btn-print" onclick="window.print()">🖨 Cetak Surat</button>
</div>

<!-- Kertas A4 -->
<div class="paper">

  <!-- KOP SURAT -->
  <div class="kop">
    <div class="kop-logo">
      <?php if (file_exists(BASE_PATH . '/assets/img/logo.png')): ?>
        <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="Logo IAKN Toraja">
      <?php else: ?>
        <div class="kop-logo-placeholder">I</div>
      <?php endif; ?>
    </div>
    <div class="kop-text">
      <div class="inst"><?= strtoupper($nama_inst) ?></div>
      <div class="lppm"><?= strtoupper($nama_lppm) ?></div>
      <div class="alamat"><?= htmlspecialchars($alamat) ?></div>
      <div class="alamat">Email: lppm@iakn-toraja.ac.id | lp2miaknt@gmail.com</div>
    </div>
    <div class="kop-right"></div>
  </div>

  <!-- JUDUL -->
  <div class="judul-surat">
    <div class="judul">
      <?= $tipe==='plagiasi' ? 'Surat Keterangan Bebas Plagiasi' : 'Surat Keterangan Publikasi' ?>
    </div>
    <div class="nomor">Nomor: <?= htmlspecialchars($data['nomor_surat']) ?></div>
  </div>

  <!-- BODY -->
  <div class="surat-body">
    <p>
      Yang bertanda tangan di bawah ini, <?= $data['jabatan_penandatangan'] ?>
      <?= $nama_lppm ?> <?= $nama_inst ?>,
      menerangkan bahwa mahasiswa yang tersebut namanya di bawah ini:
    </p>

    <table class="data-surat">
      <tr><td class="lbl">Nama Lengkap</td><td class="sep">:</td><td><?= htmlspecialchars($data['nama_lengkap']) ?></td></tr>
      <tr><td class="lbl">NIM</td><td class="sep">:</td><td><?= htmlspecialchars($data['nim']) ?></td></tr>
      <tr><td class="lbl">Program Studi</td><td class="sep">:</td><td><?= htmlspecialchars($data['program_studi'] ?? '-') ?></td></tr>
      <?php if ($tipe === 'plagiasi'): ?>
      <tr><td class="lbl">Judul Skripsi</td><td class="sep">:</td><td><?= htmlspecialchars($data['judul_skripsi']) ?></td></tr>
      <?php if ($data['nama_pembimbing1']): ?>
      <tr><td class="lbl">Pembimbing I</td><td class="sep">:</td><td><?= htmlspecialchars($data['nama_pembimbing1']) ?></td></tr>
      <?php endif; ?>
      <?php if ($data['nama_pembimbing2']): ?>
      <tr><td class="lbl">Pembimbing II</td><td class="sep">:</td><td><?= htmlspecialchars($data['nama_pembimbing2']) ?></td></tr>
      <?php endif; ?>
      <tr><td class="lbl">Tahun Sidang</td><td class="sep">:</td><td><?= $data['tahun_sidang'] ?? '-' ?></td></tr>
      <?php else: ?>
      <tr><td class="lbl">Jenis Publikasi</td><td class="sep">:</td><td><?= labelJenisPublikasi($data['jenis_publikasi']) ?></td></tr>
      <tr><td class="lbl">Judul Publikasi</td><td class="sep">:</td><td><?= htmlspecialchars($data['judul_publikasi']) ?></td></tr>
      <?php if ($data['nama_jurnal_penerbit']): ?>
      <tr><td class="lbl">Jurnal / Penerbit</td><td class="sep">:</td><td><?= htmlspecialchars($data['nama_jurnal_penerbit']) ?></td></tr>
      <?php endif; ?>
      <tr><td class="lbl">Tahun Terbit</td><td class="sep">:</td><td><?= $data['tahun_terbit'] ?? '-' ?></td></tr>
      <?php if ($data['issn_isbn']): ?>
      <tr><td class="lbl">ISSN / ISBN</td><td class="sep">:</td><td><?= htmlspecialchars($data['issn_isbn']) ?></td></tr>
      <?php endif; ?>
      <?php endif; ?>
    </table>

    <?php if ($tipe === 'plagiasi'): ?>
      <p>
        Berdasarkan hasil pengecekan menggunakan perangkat lunak deteksi plagiasi
        <strong>Turnitin</strong> yang dilakukan pada
        <?= formatTanggal($data['tanggal_cek']) ?>,
        skripsi tersebut memiliki tingkat kemiripan (<em>similarity index</em>) sebesar:
      </p>
      <div class="skor-box">
        <div class="skor-angka"><?= $data['similarity_score'] ?>%</div>
        <div class="skor-label">Di bawah batas maksimal yang ditetapkan (&le; 20%)</div>
      </div>
      <p>
        Dengan demikian, mahasiswa yang bersangkutan dinyatakan <strong>BEBAS PLAGIASI</strong>
        dan berhak mendapatkan Surat Keterangan ini untuk keperluan pengambilan ijazah
        dan keperluan lainnya.
      </p>
    <?php else: ?>
      <p>
        Telah melaksanakan publikasi karya ilmiah dan telah diverifikasi oleh
        <?= $nama_lppm ?> <?= $nama_inst ?>.
      </p>
      <p>
        Surat keterangan ini diberikan kepada yang bersangkutan untuk dapat dipergunakan
        sebagaimana mestinya, antara lain sebagai persyaratan pengambilan ijazah
        di <?= $nama_inst ?>.
      </p>
    <?php endif; ?>

    <p>
      Surat keterangan ini dibuat dengan sebenar-benarnya untuk dapat dipergunakan
      sebagaimana mestinya.
    </p>
  </div>

  <!-- TANDA TANGAN -->
  <div class="ttd-area">
    <div class="ttd-box">
      <div class="kota">Toraja Utara, <?= formatTanggal($data['tanggal_surat']) ?></div>
      <div class="jabatan"><?= htmlspecialchars($data['jabatan_penandatangan']) ?>,</div>
      <div style="height:60px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:9pt;border:1px dashed #e2e8f0;border-radius:4px;margin:4px 0">
        (Tanda tangan &amp; stempel)
      </div>
      <div class="ttd-line">
        <div class="nama"><?= htmlspecialchars($data['nama_penandatangan']) ?></div>
        <div class="nip"><?= htmlspecialchars($nip_ketua ?? '') ?></div>
      </div>
    </div>
  </div>

  <!-- Catatan stempel (tidak tercetak) -->
  <div class="stempel-note">
    &#x26A0; Surat ini sah setelah ditandatangani dan distempel basah oleh Ketua/Sekretaris LPPM di kantor LPPM IAKN Toraja.
  </div>

  <!-- Footer -->
  <div class="surat-footer">
    Diterbitkan oleh Sistem Informasi LPPM IAKN Toraja |
    Nomor: <?= htmlspecialchars($data['nomor_surat']) ?>
  </div>

</div>

</body>
</html>
