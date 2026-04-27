<?php
require_once '../../includes/config.php';
require_once '../../includes/penerimaan.php';
require_once '../../includes/profil_check.php';
require_once '../../includes/logger.php';
requireLogin('mahasiswa');
if (isMahasiswa()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$error = $success = '';
$profil_check  = cekProfilLengkap($pdo, $uid);
$profil_kurang = !$profil_check['lengkap'];

// Ambil link template dari pengaturan
$link_permohonan   = getSetting($pdo, 'ec_link_surat_permohonan')   ?: '';
$link_pernyataan   = getSetting($pdo, 'ec_link_surat_pernyataan')   ?: '';
$link_persetujuan  = getSetting($pdo, 'ec_link_persetujuan_subjek') ?: '';
$penerimaan_tutup  = (getPenerimaan($pdo, 'ec') === 'tutup');

// ── Proses POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$profil_kurang) {
    $judul          = clean($_POST['judul']           ?? '');
    $jurnal         = clean($_POST['nama_jurnal']     ?? '');
    $ketua_peneliti = clean($_POST['ketua_peneliti']  ?? '');
    $mns_manusia    = ($_POST['melibatkan_manusia']   ?? '') === 'ya' ? 1 : 0;
    $mns_hewan      = ($_POST['melibatkan_hewan']     ?? '') === 'ya' ? 1 : 0;
    $anggota        = clean($_POST['anggota_tim']     ?? '');
    $deskripsi      = clean($_POST['deskripsi']       ?? '');

    $f_permohonan   = $_FILES['file_surat_permohonan']   ?? null;
    $f_pernyataan   = $_FILES['file_surat_pernyataan']   ?? null;
    $f_persetujuan  = $_FILES['file_persetujuan_subjek'] ?? null;
    $f_laporan      = $_FILES['file_laporan']            ?? null;
    $f_proposal     = $_FILES['file_proposal']           ?? null;
    $f_surat_izin   = $_FILES['file_surat_izin']         ?? null;
    $f_cek_mandiri  = $_FILES['file_cek_mandiri']        ?? null;
    $f_ai_mandiri   = $_FILES['file_ai_mandiri']         ?? null;

    $sim_mandiri = trim($_POST['similarity_mandiri'] ?? '');
    $sim_mandiri = ($sim_mandiri !== '') ? (float)str_replace(',', '.', $sim_mandiri) : null;
    $plat_sim    = clean($_POST['platform_mandiri']    ?? '');
    $ai_mandiri  = trim($_POST['ai_mandiri'] ?? '');
    $ai_mandiri  = ($ai_mandiri !== '') ? (float)str_replace(',', '.', $ai_mandiri) : null;
    $plat_ai     = clean($_POST['platform_ai_mandiri'] ?? '');

    $allowed_ext = ['pdf','jpg','jpeg','png'];
    $allowed_doc = ['pdf','doc','docx','jpg','jpeg','png'];

    if (!$judul) {
        $error = $lang==='id' ? 'Judul penelitian wajib diisi.' : 'Research title is required.';
    } elseif (!$ketua_peneliti) {
        $error = $lang==='id' ? 'Nama Ketua Peneliti wajib diisi.' : 'Principal Investigator name is required.';
    } elseif (!$f_permohonan || $f_permohonan['error'] !== 0) {
        $error = $lang==='id' ? 'Scan Surat Permohonan wajib diunggah.' : 'Application letter scan is required.';
    } elseif (!$f_pernyataan || $f_pernyataan['error'] !== 0) {
        $error = $lang==='id' ? 'Scan Surat Pernyataan Bermeterai wajib diunggah.' : 'Stamped declaration letter scan is required.';
    } elseif (!$f_laporan || $f_laporan['error'] !== 0) {
        $error = $lang==='id' ? 'File Laporan Penelitian / Artikel wajib diunggah.' : 'Research report / article file is required.';
    }

    if (!$error) {
        $file_checks = [
            [$f_permohonan, $allowed_ext, 'Surat Permohonan'],
            [$f_pernyataan, $allowed_ext, 'Surat Pernyataan'],
            [$f_laporan,    $allowed_doc, 'Laporan Penelitian'],
        ];
        if ($mns_manusia && $f_persetujuan && $f_persetujuan['error'] === 0) {
            $file_checks[] = [$f_persetujuan, $allowed_ext, 'Surat Persetujuan Subjek'];
        }
        if ($f_proposal && $f_proposal['error'] === 0) {
            $file_checks[] = [$f_proposal, $allowed_doc, 'Proposal Penelitian'];
        }
        if ($f_surat_izin && $f_surat_izin['error'] === 0) {
            $file_checks[] = [$f_surat_izin, $allowed_ext, 'Surat Izin Pihak Terkait'];
        }
        if ($f_cek_mandiri && $f_cek_mandiri['error'] === 0) {
            $file_checks[] = [$f_cek_mandiri, $allowed_ext, 'Bukti Cek Similarity'];
        }
        if ($f_ai_mandiri && $f_ai_mandiri['error'] === 0) {
            $file_checks[] = [$f_ai_mandiri, $allowed_ext, 'Bukti Deteksi AI'];
        }
        foreach ($file_checks as [$fobj, $exts, $label]) {
            if (!$fobj || $fobj['error'] !== 0) continue;
            $ext = strtolower(pathinfo($fobj['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $exts)) {
                $error = "{$label}: " . ($lang==='id' ? 'format file tidak didukung.' : 'unsupported file format.');
                break;
            }
            if ($fobj['size'] > MAX_UPLOAD_SIZE) {
                $error = "{$label}: " . ($lang==='id' ? 'ukuran maksimal 20 MB.' : 'maximum size is 20 MB.');
                break;
            }
        }
    }

    if (!$error) {
        $uid_dir = UPLOAD_PATH . 'ethical_clearance/' . $uid . '/';
        if (!is_dir($uid_dir)) mkdir($uid_dir, 0755, true);

        $save = function($file, $prefix) use ($uid_dir, $uid) {
            if (!$file || $file['error'] !== 0) return null;
            $fname = time() . '_' . $prefix . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file['name']);
            if (move_uploaded_file($file['tmp_name'], $uid_dir . $fname)) {
                return 'uploads/ethical_clearance/' . $uid . '/' . $fname;
            }
            return null;
        };

        $path_permohonan  = $save($f_permohonan, 'permohonan');
        $path_pernyataan  = $save($f_pernyataan, 'pernyataan');
        $path_persetujuan = ($mns_manusia && $f_persetujuan && $f_persetujuan['error']===0)
                            ? $save($f_persetujuan, 'persetujuan') : null;
        $path_laporan     = $save($f_laporan, 'laporan');
        $path_proposal    = ($f_proposal   && $f_proposal['error']===0)   ? $save($f_proposal,   'proposal') : null;
        $path_surat_izin  = ($f_surat_izin && $f_surat_izin['error']===0) ? $save($f_surat_izin, 'izin')    : null;
        $path_cek_sim     = ($f_cek_mandiri && $f_cek_mandiri['error']===0) ? $save($f_cek_mandiri, 'sim') : null;
        $path_cek_ai      = ($f_ai_mandiri  && $f_ai_mandiri['error']===0)  ? $save($f_ai_mandiri,  'ai')  : null;

        // Kompatibilitas skema DB: beberapa instance belum punya kolom baru
        $ec_cols = [];
        try {
            $qCols = $pdo->query("SHOW COLUMNS FROM ethical_clearance");
            foreach ($qCols->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ec_cols[$c['Field']] = true;
            }
        } catch (\Throwable $e) {
            $error = $lang==='id'
                ? 'Gagal membaca struktur tabel Ethical Clearance.'
                : 'Failed to read Ethical Clearance table structure.';
        }

        if (!$error) {
            $insCols = [];
            $insVals = [];

            $addCol = function(string $col, $val) use (&$insCols, &$insVals, $ec_cols) {
                if (!empty($ec_cols[$col])) {
                    $insCols[] = $col;
                    $insVals[] = $val;
                }
            };

            // kolom inti
            $addCol('user_id', $uid);
            $addCol('judul_penelitian', $judul);
            $addCol('nama_jurnal', $jurnal ?: null);

            // mapping kompatibel antar versi schema
            $addCol('ketua_peneliti', $ketua_peneliti ?: null); // schema baru
            $addCol('nama_ketua', $ketua_peneliti ?: null);     // fallback schema lama/varian

            $addCol('melibatkan_manusia', $mns_manusia);               // schema baru
            $addCol('melibatkan_subjek_manusia', $mns_manusia);        // schema lama

            $addCol('melibatkan_hewan', $mns_hewan);
            $addCol('anggota_tim', $anggota ?: null);
            $addCol('deskripsi', $deskripsi ?: null);
            $addCol('abstrak', $deskripsi ?: null); // fallback schema lama

            // file utama
            $addCol('file_surat_permohonan', $path_permohonan);
            $addCol('file_surat_permohonan_name', $f_permohonan['name'] ?? null);

            $addCol('file_surat_pernyataan', $path_pernyataan);
            $addCol('file_surat_pernyataan_name', $f_pernyataan['name'] ?? null);

            $addCol('file_persetujuan_subjek', $path_persetujuan);
            $addCol('file_persetujuan_subjek_name', $f_persetujuan['name'] ?? null);

            $addCol('file_laporan', $path_laporan); // schema baru

            $addCol('file_proposal', $path_proposal);
            $addCol('file_proposal_name', $f_proposal['name'] ?? null);

            $addCol('file_surat_izin', $path_surat_izin);

            // fallback field file umum (schema lama)
            $addCol('file_path', $path_laporan);
            $addCol('file_name', $f_laporan['name'] ?? null);
            $addCol('file_size', $f_laporan['size'] ?? null);

            // self-check
            $addCol('similarity_mandiri', $sim_mandiri);
            $addCol('platform_mandiri', $plat_sim ?: null);
            $addCol('file_cek_mandiri', $path_cek_sim);

            $addCol('ai_mandiri', $ai_mandiri);
            $addCol('platform_ai_mandiri', $plat_ai ?: null);
            $addCol('file_ai_mandiri', $path_cek_ai);

            // status
            $addCol('status', 'menunggu');

            if (empty($insCols)) {
                $error = $lang==='id'
                    ? 'Tidak ada kolom yang cocok untuk menyimpan permohonan Ethical Clearance.'
                    : 'No compatible columns found to save Ethical Clearance submission.';
            } else {
                $placeholders = implode(',', array_fill(0, count($insCols), '?'));
                $sql = "INSERT INTO ethical_clearance (" . implode(',', $insCols) . ") VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($insVals);
            }
        }

        if (!$error) {
            writeLog($pdo, (int)$uid, $_SESSION['role'] ?? 'mahasiswa', 'permohonan_ec',
                "Ajukan Ethical Clearance: {$judul}");

            $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role='admin' AND is_active=1");
            $admin_stmt->execute();
            foreach ($admin_stmt->fetchAll() as $admin) {
                $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'info')")
                    ->execute([$admin['id'], 'Permohonan Baru: Ethical Clearance',
                        "{$_SESSION['nama']} mengajukan Ethical Clearance: {$judul}"]);
            }

            require_once '../../includes/email.php';
            kirimEmailAdmin($pdo,
                'Permohonan Ethical Clearance Baru — ' . $_SESSION['nama'],
                $f_laporan['name'], $_SESSION['nama'], 'Surat Ethical Clearance'
            );
            $emailUser = $pdo->prepare("SELECT email FROM users WHERE id=?");
            $emailUser->execute([$uid]);
            $em = $emailUser->fetchColumn();
            if ($em) kirimEmailKonfirmasiUpload($em, $_SESSION['nama'], 'Permohonan Ethical Clearance', $f_laporan['name']);

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => $lang==='id'
                    ? 'Permohonan Ethical Clearance berhasil diajukan! Admin LPPM akan segera memprosesnya.'
                    : 'Ethical Clearance application submitted! LPPM admin will process it shortly.',
            ];
            redirect('/dashboard.php');
        }
    }
}

