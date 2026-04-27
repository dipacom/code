<?php
// ============================================================
// HELPER — Validasi Kelengkapan Profil Pengguna
// Pastikan user melengkapi data wajib sebelum mengajukan permohonan.
// ============================================================

/**
 * Cek kelengkapan profil user.
 * Kembalikan ['lengkap' => bool, 'missing' => [...field labels...]]
 */
function cekProfilLengkap(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare(
        "SELECT nama_lengkap, email, role, nim, nidn, nip, program_studi, fakultas
         FROM users WHERE id = ? AND is_active = 1"
    );
    $stmt->execute([$uid]);
    $u = $stmt->fetch();

    if (!$u) {
        return ['lengkap' => false, 'missing' => []];
    }

    $missing = [];

    // ── Bidang wajib semua role ──────────────────────────────
    if (empty(trim($u['email'] ?? ''))) {
        $missing['email'] = ['id' => 'Email', 'en' => 'Email'];
    }
    if (empty(trim($u['fakultas'] ?? ''))) {
        $missing['fakultas'] = ['id' => 'Fakultas', 'en' => 'Faculty'];
    }
    if (empty(trim($u['program_studi'] ?? ''))) {
        $missing['program_studi'] = ['id' => 'Program Studi', 'en' => 'Study Program'];
    }

    // ── Bidang spesifik role ─────────────────────────────────
    if ($u['role'] === 'mahasiswa') {
        if (empty(trim($u['nim'] ?? ''))) {
            $missing['nim'] = ['id' => 'NIRM / NIM', 'en' => 'Student ID (NIRM/NIM)'];
        }
    } elseif ($u['role'] === 'dosen') {
        if (empty(trim($u['nidn'] ?? ''))) {
            $missing['nidn'] = ['id' => 'NIDN', 'en' => 'NIDN'];
        }
    }

    return [
        'lengkap' => empty($missing),
        'missing' => $missing,
    ];
}

