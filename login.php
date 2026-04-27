<?php
require_once 'includes/config.php';
require_once 'includes/logger.php';

// Redirect jika sudah login
if (isLoggedIn()) {
    if (isAdmin()) redirect('/admin/dashboard.php');
    elseif (isReviewer()) redirect('/modules/penelitian/reviewer.php');
    else redirect('/dashboard.php');
}

$error = '';
$lang  = $_COOKIE['lang'] ?? 'id';

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = $lang === 'id' ? 'Email dan password wajib diisi.' : 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['nama']       = $user['nama_lengkap'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['nim']        = $user['nim'];
            $_SESSION['nidn']       = $user['nidn'] ?? null;
            $_SESSION['nip']        = $user['nip']  ?? null;
            $_SESSION['fakultas']   = $user['fakultas'];
            $_SESSION['prodi']      = $user['program_studi'];
            $_SESSION['foto_profil']= $user['foto_profil'] ?? null;

            writeLog($pdo, $user['id'], $user['role'], 'login',
                "Login berhasil dari {$_SERVER['REMOTE_ADDR']}", $user['nama_lengkap']);
            // Update last_login
            try { $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]); } catch(Throwable $e){}
            if ($user['role'] === 'admin') redirect('/admin/dashboard.php');
            elseif ($user['role'] === 'reviewer') redirect('/modules/penelitian/reviewer.php');
            else redirect('/dashboard.php');
        } else {
            $namaGagal = $user['nama_lengkap'] ?? null;
            writeLog($pdo, $user['id'] ?? null, $user['role'] ?? 'system', 'login_gagal',
                "Login gagal: email={$email}", $namaGagal);
            $error = $lang === 'id' ? 'Email atau password salah.' : 'Incorrect email or password.';
        }
    }
}

$t = [
    'title'       => ['id' => 'Masuk ke Sistem',        'en' => 'Sign In to System'],
    'subtitle'    => ['id' => 'LPPM IAKN Toraja',        'en' => 'LPPM IAKN Toraja'],
    'email'       => ['id' => 'Email',                   'en' => 'Email'],
    'password'    => ['id' => 'Kata Sandi',              'en' => 'Password'],
    'login_btn'   => ['id' => 'Masuk',                   'en' => 'Sign In'],
    'no_account'  => ['id' => 'Belum punya akun?',       'en' => "Don't have an account?"],
    'register'    => ['id' => 'Daftar di sini',          'en' => 'Register here'],
    'system_name' => ['id' => 'Sistem Informasi LPPM',   'en' => 'LPPM Information System'],
    'desc'        => ['id' => 'Surat Keterangan Bebas Plagiasi & Publikasi', 'en' => 'Plagiarism-Free & Publication Certificates'],
];
function tx($t, $key, $lang) { return $t[$key][$lang] ?? $t[$key]['id']; }
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= tx($t,'title',$lang) ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css?v=4">
<style>
  .login-page::before {
    content: '';
    position: fixed;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
    pointer-events: none;
  }
</style>
</head>
<body>
<div class="login-page">
  <div style="position:relative;z-index:1">
    <!-- Language toggle -->
    <div style="text-align:right;margin-bottom:12px">
      <button class="lang-toggle" onclick="toggleLang()">
        <?= ic('globe') ?> <?= $lang === 'id' ? 'English' : 'Indonesia' ?>
      </button>
    </div>

    <div class="login-box">
      <div class="login-logo">
        <div class="logo-badge"><?= ic('building') ?></div>
        <h1><?= tx($t,'subtitle',$lang) ?></h1>
        <p><?= tx($t,'system_name',$lang) ?></p>
        <p style="font-size:12px;color:#94a3b8;margin-top:4px"><?= tx($t,'desc',$lang) ?></p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">
            <?= tx($t,'email',$lang) ?> <span class="required">*</span>
          </label>
          <input type="email" name="email" class="form-control"
                 placeholder="mahasiswa@email.com"
                 value="<?= clean($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">
            <?= tx($t,'password',$lang) ?> <span class="required">*</span>
          </label>
          <div style="position:relative">
            <input type="password" name="password" id="pwd" class="form-control"
                   placeholder="••••••••" required>
            <button type="button" id="pwd-toggle" onclick="togglePwd()"
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;align-items:center;line-height:1">
              <svg id="eye-icon" class="ic" style="width:17px;height:17px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px">
          <?= tx($t,'login_btn',$lang) ?> →
        </button>
      </form>

      <p style="text-align:center;margin-top:20px;font-size:13px;color:#94a3b8">
        <?= tx($t,'no_account',$lang) ?>
        <a href="lupa_password.php" style="color:#94a3b8;font-size:11px">Lupa password?</a>
      <a href="register.php" style="color:var(--primary);font-weight:600"><?= tx($t,'register',$lang) ?></a>
      </p>

    </div>

    <div class="login-footer">
      &copy; <?= date('Y') ?> &nbsp;<strong>DIPACOM</strong>&nbsp; All Rights Reserved
    </div>
  </div>
</div>
<script>
function togglePwd() {
  const p = document.getElementById('pwd');
  const show = p.type === 'password';
  p.type = show ? 'text' : 'password';
  document.getElementById('eye-icon').innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}
function toggleLang() {
  const cur = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  const next = cur === 'id' ? 'en' : 'id';
  document.cookie = 'lang=' + next + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
