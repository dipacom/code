<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';

$flash = '';
$flash_type = 'success';

// ══════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Simpan pengaturan umum ────────────────────────────────
    if ($action === 'save_umum') {
        $fields = [
            'penelitian_tahun'             => clean($_POST['tahun'] ?? ''),
            'penelitian_nomor_pengumuman'  => clean($_POST['nomor_pengumuman'] ?? ''),
            'penelitian_tgl_pengumuman'    => clean($_POST['tgl_pengumuman'] ?? ''),
            'penelitian_tgl_buka'          => clean($_POST['tgl_buka'] ?? ''),
            'penelitian_deadline'          => clean($_POST['deadline'] ?? ''),
            'penelitian_min_mahasiswa'     => (int)($_POST['min_mahasiswa'] ?? 2),
            'monev_reminder_interval_bulan' => max(1, (int)($_POST['monev_reminder_interval_bulan'] ?? 3)),
        ];
        foreach ($fields as $kunci => $nilai) {
            $pdo->prepare("INSERT INTO pengaturan (kunci,nilai) VALUES (?,?)
                           ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)")
                ->execute([$kunci, $nilai]);
        }
        $flash = $id ? 'Pengaturan umum berhasil disimpan.' : 'General settings saved.';
    }

    // ── Helper: ambil nilai tim dari POST ─────────────────────
    $parseTim = function() {
        $max_d = trim($_POST['max_anggota_dosen'] ?? '');
        $max_m = trim($_POST['max_anggota_mahasiswa'] ?? '');
        return [
            'min_anggota_dosen'       => max(0, (int)($_POST['min_anggota_dosen'] ?? 0)),
            'max_anggota_dosen'       => ($max_d !== '' && (int)$max_d > 0) ? (int)$max_d : null,
            'anggota_dosen_wajib'     => (int)($_POST['anggota_dosen_wajib']     ?? 0) === 1 ? 1 : 0,
            'min_anggota_mahasiswa'   => max(0, (int)($_POST['min_anggota_mahasiswa'] ?? 0)),
            'max_anggota_mahasiswa'   => ($max_m !== '' && (int)$max_m > 0) ? (int)$max_m : null,
            'anggota_mahasiswa_wajib' => (int)($_POST['anggota_mahasiswa_wajib'] ?? 0) === 1 ? 1 : 0,
        ];
    };

    // ── Tambah skema ──────────────────────────────────────────
    if ($action === 'add_skema') {
        $kode  = strtolower(preg_replace('/[^a-z0-9_]/', '_', clean($_POST['kode'] ?? '')));
        $nama  = clean($_POST['nama'] ?? '');
        $tahun = (int)($_POST['tahun_skema'] ?? date('Y'));
        if ($kode && $nama) {
            $tim = $parseTim();
            try {
                $pdo->prepare("
                    INSERT INTO skema_penelitian
                      (kode, nama, target_publikasi, anggaran_total, anggaran_penelitian,
                       anggaran_publikasi, kuota, jabatan_min, batas_similarity, batas_ai,
                       min_anggota_dosen, max_anggota_dosen, anggota_dosen_wajib,
                       min_anggota_mahasiswa, max_anggota_mahasiswa, anggota_mahasiswa_wajib,
                       is_open, deskripsi, urutan, tahun,
                       batas_luaran_bulan, jenis_luaran_wajib)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $kode,
                    $nama,
                    clean($_POST['target_publikasi'] ?? ''),
                    (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_total']      ?? 0),
                    (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_penelitian'] ?? 0),
                    (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_publikasi']  ?? 0),
                    (int)($_POST['kuota'] ?? 10),
                    in_array($_POST['jabatan_min']??'',['asisten_ahli','lektor','lektor_kepala','guru_besar'])
                        ? $_POST['jabatan_min'] : 'asisten_ahli',
                    (float)str_replace(',', '.', $_POST['batas_similarity'] ?? 25),
                    (float)str_replace(',', '.', $_POST['batas_ai']         ?? 30),
                    $tim['min_anggota_dosen'],
                    $tim['max_anggota_dosen'],
                    $tim['anggota_dosen_wajib'],
                    $tim['min_anggota_mahasiswa'],
                    $tim['max_anggota_mahasiswa'],
                    $tim['anggota_mahasiswa_wajib'],
                    isset($_POST['is_open']) ? 1 : 0,
                    clean($_POST['deskripsi'] ?? ''),
                    (int)($_POST['urutan'] ?? 99),
                    $tahun,
                    (int)($_POST['batas_luaran_bulan'] ?? 24),
                    clean($_POST['jenis_luaran_wajib'] ?? '') ?: null,
                ]);
                $flash = $id ? 'Skema berhasil ditambahkan.' : 'Scheme added successfully.';
            } catch (\PDOException $e) {
                $flash = $id ? 'Kode skema sudah digunakan. Gunakan kode lain.' : 'Scheme code already in use.';
                $flash_type = 'error';
            }
        } else {
            $flash = $id ? 'Kode dan nama skema wajib diisi.' : 'Scheme code and name are required.';
            $flash_type = 'error';
        }
    }

    // ── Edit skema ────────────────────────────────────────────
    if ($action === 'edit_skema') {
        $sid = (int)($_POST['skema_id'] ?? 0);
        if ($sid) {
            $tim = $parseTim();
            $pdo->prepare("
                UPDATE skema_penelitian SET
                  nama=?, target_publikasi=?,
                  anggaran_total=?, anggaran_penelitian=?, anggaran_publikasi=?,
                  kuota=?, jabatan_min=?, batas_similarity=?, batas_ai=?,
                  min_anggota_dosen=?, max_anggota_dosen=?, anggota_dosen_wajib=?,
                  min_anggota_mahasiswa=?, max_anggota_mahasiswa=?, anggota_mahasiswa_wajib=?,
                  is_open=?, deskripsi=?, urutan=?, tahun=?,
                  batas_luaran_bulan=?, jenis_luaran_wajib=?
                WHERE id=?
            ")->execute([
                clean($_POST['nama'] ?? ''),
                clean($_POST['target_publikasi'] ?? ''),
                (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_total']      ?? 0),
                (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_penelitian'] ?? 0),
                (int)str_replace(['.', ',', ' '], '', $_POST['anggaran_publikasi']  ?? 0),
                (int)($_POST['kuota'] ?? 10),
                in_array($_POST['jabatan_min']??'',['asisten_ahli','lektor','lektor_kepala','guru_besar'])
                    ? $_POST['jabatan_min'] : 'asisten_ahli',
                (float)str_replace(',', '.', $_POST['batas_similarity'] ?? 25),
                (float)str_replace(',', '.', $_POST['batas_ai']         ?? 30),
                $tim['min_anggota_dosen'],
                $tim['max_anggota_dosen'],
                $tim['anggota_dosen_wajib'],
                $tim['min_anggota_mahasiswa'],
                $tim['max_anggota_mahasiswa'],
                $tim['anggota_mahasiswa_wajib'],
                isset($_POST['is_open']) ? 1 : 0,
                clean($_POST['deskripsi'] ?? ''),
                (int)($_POST['urutan'] ?? 99),
                (int)($_POST['tahun_skema'] ?? date('Y')),
                (int)($_POST['batas_luaran_bulan'] ?? 24),
                clean($_POST['jenis_luaran_wajib'] ?? '') ?: null,
                $sid,
            ]);
            $flash = $id ? 'Skema berhasil diperbarui.' : 'Scheme updated.';
        }
    }

    // ── Toggle buka/tutup skema ───────────────────────────────
    if ($action === 'toggle_skema') {
        $sid = (int)($_POST['skema_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("UPDATE skema_penelitian SET is_open = 1-is_open WHERE id=?")
                ->execute([$sid]);
            $flash = $id ? 'Status skema diperbarui.' : 'Scheme status updated.';
        }
    }

    // ── Hapus skema ───────────────────────────────────────────
    if ($action === 'delete_skema') {
        $sid = (int)($_POST['skema_id'] ?? 0);
        if ($sid) {
            // Cek apakah ada proposal dengan skema ini
            $kode = $pdo->query("SELECT kode FROM skema_penelitian WHERE id=$sid")->fetchColumn();
            $n = $pdo->query("SELECT COUNT(*) FROM usulan_penelitian WHERE skema='$kode'")->fetchColumn();
            if ($n > 0) {
                $flash = $id ? "Tidak dapat dihapus — terdapat $n proposal dengan skema ini." : "Cannot delete — $n proposals use this scheme.";
                $flash_type = 'error';
            } else {
                $pdo->prepare("DELETE FROM skema_penelitian WHERE id=?")->execute([$sid]);
                $flash = $id ? 'Skema berhasil dihapus.' : 'Scheme deleted.';
            }
        }
    }

    // ── Simpan pernyataan kesanggupan ─────────────────────────
    if ($action === 'save_pernyataan') {
        $poin_raw   = $_POST['poin'] ?? '';
        $poin_lines = array_values(array_filter(array_map('trim', explode("\n", $poin_raw))));
        $pdo->prepare("INSERT INTO pengaturan (kunci,nilai) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)")
            ->execute(['penelitian_pernyataan_poin', json_encode($poin_lines, JSON_UNESCAPED_UNICODE)]);
        $flash = $id ? 'Pernyataan kesanggupan berhasil disimpan.' : 'Declaration saved.';
    }

    // ── Upload template proposal ──────────────────────────────
    if ($action === 'upload_template') {
        $judul_tmpl = clean($_POST['judul_template'] ?? '');
        $file       = $_FILES['file_template'] ?? null;
        if ($judul_tmpl && $file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'doc', 'docx'])) {
                $dir = BASE_PATH . '/uploads/penelitian/templates/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'template_' . time() . '_' . preg_replace('/[^a-z0-9._-]/', '_', strtolower(basename($file['name'])));
                if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                    $existing_raw = getSetting($pdo, 'penelitian_template');
                    $existing     = ($existing_raw) ? (json_decode($existing_raw, true) ?: []) : [];
                    $existing[]   = ['judul' => $judul_tmpl, 'file' => 'uploads/penelitian/templates/' . $fname, 'uploaded_at' => date('Y-m-d')];
                    $pdo->prepare("INSERT INTO pengaturan (kunci,nilai) VALUES (?,?)
                                   ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)")
                        ->execute(['penelitian_template', json_encode($existing, JSON_UNESCAPED_UNICODE)]);
                    $flash = $id ? 'Template berhasil diupload.' : 'Template uploaded successfully.';
                } else {
                    $flash = $id ? 'Gagal menyimpan file.' : 'Failed to save file.'; $flash_type = 'error';
                }
            } else {
                $flash = $id ? 'Format file harus PDF, DOC, atau DOCX.' : 'File must be PDF, DOC, or DOCX.'; $flash_type = 'error';
            }
        } else {
            $flash = $id ? 'Judul dan file template wajib diisi.' : 'Title and file are required.'; $flash_type = 'error';
        }
    }

    // ── Hapus template ────────────────────────────────────────
    if ($action === 'delete_template') {
        $tidx = (int)($_POST['template_idx'] ?? -1);
        $existing_raw = getSetting($pdo, 'penelitian_template');
        $existing     = ($existing_raw) ? (json_decode($existing_raw, true) ?: []) : [];
        if (isset($existing[$tidx])) {
            $fpath = BASE_PATH . '/' . $existing[$tidx]['file'];
            if (file_exists($fpath)) unlink($fpath);
            array_splice($existing, $tidx, 1);
            $pdo->prepare("INSERT INTO pengaturan (kunci,nilai) VALUES (?,?)
                           ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)")
                ->execute(['penelitian_template', json_encode(array_values($existing), JSON_UNESCAPED_UNICODE)]);
            $flash = $id ? 'Template berhasil dihapus.' : 'Template deleted.';
        }
    }

    if (!in_array($action, ['edit_skema'])) {
        $_SESSION['flash'] = ['type' => $flash_type === 'error' ? 'warning' : 'success', 'msg' => $flash];
        redirect('/admin/penelitian_setting.php');
    }
}

