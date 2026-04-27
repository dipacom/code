<?php
require_once '../includes/config.php';
requireLogin('admin');
$lang = $_COOKIE['lang'] ?? 'id';

// ── Palet warna (sama dengan guide user) ─────────────────────
$palette = [
    ['linear-gradient(135deg,#3b0764,#7c3aed)', '#faf5ff', '#ddd6fe'],  // purple
    ['linear-gradient(135deg,#1e3a8a,#3b82f6)', '#eff6ff', '#bfdbfe'],  // blue
    ['linear-gradient(135deg,#164e63,#0891b2)', '#ecfeff', '#a5f3fc'],  // cyan
    ['linear-gradient(135deg,#134e4a,#0d9488)', '#f0fdfa', '#99f6e4'],  // teal
    ['linear-gradient(135deg,#78350f,#d97706)', '#fffbeb', '#fde68a'],  // amber
    ['linear-gradient(135deg,#6b21a8,#a855f7)', '#fdf4ff', '#e9d5ff'],  // violet
    ['linear-gradient(135deg,#064e3b,#059669)', '#f0fdf4', '#bbf7d0'],  // green
];

// Helper resize SVG
$sz = fn(string $s, int $n): string =>
    preg_replace('/width="\d+" height="\d+"/', "width=\"{$n}\" height=\"{$n}\"", $s, 1);

// ── Icon SVG library ─────────────────────────────────────────
$I = [
    'bell'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
    'eye'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    'download' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    'search'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'check'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'x'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    'upload'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>',
    'edit'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    'users'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'chat'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    'send'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
    'chart'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'filter'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
    'archive'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
    'settings' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    'shield'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'toggle'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/></svg>',
    'pen'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
    'trash'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
    'refresh'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/></svg>',
    'home'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'clip'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>',
    'news'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8V6Z"/></svg>',
    'info'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    'log'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
];

// ── Data section panduan admin ───────────────────────────────
// Setiap section: id, icon (SVG 20px), warna header, judul, link, steps[]
// Step: icon key, title_id, title_en, desc_id, desc_en, pill (null/string)

$id = $lang === 'id';  // shorthand

