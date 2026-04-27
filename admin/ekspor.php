<?php
require_once '../includes/config.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';

// ── Filter parameters ──────────────────────────────────────────
$f_tahun  = (int)($_GET['tahun']  ?? 0);
$f_jenis  = clean($_GET['jenis']  ?? '');
$f_status = clean($_GET['status'] ?? '');
$f_prodi  = clean($_GET['prodi']  ?? '');
$f_tipe   = clean($_GET['tipe']   ?? 'publikasi'); // publikasi | plagiasi

// ── Build query ────────────────────────────────────────────────
if ($f_tipe === 'penelitian') {
    $where = "WHERE up.deleted_at IS NULL";
    $bind  = [];
    if ($f_tahun)  { $where .= " AND up.tahun_anggaran = ?"; $bind[] = $f_tahun; }
    if ($f_status) { $where .= " AND up.status = ?";          $bind[] = $f_status; }
    if ($f_prodi)  { $where .= " AND u.program_studi = ?";    $bind[] = $f_prodi; }

    $sql = "
        SELECT
            u.nama_lengkap, u.nidn, u.email, u.no_hp, u.fakultas, u.program_studi,
            u.jabatan_fungsional,
            up.id, up.tahun_anggaran, up.skema, up.judul, up.status,
            up.nama_ketua, up.nidn_ketua, up.jabatan_ketua,
            up.anggota_dosen, up.anggota_mahasiswa,
            up.similarity_mandiri, up.ai_mandiri,
            up.created_at, up.reviewed_at, up.catatan_reviewer,
            kp.nomor_kontrak, kp.tgl_kontrak, kp.deadline_laporan,
            (SELECT AVG(rp.nilai_total)
               FROM reviewer_penilaian rp
               JOIN reviewer_assignment ra ON ra.id = rp.assignment_id
               WHERE ra.usulan_id = up.id AND rp.submitted_at IS NOT NULL) AS nilai_rata,
            (SELECT COUNT(*)
               FROM reviewer_assignment ra
               WHERE ra.usulan_id = up.id AND ra.deleted_at IS NULL) AS jml_reviewer,
            lp.tanggal_submit AS lap_tanggal_submit,
            lp.status AS lap_status
        FROM usulan_penelitian up
        JOIN users u ON u.id = up.user_id
        LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id AND (kp.deleted_at IS NULL OR kp.deleted_at IS NULL)
        LEFT JOIN laporan_penelitian lp ON lp.usulan_id = up.id AND (lp.deleted_at IS NULL OR lp.deleted_at IS NULL)
        $where
        ORDER BY up.created_at DESC
    ";
} elseif ($f_tipe === 'plagiasi') {
    $where  = "WHERE 1=1";
    $bind   = [];
    if ($f_tahun)   { $where .= " AND s.tahun_sidang = ?";      $bind[] = $f_tahun; }
    if ($f_prodi)   { $where .= " AND u.program_studi = ?";     $bind[] = $f_prodi; }
    if ($f_status)  { $where .= " AND s.status = ?";            $bind[] = $f_status; }

    $sql = "
        SELECT
            u.nama_lengkap, u.nim, u.fakultas, u.program_studi, u.email, u.no_hp,
            s.judul_skripsi, s.nama_pembimbing1, s.nama_pembimbing2,
            s.tahun_sidang, s.status,
            cp.similarity_score, cp.tanggal_cek,
            sp.nomor_surat, sp.tanggal_surat,
            s.created_at
        FROM skripsi s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN cek_plagiasi cp ON s.id = cp.skripsi_id
        LEFT JOIN surat_plagiasi sp ON cp.id = sp.cek_plagiasi_id
        $where
        ORDER BY s.created_at DESC
    ";
} else {
    $where  = "WHERE 1=1";
    $bind   = [];
    if ($f_tahun)   { $where .= " AND p.tahun_terbit = ?";       $bind[] = $f_tahun; }
    if ($f_jenis)   { $where .= " AND p.jenis_publikasi = ?";    $bind[] = $f_jenis; }
    if ($f_status)  { $where .= " AND p.status = ?";             $bind[] = $f_status; }
    if ($f_prodi)   { $where .= " AND u.program_studi = ?";      $bind[] = $f_prodi; }

    $sql = "
        SELECT
            u.nama_lengkap, u.nim, u.fakultas, u.program_studi, u.email, u.no_hp,
            p.judul_publikasi, p.jenis_publikasi, p.nama_jurnal_penerbit,
            p.tahun_terbit, p.url_doi, p.issn_isbn, p.akreditasi_jurnal,
            p.status, p.catatan_admin,
            sp.nomor_surat, sp.tanggal_surat,
            p.created_at
        FROM publikasi p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN surat_publikasi sp ON p.id = sp.publikasi_id
        $where
        ORDER BY p.created_at DESC
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll();

// ── CSV EXPORT ─────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'rekap_' . $f_tipe . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM agar Excel baca dengan benar
    fputs($out, "\xEF\xBB\xBF");

    if ($f_tipe === 'penelitian') {
        fputcsv($out, [
            'No','Nama Pengusul','NIDN','Jabatan Fungsional','Email','No. HP','Fakultas','Program Studi',
            'Tahun Anggaran','Skema','Judul Penelitian','Status',
            'Nama Ketua (jika beda)','NIDN Ketua','Jabatan Ketua',
            'Jumlah Anggota Dosen','Jumlah Anggota Mahasiswa',
            'Similarity Mandiri (%)','AI Mandiri (%)',
            'Jumlah Reviewer','Nilai Rata-rata Reviewer',
            'Nomor Kontrak','Tanggal Kontrak','Deadline Laporan',
            'Status Laporan','Tanggal Submit Laporan',
            'Catatan Final','Tanggal Pengajuan','Tanggal Review'
        ]);
        foreach ($rows as $i => $r) {
            $n_dosen = is_array($x = json_decode($r['anggota_dosen']??'[]', true)) ? count($x) : 0;
            $n_mhs   = is_array($x = json_decode($r['anggota_mahasiswa']??'[]', true)) ? count($x) : 0;
            fputcsv($out, [
                $i + 1,
                $r['nama_lengkap'], $r['nidn'] ?? '-',
                ucwords(str_replace('_',' ', $r['jabatan_fungsional'] ?? '-')),
                $r['email'], $r['no_hp'] ?? '-', $r['fakultas'] ?? '-', $r['program_studi'] ?? '-',
                $r['tahun_anggaran'], strtoupper($r['skema']), $r['judul'], $r['status'],
                $r['nama_ketua'] ?? '-', $r['nidn_ketua'] ?? '-', $r['jabatan_ketua'] ?? '-',
                $n_dosen, $n_mhs,
                $r['similarity_mandiri'] !== null ? $r['similarity_mandiri'].'%' : '-',
                $r['ai_mandiri'] !== null ? $r['ai_mandiri'].'%' : '-',
                $r['jml_reviewer'] ?? 0,
                $r['nilai_rata'] !== null ? number_format((float)$r['nilai_rata'], 2) : '-',
                $r['nomor_kontrak'] ?? '-',
                $r['tgl_kontrak'] ? date('d/m/Y', strtotime($r['tgl_kontrak'])) : '-',
                $r['deadline_laporan'] ? date('d/m/Y H:i', strtotime($r['deadline_laporan'])) : '-',
                $r['lap_status'] ?? '-',
                $r['lap_tanggal_submit'] ? date('d/m/Y H:i', strtotime($r['lap_tanggal_submit'])) : '-',
                $r['catatan_reviewer'] ?? '-',
                date('d/m/Y H:i', strtotime($r['created_at'])),
                $r['reviewed_at'] ? date('d/m/Y H:i', strtotime($r['reviewed_at'])) : '-',
            ]);
        }
    } elseif ($f_tipe === 'plagiasi') {
        fputcsv($out, [
            'No','Nama Lengkap','NIM','Fakultas','Program Studi','Email','No. HP',
            'Judul Skripsi','Pembimbing 1','Pembimbing 2','Tahun Sidang',
            'Status','Similarity (%)','Tanggal Cek Turnitin',
            'Nomor Surat Bebas Plagiasi','Tanggal Surat','Tanggal Pengajuan'
        ]);
        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['nama_lengkap'], $r['nim'], $r['fakultas'] ?? '-', $r['program_studi'],
                $r['email'], $r['no_hp'],
                $r['judul_skripsi'], $r['nama_pembimbing1'] ?? '-', $r['nama_pembimbing2'] ?? '-',
                $r['tahun_sidang'] ?? '-',
                $r['status'],
                $r['similarity_score'] !== null ? $r['similarity_score'] . '%' : '-',
                $r['tanggal_cek'] ?? '-',
                $r['nomor_surat'] ?? '-',
                $r['tanggal_surat'] ?? '-',
                date('d/m/Y', strtotime($r['created_at'])),
            ]);
        }
    } else {
        fputcsv($out, [
            'No','Nama Lengkap','NIM','Fakultas','Program Studi','Email','No. HP',
            'Judul Publikasi','Jenis Publikasi','Nama Jurnal/Penerbit',
            'Tahun Terbit','URL / DOI / Link Artikel','ISSN / ISBN',
            'Akreditasi Jurnal','Status','Catatan Admin',
            'Nomor Surat Publikasi','Tanggal Surat','Tanggal Pengajuan'
        ]);
        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['nama_lengkap'], $r['nim'], $r['fakultas'] ?? '-', $r['program_studi'],
                $r['email'], $r['no_hp'],
                $r['judul_publikasi'],
                labelJenisPublikasi($r['jenis_publikasi']),
                $r['nama_jurnal_penerbit'] ?? '-',
                $r['tahun_terbit'] ?? '-',
                $r['url_doi'] ?? '-',
                $r['issn_isbn'] ?? '-',
                strtoupper($r['akreditasi_jurnal'] ?? '-'),
                $r['status'],
                $r['catatan_admin'] ?? '-',
                $r['nomor_surat'] ?? '-',
                $r['tanggal_surat'] ?? '-',
                date('d/m/Y', strtotime($r['created_at'])),
            ]);
        }
    }
    fclose($out);
    exit;
}

