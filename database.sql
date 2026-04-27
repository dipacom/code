-- ============================================================
-- DATABASE: SISTEM LPPM IAKN TORAJA
-- Dibuat untuk: Surat Keterangan Bebas Plagiasi & Publikasi
-- ============================================================

CREATE DATABASE IF NOT EXISTS lppm_iakntoraja CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lppm_iakntoraja;

-- ------------------------------------------------------------
-- TABEL: ref_fakultas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_fakultas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    urutan INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: ref_program_studi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_program_studi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fakultas_id INT NOT NULL,
    nama VARCHAR(150) NOT NULL,
    urutan INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (fakultas_id) REFERENCES ref_fakultas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: users (mahasiswa, dosen, admin, reviewer)
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('mahasiswa','dosen','admin','reviewer') DEFAULT 'mahasiswa',
    nim VARCHAR(20) NULL COMMENT 'Khusus mahasiswa',
    nidn VARCHAR(20) NULL COMMENT 'Khusus dosen',
    nip VARCHAR(20) NULL COMMENT 'Khusus dosen (opsional)',
    fakultas VARCHAR(100) NULL,
    program_studi VARCHAR(100) NULL,
    angkatan YEAR NULL,
    no_hp VARCHAR(20) NULL,
    jabatan_fungsional ENUM('asisten_ahli','lektor','lektor_kepala','guru_besar') NULL,
    google_scholar VARCHAR(255) NULL,
    sinta_id VARCHAR(100) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: skripsi
-- ------------------------------------------------------------
CREATE TABLE skripsi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul_skripsi TEXT NOT NULL,
    nama_pembimbing1 VARCHAR(150) NULL,
    nama_pembimbing2 VARCHAR(150) NULL,
    tahun_sidang YEAR NULL,
    file_path VARCHAR(255) NOT NULL COMMENT 'Path file PDF skripsi',
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL COMMENT 'Ukuran file dalam bytes',
    status ENUM('menunggu','diproses','selesai','ditolak') DEFAULT 'menunggu',
    catatan_admin TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: cek_plagiasi
-- ------------------------------------------------------------
CREATE TABLE cek_plagiasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    skripsi_id INT NOT NULL,
    admin_id INT NOT NULL COMMENT 'Admin yang menginput hasil',
    similarity_score DECIMAL(5,2) NOT NULL COMMENT 'Persentase similarity dari Turnitin',
    tanggal_cek DATE NOT NULL,
    screenshot_path VARCHAR(255) NULL COMMENT 'Opsional: screenshot hasil Turnitin',
    catatan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (skripsi_id) REFERENCES skripsi(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: surat_plagiasi
-- ------------------------------------------------------------
CREATE TABLE surat_plagiasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cek_plagiasi_id INT NOT NULL,
    nomor_surat VARCHAR(100) NOT NULL UNIQUE,
    tanggal_surat DATE NOT NULL,
    nama_penandatangan VARCHAR(150) NOT NULL,
    jabatan_penandatangan VARCHAR(150) NOT NULL,
    file_pdf_path VARCHAR(255) NULL,
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cek_plagiasi_id) REFERENCES cek_plagiasi(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: publikasi
-- ------------------------------------------------------------
CREATE TABLE publikasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul_publikasi TEXT NOT NULL,
    jenis_publikasi ENUM('jurnal','book_chapter','buku','prosiding') NOT NULL,
    nama_jurnal_penerbit VARCHAR(255) NULL COMMENT 'Nama jurnal / penerbit / konferensi',
    tahun_terbit YEAR NULL,
    url_doi VARCHAR(500) NULL,
    issn_isbn VARCHAR(50) NULL,
    akreditasi_jurnal ENUM('sinta1','sinta2','sinta3','sinta4','sinta5','sinta6','scopusQ1','scopusQ2','scopusQ3') NULL COMMENT 'Akreditasi jurnal — hanya diisi untuk jenis jurnal',
    file_path VARCHAR(255) NOT NULL COMMENT 'Path file bukti publikasi',
    file_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    status ENUM('menunggu','diverifikasi','ditolak') DEFAULT 'menunggu',
    catatan_admin TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: surat_publikasi
-- ------------------------------------------------------------
CREATE TABLE surat_publikasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    publikasi_id INT NOT NULL,
    admin_id INT NOT NULL,
    nomor_surat VARCHAR(100) NOT NULL UNIQUE,
    tanggal_surat DATE NOT NULL,
    nama_penandatangan VARCHAR(150) NOT NULL,
    jabatan_penandatangan VARCHAR(150) NOT NULL,
    file_pdf_path VARCHAR(255) NULL,
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (publikasi_id) REFERENCES publikasi(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: notifikasi
-- ------------------------------------------------------------
CREATE TABLE notifikasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul VARCHAR(200) NOT NULL,
    pesan TEXT NOT NULL,
    tipe ENUM('info','sukses','peringatan','error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: pengaturan (konfigurasi surat)
-- ------------------------------------------------------------
CREATE TABLE pengaturan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kunci VARCHAR(100) NOT NULL UNIQUE,
    nilai TEXT NOT NULL,
    keterangan VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABEL: surat_counter
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS surat_counter (
    tabel VARCHAR(50) NOT NULL,
    tahun YEAR NOT NULL,
    counter INT DEFAULT 0,
    PRIMARY KEY (tabel, tahun)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DATA AWAL: Admin & Pengaturan Default
-- ------------------------------------------------------------
INSERT INTO users (nama_lengkap, email, password, role) VALUES
('Admin LPPM', 'lppm@iakntoraja.ac.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
-- Password default: password

INSERT INTO pengaturan (kunci, nilai, keterangan) VALUES
('nama_institusi', 'Institut Agama Kristen Negeri (IAKN) Toraja', 'Nama lengkap institusi'),
('nama_lppm', 'Lembaga Penelitian dan Pengabdian kepada Masyarakat', 'Nama LPPM'),
('alamat_institusi', 'Jl. Poros Makale - Rantepao, Toraja Utara, Sulawesi Selatan', 'Alamat kampus'),
('nama_ketua_lppm', 'Ketua LPPM', 'Nama ketua LPPM'),
('nip_ketua_lppm', 'NIP. -', 'NIP ketua LPPM'),
('batas_similarity', '20', 'Batas maksimal persentase similarity (%)'),
('prefix_surat_plagiasi', 'LPPM-IAKN-PL', 'Prefix nomor surat bebas plagiasi'),
('prefix_surat_publikasi', 'LPPM-IAKN-PB', 'Prefix nomor surat publikasi'),
('email_lppm', 'lppm@iakntoraja.ac.id', 'Email LPPM untuk notifikasi'),
('max_upload_mb', '20', 'Maksimal ukuran file upload dalam MB');

-- ------------------------------------------------------------
-- DATA AWAL: Fakultas & Prodi (IAKN Toraja)
-- ------------------------------------------------------------
INSERT INTO ref_fakultas (nama, urutan) VALUES 
('Fakultas Keguruan dan Ilmu Pendidikan Kristen', 1),
('Fakultas Teologi', 2),
('Fakultas Budaya dan Bisnis Kristen', 3);

INSERT INTO ref_program_studi (fakultas_id, nama, urutan) VALUES 
(1, 'Pendidikan Agama Kristen', 1),
(1, 'Pendidikan Kristen Anak Usia Dini', 2),
(2, 'Teologi', 1),
(2, 'Pastoral Konseling', 2),
(3, 'Musik Gereja', 1),
(3, 'Pariwisata Budaya dan Keagamaan', 2),
(3, 'Sosiologi Agama', 3);
