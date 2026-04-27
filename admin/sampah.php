<?php
require_once '../includes/config.php';
require_once '../includes/logger.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';

// ── Auto-cleanup: hapus permanen item > 30 hari ──────────────────
foreach (['skripsi','publikasi','ethical_clearance','usulan_penelitian'] as $tbl) {
    $pdo->exec("DELETE FROM {$tbl} WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 30 DAY");
}

// ── POST handler ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    $tbl = $_POST['tbl'] ?? '';
    $allowed_tbl = ['skripsi','publikasi','ethical_clearance','usulan_penelitian'];

    if ($act !== 'kosongkan_sampah' && !in_array($tbl, $allowed_tbl)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Tabel tidak valid.'];
        redirect('/admin/sampah.php');
    }

    if ($act === 'restore' && $id) {
        $pdo->prepare("UPDATE {$tbl} SET deleted_at=NULL WHERE id=? AND deleted_at IS NOT NULL")
            ->execute([$id]);
        // Restore juga child rows usulan_penelitian (assignment, penilaian, kontrak, dll)
        if ($tbl === 'usulan_penelitian') {
            foreach (['reviewer_assignment','reviewer_penilaian','seleksi_admin','revisi_proposal','laporan_penelitian','kontrak_penelitian'] as $child) {
                try { $pdo->prepare("UPDATE {$child} SET deleted_at=NULL WHERE usulan_id=?")->execute([$id]); } catch (\Exception $e) {}
            }
        }
        writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_restore_pengajuan',
            "Pulihkan dari sampah: {$tbl} id={$id}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>$lang==='id'?'Pengajuan berhasil dipulihkan.':'Submission restored successfully.'];
    }

    if ($act === 'hapus_permanen' && $id) {
        // Ambil nama dulu untuk log
        $col = match($tbl) {
            'skripsi'           => 'judul_skripsi',
            'publikasi'         => 'judul_publikasi',
            'ethical_clearance' => 'judul_penelitian',
            'usulan_penelitian' => 'judul',
            default             => 'id',
        };
        $row = $pdo->prepare("SELECT {$col} AS judul FROM {$tbl} WHERE id=? AND deleted_at IS NOT NULL");
        $row->execute([$id]);
        $row = $row->fetch();
        if ($row) {
            // Hapus child rows (penelitian) — FK CASCADE handles users, but child tables tanpa FK perlu manual
            if ($tbl === 'usulan_penelitian') {
                foreach (['reviewer_penilaian','reviewer_assignment','seleksi_admin','revisi_proposal','laporan_penelitian','kontrak_penelitian'] as $child) {
                    try { $pdo->prepare("DELETE FROM {$child} WHERE usulan_id=?")->execute([$id]); } catch (\Exception $e) {}
                }
            }
            $pdo->prepare("DELETE FROM {$tbl} WHERE id=? AND deleted_at IS NOT NULL")->execute([$id]);
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_permanen',
                "Hapus permanen: {$tbl} id={$id} — " . mb_strimwidth($row['judul'] ?? '', 0, 60, '…'));
            $_SESSION['flash'] = ['type'=>'success','msg'=>$lang==='id'?'Pengajuan dihapus secara permanen.':'Submission permanently deleted.'];
        }
    }

    if ($act === 'kosongkan_sampah') {
        // Hapus semua sampah, plus child rows penelitian
        $up_ids = $pdo->query("SELECT id FROM usulan_penelitian WHERE deleted_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        if ($up_ids) {
            $ph = implode(',', array_fill(0, count($up_ids), '?'));
            foreach (['reviewer_penilaian','reviewer_assignment','seleksi_admin','revisi_proposal','laporan_penelitian','kontrak_penelitian'] as $child) {
                try { $pdo->prepare("DELETE FROM {$child} WHERE usulan_id IN ($ph)")->execute($up_ids); } catch (\Exception $e) {}
            }
        }
        foreach (['skripsi','publikasi','ethical_clearance','usulan_penelitian'] as $t) {
            $pdo->exec("DELETE FROM {$t} WHERE deleted_at IS NOT NULL");
        }
        writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_permanen', 'Kosongkan semua sampah');
        $_SESSION['flash'] = ['type'=>'success','msg'=>$lang==='id'?'Sampah berhasil dikosongkan.':'Trash emptied successfully.'];
    }

    redirect('/admin/sampah.php' . (isset($_POST['tab']) ? '?tab='.$_POST['tab'] : ''));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Tab aktif ──────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'semua';
