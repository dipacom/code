<?php
require_once '../includes/config.php';
// Hanya admin utama (bukan delegate) yang dapat mengelola delegasi
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $scope   = clean($_POST['scope'] ?? 'penuh');
        $catatan = clean($_POST['catatan'] ?? '');
        $valid_scope = ['penuh', 'seleksi_admin', 'penunjukan_reviewer'];
        if (!in_array($scope, $valid_scope)) $scope = 'penuh';

        if (!$user_id) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Pilih pengguna.':'Select a user.'];
            redirect('/admin/delegasi.php');
        }

        // Cegah delegasi ke admin utama (redundant)
        $u = $pdo->prepare("SELECT role, nama_lengkap FROM users WHERE id=? AND deleted_at IS NULL");
        $u->execute([$user_id]);
        $user = $u->fetch();
        if (!$user) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'User tidak ditemukan.'];
            redirect('/admin/delegasi.php');
        }
        if ($user['role'] === 'admin') {
            $_SESSION['flash'] = ['type'=>'warning','msg'=>$id?'User tersebut sudah admin utama, tidak perlu delegasi.':'User is already main admin.'];
            redirect('/admin/delegasi.php');
        }

        // Cegah duplikasi delegasi aktif
        $dup = $pdo->prepare("SELECT id FROM admin_delegasi WHERE user_id=? AND revoked_at IS NULL");
        $dup->execute([$user_id]);
        if ($dup->fetchColumn()) {
            $_SESSION['flash'] = ['type'=>'warning','msg'=>$id?'Pengguna sudah memiliki delegasi aktif. Cabut dulu sebelum mengubah scope.':'User already has active delegation. Revoke first.'];
            redirect('/admin/delegasi.php');
        }

        $pdo->prepare("
            INSERT INTO admin_delegasi (user_id, scope, catatan, granted_by)
            VALUES (?, ?, ?, ?)
        ")->execute([$user_id, $scope, $catatan ?: null, $_SESSION['user_id']]);

        // Notifikasi ke user yang didelegasikan
        $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
            ->execute([$user_id,
                $id?'Anda Ditunjuk sebagai Admin LPPM':'Designated as LPPM Admin',
                ($id?'Anda diberikan akses sebagai admin (scope: ':'You have been granted admin access (scope: ').$scope.') '.
                ($id?'pada sistem LPPM. Akses berlaku hingga dicabut oleh admin utama.':'in the LPPM system. Access valid until revoked by main admin.'),
                'sukses']);

        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Delegasi berhasil diberikan ke '.$user['nama_lengkap']:'Delegation granted to '.$user['nama_lengkap']];
        redirect('/admin/delegasi.php');
    }

    if ($action === 'revoke') {
        $delegasi_id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare("SELECT user_id FROM admin_delegasi WHERE id=? AND revoked_at IS NULL");
        $row->execute([$delegasi_id]);
        $r = $row->fetch();
        if ($r) {
            $pdo->prepare("UPDATE admin_delegasi SET revoked_at=NOW(), revoked_by=? WHERE id=?")
                ->execute([$_SESSION['user_id'], $delegasi_id]);
            $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
                ->execute([$r['user_id'],
                    $id?'Akses Admin Dicabut':'Admin Access Revoked',
                    $id?'Akses admin Anda telah dicabut oleh admin utama LPPM.':'Your admin access has been revoked by the main LPPM admin.',
                    'peringatan']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Delegasi dicabut.':'Delegation revoked.'];
        }
        redirect('/admin/delegasi.php');
    }
}

