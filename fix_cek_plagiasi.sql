-- Patch for cek_plagiasi table
USE lppm_iakntoraja;

-- ai_score
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'lppm_iakntoraja'
    AND TABLE_NAME = 'cek_plagiasi' AND COLUMN_NAME = 'ai_score');
SET @sql := IF(@col = 0,
    'ALTER TABLE cek_plagiasi ADD COLUMN ai_score DECIMAL(5,2) NULL DEFAULT NULL AFTER similarity_score',
    'SELECT "cek_plagiasi.ai_score sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- platform_ai
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'lppm_iakntoraja'
    AND TABLE_NAME = 'cek_plagiasi' AND COLUMN_NAME = 'platform_ai');
SET @sql := IF(@col = 0,
    'ALTER TABLE cek_plagiasi ADD COLUMN platform_ai VARCHAR(100) NULL DEFAULT NULL AFTER ai_score',
    'SELECT "cek_plagiasi.platform_ai sudah ada" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- verify
DESCRIBE cek_plagiasi;