// ── Dropdown data ──────────────────────────────────────────────
$tahun_list_pub = $pdo->query("SELECT DISTINCT tahun_terbit FROM publikasi WHERE tahun_terbit IS NOT NULL ORDER BY tahun_terbit DESC")->fetchAll(PDO::FETCH_COLUMN);
$tahun_list_plag = $pdo->query("SELECT DISTINCT tahun_sidang FROM skripsi WHERE tahun_sidang IS NOT NULL ORDER BY tahun_sidang DESC")->fetchAll(PDO::FETCH_COLUMN);
$prodi_list = $pdo->query("SELECT DISTINCT program_studi FROM users WHERE program_studi IS NOT NULL AND role='mahasiswa' ORDER BY program_studi")->fetchAll(PDO::FETCH_COLUMN);

// Export URL builder
function exportUrl($params) {
    return 'ekspor.php?' . http_build_query(array_merge($params, ['export' => 'csv']));
}
$current_params = array_filter([
    'tipe'   => $f_tipe,
    'tahun'  => $f_tahun  ?: null,
    'jenis'  => $f_jenis  ?: null,
    'status' => $f_status ?: null,
    'prodi'  => $f_prodi  ?: null,
]);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Ekspor Data':'Export Data' ?> — LPPM</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="topbar">
      <div>
        <div class="topbar-title">
          <?= ic('download') ?> <?= $lang==='id'?'Ekspor Data Rekap':'Export Recap Data' ?>
          <span class="breadcrumb"><?= count($rows) ?> <?= $lang==='id'?'data ditemukan':'records found' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()"><?= ic('globe') ?> <?= $lang==='id'?'EN':'ID' ?></button>
      </div>
    </div>

    <div class="page-content">

      <!-- ── Tipe toggle ── -->
      <div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
        <a href="ekspor.php?tipe=publikasi"
           class="btn <?= $f_tipe==='publikasi'?'btn-primary':'btn-outline' ?> btn-lg"
           style="gap:8px">
          <?= ic('newspaper') ?> <?= $lang==='id'?'Data Publikasi':'Publication Data' ?>
        </a>
        <a href="ekspor.php?tipe=plagiasi"
           class="btn <?= $f_tipe==='plagiasi'?'btn-primary':'btn-outline' ?> btn-lg"
           style="gap:8px">
          <?= ic('search') ?> <?= $lang==='id'?'Data Bebas Plagiasi':'Plagiarism-Free Data' ?>
        </a>
        <a href="ekspor.php?tipe=penelitian"
           class="btn <?= $f_tipe==='penelitian'?'btn-primary':'btn-outline' ?> btn-lg"
           style="gap:8px">
          <?= ic('clipboard') ?> <?= $lang==='id'?'Data Penelitian':'Research Data' ?>
        </a>
      </div>

      <!-- ── Filter form ── -->
      <div class="card" style="margin-bottom:18px">
        <div class="card-header">
          <span class="card-title"><?= ic('search') ?> <?= $lang==='id'?'Filter Data':'Filter Data' ?></span>
        </div>
        <div class="card-body">
          <form method="GET" action="ekspor.php">
            <input type="hidden" name="tipe" value="<?= $f_tipe ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end">

              <!-- Tahun -->
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $lang==='id'?'Tahun':'Year' ?></label>
                <select name="tahun" class="form-control">
                  <option value=""><?= $lang==='id'?'Semua Tahun':'All Years' ?></option>
                  <?php
                    if ($f_tipe === 'penelitian') {
                        $tahun_list_pen = $pdo->query("SELECT DISTINCT tahun_anggaran FROM usulan_penelitian WHERE deleted_at IS NULL ORDER BY tahun_anggaran DESC")->fetchAll(PDO::FETCH_COLUMN);
                        $list = $tahun_list_pen;
                    } else {
                        $list = $f_tipe==='plagiasi' ? $tahun_list_plag : $tahun_list_pub;
                    }
                  ?>
                  <?php foreach($list as $y): ?>
                    <option value="<?= $y ?>" <?= $f_tahun==$y?'selected':'' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Jenis (hanya publikasi) -->
              <?php if ($f_tipe === 'publikasi'): ?>
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $lang==='id'?'Jenis Publikasi':'Publication Type' ?></label>
                <select name="jenis" class="form-control">
                  <option value=""><?= $lang==='id'?'Semua Jenis':'All Types' ?></option>
                  <?php foreach(['jurnal'=>'Artikel Jurnal','book_chapter'=>'Book Chapter','buku'=>'Buku','prosiding'=>'Prosiding'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $f_jenis===$k?'selected':'' ?>><?= $v ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <!-- Status -->
              <div class="form-group" style="margin:0">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                  <option value=""><?= $lang==='id'?'Semua Status':'All Status' ?></option>
                  <?php if ($f_tipe==='plagiasi'): ?>
                    <option value="menunggu"   <?= $f_status==='menunggu'?  'selected':'' ?>>Menunggu</option>
                    <option value="diproses"   <?= $f_status==='diproses'?  'selected':'' ?>>Diproses</option>
                    <option value="selesai"    <?= $f_status==='selesai'?   'selected':'' ?>>Selesai</option>
                    <option value="ditolak"    <?= $f_status==='ditolak'?   'selected':'' ?>>Ditolak</option>
                  <?php elseif ($f_tipe==='penelitian'): ?>
                    <?php foreach (['draft','diajukan','seleksi_admin','lolos_admin','gagal_admin','perbaikan_admin','seleksi_substansi','perbaikan_substantif','disetujui','revisi_minor','revisi_mayor','ditolak','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai'] as $st): ?>
                    <option value="<?= $st ?>" <?= $f_status===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <option value="menunggu"    <?= $f_status==='menunggu'?   'selected':'' ?>>Menunggu</option>
                    <option value="diverifikasi"<?= $f_status==='diverifikasi'?'selected':'' ?>>Diverifikasi</option>
                    <option value="ditolak"     <?= $f_status==='ditolak'?    'selected':'' ?>>Ditolak</option>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Program Studi -->
              <div class="form-group" style="margin:0">
                <label class="form-label"><?= $lang==='id'?'Program Studi':'Study Program' ?></label>
                <select name="prodi" class="form-control">
                  <option value=""><?= $lang==='id'?'Semua Prodi':'All Programs' ?></option>
                  <?php foreach($prodi_list as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $f_prodi===$p?'selected':'' ?>>
                      <?= htmlspecialchars($p) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Tombol -->
              <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px">
                <button type="submit" class="btn btn-primary" style="flex:1">
                  <?= ic('search') ?> <?= $lang==='id'?'Terapkan':'Apply' ?>
                </button>
                <a href="ekspor.php?tipe=<?= $f_tipe ?>" class="btn btn-outline">
                  <?= ic('refresh') ?>
                </a>
              </div>

            </div>
          </form>
        </div>
      </div>

      <!-- ── Preview & Export ── -->
      <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:10px">
          <span class="card-title">
            <?= ic($f_tipe==='plagiasi'?'search':'newspaper') ?>
            <?= $f_tipe==='plagiasi'
              ? ($lang==='id'?'Rekap Bebas Plagiasi':'Plagiarism-Free Recap')
              : ($lang==='id'?'Rekap Publikasi Mahasiswa':'Student Publication Recap') ?>
            <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px"><?= count($rows) ?> baris</span>
          </span>
          <?php if (!empty($rows)): ?>
          <a href="<?= htmlspecialchars(exportUrl($current_params)) ?>"
             class="btn btn-gold btn-sm" style="gap:6px">
            <?= ic('download') ?> <?= $lang==='id'?'Unduh CSV (Excel)':'Download CSV (Excel)' ?>
          </a>
          <?php endif; ?>
        </div>

        <div class="card-body" style="padding:0">
          <?php if (empty($rows)): ?>
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
              <?= ic('inbox','style="width:36px;height:36px;margin:0 auto 10px;display:block;color:#cbd5e1"') ?>
              <?= $lang==='id'?'Tidak ada data sesuai filter.':'No data matches the current filter.' ?>
            </div>
          <?php elseif ($f_tipe === 'penelitian'): ?>
          <!-- Tabel Penelitian -->
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:36px">#</th>
                  <th><?= $lang==='id'?'Pengusul / NIDN':'Proposer / NIDN' ?></th>
                  <th><?= $lang==='id'?'Program Studi':'Study Program' ?></th>
                  <th>Skema · Tahun</th>
                  <th><?= $lang==='id'?'Judul':'Title' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Reviewer':'Reviewers' ?></th>
                  <th><?= $lang==='id'?'Nilai Rata':'Avg Score' ?></th>
                  <th><?= $lang==='id'?'Kontrak':'Contract' ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($rows as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-muted);font-size:11px"><?= $i+1 ?></td>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['nidn']??'-') ?></div>
                  </td>
                  <td style="font-size:11px;max-width:120px"><?= htmlspecialchars($r['program_studi']??'-') ?></td>
                  <td style="font-size:11px"><?= strtoupper($r['skema']) ?> · <?= $r['tahun_anggaran'] ?></td>
                  <td style="max-width:240px">
                    <div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($r['judul'],0,55,'…')) ?>
                    </div>
                  </td>
                  <td><span style="font-size:11px;font-weight:600;background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:7px"><?= str_replace('_',' ',$r['status']) ?></span></td>
                  <td style="font-size:11.5px;text-align:center"><?= (int)$r['jml_reviewer'] ?></td>
                  <td style="font-size:11.5px;text-align:center;font-weight:700">
                    <?= $r['nilai_rata'] !== null ? number_format((float)$r['nilai_rata'], 2) : '<span style="color:var(--text-muted)">—</span>' ?>
                  </td>
                  <td style="font-size:11px">
                    <?= $r['nomor_kontrak'] ? htmlspecialchars($r['nomor_kontrak']) : '<span style="color:var(--text-muted)">—</span>' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php elseif ($f_tipe === 'plagiasi'): ?>
          <!-- Tabel Plagiasi -->
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:36px">#</th>
                  <th><?= $lang==='id'?'Nama / NIM':'Name / NIM' ?></th>
                  <th><?= $lang==='id'?'Program Studi':'Study Program' ?></th>
                  <th><?= $lang==='id'?'Judul Skripsi':'Thesis Title' ?></th>
                  <th><?= $lang==='id'?'Pembimbing':'Supervisor' ?></th>
                  <th><?= $lang==='id'?'Th. Sidang':'Defense Year' ?></th>
                  <th><?= $lang==='id'?'Similarity':'Similarity' ?></th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Nomor Surat':'Letter No.' ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($rows as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-muted);font-size:11px"><?= $i+1 ?></td>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['nim']??'-') ?></div>
                  </td>
                  <td style="font-size:11px;max-width:110px"><?= htmlspecialchars($r['program_studi']??'-') ?></td>
                  <td style="max-width:200px">
                    <div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul_skripsi']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($r['judul_skripsi'],0,55,'…')) ?>
                    </div>
                  </td>
                  <td style="font-size:11px;color:var(--text-muted)">
                    <?= htmlspecialchars($r['nama_pembimbing1']??'-') ?>
                    <?php if($r['nama_pembimbing2']): ?>
                      <br><?= htmlspecialchars($r['nama_pembimbing2']) ?>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:12px;text-align:center"><?= $r['tahun_sidang']??'-' ?></td>
                  <td style="text-align:center">
                    <?php if($r['similarity_score'] !== null): ?>
                      <?php $safe = $r['similarity_score'] <= 20; ?>
                      <span style="font-weight:700;color:<?= $safe?'var(--success-mid)':'var(--danger-mid)' ?>">
                        <?= $r['similarity_score'] ?>%
                      </span>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                  </td>
                  <td><?= badgeStatus($r['status']) ?></td>
                  <td style="font-size:11px;white-space:nowrap">
                    <?= $r['nomor_surat'] ? htmlspecialchars($r['nomor_surat']) : '<span style="color:var(--text-muted)">—</span>' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php else: ?>
          <!-- Tabel Publikasi -->
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:36px">#</th>
                  <th><?= $lang==='id'?'Nama / NIM':'Name / NIM' ?></th>
                  <th><?= $lang==='id'?'Program Studi':'Study Program' ?></th>
                  <th><?= $lang==='id'?'Judul Publikasi':'Publication Title' ?></th>
                  <th><?= $lang==='id'?'Jenis':'Type' ?></th>
                  <th><?= $lang==='id'?'Jurnal / Penerbit':'Journal / Publisher' ?></th>
                  <th><?= $lang==='id'?'Tahun':'Year' ?></th>
                  <th><?= $lang==='id'?'Akreditasi':'Accreditation' ?></th>
                  <th>URL / DOI</th>
                  <th>Status</th>
                  <th><?= $lang==='id'?'Nomor Surat':'Letter No.' ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($rows as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-muted);font-size:11px"><?= $i+1 ?></td>
                  <td>
                    <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($r['nim']??'-') ?></div>
                  </td>
                  <td style="font-size:11px;max-width:110px"><?= htmlspecialchars($r['program_studi']??'-') ?></td>
                  <td style="max-width:180px">
                    <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['judul_publikasi']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($r['judul_publikasi'],0,50,'…')) ?>
                    </div>
                    <?php if($r['catatan_admin']): ?>
                      <div style="font-size:10px;color:var(--danger-mid);margin-top:2px" title="<?= htmlspecialchars($r['catatan_admin']) ?>">
                        <?= ic('alert','style="width:10px;height:10px"') ?> <?= htmlspecialchars(mb_strimwidth($r['catatan_admin'],0,40,'…')) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-process" style="font-size:10px;white-space:nowrap">
                      <?= labelJenisPublikasi($r['jenis_publikasi']) ?>
                    </span>
                  </td>
                  <td style="font-size:11px;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= htmlspecialchars($r['nama_jurnal_penerbit']??'-') ?>
                    <?php if($r['issn_isbn']): ?>
                      <div style="font-size:10px;color:var(--text-muted)">ISSN/ISBN: <?= htmlspecialchars($r['issn_isbn']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:12px;text-align:center"><?= $r['tahun_terbit']??'-' ?></td>
                  <td style="font-size:11px;text-align:center">
                    <?php
                      $ak = strtolower($r['akreditasi_jurnal']??'');
                      $ak_color = str_starts_with($ak,'sinta') ? 'var(--info)' : (str_starts_with($ak,'scopus') ? 'var(--success-mid)' : 'var(--text-muted)');
                    ?>
                    <span style="color:<?= $ak_color ?>;font-weight:600;font-size:11px"><?= strtoupper($ak ?: '—') ?></span>
                  </td>
                  <td style="max-width:160px">
                    <?php if($r['url_doi']): ?>
                      <a href="<?= htmlspecialchars($r['url_doi']) ?>" target="_blank" rel="noopener"
                         style="font-size:11px;color:var(--primary-mid);display:inline-flex;align-items:center;gap:4px;word-break:break-all">
                        <?= ic('globe','style="width:11px;height:11px;flex-shrink:0"') ?>
                        <?= htmlspecialchars(mb_strimwidth($r['url_doi'],0,35,'…')) ?>
                      </a>
                    <?php else: ?>
                      <span style="color:var(--text-muted);font-size:11px">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= badgeStatus($r['status']) ?></td>
                  <td style="font-size:11px;white-space:nowrap">
                    <?= $r['nomor_surat'] ? htmlspecialchars($r['nomor_surat']) : '<span style="color:var(--text-muted)">—</span>' ?>
                    <?php if($r['tanggal_surat']): ?>
                      <div style="font-size:10px;color:var(--text-muted)"><?= date('d/m/Y',strtotime($r['tanggal_surat'])) ?></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
        <div style="padding:14px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <span style="font-size:12px;color:var(--text-muted)">
            <?= count($rows) ?> <?= $lang==='id'?'data siap diekspor':'records ready to export' ?>
          </span>
          <a href="<?= htmlspecialchars(exportUrl($current_params)) ?>"
             class="btn btn-gold" style="gap:8px">
            <?= ic('download') ?>
            <?= $lang==='id'?'Unduh CSV untuk Excel':'Download CSV for Excel' ?>
          </a>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<script>
function toggleLang(){const c=document.cookie.match(/lang=([^;]+)/)?.[1]||'id';document.cookie='lang='+(c==='id'?'en':'id')+';path=/;max-age=31536000';location.reload();}
</script>
</body>
</html>
