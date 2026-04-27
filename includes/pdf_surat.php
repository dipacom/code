<?php
/**
 * includes/pdf_surat.php
 * Shared PDF generation functions untuk batch download.
 * Mengembalikan PDF sebagai string (tidak di-output langsung).
 */
if (!defined('BASE_PATH')) die('Akses langsung tidak diizinkan.');

require_once BASE_PATH . '/vendor/autoload.php';

// ── Kop surat base class ──────────────────────────────────────
class BaseSuratPDF extends FPDF {
    public string $nomorSurat = '';
    public string $namaInst   = '';
    public string $namaLppm   = '';
    public string $alamat     = '';
    public string $logoPath   = '';
    public bool   $hasLogo    = false;

    function Header() {
        if ($this->hasLogo) $this->Image($this->logoPath, 15, 8, 20);
        $x = $this->hasLogo ? 37 : 15;
        $w = $this->hasLogo ? 158 : 180;

        $this->SetXY($x, 8);
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(26, 51, 84);
        $this->MultiCell($w, 5, 'KEMENTERIAN AGAMA REPUBLIK INDONESIA', 0, 'C');
        $this->SetX($x); $this->MultiCell($w, 5, strtoupper($this->namaInst), 0, 'C');
        $this->SetX($x);
        $this->SetFont('Arial', 'B', 11);
        $this->MultiCell($w, 5, strtoupper($this->namaLppm), 0, 'C');
        $this->SetX($x);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->MultiCell($w, 4, $this->alamat, 0, 'C');
        $this->SetX($x);
        $this->MultiCell($w, 4, 'Email: lppm@iakn-toraja.ac.id | lp2miaknt@gmail.com | Web: https://lppm.iakn-toraja.ac.id', 0, 'C');

        $this->SetY(max($this->GetY(), $this->hasLogo ? 36 : 32) + 2);
        $this->SetDrawColor(26, 51, 84); $this->SetLineWidth(0.8);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY() + 1.2, 195, $this->GetY() + 1.2);
        $this->Ln(4);
        $this->SetDrawColor(0, 0, 0); $this->SetTextColor(0, 0, 0);
    }

    function Footer() {
        $this->SetY(-16);
        $this->SetDrawColor(26, 51, 84); $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 4, 'Diterbitkan oleh Sistem Informasi LPPM IAKN Toraja  |  Nomor: ' . $this->nomorSurat, 0, 1, 'C');
        $this->Cell(0, 3, 'Dokumen ini sah setelah ditandatangani dan distempel basah oleh Ketua/Sekretaris LPPM', 0, 1, 'C');
    }
}

/**
 * Generate Surat Bebas Plagiasi → kembalikan sebagai string PDF
 */
