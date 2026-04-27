<?php
require_once 'includes/config.php';
require_once 'includes/profil_check.php';
requireLogin('mahasiswa');

$lang    = $_COOKIE['lang'] ?? 'id';
$uid     = $_SESSION['user_id'];
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Upload foto profil ---
    if (!empty($_FILES['foto']['name'])) {
        $ext  = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['foto']['tmp_name']);
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
        
        if (!in_array($ext, $allowed) || !in_array($mime, $allowed_mimes)) {
            $error = $lang==='id' ? 'Format foto harus JPG, PNG, atau WEBP yang valid.' : 'Photo must be valid JPG, PNG, or WEBP.';
        } elseif ($_FILES['foto']['size'] > 2 * 1024 * 1024) {
            $error = $lang==='id' ? 'Ukuran foto maksimal 2 MB.' : 'Max photo size is 2 MB.';
        } else {
            $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
            $dest     = BASE_PATH . '/uploads/avatar/' . $filename;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                // Hapus foto lama
                $old = $pdo->query("SELECT foto_profil FROM users WHERE id=$uid")->fetchColumn();
                if ($old && file_exists(BASE_PATH . '/' . $old)) unlink(BASE_PATH . '/' . $old);

                $pdo->prepare("UPDATE users SET foto_profil=? WHERE id=?")
                    ->execute(['uploads/avatar/' . $filename, $uid]);
                $_SESSION['foto_profil'] = 'uploads/avatar/' . $filename;
            }
        }
    }

    // --- Update data profil ---
    if (!$error) {
        $nama     = clean($_POST['nama'] ?? '');
        $hp       = clean($_POST['no_hp'] ?? '');
        $fakultas = clean($_POST['fakultas'] ?? '');
        $prodi    = clean($_POST['prodi'] ?? '');
        if ($nama) {
            $pdo->prepare("UPDATE users SET nama_lengkap=?, no_hp=?, fakultas=?, program_studi=? WHERE id=?")
                ->execute([$nama, $hp, $fakultas, $prodi, $uid]);
            $_SESSION['nama']     = $nama;
            $_SESSION['fakultas'] = $fakultas;
            $_SESSION['prodi']    = $prodi;
        }

        // --- Ganti password ---
        if (!empty($_POST['pw_baru'])) {
            if ($_POST['pw_baru'] !== $_POST['pw_konfirm']) {
                $error = $lang==='id' ? 'Konfirmasi password tidak cocok.' : 'Password confirmation does not match.';
            } elseif (strlen($_POST['pw_baru']) < 8) {
                $error = $lang==='id' ? 'Password minimal 8 karakter.' : 'Password must be at least 8 characters.';
            } else {
                $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                    ->execute([password_hash($_POST['pw_baru'], PASSWORD_DEFAULT), $uid]);
            }
        }
        if (!$error) $success = $lang==='id' ? 'Profil berhasil diperbarui.' : 'Profile updated successfully.';
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

// Sync foto ke session
if ($user['foto_profil']) $_SESSION['foto_profil'] = $user['foto_profil'];

$fakultas_list = $pdo->query(
    "SELECT id, nama FROM ref_fakultas WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();
$prodi_all = $pdo->query(
    "SELECT id, nama, fakultas_id FROM ref_program_studi WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();
$prodi_by_fak = [];
foreach ($prodi_all as $p) $prodi_by_fak[$p['fakultas_id']][] = $p;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Profil Saya':'My Profile' ?> — LPPM</title>
<link rel="stylesheet" href="assets/css/style.css?v=4">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js" defer></script>
</head>
<body>
<div class="wrapper">
  <?php include 'includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('user') ?> <?= $lang==='id'?'Profil Saya':'My Profile' ?>
          <span class="breadcrumb"><?= htmlspecialchars($user['email']) ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content profile-page">

      <?php if($success): ?>
        <div class="alert alert-success"><?= ic('check-circle') ?> <?= $success ?></div>
      <?php endif; ?>
      <?php if($error): ?>
        <div class="alert alert-danger"><?= ic('x-circle') ?> <?= $error ?></div>
      <?php endif; ?>

      <?php
      // Cek kelengkapan profil setelah kemungkinan save
      $pc = cekProfilLengkap($pdo, $uid);
      if (!$pc['lengkap']): ?>
      <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;
                  padding:14px 18px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <span style="font-size:13px;font-weight:700;color:#92400e">
            <?= $lang==='id'
              ? 'Data berikut harus dilengkapi sebelum dapat mengajukan permohonan:'
              : 'The following data must be completed before you can submit an application:' ?>
          </span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach ($pc['missing'] as $f): ?>
          <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;
                       border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600">
            <?= $lang==='id' ? $f['id'] : $f['en'] ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php elseif ($success): // profil sudah lengkap setelah save ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;
                  padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a"
             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span style="font-size:13px;font-weight:600;color:#166534">
          <?= $lang==='id'
            ? 'Profil sudah lengkap — Anda dapat mengajukan permohonan.'
            : 'Profile is complete — you can now submit applications.' ?>
        </span>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">

        <!-- Avatar card -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
            <!-- Preview foto -->
            <div style="position:relative;flex-shrink:0">
              <div id="avatar-preview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:var(--accent-light);border:3px solid var(--border)">
                <?php if ($user['foto_profil'] && file_exists(BASE_PATH.'/'.$user['foto_profil'])): ?>
                  <img src="<?= BASE_URL ?>/<?= htmlspecialchars($user['foto_profil']) ?>?<?= time() ?>" alt="foto"
                       style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                  <?= strtoupper(mb_substr($user['nama_lengkap'],0,1)) ?>
                <?php endif; ?>
              </div>
              <label for="foto" style="position:absolute;bottom:0;right:0;width:26px;height:26px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid #fff">
                <?= ic('edit', 'style="width:11px;height:11px;color:#fff"') ?>
              </label>
            </div>
            <div>
              <div style="font-size:15px;font-weight:600;color:var(--text-primary)"><?= htmlspecialchars($user['nama_lengkap']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                <?php if ($user['role'] === 'dosen'): ?>
                  <?= $user['nidn'] ? 'NIDN: '.htmlspecialchars($user['nidn']) : ($user['nip'] ? 'NIP: '.htmlspecialchars($user['nip']) : '') ?>
                <?php else: ?>
                  <?= htmlspecialchars($user['nim']??'') ?>
                <?php endif; ?>
                <?php if($user['program_studi']): ?>&middot; <?= htmlspecialchars($user['program_studi']) ?><?php endif; ?>
              </div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
                <?= $lang==='id'?'Klik ikon pensil untuk ganti foto':'Click the pencil icon to change photo' ?>
                <span style="color:var(--text-muted);font-size:10px">(JPG/PNG/WEBP, maks 2 MB)</span>
              </div>
            </div>
            <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp"
                   style="display:none" onchange="previewFoto(this)">
          </div>
        </div>

        <!-- Data profil -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title"><?= ic('user') ?> <?= $lang==='id'?'Informasi Akun':'Account Information' ?></span>
          </div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
              <div class="form-group" style="grid-column:1/-1">
                <label class="form-label"><?= $lang==='id'?'Nama Lengkap':'Full Name' ?> <span class="required">*</span></label>
                <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($user['nama_lengkap']) ?>" required>
              </div>
              <?php if ($user['role'] === 'dosen'): ?>
              <div class="form-group">
                <label class="form-label">NIDN <span class="lang" style="text-transform:none;letter-spacing:0"><?= $lang==='id'?'(Nomor Induk Dosen Nasional)':'(National Lecturer ID)' ?></span></label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['nidn']??'') ?>" disabled style="background:var(--bg-field)">
                <div class="form-hint"><?= $lang==='id'?'NIDN tidak dapat diubah':'NIDN cannot be changed' ?></div>
              </div>
              <?php if (!empty($user['nip'])): ?>
              <div class="form-group">
                <label class="form-label">NIP <span class="lang" style="text-transform:none;letter-spacing:0"><?= $lang==='id'?'(Nomor Induk Pegawai)':'(Employee ID)' ?></span></label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['nip']) ?>" disabled style="background:var(--bg-field)">
                <div class="form-hint"><?= $lang==='id'?'NIP tidak dapat diubah':'NIP cannot be changed' ?></div>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <div class="form-group">
                <label class="form-label">NIM</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['nim']??'') ?>" disabled style="background:var(--bg-field)">
                <div class="form-hint"><?= $lang==='id'?'NIM tidak dapat diubah':'NIM cannot be changed' ?></div>
              </div>
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'No. HP / WhatsApp':'Phone / WhatsApp' ?></label>
                <input type="text" name="no_hp" class="form-control" value="<?= htmlspecialchars($user['no_hp']??'') ?>">
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label class="form-label"><?= $lang==='id'?'Fakultas':'Faculty' ?></label>
                <select name="fakultas" id="sel-fak" class="form-control"
                        onchange="updateProdi(this.value)">
                  <option value=""><?= $lang==='id'?'-- Pilih Fakultas --':'-- Select Faculty --' ?></option>
                  <?php foreach ($fakultas_list as $f): ?>
                    <option value="<?= htmlspecialchars($f['nama']) ?>"
                            data-id="<?= $f['id'] ?>"
                            <?= ($user['fakultas']??'')===$f['nama']?'selected':'' ?>>
                      <?= htmlspecialchars($f['nama']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label class="form-label"><?= $lang==='id'?'Program Studi':'Study Program' ?></label>
                <select name="prodi" id="sel-prodi" class="form-control">
                  <?php
                    // Cari fakultas_id user saat ini
                    $cur_fak_id = 0;
                    foreach ($fakultas_list as $f) {
                        if ($f['nama'] === ($user['fakultas'] ?? '')) { $cur_fak_id = $f['id']; break; }
                    }
                    if ($cur_fak_id && !empty($prodi_by_fak[$cur_fak_id])) {
                        foreach ($prodi_by_fak[$cur_fak_id] as $p) {
                            $sel = $p['nama'] === ($user['program_studi'] ?? '') ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($p['nama']) . '" ' . $sel . '>' . htmlspecialchars($p['nama']) . '</option>';
                        }
                    } else {
                        echo '<option value="">-- Pilih Fakultas dulu --</option>';
                    }
                  ?>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background:var(--bg-field)">
                <div class="form-hint"><?= $lang==='id'?'Email tidak dapat diubah':'Email cannot be changed' ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Ganti password -->
        <div class="card" style="margin-bottom:20px">
          <div class="card-header">
            <span class="card-title"><?= ic('lock') ?> <?= $lang==='id'?'Ganti Password':'Change Password' ?></span>
            <span style="font-size:11px;color:var(--text-muted)"><?= $lang==='id'?'Kosongkan jika tidak ingin mengganti':'Leave blank to keep current password' ?></span>
          </div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Password Baru':'New Password' ?></label>
                <div style="position:relative">
                  <input type="password" name="pw_baru" id="pw1" class="form-control" placeholder="Min. 8 karakter">
                  <button type="button" onclick="togglePw('pw1','e1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;align-items:center">
                    <svg id="e1" class="ic" style="width:15px;height:15px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label"><?= $lang==='id'?'Konfirmasi Password':'Confirm Password' ?></label>
                <div style="position:relative">
                  <input type="password" name="pw_konfirm" id="pw2" class="form-control" placeholder="Ulangi password baru">
                  <button type="button" onclick="togglePw('pw2','e2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;padding:4px;display:flex;align-items:center">
                    <svg id="e2" class="ic" style="width:15px;height:15px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">
          <?= ic('check') ?> <?= $lang==='id'?'Simpan Perubahan':'Save Changes' ?>
        </button>
      </form>
    </div>
  </div>
</div>
<script>
const prodiData = <?= json_encode($prodi_by_fak, JSON_UNESCAPED_UNICODE) ?>;
const fakMap    = {};
<?php foreach ($fakultas_list as $f): ?>
fakMap[<?= json_encode($f['nama']) ?>] = <?= $f['id'] ?>;
<?php endforeach; ?>

function updateProdi(namaFak) {
  const sel   = document.getElementById('sel-prodi');
  const fakId = fakMap[namaFak];
  const items = prodiData[fakId] || [];
  sel.innerHTML = items.length
    ? ''
    : '<option value="">-- Pilih Fakultas dulu --</option>';
  items.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.nama; opt.textContent = p.nama;
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  const selFak = document.getElementById('sel-fak');
  if (selFak && selFak.value) updateProdi(selFak.value);
});

/* ── CROP FOTO PROFIL (#6) ────────────────────────
   Saat user pilih file → buka modal cropper.js (1:1 aspect),
   user atur posisi/zoom → klik "Crop & Simpan" → file ter-replace
   di input file via DataTransfer API. */
let _cropper = null;
function previewFoto(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const allowed = ['image/jpeg','image/png','image/webp'];
  if (!allowed.includes(file.type)) {
    alert('Format harus JPG, PNG, atau WEBP');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('crop-img').src = e.target.result;
    const m = document.getElementById('cropModal');
    m.style.display = 'flex';
    if (_cropper) _cropper.destroy();
    _cropper = new Cropper(document.getElementById('crop-img'), {
      aspectRatio: 1,
      viewMode: 1,
      autoCropArea: 0.9,
      dragMode: 'move',
      cropBoxResizable: true,
      cropBoxMovable: true,
      background: false,
      guides: true,
    });
  };
  reader.readAsDataURL(file);
}
function closeCropModal() {
  document.getElementById('cropModal').style.display = 'none';
  if (_cropper) { _cropper.destroy(); _cropper = null; }
  document.getElementById('foto').value = '';
}
function applyCrop() {
  if (!_cropper) return;
  _cropper.getCroppedCanvas({ width:512, height:512, fillColor:'#fff', imageSmoothingQuality:'high' })
    .toBlob(blob => {
      const inp = document.getElementById('foto');
      const newFile = new File([blob], 'avatar_cropped.jpg', { type:'image/jpeg' });
      const dt = new DataTransfer();
      dt.items.add(newFile);
      inp.files = dt.files;
      // Update preview
      const prev = document.getElementById('avatar-preview');
      const url  = URL.createObjectURL(blob);
      prev.innerHTML = '<img src="'+url+'" style="width:100%;height:100%;object-fit:cover">';
      closeCropModal();
    }, 'image/jpeg', 0.92);
}
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

<!-- ══════ MODAL CROP FOTO PROFIL (#6) ══════ -->
<div id="cropModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.78);
     z-index:1500;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(6px)"
     onclick="if(event.target===this) closeCropModal()">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:540px;max-height:92vh;overflow:hidden;
              box-shadow:0 25px 70px rgba(0,0,0,.4);display:flex;flex-direction:column">
    <div style="padding:14px 18px;border-bottom:1.5px solid #e2e8f0;display:flex;align-items:center;gap:10px">
      <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#4a1d96,#7c3aed);display:flex;align-items:center;justify-content:center">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M6.13 1L6 16a2 2 0 0 0 2 2h15"/><path d="M1 6.13L16 6a2 2 0 0 1 2 2v15"/>
        </svg>
      </div>
      <div style="flex:1">
        <div style="font-size:13.5px;font-weight:800;color:#1e293b"><?= $lang==='id'?'Crop Foto Profil':'Crop Profile Photo' ?></div>
        <div style="font-size:11px;color:#64748b">Drag &amp; zoom untuk atur posisi · ratio 1:1</div>
      </div>
      <button type="button" onclick="closeCropModal()" style="background:none;border:none;color:#64748b;font-size:22px;cursor:pointer;line-height:1">×</button>
    </div>
    <div style="background:#0f172a;height:380px;overflow:hidden">
      <img id="crop-img" src="" style="display:block;max-width:100%">
    </div>
    <div style="padding:12px 18px;border-top:1.5px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;background:#f8fafc">
      <button type="button" onclick="closeCropModal()" class="btn btn-outline" style="font-size:13px"><?= $lang==='id'?'Batal':'Cancel' ?></button>
      <button type="button" onclick="applyCrop()" class="btn btn-primary" style="font-size:13px;background:linear-gradient(135deg,#4a1d96,#7c3aed);border:none">
        ✂ <?= $lang==='id'?'Crop &amp; Pakai':'Crop &amp; Use' ?>
      </button>
    </div>
  </div>
</div>

</body>
</html>
