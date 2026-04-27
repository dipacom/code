<?php
require_once '../includes/config.php';
require_once '../includes/logger.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';

// ── Hapus semua log (dengan konfirmasi via POST) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_semua') {
    $pdo->exec("TRUNCATE TABLE activity_log");
    writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_log',
        $lang==='id' ? 'Semua log aktivitas dihapus.' : 'All activity logs cleared.');
    $_SESSION['flash'] = ['type'=>'warning',
        'msg' => $lang==='id' ? 'Semua log aktivitas telah dihapus.' : 'All activity logs cleared.'];
    redirect('/admin/log.php');
}

// ── Filter ────────────────────────────────────────────────────
$f_action = clean($_GET['action_type'] ?? '');
$f_role   = clean($_GET['role'] ?? '');
$f_q      = clean($_GET['q'] ?? '');
$f_date1  = clean($_GET['d1'] ?? '');
$f_date2  = clean($_GET['d2'] ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$per      = 40;
$offset   = ($page - 1) * $per;

$where  = ['1=1'];
$bind   = [];

if ($f_action) { $where[] = 'action_type = ?'; $bind[] = $f_action; }
if ($f_role)   { $where[] = 'role = ?';         $bind[] = $f_role; }
if ($f_q) {
    $where[] = '(nama_lengkap LIKE ? OR detail LIKE ? OR ip_address LIKE ?)';
    $bind[]  = "%$f_q%"; $bind[] = "%$f_q%"; $bind[] = "%$f_q%";
}
if ($f_date1)  { $where[] = 'DATE(created_at) >= ?'; $bind[] = $f_date1; }
if ($f_date2)  { $where[] = 'DATE(created_at) <= ?'; $bind[] = $f_date2; }

$wStr = implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE $wStr");
$totalStmt->execute($bind);
$total = (int)$totalStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE $wStr ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($bind);
$logs = $stmt->fetchAll();

// Daftar action_type unik untuk filter dropdown
$actionTypes = $pdo->query("SELECT DISTINCT action_type FROM activity_log ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $allStmt = $pdo->prepare("SELECT * FROM activity_log WHERE $wStr ORDER BY created_at DESC");
    $allStmt->execute($bind);
    $allRows = $allStmt->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="log_aktivitas_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    fputcsv($out, ['ID','Waktu','Nama','Role','Aksi','Detail','IP'], ';');
    foreach ($allRows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            $r['nama_lengkap'] ?? '-',
            $r['role'],
            logActionLabel($r['action_type'], 'id'),
            $r['detail'] ?? '',
            $r['ip_address'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Helper: URL filter ─────────────────────────────────────────
function logUrl(array $extra = []): string {
    $params = array_filter(array_merge([
        'action_type' => $_GET['action_type'] ?? '',
        'role'        => $_GET['role'] ?? '',
        'q'           => $_GET['q'] ?? '',
        'd1'          => $_GET['d1'] ?? '',
        'd2'          => $_GET['d2'] ?? '',
        'p'           => $_GET['p'] ?? '1',
    ], $extra), fn($v) => $v !== '');
    return 'log.php?' . http_build_query($params);
}

// ── Badge warna ────────────────────────────────────────────────
$COLOR = [
    'blue'   => ['bg'=>'#dbeafe','color'=>'#1d4ed8'],
    'gray'   => ['bg'=>'#f1f5f9','color'=>'#475569'],
    'purple' => ['bg'=>'#f3e8ff','color'=>'#7c3aed'],
    'green'  => ['bg'=>'#dcfce7','color'=>'#16a34a'],
    'red'    => ['bg'=>'#fee2e2','color'=>'#dc2626'],
    'indigo' => ['bg'=>'#e0e7ff','color'=>'#4338ca'],
    'amber'  => ['bg'=>'#fef3c7','color'=>'#b45309'],
];
function actionBadge(string $action, string $lang, array $COLOR): string {
    $c = $COLOR[logActionColor($action)] ?? $COLOR['gray'];
    $lbl = logActionLabel($action, $lang);
    return "<span style=\"background:{$c['bg']};color:{$c['color']};font-size:11px;font-weight:600;
            padding:2px 8px;border-radius:12px;white-space:nowrap\">{$lbl}</span>";
}
function roleBadge(string $role): string {
    $map = ['admin'=>['#fef3c7','#92400e'],'mahasiswa'=>['#eff6ff','#1d4ed8'],'dosen'=>['#f0fdf4','#16a34a'],'system'=>['#f1f5f9','#64748b']];
    [$bg,$cl] = $map[$role] ?? ['#f1f5f9','#64748b'];
    return "<span style=\"background:{$bg};color:{$cl};font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;text-transform:uppercase\">$role</span>";
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Log Aktivitas':'Activity Log' ?> — LPPM Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
.log-filter-bar{
  display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;
  background:var(--bg-card);border:1px solid var(--border);
  border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:18px
}
.log-filter-bar label{font-size:11px;font-weight:700;color:var(--text-muted);
  display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.log-filter-bar .fc{min-width:130px}
.log-filter-bar .fc-wide{min-width:200px}
.log-stat-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:var(--primary-xlight);color:var(--primary);
  font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px
}
.empty-state{padding:40px;text-align:center;color:var(--text-muted)}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('shield') ?>
          <?= $lang==='id'?'Log Aktivitas':'Activity Log' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Histori seluruh kegiatan sistem':'Full system activity history' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:16px"><?= $flash['msg'] ?></div>
      <?php endif; ?>

      <!-- ── Filter bar ────────────────────────────────────── -->
      <form method="GET" action="log.php" class="log-filter-bar">
        <div class="fc fc-wide">
          <label><?= $lang==='id'?'Cari Nama / Detail / IP':'Search Name / Detail / IP' ?></label>
          <div style="position:relative">
            <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>"
                   class="form-control" style="padding-left:30px;height:34px;font-size:12px"
                   placeholder="<?= $lang==='id'?'nama, kata kunci…':'name, keyword…' ?>">
            <span style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><?= ic('search','style="width:12px;height:12px"') ?></span>
          </div>
        </div>
        <div class="fc">
          <label><?= $lang==='id'?'Jenis Aksi':'Action Type' ?></label>
          <select name="action_type" class="form-control" style="height:34px;font-size:12px">
            <option value=""><?= $lang==='id'?'— Semua —':'— All —' ?></option>
            <?php foreach ($actionTypes as $at): ?>
            <option value="<?= htmlspecialchars($at) ?>" <?= $f_action===$at?'selected':'' ?>>
              <?= htmlspecialchars(logActionLabel($at, $lang)) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fc">
          <label>Role</label>
          <select name="role" class="form-control" style="height:34px;font-size:12px">
            <option value=""><?= $lang==='id'?'— Semua —':'— All —' ?></option>
            <option value="admin"     <?= $f_role==='admin'    ?'selected':'' ?>>Admin</option>
            <option value="mahasiswa" <?= $f_role==='mahasiswa'?'selected':'' ?>>Mahasiswa</option>
            <option value="dosen"     <?= $f_role==='dosen'    ?'selected':'' ?>>Dosen</option>
            <option value="system"    <?= $f_role==='system'   ?'selected':'' ?>>System</option>
          </select>
        </div>
        <div class="fc">
          <label><?= $lang==='id'?'Dari Tanggal':'From Date' ?></label>
          <input type="date" name="d1" value="<?= htmlspecialchars($f_date1) ?>"
                 class="form-control" style="height:34px;font-size:12px">
        </div>
        <div class="fc">
          <label><?= $lang==='id'?'Sampai Tanggal':'To Date' ?></label>
          <input type="date" name="d2" value="<?= htmlspecialchars($f_date2) ?>"
                 class="form-control" style="height:34px;font-size:12px">
        </div>
        <div style="display:flex;gap:8px;align-self:flex-end">
          <button type="submit" class="btn btn-primary btn-sm"><?= ic('search') ?> <?= $lang==='id'?'Filter':'Filter' ?></button>
          <a href="log.php" class="btn btn-outline btn-sm"><?= ic('x') ?></a>
        </div>
      </form>

      <!-- ── Toolbar: stats + export + hapus ──────────────── -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <span class="log-stat-badge">
          <?= ic('doc','style="width:13px;height:13px"') ?>
          <?= number_format($total) ?> <?= $lang==='id'?'entri':'entries' ?>
        </span>
        <?php if ($f_action || $f_role || $f_q || $f_date1 || $f_date2): ?>
        <span style="font-size:12px;color:var(--text-muted)">(<?= $lang==='id'?'hasil filter':'filtered' ?>)</span>
        <?php endif; ?>

        <div style="margin-left:auto;display:flex;gap:8px">
          <!-- Export CSV -->
          <a href="<?= htmlspecialchars(logUrl(['export'=>'csv','p'=>''])) ?>"
             class="btn btn-outline btn-sm">
            <?= ic('download') ?> <?= $lang==='id'?'Ekspor CSV':'Export CSV' ?>
          </a>
          <!-- Hapus semua log -->
          <button onclick="confirmHapus()" class="btn btn-sm"
                  style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;font-weight:600">
            <?= ic('trash') ?> <?= $lang==='id'?'Hapus Semua Log':'Clear All Logs' ?>
          </button>
        </div>
      </div>

      <!-- ── Tabel log ─────────────────────────────────────── -->
      <div class="card">
        <div class="card-body" style="padding:0">
          <?php if (empty($logs)): ?>
            <div class="empty-state">
              <?= ic('inbox','style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id'?'Belum ada catatan aktivitas.':'No activity records found.' ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:150px"><?= $lang==='id'?'Waktu':'Time' ?></th>
                  <th><?= $lang==='id'?'Pengguna':'User' ?></th>
                  <th style="width:90px">Role</th>
                  <th><?= $lang==='id'?'Aktivitas':'Activity' ?></th>
                  <th><?= $lang==='id'?'Detail':'Detail' ?></th>
                  <th style="width:130px">IP</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($logs as $r):
                $dt = date('d/m/Y H:i:s', strtotime($r['created_at']));
              ?>
              <tr>
                <td style="font-size:11px;color:var(--text-muted);white-space:nowrap"><?= $dt ?></td>
                <td>
                  <?php if ($r['nama_lengkap']): ?>
                    <div style="font-weight:600;font-size:12px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted);font-style:italic">—</span>
                  <?php endif; ?>
                </td>
                <td><?= roleBadge($r['role']) ?></td>
                <td><?= actionBadge($r['action_type'], $lang, $COLOR) ?></td>
                <td style="font-size:12px;color:var(--text-secondary);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= htmlspecialchars($r['detail']??'') ?>">
                  <?= htmlspecialchars(mb_strimwidth($r['detail']??'', 0, 80, '…')) ?>
                </td>
                <td style="font-size:11px;color:var(--text-muted);font-family:monospace">
                  <?= htmlspecialchars($r['ip_address']??'-') ?>
                </td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($pages > 1): ?>
          <div style="padding:12px 16px;display:flex;align-items:center;gap:6px;border-top:1px solid var(--border);flex-wrap:wrap">
            <?php
            $pStart = max(1, $page-3);
            $pEnd   = min($pages, $page+3);
            if ($page > 1):
            ?>
            <a href="<?= htmlspecialchars(logUrl(['p'=>$page-1])) ?>"
               style="padding:4px 10px;border-radius:6px;font-size:12px;background:var(--bg-field);color:var(--text-secondary);text-decoration:none">‹</a>
            <?php endif; ?>
            <?php for ($pg = $pStart; $pg <= $pEnd; $pg++): ?>
            <a href="<?= htmlspecialchars(logUrl(['p'=>$pg])) ?>"
               style="width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;text-decoration:none;
                      <?= $pg===$page ? 'background:var(--primary);color:#fff' : 'background:var(--bg-field);color:var(--text-secondary)' ?>">
              <?= $pg ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="<?= htmlspecialchars(logUrl(['p'=>$page+1])) ?>"
               style="padding:4px 10px;border-radius:6px;font-size:12px;background:var(--bg-field);color:var(--text-secondary);text-decoration:none">›</a>
            <?php endif; ?>
            <span style="font-size:11px;color:var(--text-muted);margin-left:8px">
              <?= $lang==='id' ? "Halaman {$page} dari {$pages}" : "Page {$page} of {$pages}" ?>
            </span>
          </div>
          <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal konfirmasi hapus semua -->
<div id="modal-hapus" style="display:none;position:fixed;inset:0;z-index:9900;
     background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <div style="width:40px;height:40px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <?= ic('trash','style="width:18px;height:18px;color:#dc2626"') ?>
      </div>
      <div>
        <div style="font-weight:700;font-size:15px;color:#1e293b"><?= $lang==='id'?'Hapus Semua Log?':'Clear All Logs?' ?></div>
        <div style="font-size:12px;color:#64748b;margin-top:2px"><?= $lang==='id'?'Tindakan ini tidak dapat dibatalkan.':'This action cannot be undone.' ?></div>
      </div>
    </div>
    <p style="font-size:13px;color:#475569;margin-bottom:20px;line-height:1.6">
      <?= $lang==='id'
        ? 'Seluruh riwayat log aktivitas akan dihapus permanen dari database. Hanya satu entri log baru yang akan dicatat (aksi penghapusan ini sendiri).'
        : 'All activity log records will be permanently deleted from the database. Only one new log entry will be recorded (this deletion action itself).' ?>
    </p>
    <div style="display:flex;gap:10px">
      <form method="POST" action="log.php" style="flex:1">
        <input type="hidden" name="action" value="hapus_semua">
        <button type="submit" class="btn btn-sm"
                style="width:100%;justify-content:center;background:#dc2626;color:#fff;border:none;font-weight:600">
          <?= ic('trash') ?> <?= $lang==='id'?'Ya, Hapus Semua':'Yes, Clear All' ?>
        </button>
      </form>
      <button onclick="document.getElementById('modal-hapus').style.display='none'"
              class="btn btn-outline btn-sm" style="flex:1;justify-content:center">
        <?= $lang==='id'?'Batal':'Cancel' ?>
      </button>
    </div>
  </div>
</div>

<script>
function toggleLang(){
  const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';
  document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}
function confirmHapus(){
  const m = document.getElementById('modal-hapus');
  m.style.display = 'flex';
}
document.getElementById('modal-hapus').addEventListener('click', function(e){
  if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.getElementById('modal-hapus').style.display = 'none';
});
</script>
</body>
</html>
