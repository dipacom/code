-- ============================================================
-- MIGRASI v4 — Pengingat 3-bulanan luaran penelitian
-- ============================================================

CREATE TABLE IF NOT EXISTS luaran_reminder (
  id           INT NOT NULL AUTO_INCREMENT,
  usulan_id    INT NOT NULL,
  user_id      INT NOT NULL,
  period_no    INT NOT NULL COMMENT '1 = interval pertama, 2 = kedua, dst. 0 = final warning',
  period_date  DATE NOT NULL COMMENT 'Tanggal dimulainya periode pengingat',
  tipe         ENUM('periodik','final') DEFAULT 'periodik',
  is_read      TINYINT(1) DEFAULT 0 COMMENT 'User sudah dismiss popup',
  notif_id     INT DEFAULT NULL COMMENT 'Notifikasi terkait (bell)',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_usulan_period (usulan_id, period_no),
  KEY idx_user (user_id),
  KEY idx_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Default setting: interval pengingat 3 bulan
INSERT INTO pengaturan (kunci, nilai, keterangan)
VALUES ('monev_reminder_interval_bulan', '3', 'Interval pengingat luaran (bulan)')
ON DUPLICATE KEY UPDATE keterangan = keterangan;

SELECT 'Migrasi v4 selesai.' AS status;
