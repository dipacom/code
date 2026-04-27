-- Create missing tables for LPPM
USE lppm_iakntoraja;

-- 1. pesan (Chat)
CREATE TABLE IF NOT EXISTS pesan (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pengirim_role ENUM('user', 'admin') NOT NULL,
    isi TEXT NOT NULL,
    dibaca TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_dibaca (dibaca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. activity_log (Logging)
-- Check if logs exists to rename, else create
SET @exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'logs');
SET @query := IF(@exists > 0, 'RENAME TABLE logs TO activity_log', 'SELECT "Table logs not found, creating activity_log" AS status');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    nama_lengkap VARCHAR(150) NULL,
    role VARCHAR(50) NULL,
    action_type VARCHAR(80) NOT NULL,
    detail TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If we renamed logs, we might need to fix column names
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'lppm_iakntoraja' AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'aksi');
SET @query := IF(@col_exists > 0, 'ALTER TABLE activity_log CHANGE COLUMN aksi action_type VARCHAR(80), CHANGE COLUMN deskripsi detail TEXT, CHANGE COLUMN ip ip_address VARCHAR(45), ADD COLUMN nama_lengkap VARCHAR(150) AFTER user_id', 'SELECT "Columns already adjusted"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. seleksi_admin
CREATE TABLE IF NOT EXISTS seleksi_admin (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    admin_id INT NOT NULL,
    checklist TEXT NULL,
    keputusan VARCHAR(50) NULL,
    catatan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usulan_id (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. kontrak_penelitian
CREATE TABLE IF NOT EXISTS kontrak_penelitian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    nomor_kontrak VARCHAR(100) NULL,
    tgl_kontrak DATE NULL,
    file_kontrak VARCHAR(255) NULL,
    file_kontrak_name VARCHAR(255) NULL,
    admin_id INT NULL,
    deadline_laporan DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_usulan_id (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure uploaded_at exists in kontrak_penelitian
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'kontrak_penelitian' AND column_name = 'uploaded_at');
SET @query := IF(@exists = 0, 'ALTER TABLE kontrak_penelitian ADD COLUMN uploaded_at DATETIME NULL AFTER admin_id', 'SELECT "Column uploaded_at already exists"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. laporan_penelitian
CREATE TABLE IF NOT EXISTS laporan_penelitian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    user_id INT NOT NULL,
    file_laporan VARCHAR(255) NULL,
    status VARCHAR(50) NULL DEFAULT 'menunggu',
    catatan_admin TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_usulan_id (usulan_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure missing columns exist in laporan_penelitian
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'lppm_iakntoraja' AND table_name = 'laporan_penelitian' AND column_name = 'file_laporan_name');
SET @query := IF(@exists = 0, 'ALTER TABLE laporan_penelitian ADD COLUMN file_laporan_name VARCHAR(255) NULL AFTER file_laporan, ADD COLUMN file_laporan_size BIGINT NULL AFTER file_laporan_name, ADD COLUMN tanggal_submit DATETIME NULL AFTER file_laporan_size', 'SELECT "Columns already exist in laporan_penelitian"');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Final Verify
SHOW TABLES;

