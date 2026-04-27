<?php
/**
 * admin/ref_akademik.php
 * Kelola daftar Fakultas dan Program Studi.
 * Mendukung drag-and-drop reorder via SortableJS + AJAX.
 */
require_once '../includes/config.php';
requireLogin('admin');
$lang = $_COOKIE['lang'] ?? 'id';

// ── AJAX: Simpan urutan baru (drag & drop) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json');
    $tipe = $_GET['tab'] ?? 'fakultas';
    $ids  = array_map('intval', (array)($_POST['ids'] ?? []));
    $ids  = array_filter($ids);
    if ($ids) {
        $table = $tipe === 'prodi' ? 'ref_program_studi' : 'ref_fakultas';
        $stmt  = $pdo->prepare("UPDATE $table SET urutan=? WHERE id=?");
        foreach ($ids as $urutan => $id) {
            $stmt->execute([$urutan + 1, $id]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

$tab    = in_array($_GET['tab'] ?? '', ['prodi']) ? 'prodi' : 'fakultas';
$action = $_GET['action'] ?? '';
$msg    = $err = '';

// ── FAKULTAS ACTIONS ─────────────────────────────────────────
if ($tab === 'fakultas') {
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nama = trim(clean($_POST['nama'] ?? ''));
        if (!$nama) {
            $err = 'Nama fakultas wajib diisi.';
        } else {
            // Urutan = max + 1 (append ke bawah)
            $max = $pdo->query("SELECT COALESCE(MAX(urutan),0) FROM ref_fakultas")->fetchColumn();
            $pdo->prepare("INSERT INTO ref_fakultas (nama, urutan) VALUES (?,?)")
                ->execute([$nama, $max + 1]);
            $msg = 'Fakultas berhasil ditambahkan.';
        }
    }
    if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id    = (int)($_POST['id'] ?? 0);
        $nama  = trim(clean($_POST['nama'] ?? ''));
        $aktif = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $nama) {
            $pdo->prepare("UPDATE ref_fakultas SET nama=?, is_active=? WHERE id=?")
                ->execute([$nama, $aktif, $id]);
            $msg = 'Fakultas berhasil diperbarui.';
        }
    }
    if ($action === 'hapus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = (int)($_POST['id'] ?? 0);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM ref_program_studi WHERE fakultas_id=?");
        $cnt->execute([$id]);
        if ($cnt->fetchColumn() > 0) {
            $err = 'Tidak bisa dihapus karena masih ada program studi yang terkait.';
        } else {
            $pdo->prepare("DELETE FROM ref_fakultas WHERE id=?")->execute([$id]);
            $msg = 'Fakultas dihapus.';
        }
    }
}

// ── PRODI ACTIONS ─────────────────────────────────────────────
if ($tab === 'prodi') {
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nama        = trim(clean($_POST['nama'] ?? ''));
        $fakultas_id = (int)($_POST['fakultas_id'] ?? 0);
        if (!$nama || !$fakultas_id) {
            $err = 'Nama program studi dan fakultas wajib diisi.';
        } else {
            $max = $pdo->prepare("SELECT COALESCE(MAX(urutan),0) FROM ref_program_studi WHERE fakultas_id=?");
            $max->execute([$fakultas_id]);
            $max = $max->fetchColumn();
            $pdo->prepare("INSERT INTO ref_program_studi (fakultas_id, nama, urutan) VALUES (?,?,?)")
                ->execute([$fakultas_id, $nama, $max + 1]);
            $msg = 'Program studi berhasil ditambahkan.';
        }
    }
    if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = (int)($_POST['id'] ?? 0);
        $nama        = trim(clean($_POST['nama'] ?? ''));
        $fakultas_id = (int)($_POST['fakultas_id'] ?? 0);
        $aktif       = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $nama && $fakultas_id) {
            $pdo->prepare("UPDATE ref_program_studi SET nama=?, fakultas_id=?, is_active=? WHERE id=?")
                ->execute([$nama, $fakultas_id, $aktif, $id]);
            $msg = 'Program studi berhasil diperbarui.';
        }
    }
    if ($action === 'hapus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM ref_program_studi WHERE id=?")->execute([$id]);
        $msg = 'Program studi dihapus.';
    }
}

// ── FETCH DATA ───────────────────────────────────────────────
$fakultas_list = $pdo->query(
    "SELECT * FROM ref_fakultas ORDER BY urutan, nama"
)->fetchAll();