// ============================================================
// RENDER: Modal popup "Profil Belum Lengkap" untuk halaman user
// Muncul otomatis saat user coba mengakses form pengajuan.
// ============================================================
function renderProfilTidakLengkap(array $missing, string $lang): void
{
    $mid = 'mprofil_' . substr(md5(implode('', array_keys($missing)) . time()), 0, 8);

    // Bangun daftar field yang kurang sebagai HTML list
    $listItems = '';
    foreach ($missing as $field) {
        $label = $lang === 'id' ? $field['id'] : $field['en'];
        $listItems .= "
        <li style='display:flex;align-items:center;gap:8px;padding:6px 0;
                   border-bottom:1px solid #fef3c7;font-size:13px;color:#92400e'>
          <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='#d97706'
               stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0'>
            <circle cx='12' cy='12' r='10'/>
            <line x1='12' y1='8' x2='12' y2='12'/>
            <line x1='12' y1='16' x2='12.01' y2='16'/>
          </svg>
          {$label}
        </li>";
    }

    echo "
    <!-- ── Modal Profil Tidak Lengkap ── -->
    <style>
    .mpl-overlay {
      position:fixed;inset:0;z-index:9900;
      background:rgba(15,23,42,.72);
      backdrop-filter:blur(5px);
      display:flex;align-items:center;justify-content:center;
      padding:16px;
      animation:mplFadeIn .22s ease;
    }
    @keyframes mplFadeIn{from{opacity:0}to{opacity:1}}
    .mpl-card {
      background:#fff;border-radius:18px;max-width:460px;width:100%;
      box-shadow:0 24px 64px rgba(0,0,0,.28);
      animation:mplSlideUp .26s cubic-bezier(.22,1,.36,1);
      overflow:hidden;
    }
    @keyframes mplSlideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
    .mpl-head {
      background:linear-gradient(135deg,#d97706 0%,#b45309 100%);
      padding:28px 24px 22px;text-align:center;
    }
    .mpl-icon-ring {
      display:inline-flex;align-items:center;justify-content:center;
      width:58px;height:58px;background:rgba(255,255,255,.18);
      border-radius:50%;margin-bottom:12px;
    }
    .mpl-title { color:#fff;font-size:17px;font-weight:700;margin:0;line-height:1.3; }
    .mpl-body  { padding:22px 24px 20px; }
    .mpl-desc  { font-size:13px;color:#475569;line-height:1.75;margin:0 0 14px; }
    .mpl-list  {
      list-style:none;margin:0 0 18px;padding:0;
      background:#fffbeb;border:1.5px solid #fde68a;
      border-radius:10px;padding:4px 12px;
    }
    .mpl-list li:last-child{ border-bottom:none!important; }
    .mpl-actions{display:flex;gap:10px;flex-wrap:wrap}
    .mpl-btn-primary {
      flex:1;min-width:140px;display:flex;align-items:center;justify-content:center;
      gap:7px;background:#d97706;color:#fff;padding:11px 16px;border-radius:9px;
      text-decoration:none;font-size:13px;font-weight:600;transition:background .15s;
    }
    .mpl-btn-primary:hover{background:#b45309}
    .mpl-btn-home {
      flex:1;min-width:120px;display:flex;align-items:center;justify-content:center;
      gap:6px;background:#f1f5f9;color:#475569;padding:11px 16px;border-radius:9px;
      text-decoration:none;font-size:13px;font-weight:600;transition:background .15s;
    }
    .mpl-btn-home:hover{background:#e2e8f0}
    .mpl-dismiss {
      display:block;text-align:center;margin-top:14px;
      background:none;border:none;color:#94a3b8;font-size:12px;
      cursor:pointer;text-decoration:underline;padding:0;
    }
    .mpl-dismiss:hover{color:#64748b}
    </style>

    <div class='mpl-overlay' id='{$mid}'>
      <div class='mpl-card'>
        <div class='mpl-head'>
          <div class='mpl-icon-ring'>
            <svg width='26' height='26' viewBox='0 0 24 24' fill='none' stroke='white'
                 stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
              <path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/>
              <circle cx='12' cy='7' r='4'/>
            </svg>
          </div>
          <h3 class='mpl-title'>
            " . ($lang === 'id' ? 'Profil Belum Lengkap' : 'Incomplete Profile') . "
          </h3>
        </div>

        <div class='mpl-body'>
          <p class='mpl-desc'>
            " . ($lang === 'id'
                ? 'Sebelum mengajukan permohonan, Anda perlu melengkapi data profil berikut terlebih dahulu:'
                : 'Before submitting an application, please complete the following profile data first:'
            ) . "
          </p>

          <ul class='mpl-list'>
            {$listItems}
          </ul>

          <div class='mpl-actions'>
            <a href='<?= BASE_URL ?>/profil.php' class='mpl-btn-primary'>
              <svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor'
                   stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/>
                <circle cx='12' cy='7' r='4'/>
              </svg>
              " . ($lang === 'id' ? 'Lengkapi Profil Sekarang' : 'Complete Profile Now') . "
            </a>
            <a href='<?= BASE_URL ?>/dashboard.php' class='mpl-btn-home'>
              <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor'
                   stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                <line x1='19' y1='12' x2='5' y2='12'/><polyline points='12 19 5 12 12 5'/>
              </svg>
              " . ($lang === 'id' ? 'Ke Beranda' : 'Back to Home') . "
            </a>
          </div>

          <button class='mpl-dismiss' onclick=\"document.getElementById('{$mid}').style.display='none'\">
            " . ($lang === 'id' ? 'Tutup pemberitahuan ini' : 'Dismiss this notice') . "
          </button>
        </div>
      </div>
    </div>

    <!-- Placeholder di balik modal agar halaman tidak kosong total -->
    <div style='background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;
                padding:20px 24px;display:flex;gap:14px;align-items:flex-start'>
      <svg width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='#d97706'
           stroke-width='2' stroke-linecap='round' stroke-linejoin='round' style='flex-shrink:0;margin-top:1px'>
        <path d='M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z'/>
        <line x1='12' y1='9' x2='12' y2='13'/><line x1='12' y1='17' x2='12.01' y2='17'/>
      </svg>
      <div>
        <div style='font-size:13px;font-weight:700;color:#92400e;margin-bottom:4px'>
          " . ($lang === 'id' ? 'Profil belum lengkap — pengajuan tidak dapat dilakukan.' : 'Profile incomplete — application is not available.') . "
        </div>
        <div style='font-size:12px;color:#a16207;line-height:1.6'>
          " . ($lang === 'id'
              ? 'Lengkapi data profil Anda terlebih dahulu, kemudian kembali ke halaman ini untuk mengajukan permohonan.'
              : 'Please complete your profile data first, then return to this page to submit your application.'
          ) . "
        </div>
        <a href='<?= BASE_URL ?>/profil.php'
           style='display:inline-flex;align-items:center;gap:5px;margin-top:10px;
                  background:#d97706;color:#fff;padding:8px 16px;border-radius:8px;
                  text-decoration:none;font-size:12px;font-weight:600'>
          <svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor'
               stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
            <path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/><circle cx='12' cy='7' r='4'/>
          </svg>
          " . ($lang === 'id' ? 'Lengkapi Profil' : 'Complete Profile') . "
        </a>
      </div>
    </div>";
}