$sections = [

    // ── 1. DASHBOARD ─────────────────────────────────────────
    [
        'id'       => 'sec-dashboard',
        'hdr_ico'  => 's-purple',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'title'    => $id ? 'Dashboard' : 'Dashboard',
        'sub'      => $id ? '3 hal yang perlu dicek setiap hari' : '3 things to check daily',
        'link'     => BASE_URL . '/admin/dashboard.php',
        'link_lbl' => $id ? 'Buka Dashboard' : 'Open Dashboard',
        'steps'    => [
            ['ico'=>$I['bell'],   'title'=>$id?'Cek Notifikasi Baru':'Check New Notifications',   'desc'=>$id?'Perhatikan ikon lonceng di topbar — angka merah menunjukkan permohonan baru atau pesan yang belum dibaca.':'Check the bell icon on the topbar — red numbers indicate new applications or unread messages.', 'pill'=>$id?'Setiap hari':'Daily'],
            ['ico'=>$I['eye'],    'title'=>$id?'Pantau Permohonan Masuk':'Monitor Incoming Applications', 'desc'=>$id?'Lihat ringkasan permohonan yang menunggu verifikasi di tiga kategori: Bebas Plagiasi, Publikasi, dan Ethical Clearance.':'View summaries of applications awaiting verification in three categories: Plagiarism-Free, Publication, and Ethical Clearance.', 'pill'=>null],
            ['ico'=>$I['chart'],  'title'=>$id?'Lihat Statistik Ringkas':'View Quick Stats',        'desc'=>$id?'Grafik di dashboard memperlihatkan tren permohonan bulanan, persentase lolos/tolak, dan aktivitas pengguna terkini.':'Dashboard charts show monthly application trends, pass/reject percentages, and recent user activity.', 'pill'=>$id?'Informasi':'Info'],
        ],
    ],

    // ── 2. BEBAS PLAGIASI ─────────────────────────────────────
    [
        'id'       => 'sec-plagiasi',
        'hdr_ico'  => 's-blue',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'title'    => $id ? 'Verifikasi Bebas Plagiasi' : 'Plagiarism-Free Verification',
        'sub'      => $id ? '6 langkah memproses permohonan' : '6 steps to process an application',
        'link'     => BASE_URL . '/admin/plagiasi.php',
        'link_lbl' => $id ? 'Buka Halaman' : 'Open Page',
        'steps'    => [
            ['ico'=>$I['bell'],     'title'=>$id?'Terima Notifikasi':'Receive Notification',      'desc'=>$id?'Saat mahasiswa mengajukan, notifikasi masuk ke bell dan email admin. Klik notifikasi atau buka menu Bebas Plagiasi.':'When a student submits, a notification goes to the bell and admin email. Click the notification or open the Plagiarism-Free menu.', 'pill'=>$id?'Otomatis':'Auto'],
            ['ico'=>$I['eye'],      'title'=>$id?'Buka Detail Permohonan':'Open Application Detail', 'desc'=>$id?'Klik nama mahasiswa di tabel untuk membuka drawer detail. Periksa data: judul, jenis TA, pembimbing, dan hasil cek mandiri (jika ada).':'Click the student name in the table to open the detail drawer. Check: title, type, supervisor, and self-check results (if any).', 'pill'=>null],
            ['ico'=>$I['download'], 'title'=>$id?'Unduh File Tugas Akhir':'Download Final Project File', 'desc'=>$id?'Klik tombol unduh PDF di drawer detail. File Tugas Akhir mahasiswa siap dicek di Turnitin.':'Click the PDF download button in the detail drawer. The student\'s Final Project file is ready to be checked in Turnitin.', 'pill'=>null],
            ['ico'=>$I['search'],   'title'=>$id?'Jalankan Cek Turnitin':'Run Turnitin Check',     'desc'=>$id?'Upload file ke akun Turnitin resmi LPPM. Catat skor Similarity dan persentase AI. Simpan laporan PDF sebagai bukti.':'Upload the file to the official LPPM Turnitin account. Note the Similarity score and AI percentage. Save the PDF report as evidence.', 'pill'=>$id?'Wajib':'Required'],
            ['ico'=>$I['edit'],     'title'=>$id?'Input Hasil & Upload Bukti':'Enter Results & Upload Evidence', 'desc'=>$id?'Di drawer detail, isi kolom Similarity Turnitin, Deteksi AI, unggah file laporan Turnitin, lalu isi Nomor Surat.':'In the detail drawer, fill in the Turnitin Similarity, AI Detection, upload the Turnitin report file, then fill in the Letter Number.', 'pill'=>null],
            ['ico'=>$I['check'],    'title'=>$id?'Putuskan & Simpan Status':'Decide & Save Status', 'desc'=>$id?'Pilih "Diverifikasi" jika lolos batas, atau "Ditolak" jika melampaui batas. Klik Simpan — sistem kirim notifikasi & email otomatis.':'Choose "Verified" if within limits, or "Rejected" if exceeding limits. Click Save — the system automatically sends notifications & emails.', 'pill'=>$id?'Selesai ✓':'Done ✓'],
        ],
    ],

    // ── 3. SURAT PUBLIKASI ────────────────────────────────────
    [
        'id'       => 'sec-publikasi',
        'hdr_ico'  => 's-cyan',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8V6Z"/></svg>',
        'title'    => $id ? 'Verifikasi Surat Publikasi' : 'Publication Letter Verification',
        'sub'      => $id ? '5 langkah memproses permohonan' : '5 steps to process an application',
        'link'     => BASE_URL . '/admin/publikasi.php',
        'link_lbl' => $id ? 'Buka Halaman' : 'Open Page',
        'steps'    => [
            ['ico'=>$I['bell'],     'title'=>$id?'Terima Notifikasi':'Receive Notification',       'desc'=>$id?'Notifikasi masuk saat pengguna (mahasiswa/dosen) mengunggah bukti publikasi. Buka menu Surat Publikasi.':'Notification arrives when a user (student/lecturer) uploads publication proof. Open the Publication Letter menu.', 'pill'=>$id?'Otomatis':'Auto'],
            ['ico'=>$I['eye'],      'title'=>$id?'Periksa Data Publikasi':'Check Publication Data', 'desc'=>$id?'Klik baris permohonan untuk membuka detail. Cek judul, jenis (jurnal/buku/prosiding), penerbit, tahun, ISSN/ISBN, dan URL/DOI.':'Click the application row to open details. Check title, type (journal/book/proceedings), publisher, year, ISSN/ISBN, and URL/DOI.', 'pill'=>null],
            ['ico'=>$I['download'], 'title'=>$id?'Unduh & Verifikasi Bukti':'Download & Verify Evidence', 'desc'=>$id?'Unduh file bukti publikasi. Konfirmasi keaslian dengan membuka URL/DOI yang disertakan, pastikan nama penulis sesuai.':'Download the publication proof file. Confirm authenticity by opening the provided URL/DOI, verify the author name matches.', 'pill'=>$id?'Wajib':'Required'],
            ['ico'=>$I['edit'],     'title'=>$id?'Input Nomor Surat':'Enter Letter Number',         'desc'=>$id?'Isi nomor surat sesuai format LPPM (contoh: 012/LPPM-IAKN/V/2025). Nomor surat akan tercetak di surat keterangan.':'Fill in the letter number per LPPM format (e.g., 012/LPPM-IAKN/V/2025). This number will appear on the certificate.', 'pill'=>null],
            ['ico'=>$I['check'],    'title'=>$id?'Putuskan & Simpan':'Decide & Save',               'desc'=>$id?'Pilih "Diverifikasi" atau "Ditolak". Klik Simpan — surat otomatis bisa diunduh pemohon dan notifikasi dikirim.':'Choose "Verified" or "Rejected". Click Save — the certificate is automatically downloadable and notifications are sent.', 'pill'=>$id?'Selesai ✓':'Done ✓'],
        ],
    ],

    // ── 4. ETHICAL CLEARANCE ─────────────────────────────────
    [
        'id'       => 'sec-ec',
        'hdr_ico'  => 's-teal',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></svg>',
        'title'    => $id ? 'Proses Ethical Clearance' : 'Process Ethical Clearance',
        'sub'      => $id ? '7 langkah memproses permohonan' : '7 steps to process an application',
        'link'     => BASE_URL . '/admin/ethical_clearance.php',
        'link_lbl' => $id ? 'Buka Halaman' : 'Open Page',
        'steps'    => [
            ['ico'=>$I['bell'],     'title'=>$id?'Terima Notifikasi':'Receive Notification',       'desc'=>$id?'Notifikasi masuk saat dosen/peneliti mengajukan permohonan EC. Buka menu Ethical Clearance.':'Notification arrives when a lecturer/researcher submits an EC application. Open the Ethical Clearance menu.', 'pill'=>$id?'Otomatis':'Auto'],
            ['ico'=>$I['eye'],      'title'=>$id?'Buka & Cek Dokumen':'Open & Check Documents',    'desc'=>$id?'Klik nama pemohon untuk membuka drawer. Cek kelengkapan: Surat Permohonan dan Surat Pernyataan Bermeterai wajib tersedia.':'Click the applicant name to open the drawer. Check completeness: Application Letter and Stamped Declaration Letter must be present.', 'pill'=>$id?'Wajib cek':'Must check'],
            ['ico'=>$I['download'], 'title'=>$id?'Unduh Semua Dokumen':'Download All Documents',   'desc'=>$id?'Unduh dan pelajari semua dokumen yang diunggah (Proposal, Informed Consent, laporan, dst.) sebelum membuat keputusan.':'Download and review all uploaded documents (Proposal, Informed Consent, report, etc.) before making a decision.', 'pill'=>null],
            ['ico'=>$I['users'],    'title'=>$id?'Telaah Tim Etik':'Ethics Committee Review',       'desc'=>$id?'Diskusikan permohonan dengan anggota Tim Etik LPPM. Pastikan penelitian memenuhi standar etika penelitian yang berlaku.':'Discuss the application with LPPM Ethics Committee members. Ensure the research meets applicable research ethics standards.', 'pill'=>$id?'Tim Etik':'Ethics Team'],
            ['ico'=>$I['check'],    'title'=>$id?'Putuskan Status':'Decide Status',                 'desc'=>$id?'Pilih "Disetujui" atau "Ditolak". Jika ditolak, isi catatan alasan agar pemohon bisa melakukan perbaikan.':'Choose "Approved" or "Rejected". If rejected, fill in the reason so the applicant can make corrections.', 'pill'=>null],
            ['ico'=>$I['upload'],   'title'=>$id?'Upload Surat Bertandatangan':'Upload Signed Letter', 'desc'=>$id?'Cetak surat otomatis → tanda tangani & stempel → scan → upload PDF di tombol "Upload Surat TTD" pada drawer detail.':'Print the auto-generated letter → sign & stamp → scan → upload the PDF via the "Upload Signed Letter" button in the detail drawer.', 'pill'=>$id?'Wajib':'Required'],
            ['ico'=>$I['send'],     'title'=>$id?'Pemohon Dapat Mengunduh':'Applicant Can Download', 'desc'=>$id?'Surat yang diupload langsung bisa diunduh pemohon dari Dashboard mereka. Notifikasi & email dikirim otomatis oleh sistem.':'The uploaded letter is immediately downloadable by the applicant from their Dashboard. Notifications & emails are sent automatically.', 'pill'=>$id?'Selesai ✓':'Done ✓'],
        ],
    ],

    // ── 5. KELOLA PENGGUNA ────────────────────────────────────
    [
        'id'       => 'sec-pengguna',
        'hdr_ico'  => 's-amber',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'title'    => $id ? 'Kelola Pengguna' : 'Manage Users',
        'sub'      => $id ? '4 fungsi manajemen akun' : '4 account management functions',
        'link'     => BASE_URL . '/admin/pengguna.php',
        'link_lbl' => $id ? 'Buka Halaman' : 'Open Page',
        'steps'    => [
            ['ico'=>$I['search'],  'title'=>$id?'Cari & Filter Pengguna':'Search & Filter Users',   'desc'=>$id?'Gunakan kotak pencarian untuk mencari berdasarkan nama, NIM, atau NIDN. Filter berdasarkan role (mahasiswa/dosen).':'Use the search box to find users by name, NIM, or NIDN. Filter by role (student/lecturer).', 'pill'=>null],
            ['ico'=>$I['eye'],     'title'=>$id?'Lihat Detail Profil':'View Profile Details',       'desc'=>$id?'Klik tombol Detail untuk melihat seluruh data profil pengguna, riwayat permohonan, dan status akun.':'Click the Detail button to view the user\'s complete profile, application history, and account status.', 'pill'=>null],
            ['ico'=>$I['toggle'],  'title'=>$id?'Aktifkan / Nonaktifkan':'Activate / Deactivate',   'desc'=>$id?'Akun nonaktif tidak bisa login. Gunakan fitur ini jika ada pengguna yang bermasalah atau sudah tidak aktif sebagai civitas.':'Inactive accounts cannot log in. Use this if a user has issues or is no longer part of the institution.', 'pill'=>$id?'Hati-hati':'Caution'],
            ['ico'=>$I['trash'],   'title'=>$id?'Hapus Akun (ke Sampah)':'Delete Account (to Trash)', 'desc'=>$id?'Akun yang dihapus masuk ke menu Sampah dan bisa dipulihkan. Hapus permanen hanya dari menu Sampah. Data permohonan tetap tersimpan.':'Deleted accounts go to the Trash menu and can be restored. Permanent deletion only from the Trash menu. Application data is retained.', 'pill'=>$id?'Reversibel':'Reversible'],
        ],
    ],

    // ── 6. PESAN MASUK ────────────────────────────────────────
    [
        'id'       => 'sec-chat',
        'hdr_ico'  => 's-violet',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'title'    => $id ? 'Pesan Masuk (Chat)' : 'Inbox (Chat)',
        'sub'      => $id ? '3 langkah merespons pesan pengguna' : '3 steps to respond to user messages',
        'link'     => BASE_URL . '/admin/chat.php',
        'link_lbl' => $id ? 'Buka Pesan' : 'Open Inbox',
        'steps'    => [
            ['ico'=>$I['bell'],  'title'=>$id?'Notifikasi Pesan Baru':'New Message Notification',  'desc'=>$id?'Ikon lonceng di topbar + tombol chat mengambang di kanan bawah akan menampilkan jumlah pesan baru yang belum dibaca.':'The bell icon on topbar + floating chat button at bottom right will show the number of unread new messages.', 'pill'=>$id?'Real-time':'Real-time'],
            ['ico'=>$I['chat'],  'title'=>$id?'Baca & Balas Pesan':'Read & Reply Messages',       'desc'=>$id?'Pilih pengguna dari daftar di kiri. Baca pertanyaan/keluhan mereka lalu ketik balasan di kotak pesan. Tekan Enter atau klik Kirim.':'Select a user from the list on the left. Read their questions/complaints then type a reply in the message box. Press Enter or click Send.', 'pill'=>null],
            ['ico'=>$I['check'], 'title'=>$id?'Pesan Otomatis Dibaca':'Messages Auto-Marked Read', 'desc'=>$id?'Pesan otomatis tertandai "dibaca" saat admin membuka percakapan. Badge notifikasi akan hilang. Tidak perlu tindakan tambahan.':'Messages are automatically marked "read" when admin opens the conversation. Notification badge disappears. No further action needed.', 'pill'=>$id?'Otomatis':'Auto'],
        ],
    ],

    // ── 7. LAPORAN, STATISTIK & EKSPOR ───────────────────────
    [
        'id'       => 'sec-laporan',
        'hdr_ico'  => 's-green',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'title'    => $id ? 'Laporan, Statistik & Ekspor' : 'Reports, Statistics & Export',
        'sub'      => $id ? '4 langkah membaca & mengunduh laporan' : '4 steps to read & download reports',
        'link'     => BASE_URL . '/admin/laporan.php',
        'link_lbl' => $id ? 'Buka Laporan' : 'Open Reports',
        'steps'    => [
            ['ico'=>$I['filter'],   'title'=>$id?'Pilih Filter Periode':'Select Period Filter',       'desc'=>$id?'Tentukan rentang tanggal (bulan/tahun) dan jenis permohonan yang ingin dilihat. Klik Tampilkan untuk memuat data.':'Set the date range (month/year) and type of application to view. Click Show to load data.', 'pill'=>null],
            ['ico'=>$I['chart'],    'title'=>$id?'Baca Grafik & Tabel':'Read Charts & Tables',        'desc'=>$id?'Grafik menampilkan tren bulanan permohonan, tingkat keberhasilan, dan distribusi per program studi/fakultas.':'Charts show monthly application trends, success rates, and distribution by study program/faculty.', 'pill'=>$id?'Informasi':'Info'],
            ['ico'=>$I['download'], 'title'=>$id?'Ekspor ke Excel':'Export to Excel',                 'desc'=>$id?'Buka menu Ekspor Data, pilih rentang waktu dan jenis data, lalu klik Ekspor. File Excel/CSV siap diunduh.':'Open the Export Data menu, select the time range and data type, then click Export. Excel/CSV file is ready to download.', 'pill'=>null],
            ['ico'=>$I['archive'],  'title'=>$id?'Arsip Surat':'Letter Archive',                      'desc'=>$id?'Menu Arsip Surat menyimpan semua surat yang pernah diterbitkan. Bisa dicari berdasarkan nama, NIM, atau nomor surat.':'The Letter Archive menu stores all previously issued letters. Searchable by name, NIM, or letter number.', 'pill'=>$id?'Referensi':'Reference'],
        ],
    ],

    // ── 8. KONFIGURASI SISTEM ─────────────────────────────────
    [
        'id'       => 'sec-config',
        'hdr_ico'  => 's-purple',
        'hdr_svg'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'title'    => $id ? 'Konfigurasi & Sistem' : 'Configuration & System',
        'sub'      => $id ? '5 pengaturan penting yang perlu diketahui' : '5 important settings to know',
        'link'     => BASE_URL . '/admin/pengaturan.php',
        'link_lbl' => $id ? 'Buka Pengaturan' : 'Open Settings',
        'steps'    => [
            ['ico'=>$I['settings'], 'title'=>$id?'Atur Batas Similarity & AI':'Set Similarity & AI Limits', 'desc'=>$id?'Di menu Pengaturan Sistem, atur persentase maks. Turnitin Similarity dan AI Usage. Ini menjadi acuan lolos/tolak permohonan.':'In System Settings, set the max Turnitin Similarity and AI Usage percentages. These become the pass/fail thresholds for applications.', 'pill'=>$id?'Kritis':'Critical'],
            ['ico'=>$I['toggle'],   'title'=>$id?'Buka/Tutup Penerimaan':'Open/Close Applications',   'desc'=>$id?'Bisa membuka atau menutup penerimaan permohonan per jenis (Plagiasi, Publikasi, EC) secara independen. Berguna saat masa libur/cuti.':'Can open or close application acceptance per type (Plagiarism, Publication, EC) independently. Useful during holidays/leave.', 'pill'=>$id?'Penting':'Important'],
            ['ico'=>$I['info'],     'title'=>$id?'Info Kontak LPPM':'LPPM Contact Info',              'desc'=>$id?'Atur nama resmi LPPM, alamat email kontak, dan nomor telepon yang tampil di surat-surat yang diterbitkan.':'Set the official LPPM name, contact email, and phone number that appear on issued letters.', 'pill'=>null],
            ['ico'=>$I['log'],      'title'=>$id?'Pantau Log Aktivitas':'Monitor Activity Log',        'desc'=>$id?'Menu Log Aktivitas mencatat semua tindakan admin & pengguna. Penting untuk audit dan pelacakan jika ada masalah.':'The Activity Log menu records all admin & user actions. Important for audits and troubleshooting if issues arise.', 'pill'=>$id?'Audit':'Audit'],
            ['ico'=>$I['trash'],    'title'=>$id?'Kelola Sampah':'Manage Trash',                       'desc'=>$id?'Data yang dihapus tersimpan di menu Sampah selama 30 hari sebelum otomatis terhapus permanen. Bisa dipulihkan kapan saja.':'Deleted data is stored in the Trash menu for 30 days before automatic permanent deletion. Can be restored anytime.', 'pill'=>$id?'Reversibel':'Reversible'],
        ],
    ],
];

