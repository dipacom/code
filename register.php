<?php
require_once 'includes/config.php';
if (isLoggedIn()) redirect('/dashboard.php');

$error   = '';
$success = '';
$lang    = $_COOKIE['lang'] ?? 'id';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role_reg = clean($_POST['role_reg'] ?? 'mahasiswa');
    if (!in_array($role_reg, ['mahasiswa','dosen'])) $role_reg = 'mahasiswa';

    $nama     = clean($_POST['nama'] ?? '');
    $fakultas = clean($_POST['fakultas'] ?? '');
    $prodi    = clean($_POST['prodi'] ?? '');
    $no_hp    = clean($_POST['no_hp'] ?? '');
    $email    = clean($_POST['email'] ?? '');
    $pw       = $_POST['password'] ?? '';
    $pw2      = $_POST['password2'] ?? '';

    // Kolom per-role
    $nim      = $role_reg === 'mahasiswa' ? clean($_POST['nim'] ?? '') : null;
    $angkatan = $role_reg === 'mahasiswa' ? (int)($_POST['angkatan'] ?? 0) : null;
    $nidn     = $role_reg === 'dosen'     ? clean($_POST['nidn'] ?? '') : null;
    $nip      = $role_reg === 'dosen'     ? clean($_POST['nip']  ?? '') : null;

    // Validasi dasar
    $req_id = $role_reg === 'mahasiswa' ? $nim : $nidn;   // NIM atau NIDN wajib
    if (!$nama || !$req_id || !$fakultas || !$prodi || !$email || !$pw) {
        $error = $lang==='id' ? 'Semua kolom bertanda * wajib diisi.' : 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $lang==='id' ? 'Format email tidak valid.' : 'Invalid email format.';
    } elseif (strlen($pw) < 8) {
        $error = $lang==='id' ? 'Password minimal 8 karakter.' : 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = $lang==='id' ? 'Konfirmasi password tidak cocok.' : 'Passwords do not match.';
    } else {
        // Cek email duplikat
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = $lang==='id' ? 'Email sudah terdaftar.' : 'Email already registered.';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users
                    (nama_lengkap, email, password, role,
                     nim, nidn, nip,
                     fakultas, program_studi, angkatan, no_hp)
                VALUES (?,?,?,?, ?,?,?, ?,?,?,?)
            ");
            $stmt->execute([
                $nama, $email, $hash, $role_reg,
                $nim, $nidn ?: null, $nip ?: null,
                $fakultas, $prodi, $angkatan ?: null, $no_hp ?: null,
            ]);
            $success = $lang==='id'
                ? 'Pendaftaran berhasil! Silakan login.'
                : 'Registration successful! Please sign in.';
        }
    }
}