$post = $_POST;

// Ambil no_hp dari profil
$stmt_hp = $pdo->prepare("SELECT no_hp FROM users WHERE id=?");
$stmt_hp->execute([$uid]);
$no_hp_val = $stmt_hp->fetchColumn() ?: '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Permohonan Ethical Clearance':'Ethical Clearance Application' ?> — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="../../assets/css/style.css?v=4">
<style>
/* ── Layout responsif 2-kolom ── */
.fg2 { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
@media(max-width:640px) { .fg2 { grid-template-columns:1fr; } }

/* ── Radio Ya/Tidak ── */
.yn-group { display:flex; gap:8px; flex-wrap:wrap; }
.yn-label {
  display:flex; align-items:center; gap:6px;
  padding:8px 16px; border:1.5px solid var(--border);
  border-radius:8px; cursor:pointer; font-size:12px; font-weight:600;
  transition:border-color .15s, background .15s; user-select:none;
  color:var(--text-secondary);
}
.yn-label:has(input:checked) { border-color:var(--primary); background:var(--primary-xlight); color:var(--primary); }
.yn-label input { display:none; }
.yn-label svg { flex-shrink:0; }

/* ── Upload area compact ── */
.upload-compact {
  border:1.5px dashed var(--border); border-radius:8px;
  padding:11px 14px; cursor:pointer; transition:border-color .15s;
}
.upload-compact:hover { border-color:var(--primary); }
.upload-compact input[type="file"] { display:none; }
.upload-compact .uc-label { font-size:13px; color:var(--text-secondary); display:flex; align-items:center; gap:7px; }
.upload-compact .uc-sub { font-size:11px; color:var(--text-muted); margin-top:3px; padding-left:21px; }
.file-chosen {
  display:flex; align-items:center; gap:8px;
  padding:7px 10px; background:#f1f5f9; border-radius:7px;
  margin-top:6px; font-size:12px;
}
.file-chosen .fc-name { font-weight:600; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.file-chosen .fc-size { color:#64748b; white-space:nowrap; }

/* ── Template link ── */
.template-link {
  font-size:12px; font-weight:600; color:var(--primary);
  display:inline-flex; align-items:center; gap:4px;
  margin-bottom:8px; text-decoration:none;
}
.template-link:hover { text-decoration:underline; }

/* ── Conditional block ── */
.cond-block { overflow:hidden; transition:max-height .3s ease, opacity .3s ease; }
.cond-block.hidden  { max-height:0; opacity:0; pointer-events:none; }
.cond-block.visible { max-height:500px; opacity:1; }

/* ── Self-check ── */
.sc-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.sc-box { border:1.5px dashed var(--border); border-radius:10px; padding:13px 13px 11px; }
.sc-box.sim { border-color:#94a3b8; background:#f8fafc; }
.sc-box.ai  { border-color:#a78bfa; background:#faf5ff; }
.sc-head { display:flex; align-items:center; gap:6px; margin-bottom:10px; }
.sc-head span { font-size:12px; font-weight:700; }
.sc-score { position:relative; }
.sc-score input { padding-right:28px; }
.sc-score .unit { position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#94a3b8; pointer-events:none; }
.sc-upload {
  border:1px dashed #cbd5e1; border-radius:6px; padding:7px 10px;
  cursor:pointer; margin-top:8px; display:flex; align-items:center;
  gap:6px; font-size:11px; color:var(--text-secondary); transition:border-color .15s;
}
.sc-upload:hover { border-color:var(--primary); }
.sc-upload input { display:none; }
.sc-chosen {
  display:flex; align-items:center; gap:6px; margin-top:5px;
  padding:5px 8px; background:#fff; border-radius:5px;
  border:1px solid #e2e8f0; font-size:11px;
}
.sc-chosen .scf-name { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:600; }
@media(max-width:640px) {
  .sc-grid { grid-template-columns:1fr; }
  #prep-body > div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
}

/* ── Section label ── */
.sec-label {
  display:flex; align-items:center; gap:8px;
  font-size:13px; font-weight:700; color:var(--text-primary); margin-bottom:4px;
}
.badge-opt {
  font-size:10px; font-weight:600; background:#e2e8f0;
  color:#64748b; padding:2px 8px; border-radius:10px;
}

/* ── Dokumen Persyaratan ── */
.doc-section-hd {
  font-size:10px; font-weight:700; text-transform:uppercase;
  letter-spacing:.07em; padding:4px 10px; border-radius:20px;
  display:inline-flex; align-items:center; gap:5px; margin-bottom:10px;
}
.doc-section-hd.req { background:#dbeafe; color:#1d4ed8; }
.doc-section-hd.opt { background:#f1f5f9; color:#64748b; }
.doc-item {
  border-radius:10px; padding:13px 14px; margin-bottom:0;
}
.doc-item.req { background:#f0f7ff; border:1.5px solid #bfdbfe; }
.doc-item.opt { background:#f8fafc; border:1.5px dashed #cbd5e1; }
.doc-item .form-label { margin-bottom:6px; }
.doc-item .uc-label span { font-size:12px; }
.doc-item .uc-sub { font-size:10px; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?php /* clipboard icon */ ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-3px;margin-right:6px"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>Ethical Clearance
          <span class="breadcrumb"><?= $lang==='id'?'Permohonan Surat Ethical Clearance':'Ethical Clearance Application' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($profil_kurang): renderProfilTidakLengkap($profil_check['missing'], $lang); elseif ($penerimaan_tutup): renderPenerimaanTutup('Ethical Clearance','Ethical Clearance',$lang,getSetting($pdo,'email_lppm')); else: ?>


      <!-- ══ Panel Persiapan Dokumen ══ -->
      <div id="prep-panel" style="border:1.5px solid #bfdbfe;border-radius:12px;background:#f0f7ff;margin-bottom:20px;overflow:hidden">

        <!-- Header panel (klik untuk toggle) -->
        <div onclick="togglePrep()" style="display:flex;align-items:center;justify-content:space-between;
             padding:14px 18px;cursor:pointer;user-select:none">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="background:#1d4ed8;border-radius:8px;padding:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
            <div>
              <div style="font-size:13px;font-weight:700;color:#1e3a5f">
                <?= $lang==='id'?'Dokumen yang Harus Disiapkan Sebelum Mengajukan':'Documents to Prepare Before Submitting' ?>
              </div>
              <div style="font-size:11px;color:#3b82f6;margin-top:1px">
                <?= $lang==='id'?'Klik untuk melihat / menyembunyikan daftar':'Click to show / hide the checklist' ?>
              </div>
            </div>
          </div>
          <svg id="prep-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               style="flex-shrink:0;transition:transform .25s">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <!-- Isi panel -->
        <div id="prep-body" style="border-top:1px solid #bfdbfe;padding:16px 18px 18px">

          <p style="font-size:12px;color:#1e3a5f;margin:0 0 14px;line-height:1.6">
            <?= $lang==='id'
              ? 'Pastikan semua dokumen berikut sudah <strong>disiapkan, ditandatangani, dan di-scan / difoto</strong> sebelum mengisi formulir ini. Pengajuan yang tidak lengkap tidak dapat diproses oleh Komite Etik LPPM.'
              : 'Make sure all the documents below are <strong>prepared, signed, and scanned / photographed</strong> before filling this form. Incomplete applications cannot be processed by the LPPM Ethics Committee.' ?>
          </p>

          <!-- Grid dokumen -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">

            <!-- Wajib 1: Surat Permohonan -->
            <div style="background:#fff;border:1.5px solid #93c5fd;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#dbeafe;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#1e3a5f">
                  <?= $lang==='id'?'Surat Permohonan':'Application Letter' ?>
                  <span style="background:#dbeafe;color:#1d4ed8;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px">WAJIB</span>
                </div>
                <div style="font-size:11px;color:#475569;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Unduh format, isi, tanda tangan, lalu scan/foto. Format: PDF/JPG/PNG.'
                    : 'Download the template, fill it in, sign, then scan/photograph. Format: PDF/JPG/PNG.' ?>
                </div>
                <?php if ($link_permohonan): ?>
                <a href="<?= htmlspecialchars($link_permohonan) ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;
                          font-weight:600;color:#1d4ed8;text-decoration:none">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  <?= $lang==='id'?'Unduh template':'Download template' ?>
                </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- Wajib 2: Surat Pernyataan Bermeterai -->
            <div style="background:#fff;border:1.5px solid #93c5fd;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#dbeafe;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#1e3a5f">
                  <?= $lang==='id'?'Surat Pernyataan Bermeterai Rp10.000':'Stamped Declaration Letter (Rp10,000)' ?>
                  <span style="background:#dbeafe;color:#1d4ed8;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px">WAJIB</span>
                </div>
                <div style="font-size:11px;color:#475569;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Isi, tempel meterai Rp10.000, tanda tangan di atas meterai, lalu scan. Format: PDF/JPG/PNG.'
                    : 'Fill in, affix Rp10,000 stamp, sign on top of the stamp, then scan. Format: PDF/JPG/PNG.' ?>
                </div>
                <?php if ($link_pernyataan): ?>
                <a href="<?= htmlspecialchars($link_pernyataan) ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;
                          font-weight:600;color:#1d4ed8;text-decoration:none">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  <?= $lang==='id'?'Unduh template':'Download template' ?>
                </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- Wajib 3: Laporan/Artikel -->
            <div style="background:#fff;border:1.5px solid #93c5fd;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#dbeafe;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#1e3a5f">
                  <?= $lang==='id'?'Laporan / Naskah Artikel Penelitian':'Research Report / Article Manuscript' ?>
                  <span style="background:#dbeafe;color:#1d4ed8;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px">WAJIB</span>
                </div>
                <div style="font-size:11px;color:#475569;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Draft artikel atau laporan yang akan diajukan ke jurnal. Format: PDF/DOC/DOCX.'
                    : 'Draft article or report to be submitted to the journal. Format: PDF/DOC/DOCX.' ?>
                </div>
              </div>
            </div>

            <!-- Kondisional: Informed Consent -->
            <div style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#fef3c7;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#92400e">
                  <?= $lang==='id'?'Surat Persetujuan Subjek (Informed Consent)':'Subject Consent Letter (Informed Consent)' ?>
                  <span style="background:#fef3c7;color:#b45309;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px"><?= $lang==='id'?'JIKA MELIBATKAN MANUSIA':'IF INVOLVES HUMANS' ?></span>
                </div>
                <div style="font-size:11px;color:#78350f;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Wajib jika penelitian melibatkan subjek manusia. Isi, tanda tangan subjek, lalu scan. Format: PDF/JPG/PNG.'
                    : 'Required if research involves human subjects. Fill in, have subjects sign, then scan. Format: PDF/JPG/PNG.' ?>
                </div>
                <?php if ($link_persetujuan): ?>
                <a href="<?= htmlspecialchars($link_persetujuan) ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;font-size:11px;
                          font-weight:600;color:#b45309;text-decoration:none">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  <?= $lang==='id'?'Unduh template':'Download template' ?>
                </a>
                <?php endif; ?>
              </div>
            </div>

            <!-- Opsional 1: Proposal -->
            <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#f1f5f9;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#475569">
                  <?= $lang==='id'?'Proposal Penelitian':'Research Proposal' ?>
                  <span style="background:#f1f5f9;color:#64748b;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px"><?= $lang==='id'?'OPSIONAL':'OPTIONAL' ?></span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Lampirkan jika penelitian baru akan dimulai dan laporan belum tersedia. Format: PDF/DOC/DOCX.'
                    : 'Attach if research is yet to begin and no report is available. Format: PDF/DOC/DOCX.' ?>
                </div>
              </div>
            </div>

            <!-- Opsional 2: Surat Izin -->
            <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:9px;padding:12px 13px;display:flex;gap:10px">
              <div style="background:#f1f5f9;border-radius:6px;padding:6px;height:fit-content;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
              </div>
              <div>
                <div style="font-size:12px;font-weight:700;color:#475569">
                  <?= $lang==='id'?'Surat Izin dari Pihak Terkait':'Permission Letter from Relevant Party' ?>
                  <span style="background:#f1f5f9;color:#64748b;font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;margin-left:4px"><?= $lang==='id'?'OPSIONAL':'OPTIONAL' ?></span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:3px;line-height:1.5">
                  <?= $lang==='id'
                    ? 'Jika penelitian membutuhkan izin dari instansi atau lokasi tertentu. Format: PDF/JPG/PNG.'
                    : 'If research requires permission from a specific institution or location. Format: PDF/JPG/PNG.' ?>
                </div>
              </div>
            </div>

          </div><!-- /grid -->

          <!-- Tombol Tutup -->
          <div style="text-align:right;margin-top:14px">
            <button type="button" onclick="togglePrep()"
                    style="font-size:12px;font-weight:600;color:#1d4ed8;background:none;border:none;
                           cursor:pointer;display:inline-flex;align-items:center;gap:5px;padding:0">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
              <?= $lang==='id'?'Tutup panduan ini':'Collapse this guide' ?>
            </button>
          </div>

        </div><!-- /prep-body -->
      </div><!-- /prep-panel -->

      <?php if ($error): ?>
        <div class="alert alert-danger">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <?= $error ?>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="ecForm">

      <!-- ── Data Penelitian ── -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <span class="card-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
            <?= $lang==='id'?'Data Penelitian':'Research Data' ?>
          </span>
        </div>
        <div class="card-body">

          <!-- Nama Pemohon | No HP -->
          <div class="fg2">
            <div class="form-group">
              <label class="form-label"><?= $lang==='id'?'Nama Lengkap Pemohon':'Applicant Full Name' ?></label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['nama']) ?>" disabled
                     style="background:#f8fafc;color:var(--text-secondary)">
            </div>
            <div class="form-group">
              <label class="form-label"><?= $lang==='id'?'No. Tlp / WhatsApp':'Phone / WhatsApp' ?></label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($no_hp_val) ?>" disabled
                     style="background:#f8fafc;color:var(--text-secondary)">
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                <?= $lang==='id'?'Dari profil akun.':'From your account profile.' ?>
                <a href="<?= BASE_URL ?>/profil.php" style="color:var(--primary)"><?= $lang==='id'?'Perbarui':'Update' ?></a>
              </div>
            </div>
          </div>

          <!-- Judul Penelitian -->
          <div class="form-group">
            <label class="form-label">
              <?= $lang==='id'?'Judul Penelitian':'Research Title' ?> <span class="required">*</span>
            </label>
            <textarea name="judul" class="form-control" rows="3" required
              placeholder="<?= $lang==='id'?'Masukkan judul lengkap penelitian Anda...':'Enter the full title of your research...' ?>"><?= htmlspecialchars($post['judul']??'') ?></textarea>
          </div>

          <!-- Nama Jurnal -->
          <div class="form-group">
            <label class="form-label">
              <?= $lang==='id'?'Nama Jurnal Target':'Target Journal' ?>
              <span style="font-size:11px;color:var(--text-muted);font-weight:400"> — <?= $lang==='id'?'opsional':'optional' ?></span>
            </label>
            <input type="text" name="nama_jurnal" class="form-control"
                   placeholder="<?= $lang==='id'?'Contoh: PLOS ONE, BMC Research Notes...':'E.g.: PLOS ONE, BMC Research Notes...' ?>"
                   value="<?= htmlspecialchars($post['nama_jurnal']??'') ?>">
          </div>

          <!-- Ketua Peneliti | Anggota Tim -->
          <div class="fg2">
            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Ketua Peneliti (PI)':'Principal Investigator' ?> <span class="required">*</span>
              </label>
              <input type="text" name="ketua_peneliti" class="form-control" required
                     placeholder="<?= $lang==='id'?'Nama lengkap ketua peneliti':'Full name of the principal investigator' ?>"
                     value="<?= htmlspecialchars($post['ketua_peneliti']??$_SESSION['nama']) ?>">
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                <?= $lang==='id'?'Bisa berbeda dengan pemohon jika Anda adalah anggota.':'May differ from the applicant if you are a team member.' ?>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Anggota Tim Peneliti':'Research Team Members' ?>
                <span style="font-size:11px;color:var(--text-muted);font-weight:400"> — <?= $lang==='id'?'opsional':'optional' ?></span>
              </label>
              <input type="text" name="anggota_tim" class="form-control"
                     placeholder="<?= $lang==='id'?'Nama anggota, pisahkan dengan koma':'Member names, comma-separated' ?>"
                     value="<?= htmlspecialchars($post['anggota_tim']??'') ?>">
            </div>
          </div>

          <!-- Melibatkan Manusia | Melibatkan Hewan -->
          <div class="fg2">
            <!-- Manusia -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <?= $lang==='id'?'Melibatkan Subjek Manusia?':'Involves Human Subjects?' ?> <span class="required">*</span>
              </label>
              <div style="font-size:11px;color:var(--text-muted);margin:5px 0 8px">
                <?= $lang==='id'?'Jika Ya, wajib lampirkan Informed Consent.':'If Yes, Informed Consent is required.' ?>
              </div>
              <div class="yn-group">
                <label class="yn-label">
                  <input type="radio" name="melibatkan_manusia" value="ya"
                         <?= ($post['melibatkan_manusia']??'')==='ya'?'checked':'' ?>
                         onchange="togglePersetujuan(this.value)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  <?= $lang==='id'?'Ya':'Yes' ?>
                </label>
                <label class="yn-label">
                  <input type="radio" name="melibatkan_manusia" value="tidak"
                         <?= ($post['melibatkan_manusia']??'tidak')==='tidak'?'checked':'' ?>
                         onchange="togglePersetujuan(this.value)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <?= $lang==='id'?'Tidak':'No' ?>
                </label>
              </div>
            </div>

            <!-- Hewan -->
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><path d="M10 5.172C10 3.782 8.423 2.679 6.5 3c-2.823.47-4.113 6.006-4 7 .08.703 1.725 1.722 3.656 1 1.261-.472 1.96-1.45 2.344-2.5"/><path d="M14.267 5.172c0-1.39 1.577-2.493 3.5-2.172 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.96-1.45-2.344-2.5"/><path d="M8 14v.5"/><path d="M16 14v.5"/><path d="M11.25 16.25h1.5L12 17l-.75-.75z"/><path d="M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444c0-1.061-.162-2.2-.493-3.309m-9.243-6.082A8.801 8.801 0 0 1 12 5c.78 0 1.5.108 2.161.306"/></svg>
                <?= $lang==='id'?'Melibatkan Subjek Hewan?':'Involves Animal Subjects?' ?> <span class="required">*</span>
              </label>
              <div style="font-size:11px;color:var(--text-muted);margin:5px 0 8px"><?= $lang==='id'?'Hewan sebagai subjek percobaan penelitian.':'Animals used as research subjects.' ?></div>
              <div class="yn-group">
                <label class="yn-label">
                  <input type="radio" name="melibatkan_hewan" value="ya"
                         <?= ($post['melibatkan_hewan']??'')==='ya'?'checked':'' ?>>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  <?= $lang==='id'?'Ya':'Yes' ?>
                </label>
                <label class="yn-label">
                  <input type="radio" name="melibatkan_hewan" value="tidak"
                         <?= ($post['melibatkan_hewan']??'tidak')==='tidak'?'checked':'' ?>>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <?= $lang==='id'?'Tidak':'No' ?>
                </label>
              </div>
            </div>
          </div>

          <!-- Informed Consent (conditional) -->
          <div class="cond-block <?= ($post['melibatkan_manusia']??'')==='ya'?'visible':'hidden' ?>" id="block-persetujuan"
               style="margin-top:12px">
            <div class="form-group" style="padding:13px 15px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;margin-bottom:0">
              <label class="form-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-1px;margin-right:4px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <?= $lang==='id'?'Scan Surat Persetujuan Subjek (Informed Consent)':'Research Subject Consent Letter Scan (Informed Consent)' ?>
                <span style="font-size:11px;color:#b45309;font-weight:400"> — <?= $lang==='id'?'wajib':'required' ?></span>
              </label>
              <?php if ($link_persetujuan): ?>
              <a href="<?= htmlspecialchars($link_persetujuan) ?>" target="_blank" class="template-link">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= $lang==='id'?'Unduh format Informed Consent':'Download Informed Consent template' ?>
              </a>
              <?php else: ?>
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;font-style:italic">
                <?= $lang==='id'?'(Link template belum tersedia — hubungi admin LPPM)':'(Template link not yet available — contact LPPM admin)' ?>
              </div>
              <?php endif; ?>
              <div class="upload-compact" onclick="document.getElementById('inp-persetujuan').click()">
                <input type="file" id="inp-persetujuan" name="file_persetujuan_subjek"
                       accept=".pdf,.jpg,.jpeg,.png" onchange="showChosen(this,'chosen-persetujuan')">
                <div class="uc-label">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  <span><?= $lang==='id'?'Klik untuk upload scan / foto':'Click to upload scan / photo' ?></span>
                </div>
                <div class="uc-sub">PDF, JPG, PNG · <?= $lang==='id'?'Maks 20 MB':'Max 20 MB' ?></div>
              </div>
              <div id="chosen-persetujuan" class="file-chosen" style="display:none">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="fc-name"></span><span class="fc-size"></span>
                <button type="button" onclick="clearChosen('inp-persetujuan','chosen-persetujuan')"
                        style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ── Dokumen Persyaratan ── -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <span class="card-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
            <?= $lang==='id'?'Dokumen Persyaratan':'Required Documents' ?>
          </span>
        </div>
        <div class="card-body">

          <?php
          // Helper: render upload area compact untuk doc section
          // (inline, reusable)
          ?>

          <!-- ── WAJIB ─────────────────────────────────────────── -->
          <div class="doc-section-hd req">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $lang==='id'?'Dokumen Wajib':'Required Documents' ?>
          </div>

          <!-- Permohonan | Pernyataan (2-col) -->
          <div class="fg2" style="margin-bottom:10px">

            <!-- 1. Surat Permohonan -->
            <div class="doc-item req">
              <label class="form-label" style="font-size:12px">
                <?= $lang==='id'?'Scan Surat Permohonan':'Application Letter Scan' ?> <span class="required">*</span>
              </label>
              <?php if ($link_permohonan): ?>
              <a href="<?= htmlspecialchars($link_permohonan) ?>" target="_blank" class="template-link">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= $lang==='id'?'Unduh template':'Download template' ?>
              </a>
              <?php else: ?>
              <div style="font-size:10px;color:var(--text-muted);margin-bottom:6px;font-style:italic"><?= $lang==='id'?'(Template belum tersedia)':'(Template not yet available)' ?></div>
              <?php endif; ?>
              <div class="upload-compact" onclick="document.getElementById('inp-permohonan').click()">
                <input type="file" id="inp-permohonan" name="file_surat_permohonan"
                       accept=".pdf,.jpg,.jpeg,.png" onchange="showChosen(this,'chosen-permohonan')">
                <div class="uc-label">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  <span><?= $lang==='id'?'Klik untuk upload':'Click to upload' ?></span>
                </div>
                <div class="uc-sub">PDF, JPG, PNG · Maks 20 MB</div>
              </div>
              <div id="chosen-permohonan" class="file-chosen" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="fc-name"></span><span class="fc-size"></span>
                <button type="button" onclick="clearChosen('inp-permohonan','chosen-permohonan')"
                        style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>

            <!-- 2. Surat Pernyataan Bermeterai -->
            <div class="doc-item req">
              <label class="form-label" style="font-size:12px">
                <?= $lang==='id'?'Surat Pernyataan Bermeterai Rp10.000':'Stamped Declaration Letter (Rp10,000)' ?> <span class="required">*</span>
              </label>
              <?php if ($link_pernyataan): ?>
              <a href="<?= htmlspecialchars($link_pernyataan) ?>" target="_blank" class="template-link">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?= $lang==='id'?'Unduh template':'Download template' ?>
              </a>
              <?php else: ?>
              <div style="font-size:10px;color:var(--text-muted);margin-bottom:6px;font-style:italic"><?= $lang==='id'?'(Template belum tersedia)':'(Template not yet available)' ?></div>
              <?php endif; ?>
              <div class="upload-compact" onclick="document.getElementById('inp-pernyataan').click()">
                <input type="file" id="inp-pernyataan" name="file_surat_pernyataan"
                       accept=".pdf,.jpg,.jpeg,.png" onchange="showChosen(this,'chosen-pernyataan')">
                <div class="uc-label">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  <span><?= $lang==='id'?'Klik untuk upload':'Click to upload' ?></span>
                </div>
                <div class="uc-sub">PDF, JPG, PNG · Maks 20 MB</div>
              </div>
              <div id="chosen-pernyataan" class="file-chosen" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="fc-name"></span><span class="fc-size"></span>
                <button type="button" onclick="clearChosen('inp-pernyataan','chosen-pernyataan')"
                        style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>

          </div><!-- /fg2 wajib baris 1 -->

          <!-- 3. Laporan / Artikel (wajib, full-width) -->
          <div class="doc-item req" style="margin-bottom:14px">
            <label class="form-label" style="font-size:12px">
              <?= $lang==='id'?'Laporan Penelitian / Naskah Artikel':'Research Report / Article Manuscript' ?> <span class="required">*</span>
              <span style="font-size:10px;color:#2563eb;font-weight:400;margin-left:4px">
                <?= $lang==='id'?'(jika sudah meneliti)':'(if research is completed)' ?>
              </span>
            </label>
            <div class="upload-compact" onclick="document.getElementById('inp-laporan').click()">
              <input type="file" id="inp-laporan" name="file_laporan"
                     accept=".pdf,.doc,.docx" onchange="showChosen(this,'chosen-laporan')">
              <div class="uc-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                <span><?= $lang==='id'?'Klik untuk upload laporan / artikel (PDF, DOC, DOCX)':'Click to upload report / article (PDF, DOC, DOCX)' ?></span>
              </div>
              <div class="uc-sub">PDF, DOC, DOCX · Maks 20 MB</div>
            </div>
            <div id="chosen-laporan" class="file-chosen" style="display:none">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span class="fc-name"></span><span class="fc-size"></span>
              <button type="button" onclick="clearChosen('inp-laporan','chosen-laporan')"
                      style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
          </div>

          <!-- ── OPSIONAL ────────────────────────────────────────── -->
          <div class="doc-section-hd opt">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $lang==='id'?'Dokumen Opsional':'Optional Documents' ?>
          </div>

          <!-- Proposal | Surat Izin (2-col) -->
          <div class="fg2">

            <!-- 4. Proposal Penelitian -->
            <div class="doc-item opt">
              <label class="form-label" style="font-size:12px">
                <?= $lang==='id'?'Proposal Penelitian':'Research Proposal' ?>
              </label>
              <div style="font-size:10px;color:var(--text-muted);margin-bottom:7px;line-height:1.5">
                <?= $lang==='id'?'Jika penelitian baru akan dimulai dan laporan belum tersedia.':'If research is yet to begin and no report is available.' ?>
              </div>
              <div class="upload-compact" onclick="document.getElementById('inp-proposal').click()">
                <input type="file" id="inp-proposal" name="file_proposal"
                       accept=".pdf,.doc,.docx" onchange="showChosen(this,'chosen-proposal')">
                <div class="uc-label">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  <span><?= $lang==='id'?'Klik untuk upload':'Click to upload' ?></span>
                </div>
                <div class="uc-sub">PDF, DOC, DOCX · Maks 20 MB</div>
              </div>
              <div id="chosen-proposal" class="file-chosen" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="fc-name"></span><span class="fc-size"></span>
                <button type="button" onclick="clearChosen('inp-proposal','chosen-proposal')"
                        style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>

            <!-- 5. Surat Izin Pihak Terkait -->
            <div class="doc-item opt">
              <label class="form-label" style="font-size:12px">
                <?= $lang==='id'?'Surat Izin dari Pihak Terkait':'Permission Letter from Relevant Party' ?>
              </label>
              <div style="font-size:10px;color:var(--text-muted);margin-bottom:7px;line-height:1.5">
                <?= $lang==='id'?'Jika penelitian membutuhkan izin dari instansi / lokasi tertentu.':'If research requires permission from a specific institution or location.' ?>
              </div>
              <div class="upload-compact" onclick="document.getElementById('inp-surat-izin').click()">
                <input type="file" id="inp-surat-izin" name="file_surat_izin"
                       accept=".pdf,.jpg,.jpeg,.png" onchange="showChosen(this,'chosen-surat-izin')">
                <div class="uc-label">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  <span><?= $lang==='id'?'Klik untuk upload':'Click to upload' ?></span>
                </div>
                <div class="uc-sub">PDF, JPG, PNG · Maks 20 MB</div>
              </div>
              <div id="chosen-surat-izin" class="file-chosen" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="fc-name"></span><span class="fc-size"></span>
                <button type="button" onclick="clearChosen('inp-surat-izin','chosen-surat-izin')"
                        style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;margin-left:auto">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>

          </div><!-- /fg2 opsional -->

        </div>
      </div>

      <!-- ── Cek Mandiri: Similarity + AI ── -->
      <div style="margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span class="sec-label" style="margin:0">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?= $lang==='id'?'Hasil Cek Mandiri':'Self-Check Results' ?>
          </span>
          <span class="badge-opt"><?= $lang==='id'?'Opsional':'Optional' ?></span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.6">
          <?= $lang==='id'
            ? 'Jika sudah melakukan cek plagiasi dan/atau deteksi AI secara mandiri, lampirkan hasilnya sebagai bahan pembanding bagi tim reviewer.'
            : 'If you have already performed a self-check for plagiarism and/or AI detection, attach the results here as reference for the review team.' ?>
        </div>
        <div class="sc-grid">

          <!-- Kiri: Similarity -->
          <div class="sc-box sim">
            <div class="sc-head">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1e3a5f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <span style="color:#1e3a5f"><?= $lang==='id'?'Similarity Plagiasi':'Plagiarism Similarity' ?></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
              <div class="form-group" style="margin:0">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">
                  <?= $lang==='id'?'Skor (%)':'Score (%)' ?>
                </label>
                <div class="sc-score">
                  <input type="number" name="similarity_mandiri" class="form-control"
                         style="font-size:12px;height:34px;padding:4px 28px 4px 10px"
                         step="0.01" min="0" max="100" placeholder="0.00"
                         value="<?= htmlspecialchars($post['similarity_mandiri']??'') ?>">
                  <span class="unit">%</span>
                </div>
              </div>
              <div class="form-group" style="margin:0">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">
                  <?= $lang==='id'?'Platform':'Platform' ?>
                </label>
                <select name="platform_mandiri" class="form-control" style="font-size:12px;height:34px;padding:4px 8px">
                  <option value="">—</option>
                  <?php foreach (['Turnitin','iThenticate','PlagScan','Grammarly Plagiarism','Duplichecker','Plagiarism Checker X','Unicheck','Lainnya / Other'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($post['platform_mandiri']??'')===$p?'selected':'' ?>><?= $p ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <label class="sc-upload" onclick="document.getElementById('inp-sim').click()">
              <input type="file" id="inp-sim" name="file_cek_mandiri"
                     accept=".pdf,.jpg,.jpeg,.png" onchange="showSC(this,'sc-sim')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#1e3a5f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
              <span><?= $lang==='id'?'Upload bukti (PDF/JPG/PNG)':'Upload proof (PDF/JPG/PNG)' ?></span>
            </label>
            <div id="sc-sim" class="sc-chosen" style="display:none">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span class="scf-name"></span>
              <button type="button" onclick="clearSC('inp-sim','sc-sim')"
                style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;flex-shrink:0;margin-left:auto">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
          </div>

          <!-- Kanan: AI Detection -->
          <div class="sc-box ai">
            <div class="sc-head">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#5b21b6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="6" height="6" rx="1"/><path d="M9 3H7a4 4 0 0 0-4 4v2M9 21H7a4 4 0 0 1-4-4v-2M15 3h2a4 4 0 0 1 4 4v2M15 21h2a4 4 0 0 0 4-4v-2"/><line x1="9" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="15" y2="12"/><line x1="12" y1="9" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="15"/></svg>
              <span style="color:#5b21b6"><?= $lang==='id'?'Deteksi AI':'AI Detection' ?></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
              <div class="form-group" style="margin:0">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">
                  <?= $lang==='id'?'Skor (%)':'Score (%)' ?>
                </label>
                <div class="sc-score">
                  <input type="number" name="ai_mandiri" class="form-control"
                         style="font-size:12px;height:34px;padding:4px 28px 4px 10px"
                         step="0.01" min="0" max="100" placeholder="0.00"
                         value="<?= htmlspecialchars($post['ai_mandiri']??'') ?>">
                  <span class="unit">%</span>
                </div>
              </div>
              <div class="form-group" style="margin:0">
                <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px">
                  <?= $lang==='id'?'Platform':'Platform' ?>
                </label>
                <select name="platform_ai_mandiri" class="form-control" style="font-size:12px;height:34px;padding:4px 8px">
                  <option value="">—</option>
                  <?php foreach (['Turnitin AI Detection','GPTZero','Copyleaks AI Detector','Winston AI','Originality.ai','ZeroGPT','Quillbot AI Detector','Lainnya / Other'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($post['platform_ai_mandiri']??'')===$p?'selected':'' ?>><?= $p ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <label class="sc-upload" onclick="document.getElementById('inp-ai').click()">
              <input type="file" id="inp-ai" name="file_ai_mandiri"
                     accept=".pdf,.jpg,.jpeg,.png" onchange="showSC(this,'sc-ai')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
              <span><?= $lang==='id'?'Upload bukti (PDF/JPG/PNG)':'Upload proof (PDF/JPG/PNG)' ?></span>
            </label>
            <div id="sc-ai" class="sc-chosen" style="display:none">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span class="scf-name"></span>
              <button type="button" onclick="clearSC('inp-ai','sc-ai')"
                style="background:none;border:none;cursor:pointer;color:#94a3b8;padding:0;flex-shrink:0;margin-left:auto">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
          </div>

        </div>
      </div>
      <!-- /Cek Mandiri -->

      <!-- Tombol aksi -->
      <div style="display:flex;gap:12px;align-items:center">
        <button type="submit" class="btn btn-primary btn-lg">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:6px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          <?= $lang==='id'?'Kirim Permohonan':'Submit Application' ?>
        </button>
        <a href="../../dashboard.php" class="btn btn-outline">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:4px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          <?= $lang==='id'?'Kembali':'Back' ?>
        </a>
      </div>

      </form>

      <!-- Panduan -->
      <div class="card" style="margin-top:20px">
        <div class="card-header">
          <span class="card-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:5px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $lang==='id'?'Panduan Pengajuan':'Submission Guide' ?>
          </span>
        </div>
        <div class="card-body">
          <ol style="padding-left:18px;line-height:2.2;font-size:14px;color:#475569">
            <?php if ($lang==='id'): ?>
            <li>Unduh dan isi <strong>Format Surat Permohonan</strong> lalu scan/foto, kemudian upload di kolom yang tersedia.</li>
            <li>Unduh dan isi <strong>Surat Pernyataan Bermeterai Rp10.000</strong>, tanda tangan di atas meterai, scan, lalu upload.</li>
            <li>Jika penelitian <strong>melibatkan subjek manusia</strong>, unduh format <strong>Informed Consent</strong>, isi dan scan, lalu upload.</li>
            <li>Upload <strong>draft artikel atau laporan penelitian</strong> (PDF/DOC) yang akan diajukan ke jurnal.</li>
            <li>Pastikan <strong>Ketua Peneliti</strong> diisi sesuai dengan yang tertera pada surat permohonan.</li>
            <li>Komite Etik LPPM akan mereview dokumen dan mengirimkan pemberitahuan via email.</li>
            <li>Jika disetujui, <strong>Surat Ethical Clearance</strong> resmi akan dapat diunduh melalui halaman beranda.</li>
            <?php else: ?>
            <li>Download and fill the <strong>Application Letter template</strong>, then scan/photograph and upload it.</li>
            <li>Download and fill the <strong>Stamped Declaration Letter (Rp10,000 stamp)</strong>, sign, scan, and upload.</li>
            <li>If the research <strong>involves human subjects</strong>, download the <strong>Informed Consent</strong> template, fill and scan it, then upload.</li>
            <li>Upload the <strong>draft article or research report</strong> (PDF/DOC) to be submitted to the journal.</li>
            <li>Ensure the <strong>Principal Investigator</strong> matches the name on the application letter.</li>
            <li>The LPPM Ethics Committee will review the documents and notify you via email.</li>
            <li>If approved, the official <strong>Ethical Clearance letter</strong> will be available for download on your dashboard.</li>
            <?php endif; ?>
          </ol>
        </div>
      </div>

      <?php endif; // profil_kurang / penerimaan_tutup / form ?>

    </div>
  </div>
</div>

<script>
// ── Panel persiapan dokumen (toggle) ──
(function () {
  const body    = document.getElementById('prep-body');
  const chevron = document.getElementById('prep-chevron');
  // Buka secara default; simpan state di sessionStorage agar tidak reset tiap klik
  const stored = sessionStorage.getItem('ec_prep_open');
  if (stored === '0') { body.style.display = 'none'; chevron.style.transform = 'rotate(-90deg)'; }
})();
function togglePrep() {
  const body    = document.getElementById('prep-body');
  const chevron = document.getElementById('prep-chevron');
  const isOpen  = body.style.display !== 'none';
  body.style.display    = isOpen ? 'none' : 'block';
  chevron.style.transform = isOpen ? 'rotate(-90deg)' : 'rotate(0deg)';
  sessionStorage.setItem('ec_prep_open', isOpen ? '0' : '1');
}

function showChosen(input, chosenId) {
  if (!input.files || !input.files[0]) return;
  const f  = input.files[0];
  const el = document.getElementById(chosenId);
  el.querySelector('.fc-name').textContent = f.name;
  el.querySelector('.fc-size').textContent = (f.size/1048576).toFixed(2) + ' MB';
  el.style.display = 'flex';
  const wrap = input.closest('.upload-compact');
  if (wrap) wrap.style.borderColor = 'var(--primary)';
}
function clearChosen(inputId, chosenId) {
  document.getElementById(inputId).value = '';
  const el = document.getElementById(chosenId);
  el.style.display = 'none';
  el.querySelector('.fc-name').textContent = '';
  el.querySelector('.fc-size').textContent = '';
  const wrap = document.getElementById(inputId).closest('.upload-compact');
  if (wrap) wrap.style.borderColor = '';
}
function togglePersetujuan(val) {
  const block = document.getElementById('block-persetujuan');
  if (val === 'ya') {
    block.classList.remove('hidden');
    block.classList.add('visible');
  } else {
    block.classList.remove('visible');
    block.classList.add('hidden');
    clearChosen('inp-persetujuan','chosen-persetujuan');
  }
}
function showSC(input, chosenId) {
  if (!input.files || !input.files[0]) return;
  const f  = input.files[0];
  const el = document.getElementById(chosenId);
  el.querySelector('.scf-name').textContent = f.name + ' (' + (f.size/1048576).toFixed(2) + ' MB)';
  el.style.display = 'flex';
}
function clearSC(inputId, chosenId) {
  document.getElementById(inputId).value = '';
  const el = document.getElementById(chosenId);
  el.querySelector('.scf-name').textContent = '';
  el.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function () {
  const checked = document.querySelector('input[name="melibatkan_manusia"]:checked');
  if (checked) togglePersetujuan(checked.value);
});
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
