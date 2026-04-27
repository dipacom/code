<?php
require_once '../includes/config.php';
requireLogin('admin');
$lang = $_COOKIE['lang'] ?? 'id';
$tab  = $_GET['tab'] ?? 'plagiasi';

$batas_sim = (int)(getSetting($pdo, 'batas_similarity') ?: 20);
$batas_ai  = (int)(getSetting($pdo, 'batas_ai') ?: 30);

if ($tab === 'plagiasi') {
    $stmt = $pdo->query("
        SELECT sp.id, sp.nomor_surat, sp.tanggal_surat, sp.nama_penandatangan,
               sp.jabatan_penandatangan, sp.download_count, sp.created_at,
               cp.similarity_score, cp.ai_score, cp.platform_ai,
               cp.tanggal_cek, cp.catatan, cp.screenshot_path,
               s.id AS skripsi_id, s.judul_skripsi, s.jenis_tugas_akhir,
               s.nama_pembimbing1, s.nama_pembimbing2, s.nama_pembimbing3,
               s.tahun_sidang, s.user_id,
               u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email
        FROM surat_plagiasi sp
        JOIN cek_plagiasi cp ON sp.cek_plagiasi_id = cp.id
        JOIN skripsi s       ON cp.skripsi_id = s.id
        JOIN users u         ON s.user_id = u.id
        ORDER BY sp.created_at DESC
    ");
} elseif ($tab === 'ec') {
    // Ethical Clearance — surat tersimpan di table ethical_clearance itself
    $stmt = $pdo->query("
        SELECT ec.id, ec.nomor_surat, ec.tanggal_proses AS tanggal_surat,
               ec.judul_penelitian, ec.nama_jurnal, ec.jenis_penelitian,
               ec.melibatkan_subjek_manusia, ec.lokasi_penelitian,
               ec.tgl_mulai, ec.tgl_selesai, ec.file_surat_signed,
               ec.created_at, ec.user_id,
               u.nama_lengkap, u.nim, u.nidn, u.program_studi, u.fakultas, u.email
        FROM ethical_clearance ec
        JOIN users u ON ec.user_id = u.id
        WHERE ec.status = 'disetujui' AND ec.nomor_surat IS NOT NULL
              AND ec.deleted_at IS NULL
        ORDER BY ec.tanggal_proses DESC, ec.created_at DESC
    ");
} else {
    $stmt = $pdo->query("
        SELECT sp.id, sp.nomor_surat, sp.tanggal_surat, sp.nama_penandatangan,
               sp.jabatan_penandatangan, sp.download_count, sp.created_at,
               p.id AS pub_id, p.judul_publikasi, p.jenis_publikasi,
               p.nama_jurnal_penerbit, p.tahun_terbit, p.url_doi,
               p.issn_isbn, p.akreditasi_jurnal, p.user_id,
               u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email
        FROM surat_publikasi sp
        JOIN publikasi p ON sp.publikasi_id = p.id
        JOIN users u     ON p.user_id = u.id
        ORDER BY sp.created_at DESC
    ");
}
$list = $stmt->fetchAll();

// Counter untuk tab badge
$cnt_pl = (int)$pdo->query("SELECT COUNT(*) FROM surat_plagiasi")->fetchColumn();
$cnt_pb = (int)$pdo->query("SELECT COUNT(*) FROM surat_publikasi")->fetchColumn();
$cnt_ec = (int)$pdo->query("SELECT COUNT(*) FROM ethical_clearance WHERE status='disetujui' AND nomor_surat IS NOT NULL AND deleted_at IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Arsip Surat':'Letter Archive' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* Batch select */
.cb-row input[type=checkbox]{width:15px;height:15px;cursor:pointer;accent-color:var(--primary)}
.batch-bar{
  display:none;position:sticky;bottom:16px;z-index:50;
  background:var(--primary);color:#fff;
  border-radius:var(--radius-lg);padding:12px 20px;
  box-shadow:var(--shadow-xl);align-items:center;gap:14px;
  margin:0 0 8px;animation:fadeUp .2s ease
}
.batch-bar.show{display:flex}
.batch-count{font-weight:700;font-size:14px}
.batch-bar .btn{background:var(--accent);color:#fff;border:none;gap:6px}
.batch-bar .btn:hover{background:var(--accent-dark)}
.batch-bar .btn-clear{background:rgba(255,255,255,.15);font-size:11px;padding:0 10px;height:28px}
tr.selected-row td{background:#eef4ff !important}

/* Slide-in drawer */
#detail-drawer{
  width:520px;max-width:100%;
  animation:slideIn .2s ease
}
@keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
.drw-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);
  position:sticky;top:0;background:#fff;z-index:1
}
.drw-head h3{font-size:15px;font-weight:700;color:var(--primary);margin:0}
.drw-close{background:none;border:none;cursor:pointer;color:#94a3b8;
  padding:4px;display:flex;border-radius:6px;transition:background .15s}
.drw-close:hover{background:#f1f5f9;color:#475569}
#drw-body{padding:20px}

/* Detail sections */
.ds{margin-bottom:18px}
.ds-title{
  font-size:10px;font-weight:700;letter-spacing:.07em;
  text-transform:uppercase;color:#94a3b8;
  margin-bottom:8px;padding-bottom:5px;
  border-bottom:1px solid var(--border)
}
.dg{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.dg.full{grid-template-columns:1fr}
.di{background:#f8fafc;border-radius:8px;padding:10px 12px;
    border:1px solid var(--border)}
.di .lbl{font-size:10px;font-weight:700;text-transform:uppercase;
         color:#94a3b8;margin-bottom:3px}
.di .val{font-size:13px;font-weight:600;color:#1e293b;line-height:1.4}
.di .val.mono{font-family:monospace;font-size:12px}
.di.full{grid-column:1/-1}

.score-row{display:flex;gap:10px;margin-bottom:6px}
.score-box{flex:1;border-radius:10px;padding:12px 14px;text-align:center;
           border:1.5px solid transparent}
.score-box .sv{font-size:22px;font-weight:800;line-height:1}
.score-box .sl{font-size:10px;margin-top:3px;text-transform:uppercase;letter-spacing:.05em}
.score-box .sb{font-size:10px;margin-top:2px;opacity:.65}
.score-ok {background:#f0fdf4;border-color:#86efac;color:#16a34a}
.score-bad{background:#fff1f2;border-color:#fca5a5;color:#dc2626}

.catatan-box{background:#fffbeb;border:1px solid #fde68a;
  border-radius:8px;padding:12px 14px;font-size:13px;
  color:#92400e;line-height:1.6}
.nomor-chip{display:inline-block;background:#eff6ff;color:#1d4ed8;
  border:1px solid #bfdbfe;border-radius:20px;
  padding:3px 12px;font-size:12px;font-weight:700;margin-bottom:14px}
.dl-row{display:flex;gap:8px;margin-top:18px;flex-wrap:wrap}

.det-src{display:none}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('archive') ?> <?= $lang==='id'?'Arsip Surat Keterangan':'Certificate Archive' ?>
          <span class="breadcrumb"><?= count($list) ?> <?= $lang==='id'?'surat':'certificates' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- Tab -->
      <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border);flex-wrap:wrap">
        <a href="surat.php?tab=plagiasi"
           style="padding:10px 18px;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;border-bottom:<?= $tab==='plagiasi'?'2px solid var(--primary)':'none' ?>;color:<?= $tab==='plagiasi'?'var(--primary)':'var(--text-muted)' ?>;margin-bottom:-2px">
          <?= ic('search') ?> <?= $lang==='id'?'Bebas Plagiasi':'Plagiarism-Free' ?>
          <span style="background:<?= $tab==='plagiasi'?'var(--primary)':'var(--border)' ?>;color:<?= $tab==='plagiasi'?'#fff':'var(--text-muted)' ?>;border-radius:10px;padding:1px 7px;font-size:10px"><?= $cnt_pl ?></span>
        </a>
        <a href="surat.php?tab=publikasi"
           style="padding:10px 18px;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;border-bottom:<?= $tab==='publikasi'?'2px solid var(--primary)':'none' ?>;color:<?= $tab==='publikasi'?'var(--primary)':'var(--text-muted)' ?>;margin-bottom:-2px">
          <?= ic('newspaper') ?> <?= $lang==='id'?'Surat Publikasi':'Publication' ?>
          <span style="background:<?= $tab==='publikasi'?'var(--primary)':'var(--border)' ?>;color:<?= $tab==='publikasi'?'#fff':'var(--text-muted)' ?>;border-radius:10px;padding:1px 7px;font-size:10px"><?= $cnt_pb ?></span>
        </a>
        <a href="surat.php?tab=ec"
           style="padding:10px 18px;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;border-bottom:<?= $tab==='ec'?'2px solid #0d9488':'none' ?>;color:<?= $tab==='ec'?'#0d9488':'var(--text-muted)' ?>;margin-bottom:-2px">
          <?= ic('clipboard') ?> Ethical Clearance
          <span style="background:<?= $tab==='ec'?'#0d9488':'var(--border)' ?>;color:<?= $tab==='ec'?'#fff':'var(--text-muted)' ?>;border-radius:10px;padding:1px 7px;font-size:10px"><?= $cnt_ec ?></span>
        </a>
      </div>

      <!-- Batch bar -->
      <div class="batch-bar" id="batch-bar">
        <div style="flex:1;display:flex;align-items:center;gap:10px">
          <?= ic('check-circle','style="width:18px;height:18px;color:var(--accent-light)"') ?>
          <span class="batch-count"><span id="sel-count">0</span> <?= $lang==='id'?'surat dipilih':'certificates selected' ?></span>
        </div>
        <button type="button" class="btn" onclick="submitBatch()">
          <?= ic('download') ?> <?= $lang==='id'?'Unduh ZIP':'Download ZIP' ?>
        </button>
        <button type="button" class="btn btn-clear" onclick="clearAll()">
          <?= ic('x') ?> <?= $lang==='id'?'Batal':'Clear' ?>
        </button>
      </div>

      <form id="batch-form" method="POST" action="unduh_batch.php" target="_blank">
        <input type="hidden" name="tipe" value="<?= $tab ?>">

        <div class="card">
          <div class="card-header" style="flex-wrap:wrap;gap:10px">
            <span class="card-title">
              <?= ic($tab==='plagiasi'?'search':'newspaper') ?>
              <?= $tab==='plagiasi'
                ? ($lang==='id'?'Arsip Surat Bebas Plagiasi':'Plagiarism-Free Archive')
                : ($lang==='id'?'Arsip Surat Keterangan Publikasi':'Publication Certificate Archive') ?>
            </span>
            <?php if (!empty($list)): ?>
            <div style="display:flex;gap:8px;align-items:center">
              <button type="button" class="btn btn-outline btn-sm" onclick="selectAll(true)">
                <?= ic('check') ?> <?= $lang==='id'?'Pilih Semua':'Select All' ?>
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="selectAll(false)" style="color:var(--text-muted)">
                <?= ic('x') ?> <?= $lang==='id'?'Batalkan':'Deselect' ?>
              </button>
            </div>
            <?php endif; ?>
          </div>

          <div class="card-body" style="padding:0">
            <?php if (empty($list)): ?>
              <div style="padding:40px;text-align:center;color:var(--text-muted)">
                <?= ic('inbox','style="width:32px;height:32px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
                <?= $lang==='id'?'Belum ada surat diterbitkan.':'No certificates issued yet.' ?>
              </div>
            <?php else: ?>
            <div class="table-wrap">
              <table class="data-table" id="surat-table">
                <thead>
                  <tr>
                    <th style="width:40px;text-align:center">
                      <input type="checkbox" id="cb-all" onchange="toggleAll(this)"
                             style="width:15px;height:15px;cursor:pointer;accent-color:var(--primary)">
                    </th>
                    <th>#</th>
                    <th><?= $lang==='id'?'No. Surat':'Letter No.' ?></th>
                    <th><?= $tab==='ec' ? ($lang==='id'?'Dosen':'Lecturer') : ($lang==='id'?'Mahasiswa':'Student') ?></th>
                    <?php if ($tab==='plagiasi'): ?>
                    <th><?= $lang==='id'?'Judul Skripsi':'Thesis Title' ?></th>
                    <th>Similarity</th>
                    <?php elseif ($tab==='ec'): ?>
                    <th><?= $lang==='id'?'Judul Penelitian':'Research Title' ?></th>
                    <th><?= $lang==='id'?'Jenis Penelitian':'Type' ?></th>
                    <?php else: ?>
                    <th><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></th>
                    <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                    <?php endif; ?>
                    <th><?= $lang==='id'?'Tgl. Surat':'Letter Date' ?></th>
                    <?php if ($tab !== 'ec'): ?>
                    <th><?= $lang==='id'?'Penandatangan':'Signatory' ?></th>
                    <th style="text-align:center"><?= $lang==='id'?'Unduhan':'Downloads' ?></th>
                    <?php else: ?>
                    <th><?= $lang==='id'?'File Signed':'Signed File' ?></th>
                    <?php endif; ?>
                    <th><?= $lang==='id'?'Aksi':'Action' ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($list as $i => $r): ?>
                  <tr class="cb-row" onclick="toggleRow(this)" style="cursor:pointer">
                    <td style="text-align:center" onclick="event.stopPropagation()">
                      <input type="checkbox" name="ids[]" value="<?= $r['id'] ?>"
                             onchange="updateCount()" onclick="event.stopPropagation()"
                             style="width:15px;height:15px;cursor:pointer;accent-color:var(--primary)">
                    </td>
                    <td style="color:var(--text-muted);font-size:11px"><?= $i+1 ?></td>
                    <td style="font-size:12px;font-weight:600;color:var(--primary);white-space:nowrap">
                      <?= htmlspecialchars($r['nomor_surat']) ?>
                    </td>
                    <td>
                      <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                      <div style="font-size:11px;color:var(--text-muted)">
                        <?php if ($tab==='ec'): ?>
                          <?= $r['nidn'] ? 'NIDN '.htmlspecialchars($r['nidn']) : ($r['nim'] ? 'NIM '.htmlspecialchars($r['nim']) : '—') ?>
                        <?php else: ?>
                          <?= htmlspecialchars($r['nim']??'') ?>
                        <?php endif; ?>
                        &middot; <?= htmlspecialchars($r['program_studi']??'') ?>
                      </div>
                    </td>
                    <?php if ($tab==='plagiasi'): ?>
                    <td style="font-size:12px;max-width:160px">
                      <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul_skripsi']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['judul_skripsi'],0,48,'…')) ?>
                      </div>
                    </td>
                    <td style="text-align:center">
                      <?php $sim_ok = $r['similarity_score'] <= $batas_sim; ?>
                      <span style="font-weight:700;font-size:14px;color:<?= $sim_ok?'var(--success-mid)':'var(--danger-mid)' ?>">
                        <?= $r['similarity_score'] ?>%
                      </span>
                    </td>
                    <?php elseif ($tab==='ec'): ?>
                    <td style="font-size:12px;max-width:200px">
                      <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul_penelitian']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['judul_penelitian'],0,55,'…')) ?>
                      </div>
                      <?php if(!empty($r['nama_jurnal'])): ?>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['nama_jurnal']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="font-size:11.5px">
                      <span class="badge" style="background:#ccfbf1;color:#0f766e;font-size:10px;padding:2px 7px;border-radius:5px;font-weight:600">
                        <?= htmlspecialchars(mb_strimwidth($r['jenis_penelitian'] ?? '—', 0, 30, '…')) ?>
                      </span>
                    </td>
                    <?php else: ?>
                    <td style="font-size:12px;max-width:160px">
                      <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul_publikasi']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($r['judul_publikasi'],0,48,'…')) ?>
                      </div>
                      <?php if($r['nama_jurnal_penerbit']): ?>
                        <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['nama_jurnal_penerbit']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge badge-process" style="font-size:10px"><?= labelJenisPublikasi($r['jenis_publikasi']) ?></span>
                    </td>
                    <?php endif; ?>
                    <td style="white-space:nowrap;font-size:12px">
                      <?= $r['tanggal_surat'] ? formatTanggal($r['tanggal_surat']) : '—' ?>
                    </td>
                    <?php if ($tab !== 'ec'): ?>
                    <td style="font-size:11px;max-width:100px">
                      <div><?= htmlspecialchars($r['nama_penandatangan']) ?></div>
                      <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($r['jabatan_penandatangan']??'') ?></div>
                    </td>
                    <td style="text-align:center">
                      <span style="font-weight:700;font-size:13px;color:<?= $r['download_count']>0?'var(--success-mid)':'var(--text-muted)' ?>">
                        <?= $r['download_count'] ?>
                      </span>
                    </td>
                    <?php else: ?>
                    <td style="font-size:11px">
                      <?php if (!empty($r['file_surat_signed'])): ?>
                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:10px;padding:2px 7px;border-radius:5px">✓ Signed</span>
                      <?php else: ?>
                        <span style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 7px;border-radius:5px">Auto-gen</span>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td onclick="event.stopPropagation()" style="white-space:nowrap">
                      <?php if ($tab !== 'ec'): ?>
                      <button type="button"
                              onclick="openDetail('det-<?= $tab ?>-<?= $r['id'] ?>')"
                              class="btn btn-outline btn-sm"
                              title="<?= $lang==='id'?'Tinjau Detail':'Review Detail' ?>"
                              style="margin-right:4px">
                        <?= ic('eye') ?> <?= $lang==='id'?'Tinjau':'Review' ?>
                      </button>
                      <?php endif; ?>
                      <?php
                        $unduh_url = $tab==='plagiasi'
                            ? "../modules/plagiasi/unduh_surat.php?id={$r['id']}"
                            : ($tab==='ec'
                                ? "../modules/ethical_clearance/unduh_surat.php?id={$r['id']}"
                                : "../modules/publikasi/unduh_surat.php?id={$r['id']}");
                      ?>
                      <a href="<?= $unduh_url ?>" target="_blank"
                         class="btn btn-success btn-sm" title="<?= $lang==='id'?'Unduh PDF':'Download PDF' ?>">
                        <?= ic('download') ?>
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:var(--surface-raised)">
              <span style="font-size:11px;color:var(--text-muted)">
                <?= ic('check-circle','style="width:12px;height:12px"') ?>
                <?= $lang==='id'?'Centang baris untuk batch download · Klik Tinjau untuk melihat detail pengajuan':'Check rows for batch download · Click Review to see submission details' ?>
              </span>
              <span style="font-size:11px;color:var(--text-muted)"><?= count($list) ?> <?= $lang==='id'?'total surat':'total certificates' ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ SLIDE-IN DRAWER ══════════════════════════════════════════ -->
<div id="detail-overlay" onclick="if(event.target===this)closeDetail()">
  <div id="detail-drawer">
    <div class="drw-head">
      <h3 id="drw-title"><?= $lang==='id'?'Detail Pengajuan':'Submission Detail' ?></h3>
      <button class="drw-close" onclick="closeDetail()">
        <svg style="width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div id="drw-body"></div>
  </div>
</div>

<!-- ══ HIDDEN DETAIL SOURCES — PLAGIASI ════════════════════════ -->
<?php if ($tab === 'plagiasi'): ?>
<?php foreach($list as $r):
  $jta = $r['jenis_tugas_akhir'] ?? 'skripsi';
  $jta_lbl = match($jta){ 'tesis'=>'Tesis','disertasi'=>'Disertasi',default=>'Skripsi' };
  $sim_ok  = ($r['similarity_score'] !== null && $r['similarity_score'] <= $batas_sim);
  $ai_ok   = ($r['ai_score'] !== null && $r['ai_score'] !== '' && $r['ai_score'] <= $batas_ai);
  $raw_ai  = trim($r['platform_ai'] ?? '');
  $ai_platform = $raw_ai !== ''
    ? ucwords(str_replace(['-','_'],' ',$raw_ai)) . ' AI Detector'
    : 'Quillbot AI Detector';
?>
<div id="det-plagiasi-<?= $r['id'] ?>" class="det-src">

  <!-- Nomor surat -->
  <div style="padding:16px 20px 0">
    <span class="nomor-chip"><?= ic('award','style="width:12px;height:12px"') ?> <?= htmlspecialchars($r['nomor_surat']) ?></span>
  </div>

  <div style="padding:0 20px 20px">

    <!-- Surat -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Informasi Surat':'Certificate Information' ?></div>
      <div class="dg">
        <div class="di"><div class="lbl">Tanggal Surat</div><div class="val"><?= formatTanggal($r['tanggal_surat']) ?></div></div>
        <div class="di"><div class="lbl">Diterbitkan</div><div class="val"><?= formatTanggal($r['created_at']) ?></div></div>
        <div class="di"><div class="lbl">Penandatangan</div><div class="val"><?= htmlspecialchars($r['nama_penandatangan']) ?></div></div>
        <div class="di"><div class="lbl">Jabatan</div><div class="val"><?= htmlspecialchars($r['jabatan_penandatangan']??'-') ?></div></div>
        <div class="di"><div class="lbl">Total Unduhan</div>
          <div class="val" style="color:<?= $r['download_count']>0?'var(--success-mid)':'#94a3b8' ?>">
            <?= $r['download_count'] ?>×
          </div>
        </div>
      </div>
    </div>

    <!-- Identitas mahasiswa -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Identitas Mahasiswa':'Student Identity' ?></div>
      <div class="dg">
        <div class="di"><div class="lbl">Nama Lengkap</div><div class="val"><?= htmlspecialchars($r['nama_lengkap']) ?></div></div>
        <div class="di"><div class="lbl">NIM</div><div class="val mono"><?= htmlspecialchars($r['nim']??'-') ?></div></div>
        <div class="di"><div class="lbl">Fakultas</div><div class="val"><?= htmlspecialchars($r['fakultas']??'-') ?></div></div>
        <div class="di"><div class="lbl">Program Studi</div><div class="val"><?= htmlspecialchars($r['program_studi']??'-') ?></div></div>
        <div class="di full"><div class="lbl">Email</div><div class="val mono"><?= htmlspecialchars($r['email']??'-') ?></div></div>
      </div>
    </div>

    <!-- Judul & info akademik -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Data '.$jta_lbl:'Thesis/Dissertation Data' ?></div>
      <div class="dg">
        <div class="di full">
          <div class="lbl">Judul <?= $jta_lbl ?></div>
          <div class="val" style="font-weight:600;line-height:1.5"><?= htmlspecialchars($r['judul_skripsi']) ?></div>
        </div>
        <?php $pem_lbl1 = $jta==='disertasi'?'Promotor':($jta==='tesis'?'Pembimbing Utama':'Pembimbing I'); ?>
        <?php $pem_lbl2 = $jta==='disertasi'?'Ko-Promotor 1':($jta==='tesis'?'Pembimbing Pendamping':'Pembimbing II'); ?>
        <?php if(!empty($r['nama_pembimbing1'])): ?>
        <div class="di"><div class="lbl"><?= $pem_lbl1 ?></div><div class="val"><?= htmlspecialchars($r['nama_pembimbing1']) ?></div></div>
        <?php endif; ?>
        <?php if(!empty($r['nama_pembimbing2'])): ?>
        <div class="di"><div class="lbl"><?= $pem_lbl2 ?></div><div class="val"><?= htmlspecialchars($r['nama_pembimbing2']) ?></div></div>
        <?php endif; ?>
        <?php if(!empty($r['nama_pembimbing3'])): ?>
        <div class="di"><div class="lbl">Ko-Promotor 2</div><div class="val"><?= htmlspecialchars($r['nama_pembimbing3']) ?></div></div>
        <?php endif; ?>
        <?php if(!empty($r['tahun_sidang'])): ?>
        <div class="di"><div class="lbl">Tahun Sidang</div><div class="val"><?= htmlspecialchars($r['tahun_sidang']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Hasil pengecekan -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Hasil Pengecekan':'Check Results' ?></div>
      <div class="score-row">
        <div class="score-box <?= $sim_ok?'score-ok':'score-bad' ?>">
          <div class="sv"><?= $r['similarity_score'] ?? '—' ?><?= $r['similarity_score']!==null?'%':'' ?></div>
          <div class="sl">Similarity</div>
          <div class="sb">batas <?= $batas_sim ?>%</div>
        </div>
        <?php if($r['ai_score'] !== null && $r['ai_score'] !== ''): ?>
        <div class="score-box <?= $ai_ok?'score-ok':'score-bad' ?>">
          <div class="sv"><?= $r['ai_score'] ?>%</div>
          <div class="sl">Deteksi AI</div>
          <div class="sb">batas <?= $batas_ai ?>% · <?= htmlspecialchars($ai_platform) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php if(!empty($r['tanggal_cek'])): ?>
      <div style="font-size:12px;color:#64748b;margin-bottom:6px">
        <?= ic('calendar','style="width:12px;height:12px"') ?> Tanggal cek: <?= formatTanggal($r['tanggal_cek']) ?>
      </div>
      <?php endif; ?>
      <?php if(!empty($r['catatan'])): ?>
      <div class="catatan-box"><?= ic('info','style="width:13px;height:13px"') ?> <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($r['catatan'])) ?></div>
      <?php endif; ?>
      <?php if(!empty($r['screenshot_path'])): ?>
      <div style="margin-top:10px">
        <div style="font-size:11px;color:#64748b;margin-bottom:6px"><?= ic('image','style="width:12px;height:12px"') ?> Screenshot hasil Turnitin:</div>
        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($r['screenshot_path']) ?>"
             style="width:100%;border-radius:8px;border:1px solid var(--border);cursor:pointer"
             onclick="window.open(this.src,'_blank')" title="Klik untuk perbesar">
      </div>
      <?php endif; ?>
    </div>

    <!-- Aksi -->
    <div class="dl-row">
      <a href="../modules/plagiasi/unduh_surat.php?id=<?= $r['id'] ?>"
         class="btn btn-success" style="flex:1;justify-content:center">
        <?= ic('download') ?> Unduh PDF Surat
      </a>
      <a href="pengguna.php?action=lihat&id=<?= $r['user_id'] ?>"
         class="btn btn-outline" style="flex:1;justify-content:center">
        <?= ic('user') ?> Profil Mahasiswa
      </a>
    </div>

  </div><!-- /padding -->
</div>
<?php endforeach; ?>

<?php else: /* PUBLIKASI */ ?>
<?php foreach($list as $r):
  $akr_map = [
    'sinta1'=>'Sinta 1','sinta2'=>'Sinta 2','sinta3'=>'Sinta 3',
    'sinta4'=>'Sinta 4','sinta5'=>'Sinta 5','sinta6'=>'Sinta 6',
    'scopusQ1'=>'Scopus Q1','scopusQ2'=>'Scopus Q2','scopusQ3'=>'Scopus Q3',
  ];
  $akr_lbl = $akr_map[$r['akreditasi_jurnal'] ?? ''] ?? htmlspecialchars($r['akreditasi_jurnal'] ?? '-');
?>
<div id="det-publikasi-<?= $r['id'] ?>" class="det-src">

  <!-- Nomor surat -->
  <div style="padding:16px 20px 0">
    <span class="nomor-chip"><?= ic('award','style="width:12px;height:12px"') ?> <?= htmlspecialchars($r['nomor_surat']) ?></span>
  </div>

  <div style="padding:0 20px 20px">

    <!-- Surat -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Informasi Surat':'Certificate Information' ?></div>
      <div class="dg">
        <div class="di"><div class="lbl">Tanggal Surat</div><div class="val"><?= formatTanggal($r['tanggal_surat']) ?></div></div>
        <div class="di"><div class="lbl">Diterbitkan</div><div class="val"><?= formatTanggal($r['created_at']) ?></div></div>
        <div class="di"><div class="lbl">Penandatangan</div><div class="val"><?= htmlspecialchars($r['nama_penandatangan']) ?></div></div>
        <div class="di"><div class="lbl">Jabatan</div><div class="val"><?= htmlspecialchars($r['jabatan_penandatangan']??'-') ?></div></div>
        <div class="di"><div class="lbl">Total Unduhan</div>
          <div class="val" style="color:<?= $r['download_count']>0?'var(--success-mid)':'#94a3b8' ?>">
            <?= $r['download_count'] ?>×
          </div>
        </div>
      </div>
    </div>

    <!-- Identitas mahasiswa -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Identitas Mahasiswa':'Student Identity' ?></div>
      <div class="dg">
        <div class="di"><div class="lbl">Nama Lengkap</div><div class="val"><?= htmlspecialchars($r['nama_lengkap']) ?></div></div>
        <div class="di"><div class="lbl">NIM</div><div class="val mono"><?= htmlspecialchars($r['nim']??'-') ?></div></div>
        <div class="di"><div class="lbl">Fakultas</div><div class="val"><?= htmlspecialchars($r['fakultas']??'-') ?></div></div>
        <div class="di"><div class="lbl">Program Studi</div><div class="val"><?= htmlspecialchars($r['program_studi']??'-') ?></div></div>
        <div class="di full"><div class="lbl">Email</div><div class="val mono"><?= htmlspecialchars($r['email']??'-') ?></div></div>
      </div>
    </div>

    <!-- Detail publikasi -->
    <div class="ds">
      <div class="ds-title"><?= $lang==='id'?'Detail Publikasi':'Publication Details' ?></div>
      <div class="dg">
        <div class="di full">
          <div class="lbl">Judul Publikasi</div>
          <div class="val" style="font-weight:600;line-height:1.5"><?= htmlspecialchars($r['judul_publikasi']) ?></div>
        </div>
        <div class="di"><div class="lbl">Jenis Publikasi</div><div class="val"><?= labelJenisPublikasi($r['jenis_publikasi']) ?></div></div>
        <div class="di"><div class="lbl">Tahun Terbit</div><div class="val"><?= htmlspecialchars($r['tahun_terbit']??'-') ?></div></div>
        <div class="di full"><div class="lbl">Jurnal / Penerbit</div><div class="val"><?= htmlspecialchars($r['nama_jurnal_penerbit']??'-') ?></div></div>
        <?php if(!empty($r['issn_isbn'])): ?>
        <div class="di"><div class="lbl">ISSN / ISBN</div><div class="val mono"><?= htmlspecialchars($r['issn_isbn']) ?></div></div>
        <?php endif; ?>
        <?php if(!empty($r['akreditasi_jurnal'])): ?>
        <div class="di"><div class="lbl">Akreditasi</div><div class="val"><?= $akr_lbl ?></div></div>
        <?php endif; ?>
        <?php if(!empty($r['url_doi'])): ?>
        <div class="di full">
          <div class="lbl">DOI / URL</div>
          <div class="val mono" style="font-size:11px;word-break:break-all">
            <a href="<?= htmlspecialchars($r['url_doi']) ?>" target="_blank"
               style="color:var(--primary)"><?= htmlspecialchars($r['url_doi']) ?></a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Aksi -->
    <div class="dl-row">
      <a href="../modules/publikasi/unduh_surat.php?id=<?= $r['id'] ?>"
         class="btn btn-success" style="flex:1;justify-content:center">
        <?= ic('download') ?> Unduh PDF Surat
      </a>
      <a href="pengguna.php?action=lihat&id=<?= $r['user_id'] ?>"
         class="btn btn-outline" style="flex:1;justify-content:center">
        <?= ic('user') ?> Profil Mahasiswa
      </a>
    </div>

  </div><!-- /padding -->
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
/* ── Batch select ──────────────────────────────────────────── */
function updateCount() {
  const checked = document.querySelectorAll('input[name="ids[]"]:checked');
  const n = checked.length;
  document.getElementById('sel-count').textContent = n;
  document.getElementById('batch-bar').classList.toggle('show', n > 0);
  const all = document.querySelectorAll('input[name="ids[]"]');
  const cbAll = document.getElementById('cb-all');
  cbAll.checked       = n === all.length && all.length > 0;
  cbAll.indeterminate = n > 0 && n < all.length;
}
function toggleAll(cb) {
  document.querySelectorAll('input[name="ids[]"]').forEach(c => {
    c.checked = cb.checked;
    c.closest('tr').classList.toggle('selected-row', cb.checked);
  });
  updateCount();
}
function toggleRow(tr) {
  const cb = tr.querySelector('input[type=checkbox]');
  cb.checked = !cb.checked;
  tr.classList.toggle('selected-row', cb.checked);
  updateCount();
}
function selectAll(val) {
  document.querySelectorAll('input[name="ids[]"]').forEach(c => {
    c.checked = val;
    c.closest('tr').classList.toggle('selected-row', val);
  });
  const cbAll = document.getElementById('cb-all');
  if (cbAll) cbAll.checked = val;
  updateCount();
}
function clearAll()    { selectAll(false); }
function submitBatch() {
  const checked = document.querySelectorAll('input[name="ids[]"]:checked');
  if (!checked.length)    { alert('Pilih minimal satu surat terlebih dahulu.'); return; }
  if (checked.length > 50){ alert('Maksimal 50 surat per batch download.'); return; }
  document.getElementById('batch-form').submit();
}

/* ── Drawer detail ─────────────────────────────────────────── */
function openDetail(id) {
  const src = document.getElementById(id);
  if (!src) return;
  document.getElementById('drw-title').textContent =
    id.includes('plagiasi') ? 'Detail Surat Bebas Plagiasi' : 'Detail Surat Keterangan Publikasi';
  document.getElementById('drw-body').innerHTML = src.innerHTML;
  document.getElementById('detail-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeDetail() {
  document.getElementById('detail-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeDetail(); });

function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>
