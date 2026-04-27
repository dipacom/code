-- ============================================================
-- MIGRASI: Modul Usulan Penelitian
-- Jalankan sekali di phpMyAdmin atau terminal MySQL
-- ============================================================

-- ── Tabel usulan penelitian ───────────────────────────────────
CREATE TABLE IF NOT EXISTS usulan_penelitian (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    tahun_anggaran      YEAR NOT NULL,
    skema               ENUM('global','nasional') NOT NULL
                        COMMENT 'global = Bereputasi Global, nasional = Bereputasi Nasional',
    judul               VARCHAR(500) NOT NULL,
    abstrak             TEXT NULL,

    -- Tim peneliti (JSON array)
    anggota_dosen       TEXT NULL
                        COMMENT 'JSON: [{nama,nidn,jabatan,prodi,peran}]',
    anggota_mahasiswa   TEXT NULL
                        COMMENT 'JSON: [{nama,nim,prodi,semester}]',

    -- Data ketua saat submit
    jabatan_ketua       VARCHAR(50) NULL,
    google_scholar_ketua VARCHAR(255) NULL,
    sinta_id_ketua      VARCHAR(100) NULL,

    -- File proposal
    file_proposal       VARCHAR(300) NULL,
    file_proposal_name  VARCHAR(200) NULL,
    file_proposal_size  BIGINT NULL,

    -- Self-check similarity & AI
    similarity_mandiri  DECIMAL(5,2) NULL,
    ai_mandiri          DECIMAL(5,2) NULL,
    platform_mandiri    VARCHAR(100) NULL,
    file_cek_mandiri    VARCHAR(300) NULL,

    -- Pernyataan
    pernyataan_disetujui TINYINT(1) DEFAULT 0,

    -- Status review
    status              ENUM('draft','diajukan','ditinjau','disetujui','direvisi','ditolak')
                        DEFAULT 'draft',
    catatan_reviewer    TEXT NULL,
    reviewed_by         INT NULL,
    reviewed_at         DATETIME NULL,
    deleted_at          DATETIME NULL,

    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Pengaturan modul penelitian ───────────────────────────────
INSERT IGNORE INTO pengaturan (kunci, nilai, keterangan) VALUES
('penelitian_global_buka',    'buka',       'Status penerimaan skema Publikasi Bereputasi Global (buka/tutup)'),
('penelitian_nasional_buka',  'buka',       'Status penerimaan skema Publikasi Bereputasi Nasional (buka/tutup)'),
('penelitian_deadline',       '2026-05-18', 'Batas akhir pengumpulan proposal (YYYY-MM-DD)'),
('penelitian_tahun',          '2026',       'Tahun anggaran penelitian aktif');
