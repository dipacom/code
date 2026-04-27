<?php
require_once '../includes/config.php';
require_once '../includes/penerimaan.php';
requireLogin('admin');

$lang    = $_COOKIE['lang'] ?? 'id';
$batas   = (int)getSetting($pdo, 'batas_similarity');
$batas_ai = (int)(getSetting($pdo, 'batas_ai') ?: 20);

// Proses input skor similarity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Toggle penerimaan ─────────────────────────────────────
    if ($_POST['action'] === 'toggle_penerimaan') {
        $j       = clean($_POST['jenis'] ?? '');
        $current = getPenerimaan($pdo, $j);
        setPenerimaan($pdo, $j, $current === 'buka' ? 'tutup' : 'buka');
        $newVal = $current === 'buka' ? 'tutup' : 'buka';
        $_SESSION['flash'] = [
            'type' => $newVal === 'buka' ? 'success' : 'warning',
            'msg'  => $newVal === 'buka'
                ? ($lang==='id' ? 'Penerimaan pengajuan berhasil dibuka kembali.' : 'Submission intake reopened.')
                : ($lang==='id' ? 'Penerimaan pengajuan berhasil ditutup sementara.' : 'Submission intake temporarily closed.'),
        ];
        redirect('/admin/plagiasi.php');
    }

    if ($_POST['action'] === 'input_skor') {
        $skripsi_id = (int)$_POST['skripsi_id'];
        $skor       = (float)str_replace(',', '.', $_POST['similarity_score']);
        $tgl        = clean($_POST['tanggal_cek']);
        $catatan    = clean($_POST['catatan'] ?? '');
        $penanda    = clean($_POST['nama_penandatangan']);
        $jabatan    = clean($_POST['jabatan_penandatangan']);

        $ai_skor     = trim($_POST['ai_score'] ?? '');
        $ai_skor     = ($ai_skor !== '') ? (float)str_replace(',', '.', $ai_skor) : null;
        $platform_ai = clean($_POST['platform_ai'] ?? '');

        // Simpan hasil cek
        $stmt = $pdo->prepare("INSERT INTO cek_plagiasi (skripsi_id, admin_id, similarity_score, ai_score, platform_ai, tanggal_cek, catatan) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$skripsi_id, $_SESSION['user_id'], $skor, $ai_skor, $platform_ai ?: null, $tgl, $catatan]);
        $cp_id = $pdo->lastInsertId();

        // Lolos jika similarity OK DAN (ai_score tidak diisi ATAU ai_score <= batas_ai)
        $aiLolos = ($ai_skor === null || $ai_skor <= $batas_ai);
        if ($skor <= $batas && $aiLolos) {
            // Lolos: update status & generate surat
            $pdo->prepare("UPDATE skripsi SET status='selesai' WHERE id=?")->execute([$skripsi_id]);

            $prefix  = getSetting($pdo, 'prefix_surat_plagiasi') ?: 'LPPM/IAKNT/SK-PL';
            $no_surat = generateNomorSurat($pdo, $prefix, 'surat_plagiasi');

            $pdo->prepare("INSERT INTO surat_plagiasi (cek_plagiasi_id, nomor_surat, tanggal_surat, nama_penandatangan, jabatan_penandatangan) VALUES (?,?,?,?,?)")
                ->execute([$cp_id, $no_surat, $tgl, $penanda, $jabatan]);

            // Notifikasi mahasiswa
            $skripsi = $pdo->prepare("SELECT s.*, u.email, u.nama_lengkap FROM skripsi s JOIN users u ON s.user_id=u.id WHERE s.id=?");
            $skripsi->execute([$skripsi_id]);
            $skripsi = $skripsi->fetch();

        $pesan_notif = "Selamat! Skor similarity Anda {$skor}% (≤{$batas}%)";
            if ($ai_skor !== null) $pesan_notif .= " dan skor deteksi AI {$ai_skor}% (≤{$batas_ai}%)";
            $pesan_notif .= ". Surat keterangan bebas plagiasi Anda telah diterbitkan.";
            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'sukses')")
                ->execute([$skripsi['user_id'], 'Surat Bebas Plagiasi Siap Diunduh', $pesan_notif]);

            require_once '../includes/email.php';
            kirimEmailMahasiswa(
                $skripsi['email'],
                $skripsi['nama_lengkap'],
                'selesai',
                'Surat Keterangan Bebas Plagiasi',
                '',
                $skripsi['nim'] ?? ''
            );

        $aiMsg = ($ai_skor !== null) ? ($lang==='id' ? ", AI {$ai_skor}%" : ", AI {$ai_skor}%") : '';
            $_SESSION['flash'] = ['type' => 'success', 'msg' => ($lang==='id' ? "Similarity {$skor}%{$aiMsg} — Lolos! Surat diterbitkan: {$no_surat}." : "Similarity {$skor}%{$aiMsg} — Passed! Certificate issued: {$no_surat}.")];
        } else {
            // Tidak lolos
            $pdo->prepare("UPDATE skripsi SET status='ditolak', catatan_admin=? WHERE id=?")->execute([$catatan, $skripsi_id]);

            $skripsi = $pdo->prepare("SELECT s.*, u.email, u.nama_lengkap FROM skripsi s JOIN users u ON s.user_id=u.id WHERE s.id=?");
            $skripsi->execute([$skripsi_id]);
            $skripsi = $skripsi->fetch();

            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,'peringatan')")
                ->execute([$skripsi['user_id'], 'Perlu Revisi Skripsi', "Skor similarity Anda {$skor}% melebihi batas {$batas}%. Silakan revisi dan upload ulang."]);

            require_once '../includes/email.php';
            kirimEmailMahasiswa(
                $skripsi['email'],
                $skripsi['nama_lengkap'],
                'ditolak',
                'Surat Keterangan Bebas Plagiasi',
                $catatan ?: "Skor similarity {$skor}% melebihi batas maksimal {$batas}%.",
                $skripsi['nim'] ?? ''
            );

            $_SESSION['flash'] = ['type' => 'warning', 'msg' => ($lang==='id' ? "Skor {$skor}% — Melebihi batas {$batas}%. Mahasiswa telah dinotifikasi untuk revisi." : "Score {$skor}% — Exceeds {$batas}% limit. Student notified for revision.")];
        }
        redirect('/admin/plagiasi.php');
    }
}

