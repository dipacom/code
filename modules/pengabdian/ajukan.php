<?php
require_once '../../includes/config.php';
requireLogin('mahasiswa');
if (!isDosen()) { redirect('/dashboard.php'); }

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = $_SESSION['user_id'];
$id   = $lang === 'id';

// Pengaturan
$deadline = getSetting($pdo,'pengabdian_deadline') ?: '2026-05-18 16:00:00';
$tahun    = (int)(getSetting($pdo,'pengabdian_tahun') ?: date('Y'));

// Hitung apakah deadline global sudah terlewat (dengan jam & menit)
$deadline_ts = strtotime($deadline);
$now_ts      = time();
$deadline_lewat = $deadline_ts && $deadline_ts < $now_ts;

// Pernyataan poin (editable oleh admin via pengaturan)
$pernyataan_raw  = getSetting($pdo, 'pengabdian_pernyataan_poin');
$pernyataan_poin = [];
if ($pernyataan_raw) {
    $dec = json_decode($pernyataan_raw, true);
    if (is_array($dec) && count($dec)) $pernyataan_poin = $dec;
}
if (empty($pernyataan_poin)) {
    $pernyataan_poin = [
        'Proposal merupakan proposal baru (bukan lanjutan).',
        'Judul sudah terintegrasi dengan bidang keilmuan, pengajaran, dan PkM.',
        'Ketua dan anggota berasal dari homebase dan fakultas yang sama.',
        'Ketua memiliki akun Google Scholar dan SINTA berafiliasi IAKN Toraja.',
        'Ketua dan anggota telah memenuhi kewajiban luaran pengabdian tahun 2023 ke belakang.',
        'Proposal dikirimkan ke lp2miaknt@gmail.com paling lambat 18 Mei 2026 pukul 16.00 WITA.',
        'Saya menyatakan bahwa semua informasi yang saya sampaikan adalah benar dan sesuai ketentuan, serta bersedia mematuhi seluruh aturan pengabdian LPPM IAKN Toraja.',
    ];
}

// Template proposal (untuk diunduh dosen)
$template_raw  = getSetting($pdo, 'pengabdian_template');
$template_list = [];
if ($template_raw) {
    $dec = json_decode($template_raw, true);
    if (is_array($dec)) $template_list = $dec;
}

// Load skema dari DB
$skema_rows = [];
try {
    $sq = $pdo->prepare("SELECT * FROM skema_pengabdian WHERE tahun=? ORDER BY urutan ASC, id ASC");
    $sq->execute([$tahun]);
    $skema_rows = $sq->fetchAll();
} catch (\Exception $e) {}

// Build kode => row map
$skema_map = [];
foreach ($skema_rows as $sk) { $skema_map[$sk['kode']] = $sk; }

// Skema terbuka = is_open=1 DAN (deadline_pengajuan NULL ATAU masih berlaku) DAN deadline global belum lewat
$skemaOpen = function($sk) use ($deadline_lewat, $now_ts) {
    if (!(int)$sk['is_open']) return false;
    if ($deadline_lewat) return false; // global deadline kick-in
    if (!empty($sk['deadline_pengajuan'])) {
        $dl_ts = strtotime($sk['deadline_pengajuan']);
        if ($dl_ts && $dl_ts < $now_ts) return false;
    }
    return true;
};

$ada_buka = false;
foreach ($skema_rows as $sk) { if ($skemaOpen($sk)) { $ada_buka = true; break; } }

if (!$ada_buka) {
    $msg = $deadline_lewat
        ? ($id
            ? 'Batas akhir pengajuan proposal telah terlewat ('.date('d M Y H:i', $deadline_ts).' WITA). Pengajuan ditutup otomatis.'
            : 'Proposal submission deadline has passed ('.date('d M Y H:i', $deadline_ts).' WITA). Submission auto-closed.')
        : ($id ? 'Penerimaan proposal sedang ditutup.' : 'Proposal intake is currently closed.');
    $_SESSION['flash'] = ['type'=>'warning','msg'=>$msg];
    redirect('/modules/pengabdian/index.php');
}

// Profil dosen
$user = $pdo->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$uid]);
$user = $user->fetch();

