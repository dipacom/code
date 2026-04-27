<?php
require_once '../../includes/config.php';
requireLogin();

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// Admin seharusnya ke halaman admin
if ($role === 'admin') {
    header('Location: /admin/chat.php');
    exit;
}

// ── AJAX: fetch pesan baru setelah ID tertentu ──
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    $after = (int)($_GET['after'] ?? 0);
    $stmt  = $pdo->prepare(
        "SELECT id, pengirim_role, isi, created_at FROM pesan
         WHERE user_id=? AND id>? ORDER BY created_at ASC"
    );
    $stmt->execute([$uid, $after]);
    $rows = $stmt->fetchAll();
    
    // Cek apakah ada pesan dari admin di hasil fetch yang baru
    $hasAdminMsg = false;
    foreach ($rows as $r) {
        if ($r['pengirim_role'] === 'admin') { $hasAdminMsg = true; break; }
    }
    
    // Hanya eksekusi kueri UPDATE jika memang ada pesan admin yang harus ditandai dibaca
    if ($hasAdminMsg) {
        $pdo->prepare("UPDATE pesan SET dibaca=1 WHERE user_id=? AND pengirim_role='admin' AND dibaca=0")->execute([$uid]);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['messages' => $rows]);
    exit;
}

// ── AJAX / POST: kirim pesan ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isi    = trim($_POST['isi'] ?? '');
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($isi !== '') {
        $pdo->prepare("INSERT INTO pesan (user_id, pengirim_role, isi) VALUES (?,?,?)")
            ->execute([$uid, 'user', $isi]);
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header('Location: /modules/chat/');
    exit;
}

// ── Tandai semua pesan dari admin sebagai dibaca ──
$pdo->prepare("UPDATE pesan SET dibaca=1 WHERE user_id=? AND pengirim_role='admin' AND dibaca=0")
    ->execute([$uid]);

