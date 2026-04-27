<?php
// ============================================================
// SURAT KETERANGAN BEBAS PLAGIASI — Generator PDF
// Menggunakan FPDF + kop resmi LPPM IAKN Toraja
// ============================================================
require_once '../../includes/config.php';
requireLogin();
if (isDosen()) { redirect('/dashboard.php'); }

require_once BASE_PATH . '/vendor/autoload.php';

$id    = (int)($_GET['id'] ?? 0);
$nomor = clean($_GET['nomor'] ?? '');

if ($nomor) {
    $stmt = $pdo->prepare("
        SELECT sp.*, cp.similarity_score, cp.ai_score, cp.platform_ai, cp.tanggal_cek,
               s.judul_skripsi, s.nama_pembimbing1, s.nama_pembimbing2, s.tahun_sidang,
               u.nama_lengkap, u.nim, u.program_studi, u.fakultas, s.user_id
        FROM surat_plagiasi sp
        JOIN cek_plagiasi cp ON sp.cek_plagiasi_id = cp.id
        JOIN skripsi s       ON cp.skripsi_id = s.id
        JOIN users u         ON s.user_id = u.id
        WHERE sp.nomor_surat = ?
    ");
    $stmt->execute([$nomor]);
} else {
    $stmt = $pdo->prepare("
        SELECT sp.*, cp.similarity_score, cp.ai_score, cp.platform_ai, cp.tanggal_cek,
               s.judul_skripsi, s.nama_pembimbing1, s.nama_pembimbing2, s.tahun_sidang,
               u.nama_lengkap, u.nim, u.program_studi, u.fakultas, s.user_id
        FROM surat_plagiasi sp
        JOIN cek_plagiasi cp ON sp.cek_plagiasi_id = cp.id
        JOIN skripsi s       ON cp.skripsi_id = s.id
        JOIN users u         ON s.user_id = u.id
        WHERE sp.id = ?
    ");
    $stmt->execute([$id]);
}
$data = $stmt->fetch();

if (!$data)                                                      { http_response_code(404); die('Surat tidak ditemukan.'); }
if (isMahasiswa() && $data['user_id'] !== $_SESSION['user_id']) { http_response_code(403); die('Akses ditolak.'); }

$pdo->prepare("UPDATE surat_plagiasi SET download_count = download_count + 1 WHERE id = ?")->execute([$id]);

// Pengaturan institusi
$nama_inst  = getSetting($pdo, 'nama_institusi');
$nama_lppm  = getSetting($pdo, 'nama_lppm');
$alamat     = getSetting($pdo, 'alamat_institusi');
$nip_ketua  = getSetting($pdo, 'nip_ketua_lppm');
$nama_ketua = getSetting($pdo, 'nama_ketua_lppm');

// Path logo
$logo_path  = BASE_PATH . '/assets/img/logo_lppm.png';
$has_logo   = file_exists($logo_path);

// ============================================================
// CLASS PDF
// ============================================================
class SuratPDF extends FPDF {
    public string $nomorSurat = '';
    public string $namaInst   = '';
    public string $namaLppm   = '';
    public string $alamat     = '';
    public string $logoPath   = '';
    public bool   $hasLogo    = false;

    function Header() {
        // Top margin = 30mm (3cm). Semua elemen kop dimulai dari y=30.
        // Margin: L=40, R=30 → printable width = 140mm, x kanan = 180
        $yTop = 30;

        // ── Logo ──
        if ($this->hasLogo) {
            $this->Image($this->logoPath, 40, $yTop, 20);
        }

        // ── Teks kop ──
        $x_text = $this->hasLogo ? 62 : 40;
        $w_text = $this->hasLogo ? 118 : 140;

        $this->SetXY($x_text, $yTop);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(26, 51, 84); // navy
        $this->MultiCell($w_text, 5, 'KEMENTERIAN AGAMA REPUBLIK INDONESIA', 0, 'C');

        $this->SetX($x_text);
        $this->SetFont('Arial', 'B', 12);
        $this->MultiCell($w_text, 5, strtoupper($this->namaInst), 0, 'C');

        $this->SetX($x_text);
        $this->SetFont('Arial', 'B', 11);
        $this->MultiCell($w_text, 5, strtoupper($this->namaLppm), 0, 'C');

        $this->SetX($x_text);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->MultiCell($w_text, 4, $this->alamat, 0, 'C');

        $this->SetX($x_text);
        $this->MultiCell($w_text, 4, 'Email: lppm@iakn-toraja.ac.id | lp2miaknt@gmail.com | Web: https://lppm.iakn-toraja.ac.id', 0, 'C');

        // ── Garis bawah kop ──
        // Logo tinggi 20mm dari yTop=30 → batas bawah logo = 50mm; tambah 4mm padding = 54mm
        $minGaris = $this->hasLogo ? ($yTop + 24) : ($yTop + 22);
        $this->SetY(max($this->GetY(), $minGaris) + 2);
        $this->SetDrawColor(26, 51, 84);
        $this->SetLineWidth(0.8);
        $this->Line(40, $this->GetY(), 180, $this->GetY());
        $this->SetLineWidth(0.3);
        $this->Line(40, $this->GetY() + 1.2, 180, $this->GetY() + 1.2);
        $this->Ln(4);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        $this->SetY(-30); // 30mm dari bawah = margin bottom 3cm
        $this->SetDrawColor(26, 51, 84);
        $this->SetLineWidth(0.3);
        $this->Line(40, $this->GetY(), 180, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 4, 'Diterbitkan oleh Sistem Informasi LPPM IAKN Toraja  |  Nomor: ' . $this->nomorSurat, 0, 1, 'C');
        $this->Cell(0, 3, 'Dokumen ini sah setelah ditandatangani dan distempel basah oleh Ketua/Sekretaris LPPM', 0, 1, 'C');
    }
}

$pdf = new SuratPDF('P', 'mm', 'A4');
$pdf->nomorSurat = $data['nomor_surat'];
$pdf->namaInst   = $nama_inst;
$pdf->namaLppm   = $nama_lppm;
$pdf->alamat     = $alamat;
$pdf->logoPath   = $logo_path;
$pdf->hasLogo    = $has_logo;

// Margin: L=40mm, T=30mm, R=30mm, B=30mm
$pdf->SetMargins(40, 30, 30);
$pdf->SetAutoPageBreak(true, 30);
$pdf->AddPage();

// ── Jenis Tugas Akhir (safe: column may not exist yet) ────────
$jenis_ta = 'skripsi';
try {
    $qjta = $pdo->prepare(
        "SELECT s.jenis_tugas_akhir FROM skripsi s
         JOIN cek_plagiasi cp ON cp.skripsi_id = s.id
         WHERE cp.id = ? LIMIT 1"
    );
    $qjta->execute([$data['cek_plagiasi_id']]);
    $jta_row = $qjta->fetch();
    if ($jta_row && !empty($jta_row['jenis_tugas_akhir'])) {
        $jenis_ta = $jta_row['jenis_tugas_akhir'];
    }
} catch (PDOException $e) { /* default 'skripsi' */ }

switch ($jenis_ta) {
    case 'tesis':     $ta_label = 'Tesis';     break;
    case 'disertasi': $ta_label = 'Disertasi'; break;
    default:          $ta_label = 'Skripsi';   break;
}
$ta_lower   = strtolower($ta_label);
$ta_penulis = 'Penulis ' . $ta_label;

$batas_sim  = (int)(getSetting($pdo, 'batas_similarity') ?: 20);
$batas_ai   = (int)(getSetting($pdo, 'batas_ai') ?: 30);
$ai_score   = (isset($data['ai_score']) && $data['ai_score'] !== null && $data['ai_score'] !== '')
              ? $data['ai_score'] : null;
$tgl_cek    = formatTanggal($data['tanggal_cek']);
$tgl_surat  = formatTanggal($data['tanggal_surat']);

// Platform AI — default Quillbot AI Detector
$raw_platform = trim($data['platform_ai'] ?? '');
$ai_platform  = $raw_platform !== ''
    ? ucwords(str_replace(['-', '_'], ' ', $raw_platform)) . ' AI Detector'
    : 'Quillbot AI Detector';

// ── Font tubuh surat: Times (FPDF built-in serif, paling mendekati Palatino)
// Untuk Palatino Linotype, tambahkan font custom via FPDF AddFont().
$F = 'Times';

// ── JUDUL SURAT  (12 pt) ─────────────────────────────────────
$pdf->SetFont($F, 'BU', 12);
$pdf->SetTextColor(26, 51, 84);
$pdf->Cell(0, 7, 'SURAT KETERANGAN BEBAS PLAGIASI', 0, 1, 'C');

$pdf->SetFont($F, '', 12);
$pdf->SetTextColor(71, 85, 105);
$pdf->Cell(0, 6, 'Nomor: ' . $data['nomor_surat'], 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetTextColor(0, 0, 0);

// ── PARAGRAF PEMBUKA  (11 pt, tegak, justify) ────────────────
$nama_lembaga = $nama_lppm . ' ' . $nama_inst;
$pdf->SetFont($F, '', 11);
$pdf->MultiCell(0, 6,
    'Setelah melalui proses pengecekan dengan menggunakan aplikasi Turnitin, ' .
    'maka kami ' . $nama_lembaga .
    ' dengan ini menerangkan bahwa ' . $ta_lower . ' yang ditulis oleh:',
    0, 'J');
$pdf->Ln(3);

// ── TABEL DATA  (11 pt) ──────────────────────────────────────
$pdf->SetFont($F, '', 11);
// Printable width = 210 - 40 (L) - 30 (R) = 140mm
$col1   = 45;           // lebar kolom label
$col2   = 5;            // lebar kolom titik dua
$col3   = 90;           // lebar kolom nilai  (45+5+90 = 140)
$xLeft  = 40;           // = left margin
$xValue = $xLeft + $col1 + $col2;   // x awal nilai = 90
$lineH  = 6;

$rows_data = [
    ['Nama Penulis',        $data['nama_lengkap']],
    ['NIM',                 $data['nim']],
    ['Judul ' . $ta_label,  $data['judul_skripsi']],
    ['Tanggal Pemeriksaan', $tgl_cek],
    ['Similarity',          $data['similarity_score'] . '%'],
];
if ($ai_score !== null) {
    $rows_data[] = ['Deteksi AI', $ai_score . '%'];
}

foreach ($rows_data as $row) {
    $nbLines = max(1, (int)ceil($pdf->GetStringWidth($row[1]) / ($col3 - 4)));
    $h = $nbLines * $lineH;
    $y  = $pdf->GetY();

    // Background fill mencakup seluruh tinggi baris (label + colon)
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect($xLeft, $y, $col1 + $col2, $h, 'F');

    // Label: tinggi = lineH agar teks rata-atas dengan baris pertama nilai
    $pdf->SetXY($xLeft, $y);
    $pdf->Cell($col1, $lineH, $row[0], 0, 0, 'L');

    // Titik dua: juga rata-atas
    $pdf->SetXY($xLeft + $col1, $y);
    $pdf->Cell($col2, $lineH, ':', 0, 0, 'C');

    // Nilai: MultiCell mulai dari (xValue, y), left margin = xValue
    $pdf->SetXY($xValue, $y);
    $pdf->SetLeftMargin($xValue);
    $pdf->MultiCell($col3, $lineH, $row[1], 0, 'L');
    $pdf->SetLeftMargin($xLeft);

    // Pindah ke baris berikutnya
    $pdf->SetXY($xLeft, $y + $h);
}
$pdf->Ln(4);

// ── DEKLARASI — "MEMENUHI SYARAT" bold inline  (11 pt) ───────
$pdf->SetFont($F, '', 11);
$pdf->Write(6, 'Dinyatakan ');
$pdf->SetFont($F, 'B', 11);
$pdf->Write(6, 'MEMENUHI SYARAT');
$pdf->SetFont($F, '', 11);
$pdf->Write(6, ' ambang batas toleransi ' . $batas_sim . '% dengan Turnitin');
if ($ai_score !== null) {
    $pdf->Write(6, ', dan ' . $batas_ai . '% menggunakan ' . $ai_platform);
}
$pdf->Write(6, '.');
$pdf->Ln(8);

// ── KLAUSA DISCLAIMER  (11 pt, justify) ──────────────────────
$pdf->SetFont($F, '', 11);
$pdf->MultiCell(0, 6,
    'Jika di kemudian hari ditemukan kekeliruan karena keterbatasan aplikasi, ' .
    'seperti adanya kesamaan dengan karya ilmiah lain yang lebih awal mendapatkan ' .
    'pengakuan sebagai hak cipta: misalnya: karya ilmiah tersebut belum terbit secara ' .
    'online, maka semua konsekuensi yang ditimbulkan menjadi tanggung jawab penulis ' .
    $ta_lower . '.',
    0, 'J');
$pdf->Ln(3);

// ── PENUTUP  (11 pt, justify) ────────────────────────────────
$pdf->MultiCell(0, 6,
    'Demikian surat keterangan ini, untuk dipergunakan sebagaimana mestinya.',
    0, 'J');
$pdf->Ln(8);

// ── TANDA TANGAN DUA KOLOM  (11 pt) ─────────────────────────
// Kiri = Penulis Skripsi/Tesis/Disertasi  |  Kanan = Ketua LPPM
// Printable 140mm: colW=65, gap=10 → 65+10+65 = 140
$colW = 65; $gap = 10;
$pdf->SetFont($F, '', 11);

// Kota + tanggal di sisi kanan
$pdf->Cell($colW + $gap, 6, '', 0);
$pdf->Cell($colW, 6, 'Tana Toraja, ' . $tgl_surat, 0, 1, 'C');

// Label jabatan
$pdf->Cell($colW, 6, $ta_penulis . ',', 0, 0, 'C');
$pdf->Cell($gap,  6, '', 0);
$pdf->Cell($colW, 6, $data['jabatan_penandatangan'] . ',', 0, 1, 'C');

// Ruang tanda tangan & cap
$pdf->Ln(22);

// Nama (bold + underline)
$pdf->SetFont($F, 'BU', 11);
$pdf->Cell($colW, 6, $data['nama_lengkap'], 0, 0, 'C');
$pdf->Cell($gap,  6, '', 0);
$pdf->Cell($colW, 6, $nama_ketua, 0, 1, 'C');

// NIM / NIP
$pdf->SetFont($F, '', 11);
$pdf->Cell($colW, 5, 'NIM. ' . $data['nim'], 0, 0, 'C');
$pdf->Cell($gap,  5, '', 0);
if ($nip_ketua) {
    $pdf->Cell($colW, 5, 'NIP. ' . $nip_ketua, 0, 1, 'C');
} else {
    $pdf->Ln();
}

// ── OUTPUT ───────────────────────────────────────────────────
$namaFile = 'SuratBebasPlagiasi_' .
            preg_replace('/[^a-zA-Z0-9]/', '_', $data['nim']) . '_' .
            date('Ymd', strtotime($data['tanggal_surat'])) . '.pdf';

$pdf->Output('D', $namaFile);
