<?php
// ============================================================
// LETTER OF ETHICAL APPROVAL — Generator PDF (F4)
// Format mengikuti template Research Ethical Clearance Statement
// Ukuran: F4 (215 × 330 mm)
// ============================================================
require_once '../../includes/config.php';
requireLogin();
if (isMahasiswa()) { redirect('/dashboard.php'); }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('ID tidak valid.'); }

$stmt = $pdo->prepare("
    SELECT ec.*, u.nama_lengkap, u.nim, u.nidn, u.program_studi, u.fakultas, u.email
    FROM ethical_clearance ec
    JOIN users u ON ec.user_id = u.id
    WHERE ec.id = ?
");
$stmt->execute([$id]);
$data = $stmt->fetch();

if (!$data) { http_response_code(404); die('Data tidak ditemukan.'); }
if (!isAdmin()) {
    if ($data['user_id'] !== (int)$_SESSION['user_id']) { http_response_code(403); die('Akses ditolak.'); }
    if ($data['status'] !== 'disetujui') { http_response_code(403); die('Surat belum tersedia.'); }
}
if (!$data['nomor_surat']) { http_response_code(422); die('Nomor surat belum diisi oleh admin.'); }

require_once BASE_PATH . '/vendor/autoload.php';

// ── Pengaturan institusi ──────────────────────────────────────
$nama_inst   = getSetting($pdo, 'nama_institusi') ?: 'Institut Agama Kristen Negeri (IAKN) Toraja';
$nama_lppm   = getSetting($pdo, 'nama_lppm')      ?: 'Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM)';
$alamat      = getSetting($pdo, 'alamat_institusi') ?: 'St. Poros Makale-Makassar Km. 12, Mengkendek, Tana Toraja, South Sulawesi, Indonesia';
$nama_ttd    = getSetting($pdo, 'ec_nama_penandatangan')    ?: getSetting($pdo, 'nama_ketua_lppm')  ?: '';
$nip_ttd     = getSetting($pdo, 'ec_nip_penandatangan')     ?: getSetting($pdo, 'nip_ketua_lppm')   ?: '';
$jabatan_ttd = getSetting($pdo, 'ec_jabatan_penandatangan') ?: 'Chairperson, Research Ethics Committee';

$logo_path = BASE_PATH . '/assets/img/logo_lppm.png';
$has_logo  = file_exists($logo_path);

// ── Tanggal surat (format English) ────────────────────────────
$tgl_raw   = $data['tanggal_proses'] ?: $data['updated_at'];
$ts        = strtotime($tgl_raw);
$bulan_en  = ['January','February','March','April','May','June',
              'July','August','September','October','November','December'];
$tgl_surat = $bulan_en[(int)date('m', $ts) - 1] . ' ' . (int)date('d', $ts) . ', ' . date('Y', $ts);

// ── Kompose Author(s): pemohon + ketua jika beda ────────────
$pi_name = trim($data['ketua_peneliti'] ?? '');
$applicant = trim($data['nama_lengkap']);
if ($pi_name && strcasecmp($pi_name, $applicant) !== 0) {
    $authors = $applicant . ', ' . $pi_name;
} else {
    $authors = $applicant;
}

// ── Type of Research label ────────────────────────────────────
$type_of_research = $data['jenis_penelitian'] ?: 'Conceptual and theoretical research (library-based study)';

// ============================================================
// CLASS PDF — F4 (215 × 330 mm)
// ============================================================
class EcPDF extends FPDF {
    public string $nomorSurat = '';
    public string $namaInst   = '';
    public string $namaLppm   = '';
    public string $alamat     = '';
    public string $logoPath   = '';
    public bool   $hasLogo    = false;

    function Header() {
        // Top margin 15mm; logo size 22mm
        $yTop     = 15;
        $logoSize = 22;
        $xL       = 20;  // left margin
        $xR       = 195; // right margin (215 - 20)

        if ($this->hasLogo) $this->Image($this->logoPath, $xL, $yTop, $logoSize);
        $x_text = $this->hasLogo ? ($xL + $logoSize + 4) : $xL;
        $w_text = $xR - $x_text;

        $this->SetXY($x_text, $yTop);
        $this->SetFont('Times', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->MultiCell($w_text, 5, 'KEMENTERIAN AGAMA REPUBLIK INDONESIA', 0, 'C');
        $this->SetX($x_text);
        $this->MultiCell($w_text, 5, strtoupper($this->namaInst), 0, 'C');
        $this->SetX($x_text);
        $this->MultiCell($w_text, 5, 'LEMBAGA PENELITIAN DAN PENGABDIAN KEPADA', 0, 'C');
        $this->SetX($x_text);
        $this->MultiCell($w_text, 5, 'MASYARAKAT (LPPM)', 0, 'C');
        $this->SetX($x_text);
        $this->SetFont('Times', '', 9);
        $this->MultiCell($w_text, 4, $this->alamat, 0, 'C');
        $this->SetX($x_text);
        $this->MultiCell($w_text, 4, "lppm@iakn-toraja.ac.id   |   https://lppm.iakn-toraja.ac.id", 0, 'C');

        // Garis horizontal tebal di bawah kop
        $yLine = max($this->GetY() + 2, $yTop + $logoSize + 1);
        $this->SetY($yLine);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(1.2);
        $this->Line($xL, $this->GetY(), $xR, $this->GetY());
        $this->SetLineWidth(0.2);
        $this->Ln(8);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        // No footer — F4 page biar muat semua
    }
}

// ── F4 Custom size (215 x 330 mm) ───────────────────────────
$pdf = new EcPDF('P', 'mm', [215, 330]);
$pdf->nomorSurat = $data['nomor_surat'];
$pdf->namaInst   = $nama_inst;
$pdf->namaLppm   = $nama_lppm;
$pdf->alamat     = $alamat;
$pdf->logoPath   = $logo_path;
$pdf->hasLogo    = $has_logo;
$pdf->SetMargins(20, 15, 20);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$F  = 'Times';
$xL = 20;
$xR = 195;
$W  = $xR - $xL;  // 175 mm printable

// ── JUDUL SURAT ──────────────────────────────────────────────
$pdf->SetFont($F, 'B', 13);
$pdf->Cell(0, 7, 'RESEARCH ETHICAL CLEARANCE STATEMENT', 0, 1, 'C');
$pdf->SetFont($F, 'B', 11);
$pdf->Cell(0, 6, 'Reference Number:    ' . $data['nomor_surat'], 0, 1, 'C');
$pdf->Ln(6);

// ── PARAGRAF PEMBUKA ─────────────────────────────────────────
$pdf->SetFont($F, '', 11);
$opening = 'This is to certify that the undersigned, on behalf of the Research Ethics Committee of '
         . $nama_inst . ', has reviewed the following research article:';
$pdf->MultiCell(0, 5.5, $opening, 0, 'J');
$pdf->Ln(5);

// ── TABEL DATA: Title / Type / Author(s) ────────────────────
$col_lbl = 38;
$col_sep = 5;
$col_val = $W - $col_lbl - $col_sep;
$lh      = 5.5;

$rows = [
    ['Title',            $data['judul_penelitian']],
    ['Type of Research', $type_of_research],
    ['Author(s)',        $authors],
];
$pdf->SetFont($F, '', 11);
foreach ($rows as $row) {
    $nbL = max(1, (int)ceil($pdf->GetStringWidth($row[1]) / ($col_val - 2)));
    $h   = $nbL * $lh;
    $y   = $pdf->GetY();

    $pdf->SetXY($xL, $y);
    $pdf->Cell($col_lbl, $lh, $row[0], 0, 0, 'L');
    $pdf->SetXY($xL + $col_lbl, $y);
    $pdf->Cell($col_sep, $lh, ':', 0, 0, 'L');
    $pdf->SetXY($xL + $col_lbl + $col_sep, $y);
    $pdf->MultiCell($col_val, $lh, $row[1], 0, 'L');
    $pdf->SetXY($xL, $y + $h);
}
$pdf->Ln(5);

// ── PERNYATAAN UTAMA ─────────────────────────────────────────
$pdf->SetFont($F, '', 11);
$declared = 'Following a formal ethical review, this research is hereby declared to COMPLY with accepted '
          . 'standards of academic research ethics and is APPROVED for academic publication.';
$pdf->MultiCell(0, 5.5, $declared, 0, 'J');
$pdf->Ln(4);

$pdf->MultiCell(0, 5.5, 'The ethical review confirms that the study:', 0, 'L');
$pdf->Ln(2);

// ── 5 POIN NUMBERED ─────────────────────────────────────────
$points = [
    'Does not involve human participants, interviews, surveys, experiments, or the collection of personal or sensitive data.',
    'It is based solely on the critical analysis of existing academic literature from credible, verifiable scholarly sources.',
    'Does not involve plagiarism, data fabrication, data falsification, or any form of academic misconduct.',
    'Adheres to principles of academic integrity, scholarly responsibility, and ethical rigor in theological and educational research.',
    'Does not pose ethical, social, or psychological risks to individuals or communities.',
];
$pdf->SetFont($F, '', 11);
$num_w = 8;
$content_w = $W - $num_w;
foreach ($points as $i => $pt) {
    $y = $pdf->GetY();
    $pdf->SetXY($xL + 5, $y);
    $pdf->Cell($num_w, $lh, ($i + 1) . '.', 0, 0, 'L');
    $pdf->SetXY($xL + 5 + $num_w, $y);
    $pdf->MultiCell($content_w - 5, $lh, $pt, 0, 'J');
    $pdf->Ln(0.5);
}
$pdf->Ln(3);

// ── PARAGRAF PENUTUP ─────────────────────────────────────────
$pdf->SetFont($F, '', 11);
$pdf->MultiCell(0, 5.5,
    'Accordingly, this study does not require informed consent or further ethical approval procedures, '
    . 'as it involves no human subject research.',
    0, 'J');
$pdf->Ln(4);

$pdf->MultiCell(0, 5.5,
    'This Ethical Clearance Statement is issued for legitimate academic purposes, including the '
    . 'submission and publication of the article in national and international peer-reviewed academic journals.',
    0, 'J');
$pdf->Ln(8);

// ── TANDA TANGAN (kanan) ─────────────────────────────────────
$colR = 90;
$xR_start = $xL + ($W - $colR);

$pdf->SetFont($F, 'B', 11);
$pdf->SetX($xR_start);
$pdf->Cell($colR, 5.5, 'Issued in Tana Toraja, ' . $tgl_surat, 0, 1, 'L');

$pdf->SetFont($F, '', 11);
$pdf->SetX($xR_start);
$pdf->Cell($colR, 5.5, 'On behalf of ' . $nama_inst, 0, 1, 'L');

$pdf->SetX($xR_start);
$pdf->Cell($colR, 5.5, $jabatan_ttd, 0, 1, 'L');

// Ruang tanda tangan
$pdf->Ln(20);

// Nama (bold + underline)
$pdf->SetFont($F, 'BU', 11);
$pdf->SetX($xR_start);
$pdf->Cell($colR, 5.5, $nama_ttd, 0, 1, 'L');

if ($nip_ttd) {
    $pdf->SetFont($F, '', 10.5);
    $pdf->SetX($xR_start);
    $pdf->Cell($colR, 5, 'Academic ID Number: ' . $nip_ttd, 0, 1, 'L');
}

// ── OUTPUT ────────────────────────────────────────────────────
$id_pemohon = $data['nidn'] ?: $data['nim'] ?: $data['id'];
$namaFile   = 'EthicalApproval_' .
              preg_replace('/[^a-zA-Z0-9]/', '_', $id_pemohon) . '_' .
              date('Ymd', $ts) . '.pdf';

$pdf->Output('D', $namaFile);
