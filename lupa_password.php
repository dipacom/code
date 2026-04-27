<?php
require_once 'includes/config.php';
if (isLoggedIn()) redirect('/dashboard.php');

$lang    = $_COOKIE['lang'] ?? 'id';
$step    = $_GET['step'] ?? 'email';   // email → sent → reset → done
$token   = $_GET['token'] ?? '';
$success = $error = '';

// ── STEP 1: Kirim email reset ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'email') {
    $email = clean($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $lang==='id' ? 'Format email tidak valid.' : 'Invalid email format.';
    } else {
        $stmt = $pdo->prepare("SELECT id, nama_lengkap FROM users WHERE email=? AND is_active=1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate token unik
            $tok  = bin2hex(random_bytes(32));
            $exp  = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Buat tabel password_resets jika belum ada (auto-migrate)
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                email VARCHAR(255) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX(email),
                INDEX(token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Simpan token di database (hapus token lama untuk email ini bila ada)
            $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);
            $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")->execute([$email, $tok, $exp]);

            // Kirim email reset
            require_once 'includes/email.php';
            $resetUrl = BASE_URL . '/lupa_password.php?step=reset&token=' . $tok;

            $mail = _smtpSetup();
            $mail->addAddress($email, $user['nama_lengkap']);
            $mail->addCC(MAIL_CC_LPPM, MAIL_NAME_LPPM);
            $mail->isHTML(true);
            $mail->Subject = '[LPPM IAKN Toraja] Reset Password';
            $mail->Body    = _emailHeader() . "
              <div style='padding:24px'>
                <p style='color:#374151;font-size:13px;margin:0 0 12px'>
                  Yth. <strong>{$user['nama_lengkap']}</strong>,
                </p>
                <p style='color:#374151;font-size:13px;margin:0 0 16px'>
                  Kami menerima permintaan reset password untuk akun Anda.
                  Klik tombol di bawah untuk membuat password baru:
                </p>
                <a href='{$resetUrl}'
                   style='display:inline-block;background:#1a3354;color:#fff;padding:11px 22px;
                          border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
                  Reset Password &#8594;
                </a>
                <p style='color:#94a3b8;font-size:11px;margin-top:14px;line-height:1.6'>
                  Link ini berlaku selama <strong>1 jam</strong>.<br>
                  Jika Anda tidak meminta reset password, abaikan email ini.
                </p>
              </div>" . _emailFooter();
            $mail->AltBody = "Klik link ini untuk reset password: {$resetUrl} (berlaku 1 jam)";

            try { $mail->send(); } catch (\Exception $e) { error_log($e->getMessage()); }
        }
        // Selalu tampilkan pesan sukses (keamanan — tidak bocorkan apakah email terdaftar)
        redirect('/lupa_password.php?step=sent');
    }
}

// ── STEP 3: Simpan password baru ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'reset') {
    $pw  = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    $tok = $_POST['token']     ?? '';

    // Cek token di database
    $stmt = $pdo->prepare("SELECT email, expires_at FROM password_resets WHERE token=?");
    $stmt->execute([$tok]);
    $resetData = $stmt->fetch();

    if (!$resetData) {
        $error = $lang==='id' ? 'Token tidak valid atau tidak ditemukan.' : 'Invalid or missing token.';
    } elseif (strtotime($resetData['expires_at']) < time()) {
        $error = $lang==='id' ? 'Link reset sudah kedaluwarsa. Silakan minta lagi.' : 'Reset link has expired. Please request again.';
    } elseif (strlen($pw) < 8) {
        $error = $lang==='id' ? 'Password minimal 8 karakter.' : 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = $lang==='id' ? 'Konfirmasi password tidak cocok.' : 'Passwords do not match.';
    } else {
        $hash  = password_hash($pw, PASSWORD_DEFAULT);
        $email = $resetData['email'];
        $pdo->prepare("UPDATE users SET password=? WHERE email=?")->execute([$hash, $email]);

        // Hapus token dari database setelah berhasil digunakan
        $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);
        
        redirect('/lupa_password.php?step=done');
    }
}

