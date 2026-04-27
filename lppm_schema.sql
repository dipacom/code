-- MySQL dump 10.13  Distrib 9.6.0, for macos26.3 (arm64)
--
-- Host: localhost    Database: lppm_iakntoraja
-- ------------------------------------------------------
-- Server version	9.6.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;

--
-- GTID state at the beginning of the backup 
--

SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '1b896050-37b2-11f1-92ed-df739fc4f78b:1-180';

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `nama_lengkap` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detail` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_aksi` (`action_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cek_plagiasi`
--

DROP TABLE IF EXISTS `cek_plagiasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cek_plagiasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `skripsi_id` int NOT NULL,
  `admin_id` int NOT NULL COMMENT 'Admin yang menginput hasil',
  `similarity_score` decimal(5,2) NOT NULL COMMENT 'Persentase similarity dari Turnitin',
  `ai_score` decimal(5,2) DEFAULT NULL,
  `platform_ai` varchar(100) DEFAULT NULL,
  `tanggal_cek` date NOT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL COMMENT 'Opsional: screenshot hasil Turnitin',
  `catatan` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `skripsi_id` (`skripsi_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `cek_plagiasi_ibfk_1` FOREIGN KEY (`skripsi_id`) REFERENCES `skripsi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cek_plagiasi_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ethical_clearance`
--

DROP TABLE IF EXISTS `ethical_clearance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ethical_clearance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `judul_penelitian` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_jurnal` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jenis_penelitian` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `melibatkan_subjek_manusia` tinyint(1) NOT NULL DEFAULT '0',
  `lokasi_penelitian` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tgl_mulai` date DEFAULT NULL,
  `tgl_selesai` date DEFAULT NULL,
  `abstrak` text COLLATE utf8mb4_unicode_ci,
  `file_surat_permohonan` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_surat_permohonan_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_surat_pernyataan` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_surat_pernyataan_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_persetujuan_subjek` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_persetujuan_subjek_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_proposal` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_proposal_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('menunggu','diproses','disetujui','ditolak') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menunggu',
  `nomor_surat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_surat_signed` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan_admin` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `tanggal_proses` date DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kontrak_penelitian`
--

DROP TABLE IF EXISTS `kontrak_penelitian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kontrak_penelitian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usulan_id` int NOT NULL,
  `nomor_kontrak` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tgl_kontrak` date DEFAULT NULL,
  `file_kontrak` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_kontrak_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `deadline_laporan` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usulan_id` (`usulan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `laporan_penelitian`
--

DROP TABLE IF EXISTS `laporan_penelitian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laporan_penelitian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usulan_id` int NOT NULL,
  `user_id` int NOT NULL,
  `file_laporan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_laporan_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_laporan_size` bigint DEFAULT NULL,
  `tanggal_submit` datetime DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan_admin` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usulan_id` (`usulan_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifikasi`
--

DROP TABLE IF EXISTS `notifikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifikasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `judul` varchar(200) NOT NULL,
  `pesan` text NOT NULL,
  `tipe` enum('info','sukses','peringatan','error') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifikasi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pengaturan`
--

DROP TABLE IF EXISTS `pengaturan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pengaturan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kunci` varchar(100) NOT NULL,
  `nilai` text NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `reset_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kunci` (`kunci`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pesan`
--

DROP TABLE IF EXISTS `pesan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pesan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `pengirim_role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `isi` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `dibaca` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_dibaca` (`dibaca`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `publikasi`
--

DROP TABLE IF EXISTS `publikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `publikasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `judul_publikasi` text NOT NULL,
  `jenis_publikasi` enum('jurnal','book_chapter','buku','prosiding') NOT NULL,
  `nama_jurnal_penerbit` varchar(255) DEFAULT NULL COMMENT 'Nama jurnal / penerbit / konferensi',
  `tahun_terbit` year DEFAULT NULL,
  `url_doi` varchar(500) DEFAULT NULL,
  `issn_isbn` varchar(50) DEFAULT NULL,
  `akreditasi_jurnal` enum('sinta1','sinta2','sinta3','sinta4','sinta5','sinta6','scopusQ1','scopusQ2','scopusQ3') DEFAULT NULL COMMENT 'Akreditasi jurnal â€” hanya diisi untuk jenis jurnal',
  `file_path` varchar(255) NOT NULL COMMENT 'Path file bukti publikasi',
  `file_name` varchar(255) NOT NULL,
  `file_size` int NOT NULL,
  `status` enum('menunggu','diverifikasi','ditolak') DEFAULT 'menunggu',
  `catatan_admin` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `ai_score` decimal(5,2) DEFAULT NULL,
  `platform_ai` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `publikasi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ref_fakultas`
--

DROP TABLE IF EXISTS `ref_fakultas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ref_fakultas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama` varchar(150) NOT NULL,
  `urutan` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ref_program_studi`
--

DROP TABLE IF EXISTS `ref_program_studi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ref_program_studi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fakultas_id` int NOT NULL,
  `nama` varchar(150) NOT NULL,
  `urutan` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fakultas_id` (`fakultas_id`),
  CONSTRAINT `ref_program_studi_ibfk_1` FOREIGN KEY (`fakultas_id`) REFERENCES `ref_fakultas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reviewer_assignment`
--

DROP TABLE IF EXISTS `reviewer_assignment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviewer_assignment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usulan_id` int NOT NULL,
  `reviewer_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usulan_reviewer` (`usulan_id`,`reviewer_id`),
  KEY `idx_usulan_id` (`usulan_id`),
  KEY `idx_reviewer_id` (`reviewer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reviewer_penilaian`
--

DROP TABLE IF EXISTS `reviewer_penilaian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviewer_penilaian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `assignment_id` int NOT NULL,
  `skor_1` tinyint unsigned DEFAULT NULL,
  `skor_2` tinyint unsigned DEFAULT NULL,
  `skor_3` tinyint unsigned DEFAULT NULL,
  `skor_4` tinyint unsigned DEFAULT NULL,
  `skor_5` tinyint unsigned DEFAULT NULL,
  `skor_6` tinyint unsigned DEFAULT NULL,
  `nilai_total` decimal(6,2) DEFAULT NULL,
  `keputusan` enum('disetujui','revisi_minor','revisi_mayor','ditolak') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `saran` text COLLATE utf8mb4_unicode_ci,
  `file_review` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_review_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assignment` (`assignment_id`),
  KEY `idx_assignment_id` (`assignment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `revisi_proposal`
--

DROP TABLE IF EXISTS `revisi_proposal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revisi_proposal` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usulan_id` int NOT NULL,
  `user_id` int NOT NULL,
  `tipe` enum('substantif','administratif') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'substantif',
  `round_ke` int NOT NULL DEFAULT '1',
  `status_sebelum` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_proposal` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_proposal_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ringkasan` text COLLATE utf8mb4_unicode_ci,
  `respon_isu` text COLLATE utf8mb4_unicode_ci,
  `keputusan` enum('diterima','dikembalikan') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_catatan` text COLLATE utf8mb4_unicode_ci,
  `keputusan_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usulan_id` (`usulan_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_tipe` (`tipe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `seleksi_admin`
--

DROP TABLE IF EXISTS `seleksi_admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seleksi_admin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usulan_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `checklist` text COLLATE utf8mb4_unicode_ci,
  `keputusan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usulan_id` (`usulan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `skema_penelitian`
--

DROP TABLE IF EXISTS `skema_penelitian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `skema_penelitian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kode` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_publikasi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anggaran_total` bigint DEFAULT '0',
  `anggaran_penelitian` bigint DEFAULT '0',
  `anggaran_publikasi` bigint DEFAULT '0',
  `kuota` int DEFAULT '10',
  `jabatan_min` enum('asisten_ahli','lektor','lektor_kepala','guru_besar') COLLATE utf8mb4_unicode_ci DEFAULT 'asisten_ahli',
  `batas_similarity` decimal(5,2) DEFAULT '25.00',
  `batas_ai` decimal(5,2) DEFAULT '30.00',
  `min_anggota_dosen` int DEFAULT '0',
  `max_anggota_dosen` int DEFAULT NULL,
  `anggota_dosen_wajib` tinyint(1) DEFAULT '0',
  `min_anggota_mahasiswa` int DEFAULT '0',
  `max_anggota_mahasiswa` int DEFAULT NULL,
  `anggota_mahasiswa_wajib` tinyint(1) DEFAULT '0',
  `is_open` tinyint(1) DEFAULT '1',
  `tahun` year NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `urutan` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_kode_tahun` (`kode`,`tahun`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `skripsi`
--

DROP TABLE IF EXISTS `skripsi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `skripsi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `jenis_tugas_akhir` enum('skripsi','tesis','disertasi') DEFAULT 'skripsi',
  `judul_skripsi` text NOT NULL,
  `nama_pembimbing1` varchar(150) DEFAULT NULL,
  `nama_pembimbing2` varchar(150) DEFAULT NULL,
  `nama_pembimbing3` varchar(150) DEFAULT NULL,
  `tahun_sidang` year DEFAULT NULL,
  `file_path` varchar(255) NOT NULL COMMENT 'Path file PDF skripsi',
  `file_name` varchar(255) NOT NULL,
  `file_size` int NOT NULL COMMENT 'Ukuran file dalam bytes',
  `status` enum('menunggu','diproses','selesai','ditolak') DEFAULT 'menunggu',
  `catatan_admin` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `skripsi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `surat_counter`
--

DROP TABLE IF EXISTS `surat_counter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `surat_counter` (
  `tabel` varchar(50) NOT NULL,
  `tahun` year NOT NULL,
  `counter` int DEFAULT '0',
  `reset_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tabel`,`tahun`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `surat_plagiasi`
--

DROP TABLE IF EXISTS `surat_plagiasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `surat_plagiasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cek_plagiasi_id` int NOT NULL,
  `nomor_surat` varchar(100) NOT NULL,
  `tanggal_surat` date NOT NULL,
  `nama_penandatangan` varchar(150) NOT NULL,
  `jabatan_penandatangan` varchar(150) NOT NULL,
  `file_pdf_path` varchar(255) DEFAULT NULL,
  `download_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_surat` (`nomor_surat`),
  KEY `cek_plagiasi_id` (`cek_plagiasi_id`),
  CONSTRAINT `surat_plagiasi_ibfk_1` FOREIGN KEY (`cek_plagiasi_id`) REFERENCES `cek_plagiasi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `surat_publikasi`
--

DROP TABLE IF EXISTS `surat_publikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `surat_publikasi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `publikasi_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `nomor_surat` varchar(100) NOT NULL,
  `tanggal_surat` date NOT NULL,
  `nama_penandatangan` varchar(150) NOT NULL,
  `jabatan_penandatangan` varchar(150) NOT NULL,
  `file_pdf_path` varchar(255) DEFAULT NULL,
  `download_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nomor_surat` (`nomor_surat`),
  KEY `publikasi_id` (`publikasi_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `surat_publikasi_ibfk_1` FOREIGN KEY (`publikasi_id`) REFERENCES `publikasi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `surat_publikasi_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nama_lengkap` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('mahasiswa','dosen','admin','reviewer') DEFAULT 'mahasiswa',
  `nim` varchar(20) DEFAULT NULL COMMENT 'Khusus mahasiswa',
  `nidn` varchar(20) DEFAULT NULL COMMENT 'Khusus dosen',
  `nip` varchar(20) DEFAULT NULL COMMENT 'Khusus dosen (opsional)',
  `fakultas` varchar(100) DEFAULT NULL,
  `program_studi` varchar(100) DEFAULT NULL,
  `angkatan` year DEFAULT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `jabatan_fungsional` enum('asisten_ahli','lektor','lektor_kepala','guru_besar') DEFAULT NULL,
  `google_scholar` varchar(255) DEFAULT NULL,
  `sinta_id` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `foto_profil` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usulan_penelitian`
--

DROP TABLE IF EXISTS `usulan_penelitian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usulan_penelitian` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `tahun_anggaran` year NOT NULL,
  `skema` varchar(50) NOT NULL,
  `judul` varchar(500) NOT NULL,
  `abstrak` text,
  `anggota_dosen` text COMMENT 'JSON: [{nama,nidn,jabatan,prodi,peran}]',
  `anggota_mahasiswa` text COMMENT 'JSON: [{nama,nim,prodi,semester}]',
  `anggota_mitra` text,
  `nama_ketua` varchar(150) DEFAULT NULL,
  `nidn_ketua` varchar(20) DEFAULT NULL,
  `jabatan_ketua` varchar(50) DEFAULT NULL,
  `google_scholar_ketua` varchar(255) DEFAULT NULL,
  `sinta_id_ketua` varchar(100) DEFAULT NULL,
  `file_proposal` varchar(300) DEFAULT NULL,
  `file_proposal_name` varchar(200) DEFAULT NULL,
  `file_proposal_size` bigint DEFAULT NULL,
  `similarity_mandiri` decimal(5,2) DEFAULT NULL,
  `ai_mandiri` decimal(5,2) DEFAULT NULL,
  `platform_mandiri` varchar(100) DEFAULT NULL,
  `platform_ai_mandiri` varchar(100) DEFAULT NULL,
  `file_cek_mandiri` varchar(300) DEFAULT NULL,
  `pernyataan_disetujui` tinyint(1) DEFAULT '0',
  `status` enum('draft','diajukan','ditinjau','disetujui','direvisi','ditolak') DEFAULT 'draft',
  `catatan_reviewer` text,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lolos_admin` tinyint(1) DEFAULT NULL,
  `tgl_kontrak` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `usulan_penelitian_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `usulan_penelitian_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-21 15:54:38
