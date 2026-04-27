-- ============================================================
-- MIGRASI v3 — Laporan revisi + Monev penelitian
-- Backup wajib dijalankan SEBELUM script ini
-- Idempotent: aman dijalankan ulang
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = '';

-- Helper procedure (re-create jika sudah ada dari migrate_v2)
DROP PROCEDURE IF EXISTS lppm_add_col;
DELIMITER $$
CREATE PROCEDURE lppm_add_col(
  IN p_table VARCHAR(64),
  IN p_col   VARCHAR(64),
  IN p_def   TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------
-- 1) Update enum status laporan_penelitian — tambah 'revisi'
-- ------------------------------------------------------------
ALTER TABLE laporan_penelitian
  MODIFY COLUMN status VARCHAR(50) DEFAULT 'menunggu'
  COMMENT 'menunggu | diterima | revisi | ditolak';

CALL lppm_add_col('laporan_penelitian', 'round_ke',
  'INT NOT NULL DEFAULT 1 COMMENT ''Round laporan: 1, 2, 3 (kalau direvisi)''');

-- ------------------------------------------------------------
-- 2) Tabel history revisi laporan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS laporan_revisi (
  id              INT NOT NULL AUTO_INCREMENT,
  laporan_id      INT NOT NULL,
  usulan_id       INT NOT NULL,
  round_ke        INT NOT NULL DEFAULT 1,
  file_laporan    VARCHAR(300) DEFAULT NULL,
  file_laporan_name VARCHAR(255) DEFAULT NULL,
  file_laporan_size BIGINT DEFAULT NULL,
  ringkasan       TEXT DEFAULT NULL COMMENT 'Ringkasan perubahan dari peneliti',
  catatan_admin   TEXT DEFAULT NULL COMMENT 'Catatan admin saat memutuskan',
  keputusan       ENUM('diterima','revisi','ditolak') DEFAULT NULL,
  reviewed_by     INT DEFAULT NULL,
  reviewed_at     DATETIME DEFAULT NULL,
  submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_laporan (laporan_id),
  KEY idx_usulan  (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) Skema penelitian — batas luaran (bulan sejak deadline laporan)
-- ------------------------------------------------------------
CALL lppm_add_col('skema_penelitian', 'batas_luaran_bulan',
  'INT DEFAULT 24 COMMENT ''Batas akhir luaran (bulan sejak deadline laporan). Default 24 = 2 tahun''');

CALL lppm_add_col('skema_penelitian', 'jenis_luaran_wajib',
  'VARCHAR(255) DEFAULT NULL COMMENT ''Daftar jenis luaran wajib (CSV: jurnal_sinta,prosiding,...)''');

-- Set default 36 bulan untuk skema 'global', 24 bulan untuk lainnya (kalau masih NULL)
UPDATE skema_penelitian SET batas_luaran_bulan = 36 WHERE kode = 'global' AND batas_luaran_bulan IS NULL;
UPDATE skema_penelitian SET batas_luaran_bulan = 24 WHERE batas_luaran_bulan IS NULL;

-- ------------------------------------------------------------
-- 4) Tabel monev luaran — luaran yang dijanjikan & realisasinya
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monev_luaran (
  id              INT NOT NULL AUTO_INCREMENT,
  usulan_id       INT NOT NULL,
  user_id         INT NOT NULL COMMENT 'Pengusul (peneliti)',
  jenis_luaran    VARCHAR(50) NOT NULL
                  COMMENT 'jurnal_sinta | jurnal_scopus | jurnal_internasional | prosiding | book_chapter | buku | hki | paten | lainnya',
  judul_luaran    VARCHAR(500) DEFAULT NULL,
  nama_jurnal     VARCHAR(255) DEFAULT NULL COMMENT 'Nama jurnal/penerbit/konferensi',
  url_doi         VARCHAR(500) DEFAULT NULL,
  issn_isbn       VARCHAR(50) DEFAULT NULL,
  akreditasi      VARCHAR(30) DEFAULT NULL COMMENT 'sinta1..6 | scopusQ1..3 | -',
  tanggal_terbit  DATE DEFAULT NULL,
  file_path       VARCHAR(300) DEFAULT NULL,
  file_name       VARCHAR(255) DEFAULT NULL,
  file_size       BIGINT DEFAULT NULL,
  status          ENUM('rencana','submit','terbit','diverifikasi','ditolak') DEFAULT 'rencana'
                  COMMENT 'rencana=dijanjikan, submit=under review jurnal, terbit=published, diverifikasi=admin LPPM verif, ditolak',
  catatan_admin   TEXT DEFAULT NULL,
  verified_by     INT DEFAULT NULL,
  verified_at     DATETIME DEFAULT NULL,
  deleted_at      DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan (usulan_id),
  KEY idx_user   (user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5) Tabel monev proses — catatan progress periodik (oleh admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monev_proses (
  id              INT NOT NULL AUTO_INCREMENT,
  usulan_id       INT NOT NULL,
  tahap           VARCHAR(80) NOT NULL
                  COMMENT 'kunjungan_lapangan | seminar_progres | review_log_book | konsultasi | lainnya',
  tanggal         DATE NOT NULL,
  catatan         TEXT NOT NULL COMMENT 'Hasil monev / temuan / rekomendasi',
  rekomendasi     TEXT DEFAULT NULL,
  file_path       VARCHAR(300) DEFAULT NULL,
  file_name       VARCHAR(255) DEFAULT NULL,
  admin_id        INT NOT NULL,
  deleted_at      DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan  (usulan_id),
  KEY idx_tanggal (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6) Tabel sanksi — sanksi atas laporan/luaran tidak terpenuhi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS monev_sanksi (
  id              INT NOT NULL AUTO_INCREMENT,
  usulan_id       INT NOT NULL,
  user_id         INT NOT NULL,
  jenis_sanksi    VARCHAR(50) NOT NULL
                  COMMENT 'tidak_boleh_ajuan_X_tahun | wajib_kembalikan_dana | catatan_administratif | lainnya',
  deskripsi       TEXT NOT NULL,
  durasi_tahun    INT DEFAULT NULL COMMENT 'Lama sanksi (jika applicable)',
  berlaku_sd      DATE DEFAULT NULL,
  status          ENUM('aktif','dicabut','selesai') DEFAULT 'aktif',
  admin_id        INT NOT NULL,
  catatan_pencabutan TEXT DEFAULT NULL,
  cabut_oleh      INT DEFAULT NULL,
  cabut_at        DATETIME DEFAULT NULL,
  deleted_at      DATETIME DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_usulan  (usulan_id),
  KEY idx_user    (user_id),
  KEY idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bersihkan helper
DROP PROCEDURE IF EXISTS lppm_add_col;

SET SQL_MODE = @OLD_SQL_MODE;

SELECT 'Migrasi v3 selesai.' AS status;
