<?php
require_once '../../includes/config.php';
require_once '../../includes/penerimaan.php';
require_once '../../includes/profil_check.php';
require_once '../../includes/logger.php';
requireLogin('mahasiswa');
if (isDosen()) { redirect('/dashboard.php'); }

$lang  = $_COOKIE['lang'] ?? 'id';
$uid   = $_SESSION['user_id'];
$error = $success = '';
$penerimaan_tutup = (getPenerimaan($pdo, 'publikasi') === 'tutup');
$profil_check     = cekProfilLengkap($pdo, $uid);
$profil_kurang    = !$profil_check['lengkap'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$profil_kurang) {
    $judul  = clean($_POST['judul'] ?? '');
    $jenis  = clean($_POST['jenis'] ?? '');
    $file   = $_FILES['file_publikasi'] ?? null;

    if ($jenis === 'buku') {
        $jurnal     = clean($_POST['nama_penerbit'] ?? '');
        $tahun      = (int)($_POST['tahun_terbit_buku'] ?? 0);
        $doi        = clean($_POST['kota_penerbit'] ?? '');   // kota di kolom url_doi
        $issn       = clean($_POST['isbn_buku'] ?? '');
        $akreditasi = '';
        $nomor_bab  = null;
        $editor     = '';
    } elseif ($jenis === 'book_chapter') {
        $jurnal     = clean($_POST['nama_penerbit_bc'] ?? '');
        $tahun      = (int)($_POST['tahun_terbit_bc'] ?? 0);
        $doi        = clean($_POST['kota_penerbit_bc'] ?? '');
        $issn       = clean($_POST['isbn_bc'] ?? '');
        $akreditasi = '';
        $nomor_bab  = (int)($_POST['nomor_bab'] ?? 0) ?: null;
        $editor     = clean($_POST['editor_bc'] ?? '');
    } else {
        $jurnal     = clean($_POST['nama_jurnal'] ?? '');
        $tahun      = (int)($_POST['tahun_terbit'] ?? 0);
        $doi        = clean($_POST['url_doi'] ?? '');
        $issn       = clean($_POST['issn_isbn'] ?? '');
        $akreditasi = clean($_POST['akreditasi_jurnal'] ?? '');
        $nomor_bab  = null;
        $editor     = '';
    }

    $jenis_allowed = ['jurnal','book_chapter','buku','prosiding'];

    if (!$judul || !$jenis || !$file || $file['error'] !== 0) {
        $error = $lang==='id' ? 'Judul, jenis, dan file wajib diisi.' : 'Title, type, and file are required.';
    } elseif (!in_array($jenis, $jenis_allowed)) {
        $error = $lang==='id' ? 'Jenis publikasi tidak valid.' : 'Invalid publication type.';
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $error = $lang==='id' ? 'Ukuran file maksimal 20 MB.' : 'Maximum file size is 20 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
            $error = $lang==='id' ? 'Format file harus PDF, JPG, atau PNG.' : 'File must be PDF, JPG, or PNG.';
        } else {
            $dir   = UPLOAD_PATH . 'publikasi/' . $uid . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname   = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file['name']);
            $fpath   = $dir . $fname;
            $db_path = 'uploads/publikasi/' . $uid . '/' . $fname;

            if (move_uploaded_file($file['tmp_name'], $fpath)) {
                $stmt = $pdo->prepare("INSERT INTO publikasi (user_id, judul_publikasi, jenis_publikasi, nama_jurnal_penerbit, tahun_terbit, url_doi, issn_isbn, akreditasi_jurnal, nomor_bab, editor_buku, file_path, file_name, file_size, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$uid, $judul, $jenis, $jurnal ?: null, $tahun ?: null, $doi ?: null, $issn ?: null, $akreditasi ?: null, $nomor_bab, $editor ?: null, $db_path, $file['name'], $file['size'], 'menunggu']);
                writeLog($pdo, (int)$uid, $_SESSION['role'] ?? 'mahasiswa', 'permohonan_publikasi',
                    "Ajukan surat publikasi: {$judul}");

                // Notifikasi admin — kirim ke semua admin aktif
                $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role='admin' AND is_active=1");
                $admin_stmt->execute();
                foreach ($admin_stmt->fetchAll() as $admin) {
                    $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'info')")
                        ->execute([$admin['id'], 'Permohonan Baru: Surat Publikasi', "Mahasiswa {$_SESSION['nama']} mengajukan verifikasi publikasi: {$judul}"]);
                }

                require_once '../../includes/email.php';

                // 1. Notifikasi ke Admin LPPM (To: lppm@iakn-toraja.ac.id, CC: lp2miaknt@gmail.com)
                kirimEmailAdmin($pdo,
                    'Permohonan Surat Keterangan Publikasi Baru - ' . $_SESSION['nama'],
                    $file['name'],
                    $_SESSION['nama'],
                    'Surat Keterangan Publikasi (' . labelJenisPublikasi($jenis) . ')'
                );

                // 2. Konfirmasi upload ke Mahasiswa (To: email mahasiswa, CC: lp2miaknt@gmail.com)
                $stmt_email = $pdo->prepare("SELECT email FROM users WHERE id=?");
                $stmt_email->execute([$uid]);
                $emailMhs = $stmt_email->fetchColumn();
                if ($emailMhs) {
                    kirimEmailKonfirmasiUpload(
                        $emailMhs,
                        $_SESSION['nama'],
                        'Surat Keterangan Publikasi (' . labelJenisPublikasi($jenis) . ')',
                        $file['name']
                    );
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'msg'  => $lang==='id'
                        ? 'Bukti publikasi berhasil diupload! Admin LPPM akan segera memverifikasi.'
                        : 'Publication proof uploaded! LPPM admin will verify shortly.',
                ];
                redirect('/dashboard.php');
            } else {
                $error = $lang==='id' ? 'Gagal menyimpan file.' : 'Failed to save file.';
            }
        }
    }
}

