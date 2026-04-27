<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id = $lang === 'id';

// Daftar kueri optimasi indeks (Composite Indexes)
$indexes = [
    "ALTER TABLE `pesan` ADD INDEX `idx_pesan_polling` (`user_id`, `id`)",
    "ALTER TABLE `pesan` ADD INDEX `idx_pesan_unread` (`user_id`, `pengirim_role`, `dibaca`)",
    "ALTER TABLE `notifikasi` ADD INDEX `idx_notif_unread` (`user_id`, `is_read`, `created_at`)",
    "ALTER TABLE `usulan_penelitian` ADD INDEX `idx_up_dashboard` (`tahun_anggaran`, `status`, `deleted_at`)",
    "ALTER TABLE `usulan_penelitian` ADD INDEX `idx_up_skema` (`skema`, `tahun_anggaran`)",
    "ALTER TABLE `usulan_penelitian` ADD INDEX `idx_up_user` (`user_id`, `deleted_at`)",
    "ALTER TABLE `usulan_penelitian` ADD FULLTEXT INDEX `ft_up_judul_abstrak` (`judul`, `abstrak`)",
    "ALTER TABLE `skripsi` ADD INDEX `idx_skripsi_user_status` (`user_id`, `status`, `deleted_at`)",
    "ALTER TABLE `publikasi` ADD INDEX `idx_pub_user_status` (`user_id`, `status`, `deleted_at`)",
    "ALTER TABLE `ethical_clearance` ADD INDEX `idx_ec_user_status` (`user_id`, `status`, `deleted_at`)",
    "ALTER TABLE `reviewer_assignment` ADD INDEX `idx_ra_reviewer` (`reviewer_id`, `deleted_at`)",
    "ALTER TABLE `reviewer_assignment` ADD INDEX `idx_ra_usulan` (`usulan_id`, `deleted_at`)",
    "ALTER TABLE `reviewer_penilaian` ADD INDEX `idx_rp_assignment` (`assignment_id`)",
    "ALTER TABLE `users` ADD INDEX `idx_user_login` (`email`, `is_active`)",
    "ALTER TABLE `users` ADD INDEX `idx_user_role` (`role`, `created_at`)",
];

$results = [];
$isExecuted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['optimize'])) {
    $isExecuted = true;
    foreach ($indexes as $sql) {
        try {
            $pdo->exec($sql);
            $results[] = ['sql' => $sql, 'status' => 'success', 'msg' => $id ? 'Berhasil ditambahkan' : 'Successfully added'];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $results[] = ['sql' => $sql, 'status' => 'info', 'msg' => $id ? 'Sudah ada (Skipped)' : 'Already exists (Skipped)'];
            } else {
                $results[] = ['sql' => $sql, 'status' => 'error', 'msg' => $e->getMessage()];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id ? 'Optimasi Database' : 'Database Optimization' ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
.opt-card { background:var(--bg-card); border:1.5px solid var(--border); border-radius:12px; padding:24px; margin-bottom:20px; }
.sql-code { background:#1e293b; color:#38bdf8; padding:8px 12px; border-radius:6px; font-family:monospace; font-size:11px; display:block; margin-bottom:4px; overflow-x:auto; }
.res-success { color:#16a34a; font-weight:700; font-size:12px; display:flex; align-items:center; gap:5px; }
.res-info { color:#ca8a04; font-weight:700; font-size:12px; display:flex; align-items:center; gap:5px; }
.res-error { color:#dc2626; font-weight:700; font-size:12px; display:flex; align-items:center; gap:5px; }
.opt-list { display:flex; flex-direction:column; gap:12px; margin-top:20px; }
.opt-item { border:1px solid var(--border); padding:12px; border-radius:8px; background:#f8fafc; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('settings') ?> <?= $id ? 'Optimasi Database (INDEX)' : 'Database Optimization (INDEX)' ?>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">
      <div class="opt-card">
        <div style="display:flex; gap:14px; align-items:flex-start;">
          <div style="width:48px;height:48px;background:#e0e7ff;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#4338ca;flex-shrink:0">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
          </div>
          <div>
            <h2 style="font-size:16px;font-weight:800;color:#1e3a8a;margin-bottom:6px">
              <?= $id ? 'Alat Optimasi Kueri (Database Indexing)' : 'Query Optimization Tool (Database Indexing)' ?>
            </h2>
            <p style="font-size:13px;color:#475569;line-height:1.6">
              <?= $id
                ? 'Alat ini akan secara otomatis menambahkan struktur <strong>Composite INDEX</strong> ke dalam tabel-tabel utama. Ini akan mencegah beban <em style="color:#ef4444">Full Table Scan</em> dan mempercepat waktu muat (load) server secara drastis saat data sudah mencapai ribuan baris.'
                : 'This tool will automatically add <strong>Composite INDEXES</strong> to the main tables. This prevents heavy <em style="color:#ef4444">Full Table Scans</em> and drastically speeds up server load times when data reaches thousands of rows.'
              ?>
            </p>
            <?php if (!$isExecuted): ?>
            <form method="POST" style="margin-top:16px">
              <button type="submit" name="optimize" value="1" class="btn btn-primary btn-lg" style="background:linear-gradient(135deg,#4f46e5,#4338ca);border:none;box-shadow:0 4px 14px rgba(67,56,202,.3)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                <?= $id ? 'Jalankan Optimasi Sekarang' : 'Run Optimization Now' ?>
              </button>
            </form>
            <?php else: ?>
            <a href="dashboard.php" class="btn btn-outline" style="margin-top:16px">
              ← <?= $id ? 'Kembali ke Dashboard' : 'Back to Dashboard' ?>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($isExecuted && !empty($results)): ?>
      <h3 style="font-size:14px;font-weight:700;color:#334155;margin-bottom:12px"><?= $id ? 'Hasil Eksekusi:' : 'Execution Results:' ?></h3>
      <div class="opt-list">
        <?php foreach ($results as $res): ?>
        <div class="opt-item">
          <code class="sql-code"><?= htmlspecialchars($res['sql']) ?></code>
          <?php if ($res['status'] === 'success'): ?>
            <div class="res-success">✓ <?= $res['msg'] ?></div>
          <?php elseif ($res['status'] === 'info'): ?>
            <div class="res-info">ℹ <?= $res['msg'] ?></div>
          <?php else: ?>
            <div class="res-error">✗ Error: <?= htmlspecialchars($res['msg']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>