// Load program studi grouped by fakultas
$prodi_by_fak = [];
try {
    $pq = $pdo->query("
        SELECT p.nama AS prodi, f.nama AS fakultas
        FROM ref_program_studi p
        JOIN ref_fakultas f ON p.fakultas_id = f.id
        WHERE p.is_active = 1
        ORDER BY f.urutan, p.urutan
    ");
    foreach ($pq->fetchAll() as $pr) {
        $prodi_by_fak[$pr['fakultas']][] = $pr['prodi'];
    }
} catch (\Exception $e) {}

// Build grouped <select> options HTML
function prodiOpts(string $sel = ''): string {
    global $prodi_by_fak, $lang;
    $h = '<option value="">' . ($lang === 'id' ? '-- Pilih Prodi --' : '-- Select Program --') . '</option>';
    foreach ($prodi_by_fak as $fak => $list) {
        $h .= '<optgroup label="' . htmlspecialchars($fak) . '">';
        foreach ($list as $p) {
            $h .= '<option value="' . htmlspecialchars($p) . '"' . ($p === $sel ? ' selected' : '') . '>'
                . htmlspecialchars($p) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

$error = '';

// ── Edit mode ─────────────────────────────────────────────────
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit_data = null;
if ($edit_id) {
    $ed = $pdo->prepare("SELECT * FROM usulan_pengabdian WHERE id=? AND user_id=? AND status='draft'");
    $ed->execute([$edit_id, $uid]);
    $edit_data = $ed->fetch();
    if (!$edit_data) { redirect('/modules/pengabdian/index.php'); }
}

// ── Fungsi Helper Pemisahan Logika (Fat Controller Refactoring) ──
function checkDuplikasiTim($pdo, $tahun, $excl_id, $uid, $ketua_lain, $nidn_ketua, $nama_ketua, $anggota_dosen, $anggota_mahasiswa, $id_lang) {
    $norm = fn($v) => preg_replace('/[\s\-\.]/', '', strtolower(trim((string)$v)));
    $current_nidn_ketua = '';
    if ($ketua_lain) {
        $current_nidn_ketua = $norm($nidn_ketua ?? '');
    } else {
        $me_q = $pdo->prepare("SELECT nidn FROM users WHERE id=?");
        $me_q->execute([$uid]);
        $current_nidn_ketua = $norm($me_q->fetchColumn() ?: '');
    }

    $current_nidns = [];
    if ($current_nidn_ketua) $current_nidns[$current_nidn_ketua] = ($ketua_lain ? $nama_ketua : ($_SESSION['nama']??'')) . ' (ketua)';
    foreach ($anggota_dosen as $ad) {
        $k = $norm($ad['nidn'] ?? '');
        if ($k) $current_nidns[$k] = ($ad['nama'] ?? '') . ' (anggota dosen)';
    }

    $current_nims = [];
    foreach ($anggota_mahasiswa as $am) {
        $k = $norm($am['nim'] ?? '');
        if ($k) $current_nims[$k] = ($am['nama'] ?? '') . ' (anggota mahasiswa)';
    }

    $others = $pdo->prepare("
        SELECT up.id, up.judul, up.nama_ketua, up.nidn_ketua, up.user_id,
               up.anggota_dosen, up.anggota_mahasiswa,
               u.nama_lengkap AS user_nama, u.nidn AS user_nidn
        FROM usulan_pengabdian up
        JOIN users u ON u.id = up.user_id
        WHERE up.tahun_anggaran = ?
          AND up.deleted_at IS NULL
          AND up.status NOT IN ('draft','ditolak','gagal_admin')
          AND up.id <> ?
    ");
    $others->execute([$tahun, $excl_id]);

    $konflik = [];
    while ($o = $others->fetch()) {
        $label_prop = ($o['user_nama'] ?? '') . ' — "' . mb_strimwidth($o['judul'] ?? '', 0, 40, '…') . '"';
        $used_nidns = [];
        $k_ketua = $norm($o['nidn_ketua'] ?? '');
        if (!$k_ketua) $k_ketua = $norm($o['user_nidn'] ?? '');
        if ($k_ketua) $used_nidns[$k_ketua] = true;
        $ad_list = json_decode($o['anggota_dosen'] ?? '[]', true) ?: [];
        foreach ($ad_list as $ad) {
            $k = $norm($ad['nidn'] ?? '');
            if ($k) $used_nidns[$k] = true;
        }
        $used_nims = [];
        $am_list = json_decode($o['anggota_mahasiswa'] ?? '[]', true) ?: [];
        foreach ($am_list as $am) {
            $k = $norm($am['nim'] ?? '');
            if ($k) $used_nims[$k] = true;
        }

        foreach ($current_nidns as $nidn_key => $who) {
            if (isset($used_nidns[$nidn_key])) $konflik[] = "$who — " . ($id_lang?'sudah terdaftar di proposal ':'already registered in proposal ') . $label_prop;
        }
        foreach ($current_nims as $nim_key => $who) {
            if (isset($used_nims[$nim_key])) $konflik[] = "$who — " . ($id_lang?'sudah terdaftar di proposal ':'already registered in proposal ') . $label_prop;
        }
    }
    if (!empty($konflik)) {
        return ($id_lang ? 'Dalam satu tahun anggaran, satu dosen/mahasiswa hanya dapat tergabung di satu proposal (sebagai ketua atau anggota). Konflik ditemukan: ' : 'Within a budget year, a lecturer/student can only join ONE proposal (as chair or member). Conflicts: ') . implode('; ', array_slice($konflik, 0, 5)) . (count($konflik) > 5 ? ($id_lang?' — dan lainnya':' — and others') : '');
    }
    return '';
}

function prosesUploadDokumen($file, $allowed_exts, $allowed_mimes, $max_mb, $uid, $prefix, $id_lang) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($ext, $allowed_exts) || !in_array($mime, $allowed_mimes)) return ['err' => $id_lang?"File harus berformat valid (ekstensi & MIME tidak cocok).":"File must be a valid format (extension & MIME mismatch)."];
    if ($file['size'] > $max_mb * 1024 * 1024) return ['err' => $id_lang?"Ukuran file maks. {$max_mb} MB.":"Max file size is {$max_mb} MB."];
    $dir = BASE_PATH . '/uploads/proposal_pengabdian/' . $uid . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = time() . '_' . $prefix . preg_replace('/[^a-zA-Z0-9_.]/', '_', $file['name']);
    if (move_uploaded_file($file['tmp_name'], $dir . $fname)) return ['err' => '', 'path' => 'uploads/proposal_pengabdian/' . $uid . '/' . $fname, 'name' => $file['name'], 'size' => $file['size']];
    return ['err' => $id_lang?'Gagal mengunggah file.':'Failed to upload file.'];
}

// ── POST: handler create_draft_quick (dari pop-up index.php) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_draft_quick') {
    $q_judul  = trim($_POST['judul'] ?? '');
    $q_skema  = clean($_POST['skema'] ?? '');
    $q_tahun  = (int)($_POST['tahun_anggaran'] ?? $tahun);
    if ($q_judul && $q_skema && isset($skema_map[$q_skema]) && $skemaOpen($skema_map[$q_skema])) {
        $pdo->prepare("
            INSERT INTO usulan_pengabdian (user_id, tahun_anggaran, skema, judul, status)
            VALUES (?, ?, ?, ?, 'draft')
        ")->execute([$uid, $q_tahun, $q_skema, $q_judul]);
        $new_id = (int)$pdo->lastInsertId();
        $_SESSION['flash'] = ['type'=>'success','msg'=>$id?'Usulan dibuat sebagai draft. Silakan lengkapi form di bawah.':'Proposal created as draft. Please complete the form below.'];
        redirect('/modules/pengabdian/ajukan.php?edit=' . $new_id);
    }
    $_SESSION['flash'] = ['type'=>'danger','msg'=>$id?'Lengkapi judul, skema, dan tahun.':'Please complete title, scheme, and year.'];
    redirect('/modules/pengabdian/index.php');
}

// ── POST: simpan proposal ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? 'draft'; // 'draft' | 'ajukan'
    $skema   = isset($skema_map[$_POST['skema'] ?? '']) ? $_POST['skema'] : '';
    $judul   = trim($_POST['judul'] ?? '');
    $kesesuaian_bidang  = clean($_POST['kesesuaian_bidang']  ?? '');
    $kontribusi_prodi   = clean($_POST['kontribusi_prodi']   ?? '');
    $kesesuaian_roadmap = clean($_POST['kesesuaian_roadmap'] ?? '');
    $abstrak = trim($_POST['abstrak'] ?? '');
    $ketua_lain          = isset($_POST['ketua_lain']) ? 1 : 0;
    $nama_ketua          = $ketua_lain ? clean($_POST['nama_ketua'] ?? '') : null;
    $nidn_ketua          = $ketua_lain ? clean($_POST['nidn_ketua'] ?? '') : null;
    $jabatan_ketua       = clean($_POST['jabatan_ketua'] ?? '');
    $google_scholar_ketua = clean($_POST['google_scholar_ketua'] ?? '');
    $sinta_id_ketua      = clean($_POST['sinta_id_ketua'] ?? '');
    $sim_mandiri         = trim($_POST['similarity_mandiri'] ?? '');
    $ai_mandiri          = trim($_POST['ai_mandiri'] ?? '');
    $platform_mandiri    = clean($_POST['platform_mandiri'] ?? '');
    $platform_ai_mandiri = clean($_POST['platform_ai_mandiri'] ?? '');
    $checked_poin = $_POST['pernyataan_poin'] ?? [];
    $pernyataan   = (!empty($pernyataan_poin) && count($checked_poin) >= count($pernyataan_poin)) ? 1 : 0;

    // Anggota dosen
    $ad_nama    = $_POST['ad_nama']    ?? [];
    $ad_nidn    = $_POST['ad_nidn']    ?? [];
    $ad_jabatan = $_POST['ad_jabatan'] ?? [];
    $ad_prodi   = $_POST['ad_prodi']   ?? [];
    $anggota_dosen = [];
    for ($i = 0; $i < count($ad_nama); $i++) {
        if (trim($ad_nama[$i] ?? '')) {
            $anggota_dosen[] = [
                'nama'    => clean($ad_nama[$i]),
                'nidn'    => clean($ad_nidn[$i] ?? ''),
                'jabatan' => clean($ad_jabatan[$i] ?? ''),
                'prodi'   => clean($ad_prodi[$i] ?? ''),
                'peran'   => 'anggota',
            ];
        }
    }

    // Anggota mahasiswa
    $am_nama     = $_POST['am_nama']     ?? [];
    $am_nim      = $_POST['am_nim']      ?? [];
    $am_prodi    = $_POST['am_prodi']    ?? [];
    $am_semester = $_POST['am_semester'] ?? [];
    $anggota_mahasiswa = [];
    for ($i = 0; $i < count($am_nama); $i++) {
        if (trim($am_nama[$i] ?? '')) {
            $anggota_mahasiswa[] = [
                'nama'     => clean($am_nama[$i]),
                'nim'      => clean($am_nim[$i] ?? ''),
                'prodi'    => clean($am_prodi[$i] ?? ''),
                'semester' => clean($am_semester[$i] ?? ''),
            ];
        }
    }

    // Anggota mitra bestari (dari luar kampus)
    $mb_nama     = $_POST['mb_nama']     ?? [];
    $mb_nidn     = $_POST['mb_nidn']     ?? [];
    $mb_instansi = $_POST['mb_instansi'] ?? [];
    $anggota_mitra = [];
    for ($i = 0; $i < count($mb_nama); $i++) {
        if (trim($mb_nama[$i] ?? '')) {
            $anggota_mitra[] = [
                'nama'     => clean($mb_nama[$i]),
                'nidn'     => clean($mb_nidn[$i] ?? ''),
                'instansi' => clean($mb_instansi[$i] ?? ''),
            ];
        }
    }

    // Validasi
    if (!$skema)  $error = $id?'Pilih skema pengabdian.':'Please select a research scheme.';
    elseif (!isset($skema_map[$skema])) $error = $id?'Skema tidak valid.':'Invalid scheme selected.';
    elseif (!$judul) $error = $id?'Judul tidak boleh kosong.':'Title is required.';
    elseif ($ketua_lain && !$nama_ketua) $error = $id?'Nama ketua peneliti wajib diisi.':'Research chair name is required.';
    elseif ($action === 'ajukan' && !$skemaOpen($skema_map[$skema])) {
        $error = $id
            ? 'Skema yang dipilih sudah ditutup (deadline terlewati). Pengajuan tidak dapat diproses.'
            : 'The selected scheme is closed (deadline passed). Submission cannot be processed.';
    }
    elseif ($action === 'ajukan') {
        $sk_row   = $skema_map[$skema];
        $jlabel   = ['asisten_ahli'=>'Asisten Ahli','lektor'=>'Lektor','lektor_kepala'=>'Lektor Kepala','guru_besar'=>'Guru Besar'];
        $jrank    = ['asisten_ahli'=>1,'lektor'=>2,'lektor_kepala'=>3,'guru_besar'=>4];

        $n_dosen  = count($anggota_dosen);
        $n_mhs    = count($anggota_mahasiswa);
        $min_d    = (int)$sk_row['min_anggota_dosen'];
        $max_d    = $sk_row['max_anggota_dosen'] !== null ? (int)$sk_row['max_anggota_dosen'] : PHP_INT_MAX;
        $d_wjb    = (bool)$sk_row['anggota_dosen_wajib'];
        $min_m    = (int)$sk_row['min_anggota_mahasiswa'];
        $max_m    = $sk_row['max_anggota_mahasiswa'] !== null ? (int)$sk_row['max_anggota_mahasiswa'] : PHP_INT_MAX;
        $m_wjb    = (bool)$sk_row['anggota_mahasiswa_wajib'];

        if (!$jabatan_ketua)
            $error = $id?'Jabatan fungsional ketua wajib diisi.':'Chair functional rank is required.';
        elseif (!$sinta_id_ketua)
            $error = $id?'SINTA ID ketua wajib diisi sebelum mengajukan proposal.':'SINTA ID of the research chair is required.';
        elseif (!$google_scholar_ketua)
            $error = $id?'Link Google Scholar ketua wajib diisi sebelum mengajukan proposal.':'Google Scholar link of the research chair is required.';
        elseif (!$pernyataan)
            $error = $id?'Harap centang semua poin pernyataan kesanggupan.':'Please check all declaration items.';
        elseif (!$edit_data && empty($_FILES['file_proposal']['name']))
            $error = $id?'File proposal wajib diunggah.':'Proposal file is required.';
        else {
            // Validasi jabatan minimum per skema
            $min_rank  = $jrank[$sk_row['jabatan_min']] ?? 1;
            $user_rank = $jrank[$jabatan_ketua] ?? 0;
            if ($user_rank < $min_rank) {
                $min_lbl = $jlabel[$sk_row['jabatan_min']] ?? $sk_row['jabatan_min'];
                $error = $id
                    ? "Skema {$sk_row['nama']} mensyaratkan jabatan minimal {$min_lbl}."
                    : "Scheme {$sk_row['nama']} requires minimum rank: {$min_lbl}.";
            }
            else {
                // ── Aturan #7: 1 dosen = 1 proposal per tahun anggaran ──────────
                $excl_id = (int)($edit_id ?: 0);
                $dup_err = checkDuplikasiTim($pdo, $tahun, $excl_id, $uid, $ketua_lain, $nidn_ketua, $nama_ketua, $anggota_dosen, $anggota_mahasiswa, $id);
                if ($dup_err) {
                    $error = $dup_err;
                }
            }
            // Validasi jumlah anggota dosen & mahasiswa (jika belum ada error lain)
            if (!$error) {
                if ($d_wjb && $n_dosen < $min_d) {
                    $error = $id
                        ? "Skema ini memerlukan minimal {$min_d} dosen anggota."
                        : "This scheme requires at least {$min_d} additional lecturer(s).";
                } elseif ($n_dosen > $max_d) {
                    $error = $id
                        ? "Skema ini maksimal {$sk_row['max_anggota_dosen']} dosen anggota."
                        : "This scheme allows maximum {$sk_row['max_anggota_dosen']} additional lecturer(s).";
                } elseif ($m_wjb && $n_mhs < $min_m) {
                    $error = $id
                        ? "Skema ini memerlukan minimal {$min_m} mahasiswa dalam tim."
                        : "This scheme requires at least {$min_m} student(s) in the team.";
                } elseif ($n_mhs > $max_m) {
                    $error = $id
                        ? "Skema ini maksimal {$sk_row['max_anggota_mahasiswa']} mahasiswa dalam tim."
                        : "This scheme allows maximum {$sk_row['max_anggota_mahasiswa']} student(s) in the team.";
                }
            }
        }
    }

    // Upload file proposal
    $file_proposal = $edit_data['file_proposal'] ?? null;
    $file_proposal_name = $edit_data['file_proposal_name'] ?? null;
    $file_proposal_size = $edit_data['file_proposal_size'] ?? null;

    if (!$error && !empty($_FILES['file_proposal']['name'])) {
        $res = prosesUploadDokumen($_FILES['file_proposal'], ['doc','docx'], [
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ], 20, $uid, '', $id);

        if ($res['err']) {
            $error = $res['err'];
        } else {
            // Hapus file lama jika ada
            if ($file_proposal && file_exists(BASE_PATH . '/' . $file_proposal)) {
                unlink(BASE_PATH . '/' . $file_proposal);
            }
            $file_proposal      = $res['path'];
            $file_proposal_name = $res['name'];
            $file_proposal_size = $res['size'];
        }
    }

    // Upload file cek mandiri (opsional)
    $file_cek_mandiri = $edit_data['file_cek_mandiri'] ?? null;
    if (!$error && !empty($_FILES['file_cek_mandiri']['name'])) {
        $resc = prosesUploadDokumen($_FILES['file_cek_mandiri'], ['pdf','jpg','jpeg','png'], [
            'application/pdf', 
            'image/jpeg', 
            'image/png'
        ], 10, $uid, 'cek_', $id);

        if ($resc['err']) {
            $error = $resc['err'];
        } else {
            $file_cek_mandiri = $resc['path'];
        }
    }

    if (!$error) {
        $sim_val = ($sim_mandiri !== '') ? (float)str_replace(',', '.',$sim_mandiri) : null;
        $ai_val  = ($ai_mandiri !== '')  ? (float)str_replace(',', '.', $ai_mandiri) : null;
        $status  = ($action === 'ajukan') ? 'diajukan' : 'draft';
        $ad_json = json_encode($anggota_dosen,     JSON_UNESCAPED_UNICODE);
        $am_json = json_encode($anggota_mahasiswa, JSON_UNESCAPED_UNICODE);
        $mb_json = json_encode($anggota_mitra,     JSON_UNESCAPED_UNICODE);

        // Update profil dosen dari jabatan yang diisi (hanya jika pengaju = ketua)
        if (!$ketua_lain) {
            if ($jabatan_ketua)        $pdo->prepare("UPDATE users SET jabatan_fungsional=? WHERE id=?")->execute([$jabatan_ketua, $uid]);
            if ($google_scholar_ketua) $pdo->prepare("UPDATE users SET google_scholar=? WHERE id=?")->execute([$google_scholar_ketua, $uid]);
            if ($sinta_id_ketua)       $pdo->prepare("UPDATE users SET sinta_id=? WHERE id=?")->execute([$sinta_id_ketua, $uid]);
        }

        if ($edit_data) {
            $pdo->prepare("
                UPDATE usulan_pengabdian SET
                  skema=?, judul=?, abstrak=?,
                  anggota_dosen=?, anggota_mahasiswa=?, anggota_mitra=?,
                  nama_ketua=?, nidn_ketua=?,
                  jabatan_ketua=?, google_scholar_ketua=?, sinta_id_ketua=?,
                  file_proposal=?, file_proposal_name=?, file_proposal_size=?,
                  similarity_mandiri=?, ai_mandiri=?, platform_mandiri=?, platform_ai_mandiri=?, file_cek_mandiri=?,
                  kesesuaian_bidang=?, kontribusi_prodi=?, kesesuaian_roadmap=?,
                  pernyataan_disetujui=?, status=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $skema, $judul, $abstrak,
                $ad_json, $am_json, $mb_json,
                $nama_ketua, $nidn_ketua,
                $jabatan_ketua, $google_scholar_ketua, $sinta_id_ketua,
                $file_proposal, $file_proposal_name, $file_proposal_size,
                $sim_val, $ai_val, $platform_mandiri ?: null, $platform_ai_mandiri ?: null, $file_cek_mandiri,
                $kesesuaian_bidang ?: null, $kontribusi_prodi ?: null, $kesesuaian_roadmap ?: null,
                $pernyataan, $status, $edit_id,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO usulan_pengabdian
                  (user_id, tahun_anggaran, skema, judul, abstrak,
                   anggota_dosen, anggota_mahasiswa, anggota_mitra,
                   nama_ketua, nidn_ketua,
                   jabatan_ketua, google_scholar_ketua, sinta_id_ketua,
                   file_proposal, file_proposal_name, file_proposal_size,
                   similarity_mandiri, ai_mandiri, platform_mandiri, platform_ai_mandiri, file_cek_mandiri,
                   kesesuaian_bidang, kontribusi_prodi, kesesuaian_roadmap,
                   pernyataan_disetujui, status)
                VALUES (?,?,?,?,?, ?,?,?, ?,?, ?,?,?, ?,?,?, ?,?,?,?,?, ?,?,?, ?,?)
            ")->execute([
                $uid, $tahun, $skema, $judul, $abstrak,
                $ad_json, $am_json, $mb_json,
                $nama_ketua, $nidn_ketua,
                $jabatan_ketua, $google_scholar_ketua, $sinta_id_ketua,
                $file_proposal, $file_proposal_name, $file_proposal_size,
                $sim_val, $ai_val, $platform_mandiri ?: null, $platform_ai_mandiri ?: null, $file_cek_mandiri,
                $kesesuaian_bidang ?: null, $kontribusi_prodi ?: null, $kesesuaian_roadmap ?: null,
                $pernyataan, $status,
            ]);
        }

        // Notifikasi ke admin jika diajukan
        if ($status === 'diajukan') {
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll();
            foreach ($admins as $adm) {
                $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
                    ->execute([
                        $adm['id'],
                        $id?'Usulan Pengabdian Baru':'New Research Proposal',
                        ($id?'Dosen ':'Lecturer ').$_SESSION['nama'].
                        ($id?' mengajukan proposal pengabdian: ':' submitted a research proposal: ').$judul,
                        'info',
                    ]);
            }
        }

        $flash_msg = $status === 'diajukan'
            ? ($id?'Proposal berhasil diajukan! Admin LPPM akan segera meninjau.':'Proposal submitted! LPPM admin will review shortly.')
            : ($id?'Draft berhasil disimpan.':'Draft saved successfully.');
        $_SESSION['flash'] = ['type'=>'success','msg'=>$flash_msg];
        redirect('/modules/pengabdian/index.php');
    }

    // ── Auto-save draft saat "Ajukan" gagal validasi ──────────
    // Mencegah kehilangan data saat validasi pengajuan gagal
    if ($error && $action === 'ajukan' && $skema) {
        $jd   = $judul ?: ($lang === 'id' ? '(tanpa judul)' : '(untitled)');
        $sv   = ($sim_mandiri !== '') ? (float)str_replace(',', '.', $sim_mandiri) : null;
        $av   = ($ai_mandiri  !== '') ? (float)str_replace(',', '.', $ai_mandiri)  : null;
        $adj  = json_encode($anggota_dosen,     JSON_UNESCAPED_UNICODE);
        $amj  = json_encode($anggota_mahasiswa, JSON_UNESCAPED_UNICODE);
        $mbj  = json_encode($anggota_mitra,     JSON_UNESCAPED_UNICODE);
        try {
            if ($edit_id && $edit_data) {
                $pdo->prepare("
                    UPDATE usulan_pengabdian SET
                      skema=?,judul=?,abstrak=?,
                      anggota_dosen=?,anggota_mahasiswa=?,anggota_mitra=?,
                      nama_ketua=?,nidn_ketua=?,
                      jabatan_ketua=?,google_scholar_ketua=?,sinta_id_ketua=?,
                      similarity_mandiri=?,ai_mandiri=?,platform_mandiri=?,platform_ai_mandiri=?,
                      pernyataan_disetujui=?,status='draft',updated_at=NOW()
                    WHERE id=? AND user_id=?
                ")->execute([
                    $skema,$jd,$abstrak,
                    $adj,$amj,$mbj,
                    $nama_ketua,$nidn_ketua,
                    $jabatan_ketua,$google_scholar_ketua,$sinta_id_ketua,
                    $sv,$av,$platform_mandiri?:null,$platform_ai_mandiri?:null,
                    $pernyataan,
                    $edit_id,$uid,
                ]);
                $saved_id = $edit_id;
            } else {
                $pdo->prepare("
                    INSERT INTO usulan_pengabdian
                      (user_id,tahun_anggaran,skema,judul,abstrak,
                       anggota_dosen,anggota_mahasiswa,anggota_mitra,
                       nama_ketua,nidn_ketua,
                       jabatan_ketua,google_scholar_ketua,sinta_id_ketua,
                       file_proposal,file_proposal_name,file_proposal_size,
                       similarity_mandiri,ai_mandiri,platform_mandiri,platform_ai_mandiri,
                       pernyataan_disetujui,status)
                    VALUES (?,?,?,?,?, ?,?,?, ?,?, ?,?,?, ?,?,?, ?,?,?,?, ?,?)
                ")->execute([
                    $uid,$tahun,$skema,$jd,$abstrak,
                    $adj,$amj,$mbj,
                    $nama_ketua,$nidn_ketua,
                    $jabatan_ketua,$google_scholar_ketua,$sinta_id_ketua,
                    $file_proposal,$file_proposal_name,$file_proposal_size,
                    $sv,$av,$platform_mandiri?:null,$platform_ai_mandiri?:null,
                    $pernyataan,'draft',
                ]);
                $saved_id = (int)$pdo->lastInsertId();
            }
            $_SESSION['ajukan_error']       = $error;
            $_SESSION['ajukan_draft_saved'] = true;
            redirect('/modules/pengabdian/ajukan.php?edit=' . $saved_id);
        } catch (\Exception $e) {
            // auto-save gagal, tampilkan error inline saja
        }
    }
}