$jenis_options = [
    'jurnal'       => ['id' => 'Artikel Jurnal (Nasional/Internasional)', 'en' => 'Journal Article (National/International)'],
    'book_chapter' => ['id' => 'Book Chapter',                           'en' => 'Book Chapter'],
    'buku'         => ['id' => 'Buku',                                   'en' => 'Book'],
    'prosiding'    => ['id' => 'Prosiding Konferensi',                   'en' => 'Conference Proceedings'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Upload Publikasi':'Upload Publication' ?> — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="../../assets/css/style.css?v=4">
<style>
.pub-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.pub-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:0; }
@media(max-width:640px) {
  .pub-grid-2, .pub-grid-3 { grid-template-columns:1fr; }
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
          <?= $lang==='id' ? 'Upload Bukti Publikasi' : 'Upload Publication Proof' ?>
          <span class="breadcrumb">
            <?= $lang==='id' ? 'Permohonan Surat Keterangan Publikasi' : 'Publication Certificate Application' ?>
          </span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($profil_kurang): renderProfilTidakLengkap($profil_check['missing'], $lang); elseif ($penerimaan_tutup): renderPenerimaanTutup('Surat Rekomendasi Publikasi','Publication Recommendation Letter',$lang,getSetting($pdo,'email_lppm')); else: ?>


      <div class="alert alert-info">
        <strong>ℹ <?= $lang==='id'?'Informasi':'Information' ?>:</strong>
        <?= $lang==='id'
          ? 'Upload bukti publikasi ilmiah Anda (jurnal, book chapter, buku, atau prosiding). Admin LPPM akan memverifikasi dan menerbitkan surat keterangan.'
          : 'Upload your scientific publication proof (journal, book chapter, book, or proceedings). LPPM admin will verify and issue the certificate.' ?>
      </div>

      <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success">
          <?= $success ?>
          <a href="../../dashboard.php" style="font-weight:700;margin-left:8px">← <?= $lang==='id'?'Kembali ke Beranda':'Back to Dashboard' ?></a>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <span class="card-title"><?= ic('newspaper') ?> <?= $lang==='id'?'Form Upload Publikasi':'Publication Upload Form' ?></span>
        </div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data">

            <div class="form-group">
              <label class="form-label" id="judul-label">
                <?= $lang==='id'?'Judul Publikasi':'Publication Title' ?> <span class="required">*</span>
              </label>
              <textarea name="judul" id="judul-input" class="form-control" rows="2" required
                placeholder="<?= $lang==='id'?'Judul artikel/buku/prosiding Anda...':'Your article/book/proceedings title...' ?>"><?= clean($_POST['judul']??'') ?></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Jenis Publikasi':'Publication Type' ?> <span class="required">*</span>
              </label>
              <select name="jenis" class="form-control" required onchange="switchJenis(this.value)">
                <option value=""><?= $lang==='id'?'-- Pilih Jenis --':'-- Select Type --' ?></option>
                <?php foreach($jenis_options as $val=>$label): ?>
                  <option value="<?= $val ?>" <?= ($_POST['jenis']??'')===$val?'selected':'' ?>>
                    <?= $label[$lang] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- ── SEKSI: Jurnal / Prosiding ── -->
            <div id="sec-jurnal" style="display:none">
              <div class="form-group">
                <label class="form-label" id="lbl-nama-jurnal">
                  <?= $lang==='id'?'Nama Jurnal / Konferensi':'Journal / Conference Name' ?> <span class="required">*</span>
                </label>
                <input type="text" name="nama_jurnal" class="form-control" id="inp-nama-jurnal"
                       value="<?= !in_array($_POST['jenis']??'',['buku','book_chapter'])?clean($_POST['nama_jurnal']??''):'' ?>"
                       placeholder="<?= $lang==='id'?'Contoh: Jurnal Pendidikan Agama Kristen, Vol. 5 No. 2':'Example: Journal of Christian Education, Vol. 5 No. 2' ?>">
              </div>
              <div class="pub-grid-3">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Tahun Terbit':'Year of Publication' ?> <span class="required">*</span></label>
                  <select name="tahun_terbit" class="form-control" id="sel-tahun-jurnal"></select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label">ISSN <span class="required">*</span></label>
                  <input type="text" name="issn_isbn" class="form-control"
                         value="<?= clean($_POST['issn_isbn']??'') ?>" placeholder="xxxx-xxxx" required>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label">DOI / URL <span class="required">*</span></label>
                  <input type="url" name="url_doi" class="form-control"
                         value="<?= clean($_POST['url_doi']??'') ?>" placeholder="https://doi.org/..." required>
                </div>
              </div>
              <div class="form-group" style="margin-top:14px" id="col-akreditasi">
                <label class="form-label">
                  <?= $lang==='id'?'Akreditasi Jurnal':'Journal Accreditation' ?> <span class="required">*</span>
                </label>
                <select name="akreditasi_jurnal" class="form-control" id="sel-akred"
                        onchange="previewAkred(this.value)">
                  <option value="">-- <?= $lang==='id'?'Pilih Akreditasi':'Select Accreditation' ?> --</option>
                  <optgroup label="── Sinta (<?= $lang==='id'?'Nasional':'National' ?>) ──">
                    <option value="sinta1" <?= ($_POST['akreditasi_jurnal']??'')==='sinta1'?'selected':'' ?>>Sinta 1 — <?= $lang==='id'?'Tertinggi':'Highest' ?></option>
                    <option value="sinta2" <?= ($_POST['akreditasi_jurnal']??'')==='sinta2'?'selected':'' ?>>Sinta 2</option>
                    <option value="sinta3" <?= ($_POST['akreditasi_jurnal']??'')==='sinta3'?'selected':'' ?>>Sinta 3</option>
                    <option value="sinta4" <?= ($_POST['akreditasi_jurnal']??'')==='sinta4'?'selected':'' ?>>Sinta 4</option>
                    <option value="sinta5" <?= ($_POST['akreditasi_jurnal']??'')==='sinta5'?'selected':'' ?>>Sinta 5</option>
                    <option value="sinta6" <?= ($_POST['akreditasi_jurnal']??'')==='sinta6'?'selected':'' ?>>Sinta 6</option>
                  </optgroup>
                  <optgroup label="── Scopus (<?= $lang==='id'?'Internasional':'International' ?>) ──">
                    <option value="scopusQ1" <?= ($_POST['akreditasi_jurnal']??'')==='scopusQ1'?'selected':'' ?>>Scopus Q1 — <?= $lang==='id'?'Tertinggi':'Highest' ?></option>
                    <option value="scopusQ2" <?= ($_POST['akreditasi_jurnal']??'')==='scopusQ2'?'selected':'' ?>>Scopus Q2</option>
                    <option value="scopusQ3" <?= ($_POST['akreditasi_jurnal']??'')==='scopusQ3'?'selected':'' ?>>Scopus Q3</option>
                  </optgroup>
                </select>
                <div id="akred-badge-wrap" style="margin-top:5px;min-height:20px"></div>
                <div class="form-hint" id="akred-hint">
                  <?= $lang==='id' ? 'Jurnal tidak terakreditasi Sinta atau Scopus tidak dapat diproses.' : 'Journals not accredited by Sinta or Scopus cannot be processed.' ?>
                </div>
              </div>
            </div>

            <!-- ── SEKSI: Buku ── -->
            <div id="sec-buku-only" style="display:none">
              <div class="pub-grid-2">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Kota Penerbit':'City of Publication' ?> <span class="required">*</span></label>
                  <input type="text" name="kota_penerbit" class="form-control"
                         value="<?= clean($_POST['kota_penerbit']??'') ?>"
                         placeholder="<?= $lang==='id'?'Contoh: Yogyakarta, Jakarta':'Example: Yogyakarta, Jakarta' ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Nama Penerbit':'Publisher Name' ?> <span class="required">*</span></label>
                  <input type="text" name="nama_penerbit" class="form-control"
                         value="<?= ($_POST['jenis']??'')==='buku'?clean($_POST['nama_penerbit']??''):'' ?>"
                         placeholder="<?= $lang==='id'?'Contoh: Deepublish, Gramedia...':'Example: Deepublish, Gramedia...' ?>" required>
                </div>
              </div>
              <div class="pub-grid-2" style="margin-top:14px">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Tahun Terbit':'Year of Publication' ?> <span class="required">*</span></label>
                  <select name="tahun_terbit_buku" class="form-control" id="sel-tahun-buku" required></select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label">ISBN <span class="required">*</span></label>
                  <input type="text" name="isbn_buku" class="form-control"
                         value="<?= clean($_POST['isbn_buku']??'') ?>" placeholder="978-x-xxx-xxxxx-x" required>
                </div>
              </div>
            </div>

            <!-- ── SEKSI: Book Chapter ── -->
            <div id="sec-book-chapter" style="display:none">
              <div class="pub-grid-2">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Nomor Bab / Chapter':'Chapter Number' ?> <span class="required">*</span></label>
                  <select name="nomor_bab" class="form-control" required>
                    <option value="">-- <?= $lang==='id'?'Pilih':'Select' ?> --</option>
                    <?php for($c=1;$c<=25;$c++): ?>
                      <option value="<?= $c ?>" <?= ($_POST['nomor_bab']??'')==$c?'selected':'' ?>>
                        <?= $lang==='id'?"Bab $c":"Chapter $c" ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Editor Buku':'Book Editor' ?> <span class="required">*</span></label>
                  <input type="text" name="editor_bc" class="form-control"
                         value="<?= clean($_POST['editor_bc']??'') ?>"
                         placeholder="<?= $lang==='id'?'Nama editor / penyunting buku':'Book editor name' ?>" required>
                </div>
              </div>
              <div class="pub-grid-2" style="margin-top:14px">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Kota Penerbit':'City of Publication' ?> <span class="required">*</span></label>
                  <input type="text" name="kota_penerbit_bc" class="form-control"
                         value="<?= clean($_POST['kota_penerbit_bc']??'') ?>"
                         placeholder="<?= $lang==='id'?'Contoh: Yogyakarta, Jakarta':'Example: Yogyakarta, Jakarta' ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Penerbit':'Publisher' ?> <span class="required">*</span></label>
                  <input type="text" name="nama_penerbit_bc" class="form-control"
                         value="<?= clean($_POST['nama_penerbit_bc']??'') ?>"
                         placeholder="<?= $lang==='id'?'Nama penerbit buku':'Book publisher name' ?>" required>
                </div>
              </div>
              <div class="pub-grid-2" style="margin-top:14px">
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label"><?= $lang==='id'?'Tahun Terbit':'Year of Publication' ?> <span class="required">*</span></label>
                  <select name="tahun_terbit_bc" class="form-control" id="sel-tahun-bc" required></select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label">ISBN <span class="required">*</span></label>
                  <input type="text" name="isbn_bc" class="form-control"
                         value="<?= clean($_POST['isbn_bc']??'') ?>" placeholder="978-x-xxx-xxxxx-x" required>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'File Bukti Publikasi':'Publication Proof File' ?> <span class="required">*</span>
                <span class="lang"><?= $lang==='id'?'(PDF, JPG, atau PNG)':'(PDF, JPG, or PNG)' ?></span>
              </label>
              <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                <input type="file" id="fileInput" name="file_publikasi" accept=".pdf,.jpg,.jpeg,.png" required onchange="showFile(this)">
                <div class="upload-icon">
                  <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div class="upload-text" id="uploadText">
                  <?= $lang==='id'?'Klik untuk pilih file':'Click to select file' ?>
                </div>
                <div class="upload-sub">
                  <?= $lang==='id'?'Maksimal 20 MB · PDF/JPG/PNG':'Maximum 20 MB · PDF/JPG/PNG' ?>
                </div>
              </div>
            </div>

            <div id="filePreview" style="display:none;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f1f5f9;border-radius:8px">
                <span style="font-size:24px">📎</span>
                <div>
                  <div id="fileName" style="font-weight:600;font-size:14px"></div>
                  <div id="fileSize" style="font-size:12px;color:#64748b"></div>
                </div>
                <button type="button" onclick="clearFile()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#94a3b8"><?= ic('x') ?></button>
              </div>
            </div>

            <div style="display:flex;gap:12px">
              <button type="submit" class="btn btn-primary btn-lg">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <?= $lang==='id'?'Upload & Ajukan':'Upload & Submit' ?>
              </button>
              <a href="../../dashboard.php" class="btn btn-outline">← <?= $lang==='id'?'Kembali':'Back' ?></a>
            </div>
          </form>
        </div>
      </div>

      <!-- Panduan -->
      <div class="card" style="margin-top:20px">
        <div class="card-header"><span class="card-title"><?= ic('clipboard') ?> <?= $lang==='id'?'Panduan':'Guide' ?></span></div>
        <div class="card-body">
          <ol style="padding-left:18px;line-height:2;font-size:14px;color:#475569">
            <?php if ($lang==='id'): ?>
            <li>Upload bukti publikasi berupa file <strong>PDF</strong> (scan/export jurnal), <strong>JPG/PNG</strong> (screenshot).</li>
            <li>Jenis publikasi yang diterima: Artikel Jurnal, Book Chapter, Buku, dan Prosiding Konferensi.</li>
            <li>Admin LPPM akan memverifikasi keaslian dan kelayakan publikasi Anda.</li>
            <li>Jika disetujui, surat keterangan publikasi akan <strong>diterbitkan otomatis</strong>.</li>
            <li>Unduh surat, print, dan bawa ke kantor LPPM untuk tanda tangan dan stempel.</li>
            <?php else: ?>
            <li>Upload publication proof as <strong>PDF</strong> (journal scan/export) or <strong>JPG/PNG</strong> (screenshot).</li>
            <li>Accepted types: Journal Article, Book Chapter, Book, and Conference Proceedings.</li>
            <li>LPPM admin will verify the authenticity and eligibility of your publication.</li>
            <li>If approved, the publication certificate will be <strong>automatically issued</strong>.</li>
            <li>Download the certificate, print it, and bring to the LPPM office for signature and stamp.</li>
            <?php endif; ?>
          </ol>
        </div>
      </div>

      <?php endif; // profil_kurang / penerimaan_tutup / form ?>

    </div>
  </div>
</div>
<script>
// ── Inisialisasi dropdown tahun & tampilkan seksi sesuai jenis ──
(function(){
  const now = new Date().getFullYear();
  ['sel-tahun-jurnal','sel-tahun-buku','sel-tahun-bc'].forEach(id => {
    const s = document.getElementById(id);
    if (!s) return;
    s.innerHTML = '<option value="">-- Pilih Tahun --</option>';
    for (let y = now; y >= 2010; y--) {
      const o = document.createElement('option');
      o.value = y;
      o.textContent = y === now ? y + ' (tahun ini)' : y;
      if (y === now) o.selected = true;
      s.appendChild(o);
    }
  });
  // Restore seksi jika halaman reload karena error validasi
  const jenis = document.querySelector('[name="jenis"]')?.value;
  if (jenis) switchJenis(jenis);
})();

function _setSectionActive(id, active) {
  const sec = document.getElementById(id);
  if (!sec) return;
  sec.style.display = active ? 'block' : 'none';
  // disable/enable agar required tidak divalidasi & field tidak terkirim saat tersembunyi
  sec.querySelectorAll('input,select,textarea').forEach(el => {
    el.disabled = !active;
  });
}

function switchJenis(val) {
  _setSectionActive('sec-jurnal',       val === 'jurnal' || val === 'prosiding');
  _setSectionActive('sec-buku-only',    val === 'buku');
  _setSectionActive('sec-book-chapter', val === 'book_chapter');

  // Update label judul utama
  const lbl = document.getElementById('judul-label');
  const inp = document.getElementById('judul-input');
  if (lbl) {
    const labels = {
      jurnal:       ['Judul Artikel *',  'Article Title *'],
      prosiding:    ['Judul Artikel Prosiding *', 'Proceedings Article Title *'],
      buku:         ['Judul Buku *',     'Book Title *'],
      book_chapter: ['Judul Chapter *',  'Chapter Title *'],
    };
    const [id_, en_] = labels[val] || ['Judul Publikasi *', 'Publication Title *'];
    lbl.innerHTML = (document.documentElement.lang === 'en' ? en_ : id_) + ' <span class="required">*</span>';
  }
  if (inp) {
    const ph = {
      jurnal:       'Judul lengkap artikel jurnal Anda...',
      prosiding:    'Judul lengkap artikel prosiding Anda...',
      buku:         'Judul buku yang diterbitkan...',
      book_chapter: 'Judul chapter / bab yang Anda tulis...',
    };
    inp.placeholder = ph[val] || 'Judul publikasi Anda...';
  }
}

// ── Badge preview akreditasi jurnal ──
function previewAkred(val) {
  const wrap = document.getElementById('akred-badge-wrap');
  const hint = document.getElementById('akred-hint');
  if (!wrap) return;

  const map = {
    '':         { label:'Belum dipilih',  cls:'',         hint:'Jurnal tidak terakreditasi Sinta atau tidak terindeks Scopus tidak dapat diproses.' },
    'sinta1':   { label:'✦ Sinta 1',      cls:'sinta',    hint:'Peringkat Sinta 1 — tertinggi untuk jurnal nasional.' },
    'sinta2':   { label:'✦ Sinta 2',      cls:'sinta',    hint:'Jurnal nasional terakreditasi Sinta 2.' },
    'sinta3':   { label:'✦ Sinta 3',      cls:'sinta',    hint:'Jurnal nasional terakreditasi Sinta 3.' },
    'sinta4':   { label:'✦ Sinta 4',      cls:'sinta',    hint:'Jurnal nasional terakreditasi Sinta 4.' },
    'sinta5':   { label:'✦ Sinta 5',      cls:'sinta',    hint:'Jurnal nasional terakreditasi Sinta 5.' },
    'sinta6':   { label:'✦ Sinta 6',      cls:'sinta',    hint:'Jurnal nasional terakreditasi Sinta 6.' },
    'scopusQ1': { label:'◆ Scopus Q1',    cls:'scopus',   hint:'Jurnal internasional terindeks Scopus kuartil Q1 — peringkat tertinggi.' },
    'scopusQ2': { label:'◆ Scopus Q2',    cls:'scopus',   hint:'Jurnal internasional terindeks Scopus kuartil Q2.' },
    'scopusQ3': { label:'◆ Scopus Q3',    cls:'scopus',   hint:'Jurnal internasional terindeks Scopus kuartil Q3.' },
  };

  const c = map[val] || map[''];

  // Warna badge
  const style = {
    sinta:  'background:#dbeafe;color:#1e3a5f;border:1px solid #93c5fd',
    scopus: 'background:#faeeda;color:#7a3f0e;border:1px solid #fbbf24',
    '':     'background:#f1f5f9;color:#94a3b8;font-style:italic;border:1px solid #e2e8f0',
  }[c.cls] || '';

  wrap.innerHTML = c.label
    ? `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:12px;font-size:10px;font-weight:600;${style}">${c.label}</span>`
    : `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:12px;font-size:10px;${style}">${c.label || 'Belum dipilih'}</span>`;

  if (hint) hint.innerHTML = c.hint;
}

// ── Upload file ──
function showFile(input) {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    document.getElementById('fileName').textContent = f.name;
    document.getElementById('fileSize').textContent = (f.size/1048576).toFixed(2)+' MB';
    document.getElementById('filePreview').style.display='block';
    document.getElementById('uploadArea').style.borderColor='#1e3a5f';
  }
}
function clearFile() {
  document.getElementById('fileInput').value='';
  document.getElementById('filePreview').style.display='none';
  document.getElementById('uploadArea').style.borderColor='';
}
const area=document.getElementById('uploadArea');
area.addEventListener('dragover',e=>{e.preventDefault();area.classList.add('dragover');});
area.addEventListener('dragleave',()=>area.classList.remove('dragover'));
area.addEventListener('drop',e=>{e.preventDefault();area.classList.remove('dragover');const fi=document.getElementById('fileInput');fi.files=e.dataTransfer.files;showFile(fi);});
function toggleLang(){const cur=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>
