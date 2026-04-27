<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');
if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';
$tahun = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

$jenis_luaran_opts = [
    'jurnal_sinta'         => 'Jurnal Sinta',
    'jurnal_scopus'        => 'Jurnal Scopus',
    'jurnal_internasional' => 'Jurnal Internasional (non-Scopus)',
    'jurnal_nasional'      => 'Jurnal Nasional (non-Sinta)',
    'prosiding'            => 'Prosiding Konferensi',
    'book_chapter'         => 'Book Chapter',
    'buku'                 => 'Buku',
    'hki'                  => 'HKI / Paten',
    'lainnya'              => 'Lainnya',
];

/* ── POST handlers ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_luaran') {
        $pid    = (int)($_POST['pid'] ?? 0);
        $jenis  = clean($_POST['jenis_luaran'] ?? '');
        $judul  = clean($_POST['judul_luaran'] ?? '');
        $jurnal = clean($_POST['nama_jurnal'] ?? '');
        $url    = trim($_POST['url_doi'] ?? '');
        $issn   = clean($_POST['issn_isbn'] ?? '');
        $akr    = clean($_POST['akreditasi'] ?? '');
        $tgl    = clean($_POST['tanggal_terbit'] ?? '');

        // Verifikasi proposal milik user
        $chk = $pdo->prepare("SELECT id FROM usulan_pengabdian WHERE id=? AND user_id=? AND deleted_at IS NULL");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Proposal tidak valid.'];
            redirect('/modules/pengabdian/luaran.php');
        }

        if (!$jenis || !array_key_exists($jenis, $jenis_luaran_opts)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Pilih jenis luaran.':'Select output type.'];
            redirect('/modules/pengabdian/luaran.php');
        }

        // Upload bukti (opsional tapi disarankan)
        $fp = null; $fn = null; $fs = null;
        if (!empty($_FILES['file']['name'])) {
            $f = $_FILES['file'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']);
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];
            
            if (!in_array($ext, ['pdf','jpg','jpeg','png']) || !in_array($mime, $allowed_mimes)) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'File bukti: PDF/JPG/PNG yang valid.':'File: valid PDF/JPG/PNG.'];
                redirect('/modules/pengabdian/luaran.php');
            }
            if ($f['size'] > 15 * 1024 * 1024) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Maks. 15 MB.':'Max 15 MB.'];
                redirect('/modules/pengabdian/luaran.php');
            }
            $dir = BASE_PATH . '/uploads/luaran/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = time() . '_' . $uid . '_' . preg_replace('/[^a-zA-Z0-9_.]/','_',$f['name']);
            if (move_uploaded_file($f['tmp_name'], $dir . $fname)) {
                $fp = 'uploads/luaran/' . $fname;
                $fn = $f['name'];
                $fs = $f['size'];
            }
        }

        // Status default: terbit (jika ada tanggal terbit) atau submit (jika no tanggal)
        $status = $tgl ? 'terbit' : 'submit';

        $pdo->prepare("
            INSERT INTO monev_pengabdian_luaran
              (usulan_id, user_id, jenis_luaran, judul_luaran, nama_jurnal, url_doi, issn_isbn,
               akreditasi, tanggal_terbit, file_path, file_name, file_size, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $pid, $uid, $jenis, $judul ?: null, $jurnal ?: null, $url ?: null,
            $issn ?: null, $akr ?: null, $tgl ?: null,
            $fp, $fn, $fs, $status,
        ]);

        // Notifikasi ke admin LPPM
        $admin_ids = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 LIMIT 3")->fetchAll(\PDO::FETCH_COLUMN);
        $p = $pdo->prepare("SELECT judul FROM usulan_pengabdian WHERE id=?");
        $p->execute([$pid]); $pd = $p->fetch();
        foreach ($admin_ids as $aid) {
            $pdo->prepare("INSERT INTO notifikasi (user_id,judul,pesan,tipe) VALUES (?,?,?,?)")
                ->execute([$aid,
                    $id?'Luaran Pengabdian Disubmit':'Research Output Submitted',
                    ($id?'Peneliti submit luaran (':'Researcher submitted output (').$jenis_luaran_opts[$jenis].') '
                    . ($id?'untuk proposal: ':'for proposal: ') . mb_strimwidth($pd['judul'] ?? '', 0, 70, '…'),
                    'info']);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>$id
            ? 'Luaran berhasil disubmit. LPPM akan memverifikasi.'
            : 'Output submitted. LPPM will verify.'];
        redirect('/modules/pengabdian/luaran.php');
    }
}

/* ── Load proposals milik user yang eligible (sudah punya kontrak / laporan diterima) ─── */
$props = $pdo->prepare("
    SELECT up.id, up.judul, up.skema, up.tahun_anggaran,
           kp.deadline_laporan,
           sk.batas_luaran_bulan, sk.jenis_luaran_wajib
    FROM usulan_pengabdian up
    LEFT JOIN kontrak_pengabdian kp ON kp.usulan_id = up.id
    LEFT JOIN skema_pengabdian sk ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci AND sk.tahun = up.tahun_anggaran
    WHERE up.user_id = ? AND up.deleted_at IS NULL
      AND up.status IN ('kontrak_aktif','laporan_diterima','selesai')
    ORDER BY up.reviewed_at DESC
");
$props->execute([$uid]);
$proposals = $props->fetchAll();

/* ── Load semua luaran yang sudah disubmit ─── */
$luaran_map = [];
if (!empty($proposals)) {
    $pids = array_column($proposals, 'id');
    $ph = implode(',', array_fill(0, count($pids), '?'));
    $lq = $pdo->prepare("
        SELECT * FROM monev_pengabdian_luaran
        WHERE usulan_id IN ($ph) AND deleted_at IS NULL
        ORDER BY created_at DESC
    ");
    $lq->execute($pids);
    foreach ($lq->fetchAll() as $l) {
        $luaran_map[$l['usulan_id']][] = $l;
    }
}

$now_ts = time();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id?'Luaran Pengabdian':'Research Output' ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.lua-card{background:var(--bg-card);border:1.5px solid var(--border);border-radius:13px;padding:18px;margin-bottom:14px}
.lua-card.overdue{border-color:#fecaca;background:#fff9f9}
.lua-card.done{border-color:#bbf7d0;background:#f9fffc}

.lua-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:900;display:none;
  align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px)}
.lua-modal.open{display:flex}
.lua-box{background:var(--bg-card);border-radius:14px;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.25)}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('award') ?>
          <?= $id?'Luaran Pengabdian':'Research Output' ?>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if (!empty($_SESSION['flash'])): $fl=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="alert alert-<?= $fl['type'] ?>" style="margin-bottom:14px"><?= htmlspecialchars($fl['msg']) ?></div>
      <?php endif; ?>

      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:11px;padding:13px 16px;margin-bottom:16px;font-size:12.5px;color:#1e40af">
        <?= ic('info','style="width:15px;height:15px"') ?>
        <span style="margin-left:5px"><?= $id
          ? 'Halaman ini untuk <strong>menyerahkan bukti luaran pengabdian</strong> (jurnal, prosiding, HKI, dll.) dari hibah yang telah Anda laksanakan. Batas waktu luaran bervariasi per skema pengabdian. Lampirkan link / DOI dan file bukti (PDF/JPG).'
          : 'Submit <strong>research output evidence</strong> (journals, proceedings, IP, etc.) from your grant. Output deadlines vary per scheme. Attach link/DOI and evidence file.' ?></span>
      </div>

      <?php if (empty($proposals)): ?>
      <div style="padding:60px 20px;text-align:center;color:var(--text-muted)">
        <?= ic('inbox','style="width:48px;height:48px;opacity:.4"') ?>
        <div style="margin-top:10px;font-size:14px;font-weight:600">
          <?= $id?'Belum ada proposal dengan kontrak aktif':'No proposals with active contract yet' ?>
        </div>
        <div style="margin-top:5px;font-size:12.5px">
          <?= $id?'Luaran hanya dapat disubmit setelah kontrak pengabdian Anda aktif.':'Output can only be submitted after your contract is active.' ?>
        </div>
      </div>
      <?php else: foreach ($proposals as $p):
        $dl_lap = $p['deadline_laporan'] ? strtotime($p['deadline_laporan']) : 0;
        $dl_lua = 0;
        if ($dl_lap && !empty($p['batas_luaran_bulan'])) {
            $dl_lua = strtotime("+".(int)$p['batas_luaran_bulan']." months", $dl_lap);
        }
        $my_lua = $luaran_map[$p['id']] ?? [];
        $n_terbit = count(array_filter($my_lua, fn($l) => in_array($l['status'], ['terbit','diverifikasi'])));
        $overdue = $dl_lua && $dl_lua < $now_ts && $n_terbit == 0;
        $done = $n_terbit > 0;
      ?>
      <div class="lua-card <?= $overdue?'overdue':($done?'done':'') ?>">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:700;line-height:1.35"><?= htmlspecialchars($p['judul']) ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px;display:flex;flex-wrap:wrap;gap:8px">
              <span style="font-weight:700;color:<?= $p['skema']==='nasional'?'#15803d':'#0369a1' ?>"><?= strtoupper($p['skema']) ?></span>
              <span><?= $p['tahun_anggaran'] ?></span>
              <?php if ($dl_lua): ?>
              <span style="color:<?= $overdue?'#dc2626':($done?'#15803d':'#a16207') ?>;font-weight:700">
                <?= ic('clock','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
                <?= $id?'Batas Luaran':'Output Deadline' ?>: <?= date('d M Y', $dl_lua) ?>
                <?php if ($overdue): ?> · <?= $id?'TERLEWAT':'OVERDUE' ?><?php endif; ?>
              </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($p['jenis_luaran_wajib'])): ?>
            <div style="margin-top:7px;padding:7px 11px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;font-size:11.5px;color:#854d0e">
              <strong><?= $id?'Luaran Wajib:':'Required:' ?></strong> <?= htmlspecialchars($p['jenis_luaran_wajib']) ?>
            </div>
            <?php endif; ?>
          </div>
          <button onclick="openLua(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes(mb_strimwidth($p['judul'],0,60,'…')),ENT_QUOTES) ?>')"
                  class="btn btn-primary" style="font-size:12px;padding:7px 13px;height:fit-content;flex-shrink:0">
            <?= ic('plus','style="width:12px;height:12px"') ?> <?= $id?'Submit Luaran':'Submit Output' ?>
          </button>
        </div>

        <?php if (!empty($my_lua)): ?>
        <div style="border-top:1px dashed var(--border);padding-top:10px;margin-top:4px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px">
            <?= $id?'Luaran yang sudah disubmit':'Submitted outputs' ?> (<?= count($my_lua) ?>)
          </div>
          <?php foreach ($my_lua as $l):
            $st_cfg = match($l['status']) {
              'rencana'      => ['#f1f5f9','#64748b',$id?'Rencana':'Planned'],
              'submit'       => ['#dbeafe','#1d4ed8',$id?'Submitted':'Submitted'],
              'terbit'       => ['#dcfce7','#15803d',$id?'Terbit':'Published'],
              'diverifikasi' => ['#bbf7d0','#14532d',$id?'Diverifikasi LPPM':'Verified'],
              'ditolak'      => ['#fee2e2','#991b1b',$id?'Ditolak':'Rejected'],
            };
          ?>
          <div style="border:1px solid var(--border);border-radius:8px;padding:9px 12px;margin-bottom:6px;background:#fff">
            <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:4px">
              <span style="background:#e0e7ff;color:#3730a3;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px">
                <?= htmlspecialchars($jenis_luaran_opts[$l['jenis_luaran']] ?? $l['jenis_luaran']) ?>
              </span>
              <span style="background:<?= $st_cfg[0] ?>;color:<?= $st_cfg[1] ?>;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px">
                <?= $st_cfg[2] ?>
              </span>
              <?php if ($l['akreditasi']): ?>
              <span style="background:#fef3c7;color:#92400e;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase"><?= htmlspecialchars($l['akreditasi']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($l['judul_luaran']): ?>
            <div style="font-size:12px;font-weight:600;line-height:1.35"><?= htmlspecialchars($l['judul_luaran']) ?></div>
            <?php endif; ?>
            <?php if ($l['nama_jurnal'] || $l['tanggal_terbit']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <?php if ($l['nama_jurnal']): ?><?= htmlspecialchars($l['nama_jurnal']) ?><?php endif; ?>
              <?php if ($l['tanggal_terbit']): ?> · <?= date('d M Y', strtotime($l['tanggal_terbit'])) ?><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($l['catatan_admin']): ?>
            <div style="margin-top:5px;padding:6px 9px;background:var(--bg-field);border-radius:5px;font-size:11px;color:#475569">
              <strong><?= $id?'Catatan LPPM:':'LPPM Note:' ?></strong> <?= nl2br(htmlspecialchars($l['catatan_admin'])) ?>
            </div>
            <?php endif; ?>
            <div style="margin-top:5px;display:flex;gap:9px;flex-wrap:wrap;font-size:11px">
              <?php if ($l['url_doi']): ?>
              <a href="<?= htmlspecialchars($l['url_doi']) ?>" target="_blank" style="color:var(--primary);text-decoration:none">
                <?= ic('link','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
                DOI / URL
              </a>
              <?php endif; ?>
              <?php if ($l['file_path']): ?>
              <a href="<?= BASE_URL ?>/<?= htmlspecialchars($l['file_path']) ?>" target="_blank" style="color:var(--primary);text-decoration:none">
                <?= ic('download','style="width:10px;height:10px;display:inline;vertical-align:-1px"') ?>
                <?= $id?'Bukti':'Evidence' ?>
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>

    </div>
  </div>
</div>

<!-- Modal Submit Luaran -->
<div class="lua-modal" id="luaModal" onclick="if(event.target===this)closeLua()">
  <div class="lua-box">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="submit_luaran">
      <input type="hidden" name="pid" id="lvPid">
      <div style="padding:16px 20px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:14px;font-weight:700"><?= $id?'Submit Luaran Pengabdian':'Submit Research Output' ?></div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px" id="lvProp"></div>
        </div>
        <button type="button" onclick="closeLua()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">×</button>
      </div>
      <div style="padding:16px 20px;display:grid;gap:11px">
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Jenis Luaran':'Output Type' ?> *</label>
          <select name="jenis_luaran" required class="form-control" style="font-size:12.5px">
            <?php foreach ($jenis_luaran_opts as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Judul Luaran':'Output Title' ?> *</label>
          <input type="text" name="judul_luaran" required class="form-control" style="font-size:12.5px" maxlength="500">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Nama Jurnal/Penerbit':'Journal/Publisher' ?></label>
            <input type="text" name="nama_jurnal" class="form-control" style="font-size:12.5px" maxlength="255">
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Tanggal Terbit':'Publication Date' ?></label>
            <input type="date" name="tanggal_terbit" class="form-control" style="font-size:12.5px">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label" style="font-size:11.5px">ISSN / ISBN</label>
            <input type="text" name="issn_isbn" class="form-control" style="font-size:12.5px" maxlength="50">
          </div>
          <div>
            <label class="form-label" style="font-size:11.5px"><?= $id?'Akreditasi':'Accreditation' ?></label>
            <select name="akreditasi" class="form-control" style="font-size:12.5px">
              <option value="">—</option>
              <?php foreach (['sinta1','sinta2','sinta3','sinta4','sinta5','sinta6','scopusQ1','scopusQ2','scopusQ3'] as $a): ?>
              <option value="<?= $a ?>"><?= strtoupper($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px">URL / DOI</label>
          <input type="url" name="url_doi" class="form-control" style="font-size:12.5px" placeholder="https://doi.org/...">
        </div>
        <div>
          <label class="form-label" style="font-size:11.5px"><?= $id?'Bukti (PDF/JPG) — disarankan':'Evidence — recommended' ?></label>
          <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control" style="font-size:12px">
          <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px"><?= $id?'Screenshot halaman jurnal, cover artikel, sertifikat HKI, dll. Maks 15 MB.':'Journal page screenshot, article cover, IP cert. Max 15 MB.' ?></div>
        </div>
      </div>
      <div style="padding:13px 20px;border-top:1.5px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
        <button type="button" onclick="closeLua()" class="btn btn-outline"><?= $id?'Batal':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><?= $id?'Submit':'Submit' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openLua(pid, judul){
  document.getElementById('lvPid').value = pid;
  document.getElementById('lvProp').textContent = judul;
  document.getElementById('luaModal').classList.add('open');
}
function closeLua(){document.getElementById('luaModal').classList.remove('open')}
</script>
</body>
</html>
