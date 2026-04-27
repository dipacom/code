<?php
require_once '../includes/config.php';
require_once '../includes/logger.php';
requireLogin('admin');

$lang    = $_COOKIE['lang'] ?? 'id';
$success = $error = '';
$action  = $_GET['action'] ?? '';
$uid_target = (int)($_GET['id'] ?? 0);

// Tab: mahasiswa | dosen | reviewer
$tab = in_array($_GET['tab'] ?? '', ['dosen','mahasiswa','reviewer']) ? $_GET['tab'] : 'mahasiswa';

// ── CRUD: Edit profil ──────────────────────────────────────────
if ($action === 'edit' && $uid_target && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama     = clean($_POST['nama_lengkap'] ?? '');
    $email    = clean($_POST['email'] ?? '');
    $fakultas = clean($_POST['fakultas'] ?? '');
    $prodi    = clean($_POST['program_studi'] ?? '');
    $no_hp    = clean($_POST['no_hp'] ?? '');

    if ($tab === 'mahasiswa') {
        $nim = clean($_POST['nim'] ?? '');
    } else {
        $nidn = clean($_POST['nidn'] ?? '');
        $nip  = clean($_POST['nip'] ?? '');
    }

    if (!$nama || !$email) {
        $error = $lang==='id' ? 'Nama dan email wajib diisi.' : 'Name and email are required.';
    } else {
        $cek = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $cek->execute([$email, $uid_target]);
        if ($cek->fetch()) {
            $error = $lang==='id' ? 'Email sudah digunakan akun lain.' : 'Email already used by another account.';
        } else {
            try {
                if ($tab === 'mahasiswa') {
                    $pdo->prepare("UPDATE users SET nama_lengkap=?, nim=?, email=?, fakultas=?, program_studi=?, no_hp=? WHERE id=? AND role='mahasiswa'")
                        ->execute([$nama, $nim, $email, $fakultas, $prodi, $no_hp, $uid_target]);
                } else {
                    $pdo->prepare("UPDATE users SET nama_lengkap=?, nidn=?, nip=?, email=?, fakultas=?, program_studi=?, no_hp=? WHERE id=? AND role='dosen'")
                        ->execute([$nama, $nidn, $nip, $email, $fakultas, $prodi, $no_hp, $uid_target]);
                }
            } catch (PDOException $e) {
                // Fallback without no_hp
                if ($tab === 'mahasiswa') {
                    $pdo->prepare("UPDATE users SET nama_lengkap=?, nim=?, email=?, fakultas=?, program_studi=? WHERE id=? AND role='mahasiswa'")
                        ->execute([$nama, $nim, $email, $fakultas, $prodi, $uid_target]);
                } else {
                    $pdo->prepare("UPDATE users SET nama_lengkap=?, nidn=?, nip=?, email=?, fakultas=?, program_studi=? WHERE id=? AND role='dosen'")
                        ->execute([$nama, $nidn ?? '', $nip ?? '', $email, $fakultas, $prodi, $uid_target]);
                }
            }
            $success = $lang==='id'
                ? ($tab==='mahasiswa' ? 'Profil mahasiswa berhasil diperbarui.' : 'Profil dosen berhasil diperbarui.')
                : ($tab==='mahasiswa' ? 'Student profile updated.' : 'Lecturer profile updated.');
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_edit_user',
                "Edit profil {$tab} id={$uid_target} ({$nama})");
            $action = '';
        }
    }
}

// ── CRUD: Tambah Reviewer ──────────────────────────────────────
if ($action === 'tambah_reviewer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama  = clean($_POST['nama_lengkap'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $pw    = $_POST['password'] ?? '';
    if (!$nama || !$email || strlen($pw) < 8) {
        $error = 'Nama, email, dan password (min. 8 karakter) wajib diisi.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'Email sudah digunakan.';
        } else {
            $pdo->prepare("INSERT INTO users (nama_lengkap, email, password, role) VALUES (?,?,?,'reviewer')")
                ->execute([$nama, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $success = "Akun reviewer {$nama} berhasil dibuat.";
            $action = '';
            $tab    = 'reviewer';
        }
    }
}

// ── CRUD: Reset password ───────────────────────────────────────
if ($action === 'reset_pw' && $uid_target && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['pw_baru'] ?? '';
    $pw2 = $_POST['pw_konfirm'] ?? '';
    if (strlen($pw1) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND role=?")
            ->execute([password_hash($pw1, PASSWORD_DEFAULT), $uid_target, $tab]);
        $success = 'Password berhasil diubah.';
        writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_reset_pw',
            "Reset password {$tab} id={$uid_target}");
        $action  = '';
    }
}

// ── CRUD: Toggle aktif ─────────────────────────────────────────
if ($action === 'toggle' && $uid_target) {
    $cur = $pdo->prepare("SELECT is_active FROM users WHERE id=? AND role=?");
    $cur->execute([$uid_target, $tab]);
    $row = $cur->fetch();
    if ($row) {
        $newState = $row['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$newState, $uid_target]);
        $success = $row['is_active']
            ? ($lang==='id'?'Akun dinonaktifkan.':'Account deactivated.')
            : ($lang==='id'?'Akun diaktifkan.':'Account activated.');
        writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_toggle_user',
            ($newState ? 'Aktifkan' : 'Nonaktifkan') . " akun {$tab} id={$uid_target}");
    }
    $action = '';
}

