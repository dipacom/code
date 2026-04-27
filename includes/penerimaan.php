<?php
// ============================================================
// HELPER — Sistem Buka/Tutup Penerimaan Pengajuan
// Jenis valid: 'plagiasi', 'publikasi', 'ec'
// ============================================================

/**
 * Ambil status penerimaan: 'buka' atau 'tutup'
 */
function getPenerimaan(PDO $pdo, string $jenis): string {
    return getSetting($pdo, 'penerimaan_' . $jenis) ?: 'buka';
}

/**
 * Set status penerimaan
 */
function setPenerimaan(PDO $pdo, string $jenis, string $nilai): void {
    $pdo->prepare("UPDATE pengaturan SET nilai=? WHERE kunci=?")
        ->execute([$nilai, 'penerimaan_' . $jenis]);
}

/**
 * Cek & auto-buka jika pending sudah 0 (semua selesai diverifikasi).
 * Mengembalikan true jika terjadi auto-buka.
 */
function autoReopenIfCleared(PDO $pdo, string $jenis, int $pending): bool {
    if ($pending === 0 && getPenerimaan($pdo, $jenis) === 'tutup') {
        setPenerimaan($pdo, $jenis, 'buka');
        return true;
    }
    return false;
}

// ============================================================
// Konstanta ambang batas
// ============================================================
define('PENERIMAAN_BATAS_TUTUP', 15);   // peringatan untuk menutup
define('PENERIMAAN_BATAS_SARAN', 5);    // saran untuk membuka kembali

