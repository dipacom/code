<?php
/**
 * admin/unduh_batch.php
 * Batch download surat → ZIP berisi beberapa PDF sekaligus.
 * POST params: ids[] (array int), tipe (plagiasi|publikasi)
 */
require_once '../includes/config.php';
requireLogin('admin');
require_once BASE_PATH . '/includes/pdf_surat.php';

$tipe = clean($_POST['tipe'] ?? '');
$ids  = array_map('intval', (array)($_POST['ids'] ?? []));
$ids  = array_filter($ids);

if (empty($ids) || !in_array($tipe, ['plagiasi', 'publikasi'])) {
    http_response_code(400);
    die('Parameter tidak valid. Pilih minimal satu surat.');
}
if (count($ids) > 50) {
    http_response_code(400);
    die('Maksimal 50 surat per batch download.');
}

// Ambil pengaturan institusi (sekali saja)
$nama_inst  = getSetting($pdo, 'nama_institusi')  ?? 'IAKN Toraja';
$nama_lppm  = getSetting($pdo, 'nama_lppm')        ?? 'LPPM IAKN Toraja';
$alamat     = getSetting($pdo, 'alamat_institusi') ?? '';
$nip_ketua  = getSetting($pdo, 'nip_ketua_lppm')   ?? '';
$nama_ketua = getSetting($pdo, 'nama_ketua_lppm')  ?? '';
$logo_path  = BASE_PATH . '/assets/img/logo_lppm.png';
$batas_sim  = (int)(getSetting($pdo, 'batas_similarity') ?: 20);
$batas_ai   = (int)(getSetting($pdo, 'batas_ai') ?: 20);

// Buat ZIP di memori
$zip_file = tempnam(sys_get_temp_dir(), 'surat_batch_');
$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Gagal membuat file ZIP.');
}

$count    = 0;
$skipped  = 0;

foreach ($ids as $id) {
    if ($tipe === 'plagiasi') {
        $stmt = $pdo->prepare("
            SELECT sp.*, cp.similarity_score, cp.ai_score, cp.platform_ai, cp.tanggal_cek,
                   s.judul_skripsi, s.nama_pembimbing1, s.nama_pembimbing2, s.tahun_sidang,
                   u.nama_lengkap, u.nim, u.fakultas, u.program_studi
            FROM surat_plagiasi sp
            JOIN cek_plagiasi cp ON sp.cek_plagiasi_id = cp.id
            JOIN skripsi s       ON cp.skripsi_id = s.id
            JOIN users u         ON s.user_id = u.id
            WHERE sp.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) { $skipped++; continue; }

        $pdf_str  = generatePdf_Plagiasi($data, $nama_inst, $nama_lppm, $alamat, $nip_ketua, $logo_path, $nama_ketua, $batas_sim, $batas_ai);
        $filename = 'SuratBebasPlagiasi_' .
                    preg_replace('/[^a-zA-Z0-9]/', '_', $data['nim']) . '_' .
                    date('Ymd', strtotime($data['tanggal_surat'])) . '.pdf';

        // Update download_count
        $pdo->prepare("UPDATE surat_plagiasi SET download_count = download_count + 1 WHERE id=?")->execute([$id]);

    } else {
        $stmt = $pdo->prepare("
            SELECT sp.*, p.judul_publikasi, p.jenis_publikasi, p.nama_jurnal_penerbit,
                   p.tahun_terbit, p.url_doi, p.issn_isbn, p.akreditasi_jurnal,
                   u.nama_lengkap, u.nim, u.fakultas, u.program_studi
            FROM surat_publikasi sp
            JOIN publikasi p ON sp.publikasi_id = p.id
            JOIN users u     ON p.user_id = u.id
            WHERE sp.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        if (!$data) { $skipped++; continue; }

        $pdf_str  = generatePdf_Publikasi($data, $nama_inst, $nama_lppm, $alamat, $nip_ketua, $logo_path, $nama_ketua);
        $filename = 'SuratPublikasi_' .
                    preg_replace('/[^a-zA-Z0-9]/', '_', $data['nim']) . '_' .
                    date('Ymd', strtotime($data['tanggal_surat'])) . '.pdf';

        $pdo->prepare("UPDATE surat_publikasi SET download_count = download_count + 1 WHERE id=?")->execute([$id]);
    }

    // Jika nama file duplikat, tambahkan suffix
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = '.pdf';
    $n    = 1;
    $final_name = $filename;
    while ($zip->locateName($final_name) !== false) {
        $final_name = $base . '_' . (++$n) . $ext;
    }

    $zip->addFromString($final_name, $pdf_str);
    $count++;
}

$zip->close();

if ($count === 0) {
    unlink($zip_file);
    http_response_code(404);
    die('Tidak ada surat yang berhasil dibuat.');
}

$label    = $tipe === 'plagiasi' ? 'BebasPlagiasi' : 'Publikasi';
$zip_name = 'BatchSurat_' . $label . '_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_name . '"');
header('Content-Length: ' . filesize($zip_file));
header('Pragma: no-cache');

readfile($zip_file);
unlink($zip_file);
exit;
