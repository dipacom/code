<?php
// admin/mahasiswa.php — Kelola data mahasiswa
require_once '../includes/config.php';
requireLogin('admin');
$lang = $_COOKIE['lang'] ?? 'id';

// Nonaktifkan / aktifkan akun
if (isset($_GET['toggle'])) {
    $uid = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id=? AND role='mahasiswa'")->execute([$uid]);
    redirect('/admin/mahasiswa.php');
}

$search = clean($_GET['q'] ?? '');
$params = [];
$where  = "WHERE u.role='mahasiswa'";
if ($search) {
    $where .= " AND (u.nama_lengkap LIKE ? OR u.nim LIKE ? OR u.email LIKE ? OR u.fakultas LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$stmt = $pdo->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM skripsi WHERE user_id=u.id) as jml_skripsi,
           (SELECT COUNT(*) FROM publikasi WHERE user_id=u.id) as jml_pub,
           (SELECT COUNT(*) FROM surat_plagiasi sp JOIN cek_plagiasi cp ON sp.cek_plagiasi_id=cp.id JOIN skripsi s ON cp.skripsi_id=s.id WHERE s.user_id=u.id) as jml_surat_pl,
           (SELECT COUNT(*) FROM surat_publikasi sp JOIN publikasi p ON sp.publikasi_id=p.id WHERE p.user_id=u.id) as jml_surat_pub
    FROM users u $where
    ORDER BY u.created_at DESC
");
$stmt->execute($params);
$list = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Data Mahasiswa':'Student Data' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('users') ?> <?= $lang==='id'?'Data Mahasiswa':'Student Data' ?>
          <span class="breadcrumb"><?= count($list) ?> <?= $lang==='id'?'mahasiswa terdaftar':'registered students' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- Search -->
      <form method="GET" style="margin-bottom:16px;display:flex;gap:10px;max-width:400px">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control"
               placeholder="<?= $lang==='id'?'Cari nama, NIM, atau email...':'Search name, NIM, or email...' ?>">
        <button type="submit" class="btn btn-primary"><?= ic('search') ?></button>
        <?php if($search): ?><a href="mahasiswa.php" class="btn btn-outline"><?= ic('x') ?></a><?php endif; ?>
      </form>

      <div class="card">
        <div class="card-body" style="padding:0">
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th><?= $lang==='id'?'Nama Lengkap':'Full Name' ?></th>
                  <th>NIM</th>
                  <th><?= $lang==='id'?'Fakultas':'Faculty' ?></th>
                  <th><?= $lang==='id'?'Program Studi':'Study Program' ?></th>
                  <th>Email</th>
                  <th><?= $lang==='id'?'Skripsi':'Thesis' ?></th>
                  <th><?= $lang==='id'?'Publikasi':'Publications' ?></th>
                  <th><?= $lang==='id'?'Surat':'Certificates' ?></th>
                  <th><?= $lang==='id'?'Daftar':'Registered' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($list as $i=>$r): ?>
                <tr style="<?= !$r['is_active']?'opacity:.5':'' ?>">
                  <td style="color:#94a3b8;font-size:12px"><?= $i+1 ?></td>
                  <td style="font-weight:600"><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                  <td style="font-size:13px;color:#475569"><?= $r['nim'] ?? '-' ?></td>
                  <td style="font-size:12px;max-width:130px"><?= htmlspecialchars($r['fakultas']??'-') ?></td>
                  <td style="font-size:12px;max-width:120px"><?= htmlspecialchars($r['program_studi']??'-') ?></td>
                  <td style="font-size:12px;color:#475569"><?= htmlspecialchars($r['email']) ?></td>
                  <td style="text-align:center;font-weight:600"><?= $r['jml_skripsi'] ?></td>
                  <td style="text-align:center;font-weight:600"><?= $r['jml_pub'] ?></td>
                  <td style="text-align:center">
                    <span style="font-size:12px;color:#059669;font-weight:600">
                      <?= $r['jml_surat_pl'] + $r['jml_surat_pub'] ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:#94a3b8;white-space:nowrap"><?= formatTanggal($r['created_at'], false) ?></td>
                  <td>
                    <span class="badge <?= $r['is_active']?'badge-success':'badge-danger' ?>">
                      <?= $r['is_active'] ? ($lang==='id'?'Aktif':'Active') : ($lang==='id'?'Nonaktif':'Inactive') ?>
                    </span>
                  </td>
                  <td>
                    <a href="mahasiswa.php?toggle=<?= $r['id'] ?>"
                       class="btn btn-sm <?= $r['is_active']?'btn-danger':'btn-success' ?>"
                       onclick="return confirm('<?= $lang==='id'?'Yakin mengubah status akun ini?':'Sure to change this account status?' ?>')">
                      <?= $r['is_active'] ? ($lang==='id'?'Nonaktifkan':'Deactivate') : ($lang==='id'?'Aktifkan':'Activate') ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($list)): ?>
                <tr><td colspan="12" style="text-align:center;padding:28px;color:#94a3b8">
                  <?= $lang==='id'?'Tidak ada data mahasiswa.':'No student data found.' ?>
                </td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function toggleLang(){const cur=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>