// Daftar delegasi aktif + history
$active = $pdo->query("
    SELECT d.*, u.nama_lengkap, u.email, u.role, u.foto_profil,
           gb.nama_lengkap AS granted_by_name
    FROM admin_delegasi d
    JOIN users u ON u.id = d.user_id
    LEFT JOIN users gb ON gb.id = d.granted_by
    WHERE d.revoked_at IS NULL
    ORDER BY d.granted_at DESC
")->fetchAll();

$history = $pdo->query("
    SELECT d.*, u.nama_lengkap, u.email,
           gb.nama_lengkap AS granted_by_name,
           rb.nama_lengkap AS revoked_by_name
    FROM admin_delegasi d
    JOIN users u ON u.id = d.user_id
    LEFT JOIN users gb ON gb.id = d.granted_by
    LEFT JOIN users rb ON rb.id = d.revoked_by
    WHERE d.revoked_at IS NOT NULL
    ORDER BY d.revoked_at DESC
    LIMIT 30
")->fetchAll();

// Pengguna yang dapat didelegasikan: dosen + reviewer (bukan admin, bukan mahasiswa)
$candidates = $pdo->query("
    SELECT id, nama_lengkap, email, role
    FROM users
    WHERE role IN ('dosen','reviewer')
      AND deleted_at IS NULL AND is_active = 1
      AND id NOT IN (SELECT user_id FROM admin_delegasi WHERE revoked_at IS NULL)
    ORDER BY role ASC, nama_lengkap ASC
")->fetchAll();

$scopeLabel = fn($s) => match($s) {
    'penuh'               => $id?'Akses Penuh (semua menu admin)':'Full Access (all admin menus)',
    'seleksi_admin'       => $id?'Hanya Seleksi Administratif':'Admin Selection Only',
    'penunjukan_reviewer' => $id?'Hanya Penunjukan Reviewer':'Reviewer Assignment Only',
    default               => $s,
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Delegasi Admin':'Admin Delegation' ?> — LPPM</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.del-card { background:var(--bg-card); border:1.5px solid var(--border); border-radius:12px;
  padding:14px 16px; margin-bottom:10px; display:flex; gap:13px; align-items:flex-start; }
.del-av { width:42px; height:42px; border-radius:50%;
  background:linear-gradient(135deg,#1e3a8a,#3b82f6); color:#fff;
  display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px; flex-shrink:0; overflow:hidden; }
.scope-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 9px;
  border-radius:9px; font-size:11px; font-weight:700;
  background:#e0e7ff; color:#3730a3; }
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
          <?= $id?'Delegasi Admin LPPM':'LPPM Admin Delegation' ?>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:14px">
        <?= htmlspecialchars($fl['msg']) ?>
      </div>
      <?php endif; ?>

      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:12.5px;color:#1e40af">
        <?= ic('info','style="width:15px;height:15px"') ?>
        <span style="margin-left:5px"><?= $id
          ? 'Delegasi memberikan akses sebagaimana admin LPPM kepada pengguna lain (dosen/reviewer) untuk membantu pemeriksaan usulan penelitian. Akses berlaku hingga Anda mencabutnya. Hanya admin utama yang bisa mengelola halaman ini.'
          : 'Delegation grants admin-level access to other users (lecturers/reviewers) to help with proposal review. Access remains valid until revoked. Only the main admin can manage this page.' ?></span>
      </div>

      <!-- Form tambah delegasi -->
      <div class="card" style="margin-bottom:18px">
        <div style="font-size:13px;font-weight:700;margin-bottom:11px;display:flex;align-items:center;gap:7px">
          <?= ic('plus') ?> <?= $id?'Tambah Delegasi':'Add Delegation' ?>
        </div>
        <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <input type="hidden" name="action" value="add">
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Pengguna':'User' ?> <span class="required">*</span></label>
            <select name="user_id" class="form-control" required style="font-size:12.5px">
              <option value=""><?= $id?'-- Pilih pengguna --':'-- Select user --' ?></option>
              <?php foreach ($candidates as $c): ?>
              <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['nama_lengkap']) ?>
                · <?= ucfirst($c['role']) ?>
                · <?= htmlspecialchars($c['email']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Lingkup Akses':'Scope' ?></label>
            <select name="scope" class="form-control" style="font-size:12.5px">
              <option value="penuh"><?= $scopeLabel('penuh') ?></option>
              <option value="seleksi_admin"><?= $scopeLabel('seleksi_admin') ?></option>
              <option value="penunjukan_reviewer"><?= $scopeLabel('penunjukan_reviewer') ?></option>
            </select>
          </div>
          <div style="grid-column:1/-1">
            <label class="form-label" style="font-size:11.5px"><?= $id?'Catatan (opsional)':'Note (optional)' ?></label>
            <input type="text" name="catatan" class="form-control" maxlength="255" style="font-size:12.5px"
                   placeholder="<?= $id?'Mis. Membantu seleksi admin tahun 2026':'e.g. Helping with 2026 admin selection' ?>">
          </div>
          <div style="grid-column:1/-1;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary" style="font-size:12.5px">
              <?= ic('check') ?> <?= $id?'Berikan Akses':'Grant Access' ?>
            </button>
          </div>
        </form>
      </div>

      <!-- Delegasi aktif -->
      <div style="font-size:13px;font-weight:700;margin:18px 0 10px;display:flex;align-items:center;gap:7px">
        <?= ic('users') ?> <?= $id?'Delegasi Aktif':'Active Delegations' ?>
        <span style="background:#dcfce7;color:#15803d;font-size:11px;padding:2px 9px;border-radius:9px"><?= count($active) ?></span>
      </div>

      <?php if (empty($active)): ?>
      <div style="padding:30px 20px;text-align:center;color:var(--text-muted);background:var(--bg-field);border-radius:11px">
        <?= ic('inbox','style="width:36px;height:36px;opacity:.4"') ?>
        <div style="margin-top:8px;font-size:13px"><?= $id?'Belum ada delegasi aktif.':'No active delegations.' ?></div>
      </div>
      <?php else: foreach ($active as $a): ?>
      <div class="del-card">
        <div class="del-av">
          <?php if ($a['foto_profil']): ?>
          <img src="<?= BASE_URL ?>/<?= htmlspecialchars($a['foto_profil']) ?>" style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
          <?= strtoupper(mb_substr($a['nama_lengkap'],0,1)) ?>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:700"><?= htmlspecialchars($a['nama_lengkap']) ?></div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px">
            <?= ucfirst($a['role']) ?> · <?= htmlspecialchars($a['email']) ?>
          </div>
          <div style="margin-top:7px;display:flex;flex-wrap:wrap;gap:7px;align-items:center">
            <span class="scope-pill"><?= ic('shield','style="width:10px;height:10px"') ?> <?= htmlspecialchars($scopeLabel($a['scope'])) ?></span>
            <span style="font-size:10.5px;color:var(--text-muted)">
              <?= $id?'Diberikan':'Granted' ?> <?= date('d M Y H:i', strtotime($a['granted_at'])) ?>
              <?php if (!empty($a['granted_by_name'])): ?> oleh <?= htmlspecialchars($a['granted_by_name']) ?><?php endif; ?>
            </span>
          </div>
          <?php if (!empty($a['catatan'])): ?>
          <div style="margin-top:5px;font-size:11.5px;color:#475569;background:#f8fafc;padding:6px 10px;border-radius:7px">
            💬 <?= htmlspecialchars($a['catatan']) ?>
          </div>
          <?php endif; ?>
        </div>
        <form method="POST" onsubmit="return confirm('<?= $id?'Cabut akses admin '.addslashes($a['nama_lengkap']).'?':'Revoke admin access for '.addslashes($a['nama_lengkap']).'?' ?>')">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn btn-outline" style="font-size:11.5px;padding:6px 12px;color:#dc2626;border-color:#fecaca">
            <?= ic('x-circle','style="width:13px;height:13px"') ?> <?= $id?'Cabut':'Revoke' ?>
          </button>
        </form>
      </div>
      <?php endforeach; endif; ?>

      <!-- History -->
      <?php if (!empty($history)): ?>
      <div style="font-size:13px;font-weight:700;margin:24px 0 10px;color:var(--text-muted);display:flex;align-items:center;gap:7px">
        <?= ic('clock') ?> <?= $id?'Riwayat (30 entri terakhir)':'History (last 30 entries)' ?>
      </div>
      <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:11px;overflow:hidden;font-size:11.5px">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:var(--bg-field);text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted)">
              <th style="padding:9px 13px"><?= $id?'Pengguna':'User' ?></th>
              <th style="padding:9px 13px"><?= $id?'Lingkup':'Scope' ?></th>
              <th style="padding:9px 13px"><?= $id?'Diberikan':'Granted' ?></th>
              <th style="padding:9px 13px"><?= $id?'Dicabut':'Revoked' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($history as $h): ?>
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:9px 13px"><?= htmlspecialchars($h['nama_lengkap']) ?></td>
              <td style="padding:9px 13px"><?= htmlspecialchars($scopeLabel($h['scope'])) ?></td>
              <td style="padding:9px 13px;color:var(--text-muted)">
                <?= date('d M Y H:i', strtotime($h['granted_at'])) ?>
                <?php if ($h['granted_by_name']): ?><br><span style="font-size:10px"><?= htmlspecialchars($h['granted_by_name']) ?></span><?php endif; ?>
              </td>
              <td style="padding:9px 13px;color:#dc2626">
                <?= date('d M Y H:i', strtotime($h['revoked_at'])) ?>
                <?php if ($h['revoked_by_name']): ?><br><span style="font-size:10px"><?= htmlspecialchars($h['revoked_by_name']) ?></span><?php endif; ?>
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
</body>
</html>