$fak_filter = (int)($_GET['fak'] ?? 0);

$prodi_sql = "SELECT ps.*, f.nama AS nama_fakultas
              FROM ref_program_studi ps
              JOIN ref_fakultas f ON ps.fakultas_id = f.id";
if ($fak_filter) {
    $ps = $pdo->prepare($prodi_sql . " WHERE ps.fakultas_id=? ORDER BY ps.urutan, ps.nama");
    $ps->execute([$fak_filter]);
} else {
    $ps = $pdo->query($prodi_sql . " ORDER BY f.urutan, ps.urutan, ps.nama");
}
$prodi_list = $ps->fetchAll();

$fak_options = $pdo->query(
    "SELECT * FROM ref_fakultas WHERE is_active=1 ORDER BY urutan, nama"
)->fetchAll();

// inline-edit target
$edit_fak   = null;
$edit_prodi = null;
if ($action === 'edit_form') {
    $eid = (int)($_GET['id'] ?? 0);
    if ($tab === 'fakultas') {
        $s = $pdo->prepare("SELECT * FROM ref_fakultas WHERE id=?");
        $s->execute([$eid]);
        $edit_fak = $s->fetch();
    } else {
        $s = $pdo->prepare("SELECT * FROM ref_program_studi WHERE id=?");
        $s->execute([$eid]);
        $edit_prodi = $s->fetch();
    }
}

// Hitung jumlah prodi per fakultas (sekali saja)
$jml_prodi_map = [];
foreach ($pdo->query("SELECT fakultas_id, COUNT(*) AS n FROM ref_program_studi GROUP BY fakultas_id")->fetchAll() as $r) {
    $jml_prodi_map[$r['fakultas_id']] = $r['n'];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Referensi Akademik — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── tabel ── */
.ref-table td, .ref-table th { padding: 9px 12px; font-size: 13px; }
.ref-table td:last-child { white-space: nowrap; }

/* ── drag handle ── */
.drag-handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px; height: 28px;
  border-radius: 6px;
  color: var(--text-muted);
  cursor: grab;
  transition: background .15s, color .15s;
  flex-shrink: 0;
}
.drag-handle:hover { background: var(--primary-xlight); color: var(--primary); }
.drag-handle:active { cursor: grabbing; }
.drag-handle svg { width:16px; height:16px; pointer-events:none }