// ── STEP 2 via GET: verifikasi token dari link email ──
if ($step === 'reset' && $token) {
    $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token=? AND expires_at > NOW()");
    $stmt->execute([$token]);
    if (!$stmt->fetch()) {
        $error = $lang==='id' ? 'Link tidak valid atau sudah kedaluwarsa.' : 'Link is invalid or expired.';
        $step  = 'email';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Lupa Password':'Forgot Password' ?> — LPPM IAKN Toraja</title>
<link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
<link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
<div class="login-page">
  <div style="width:100%;max-width:420px">

    <!-- Lang toggle -->
    <div style="text-align:right;margin-bottom:12px">
      <button class="lang-btn" onclick="toggleLang()">
        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
        <?= $lang==='id'?'English':'Indonesia' ?>
      </button>
    </div>

    <div style="background:var(--bg-card);border-radius:var(--radius-lg);padding:36px 32px;box-shadow:0 20px 60px rgba(0,0,0,.25)">

      <!-- Logo -->
      <div style="text-align:center;margin-bottom:24px">
        <div style="width:50px;height:50px;background:var(--navy);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
          <svg class="ic" style="width:24px;height:24px;stroke:#c9952a" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        </div>
        <h2 style="font-size:17px;margin-bottom:4px">
          <?php
          if ($step==='email')  echo $lang==='id'?'Lupa Password':'Forgot Password';
          if ($step==='sent')   echo $lang==='id'?'Email Terkirim':'Email Sent';
          if ($step==='reset')  echo $lang==='id'?'Buat Password Baru':'Create New Password';
          if ($step==='done')   echo $lang==='id'?'Password Berhasil Diubah':'Password Changed';
          ?>
        </h2>
        <p style="font-size:11px;color:var(--text-muted)">LPPM IAKN Toraja</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>

      <?php if ($step === 'email'): ?>
        <p style="font-size:11px;color:var(--text-muted);margin-bottom:18px;text-align:center;line-height:1.6">
          <?= $lang==='id'
            ? 'Masukkan email yang terdaftar. Kami akan mengirimkan link untuk membuat password baru.'
            : 'Enter your registered email. We will send a link to create a new password.' ?>
        </p>
        <form method="POST">
          <div class="form-group">
            <label class="form-label">Email <span class="req">*</span></label>
            <div class="input-wrap">
              <input type="email" name="email" class="form-control"
                     placeholder="email@anda.com" required
                     value="<?= clean($_POST['email']??'') ?>">
              <span class="input-ico">
                <svg class="ic" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              </span>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:6px">
            <svg class="ic" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            <?= $lang==='id'?'Kirim Link Reset':'Send Reset Link' ?>
          </button>
        </form>

      <?php elseif ($step === 'sent'): ?>
        <div class="alert alert-success" style="text-align:center">
          <div style="font-size:28px;margin-bottom:8px">📧</div>
          <strong><?= $lang==='id'?'Link reset telah dikirim!':'Reset link has been sent!' ?></strong><br>
          <span style="font-size:10px">
            <?= $lang==='id'
              ? 'Periksa email Anda dan klik link yang dikirimkan. Link berlaku 1 jam.'
              : 'Check your email and click the link we sent. Link is valid for 1 hour.' ?>
          </span>
        </div>
        <p style="font-size:10px;color:var(--text-muted);text-align:center;margin-top:8px">
          <?= $lang==='id'?'Tidak menerima email? Periksa folder spam atau':'Did not receive email? Check spam folder or' ?>
          <a href="<?= BASE_URL ?>/lupa_password.php" style="color:var(--navy);font-weight:600">
            <?= $lang==='id'?'coba lagi':'try again' ?>
          </a>
        </p>

      <?php elseif ($step === 'reset' && !$error): ?>
        <form method="POST">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="form-group">
            <label class="form-label"><?= $lang==='id'?'Password Baru':'New Password' ?> <span class="req">*</span></label>
            <input type="password" name="password" class="form-control"
                   placeholder="<?= $lang==='id'?'Min. 8 karakter':'Min. 8 characters' ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label"><?= $lang==='id'?'Konfirmasi Password':'Confirm Password' ?> <span class="req">*</span></label>
            <input type="password" name="password2" class="form-control"
                   placeholder="<?= $lang==='id'?'Ulangi password baru':'Repeat new password' ?>" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:6px">
            <svg class="ic" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?= $lang==='id'?'Simpan Password Baru':'Save New Password' ?>
          </button>
        </form>

      <?php elseif ($step === 'done'): ?>
        <div class="alert alert-success" style="text-align:center">
          <?= ic('check-circle', 'style="width:36px;height:36px;margin:0 auto 10px;display:block;color:#16a34a"') ?>
          <strong><?= $lang==='id'?'Password berhasil diubah!':'Password changed successfully!' ?></strong>
        </div>
        <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary btn-block btn-lg" style="margin-top:4px">
          <svg class="ic" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
          <?= $lang==='id'?'Login Sekarang':'Sign In Now' ?>
        </a>
      <?php endif; ?>

      <!-- Back to login -->
      <?php if ($step !== 'done'): ?>
      <p style="text-align:center;margin-top:18px;font-size:11px;color:var(--text-muted)">
        <a href="<?= BASE_URL ?>/login.php" style="color:var(--navy);font-weight:600;display:inline-flex;align-items:center;gap:4px">
          <svg class="ic ic-sm" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          <?= $lang==='id'?'Kembali ke halaman login':'Back to sign in' ?>
        </a>
      </p>
      <?php endif; ?>

    </div>

    <div class="login-footer">
      &copy; <?= date('Y') ?> &nbsp;<strong>DIPACOM</strong>&nbsp; All Rights Reserved
    </div>
  </div>
</div>
<script>
function toggleLang(){
  const cur=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';
  document.cookie='lang='+(cur==='id'?'en':'id')+';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