// ── Helper: render satu section panduan ──────────────────────
function renderAdminSection(array $sec, array $palette, callable $sz, string $lang): void
{
    $steps    = $sec['steps'];
    $count    = count($steps);
    // Bagi steps ke baris: ≤4 → satu baris; >4 → dua baris setara
    $row_size = $count <= 4 ? $count : (int)ceil($count / 2);
    $rows     = array_chunk($steps, $row_size);
    $arrow    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
    $idx      = 0;
    ?>
    <div class="guide-panel">
      <!-- header (toggle collapse) -->
      <div class="guide-header" onclick="guideToggle('<?= $sec['id'] ?>')">
        <div class="guide-header-left">
          <div class="guide-header-badge">
            <?= $sz($sec['hdr_svg'], 16) ?>
            <span><?= htmlspecialchars($sec['title']) ?></span>
          </div>
          <div class="guide-header-steps-count">
            <strong><?= $count ?></strong>&nbsp;<?= $lang==='id'?'langkah':'steps' ?>
          </div>
        </div>
        <button type="button" class="guide-toggle-btn open" id="<?= $sec['id'] ?>-tbtn"
                onclick="event.stopPropagation();guideToggle('<?= $sec['id'] ?>')">
          <span><?= $lang==='id'?'Tutup':'Collapse' ?></span>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>
      </div>

      <!-- body (starts open) -->
      <div class="guide-body open" id="<?= $sec['id'] ?>-body">
        
        <?php if (!empty($sec['sub'])): ?>
        <div class="guide-desc-banner">
          <div class="guide-desc-icon"><?= $sz($sec['hdr_svg'], 20) ?></div>
          <div class="guide-desc-text"><?= htmlspecialchars($sec['sub']) ?></div>
        </div>
        <?php endif; ?>

        <div class="guide-section-label">
          <?= $lang === 'id' ? 'Alur SOP Admin' : 'Admin SOP Flow' ?>
        </div>

        <div class="guide-flow">
          <?php foreach ($rows as $row_items): ?>
          <div class="guide-flow-row">
            <?php foreach ($row_items as $ri => $step):
              $c = $palette[$idx % count($palette)];
              $idx++;
            ?>
            <?php if ($ri > 0): ?>
            <div class="guide-arrow"><?= $arrow ?></div>
            <?php endif; ?>
            <div class="guide-step-card" style="border-color:<?= $c[2] ?>;background:<?= $c[1] ?>; animation-delay: <?= ($idx * 50) ?>ms;">
              <div class="gsc-top" style="background:<?= $c[0] ?>">
                <div class="gsc-num"><?= $idx ?></div>
                <div class="gsc-ico"><?= $sz($step['ico'], 20) ?></div>
                <?php if ($step['pill']): ?>
                <span class="gsc-pill"><?= htmlspecialchars($step['pill']) ?></span>
                <?php endif; ?>
              </div>
              <div class="gsc-body">
                <div class="guide-step-title"><?= htmlspecialchars($step['title']) ?></div>
                <div class="guide-step-desc-text"><?= htmlspecialchars($step['desc']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <script>
    if (typeof guideToggle !== 'function') {
      function guideToggle(id) {
        var body = document.getElementById(id + '-body');
        var tbtn = document.getElementById(id + '-tbtn');
        if (!body) return;
        var open = body.classList.toggle('open');
        if (tbtn) {
          tbtn.classList.toggle('open', open);
          tbtn.querySelector('span').textContent = open
            ? '<?= $lang==='id'?'Tutup':'Collapse' ?>'
            : '<?= $lang==='id'?'Lihat':'Expand' ?>';
        }
      }
    }
    </script>
    <?php
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Panduan Admin':'Admin Guide' ?> — LPPM IAKN Toraja</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
html { scroll-behavior: smooth; }

/* ── Hero ── */
.panduan-hero {
  background: linear-gradient(135deg, #0d0428 0%, #1a0a3d 55%, #0a1628 100%);
  border-radius: 16px; padding: 32px 28px 28px;
  margin-bottom: 28px; position: relative; overflow: hidden;
}
.panduan-hero::before {
  content: ''; position: absolute; inset: 0; pointer-events: none;
  background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3Ccircle cx='0' cy='0' r='10'/%3E%3Ccircle cx='60' cy='60' r='10'/%3E%3C/g%3E%3C/svg%3E") repeat;
}
.panduan-hero-inner { position: relative; z-index: 1; }
.panduan-hero-top { display: flex; align-items: center; gap: 16px; margin-bottom: 14px; }
.panduan-hero-ico {
  width: 54px; height: 54px; border-radius: 14px; flex-shrink: 0;
  background: rgba(59,130,246,.3); border: 1.5px solid rgba(147,197,253,.4);
  display: flex; align-items: center; justify-content: center;
}
.panduan-hero-ico svg { color: #93c5fd; }
.panduan-hero-title { font-size: 22px; font-weight: 800; color: #fff; line-height: 1.2; }
.panduan-hero-sub { font-size: 13px; color: rgba(147,197,253,.8); margin-top: 3px; }
.panduan-hero-desc { font-size: 13px; color: rgba(255,255,255,.65); line-height: 1.7; max-width: 700px; }

/* ── Quick-nav pills ── */
.panduan-nav {
  display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px;
}
.panduan-nav-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(59,130,246,.18); border: 1px solid rgba(147,197,253,.3);
  border-radius: 20px; padding: 5px 13px;
  font-size: 11px; font-weight: 600; color: #bfdbfe;
  text-decoration: none; cursor: pointer;
  transition: background .18s, border-color .18s, transform .15s;
}
.panduan-nav-pill:hover {
  background: rgba(59,130,246,.38); border-color: rgba(147,197,253,.65);
  transform: translateY(-2px);
}
.panduan-nav-pill:active { transform: translateY(0); }
.panduan-nav-pill svg { flex-shrink: 0; color: #93c5fd; }

/* ── Section header ── */
.panduan-section-hdr {
  display: flex; align-items: center; gap: 14px;
  margin: 28px 0 14px; scroll-margin-top: 72px;
}
.panduan-section-ico {
  width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.panduan-section-ico svg { color: #fff; }
.s-purple { background: linear-gradient(135deg,#4a1d96,#7c3aed); }
.s-blue   { background: linear-gradient(135deg,#1e3a8a,#3b82f6); }
.s-cyan   { background: linear-gradient(135deg,#164e63,#0891b2); }
.s-teal   { background: linear-gradient(135deg,#134e4a,#0d9488); }
.s-amber  { background: linear-gradient(135deg,#78350f,#d97706); }
.s-violet { background: linear-gradient(135deg,#6b21a8,#a855f7); }
.s-green  { background: linear-gradient(135deg,#064e3b,#059669); }
.panduan-section-title { font-size: 16px; font-weight: 700; color: var(--text-primary); }
.panduan-section-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.panduan-section-line { flex: 1; height: 1.5px; background: linear-gradient(90deg, var(--primary-light), transparent); }
.panduan-shortcut {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--primary-xlight); border: 1px solid var(--primary-light);
  border-radius: 8px; padding: 6px 13px;
  font-size: 12px; font-weight: 600; color: var(--primary);
  text-decoration: none; transition: background .15s; flex-shrink: 0;
}
.panduan-shortcut:hover { background: var(--primary-light); }

@media (max-width: 640px) {
  .panduan-hero { padding: 22px 18px 20px; }
  .panduan-hero-title { font-size: 18px; }
  .panduan-section-hdr { flex-wrap: wrap; gap: 10px; }
  .panduan-shortcut { order: 3; }
}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= $lang==='id'?'Panduan Admin':'Admin Guide' ?>
          <span class="breadcrumb">
            <?= $lang==='id'?'SOP & alur kerja admin LPPM':'LPPM admin SOP & workflow' ?>
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
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
            </div>
            <div>
              <div class="panduan-hero-title">
                <?= $lang==='id'?'Panduan Kerja Admin LPPM':'LPPM Admin Work Guide' ?>
              </div>
              <div class="panduan-hero-sub">LPPM IAKN Toraja — SOP Sistem</div>
            </div>
          </div>
          <div class="panduan-hero-desc">
            <?= $lang==='id'
              ? 'Halaman ini menjelaskan tugas dan alur kerja admin untuk setiap fitur dalam sistem LPPM IAKN Toraja. Panduan ini dirancang khusus untuk memudahkan pergantian admin sehingga siapapun yang menjabat dapat langsung memahami alur kerja tanpa perlu pelatihan panjang.'
              : 'This page explains the admin tasks and workflows for each feature in the LPPM IAKN Toraja system. This guide is specifically designed to facilitate admin transitions so that anyone in the role can immediately understand the workflow without lengthy training.'
            ?>
          </div>

          <!-- Quick-nav pills -->
          <div class="panduan-nav">
            <?php
            $nav_items = [
                ['#sec-dashboard', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>', 'Dashboard'],
                ['#sec-plagiasi', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>', $id?'Bebas Plagiasi':'Plagiarism-Free'],
                ['#sec-publikasi', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/></svg>', $id?'Publikasi':'Publication'],
                ['#sec-ec', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>', 'Ethical Clearance'],
                ['#sec-pengguna', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>', $id?'Pengguna':'Users'],
                ['#sec-chat', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>', $id?'Pesan Masuk':'Inbox'],
                ['#sec-laporan', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>', $id?'Laporan':'Reports'],
                ['#sec-config', '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>', $id?'Konfigurasi':'Config'],
            ];
            foreach ($nav_items as [$href, $svg, $label]):
            ?>
            <a href="<?= $href ?>" class="panduan-nav-pill">
              <?= $svg ?>
              <?= htmlspecialchars($label) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           LOOP SEMUA SECTION
      ══════════════════════════════════════════════════════ -->
      <?php foreach ($sections as $sec): ?>
      <div id="<?= $sec['id'] ?>" class="panduan-section-hdr">
        <div class="panduan-section-ico <?= $sec['hdr_ico'] ?>">
          <?= $sec['hdr_svg'] ?>
        </div>
        <div>
          <div class="panduan-section-title"><?= htmlspecialchars($sec['title']) ?></div>
          <div class="panduan-section-sub"><?= htmlspecialchars($sec['sub']) ?></div>
        </div>
        <div class="panduan-section-line"></div>
        <a href="<?= $sec['link'] ?>" class="panduan-shortcut">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
          </svg>
          <?= htmlspecialchars($sec['link_lbl']) ?>
        </a>
      </div>
      <?php renderAdminSection($sec, $palette, $sz, $lang); ?>
      <?php endforeach; ?>

      <!-- ── Tips penting ── -->
      <div style="margin-top:28px;padding:18px 20px;
                  background:linear-gradient(135deg,#fef9c3,#fef08a20);
                  border:1.5px solid #fde047;border-radius:12px;
                  display:flex;gap:14px;align-items:flex-start;">
        <div style="width:36px;height:36px;border-radius:9px;
                    background:linear-gradient(135deg,#78350f,#d97706);
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>
        <div>
          <div style="font-size:13px;font-weight:700;color:#78350f;margin-bottom:5px;">
            <?= $lang==='id'?'Hal-hal Penting untuk Admin Baru':'Key Reminders for New Admins' ?>
          </div>
          <div style="font-size:12px;color:#92400e;line-height:1.75;">
            <?php if ($lang==='id'): ?>
            <strong>1.</strong> Segera ganti password default setelah pertama login. &nbsp;
            <strong>2.</strong> Cek email admin secara rutin — semua notifikasi permohonan masuk ke email. &nbsp;
            <strong>3.</strong> Jangan nonaktifkan akun pengguna tanpa konfirmasi dari pimpinan LPPM. &nbsp;
            <strong>4.</strong> Setiap perubahan pengaturan sistem akan langsung berpengaruh ke seluruh pengguna — pastikan koordinasi dengan Tim LPPM sebelum mengubah batas similarity/AI atau menutup penerimaan.
            <?php else: ?>
            <strong>1.</strong> Change the default password immediately after first login. &nbsp;
            <strong>2.</strong> Check the admin email regularly — all application notifications go to email. &nbsp;
            <strong>3.</strong> Do not deactivate user accounts without confirmation from LPPM leadership. &nbsp;
            <strong>4.</strong> Any system setting changes immediately affect all users — coordinate with the LPPM Team before changing similarity/AI limits or closing applications.
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /wrapper -->
</body>
</html>