function generatePdf_Plagiasi(array $data, string $nama_inst, string $nama_lppm, string $alamat, string $nip_ketua, string $logo_path, string $nama_ketua = '', int $batas_sim = 20, int $batas_ai = 20): string {
    $has_logo = file_exists($logo_path);
    $pdf = new BaseSuratPDF('P', 'mm', 'A4');
    $pdf->nomorSurat = $data['nomor_surat'];
    $pdf->namaInst   = $nama_inst;
    $pdf->namaLppm   = $nama_lppm;
    $pdf->alamat     = $alamat;
    $pdf->logoPath   = $logo_path;
    $pdf->hasLogo    = $has_logo;
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'BU', 13);
    $pdf->SetTextColor(26, 51, 84);
    $pdf->Cell(0, 7, 'SURAT KETERANGAN BEBAS PLAGIASI', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(0, 6, 'Nomor: ' . $data['nomor_surat'], 0, 1, 'C');
    $pdf->Ln(5); $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6,
        "Yang bertanda tangan di bawah ini, " . $data['jabatan_penandatangan'] .
        " " . $nama_lppm . " " . $nama_inst .
        ", menerangkan bahwa mahasiswa yang tersebut namanya di bawah ini:", 0, 'J');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', '', 10);
    $col1 = 52; $col2 = 5; $col3 = 108;
    $rows = [
        ['Nama Lengkap',  $data['nama_lengkap']],
        ['NIM',           $data['nim']],
        ['Fakultas',      $data['fakultas'] ?? '-'],
        ['Program Studi', $data['program_studi'] ?? '-'],
        ['Judul Skripsi', $data['judul_skripsi']],
        ['Pembimbing I',  $data['nama_pembimbing1'] ?: '-'],
        ['Pembimbing II', $data['nama_pembimbing2'] ?: '-'],
        ['Tahun Sidang',  (string)($data['tahun_sidang'] ?? '-')],
    ];
    foreach ($rows as $row) {
        $pdf->SetFillColor(248, 250, 252);
        $nbLines = max(1, ceil($pdf->GetStringWidth($row[1]) / $col3));
        $h = max(6, $nbLines * 5.5);
        $pdf->Cell($col1, $h, $row[0], 0, 0, 'L', true);
        $pdf->Cell($col2, $h, ':', 0,  0, 'C', true);
        $pdf->MultiCell($col3, $h / $nbLines, $row[1], 0, 'L');
    }
    $pdf->Ln(4);

    $ai_score = $data['ai_score'] ?? null;

    $pdf->SetFont('Arial', '', 11);
    if ($ai_score !== null) {
        $pdf->MultiCell(0, 6, "Berdasarkan hasil pengecekan yang dilakukan pada " . formatTanggal($data['tanggal_cek']) . ", skripsi tersebut memiliki hasil sebagai berikut:", 0, 'J');
    } else {
        $pdf->MultiCell(0, 6, "Berdasarkan hasil pengecekan menggunakan perangkat lunak deteksi plagiasi Turnitin yang dilakukan pada " . formatTanggal($data['tanggal_cek']) . ", skripsi tersebut memiliki tingkat kemiripan (similarity index) sebesar:", 0, 'J');
    }
    $pdf->Ln(3);

    $skor = $data['similarity_score'];
    if ($ai_score !== null) {
        $boxW = 82; $boxH = 32; $gap = 6;
        $xL = 15; $xR = $xL + $boxW + $gap; $yBox = $pdf->GetY();

        $pdf->SetFillColor(234,243,222); $pdf->SetDrawColor(59,109,17); $pdf->SetLineWidth(0.5);
        $pdf->Rect($xL, $yBox, $boxW, $boxH, 'DF');
        $pdf->SetFont('Arial','B',9); $pdf->SetTextColor(39,80,10);
        $pdf->SetXY($xL, $yBox+2); $pdf->Cell($boxW,5,'SIMILARITY INDEX (TURNITIN)',0,1,'C');
        $pdf->SetFont('Arial','B',26); $pdf->SetXY($xL,$yBox+8); $pdf->Cell($boxW,12,$skor.'%',0,1,'C');
        $pdf->SetFont('Arial','',8); $pdf->SetTextColor(71,85,105);
        $pdf->SetXY($xL,$yBox+21); $pdf->Cell($boxW,5,'Batas maksimal: \xe2\x89\xa4 '.$batas_sim.'%',0,1,'C');
        $pdf->SetXY($xL,$yBox+26); $pdf->Cell($boxW,5,'LULUS',0,1,'C');

        $aiLabel = (!empty($data['platform_ai']) && strlen($data['platform_ai']) <= 22)
            ? 'DETEKSI AI ('.strtoupper($data['platform_ai']).')'
            : 'DETEKSI AI';
        $pdf->SetFillColor(240,235,255); $pdf->SetDrawColor(109,40,217); $pdf->SetLineWidth(0.5);
        $pdf->Rect($xR, $yBox, $boxW, $boxH, 'DF');
        $pdf->SetFont('Arial','B',9); $pdf->SetTextColor(76,29,149);
        $pdf->SetXY($xR,$yBox+2); $pdf->Cell($boxW,5,$aiLabel,0,1,'C');
        $pdf->SetFont('Arial','B',26); $pdf->SetTextColor(76,29,149);
        $pdf->SetXY($xR,$yBox+8); $pdf->Cell($boxW,12,$ai_score.'%',0,1,'C');
        $pdf->SetFont('Arial','',8); $pdf->SetTextColor(71,85,105);
        $pdf->SetXY($xR,$yBox+21); $pdf->Cell($boxW,5,'Batas maksimal: \xe2\x89\xa4 '.$batas_ai.'%',0,1,'C');
        $pdf->SetXY($xR,$yBox+26); $pdf->Cell($boxW,5,'LULUS',0,1,'C');
        $pdf->SetY($yBox + $boxH + 4);
    } else {
        $pdf->SetFillColor(234,243,222); $pdf->SetDrawColor(59,109,17); $pdf->SetLineWidth(0.5);
        $pdf->Rect(55,$pdf->GetY(),90,22,'DF');
        $pdf->SetFont('Arial','B',24); $pdf->SetTextColor(39,80,10);
        $pdf->Cell(0,12,$skor.'%',0,1,'C');
        $pdf->SetFont('Arial','',9); $pdf->SetTextColor(71,85,105);
        $pdf->Cell(0,6,'(Di bawah/sama dengan batas maksimal yang ditetapkan, yaitu \xe2\x89\xa4 '.$batas_sim.'%)',0,1,'C');
        $pdf->Ln(3);
    }
    $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0,0,0); $pdf->SetTextColor(0,0,0);

    $pdf->SetFont('Arial','',11);
    if ($ai_score !== null) {
        $pdf->MultiCell(0,6,"Dengan demikian, mahasiswa yang bersangkutan dinyatakan BEBAS PLAGIASI dan BEBAS DARI KONTEN AI yang melebihi batas yang ditetapkan, sehingga berhak mendapatkan Surat Keterangan ini untuk keperluan pengambilan ijazah dan keperluan lainnya.",0,'J');
    } else {
        $pdf->MultiCell(0,6,"Dengan demikian, mahasiswa yang bersangkutan dinyatakan BEBAS PLAGIASI dan berhak mendapatkan Surat Keterangan ini untuk keperluan pengambilan ijazah dan keperluan lainnya.",0,'J');
    }
    $pdf->Ln(3);
    $pdf->MultiCell(0, 6, "Surat keterangan ini dibuat dengan sebenar-benarnya untuk dapat dipergunakan sebagaimana mestinya.", 0, 'J');

    $pdf->Ln(8);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, 'Tana Toraja, ' . formatTanggal($data['tanggal_surat']), 0, 1, 'C');
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, $data['jabatan_penandatangan'] . ',', 0, 1, 'C');
    $pdf->Ln(25);
    $pdf->SetFont('Arial', 'BU', 11);
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, $nama_ketua, 0, 1, 'C');
    if ($nip_ketua) { $pdf->SetFont('Arial', '', 10); $pdf->Cell(110, 5, '', 0); $pdf->Cell(70, 5, $nip_ketua, 0, 1, 'C'); }

    return $pdf->Output('S', '');
}

