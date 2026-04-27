<?php
require_once '../includes/config.php';
require_once '../includes/penerimaan.php';
requireLogin('admin');

$lang    = $_COOKIE['lang'] ?? 'id';
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Simpan Rubrik Penilaian Reviewer ─────────────────────────
    if (($_POST['action'] ?? '') === 'save_rubrik') {
        $rb_kriteria = $_POST['rb_kriteria'] ?? [];
        $rb_bobot    = $_POST['rb_bobot']    ?? [];
        $rb_skor_max = $_POST['rb_skor_max'] ?? [];

        $rubrik = [];
        $total_bobot = 0;
        for ($i = 0; $i < count($rb_kriteria); $i++) {
            $nama = trim($rb_kriteria[$i] ?? '');
            $b    = max(1, min(100, (int)($rb_bobot[$i] ?? 0)));
            $s    = max(2, min(10,  (int)($rb_skor_max[$i] ?? 5)));
            if ($nama !== '') {
                $rubrik[]     = ['kriteria' => $nama, 'bobot' => $b, 'skor_max' => $s];
                $total_bobot += $b;
            }
        }
        if (count($rubrik) < 1 || count($rubrik) > 6) {
            $error = $lang==='id' ? 'Rubrik harus memiliki 1–6 kriteria.' : 'Rubric must have 1–6 criteria.';
        } elseif ($total_bobot !== 100) {
            $error = $lang==='id'
                ? "Total bobot harus tepat 100%. Saat ini: {$total_bobot}%."
                : "Total weight must equal exactly 100%. Current: {$total_bobot}%.";
        } else {
            $json = json_encode($rubrik, JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO pengaturan (kunci, nilai) VALUES ('reviewer_rubrik', ?)
                           ON DUPLICATE KEY UPDATE nilai=?")->execute([$json, $json]);
            $success = $lang==='id' ? 'Rubrik penilaian berhasil disimpan.' : 'Assessment rubric saved successfully.';
        }
        goto done_post;
    }

    // ── Reset nomor surat ─────────────────────────────────────────
    if (($_POST['action'] ?? '') === 'reset_counter') {
        $tbl_target = $_POST['tbl_target'] ?? '';
        $konfirmasi = trim($_POST['konfirmasi_reset'] ?? '');
        $allowed_tbl = ['surat_plagiasi', 'surat_publikasi'];

        if (!in_array($tbl_target, $allowed_tbl)) {
            $error = 'Tabel tidak valid.';
        } elseif (strtoupper($konfirmasi) !== 'RESET') {
            $error = $lang==='id' ? 'Konfirmasi tidak sesuai. Ketik RESET untuk melanjutkan.' : 'Confirmation mismatch. Type RESET to proceed.';
        } else {
            $tahun_now = (int)date('Y');
            // Set counter ke 0 — nomor surat berikutnya akan menjadi 001
            $pdo->prepare("
                INSERT INTO surat_counter (tabel, tahun, counter, reset_at)
                VALUES (?, ?, 0, NOW())
                ON DUPLICATE KEY UPDATE counter = 0, reset_at = NOW()
            ")->execute([$tbl_target, $tahun_now]);

            require_once '../includes/logger.php';
            $label_tbl = $tbl_target === 'surat_plagiasi' ? 'Plagiasi' : 'Publikasi';
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_reset_counter',
                "Reset nomor surat {$label_tbl} tahun {$tahun_now}");

            $success = $lang==='id'
                ? "Nomor surat {$label_tbl} berhasil direset. Surat berikutnya akan bernomor 001."
                : "Letter number for {$label_tbl} has been reset. Next letter will be numbered 001.";
        }
        // Jangan lanjut ke handler lain
        goto done_post;
    }

    // Toggle penerimaan dari pengaturan
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_penerimaan') {
        $j       = clean($_POST['jenis'] ?? '');
        $current = getPenerimaan($pdo, $j);
        setPenerimaan($pdo, $j, $current === 'buka' ? 'tutup' : 'buka');
        $success = $lang==='id' ? 'Status penerimaan berhasil diperbarui.' : 'Submission status updated.';
    } else {
        $keys = ['nama_institusi','nama_lppm','alamat_institusi','nama_ketua_lppm','nip_ketua_lppm',
                 'batas_similarity','batas_ai','prefix_surat_plagiasi','prefix_surat_publikasi',
                 'prefix_surat_ec','ec_nama_penandatangan','ec_nip_penandatangan',
                 'ec_jabatan_penandatangan','email_lppm','max_upload_mb',
                 'ec_link_surat_permohonan','ec_link_surat_pernyataan','ec_link_persetujuan_subjek'];
        try {
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $val = clean($_POST[$k]);
                    $pdo->prepare("UPDATE pengaturan SET nilai=? WHERE kunci=?")->execute([$val, $k]);
                }
            }
            // Ganti password admin jika diisi
            if (!empty($_POST['pw_baru'])) {
                if ($_POST['pw_baru'] !== $_POST['pw_konfirm']) {
                    $error = $lang==='id' ? 'Konfirmasi password tidak cocok.' : 'Password confirmation does not match.';
                } elseif (strlen($_POST['pw_baru']) < 8) {
                    $error = $lang==='id' ? 'Password minimal 8 karakter.' : 'Password must be at least 8 characters.';
                } else {
                    $hash = password_hash($_POST['pw_baru'], PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $_SESSION['user_id']]);
                }
            }
            if (!$error) $success = $lang==='id' ? 'Pengaturan berhasil disimpan.' : 'Settings saved successfully.';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
    done_post:;
}