// Ambil daftar fakultas & prodi dari database
$fakultas_list = $pdo->query(
    "SELECT id, nama FROM ref_fakultas WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();

$prodi_all = $pdo->query(
    "SELECT ps.id, ps.nama, ps.fakultas_id
     FROM ref_program_studi ps
     WHERE ps.is_active=1
     ORDER BY ps.urutan, ps.nama"
)->fetchAll();

// Kelompokkan prodi by fakultas_id (untuk JS)
$prodi_by_fak = [];
foreach ($prodi_all as $p) {
    $prodi_by_fak[$p['fakultas_id']][] = $p;
}

$post_role = $_POST['role_reg'] ?? 'mahasiswa';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id' ? 'Daftar Akun' : 'Register' ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css?v=4">
<style>
/* Role toggle */
.role-toggle {
  display: flex;
  background: #f1f5f9;
  border-radius: 10px;
  padding: 4px;
  gap: 4px;
  margin-bottom: 20px;
}
.role-toggle label {
  flex: 1;
  text-align: center;
  padding: 9px 12px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  color: #64748b;
  transition: background .15s, color .15s, box-shadow .15s;
  user-select: none;
}
.role-toggle input[type="radio"] { display: none; }
.role-toggle input[type="radio"]:checked + label {
  background: #fff;
  color: var(--primary, #4a1d96);
  box-shadow: 0 1px 4px rgba(74,29,150,.18);
}
</style>
</head>
<body>
<div class="login-page" style="padding:30px 20px">
  <div style="position:relative;z-index:1;width:100%;max-width:520px;margin:0 auto">
    <div style="text-align:right;margin-bottom:12px">
      <button class="lang-toggle" onclick="toggleLang()">
        <?= ic('globe') ?> <?= $lang === 'id' ? 'English' : 'Indonesia' ?>
      </button>
    </div>

    <div class="login-box" style="max-width:100%">
      <div class="login-logo">
        <div class="logo-badge"><?= ic('building') ?></div>
        <h1 id="reg-heading">
          <?= $lang==='id' ? 'Daftar Akun' : 'Account Registration' ?>
        </h1>
        <p>LPPM IAKN Toraja</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success">
          <?= $success ?>
          <a href="login.php" style="font-weight:700;margin-left:8px">
            <?= $lang==='id' ? 'Login sekarang →' : 'Sign in now →' ?>
          </a>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" id="regForm">

        <!-- Pilihan jenis akun -->
        <div class="role-toggle">
          <input type="radio" name="role_reg" id="r-mhs" value="mahasiswa"
                 <?= $post_role !== 'dosen' ? 'checked' : '' ?>
                 onchange="switchRole('mahasiswa')">
          <label for="r-mhs">🎓 <?= $lang==='id' ? 'Mahasiswa' : 'Student' ?></label>

          <input type="radio" name="role_reg" id="r-dsn" value="dosen"
                 <?= $post_role === 'dosen' ? 'checked' : '' ?>
                 onchange="switchRole('dosen')">
          <label for="r-dsn">👨‍🏫 <?= $lang==='id' ? 'Dosen' : 'Lecturer' ?></label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">

          <!-- Nama lengkap — selalu tampil -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">
              <?= $lang==='id' ? 'Nama Lengkap' : 'Full Name' ?> <span class="required">*</span>
            </label>
            <input type="text" name="nama" class="form-control"
                   value="<?= clean($_POST['nama']??'') ?>" required>
          </div>

          <!-- ── Mahasiswa: NIM + Angkatan ── -->
          <div class="form-group row-mhs">
            <label class="form-label">NIM <span class="required">*</span></label>
            <input type="text" name="nim" id="inp-nim" class="form-control"
                   value="<?= clean($_POST['nim']??'') ?>">
          </div>
          <div class="form-group row-mhs">
            <label class="form-label">
              <?= $lang==='id' ? 'Angkatan' : 'Year of Entry' ?>
            </label>
            <input type="number" name="angkatan" class="form-control"
                   min="2010" max="<?= date('Y') ?>"
                   value="<?= clean($_POST['angkatan']??'') ?>">
          </div>

          <!-- ── Dosen: NIDN + NIP ── -->
          <div class="form-group row-dsn" style="display:none">
            <label class="form-label">
              NIDN <span class="required">*</span>
            </label>
            <input type="text" name="nidn" id="inp-nidn" class="form-control"
                   placeholder="<?= $lang==='id'?'Nomor Induk Dosen Nasional':'National Lecturer ID' ?>"
                   value="<?= clean($_POST['nidn']??'') ?>">
          </div>
          <div class="form-group row-dsn" style="display:none">
            <label class="form-label">
              NIP
              <span style="font-size:11px;color:var(--text-muted);font-weight:400"> — <?= $lang==='id'?'opsional':'optional' ?></span>
            </label>
            <input type="text" name="nip" class="form-control"
                   placeholder="<?= $lang==='id'?'Nomor Induk Pegawai (jika ada)':'Employee ID (if any)' ?>"
                   value="<?= clean($_POST['nip']??'') ?>">
          </div>

          <!-- Fakultas — selalu tampil -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">
              <?= $lang==='id' ? 'Fakultas' : 'Faculty' ?> <span class="required">*</span>
            </label>
            <select name="fakultas" id="sel-fak" class="form-control" required
                    onchange="updateProdi(this.value)">
              <option value=""><?= $lang==='id' ? '-- Pilih Fakultas --' : '-- Select Faculty --' ?></option>
              <?php foreach ($fakultas_list as $f): ?>
                <option value="<?= htmlspecialchars($f['nama']) ?>"
                        data-id="<?= $f['id'] ?>"
                        <?= ($_POST['fakultas']??'')===$f['nama']?'selected':'' ?>>
                  <?= htmlspecialchars($f['nama']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Program Studi — selalu tampil -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">
              <?= $lang==='id' ? 'Program Studi' : 'Study Program' ?> <span class="required">*</span>
            </label>
            <select name="prodi" id="sel-prodi" class="form-control" required>
              <option value=""><?= $lang==='id' ? '-- Pilih Fakultas dulu --' : '-- Select Faculty first --' ?></option>
              <?php
                $sel_fak_id = 0;
                foreach ($fakultas_list as $f) {
                    if ($f['nama'] === ($_POST['fakultas'] ?? '')) { $sel_fak_id = $f['id']; break; }
                }
                if ($sel_fak_id && isset($prodi_by_fak[$sel_fak_id])) {
                    foreach ($prodi_by_fak[$sel_fak_id] as $p) {
                        $sel = ($p['nama'] === ($_POST['prodi'] ?? '')) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($p['nama']) . '" ' . $sel . '>' . htmlspecialchars($p['nama']) . '</option>';
                    }
                }
              ?>
            </select>
          </div>

          <!-- No HP — selalu tampil -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">
              <?= $lang==='id' ? 'No. HP / WhatsApp' : 'Phone / WhatsApp' ?>
            </label>
            <input type="text" name="no_hp" class="form-control"
                   placeholder="08xxxxxxxxxx"
                   value="<?= clean($_POST['no_hp']??'') ?>">
          </div>

          <!-- Email — selalu tampil -->
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Email <span class="required">*</span></label>
            <input type="email" name="email" class="form-control"
                   value="<?= clean($_POST['email']??'') ?>" required>
          </div>

          <!-- Password -->
          <div class="form-group">
            <label class="form-label">
              <?= $lang==='id' ? 'Kata Sandi' : 'Password' ?> <span class="required">*</span>
            </label>
            <input type="password" name="password" class="form-control"
                   placeholder="Min. 8 karakter" required>
          </div>
          <div class="form-group">
            <label class="form-label">
              <?= $lang==='id' ? 'Konfirmasi Kata Sandi' : 'Confirm Password' ?> <span class="required">*</span>
            </label>
            <input type="password" name="password2" class="form-control"
                   placeholder="Ulangi password" required>
          </div>

        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">
          <?= $lang==='id' ? 'Daftar Sekarang' : 'Register Now' ?> →
        </button>
      </form>
      <?php endif; ?>

      <p style="text-align:center;margin-top:16px;font-size:13px;color:#94a3b8">
        <?= $lang==='id' ? 'Sudah punya akun?' : 'Already have an account?' ?>
        <a href="login.php" style="color:var(--primary);font-weight:600">
          <?= $lang==='id' ? 'Login di sini' : 'Sign in here' ?>
        </a>
      </p>
    </div>

    <div class="login-footer">
      &copy; <?= date('Y') ?> &nbsp;<strong>DIPACOM</strong>&nbsp; All Rights Reserved
    </div>
  </div>
</div>
<script>
const LANG = document.documentElement.lang === 'en' ? 1 : 0;

// Data prodi per fakultas (dari server)
const prodiData = <?= json_encode($prodi_by_fak, JSON_UNESCAPED_UNICODE) ?>;
const fakMap = {};
<?php foreach ($fakultas_list as $f): ?>
fakMap[<?= json_encode($f['nama']) ?>] = <?= $f['id'] ?>;
<?php endforeach; ?>

function updateProdi(namaFak) {
  const sel   = document.getElementById('sel-prodi');
  const fakId = fakMap[namaFak];
  const items = prodiData[fakId] || [];
  const label = namaFak
    ? (LANG ? '-- Select Study Program --' : '-- Pilih Program Studi --')
    : (LANG ? '-- Select Faculty first --' : '-- Pilih Fakultas dulu --');

  sel.innerHTML = '<option value="">' + label + '</option>';
  items.forEach(p => {
    const opt = document.createElement('option');
    opt.value       = p.nama;
    opt.textContent = p.nama;
    sel.appendChild(opt);
  });
}

function switchRole(role) {
  const mhsRows = document.querySelectorAll('.row-mhs');
  const dsnRows = document.querySelectorAll('.row-dsn');
  const heading = document.getElementById('reg-heading');

  if (role === 'dosen') {
    mhsRows.forEach(el => { el.style.display = 'none'; });
    dsnRows.forEach(el => { el.style.display = 'block'; });
    document.getElementById('inp-nim').removeAttribute('required');
    document.getElementById('inp-nidn').setAttribute('required', '');
    if (heading) heading.textContent = LANG ? 'Lecturer Account Registration' : 'Daftar Akun Dosen';
  } else {
    mhsRows.forEach(el => { el.style.display = 'block'; });
    dsnRows.forEach(el => { el.style.display = 'none'; });
    document.getElementById('inp-nidn').removeAttribute('required');
    document.getElementById('inp-nim').setAttribute('required', '');
    if (heading) heading.textContent = LANG ? 'Student Account Registration' : 'Daftar Akun Mahasiswa';
  }
}

// Inisialisasi saat page load
document.addEventListener('DOMContentLoaded', function () {
  // Sinkron dropdown prodi jika POST gagal
  const selFak = document.getElementById('sel-fak');
  if (selFak && selFak.value) updateProdi(selFak.value);

  // Terapkan role yang dipilih (saat reload setelah error validasi)
  const checked = document.querySelector('input[name="role_reg"]:checked');
  if (checked) switchRole(checked.value);
});

function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (cur==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