// ============================================================
// RENDER: Status Bar untuk halaman admin
// Tampilkan: status + toggle + warning/saran
// ============================================================
function renderPenerimaanBar(
    PDO    $pdo,
    string $jenis,          // 'plagiasi' | 'publikasi' | 'ec'
    string $labelID,        // nama fitur bahasa Indonesia
    string $labelEN,        // nama fitur bahasa Inggris
    int    $pending,        // jumlah menunggu saat ini
    string $lang,           // 'id' | 'en'
    string $redirectUrl     // URL redirect setelah toggle
): void {
    $status    = getPenerimaan($pdo, $jenis);
    $isBuka    = ($status === 'buka');
    $label     = $lang === 'id' ? $labelID : $labelEN;

    // Auto-buka jika queue bersih
    if (!$isBuka && $pending === 0) {
        setPenerimaan($pdo, $jenis, 'buka');
        $isBuka = true;
        $status = 'buka';
        echo "<div class='alert alert-success' style='margin-bottom:12px;font-size:13px'>
            <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor'
                 stroke-width='2' stroke-linecap='round' stroke-linejoin='round'
                 style='display:inline-block;vertical-align:-2px;margin-right:5px'>
              <polyline points='20 6 9 17 4 12'/>
            </svg>
            " . ($lang === 'id'
                ? "Penerimaan <strong>{$labelID}</strong> dibuka kembali otomatis — semua pengajuan sebelumnya telah diverifikasi."
                : "Submission for <strong>{$labelEN}</strong> auto-reopened — all previous applications have been verified."
            ) . "
        </div>";
    }

    // Warna & teks berdasarkan status
    $barBg      = $isBuka ? '#f0fdf4' : '#fff1f2';
    $barBorder  = $isBuka ? '#86efac' : '#fca5a5';
    $dotColor   = $isBuka ? '#16a34a' : '#dc2626';
    $statusText = $isBuka
        ? ($lang === 'id' ? 'Terbuka' : 'Open')
        : ($lang === 'id' ? 'Ditutup Sementara' : 'Temporarily Closed');
    $btnLabel   = $isBuka
        ? ($lang === 'id' ? 'Tutup Penerimaan' : 'Close Submissions')
        : ($lang === 'id' ? 'Buka Penerimaan' : 'Reopen Submissions');
    $btnBg      = $isBuka ? '#dc2626' : '#16a34a';
    $btnIcon    = $isBuka
        ? '<path d="M18 6L6 18M6 6l12 12"/>'   // X
        : '<polyline points="20 6 9 17 4 12"/>'; // check

    echo "
    <div style='background:{$barBg};border:1.5px solid {$barBorder};border-radius:10px;
                padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;
                gap:12px;flex-wrap:wrap'>

      <!-- Status dot + label -->
      <div style='display:flex;align-items:center;gap:7px;flex:1;min-width:200px'>
        <span style='width:9px;height:9px;border-radius:50%;background:{$dotColor};
                     display:inline-block;flex-shrink:0'></span>
        <span style='font-size:13px;font-weight:700;color:#1e293b'>{$label}</span>
        <span style='font-size:12px;font-weight:600;color:{$dotColor}'>{$statusText}</span>
      </div>

      <!-- Pending badge -->
      <div style='font-size:12px;color:#64748b'>
        " . ($lang === 'id' ? 'Menunggu:' : 'Pending:') . "
        <strong style='color:#1e293b;font-size:13px'>{$pending}</strong>
      </div>

      <!-- Toggle button -->
      <form method='POST' style='margin:0'>
        <input type='hidden' name='action' value='toggle_penerimaan'>
        <input type='hidden' name='jenis'  value='{$jenis}'>
        <input type='hidden' name='redirect' value='{$redirectUrl}'>
        <button type='submit' style='background:{$btnBg};color:#fff;border:none;
                border-radius:7px;padding:7px 14px;font-size:12px;font-weight:600;
                cursor:pointer;display:flex;align-items:center;gap:5px'>
          <svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor'
               stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'>
            {$btnIcon}
          </svg>
          {$btnLabel}
        </button>
      </form>
    </div>";

    // ── Peringatan: pending > batas → sarankan tutup ──
    if ($isBuka && $pending > PENERIMAAN_BATAS_TUTUP) {
        echo "
        <div style='background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;
                    padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start'>
          <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#ea580c'
               stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0;margin-top:1px'>
            <path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/>
            <line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/>
          </svg>
          <div style='flex:1'>
            <div style='font-size:13px;font-weight:700;color:#9a3412;margin-bottom:3px'>
              " . ($lang === 'id'
                ? "Antrian {$labelID} Melebihi {$pending} pengajuan"
                : "{$labelEN} queue has {$pending} pending applications"
              ) . "
            </div>
            <div style='font-size:12px;color:#c2410c;line-height:1.6'>
              " . ($lang === 'id'
                ? "Rekomendasi: <strong>tutup penerimaan sementara</strong> agar tim dapat menyelesaikan " . PENERIMAAN_BATAS_TUTUP . " pengajuan yang ada terlebih dahulu sebelum menerima pengajuan baru. Penerimaan akan dibuka kembali otomatis setelah semua antrian saat ini selesai diverifikasi."
                : "Recommendation: <strong>temporarily close submissions</strong> so the team can process the existing " . PENERIMAAN_BATAS_TUTUP . " applications first before accepting new ones. Submissions will auto-reopen once the current queue is fully verified."
              ) . "
            </div>
          </div>
          <form method='POST' style='margin:0;flex-shrink:0'>
            <input type='hidden' name='action' value='toggle_penerimaan'>
            <input type='hidden' name='jenis'  value='{$jenis}'>
            <input type='hidden' name='redirect' value='{$redirectUrl}'>
            <button type='submit' style='background:#dc2626;color:#fff;border:none;
                    border-radius:7px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;
                    white-space:nowrap'>
              " . ($lang === 'id' ? 'Tutup Sekarang' : 'Close Now') . "
            </button>
          </form>
        </div>";
    }

    // ── Saran: closed + pending turun ≤ batas_saran → sarankan buka ──
    if (!$isBuka && $pending <= PENERIMAAN_BATAS_SARAN && $pending > 0) {
        echo "
        <div style='background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;
                    padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start'>
          <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#16a34a'
               stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0;margin-top:1px'>
            <circle cx='12' cy='12' r='10'/>
            <line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/>
          </svg>
          <div style='flex:1'>
            <div style='font-size:13px;font-weight:700;color:#14532d;margin-bottom:3px'>
              " . ($lang === 'id'
                ? "Antrian {$labelID} tinggal {$pending} — pertimbangkan membuka kembali"
                : "{$labelEN} queue is down to {$pending} — consider reopening"
              ) . "
            </div>
            <div style='font-size:12px;color:#166534;line-height:1.6'>
              " . ($lang === 'id'
                ? "Sisa antrian sudah berkurang signifikan. Anda dapat membuka penerimaan kembali kapan saja."
                : "The queue has reduced significantly. You can reopen submissions at any time."
              ) . "
            </div>
          </div>
          <form method='POST' style='margin:0;flex-shrink:0'>
            <input type='hidden' name='action' value='toggle_penerimaan'>
            <input type='hidden' name='jenis'  value='{$jenis}'>
            <input type='hidden' name='redirect' value='{$redirectUrl}'>
            <button type='submit' style='background:#16a34a;color:#fff;border:none;
                    border-radius:7px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;
                    white-space:nowrap'>
              " . ($lang === 'id' ? 'Buka Kembali' : 'Reopen') . "
            </button>
          </form>
        </div>";
    }
}

