<?php
// ============================================================
// PANDUAN & ALUR PENGAJUAN — Komponen reusable
// Dipanggil dari: modules/plagiasi/upload.php
//                 modules/publikasi/upload.php
//                 modules/ethical_clearance/upload.php
// ============================================================

function renderGuide(string $type, string $lang, bool $expanded = false): void
{
    // ─── Ikon SVG ────────────────────────────────────────────
    $ico = [
        'user'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'file'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'edit'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'upload'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>',
        'search'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'clock'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'download' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'shield'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'pen'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        'news'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>',
        'clip'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>',
    ];

    // ─── Palet warna per langkah (berulang jika > 7 langkah) ─
    // Format: [gradient top, bg card, border card]
    $palette = [
        ['linear-gradient(135deg,#3b0764,#7c3aed)', '#faf5ff', '#ddd6fe'],  // 1 purple
        ['linear-gradient(135deg,#1e3a8a,#3b82f6)', '#eff6ff', '#bfdbfe'],  // 2 blue
        ['linear-gradient(135deg,#164e63,#0891b2)', '#ecfeff', '#a5f3fc'],  // 3 cyan
        ['linear-gradient(135deg,#134e4a,#0d9488)', '#f0fdfa', '#99f6e4'],  // 4 teal
        ['linear-gradient(135deg,#78350f,#d97706)', '#fffbeb', '#fde68a'],  // 5 amber
        ['linear-gradient(135deg,#6b21a8,#a855f7)', '#fdf4ff', '#e9d5ff'],  // 6 violet
        ['linear-gradient(135deg,#064e3b,#059669)', '#f0fdf4', '#bbf7d0'],  // 7 green
    ];

    // ─── Data per jenis ──────────────────────────────────────
    $data = [

        // ── BEBAS PLAGIASI ────────────────────────────────────
        'plagiasi' => [
            'id' => [
                'title'       => 'Panduan: Surat Keterangan Bebas Plagiasi',
                'header_icon' => $ico['search'],
                'desc'        => 'Surat ini menyatakan bahwa Tugas Akhir (Skripsi/Tesis/Disertasi) Anda telah lolos uji kesamaan Turnitin dan uji deteksi AI sesuai ketentuan LPPM IAKN Toraja. Diterbitkan setelah verifikasi resmi oleh admin LPPM.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Lengkapi Profil',      'desc'=>'Pastikan NIM, program studi, dan nomor HP sudah terisi di menu Profil',     'pill'=>'Wajib dahulu'],
                    ['icon'=>$ico['file'],     'title'=>'Siapkan File TA',       'desc'=>'File Tugas Akhir format PDF, ukuran maks. 20 MB',                           'pill'=>'Format PDF'],
                    ['icon'=>$ico['edit'],     'title'=>'Isi Form Pengajuan',    'desc'=>'Isi judul, jenis TA, nama pembimbing, dan tahun sidang',                    'pill'=>null],
                    ['icon'=>$ico['search'],   'title'=>'Lapor Cek Mandiri',     'desc'=>'Isi skor Turnitin & AI jika sudah pernah dicek sendiri',                   'pill'=>'Opsional'],
                    ['icon'=>$ico['clock'],    'title'=>'Tunggu Verifikasi',     'desc'=>'Admin LPPM mengecek dengan Turnitin resmi (1–3 hari kerja)',                'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Unduh Surat',           'desc'=>'Jika lolos → notifikasi dikirim, surat siap diunduh',                      'pill'=>'Selesai ✓'],
                ],
            ],
            'en' => [
                'title'       => 'Guide: Plagiarism-Free Certificate',
                'header_icon' => $ico['search'],
                'desc'        => 'This certificate confirms that your Final Project has passed the Turnitin similarity check and AI detection test per LPPM IAKN Toraja standards. Issued after official verification by LPPM admin.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Complete Profile',      'desc'=>'Ensure your student ID, study program, and phone are filled in Profile',   'pill'=>'Required first'],
                    ['icon'=>$ico['file'],     'title'=>'Prepare Your File',     'desc'=>'Final Project PDF file, max 20 MB',                                        'pill'=>'PDF only'],
                    ['icon'=>$ico['edit'],     'title'=>'Fill Out the Form',     'desc'=>'Enter title, project type, supervisor name, and defense year',             'pill'=>null],
                    ['icon'=>$ico['search'],   'title'=>'Report Self-Check',     'desc'=>'Enter Turnitin/AI scores if you already checked yourself',                 'pill'=>'Optional'],
                    ['icon'=>$ico['clock'],    'title'=>'Wait for Verification', 'desc'=>'LPPM admin runs official Turnitin check (1–3 working days)',               'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Download Certificate',  'desc'=>'If passed → notification sent, certificate ready to download',             'pill'=>'Done ✓'],
                ],
            ],
        ],

        // ── PUBLIKASI ─────────────────────────────────────────
        'publikasi' => [
            'id' => [
                'title'       => 'Panduan: Surat Keterangan Publikasi',
                'header_icon' => $ico['news'],
                'desc'        => 'Surat keterangan/rekomendasi yang menyatakan bahwa karya ilmiah Anda (jurnal, buku, book chapter, atau prosiding) telah terverifikasi oleh LPPM IAKN Toraja. Dapat digunakan untuk keperluan akademik dan administrasi.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Lengkapi Profil',         'desc'=>'Pastikan data profil sudah lengkap sebelum memulai pengajuan',              'pill'=>'Wajib dahulu'],
                    ['icon'=>$ico['file'],     'title'=>'Siapkan Bukti Publikasi', 'desc'=>'File artikel/jurnal/buku dalam format PDF, JPG, atau PNG, maks. 20 MB',   'pill'=>'Wajib'],
                    ['icon'=>$ico['edit'],     'title'=>'Isi Data Publikasi',      'desc'=>'Isi judul, jenis, nama jurnal/penerbit, tahun, ISSN/ISBN, dan DOI/URL',    'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Tunggu Verifikasi',       'desc'=>'Admin LPPM memverifikasi keaslian dan kelengkapan data publikasi',         'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Unduh Surat',             'desc'=>'Jika diverifikasi → notifikasi & email dikirim, surat siap diunduh',       'pill'=>'Selesai ✓'],
                ],
            ],
            'en' => [
                'title'       => 'Guide: Publication Certificate',
                'header_icon' => $ico['news'],
                'desc'        => 'A certificate confirming that your scientific publication (journal, book, book chapter, or proceedings) has been verified by LPPM IAKN Toraja. Valid for academic and administrative use.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Complete Profile',          'desc'=>'Ensure your profile is complete before starting',                          'pill'=>'Required first'],
                    ['icon'=>$ico['file'],     'title'=>'Prepare Publication Proof', 'desc'=>'Article/journal/book file in PDF, JPG, or PNG, max 20 MB',                'pill'=>'Required'],
                    ['icon'=>$ico['edit'],     'title'=>'Fill in Publication Data',  'desc'=>'Enter title, type, journal/publisher, year, ISSN/ISBN, and DOI/URL',      'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Wait for Admin Review',     'desc'=>'LPPM admin verifies the authenticity and completeness of your data',       'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Download Certificate',      'desc'=>'If verified → notification & email sent, certificate ready to download',  'pill'=>'Done ✓'],
                ],
            ],
        ],

        // ── PENELITIAN ────────────────────────────────────────
        'penelitian' => [
            'id' => [
                'title'       => 'Panduan: Pengajuan Proposal Penelitian',
                'header_icon' => $ico['edit'],
                'desc'        => 'Panduan langkah-langkah mengajukan proposal penelitian internal melalui sistem LPPM IAKN Toraja. Proposal yang berhasil diajukan akan ditinjau oleh reviewer dan diproses sesuai skema yang dipilih.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Lengkapi Profil',         'desc'=>'Pastikan NIDN, program studi, dan jabatan fungsional sudah terisi di menu Profil',               'pill'=>'Wajib dahulu'],
                    ['icon'=>$ico['search'],   'title'=>'Pilih Skema Penelitian',  'desc'=>'Pilih skema penelitian yang sesuai bidang dan jenjang Anda. Perhatikan target publikasi tiap skema', 'pill'=>null],
                    ['icon'=>$ico['file'],     'title'=>'Isi Data Proposal',       'desc'=>'Isi judul, abstrak, latar belakang penelitian, dan kata kunci dengan lengkap dan jelas',           'pill'=>null],
                    ['icon'=>$ico['pen'],      'title'=>'Susun Tim Peneliti',      'desc'=>'Tambahkan anggota dosen, mahasiswa, dan mitra bestari (jika ada). Lengkapi NIDN/NIM tiap anggota', 'pill'=>null],
                    ['icon'=>$ico['upload'],   'title'=>'Ajukan Proposal',         'desc'=>'Klik "Ajukan Proposal". Jika ada data yang belum lengkap, sistem menyimpan sebagai draft otomatis', 'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Proses Review',           'desc'=>'Admin LPPM meninjau kelengkapan dan meneruskan ke reviewer. Status berubah: Diajukan → Ditinjau',  'pill'=>'1–7 hari kerja'],
                    ['icon'=>$ico['download'], 'title'=>'Lihat Hasil',             'desc'=>'Pantau status di Riwayat Proposal. Jika disetujui → SK Penelitian dapat diunduh',                 'pill'=>'Selesai ✓'],
                ],
            ],
            'en' => [
                'title'       => 'Guide: Research Proposal Submission',
                'header_icon' => $ico['edit'],
                'desc'        => 'Step-by-step guide for submitting an internal research proposal through the LPPM IAKN Toraja system. Submitted proposals will be reviewed and processed according to the selected scheme.',
                'steps'       => [
                    ['icon'=>$ico['user'],     'title'=>'Complete Profile',         'desc'=>'Ensure your NIDN, study program, and academic position are filled in Profile',                    'pill'=>'Required first'],
                    ['icon'=>$ico['search'],   'title'=>'Select Research Scheme',   'desc'=>'Choose a scheme matching your field and level. Note the target publication for each scheme',      'pill'=>null],
                    ['icon'=>$ico['file'],     'title'=>'Fill in Proposal Data',    'desc'=>'Enter the title, abstract, research background, and keywords clearly and completely',             'pill'=>null],
                    ['icon'=>$ico['pen'],      'title'=>'Build Research Team',      'desc'=>'Add lecturer members, students, and external reviewers (if any). Include NIDN/NIM for each',    'pill'=>null],
                    ['icon'=>$ico['upload'],   'title'=>'Submit Proposal',          'desc'=>'Click "Submit Proposal". If any data is incomplete, the system auto-saves it as a draft',        'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Review Process',           'desc'=>'LPPM admin checks completeness and forwards to reviewer. Status: Submitted → Under Review',      'pill'=>'1–7 working days'],
                    ['icon'=>$ico['download'], 'title'=>'View Results',             'desc'=>'Track status in Proposal History. If approved → Research Decree letter ready to download',       'pill'=>'Done ✓'],
                ],
            ],
        ],

        // ── ETHICAL CLEARANCE ─────────────────────────────────
        'ec' => [
            'id' => [
                'title'       => 'Panduan: Permohonan Ethical Clearance',
                'header_icon' => $ico['clip'],
                'desc'        => 'Letter of Ethical Approval menyatakan bahwa penelitian Anda telah dinilai secara etik oleh Tim Etik LPPM IAKN Toraja dan tidak ada keberatan etis. Wajib dilengkapi dokumen resmi bermeterai.',
                'steps'       => [
                    ['icon'=>$ico['file'],     'title'=>'Siapkan Dokumen Wajib',  'desc'=>'Scan: (1) Surat Permohonan & (2) Surat Pernyataan Bermeterai — keduanya WAJIB', 'pill'=>'Wajib'],
                    ['icon'=>$ico['shield'],   'title'=>'Dokumen Tambahan',        'desc'=>'Informed Consent, Proposal, Laporan/Artikel, Surat Izin (jika ada)',           'pill'=>'Jika ada'],
                    ['icon'=>$ico['edit'],     'title'=>'Isi Formulir',            'desc'=>'Isi judul penelitian, ketua peneliti, jurnal target, dan anggota tim',         'pill'=>null],
                    ['icon'=>$ico['upload'],   'title'=>'Upload & Ajukan',         'desc'=>'Upload semua dokumen lalu klik "Ajukan Permohonan"',                           'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Review Tim Etik',         'desc'=>'Tim Etik LPPM meninjau permohonan (estimasi 3–7 hari kerja)',                  'pill'=>null],
                    ['icon'=>$ico['pen'],      'title'=>'Penandatanganan',         'desc'=>'Jika disetujui, Tim Etik menandatangani dan mengupload surat resmi',           'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Unduh Surat',             'desc'=>'Unduh Letter of Ethical Approval bertandatangan dari Dashboard',              'pill'=>'Selesai ✓'],
                ],
            ],
            'en' => [
                'title'       => 'Guide: Ethical Clearance Application',
                'header_icon' => $ico['clip'],
                'desc'        => 'The Letter of Ethical Approval certifies that your research has been ethically reviewed by the LPPM IAKN Toraja Research Ethics Committee with no ethical objections. Official stamped documents are required.',
                'steps'       => [
                    ['icon'=>$ico['file'],     'title'=>'Required Documents',     'desc'=>'Scan: (1) Application Letter & (2) Stamped Declaration — both REQUIRED',    'pill'=>'Required'],
                    ['icon'=>$ico['shield'],   'title'=>'Additional Documents',   'desc'=>'Informed Consent, Proposal, Research Report, Permission Letter (if any)',    'pill'=>'If applicable'],
                    ['icon'=>$ico['edit'],     'title'=>'Fill Out the Form',      'desc'=>'Enter research title, lead researcher, target journal, and team members',    'pill'=>null],
                    ['icon'=>$ico['upload'],   'title'=>'Upload & Submit',        'desc'=>'Upload all documents and click "Submit Application"',                        'pill'=>null],
                    ['icon'=>$ico['clock'],    'title'=>'Ethics Committee Review','desc'=>'LPPM Ethics Committee reviews your application (3–7 working days)',          'pill'=>null],
                    ['icon'=>$ico['pen'],      'title'=>'Letter Signing',         'desc'=>'If approved, the Committee signs and uploads the official stamped letter',   'pill'=>null],
                    ['icon'=>$ico['download'], 'title'=>'Download Certificate',   'desc'=>'Download the signed Letter of Ethical Approval from your Dashboard',        'pill'=>'Done ✓'],
                ],
            ],
        ],
    ];

    if (!isset($data[$type])) return;
    $d       = $data[$type][$lang] ?? $data[$type]['id'];
    $steps   = $d['steps'];
    $count   = count($steps);
    $guideId = 'guide_' . $type;
    $lsKey   = 'guide_hide_' . $type;

    // Bagi steps ke baris: ≤5 → satu baris; >5 → dua baris setara
    $row_size = $count <= 5 ? $count : (int)ceil($count / 2);
    $rows     = array_chunk($steps, $row_size);

    // Helper resize SVG ke ukuran tertentu
    $sz = static fn(string $svg, int $s): string =>
        preg_replace('/width="\d+" height="\d+"/', "width=\"{$s}\" height=\"{$s}\"", $svg, 1);

    // Arrow SVG kanan (→); CSS rotate(90deg) di mobile jadi ↓
    $arrowSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
    ?>

    <div class="guide-panel" id="<?= $guideId ?>-panel">

      <!-- ── Header bar (klik = toggle) ────────────────────── -->
      <div class="guide-header" onclick="guideToggle('<?= $guideId ?>', '<?= $lsKey ?>')">
        <div class="guide-header-left">
          <div class="guide-header-badge">
            <?= $sz($d['header_icon'], 16) ?>
            <span><?= htmlspecialchars($d['title']) ?></span>
          </div>
          <div class="guide-header-steps-count">
            <strong><?= $count ?></strong>&nbsp;<?= $lang === 'id' ? 'langkah' : 'steps' ?>
          </div>
        </div>
        <button type="button" class="guide-toggle-btn <?= $expanded ? 'open' : '' ?>" id="<?= $guideId ?>-tbtn"
                onclick="event.stopPropagation(); guideToggle('<?= $guideId ?>', '<?= $lsKey ?>')">
          <span><?= $lang === 'id' ? 'Lihat Panduan' : 'View Guide' ?></span>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>
      </div>

      <!-- ── Body (.open = expanded) ─── -->
      <div class="guide-body <?= $expanded ? 'open' : '' ?>" id="<?= $guideId ?>-body">

        <!-- Deskripsi singkat -->
        <div class="guide-desc-banner">
          <div class="guide-desc-icon"><?= $sz($d['header_icon'], 20) ?></div>
          <div class="guide-desc-text"><?= htmlspecialchars($d['desc']) ?></div>
        </div>

        <!-- Divider label -->
        <div class="guide-section-label">
          <?= $lang === 'id' ? 'Alur Pengajuan SOP' : 'Submission SOP Flow' ?>
        </div>

        <!-- ── Flow rows ── -->
        <div class="guide-flow">
          <?php
          $idx = 0;
          foreach ($rows as $row_items):
          ?>
          <div class="guide-flow-row">
            <?php foreach ($row_items as $ri => $step):
              $c = $palette[$idx % count($palette)];
              $idx++;
              if ($ri > 0): ?>
            <div class="guide-arrow"><?= $arrowSvg ?></div>
            <?php endif; ?>
            <div class="guide-step-card" style="border-color:<?= $c[2] ?>;background:<?= $c[1] ?>">
              <!-- Colored top -->
              <div class="gsc-top" style="background:<?= $c[0] ?>">
                <div class="gsc-num"><?= $idx ?></div>
                <div class="gsc-ico"><?= $sz($step['icon'], 22) ?></div>
                <?php if ($step['pill']): ?>
                <span class="gsc-pill"><?= htmlspecialchars($step['pill']) ?></span>
                <?php endif; ?>
              </div>
              <!-- Content -->
              <div class="gsc-body">
                <div class="guide-step-title"><?= htmlspecialchars($step['title']) ?></div>
                <div class="guide-step-desc-text"><?= htmlspecialchars($step['desc']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div><!-- /guide-flow -->

      </div><!-- /guide-body -->
    </div><!-- /guide-panel -->

    <script>
    function guideToggle(id) {
      var body = document.getElementById(id + '-body');
      var tbtn = document.getElementById(id + '-tbtn');
      if (!body) return;
      body.classList.toggle('open');
      if (tbtn) tbtn.classList.toggle('open');
    }
    </script>
    <?php
}
