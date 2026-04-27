<?php
require_once '../includes/config.php';
requireLogin('mahasiswa'); // covers mahasiswa & dosen (semua non-admin)

$lang     = $_COOKIE['lang'] ?? 'id';
$is_dosen = isDosen();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id' ? 'Panduan' : 'Guide' ?> — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── Hero banner panduan ── */
.panduan-hero {
  background: linear-gradient(135deg, #0d0428 0%, #2d0f6e 55%, #130830 100%);
  border-radius: 16px;
  padding: 32px 28px 28px;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
}
.panduan-hero::before {
  content: '';
  position: absolute; inset: 0;
  background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3Ccircle cx='0' cy='0' r='10'/%3E%3Ccircle cx='60' cy='60' r='10'/%3E%3C/g%3E%3C/svg%3E") repeat;
  pointer-events: none;
}
.panduan-hero-inner { position: relative; z-index: 1; }
.panduan-hero-top {
  display: flex; align-items: center; gap: 16px; margin-bottom: 14px;
}
.panduan-hero-ico {
  width: 54px; height: 54px; border-radius: 14px; flex-shrink: 0;
  background: rgba(139,92,246,.3); border: 1.5px solid rgba(167,139,250,.4);
  display: flex; align-items: center; justify-content: center;
}
.panduan-hero-ico svg { color: #c4b5fd; }
.panduan-hero-title {
  font-size: 22px; font-weight: 800; color: #fff; line-height: 1.2;
}
.panduan-hero-sub {
  font-size: 13px; color: rgba(196,181,253,.8); margin-top: 3px;
}
.panduan-hero-desc {
  font-size: 13px; color: rgba(255,255,255,.65); line-height: 1.7;
  max-width: 680px;
}
.panduan-hero-pills {
  display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px;
}
.panduan-hero-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(139,92,246,.2); border: 1px solid rgba(167,139,250,.3);
  border-radius: 20px; padding: 5px 13px;
  font-size: 11px; font-weight: 600; color: #e9d5ff;
  cursor: pointer; text-decoration: none;
  transition: background .18s, border-color .18s, transform .15s;
}
.panduan-hero-pill svg { flex-shrink: 0; color: #c4b5fd; }
.panduan-hero-pill:hover {
  background: rgba(139,92,246,.38);
  border-color: rgba(196,181,253,.7);
  transform: translateY(-2px);
}
.panduan-hero-pill:active { transform: translateY(0); }

/* ── Section header (per jenis permohonan) ── */
.panduan-section-hdr {
  display: flex; align-items: center; gap: 14px;
  margin: 30px 0 16px;
}
.panduan-section-ico {
  width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.panduan-section-ico.s-purple { background: linear-gradient(135deg,#4a1d96,#7c3aed); }
.panduan-section-ico.s-blue   { background: linear-gradient(135deg,#1e3a8a,#3b82f6); }
.panduan-section-ico.s-teal   { background: linear-gradient(135deg,#134e4a,#0d9488); }
.panduan-section-ico.s-indigo { background: linear-gradient(135deg,#312e81,#4f46e5); }
.panduan-section-ico svg { color: #fff; }
.panduan-section-title {
  font-size: 16px; font-weight: 700; color: var(--text-primary);
}
.panduan-section-sub {
  font-size: 12px; color: var(--text-muted); margin-top: 2px;
}
.panduan-section-line {
  flex: 1; height: 1.5px;
  background: linear-gradient(90deg, var(--primary-light), transparent);
}
.panduan-shortcut {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--primary-xlight); border: 1px solid var(--primary-light);
  border-radius: 8px; padding: 6px 13px;
  font-size: 12px; font-weight: 600; color: var(--primary);
  text-decoration: none; transition: background .15s, border-color .15s;
  flex-shrink: 0;
}
.panduan-shortcut:hover {
  background: var(--primary-light); border-color: var(--accent-light);
}

/* ── Override: panduan page guide panel ── */
.page-panduan .guide-panel { margin-bottom: 0; }

/* offset untuk topbar fixed */
.panduan-anchor { scroll-margin-top: 72px; }

@media (max-width: 640px) {
  .panduan-hero { padding: 22px 18px 20px; }
  .panduan-hero-title { font-size: 18px; }
  .panduan-section-hdr { flex-wrap: wrap; gap: 10px; }
  .panduan-shortcut { order: 3; }
}
</style>
</head>
<style>html { scroll-behavior: smooth; }</style>
<body class="page-panduan">
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= $lang==='id' ? 'Panduan' : 'Guide' ?>
          <span class="breadcrumb">
            <?= $lang==='id' ? 'Panduan pengajuan permohonan' : 'Application submission guide' ?>
          </span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- ── Hero ── -->
      <div class="panduan-hero">
        <div class="panduan-hero-inner">
          <div class="panduan-hero-top">
            <div class="panduan-hero-ico">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
              </svg>
            </div>
            <div>
              <div class="panduan-hero-title">
                <?= $lang==='id' ? 'Panduan Pengajuan Permohonan' : 'Application Submission Guide' ?>
              </div>
              <div class="panduan-hero-sub">LPPM IAKN Toraja</div>
            </div>
          </div>
          <div class="panduan-hero-desc">
            <?php if ($is_dosen): ?>
              <?= $lang==='id'
                ? 'Halaman ini berisi panduan lengkap pengajuan proposal penelitian dan permohonan Ethical Clearance di sistem LPPM IAKN Toraja. Ikuti langkah-langkah yang tertera agar proses pengajuan Anda berjalan lancar.'
                : 'This page contains complete guides for research proposal submission and Ethical Clearance applications in the LPPM IAKN Toraja system. Follow the steps shown to ensure your submission runs smoothly.'
              ?>
            <?php else: ?>
              <?= $lang==='id'
                ? 'Halaman ini berisi panduan lengkap dan alur SOP untuk setiap jenis permohonan yang tersedia di sistem LPPM IAKN Toraja. Ikuti langkah-langkah yang tertera agar proses pengajuan Anda berjalan lancar dan cepat diproses oleh tim LPPM.'
                : 'This page contains complete guides and SOP flows for each application type available in the LPPM IAKN Toraja system. Follow the steps shown to ensure your submission runs smoothly and is processed quickly by the LPPM team.'
              ?>
            <?php endif; ?>
          </div>
          <div class="panduan-hero-pills">
            <?php if ($is_dosen): ?>
              <!-- Dosen: penelitian + ec -->
              <a href="#sec-penelitian" class="panduan-hero-pill">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?= $lang==='id' ? 'Proposal Penelitian' : 'Research Proposal' ?>
              </a>
              <a href="#sec-ec" class="panduan-hero-pill">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>
                Ethical Clearance
              </a>
            <?php else: ?>
              <!-- Mahasiswa: plagiasi + publikasi + ec -->
              <a href="#sec-plagiasi" class="panduan-hero-pill">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?= $lang==='id' ? 'Bebas Plagiasi' : 'Plagiarism-Free' ?>
              </a>
              <a href="#sec-publikasi" class="panduan-hero-pill">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>
                <?= $lang==='id' ? 'Surat Publikasi' : 'Publication Letter' ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php require_once '../includes/guide.php'; ?>

      <?php if ($is_dosen): ?>

      <!-- ══════════════════════════════════════════════════════
           PENELITIAN (dosen only)
      ══════════════════════════════════════════════════════ -->
      <div id="sec-penelitian" class="panduan-section-hdr panduan-anchor">
        <div class="panduan-section-ico s-indigo">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </div>
        <div>
          <div class="panduan-section-title">
            <?= $lang==='id' ? 'Proposal Penelitian' : 'Research Proposal' ?>
          </div>
          <div class="panduan-section-sub">
            <?= $lang==='id' ? '7 langkah pengajuan' : '7 submission steps' ?>
          </div>
        </div>
        <div class="panduan-section-line"></div>
        <a href="<?= BASE_URL ?>/modules/penelitian/ajukan.php" class="panduan-shortcut">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
          </svg>
          <?= $lang==='id' ? 'Ajukan Sekarang' : 'Apply Now' ?>
        </a>
      </div>
      <?php renderGuide('penelitian', $lang, true); ?>

      <?php else: ?>

      <!-- ══════════════════════════════════════════════════════
           BEBAS PLAGIASI (mahasiswa only)
      ══════════════════════════════════════════════════════ -->
      <div id="sec-plagiasi" class="panduan-section-hdr panduan-anchor">
        <div class="panduan-section-ico s-purple">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </div>
        <div>
          <div class="panduan-section-title">
            <?= $lang==='id' ? 'Surat Keterangan Bebas Plagiasi' : 'Plagiarism-Free Certificate' ?>
          </div>
          <div class="panduan-section-sub">
            <?= $lang==='id' ? '6 langkah pengajuan' : '6 submission steps' ?>
          </div>
        </div>
        <div class="panduan-section-line"></div>
        <a href="<?= BASE_URL ?>/modules/plagiasi/upload.php" class="panduan-shortcut">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
          </svg>
          <?= $lang==='id' ? 'Ajukan Sekarang' : 'Apply Now' ?>
        </a>
      </div>
      <?php renderGuide('plagiasi', $lang, true); ?>

      <!-- ══════════════════════════════════════════════════════
           PUBLIKASI (mahasiswa only)
      ══════════════════════════════════════════════════════ -->
      <div id="sec-publikasi" class="panduan-section-hdr panduan-anchor">
        <div class="panduan-section-ico s-blue">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/>
            <path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/>
          </svg>
        </div>
        <div>
          <div class="panduan-section-title">
            <?= $lang==='id' ? 'Surat Keterangan Publikasi' : 'Publication Certificate' ?>
          </div>
          <div class="panduan-section-sub">
            <?= $lang==='id' ? '5 langkah pengajuan' : '5 submission steps' ?>
          </div>
        </div>
        <div class="panduan-section-line"></div>
        <a href="<?= BASE_URL ?>/modules/publikasi/upload.php" class="panduan-shortcut">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
          </svg>
          <?= $lang==='id' ? 'Ajukan Sekarang' : 'Apply Now' ?>
        </a>
      </div>
      <?php renderGuide('publikasi', $lang, true); ?>

      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════
           ETHICAL CLEARANCE (dosen; mahasiswa belum punya akses)
      ══════════════════════════════════════════════════════ -->
      <?php if ($is_dosen): ?>
      <div id="sec-ec" class="panduan-section-hdr panduan-anchor">
        <div class="panduan-section-ico s-teal">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="2" width="6" height="4" rx="1"/>
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
            <path d="M9 12h6M9 16h4"/>
          </svg>
        </div>
        <div>
          <div class="panduan-section-title">Ethical Clearance</div>
          <div class="panduan-section-sub">
            <?= $lang==='id' ? '7 langkah pengajuan' : '7 submission steps' ?>
          </div>
        </div>
        <div class="panduan-section-line"></div>
        <a href="<?= BASE_URL ?>/modules/ethical_clearance/upload.php" class="panduan-shortcut">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
          </svg>
          <?= $lang==='id' ? 'Ajukan Sekarang' : 'Apply Now' ?>
        </a>
      </div>
      <?php renderGuide('ec', $lang, true); ?>
      <?php endif; // EC: dosen only — tampilkan lagi jika mahasiswa mendapat akses EC ?>

      <!-- ── Tips bawah ── -->
      <div style="margin-top:28px; padding:18px 20px; background:linear-gradient(135deg,#f0fdf4,#dcfce7);
                  border:1.5px solid #bbf7d0; border-radius:12px; display:flex; gap:14px; align-items:flex-start;">
        <div style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#064e3b,#059669);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 8v4"/><path d="M12 16h.01"/>
          </svg>
        </div>
        <div>
          <div style="font-size:13px;font-weight:700;color:#166534;margin-bottom:5px;">
            <?= $lang==='id' ? 'Tips Penting' : 'Important Tips' ?>
          </div>
          <div style="font-size:12px;color:#14532d;line-height:1.7;">
            <?= $lang==='id'
              ? '<strong>Sebelum mengajukan</strong>, pastikan data profil Anda sudah lengkap (' . ($is_dosen ? 'NIDN, Program Studi, jabatan fungsional' : 'NIM/NIDN, Program Studi, nomor HP') . '). Semua pengajuan yang belum memiliki profil lengkap <strong>tidak dapat diproses</strong>. Jika ada pertanyaan, gunakan fitur <a href="'.BASE_URL.'/modules/chat/" style="color:#059669;font-weight:600;">Chat dengan Admin</a>.'
              : '<strong>Before submitting</strong>, ensure your profile is complete (' . ($is_dosen ? 'NIDN, Study Program, academic position' : 'NIM/NIDN, Study Program, phone number') . '). All submissions without a complete profile <strong>cannot be processed</strong>. For questions, use the <a href="'.BASE_URL.'/modules/chat/" style="color:#059669;font-weight:600;">Chat with Admin</a> feature.'
            ?>
          </div>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->
</body>
</html>