// ============================================================
// RENDER: Modal popup "Pengajuan Ditutup" untuk halaman user
// Muncul otomatis saat halaman dimuat ketika penerimaan tutup.
// ============================================================
function renderPenerimaanTutup(
    string $labelID,
    string $labelEN,
    string $lang,
    string $email = ''
): void {
    $label = $lang === 'id' ? $labelID : $labelEN;
    $mid   = 'mtutup_' . substr(md5($labelID), 0, 8);

    echo "
    <!-- ── Modal Penerimaan Ditutup ── -->
    <style>
    .mto-overlay {
      position:fixed;inset:0;z-index:9900;
      background:rgba(15,23,42,.72);
      backdrop-filter:blur(5px);
      display:flex;align-items:center;justify-content:center;
      padding:16px;
      animation:mtoFadeIn .22s ease;
    }
    @keyframes mtoFadeIn{from{opacity:0}to{opacity:1}}
    .mto-card {
      background:#fff;border-radius:18px;max-width:460px;width:100%;
      box-shadow:0 24px 64px rgba(0,0,0,.28);
      animation:mtoSlideUp .26s cubic-bezier(.22,1,.36,1);
      overflow:hidden;
    }
    @keyframes mtoSlideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
    .mto-head {
      background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);
      padding:28px 24px 22px;text-align:center;
    }
    .mto-icon-ring {
      display:inline-flex;align-items:center;justify-content:center;
      width:58px;height:58px;background:rgba(255,255,255,.18);
      border-radius:50%;margin-bottom:12px;
    }
    .mto-title {
      color:#fff;font-size:17px;font-weight:700;
      margin:0;line-height:1.3;
    }
    .mto-body { padding:22px 24px 20px; }
    .mto-desc {
      font-size:13px;color:#475569;line-height:1.75;margin:0 0 18px;
    }
    .mto-contact {
      background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;
      padding:11px 14px;display:flex;align-items:center;gap:10px;margin-bottom:18px;
    }
    .mto-contact-label{font-size:11px;color:#0369a1;font-weight:600;margin-bottom:2px}
    .mto-contact-email{font-size:13px;color:#0284c7;font-weight:700;text-decoration:none}
    .mto-contact-email:hover{text-decoration:underline}
    .mto-actions{display:flex;gap:10px;flex-wrap:wrap}
    .mto-btn-chat {
      flex:1;min-width:140px;display:flex;align-items:center;justify-content:center;
      gap:7px;background:#1e3a5f;color:#fff;padding:11px 16px;border-radius:9px;
      text-decoration:none;font-size:13px;font-weight:600;transition:background .15s;
    }
    .mto-btn-chat:hover{background:#2d5282}
    .mto-btn-home {
      flex:1;min-width:120px;display:flex;align-items:center;justify-content:center;
      gap:6px;background:#f1f5f9;color:#475569;padding:11px 16px;border-radius:9px;
      text-decoration:none;font-size:13px;font-weight:600;transition:background .15s;
    }
    .mto-btn-home:hover{background:#e2e8f0}
    .mto-dismiss {
      display:block;text-align:center;margin-top:14px;
      background:none;border:none;color:#94a3b8;font-size:12px;
      cursor:pointer;text-decoration:underline;padding:0;
    }
    .mto-dismiss:hover{color:#64748b}
    </style>

    <div class='mto-overlay' id='{$mid}'>
      <div class='mto-card'>
        <div class='mto-head'>
          <div class='mto-icon-ring'>
            <svg width='26' height='26' viewBox='0 0 24 24' fill='none' stroke='white'
                 stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
              <rect x='3' y='11' width='18' height='11' rx='2' ry='2'/>
              <path d='M7 11V7a5 5 0 0 1 10 0v4'/>
            </svg>
          </div>
          <h3 class='mto-title'>
            " . ($lang === 'id'
                ? "Penerimaan {$label}<br>Sementara Ditutup"
                : "{$label} Submissions<br>Temporarily Closed"
            ) . "
          </h3>
        </div>

        <div class='mto-body'>
          <p class='mto-desc'>
            " . ($lang === 'id'
                ? "Tim LPPM sedang memproses pengajuan yang sudah masuk. Penerimaan pengajuan baru akan dibuka kembali setelah antrian saat ini selesai diverifikasi."
                : "The LPPM team is currently processing existing submissions. New submissions will reopen once the current queue has been verified."
            ) . "
          </p>

          " . ($email !== '' ? "
          <div class='mto-contact'>
            <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='#0284c7'
                 stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0'>
              <path d='M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z'/>
              <polyline points='22,6 12,13 2,6'/>
            </svg>
            <div>
              <div class='mto-contact-label'>" . ($lang==='id'?'Hubungi Admin via Email':'Contact Admin via Email') . "</div>
              <a href='mailto:{$email}' class='mto-contact-email'>{$email}</a>
            </div>
          </div>" : "") . "

          <div class='mto-actions'>
            <a href='<?= BASE_URL ?>/modules/chat/' class='mto-btn-chat'>
              <svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor'
                   stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <path d='M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'/>
              </svg>
              " . ($lang==='id'?'Chat dengan Admin':'Chat with Admin') . "
            </a>
            <a href='<?= BASE_URL ?>/dashboard.php' class='mto-btn-home'>
              <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor'
                   stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <line x1='19' y1='12' x2='5' y2='12'/><polyline points='12 19 5 12 12 5'/>
              </svg>
              " . ($lang==='id'?'Ke Beranda':'Back to Home') . "
            </a>
          </div>

          <button class='mto-dismiss' onclick=\"document.getElementById('{$mid}').style.display='none'\">
            " . ($lang==='id'?'Tutup pemberitahuan ini':'Dismiss this notice') . "
          </button>
        </div>
      </div>
    </div>

    <!-- Placeholder di balik modal agar halaman tidak kosong total -->
    <div style='text-align:center;padding:60px 24px;color:#94a3b8'>
      <svg width='40' height='40' viewBox='0 0 24 24' fill='none' stroke='currentColor'
           stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'
           style='display:block;margin:0 auto 12px;opacity:.4'>
        <rect x='3' y='11' width='18' height='11' rx='2' ry='2'/>
        <path d='M7 11V7a5 5 0 0 1 10 0v4'/>
      </svg>
      <p style='font-size:13px;margin:0'>" . ($lang==='id'?'Penerimaan sementara ditutup.':'Submissions temporarily closed.') . "</p>
    </div>";
}