/**
 * Generate Surat Keterangan Publikasi → kembalikan sebagai string PDF
 */
function generatePdf_Publikasi(array $data, string $nama_inst, string $nama_lppm, string $alamat, string $nip_ketua, string $logo_path, string $nama_ketua = ''): string {
    $has_logo = file_exists($logo_path);
    $pdf = new BaseSuratPDF('P', 'mm', 'A4');
    $pdf->nomorSurat = $data['nomor_surat'];
    $pdf->namaInst   = $nama_inst;
    $pdf->namaLppm   = $nama_lppm;
    $pdf->alamat     = $alamat;
    $pdf->logoPath   = $logo_path;
    $pdf->hasLogo    = $has_logo;
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'BU', 13); $pdf->SetTextColor(26, 51, 84);
    $pdf->Cell(0, 7, 'SURAT KETERANGAN PUBLIKASI', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10); $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(0, 6, 'Nomor: ' . $data['nomor_surat'], 0, 1, 'C');
    $pdf->Ln(5); $pdf->SetTextColor(0,0,0);

    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6,
        "Yang bertanda tangan di bawah ini, " . $data['jabatan_penandatangan'] .
        " " . $nama_lppm . " " . $nama_inst . ", menerangkan bahwa mahasiswa berikut ini:", 0, 'J');
    $pdf->Ln(3);

    $col1 = 52; $col2 = 5; $col3 = 108;
    $pdf->SetFont('Arial', '', 10);
    foreach ([['Nama Lengkap', $data['nama_lengkap']], ['NIM', $data['nim']], ['Fakultas', $data['fakultas'] ?? '-'], ['Program Studi', $data['program_studi'] ?? '-']] as $row) {
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell($col1, 6, $row[0], 0, 0, 'L', true);
        $pdf->Cell($col2, 6, ':', 0,  0, 'C', true);
        $pdf->MultiCell($col3, 6, $row[1], 0, 'L');
    }
    $pdf->Ln(4);

    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, "Telah melaksanakan publikasi karya ilmiah sebagai berikut:", 0, 'J');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', '', 10);
    $rows_pub = [
        ['Jenis Publikasi',   labelJenisPublikasi($data['jenis_publikasi'])],
        ['Judul',             $data['judul_publikasi']],
        ['Jurnal / Penerbit', $data['nama_jurnal_penerbit'] ?: '-'],
        ['Tahun Terbit',      (string)($data['tahun_terbit'] ?: '-')],
    ];
    if ($data['issn_isbn']) $rows_pub[] = ['ISSN / ISBN', $data['issn_isbn']];
    if ($data['url_doi'])   $rows_pub[] = ['DOI / URL',   $data['url_doi']];
    if (!empty($data['akreditasi_jurnal'])) {
        $akMap = ['sinta1'=>'Sinta 1 (Tertinggi)','sinta2'=>'Sinta 2','sinta3'=>'Sinta 3','sinta4'=>'Sinta 4','sinta5'=>'Sinta 5','sinta6'=>'Sinta 6','scopusQ1'=>'Scopus Q1','scopusQ2'=>'Scopus Q2','scopusQ3'=>'Scopus Q3'];
        $rows_pub[] = ['Akreditasi Jurnal', $akMap[$data['akreditasi_jurnal']] ?? $data['akreditasi_jurnal']];
    }
    foreach ($rows_pub as $row) {
        $pdf->SetFillColor(248, 250, 252);
        $nbLines = max(1, ceil($pdf->GetStringWidth($row[1]) / $col3));
        $h = max(6, $nbLines * 5.5);
        $pdf->Cell($col1, $h, $row[0], 0, 0, 'L', true);
        $pdf->Cell($col2, $h, ':', 0,  0, 'C', true);
        $pdf->MultiCell($col3, $h / $nbLines, $row[1], 0, 'L');
    }
    $pdf->Ln(5);

    $pdf->SetFont('Arial', '', 11);
    $pdf->MultiCell(0, 6, "Publikasi tersebut telah diverifikasi dan dinyatakan memenuhi persyaratan oleh $nama_lppm $nama_inst.", 0, 'J');
    $pdf->Ln(3);
    $pdf->MultiCell(0, 6, "Surat keterangan ini diberikan kepada yang bersangkutan untuk dapat dipergunakan sebagaimana mestinya, antara lain sebagai persyaratan pengambilan ijazah di $nama_inst.", 0, 'J');
    $pdf->Ln(3);
    $pdf->MultiCell(0, 6, "Demikian surat keterangan ini dibuat dengan sebenar-benarnya.", 0, 'J');

    $pdf->Ln(8);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, 'Tana Toraja, ' . formatTanggal($data['tanggal_surat']), 0, 1, 'C');
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, $data['jabatan_penandatangan'] . ',', 0, 1, 'C');
    $pdf->Ln(25);
    $pdf->SetFont('Arial', 'BU', 11);
    $pdf->Cell(110, 6, '', 0); $pdf->Cell(70, 6, $nama_ketua, 0, 1, 'C');
    if ($nip_ketua) { $pdf->SetFont('Arial', '', 10); $pdf->Cell(110, 5, '', 0); $pdf->Cell(70, 5, $nip_ketua, 0, 1, 'C'); }

    return $pdf->Output('S', '');
}