// ── Soft delete (pindah ke sampah) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_pengajuan') {
    $hid = (int)($_POST['id'] ?? 0);
    if ($hid) {
        $stmt = $pdo->prepare("UPDATE skripsi SET deleted_at=NOW() WHERE id=? AND status IN ('selesai','ditolak')");
        $stmt->execute([$hid]);
        if ($stmt->rowCount()) {
            require_once '../includes/logger.php';
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_pengajuan',
                "Pindah ke sampah: plagiasi id={$hid}");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Pengajuan dipindahkan ke Sampah.'];
        }
    }
    redirect('/admin/plagiasi.php?filter=' . ($_POST['filter_back'] ?? 'semua'));
}

// Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Skripsi yang sedang diproses (dari klik tombol Proses)
$proses_id  = (int)($_GET['proses'] ?? 0);
$skripsi_proses = null;
if ($proses_id) {
    $stmt = $pdo->prepare("SELECT s.*, u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email FROM skripsi s JOIN users u ON s.user_id=u.id WHERE s.id=?");
    $stmt->execute([$proses_id]);
    $skripsi_proses = $stmt->fetch();
}

// Pending count untuk penerimaan bar
$pending_plagiasi = (int)$pdo->query("SELECT COUNT(*) FROM skripsi WHERE status='menunggu' AND deleted_at IS NULL")->fetchColumn();

