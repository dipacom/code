<?php
require_once '../../includes/config.php';
require_once '../../includes/penerimaan.php';
require_once '../../includes/profil_check.php';
require_once '../../includes/logger.php';
requireLogin('mahasiswa');
if (isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$error = $success = '';
$penerimaan_tutup = (getPenerimaan($pdo, 'plagiasi') === 'tutup');
$profil_check     = cekProfilLengkap($pdo, $uid);
$profil_kurang    = !$profil_check['lengkap'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$profil_kurang) {
    $jenis_ta = clean($_POST['jenis_ta'] ?? 'skripsi');
    if (!in_array($jenis_ta, ['skripsi','tesis','disertasi'])) $jenis_ta = 'skripsi';
    $judul    = clean($_POST['judul'] ?? '');
    $pemb1    = clean($_POST['pembimbing1'] ?? '');
    $pemb2    = clean($_POST['pembimbing2'] ?? '');
    $pemb3    = clean($_POST['pembimbing3'] ?? '');
    $tahun    = (int)($_POST['tahun_sidang'] ?? 0);
    $file     = $_FILES['file_skripsi'] ?? null;
    $ta_labels = ['skripsi'=>'Skripsi','tesis'=>'Tesis','disertasi'=>'Disertasi'];

    // Data cek mandiri similarity (opsional)
    $sim_mandiri  = trim($_POST['similarity_mandiri'] ?? '');
    $sim_mandiri  = ($sim_mandiri !== '') ? (float)str_replace(',', '.', $sim_mandiri) : null;
    $platform     = clean($_POST['platform_mandiri'] ?? '');
    $cat_mandiri  = clean($_POST['catatan_mandiri'] ?? '');
    $file_mandiri = $_FILES['file_cek_mandiri'] ?? null;

    // Data cek AI mandiri (opsional)
    $ai_mandiri       = trim($_POST['ai_mandiri'] ?? '');
    $ai_mandiri       = ($ai_mandiri !== '') ? (float)str_replace(',', '.', $ai_mandiri) : null;
    $platform_ai      = clean($_POST['platform_ai_mandiri'] ?? '');
    $file_ai_mandiri  = $_FILES['file_ai_mandiri'] ?? null;

    if (!$judul || !$file || $file['error'] !== 0) {
        $lbl = $ta_labels[$jenis_ta] ?? 'Tugas Akhir';
        $error = $lang==='id' ? "Judul dan file {$lbl} wajib diisi." : "Title and {$lbl} file are required.";
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $error = $lang==='id' ? 'Ukuran file maksimal 20 MB.' : 'Maximum file size is 20 MB.';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $error = $lang==='id' ? 'File harus dalam format PDF.' : 'File must be in PDF format.';
    } elseif ($file_mandiri && $file_mandiri['error'] === 0) {
        $ext_m = strtolower(pathinfo($file_mandiri['name'], PATHINFO_EXTENSION));
        if (!in_array($ext_m, ['pdf','jpg','jpeg','png'])) {
            $error = $lang==='id' ? 'Bukti cek plagiasi harus PDF, JPG, atau PNG.' : 'Plagiarism check proof must be PDF, JPG, or PNG.';
        } elseif ($file_mandiri['size'] > MAX_UPLOAD_SIZE) {
            $error = $lang==='id' ? 'Ukuran file bukti maksimal 20 MB.' : 'Proof file maximum size is 20 MB.';
        }
    } elseif ($file_ai_mandiri && $file_ai_mandiri['error'] === 0) {
        $ext_ai = strtolower(pathinfo($file_ai_mandiri['name'], PATHINFO_EXTENSION));
        if (!in_array($ext_ai, ['pdf','jpg','jpeg','png'])) {
            $error = $lang==='id' ? 'Bukti deteksi AI harus PDF, JPG, atau PNG.' : 'AI detection proof must be PDF, JPG, or PNG.';
        } elseif ($file_ai_mandiri['size'] > MAX_UPLOAD_SIZE) {
            $error = $lang==='id' ? 'Ukuran file bukti AI maksimal 20 MB.' : 'AI proof file maximum size is 20 MB.';
        }
    }

    if (!$error) {
        // Simpan file skripsi
        $dir = UPLOAD_PATH . 'skripsi/' . $uid . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname   = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file['name']);
        $fpath   = $dir . $fname;
        $db_path = 'uploads/skripsi/' . $uid . '/' . $fname;

        if (!move_uploaded_file($file['tmp_name'], $fpath)) {
            $error = $lang==='id' ? 'Gagal menyimpan file. Coba lagi.' : 'Failed to save file. Please try again.';
        } else {
            // Simpan file bukti cek mandiri (jika ada)
            $db_path_mandiri = null;
            if ($file_mandiri && $file_mandiri['error'] === 0) {
                $dir_m  = UPLOAD_PATH . 'cek_mandiri/' . $uid . '/';
                if (!is_dir($dir_m)) mkdir($dir_m, 0755, true);
                $fname_m  = time() . '_cek_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file_mandiri['name']);
                if (move_uploaded_file($file_mandiri['tmp_name'], $dir_m . $fname_m)) {
                    $db_path_mandiri = 'uploads/cek_mandiri/' . $uid . '/' . $fname_m;
                }
            }

            // Simpan file bukti deteksi AI mandiri (jika ada)
            $db_path_ai_mandiri = null;
            if ($file_ai_mandiri && $file_ai_mandiri['error'] === 0) {
                $dir_ai  = UPLOAD_PATH . 'cek_mandiri/' . $uid . '/';
                if (!is_dir($dir_ai)) mkdir($dir_ai, 0755, true);
                $fname_ai = time() . '_ai_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file_ai_mandiri['name']);
                if (move_uploaded_file($file_ai_mandiri['tmp_name'], $dir_ai . $fname_ai)) {
                    $db_path_ai_mandiri = 'uploads/cek_mandiri/' . $uid . '/' . $fname_ai;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO skripsi
                (user_id, jenis_tugas_akhir, judul_skripsi,
                 nama_pembimbing1, nama_pembimbing2, nama_pembimbing3, tahun_sidang,
                 file_path, file_name, file_size, status,
                 similarity_mandiri, platform_mandiri, file_cek_mandiri, catatan_mandiri,
                 ai_mandiri, platform_ai_mandiri, file_ai_mandiri)
                VALUES (?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?)");
            $stmt->execute([
                $uid, $jenis_ta, $judul,
                $pemb1 ?: null, $pemb2 ?: null, $pemb3 ?: null, $tahun ?: null,
                $db_path, $file['name'], $file['size'], 'menunggu',
                $sim_mandiri, $platform ?: null, $db_path_mandiri, $cat_mandiri ?: null,
                $ai_mandiri, $platform_ai ?: null, $db_path_ai_mandiri
            ]);
            $skripsi_id = $pdo->lastInsertId();
            writeLog($pdo, (int)$uid, $_SESSION['role'] ?? 'mahasiswa', 'permohonan_plagiasi',
                "Ajukan cek plagiasi: {$judul}");

            // Notifikasi DB — kirim ke semua admin aktif
            $ta_lbl    = $ta_labels[$jenis_ta] ?? 'Tugas Akhir';
            $notif_msg = "Mahasiswa {$_SESSION['nama']} mengajukan cek bebas plagiasi untuk {$ta_lbl}: {$judul}";
            if ($sim_mandiri !== null) {
                $notif_msg .= " (Hasil cek mandiri: {$sim_mandiri}%)";
            }
            $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role='admin' AND is_active=1");
            $admin_stmt->execute();
            foreach ($admin_stmt->fetchAll() as $admin) {
                $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'info')")
                    ->execute([$admin['id'], 'Permohonan Baru: Cek Plagiasi', $notif_msg]);
            }

            // Kirim email ke admin
            require_once '../../includes/email.php';

            kirimEmailAdmin($pdo,
                'Permohonan Cek Bebas Plagiasi Baru - ' . $_SESSION['nama'],
                $file['name'],
                $_SESSION['nama'],
                'Surat Keterangan Bebas Plagiasi (' . ($ta_labels[$jenis_ta]??'Tugas Akhir') . ')'
            );

            $stmt_email = $pdo->prepare("SELECT email FROM users WHERE id=?");
            $stmt_email->execute([$uid]);
            $emailMhs = $stmt_email->fetchColumn();
            if ($emailMhs) {
                kirimEmailKonfirmasiUpload(
                    $emailMhs,
                    $_SESSION['nama'],
                    'Surat Keterangan Bebas Plagiasi',
                    $file['name']
                );
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => $lang==='id'
                    ? ($ta_labels[$jenis_ta]??'Tugas Akhir') . ' berhasil diupload! Admin LPPM akan segera memproses permohonan Anda.'
                    : ($ta_labels[$jenis_ta]??'Tugas Akhir') . ' uploaded successfully! LPPM admin will process your application shortly.',
            ];
            redirect('/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Upload Tugas Akhir':'Upload Final Project' ?> — LPPM IAKN Toraja</title>
<style>
.ta-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:580px){ .ta-grid-2 { grid-template-columns:1fr; } }

/* ── Cek Mandiri Side-by-Side ── */
.sc-wrap {
  margin:24px 0 0;
  border:1.5px solid var(--border-strong);
  border-radius:var(--radius-lg);
  overflow:hidden;
}
.sc-wrap-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 16px;
  background:linear-gradient(135deg, #0d0428 0%, #1a0a3d 100%);
}
.sc-wrap-title {
  display:flex; align-items:center; gap:8px;
  font-size:13px; font-weight:700; color:#fff;
}
.sc-wrap-title svg { color:var(--accent-light); }
.sc-opt-badge {
  font-size:10px; font-weight:600; color:rgba(255,255,255,0.5);
  background:rgba(255,255,255,0.10); padding:2px 9px; border-radius:20px;
}
.sc-wrap-body { padding:14px; background:#faf9fe; }
.sc-wrap-desc {
  font-size:11px; color:var(--text-muted); line-height:1.6;
  margin-bottom:12px;
}
.sc-grid {
  display:grid; grid-template-columns:1fr 1fr; gap:12px;
}
.sc-box {
  border-radius:10px; padding:13px 13px 11px;
}
.sc-box.sim {
  border:1.5px dashed #a78bfa;
  background:linear-gradient(135deg, #faf9fe 0%, #f5f3ff 100%);
}
.sc-box.ai {
  border:1.5px dashed #8b5cf6;
  background:linear-gradient(135deg, #fdf4ff 0%, #f5f3ff 100%);
}
.sc-head {
  display:flex; align-items:center; gap:7px; margin-bottom:10px;
}
.sc-head-icon {
  width:24px; height:24px; border-radius:6px;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.sc-box.sim .sc-head-icon { background:rgba(167,139,250,0.18); color:#6d28d9; }
.sc-box.ai  .sc-head-icon { background:rgba(139,92,246,0.18);  color:#6d28d9; }
.sc-head-icon svg { width:13px; height:13px; }
.sc-head span { font-size:12px; font-weight:700; color:var(--primary); }
.sc-score { position:relative; }
.sc-score input { padding-right:28px; font-size:13px; }
.sc-score .unit { position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#94a3b8; pointer-events:none; }
.sc-preview { margin-top:6px; text-align:center; display:none; }
.sc-preview .sp-num { font-size:22px; font-weight:800; line-height:1; }
.sc-preview .sp-bar { height:5px; background:#e2e8f0; border-radius:3px; overflow:hidden; margin:5px auto; max-width:140px; }
.sc-preview .sp-fill { height:100%; border-radius:3px; transition:width .4s; }
.sc-preview .sp-lbl  { font-size:10px; font-weight:600; }
.sc-upload {
  border:1px dashed #c4b5fd; border-radius:7px; padding:7px 10px;
  cursor:pointer; margin-top:8px; display:flex; align-items:center;
  gap:6px; font-size:11px; color:var(--text-secondary); transition:border-color .15s;
  background:#fff;
}
.sc-upload:hover { border-color:var(--primary-mid); }
.sc-upload input { display:none; }
.sc-chosen {
  display:flex; align-items:center; gap:6px; margin-top:5px;
  padding:5px 8px; background:#fff; border-radius:5px;
  border:1px solid var(--border); font-size:11px;
}
.sc-chosen .scf-name { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:600; }
.sc-catatan { margin-top:12px; }
@media(max-width:640px) { .sc-grid { grid-template-columns:1fr; } }
</style>
<link rel="stylesheet" href="../../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= $lang==='id' ? 'Upload Tugas Akhir' : 'Upload Final Project' ?>
          <span class="breadcrumb">
            <?= $lang==='id' ? 'Permohonan Surat Keterangan Bebas Plagiasi' : 'Plagiarism-Free Certificate Application' ?>
          </span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($profil_kurang): renderProfilTidakLengkap($profil_check['missing'], $lang); elseif ($penerimaan_tutup): renderPenerimaanTutup('Surat Bebas Plagiasi','Plagiarism Certificate',$lang,getSetting($pdo,'email_lppm')); else: ?>


      <!-- Info Box -->
      <div class="alert alert-info" style="margin-bottom:20px">
        <strong>ℹ <?= $lang==='id'?'Informasi':'Information' ?>:</strong>
        <?php
        $batas_sim_info = (int)(getSetting($pdo,'batas_similarity') ?: 20);
        $batas_ai_info  = (int)(getSetting($pdo,'batas_ai') ?: 30);
        ?>
        <?= $lang==='id'
          ? "Batas maksimal <strong>Similarity Turnitin</strong> adalah <strong>{$batas_sim_info}%</strong> dan <strong>Penggunaan AI</strong> adalah <strong>{$batas_ai_info}%</strong>. Jika hasil melebihi batas, Anda perlu merevisi <span id='info-jenis-ta'>tugas akhir</span> dan mengajukan ulang."
          : "Maximum <strong>Turnitin Similarity</strong> is <strong>{$batas_sim_info}%</strong> and <strong>AI Usage</strong> is <strong>{$batas_ai_info}%</strong>. If results exceed the limit, you need to revise your <span id='info-jenis-ta'>final project</span> and resubmit." ?>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success">
          <?= $success ?>
          <a href="../../dashboard.php" style="font-weight:700;margin-left:8px">
            ← <?= $lang==='id'?'Kembali ke Beranda':'Back to Dashboard' ?>
          </a>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <span class="card-title" id="card-title-ta">
            <?= ic('doc') ?> <?= $lang==='id' ? 'Form Upload Tugas Akhir' : 'Final Project Upload Form' ?>
          </span>
        </div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data" id="uploadForm">

            <!-- Jenis Tugas Akhir -->
            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Jenis Tugas Akhir':'Final Project Type' ?> <span class="required">*</span>
              </label>
              <select name="jenis_ta" class="form-control" required onchange="switchTA(this.value)"
                      style="max-width:320px">
                <option value=""><?= $lang==='id'?'-- Pilih Jenis --':'-- Select Type --' ?></option>
                <option value="skripsi"    <?= ($_POST['jenis_ta']??'')==='skripsi'   ?'selected':'' ?>>Skripsi (S1)</option>
                <option value="tesis"      <?= ($_POST['jenis_ta']??'')==='tesis'     ?'selected':'' ?>>Tesis (S2)</option>
                <option value="disertasi"  <?= ($_POST['jenis_ta']??'')==='disertasi' ?'selected':'' ?>>Disertasi (S3)</option>
              </select>
            </div>

            <!-- Judul -->
            <div class="form-group">
              <label class="form-label" id="lbl-judul">
                <?= $lang==='id'?'Judul Tugas Akhir':'Final Project Title' ?> <span class="required">*</span>
                <span class="lang" id="lbl-judul-hint"><?= $lang==='id'?'(sesuai halaman judul)':'(as on the title page)' ?></span>
              </label>
              <textarea name="judul" id="inp-judul" class="form-control" rows="3" required
                placeholder="<?= $lang==='id'?'Masukkan judul lengkap tugas akhir Anda...':'Enter the full title of your thesis...' ?>"><?= clean($_POST['judul']??'') ?></textarea>
            </div>

            <!-- Pembimbing -->
            <div class="ta-grid-2" id="row-pemb-12">
              <div class="form-group">
                <label class="form-label" id="lbl-pemb1">
                  <?= $lang==='id'?'Nama Pembimbing I':'Supervisor I' ?>
                </label>
                <input type="text" name="pembimbing1" class="form-control" id="inp-pemb1"
                       value="<?= clean($_POST['pembimbing1']??'') ?>"
                       placeholder="<?= $lang==='id'?'Nama dosen pembimbing pertama':'First supervisor name' ?>">
              </div>
              <div class="form-group">
                <label class="form-label" id="lbl-pemb2">
                  <?= $lang==='id'?'Nama Pembimbing II':'Supervisor II' ?>
                  <span id="hint-pemb2" style="font-size:11px;color:var(--text-muted);font-weight:400"> — <?= $lang==='id'?'opsional':'optional' ?></span>
                </label>
                <input type="text" name="pembimbing2" class="form-control" id="inp-pemb2"
                       value="<?= clean($_POST['pembimbing2']??'') ?>"
                       placeholder="<?= $lang==='id'?'Nama dosen pembimbing kedua':'Second supervisor' ?>">
              </div>
            </div>

            <!-- Ko-Promotor 2 — hanya muncul untuk Disertasi -->
            <div class="form-group" id="row-pemb3" style="display:none;max-width:50%">
              <label class="form-label">
                Ko-Promotor 2
                <span style="font-size:11px;color:var(--text-muted);font-weight:400"> — <?= $lang==='id'?'opsional':'optional' ?></span>
              </label>
              <input type="text" name="pembimbing3" class="form-control"
                     value="<?= clean($_POST['pembimbing3']??'') ?>"
                     placeholder="<?= $lang==='id'?'Nama Ko-Promotor 2 (jika ada)':'Co-supervisor 2 name (if any)' ?>">
            </div>

            <!-- Tahun Sidang -->
            <div class="form-group" style="max-width:200px">
              <label class="form-label" id="lbl-tahun">
                <?= $lang==='id'?'Tahun Sidang':'Defense Year' ?>
              </label>
              <input type="number" name="tahun_sidang" class="form-control"
                     min="2010" max="<?= date('Y') ?>"
                     value="<?= clean($_POST['tahun_sidang']??date('Y')) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'File Skripsi (PDF)':'Thesis File (PDF)' ?>
                <span class="required">*</span>
              </label>
              <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                <input type="file" id="fileInput" name="file_skripsi" accept=".pdf" required onchange="showFile(this)">
                <div class="upload-icon">
                  <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
                <div class="upload-text" id="uploadText">
                  <?= $lang==='id'?'Klik untuk pilih file PDF':'Click to select PDF file' ?>
                </div>
                <div class="upload-sub">
                  <?= $lang==='id'?'Maksimal 20 MB · Format PDF':'Maximum 20 MB · PDF format only' ?>
                </div>
              </div>
            </div>

            <!-- Preview file terpilih -->
            <div id="filePreview" style="display:none;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#f1f5f9;border-radius:8px;border:1px solid #e2e8f0">
                <?= ic('doc', 'style="width:28px;height:28px;color:var(--primary)"') ?>
                <div>
                  <div id="fileName" style="font-weight:600;font-size:14px"></div>
                  <div id="fileSize" style="font-size:12px;color:#64748b"></div>
                </div>
                <button type="button" onclick="clearFile()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#94a3b8"><?= ic('x') ?></button>
              </div>
            </div>

            <!-- ══ SEKSI CEK MANDIRI + AI — Side by Side ══ -->
            <div class="sc-wrap">
              <!-- Header -->
              <div class="sc-wrap-head">
                <div class="sc-wrap-title">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <?= $lang==='id'?'Hasil Cek Mandiri (Turnitin &amp; AI)':'Self-Check Results (Turnitin &amp; AI)' ?>
                </div>
                <span class="sc-opt-badge"><?= $lang==='id'?'Opsional':'Optional' ?></span>
              </div>

              <!-- Body -->
              <div class="sc-wrap-body">
                <p class="sc-wrap-desc">
                  <?= $lang==='id'
                    ? 'Lampirkan hasil cek mandiri jika sudah tersedia. Admin LPPM akan menggunakan data ini sebagai pembanding saat verifikasi resmi.'
                    : 'Attach self-check results if available. LPPM admin will use this data for comparison during official verification.' ?>
                </p>

                <!-- 2-kolom: Similarity | Deteksi AI -->
                <div class="sc-grid">

                  <!-- ── Kolom Kiri: Turnitin Similarity ── -->
                  <div class="sc-box sim">
                    <div class="sc-head">
                      <div class="sc-head-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                      </div>
                      <span><?= $lang==='id'?'Similarity Turnitin':'Turnitin Similarity' ?></span>
                    </div>

                    <div class="form-group" style="margin-bottom:8px">
                      <label class="form-label" style="font-size:10px"><?= $lang==='id'?'Skor (%)':'Score (%)' ?></label>
                      <div class="sc-score">
                        <input type="number" name="similarity_mandiri" id="sim_mandiri_input"
                               class="form-control" step="0.01" min="0" max="100" placeholder="0.00"
                               value="<?= htmlspecialchars($_POST['similarity_mandiri']??'') ?>"
                               oninput="previewMandiri(this.value)">
                        <span class="unit">%</span>
                      </div>
                      <div id="mandiri_preview" class="sc-preview">
                        <span id="mandiri_angka" class="sp-num"></span>
                        <div class="sp-bar"><div id="mandiri_fill" class="sp-fill"></div></div>
                        <div id="mandiri_label" class="sp-lbl"></div>
                      </div>
                    </div>

                    <div class="form-group" style="margin-bottom:8px">
                      <label class="form-label" style="font-size:10px"><?= $lang==='id'?'Platform':'Platform' ?></label>
                      <select name="platform_mandiri" class="form-control" style="font-size:12px">
                        <option value=""><?= $lang==='id'?'-- Pilih --':'-- Select --' ?></option>
                        <?php
                        $platforms = ['Turnitin','iThenticate','PlagScan','Grammarly Plagiarism','Duplichecker','Plagiarism Checker X','Unicheck','Lainnya / Other'];
                        $selPlatform = $_POST['platform_mandiri'] ?? '';
                        foreach ($platforms as $p): ?>
                          <option value="<?= htmlspecialchars($p) ?>" <?= $selPlatform===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div onclick="document.getElementById('fileMandiriInput').click()" class="sc-upload">
                      <input type="file" id="fileMandiriInput" name="file_cek_mandiri"
                             accept=".pdf,.jpg,.jpeg,.png" onchange="showFileMandiri(this)">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      <span id="uploadTextMandiri"><?= $lang==='id'?'Lampirkan bukti (PDF/JPG/PNG)':'Attach proof (PDF/JPG/PNG)' ?></span>
                    </div>
                    <div id="fileMandiriPreview" class="sc-chosen" style="display:none">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#6d28d9" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      <span id="fileNameMandiri" class="scf-name"></span>
                      <span id="fileSizeMandiri" style="color:#94a3b8;white-space:nowrap;font-size:10px"></span>
                      <button type="button" onclick="clearFileMandiri()" style="background:none;border:none;cursor:pointer;color:#94a3b8;line-height:1;padding:0 2px">✕</button>
                    </div>
                  </div><!-- /sim -->

                  <!-- ── Kolom Kanan: Deteksi AI ── -->
                  <div class="sc-box ai">
                    <div class="sc-head">
                      <div class="sc-head-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 9h2m2 0h2m2 0h2"/></svg>
                      </div>
                      <span><?= $lang==='id'?'Deteksi AI':'AI Detection' ?></span>
                    </div>

                    <div class="form-group" style="margin-bottom:8px">
                      <label class="form-label" style="font-size:10px"><?= $lang==='id'?'Skor AI (%)':'AI Score (%)' ?></label>
                      <div class="sc-score">
                        <input type="number" name="ai_mandiri" id="ai_mandiri_input"
                               class="form-control" step="0.01" min="0" max="100" placeholder="0.00"
                               value="<?= htmlspecialchars($_POST['ai_mandiri']??'') ?>"
                               oninput="previewAIMandiri(this.value)">
                        <span class="unit">%</span>
                      </div>
                      <div id="ai_mandiri_preview" class="sc-preview">
                        <span id="ai_mandiri_angka" class="sp-num"></span>
                        <div class="sp-bar"><div id="ai_mandiri_fill" class="sp-fill"></div></div>
                        <div id="ai_mandiri_label" class="sp-lbl"></div>
                      </div>
                    </div>

                    <div class="form-group" style="margin-bottom:8px">
                      <label class="form-label" style="font-size:10px"><?= $lang==='id'?'Platform AI':'AI Platform' ?></label>
                      <select name="platform_ai_mandiri" class="form-control" style="font-size:12px">
                        <option value=""><?= $lang==='id'?'-- Pilih --':'-- Select --' ?></option>
                        <?php
                        $aiPlatforms = ['Turnitin AI Detection','GPTZero','Copyleaks AI Detector','Winston AI','Originality.ai','ZeroGPT','Quillbot AI Detector','Lainnya / Other'];
                        $selAiPlatform = $_POST['platform_ai_mandiri'] ?? '';
                        foreach ($aiPlatforms as $p): ?>
                          <option value="<?= htmlspecialchars($p) ?>" <?= $selAiPlatform===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div onclick="document.getElementById('fileAIInput').click()" class="sc-upload" style="border-color:#c4b5fd">
                      <input type="file" id="fileAIInput" name="file_ai_mandiri"
                             accept=".pdf,.jpg,.jpeg,.png" onchange="showFileAI(this)">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      <span id="uploadTextAI"><?= $lang==='id'?'Lampirkan bukti (PDF/JPG/PNG)':'Attach proof (PDF/JPG/PNG)' ?></span>
                    </div>
                    <div id="fileAIPreview" class="sc-chosen" style="display:none">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#6d28d9" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      <span id="fileNameAI" class="scf-name"></span>
                      <span id="fileSizeAI" style="color:#94a3b8;white-space:nowrap;font-size:10px"></span>
                      <button type="button" onclick="clearFileAI()" style="background:none;border:none;cursor:pointer;color:#94a3b8;line-height:1;padding:0 2px">✕</button>
                    </div>
                  </div><!-- /ai -->

                </div><!-- /sc-grid -->

                <!-- Keterangan Tambahan — full width -->
                <div class="sc-catatan">
                  <label class="form-label"><?= $lang==='id'?'Keterangan Tambahan':'Additional Notes' ?></label>
                  <textarea name="catatan_mandiri" class="form-control" rows="2"
                    placeholder="<?= $lang==='id'?'Contoh: Dicek menggunakan akun kampus, tanggal 10 April 2026...':'E.g.: Checked using campus account, on April 10 2026...' ?>"><?= htmlspecialchars($_POST['catatan_mandiri']??'') ?></textarea>
                </div>

              </div><!-- /sc-wrap-body -->
            </div><!-- /sc-wrap -->
            <!-- /SEKSI CEK MANDIRI + AI -->

            <div style="display:flex;gap:12px;align-items:center;margin-top:24px">
              <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <?= $lang==='id'?'Upload & Ajukan Permohonan':'Upload & Submit Application' ?>
              </button>
              <a href="../../dashboard.php" class="btn btn-outline">
                ← <?= $lang==='id'?'Kembali':'Back' ?>
              </a>
            </div>

          </form>
        </div>
      </div>

      <!-- Panduan -->
      <div class="card" style="margin-top:20px">
        <div class="card-header">
          <span class="card-title"><?= ic('clipboard') ?> <?= $lang==='id'?'Panduan Upload':'Upload Guide' ?></span>
        </div>
        <div class="card-body">
          <ol style="padding-left:18px;line-height:2;font-size:14px;color:#475569">
            <?php if ($lang==='id'): ?>
            <li>Pilih jenis tugas akhir: <strong>Skripsi</strong> (S1), <strong>Tesis</strong> (S2), atau <strong>Disertasi</strong> (S3).</li>
            <li>Upload file tugas akhir dalam format <strong>PDF</strong>, maksimal <strong>20 MB</strong>.</li>
            <li>Pastikan judul sesuai dengan halaman judul pada dokumen tugas akhir.</li>
            <li>Admin LPPM akan mengecek menggunakan <strong>Turnitin</strong>.</li>
            <li>Jika similarity <strong>≤ 20%</strong>, surat keterangan akan <strong>otomatis diterbitkan</strong> dan dapat diunduh.</li>
            <li>Jika similarity <strong>&gt; 20%</strong>, Anda akan mendapat notifikasi email untuk merevisi dokumen.</li>
            <li>Setelah mengunduh surat, <strong>print</strong> dan bawa ke kantor LPPM untuk tanda tangan dan stempel basah.</li>
            <?php else: ?>
            <li>Select the type: <strong>Skripsi</strong> (S1/Bachelor), <strong>Tesis</strong> (S2/Master), or <strong>Disertasi</strong> (S3/Doctoral).</li>
            <li>Upload your final project as a <strong>PDF</strong> file, maximum <strong>20 MB</strong>.</li>
            <li>Make sure the title matches the title page in your document.</li>
            <li>LPPM admin will check your document using <strong>Turnitin</strong>.</li>
            <li>If similarity is <strong>≤ 20%</strong>, the certificate will be <strong>automatically issued</strong> and ready to download.</li>
            <li>If similarity is <strong>&gt; 20%</strong>, you will receive an email notification to revise your document.</li>
            <li>After downloading the letter, <strong>print</strong> it and bring it to the LPPM office for signature and wet stamp.</li>
            <?php endif; ?>
          </ol>
        </div>
      </div>

      <?php endif; // profil_kurang / penerimaan_tutup / form ?>

    </div>
  </div>
</div>

<script>
// ── Jenis Tugas Akhir ──
const TA_CFG = {
  skripsi: {
    judul:  ['Judul Skripsi *',    'Thesis Title *'],
    hint:   ['(sesuai halaman judul skripsi)', '(as on the thesis title page)'],
    ph:     ['Masukkan judul lengkap skripsi Anda...', 'Enter the full title of your thesis...'],
    pemb1:  ['Nama Pembimbing I',  'Supervisor I'],
    pemb2:  ['Nama Pembimbing II', 'Supervisor II'],
    ph_pemb1: ['Nama dosen pembimbing pertama', 'First supervisor name'],
    ph_pemb2: ['Nama dosen pembimbing kedua',   'Second supervisor name'],
    tahun:  ['Tahun Sidang',       'Defense Year'],
    card:   ['Form Upload Skripsi','Thesis Upload Form'],
  },
  tesis: {
    judul:  ['Judul Tesis *',      'Thesis Title *'],
    hint:   ['(sesuai halaman judul tesis)', '(as on the thesis title page)'],
    ph:     ['Masukkan judul lengkap tesis Anda...', 'Enter the full title of your master thesis...'],
    pemb1:  ['Pembimbing Utama',   'Main Supervisor'],
    pemb2:  ['Pembimbing Pendamping', 'Co-Supervisor'],
    ph_pemb1: ['Nama dosen pembimbing utama',    'Main supervisor name'],
    ph_pemb2: ['Nama dosen pembimbing pendamping', 'Co-supervisor name'],
    tahun:  ['Tahun Ujian Tesis',  'Thesis Defense Year'],
    card:   ['Form Upload Tesis',  'Thesis Upload Form'],
  },
  disertasi: {
    judul:  ['Judul Disertasi *',  'Dissertation Title *'],
    hint:   ['(sesuai halaman judul disertasi)', '(as on the dissertation title page)'],
    ph:     ['Masukkan judul lengkap disertasi Anda...', 'Enter the full title of your dissertation...'],
    pemb1:  ['Promotor',           'Promotor'],
    pemb2:  ['Ko-Promotor 1',      'Co-Promotor 1'],
    ph_pemb1: ['Nama Promotor',    'Promotor name'],
    ph_pemb2: ['Nama Ko-Promotor 1', 'Co-Promotor 1 name'],
    tahun:  ['Tahun Ujian Disertasi', 'Dissertation Defense Year'],
    card:   ['Form Upload Disertasi', 'Dissertation Upload Form'],
  },
};
const LANG = document.documentElement.lang === 'en' ? 1 : 0;

function switchTA(val) {
  const cfg = TA_CFG[val];
  if (!cfg) return;

  const set = (id, text) => { const el = document.getElementById(id); if(el) el.firstChild.textContent = text; };
  const setText = (id, text) => { const el = document.getElementById(id); if(el) el.textContent = text; };
  const setAttr = (id, attr, val) => { const el = document.getElementById(id); if(el) el[attr] = val; };

  // Judul
  setText('lbl-judul', cfg.judul[LANG] + ' ');
  setText('lbl-judul-hint', cfg.hint[LANG]);
  setAttr('inp-judul', 'placeholder', cfg.ph[LANG]);

  // Pembimbing labels + placeholders
  setText('lbl-pemb1', cfg.pemb1[LANG]);
  setText('lbl-pemb2', cfg.pemb2[LANG]);
  setAttr('inp-pemb1', 'placeholder', cfg.ph_pemb1[LANG]);
  setAttr('inp-pemb2', 'placeholder', cfg.ph_pemb2[LANG]);

  // Ko-Promotor 2 — hanya Disertasi
  const row3 = document.getElementById('row-pemb3');
  if (row3) row3.style.display = val === 'disertasi' ? 'block' : 'none';

  // Update istilah di kotak info
  const infoSpan = document.getElementById('info-jenis-ta');
  if (infoSpan) {
    const taTerm = { skripsi:'skripsi', tesis:'tesis', disertasi:'disertasi' };
    const taTerm_en = { skripsi:'thesis', tesis:'thesis', disertasi:'dissertation' };
    infoSpan.textContent = LANG ? (taTerm_en[val]||'final project') : (taTerm[val]||'tugas akhir');
  }

  // Tahun label
  setText('lbl-tahun', cfg.tahun[LANG]);

  // Card title
  const ct = document.getElementById('card-title-ta');
  if (ct) ct.innerHTML = ct.innerHTML.replace(/Form Upload .+/, 'Form Upload ' + ['Skripsi','Tesis','Disertasi'][['skripsi','tesis','disertasi'].indexOf(val)] || 'Tugas Akhir');
}

// Restore saat reload karena error validasi
(function(){
  const v = document.querySelector('[name="jenis_ta"]')?.value;
  if (v) switchTA(v);
})();

function showFile(input) {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    document.getElementById('fileName').textContent = f.name;
    document.getElementById('fileSize').textContent = (f.size / 1048576).toFixed(2) + ' MB';
    document.getElementById('filePreview').style.display = 'block';
    document.getElementById('uploadText').textContent = '<?= $lang==="id"?"File terpilih:":"File selected:" ?>';
    document.getElementById('uploadArea').style.borderColor = '#1e3a5f';
  }
}
function clearFile() {
  document.getElementById('fileInput').value = '';
  document.getElementById('filePreview').style.display = 'none';
  document.getElementById('uploadText').textContent = '<?= $lang==="id"?"Klik untuk pilih file PDF":"Click to select PDF file" ?>';
  document.getElementById('uploadArea').style.borderColor = '';
}
// Drag and drop — file skripsi
const area = document.getElementById('uploadArea');
area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('dragover'); });
area.addEventListener('dragleave', () => area.classList.remove('dragover'));
area.addEventListener('drop', e => {
  e.preventDefault();
  area.classList.remove('dragover');
  const fi = document.getElementById('fileInput');
  fi.files = e.dataTransfer.files;
  showFile(fi);
});

// File bukti cek mandiri
function showFileMandiri(input) {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    document.getElementById('fileNameMandiri').textContent = f.name;
    document.getElementById('fileSizeMandiri').textContent = (f.size / 1048576).toFixed(2) + ' MB';
    document.getElementById('fileMandiriPreview').style.display = 'block';
    document.getElementById('uploadTextMandiri').textContent = '<?= $lang==="id"?"File terpilih":"File selected" ?>';
    document.getElementById('uploadAreaMandiri').style.borderColor = 'var(--accent)';
  }
}
function clearFileMandiri() {
  document.getElementById('fileMandiriInput').value = '';
  document.getElementById('fileMandiriPreview').style.display = 'none';
  document.getElementById('uploadTextMandiri').textContent = '<?= $lang==="id"?"Klik untuk lampirkan bukti":"Click to attach proof" ?>';
  document.getElementById('uploadAreaMandiri').style.borderColor = '';
}

// Preview skor mandiri
function previewMandiri(v) {
  const batas = 20;
  const el = document.getElementById('mandiri_preview');
  if (v === '') { el.style.display = 'none'; return; }
  el.style.display = 'block';
  const pct = Math.min(parseFloat(v) || 0, 100);
  const ok  = pct <= batas;
  document.getElementById('mandiri_angka').textContent = pct + '%';
  document.getElementById('mandiri_angka').style.color = ok ? '#059669' : '#dc2626';
  document.getElementById('mandiri_fill').style.width  = pct + '%';
  document.getElementById('mandiri_fill').style.background = ok ? '#059669' : '#dc2626';
  document.getElementById('mandiri_label').textContent = ok
    ? '✓ <?= $lang==="id"?"Di bawah batas 20%":"Below 20% threshold" ?>'
    : '✕ <?= $lang==="id"?"Melebihi batas 20%":"Exceeds 20% threshold" ?>';
  document.getElementById('mandiri_label').style.color = ok ? '#059669' : '#dc2626';
}

// File bukti deteksi AI mandiri
function showFileAI(input) {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    document.getElementById('fileNameAI').textContent = f.name;
    document.getElementById('fileSizeAI').textContent = (f.size / 1048576).toFixed(2) + ' MB';
    document.getElementById('fileAIPreview').style.display = 'block';
    document.getElementById('uploadTextAI').textContent = '<?= $lang==="id"?"File terpilih":"File selected" ?>';
    document.getElementById('uploadAreaAI').style.borderColor = '#7c3aed';
  }
}
function clearFileAI() {
  document.getElementById('fileAIInput').value = '';
  document.getElementById('fileAIPreview').style.display = 'none';
  document.getElementById('uploadTextAI').textContent = '<?= $lang==="id"?"Klik untuk lampirkan bukti deteksi AI":"Click to attach AI detection proof" ?>';
  document.getElementById('uploadAreaAI').style.borderColor = '';
}
function previewAIMandiri(v) {
  const el = document.getElementById('ai_mandiri_preview');
  if (v === '') { el.style.display = 'none'; return; }
  el.style.display = 'block';
  const pct = Math.min(parseFloat(v) || 0, 100);
  const ok  = pct <= 20;
  document.getElementById('ai_mandiri_angka').textContent = pct + '%';
  document.getElementById('ai_mandiri_angka').style.color = ok ? '#059669' : '#dc2626';
  document.getElementById('ai_mandiri_fill').style.width  = pct + '%';
  document.getElementById('ai_mandiri_fill').style.background = ok ? '#059669' : '#dc2626';
  document.getElementById('ai_mandiri_label').textContent = ok
    ? '✓ <?= $lang==="id"?"Di bawah batas AI 20%":"Below AI threshold 20%" ?>'
    : '✕ <?= $lang==="id"?"Melebihi batas AI 20%":"Exceeds AI threshold 20%" ?>';
  document.getElementById('ai_mandiri_label').style.color = ok ? '#059669' : '#dc2626';
}

function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
