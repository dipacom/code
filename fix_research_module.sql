-- Fix research modules and schemes (MySQL compatible)
USE lppm_iakntoraja;

-- 1. Fix skema_penelitian table structure
SET @db = 'lppm_iakntoraja';
SET @tbl = 'skema_penelitian';

DROP PROCEDURE IF EXISTS AddCol;
DELIMITER //
CREATE PROCEDURE AddCol(IN col_name VARCHAR(64), IN col_type VARCHAR(255), IN after_col VARCHAR(64))
BEGIN
    SET @s = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = @tbl AND column_name = col_name);
    IF @s = 0 THEN
        SET @query = CONCAT('ALTER TABLE ', @tbl, ' ADD COLUMN ', col_name, ' ', col_type, ' AFTER ', after_col);
        PREPARE stmt FROM @query;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL AddCol('target_publikasi', 'VARCHAR(255) NULL', 'nama');
CALL AddCol('anggaran_total', 'BIGINT DEFAULT 0', 'target_publikasi');
CALL AddCol('anggaran_penelitian', 'BIGINT DEFAULT 0', 'anggaran_total');
CALL AddCol('anggaran_publikasi', 'BIGINT DEFAULT 0', 'anggaran_penelitian');
CALL AddCol('kuota', 'INT DEFAULT 10', 'anggaran_publikasi');
CALL AddCol('jabatan_min', 'ENUM("asisten_ahli", "lektor", "lektor_kepala", "guru_besar") DEFAULT "asisten_ahli"', 'kuota');
CALL AddCol('batas_similarity', 'DECIMAL(5,2) DEFAULT 25.00', 'jabatan_min');
CALL AddCol('batas_ai', 'DECIMAL(5,2) DEFAULT 30.00', 'batas_similarity');
CALL AddCol('min_anggota_dosen', 'INT DEFAULT 0', 'batas_ai');
CALL AddCol('max_anggota_dosen', 'INT NULL', 'min_anggota_dosen');
CALL AddCol('anggota_dosen_wajib', 'TINYINT(1) DEFAULT 0', 'max_anggota_dosen');
CALL AddCol('min_anggota_mahasiswa', 'INT DEFAULT 0', 'anggota_dosen_wajib');
CALL AddCol('max_anggota_mahasiswa', 'INT NULL', 'min_anggota_mahasiswa');
CALL AddCol('anggota_mahasiswa_wajib', 'TINYINT(1) DEFAULT 0', 'max_anggota_mahasiswa');
CALL AddCol('is_open', 'TINYINT(1) DEFAULT 1', 'anggota_mahasiswa_wajib');

DROP PROCEDURE AddCol;

-- 2. Seed default schemes for 2026
INSERT IGNORE INTO skema_penelitian (kode, nama, tahun, is_open, urutan, jabatan_min, target_publikasi, anggaran_total) VALUES
('pdp', 'Penelitian Dosen Pemula', 2026, 1, 1, 'asisten_ahli', 'Jurnal Nasional Sinta 3-6', 15000000),
('pd', 'Penelitian Dasar', 2026, 1, 2, 'lektor', 'Jurnal Nasional Sinta 1-2', 25000000),
('pt', 'Penelitian Terapan', 2026, 1, 3, 'lektor', 'Jurnal Internasional Bereputasi', 40000000),
('pkm', 'Pengabdian kepada Masyarakat', 2026, 1, 4, 'asisten_ahli', 'Laporan Kemajuan & Jurnal PkM', 10000000);

-- 3. Fix usulan_penelitian table structure
SET @tbl = 'usulan_penelitian';

DROP PROCEDURE IF EXISTS AddColProp;
DELIMITER //
CREATE PROCEDURE AddColProp(IN col_name VARCHAR(64), IN col_type VARCHAR(255), IN after_col VARCHAR(64))
BEGIN
    SET @s = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = @tbl AND column_name = col_name);
    IF @s = 0 THEN
        SET @query = CONCAT('ALTER TABLE ', @tbl, ' ADD COLUMN ', col_name, ' ', col_type, ' AFTER ', after_col);
        PREPARE stmt FROM @query;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Change skema type first
ALTER TABLE usulan_penelitian MODIFY COLUMN skema VARCHAR(50) NOT NULL;

CALL AddColProp('anggota_mitra', 'TEXT NULL', 'anggota_mahasiswa');
CALL AddColProp('nama_ketua', 'VARCHAR(150) NULL', 'anggota_mitra');
CALL AddColProp('nidn_ketua', 'VARCHAR(20) NULL', 'nama_ketua');
CALL AddColProp('platform_ai_mandiri', 'VARCHAR(100) NULL', 'platform_mandiri');

DROP PROCEDURE AddColProp;

-- Verify
DESCRIBE skema_penelitian;
DESCRIBE usulan_penelitian;
SELECT * FROM skema_penelitian;
