-- ============================================================
-- MIGRASI SKEMA DATABASE: MODUL PENGABDIAN KEPADA MASYARAKAT (PkM)
-- ============================================================

SET foreign_key_checks = 0;

-- 1. Pengaturan Dasar PkM
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
    ('pengabdian_tahun', YEAR(NOW())),
    ('pengabdian_nomor_pengumuman', ''),
    ('pengabdian_tgl_pengumuman', ''),
    ('pengabdian_tgl_buka', ''),
    ('pengabdian_deadline', ''),
    ('pengabdian_min_mahasiswa', '2'),
    ('pengabdian_pernyataan_poin', '[]');

-- 2. Tabel Skema Pengabdian
CREATE TABLE IF NOT EXISTS skema_pengabdian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(50) NOT NULL,
    nama VARCHAR(200) NOT NULL,
    tahun YEAR NOT NULL,
    target_luaran VARCHAR(255) NULL DEFAULT NULL,
    anggaran_total INT NOT NULL DEFAULT 0,
    anggaran_pengabdian INT NOT NULL DEFAULT 0,
    anggaran_publikasi INT NOT NULL DEFAULT 0,
    kuota INT NOT NULL DEFAULT 10,
    jabatan_min VARCHAR(50) NOT NULL DEFAULT 'asisten_ahli',
    min_anggota_dosen INT NOT NULL DEFAULT 0,
    max_anggota_dosen INT NULL DEFAULT NULL,
    anggota_dosen_wajib TINYINT(1) NOT NULL DEFAULT 0,
    min_anggota_mahasiswa INT NOT NULL DEFAULT 0,
    max_anggota_mahasiswa INT NULL DEFAULT NULL,
    anggota_mahasiswa_wajib TINYINT(1) NOT NULL DEFAULT 0,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    deskripsi TEXT NULL DEFAULT NULL,
    urutan INT NOT NULL DEFAULT 99,
    batas_luaran_bulan INT NOT NULL DEFAULT 24,
    jenis_luaran_wajib VARCHAR(255) NULL DEFAULT NULL,
    deadline_pengajuan DATETIME NULL DEFAULT NULL,
    deadline_laporan DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kode_tahun (kode, tahun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Usulan Pengabdian Utama
CREATE TABLE IF NOT EXISTS usulan_pengabdian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tahun_anggaran YEAR NOT NULL,
    skema VARCHAR(50) NOT NULL,
    judul VARCHAR(500) NOT NULL,
    abstrak TEXT NULL DEFAULT NULL,
    anggota_dosen JSON NULL DEFAULT NULL,
    anggota_mahasiswa JSON NULL DEFAULT NULL,
    anggota_mitra JSON NULL DEFAULT NULL,
    nama_ketua VARCHAR(255) NULL DEFAULT NULL,
    nidn_ketua VARCHAR(50) NULL DEFAULT NULL,
    jabatan_ketua VARCHAR(50) NULL DEFAULT NULL,
    google_scholar_ketua VARCHAR(255) NULL DEFAULT NULL,
    sinta_id_ketua VARCHAR(100) NULL DEFAULT NULL,
    file_proposal VARCHAR(300) NULL DEFAULT NULL,
    file_proposal_name VARCHAR(200) NULL DEFAULT NULL,
    file_proposal_size BIGINT NULL DEFAULT NULL,
    pernyataan_disetujui TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('draft','diajukan','seleksi_admin','lolos_admin','gagal_admin','perbaikan_admin','seleksi_substansi','perbaikan_substantif','disetujui','revisi_minor','revisi_mayor','ditolak','penandatanganan_kontrak','kontrak_aktif','laporan_diterima','selesai','ditinjau','direvisi') NOT NULL DEFAULT 'draft',
    catatan_reviewer TEXT NULL DEFAULT NULL,
    reviewed_by INT NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    lolos_admin TINYINT(1) NULL DEFAULT NULL,
    tgl_kontrak DATE NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_tahun (user_id, tahun_anggaran),
    INDEX idx_status (status),
    FULLTEXT INDEX ft_upg_judul_abstrak (judul, abstrak)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Seleksi Administratif
CREATE TABLE IF NOT EXISTS seleksi_admin_pengabdian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    admin_id INT NOT NULL,
    checklist JSON NULL DEFAULT NULL,
    keputusan ENUM('lolos','gagal') NOT NULL,
    catatan TEXT NULL DEFAULT NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usulan (usulan_id),
    INDEX idx_usulan_id (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Penugasan Reviewer
CREATE TABLE IF NOT EXISTS reviewer_assignment_pengabdian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_review DATETIME DEFAULT NULL,
    catatan_admin VARCHAR(500) DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_usulan_reviewer (usulan_id, reviewer_id),
    INDEX idx_usulan_id (usulan_id),
    INDEX idx_reviewer_id (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel Penilaian Reviewer Substantif
CREATE TABLE IF NOT EXISTS reviewer_penilaian_pengabdian (
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
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_assignment (assignment_id),
    INDEX idx_assignment_id (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel Kontrak Pengabdian
CREATE TABLE IF NOT EXISTS kontrak_pengabdian (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usulan_id INT NOT NULL,
    nomor_kontrak VARCHAR(150) NULL DEFAULT NULL,
    tgl_kontrak DATE NULL DEFAULT NULL,
    deadline_laporan DATETIME NULL DEFAULT NULL,
    file_kontrak VARCHAR(300) NULL DEFAULT NULL,
    file_kontrak_name VARCHAR(200) NULL DEFAULT NULL,
    file_kontrak_signed VARCHAR(300) NULL DEFAULT NULL,
    file_kontrak_signed_name VARCHAR(200) NULL DEFAULT NULL,
    uploaded_at DATETIME NULL DEFAULT NULL,
    uploaded_signed_at DATETIME NULL DEFAULT NULL,
    admin_id INT NULL DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usulan (usulan_id),
    INDEX idx_usulan_id (usulan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;