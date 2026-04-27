-- ============================================================
-- MIGRASI v5 — Modul Pengabdian (PkM) full
-- Mirror schema penelitian → pengabdian
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = '';

-- 1) Skema Pengabdian (mirror skema_penelitian)
CREATE TABLE IF NOT EXISTS skema_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  kode VARCHAR(50) NOT NULL,
  nama VARCHAR(200) NOT NULL,
  target_publikasi VARCHAR(255) DEFAULT NULL,
  anggaran_total BIGINT DEFAULT 0,
  anggaran_penelitian BIGINT DEFAULT 0,
  anggaran_publikasi BIGINT DEFAULT 0,
  kuota INT DEFAULT 10,
  jabatan_min ENUM('asisten_ahli','lektor','lektor_kepala','guru_besar') DEFAULT 'asisten_ahli',
  batas_similarity DECIMAL(5,2) DEFAULT 25.00,
  batas_ai DECIMAL(5,2) DEFAULT 30.00,
  min_anggota_dosen INT DEFAULT 0,
  max_anggota_dosen INT DEFAULT NULL,
  anggota_dosen_wajib TINYINT(1) DEFAULT 0,
  min_anggota_mahasiswa INT DEFAULT 0,
  max_anggota_mahasiswa INT DEFAULT NULL,
  anggota_mahasiswa_wajib TINYINT(1) DEFAULT 0,
  is_open TINYINT(1) DEFAULT 1,
  tahun YEAR NOT NULL,
  deskripsi TEXT DEFAULT NULL,
  urutan INT DEFAULT 0,
  deadline_pengajuan DATETIME DEFAULT NULL,
  deadline_laporan DATETIME DEFAULT NULL,
  batas_luaran_bulan INT DEFAULT 24,
  jenis_luaran_wajib VARCHAR(255) DEFAULT NULL,
  kontrak_wajib TINYINT(1) DEFAULT 0 COMMENT '1 = kontrak wajib, 0 = opsional (admin dapat skip kontrak)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kode_tahun (kode, tahun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2) Usulan Pengabdian (mirror usulan_penelitian)
CREATE TABLE IF NOT EXISTS usulan_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  tahun_anggaran YEAR NOT NULL,
  skema VARCHAR(50) NOT NULL,
  judul VARCHAR(500) NOT NULL,
  abstrak TEXT,
  anggota_dosen TEXT COMMENT 'JSON',
  anggota_mahasiswa TEXT COMMENT 'JSON',
  anggota_mitra TEXT,
  nama_ketua VARCHAR(150) DEFAULT NULL,
  nidn_ketua VARCHAR(20) DEFAULT NULL,
  jabatan_ketua VARCHAR(50) DEFAULT NULL,
  google_scholar_ketua VARCHAR(255) DEFAULT NULL,
  sinta_id_ketua VARCHAR(100) DEFAULT NULL,
  file_proposal VARCHAR(300) DEFAULT NULL,
  file_proposal_name VARCHAR(200) DEFAULT NULL,
  file_proposal_size BIGINT DEFAULT NULL,
  similarity_mandiri DECIMAL(5,2) DEFAULT NULL,
  ai_mandiri DECIMAL(5,2) DEFAULT NULL,
  platform_mandiri VARCHAR(100) DEFAULT NULL,
  platform_ai_mandiri VARCHAR(100) DEFAULT NULL,
  file_cek_mandiri VARCHAR(300) DEFAULT NULL,
  pernyataan_disetujui TINYINT(1) DEFAULT 0,
  status ENUM('draft','diajukan','seleksi_admin','lolos_admin','gagal_admin','perbaikan_admin',
              'seleksi_substansi','perbaikan_substantif','disetujui','revisi_minor','revisi_mayor',
              'ditolak','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai',
              'ditinjau','direvisi') DEFAULT 'draft',
  catatan_reviewer TEXT,
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  lolos_admin TINYINT(1) DEFAULT NULL,
  tgl_kontrak DATE DEFAULT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY reviewed_by (reviewed_by),
  CONSTRAINT usulan_pengabdian_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT usulan_pengabdian_review_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3) Seleksi Admin Pengabdian
