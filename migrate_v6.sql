-- ============================================================
-- MIGRASI v6 — Field kesesuaian PkM + seed skema mandiri/kompetitif/hibah
-- ============================================================

SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = '';

-- Helper: tambah kolom jika belum ada
DROP PROCEDURE IF EXISTS lppm_add_col;
DELIMITER $$
CREATE PROCEDURE lppm_add_col(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_col) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- Tambah 3 kolom kesesuaian PkM
CALL lppm_add_col('usulan_pengabdian', 'kesesuaian_bidang',
  'TEXT DEFAULT NULL COMMENT ''Kesesuaian usulan pengabdian dengan bidang ilmu''');
CALL lppm_add_col('usulan_pengabdian', 'kontribusi_prodi',
  'TEXT DEFAULT NULL COMMENT ''Kontribusi pengabdian bagi pengembangan program studi''');
CALL lppm_add_col('usulan_pengabdian', 'kesesuaian_roadmap',
  'TEXT DEFAULT NULL COMMENT ''Kesesuaian pengabdian dengan road map pengabdian prodi''');

DROP PROCEDURE IF EXISTS lppm_add_col;

-- Seed 3 skema PkM default jika belum ada (mandiri, kompetitif, hibah)
INSERT INTO skema_pengabdian
  (kode, nama, target_publikasi, anggaran_total, anggaran_penelitian, anggaran_publikasi,
   kuota, jabatan_min, batas_similarity, batas_ai,
   min_anggota_dosen, max_anggota_dosen, anggota_dosen_wajib,
   min_anggota_mahasiswa, max_anggota_mahasiswa, anggota_mahasiswa_wajib,
   is_open, deskripsi, urutan, tahun, batas_luaran_bulan, jenis_luaran_wajib, kontrak_wajib)
SELECT * FROM (
  SELECT 'mandiri'    AS kode, 'PkM Mandiri'    AS nama, 'Artikel Jurnal Nasional / Prosiding' AS target_publikasi,
         0 AS at, 0 AS ap, 0 AS apc, 50 AS kuota, 'asisten_ahli' AS jab,
         25.0 AS bs, 30.0 AS ba, 0 AS mind, NULL AS maxd, 0 AS dwjb,
         0 AS minm, 5 AS maxm, 0 AS mwjb, 1 AS isopen,
         'Skema pengabdian mandiri (self-funded). Dosen menggunakan dana pribadi. Tetap melalui registrasi LPPM untuk dokumentasi & sertifikasi.' AS desk,
         1 AS urut, YEAR(CURDATE()) AS th, 24 AS blr,
         'Artikel Jurnal Nasional ber-ISSN atau Prosiding Konferensi' AS jlw,
         0 AS kw  -- mandiri: kontrak opsional
  UNION ALL
  SELECT 'kompetitif',  'PkM Kompetitif', 'Artikel Jurnal Sinta 3-4 / Prosiding Internasional',
         15000000, 12000000, 3000000, 20, 'asisten_ahli',
         25.0, 30.0, 1, 3, 0, 1, 5, 0, 1,
         'Skema pengabdian kompetitif (PkM Kompetitif). Pendanaan internal LPPM melalui kompetisi.',
         2, YEAR(CURDATE()), 24,
         'Artikel Jurnal Sinta 3-4 atau Prosiding Internasional', 1
  UNION ALL
  SELECT 'hibah',       'PkM Hibah',      'Artikel Jurnal Sinta 1-2 / Scopus Q3-Q4',
         50000000, 42000000, 8000000, 5, 'lektor',
         20.0, 25.0, 2, 4, 1, 2, 5, 1, 1,
         'Skema pengabdian hibah (PkM Hibah). Pendanaan eksternal/Diktis dengan kontrak ketat.',
         3, YEAR(CURDATE()), 36,
         'Artikel Jurnal Sinta 1-2 atau Scopus Q3-Q4', 1
) src
WHERE NOT EXISTS (
  SELECT 1 FROM skema_pengabdian sp
  WHERE sp.kode COLLATE utf8mb4_unicode_ci = src.kode COLLATE utf8mb4_unicode_ci
    AND sp.tahun = src.th
);

SET SQL_MODE = @OLD_SQL_MODE;
SELECT 'Migrasi v6 selesai.' AS status;
SELECT kode, nama, kontrak_wajib, is_open FROM skema_pengabdian ORDER BY urutan;