// ── Load data ─────────────────────────────────────────────────
$settings = [];
$rows = $pdo->query("SELECT kunci,nilai FROM pengaturan WHERE kunci LIKE 'penelitian%'")->fetchAll();
foreach ($rows as $r) $settings[$r['kunci']] = $r['nilai'];

$tahun       = $settings['penelitian_tahun']            ?? date('Y');
$nomor_peng  = $settings['penelitian_nomor_pengumuman'] ?? '';
$tgl_peng    = $settings['penelitian_tgl_pengumuman']   ?? '';
$tgl_buka    = $settings['penelitian_tgl_buka']         ?? '';
$deadline    = $settings['penelitian_deadline']         ?? '';
$min_mhs     = $settings['penelitian_min_mahasiswa']    ?? '2';
$reminder_interval = (int)($settings['monev_reminder_interval_bulan'] ?? 3);

$skemas = $pdo->query(
    "SELECT * FROM skema_penelitian ORDER BY urutan, id"
)->fetchAll();

// Pernyataan poin
$pernyataan_raw  = getSetting($pdo, 'penelitian_pernyataan_poin');
$pernyataan_poin = [];
if ($pernyataan_raw) {
    $dec = json_decode($pernyataan_raw, true);
    if (is_array($dec)) $pernyataan_poin = $dec;
}
if (empty($pernyataan_poin)) {
    $pernyataan_poin = [
        'Proposal merupakan proposal baru (bukan lanjutan).',
        'Judul sudah terintegrasi dengan bidang keilmuan, pengajaran, dan PkM.',
        'Ketua dan anggota berasal dari homebase dan fakultas yang sama.',
        'Ketua memiliki akun Google Scholar dan SINTA berafiliasi IAKN Toraja.',
        'Ketua dan anggota telah memenuhi kewajiban luaran penelitian tahun 2023 ke belakang.',
        'Proposal dikirimkan ke lp2miaknt@gmail.com paling lambat 18 Mei 2026 pukul 16.00 WITA.',
        'Saya menyatakan bahwa semua informasi yang saya sampaikan adalah benar dan sesuai ketentuan, serta bersedia mematuhi seluruh aturan penelitian LPPM IAKN Toraja.',
    ];
}
$pernyataan_text = implode("\n", $pernyataan_poin);

// Template proposal
$template_raw  = getSetting($pdo, 'penelitian_template');
$template_list = [];
if ($template_raw) {
    $dec = json_decode($template_raw, true);
    if (is_array($dec)) $template_list = $dec;
}

// Edit mode: ambil data satu skema
$edit_skema = null;
if (isset($_GET['edit'])) {
    $edit_skema = $pdo->prepare("SELECT * FROM skema_penelitian WHERE id=?");
    $edit_skema->execute([(int)$_GET['edit']]);
    $edit_skema = $edit_skema->fetch();
}

$jabatan_opts = [
    'asisten_ahli'  => 'Asisten Ahli (AA)',
    'lektor'        => 'Lektor',
    'lektor_kepala' => 'Lektor Kepala',
    'guru_besar'    => 'Guru Besar / Profesor',
];