// ── Muat seluruh riwayat percakapan ──
$stmt = $pdo->prepare(
    "SELECT id, pengirim_role, isi, created_at FROM pesan
     WHERE user_id=? ORDER BY created_at ASC"
);
$stmt->execute([$uid]);
$messages = $stmt->fetchAll();
$last_id  = empty($messages) ? 0 : (int)end($messages)['id'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Chat Admin':'Admin Chat' ?> — LPPM</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=4">
<style>
/* ── Chat layout ── */
.chat-wrap {
  display:flex; flex-direction:column;
  height:calc(100vh - 120px);
  background:#fff; border-radius:14px;
  border:1.5px solid #e2e8f0;
  overflow:hidden;
}
.chat-header {
  padding:14px 18px; border-bottom:1.5px solid #f1f5f9;
  display:flex; align-items:center; gap:12px;
  background:#fafbfc;
}
.chat-header-avatar {
  width:38px; height:38px; border-radius:50%;
  background:#1e3a5f; color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-size:14px; font-weight:700; flex-shrink:0;
}
.chat-header-name  { font-size:14px; font-weight:700; color:#1e293b; }
.chat-header-sub   { font-size:11px; color:#94a3b8; margin-top:1px; }
.chat-online-dot   {
  width:8px; height:8px; border-radius:50%;
  background:#22c55e; display:inline-block; margin-right:4px;
}

.chat-messages {
  flex:1; overflow-y:auto; padding:18px;
  display:flex; flex-direction:column; gap:10px;
  scroll-behavior:smooth;
}
.chat-empty {
  flex:1; display:flex; flex-direction:column;
  align-items:center; justify-content:center;
  color:#94a3b8; gap:10px; padding:32px;
}
.chat-empty svg { opacity:.35; }

/* Bubbles */
.bubble-row {
  display:flex; align-items:flex-end; gap:8px;
}
.bubble-row.from-user { flex-direction:row-reverse; }

.bubble-avatar {
  width:28px; height:28px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700;
}
.bubble-avatar.admin-av { background:#1e3a5f; color:#fff; }
.bubble-avatar.user-av  { background:#e0e7ff; color:#3730a3; }

.bubble {
  max-width:70%; padding:10px 14px; border-radius:14px;
  font-size:13px; line-height:1.6; word-break:break-word;
  white-space:pre-wrap;
}
.bubble.from-admin {
  background:#f1f5f9; color:#1e293b;
  border-bottom-left-radius:4px;
}
.bubble.from-user {
  background:#1e3a5f; color:#fff;
  border-bottom-right-radius:4px;
}
.bubble-time {
  font-size:10px; color:#94a3b8; margin-top:4px;
  text-align:right;
}
.bubble-row.from-admin .bubble-time { text-align:left; }

/* Input area */
.chat-input-area {
  padding:12px 16px; border-top:1.5px solid #f1f5f9;
  background:#fafbfc;
  display:flex; align-items:flex-end; gap:10px;
}
.chat-textarea {
  flex:1; resize:none; border:1.5px solid #e2e8f0;
  border-radius:10px; padding:10px 12px;
  font-size:13px; line-height:1.5; font-family:inherit;
  max-height:120px; min-height:42px;
  transition:border-color .15s;
  outline:none;
}
.chat-textarea:focus { border-color:#2563eb; }
.chat-send-btn {
  flex-shrink:0; width:40px; height:40px;
  background:#1e3a5f; color:#fff; border:none;
  border-radius:10px; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:background .15s;
}
.chat-send-btn:hover { background:#2d5282; }
.chat-send-btn:disabled { background:#94a3b8; cursor:default; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:-2px;margin-right:6px">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <?= $lang==='id'?'Chat dengan Admin LPPM':'Chat with LPPM Admin' ?>
          <span class="breadcrumb"><?= $lang==='id'?'Kirim pertanyaan atau permintaan kepada admin':'Send questions or requests to admin' ?></span>
        </div>
      </div>
      <div class="topbar-right">
        <button class="lang-toggle" onclick="toggleLang()">
          <svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <?= $lang==='id'?'EN':'ID' ?>
        </button>
      </div>
    </div>

    <div class="page-content" style="padding-bottom:0">
      <div class="chat-wrap">

        <!-- Header -->
        <div class="chat-header">
          <div class="chat-header-avatar">A</div>
          <div>
            <div class="chat-header-name">Admin LPPM</div>
            <div class="chat-header-sub">
              <span class="chat-online-dot"></span>
              <?= $lang==='id'?'Biasanya membalas dalam 1 hari kerja':'Usually replies within 1 business day' ?>
            </div>
          </div>
        </div>

        <!-- Messages area -->
        <div class="chat-messages" id="chatMessages">
          <?php if (empty($messages)): ?>
          <div class="chat-empty" id="chatEmpty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <p style="font-size:13px;margin:0;text-align:center;max-width:260px">
              <?= $lang==='id'
                ? 'Belum ada pesan. Kirim pesan pertama Anda kepada admin LPPM.'
                : 'No messages yet. Send your first message to the LPPM admin.' ?>
            </p>
          </div>
          <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <?php renderBubble($m, $lang) ?>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Input -->
        <div class="chat-input-area">
          <textarea id="chatInput" class="chat-textarea" rows="1"
            placeholder="<?= $lang==='id'?'Tulis pesan…':'Type a message…' ?>"
            onkeydown="handleKey(event)"
            oninput="autoResize(this)"></textarea>
          <button class="chat-send-btn" id="sendBtn" onclick="sendMessage()" title="<?= $lang==='id'?'Kirim':'Send' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const LANG      = '<?= $lang ?>';
const LAST_ID   = { val: <?= $last_id ?> };
const MY_NAMA   = <?= json_encode(mb_substr($_SESSION['nama'] ?? 'U', 0, 1)) ?>;

<?php
function renderBubble(array $m, string $lang, bool $echo = true): string {
    $isUser = $m['pengirim_role'] === 'user';
    $rowCls  = $isUser ? 'from-user' : '';
    $bubCls  = $isUser ? 'from-user' : 'from-admin';
    $avCls   = $isUser ? 'user-av'   : 'admin-av';
    $avLetter= $isUser ? 'U'          : 'A';
    $dt = date('d M H:i', strtotime($m['created_at']));
    $isi = htmlspecialchars($m['isi'], ENT_QUOTES);
    $html = "
    <div class='bubble-row {$rowCls}' data-id='{$m['id']}'>
      <div class='bubble-avatar {$avCls}'>{$avLetter}</div>
      <div>
        <div class='bubble {$bubCls}'>{$isi}</div>
        <div class='bubble-time'>{$dt}</div>
      </div>
    </div>";
    if ($echo) echo $html;
    return $html;
}
?>

function buildBubble(msg) {
    const isUser = msg.pengirim_role === 'user';
    const dt = new Date(msg.created_at).toLocaleString(LANG==='id'?'id-ID':'en-GB',
        {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    const div = document.createElement('div');
    div.className = 'bubble-row' + (isUser ? ' from-user' : '');
    div.dataset.id = msg.id;
    div.innerHTML =
        `<div class="bubble-avatar ${isUser?'user-av':'admin-av'}">${isUser?MY_NAMA:'A'}</div>` +
        `<div>` +
          `<div class="bubble ${isUser?'from-user':'from-admin'}">${escHtml(msg.isi)}</div>` +
          `<div class="bubble-time">${dt}</div>` +
        `</div>`;
    return div;
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;').replace(/\n/g,'<br>');
}

function scrollBottom() {
    const el = document.getElementById('chatMessages');
    el.scrollTop = el.scrollHeight;
}

function sendMessage() {
    const inp = document.getElementById('chatInput');
    const isi = inp.value.trim();
    if (!isi) return;
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;

    fetch('', {
        method: 'POST',
        headers: {'X-Requested-With':'XMLHttpRequest',
                  'Content-Type':'application/x-www-form-urlencoded'},
        body: 'isi=' + encodeURIComponent(isi)
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            inp.value = '';
            inp.style.height = '';
            // Append optimistically, then fetch to get server ID
            pollNew();
        }
        btn.disabled = false;
    }).catch(() => { btn.disabled = false; });
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}
function autoResize(el) {
    el.style.height = '';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

// Pengaturan Adaptive Polling
let pollInterval = 10000;       // Mulai polling dari 10 detik
const MAX_INTERVAL = 60000;     // Maksimal perlambatan 60 detik jika chat sepi
let pollTimer = null;

// Polling untuk pesan baru
function pollNew() {
    fetch('?action=fetch&after=' + LAST_ID.val, {
        headers:{'X-Requested-With':'XMLHttpRequest'}
    }).then(r=>r.json()).then(d=>{
        const container = document.getElementById('chatMessages');
        const empty     = document.getElementById('chatEmpty');
        
        if (d.messages && d.messages.length > 0) {
            d.messages.forEach(m => {
                if (empty) { empty.remove(); }
                const el = buildBubble(m);
                container.appendChild(el);
                if (+m.id > LAST_ID.val) LAST_ID.val = +m.id;
            });
            scrollBottom();
            pollInterval = 10000; // Chat aktif, kembalikan interval ke 10 detik
        } else {
            // Chat sepi, perlambat interval +5 detik tiap kali polling kosong (Adaptive Backoff)
            pollInterval = Math.min(pollInterval + 5000, MAX_INTERVAL);
        }
        pollTimer = setTimeout(pollNew, pollInterval);
    }).catch(()=>{
        pollTimer = setTimeout(pollNew, pollInterval);
    });
}

// Auto-scroll ke bawah saat load
document.addEventListener('DOMContentLoaded', function(){
    scrollBottom();
    pollTimer = setTimeout(pollNew, pollInterval);
});

// Kembalikan polling menjadi cepat saat user mulai mengetik (Tanda sedang aktif)
document.getElementById('chatInput').addEventListener('focus', function() {
    if (pollInterval > 10000) {
        pollInterval = 10000;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(pollNew, pollInterval);
    }
});
</script>
</body>
</html>
