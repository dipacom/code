-- Fix missing columns for Lecturer Dashboard and Admin tools (MySQL compatible)
USE lppm_iakntoraja;

-- 1. ethical_clearance
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'ethical_clearance' AND column_name = 'nama_jurnal');
SET @query := IF(@col_exists = 0, 'ALTER TABLE ethical_clearance ADD COLUMN nama_jurnal VARCHAR(255) NULL AFTER judul_penelitian', 'SELECT "Column nama_jurnal already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'ethical_clearance' AND column_name = 'tanggal_proses');
SET @query := IF(@col_exists = 0, 'ALTER TABLE ethical_clearance ADD COLUMN tanggal_proses DATE NULL AFTER reviewed_at', 'SELECT "Column tanggal_proses already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'ethical_clearance' AND column_name = 'file_surat_signed');
SET @query := IF(@col_exists = 0, 'ALTER TABLE ethical_clearance ADD COLUMN file_surat_signed VARCHAR(255) NULL AFTER nomor_surat', 'SELECT "Column file_surat_signed already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. skripsi
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'skripsi' AND column_name = 'jenis_tugas_akhir');
SET @query := IF(@col_exists = 0, 'ALTER TABLE skripsi ADD COLUMN jenis_tugas_akhir ENUM("skripsi", "tesis", "disertasi") DEFAULT "skripsi" AFTER user_id', 'SELECT "Column jenis_tugas_akhir already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'skripsi' AND column_name = 'nama_pembimbing3');
SET @query := IF(@col_exists = 0, 'ALTER TABLE skripsi ADD COLUMN nama_pembimbing3 VARCHAR(150) NULL AFTER nama_pembimbing2', 'SELECT "Column nama_pembimbing3 already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. pengaturan
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'pengaturan' AND column_name = 'reset_at');
SET @query := IF(@col_exists = 0, 'ALTER TABLE pengaturan ADD COLUMN reset_at DATETIME NULL', 'SELECT "Column reset_at already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. skema_penelitian
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'skema_penelitian' AND column_name = 'urutan');
SET @query := IF(@col_exists = 0, 'ALTER TABLE skema_penelitian ADD COLUMN urutan INT DEFAULT 0', 'SELECT "Column urutan already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Final Verify
DESCRIBE ethical_clearance;
DESCRIBE skripsi;
DESCRIBE pengaturan;
DESCRIBE skema_penelitian;