function rupiah(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Pengaturan Penelitian':'Research Settings' ?> — LPPM Admin</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
/* Section headers */
.cfg-sec {
  display:flex; align-items:center; gap:12px;
  margin:28px 0 16px; padding-bottom:10px;
  border-bottom:2px solid var(--border);
}
.cfg-sec-ico {
  width:38px; height:38px; border-radius:10px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.cfg-sec-ico svg { color:#fff; }
.cfg-sec-title { font-size:15px; font-weight:800; color:var(--text-primary); }
.cfg-sec-sub   { font-size:12px; color:var(--text-muted); }

/* Scheme cards — compact tapi tetap nyaman dibaca */
.skema-card {
  border:1.5px solid var(--border); border-radius:12px;
  background:var(--bg-card); margin-bottom:11px; overflow:hidden;
  transition:border-color .15s, box-shadow .15s;
}
.skema-card:hover { border-color:#cbd5e1; box-shadow:0 2px 12px rgba(0,0,0,.05); }
.skema-card.closed { opacity:.78; }
.skema-card-head {
  padding:12px 15px; display:flex; align-items:center;
  gap:11px; background:var(--bg-field);
  border-bottom:1px solid var(--border);
}
.skema-card-ico {
  width:36px; height:36px; border-radius:8px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.skema-card-ico svg { width:17px; height:17px; color:#fff; }
.skema-card-body { padding:12px 15px; }

/* Compact info strip */
.sk-strip {
  display:flex; flex-wrap:wrap; gap:7px 12px;
  align-items:center; font-size:12.5px; line-height:1.35;
}
.sk-strip-item {
  display:inline-flex; align-items:center; gap:6px;
  padding:5px 10px; border-radius:7px; background:#f8fafc;
  border:1px solid #e2e8f0;
}
.sk-strip-item svg { width:13px; height:13px; flex-shrink:0; }
.sk-strip-lbl { color:#64748b; font-size:11.5px; font-weight:600; }
.sk-strip-val { color:#1e293b; font-weight:700; }
.sk-strip-sep { width:1px; height:14px; background:#e2e8f0; }
.sk-meta {
  font-size:11.5px; color:var(--text-muted);
  display:inline-flex; align-items:center; gap:7px; flex-wrap:wrap;
}
.sk-meta code {
  background:#f1f5f9; color:#475569;
  padding:1px 7px; border-radius:4px; font-size:11px;
}
.sk-tgt {
  display:inline-block; padding:2px 8px; border-radius:5px;
  font-size:10.5px; font-weight:600;
}

/* Stat grid inside scheme card */
.skema-stat-grid {
  display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px;
}
@media(max-width:640px){ .skema-stat-grid { grid-template-columns:1fr 1fr; } }
.skema-stat {
  background:var(--bg-field); border:1px solid var(--border);
  border-radius:8px; padding:10px 12px;
}
.skema-stat-val { font-size:14px; font-weight:800; color:var(--text-primary); }
.skema-stat-lbl { font-size:10.5px; color:var(--text-muted); margin-top:2px; }

/* Form grid */
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
@media(max-width:640px){
  .form-grid-2, .form-grid-3 { grid-template-columns:1fr; }
}

/* Modal overlay */
.modal-overlay {
  position:fixed; inset:0; background:rgba(15,23,42,.6);
  z-index:900; display:none; align-items:center; justify-content:center;
  padding:16px; backdrop-filter:blur(4px);
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:var(--bg-card); border-radius:16px; width:100%; max-width:700px;
  max-height:92vh; overflow-y:auto;
  box-shadow:0 24px 60px rgba(0,0,0,.22);
  animation:slideUp .22s cubic-bezier(.22,1,.36,1);
}
@keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-head {
  padding:18px 20px 14px; border-bottom:1.5px solid var(--border);
  display:flex; align-items:center; gap:10px;
}
.modal-body { padding:20px; }
.modal-foot {
  padding:14px 20px; border-top:1.5px solid var(--border);
  display:flex; gap:10px; justify-content:flex-end;
}

/* Badge status */
.badge-open   { background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; }
.badge-closed { background:#fee2e2; color:#dc2626; border:1px solid #fecaca; }
.st-badge {
  display:inline-block; padding:3px 10px; border-radius:8px;
  font-size:11px; font-weight:700;
}

/* ── Admin scheme card info grid (2×2) ──────────────── */
.skema-info-grid {
  display:grid; grid-template-columns:1fr 1fr;
  border-top:1.5px solid var(--border);
}
@media(max-width:640px){
  .skema-info-grid { grid-template-columns:1fr; }
  .skema-info-grid > div { border-right:none !important; }
}
.sinfo-lbl {
  display:inline-flex; align-items:center; gap:5px;
  font-size:10.5px; font-weight:700;
  border-radius:5px; padding:3px 8px; border:1px solid; margin-bottom:9px;
}

/* ── Modal form section groups ──────────────────────── */
.msec {
  border:1.5px solid var(--border);
  border-radius:11px;
  overflow:hidden;
  margin-bottom:13px;
}
.msec-head {
  display:flex; align-items:center; gap:8px;
  padding:9px 14px;
  font-size:12px; font-weight:700;
  border-bottom:1.5px solid;
}
.msec-head svg { flex-shrink:0; }
.msec-body { padding:14px; }
/* inner spacing resets */
.msec-body .form-group { margin-bottom:12px; }
.msec-body .form-group:last-child { margin-bottom:0; }
.msec-body .form-grid-2 { margin-bottom:12px; }
.msec-body .form-grid-2:last-child,
.msec-body .form-grid-3:last-child { margin-bottom:0; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('settings') ?>
          <?= $id?'Pengaturan Usulan Penelitian':'Research Proposal Settings' ?>
          <span class="breadcrumb"><?= $id?'Konfigurasi skema & persyaratan':'Configure schemes & requirements' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($flash && !$edit_skema): ?>
      <div class="alert alert-<?= $flash_type==='error'?'danger':'success' ?>" style="margin-bottom:16px">
        <?= ic($flash_type==='error'?'alert':'check-circle') ?> <?= htmlspecialchars($flash) ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['flash'])): ?>
      <div class="alert alert-<?= $_SESSION['flash']['type']==='success'?'success':'danger' ?>" style="margin-bottom:16px">
        <?= ic($_SESSION['flash']['type']==='success'?'check-circle':'alert') ?>
        <?= htmlspecialchars($_SESSION['flash']['msg']) ?>
      </div>
      <?php unset($_SESSION['flash']); endif; ?>

      <!-- ══════════════════════════════════════════════
           BAGIAN 1 — PENGATURAN UMUM
      ══════════════════════════════════════════════ -->
      <div class="cfg-sec">
        <div class="cfg-sec-ico" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
        </div>
        <div>
          <div class="cfg-sec-title">1. <?= $id?'Pengaturan Umum':'General Settings' ?></div>
          <div class="cfg-sec-sub"><?= $id?'Informasi pengumuman, periode, dan persyaratan dasar':'Announcement info, period, and basic requirements' ?></div>
        </div>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="save_umum">
        <div class="card" style="padding:0;border-radius:12px;overflow:hidden">
          <div style="padding:18px 20px 6px">
            <div class="form-grid-2" style="margin-bottom:14px">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Nomor Pengumuman':'Announcement Number' ?></label>
                <input type="text" name="nomor_pengumuman" class="form-control"
                       value="<?= htmlspecialchars($nomor_peng) ?>"
                       placeholder="20/LPPM/IAKN-T/IV/2026">
                <div class="form-hint"><?= $id?'Tampil di hero banner halaman dosen':'Shown on dosen page hero banner' ?></div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Tahun Anggaran':'Budget Year' ?> <span class="required">*</span></label>
                <input type="number" name="tahun" class="form-control" min="2020" max="2099"
                       value="<?= htmlspecialchars($tahun) ?>" required>
                <div class="form-hint"><?= $id?'Digunakan untuk filter proposal':'Used to filter proposals' ?></div>
              </div>
            </div>
            <div class="form-grid-3" style="margin-bottom:14px">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Tanggal Pengumuman':'Announcement Date' ?></label>
                <input type="date" name="tgl_pengumuman" class="form-control"
                       value="<?= htmlspecialchars($tgl_peng) ?>">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Tanggal Pendaftaran Dibuka':'Registration Opens' ?></label>
                <input type="date" name="tgl_buka" class="form-control"
                       value="<?= htmlspecialchars($tgl_buka) ?>">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Batas Akhir Pengumpulan':'Submission Deadline' ?> <span class="required">*</span></label>
                <input type="datetime-local" name="deadline" class="form-control"
                       value="<?= htmlspecialchars(str_replace(' ', 'T', substr($deadline, 0, 16))) ?>" required>
                <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px">
                  <?= $id?'Sampai dengan jam & menit (WITA). Semua skema akan otomatis ditutup setelah waktu ini.':'Until hour & minute (WITA). All schemes will auto-close after this time.' ?>
                </div>
              </div>
            </div>
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:9px 13px;
                        font-size:12px;color:#713f12;margin-bottom:18px;display:flex;gap:8px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              <?= $id
                ? 'Ketentuan jumlah anggota diatur <strong>per skema</strong> di bagian "Kelola Skema" di bawah.'
                : 'Member count requirements are configured <strong>per scheme</strong> in the "Manage Schemes" section below.'
              ?>
            </div>

            <!-- ── Monev: interval pengingat luaran ── -->
            <div style="background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);border:1.5px solid #bbf7d0;
                        border-radius:10px;padding:14px 16px;margin-bottom:14px">
              <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px">
                <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#14532d,#16a34a);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <?= ic('bell','style="width:14px;height:14px;color:#fff"') ?>
                </div>
                <div style="flex:1">
                  <div style="font-size:12.5px;font-weight:700;color:#14532d">
                    <?= $id?'Pengingat Luaran Penelitian (Monev)':'Research Output Reminder (Monev)' ?>
                  </div>
                  <div style="font-size:10.5px;color:#15803d">
                    <?= $id?'Frekuensi pengingat ke peneliti hingga batas akhir luaran':'Reminder frequency until output deadline' ?>
                  </div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:160px 1fr;gap:14px;align-items:center">
                <div>
                  <label style="font-size:11.5px;font-weight:600;color:#065f46;display:block;margin-bottom:4px">
                    <?= $id?'Interval (bulan)':'Interval (months)' ?>
                  </label>
                  <input type="number" name="monev_reminder_interval_bulan" class="form-control"
                         min="1" max="12" step="1" value="<?= $reminder_interval ?>"
                         style="font-size:13px">
                </div>
                <div style="font-size:11.5px;color:#065f46;line-height:1.55">
                  <?= $id
                    ? 'Peneliti akan menerima pengingat (notifikasi bell + popup dashboard) setiap <strong>'.$reminder_interval.' bulan</strong> sejak batas laporan hingga batas luaran tercapai. Pengingat H-14 &amp; peringatan "lewat deadline" dikirim otomatis. Berhenti otomatis jika luaran wajib sudah diverifikasi LPPM.'
                    : 'Researchers receive reminders (bell + dashboard popup) every <strong>'.$reminder_interval.' months</strong> from report deadline until output deadline. H-14 alerts and overdue warnings are auto-sent. Stops when required output is verified.'
                  ?>
                </div>
              </div>
              <div style="margin-top:9px;padding:8px 11px;background:#fff;border:1px dashed #86efac;border-radius:7px;font-size:11px;color:#14532d">
                <?= $id?'Batas akhir luaran per skema diatur di bagian':'Output deadline per scheme is configured in' ?>
                <strong>"<?= $id?'Kelola Skema':'Manage Schemes' ?>"</strong>
                → <?= $id?'section "Monev — Batas Luaran Publikasi"':'"Monev — Publication Output Deadline" section' ?>.
              </div>
            </div>
          </div>
          <div style="padding:12px 20px 14px;background:var(--bg-field);border-top:1px solid var(--border);
                      display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" style="font-size:13px">
              <?= ic('check-circle') ?> <?= $id?'Simpan Pengaturan Umum':'Save General Settings' ?>
            </button>
          </div>
        </div>
      </form>

      <!-- ══════════════════════════════════════════════
           BAGIAN 2 — KELOLA SKEMA
      ══════════════════════════════════════════════ -->
      <div class="cfg-sec">
        <div class="cfg-sec-ico" style="background:linear-gradient(135deg,#4a1d96,#7c3aed)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
          </svg>
        </div>
        <div style="flex:1">
          <div class="cfg-sec-title">2. <?= $id?'Kelola Skema Penelitian':'Manage Research Schemes' ?></div>
          <div class="cfg-sec-sub"><?= $id?'Tambah, ubah, atau tutup skema per tahun anggaran':'Add, edit, or close schemes per budget year' ?></div>
        </div>
        <button type="button" class="btn btn-primary" style="font-size:12.5px"
                onclick="openAddModal()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <?= $id?'Tambah Skema':'Add Scheme' ?>
        </button>
      </div>

      <!-- Scheme cards -->
      <?php if (empty($skemas)): ?>
      <div style="background:var(--bg-card);border:1.5px dashed var(--border);border-radius:12px;
                  padding:40px 24px;text-align:center;color:var(--text-muted)">
        <?= $id?'Belum ada skema. Klik "Tambah Skema" untuk membuat skema pertama.':'No schemes yet. Click "Add Scheme" to create the first one.' ?>
      </div>
      <?php endif; ?>

      <?php foreach ($skemas as $sk):
        $colors = match($sk['kode']) {
            'global'   => ['linear-gradient(135deg,#1e3a8a,#3b82f6)', '#eff6ff', '#bfdbfe'],
            'nasional' => ['linear-gradient(135deg,#14532d,#16a34a)', '#f0fdf4', '#bbf7d0'],
            default    => ['linear-gradient(135deg,#4a1d96,#7c3aed)', '#faf5ff', '#ddd6fe'],
        };
      ?>
      <?php
      $d_min  = (int)$sk['min_anggota_dosen'];
      $d_max  = $sk['max_anggota_dosen'];
      $d_wjb  = (bool)$sk['anggota_dosen_wajib'];
      $m_min  = (int)$sk['min_anggota_mahasiswa'];
      $m_max  = $sk['max_anggota_mahasiswa'];
      $m_wjb  = (bool)$sk['anggota_mahasiswa_wajib'];
      $fmt_range = fn($mn, $mx) => $mx !== null ? "{$mn}–{$mx}" : "≥{$mn}";
      $fmt_short = function(int $n): string {
          if ($n >= 1000000) {
              $v = rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',');
              return 'Rp ' . $v . 'jt';
          }
          if ($n >= 1000) return 'Rp ' . number_format($n / 1000, 0, ',', '.') . 'rb';
          return 'Rp ' . $n;
      };
      $sim_color = (float)$sk['batas_similarity'] <= 20?'#15803d':((float)$sk['batas_similarity'] <= 30?'#b45309':'#dc2626');
      $ai_color  = (float)$sk['batas_ai'] <= 20?'#15803d':((float)$sk['batas_ai'] <= 35?'#b45309':'#dc2626');
      ?>
      <div class="skema-card <?= $sk['is_open']?'':'closed' ?>">
        <div class="skema-card-head">
          <div class="skema-card-ico" style="background:<?= $colors[0] ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <?php if ($sk['kode'] === 'global'): ?>
              <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
              <?php elseif ($sk['kode'] === 'nasional'): ?>
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
              <?php else: ?>
              <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
              <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
              <?php endif; ?>
            </svg>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:700;color:var(--text-primary);line-height:1.3">
              <?= htmlspecialchars($sk['nama']) ?>
              <?php if ($sk['target_publikasi']): ?>
              <span class="sk-tgt" style="background:<?= $colors[1] ?>;color:#1e293b;border:1px solid <?= $colors[2] ?>;margin-left:6px;vertical-align:1px">
                <?= htmlspecialchars($sk['target_publikasi']) ?>
              </span>
              <?php endif; ?>
            </div>
            <div class="sk-meta" style="margin-top:3px">
              <code><?= htmlspecialchars($sk['kode']) ?></code>
              <span><?= $sk['tahun'] ?></span>
              <span>· <?= $id?'urut':'order' ?> <?= $sk['urutan'] ?></span>
            </div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
            <span class="st-badge <?= $sk['is_open']?'badge-open':'badge-closed' ?>" style="font-size:10.5px;padding:3px 9px">
              <?= $sk['is_open']?($id?'Buka':'Open'):($id?'Tutup':'Closed') ?>
            </span>
            <form method="POST" style="margin:0">
              <input type="hidden" name="action" value="toggle_skema">
              <input type="hidden" name="skema_id" value="<?= $sk['id'] ?>">
              <button type="submit" class="btn btn-outline" style="font-size:11px;padding:5px 9px"
                      title="<?= $sk['is_open']?($id?'Tutup skema':'Close scheme'):($id?'Buka skema':'Open scheme') ?>">
                <?php if ($sk['is_open']): ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <?php else: ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                <?php endif; ?>
              </button>
            </form>
            <button type="button" class="btn btn-outline" style="font-size:11px;padding:5px 9px"
                onclick="openEditModal(<?= htmlspecialchars(json_encode($sk, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)" title="<?= $id?'Edit':'Edit' ?>">
              <?= ic('edit','style="width:13px;height:13px"') ?>
            </button>
            <form method="POST" style="margin:0"
                  onsubmit="return confirm('<?= $id?'Hapus skema ini?':'Delete this scheme?' ?>')">
              <input type="hidden" name="action" value="delete_skema">
              <input type="hidden" name="skema_id" value="<?= $sk['id'] ?>">
              <button type="submit" class="btn btn-outline" title="<?= $id?'Hapus':'Delete' ?>"
                      style="font-size:11px;padding:5px 9px;border-color:#fecaca;color:#dc2626">
                <?= ic('trash','style="width:13px;height:13px"') ?>
              </button>
            </form>
          </div>
        </div>

        <div class="skema-card-body">
          <div class="sk-strip">
            <span class="sk-strip-item" title="<?= $id?'Min. jabatan fungsional ketua':'Min. lead rank' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="#a16207" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
              </svg>
              <span class="sk-strip-lbl"><?= $id?'Ketua':'Lead' ?>:</span>
              <span class="sk-strip-val"><?= $jabatan_opts[$sk['jabatan_min']] ?? $sk['jabatan_min'] ?></span>
            </span>
            <span class="sk-strip-item" title="<?= $id?'Total anggaran (penelitian + APC)':'Total budget (research + APC)' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.2" stroke-linecap="round">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
              <span class="sk-strip-lbl"><?= $id?'Anggaran':'Budget' ?>:</span>
              <span class="sk-strip-val" style="color:#15803d"><?= $fmt_short((int)$sk['anggaran_total']) ?></span>
              <span style="font-size:10px;color:#94a3b8">(<?= $fmt_short((int)$sk['anggaran_penelitian']) ?> + <?= $fmt_short((int)$sk['anggaran_publikasi']) ?> APC)</span>
            </span>
            <span class="sk-strip-item" title="Kuota proposal">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/>
              </svg>
              <span class="sk-strip-lbl"><?= $id?'Kuota':'Quota' ?>:</span>
              <span class="sk-strip-val"><?= (int)$sk['kuota'] ?></span>
            </span>
            <span class="sk-strip-item" title="Batas similarity">
              <svg viewBox="0 0 24 24" fill="none" stroke="<?= $sim_color ?>" stroke-width="2.2" stroke-linecap="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              <span class="sk-strip-lbl">Sim:</span>
              <span class="sk-strip-val" style="color:<?= $sim_color ?>"><?= (float)$sk['batas_similarity'] ?>%</span>
            </span>
            <span class="sk-strip-item" title="Batas deteksi AI">
              <svg viewBox="0 0 24 24" fill="none" stroke="<?= $ai_color ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
              </svg>
              <span class="sk-strip-lbl">AI:</span>
              <span class="sk-strip-val" style="color:<?= $ai_color ?>"><?= (float)$sk['batas_ai'] ?>%</span>
            </span>
            <span class="sk-strip-item" title="<?= $id?'Anggota dosen':'Lecturer members' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
              </svg>
              <span class="sk-strip-lbl"><?= $id?'Dosen':'Lect.' ?>:</span>
              <span class="sk-strip-val"><?= $fmt_range($d_min, $d_max) ?></span>
              <?php if ($d_wjb): ?><span style="background:#dbeafe;color:#1d4ed8;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px"><?= $id?'WAJIB':'REQ' ?></span><?php endif; ?>
            </span>
            <span class="sk-strip-item" title="<?= $id?'Anggota mahasiswa':'Student members' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
              </svg>
              <span class="sk-strip-lbl"><?= $id?'Mhs':'Std.' ?>:</span>
              <span class="sk-strip-val"><?= $fmt_range($m_min, $m_max) ?></span>
              <?php if ($m_wjb): ?><span style="background:#dcfce7;color:#15803d;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px"><?= $id?'WAJIB':'REQ' ?></span><?php endif; ?>
            </span>
            <?php if (!empty($sk['deadline_pengajuan'])): ?>
            <?php
              $sk_dl_ts = strtotime($sk['deadline_pengajuan']);
              $sk_dl_lewat = $sk_dl_ts < time();
            ?>
            <span class="sk-strip-item" title="Deadline khusus skema ini" style="background:<?= $sk_dl_lewat?'#fef2f2':'#fffbeb' ?>;border-color:<?= $sk_dl_lewat?'#fecaca':'#fde68a' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="<?= $sk_dl_lewat?'#dc2626':'#a16207' ?>" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <span class="sk-strip-lbl">Deadline:</span>
              <span class="sk-strip-val" style="color:<?= $sk_dl_lewat?'#991b1b':'#92400e' ?>">
                <?= date('d M H:i', $sk_dl_ts) ?>
              </span>
            </span>
            <?php endif; ?>
          </div>
          <?php if ($sk['deskripsi']): ?>
          <details style="margin-top:9px">
            <summary style="cursor:pointer;font-size:11.5px;color:var(--text-muted);user-select:none;display:inline-flex;align-items:center;gap:5px">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
              <?= $id?'Deskripsi skema':'Description' ?>
            </summary>
            <div style="margin-top:7px;font-size:12.5px;color:#475569;line-height:1.6;padding:9px 12px;background:var(--bg-field);border-radius:7px">
              <?= nl2br(htmlspecialchars($sk['deskripsi'])) ?>
            </div>
          </details>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- ══════════════════════════════════════════════
           BAGIAN 3 — PERNYATAAN KESANGGUPAN
      ══════════════════════════════════════════════ -->
      <div class="cfg-sec">
        <div class="cfg-sec-ico" style="background:linear-gradient(135deg,#78350f,#d97706)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div>
          <div class="cfg-sec-title">3. <?= $id?'Pernyataan Kesanggupan':'Declaration of Commitment' ?></div>
          <div class="cfg-sec-sub"><?= $id?'Poin-poin yang harus dicentang dosen sebelum mengajukan proposal':'Points lecturers must check before submitting a proposal' ?></div>
        </div>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="save_pernyataan">
        <div class="card" style="padding:0;border-radius:12px;overflow:hidden">
          <div style="padding:18px 20px">
            <div class="form-group" style="margin:0">
              <label class="form-label">
                <?= $id?'Daftar Poin Pernyataan (satu poin per baris)':'Declaration Points (one per line)' ?>
              </label>
              <textarea name="poin" class="form-control" rows="9"
                        style="resize:vertical;font-size:13px;line-height:1.8"
                        placeholder="<?= $id?'Masukkan satu poin pernyataan per baris...':'Enter one declaration point per line...' ?>"><?= htmlspecialchars($pernyataan_text) ?></textarea>
              <div class="form-hint">
                <?= $id
                  ? 'Setiap baris menjadi satu poin dengan checkbox tersendiri. Kosongkan baris tidak diinginkan. Perubahan langsung berlaku untuk pengajuan berikutnya.'
                  : 'Each line becomes one checkbox point. Remove unwanted lines. Changes apply to future submissions immediately.' ?>
              </div>
            </div>

            <!-- Preview -->
            <div style="margin-top:14px;padding:13px 15px;background:var(--bg-field);
                        border:1.5px solid var(--border);border-radius:9px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);
                          letter-spacing:.5px;margin-bottom:10px">
                <?= $id?'Preview tampilan di form dosen:':'Preview as shown in lecturer form:' ?>
              </div>
              <div id="preview-list" style="display:flex;flex-direction:column;gap:6px"></div>
            </div>
          </div>
          <div style="padding:12px 20px 14px;background:var(--bg-field);border-top:1px solid var(--border);
                      display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary" style="font-size:13px">
              <?= ic('check-circle') ?> <?= $id?'Simpan Pernyataan':'Save Declaration' ?>
            </button>
            <span style="font-size:12px;color:var(--text-muted)">
              <?= count($pernyataan_poin) ?> <?= $id?'poin aktif':'active points' ?>
            </span>
          </div>
        </div>
      </form>

      <!-- ══════════════════════════════════════════════
           BAGIAN 4 — TEMPLATE PROPOSAL
      ══════════════════════════════════════════════ -->
      <div class="cfg-sec">
        <div class="cfg-sec-ico" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
        </div>
        <div>
          <div class="cfg-sec-title">4. <?= $id?'Template Proposal':'Proposal Template' ?></div>
          <div class="cfg-sec-sub"><?= $id?'Upload template yang wajib diikuti dosen saat menyusun proposal':'Upload templates that lecturers must follow when preparing proposals' ?></div>
        </div>
      </div>

      <!-- Daftar template yang sudah ada -->
      <?php if (!empty($template_list)): ?>
      <div class="card" style="padding:0;border-radius:12px;overflow:hidden;margin-bottom:14px">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);
                    font-size:12px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          <?= $id?'Template Aktif':'Active Templates' ?>
          <span style="background:var(--primary-xlight);color:var(--primary);border-radius:8px;
                       padding:1px 8px;font-size:11px"><?= count($template_list) ?></span>
        </div>
        <?php foreach ($template_list as $ti => $tmpl):
          $ext = strtolower(pathinfo($tmpl['file'], PATHINFO_EXTENSION));
          $ec  = $ext === 'pdf' ? '#dc2626' : '#1d4ed8';
          $ebg = $ext === 'pdf' ? '#fee2e2' : '#dbeafe';
          $fexists = file_exists(BASE_PATH . '/' . $tmpl['file']);
        ?>
        <div style="padding:13px 18px;display:flex;align-items:center;gap:12px;
                    border-bottom:1px solid var(--border)">
          <span style="background:<?= $ebg ?>;color:<?= $ec ?>;font-size:9px;font-weight:800;
                       padding:3px 7px;border-radius:5px;text-transform:uppercase;letter-spacing:.5px;
                       flex-shrink:0"><?= htmlspecialchars(strtoupper($ext)) ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text-primary)">
              <?= htmlspecialchars($tmpl['judul']) ?>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?= $id?'Diupload':'Uploaded' ?>: <?= htmlspecialchars($tmpl['uploaded_at'] ?? '—') ?>
              <?php if (!$fexists): ?>
                &nbsp;<span style="color:#dc2626;font-weight:600"><?= $id?'· File tidak ditemukan!':'· File not found!' ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <?php if ($fexists): ?>
            <a href="<?= BASE_URL . '/' . htmlspecialchars($tmpl['file']) ?>" target="_blank"
               class="btn btn-outline" style="font-size:11.5px;padding:5px 10px">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              <?= $id?'Lihat':'View' ?>
            </a>
            <?php endif; ?>
            <form method="POST" style="margin:0;display:inline"
                  onsubmit="return confirm('<?= $id?'Hapus template ini?':'Delete this template?' ?>')">
              <input type="hidden" name="action" value="delete_template">
              <input type="hidden" name="template_idx" value="<?= $ti ?>">
              <button type="submit" class="btn btn-outline"
                      style="font-size:11.5px;padding:5px 10px;border-color:#fecaca;color:#dc2626">
                <?= ic('trash') ?>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Form upload template baru -->
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_template">
        <div class="card" style="padding:0;border-radius:12px;overflow:hidden">
          <div style="padding:18px 20px">
            <div class="form-grid-2" style="margin-bottom:0">
              <div class="form-group" style="margin:0">
                <label class="form-label">
                  <?= $id?'Judul Template':'Template Title' ?> <span class="required">*</span>
                </label>
                <input type="text" name="judul_template" class="form-control"
                       placeholder="<?= $id?'Contoh: Template Proposal PKM 2026':'e.g. PKM 2026 Proposal Template' ?>">
                <div class="form-hint"><?= $id?'Nama yang akan ditampilkan kepada dosen':'Name shown to lecturers' ?></div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">
                  <?= $id?'File Template':'Template File' ?> <span class="required">*</span>
                </label>
                <input type="file" name="file_template" class="form-control"
                       accept=".pdf,.doc,.docx">
                <div class="form-hint"><?= $id?'Format: PDF, DOC, atau DOCX. Maks. 10 MB':'Format: PDF, DOC, or DOCX. Max 10 MB' ?></div>
              </div>
            </div>
          </div>
          <div style="padding:12px 20px 14px;background:var(--bg-field);border-top:1px solid var(--border);
                      display:flex;gap:10px">
            <button type="submit" class="btn btn-primary" style="font-size:13px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 16 12 12 8 16"/>
                <line x1="12" y1="12" x2="12" y2="21"/>
                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
              </svg>
              <?= $id?'Upload Template':'Upload Template' ?>
            </button>
          </div>
        </div>
      </form>

      <!-- ══════════════════════════════════════════════
           BAGIAN 5 — TIPS
      ══════════════════════════════════════════════ -->
      <div style="margin-top:24px;padding:16px 18px;
                  background:linear-gradient(135deg,#eff6ff,#dbeafe20);
                  border:1.5px solid #bfdbfe;border-radius:12px;
                  display:flex;gap:12px;align-items:flex-start;font-size:12px;color:#1e40af">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <div>
          <div style="font-weight:700;margin-bottom:4px"><?= $id?'Catatan Penting':'Important Notes' ?></div>
          <ul style="margin:0;padding-left:16px;line-height:1.9">
            <li><?= $id?'Perubahan <strong>batas similarity/AI</strong> per skema berlaku untuk proposal yang belum diajukan.':'Changes to <strong>similarity/AI limits</strong> per scheme apply to not-yet-submitted proposals.' ?></li>
            <li><?= $id?'Menutup skema (<strong>Tutup</strong>) akan menyembunyikan pilihan skema tersebut dari dosen.':'Closing a scheme (<strong>Close</strong>) will hide that scheme option from lecturers.' ?></li>
            <li><?= $id?'Skema dapat dihapus hanya jika <strong>belum ada proposal</strong> yang menggunakan kode skema tersebut.':'Schemes can only be deleted if <strong>no proposals</strong> have used that scheme code.' ?></li>
            <li><?= $id?'<strong>Kode skema</strong> (contoh: "global") tidak dapat diubah setelah dibuat — gunakan nama yang jelas dan konsisten.':'<strong>Scheme code</strong> (e.g. "global") cannot be changed after creation — use a clear, consistent name.' ?></li>
            <li><?= $id?'<strong>Ketentuan anggota</strong> diatur per skema: min/maks dosen dan mahasiswa, serta sifat wajib/opsional masing-masing. Validasi akan diterapkan saat dosen mengajukan proposal.':'<strong>Member requirements</strong> are per-scheme: min/max lecturers and students, each with required/optional flag. Validation is enforced when submitting.' ?></li>
            <li><?= $id?'Jika <strong>sifat = Opsional</strong>, dosen tetap bisa mengajukan tanpa memenuhi jumlah minimum. Jika <strong>Wajib</strong>, jumlah minimum harus terpenuhi.':'If <strong>nature = Optional</strong>, lecturers can still submit without meeting the minimum. If <strong>Required</strong>, the minimum must be met.' ?></li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL — Tambah / Edit Skema
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="skemaModal" onclick="closeModalOutside(event)">
  <div class="modal-box">
    <div class="modal-head">
      <div class="cfg-sec-ico" id="modalIco"
           style="background:linear-gradient(135deg,#4a1d96,#7c3aed)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
          <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
        </svg>
      </div>
      <div style="flex:1">
        <div id="modalTitle" style="font-size:14px;font-weight:800;color:var(--text-primary)">
          <?= $id?'Tambah Skema Baru':'Add New Scheme' ?>
        </div>
        <div style="font-size:11.5px;color:var(--text-muted)"><?= $id?'Isi semua kolom yang diperlukan':'Fill in all required fields' ?></div>
      </div>
      <button onclick="closeModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form method="POST" id="skemaForm">
      <input type="hidden" name="action" id="formAction" value="add_skema">
      <input type="hidden" name="skema_id" id="formSkemaId" value="">

      <div class="modal-body">

        <!-- ① Identitas Skema ─────────────────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:#eff6ff;color:#1e40af;border-bottom-color:#bfdbfe">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
              <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
            </svg>
            <?= $id?'Identitas Skema':'Scheme Identity' ?>
          </div>
          <div class="msec-body">
            <div class="form-grid-2">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Kode Skema':'Scheme Code' ?> <span class="required">*</span></label>
                <input type="text" name="kode" id="fKode" class="form-control"
                       placeholder="<?= $id?'Contoh: global, nasional':'e.g. global, nasional' ?>"
                       pattern="[a-z0-9_]+" title="<?= $id?'Hanya huruf kecil, angka, dan garis bawah':'Lowercase letters, numbers, underscore only' ?>">
                <div class="form-hint" id="kodeNote"><?= $id?'Huruf kecil · tidak dapat diubah setelah tersimpan':'Lowercase · cannot be changed after saving' ?></div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Tahun Anggaran':'Budget Year' ?> <span class="required">*</span></label>
                <input type="number" name="tahun_skema" id="fTahun" class="form-control"
                       min="2020" max="2099" value="<?= $tahun ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label"><?= $id?'Nama Skema':'Scheme Name' ?> <span class="required">*</span></label>
              <input type="text" name="nama" id="fNama" class="form-control"
                     placeholder="<?= $id?'Contoh: Publikasi Bereputasi Global':'e.g. Global Reputation Publication' ?>">
            </div>
            <div class="form-grid-2" style="margin-bottom:0">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Target Publikasi':'Publication Target' ?></label>
                <input type="text" name="target_publikasi" id="fTarget" class="form-control"
                       placeholder="<?= $id?'Contoh: Scopus Q1-Q3':'e.g. Scopus Q1-Q3' ?>">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Kuota Proposal':'Proposal Quota' ?> <span class="required">*</span></label>
                <input type="number" name="kuota" id="fKuota" class="form-control" min="1" value="10">
                <div class="form-hint"><?= $id?'Maks. proposal yang diterima':'Maximum accepted proposals' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ② Syarat Ketua Peneliti ───────────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:#fffbeb;color:#92400e;border-bottom-color:#fde68a">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
            <?= $id?'Syarat Ketua Peneliti':'Lead Researcher Requirements' ?>
          </div>
          <div class="msec-body">
            <div class="form-group" style="margin:0">
              <label class="form-label"><?= $id?'Jabatan Fungsional Minimal Ketua':'Minimum Functional Rank for Chair' ?></label>
              <select name="jabatan_min" id="fJabatan" class="form-control">
                <?php foreach ($jabatan_opts as $val => $lbl): ?>
                <option value="<?= $val ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint"><?= $id?'Dosen dengan jabatan di bawah nilai ini tidak dapat menjadi ketua peneliti':'Lecturers below this rank cannot serve as lead researcher' ?></div>
            </div>
          </div>
        </div>

        <!-- ③ Pengaturan Anggaran ─────────────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:#f0fdf4;color:#14532d;border-bottom-color:#bbf7d0">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="1" x2="12" y2="23"/>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            <?= $id?'Pengaturan Anggaran':'Budget Settings' ?>
          </div>
          <div class="msec-body">
            <div class="form-grid-3" style="margin-bottom:0">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Biaya Penelitian (Rp)':'Research Cost (Rp)' ?></label>
                <input type="text" name="anggaran_penelitian" id="fAngPenelitian" class="form-control"
                       placeholder="15000000" oninput="fmtRupiah(this)">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Biaya Publikasi / APC (Rp)':'Publication / APC (Rp)' ?></label>
                <input type="text" name="anggaran_publikasi" id="fAngPublikasi" class="form-control"
                       placeholder="13500000" oninput="fmtRupiah(this)">
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label" style="display:flex;align-items:center;justify-content:space-between">
                  <span><?= $id?'Anggaran Total (Rp)':'Total Budget (Rp)' ?></span>
                  <span style="font-size:10px;font-weight:600;background:#dbeafe;color:#1d4ed8;
                               border-radius:4px;padding:1px 6px">auto</span>
                </label>
                <input type="text" name="anggaran_total" id="fAngTotal" class="form-control"
                       placeholder="<?= $id?'Otomatis dihitung':'Auto-calculated' ?>"
                       readonly
                       style="background:var(--bg-field);color:var(--text-primary);font-weight:700;
                              cursor:default;border-color:var(--primary-light)">
                <div id="totalAutoLbl" style="font-size:11px;color:#1d4ed8;font-weight:600;margin-top:3px"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ④ Batas Deteksi Plagiasi & AI ────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:#fff7ed;color:#9a3412;border-bottom-color:#fed7aa">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <?= $id?'Batas Deteksi Plagiasi &amp; AI':'Plagiarism &amp; AI Detection Limits' ?>
          </div>
          <div class="msec-body">
            <div class="form-grid-2" style="margin-bottom:0">
              <div class="form-group" style="margin:0">
                <label class="form-label">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2.2"
                       stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                  </svg>
                  <?= $id?'Maks. Similarity (%)':'Max Similarity (%)' ?>
                </label>
                <input type="number" name="batas_similarity" id="fSim" class="form-control"
                       min="1" max="100" step="0.5" value="25">
                <div class="form-hint"><?= $id?'Batas nilai similarity Turnitin / iThenticate':'Turnitin / iThenticate similarity limit' ?></div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.2"
                       stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                  </svg>
                  <?= $id?'Maks. Deteksi AI (%)':'Max AI Detection (%)' ?>
                </label>
                <input type="number" name="batas_ai" id="fAI" class="form-control"
                       min="1" max="100" step="0.5" value="30">
                <div class="form-hint"><?= $id?'Batas nilai deteksi AI (GPTZero, Turnitin AI, dll.)':'AI detection limit (GPTZero, Turnitin AI, etc.)' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Monev Luaran (batas waktu luaran publikasi per skema) -->
        <div class="msec">
          <div class="msec-head" style="background:#ecfdf5;color:#065f46;border-bottom-color:#a7f3d0">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="8" r="6"/>
              <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
            </svg>
            <?= $id?'Monev — Batas Luaran Publikasi':'Monev — Publication Output Deadline' ?>
          </div>
          <div class="msec-body">
            <div class="form-grid-2" style="margin-bottom:0">
              <div class="form-group" style="margin:0">
                <label class="form-label">
                  <?= $id?'Batas Luaran (bulan sejak batas laporan)':'Output Deadline (months after report)' ?>
                </label>
                <input type="number" name="batas_luaran_bulan" id="fBatasLuaran" class="form-control"
                       min="1" max="60" step="1" value="24" placeholder="24">
                <div class="form-hint"><?= $id
                  ? 'Default: 24 bulan (nasional), 36 bulan (global). Peneliti wajib menyerahkan bukti luaran (jurnal/prosiding dll.) sebelum batas ini.'
                  : 'Default: 24 months (national), 36 months (global). Researcher must submit output evidence before this deadline.' ?></div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Jenis Luaran Wajib':'Required Output Types' ?></label>
                <input type="text" name="jenis_luaran_wajib" id="fJenisLuaran" class="form-control"
                       placeholder="<?= $id?'mis. Artikel jurnal Sinta 3 / Scopus Q3':'e.g. Sinta 3 / Scopus Q3 article' ?>"
                       maxlength="255">
                <div class="form-hint"><?= $id?'Deskripsi luaran wajib (ditampilkan ke peneliti saat submit luaran)':'Required output description (shown to researcher)' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ⑤ Ketentuan Anggota Tim ──────────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:#faf5ff;color:#4a1d96;border-bottom-color:#ddd6fe">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <?= $id?'Ketentuan Anggota Tim':'Team Member Requirements' ?>
          </div>
          <div class="msec-body">
            <!-- Dosen sub-panel -->
            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:9px;padding:12px 14px;margin-bottom:10px">
              <div style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:10px;display:flex;align-items:center;gap:6px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <?= $id?'Dosen Anggota (selain Ketua)':'Lecturer Members (excl. Chair)' ?>
              </div>
              <div class="form-grid-3" style="margin-bottom:0">
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Min. Dosen':'Min. Lecturers' ?></label>
                  <input type="number" name="min_anggota_dosen" id="fMinDosen"
                         class="form-control" min="0" max="20" value="0" placeholder="0">
                  <div class="form-hint"><?= $id?'0 = tidak ada min.':'0 = no minimum' ?></div>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Maks. Dosen':'Max. Lecturers' ?></label>
                  <input type="number" name="max_anggota_dosen" id="fMaxDosen"
                         class="form-control" min="0" max="20" value=""
                         placeholder="<?= $id?'kosong = ∞':'empty = ∞' ?>">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Sifat':'Nature' ?></label>
                  <select name="anggota_dosen_wajib" id="fDosenWajib" class="form-control">
                    <option value="1"><?= $id?'Wajib':'Required' ?></option>
                    <option value="0" selected><?= $id?'Opsional':'Optional' ?></option>
                  </select>
                  <div class="form-hint"><?= $id?'Apakah dosen anggota harus ada?':'Are lecturers mandatory?' ?></div>
                </div>
              </div>
            </div>
            <!-- Mahasiswa sub-panel -->
            <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;padding:12px 14px">
              <div style="font-size:12px;font-weight:700;color:#14532d;margin-bottom:10px;display:flex;align-items:center;gap:6px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                <?= $id?'Mahasiswa Anggota':'Student Members' ?>
              </div>
              <div class="form-grid-3" style="margin-bottom:0">
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Min. Mahasiswa':'Min. Students' ?></label>
                  <input type="number" name="min_anggota_mahasiswa" id="fMinMhs"
                         class="form-control" min="0" max="30" value="2" placeholder="2">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Maks. Mahasiswa':'Max. Students' ?></label>
                  <input type="number" name="max_anggota_mahasiswa" id="fMaxMhs"
                         class="form-control" min="0" max="30" value=""
                         placeholder="<?= $id?'kosong = ∞':'empty = ∞' ?>">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11.5px"><?= $id?'Sifat':'Nature' ?></label>
                  <select name="anggota_mahasiswa_wajib" id="fMhsWajib" class="form-control">
                    <option value="1" selected><?= $id?'Wajib':'Required' ?></option>
                    <option value="0"><?= $id?'Opsional':'Optional' ?></option>
                  </select>
                  <div class="form-hint"><?= $id?'Apakah mahasiswa harus ada?':'Are students mandatory?' ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ⑥ Konfigurasi Lainnya ────────────────────────── -->
        <div class="msec">
          <div class="msec-head" style="background:var(--bg-field);color:var(--text-primary);border-bottom-color:var(--border)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="4" y1="6" x2="20" y2="6"/>
              <line x1="4" y1="12" x2="20" y2="12"/>
              <line x1="4" y1="18" x2="20" y2="18"/>
            </svg>
            <?= $id?'Konfigurasi Lainnya':'Other Configuration' ?>
          </div>
          <div class="msec-body">
            <div class="form-grid-2">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Urutan Tampil':'Display Order' ?></label>
                <input type="number" name="urutan" id="fUrutan" class="form-control" min="0" value="99">
                <div class="form-hint"><?= $id?'Angka kecil tampil lebih dulu':'Lower number appears first' ?></div>
              </div>
              <div class="form-group" style="margin:0;align-self:center;padding-top:22px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px">
                  <input type="checkbox" name="is_open" id="fIsOpen" checked style="width:16px;height:16px">
                  <span><?= $id?'Skema langsung dibuka':'Scheme immediately open' ?></span>
                </label>
              </div>
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label"><?= $id?'Deskripsi / Catatan':'Description / Notes' ?></label>
              <textarea name="deskripsi" id="fDeskripsi" class="form-control" rows="3" style="resize:vertical"
                        placeholder="<?= $id?'Informasi tambahan tentang skema ini (opsional)':'Additional information about this scheme (optional)' ?>"></textarea>
            </div>
          </div>
        </div>

      </div>

      <div class="modal-foot">
        <button type="button" onclick="closeModal()" class="btn btn-outline">
          <?= $id?'Batal':'Cancel' ?>
        </button>
        <button type="submit" class="btn btn-primary">
          <?= ic('check-circle') ?> <span id="submitLbl"><?= $id?'Simpan Skema':'Save Scheme' ?></span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const LANG = '<?= $lang ?>';

function openAddModal() {
  document.getElementById('formAction').value = 'add_skema';
  document.getElementById('formSkemaId').value = '';
  document.getElementById('modalTitle').textContent = LANG==='id' ? 'Tambah Skema Baru' : 'Add New Scheme';
  document.getElementById('submitLbl').textContent  = LANG==='id' ? 'Simpan Skema' : 'Save Scheme';

  // Reset fields
  const ids = ['fKode','fNama','fTarget','fTahun','fKuota',
                'fAngPenelitian','fAngPublikasi','fDeskripsi'];
  ids.forEach(i => { const el = document.getElementById(i); if(el) el.value = i==='fTahun'?'<?= $tahun ?>':i==='fKuota'?'10':''; });
  autoSumTotal(); // reset total juga
  document.getElementById('fJabatan').value   = 'asisten_ahli';
  document.getElementById('fSim').value       = '25';
  document.getElementById('fAI').value        = '30';
  document.getElementById('fUrutan').value    = '99';
  document.getElementById('fIsOpen').checked  = true;
  // Tim anggota — defaults
  document.getElementById('fMinDosen').value  = '0';
  document.getElementById('fMaxDosen').value  = '';
  document.getElementById('fDosenWajib').value = '0';
  document.getElementById('fMinMhs').value    = '2';
  document.getElementById('fMaxMhs').value    = '';
  document.getElementById('fMhsWajib').value  = '1';
  // Monev luaran defaults
  const bl = document.getElementById('fBatasLuaran'); if (bl) bl.value = '24';
  const jl = document.getElementById('fJenisLuaran'); if (jl) jl.value = '';
  // Show kode field
  document.getElementById('fKode').disabled = false;
  document.getElementById('kodeNote').style.display = 'block';

  document.getElementById('skemaModal').classList.add('open');
  document.getElementById('skemaModal').querySelector('.modal-box').scrollTop = 0;
}

function openEditModal(sk) {
  document.getElementById('formAction').value = 'edit_skema';
  document.getElementById('formSkemaId').value = sk.id;
  document.getElementById('modalTitle').textContent = LANG==='id' ? 'Edit Skema' : 'Edit Scheme';
  document.getElementById('submitLbl').textContent  = LANG==='id' ? 'Simpan Perubahan' : 'Save Changes';

  document.getElementById('fKode').value = sk.kode;
  document.getElementById('fKode').disabled = true;
  document.getElementById('kodeNote').textContent = LANG==='id'
    ? '⚠ Kode tidak dapat diubah setelah tersimpan.'
    : '⚠ Code cannot be changed after saving.';

  document.getElementById('fNama').value       = sk.nama;
  document.getElementById('fTarget').value     = sk.target_publikasi || '';
  document.getElementById('fTahun').value      = sk.tahun;
  document.getElementById('fKuota').value      = sk.kuota;
  document.getElementById('fAngPenelitian').value  = sk.anggaran_penelitian;
  document.getElementById('fAngPublikasi').value   = sk.anggaran_publikasi;
  autoSumTotal(); // hitung ulang total dari penelitian + publikasi
  document.getElementById('fJabatan').value    = sk.jabatan_min;
  document.getElementById('fSim').value        = sk.batas_similarity;
  document.getElementById('fAI').value         = sk.batas_ai;
  document.getElementById('fUrutan').value     = sk.urutan;
  document.getElementById('fIsOpen').checked   = sk.is_open == 1;
  document.getElementById('fDeskripsi').value  = sk.deskripsi || '';
  // Tim anggota
  document.getElementById('fMinDosen').value   = sk.min_anggota_dosen  ?? '0';
  document.getElementById('fMaxDosen').value   = sk.max_anggota_dosen  != null ? sk.max_anggota_dosen : '';
  document.getElementById('fDosenWajib').value = sk.anggota_dosen_wajib     == 1 ? '1' : '0';
  document.getElementById('fMinMhs').value     = sk.min_anggota_mahasiswa   ?? '2';
  document.getElementById('fMaxMhs').value     = sk.max_anggota_mahasiswa   != null ? sk.max_anggota_mahasiswa : '';
  document.getElementById('fMhsWajib').value   = sk.anggota_mahasiswa_wajib == 1 ? '1' : '0';
  // Monev luaran
  const bl = document.getElementById('fBatasLuaran');
  if (bl) bl.value = sk.batas_luaran_bulan ?? 24;
  const jl = document.getElementById('fJenisLuaran');
  if (jl) jl.value = sk.jenis_luaran_wajib || '';

  document.getElementById('skemaModal').classList.add('open');
  document.getElementById('skemaModal').querySelector('.modal-box').scrollTop = 0;
}

function closeModal() {
  document.getElementById('skemaModal').classList.remove('open');
}
function closeModalOutside(e) {
  if (e.target === document.getElementById('skemaModal')) closeModal();
}

// ── Format ribuan (display, value tetap angka polos) ────────
function fmtRupiah(el) {
  el.value = el.value.replace(/\D/g,'');
  autoSumTotal();
}

// ── Auto-sum: total = penelitian + publikasi ─────────────────
function autoSumTotal() {
  const p = parseInt(document.getElementById('fAngPenelitian').value.replace(/\D/g,'')) || 0;
  const pub = parseInt(document.getElementById('fAngPublikasi').value.replace(/\D/g,'')) || 0;
  const total = p + pub;
  const el = document.getElementById('fAngTotal');
  if (el) {
    el.value = total > 0 ? total : '';
    // Tunjukkan label ringkas di sebelah field
    const lbl = document.getElementById('totalAutoLbl');
    if (lbl) {
      lbl.textContent = total > 0
        ? 'Rp ' + total.toLocaleString('id-ID')
        : '';
    }
  }
}

document.addEventListener('DOMContentLoaded', function() {
  // Pasang listener setelah DOM siap
  const p   = document.getElementById('fAngPenelitian');
  const pub = document.getElementById('fAngPublikasi');
  if (p)   p.addEventListener('input',   autoSumTotal);
  if (pub) pub.addEventListener('input', autoSumTotal);

  // ── Preview pernyataan poin ──────────────────────────────
  const poinTA = document.querySelector('textarea[name="poin"]');
  const previewList = document.getElementById('preview-list');

  function renderPreview() {
    if (!poinTA || !previewList) return;
    const lines = poinTA.value.split('\n').map(l => l.trim()).filter(l => l);
    previewList.innerHTML = lines.length === 0
      ? '<div style="font-size:12px;color:#94a3b8;font-style:italic">' + (LANG==='id'?'(tidak ada poin)':'(no points)') + '</div>'
      : lines.map((l, i) =>
          '<div style="display:flex;align-items:flex-start;gap:9px;padding:9px 11px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;font-size:12px">' +
             '<input type="checkbox" disabled style="margin-top:2px;flex-shrink:0;width:14px;height:14px">' +
             '<span><strong style="color:#4f46e5">' + (i+1) + '.</strong> ' + l.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;') + '</span>' +
           '</div>'
        ).join('');
  }

  if (poinTA) { poinTA.addEventListener('input', renderPreview); renderPreview(); }
});
</script>
</body>
</html>
