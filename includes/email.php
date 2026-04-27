<?php
// ============================================================
// HELPER EMAIL — PHPMailer
// Install: composer require phpmailer/phpmailer
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once BASE_PATH . '/vendor/autoload.php';

// ============================================================
// KONFIGURASI PENERIMA EMAIL LPPM
//   To  : lp2miaknt@gmail.com       (Gmail utama LPPM — penerima pertama)
//   CC  : lppm@iakn-toraja.ac.id    (email resmi institusi — arsip)
// ============================================================
define('MAIL_TO_LPPM',   'lp2miaknt@gmail.com');
define('MAIL_CC_LPPM',   'lppm@iakn-toraja.ac.id');
define('MAIL_NAME_LPPM', 'Admin LPPM IAKN Toraja');

// ============================================================
// FUNGSI HELPER PRIVATE — setup koneksi SMTP
// ============================================================
function _smtpSetup(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
    return $mail;
}

// ============================================================
// 1. NOTIFIKASI KE ADMIN LPPM
//    Dipanggil saat mahasiswa upload skripsi atau publikasi.
//
//    To  → lp2miaknt@gmail.com     (Gmail utama LPPM)
//    CC  → lppm@iakn-toraja.ac.id  (email resmi institusi — arsip)
// ============================================================
function kirimEmailAdmin(
    $pdo,
    string $subjek,
    string $namaFile,
    string $namaMahasiswa,
    string $jenisPermohonan
): bool {
    $namaInstitusi = getSetting($pdo, 'nama_institusi');

    try {
        $mail = _smtpSetup();

        // Penerima utama — email resmi institusi
        $mail->addAddress(MAIL_TO_LPPM, MAIL_NAME_LPPM);

        // CC — Gmail LPPM sebagai backup & arsip
        $mail->addCC(MAIL_CC_LPPM, MAIL_NAME_LPPM);

        $mail->isHTML(true);
        $mail->Subject = $subjek;
        $mail->Body    = _tplAdmin($namaMahasiswa, $namaFile, $jenisPermohonan, $namaInstitusi);
        $mail->AltBody = "Ada permohonan baru dari {$namaMahasiswa} ({$jenisPermohonan})."
                       . " File: {$namaFile}. Login ke sistem LPPM untuk memproses.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("[LPPM Email] kirimEmailAdmin gagal: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// 2. NOTIFIKASI KE MAHASISWA
//    Dipanggil saat admin selesai memproses permohonan.
//
//    To  → email mahasiswa (dari data registrasi)
//    CC  → lp2miaknt@gmail.com  (Gmail utama LPPM — arsip hasil proses)
// ============================================================
function kirimEmailMahasiswa(
    string $emailMahasiswa,
    string $namaMahasiswa,
    string $status,
    string $jenisPermohonan,
    string $catatan = '',
    string $nimMahasiswa = ''
): bool {
    $isApproved = in_array($status, ['selesai', 'diverifikasi']);

    try {
        $mail = _smtpSetup();

        // Penerima utama — email mahasiswa yang didaftarkan
        $mail->addAddress($emailMahasiswa, $namaMahasiswa);

        // CC — Gmail LPPM menerima salinan setiap hasil proses
        $mail->addCC(MAIL_CC_LPPM, MAIL_NAME_LPPM);

        $mail->isHTML(true);

        $mail->Subject = $isApproved
            ? "[LPPM IAKN Toraja] Surat Keterangan Anda Siap Diunduh"
            : "[LPPM IAKN Toraja] Permohonan {$jenisPermohonan} Perlu Direvisi";

        $mail->Body    = _tplMahasiswa($namaMahasiswa, $nimMahasiswa, $status, $jenisPermohonan, $catatan);
        $mail->AltBody = "Permohonan {$jenisPermohonan} Anda telah diproses. Status: {$status}."
                       . " Login ke sistem LPPM: " . BASE_URL . "/dashboard.php";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("[LPPM Email] kirimEmailMahasiswa gagal: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// 3. KONFIRMASI UPLOAD KE MAHASISWA (opsional tapi direkomendasikan)
//    Dikirim langsung begitu mahasiswa berhasil upload,
//    sebelum admin memproses — agar mahasiswa tahu
//    dokumennya sudah diterima sistem.
//
//    To  → email mahasiswa
//    CC  → lp2miaknt@gmail.com
// ============================================================
function kirimEmailKonfirmasiUpload(
    string $emailMahasiswa,
    string $namaMahasiswa,
    string $jenisPermohonan,
    string $namaFile
): bool {
    try {
        $mail = _smtpSetup();

        $mail->addAddress($emailMahasiswa, $namaMahasiswa);
        $mail->addCC(MAIL_CC_LPPM, MAIL_NAME_LPPM);

        $mail->isHTML(true);
        $mail->Subject = "[LPPM IAKN Toraja] Upload Berhasil — Permohonan Sedang Diproses";
        $mail->Body    = _tplKonfirmasiUpload($namaMahasiswa, $jenisPermohonan, $namaFile);
        $mail->AltBody = "Upload Anda berhasil diterima. File: {$namaFile}."
                       . " Admin LPPM akan memproses permohonan {$jenisPermohonan} Anda segera.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("[LPPM Email] kirimEmailKonfirmasiUpload gagal: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// TEMPLATE HTML — INTERNAL
// ============================================================

function _emailHeader(): string {
    return "
    <div style='font-family:\"Poppins\",Arial,sans-serif;max-width:600px;margin:0 auto;
                border:1px solid #e2e8f0;border-radius:10px;overflow:hidden'>
      <div style='background:#1a3354;padding:20px 24px;display:flex;align-items:center;gap:14px'>
        <div style='background:rgba(201,149,42,0.2);border-radius:8px;padding:8px;flex-shrink:0'>
          <div style='color:#c9952a;font-size:20px;line-height:1'>&#x1F3DB;</div>
        </div>
        <div>
          <div style='color:#fff;font-size:15px;font-weight:700;margin:0;line-height:1.3'>
            LPPM IAKN Toraja
          </div>
          <div style='color:rgba(255,255,255,0.55);font-size:11px;margin-top:3px'>
            Lembaga Penelitian dan Pengabdian kepada Masyarakat
          </div>
        </div>
      </div>";
}

function _emailFooter(): string {
    return "
      <div style='background:#f8fafc;padding:14px 24px;border-top:1px solid #e2e8f0;
                  text-align:center;font-size:11px;color:#94a3b8;line-height:1.7'>
        Email ini dikirim otomatis oleh Sistem Informasi LPPM IAKN Toraja.<br>
        Mohon tidak membalas email ini langsung.<br>
        Hubungi LPPM:
        <a href='mailto:lppm@iakn-toraja.ac.id' style='color:#c9952a;text-decoration:none'>
          lppm@iakn-toraja.ac.id
        </a>
        &nbsp;|&nbsp;
        <a href='mailto:lp2miaknt@gmail.com' style='color:#c9952a;text-decoration:none'>
          lp2miaknt@gmail.com
        </a>
      </div>
    </div>";
}

// Template notifikasi admin (permohonan baru masuk)
function _tplAdmin(string $nama, string $file, string $jenis, string $institusi): string {
    $nama      = htmlspecialchars($nama, ENT_QUOTES);
    $file      = htmlspecialchars($file, ENT_QUOTES);
    $jenis     = htmlspecialchars($jenis, ENT_QUOTES);
    $institusi = htmlspecialchars($institusi, ENT_QUOTES);

    $waktu = date('d/m/Y H:i') . ' WITA';
    return _emailHeader() . "
      <div style='padding:24px'>
        <div style='background:#fef3c7;border-left:4px solid #f59e0b;padding:11px 14px;
                    border-radius:4px;margin-bottom:18px'>
          <strong style='color:#78350f;font-size:13px'>Ada Permohonan Baru Masuk</strong>
        </div>

        <p style='color:#374151;font-size:13px;margin:0 0 10px'>Yth. Admin LPPM,</p>
        <p style='color:#374151;font-size:13px;margin:0 0 16px'>
          Mahasiswa berikut telah mengajukan permohonan
          <strong>{$jenis}</strong> dan menunggu untuk diproses.
        </p>

        <table style='width:100%;border-collapse:collapse;margin:0 0 20px;font-size:13px'>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;width:38%;
                       border:1px solid #e2e8f0;color:#475569'>Nama Mahasiswa</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$nama}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Jenis Permohonan</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$jenis}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Nama File</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$file}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Waktu Upload</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$waktu}</td>
          </tr>
        </table>

        <a href='" . BASE_URL . "/admin/dashboard.php'
           style='display:inline-block;background:#1a3354;color:#fff;padding:11px 22px;
                  border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
          Buka Dashboard Admin &#8594;
        </a>

        <p style='color:#94a3b8;font-size:11px;margin-top:16px;line-height:1.6'>
          Email ini dikirim ke:
          <strong style='color:#475569'>lp2miaknt@gmail.com</strong> (To) dan
          <strong style='color:#475569'>lppm@iakn-toraja.ac.id</strong> (CC).
        </p>
      </div>"
    . _emailFooter();
}

// Template notifikasi mahasiswa (hasil proses permohonan)
function _tplMahasiswa(
    string $nama,
    string $nim,
    string $status,
    string $jenis,
    string $catatan
): string {
    $nama    = htmlspecialchars($nama, ENT_QUOTES);
    $nim     = htmlspecialchars($nim, ENT_QUOTES);
    $jenis   = htmlspecialchars($jenis, ENT_QUOTES);
    $catatan = htmlspecialchars($catatan, ENT_QUOTES);

    $isApproved  = in_array($status, ['selesai', 'diverifikasi']);
    $warna       = $isApproved ? '#065f46' : '#991b1b';
    $bg          = $isApproved ? '#d1fae5' : '#fee2e2';
    $icon        = $isApproved ? '&#x2705;' : '&#x274C;';
    $statusLabel = $isApproved ? 'DISETUJUI &#8212; Surat Siap Diunduh' : 'PERLU REVISI';
    $nimHtml     = $nim ? " <span style='color:#94a3b8;font-size:12px'>&#183; NIM: {$nim}</span>" : '';

    $catatanHtml = $catatan
        ? "<div style='background:#fef3c7;border-left:4px solid #f59e0b;padding:11px 14px;
                        border-radius:4px;margin:14px 0'>
             <strong style='color:#78350f;font-size:12px'>Catatan dari Admin LPPM:</strong>
             <p style='color:#78350f;font-size:13px;margin:6px 0 0'>{$catatan}</p>
           </div>"
        : '';

    $actionHtml = $isApproved
        ? "<p style='color:#374151;font-size:13px;margin:0 0 14px'>
             Silakan login ke sistem LPPM untuk mengunduh surat keterangan Anda:
           </p>
           <a href='" . BASE_URL . "/dashboard.php'
              style='display:inline-block;background:#065f46;color:#fff;padding:11px 22px;
                     border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
             Unduh Surat Sekarang &#8594;
           </a>
           <p style='color:#64748b;font-size:12px;margin-top:14px;line-height:1.6'>
             Setelah mengunduh, <strong>cetak surat</strong> dan bawa ke kantor LPPM untuk
             mendapatkan <strong>tanda tangan dan stempel basah</strong> dari Ketua/Sekretaris LPPM.
           </p>"
        : "<p style='color:#374151;font-size:13px;margin:0 0 14px'>
             Silakan perbaiki dokumen Anda dan upload ulang melalui sistem:
           </p>
           <a href='" . BASE_URL . "/dashboard.php'
              style='display:inline-block;background:#991b1b;color:#fff;padding:11px 22px;
                     border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
             Upload Ulang &#8594;
           </a>";

    return _emailHeader() . "
      <div style='padding:24px'>
        <div style='background:{$bg};border-left:4px solid {$warna};padding:11px 14px;
                    border-radius:4px;margin-bottom:18px'>
          <strong style='color:{$warna};font-size:13px'>
            {$icon} Status Permohonan: {$statusLabel}
          </strong>
        </div>

        <p style='color:#374151;font-size:13px;margin:0 0 6px'>
          Yth. <strong>{$nama}</strong>{$nimHtml},
        </p>
        <p style='color:#374151;font-size:13px;margin:0 0 14px'>
          Permohonan <strong>{$jenis}</strong> Anda telah selesai diproses oleh Admin LPPM.
        </p>

        {$catatanHtml}
        {$actionHtml}

        <p style='color:#94a3b8;font-size:11px;margin-top:16px;line-height:1.6'>
          Salinan email ini juga dikirimkan ke
          <strong style='color:#475569'>lppm@iakn-toraja.ac.id</strong> sebagai arsip LPPM.
        </p>
      </div>"
    . _emailFooter();
}

// ============================================================
// 4. NOTIFIKASI STATUS ETHICAL CLEARANCE KE PEMOHON
//    Dipanggil saat admin update status EC: disetujui / ditolak / diproses
//
//    To  → email pemohon
//    CC  → lppm@iakn-toraja.ac.id
// ============================================================
function kirimEmailStatusEC(
    string $emailPemohon,
    string $namaPemohon,
    string $status,           // 'disetujui' | 'ditolak' | 'diproses'
    string $judulPenelitian,
    string $catatan = '',
    string $nomorSurat = ''
): bool {
    try {
        $mail = _smtpSetup();
        $mail->addAddress($emailPemohon, $namaPemohon);
        $mail->addCC(MAIL_CC_LPPM, MAIL_NAME_LPPM);
        $mail->isHTML(true);

        $subjekMap = [
            'disetujui' => '[LPPM IAKN Toraja] Ethical Clearance Anda Disetujui — Surat Siap Diunduh',
            'ditolak'   => '[LPPM IAKN Toraja] Permohonan Ethical Clearance Ditolak',
            'diproses'  => '[LPPM IAKN Toraja] Permohonan Ethical Clearance Sedang Ditinjau',
        ];
        $mail->Subject = $subjekMap[$status] ?? '[LPPM IAKN Toraja] Update Permohonan Ethical Clearance';
        $mail->Body    = _tplStatusEC($namaPemohon, $status, $judulPenelitian, $catatan, $nomorSurat);
        $mail->AltBody = "Permohonan Ethical Clearance Anda untuk \"{$judulPenelitian}\" telah diperbarui. "
                       . "Status: {$status}. Login ke sistem LPPM: " . BASE_URL . "/dashboard.php";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("[LPPM Email] kirimEmailStatusEC gagal: " . $e->getMessage());
        return false;
    }
}

// Template status EC (internal)
function _tplStatusEC(
    string $nama,
    string $status,
    string $judul,
    string $catatan,
    string $nomorSurat
): string {
    $nama       = htmlspecialchars($nama, ENT_QUOTES);
    $judul      = htmlspecialchars($judul, ENT_QUOTES);
    $catatan    = htmlspecialchars($catatan, ENT_QUOTES);
    $nomorSurat = htmlspecialchars($nomorSurat, ENT_QUOTES);

    $waktu = date('d/m/Y H:i') . ' WITA';

    // Warna & label per status
    $cfg = [
        'disetujui' => [
            'bg'    => '#d1fae5', 'border' => '#065f46', 'text' => '#065f46',
            'icon'  => '&#x2705;',
            'label' => 'DISETUJUI — Surat Ethical Clearance Siap Diunduh',
            'btn_bg'=> '#065f46', 'btn_lbl'=> 'Unduh Surat Sekarang &#8594;',
        ],
        'ditolak'   => [
            'bg'    => '#fee2e2', 'border' => '#991b1b', 'text' => '#991b1b',
            'icon'  => '&#x274C;',
            'label' => 'TIDAK DISETUJUI — Permohonan Perlu Diperbaiki',
            'btn_bg'=> '#991b1b', 'btn_lbl'=> 'Ajukan Ulang &#8594;',
        ],
        'diproses'  => [
            'bg'    => '#dbeafe', 'border' => '#1d4ed8', 'text' => '#1d4ed8',
            'icon'  => '&#x1F50D;',
            'label' => 'SEDANG DITINJAU — Dokumen Anda Sedang Diverifikasi',
            'btn_bg'=> '#1a3354', 'btn_lbl'=> 'Pantau Status &#8594;',
        ],
    ];
    $c = $cfg[$status] ?? $cfg['diproses'];

    $noSuratHtml = ($nomorSurat && $status === 'disetujui')
        ? "<tr>
             <td style='padding:9px 12px;background:#f8fafc;font-weight:600;width:38%;
                        border:1px solid #e2e8f0;color:#475569'>Nomor Surat</td>
             <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b;font-weight:600'>{$nomorSurat}</td>
           </tr>"
        : '';

    $catatanHtml = $catatan
        ? "<div style='background:#fef3c7;border-left:4px solid #f59e0b;padding:11px 14px;
                       border-radius:4px;margin:14px 0'>
             <strong style='color:#78350f;font-size:12px'>Catatan dari Admin LPPM:</strong>
             <p style='color:#78350f;font-size:13px;margin:6px 0 0'>" . htmlspecialchars($catatan) . "</p>
           </div>"
        : '';

    $actionHtml = '';
    if ($status === 'disetujui') {
        $actionHtml = "
        <p style='color:#374151;font-size:13px;margin:0 0 10px'>
          Surat <strong>Letter of Ethical Approval</strong> Anda telah siap. Silakan login ke sistem LPPM untuk mengunduhnya:
        </p>
        <a href='" . BASE_URL . "/dashboard.php'
           style='display:inline-block;background:{$c['btn_bg']};color:#fff;padding:11px 22px;
                  border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
          {$c['btn_lbl']}
        </a>
        <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;
                    padding:13px 16px;margin-top:16px;font-size:12px;color:#166534;line-height:1.8'>
          <strong>Langkah selanjutnya:</strong><br>
          1. Login ke <a href='" . BASE_URL . "/dashboard.php' style='color:#166534'>Sistem Informasi LPPM</a>
             dan unduh surat dari halaman beranda.<br>
          2. Cetak surat tersebut.<br>
          3. Bawa ke kantor LPPM untuk mendapatkan <strong>tanda tangan dan stempel basah</strong>
             dari Ketua/Sekretaris LPPM.
        </div>";
    } elseif ($status === 'ditolak') {
        $actionHtml = "
        <p style='color:#374151;font-size:13px;margin:0 0 10px'>
          Permohonan Anda belum dapat disetujui. Silakan perbaiki dokumen sesuai catatan di atas,
          kemudian ajukan kembali melalui sistem:
        </p>
        <a href='" . BASE_URL . "/modules/ethical_clearance/upload.php'
           style='display:inline-block;background:{$c['btn_bg']};color:#fff;padding:11px 22px;
                  border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
          {$c['btn_lbl']}
        </a>";
    } else {
        $actionHtml = "
        <p style='color:#374151;font-size:13px;margin:0 0 10px'>
          Dokumen Anda sedang dalam proses tinjauan oleh Komite Etik LPPM.
          Anda akan menerima email kembali begitu keputusan sudah ditetapkan.
        </p>
        <a href='" . BASE_URL . "/dashboard.php'
           style='display:inline-block;background:{$c['btn_bg']};color:#fff;padding:11px 22px;
                  border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
          {$c['btn_lbl']}
        </a>";
    }

    return _emailHeader() . "
      <div style='padding:24px'>
        <div style='background:{$c['bg']};border-left:4px solid {$c['border']};padding:11px 14px;
                    border-radius:4px;margin-bottom:18px'>
          <strong style='color:{$c['text']};font-size:13px'>
            {$c['icon']} {$c['label']}
          </strong>
        </div>

        <p style='color:#374151;font-size:13px;margin:0 0 10px'>
          Yth. <strong>{$nama}</strong>,
        </p>
        <p style='color:#374151;font-size:13px;margin:0 0 16px'>
          Berikut adalah pembaruan status permohonan <strong>Ethical Clearance</strong> Anda:
        </p>

        <table style='width:100%;border-collapse:collapse;margin:0 0 16px;font-size:13px'>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;width:38%;
                       border:1px solid #e2e8f0;color:#475569'>Judul Penelitian</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$judul}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Status</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;font-weight:700;color:{$c['text']}'>
              {$c['icon']} " . strtoupper($status) . "</td>
          </tr>
          {$noSuratHtml}
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Tanggal Proses</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$waktu}</td>
          </tr>
        </table>

        {$catatanHtml}
        {$actionHtml}

        <p style='color:#94a3b8;font-size:11px;margin-top:18px;line-height:1.6'>
          Salinan email ini dikirimkan ke
          <strong style='color:#475569'>lppm@iakn-toraja.ac.id</strong> sebagai arsip LPPM.
        </p>
      </div>"
    . _emailFooter();
}

// Template konfirmasi upload berhasil (untuk mahasiswa)
function _tplKonfirmasiUpload(string $nama, string $jenis, string $file): string {
    $nama  = htmlspecialchars($nama, ENT_QUOTES);
    $jenis = htmlspecialchars($jenis, ENT_QUOTES);
    $file  = htmlspecialchars($file, ENT_QUOTES);

    $waktu = date('d/m/Y H:i') . ' WITA';
    return _emailHeader() . "
      <div style='padding:24px'>
        <div style='background:#dbeafe;border-left:4px solid #1a3354;padding:11px 14px;
                    border-radius:4px;margin-bottom:18px'>
          <strong style='color:#1e3a5f;font-size:13px'>Upload Berhasil Diterima oleh Sistem</strong>
        </div>

        <p style='color:#374151;font-size:13px;margin:0 0 10px'>
          Yth. <strong>{$nama}</strong>,
        </p>
        <p style='color:#374151;font-size:13px;margin:0 0 16px'>
          Permohonan <strong>{$jenis}</strong> Anda telah berhasil diterima oleh sistem LPPM.
          Admin LPPM akan segera memverifikasi dokumen Anda.
        </p>

        <table style='width:100%;border-collapse:collapse;margin:0 0 20px;font-size:13px'>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;width:38%;
                       border:1px solid #e2e8f0;color:#475569'>Jenis Permohonan</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$jenis}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>File Diunggah</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$file}</td>
          </tr>
          <tr>
            <td style='padding:9px 12px;background:#f8fafc;font-weight:600;
                       border:1px solid #e2e8f0;color:#475569'>Waktu Upload</td>
            <td style='padding:9px 12px;border:1px solid #e2e8f0;color:#1e293b'>{$waktu}</td>
          </tr>
        </table>

        <p style='color:#64748b;font-size:12px;margin:0 0 14px;line-height:1.6'>
          Anda akan menerima email notifikasi kembali begitu permohonan selesai diproses.
          Pantau juga status permohonan melalui dasbor:
        </p>
        <a href='" . BASE_URL . "/dashboard.php'
           style='display:inline-block;background:#1a3354;color:#fff;padding:11px 22px;
                  border-radius:7px;text-decoration:none;font-weight:600;font-size:13px'>
          Pantau Status Permohonan &#8594;
        </a>
      </div>"
    . _emailFooter();
}