// Stats per status untuk summary cards
$pl_stats_all = $pdo->query("SELECT status, COUNT(*) c FROM skripsi WHERE deleted_at IS NULL GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pl_stat_menunggu = (int)($pl_stats_all['menunggu'] ?? 0);
$pl_stat_selesai  = (int)($pl_stats_all['selesai']  ?? 0);
$pl_stat_ditolak  = (int)($pl_stats_all['ditolak']  ?? 0);
$pl_stat_semua    = array_sum($pl_stats_all);

// Daftar semua skripsi
$filter        = $_GET['filter'] ?? 'menunggu';
$filter_search = clean($_GET['q'] ?? '');
$allowed = ['menunggu','diproses','selesai','ditolak','semua'];
if (!in_array($filter, $allowed)) $filter = 'menunggu';

$where_parts = ['s.deleted_at IS NULL'];
$params      = [];
if ($filter !== 'semua') {
    $where_parts[] = 's.status = ?';
    $params[]      = $filter;
}
if ($filter_search !== '') {
    $where_parts[] = '(u.nama_lengkap LIKE ? OR s.judul_skripsi LIKE ? OR u.nim LIKE ?)';
    $kw = '%' . $filter_search . '%';
    array_push($params, $kw, $kw, $kw);
}
$where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : 'WHERE 1=1';

$stmt = $pdo->prepare("
    SELECT s.*, u.nama_lengkap, u.nim, u.program_studi, u.fakultas,
           cp.similarity_score, cp.ai_score, cp.platform_ai, sp.nomor_surat
    FROM skripsi s
    JOIN users u ON s.user_id=u.id
    LEFT JOIN cek_plagiasi cp ON s.id=cp.skripsi_id
    LEFT JOIN surat_plagiasi sp ON cp.id=sp.cek_plagiasi_id
    $where
    ORDER BY s.created_at DESC
");
$stmt->execute($params);
$list = $stmt->fetchAll();

// Data penandatangan default
$ketua = getSetting($pdo, 'nama_ketua_lppm');
$nip   = getSetting($pdo, 'nip_ketua_lppm');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Kelola Plagiasi':'Manage Plagiarism' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('search') ?> <?= $lang==='id'?'Cek Bebas Plagiasi':'Plagiarism Check Management' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Input hasil Turnitin & terbitkan surat':'Input Turnitin results & issue certificates' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type']==='success'?'success':'warning' ?>">
          <?= $flash['msg'] ?>
        </div>
      <?php endif; ?>

      <?php renderPenerimaanBar($pdo, 'plagiasi',
            'Surat Bebas Plagiasi', 'Plagiarism Certificate',
            $pending_plagiasi, $lang, '/admin/plagiasi.php'); ?>

      <!-- Panel Proses -->
      <?php if ($skripsi_proses): ?>
      <?php $sp = $skripsi_proses; $hasMandiri = $sp['similarity_mandiri'] !== null; ?>
      <div class="card" style="margin-bottom:24px;border:2px solid var(--primary)">
        <div class="card-header" style="background:var(--primary-xlight)">
          <span class="card-title"><?= ic('play') ?> <?= $lang==='id'?'Verifikasi & Input Hasil Turnitin':'Verify & Input Turnitin Result' ?></span>
          <a href="plagiasi.php" class="btn btn-outline btn-sm"><?= ic('x') ?> <?= $lang==='id'?'Tutup':'Close' ?></a>
        </div>
        <div class="card-body">

          <!-- Info mahasiswa -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;padding:14px 16px;background:var(--bg-field);border-radius:8px;border:1px solid var(--border)">
            <div>
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px"><?= $lang==='id'?'Nama':'Name' ?></div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($sp['nama_lengkap']) ?></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px">NIM</div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($sp['nim']??'-') ?></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px"><?= $lang==='id'?'Jenis TA':'Project Type' ?></div>
              <div style="font-weight:600;font-size:13px"><?= $taLbl ?></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px"><?= $lang==='id'?'Fakultas':'Faculty' ?></div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($sp['fakultas']??'-') ?></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px"><?= $lang==='id'?'Program Studi':'Study Program' ?></div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($sp['program_studi']??'-') ?></div>
            </div>
          </div>

          <!-- Judul & file -->
          <?php
          $jTA   = $sp['jenis_tugas_akhir'] ?? 'skripsi';
          $taLbl = ['skripsi'=>'Skripsi','tesis'=>'Tesis','disertasi'=>'Disertasi'][$jTA] ?? 'Skripsi';
          $pemb1Lbl = match($jTA) { 'tesis'=>'Pembimbing Utama','disertasi'=>'Promotor',default=>'Pembimbing I' };
          $pemb2Lbl = match($jTA) { 'tesis'=>'Pembimbing Pendamping','disertasi'=>'Ko-Promotor 1',default=>'Pembimbing II' };
          ?>
          <div style="margin-bottom:20px;padding:12px 16px;background:#fff;border:1px solid var(--border);border-radius:8px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase"><?= $lang==='id'?'Judul':'Title' ?></span>
              <span style="font-size:10px;font-weight:700;padding:1px 8px;border-radius:10px;background:var(--primary-light);color:var(--primary)"><?= $taLbl ?></span>
            </div>
            <div style="font-weight:600;font-size:13px;line-height:1.5"><?= htmlspecialchars($sp['judul_skripsi']) ?></div>
            <?php if ($sp['nama_pembimbing1']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
              <?= $pemb1Lbl ?>: <?= htmlspecialchars($sp['nama_pembimbing1']) ?>
              <?php if ($sp['nama_pembimbing2']): ?> &nbsp;·&nbsp; <?= $pemb2Lbl ?>: <?= htmlspecialchars($sp['nama_pembimbing2']) ?><?php endif; ?>
              <?php if (!empty($sp['nama_pembimbing3'])): ?> &nbsp;·&nbsp; Ko-Promotor 2: <?= htmlspecialchars($sp['nama_pembimbing3']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
            <div style="margin-top:10px">
              <a href="../<?= $sp['file_path'] ?>" target="_blank" class="btn btn-outline btn-sm">
                <?= ic('doc') ?> <?= $lang==='id'?'Buka File Skripsi':'Open Thesis File' ?>
              </a>
            </div>
          </div>

          <!-- ── HASIL CEK MANDIRI (PLAGIASI + AI) ── -->
          <?php
            $hasAiMandiri = $sp['ai_mandiri'] !== null;
            if ($hasMandiri) { $sm = (float)$sp['similarity_mandiri']; $smOk = $sm <= $batas; $smColor = $smOk ? '#059669' : '#dc2626'; }
            if ($hasAiMandiri) { $aim = (float)$sp['ai_mandiri']; $aimOk = $aim <= $batas_ai; $aimColor = $aimOk ? '#059669' : '#dc2626'; }
          ?>
          <?php if ($hasMandiri || $hasAiMandiri): ?>
          <div class="det-grid" style="margin-bottom:12px;gap:14px">

            <!-- KIRI: Cek Plagiasi Mandiri -->
            <?php if ($hasMandiri): ?>
            <div style="border-radius:10px;overflow:hidden;border:1.5px solid var(--accent-light)">
              <div style="padding:8px 14px;background:linear-gradient(90deg,var(--accent-dark),var(--accent));display:flex;align-items:center;gap:6px">
                <?= ic('search', 'style="width:13px;height:13px;color:#fff"') ?>
                <span style="color:#fff;font-weight:700;font-size:11px"><?= $lang==='id'?'Cek Plagiasi Mandiri':'Self-Check: Plagiarism' ?></span>
              </div>
              <div style="padding:12px;background:var(--purple-bg)">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                  <div style="text-align:center;padding:8px 10px;background:#fff;border-radius:8px;border:1px solid var(--purple-border);flex-shrink:0;min-width:72px">
                    <div style="font-size:26px;font-weight:800;line-height:1;color:<?= $smColor ?>"><?= $sm ?>%</div>
                    <div style="height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;margin:4px 0">
                      <div style="height:100%;width:<?= min($sm,100) ?>%;background:<?= $smColor ?>;border-radius:2px"></div>
                    </div>
                    <div style="font-size:9px;font-weight:700;color:<?= $smColor ?>"><?= $smOk ? '✓ OK' : '✕ Melebihi' ?></div>
                    <div style="font-size:9px;color:var(--text-muted)">Batas <?= $batas ?>%</div>
                  </div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase">Platform</div>
                    <div style="font-weight:600;font-size:12px;margin-bottom:6px;word-break:break-word"><?= htmlspecialchars($sp['platform_mandiri'] ?: ($lang==='id'?'Tidak disebutkan':'Not specified')) ?></div>
                    <?php if ($sp['file_cek_mandiri']): ?>
                    <a href="../<?= htmlspecialchars($sp['file_cek_mandiri']) ?>" target="_blank"
                       class="btn btn-sm" style="background:var(--accent);color:#fff;border:none;font-size:11px;padding:4px 10px">
                      <?= ic('doc') ?> <?= $lang==='id'?'Bukti':'Proof' ?>
                    </a>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($sp['catatan_mandiri']): ?>
                <div style="font-size:11px;color:var(--text-secondary);background:#fff;padding:7px 10px;border-radius:6px;border:1px solid var(--purple-border);line-height:1.5">
                  <?= nl2br(htmlspecialchars($sp['catatan_mandiri'])) ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php else: ?>
            <div style="border-radius:10px;border:1.5px dashed var(--border-strong);padding:16px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--text-muted);font-size:12px;background:var(--bg-field);text-align:center;gap:6px">
              <?= ic('info', 'style="width:16px;height:16px;opacity:.5"') ?>
              <?= $lang==='id'?'Tidak ada data cek plagiasi mandiri':'No self-check plagiarism data' ?>
            </div>
            <?php endif; ?>

            <!-- KANAN: Cek AI Mandiri -->
            <?php if ($hasAiMandiri): ?>
            <div style="border-radius:10px;overflow:hidden;border:1.5px solid #ddd6fe">
              <div style="padding:8px 14px;background:linear-gradient(90deg,#4c1d95,#6d28d9);display:flex;align-items:center;gap:6px">
                <?= ic('search', 'style="width:13px;height:13px;color:#fff"') ?>
                <span style="color:#fff;font-weight:700;font-size:11px"><?= $lang==='id'?'Cek Deteksi AI Mandiri':'Self-Check: AI Detection' ?></span>
              </div>
              <div style="padding:12px;background:#faf5ff">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                  <div style="text-align:center;padding:8px 10px;background:#fff;border-radius:8px;border:1px solid #ddd6fe;flex-shrink:0;min-width:72px">
                    <div style="font-size:26px;font-weight:800;line-height:1;color:<?= $aimColor ?>"><?= $aim ?>%</div>
                    <div style="height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;margin:4px 0">
                      <div style="height:100%;width:<?= min($aim,100) ?>%;background:<?= $aimColor ?>;border-radius:2px"></div>
                    </div>
                    <div style="font-size:9px;font-weight:700;color:<?= $aimColor ?>"><?= $aimOk ? '✓ Lolos' : '✕ Melebihi' ?></div>
                    <div style="font-size:9px;color:var(--text-muted)">Batas <?= $batas_ai ?>%</div>
                  </div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase">Platform AI</div>
                    <div style="font-weight:600;font-size:12px;margin-bottom:6px;word-break:break-word"><?= htmlspecialchars($sp['platform_ai_mandiri'] ?: ($lang==='id'?'Tidak disebutkan':'Not specified')) ?></div>
                    <?php if ($sp['file_ai_mandiri']): ?>
                    <a href="../<?= htmlspecialchars($sp['file_ai_mandiri']) ?>" target="_blank"
                       class="btn btn-sm" style="background:#6d28d9;color:#fff;border:none;font-size:11px;padding:4px 10px">
                      <?= ic('doc') ?> <?= $lang==='id'?'Bukti AI':'AI Proof' ?>
                    </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php else: ?>
            <div style="border-radius:10px;border:1.5px dashed #ddd6fe;padding:16px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#a78bfa;font-size:12px;background:#faf5ff;text-align:center;gap:6px">
              <?= ic('info', 'style="width:16px;height:16px;opacity:.5"') ?>
              <?= $lang==='id'?'Tidak ada data cek AI mandiri':'No self-check AI detection data' ?>
            </div>
            <?php endif; ?>

          </div>
          <!-- Hint gabungan -->
          <div style="margin-bottom:20px;padding:8px 12px;background:#fff;border-left:3px solid var(--accent);border-radius:0 6px 6px 0;font-size:11px;color:var(--text-secondary)">
            <?= ic('info', 'style="width:12px;height:12px;color:var(--accent);vertical-align:middle"') ?>
            <?php if ($hasMandiri && $hasAiMandiri): ?>
              <?= $lang==='id'
                ? "Pengguna melaporkan plagiasi <strong>{$sm}%</strong> dan AI <strong>{$aim}%</strong>. Masukkan skor resmi Turnitin LPPM di bawah untuk verifikasi dan penerbitan surat."
                : "User reported plagiarism <strong>{$sm}%</strong> and AI <strong>{$aim}%</strong>. Enter official LPPM Turnitin scores below for verification." ?>
            <?php elseif ($hasMandiri): ?>
              <?= $lang==='id'
                ? "Pengguna melaporkan skor <strong>{$sm}%</strong>. Masukkan skor resmi Turnitin LPPM di bawah untuk verifikasi dan penerbitan surat."
                : "User reported a score of <strong>{$sm}%</strong>. Enter the official LPPM Turnitin score below to verify and issue the certificate." ?>
            <?php else: ?>
              <?= $lang==='id'
                ? "Pengguna melaporkan skor AI <strong>{$aim}%</strong>. Masukkan skor resmi deteksi AI di atas untuk verifikasi."
                : "User reported AI score of <strong>{$aim}%</strong>. Enter the official AI detection score above to verify." ?>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="margin-bottom:20px;padding:10px 14px;background:var(--bg-field);border:1px dashed var(--border-strong);border-radius:8px;font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:8px">
            <?= ic('info', 'style="width:13px;height:13px;flex-shrink:0"') ?>
            <?= $lang==='id'?'Pengguna tidak melampirkan hasil cek mandiri (plagiasi maupun AI).':'User did not attach any self-check results (plagiarism or AI).' ?>
          </div>
          <?php endif; ?>

          <!-- ── FORM INPUT TURNITIN RESMI ── -->
          <div style="padding:18px 20px;background:#fff;border:1.5px solid var(--primary-light);border-radius:10px">
            <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:14px;display:flex;align-items:center;gap:6px">
              <?= ic('edit', 'style="width:13px;height:13px"') ?>
              <?= $lang==='id'?'Input Skor Turnitin Resmi LPPM':'Enter Official LPPM Turnitin Score' ?>
            </div>

          <form method="POST">
            <input type="hidden" name="action" value="input_skor">
            <input type="hidden" name="skripsi_id" value="<?= $sp['id'] ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
              <!-- Similarity -->
              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Similarity Turnitin (%)':'Turnitin Similarity (%)' ?>
                  <span class="required">*</span>
                </label>
                <div style="position:relative">
                  <input type="number" name="similarity_score" id="skor_input"
                         class="form-control" step="0.01" min="0" max="100" required
                         style="font-size:20px;font-weight:700;padding-right:40px;text-align:center"
                         oninput="previewSkor(this.value)"
                         placeholder="0.00">
                  <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:15px;color:#94a3b8">%</span>
                </div>
                <div id="skor_preview" style="margin-top:8px;text-align:center;display:none">
                  <div id="skor_angka" style="font-size:36px;font-weight:800;line-height:1"></div>
                  <div id="skor_bar" style="height:7px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin:6px auto;max-width:220px">
                    <div id="skor_fill" style="height:100%;border-radius:4px;transition:width .5s"></div>
                  </div>
                  <div id="skor_status" style="font-size:11px;font-weight:700"></div>
                  <?php if ($hasMandiri): ?>
                  <div id="skor_compare" style="margin-top:4px;font-size:10px;color:var(--text-muted);display:none"></div>
                  <?php endif; ?>
                </div>
              </div>

              <!-- AI Detection -->
              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Skor Deteksi AI (%)':'AI Detection Score (%)' ?>
                  <span style="font-size:9px;color:var(--text-muted);font-weight:400;text-transform:none"> — <?= $lang==='id'?'opsional':'optional' ?></span>
                </label>
                <div style="position:relative">
                  <input type="number" name="ai_score" id="ai_input"
                         class="form-control" step="0.01" min="0" max="100"
                         style="font-size:20px;font-weight:700;padding-right:40px;text-align:center"
                         oninput="previewAI(this.value)"
                         placeholder="0.00">
                  <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:15px;color:#94a3b8">%</span>
                </div>
                <div id="ai_preview" style="margin-top:8px;text-align:center;display:none">
                  <div id="ai_angka" style="font-size:36px;font-weight:800;line-height:1"></div>
                  <div id="ai_bar" style="height:7px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin:6px auto;max-width:220px">
                    <div id="ai_fill" style="height:100%;border-radius:4px;transition:width .5s"></div>
                  </div>
                  <div id="ai_status" style="font-size:11px;font-weight:700"></div>
                  <?php if ($sp['ai_mandiri'] !== null): ?>
                  <div id="ai_compare" style="margin-top:4px;font-size:10px;color:var(--text-muted);display:none"></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Platform AI -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Platform Deteksi AI':'AI Detection Platform' ?>
                </label>
                <select name="platform_ai" class="form-control">
                  <option value=""><?= $lang==='id'?'-- Pilih platform --':'-- Select platform --' ?></option>
                  <?php foreach (['Turnitin AI Detection','GPTZero','Copyleaks AI Detector','Winston AI','Originality.ai','ZeroGPT','Quillbot AI Detector','Lainnya / Other'] as $p): ?>
                  <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Tanggal Cek':'Check Date' ?>
                  <span class="required">*</span>
                </label>
                <input type="date" name="tanggal_cek" class="form-control"
                       value="<?= date('Y-m-d') ?>" required>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Nama Penandatangan':'Signatory Name' ?>
                  <span class="required">*</span>
                </label>
                <input type="text" name="nama_penandatangan" class="form-control"
                       value="<?= htmlspecialchars($ketua??'') ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label">
                  <?= $lang==='id'?'Jabatan':'Position' ?>
                  <span class="required">*</span>
                </label>
                <input type="text" name="jabatan_penandatangan" class="form-control"
                       value="Ketua LPPM" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">
                <?= $lang==='id'?'Catatan (opsional)':'Notes (optional)' ?>
              </label>
              <textarea name="catatan" class="form-control" rows="2"
                placeholder="<?= $lang==='id'?'Catatan untuk mahasiswa jika perlu revisi...':'Notes for student if revision needed...' ?>"></textarea>
            </div>

            <div style="display:flex;gap:10px">
              <button type="submit" class="btn btn-primary">
                <?= ic('check-circle') ?> <?= $lang==='id'?'Simpan & Proses':'Save & Process' ?>
              </button>
              <a href="plagiasi.php" class="btn btn-outline">
                <?= $lang==='id'?'Batal':'Cancel' ?>
              </a>
            </div>
          </form>
          </div><!-- /form turnitin resmi -->

        </div>
      </div>
      <?php endif; ?>

      <!-- Stats & Filter Cards -->
      <div class="stats-grid" style="margin-bottom:20px">
        <?php
        $plCards = [
          ['menunggu', $lang==='id'?'Menunggu Review':'Pending Review', 'amber', 'clock',   $pl_stat_menunggu],
          ['selesai',  $lang==='id'?'Selesai / Lolos':'Passed',         'green', 'check',   $pl_stat_selesai],
          ['ditolak',  $lang==='id'?'Perlu Revisi':'Needs Revision',    'red',   'x',       $pl_stat_ditolak],
          ['semua',    $lang==='id'?'Semua Pengajuan':'All Submissions', 'navy',  'doc',     $pl_stat_semua],
        ];
        foreach ($plCards as [$key, $lbl, $color, $icon, $n]):
          $isActive = ($filter === $key);
        ?>
        <a href="plagiasi.php?filter=<?= $key ?>" class="stat-card"
           style="text-decoration:none<?= $isActive ? ';outline:2px solid var(--primary);outline-offset:1px' : '' ?>">
          <div class="stat-icon <?= $color ?>"><?= ic($icon) ?></div>
          <div>
            <div class="stat-label"><?= $lbl ?></div>
            <div class="stat-value"><?= $n ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter Pencarian -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="padding:14px 16px">
          <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">
                <?= $lang==='id'?'Cari nama / judul / NIM':'Search name / title / NIM' ?>
              </label>
              <input type="text" name="q" class="form-control" style="height:36px;font-size:13px"
                     value="<?= htmlspecialchars($filter_search) ?>"
                     placeholder="<?= $lang==='id'?'Cari...':'Search...' ?>">
            </div>
            <div style="min-width:160px">
              <label class="form-label" style="margin-bottom:4px;font-size:12px">Status</label>
              <select name="filter" class="form-control" style="height:36px;font-size:13px">
                <option value="semua"    <?= $filter==='semua'   ?'selected':'' ?>><?= $lang==='id'?'Semua Status':'All Status' ?></option>
                <option value="menunggu" <?= $filter==='menunggu'?'selected':'' ?>><?= $lang==='id'?'Menunggu':'Pending' ?></option>
                <option value="diproses" <?= $filter==='diproses'?'selected':'' ?>><?= $lang==='id'?'Diproses':'Processing' ?></option>
                <option value="selesai"  <?= $filter==='selesai' ?'selected':'' ?>><?= $lang==='id'?'Selesai':'Done' ?></option>
                <option value="ditolak"  <?= $filter==='ditolak' ?'selected':'' ?>><?= $lang==='id'?'Ditolak':'Rejected' ?></option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px">
              <?= ic('search') ?> <?= $lang==='id'?'Cari':'Search' ?>
            </button>
            <?php if ($filter_search || $filter !== 'menunggu'): ?>
              <a href="plagiasi.php" class="btn btn-outline btn-sm" style="height:36px">Reset</a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- Tabel -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">
            <?= ic('clipboard') ?> <?= $lang==='id'?'Daftar Pengajuan':'Application List' ?>
            (<?= count($list) ?>)
          </span>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($list)): ?>
            <div style="padding:32px;text-align:center;color:#94a3b8">
              <?= $lang==='id'?'Tidak ada data.':'No data found.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="resp-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th><?= $lang==='id'?'Mahasiswa':'Student' ?></th>
                  <th><?= $lang==='id'?'Judul Tugas Akhir':'Final Project Title' ?></th>
                  <th><?= $lang==='id'?'Mandiri':'Self-Check' ?></th>
                  <th><?= $lang==='id'?'Turnitin':'Turnitin' ?></th>
                  <th><?= $lang==='id'?'Deteksi AI':'AI Detection' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'No. Surat':'Letter No.' ?></th>
                  <th><?= $lang==='id'?'Tanggal':'Date' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($list as $i=>$r): ?>
                <tr>
                  <td class="td-no"><?= $i+1 ?></td>
                  <td class="td-nama">
                    <div class="td-nama-inner">
                      <div>
                        <span><?= htmlspecialchars($r['nama_lengkap']) ?></span>
                        <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:1px"><?= htmlspecialchars($r['nim']) ?></div>
                        <?php if ($r['fakultas']): ?>
                        <div style="font-size:10px;color:rgba(255,255,255,.45)"><?= htmlspecialchars(mb_strimwidth($r['fakultas'],0,22,'…')) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td data-label="<?= $lang==='id'?'Judul':'Title' ?>">
                    <div>
                      <?= htmlspecialchars(mb_strimwidth($r['judul_skripsi'],0,48,'…')) ?>
                      <br><a href="../<?= $r['file_path'] ?>" target="_blank" style="font-size:10px;color:var(--primary-mid);display:inline-flex;align-items:center;gap:2px;margin-top:3px"><?= ic('doc') ?> <?= $lang==='id'?'Lihat file':'View file' ?></a>
                    </div>
                  </td>
                  <td data-label="<?= $lang==='id'?'Mandiri':'Self-Check' ?>">
                    <?php if ($r['similarity_mandiri'] !== null): ?>
                      <?php $smOk = (float)$r['similarity_mandiri'] <= $batas; ?>
                      <div>
                        <span style="font-weight:700;font-size:14px;color:<?= $smOk?'#059669':'#dc2626' ?>"><?= $r['similarity_mandiri'] ?>%</span>
                        <?php if ($r['platform_mandiri']): ?>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['platform_mandiri']) ?></div>
                        <?php endif; ?>
                        <?php if ($r['file_cek_mandiri']): ?>
                        <a href="../<?= htmlspecialchars($r['file_cek_mandiri']) ?>" target="_blank"
                           style="font-size:10px;color:var(--accent);display:inline-flex;align-items:center;gap:2px"><?= ic('doc') ?> Bukti</a>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span style="color:#94a3b8;font-size:11px">—</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Turnitin">
                    <?php if ($r['similarity_score'] !== null): ?>
                      <?php $lppmOk = (float)$r['similarity_score'] <= $batas; ?>
                      <div>
                        <span style="font-weight:700;font-size:14px;color:<?= $lppmOk?'#059669':'#dc2626' ?>"><?= $r['similarity_score'] ?>%</span>
                        <?php if ($r['similarity_mandiri'] !== null): ?>
                          <?php $diff = round((float)$r['similarity_score'] - (float)$r['similarity_mandiri'], 2); ?>
                          <div style="font-size:10px;color:<?= abs($diff)<1?'#059669':($diff>0?'#d97706':'#2563eb') ?>">
                            <?= $diff > 0 ? "▲+{$diff}%" : ($diff < 0 ? "▼{$diff}%" : "≈") ?> vs mandiri
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span style="color:#94a3b8">—</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?= $lang==='id'?'Deteksi AI':'AI Detection' ?>">
                    <?php if ($r['ai_score'] !== null): ?>
                      <?php $aiOk = (float)$r['ai_score'] <= $batas_ai; ?>
                      <div>
                        <span style="font-weight:700;font-size:14px;color:<?= $aiOk?'#059669':'#dc2626' ?>"><?= $r['ai_score'] ?>%</span>
                        <?php if ($r['platform_ai']): ?>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['platform_ai']) ?></div>
                        <?php endif; ?>
                        <?php if ($r['ai_mandiri'] !== null): ?>
                          <?php $diffAi = round((float)$r['ai_score'] - (float)$r['ai_mandiri'], 2); ?>
                          <div style="font-size:10px;color:<?= abs($diffAi)<1?'#059669':($diffAi>0?'#d97706':'#2563eb') ?>">
                            <?= $diffAi > 0 ? "▲+{$diffAi}%" : ($diffAi < 0 ? "▼{$diffAi}%" : "≈") ?> vs mandiri
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span style="color:#94a3b8;font-size:11px">—</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="Status"><?= badgeStatus($r['status']) ?></td>
                  <td data-label="<?= $lang==='id'?'No. Surat':'Letter No.' ?>"><?= $r['nomor_surat'] ?? '—' ?></td>
                  <td data-label="<?= $lang==='id'?'Tanggal':'Date' ?>"><?= formatTanggal($r['created_at']) ?></td>
                  <td class="td-aksi">
                    <div class="aksi-wrap">
                      <?php if ($r['status'] === 'menunggu'): ?>
                        <a href="plagiasi.php?proses=<?= $r['id'] ?>" class="btn btn-primary btn-sm"><?= ic('play') ?> <?= $lang==='id'?'Proses':'Process' ?></a>
                      <?php else: ?>
                        <?php if ($r['nomor_surat']): ?>
                          <a href="../modules/plagiasi/unduh_surat.php?nomor=<?= urlencode($r['nomor_surat']) ?>" class="btn btn-success btn-sm"><?= ic('download') ?> Surat</a>
                        <?php endif; ?>
                        <?php if (in_array($r['status'], ['selesai','ditolak'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('<?= $lang==='id'?'Pindahkan ke Sampah? Data masih tersimpan 30 hari.':'Move to Trash? Data is kept for 30 days.' ?>')">
                          <input type="hidden" name="action" value="hapus_pengajuan">
                          <input type="hidden" name="id" value="<?= $r['id'] ?>">
                          <input type="hidden" name="filter_back" value="<?= htmlspecialchars($filter) ?>">
                          <button type="submit" class="btn btn-sm" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3" title="<?= $lang==='id'?'Pindah ke Sampah':'Move to Trash' ?>">
                            <?= ic('trash') ?>
                          </button>
                        </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function previewSkor(v) {
  const batas   = <?= $batas ?>;
  <?php if ($skripsi_proses && $skripsi_proses['similarity_mandiri'] !== null): ?>
  const mandiri = <?= (float)$skripsi_proses['similarity_mandiri'] ?>;
  <?php else: ?>
  const mandiri = null;
  <?php endif; ?>
  const preview = document.getElementById('skor_preview');
  const angka   = document.getElementById('skor_angka');
  const fill    = document.getElementById('skor_fill');
  const status  = document.getElementById('skor_status');
  const compare = document.getElementById('skor_compare');
  if (v === '') { preview.style.display='none'; return; }
  preview.style.display = 'block';
  const pct = Math.min(parseFloat(v) || 0, 100);
  const ok  = pct <= batas;
  angka.textContent = pct + '%';
  angka.style.color = ok ? '#059669' : '#dc2626';
  fill.style.width  = pct + '%';
  fill.style.background = ok ? '#059669' : '#dc2626';
  status.textContent = ok
    ? '✓ <?= $lang==="id"?"Lolos! Surat akan diterbitkan.":"Passed! Certificate will be issued." ?>'
    : '✕ <?= $lang==="id"?"Melebihi batas. Mahasiswa perlu revisi.":"Exceeds limit. Student must revise." ?>';
  status.style.color = ok ? '#059669' : '#dc2626';

  // Perbandingan dengan skor mandiri
  if (compare && mandiri !== null) {
    compare.style.display = 'block';
    const diff = (pct - mandiri).toFixed(2);
    const absDiff = Math.abs(diff);
    let msg = '', color = '#64748b';
    if (absDiff < 1) {
      msg   = '≈ <?= $lang==="id"?"Hampir sama dengan laporan mandiri":"Very close to self-reported score" ?> (' + mandiri + '%)';
      color = '#059669';
    } else if (diff > 0) {
      msg   = '▲ ' + diff + '% <?= $lang==="id"?"lebih tinggi dari mandiri":"higher than self-report" ?> (' + mandiri + '%)';
      color = '#d97706';
    } else {
      msg   = '▼ ' + absDiff + '% <?= $lang==="id"?"lebih rendah dari mandiri":"lower than self-report" ?> (' + mandiri + '%)';
      color = '#2563eb';
    }
    compare.textContent = msg;
    compare.style.color = color;
  }
}
function previewAI(v) {
  const batasAI = <?= $batas_ai ?>;
  <?php if ($skripsi_proses && $skripsi_proses['ai_mandiri'] !== null): ?>
  const mandiriAI = <?= (float)$skripsi_proses['ai_mandiri'] ?>;
  <?php else: ?>
  const mandiriAI = null;
  <?php endif; ?>
  const el     = document.getElementById('ai_preview');
  const angka  = document.getElementById('ai_angka');
  const fill   = document.getElementById('ai_fill');
  const status = document.getElementById('ai_status');
  const cmp    = document.getElementById('ai_compare');
  if (v === '') { el.style.display = 'none'; return; }
  el.style.display = 'block';
  const pct = Math.min(parseFloat(v) || 0, 100);
  const ok  = pct <= batasAI;
  angka.textContent = pct + '%';
  angka.style.color = ok ? '#059669' : '#dc2626';
  fill.style.width  = pct + '%';
  fill.style.background = ok ? '#059669' : '#dc2626';
  status.textContent = ok
    ? '✓ <?= $lang==="id"?"Lolos batas AI":"Below AI limit" ?> (≤<?= $batas_ai ?>%)'
    : '✕ <?= $lang==="id"?"Melebihi batas AI":"Exceeds AI limit" ?> (<?= $batas_ai ?>%)';
  status.style.color = ok ? '#059669' : '#dc2626';
  if (cmp && mandiriAI !== null) {
    cmp.style.display = 'block';
    const diff = (pct - mandiriAI).toFixed(2);
    const absDiff = Math.abs(diff);
    let msg, color;
    if (absDiff < 1)      { msg = '≈ <?= $lang==="id"?"Sama dengan mandiri":"Same as self-report" ?> (' + mandiriAI + '%)'; color = '#059669'; }
    else if (diff > 0)    { msg = '▲ ' + diff + '% <?= $lang==="id"?"lebih tinggi":"higher" ?> vs mandiri (' + mandiriAI + '%)'; color = '#d97706'; }
    else                  { msg = '▼ ' + absDiff + '% <?= $lang==="id"?"lebih rendah":"lower" ?> vs mandiri (' + mandiriAI + '%)'; color = '#2563eb'; }
    cmp.textContent = msg;
    cmp.style.color = color;
  }
}

function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