CREATE TABLE IF NOT EXISTS seleksi_admin_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  admin_id INT NOT NULL,
  checklist TEXT,
  keputusan VARCHAR(50) DEFAULT NULL,
  catatan TEXT,
  deleted_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usulan (usulan_id),
  KEY idx_usulan (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4) Reviewer assignment & penilaian
CREATE TABLE IF NOT EXISTS reviewer_assignment_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  reviewer_id INT NOT NULL,
  assigned_by INT NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deadline_review DATETIME DEFAULT NULL,
  catatan_admin VARCHAR(500) DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usulan_reviewer (usulan_id, reviewer_id),
  KEY idx_usulan (usulan_id),
  KEY idx_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS reviewer_penilaian_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  assignment_id INT NOT NULL,
  usulan_id INT DEFAULT NULL,
  reviewer_id INT DEFAULT NULL,
  skor_1 TINYINT UNSIGNED DEFAULT NULL,
  skor_2 TINYINT UNSIGNED DEFAULT NULL,
  skor_3 TINYINT UNSIGNED DEFAULT NULL,
  skor_4 TINYINT UNSIGNED DEFAULT NULL,
  skor_5 TINYINT UNSIGNED DEFAULT NULL,
  skor_6 TINYINT UNSIGNED DEFAULT NULL,
  nilai_total DECIMAL(6,2) DEFAULT NULL,
  keputusan ENUM('disetujui','revisi_minor','revisi_mayor','ditolak') DEFAULT NULL,
  saran TEXT,
  file_review VARCHAR(300) DEFAULT NULL,
  file_review_name VARCHAR(200) DEFAULT NULL,
  submitted_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_assignment (assignment_id),
  KEY idx_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5) Revisi Proposal Pengabdian
CREATE TABLE IF NOT EXISTS revisi_proposal_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  user_id INT NOT NULL,
  tipe ENUM('substantif','administratif','admin') DEFAULT 'substantif',
  round_ke INT NOT NULL DEFAULT 1,
  status_sebelum VARCHAR(50) DEFAULT NULL,
  file_proposal VARCHAR(300) DEFAULT NULL,
  file_proposal_name VARCHAR(200) DEFAULT NULL,
  ringkasan TEXT,
  respon_isu TEXT,
  keputusan ENUM('diterima','dikembalikan') DEFAULT NULL,
  admin_id INT DEFAULT NULL,
  admin_catatan TEXT,
  keputusan_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6) Kontrak Pengabdian
CREATE TABLE IF NOT EXISTS kontrak_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  nomor_kontrak VARCHAR(100) DEFAULT NULL,
  tgl_kontrak DATE DEFAULT NULL,
  file_kontrak VARCHAR(255) DEFAULT NULL,
  file_kontrak_name VARCHAR(255) DEFAULT NULL,
  file_kontrak_signed VARCHAR(255) DEFAULT NULL,
  file_kontrak_signed_name VARCHAR(255) DEFAULT NULL,
  uploaded_signed_at DATETIME DEFAULT NULL,
  admin_id INT DEFAULT NULL,
  uploaded_at DATETIME DEFAULT NULL,
  deadline_laporan DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7) Laporan Pengabdian