// ── Recover error dari redirect auto-save draft ───────────────
$draft_auto_saved = false;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['ajukan_error'])) {
    $error = $_SESSION['ajukan_error'];
    $draft_auto_saved = $_SESSION['ajukan_draft_saved'] ?? false;
    unset($_SESSION['ajukan_error'], $_SESSION['ajukan_draft_saved']);
}

// Prefill dari edit_data
$d = $edit_data ?? [];

// Deteksi skema dari URL param (dosen klik card di halaman index)
$get_skema = null;
if (!$edit_data && isset($_GET['skema'], $skema_map[$_GET['skema']]) && $skema_map[$_GET['skema']]['is_open']) {
    $get_skema = $_GET['skema'];
}
// Skema "dikunci" jika edit mode atau dipilih dari card
$skema_locked = ($edit_data !== null) || ($get_skema !== null);

// Tentukan pre_skema
if ($edit_data) {
    $pre_skema = $d['skema'] ?? '';
} elseif ($get_skema) {
    $pre_skema = $get_skema;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($skema_map[$_POST['skema'] ?? ''])) {
    $pre_skema = $_POST['skema'];  // re-render setelah POST error
} else {
    $pre_skema = '';
    foreach ($skema_rows as $_sk) { if ($_sk['is_open']) { $pre_skema = $_sk['kode']; break; } }
}
$pre_judul      = $d['judul']   ?? '';
$pre_abstrak    = $d['abstrak'] ?? '';
$pre_nama_ketua = $d['nama_ketua'] ?? '';
$pre_nidn_ketua = $d['nidn_ketua'] ?? '';
$pre_ketua_lain = ($pre_nama_ketua !== '');
$pre_jabatan    = $d['jabatan_ketua'] ?? ($user['jabatan_fungsional'] ?? '');
$pre_gs         = $d['google_scholar_ketua'] ?? ($user['google_scholar'] ?? '');
$pre_sinta      = $d['sinta_id_ketua'] ?? ($user['sinta_id'] ?? '');
$pre_sim        = $d['similarity_mandiri']   ?? '';
$pre_ai         = $d['ai_mandiri']           ?? '';
$pre_platform   = $d['platform_mandiri']     ?? '';
$pre_platform_ai = $d['platform_ai_mandiri'] ?? '';
$pre_ad = $d ? json_decode($d['anggota_dosen']     ?? '[]', true) : [];
$pre_am = $d ? json_decode($d['anggota_mahasiswa'] ?? '[]', true) : [];
$pre_mb = $d ? json_decode($d['anggota_mitra']     ?? '[]', true) : [];
if (!is_array($pre_mb)) $pre_mb = [];
if (empty($pre_am)) $pre_am = [['nama'=>'','nim'=>'','prodi'=>'','semester'=>''],['nama'=>'','nim'=>'','prodi'=>'','semester'=>'']];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $edit_data?($id?'Edit Proposal':'Edit Proposal'):($id?'Ajukan Proposal':'Submit Proposal') ?> — LPPM</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
.sec-hdr {
  display:flex; align-items:center; gap:10px;
  padding:13px 16px; background:var(--bg-field);
  border-bottom:1.5px solid var(--border);
  border-radius:10px 10px 0 0;
}
.sec-hdr-ico {
  width:32px; height:32px; border-radius:8px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
}
.sec-hdr-ico svg { color:#fff; }
.sec-hdr-title { font-size:13px; font-weight:700; color:var(--text-primary); }
.sec-hdr-sub   { font-size:11px; color:var(--text-muted); }
.sec-body  { padding:18px 18px 14px; }

/* Skema selector — mini card grid (unlocked) */
.skema-mini-grid {
  display:grid; grid-template-columns:1fr 1fr; gap:8px;
}
@media(max-width:520px) { .skema-mini-grid { grid-template-columns:1fr; } }

.skema-mini-opt { position:relative; }
.skema-mini-opt input[type=radio] {
  position:absolute; opacity:0; width:0; height:0;
}
.skema-mini-lbl {
  display:flex; align-items:center; gap:10px;
  padding:11px 13px; border-radius:10px;
  border:1.5px solid var(--border); background:var(--bg-card);
  cursor:pointer; transition:border-color .14s, background .14s, box-shadow .14s;
  height:100%;
}
.skema-mini-lbl:hover {
  border-color:var(--primary-mid); background:var(--primary-xlight);
}
.skema-mini-opt input[type=radio]:checked + .skema-mini-lbl {
  border-color:var(--primary); background:var(--primary-xlight);
  box-shadow:0 0 0 3px rgba(79,70,229,.1);
}
.skema-mini-opt input[type=radio]:disabled + .skema-mini-lbl {
  opacity:.55; cursor:not-allowed; pointer-events:none;
}
.skema-mini-check {
  width:18px; height:18px; border-radius:50%; flex-shrink:0;
  border:2px solid var(--border); background:#fff;
  display:flex; align-items:center; justify-content:center;
  transition:border-color .14s, background .14s;
}
.skema-mini-opt input[type=radio]:checked + .skema-mini-lbl .skema-mini-check {
  border-color:var(--primary); background:var(--primary);
}
.skema-mini-dot {
  width:7px; height:7px; border-radius:50%; background:#fff;
  display:none;
}
.skema-mini-opt input[type=radio]:checked + .skema-mini-lbl .skema-mini-dot {
  display:block;
}
.skema-mini-name {
  font-size:13px; font-weight:700; color:var(--text-primary); line-height:1.35;
}
.skema-mini-sub {
  font-size:11px; color:var(--text-muted); margin-top:2px;
}
.skema-mini-closed {
  font-size:9.5px; font-weight:700; color:#dc2626;
  background:#fee2e2; border-radius:5px; padding:1px 6px; margin-top:4px;
  display:inline-block;
}

/* Dynamic team rows */
.team-row {
  display:grid; gap:8px; align-items:start;
  padding:10px 12px; background:var(--bg-field);
  border:1px solid var(--border); border-radius:8px; margin-bottom:8px;
}
.team-row.dosen-row  { grid-template-columns:2fr 1.2fr 1.3fr 1.5fr auto; }
.team-row.mhs-row    { grid-template-columns:2fr 1.2fr 1.5fr 0.8fr auto; }
.team-row.mitra-row  { grid-template-columns:2fr 1.2fr 2fr auto; }
@media(max-width:640px) {
  .team-row.dosen-row,
  .team-row.mhs-row,
  .team-row.mitra-row { grid-template-columns:1fr 1fr; }
  .sc-cols-2 { grid-template-columns:1fr !important; }
}
.team-del-btn {
  background:none; border:1px solid #fecaca; border-radius:7px;
  padding:6px 8px; cursor:pointer; color:#dc2626;
  display:flex; align-items:center; justify-content:center;
  margin-top:22px;
}
.team-del-btn:hover { background:#fee2e2; }

/* Upload areas */
.up-area {
  border:1.5px dashed var(--border); border-radius:9px;
  padding:18px; text-align:center; cursor:pointer;
  transition:border-color .15s, background .15s;
  background:var(--bg-field);
}
.up-area:hover { border-color:var(--primary-mid); background:var(--primary-xlight); }
.up-area input { display:none; }
.up-area-ico svg { color:var(--text-muted); }
.up-chosen {
  display:flex; align-items:center; gap:8px; margin-top:8px;
  padding:8px 12px; background:var(--bg-field);
  border:1px solid var(--border); border-radius:8px;
}

/* Warning info box */
.req-box {
  background:#fef9c3; border:1px solid #fde047; border-radius:10px;
  padding:10px 14px; font-size:12px; color:#713f12;
  display:flex; gap:9px; align-items:flex-start; margin-bottom:16px;
}
/* Numeric-only input warning */
.num-warn {
  font-size:11px; color:#dc2626; margin-top:3px; display:none;
}
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('upload') ?>
          <?= $edit_data?($id?'Edit Proposal':'Edit Proposal'):($id?'Ajukan Proposal Pengabdian':'Submit Research Proposal') ?>
          <span class="breadcrumb"><?= $tahun ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/modules/pengabdian/index.php" class="btn btn-outline" style="font-size:12px">
          ← <?= $id?'Kembali':'Back' ?>
        </a>
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $id?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-bottom:<?= $draft_auto_saved?'8px':'16px' ?>">
        <?= ic('alert') ?> <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- ── Banner deadline global (#5) ── -->
      <?php if ($deadline_ts): ?>
      <?php
        $sisa = $deadline_ts - $now_ts;
        $dl_str = date('d M Y · H:i', $deadline_ts) . ' WITA';
        $is_urgent = $sisa > 0 && $sisa < 86400 * 3;
        $is_gone   = $sisa <= 0;
      ?>
      <div style="margin-bottom:16px;padding:12px 16px;border-radius:11px;display:flex;gap:12px;align-items:center;
                  background:<?= $is_gone?'#fef2f2':($is_urgent?'#fef9c3':'#eff6ff') ?>;
                  border:1.5px solid <?= $is_gone?'#fecaca':($is_urgent?'#fde047':'#bfdbfe') ?>">
        <div style="flex-shrink:0"><?= ic('clock','style="width:18px;height:18px;color:'.($is_gone?'#dc2626':($is_urgent?'#a16207':'#1e40af')).'"') ?></div>
        <div style="flex:1;font-size:12.5px;color:<?= $is_gone?'#991b1b':($is_urgent?'#713f12':'#1e3a8a') ?>">
          <div style="font-weight:700"><?= $id?'Batas akhir pengumpulan proposal':'Submission deadline' ?>: <?= $dl_str ?></div>
          <?php if ($is_gone): ?>
            <div><?= $id?'Deadline telah terlewat. Semua skema tertutup otomatis.':'Deadline has passed. All schemes auto-closed.' ?></div>
          <?php elseif ($is_urgent): ?>
            <div><?= $id?'Waktu hampir habis':'Time almost up' ?> — <span id="cd-countdown"></span></div>
          <?php else: ?>
            <div><?= $id?'Sisa waktu':'Remaining' ?>: <span id="cd-countdown"></span></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$is_gone): ?>
      <script>
        (function(){
          const target = <?= $deadline_ts * 1000 ?>;
          const el = document.getElementById('cd-countdown');
          function tick(){
            const d = target - Date.now();
            if (d <= 0) { el.textContent = '<?= $id?"Deadline terlewat":"Deadline passed" ?>'; return; }
            const dd = Math.floor(d / 86400000);
            const hh = Math.floor(d / 3600000) % 24;
            const mm = Math.floor(d / 60000) % 60;
            const ss = Math.floor(d / 1000) % 60;
            el.textContent = (dd>0?dd+' hari ':'') + String(hh).padStart(2,'0') + ':' + String(mm).padStart(2,'0') + ':' + String(ss).padStart(2,'0');
            setTimeout(tick, 1000);
          }
          tick();
        })();
      </script>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($draft_auto_saved): ?>
      <div style="display:flex;align-items:center;gap:9px;background:#f0fdf4;border:1px solid #bbf7d0;
                  color:#166534;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:12.5px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:#16a34a">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
          <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
        </svg>
        <div>
          <strong><?= $lang==='id'?'Data tersimpan sebagai draft.':'Data saved as draft.' ?></strong>
          <?= $lang==='id'
            ? ' Perbaiki kesalahan di atas, lalu klik "Ajukan Proposal" kembali.'
            : ' Fix the error above, then click "Submit Proposal" again.' ?>
        </div>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="formAjukan">

        <?php if (!empty($template_list)): ?>
        <!-- ── Template Proposal ── -->
        <div class="card" style="margin-bottom:16px;border-radius:12px;overflow:hidden;
                    padding:0;border-color:#bfdbfe">
          <div class="sec-hdr" style="background:linear-gradient(135deg,#eff6ff 0%,#dbeafe30 100%)">
            <div class="sec-hdr-ico" style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
              </svg>
            </div>
            <div>
              <div class="sec-hdr-title" style="color:#1e40af">
                <?= $id?'Template Proposal':'Proposal Template' ?>
              </div>
              <div class="sec-hdr-sub">
                <?= $id?'Unduh dan gunakan template sebelum mengisi proposal':'Download and use the template before filling in your proposal' ?>
              </div>
            </div>
          </div>
          <div class="sec-body" style="background:#f0f7ff40">
            <div style="font-size:12px;color:#1e3a8a;margin-bottom:12px">
              <?= $id
                ? 'Silakan unduh template berikut sebagai panduan penyusunan proposal Anda:'
                : 'Download the following template as a guide for preparing your proposal:' ?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
              <?php foreach ($template_list as $tmpl):
                $furl  = BASE_URL . '/' . ltrim($tmpl['file'], '/');
                $ext   = strtolower(pathinfo($tmpl['file'], PATHINFO_EXTENSION));
                $ec    = $ext === 'pdf' ? '#dc2626' : '#1d4ed8';
                $ebg   = $ext === 'pdf' ? '#fee2e2' : '#dbeafe';
              ?>
              <a href="<?= htmlspecialchars($furl) ?>" target="_blank"
                 style="display:inline-flex;align-items:center;gap:8px;
                        background:#fff;border:1.5px solid #bfdbfe;border-radius:9px;
                        padding:9px 14px;text-decoration:none;font-size:12.5px;font-weight:600;
                        color:var(--text-primary)">
                <span style="background:<?= $ebg ?>;color:<?= $ec ?>;
                             font-size:9px;font-weight:800;padding:2px 6px;
                             border-radius:4px;text-transform:uppercase;letter-spacing:.5px">
                  <?= htmlspecialchars(strtoupper($ext)) ?>
                </span>
                <?= htmlspecialchars($tmpl['judul']) ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#3b82f6"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="7 10 12 15 17 10"/>
                  <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════
             BAGIAN 1 — Informasi Proposal
        ══════════════════════════════════════ -->
        <div class="card" style="margin-bottom:18px;border-radius:12px;overflow:hidden;padding:0">
          <div class="sec-hdr">
            <div class="sec-hdr-ico" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
            </div>
            <div>
              <div class="sec-hdr-title">1. <?= $id?'Informasi Proposal':'Proposal Information' ?></div>
              <div class="sec-hdr-sub"><?= $id?'Skema, judul, dan abstrak singkat':'Scheme, title, and brief abstract' ?></div>
            </div>
          </div>
          <div class="sec-body">

            <!-- Skema -->
            <div class="form-group" style="margin-bottom:18px">
              <label class="form-label"><?= $id?'Skema Pengabdian':'Research Scheme' ?> <span class="required">*</span></label>

              <?php if ($skema_locked && isset($skema_map[$pre_skema])): ?>
              <?php /* Locked: hanya tampilkan nama skema, detail disembunyikan */ ?>
              <?php $sk_sel = $skema_map[$pre_skema]; $sk_colors=['#1e3a8a','#14532d','#7c2d12','#4a1d96']; ?>
              <?php $clr_sel = $sk_colors[array_search($pre_skema, array_column($skema_rows,'kode')) % count($sk_colors)]; ?>
              <input type="hidden" name="skema" value="<?= htmlspecialchars($pre_skema) ?>">
              <div style="display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;border:2px solid var(--primary-light);background:var(--primary-xlight)">
                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,<?= $clr_sel ?>,<?= $clr_sel ?>cc);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                    <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                  </svg>
                </div>
                <div>
                  <div style="font-size:13px;font-weight:700;color:var(--primary)"><?= htmlspecialchars($sk_sel['nama']) ?></div>
                  <?php if ($sk_sel['target_publikasi']): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($sk_sel['target_publikasi']) ?></div>
                  <?php endif; ?>
                </div>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <div style="margin-top:6px;font-size:11px;color:var(--text-muted)">
                <?= $id?'Skema dipilih dari halaman sebelumnya. <a href="'.BASE_URL.'/modules/pengabdian/index.php" style="color:var(--primary)">Ganti skema</a>':'Scheme selected from previous page. <a href="'.BASE_URL.'/modules/pengabdian/index.php" style="color:var(--primary)">Change scheme</a>' ?>
              </div>

              <?php else: ?>
              <?php /* Unlocked: mini card grid 2 kolom */ ?>
              <?php
              $first_open = null;
              foreach ($skema_rows as $sk) { if ($skemaOpen($sk) && $first_open === null) $first_open = $sk['kode']; }
              ?>
              <div class="skema-mini-grid">
                <?php foreach ($skema_rows as $sk):
                    $is_open = $skemaOpen($sk);
                    $checked = ($pre_skema === $sk['kode']) || ($pre_skema === '' && $sk['kode'] === $first_open);
                ?>
                <div class="skema-mini-opt">
                  <input type="radio" name="skema" id="sk-<?= htmlspecialchars($sk['kode']) ?>"
                         value="<?= htmlspecialchars($sk['kode']) ?>"
                         <?= ($checked && $is_open) ? 'checked' : '' ?>
                         <?= !$is_open ? 'disabled' : '' ?>>
                  <label for="sk-<?= htmlspecialchars($sk['kode']) ?>" class="skema-mini-lbl">
                    <div class="skema-mini-check">
                      <div class="skema-mini-dot"></div>
                    </div>
                    <div>
                      <div class="skema-mini-name"><?= htmlspecialchars($sk['nama']) ?></div>
                      <?php if ($sk['target_publikasi']): ?>
                      <div class="skema-mini-sub"><?= htmlspecialchars($sk['target_publikasi']) ?></div>
                      <?php endif; ?>
                      <?php if (!$is_open): ?>
                      <span class="skema-mini-closed"><?= $id?'Ditutup':'Closed' ?></span>
                      <?php endif; ?>
                    </div>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>

            <!-- Judul -->
            <div class="form-group" style="margin-bottom:14px">
              <label class="form-label"><?= $id?'Judul Pengabdian':'Research Title' ?> <span class="required">*</span></label>
              <textarea name="judul" class="form-control" rows="2" required
                style="resize:vertical;min-height:56px"
                placeholder="<?= $id?'Tulis judul lengkap sesuai format LPPM':'Write the full title per LPPM format' ?>"><?= htmlspecialchars($pre_judul) ?></textarea>
            </div>

            <!-- Abstrak -->
            <div class="form-group">
              <label class="form-label"><?= $id?'Abstrak Singkat':'Brief Abstract' ?></label>
              <textarea name="abstrak" class="form-control" rows="4" style="resize:vertical"
                placeholder="<?= $id?'Ringkasan latar belakang, tujuan, dan metode pengabdian (opsional)':'Summary of background, objectives, and research method (optional)' ?>"><?= htmlspecialchars($pre_abstrak) ?></textarea>
              <div class="form-hint"><?= $id?'Maks. 500 kata — opsional':'Max. 500 words — optional' ?></div>
            </div>

            <!-- ══════ KOLOM KESESUAIAN PkM (#3) ══════ -->
            <div class="form-group" style="background:#faf5ff;border:1.5px solid #ddd6fe;border-radius:11px;padding:13px 16px;margin-top:6px">
              <div style="font-size:12.5px;font-weight:700;color:#4a1d96;margin-bottom:11px;display:flex;align-items:center;gap:7px">
                <?= ic('check-circle','style="width:14px;height:14px"') ?>
                <?= $id?'Justifikasi Kesesuaian Pengabdian':'PkM Alignment Justification' ?>
              </div>

              <div style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#4a1d96">
                  <?= $id?'1. Kesesuaian dengan Bidang Ilmu':'1. Alignment with Discipline' ?> <span class="required">*</span>
                </label>
                <textarea name="kesesuaian_bidang" class="form-control" rows="3" style="resize:vertical;font-size:12.5px"
                  placeholder="<?= $id?'Jelaskan keterkaitan tema pengabdian dengan bidang ilmu/keahlian Anda':'Explain how the PkM theme relates to your discipline' ?>"><?= htmlspecialchars($d['kesesuaian_bidang'] ?? '') ?></textarea>
              </div>

              <div style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#4a1d96">
                  <?= $id?'2. Kontribusi bagi Pengembangan Program Studi':'2. Contribution to Study Program Development' ?> <span class="required">*</span>
                </label>
                <textarea name="kontribusi_prodi" class="form-control" rows="3" style="resize:vertical;font-size:12.5px"
                  placeholder="<?= $id?'Sebutkan dampak/manfaat pengabdian bagi pengembangan program studi':'State the impact/benefit for study program development' ?>"><?= htmlspecialchars($d['kontribusi_prodi'] ?? '') ?></textarea>
              </div>

              <div style="margin:0">
                <label class="form-label" style="font-size:12px;color:#4a1d96">
                  <?= $id?'3. Kesesuaian dengan Road Map Pengabdian Prodi':'3. Alignment with Program Road Map' ?> <span class="required">*</span>
                </label>
                <textarea name="kesesuaian_roadmap" class="form-control" rows="3" style="resize:vertical;font-size:12.5px"
                  placeholder="<?= $id?'Hubungkan pengabdian ini dengan dokumen road map pengabdian program studi Anda':'Relate this PkM to your study program PkM road map' ?>"><?= htmlspecialchars($d['kesesuaian_roadmap'] ?? '') ?></textarea>
              </div>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════
             BAGIAN 2 — Tim Peneliti
        ══════════════════════════════════════ -->
        <div class="card" style="margin-bottom:18px;border-radius:12px;overflow:hidden;padding:0">
          <div class="sec-hdr">
            <div class="sec-hdr-ico" style="background:linear-gradient(135deg,#4a1d96,#7c3aed)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div>
              <div class="sec-hdr-title">2. <?= $id?'Tim Peneliti':'Research Team' ?></div>
              <div class="sec-hdr-sub" id="timHint">
                <?= $id?'Anda sebagai ketua peneliti':'You as research chair' ?>
              </div>
            </div>
          </div>
          <div class="sec-body">

            <!-- Ketua -->
            <div style="margin-bottom:14px">
              <!-- Info pengaju -->
              <div style="background:var(--primary-xlight);border:1.5px solid var(--primary-light);
                          border-radius:9px;padding:12px 14px;margin-bottom:10px">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                  <div>
                    <div style="font-size:11px;font-weight:700;color:var(--primary);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">
                      <?= ic('user', 'style="width:12px;height:12px"') ?> <?= $id?'Pengaju Proposal':'Proposal Submitter' ?>
                    </div>
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary)">
                      <?= htmlspecialchars($user['nama_lengkap']) ?>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted)">
                      NIDN: <?= htmlspecialchars($user['nidn']??'-') ?> &nbsp;·&nbsp;
                      <?= htmlspecialchars($user['program_studi']??'-') ?>
                    </div>
                  </div>
                  <!-- Toggle: bukan ketua -->
                  <label style="display:flex;align-items:center;gap:7px;cursor:pointer;padding:7px 12px;
                                border-radius:8px;border:1.5px solid var(--border);background:#fff;
                                font-size:12px;font-weight:600;color:var(--text-secondary);white-space:nowrap;flex-shrink:0">
                    <input type="checkbox" name="ketua_lain" id="ketua_lain_cb" value="1"
                           <?= $pre_ketua_lain ? 'checked' : '' ?>
                           onchange="toggleKetuaLain(this.checked)"
                           style="width:15px;height:15px;accent-color:var(--primary);cursor:pointer">
                    <?= $id?'Bukan ketua peneliti':'Not the research chair' ?>
                  </label>
                </div>
              </div>

              <!-- Panel ketua lain (muncul saat dicentang) -->
              <div id="ketua-lain-panel" style="display:<?= $pre_ketua_lain?'block':'none' ?>;
                   background:#fffbeb;border:1.5px solid #fde68a;border-radius:9px;padding:12px 14px;margin-bottom:4px">
                <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:10px;display:flex;align-items:center;gap:5px">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <?= $id?'Isi identitas ketua peneliti yang sebenarnya':'Enter the actual research chair identity' ?>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                  <div class="form-group" style="margin:0;grid-column:1/-1">
                    <label class="form-label"><?= $id?'Nama Ketua Peneliti':'Research Chair Name' ?> <span class="required">*</span></label>
                    <input type="text" name="nama_ketua" id="nama_ketua_inp" class="form-control"
                           value="<?= htmlspecialchars($pre_nama_ketua) ?>"
                           placeholder="<?= $id?'Nama lengkap ketua peneliti':'Full name of research chair' ?>">
                  </div>
                  <div class="form-group" style="margin:0">
                    <label class="form-label">NIDN <?= $id?'Ketua':'Chair' ?></label>
                    <input type="text" name="nidn_ketua" class="form-control nidn-input"
                           value="<?= htmlspecialchars($pre_nidn_ketua) ?>"
                           inputmode="numeric"
                           placeholder="<?= $id?'NIDN ketua peneliti':'Chair NIDN' ?>">
                    <div class="num-warn"><?= $id?'⚠ NIDN hanya boleh berisi angka.':'⚠ NIDN must contain digits only.' ?></div>
                  </div>
                  <div class="form-group" style="margin:0">
                    <label class="form-label"><?= $id?'Program Studi Ketua':'Chair Study Program' ?></label>
                    <select name="prodi_ketua" class="form-control">
                      <?= prodiOpts($pre_nama_ketua ? '' : ($user['prodi'] ?? '')) ?>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Jabatan ketua -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $id?'Jabatan Fungsional Ketua':'Chair Functional Rank' ?> <span class="required">*</span></label>
                <select name="jabatan_ketua" class="form-control" id="jabatan_ketua">
                  <option value="">-- <?= $id?'Pilih Jabatan':'Select Rank' ?> --</option>
                  <option value="asisten_ahli"   <?= $pre_jabatan==='asisten_ahli'  ?'selected':'' ?>>Asisten Ahli (AA)</option>
                  <option value="lektor"         <?= $pre_jabatan==='lektor'        ?'selected':'' ?>>Lektor</option>
                  <option value="lektor_kepala"  <?= $pre_jabatan==='lektor_kepala' ?'selected':'' ?>>Lektor Kepala</option>
                  <option value="guru_besar"     <?= $pre_jabatan==='guru_besar'    ?'selected':'' ?>>Guru Besar / Profesor</option>
                </select>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">SINTA ID Ketua <span class="required">*</span>
                  <span style="font-size:10px;font-weight:400;color:var(--text-muted)">(<?= $id?'wajib untuk pengajuan':'required to submit' ?>)</span>
                </label>
                <input type="text" name="sinta_id_ketua" id="sinta_id_ketua" class="form-control"
                       value="<?= htmlspecialchars($pre_sinta) ?>"
                       placeholder="<?= $id?'Contoh: 12345678':'e.g. 12345678' ?>">
              </div>
              <div class="form-group" style="margin:0;grid-column:1/-1">
                <label class="form-label">Google Scholar Ketua <span class="required">*</span>
                  <span style="font-size:10px;font-weight:400;color:var(--text-muted)">(<?= $id?'wajib untuk pengajuan':'required to submit' ?>)</span>
                </label>
                <input type="url" name="google_scholar_ketua" id="google_scholar_ketua" class="form-control"
                       value="<?= htmlspecialchars($pre_gs) ?>"
                       placeholder="https://scholar.google.com/citations?user=...">
              </div>
            </div>

            <!-- Anggota Dosen -->
            <div style="margin-bottom:16px">
              <div style="font-size:12.5px;font-weight:700;color:var(--text-primary);margin-bottom:8px" id="dosen-label">
                <?= $id?'Anggota Dosen (opsional)':'Lecturer Members (optional)' ?>
              </div>
              <div id="dosen-rows">
              <?php foreach ($pre_ad as $rd): ?>
              <div class="team-row dosen-row">
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Nama Dosen':'Lecturer Name' ?></label>
                  <input type="text" name="ad_nama[]" class="form-control" value="<?= htmlspecialchars($rd['nama']) ?>" placeholder="<?= $id?'Nama lengkap':'Full name' ?>">
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIDN</label>
                  <input type="text" name="ad_nidn[]" class="form-control nidn-input" value="<?= htmlspecialchars($rd['nidn']) ?>" placeholder="NIDN" inputmode="numeric">
                  <div class="num-warn"><?= $id?'⚠ NIDN hanya angka.':'⚠ Digits only.' ?></div>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Jabatan':'Rank' ?></label>
                  <select name="ad_jabatan[]" class="form-control">
                    <option value="">--</option>
                    <option value="asisten_ahli"  <?= ($rd['jabatan']??'')==='asisten_ahli' ?'selected':'' ?>>Asisten Ahli</option>
                    <option value="lektor"        <?= ($rd['jabatan']??'')==='lektor'       ?'selected':'' ?>>Lektor</option>
                    <option value="lektor_kepala" <?= ($rd['jabatan']??'')==='lektor_kepala'?'selected':'' ?>>Lektor Kepala</option>
                    <option value="guru_besar"    <?= ($rd['jabatan']??'')==='guru_besar'   ?'selected':'' ?>>Guru Besar</option>
                  </select>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Program Studi':'Study Program' ?></label>
                  <select name="ad_prodi[]" class="form-control">
                    <?= prodiOpts($rd['prodi'] ?? '') ?>
                  </select>
                </div>
                <button type="button" class="team-del-btn" onclick="this.closest('.team-row').remove()">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <?php endforeach; ?>
              </div>
              <button type="button" onclick="addDosen()" class="btn btn-outline"
                      style="font-size:12px;padding:6px 13px;margin-top:4px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= $id?'Tambah Anggota Dosen':'Add Lecturer Member' ?>
              </button>
            </div>

            <!-- Anggota Mahasiswa -->
            <div>
              <div style="font-size:12.5px;font-weight:700;color:var(--text-primary);margin-bottom:4px" id="mhs-label">
                <?= $id?'Anggota Mahasiswa':'Student Members' ?> <span class="required" id="mhs-required-star">*</span>
              </div>
              <div style="font-size:11.5px;color:var(--text-muted);margin-bottom:8px" id="mhs-hint">
                <?= $id?'Minimal 2 mahasiswa dari homebase/prodi yang sama':'Minimum 2 students from the same homebase/study program' ?>
              </div>
              <div id="mhs-rows">
              <?php foreach ($pre_am as $rm): ?>
              <div class="team-row mhs-row">
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Nama Mahasiswa':'Student Name' ?></label>
                  <input type="text" name="am_nama[]" class="form-control" value="<?= htmlspecialchars($rm['nama']??'') ?>" placeholder="<?= $id?'Nama lengkap':'Full name' ?>">
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIM</label>
                  <input type="text" name="am_nim[]" class="form-control nim-input" value="<?= htmlspecialchars($rm['nim']??'') ?>" placeholder="NIM" inputmode="numeric">
                  <div class="num-warn"><?= $id?'⚠ NIM hanya angka.':'⚠ Digits only.' ?></div>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Program Studi':'Study Program' ?></label>
                  <select name="am_prodi[]" class="form-control">
                    <?= prodiOpts($rm['prodi'] ?? '') ?>
                  </select>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Semester':'Semester' ?></label>
                  <input type="number" name="am_semester[]" class="form-control" min="1" max="14" value="<?= htmlspecialchars($rm['semester']??'') ?>" placeholder="6">
                </div>
                <button type="button" class="team-del-btn" onclick="rmMhs(this)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <?php endforeach; ?>
              </div>
              <button type="button" onclick="addMhs()" class="btn btn-outline"
                      style="font-size:12px;padding:6px 13px;margin-top:4px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= $id?'Tambah Mahasiswa':'Add Student' ?>
              </button>
            </div>

            <!-- Anggota Mitra Bestari -->
            <div style="margin-top:20px;padding-top:16px;border-top:1px dashed var(--border)">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:8px">
                <div>
                  <div style="font-size:12.5px;font-weight:700;color:var(--text-primary)">
                    <?= $id?'Anggota Mitra Bestari / Peneliti Luar':'External / Peer Reviewer Members' ?>
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px">(<?= $id?'opsional':'optional' ?>)</span>
                  </div>
                  <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px">
                    <?= $id
                      ? 'Anggota dari instansi/perguruan tinggi lain di luar IAKN Toraja. NIDN/NIP opsional.'
                      : 'Members from external institutions outside IAKN Toraja. NIDN/NIP is optional.' ?>
                  </div>
                </div>
              </div>
              <div id="mitra-rows">
              <?php foreach ($pre_mb as $rb): ?>
              <div class="team-row mitra-row">
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Nama Lengkap':'Full Name' ?></label>
                  <input type="text" name="mb_nama[]" class="form-control" value="<?= htmlspecialchars($rb['nama']??'') ?>" placeholder="<?= $id?'Nama lengkap':'Full name' ?>">
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIDN / NIP <span style="font-weight:400;color:var(--text-muted)">(<?= $id?'opsional':'optional' ?>)</span></label>
                  <input type="text" name="mb_nidn[]" class="form-control nidn-input" value="<?= htmlspecialchars($rb['nidn']??'') ?>" placeholder="NIDN / NIP" inputmode="numeric">
                  <div class="num-warn"><?= $id?'⚠ NIDN/NIP hanya angka.':'⚠ Digits only.' ?></div>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px"><?= $id?'Instansi / Afiliasi':'Institution / Affiliation' ?></label>
                  <input type="text" name="mb_instansi[]" class="form-control" value="<?= htmlspecialchars($rb['instansi']??'') ?>" placeholder="<?= $id?'Nama perguruan tinggi / lembaga':'University / institution name' ?>">
                </div>
                <button type="button" class="team-del-btn" onclick="this.closest('.team-row').remove()">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
              <?php endforeach; ?>
              </div>
              <button type="button" onclick="addMitra()" class="btn btn-outline"
                      style="font-size:12px;padding:6px 13px;margin-top:4px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= $id?'Tambah Anggota Mitra':'Add External Member' ?>
              </button>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════
             BAGIAN 3 — File Proposal + Self-check
        ══════════════════════════════════════ -->
        <div class="card" style="margin-bottom:18px;border-radius:12px;overflow:hidden;padding:0">
          <div class="sec-hdr">
            <div class="sec-hdr-ico" style="background:linear-gradient(135deg,#134e4a,#0d9488)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
            </div>
            <div>
              <div class="sec-hdr-title">3. <?= $id?'File Proposal & Self-Check':'Proposal File & Self-Check' ?></div>
              <div class="sec-hdr-sub"><?= $id?'Upload PDF proposal + cek mandiri similarity/AI':'Upload PDF proposal + self-check similarity/AI' ?></div>
            </div>
          </div>
          <div class="sec-body">

            <div class="req-box">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
              <span><?= $id
                ? 'Nama file yang dikirimkan: <strong>NamaPeneliti-Proposal Pengabdian 2026.docx</strong>. Batas similarity maks. <strong>25%</strong> dan AI maks. <strong>30%</strong>.'
                : 'File name format: <strong>ResearcherName-Research Proposal 2026.docx</strong>. Max similarity <strong>25%</strong> and AI detection <strong>30%</strong>.'
              ?></span>
            </div>

            <!-- Upload proposal -->
            <div class="form-group" style="margin-bottom:16px">
              <label class="form-label"><?= $id?'File Proposal (DOC/DOCX)':'Proposal File (DOC/DOCX)' ?> <span class="required">*</span></label>
              <div class="up-area" id="upArea" onclick="document.getElementById('fileProposal').click()">
                <input type="file" id="fileProposal" name="file_proposal"
                       accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                       <?= !$edit_data?'required':'' ?> onchange="showProposal(this)">
                <div class="up-area-ico" style="margin-bottom:8px">
                  <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="8" y1="13" x2="16" y2="13"/>
                    <line x1="8" y1="17" x2="16" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                  </svg>
                </div>
                <div id="upText" style="font-size:13px;font-weight:600;color:var(--text-secondary)">
                  <?= $edit_data&&$edit_data['file_proposal_name']
                    ? htmlspecialchars($edit_data['file_proposal_name'])
                    : ($id?'Klik untuk pilih file DOC/DOCX':'Click to select DOC/DOCX file') ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                  <?= $id?'Maksimal 20 MB · Format DOC atau DOCX':'Max 20 MB · DOC or DOCX format' ?>
                </div>
              </div>
              <div id="upPreview" style="display:none" class="up-chosen">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                  <line x1="8" y1="13" x2="16" y2="13"/>
                  <line x1="8" y1="17" x2="16" y2="17"/>
                </svg>
                <div>
                  <div id="upName" style="font-size:13px;font-weight:600"></div>
                  <div id="upSize" style="font-size:11px;color:var(--text-muted)"></div>
                </div>
                <button type="button" onclick="clearProposal()"
                        style="margin-left:auto;background:none;border:none;cursor:pointer;color:#94a3b8">
                  <?= ic('x') ?>
                </button>
              </div>
            </div>

            <!-- Self-check: 2 kolom (Similarity | AI) -->
            <?php
            $sim_platforms = ['Turnitin','iThenticate','PlagScan','Grammarly Plagiarism','Duplichecker','Plagiarism Checker X','Unicheck','Lainnya / Other'];
            $ai_platforms  = ['Turnitin AI Detection','GPTZero','Copyleaks AI Detector','Winston AI','Originality.ai','ZeroGPT','Quillbot AI Detector','Lainnya / Other'];
            ?>
            <div class="sc-cols-2" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">

              <!-- Similarity -->
              <div style="background:var(--bg-field);border:1px solid var(--border);border-radius:9px;padding:12px 14px">
                <div style="font-size:11.5px;font-weight:700;color:var(--text-primary);margin-bottom:10px;
                            display:flex;align-items:center;gap:6px">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <?= $id?'Cek Similarity':'Similarity Check' ?>
                </div>
                <div class="form-group" style="margin-bottom:8px">
                  <label class="form-label" style="font-size:11px"><?= $id?'Skor Similarity (%)':'Similarity Score (%)' ?></label>
                  <input type="number" name="similarity_mandiri" class="form-control"
                         min="0" max="100" step="0.1" id="simInput"
                         value="<?= htmlspecialchars($pre_sim) ?>"
                         placeholder="<?= $id?'Contoh: 18':'e.g. 18' ?>">
                  <div id="simWarn" class="form-hint" style="color:#dc2626;display:none">
                    ⚠ <?= $id?'Melebihi batas':'Exceeds limit' ?>
                  </div>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11px"><?= $id?'Platform':'Platform' ?></label>
                  <select name="platform_mandiri" class="form-control" style="font-size:12px">
                    <option value=""><?= $id?'-- Pilih platform --':'-- Select platform --' ?></option>
                    <?php foreach ($sim_platforms as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"
                            <?= $pre_platform === $p ? 'selected' : '' ?>>
                      <?= htmlspecialchars($p) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <!-- AI Detection -->
              <div style="background:var(--bg-field);border:1px solid var(--border);border-radius:9px;padding:12px 14px">
                <div style="font-size:11.5px;font-weight:700;color:var(--text-primary);margin-bottom:10px;
                            display:flex;align-items:center;gap:6px">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                  <?= $id?'Deteksi AI':'AI Detection' ?>
                </div>
                <div class="form-group" style="margin-bottom:8px">
                  <label class="form-label" style="font-size:11px"><?= $id?'Skor AI (%)':'AI Score (%)' ?></label>
                  <input type="number" name="ai_mandiri" class="form-control"
                         min="0" max="100" step="0.1" id="aiInput"
                         value="<?= htmlspecialchars($pre_ai) ?>"
                         placeholder="<?= $id?'Contoh: 15':'e.g. 15' ?>">
                  <div id="aiWarn" class="form-hint" style="color:#dc2626;display:none">
                    ⚠ <?= $id?'Melebihi batas':'Exceeds limit' ?>
                  </div>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label" style="font-size:11px"><?= $id?'Platform AI':'AI Platform' ?></label>
                  <select name="platform_ai_mandiri" class="form-control" style="font-size:12px">
                    <option value=""><?= $id?'-- Pilih platform --':'-- Select platform --' ?></option>
                    <?php foreach ($ai_platforms as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"
                            <?= $pre_platform_ai === $p ? 'selected' : '' ?>>
                      <?= htmlspecialchars($p) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

            </div>

            <!-- Upload bukti cek -->
            <div class="form-group">
              <label class="form-label"><?= $id?'Bukti Cek Mandiri (opsional)':'Self-Check Evidence (optional)' ?></label>
              <div onclick="document.getElementById('fileCek').click()"
                   style="display:flex;align-items:center;gap:8px;border:1px dashed var(--border);
                          border-radius:8px;padding:8px 12px;cursor:pointer;background:var(--bg-field)">
                <input type="file" id="fileCek" name="file_cek_mandiri"
                       accept=".pdf,.jpg,.jpeg,.png" onchange="showCek(this)"
                       style="display:none">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="17 8 12 3 7 8"/>
                  <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <span id="cekText" style="font-size:12px;color:var(--text-muted)">
                  <?= $edit_data&&$edit_data['file_cek_mandiri']
                    ? '✓ '.basename($edit_data['file_cek_mandiri'])
                    : ($id?'Lampirkan laporan/screenshot (PDF/JPG/PNG)':'Attach report/screenshot (PDF/JPG/PNG)') ?>
                </span>
              </div>
            </div>

          </div>
        </div>

        <!-- ══════════════════════════════════════
             BAGIAN 4 — Pernyataan & Submit
        ══════════════════════════════════════ -->
        <div class="card" style="margin-bottom:20px;border-radius:12px;overflow:hidden;padding:0">
          <div class="sec-hdr">
            <div class="sec-hdr-ico" style="background:linear-gradient(135deg,#78350f,#d97706)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
            </div>
            <div>
              <div class="sec-hdr-title">4. <?= $id?'Pernyataan Kesanggupan':'Declaration' ?></div>
              <div class="sec-hdr-sub"><?= $id?'Baca dan centang setiap poin sebelum mengajukan':'Read and check each point before submitting' ?></div>
            </div>
          </div>
          <div class="sec-body">

            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
              <?= $id
                ? 'Centang setiap poin di bawah untuk menyatakan persetujuan Anda. Semua poin wajib dicentang sebelum proposal dapat diajukan.'
                : 'Check each point below to confirm your agreement. All points must be checked before the proposal can be submitted.' ?>
            </div>

            <div id="pernyataan-list" style="display:flex;flex-direction:column;gap:7px;margin-bottom:16px">
              <?php foreach ($pernyataan_poin as $i => $poin):
                $pre_checked = ($edit_data && $edit_data['pernyataan_disetujui']) ? 'checked' : '';
              ?>
              <label style="display:flex;align-items:flex-start;gap:11px;cursor:pointer;
                            padding:11px 13px;border-radius:9px;
                            border:1.5px solid var(--border);background:var(--bg-field);
                            transition:border-color .15s,background .15s"
                     onclick="this.style.borderColor=''; this.style.background=''">
                <input type="checkbox" name="pernyataan_poin[]" value="<?= $i ?>"
                       <?= $pre_checked ?>
                       onchange="updatePernyataanState(this)"
                       style="margin-top:2px;flex-shrink:0;width:16px;height:16px;
                              accent-color:var(--primary);cursor:pointer">
                <span style="font-size:12.5px;line-height:1.65;color:var(--text-primary)">
                  <strong style="color:var(--primary);margin-right:3px"><?= $i+1 ?>.</strong><?= htmlspecialchars($poin) ?>
                </span>
              </label>
              <?php endforeach; ?>
            </div>

            <!-- Progress bar -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
              <div style="flex:1;background:#e2e8f0;border-radius:6px;height:6px;overflow:hidden">
                <div id="pernyataan-bar" style="height:100%;background:var(--primary);border-radius:6px;
                     width:0%;transition:width .25s ease"></div>
              </div>
              <span id="pernyataan-count" style="font-size:11.5px;font-weight:700;
                    color:var(--text-muted);flex-shrink:0;min-width:40px;text-align:right">
                0 / <?= count($pernyataan_poin) ?>
              </span>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button type="submit" name="action" value="ajukan" class="btn btn-primary btn-lg"
                      onclick="return konfirmAjukan()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="22" y1="2" x2="11" y2="13"/>
                  <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
                <?= $id?'Ajukan Proposal':'Submit Proposal' ?>
              </button>
              <button type="submit" name="action" value="draft" class="btn btn-outline">
                <?= ic('download') ?>
                <?= $id?'Simpan sebagai Draft':'Save as Draft' ?>
              </button>
              <a href="<?= BASE_URL ?>/modules/pengabdian/index.php" class="btn btn-outline">
                ← <?= $id?'Batal':'Cancel' ?>
              </a>
            </div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:10px">
              <?= $id
                ? '💡 Simpan Draft jika ingin melanjutkan nanti. Status draft tidak terkirim ke admin.'
                : '💡 Save as Draft to continue later. Draft status is not sent to admin.' ?>
            </div>

          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
/* ── Prodi options (generated from DB) ── */
const PRODI_OPTS = <?= json_encode(array_map(
    fn($fak, $list) => ['fak' => $fak, 'list' => $list],
    array_keys($prodi_by_fak),
    array_values($prodi_by_fak)
), JSON_UNESCAPED_UNICODE) ?>;

function buildProdiSelect(name, selected = '') {
  const lang = '<?= $lang ?>';
  let html = `<select name="${name}" class="form-control"><option value="">${lang==='id'?'-- Pilih Prodi --':'-- Select Program --'}</option>`;
  PRODI_OPTS.forEach(g => {
    html += `<optgroup label="${g.fak}">`;
    g.list.forEach(p => {
      html += `<option value="${p}"${p===selected?' selected':''}>${p}</option>`;
    });
    html += `</optgroup>`;
  });
  html += `</select>`;
  return html;
}

/* ── Dynamic team rows ── */
function addDosen() {
  const lang = '<?= $lang ?>';
  const row = document.createElement('div');
  row.className = 'team-row dosen-row';
  row.innerHTML = `
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Nama Dosen':'Lecturer Name'}</label>
      <input type="text" name="ad_nama[]" class="form-control" placeholder="${lang==='id'?'Nama lengkap':'Full name'}"></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIDN</label>
      <input type="text" name="ad_nidn[]" class="form-control nidn-input" placeholder="NIDN" inputmode="numeric">
      <div class="num-warn">${lang==='id'?'⚠ NIDN hanya angka.':'⚠ Digits only.'}</div></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Jabatan':'Rank'}</label>
      <select name="ad_jabatan[]" class="form-control">
        <option value="">--</option>
        <option value="asisten_ahli">Asisten Ahli</option>
        <option value="lektor">Lektor</option>
        <option value="lektor_kepala">Lektor Kepala</option>
        <option value="guru_besar">Guru Besar</option>
      </select></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Program Studi':'Study Program'}</label>
      ${buildProdiSelect('ad_prodi[]')}</div>
    <button type="button" class="team-del-btn" onclick="this.closest('.team-row').remove()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>`;
  document.getElementById('dosen-rows').appendChild(row);
}

function addMhs() {
  const lang = '<?= $lang ?>';
  const row = document.createElement('div');
  row.className = 'team-row mhs-row';
  row.innerHTML = `
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Nama Mahasiswa':'Student Name'}</label>
      <input type="text" name="am_nama[]" class="form-control" placeholder="${lang==='id'?'Nama lengkap':'Full name'}"></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIM</label>
      <input type="text" name="am_nim[]" class="form-control nim-input" placeholder="NIM" inputmode="numeric">
      <div class="num-warn">${lang==='id'?'⚠ NIM hanya angka.':'⚠ Digits only.'}</div></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Program Studi':'Study Program'}</label>
      ${buildProdiSelect('am_prodi[]')}</div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Semester':'Semester'}</label>
      <input type="number" name="am_semester[]" class="form-control" min="1" max="14" placeholder="6"></div>
    <button type="button" class="team-del-btn" onclick="rmMhs(this)">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>`;
  document.getElementById('mhs-rows').appendChild(row);
}

function addMitra() {
  const lang = '<?= $lang ?>';
  const row = document.createElement('div');
  row.className = 'team-row mitra-row';
  row.innerHTML = `
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Nama Lengkap':'Full Name'}</label>
      <input type="text" name="mb_nama[]" class="form-control" placeholder="${lang==='id'?'Nama lengkap':'Full name'}"></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">NIDN / NIP <span style="font-weight:400;color:var(--text-muted)">(${lang==='id'?'opsional':'optional'})</span></label>
      <input type="text" name="mb_nidn[]" class="form-control nidn-input" placeholder="NIDN / NIP" inputmode="numeric">
      <div class="num-warn">${lang==='id'?'⚠ NIDN/NIP hanya angka.':'⚠ Digits only.'}</div></div>
    <div><label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">${lang==='id'?'Instansi / Afiliasi':'Institution / Affiliation'}</label>
      <input type="text" name="mb_instansi[]" class="form-control" placeholder="${lang==='id'?'Nama perguruan tinggi / lembaga':'University / institution name'}"></div>
    <button type="button" class="team-del-btn" onclick="this.closest('.team-row').remove()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>`;
  document.getElementById('mitra-rows').appendChild(row);
}

function rmMhs(btn) {
  const rows = document.querySelectorAll('#mhs-rows .team-row');
  if (rows.length <= 2) { alert('<?= $id?"Minimal 2 mahasiswa dalam tim.":"Minimum 2 students required." ?>'); return; }
  btn.closest('.team-row').remove();
}

/* ── File preview ── */
function showProposal(input) {
  const f = input.files[0]; if (!f) return;
  document.getElementById('upName').textContent = f.name;
  document.getElementById('upSize').textContent = (f.size/1048576).toFixed(2)+' MB';
  document.getElementById('upPreview').style.display = 'flex';
  document.getElementById('upText').textContent = '<?= $id?"File terpilih":"File selected" ?>';
}
function clearProposal() {
  document.getElementById('fileProposal').value = '';
  document.getElementById('upPreview').style.display = 'none';
  document.getElementById('upText').textContent = '<?= $id?"Klik untuk pilih file PDF":"Click to select PDF file" ?>';
}
function showCek(input) {
  const f = input.files[0]; if (!f) return;
  document.getElementById('cekText').textContent = '✓ ' + f.name;
}

/* ── Similarity / AI warning ── */
function getActiveSkemaLimits() {
  const kode = document.querySelector('[name="skema"]:checked')?.value;
  const sk   = getSkema(kode);
  return {
    sim: sk ? parseFloat(sk.batas_similarity ?? 25) : 25,
    ai:  sk ? parseFloat(sk.batas_ai         ?? 30) : 30,
  };
}

document.getElementById('simInput').addEventListener('input', function(){
  const lim = getActiveSkemaLimits().sim;
  const w   = document.getElementById('simWarn');
  const v   = parseFloat(this.value);
  w.style.display = (!isNaN(v) && v > lim) ? 'block' : 'none';
  if (w.style.display === 'block')
    w.textContent = `⚠ ${IS_ID?'Melebihi batas':'Exceeds limit'} ${lim}%`;
});
document.getElementById('aiInput').addEventListener('input', function(){
  const lim = getActiveSkemaLimits().ai;
  const w   = document.getElementById('aiWarn');
  const v   = parseFloat(this.value);
  w.style.display = (!isNaN(v) && v > lim) ? 'block' : 'none';
  if (w.style.display === 'block')
    w.textContent = `⚠ ${IS_ID?'Melebihi batas':'Exceeds limit'} ${lim}%`;
});

// Refresh warning saat skema berubah
document.querySelectorAll('[name="skema"]').forEach(r => {
  r.addEventListener('change', function() {
    document.getElementById('simInput').dispatchEvent(new Event('input'));
    document.getElementById('aiInput').dispatchEvent(new Event('input'));
  });
});

// ── Skema data (untuk hint tim dan validasi client-side) ─────
const SKEMA_DATA = <?= json_encode(array_values(array_map(function($sk) {
    return [
        'kode'                    => $sk['kode'],
        'min_anggota_dosen'       => (int)$sk['min_anggota_dosen'],
        'max_anggota_dosen'       => $sk['max_anggota_dosen'] !== null ? (int)$sk['max_anggota_dosen'] : null,
        'anggota_dosen_wajib'     => (bool)$sk['anggota_dosen_wajib'],
        'min_anggota_mahasiswa'   => (int)$sk['min_anggota_mahasiswa'],
        'max_anggota_mahasiswa'   => $sk['max_anggota_mahasiswa'] !== null ? (int)$sk['max_anggota_mahasiswa'] : null,
        'anggota_mahasiswa_wajib' => (bool)$sk['anggota_mahasiswa_wajib'],
        'batas_similarity'        => $sk['batas_similarity'] !== null ? (float)$sk['batas_similarity'] : 25.0,
        'batas_ai'                => $sk['batas_ai']         !== null ? (float)$sk['batas_ai']         : 30.0,
    ];
}, $skema_rows)), JSON_UNESCAPED_UNICODE) ?>;
const IS_ID = <?= $id ? 'true' : 'false' ?>;

function getSkema(kode) {
  return SKEMA_DATA.find(s => s.kode === kode) || null;
}

function updateTimHint() {
  const kode = document.querySelector('[name="skema"]:checked')?.value;
  const hint = document.getElementById('timHint');
  const dosenLbl  = document.getElementById('dosen-label');
  const mhsLbl    = document.getElementById('mhs-label');
  const mhsHint   = document.getElementById('mhs-hint');
  const mhsStar   = document.getElementById('mhs-required-star');
  const sk = getSkema(kode);

  if (!hint) return;

  if (!sk) {
    hint.textContent = IS_ID ? 'Anda sebagai ketua peneliti' : 'You as research chair';
    return;
  }

  const fmtD = sk.max_anggota_dosen !== null
    ? `${sk.min_anggota_dosen}–${sk.max_anggota_dosen}`
    : (sk.min_anggota_dosen > 0 ? `≥${sk.min_anggota_dosen}` : '');
  const fmtM = sk.max_anggota_mahasiswa !== null
    ? `${sk.min_anggota_mahasiswa}–${sk.max_anggota_mahasiswa}`
    : `≥${sk.min_anggota_mahasiswa}`;

  const dWjb = sk.anggota_dosen_wajib;
  const mWjb = sk.anggota_mahasiswa_wajib;

  const dLabel = IS_ID
    ? `Dosen anggota: ${fmtD || '0'} (${dWjb ? 'wajib' : 'opsional'})`
    : `Lecturer members: ${fmtD || '0'} (${dWjb ? 'required' : 'optional'})`;
  const mLabel = IS_ID
    ? `Mahasiswa: ${fmtM} (${mWjb ? 'wajib' : 'opsional'})`
    : `Students: ${fmtM} (${mWjb ? 'required' : 'optional'})`;

  hint.textContent = `${IS_ID?'Anda sebagai ketua':'You as chair'} · ${dLabel} · ${mLabel}`;

  // Update section labels dynamically
  if (dosenLbl) {
    const dText = IS_ID ? 'Anggota Dosen' : 'Lecturer Members';
    const dBadge = dWjb
      ? `<span class="required" style="margin-left:3px">*</span>`
      : ` <span style="font-size:11px;font-weight:400;color:var(--text-muted)">(${IS_ID?'opsional':'optional'})</span>`;
    const dCount = fmtD ? ` <span style="font-size:11px;font-weight:400;color:var(--text-muted)">${fmtD} ${IS_ID?'orang':''}</span>` : '';
    dosenLbl.innerHTML = `${dText}${dCount}${dBadge}`;
  }
  if (mhsLbl && mhsStar && mhsHint) {
    const mText = IS_ID ? 'Anggota Mahasiswa' : 'Student Members';
    const mBadge = mWjb
      ? `<span class="required" id="mhs-required-star" style="margin-left:3px">*</span>`
      : ` <span id="mhs-required-star" style="font-size:11px;font-weight:400;color:var(--text-muted)">(${IS_ID?'opsional':'optional'})</span>`;
    const mCount = `<span style="font-size:11px;font-weight:400;color:var(--text-muted)">${fmtM} ${IS_ID?'orang':''}</span>`;
    mhsLbl.innerHTML = `${mText} ${mCount}${mBadge}`;
    mhsHint.textContent = IS_ID
      ? `Minimal ${sk.min_anggota_mahasiswa} mahasiswa dari homebase/prodi yang sama`
      : `Minimum ${sk.min_anggota_mahasiswa} student(s) from the same homebase/study program`;
  }
}

document.querySelectorAll('[name="skema"]').forEach(r => {
  r.addEventListener('change', updateTimHint);
});
document.addEventListener('DOMContentLoaded', updateTimHint);

/* ── Pernyataan progress bar ── */
function updatePernyataanState(changedCb) {
  const cbs    = document.querySelectorAll('[name="pernyataan_poin[]"]');
  const total  = cbs.length;
  const checked = [...cbs].filter(c => c.checked).length;
  const pct    = total > 0 ? (checked / total * 100) : 0;
  const bar    = document.getElementById('pernyataan-bar');
  const cnt    = document.getElementById('pernyataan-count');
  if (bar) { bar.style.width = pct + '%'; bar.style.background = pct === 100 ? '#16a34a' : 'var(--primary)'; }
  if (cnt) { cnt.textContent = checked + ' / ' + total; cnt.style.color = pct === 100 ? '#16a34a' : 'var(--text-muted)'; }
  // Highlight checked items
  if (changedCb) {
    const lbl = changedCb.closest('label');
    if (lbl) {
      lbl.style.borderColor = changedCb.checked ? 'var(--primary)' : '';
      lbl.style.background  = changedCb.checked ? 'var(--primary-xlight)' : '';
    }
  }
}
document.addEventListener('DOMContentLoaded', function() {
  // Init bar state (for edit mode with pre-checked items)
  document.querySelectorAll('[name="pernyataan_poin[]"]').forEach(function(cb) {
    if (cb.checked) {
      const lbl = cb.closest('label');
      if (lbl) { lbl.style.borderColor = 'var(--primary)'; lbl.style.background = 'var(--primary-xlight)'; }
    }
  });
  updatePernyataanState(null);
});

/* ── Konfirmasi ajukan ── */
function konfirmAjukan() {
  // Validasi SINTA ID dan Google Scholar ketua (wajib saat pengajuan)
  const sintaEl  = document.getElementById('sinta_id_ketua');
  const gsEl     = document.getElementById('google_scholar_ketua');
  if (sintaEl && !sintaEl.value.trim()) {
    sintaEl.style.borderColor = '#ef4444';
    sintaEl.focus();
    sintaEl.scrollIntoView({ behavior:'smooth', block:'center' });
    alert('<?= $id?"SINTA ID ketua wajib diisi sebelum mengajukan proposal.":"SINTA ID of the research chair is required." ?>');
    sintaEl.addEventListener('input', () => sintaEl.style.borderColor = '', { once:true });
    return false;
  }
  if (gsEl && !gsEl.value.trim()) {
    gsEl.style.borderColor = '#ef4444';
    gsEl.focus();
    gsEl.scrollIntoView({ behavior:'smooth', block:'center' });
    alert('<?= $id?"Link Google Scholar ketua wajib diisi sebelum mengajukan proposal.":"Google Scholar link of the research chair is required." ?>');
    gsEl.addEventListener('input', () => gsEl.style.borderColor = '', { once:true });
    return false;
  }

  const poinCbs   = document.querySelectorAll('[name="pernyataan_poin[]"]');
  const allChecked = poinCbs.length > 0 && [...poinCbs].every(cb => cb.checked);
  if (!allChecked) {
    alert('<?= $id?"Harap centang semua poin pernyataan kesanggupan terlebih dahulu.":"Please check all declaration items first." ?>');
    return false;
  }
  const kode = document.querySelector('[name="skema"]:checked')?.value;
  const sk   = getSkema(kode);

  const mhs = document.querySelectorAll('#mhs-rows .team-row');
  let validMhs = 0;
  mhs.forEach(r => { if (r.querySelector('[name="am_nama[]"]').value.trim()) validMhs++; });

  const dosen = document.querySelectorAll('#dosen-rows .team-row');
  let validDosen = 0;
  dosen.forEach(r => { if (r.querySelector('[name="ad_nama[]"]').value.trim()) validDosen++; });

  if (sk) {
    if (sk.anggota_mahasiswa_wajib && validMhs < sk.min_anggota_mahasiswa) {
      alert(IS_ID
        ? `Minimal ${sk.min_anggota_mahasiswa} mahasiswa harus diisi.`
        : `At least ${sk.min_anggota_mahasiswa} student(s) must be filled.`);
      return false;
    }
    if (sk.anggota_dosen_wajib && validDosen < sk.min_anggota_dosen) {
      alert(IS_ID
        ? `Minimal ${sk.min_anggota_dosen} dosen anggota harus diisi.`
        : `At least ${sk.min_anggota_dosen} lecturer member(s) must be filled.`);
      return false;
    }
  } else if (validMhs < 2) {
    alert('<?= $id?"Minimal 2 mahasiswa harus diisi.":"At least 2 students must be filled." ?>');
    return false;
  }

  return confirm('<?= $id?
    "Setelah diajukan, proposal tidak dapat diedit. Lanjutkan?":
    "Once submitted, the proposal cannot be edited. Continue?" ?>');
}

function toggleKetuaLain(checked) {
  const panel = document.getElementById('ketua-lain-panel');
  const inp   = document.getElementById('nama_ketua_inp');
  panel.style.display = checked ? 'block' : 'none';
  if (checked) {
    inp.setAttribute('required', 'required');
    setTimeout(() => inp.focus(), 80);
  } else {
    inp.removeAttribute('required');
    inp.value = '';
    document.querySelector('[name=nidn_ketua]').value = '';
    const prodSel = document.querySelector('[name=prodi_ketua]');
    if (prodSel) prodSel.value = '';
  }
}

/* ── Numeric-only validation for NIDN / NIM ── */
function applyNumericValidation(input) {
  const warn = input.nextElementSibling;
  const cleaned = input.value.replace(/[^0-9]/g, '');
  if (cleaned !== input.value) {
    input.value = cleaned;
    if (warn && warn.classList.contains('num-warn')) {
      warn.style.display = 'block';
      setTimeout(() => { warn.style.display = 'none'; }, 2500);
    }
  }
}

// Event delegation: catch input on nidn-input and nim-input anywhere in form
document.getElementById('formAjukan').addEventListener('input', function(e) {
  if (e.target.classList.contains('nidn-input') || e.target.classList.contains('nim-input')) {
    applyNumericValidation(e.target);
  }
});

/* ── Cek duplikasi anggota realtime (#7) ───────────────────────────
   Saat NIDN/NIM kehilangan fokus, panggil endpoint cek_anggota.php
   dan tampilkan warning jika nilai sudah dipakai di proposal lain
   tahun anggaran berjalan. */
(function(){
  const EXCL = <?= (int)($edit_id ?: 0) ?>;
  const BASE = <?= json_encode(BASE_URL . '/modules/pengabdian/cek_anggota.php') ?>;
  const CACHE = {};

  function cekWarn(input, tipe) {
    const val = (input.value || '').trim();
    // Hapus warning lama
    const parent = input.parentNode;
    let warn = parent.querySelector('.dup-warn');
    if (!val) { if (warn) warn.remove(); return; }

    const cacheKey = tipe + '|' + val;
    if (CACHE[cacheKey]) { renderWarn(input, CACHE[cacheKey]); return; }

    fetch(BASE + '?tipe=' + tipe + '&value=' + encodeURIComponent(val) + '&excl_id=' + EXCL)
      .then(r => r.json())
      .then(j => {
        CACHE[cacheKey] = j.konflik || [];
        renderWarn(input, CACHE[cacheKey]);
      })
      .catch(() => { /* silent */ });
  }

  function renderWarn(input, konflik) {
    const parent = input.parentNode;
    let warn = parent.querySelector('.dup-warn');
    if (!konflik.length) { if (warn) warn.remove(); input.style.borderColor = ''; return; }
    if (!warn) {
      warn = document.createElement('div');
      warn.className = 'dup-warn';
      warn.style.cssText = 'margin-top:4px;padding:6px 9px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;font-size:11.5px';
      parent.appendChild(warn);
    }
    input.style.borderColor = '#f87171';
    const lines = konflik.slice(0, 3).map(k =>
      '⚠ ' + (k.pengusul || '—') + ' (proposal: “' + (k.judul || '—') + '”)'
    ).join('<br>');
    const more = konflik.length > 3 ? '<br><i>+ ' + (konflik.length - 3) + ' lainnya</i>' : '';
    warn.innerHTML = '<b>Sudah terpakai di proposal lain tahun ini:</b><br>' + lines + more;
  }

  document.getElementById('formAjukan').addEventListener('blur', function(e) {
    const t = e.target;
    if (!t) return;
    if (t.name === 'nidn_ketua' || t.name === 'ad_nidn[]') cekWarn(t, 'nidn');
    else if (t.name === 'am_nim[]') cekWarn(t, 'nim');
  }, true);
})();
</script>
</body>
</html>
