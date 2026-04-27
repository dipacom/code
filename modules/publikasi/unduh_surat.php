<?php
// ============================================================
// SURAT KETERANGAN PUBLIKASI — Generator PDF (1 halaman, Times New Roman)
// ============================================================
require_once '../../includes/config.php';
requireLogin();
if (isDosen()) { redirect('/dashboard.php'); }

require_once BASE_PATH . '/vendor/autoload.php';

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT sp.*, p.judul_publikasi, p.jenis_publikasi, p.nama_jurnal_penerbit,
           p.tahun_terbit, p.url_doi, p.issn_isbn, p.akreditasi_jurnal, p.user_id,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas
    FROM surat_publikasi sp
    JOIN publikasi p ON sp.publikasi_id = p.id
    JOIN users u     ON p.user_id = u.id
    WHERE sp.id = ?
");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data)                                                      { http_response_code(404); die('Surat tidak ditemukan.'); }
if (isMahasiswa() && $data['user_id'] !== $_SESSION['user_id']) { http_response_code(403); die('Akses ditolak.'); }

$pdo->prepare("UPDATE surat_publikasi SET download_count = download_count + 1 WHERE id = ?")->execute([$id]);

$nama_inst  = getSetting($pdo, 'nama_institusi');
$nama_lppm  = getSetting($pdo, 'nama_lppm');
$alamat     = getSetting($pdo, 'alamat_institusi');
$nip_ketua  = getSetting($pdo, 'nip_ketua_lppm');
$nama_ketua = getSetting($pdo, 'nama_ketua_lppm');
$logo_path  = BASE_PATH . '/assets/img/logo_lppm.png';
$has_logo   = file_exists($logo_path);

// ── CLASS PDF ────────────────────────────────────────────────
// Kop kompak, font Times, margin lebih kecil agar muat 1 halaman
class PubPDF extends FPDF {
    public string $nomorSurat = '';
    public string $namaInst   = '';
    public string $namaLppm   = '';
    public string $alamat     = '';
    public string $logoPath   = '';
    public bool   $hasLogo    = false;