$allowed_tabs = ['semua','plagiasi','publikasi','ec','penelitian'];
if (!in_array($tab, $allowed_tabs)) $tab = 'semua';

// ── Ambil data sampah ─────────────────────────────────────────────
$items_pl = $pdo->query("
    SELECT s.id, s.judul_skripsi AS judul, s.status, s.deleted_at,
           u.nama_lengkap, u.nim, u.program_studi,
           'skripsi' AS tbl, 'plagiasi' AS jenis
    FROM skripsi s JOIN users u ON s.user_id=u.id
    WHERE s.deleted_at IS NOT NULL
    ORDER BY s.deleted_at DESC
")->fetchAll();

$items_pb = $pdo->query("
    SELECT p.id, p.judul_publikasi AS judul, p.status, p.deleted_at,
           u.nama_lengkap, u.nim, u.program_studi,
           'publikasi' AS tbl, 'publikasi' AS jenis
    FROM publikasi p JOIN users u ON p.user_id=u.id
    WHERE p.deleted_at IS NOT NULL
    ORDER BY p.deleted_at DESC
")->fetchAll();

$items_ec = $pdo->query("
    SELECT ec.id, ec.judul_penelitian AS judul, ec.status, ec.deleted_at,
           u.nama_lengkap, COALESCE(u.nim, u.nidn, '—') AS nim, u.program_studi,
           'ethical_clearance' AS tbl, 'ec' AS jenis
    FROM ethical_clearance ec JOIN users u ON ec.user_id=u.id
    WHERE ec.deleted_at IS NOT NULL
    ORDER BY ec.deleted_at DESC
")->fetchAll();

$items_pen = $pdo->query("
    SELECT up.id, up.judul, up.status, up.deleted_at,
           u.nama_lengkap, COALESCE(u.nidn, u.nim, '—') AS nim, u.program_studi,
           'usulan_penelitian' AS tbl, 'penelitian' AS jenis
    FROM usulan_penelitian up JOIN users u ON up.user_id=u.id
    WHERE up.deleted_at IS NOT NULL
    ORDER BY up.deleted_at DESC
")->fetchAll();

$all_items = array_merge($items_pl, $items_pb, $items_ec, $items_pen);
usort($all_items, fn($a,$b) => strcmp($b['deleted_at'], $a['deleted_at']));

$display = match($tab) {
    'plagiasi'   => $items_pl,
    'publikasi'  => $items_pb,
    'ec'         => $items_ec,
    'penelitian' => $items_pen,
    default      => $all_items,
};

$total_sampah = count($all_items);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Sampah':'Trash' ?> — Admin LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── Tab bar ─────────────────────────────────────────────────── */
.trash-tabs {
  display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px;
}
.trash-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
  text-decoration: none; border: 1.5px solid var(--border);
  background: var(--bg-card); color: var(--text-secondary);
  transition: all .15s;
}
.trash-tab:hover { background: var(--bg-field); color: var(--text-primary); }
.trash-tab.active {
  background: #fff1f2; color: #e11d48; border-color: #fecdd3;
}
.tab-count {
  background: currentColor; color: #fff; border-radius: 10px;
  font-size: 10px; font-weight: 700; padding: 1px 6px; min-width: 18px;
  display: inline-flex; align-items: center; justify-content: center;
  opacity: .85;
}
/* ── Trash card ─────────────────────────────────────────────── */
.trash-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius-lg); padding: 14px 16px;
  display: flex; gap: 14px; align-items: flex-start;
  transition: box-shadow .15s;
}
.trash-card:hover { box-shadow: var(--shadow-sm); }
.trash-card + .trash-card { margin-top: 10px; }
.trash-type-badge {
  flex-shrink: 0; width: 38px; height: 38px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.trash-type-badge.pl { background: #fef3c7; color: #92400e; }
.trash-type-badge.pb { background: #dbeafe; color: #1d4ed8; }
.trash-type-badge.ec { background: #f0fdfa; color: #0d9488; }
.trash-title { font-size: 13px; font-weight: 600; color: var(--text-primary); line-height: 1.4; }
.trash-meta  { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
.trash-countdown {
  font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px;
  white-space: nowrap;
}
.countdown-urgent { background: #fee2e2; color: #b91c1c; }
.countdown-warn   { background: #fef9c3; color: #a16207; }
.countdown-ok     { background: #f0fdf4; color: #15803d; }
.trash-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

/* ── Responsive ─────────────────────────────────────────────── */
@media(max-width:640px) {
  .trash-card { flex-wrap: wrap; }
  .trash-actions { width: 100%; }
}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('trash') ?> <?= $lang==='id'?'Sampah':'Trash' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Pengajuan yang dihapus — tersimpan 30 hari':'Deleted submissions — kept for 30 days' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:16px"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <!-- Info banner -->
      <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-start">
        <?= ic('info','style="width:16px;height:16px;color:#d97706;flex-shrink:0;margin-top:1px"') ?>
        <div style="font-size:13px;color:#92400e;line-height:1.6">
          <?php if ($lang==='id'): ?>
          Pengajuan di sini akan <strong>dihapus permanen secara otomatis setelah 30 hari</strong> sejak dipindahkan ke sampah. Hanya pengajuan dengan status <em>Selesai / Diverifikasi / Disetujui</em> atau <em>Ditolak</em> yang dapat dihapus.
          <?php else: ?>
          Items here will be <strong>automatically deleted permanently after 30 days</strong> from when they were trashed. Only submissions with status <em>Done / Verified / Approved</em> or <em>Rejected</em> can be trashed.
          <?php endif; ?>
        </div>
      </div>

      <!-- Header dengan tombol Kosongkan -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <div style="font-size:14px;font-weight:600;color:var(--text-secondary)">
          <?= $total_sampah ?> <?= $lang==='id'?'item di sampah':'items in trash' ?>
        </div>
        <?php if ($total_sampah > 0): ?>
        <form method="POST" onsubmit="return confirm('<?= $lang==='id'?'Hapus semua item di sampah secara permanen? Tindakan ini tidak dapat dibatalkan.':'Permanently delete all items in trash? This cannot be undone.' ?>')">
          <input type="hidden" name="action" value="kosongkan_sampah">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
          <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;font-weight:600">
            <?= ic('trash') ?> <?= $lang==='id'?'Kosongkan Sampah':'Empty Trash' ?>
          </button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Tab bar -->
      <div class="trash-tabs">
        <?php
        $tabs = [
          ['semua',     $lang==='id'?'Semua':'All',                         count($all_items),  'doc'],
          ['plagiasi',  $lang==='id'?'Bebas Plagiasi':'Plagiarism-Free',    count($items_pl),   'search'],
          ['publikasi', $lang==='id'?'Surat Publikasi':'Publication Letter',count($items_pb),   'newspaper'],
          ['ec',        'Ethical Clearance',                                count($items_ec),   'clipboard'],
          ['penelitian',$lang==='id'?'Penelitian':'Research',               count($items_pen),  'award'],
        ];
        foreach ($tabs as [$key,$lbl,$cnt,$ico]):
        ?>
        <a href="?tab=<?= $key ?>" class="trash-tab <?= $tab===$key?'active':'' ?>">
          <?= ic($ico,'style="width:14px;height:14px"') ?>
          <?= $lbl ?>
          <?php if ($cnt > 0): ?><span class="tab-count"><?= $cnt ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- List items -->
      <?php if (empty($display)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"
               style="width:52px;height:52px;display:block;margin:0 auto 14px;opacity:.3"
               stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
            <path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
          </svg>
          <div style="font-size:14px;font-weight:600"><?= $lang==='id'?'Sampah kosong':'Trash is empty' ?></div>
          <div style="font-size:12px;margin-top:4px"><?= $lang==='id'?'Tidak ada pengajuan yang dihapus.':'No deleted submissions.' ?></div>
        </div>
      <?php else: ?>
        <?php foreach ($display as $item):
          $daysLeft = max(0, 30 - (int)floor((time() - strtotime($item['deleted_at'])) / 86400));
          $countdownClass = $daysLeft <= 3 ? 'countdown-urgent' : ($daysLeft <= 10 ? 'countdown-warn' : 'countdown-ok');
          $typeClass = match($item['jenis']) { 'plagiasi'=>'pl','publikasi'=>'pb','ec'=>'ec' };
          $typeIcon  = match($item['jenis']) { 'plagiasi'=>'search','publikasi'=>'newspaper','ec'=>'clipboard' };
          $typeLabel = match($item['jenis']) {
            'plagiasi'  => $lang==='id'?'Bebas Plagiasi':'Plagiarism-Free',
            'publikasi' => $lang==='id'?'Surat Publikasi':'Publication Letter',
            'ec'        => 'Ethical Clearance',
          };
          $statusBadge = match($item['status']) {
            'selesai'      => '<span class="badge badge-success" style="font-size:10px">'.($lang==='id'?'Selesai':'Done').'</span>',
            'diverifikasi' => '<span class="badge badge-success" style="font-size:10px">'.($lang==='id'?'Diverifikasi':'Verified').'</span>',
            'disetujui'    => '<span class="badge badge-success" style="font-size:10px">'.($lang==='id'?'Disetujui':'Approved').'</span>',
            'ditolak'      => '<span class="badge badge-danger"  style="font-size:10px">'.($lang==='id'?'Ditolak':'Rejected').'</span>',
            default        => '<span class="badge" style="font-size:10px">'.htmlspecialchars($item['status']).'</span>',
          };
        ?>
        <div class="trash-card">
          <!-- Ikon jenis -->
          <div class="trash-type-badge <?= $typeClass ?>">
            <?= ic($typeIcon,'style="width:16px;height:16px"') ?>
          </div>

          <!-- Info -->
          <div style="flex:1;min-width:0">
            <div class="trash-title"><?= htmlspecialchars(mb_strimwidth($item['judul'], 0, 90, '…')) ?></div>
            <div class="trash-meta">
              <strong><?= htmlspecialchars($item['nama_lengkap']) ?></strong>
              · <?= htmlspecialchars($item['nim']) ?>
              · <?= htmlspecialchars($item['program_studi'] ?? '—') ?>
              · <span style="color:#64748b"><?= $typeLabel ?></span>
              · <?= $statusBadge ?>
            </div>
            <div class="trash-meta" style="margin-top:4px">
              <?= $lang==='id'?'Dihapus':'Deleted' ?>: <?= date('d/m/Y H:i', strtotime($item['deleted_at'])) ?>
            </div>
          </div>

          <!-- Countdown + Aksi -->
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0">
            <span class="trash-countdown <?= $countdownClass ?>">
              <?php if ($daysLeft === 0): ?>
                <?= $lang==='id'?'Terhapus hari ini':'Deleted today' ?>
              <?php else: ?>
                <?= $daysLeft ?> <?= $lang==='id'?'hari lagi':'days left' ?>
              <?php endif; ?>
            </span>
            <div class="trash-actions">
              <!-- Pulihkan -->
              <form method="POST">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id"  value="<?= $item['id'] ?>">
                <input type="hidden" name="tbl" value="<?= $item['tbl'] ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <button type="submit" class="btn btn-sm btn-outline" title="<?= $lang==='id'?'Pulihkan':'Restore' ?>">
                  <?= ic('rotate-ccw','style="width:13px;height:13px"') ?>
                  <?= $lang==='id'?'Pulihkan':'Restore' ?>
                </button>
              </form>
              <!-- Hapus Permanen -->
              <form method="POST" onsubmit="return confirm('<?= $lang==='id'?'Hapus permanen? Tidak dapat dibatalkan.':'Delete permanently? This cannot be undone.' ?>')">
                <input type="hidden" name="action" value="hapus_permanen">
                <input type="hidden" name="id"  value="<?= $item['id'] ?>">
                <input type="hidden" name="tbl" value="<?= $item['tbl'] ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5">
                  <?= ic('trash','style="width:13px;height:13px"') ?>
                  <?= $lang==='id'?'Hapus Permanen':'Delete Permanently' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->

<script>
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