// Ambil semua pengaturan
$rows = $pdo->query("SELECT kunci, nilai FROM pengaturan")->fetchAll();
$cfg  = [];
foreach ($rows as $r) $cfg[$r['kunci']] = $r['nilai'];

// Rubrik reviewer
$rubrik_raw = $cfg['reviewer_rubrik'] ?? null;
$rubrik_cfg = [];
if ($rubrik_raw) {
    $dec = json_decode($rubrik_raw, true);
    if (is_array($dec) && count($dec)) $rubrik_cfg = $dec;
}
if (empty($rubrik_cfg)) {
    $rubrik_cfg = [
        ['kriteria'=>'Perumusan Masalah',             'bobot'=>20, 'skor_max'=>5],
        ['kriteria'=>'Manfaat Hasil Penelitian',       'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Tinjauan Pustaka',               'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Landasan Teori',                 'bobot'=>20, 'skor_max'=>5],
        ['kriteria'=>'Metode Penelitian',              'bobot'=>15, 'skor_max'=>5],
        ['kriteria'=>'Output dan Outcome Penelitian',  'bobot'=>15, 'skor_max'=>5],
    ];
}

// Counter nomor surat tahun ini
$tahun_ini = (int)date('Y');
$counters = [];
$crows = $pdo->prepare("SELECT tabel, counter, reset_at FROM surat_counter WHERE tahun = ?");
$crows->execute([$tahun_ini]);
foreach ($crows->fetchAll() as $cr) $counters[$cr['tabel']] = $cr;
$ctr_pl = $counters['surat_plagiasi']  ?? ['counter'=>0,'reset_at'=>null];
$ctr_pb = $counters['surat_publikasi'] ?? ['counter'=>0,'reset_at'=>null];

