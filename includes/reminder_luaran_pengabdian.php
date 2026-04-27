<?php
/**
 * Pengingat luaran 3-bulanan untuk peneliti.
 *
 * Dipanggil saat dosen load dashboard.php. Function ini:
 * 1. Iterasi proposal dosen dgn status kontrak_aktif / laporan_diterima / selesai
 *    yang punya deadline_laporan + batas_luaran_bulan di skema.
 * 2. Hitung checkpoint pengingat: setiap X bulan sejak deadline_laporan
 *    (X = pengaturan 'monev_pkm_reminder_interval_bulan', default 3).
 * 3. Untuk setiap checkpoint yang sudah lewat & belum ada di luaran_reminder,
 *    insert row + buat notifikasi (bell) — idempotent via UNIQUE (usulan_id,period_no).
 * 4. Return daftar reminder yang is_read=0 (untuk popup dashboard).
 *
 * Reminder berhenti otomatis jika:
 * - Proposal memiliki luaran dengan status='diverifikasi'
 * - User manual menandai reminder is_read=1 via UI
 */

if (!function_exists('processLuaranRemindersPengabdian')) {

function processLuaranRemindersPengabdian(PDO $pdo, int $user_id): array {
    $interval_bulan = max(1, (int)(getSetting($pdo, 'monev_pkm_reminder_interval_bulan') ?: 3));
    $now_ts = time();

    // Ambil proposal eligible + deadline luaran
    $q = $pdo->prepare("
        SELECT up.id AS usulan_id, up.judul, up.skema, up.user_id,
               kp.deadline_laporan,
               sk.batas_luaran_bulan,
               (SELECT COUNT(*) FROM monev_luaran ml
                WHERE ml.usulan_id = up.id AND ml.status='diverifikasi' AND ml.deleted_at IS NULL) AS n_luaran_ok
        FROM usulan_penelitian up
        LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
        LEFT JOIN skema_penelitian sk
               ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci
              AND sk.tahun = up.tahun_anggaran
        WHERE up.user_id = ?
          AND up.deleted_at IS NULL
          AND up.status IN ('kontrak_aktif','laporan_diterima','selesai')
    ");
    $q->execute([$user_id]);
    $proposals = $q->fetchAll();

    foreach ($proposals as $p) {
        // Skip jika luaran wajib sudah ter-verifikasi LPPM
        if ((int)$p['n_luaran_ok'] > 0) continue;

        // Butuh deadline_laporan & batas_luaran_bulan
        if (empty($p['deadline_laporan']) || empty($p['batas_luaran_bulan'])) continue;

        $dl_lap_ts = strtotime($p['deadline_laporan']);
        $batas_bln = (int)$p['batas_luaran_bulan'];
        if (!$dl_lap_ts || $batas_bln <= 0) continue;

        $dl_luaran_ts = strtotime("+{$batas_bln} months", $dl_lap_ts);

        // Generate checkpoint periodik: interval 3 bulan (atau sesuai pengaturan)
        // Periode dimulai dari deadline_laporan + 3 bulan
        $period = 1;
        $next_ts = strtotime("+{$interval_bulan} months", $dl_lap_ts);

        while ($next_ts <= $dl_luaran_ts) {
            if ($next_ts <= $now_ts) {
                insertReminderIfMissingPengabdian(
                    $pdo, $p, $period, date('Y-m-d', $next_ts), 'periodik', $interval_bulan, $dl_luaran_ts
                );
            }
            $period++;
            $next_ts = strtotime("+" . ($interval_bulan * $period) . " months", $dl_lap_ts);
            if ($period > 40) break; // safety
        }

        // Final warning: H-14 deadline_luaran
        $warn_ts = strtotime('-14 days', $dl_luaran_ts);
        if ($warn_ts <= $now_ts && $dl_luaran_ts >= $now_ts) {
            insertReminderIfMissingPengabdian($pdo, $p, 0, date('Y-m-d', $warn_ts), 'final', $interval_bulan, $dl_luaran_ts);
        }
        // Overdue reminder: setelah lewat deadline, period_no -1 = overdue (tapi kita pakai 0 juga untuk final, jadi -1 untuk overdue)
        if ($dl_luaran_ts < $now_ts) {
            insertReminderIfMissingPengabdian($pdo, $p, -1, date('Y-m-d', $dl_luaran_ts), 'final', $interval_bulan, $dl_luaran_ts);
        }
    }

    // Return unread reminders untuk popup
    $u = $pdo->prepare("
        SELECT lr.id, lr.usulan_id, lr.period_no, lr.period_date, lr.tipe, lr.created_at,
               up.judul, up.skema,
               kp.deadline_laporan,
               sk.batas_luaran_bulan, sk.jenis_luaran_wajib
        FROM luaran_reminder lr
        JOIN usulan_penelitian up ON up.id = lr.usulan_id
        LEFT JOIN kontrak_penelitian kp ON kp.usulan_id = up.id
        LEFT JOIN skema_penelitian sk
               ON sk.kode COLLATE utf8mb4_unicode_ci = up.skema COLLATE utf8mb4_unicode_ci
              AND sk.tahun = up.tahun_anggaran
        WHERE lr.user_id = ? AND lr.is_read = 0
          AND up.deleted_at IS NULL
        ORDER BY lr.period_date DESC, lr.id DESC
    ");
    $u->execute([$user_id]);
    return $u->fetchAll();
}

function insertReminderIfMissingPengabdian(PDO $pdo, array $p, int $period_no, string $period_date, string $tipe, int $interval_bulan, int $dl_luaran_ts): void {
    try {
        $pdo->prepare("
            INSERT INTO luaran_reminder (usulan_id, user_id, period_no, period_date, tipe)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$p['usulan_id'], $p['user_id'], $period_no, $period_date, $tipe]);
    } catch (\PDOException $e) {
        // Duplicate period_no — sudah ada, skip
        return;
    }
    // Baru dibuat → juga kirim notifikasi bell
    $judul_pendek = mb_strimwidth($p['judul'], 0, 70, '…');
    if ($period_no === -1) {
        $jdl = 'Luaran Penelitian LEWAT Batas';
        $msg = "Batas akhir pelaporan luaran untuk proposal \"$judul_pendek\" telah terlewati ("
             . date('d M Y', $dl_luaran_ts) . "). Segera serahkan bukti luaran atau konsultasi dengan LPPM.";
        $tipe_notif = 'error';
    } elseif ($period_no === 0) {
        $jdl = 'Luaran Penelitian — H-14 Batas Akhir';
        $msg = "14 hari lagi batas akhir pelaporan luaran untuk \"$judul_pendek\" ("
             . date('d M Y', $dl_luaran_ts) . "). Segera submit bukti luaran.";
        $tipe_notif = 'peringatan';
    } else {
        $bulan_sejak = $period_no * $interval_bulan;
        $jdl = "Pengingat Luaran — $bulan_sejak Bulan Sejak Laporan";
        $msg = "Sudah $bulan_sejak bulan sejak batas laporan \"$judul_pendek\". "
             . "Batas akhir luaran: " . date('d M Y', $dl_luaran_ts) . ". "
             . "Silakan submit bukti luaran di menu Luaran Penelitian.";
        $tipe_notif = 'info';
    }

    $pdo->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
        ->execute([$p['user_id'], $jdl, $msg, $tipe_notif]);
    $notif_id = (int)$pdo->lastInsertId();

    // Update luaran_reminder dengan notif_id
    $pdo->prepare("UPDATE luaran_reminder SET notif_id=? WHERE usulan_id=? AND period_no=?")
        ->execute([$notif_id, $p['usulan_id'], $period_no]);
}

/**
 * Mark reminders as read. Dipanggil via AJAX saat user dismiss popup.
 */
function markLuaranRemindersReadPengabdian(PDO $pdo, int $user_id, array $reminder_ids = []): int {
    if (empty($reminder_ids)) {
        $st = $pdo->prepare("UPDATE luaran_reminder SET is_read=1 WHERE user_id=? AND is_read=0");
        $st->execute([$user_id]);
        return $st->rowCount();
    }
    $ph = implode(',', array_fill(0, count($reminder_ids), '?'));
    $params = array_map('intval', $reminder_ids);
    array_unshift($params, $user_id);
    $st = $pdo->prepare("UPDATE luaran_reminder SET is_read=1 WHERE user_id=? AND id IN ($ph)");
    $st->execute($params);
    return $st->rowCount();
}

} // end function_exists guard
