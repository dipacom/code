-- ============================================================
-- MIGRASI v2 — Pengembangan Fitur Lanjutan LPPM IAKN Toraja
-- Backup wajib dijalankan SEBELUM script ini
-- Idempotent: aman dijalankan ulang (cek kolom via INFORMATION_SCHEMA)
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = '';

-- Helper: prosedur tambah kolom jika belum ada
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
-- 1) Sinkronisasi enum usulan_penelitian.status
-- ------------------------------------------------------------
ALTER TABLE usulan_penelitian
  MODIFY COLUMN status ENUM(
    'draft',
    'diajukan',
    'seleksi_admin',
    'lolos_admin',
    'gagal_admin',
    'perbaikan_admin',
    'seleksi_substansi',
    'perbaikan_substantif',
    'disetujui',
    'revisi_minor',
    'revisi_mayor',
    'ditolak',
    'penandatanganan_kontrak',
    'kontrak_aktif',
    'laporan_diterima',
    'selesai',
    'ditinjau',
    'direvisi'
  ) DEFAULT 'draft';

-- ------------------------------------------------------------
-- 2) Deadline penilaian reviewer (#1)
-- ------------------------------------------------------------
CALL lppm_add_col('reviewer_assignment', 'deadline_review',
  'DATETIME DEFAULT NULL COMMENT ''Batas akhir reviewer menyelesaikan penilaian''');
CALL lppm_add_col('reviewer_assignment', 'catatan_admin',
  'VARCHAR(500) DEFAULT NULL COMMENT ''Catatan admin saat menugaskan reviewer''');

-- ------------------------------------------------------------
-- 3) Deadline pengajuan proposal per skema (#5)
-- ------------------------------------------------------------
CALL lppm_add_col('skema_penelitian', 'deadline_pengajuan',
  'DATETIME DEFAULT NULL COMMENT ''Auto-close skema saat NOW() > deadline_pengajuan''');
CALL lppm_add_col('skema_penelitian', 'deadline_laporan',
  'DATETIME DEFAULT NULL COMMENT ''Default deadline laporan untuk usulan dari skema ini''');

-- ------------------------------------------------------------
-- 4) Kontrak — upgrade ke datetime + file kontrak ditandatangani (#6, #12)
-- ------------------------------------------------------------
ALTER TABLE kontrak_penelitian
  MODIFY COLUMN deadline_laporan DATETIME DEFAULT NULL
    COMMENT 'Batas akhir submit laporan (jam:menit)';

CALL lppm_add_col('kontrak_penelitian', 'file_kontrak_signed',
  'VARCHAR(255) DEFAULT NULL COMMENT ''File kontrak yang sudah ditandatangani peneliti''');
CALL lppm_add_col('kontrak_penelitian', 'file_kontrak_signed_name',
  'VARCHAR(255) DEFAULT NULL');
CALL lppm_add_col('kontrak_penelitian', 'uploaded_signed_at',
  'DATETIME DEFAULT NULL');
CALL lppm_add_col('kontrak_penelitian', 'deleted_at',
  'DATETIME DEFAULT NULL');

-- ------------------------------------------------------------
-- 5) Soft-delete untuk semua tabel terkait penelitian (#10)
-- ------------------------------------------------------------
CALL lppm_add_col('reviewer_assignment',  'deleted_at', 'DATETIME DEFAULT NULL');
CALL lppm_add_col('reviewer_penilaian',   'deleted_at', 'DATETIME DEFAULT NULL');
CALL lppm_add_col('seleksi_admin',        'deleted_at', 'DATETIME DEFAULT NULL');
CALL lppm_add_col('revisi_proposal',      'deleted_at', 'DATETIME DEFAULT NULL');
CALL lppm_add_col('laporan_penelitian',   'deleted_at', 'DATETIME DEFAULT NULL');

-- ------------------------------------------------------------
-- 6) Panduan reviewer (#3)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS panduan_reviewer (
  id            INT NOT NULL AUTO_INCREMENT,
  judul         VARCHAR(200) NOT NULL,
  deskripsi     TEXT DEFAULT NULL,
  file_path     VARCHAR(300) DEFAULT NULL,
  file_name     VARCHAR(200) DEFAULT NULL,
  file_size     BIGINT DEFAULT NULL,
  link_url      VARCHAR(500) DEFAULT NULL COMMENT 'Optional: link eksternal',
  urutan        INT DEFAULT 0,
  is_active     TINYINT(1) DEFAULT 1,
  uploaded_by   INT DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active_urutan (is_active, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7) Admin delegasi (sub-admin) (#8)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_delegasi (
  id            INT NOT NULL AUTO_INCREMENT,
  user_id       INT NOT NULL,
  scope         VARCHAR(50) NOT NULL DEFAULT 'penuh'
                COMMENT 'penuh | seleksi_admin | penunjukan_reviewer',
  catatan       VARCHAR(255) DEFAULT NULL,
  granted_by    INT NOT NULL,
  granted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_by    INT DEFAULT NULL,
  revoked_at    DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user_active (user_id, revoked_at),
  CONSTRAINT fk_delegasi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bersihkan helper
DROP PROCEDURE IF EXISTS lppm_add_col;

SET SQL_MODE = @OLD_SQL_MODE;

SELECT 'Migrasi v2 selesai.' AS status;