// Data pending untuk manajemen penerimaan
$jenis_list = [
    ['plagiasi',  'Surat Bebas Plagiasi',           'Plagiarism Certificate',
     (int)$pdo->query("SELECT COUNT(*) FROM skripsi WHERE status='menunggu'")->fetchColumn()],
    ['publikasi', 'Surat Rekomendasi Publikasi',     'Publication Recommendation Letter',
     (int)$pdo->query("SELECT COUNT(*) FROM publikasi WHERE status='menunggu'")->fetchColumn()],
    ['ec',        'Ethical Clearance',               'Ethical Clearance',
     (int)$pdo->query("SELECT COUNT(*) FROM ethical_clearance WHERE status='menunggu'")->fetchColumn()],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Pengaturan':'Settings' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── Accordion ── */
.acc-wrap { display:flex; flex-direction:column; gap:10px; }

.acc-item {
  background:#fff;
  border:1.5px solid #e2e8f0;
  border-radius:12px;
  overflow:hidden;
  transition:box-shadow .2s;
}
.acc-item.open { box-shadow:0 2px 12px rgba(0,0,0,.07); border-color:#c7d7ed; }

.acc-hd {
  display:flex; align-items:center; gap:12px;
  padding:14px 18px; cursor:pointer;
  user-select:none; background:#fff;
  transition:background .15s;
}
.acc-hd:hover { background:#f8fafc; }

.acc-icon {
  width:34px; height:34px; border-radius:9px;
  display:flex; align-items:center; justify-content:center;
  flex-shrink:0;
  background:#eff6ff;
}
.acc-icon svg { color:#2563eb; }

.acc-meta { flex:1; min-width:0; }
.acc-title {
  font-size:14px; font-weight:700; color:#1e293b; line-height:1.2;
}
.acc-summary {
  font-size:12px; color:#94a3b8; margin-top:2px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.acc-item.open .acc-summary { display:none; }

.acc-chevron {
  flex-shrink:0; transition:transform .25s;
  color:#94a3b8;
}
.acc-item.open .acc-chevron { transform:rotate(180deg); }

.acc-bd {
  display:none;
  padding:0 18px 18px;
  border-top:1.5px solid #f1f5f9;
}
.acc-item.open .acc-bd { display:block; }

/* Save bar */
.save-bar {
  position:sticky; bottom:0; z-index:20;
  background:rgba(255,255,255,.95);
  backdrop-filter:blur(6px);
  border-top:1.5px solid #e2e8f0;
  padding:12px 0; margin-top:24px;
  display:flex; align-items:center; gap:12px;
}

/* Penerimaan rows */
.penerimaan-row {
  display:flex; align-items:center; gap:12px; flex-wrap:wrap;
  padding:11px 14px; border-radius:9px; margin-bottom:8px;
}

/* Two-col grid inside accordion */
.acc-fg2 {
  display:grid; grid-template-columns:1fr 1fr; gap:0 18px;
}
@media(max-width:640px){ .acc-fg2{ grid-template-columns:1fr; } }

.acc-item.danger .acc-icon { background:#fff1f2; }
.acc-item.danger .acc-icon svg { color:#dc2626; }
.acc-item.danger.open { border-color:#fca5a5; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('settings') ?> <?= $lang==='id'?'Pengaturan Sistem':'System Settings' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Konfigurasi informasi institusi & sistem':'Configure institution information & system' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($success): ?><div class="alert alert-success"><?= ic('check-circle') ?> <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= ic('x-circle') ?> <?= $error ?></div><?php endif; ?>

      <form method="POST">
      <div class="acc-wrap">

        <!-- ① Informasi Institusi -->
        <div class="acc-item" id="acc-institusi">
          <div class="acc-hd" onclick="toggleAcc('acc-institusi')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Informasi Institusi':'Institution Information' ?></div>
              <div class="acc-summary"><?= htmlspecialchars(($cfg['nama_institusi']??'')?: ($lang==='id'?'Belum diatur':'Not set')) ?></div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px" class="acc-fg2">
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Nama Lengkap Institusi':'Institution Full Name' ?></label>
                <input type="text" name="nama_institusi" class="form-control" value="<?= htmlspecialchars($cfg['nama_institusi']??'') ?>">
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Nama LPPM':'LPPM Name' ?></label>
                <input type="text" name="nama_lppm" class="form-control" value="<?= htmlspecialchars($cfg['nama_lppm']??'') ?>">
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Alamat Kampus':'Campus Address' ?></label>
                <textarea name="alamat_institusi" class="form-control" rows="2"><?= htmlspecialchars($cfg['alamat_institusi']??'') ?></textarea>
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Email LPPM':'LPPM Email' ?></label>
                <input type="email" name="email_lppm" class="form-control" value="<?= htmlspecialchars($cfg['email_lppm']??'') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ② Penandatangan Surat (Plagiasi & Publikasi) -->
        <div class="acc-item" id="acc-ttd">
          <div class="acc-hd" onclick="toggleAcc('acc-ttd')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Penandatangan Surat':'Letter Signatory' ?></div>
              <div class="acc-summary"><?= htmlspecialchars(($cfg['nama_ketua_lppm']??'')?: ($lang==='id'?'Belum diatur':'Not set')) ?></div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px" class="acc-fg2">
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Nama Ketua/Sekretaris LPPM':'LPPM Chair/Secretary Name' ?></label>
                <input type="text" name="nama_ketua_lppm" class="form-control" value="<?= htmlspecialchars($cfg['nama_ketua_lppm']??'') ?>">
                <div class="form-hint"><?= $lang==='id'?'Muncul di surat bebas plagiasi & rekomendasi publikasi':'Appears on plagiarism & publication letters' ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">NIP</label>
                <input type="text" name="nip_ketua_lppm" class="form-control" value="<?= htmlspecialchars($cfg['nip_ketua_lppm']??'') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ③ Nomor & Prefix Surat -->
        <div class="acc-item" id="acc-nomor">
          <div class="acc-hd" onclick="toggleAcc('acc-nomor')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                <rect x="9" y="3" width="6" height="4" rx="2"/>
                <path d="M9 12h6M9 16h4"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Nomor & Prefix Surat':'Letter Number Prefixes' ?></div>
              <div class="acc-summary"><?= htmlspecialchars($cfg['prefix_surat_plagiasi']??'LPPM-IAKN-PL') ?> · <?= htmlspecialchars($cfg['prefix_surat_publikasi']??'LPPM-IAKN-PB') ?> · <?= htmlspecialchars($cfg['prefix_surat_ec']??'LPPM/IAKN-T/LoEA') ?></div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px">

              <!-- Info format -->
              <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:12px;color:#0369a1;line-height:1.7">
                <?= ic('info','style="width:13px;height:13px;vertical-align:-1px"') ?>
                <?= $lang==='id'
                  ? 'Format nomor surat: <strong>001/[PREFIX]/[BULAN-ROMAWI]/[TAHUN]</strong>. Nomor urut direset setiap tahun secara otomatis, atau dapat direset manual kapan saja.'
                  : 'Letter number format: <strong>001/[PREFIX]/[ROMAN-MONTH]/[YEAR]</strong>. Sequence resets each year automatically, or can be manually reset anytime.' ?>
              </div>

              <div class="acc-fg2">

                <!-- Plagiasi prefix + reset -->
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Prefix Surat Plagiasi':'Plagiarism Letter Prefix' ?></label>
                  <input type="text" name="prefix_surat_plagiasi" class="form-control" value="<?= htmlspecialchars($cfg['prefix_surat_plagiasi']??'LPPM-IAKN-PL') ?>">
                  <div class="form-hint"><?= $lang==='id'?'Contoh: LPPM/IAKNT/SK-PL':'e.g. LPPM/IAKNT/SK-PL' ?></div>
                  <!-- Counter + Reset -->
                  <div style="display:flex;align-items:center;gap:8px;margin-top:8px;padding:8px 10px;background:#f8fafc;border-radius:7px;border:1px solid #e2e8f0">
                    <div style="flex:1">
                      <div style="font-size:11px;color:#94a3b8"><?= $lang==='id'?'Nomor terakhir diterbitkan':'Last issued number' ?> <?= $tahun_ini ?></div>
                      <div style="font-size:15px;font-weight:700;color:#1e293b;font-family:monospace">
                        <?= str_pad($ctr_pl['counter'], 3, '0', STR_PAD_LEFT) ?>
                        <?php if ($ctr_pl['reset_at']): ?>
                          <span style="font-size:10px;font-weight:400;color:#94a3b8;font-family:inherit;margin-left:4px">
                            <?= $lang==='id'?'direset':'reset' ?> <?= date('d/m/Y', strtotime($ctr_pl['reset_at'])) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <button type="button" class="btn btn-sm"
                            style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;flex-shrink:0"
                            onclick="openResetModal('surat_plagiasi','<?= htmlspecialchars($cfg['prefix_surat_plagiasi']??'LPPM-IAKN-PL') ?>',<?= (int)$ctr_pl['counter'] ?>)">
                      <?= ic('rotate-ccw','style="width:13px;height:13px"') ?>
                      Reset
                    </button>
                  </div>
                </div>

                <!-- Publikasi prefix + reset -->
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Prefix Surat Publikasi':'Publication Letter Prefix' ?></label>
                  <input type="text" name="prefix_surat_publikasi" class="form-control" value="<?= htmlspecialchars($cfg['prefix_surat_publikasi']??'LPPM-IAKN-PB') ?>">
                  <div class="form-hint"><?= $lang==='id'?'Contoh: LPPM/IAKNT/SK-PB':'e.g. LPPM/IAKNT/SK-PB' ?></div>
                  <!-- Counter + Reset -->
                  <div style="display:flex;align-items:center;gap:8px;margin-top:8px;padding:8px 10px;background:#f8fafc;border-radius:7px;border:1px solid #e2e8f0">
                    <div style="flex:1">
                      <div style="font-size:11px;color:#94a3b8"><?= $lang==='id'?'Nomor terakhir diterbitkan':'Last issued number' ?> <?= $tahun_ini ?></div>
                      <div style="font-size:15px;font-weight:700;color:#1e293b;font-family:monospace">
                        <?= str_pad($ctr_pb['counter'], 3, '0', STR_PAD_LEFT) ?>
                        <?php if ($ctr_pb['reset_at']): ?>
                          <span style="font-size:10px;font-weight:400;color:#94a3b8;font-family:inherit;margin-left:4px">
                            <?= $lang==='id'?'direset':'reset' ?> <?= date('d/m/Y', strtotime($ctr_pb['reset_at'])) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <button type="button" class="btn btn-sm"
                            style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;flex-shrink:0"
                            onclick="openResetModal('surat_publikasi','<?= htmlspecialchars($cfg['prefix_surat_publikasi']??'LPPM-IAKN-PB') ?>',<?= (int)$ctr_pb['counter'] ?>)">
                      <?= ic('rotate-ccw','style="width:13px;height:13px"') ?>
                      Reset
                    </button>
                  </div>
                </div>

                <!-- EC prefix (manual, no counter) -->
                <div class="form-group" style="grid-column:1/-1">
                  <label class="form-label"><?= $lang==='id'?'Prefix Surat Ethical Clearance':'EC Letter Prefix' ?></label>
                  <input type="text" name="prefix_surat_ec" class="form-control" value="<?= htmlspecialchars($cfg['prefix_surat_ec']??'LPPM/IAKN-T/LoEA') ?>">
                  <div class="form-hint"><?= $lang==='id'?'Nomor EC diisi manual oleh admin saat memproses. Contoh: 001/LPPM/IAKN-T/LoEA/IV/2026':'EC number is entered manually by admin when processing. e.g. 001/LPPM/IAKN-T/LoEA/IV/2026' ?></div>
                </div>

              </div>
            </div>
          </div>
        </div>

        <!-- ④ Ethical Clearance (penandatangan + template) -->
        <div class="acc-item" id="acc-ec">
          <div class="acc-hd" onclick="toggleAcc('acc-ec')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title">Ethical Clearance</div>
              <div class="acc-summary"><?= $lang==='id'?'Penandatangan surat EC & link template dokumen':'EC letter signatory & document template links' ?></div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px">
              <p style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
                <?= $lang==='id'?'Penandatangan Surat EC':'EC Letter Signatory' ?>
              </p>
              <div class="acc-fg2">
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Nama Penandatangan':'Signatory Name' ?></label>
                  <input type="text" name="ec_nama_penandatangan" class="form-control"
                         value="<?= htmlspecialchars($cfg['ec_nama_penandatangan']??'') ?>"
                         placeholder="Contoh: Serdianus, M.Pd.">
                </div>
                <div class="form-group">
                  <label class="form-label">NIP</label>
                  <input type="text" name="ec_nip_penandatangan" class="form-control"
                         value="<?= htmlspecialchars($cfg['ec_nip_penandatangan']??'') ?>"
                         placeholder="Contoh: 198608012025211058">
                </div>
                <div class="form-group">
                  <label class="form-label"><?= $lang==='id'?'Jabatan':'Title/Position' ?></label>
                  <input type="text" name="ec_jabatan_penandatangan" class="form-control"
                         value="<?= htmlspecialchars($cfg['ec_jabatan_penandatangan']??'Head of Research Unit LPPM') ?>"
                         placeholder="Head of Research Unit LPPM">
                </div>
              </div>

              <div style="height:1px;background:#f1f5f9;margin:14px 0"></div>

              <p style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">
                <?= $lang==='id'?'Link Template Dokumen':'Document Template Links' ?>
              </p>
              <p style="font-size:12px;color:#94a3b8;margin-bottom:12px;line-height:1.6">
                <?= $lang==='id'
                  ? 'Tautan Google Drive / OneDrive untuk template surat yang harus diunduh pemohon.'
                  : 'Google Drive / OneDrive links to letter templates applicants must download.' ?>
              </p>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Template Surat Permohonan':'Application Letter Template' ?></label>
                <input type="url" name="ec_link_surat_permohonan" class="form-control"
                       placeholder="https://drive.google.com/..."
                       value="<?= htmlspecialchars($cfg['ec_link_surat_permohonan']??'') ?>">
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Template Surat Pernyataan Bermeterai':'Stamped Declaration Letter Template' ?></label>
                <input type="url" name="ec_link_surat_pernyataan" class="form-control"
                       placeholder="https://drive.google.com/..."
                       value="<?= htmlspecialchars($cfg['ec_link_surat_pernyataan']??'') ?>">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label"><?= $lang==='id'?'Template Informed Consent':'Informed Consent Template' ?></label>
                <input type="url" name="ec_link_persetujuan_subjek" class="form-control"
                       placeholder="https://drive.google.com/..."
                       value="<?= htmlspecialchars($cfg['ec_link_persetujuan_subjek']??'') ?>">
                <div class="form-hint"><?= $lang==='id'?'Muncul jika penelitian melibatkan subjek manusia':'Shown when research involves human subjects' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ⑤ Ambang Batas Sistem -->
        <div class="acc-item" id="acc-batas">
          <div class="acc-hd" onclick="toggleAcc('acc-batas')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Ambang Batas Sistem':'System Thresholds' ?></div>
              <div class="acc-summary"><?= $lang==='id'?'Similarity':'Similarity' ?> ≤ <?= htmlspecialchars($cfg['batas_similarity']??'20') ?>% · AI ≤ <?= htmlspecialchars($cfg['batas_ai']??'20') ?>% · <?= $lang==='id'?'Upload':'Upload' ?> <?= htmlspecialchars($cfg['max_upload_mb']??'20') ?> MB</div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px" class="acc-fg2">
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Batas Similarity Turnitin (%)':'Max Turnitin Similarity (%)' ?></label>
                <input type="number" name="batas_similarity" class="form-control"
                       min="1" max="100" value="<?= htmlspecialchars($cfg['batas_similarity']??'20') ?>"
                       style="max-width:120px">
                <div class="form-hint"><?= $lang==='id'?'Skor ≤ nilai ini otomatis lulus':'Score ≤ this value auto-passes' ?></div>
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Batas Deteksi AI (%)':'Max AI Detection Score (%)' ?></label>
                <input type="number" name="batas_ai" class="form-control"
                       min="1" max="100" value="<?= htmlspecialchars($cfg['batas_ai']??'20') ?>"
                       style="max-width:120px">
                <div class="form-hint"><?= $lang==='id'?'Skor AI ≤ nilai ini dianggap lolos':'AI score ≤ this value passes' ?></div>
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Ukuran Maksimal Upload (MB)':'Max Upload Size (MB)' ?></label>
                <input type="number" name="max_upload_mb" class="form-control"
                       min="1" max="50" value="<?= htmlspecialchars($cfg['max_upload_mb']??'20') ?>"
                       style="max-width:120px">
              </div>
            </div>
          </div>
        </div>

        <!-- ⑥ Manajemen Penerimaan (form terpisah, di luar main form) -->
      </div><!-- /acc-wrap inside form -->

      <!-- Save bar -->
      <div class="save-bar">
        <button type="submit" class="btn btn-primary">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="display:inline-block;vertical-align:-2px;margin-right:5px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <?= $lang==='id'?'Simpan Semua Pengaturan':'Save All Settings' ?>
        </button>
        <span style="font-size:12px;color:#94a3b8"><?= $lang==='id'?'Perubahan hanya disimpan saat tombol ini ditekan.':'Changes are only saved when this button is pressed.' ?></span>
      </div>
      </form>

      <!-- ⑥ Manajemen Penerimaan -->
      <div class="acc-wrap" style="margin-top:10px">
        <div class="acc-item" id="acc-penerimaan">
          <div class="acc-hd" onclick="toggleAcc('acc-penerimaan')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Manajemen Penerimaan Pengajuan':'Submission Intake Management' ?></div>
              <div class="acc-summary">
                <?php
                $open_count = 0;
                foreach ($jenis_list as [$j]) { if (getPenerimaan($pdo,$j)==='buka') $open_count++; }
                echo $lang==='id' ? "$open_count dari 3 layanan terbuka" : "$open_count of 3 services open";
                ?>
              </div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="padding-top:14px">
              <p style="font-size:12px;color:#94a3b8;margin-bottom:14px;line-height:1.6">
                <?= $lang==='id'
                  ? 'Tutup penerimaan sementara jika antrian melebihi kapasitas. Penerimaan buka kembali otomatis jika semua antrian habis, atau dapat dibuka manual kapan saja.'
                  : 'Temporarily close intake if the queue exceeds team capacity. Intake auto-reopens when all pending items are cleared, or can be reopened manually at any time.' ?>
              </p>
              <?php foreach ($jenis_list as [$j, $lblID, $lblEN, $pend]):
                $st    = getPenerimaan($pdo, $j);
                $buka  = $st === 'buka';
                $label = $lang === 'id' ? $lblID : $lblEN;
                $bg    = $buka ? '#f0fdf4' : '#fff1f2';
                $bd    = $buka ? '#86efac' : '#fca5a5';
                $dot   = $buka ? '#16a34a' : '#dc2626';
                $stTxt = $buka ? ($lang==='id'?'Terbuka':'Open') : ($lang==='id'?'Ditutup':'Closed');
                $btnBg = $buka ? '#dc2626' : '#16a34a';
                $btnTxt= $buka ? ($lang==='id'?'Tutup':'Close') : ($lang==='id'?'Buka Kembali':'Reopen');
                $warn  = ($pend >= PENERIMAAN_BATAS_TUTUP);
              ?>
              <div class="penerimaan-row" style="background:<?= $bg ?>;border:1.5px solid <?= $bd ?>">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= $dot ?>;flex-shrink:0"></span>
                <span style="font-size:13px;font-weight:600;color:#1e293b;flex:1"><?= $label ?></span>
                <?php if ($warn): ?>
                <span style="font-size:11px;background:#fef3c7;color:#b45309;border-radius:5px;padding:2px 7px;font-weight:600">
                  <?= ic('alert') ?> <?= $pend ?> <?= $lang==='id'?'menunggu':'pending' ?>
                </span>
                <?php else: ?>
                <span style="font-size:12px;color:#64748b"><?= $lang==='id'?'Menunggu:':'Pending:' ?> <strong><?= $pend ?></strong></span>
                <?php endif; ?>
                <span style="font-size:12px;font-weight:700;color:<?= $dot ?>"><?= $stTxt ?></span>
                <form method="POST" style="margin:0">
                  <input type="hidden" name="action" value="toggle_penerimaan">
                  <input type="hidden" name="jenis"  value="<?= $j ?>">
                  <button type="submit" style="background:<?= $btnBg ?>;color:#fff;border:none;
                          border-radius:7px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer">
                    <?= $btnTxt ?>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- ⑦ Rubrik Penilaian Reviewer -->
        <div class="acc-item" id="acc-rubrik">
          <div class="acc-hd" onclick="toggleAcc('acc-rubrik')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Rubrik Penilaian Reviewer':'Reviewer Assessment Rubric' ?></div>
              <div class="acc-summary">
                <?= $lang==='id'
                    ? count($rubrik_cfg).' kriteria · total bobot '.array_sum(array_column($rubrik_cfg,'bobot')).'%'
                    : count($rubrik_cfg).' criteria · total weight '.array_sum(array_column($rubrik_cfg,'bobot')).'%' ?>
              </div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <div style="margin-bottom:12px;font-size:12px;color:var(--text-muted);background:#faf5ff;border:1px solid #ddd6fe;border-radius:8px;padding:9px 13px">
              <?= ic('alert','style="width:13px;height:13px;color:#7c3aed"') ?>
              <?= $lang==='id'
                ? 'Maksimal 6 kriteria. <strong>Total bobot harus tepat 100%</strong>. Skor maks: nilai tertinggi yang bisa dipilih reviewer (min 2, maks 10).'
                : 'Maximum 6 criteria. <strong>Total weight must equal exactly 100%</strong>. Max score: highest value a reviewer can select (min 2, max 10).' ?>
            </div>
            <form method="POST" id="rubrik-form">
              <input type="hidden" name="action" value="save_rubrik">
              <div style="overflow-x:auto;margin-bottom:14px">
                <table style="width:100%;border-collapse:collapse;font-size:13px" id="rubrik-tbl">
                  <thead>
                    <tr>
                      <th style="text-align:left;padding:8px 10px;background:var(--bg-field);border:1px solid var(--border);width:50%">
                        <?= $lang==='id'?'Nama Kriteria':'Criterion Name' ?>
                      </th>
                      <th style="text-align:center;padding:8px 10px;background:var(--bg-field);border:1px solid var(--border);width:18%">
                        <?= $lang==='id'?'Bobot (%)':'Weight (%)' ?>
                      </th>
                      <th style="text-align:center;padding:8px 10px;background:var(--bg-field);border:1px solid var(--border);width:18%">
                        <?= $lang==='id'?'Skor Maks':'Max Score' ?>
                      </th>
                      <th style="text-align:center;padding:8px 10px;background:var(--bg-field);border:1px solid var(--border);width:14%">
                        <?= $lang==='id'?'Aksi':'Action' ?>
                      </th>
                    </tr>
                  </thead>
                  <tbody id="rubrik-body">
                  <?php foreach ($rubrik_cfg as $ri => $rw): ?>
                  <tr class="rubrik-row">
                    <td style="padding:7px 8px;border:1px solid var(--border)">
                      <input type="text" name="rb_kriteria[]" class="form-control" style="font-size:12.5px;padding:6px 9px"
                             value="<?= htmlspecialchars($rw['kriteria']) ?>" required placeholder="<?= $lang==='id'?'Nama kriteria':'Criterion name' ?>">
                    </td>
                    <td style="padding:7px 8px;border:1px solid var(--border)">
                      <input type="number" name="rb_bobot[]" class="form-control rubrik-bobot" style="font-size:12.5px;padding:6px 9px;text-align:center"
                             value="<?= $rw['bobot'] ?>" min="1" max="100" required oninput="updateBobotTotal()">
                    </td>
                    <td style="padding:7px 8px;border:1px solid var(--border)">
                      <input type="number" name="rb_skor_max[]" class="form-control" style="font-size:12.5px;padding:6px 9px;text-align:center"
                             value="<?= $rw['skor_max'] ?>" min="2" max="10" required>
                    </td>
                    <td style="padding:7px 8px;border:1px solid var(--border);text-align:center">
                      <button type="button" onclick="delRubrikRow(this)"
                              style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:6px;padding:4px 9px;font-size:11.5px;cursor:pointer;font-weight:600">
                        <?= $lang==='id'?'Hapus':'Delete' ?>
                      </button>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr>
                      <td style="padding:8px 10px;border:1px solid var(--border);font-weight:700;font-size:12px">
                        TOTAL
                      </td>
                      <td style="padding:8px 10px;border:1px solid var(--border);text-align:center;font-weight:800;font-size:14px" id="bobot-total">
                        <?= array_sum(array_column($rubrik_cfg,'bobot')) ?>%
                      </td>
                      <td colspan="2" style="padding:8px 10px;border:1px solid var(--border)"></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <button type="button" onclick="addRubrikRow()" id="btn-add-rubrik"
                        style="font-size:12.5px;padding:7px 14px;background:#f5f3ff;border:1.5px solid #c4b5fd;color:#6d28d9;border-radius:8px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:5px">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <?= $lang==='id'?'Tambah Kriteria':'Add Criterion' ?>
                </button>
                <button type="submit" class="btn btn-primary" style="font-size:12.5px;padding:7px 18px">
                  <?= ic('check-circle','style="width:14px;height:14px"') ?>
                  <?= $lang==='id'?'Simpan Rubrik':'Save Rubric' ?>
                </button>
                <span id="bobot-warn" style="font-size:11.5px;color:#dc2626;font-weight:600;display:none">
                  ⚠ <?= $lang==='id'?'Total bobot harus 100%':'Total weight must be 100%' ?>
                </span>
              </div>
            </form>
          </div>
        </div>

        <!-- ⑧ Keamanan Akun -->
        <div class="acc-item danger" id="acc-password">
          <div class="acc-hd" onclick="toggleAcc('acc-password')">
            <div class="acc-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </div>
            <div class="acc-meta">
              <div class="acc-title"><?= $lang==='id'?'Keamanan Akun':'Account Security' ?></div>
              <div class="acc-summary"><?= $lang==='id'?'Ganti password login admin':'Change admin login password' ?></div>
            </div>
            <svg class="acc-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
          </div>
          <div class="acc-bd">
            <form method="POST">
            <div style="padding-top:14px;max-width:440px">
              <div class="alert alert-warning" style="font-size:12px;margin-bottom:14px">
                <?= ic('alert') ?> <?= $lang==='id'?'Kosongkan jika tidak ingin mengganti password.':'Leave blank to keep your current password.' ?>
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Password Baru':'New Password' ?></label>
                <input type="password" name="pw_baru" class="form-control" placeholder="<?= $lang==='id'?'Min. 8 karakter':'Min. 8 characters' ?>">
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Konfirmasi Password':'Confirm Password' ?></label>
                <input type="password" name="pw_konfirm" class="form-control" placeholder="<?= $lang==='id'?'Ulangi password baru':'Repeat new password' ?>">
              </div>
              <button type="submit" class="btn btn-danger" style="margin-top:4px">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" style="display:inline-block;vertical-align:-2px;margin-right:4px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <?= $lang==='id'?'Simpan Password Baru':'Save New Password' ?>
              </button>
            </div>
            </form>
          </div>
        </div>

      </div><!-- /acc-wrap penerimaan+password -->

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->

<!-- ── Modal Reset Nomor Surat ─────────────────────────────────── -->
<div id="reset-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1400;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:28px 28px 24px;width:min(440px,calc(100vw - 32px));box-shadow:0 12px 40px rgba(0,0,0,.2)">

    <!-- Ikon + Judul -->
    <div style="text-align:center;margin-bottom:20px">
      <div style="width:52px;height:52px;border-radius:50%;background:#fff1f2;border:2px solid #fecdd3;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M10 11v6M14 11v6"/>
          <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
        </svg>
      </div>
      <div style="font-size:16px;font-weight:800;color:#1e293b" id="rm-title">Reset Nomor Surat</div>
      <div style="font-size:13px;color:#64748b;margin-top:4px" id="rm-subtitle"></div>
    </div>

    <!-- Info saat ini -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:2px"><?= $lang==='id'?'Nomor terakhir diterbitkan':'Last number issued' ?></div>
          <div style="font-size:22px;font-weight:800;color:#1e293b;font-family:monospace" id="rm-current">005</div>
        </div>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        <div style="text-align:right">
          <div style="font-size:11px;color:#94a3b8;margin-bottom:2px"><?= $lang==='id'?'Setelah reset':'After reset' ?></div>
          <div style="font-size:22px;font-weight:800;color:#16a34a;font-family:monospace">001</div>
        </div>
      </div>
    </div>

    <!-- Peringatan -->
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 13px;margin-bottom:18px;font-size:12px;color:#92400e;line-height:1.7">
      <strong><?= $lang==='id'?'Perhatian:':'Warning:' ?></strong>
      <?= $lang==='id'
        ? 'Surat yang <em>sudah diterbitkan</em> tidak akan berubah. Nomor surat <em>berikutnya</em> akan dimulai dari <strong>001</strong>. Jika format bulan/tahun sama, pastikan tidak ada duplikasi nomor.'
        : 'Already issued letters will not change. The <em>next</em> letter number will start from <strong>001</strong>. If month/year format is the same, make sure there are no duplicate numbers.' ?>
    </div>

    <!-- Input konfirmasi -->
    <div style="margin-bottom:18px">
      <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:6px">
        <?= $lang==='id'?'Ketik <strong>RESET</strong> untuk melanjutkan:':'Type <strong>RESET</strong> to proceed:' ?>
      </label>
      <input type="text" id="rm-konfirmasi"
             autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false"
             style="width:100%;padding:9px 12px;border:2px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:monospace;letter-spacing:.05em;transition:border-color .15s;box-sizing:border-box"
             placeholder="Ketik RESET di sini"
             oninput="checkResetInput()">
    </div>

    <!-- Tombol -->
    <div style="display:flex;gap:10px">
      <button type="button" onclick="closeResetModal()"
              style="flex:1;padding:10px;border:1.5px solid #e2e8f0;background:#fff;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;color:#475569">
        <?= $lang==='id'?'Batal':'Cancel' ?>
      </button>
      <form method="POST" id="rm-form" style="flex:1">
        <input type="hidden" name="action" value="reset_counter">
        <input type="hidden" name="tbl_target" id="rm-tbl">
        <input type="hidden" name="konfirmasi_reset" id="rm-hidden-conf">
        <button type="submit" id="rm-submit" disabled
                style="width:100%;padding:10px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;opacity:.4;transition:opacity .15s"
                onclick="document.getElementById('rm-hidden-conf').value=document.getElementById('rm-konfirmasi').value">
          <?= ic('rotate-ccw','style="width:14px;height:14px;vertical-align:-1px;margin-right:4px"') ?>
          <?= $lang==='id'?'Ya, Reset Nomor Surat':'Yes, Reset Letter Number' ?>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1]||'id';
  document.cookie='lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}

const ACC_KEY = 'lppm_acc_pengaturan';

function loadAccState() {
  try { return JSON.parse(sessionStorage.getItem(ACC_KEY)||'{}'); } catch(e){ return {}; }
}
function saveAccState(state) {
  try { sessionStorage.setItem(ACC_KEY, JSON.stringify(state)); } catch(e){}
}

function toggleAcc(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const wasOpen = el.classList.contains('open');
  el.classList.toggle('open', !wasOpen);
  const state = loadAccState();
  state[id] = !wasOpen;
  saveAccState(state);
}

// Restore state on load; default: first accordion open
(function(){
  const state = loadAccState();
  const allIds = ['acc-institusi','acc-ttd','acc-nomor','acc-ec','acc-batas','acc-penerimaan','acc-rubrik','acc-password'];
  let anyOpen = false;
  allIds.forEach(id => {
    if (state[id] === true) {
      const el = document.getElementById(id);
      if (el) { el.classList.add('open'); anyOpen = true; }
    }
  });
  // If nothing stored, open the first one
  if (!anyOpen) {
    const first = document.getElementById('acc-institusi');
    if (first) first.classList.add('open');
  }
})();

/* ── Reset Nomor Surat Modal ── */
function openResetModal(tbl, prefix, currentCounter) {
  document.getElementById('rm-tbl').value = tbl;
  document.getElementById('rm-title').textContent = 'Reset Nomor Surat: ' + prefix;
  document.getElementById('rm-subtitle').textContent = 'Tahun ' + new Date().getFullYear();
  document.getElementById('rm-current').textContent = String(currentCounter).padStart(3, '0');
  document.getElementById('rm-konfirmasi').value = '';
  const btn = document.getElementById('rm-submit');
  btn.disabled = true;
  btn.style.opacity = '0.4';
  document.getElementById('rm-konfirmasi').style.borderColor = '#e2e8f0';
  const overlay = document.getElementById('reset-overlay');
  overlay.style.display = 'flex';
  setTimeout(() => document.getElementById('rm-konfirmasi').focus(), 80);
}

function closeResetModal() {
  document.getElementById('reset-overlay').style.display = 'none';
  document.getElementById('rm-konfirmasi').value = '';
  document.getElementById('rm-konfirmasi').style.borderColor = '#e2e8f0';
}

function checkResetInput() {
  const val = document.getElementById('rm-konfirmasi').value;
  const btn = document.getElementById('rm-submit');
  const inp = document.getElementById('rm-konfirmasi');
  const match = val.toUpperCase() === 'RESET';
  btn.disabled = !match;
  btn.style.opacity = match ? '1' : '0.4';
  inp.style.borderColor = val === '' ? '#e2e8f0' : (match ? '#16a34a' : '#ef4444');
}

document.getElementById('reset-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeResetModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeResetModal();
});

// ── Rubrik Penilaian Reviewer ──────────────────────────────────
function updateBobotTotal() {
  const inputs = document.querySelectorAll('.rubrik-bobot');
  let total = 0;
  inputs.forEach(i => { total += parseInt(i.value||0); });
  const el = document.getElementById('bobot-total');
  const warn = document.getElementById('bobot-warn');
  if (el) {
    el.textContent = total + '%';
    el.style.color = total === 100 ? '#16a34a' : '#dc2626';
  }
  if (warn) warn.style.display = total !== 100 ? 'inline' : 'none';
}

function addRubrikRow() {
  const tbody = document.getElementById('rubrik-body');
  const rows  = tbody.querySelectorAll('.rubrik-row');
  if (rows.length >= 6) { alert('<?= $lang==='id'?'Maksimal 6 kriteria.':'Maximum 6 criteria.' ?>'); return; }
  const tr = document.createElement('tr');
  tr.className = 'rubrik-row';
  tr.innerHTML = `
    <td style="padding:7px 8px;border:1px solid var(--border)">
      <input type="text" name="rb_kriteria[]" class="form-control" style="font-size:12.5px;padding:6px 9px"
             placeholder="<?= $lang==='id'?'Nama kriteria':'Criterion name' ?>" required>
    </td>
    <td style="padding:7px 8px;border:1px solid var(--border)">
      <input type="number" name="rb_bobot[]" class="form-control rubrik-bobot" style="font-size:12.5px;padding:6px 9px;text-align:center"
             value="10" min="1" max="100" required oninput="updateBobotTotal()">
    </td>
    <td style="padding:7px 8px;border:1px solid var(--border)">
      <input type="number" name="rb_skor_max[]" class="form-control" style="font-size:12.5px;padding:6px 9px;text-align:center"
             value="5" min="2" max="10" required>
    </td>
    <td style="padding:7px 8px;border:1px solid var(--border);text-align:center">
      <button type="button" onclick="delRubrikRow(this)"
              style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:6px;padding:4px 9px;font-size:11.5px;cursor:pointer;font-weight:600">
        <?= $lang==='id'?'Hapus':'Delete' ?>
      </button>
    </td>`;
  tbody.appendChild(tr);
  updateBobotTotal();
  // Enforce show/hide add btn
  document.getElementById('btn-add-rubrik').style.display = rows.length + 1 >= 6 ? 'none' : 'inline-flex';
}

function delRubrikRow(btn) {
  const tbody = document.getElementById('rubrik-body');
  if (tbody.querySelectorAll('.rubrik-row').length <= 1) {
    alert('<?= $lang==='id'?'Minimal 1 kriteria harus ada.':'At least 1 criterion is required.' ?>'); return;
  }
  btn.closest('.rubrik-row').remove();
  updateBobotTotal();
  document.getElementById('btn-add-rubrik').style.display = 'inline-flex';
}

// Validate bobot on submit
document.getElementById('rubrik-form')?.addEventListener('submit', function(e) {
  const inputs = document.querySelectorAll('.rubrik-bobot');
  let total = 0;
  inputs.forEach(i => { total += parseInt(i.value||0); });
  if (total !== 100) {
    e.preventDefault();
    alert('<?= $lang==='id'?'Total bobot harus tepat 100%. Saat ini: ':'Total weight must be exactly 100%. Current: ' ?>' + total + '%');
  }
});
</script>
</body>
</html>
