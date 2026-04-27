-- =====================================================
-- FIX SCHEMA: lppm_iakntoraja (MariaDB 10.4 compatible)
-- Menambah kolom & tabel yang hilang TANPA menghapus data
-- =====================================================

SET foreign_key_checks = 0;

-- =====================================================
-- FIX 1: Tabel USERS — tambah kolom yang hilang
-- =====================================================

-- foto_profil (PENYEBAB UTAMA crash dashboard)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'foto_profil');
SET @sql := IF(@col = 0,
    'ALTER TABLE users ADD COLUMN foto_profil VARCHAR(255) NULL DEFAULT NULL',
    'SELECT "users.foto_profil sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- last_login
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login');
SET @sql := IF(@col = 0,
    'ALTER TABLE users ADD COLUMN last_login DATETIME NULL DEFAULT NULL',
    'SELECT "users.last_login sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- deleted_at (untuk soft delete pengguna)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    'SELECT "users.deleted_at sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- FIX 2: Tabel SKRIPSI — tambah kolom yang hilang
-- =====================================================

-- deleted_at (dibutuhkan dashboard line 108: WHERE s.deleted_at IS NULL)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'skripsi' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE skripsi ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    'SELECT "skripsi.deleted_at sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- FIX 3: Tabel PUBLIKASI — tambah kolom yang hilang
-- =====================================================

-- deleted_at (dibutuhkan dashboard line 117: WHERE p.deleted_at IS NULL)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publikasi' AND COLUMN_NAME = 'deleted_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE publikasi ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL',
    'SELECT "publikasi.deleted_at sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_score
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publikasi' AND COLUMN_NAME = 'ai_score');
SET @sql := IF(@col = 0,
    'ALTER TABLE publikasi ADD COLUMN ai_score DECIMAL(5,2) NULL DEFAULT NULL',
    'SELECT "publikasi.ai_score sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- platform_ai
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'publikasi' AND COLUMN_NAME = 'platform_ai');
SET @sql := IF(@col = 0,
    'ALTER TABLE publikasi ADD COLUMN platform_ai VARCHAR(100) NULL DEFAULT NULL',
    'SELECT "publikasi.platform_ai sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- selesai (status enum fix - tambahkan 'selesai' jika belum ada)
-- Cukup aman karena enum extend tidak menghapus data lama

-- =====================================================
-- FIX 4: Tabel ETHICAL_CLEARANCE (BARU - dashboard line 46)
-- =====================================================
CREATE TABLE IF NOT EXISTS ethical_clearance (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul_penelitian VARCHAR(500) NOT NULL,
    jenis_penelitian VARCHAR(100) NULL DEFAULT NULL,
    melibatkan_subjek_manusia TINYINT(1) NOT NULL DEFAULT 0,
    lokasi_penelitian VARCHAR(255) NULL DEFAULT NULL,
    tgl_mulai DATE NULL DEFAULT NULL,
    tgl_selesai DATE NULL DEFAULT NULL,
    abstrak TEXT NULL DEFAULT NULL,
    file_surat_permohonan VARCHAR(300) NULL DEFAULT NULL,
    file_surat_permohonan_name VARCHAR(200) NULL DEFAULT NULL,
    file_surat_pernyataan VARCHAR(300) NULL DEFAULT NULL,
    file_surat_pernyataan_name VARCHAR(200) NULL DEFAULT NULL,
    file_persetujuan_subjek VARCHAR(300) NULL DEFAULT NULL,
    file_persetujuan_subjek_name VARCHAR(200) NULL DEFAULT NULL,
    file_proposal VARCHAR(300) NULL DEFAULT NULL,
    file_proposal_name VARCHAR(200) NULL DEFAULT NULL,
    status ENUM('menunggu','diproses','disetujui','ditolak') NOT NULL DEFAULT 'menunggu',
    nomor_surat VARCHAR(100) NULL DEFAULT NULL,
    catatan_admin TEXT NULL DEFAULT NULL,
    reviewed_by INT NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 5: Tabel SKEMA_PENELITIAN (BARU - dashboard line 90)
-- =====================================================
CREATE TABLE IF NOT EXISTS skema_penelitian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(50) NOT NULL,
    nama VARCHAR(200) NOT NULL,
    tahun YEAR NOT NULL,
    deskripsi TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kode_tahun (kode, tahun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 6: Tabel REVISI_PROPOSAL (BARU - penelitian_reviewer.php)
-- =====================================================
CREATE TABLE IF NOT EXISTS revisi_proposal (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    user_id INT NOT NULL,
    tipe ENUM('substantif','administratif') NOT NULL DEFAULT 'substantif',
    round_ke INT NOT NULL DEFAULT 1,
    status_sebelum VARCHAR(50) NULL DEFAULT NULL,
    file_proposal VARCHAR(300) NULL DEFAULT NULL,
    file_proposal_name VARCHAR(200) NULL DEFAULT NULL,
    ringkasan TEXT NULL DEFAULT NULL,
    respon_isu TEXT NULL DEFAULT NULL,
    keputusan ENUM('diterima','dikembalikan') NULL DEFAULT NULL,
    admin_catatan TEXT NULL DEFAULT NULL,
    keputusan_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usulan_id (usulan_id),
    INDEX idx_user_id (user_id),
    INDEX idx_tipe (tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 7: Tabel REVIEWER_ASSIGNMENT (BARU)
-- =====================================================
CREATE TABLE IF NOT EXISTS reviewer_assignment (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usulan_reviewer (usulan_id, reviewer_id),
    INDEX idx_usulan_id (usulan_id),
    INDEX idx_reviewer_id (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 8: Tabel REVIEWER_PENILAIAN (BARU)
-- =====================================================
CREATE TABLE IF NOT EXISTS reviewer_penilaian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    skor_1 TINYINT UNSIGNED NULL DEFAULT NULL,
    skor_2 TINYINT UNSIGNED NULL DEFAULT NULL,
    skor_3 TINYINT UNSIGNED NULL DEFAULT NULL,
    skor_4 TINYINT UNSIGNED NULL DEFAULT NULL,
    skor_5 TINYINT UNSIGNED NULL DEFAULT NULL,
    skor_6 TINYINT UNSIGNED NULL DEFAULT NULL,
    nilai_total DECIMAL(6,2) NULL DEFAULT NULL,
    keputusan ENUM('disetujui','revisi_minor','revisi_mayor','ditolak') NULL DEFAULT NULL,
    saran TEXT NULL DEFAULT NULL,
    file_review VARCHAR(300) NULL DEFAULT NULL,
    file_review_name VARCHAR(200) NULL DEFAULT NULL,
    submitted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_assignment (assignment_id),
    INDEX idx_assignment_id (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 9: Tabel LOGS (BARU - dibutuhkan logger.php / sampah.php)
-- =====================================================
CREATE TABLE IF NOT EXISTS logs (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL DEFAULT NULL,
    role VARCHAR(50) NULL DEFAULT NULL,
    aksi VARCHAR(100) NOT NULL,
    deskripsi TEXT NULL DEFAULT NULL,
    ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_aksi (aksi),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FIX 10: Kolom tambahan di USULAN_PENELITIAN
-- =====================================================
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'usulan_penelitian' AND COLUMN_NAME = 'lolos_admin');
SET @sql := IF(@col = 0,
    'ALTER TABLE usulan_penelitian ADD COLUMN lolos_admin TINYINT(1) NULL DEFAULT NULL',
    'SELECT "usulan_penelitian.lolos_admin sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'usulan_penelitian' AND COLUMN_NAME = 'tgl_kontrak');
SET @sql := IF(@col = 0,
    'ALTER TABLE usulan_penelitian ADD COLUMN tgl_kontrak DATE NULL DEFAULT NULL',
    'SELECT "usulan_penelitian.tgl_kontrak sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- FIX 11: Kolom tambahan di PENGATURAN jika belum ada
-- =====================================================
-- Tambah default settings yang dibutuhkan aplikasi
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
    ('penerimaan_plagiasi',   'buka'),
    ('penerimaan_publikasi',  'buka'),
    ('penerimaan_ec',         'buka'),
    ('batas_similarity',      '20'),
    ('batas_ai',              '20'),
    ('max_upload_mb',         '20'),
    ('penelitian_tahun',      YEAR(NOW())),
    ('prefix_surat_plagiasi', 'LPPM-IAKN-PL'),
    ('prefix_surat_publikasi','LPPM-IAKN-PB'),
    ('prefix_surat_ec',       'LPPM/IAKN-T/LoEA'),
    ('nama_institusi',        'Institut Agama Kristen Negeri (IAKN) Toraja'),
    ('nama_lppm',             'Lembaga Penelitian dan Pengabdian kepada Masyarakat (LPPM)'),
    ('alamat_institusi',      'Jl. Nusantara No. 1, Makale, Tana Toraja, Sulawesi Selatan'),
    ('nama_ketua_lppm',       ''),
    ('nip_ketua_lppm',        ''),
    ('email_lppm',            ''),
    ('ec_nama_penandatangan', ''),
    ('ec_nip_penandatangan',  ''),
    ('ec_jabatan_penandatangan', 'Head of Research Unit LPPM'),
    ('lulus_threshold',       '60');

SET foreign_key_checks = 1;

-- =====================================================
-- VERIFIKASI AKHIR
-- =====================================================
SELECT 'TABEL YANG ADA:' AS hasil;
SHOW TABLES;

SELECT 'KOLOM USERS:' AS hasil;
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
ORDER BY ORDINAL_POSITION;

SELECT '✅ FIX SELESAI! Dashboard seharusnya sudah bisa diakses.' AS PESAN_AKHIR;