/* ── sortable ghost / chosen ── */
.sortable-ghost  { opacity: .35; background: var(--primary-xlight) !important; }
.sortable-chosen { background: var(--bg-hover) !important; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
.sortable-drag   { box-shadow: 0 4px 18px rgba(0,0,0,.18); }

/* ── row animasi setelah simpan ── */
@keyframes flash-ok {
  0%   { background: #d1fae5; }
  100% { background: transparent; }
}
.row-saved { animation: flash-ok .8s ease; }

/* ── toast ── */
#toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(60px);
  background: #1e3a5f; color: #fff; padding: 10px 20px;
  border-radius: 8px; font-size: 13px; font-weight: 500;
  box-shadow: 0 4px 16px rgba(0,0,0,.25); z-index: 9999;
  transition: transform .25s ease, opacity .25s ease; opacity: 0;
  white-space: nowrap;
}
#toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
#toast.error { background: #dc2626; }

/* ── add card ── */
.add-card { background:var(--bg-card);border:2px dashed var(--border);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:20px; }
.add-card .card-title { font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:12px;display:flex;align-items:center;gap:6px }
.form-row { display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end }
.form-row .form-group { margin:0;flex:1;min-width:160px }

/* ── drag hint ── */
.drag-hint {
  font-size: 11px; color: var(--text-muted);
  display: flex; align-items: center; gap: 5px;
}
</style>
</head>
<body>
<div id="toast"></div>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('settings') ?> Referensi Akademik
          <span class="breadcrumb">Fakultas &amp; Program Studi</span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($msg): ?>
        <div class="alert alert-success"><?= ic('check-circle') ?> <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= ic('x-circle') ?> <?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <!-- Tab toggle -->
      <div style="display:flex;gap:8px;margin-bottom:20px">
        <a href="ref_akademik.php?tab=fakultas"
           class="btn <?= $tab==='fakultas'?'btn-primary':'btn-outline' ?>">
          <?= ic('building') ?> Fakultas
          <span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:2px"><?= count($fakultas_list) ?></span>
        </a>
        <a href="ref_akademik.php?tab=prodi"
           class="btn <?= $tab==='prodi'?'btn-primary':'btn-outline' ?>">
          <?= ic('doc') ?> Program Studi
          <span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:2px"><?= count($prodi_list) ?></span>
        </a>
      </div>

      <?php if ($tab === 'fakultas'): ?>
      <!-- ══════════════════ TAB FAKULTAS ══════════════════ -->

      <?php if ($edit_fak): ?>
      <div class="add-card">
        <div class="card-title"><?= ic('edit') ?> Edit Fakultas</div>
        <form method="POST" action="ref_akademik.php?tab=fakultas&action=edit">
          <input type="hidden" name="id" value="<?= $edit_fak['id'] ?>">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Nama Fakultas <span class="required">*</span></label>
              <input type="text" name="nama" class="form-control"
                     value="<?= htmlspecialchars($edit_fak['nama']) ?>" required>
            </div>
            <div class="form-group" style="flex:0 0 auto">
              <label class="form-label">Aktif</label>
              <div style="padding-top:8px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                  <input type="checkbox" name="is_active" value="1" <?= $edit_fak['is_active']?'checked':'' ?>> Aktif
                </label>
              </div>
            </div>
            <div class="form-group" style="flex:0 0 auto;align-self:flex-end;display:flex;gap:6px">
              <button type="submit" class="btn btn-primary"><?= ic('check') ?> Simpan</button>
              <a href="ref_akademik.php?tab=fakultas" class="btn btn-outline"><?= ic('x') ?> Batal</a>
            </div>
          </div>
        </form>
      </div>
      <?php else: ?>
      <div class="add-card">
        <div class="card-title"><?= ic('plus') ?> Tambah Fakultas Baru</div>
        <form method="POST" action="ref_akademik.php?tab=fakultas&action=add">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Nama Fakultas <span class="required">*</span></label>
              <input type="text" name="nama" class="form-control"
                     placeholder="Contoh: Fakultas Teologi" required>
            </div>
            <div class="form-group" style="flex:0 0 auto;align-self:flex-end">
              <button type="submit" class="btn btn-primary"><?= ic('plus') ?> Tambah</button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- Tabel Fakultas -->
      <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:8px">
          <span class="card-title"><?= ic('building') ?> Daftar Fakultas (<?= count($fakultas_list) ?>)</span>
          <span class="drag-hint">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
            Seret baris untuk mengubah urutan
          </span>
        </div>
        <div class="card-body" style="padding:0">
          <table class="data-table ref-table" style="table-layout:fixed">
            <colgroup>
              <col style="width:44px">  <!-- drag handle -->
              <col style="width:36px">  <!-- no -->
              <col>                     <!-- nama -->
              <col style="width:90px">  <!-- prodi -->
              <col style="width:76px">  <!-- status -->
              <col style="width:110px"> <!-- aksi -->
            </colgroup>
            <thead>
              <tr>
                <th></th>
                <th>#</th>
                <th>Nama Fakultas</th>
                <th>Prodi</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="sort-fak">
            <?php if (empty($fakultas_list)): ?>
              <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted)">
                Belum ada data fakultas.
              </td></tr>
            <?php endif; ?>
            <?php foreach ($fakultas_list as $i => $f):
                $jml = $jml_prodi_map[$f['id']] ?? 0;
            ?>
              <tr data-id="<?= $f['id'] ?>" style="<?= !$f['is_active']?'opacity:.5':'' ?>">
                <td>
                  <span class="drag-handle" title="Seret untuk mengubah urutan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
                  </span>
                </td>
                <td class="row-num" style="color:var(--text-muted)"><?= $i+1 ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($f['nama']) ?></td>
                <td>
                  <a href="ref_akademik.php?tab=prodi&fak=<?= $f['id'] ?>"
                     style="color:var(--primary);font-weight:600"><?= $jml ?> prodi</a>
                </td>
                <td>
                  <span class="badge <?= $f['is_active']?'badge-success':'badge-danger' ?>">
                    <?= $f['is_active']?'Aktif':'Nonaktif' ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px">
                    <a href="ref_akademik.php?tab=fakultas&action=edit_form&id=<?= $f['id'] ?>"
                       class="btn btn-sm btn-outline"><?= ic('edit') ?></a>
                    <?php if ($jml === 0): ?>
                    <form method="POST" action="ref_akademik.php?tab=fakultas&action=hapus"
                          onsubmit="return confirm('Hapus fakultas ini?')">
                      <input type="hidden" name="id" value="<?= $f['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger"><?= ic('trash') ?></button>
                    </form>
                    <?php else: ?>
                      <button class="btn btn-sm btn-danger" disabled
                              title="Masih ada <?= $jml ?> prodi terkait"><?= ic('trash') ?></button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php else: ?>
      <!-- ══════════════════ TAB PROGRAM STUDI ══════════════════ -->

      <?php if ($edit_prodi): ?>
      <div class="add-card">
        <div class="card-title"><?= ic('edit') ?> Edit Program Studi</div>
        <form method="POST" action="ref_akademik.php?tab=prodi&action=edit">
          <input type="hidden" name="id" value="<?= $edit_prodi['id'] ?>">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Nama Program Studi <span class="required">*</span></label>
              <input type="text" name="nama" class="form-control"
                     value="<?= htmlspecialchars($edit_prodi['nama']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Fakultas <span class="required">*</span></label>
              <select name="fakultas_id" class="form-control" required>
                <option value="">-- Pilih --</option>
                <?php foreach ($fak_options as $fo): ?>
                  <option value="<?= $fo['id'] ?>" <?= $fo['id']==$edit_prodi['fakultas_id']?'selected':'' ?>>
                    <?= htmlspecialchars($fo['nama']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto">
              <label class="form-label">Aktif</label>
              <div style="padding-top:8px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                  <input type="checkbox" name="is_active" value="1" <?= $edit_prodi['is_active']?'checked':'' ?>> Aktif
                </label>
              </div>
            </div>
            <div class="form-group" style="flex:0 0 auto;align-self:flex-end;display:flex;gap:6px">
              <button type="submit" class="btn btn-primary"><?= ic('check') ?> Simpan</button>
              <a href="ref_akademik.php?tab=prodi<?= $fak_filter?"&fak=$fak_filter":'' ?>"
                 class="btn btn-outline"><?= ic('x') ?> Batal</a>
            </div>
          </div>
        </form>
      </div>
      <?php else: ?>
      <div class="add-card">
        <div class="card-title"><?= ic('plus') ?> Tambah Program Studi Baru</div>
        <form method="POST" action="ref_akademik.php?tab=prodi&action=add">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Nama Program Studi <span class="required">*</span></label>
              <input type="text" name="nama" class="form-control"
                     placeholder="Contoh: Pendidikan Agama Kristen" required>
            </div>
            <div class="form-group">
              <label class="form-label">Fakultas <span class="required">*</span></label>
              <select name="fakultas_id" class="form-control" required>
                <option value="">-- Pilih Fakultas --</option>
                <?php foreach ($fak_options as $fo): ?>
                  <option value="<?= $fo['id'] ?>" <?= $fo['id']===$fak_filter?'selected':'' ?>>
                    <?= htmlspecialchars($fo['nama']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto;align-self:flex-end">
              <button type="submit" class="btn btn-primary"><?= ic('plus') ?> Tambah</button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- Filter by Fakultas -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
        <span style="font-size:12px;color:var(--text-muted);font-weight:600">Filter:</span>
        <a href="ref_akademik.php?tab=prodi"
           class="btn btn-sm <?= !$fak_filter?'btn-primary':'btn-outline' ?>">Semua</a>
        <?php foreach ($fakultas_list as $f): ?>
          <a href="ref_akademik.php?tab=prodi&fak=<?= $f['id'] ?>"
             class="btn btn-sm <?= $fak_filter===$f['id']?'btn-primary':'btn-outline' ?>">
            <?= htmlspecialchars($f['nama']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Tabel Program Studi -->
      <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:8px">
          <span class="card-title"><?= ic('doc') ?> Daftar Program Studi (<?= count($prodi_list) ?>)</span>
          <span class="drag-hint">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
            Seret baris untuk mengubah urutan
            <?php if (!$fak_filter): ?>
            &nbsp;·&nbsp;<span style="color:#f59e0b">Gunakan filter fakultas agar urutan lebih akurat</span>
            <?php endif; ?>
          </span>
        </div>
        <div class="card-body" style="padding:0">
          <table class="data-table ref-table" style="table-layout:fixed">
            <colgroup>
              <col style="width:44px">
              <col style="width:36px">
              <col>
              <col style="width:180px">
              <col style="width:76px">
              <col style="width:110px">
            </colgroup>
            <thead>
              <tr>
                <th></th>
                <th>#</th>
                <th>Program Studi</th>
                <th>Fakultas</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody id="sort-prodi">
            <?php if (empty($prodi_list)): ?>
              <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted)">
                Belum ada data program studi<?= $fak_filter ? ' untuk fakultas ini.' : '.' ?>
              </td></tr>
            <?php endif; ?>
            <?php foreach ($prodi_list as $i => $p): ?>
              <tr data-id="<?= $p['id'] ?>" style="<?= !$p['is_active']?'opacity:.5':'' ?>">
                <td>
                  <span class="drag-handle" title="Seret untuk mengubah urutan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
                  </span>
                </td>
                <td class="row-num" style="color:var(--text-muted)"><?= $i+1 ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($p['nama']) ?></td>
                <td>
                  <span style="font-size:11px;background:var(--primary-xlight);color:var(--primary);padding:2px 8px;border-radius:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block;max-width:100%">
                    <?= htmlspecialchars($p['nama_fakultas']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge <?= $p['is_active']?'badge-success':'badge-danger' ?>">
                    <?= $p['is_active']?'Aktif':'Nonaktif' ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px">
                    <a href="ref_akademik.php?tab=prodi&action=edit_form&id=<?= $p['id'] ?><?= $fak_filter?"&fak=$fak_filter":'' ?>"
                       class="btn btn-sm btn-outline"><?= ic('edit') ?></a>
                    <form method="POST" action="ref_akademik.php?tab=prodi&action=hapus<?= $fak_filter?"&fak=$fak_filter":'' ?>"
                          onsubmit="return confirm('Hapus program studi ini?')">
                      <input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger"><?= ic('trash') ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div>

<!-- SortableJS via CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
// ── Toast helper ────────────────────────────────────────────
function showToast(msg, isErr) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show' + (isErr ? ' error' : '');
  clearTimeout(t._tid);
  t._tid = setTimeout(() => { t.className = ''; }, 2200);
}

// ── Renumber #-column ────────────────────────────────────────
function renumber(tbody) {
  tbody.querySelectorAll('tr').forEach((tr, i) => {
    const cell = tr.querySelector('.row-num');
    if (cell) cell.textContent = i + 1;
  });
}

// ── Send new order to server ─────────────────────────────────
function saveOrder(tbody, tab) {
  const ids = [...tbody.querySelectorAll('tr[data-id]')]
              .map(tr => tr.dataset.id);
  if (!ids.length) return;

  const fd = new FormData();
  ids.forEach(id => fd.append('ids[]', id));

  fetch('ref_akademik.php?action=reorder&tab=' + tab, {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      showToast('Urutan berhasil disimpan');
      // Flash rows
      tbody.querySelectorAll('tr').forEach(tr => {
        tr.classList.remove('row-saved');
        void tr.offsetWidth; // reflow
        tr.classList.add('row-saved');
      });
    } else {
      showToast('Gagal menyimpan urutan', true);
    }
  })
  .catch(() => showToast('Koneksi gagal', true));
}

// ── Init SortableJS ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

  const fakBody   = document.getElementById('sort-fak');
  const prodiBody = document.getElementById('sort-prodi');

  const opts = (tbody, tab) => ({
    handle:      '.drag-handle',
    animation:   160,
    ghostClass:  'sortable-ghost',
    chosenClass: 'sortable-chosen',
    dragClass:   'sortable-drag',
    onEnd() {
      renumber(tbody);
      saveOrder(tbody, tab);
    }
  });

  if (fakBody)   Sortable.create(fakBody,   opts(fakBody,   'fakultas'));
  if (prodiBody) Sortable.create(prodiBody, opts(prodiBody, 'prodi'));
});

function toggleLang(){
  const c = document.cookie.match(/lang=([^;]+)/)?.[1] || 'id';
  document.cookie = 'lang=' + (c==='id'?'en':'id') + ';path=/;max-age=31536000';
  location.reload();
}
</script>
</body>
</html>
