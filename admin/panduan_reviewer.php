<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$id   = $lang === 'id';

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $pid       = (int)($_POST['id'] ?? 0);
        $judul     = clean($_POST['judul'] ?? '');
        $deskripsi = clean($_POST['deskripsi'] ?? '');
        $link_url  = trim($_POST['link_url'] ?? '');
        $urutan    = (int)($_POST['urutan'] ?? 99);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$judul) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Judul wajib diisi.':'Title required.'];
            redirect('/admin/panduan_reviewer.php');
        }

        // Ambil file lama jika edit
        $old = null;
        if ($action === 'edit' && $pid) {
            $oq = $pdo->prepare("SELECT file_path, file_name, file_size FROM panduan_reviewer WHERE id=?");
            $oq->execute([$pid]);
            $old = $oq->fetch();
        }

        $file_path = $old['file_path'] ?? null;
        $file_name = $old['file_name'] ?? null;
        $file_size = $old['file_size'] ?? null;

        if (!empty($_FILES['file']['name'])) {
            $f = $_FILES['file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','doc','docx','ppt','pptx'])) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'File harus PDF/DOC/DOCX/PPT/PPTX.':'File must be PDF/DOC/DOCX/PPT/PPTX.'];
                redirect('/admin/panduan_reviewer.php');
            }
            if ($f['size'] > 25 * 1024 * 1024) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Maks. 25 MB.':'Max 25 MB.'];
                redirect('/admin/panduan_reviewer.php');
            }
            $dir = BASE_PATH . '/uploads/panduan_reviewer/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = time() . '_' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $f['name']);
            if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                // Hapus file lama
                if ($file_path && file_exists(BASE_PATH . '/' . $file_path)) {
                    @unlink(BASE_PATH . '/' . $file_path);
                }
                $file_path = 'uploads/panduan_reviewer/' . $fname;
                $file_name = $f['name'];
                $file_size = $f['size'];
            }
        }

        if ($action === 'add') {
            $pdo->prepare("
                INSERT INTO panduan_reviewer
                  (judul, deskripsi, file_path, file_name, file_size, link_url, urutan, is_active, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $judul, $deskripsi ?: null,
                $file_path, $file_name, $file_size,
                $link_url ?: null,
                $urutan, $is_active,
                $_SESSION['user_id'],
            ]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Panduan ditambahkan.':'Guideline added.'];
        } else {
            $pdo->prepare("
                UPDATE panduan_reviewer SET
                  judul=?, deskripsi=?, file_path=?, file_name=?, file_size=?,
                  link_url=?, urutan=?, is_active=?
                WHERE id=?
            ")->execute([
                $judul, $deskripsi ?: null,
                $file_path, $file_name, $file_size,
                $link_url ?: null,
                $urutan, $is_active,
                $pid,
            ]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Panduan diperbarui.':'Guideline updated.'];
        }
        redirect('/admin/panduan_reviewer.php');
    }

    if ($action === 'delete') {
        $pid = (int)($_POST['id'] ?? 0);
        $oq = $pdo->prepare("SELECT file_path FROM panduan_reviewer WHERE id=?");
        $oq->execute([$pid]);
        $row = $oq->fetch();
        if ($row && $row['file_path'] && file_exists(BASE_PATH . '/' . $row['file_path'])) {
            @unlink(BASE_PATH . '/' . $row['file_path']);
        }
        $pdo->prepare("DELETE FROM panduan_reviewer WHERE id=?")->execute([$pid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Panduan dihapus.':'Guideline deleted.'];
        redirect('/admin/panduan_reviewer.php');
    }

    if ($action === 'toggle') {
        $pid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE panduan_reviewer SET is_active = 1 - is_active WHERE id=?")->execute([$pid]);
        redirect('/admin/panduan_reviewer.php');
    }
}

$panduan = $pdo->query("
    SELECT p.*, u.nama_lengkap AS uploader_nama
    FROM panduan_reviewer p
    LEFT JOIN users u ON u.id = p.uploaded_by
    ORDER BY p.urutan ASC, p.id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $id?'Panduan Reviewer':'Reviewer Guidelines' ?> — LPPM</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.panduan-row { background:var(--bg-card); border:1.5px solid var(--border); border-radius:11px;
  padding:13px 16px; margin-bottom:10px; display:flex; gap:13px; align-items:flex-start; }
.panduan-row.inactive { opacity:.55; }
.panduan-row-icon { width:38px; height:38px; border-radius:9px;
  background:linear-gradient(135deg,#4a1d96,#7c3aed); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:900;
  backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border-radius:16px; width:520px; max-width:95vw;
  box-shadow:0 8px 40px rgba(0,0,0,.18); }
.modal-head { padding:18px 20px 14px; border-bottom:1.5px solid var(--border); }
.modal-body { padding:18px 20px; }
.modal-foot { padding:12px 20px; border-top:1.5px solid var(--border);
  display:flex; gap:8px; justify-content:flex-end; }
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
          <?= $id?'Panduan Reviewer':'Reviewer Guidelines' ?>
        </div>
      </div>
      <div class="topbar-right">
        <button class="btn btn-primary" onclick="openModal()" style="font-size:12.5px">
          <?= ic('plus') ?> <?= $id?'Tambah Panduan':'Add Guideline' ?>
        </button>
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
          ? 'Panduan yang aktif akan muncul sebagai banner prominent di beranda reviewer agar dibaca sebelum menilai proposal. Anda bisa upload file (PDF/DOC/PPT) atau cantumkan link eksternal.'
          : 'Active guidelines appear as a prominent banner on the reviewer home page. You can upload files (PDF/DOC/PPT) or provide an external link.' ?></span>
      </div>

      <?php if (empty($panduan)): ?>
      <div style="text-align:center;padding:50px 20px;color:var(--text-muted)">
        <?= ic('shield','style="width:42px;height:42px;opacity:.4"') ?>
        <div style="margin-top:10px;font-size:14px;font-weight:600"><?= $id?'Belum ada panduan reviewer':'No guidelines yet' ?></div>
        <div style="font-size:12.5px;margin-top:5px"><?= $id?'Klik "Tambah Panduan" untuk mulai.':'Click "Add Guideline" to start.' ?></div>
      </div>
      <?php else: ?>

      <?php foreach ($panduan as $p): ?>
      <div class="panduan-row <?= $p['is_active']?'':'inactive' ?>">
        <div class="panduan-row-icon">
          <?= ic($p['file_path']?'file-text':'link','style="width:18px;height:18px;color:#fff"') ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:700;margin-bottom:3px">
            <?= htmlspecialchars($p['judul']) ?>
            <?php if (!$p['is_active']): ?>
            <span style="background:#f1f5f9;color:#64748b;font-size:10px;font-weight:600;padding:2px 8px;border-radius:8px;margin-left:6px"><?= $id?'Nonaktif':'Inactive' ?></span>
            <?php endif; ?>
          </div>
          <?php if ($p['deskripsi']): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px"><?= htmlspecialchars($p['deskripsi']) ?></div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:10px">
            <?php if ($p['file_path']): ?>
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($p['file_path']) ?>" target="_blank" style="color:var(--primary);font-weight:600">
              <?= ic('download','style="width:11px;height:11px;display:inline;vertical-align:-1px"') ?>
              <?= htmlspecialchars($p['file_name']) ?>
              <?php if ($p['file_size']): ?> (<?= formatFileSize($p['file_size']) ?>)<?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if ($p['link_url']): ?>
            <a href="<?= htmlspecialchars($p['link_url']) ?>" target="_blank" style="color:#0891b2;font-weight:600">
              <?= ic('link','style="width:11px;height:11px;display:inline;vertical-align:-1px"') ?>
              <?= htmlspecialchars(mb_strimwidth($p['link_url'],0,40,'…')) ?>
            </a>
            <?php endif; ?>
            <span><?= $id?'Urutan':'Order' ?>: <?= (int)$p['urutan'] ?></span>
          </div>
        </div>
        <div style="display:flex;gap:6px">
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-outline" style="font-size:11px;padding:5px 10px"
                    title="<?= $p['is_active']?($id?'Nonaktifkan':'Deactivate'):($id?'Aktifkan':'Activate') ?>">
              <?= ic($p['is_active']?'eye-off':'eye','style="width:13px;height:13px"') ?>
            </button>
          </form>
          <button class="btn btn-outline" style="font-size:11px;padding:5px 10px"
                  onclick="openModal(<?= htmlspecialchars(json_encode([
                    'id'=>(int)$p['id'],
                    'judul'=>$p['judul'],
                    'deskripsi'=>$p['deskripsi']??'',
                    'link_url'=>$p['link_url']??'',
                    'urutan'=>(int)$p['urutan'],
                    'is_active'=>(int)$p['is_active'],
                    'has_file'=>(bool)$p['file_path'],
                  ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG), ENT_QUOTES) ?>)">
            <?= ic('edit','style="width:13px;height:13px"') ?>
          </button>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $id?'Hapus panduan ini?':'Delete this guideline?' ?>')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-outline" style="font-size:11px;padding:5px 10px;color:#dc2626;border-color:#fecaca">
              <?= ic('trash','style="width:13px;height:13px"') ?>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>

      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Modal Add/Edit -->