CREATE TABLE IF NOT EXISTS laporan_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  user_id INT NOT NULL,
  file_laporan VARCHAR(255) DEFAULT NULL,
  file_laporan_name VARCHAR(255) DEFAULT NULL,
  file_laporan_size BIGINT DEFAULT NULL,
  tanggal_submit DATETIME DEFAULT NULL,
  status VARCHAR(50) DEFAULT 'menunggu',
  round_ke INT NOT NULL DEFAULT 1,
  catatan_admin TEXT,
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS laporan_pengabdian_revisi (
  id INT NOT NULL AUTO_INCREMENT,
  laporan_id INT NOT NULL,
  usulan_id INT NOT NULL,
  round_ke INT NOT NULL DEFAULT 1,
  file_laporan VARCHAR(300) DEFAULT NULL,
  file_laporan_name VARCHAR(255) DEFAULT NULL,
  file_laporan_size BIGINT DEFAULT NULL,
  ringkasan TEXT,
  catatan_admin TEXT,
  keputusan ENUM('diterima','revisi','ditolak') DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_laporan (laporan_id),
  KEY idx_usulan (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8) Monev (proses, luaran, sanksi) Pengabdian
CREATE TABLE IF NOT EXISTS monev_pengabdian_proses (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  tahap VARCHAR(80) NOT NULL,
  tanggal DATE NOT NULL,
  catatan TEXT NOT NULL,
  rekomendasi TEXT,
  file_path VARCHAR(300) DEFAULT NULL,
  file_name VARCHAR(255) DEFAULT NULL,
  admin_id INT NOT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id),
  KEY idx_tanggal (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS monev_pengabdian_luaran (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  user_id INT NOT NULL,
  jenis_luaran VARCHAR(50) NOT NULL,
  judul_luaran VARCHAR(500) DEFAULT NULL,
  nama_jurnal VARCHAR(255) DEFAULT NULL,
  url_doi VARCHAR(500) DEFAULT NULL,
  issn_isbn VARCHAR(50) DEFAULT NULL,
  akreditasi VARCHAR(30) DEFAULT NULL,
  tanggal_terbit DATE DEFAULT NULL,
  file_path VARCHAR(300) DEFAULT NULL,
  file_name VARCHAR(255) DEFAULT NULL,
  file_size BIGINT DEFAULT NULL,
  status ENUM('rencana','submit','terbit','diverifikasi','ditolak') DEFAULT 'rencana',
  catatan_admin TEXT,
  verified_by INT DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS monev_pengabdian_sanksi (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  user_id INT NOT NULL,
  jenis_sanksi VARCHAR(50) NOT NULL,
  deskripsi TEXT NOT NULL,
  durasi_tahun INT DEFAULT NULL,
  berlaku_sd DATE DEFAULT NULL,
  status ENUM('aktif','dicabut','selesai') DEFAULT 'aktif',
  admin_id INT NOT NULL,
  catatan_pencabutan TEXT,
  cabut_oleh INT DEFAULT NULL,
  cabut_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 9) Panduan Reviewer PkM (sama struktur dgn panduan_reviewer)
CREATE TABLE IF NOT EXISTS panduan_reviewer_pkm (
  id INT NOT NULL AUTO_INCREMENT,
  judul VARCHAR(200) NOT NULL,
  deskripsi TEXT,
  file_path VARCHAR(300) DEFAULT NULL,
  file_name VARCHAR(200) DEFAULT NULL,
  file_size BIGINT DEFAULT NULL,
  link_url VARCHAR(500) DEFAULT NULL,
  urutan INT DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  uploaded_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active_urutan (is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 10) Reminder luaran pengabdian
CREATE TABLE IF NOT EXISTS luaran_reminder_pengabdian (
  id INT NOT NULL AUTO_INCREMENT,
  usulan_id INT NOT NULL,
  user_id INT NOT NULL,
  period_no INT NOT NULL,
  period_date DATE NOT NULL,
  tipe ENUM('periodik','final') DEFAULT 'periodik',
  is_read TINYINT(1) DEFAULT 0,
  notif_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usulan_period (usulan_id, period_no),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 11) Settings default pengabdian
INSERT INTO pengaturan (kunci, nilai, keterangan) VALUES
  ('pengabdian_tahun', YEAR(CURDATE()), 'Tahun anggaran pengabdian aktif'),
  ('pengabdian_deadline', '2026-08-31 23:59:00', 'Batas akhir pengajuan pengabdian'),
  ('pengabdian_pernyataan_poin', '', 'JSON poin pernyataan kesanggupan PkM'),
  ('pengabdian_template', '', 'JSON template proposal PkM'),
  ('pengabdian_reviewer_rubrik', '', 'JSON rubrik penilaian PkM'),
  ('pengabdian_reviewer_lulus_threshold', '60', 'Nilai minimal lulus PkM'),
  ('pengabdian_kontrak_wajib_default', '0', 'Default kontrak wajib (1) atau opsional (0) untuk skema baru')
ON DUPLICATE KEY UPDATE keterangan = keterangan;

SET SQL_MODE = @OLD_SQL_MODE;
SELECT 'Migrasi v5 (Pengabdian schema) selesai.' AS status;