    function Header() {
        // Kop kompak: mulai y=12, logo 16mm
        $yTop = 12;
        $logoSize = 16;

        if ($this->hasLogo) $this->Image($this->logoPath, 25, $yTop, $logoSize);
        $x = $this->hasLogo ? ($logoSize + 28) : 25;
        $w = 210 - $x - 25; // 25mm margin kanan

        $this->SetXY($x, $yTop);
        $this->SetFont('Times', 'B', 11);
        $this->SetTextColor(26, 51, 84);
        $this->Cell($w, 4.6, 'KEMENTERIAN AGAMA REPUBLIK INDONESIA', 0, 2, 'C');
        $this->Cell($w, 4.6, strtoupper($this->namaInst), 0, 2, 'C');
        $this->Cell($w, 4.6, strtoupper($this->namaLppm), 0, 2, 'C');
        $this->SetFont('Times', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->Cell($w, 3.6, $this->alamat, 0, 2, 'C');
        $this->Cell($w, 3.6, 'Email: lppm@iakn-toraja.ac.id  |  Web: https://lppm.iakn-toraja.ac.id', 0, 2, 'C');

        // Garis ganda di bawah kop
        $yLine = max($this->GetY() + 1, $yTop + $logoSize + 2);
        $this->SetY($yLine);
        $this->SetDrawColor(26, 51, 84);
        $this->SetLineWidth(0.7);
        $this->Line(25, $this->GetY(), 185, $this->GetY());
        $this->SetLineWidth(0.25);
        $this->Line(25, $this->GetY() + 1, 185, $this->GetY() + 1);
        $this->Ln(3.5);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        // Footer kompak
        $this->SetY(-14);
        $this->SetFont('Times', 'I', 7);
        $this->SetTextColor(120, 130, 145);
        $this->Cell(0, 3.5, 'Diterbitkan oleh Sistem Informasi LPPM IAKN Toraja  |  Nomor: ' . $this->nomorSurat, 0, 1, 'C');
        $this->Cell(0, 3.5, 'Dokumen ini sah setelah ditandatangani dan distempel basah oleh Ketua/Sekretaris LPPM', 0, 1, 'C');
    }
}

$pdf = new PubPDF('P', 'mm', 'A4');
$pdf->nomorSurat = $data['nomor_surat'];
$pdf->namaInst   = $nama_inst;
$pdf->namaLppm   = $nama_lppm;
$pdf->alamat     = $alamat;
$pdf->logoPath   = $logo_path;
$pdf->hasLogo    = $has_logo;

// Margin: L=25mm, T=12mm, R=25mm, Auto break B=15mm
$pdf->SetMargins(25, 12, 25);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// ── JUDUL ────────────────────────────────────────────────────
$pdf->SetFont('Times', 'BU', 13);
$pdf->SetTextColor(26, 51, 84);
$pdf->Cell(0, 6, 'SURAT KETERANGAN PUBLIKASI', 0, 1, 'C');
$pdf->SetFont('Times', '', 10.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->Cell(0, 5, 'Nomor: ' . $data['nomor_surat'], 0, 1, 'C');
$pdf->Ln(3);
$pdf->SetTextColor(0, 0, 0);

// ── PEMBUKA ──────────────────────────────────────────────────
$pdf->SetFont('Times', '', 11);
$pdf->MultiCell(0, 5.2,
    "Yang bertanda tangan di bawah ini, " . $data['jabatan_penandatangan'] .
    " " . $nama_lppm . " " . $nama_inst .
    ", menerangkan bahwa mahasiswa berikut ini:",
    0, 'J');
$pdf->Ln(1.5);

// ── DATA MAHASISWA ───────────────────────────────────────────
$pdf->SetFont('Times', '', 11);
$col1 = 42; $col2 = 4; $col3 = 114; // 42 + 4 + 114 = 160 (printable width)

$rows_mhs = [
    ['Nama Lengkap',  $data['nama_lengkap']],
    ['NIM',           $data['nim']],
    ['Fakultas',      $data['fakultas'] ?? '-'],
    ['Program Studi', $data['program_studi'] ?? '-'],
];
foreach ($rows_mhs as $row) {
    $pdf->Cell($col1, 5.2, $row[0], 0, 0, 'L');
    $pdf->Cell($col2, 5.2, ':', 0, 0, 'C');
    $pdf->MultiCell($col3, 5.2, $row[1], 0, 'L');
}
$pdf->Ln(2);

// ── PERNYATAAN PUBLIKASI ─────────────────────────────────────
$pdf->SetFont('Times', '', 11);
$pdf->MultiCell(0, 5.2, "Telah melaksanakan publikasi karya ilmiah sebagai berikut:", 0, 'J');
$pdf->Ln(1.5);

// Data publikasi
$rows_pub = [
    ['Jenis Publikasi',   labelJenisPublikasi($data['jenis_publikasi'])],
    ['Judul',             $data['judul_publikasi']],
    ['Jurnal / Penerbit', $data['nama_jurnal_penerbit'] ?: '-'],
    ['Tahun Terbit',      (string)($data['tahun_terbit'] ?: '-')],
];
if ($data['issn_isbn']) $rows_pub[] = ['ISSN / ISBN', $data['issn_isbn']];
if ($data['url_doi'])   $rows_pub[] = ['DOI / URL',   $data['url_doi']];
if (!empty($data['akreditasi_jurnal'])) {
    $akrLabel = match($data['akreditasi_jurnal']) {
        'sinta1'   => 'Sinta 1 (Tertinggi)',
        'sinta2'   => 'Sinta 2',
        'sinta3'   => 'Sinta 3',
        'sinta4'   => 'Sinta 4',
        'sinta5'   => 'Sinta 5',
        'sinta6'   => 'Sinta 6',
        'scopusQ1' => 'Scopus Q1 (Tertinggi)',
        'scopusQ2' => 'Scopus Q2',
        'scopusQ3' => 'Scopus Q3',
        default    => $data['akreditasi_jurnal'],
    };
    $rows_pub[] = ['Akreditasi Jurnal', $akrLabel];
}

foreach ($rows_pub as $row) {
    // Hitung tinggi sesuai isi (untuk MultiCell)
    $nbLines = max(1, ceil($pdf->GetStringWidth($row[1]) / ($col3 - 2)));
    $h = 5.2;
    $pdf->Cell($col1, $h, $row[0], 0, 0, 'L');
    $pdf->Cell($col2, $h, ':', 0, 0, 'C');
    // Posisi awal MultiCell (tetap pada baris yang sama)
    $x_start = $pdf->GetX();
    $y_start = $pdf->GetY();
    $pdf->MultiCell($col3, $h, $row[1], 0, 'L');
}
$pdf->Ln(2.5);

// ── PENUTUP ──────────────────────────────────────────────────
$pdf->SetFont('Times', '', 11);
$pdf->MultiCell(0, 5.2,
    "Publikasi tersebut telah diverifikasi dan dinyatakan memenuhi persyaratan oleh " .
    $nama_lppm . " " . $nama_inst . ". " .
    "Surat keterangan ini diberikan kepada yang bersangkutan untuk dapat dipergunakan " .
    "sebagaimana mestinya, antara lain sebagai persyaratan pengambilan ijazah di " . $nama_inst . ".",
    0, 'J');
$pdf->Ln(1.5);

$pdf->MultiCell(0, 5.2, "Demikian surat keterangan ini dibuat dengan sebenar-benarnya.", 0, 'J');

// ── TANDA TANGAN ─────────────────────────────────────────────
$pdf->Ln(4);
$tgl_surat = formatTanggal($data['tanggal_surat']);
$pdf->SetFont('Times', '', 11);
$pdf->Cell(95, 5.2, '', 0);
$pdf->Cell(65, 5.2, 'Tana Toraja, ' . $tgl_surat, 0, 1, 'C');
$pdf->Cell(95, 5.2, '', 0);
$pdf->Cell(65, 5.2, $data['jabatan_penandatangan'] . ',', 0, 1, 'C');

// Ruang TTD lebih kompak: 18mm (sebelumnya 25mm)
$pdf->Ln(18);

$pdf->SetFont('Times', 'BU', 11);
$pdf->Cell(95, 5.2, '', 0);
$pdf->Cell(65, 5.2, $nama_ketua, 0, 1, 'C');

if ($nip_ketua) {
    $pdf->SetFont('Times', '', 10.5);
    $pdf->Cell(95, 4.6, '', 0);
    $pdf->Cell(65, 4.6, $nip_ketua, 0, 1, 'C');
}

// ── OUTPUT ───────────────────────────────────────────────────
$namaFile = 'SuratPublikasi_' .
            preg_replace('/[^a-zA-Z0-9]/', '_', $data['nim']) . '_' .
            date('Ymd', strtotime($data['tanggal_surat'])) . '.pdf';

$pdf->Output('D', $namaFile);