<div class="modal-overlay" id="formModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <form method="POST" enctype="multipart/form-data" id="panduanForm">
      <input type="hidden" name="action" value="add" id="modalAction">
      <input type="hidden" name="id" value="" id="modalId">
      <div class="modal-head">
        <div style="font-size:14.5px;font-weight:700" id="modalTitle"><?= $id?'Tambah Panduan Reviewer':'Add Reviewer Guideline' ?></div>
      </div>
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:13px">
          <label class="form-label"><?= $id?'Judul':'Title' ?> <span class="required">*</span></label>
          <input type="text" name="judul" id="fJudul" class="form-control" required maxlength="200">
        </div>
        <div class="form-group" style="margin-bottom:13px">
          <label class="form-label"><?= $id?'Deskripsi singkat':'Short description' ?></label>
          <textarea name="deskripsi" id="fDesk" class="form-control" rows="2" maxlength="500"></textarea>
        </div>
        <div class="form-group" style="margin-bottom:13px">
          <label class="form-label">
            <?= $id?'File (PDF/DOC/PPT)':'File (PDF/DOC/PPT)' ?>
            <span style="color:var(--text-muted);font-weight:400"> — <?= $id?'opsional':'optional' ?></span>
          </label>
          <input type="file" name="file" accept=".pdf,.doc,.docx,.ppt,.pptx" class="form-control">
          <div id="fHasFile" style="font-size:11px;color:var(--text-muted);margin-top:4px;display:none">
            <?= $id?'File saat ini sudah ada. Upload baru akan menggantinya.':'A file already exists. Uploading replaces it.' ?>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:13px">
          <label class="form-label"><?= $id?'Atau Link Eksternal':'Or External Link' ?></label>
          <input type="url" name="link_url" id="fLink" class="form-control" placeholder="https://...">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group" style="margin:0">
            <label class="form-label"><?= $id?'Urutan':'Order' ?></label>
            <input type="number" name="urutan" id="fUrutan" class="form-control" value="99" min="0">
          </div>
          <div class="form-group" style="margin:0;display:flex;align-items:flex-end;padding-bottom:8px">
            <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;cursor:pointer">
              <input type="checkbox" name="is_active" id="fActive" checked style="width:15px;height:15px">
              <?= $id?'Aktif (tampil di beranda reviewer)':'Active (show on reviewer home)' ?>
            </label>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-outline" onclick="closeModal()"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><?= $id?'Simpan':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(data) {
  document.getElementById('modalAction').value = data ? 'edit' : 'add';
  document.getElementById('modalId').value     = data ? data.id : '';
  document.getElementById('modalTitle').textContent = data
    ? (<?= json_encode($id?'Edit Panduan':'Edit Guideline') ?>)
    : (<?= json_encode($id?'Tambah Panduan Reviewer':'Add Reviewer Guideline') ?>);
  document.getElementById('fJudul').value   = data ? data.judul : '';
  document.getElementById('fDesk').value    = data ? data.deskripsi : '';
  document.getElementById('fLink').value    = data ? data.link_url : '';
  document.getElementById('fUrutan').value  = data ? data.urutan : 99;
  document.getElementById('fActive').checked = data ? data.is_active==1 : true;
  document.getElementById('fHasFile').style.display = (data && data.has_file) ? 'block' : 'none';
  document.getElementById('formModal').classList.add('open');
}
function closeModal() {
  document.getElementById('formModal').classList.remove('open');
  document.getElementById('panduanForm').reset();
}
document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });
</script>
</body>
</html>