// ── CRUD: Hapus ────────────────────────────────────────────────
if ($action === 'hapus' && $uid_target && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil nama sebelum dihapus untuk log
    $delName = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id=? AND role=?");
    $delName->execute([$uid_target, $tab]);
    $delRow = $delName->fetch();
    $pdo->prepare("DELETE FROM users WHERE id=? AND role=?")->execute([$uid_target, $tab]);
    $success = $lang==='id'
        ? ($tab==='mahasiswa' ? 'Akun mahasiswa dihapus.' : 'Akun dosen dihapus.')
        : ($tab==='mahasiswa' ? 'Student account deleted.' : 'Lecturer account deleted.');
    writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_user',
        "Hapus akun {$tab}: " . ($delRow['nama_lengkap'] ?? "id={$uid_target}"));
    $action = '';
}

// ── Data target user ───────────────────────────────────────────
$target_user = null;
if (in_array($action, ['reset_pw','edit','lihat']) && $uid_target) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role=?");
    $s->execute([$uid_target, $tab]);
    $target_user = $s->fetch();
    if (!$target_user) { $action = ''; }
}

// ── Statistik pengajuan untuk panel lihat ─────────────────────
$stat_plagiasi = $stat_publikasi = 0;
$riwayat_plagiasi = $riwayat_publikasi = [];
if ($action === 'lihat' && $target_user) {
    try {
        $q1 = $pdo->prepare("SELECT * FROM skripsi WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
        $q1->execute([$uid_target]);
        $riwayat_plagiasi = $q1->fetchAll();
        $stat_plagiasi = count($riwayat_plagiasi);
    } catch (PDOException $e) {}

    try {
        $q2 = $pdo->prepare("SELECT judul_publikasi, jenis_publikasi, status, created_at FROM publikasi WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
        $q2->execute([$uid_target]);
        $riwayat_publikasi = $q2->fetchAll();
        $stat_publikasi = count($riwayat_publikasi);
    } catch (PDOException $e) {}
}

// ── Referensi akademik ─────────────────────────────────────────
$ref_fakultas = $pdo->query(
    "SELECT id, nama FROM ref_fakultas WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();
$ref_prodi_all = $pdo->query(
    "SELECT id, nama, fakultas_id FROM ref_program_studi WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();
$ref_prodi_by_fak = [];
foreach ($ref_prodi_all as $p) $ref_prodi_by_fak[$p['fakultas_id']][] = $p;

// ── List pengguna (tab-aware) ──────────────────────────────────
$search = clean($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 20;
$offset = ($page - 1) * $per;

$id_col = $tab === 'mahasiswa' ? 'nim' : ($tab === 'reviewer' ? 'nidn' : 'nidn');

$where = "WHERE role=?";
$bind  = [$tab];
if ($search) {
    $where .= " AND (nama_lengkap LIKE ? OR {$id_col} LIKE ? OR email LIKE ?)";
    $bind[] = "%$search%"; $bind[] = "%$search%"; $bind[] = "%$search%";
}

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$total_stmt->execute($bind);
$total = $total_stmt->fetchColumn();
$pages = ceil($total / $per);

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($bind);
$users = $stmt->fetchAll();

// Tab counts
$cnt_mhs = $pdo->query("SELECT COUNT(*) FROM users WHERE role='mahasiswa'")->fetchColumn();
$cnt_dsn = $pdo->query("SELECT COUNT(*) FROM users WHERE role='dosen'")->fetchColumn();
$cnt_rev = $pdo->query("SELECT COUNT(*) FROM users WHERE role='reviewer'")->fetchColumn();

// ── Helper: URL builder ────────────────────────────────────────
function pgUrl(string $tab, string $q, int $p, string $action='', int $id=0): string {
    $u = 'pengguna.php?tab='.$tab;
    if ($q)      $u .= '&q='.urlencode($q);
    if ($p > 1)  $u .= '&p='.$p;
    if ($action) $u .= '&action='.$action;
    if ($id)     $u .= '&id='.$id;
    return $u;
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Pengelolaan Pengguna':'User Management' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── Tab styles ── */
.pg-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.pg-tab{padding:10px 22px;font-size:13px;font-weight:600;color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:6px;transition:color .15s;white-space:nowrap}
.pg-tab:hover{color:var(--primary)}
.pg-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.pg-tab-badge{background:var(--primary-xlight);color:var(--primary);font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px}
.pg-tab.active .pg-tab-badge{background:var(--primary);color:#fff}

/* ── Search form responsive ── */
.pg-search-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pg-search-form .search-input-wrap{position:relative;flex:1;min-width:180px}
.pg-search-form .search-input-wrap input{padding-left:32px;width:100%;height:32px;font-size:12px}
.pg-search-form .search-input-wrap span{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)}

/* ── Card header responsive (search di bawah pada mobile) ── */
.pg-card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);gap:10px;flex-wrap:wrap}
.pg-card-head .card-title{flex:1;min-width:0}

/* ── Responsive table → card layout ── */
.resp-table{width:100%;border-collapse:collapse;font-size:12px}
.resp-table thead tr{background:var(--bg-field);border-bottom:2px solid var(--border)}
.resp-table th{padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);white-space:nowrap}
.resp-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;color:var(--text-secondary)}
.resp-table tbody tr:last-child td{border-bottom:none}
.resp-table tbody tr:hover{background:var(--primary-xlight)}

/* ── Aksi tombol ── */
.aksi-wrap{display:flex;gap:5px;align-items:center;flex-wrap:wrap}

/* ═══════════════════════════════
   MOBILE: tabel → kartu
   ═══════════════════════════════ */
@media(max-width:768px){
  /* sembunyikan thead */
  .resp-table thead{display:none}

  /* setiap baris jadi kartu */
  .resp-table, .resp-table tbody,
  .resp-table tr, .resp-table td{display:block;width:100%}

  .resp-table tbody tr{
    border:1px solid var(--border);
    border-radius:10px;
    margin:0 0 10px;
    padding:0;
    background:#fff;
    box-shadow:var(--shadow-xs);
    overflow:hidden;
  }
  .resp-table tbody tr:hover{background:#fff}

  /* header kartu = kolom nama (td ke-2) */
  .resp-table td.td-nama{
    background:linear-gradient(135deg,#0d0428 0%,#1a0a3d 100%);
    padding:12px 14px;
    border-bottom:1px solid rgba(139,92,246,.2);
  }
  .resp-table td.td-nama .td-nama-inner{
    display:flex;align-items:center;gap:10px;
  }
  .resp-table td.td-nama .td-nama-inner span{
    color:#fff !important;font-size:13px;
  }

  /* sembunyikan kolom nomor urut di mobile */
  .resp-table td.td-no{display:none}

  /* setiap td lainnya tampil sebagai baris label: nilai */
  .resp-table td[data-label]{
    display:flex;
    align-items:flex-start;
    gap:8px;
    padding:8px 14px;
    border-bottom:1px solid var(--border);
    font-size:12px;
  }
  .resp-table td[data-label]:last-child{border-bottom:none}
  .resp-table td[data-label]::before{
    content:attr(data-label);
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    color:var(--text-muted);
    letter-spacing:.04em;
    white-space:nowrap;
    min-width:90px;
    padding-top:1px;
    flex-shrink:0;
  }

  /* kolom aksi: full-width, tombol berderet */
  .resp-table td.td-aksi{
    padding:10px 14px;
    border-top:1px solid var(--border);
    border-bottom:none;
  }
  .resp-table td.td-aksi::before{display:none}
  .aksi-wrap{flex-wrap:nowrap;gap:6px}
  .aksi-wrap .btn{flex:1;justify-content:center;height:32px;font-size:11px}
  .aksi-wrap form{flex:1}
  .aksi-wrap form .btn{width:100%}

  /* search form stack */
  .pg-search-form{flex-direction:column;align-items:stretch}
  .pg-search-form .search-input-wrap{min-width:unset}
  .pg-card-head{flex-direction:column;align-items:flex-start}
}

@media(max-width:480px){
  .resp-table td[data-label]::before{min-width:80px;font-size:9px}
  .aksi-wrap .btn{font-size:10px;height:30px}
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
          <?= ic('users') ?> <?= $lang==='id'?'Pengelolaan Pengguna':'User Management' ?>
          <span class="breadcrumb">
            <?= $total ?> <?= $tab==='mahasiswa'
              ? ($lang==='id'?'mahasiswa':'students')
              : ($lang==='id'?'dosen':'lecturers') ?>
          </span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if($success): ?>
        <div class="alert alert-success"><?= ic('check-circle') ?> <?= $success ?></div>
      <?php endif; ?>
      <?php if($error): ?>
        <div class="alert alert-danger"><?= ic('x-circle') ?> <?= $error ?></div>
      <?php endif; ?>

      <!-- ── Tab bar ── -->
      <div class="pg-tabs">
        <a class="pg-tab <?= $tab==='mahasiswa'?'active':'' ?>"
           href="pengguna.php?tab=mahasiswa<?= $search ? '&q='.urlencode($search) : '' ?>">
          <?= ic('users') ?>
          <?= $lang==='id'?'Mahasiswa':'Students' ?>
          <span class="pg-tab-badge"><?= $cnt_mhs ?></span>
        </a>
        <a class="pg-tab <?= $tab==='dosen'?'active':'' ?>"
           href="pengguna.php?tab=dosen<?= $search ? '&q='.urlencode($search) : '' ?>">
          <?= ic('graduation') ?>
          <?= $lang==='id'?'Dosen':'Lecturers' ?>
          <span class="pg-tab-badge"><?= $cnt_dsn ?></span>
        </a>
        <a class="pg-tab <?= $tab==='reviewer'?'active':'' ?>"
           href="pengguna.php?tab=reviewer<?= $search ? '&q='.urlencode($search) : '' ?>">
          <?= ic('award') ?>
          Reviewer
          <span class="pg-tab-badge"><?= $cnt_rev ?></span>
        </a>
      </div>

      <?php if ($action === 'tambah_reviewer'): ?>
      <!-- ── Panel Tambah Reviewer ── -->
      <div class="card" style="max-width:480px">
        <div class="card-header"><span class="card-title"><?= ic('award') ?> <?= $lang==='id'?'Buat Akun Reviewer':'Create Reviewer Account' ?></span></div>
        <form method="POST" action="?tab=reviewer&action=tambah_reviewer" style="padding:16px 18px">
          <div class="form-group">
            <label class="form-label"><?= $lang==='id'?'Nama Lengkap':'Full Name' ?> <span class="required">*</span></label>
            <input type="text" name="nama_lengkap" class="form-control" required placeholder="<?= $lang==='id'?'Nama reviewer':'Reviewer name' ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Email <span class="required">*</span></label>
            <input type="email" name="email" class="form-control" required placeholder="email@example.com">
          </div>
          <div class="form-group">
            <label class="form-label"><?= $lang==='id'?'Password':'Password' ?> <span class="required">*</span></label>
            <input type="password" name="password" class="form-control" required minlength="8" placeholder="<?= $lang==='id'?'Min. 8 karakter':'Min. 8 characters' ?>">
          </div>
          <div style="display:flex;gap:8px;margin-top:16px">
            <button type="submit" class="btn btn-primary"><?= ic('plus') ?> <?= $lang==='id'?'Buat Akun':'Create Account' ?></button>
            <a href="?tab=reviewer" class="btn btn-outline"><?= $lang==='id'?'Batal':'Cancel' ?></a>
          </div>
        </form>
      </div>
      <?php elseif ($action === 'reset_pw' && $target_user): ?>
      <!-- ── Panel Reset Password ── -->
      <div class="card" style="max-width:480px">
        <div class="card-header">
          <span class="card-title"><?= ic('lock') ?> Reset Password <?= $tab==='mahasiswa'?'Mahasiswa':'Dosen' ?></span>
          <a href="pengguna.php?tab=<?= $tab ?>" class="btn btn-outline btn-sm"><?= ic('x') ?> Tutup</a>
        </div>
        <div class="card-body">
          <div style="background:var(--primary-xlight);border-radius:var(--radius-md);padding:12px 14px;margin-bottom:18px;display:flex;align-items:center;gap:12px">
            <div style="width:38px;height:38px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--accent-light);flex-shrink:0">
              <?= strtoupper(mb_substr($target_user['nama_lengkap'],0,1)) ?>
            </div>
            <div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($target_user['nama_lengkap']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)">
                <?= $tab==='mahasiswa' ? ($target_user['nim']??'-') : ($target_user['nidn']??'-') ?>
                · <?= htmlspecialchars($target_user['email']) ?>
              </div>
            </div>
          </div>
          <form method="POST" action="<?= pgUrl($tab, '', 0, 'reset_pw', $target_user['id']) ?>">
            <div class="form-group">
              <label class="form-label">Password Baru <span class="required">*</span></label>
              <div style="position:relative">
                <input type="password" name="pw_baru" id="pw1" class="form-control" placeholder="Min. 8 karakter" required>
                <button type="button" onclick="togglePw('pw1','e1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;align-items:center">
                  <svg id="e1" class="ic" style="width:15px;height:15px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Konfirmasi Password <span class="required">*</span></label>
              <div style="position:relative">
                <input type="password" name="pw_konfirm" id="pw2" class="form-control" placeholder="Ulangi password baru" required>
                <button type="button" onclick="togglePw('pw2','e2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;align-items:center">
                  <svg id="e2" class="ic" style="width:15px;height:15px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:4px">
              <button type="submit" class="btn btn-primary"><?= ic('check') ?> Simpan Password</button>
              <a href="pengguna.php?tab=<?= $tab ?>" class="btn btn-outline">Batal</a>
            </div>
          </form>
        </div>
      </div>

      <?php elseif ($action === 'lihat' && $target_user): ?>
      <!-- ── Panel Lihat Profil ── -->
      <div class="card" style="max-width:640px">
        <div class="card-header">
          <span class="card-title"><?= ic('user') ?> <?= $tab==='mahasiswa'
            ? ($lang==='id'?'Profil Mahasiswa':'Student Profile')
            : ($lang==='id'?'Profil Dosen':'Lecturer Profile') ?></span>
          <div style="display:flex;gap:8px">
            <a href="<?= pgUrl($tab,'',0,'edit',$target_user['id']) ?>" class="btn btn-primary btn-sm"><?= ic('edit') ?> <?= $lang==='id'?'Edit':'Edit' ?></a>
            <a href="pengguna.php?tab=<?= $tab ?>" class="btn btn-outline btn-sm"><?= ic('x') ?> <?= $lang==='id'?'Tutup':'Close' ?></a>
          </div>
        </div>
        <div class="card-body">
          <!-- Avatar + nama -->
          <div style="display:flex;align-items:center;gap:16px;padding:16px;background:var(--primary-xlight);border-radius:var(--radius-md);margin-bottom:20px">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:var(--accent-light);flex-shrink:0;overflow:hidden;border:2px solid var(--accent-light)">
              <?php if (!empty($target_user['foto_profil']) && file_exists(BASE_PATH.'/'.$target_user['foto_profil'])): ?>
                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($target_user['foto_profil']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?>
                <?= strtoupper(mb_substr($target_user['nama_lengkap'],0,1)) ?>
              <?php endif; ?>
            </div>
            <div>
              <div style="font-weight:700;font-size:16px;color:var(--primary)"><?= htmlspecialchars($target_user['nama_lengkap']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= htmlspecialchars($target_user['email']) ?></div>
              <div style="margin-top:6px">
                <span style="background:<?= $tab==='dosen'?'#f0fdf4':'var(--primary-xlight)' ?>;color:<?= $tab==='dosen'?'#16a34a':'var(--primary)' ?>;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600">
                  <?= $tab==='mahasiswa'?'Mahasiswa':'Dosen' ?>
                </span>
                <?php if ($target_user['is_active']): ?>
                  <span style="background:var(--success-bg);color:var(--success-mid);padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;margin-left:4px">● Aktif</span>
                <?php else: ?>
                  <span style="background:var(--danger-bg);color:var(--danger-mid);padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;margin-left:4px">● Nonaktif</span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Detail data -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
            <?php
            if ($tab === 'mahasiswa') {
                $fields = [
                  [$lang==='id'?'NIM':'Student ID',             $target_user['nim'] ?? '-'],
                  [$lang==='id'?'Fakultas':'Faculty',           $target_user['fakultas'] ?? '-'],
                  [$lang==='id'?'Program Studi':'Study Program',$target_user['program_studi'] ?? '-'],
                  [$lang==='id'?'No. HP / WhatsApp':'Phone',    $target_user['no_hp'] ?? '-'],
                  [$lang==='id'?'Tanggal Daftar':'Registered',  formatTanggal($target_user['created_at'])],
                  [$lang==='id'?'Login Terakhir':'Last Login',  !empty($target_user['last_login']) ? formatTanggal($target_user['last_login']) : '-'],
                ];
            } else {
                $fields = [
                  ['NIDN',                                       $target_user['nidn'] ?? '-'],
                  ['NIP',                                        $target_user['nip']  ?? '-'],
                  [$lang==='id'?'Fakultas':'Faculty',           $target_user['fakultas'] ?? '-'],
                  [$lang==='id'?'Program Studi':'Study Program',$target_user['program_studi'] ?? '-'],
                  [$lang==='id'?'No. HP / WhatsApp':'Phone',    $target_user['no_hp'] ?? '-'],
                  [$lang==='id'?'Tanggal Daftar':'Registered',  formatTanggal($target_user['created_at'])],
                ];
            }
            foreach ($fields as [$lbl, $val]): ?>
            <div style="padding:10px 12px;background:#f8fafc;border-radius:8px;border:1px solid var(--border)">
              <div style="font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:3px"><?= $lbl ?></div>
              <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($val) ?></div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Statistik pengajuan -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
            <div style="padding:14px 16px;background:var(--primary-xlight);border-radius:8px;border:1px solid var(--primary-light);text-align:center">
              <div style="font-size:28px;font-weight:800;color:var(--primary)"><?= $stat_plagiasi ?></div>
              <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-top:2px"><?= $lang==='id'?'Pengajuan Cek Plagiasi':'Plagiarism Check Submissions' ?></div>
            </div>
            <div style="padding:14px 16px;background:#faf5ff;border-radius:8px;border:1px solid #e9d5ff;text-align:center">
              <div style="font-size:28px;font-weight:800;color:var(--accent)"><?= $stat_publikasi ?></div>
              <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-top:2px"><?= $lang==='id'?'Pengajuan Surat Publikasi':'Publication Submissions' ?></div>
            </div>
          </div>

          <!-- Riwayat plagiasi -->
          <?php if ($riwayat_plagiasi): ?>
          <div style="margin-bottom:16px">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px"><?= $lang==='id'?'Riwayat Cek Plagiasi':'Plagiarism Check History' ?></div>
            <?php foreach ($riwayat_plagiasi as $r): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;margin-bottom:6px;font-size:12px">
              <span style="flex-shrink:0"><?= badgeStatus($r['status']) ?></span>
              <span style="flex:1;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['judul_skripsi']) ?>">
                <strong><?= ucfirst($r['jenis_tugas_akhir'] ?? 'skripsi') ?></strong> — <?= htmlspecialchars(mb_strimwidth($r['judul_skripsi'],0,55,'…')) ?>
              </span>
              <span style="flex-shrink:0;color:var(--text-muted)"><?= date('d/m/Y', strtotime($r['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Riwayat publikasi -->
          <?php if ($riwayat_publikasi): ?>
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px"><?= $lang==='id'?'Riwayat Pengajuan Publikasi':'Publication Submission History' ?></div>
            <?php foreach ($riwayat_publikasi as $r): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;margin-bottom:6px;font-size:12px">
              <span style="flex-shrink:0"><?= badgeStatus($r['status']) ?></span>
              <span style="flex:1;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['judul_publikasi']) ?>">
                <strong><?= labelJenisPublikasi($r['jenis_publikasi']) ?></strong> — <?= htmlspecialchars(mb_strimwidth($r['judul_publikasi'],0,55,'…')) ?>
              </span>
              <span style="flex-shrink:0;color:var(--text-muted)"><?= date('d/m/Y', strtotime($r['created_at'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <?php elseif ($action === 'edit' && $target_user): ?>
      <!-- ── Panel Edit Profil ── -->
      <div class="card" style="max-width:560px">
        <div class="card-header">
          <span class="card-title"><?= ic('edit') ?> <?= $tab==='mahasiswa'
            ? ($lang==='id'?'Edit Profil Mahasiswa':'Edit Student Profile')
            : ($lang==='id'?'Edit Profil Dosen':'Edit Lecturer Profile') ?></span>
          <a href="<?= pgUrl($tab,'',0,'lihat',$target_user['id']) ?>" class="btn btn-outline btn-sm"><?= ic('x') ?> <?= $lang==='id'?'Batal':'Cancel' ?></a>
        </div>
        <div class="card-body">
          <!-- Info akun -->
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--primary-xlight);border-radius:var(--radius-md);margin-bottom:20px">
            <div style="width:38px;height:38px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--accent-light);flex-shrink:0">
              <?= strtoupper(mb_substr($target_user['nama_lengkap'],0,1)) ?>
            </div>
            <div>
              <div style="font-size:12px;color:var(--text-muted)"><?= $lang==='id'?'Mengedit profil:':'Editing profile:' ?></div>
              <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($target_user['nama_lengkap']) ?></div>
            </div>
          </div>

          <form method="POST" action="<?= pgUrl($tab,'',0,'edit',$target_user['id']) ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label"><?= $lang==='id'?'Nama Lengkap':'Full Name' ?> <span class="required">*</span></label>
                <input type="text" name="nama_lengkap" class="form-control" required
                       value="<?= htmlspecialchars($target_user['nama_lengkap']) ?>">
              </div>
              <?php if ($tab === 'mahasiswa'): ?>
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">NIM</label>
                <input type="text" name="nim" class="form-control"
                       value="<?= htmlspecialchars($target_user['nim']??'') ?>"
                       placeholder="Nomor Induk Mahasiswa">
              </div>
              <?php else: ?>
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">NIDN</label>
                <input type="text" name="nidn" class="form-control"
                       value="<?= htmlspecialchars($target_user['nidn']??'') ?>"
                       placeholder="Nomor Induk Dosen Nasional">
              </div>
              <?php endif; ?>
            </div>

            <?php if ($tab === 'dosen'): ?>
            <div class="form-group" style="margin-top:14px">
              <label class="form-label">NIP <span style="font-size:11px;color:var(--text-muted)">(Opsional)</span></label>
              <input type="text" name="nip" class="form-control"
                     value="<?= htmlspecialchars($target_user['nip']??'') ?>"
                     placeholder="Nomor Induk Pegawai">
            </div>
            <?php endif; ?>

            <div class="form-group" style="margin-top:14px">
              <label class="form-label">Email <span class="required">*</span></label>
              <input type="email" name="email" class="form-control" required
                     value="<?= htmlspecialchars($target_user['email']) ?>">
            </div>

            <div class="form-group" style="margin-top:14px">
              <label class="form-label"><?= $lang==='id'?'Fakultas':'Faculty' ?></label>
              <select name="fakultas" id="adm-sel-fak" class="form-control"
                      onchange="admUpdateProdi(this.value, null)">
                <option value=""><?= $lang==='id'?'-- Pilih Fakultas --':'-- Select Faculty --' ?></option>
                <?php foreach ($ref_fakultas as $f): ?>
                  <option value="<?= htmlspecialchars($f['nama']) ?>"
                          data-id="<?= $f['id'] ?>"
                          <?= ($target_user['fakultas']??'')===$f['nama']?'selected':'' ?>>
                    <?= htmlspecialchars($f['nama']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group" style="margin-top:14px">
              <label class="form-label"><?= $lang==='id'?'Program Studi':'Study Program' ?></label>
              <select name="program_studi" id="adm-sel-prodi" class="form-control">
                <?php
                  $cur_fak_id = 0;
                  foreach ($ref_fakultas as $f) {
                      if ($f['nama'] === ($target_user['fakultas'] ?? '')) { $cur_fak_id = $f['id']; break; }
                  }
                  if ($cur_fak_id && !empty($ref_prodi_by_fak[$cur_fak_id])) {
                      foreach ($ref_prodi_by_fak[$cur_fak_id] as $p) {
                          $sel = $p['nama'] === ($target_user['program_studi'] ?? '') ? 'selected' : '';
                          echo '<option value="' . htmlspecialchars($p['nama']) . '" ' . $sel . '>' . htmlspecialchars($p['nama']) . '</option>';
                      }
                  } else {
                      echo '<option value="">-- Pilih Fakultas dulu --</option>';
                  }
                ?>
              </select>
            </div>

            <div class="form-group" style="margin-top:14px">
              <label class="form-label"><?= $lang==='id'?'No. HP / WhatsApp':'Phone / WhatsApp' ?></label>
              <input type="text" name="no_hp" class="form-control"
                     value="<?= htmlspecialchars($target_user['no_hp']??'') ?>"
                     placeholder="08xxxxxxxxxx">
            </div>

            <div style="display:flex;gap:10px;margin-top:20px">
              <button type="submit" class="btn btn-primary"><?= ic('check') ?> <?= $lang==='id'?'Simpan Perubahan':'Save Changes' ?></button>
              <a href="<?= pgUrl($tab,'',0,'lihat',$target_user['id']) ?>" class="btn btn-outline"><?= $lang==='id'?'Batal':'Cancel' ?></a>
            </div>
          </form>
        </div>
      </div>

      <?php else: ?>
      <!-- ── Daftar Pengguna (tab-aware) ── -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">
            <?= ic($tab==='mahasiswa'?'users':($tab==='reviewer'?'award':'briefcase')) ?>
            <?= $tab==='mahasiswa'
              ? ($lang==='id'?'Daftar Akun Mahasiswa':'Student Accounts')
              : ($tab==='reviewer'
                  ? ($lang==='id'?'Daftar Reviewer':'Reviewer Accounts')
                  : ($lang==='id'?'Daftar Akun Dosen':'Lecturer Accounts')) ?>
          </span>
          <?php if ($tab === 'reviewer'): ?>
          <a href="?tab=reviewer&action=tambah_reviewer" class="btn btn-primary" style="font-size:12px;padding:6px 12px">
            <?= ic('plus') ?> <?= $lang==='id'?'Tambah Reviewer':'Add Reviewer' ?>
          </a>
          <?php endif; ?>
          <!-- Search -->
          <form method="GET" action="pengguna.php" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="tab" value="<?= $tab ?>">
            <div style="position:relative">
              <input type="text" name="q" class="form-control"
                     placeholder="<?= $tab==='mahasiswa'
                       ? ($lang==='id'?'Cari nama / NIM / email...':'Search name / NIM / email...')
                       : ($lang==='id'?'Cari nama / NIDN / email...':'Search name / NIDN / email...') ?>"
                     value="<?= htmlspecialchars($search) ?>"
                     style="padding-left:32px;width:260px;height:32px;font-size:12px">
              <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted)"><?= ic('search') ?></span>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= $lang==='id'?'Cari':'Search' ?></button>
            <?php if($search): ?>
              <a href="pengguna.php?tab=<?= $tab ?>" class="btn btn-outline btn-sm"><?= ic('x') ?></a>
            <?php endif; ?>
          </form>
        </div>
        <div class="card-body" style="padding:0">
          <?php if (empty($users)): ?>
            <div style="padding:32px;text-align:center;color:var(--text-muted)">
              <?= ic('inbox', 'style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $tab==='mahasiswa'
                ? ($lang==='id'?'Tidak ada data mahasiswa.':'No student accounts found.')
                : ($lang==='id'?'Tidak ada data dosen.':'No lecturer accounts found.') ?>
            </div>
          <?php else: ?>
          <div class="table-wrap">
            <table class="resp-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th><?= $tab==='mahasiswa'?($lang==='id'?'Nama Mahasiswa':'Student Name'):($lang==='id'?'Nama Dosen':'Lecturer Name') ?></th>
                  <th><?= $tab==='mahasiswa'?'NIM':'NIDN' ?></th>
                  <?php if ($tab==='dosen'): ?><th>NIP</th><?php endif; ?>
                  <th><?= $lang==='id'?'Fakultas':'Faculty' ?></th>
                  <th><?= $lang==='id'?'Program Studi':'Study Program' ?></th>
                  <th>Email</th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Daftar':'Registered' ?></th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($users as $i => $u): ?>
                <tr>
                  <td class="td-no"><?= $offset+$i+1 ?></td>
                  <td class="td-nama">
                    <div class="td-nama-inner">
                      <div style="width:30px;height:30px;border-radius:50%;background:<?= $tab==='dosen'?'#16a34a':'var(--primary)' ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden">
                        <?php if (!empty($u['foto_profil']) && file_exists(BASE_PATH.'/'.$u['foto_profil'])): ?>
                          <img src="<?= BASE_URL ?>/<?= htmlspecialchars($u['foto_profil']) ?>" style="width:100%;height:100%;object-fit:cover">
                        <?php else: ?>
                          <?= strtoupper(mb_substr($u['nama_lengkap'],0,1)) ?>
                        <?php endif; ?>
                      </div>
                      <span><?= htmlspecialchars($u['nama_lengkap']) ?></span>
                    </div>
                  </td>
                  <td data-label="<?= $tab==='mahasiswa'?'NIM':'NIDN' ?>"><?= htmlspecialchars($tab==='mahasiswa'?($u['nim']??'-'):($u['nidn']??'-')) ?></td>
                  <?php if ($tab==='dosen'): ?>
                  <td data-label="NIP"><?= htmlspecialchars($u['nip']??'-') ?></td>
                  <?php endif; ?>
                  <td data-label="<?= $lang==='id'?'Fakultas':'Faculty' ?>"><?= htmlspecialchars($u['fakultas']??'-') ?></td>
                  <td data-label="<?= $lang==='id'?'Program Studi':'Study Program' ?>"><?= htmlspecialchars($u['program_studi']??'-') ?></td>
                  <td data-label="Email"><?= htmlspecialchars($u['email']) ?></td>
                  <td data-label="Status">
                    <?php if ($u['is_active']): ?>
                      <span style="background:var(--success-bg);color:var(--success-mid);padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600"><?= ic('check', 'style="width:10px;height:10px"') ?> Aktif</span>
                    <?php else: ?>
                      <span style="background:var(--danger-bg);color:var(--danger-mid);padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600"><?= ic('x', 'style="width:10px;height:10px"') ?> Nonaktif</span>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?= $lang==='id'?'Tgl Daftar':'Registered' ?>"><?= formatTanggal($u['created_at']) ?></td>
                  <td class="td-aksi">
                    <div class="aksi-wrap">
                      <a href="<?= pgUrl($tab,'',0,'lihat',$u['id']) ?>"
                         class="btn btn-outline btn-sm" title="<?= $lang==='id'?'Lihat Profil':'View Profile' ?>">
                        <?= ic('user') ?>
                      </a>
                      <a href="<?= pgUrl($tab,'',0,'edit',$u['id']) ?>"
                         class="btn btn-sm" style="background:var(--accent-glow);color:var(--accent);border:1px solid var(--accent-light)"
                         title="<?= $lang==='id'?'Edit Profil':'Edit Profile' ?>">
                        <?= ic('edit') ?>
                      </a>
                      <a href="<?= pgUrl($tab,'',0,'reset_pw',$u['id']) ?>"
                         class="btn btn-primary btn-sm" title="Reset Password">
                        <?= ic('lock') ?>
                      </a>
                      <a href="<?= pgUrl($tab,'',0,'toggle',$u['id']) ?>"
                         class="btn btn-sm <?= $u['is_active'] ? 'btn-outline' : 'btn-success' ?>"
                         onclick="return confirm('<?= $u['is_active'] ? ($lang==='id'?'Nonaktifkan':'Deactivate') : ($lang==='id'?'Aktifkan':'Activate') ?> akun ini?')"
                         title="<?= $u['is_active'] ? ($lang==='id'?'Nonaktifkan':'Deactivate') : ($lang==='id'?'Aktifkan':'Activate') ?>">
                        <?= ic($u['is_active'] ? 'x-circle' : 'check-circle') ?>
                      </a>
                      <form method="POST" action="<?= pgUrl($tab,'',0,'hapus',$u['id']) ?>" style="display:inline"
                            onsubmit="return confirm('Hapus akun <?= htmlspecialchars(addslashes($u['nama_lengkap'])) ?>? Data tidak bisa dikembalikan.')">
                        <button type="submit" class="btn btn-sm" style="background:var(--danger-bg);color:var(--danger-mid);border-color:var(--danger-border)" title="<?= $lang==='id'?'Hapus':'Delete' ?>">
                          <?= ic('trash') ?>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <!-- Pagination -->
          <?php if ($pages > 1): ?>
          <div style="padding:14px 16px;display:flex;align-items:center;gap:6px;border-top:1px solid var(--border)">
            <?php for($pg=1;$pg<=$pages;$pg++): ?>
              <a href="pengguna.php?tab=<?= $tab ?>&p=<?= $pg ?>&q=<?= urlencode($search) ?>"
                 style="width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;<?= $pg===$page ? 'background:var(--primary);color:#fff' : 'background:var(--bg-field);color:var(--text-secondary)' ?>">
                <?= $pg ?>
              </a>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>
// ── Referensi prodi per-fakultas ──────────────────────────────
const admProdiData = <?= json_encode($ref_prodi_by_fak, JSON_UNESCAPED_UNICODE) ?>;
const admFakMap    = {};
<?php foreach ($ref_fakultas as $f): ?>
admFakMap[<?= json_encode($f['nama']) ?>] = <?= $f['id'] ?>;
<?php endforeach; ?>

function admUpdateProdi(namaFak, currentProdi) {
  const sel   = document.getElementById('adm-sel-prodi');
  if (!sel) return;
  const fakId = admFakMap[namaFak];
  const items = admProdiData[fakId] || [];
  sel.innerHTML = items.length
    ? ''
    : '<option value="">-- Pilih Fakultas dulu --</option>';
  items.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.nama; opt.textContent = p.nama;
    if (currentProdi && p.nama === currentProdi) opt.selected = true;
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  const selFak = document.getElementById('adm-sel-fak');
  if (selFak && selFak.value) {
    const curProdi = document.getElementById('adm-sel-prodi')?.value;
    admUpdateProdi(selFak.value, curProdi);
  }
});

function togglePw(inputId, iconId) {
  const inp  = document.getElementById(inputId);
  const show = inp.type === 'password';
  inp.type   = show ? 'text' : 'password';
  document.getElementById(iconId).innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}
function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>
