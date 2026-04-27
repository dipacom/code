<?php
require_once '../includes/config.php';
require_once '../includes/logger.php';
requireLogin('admin');

$lang = $_COOKIE['lang'] ?? 'id';
$uid  = (int)($_GET['uid'] ?? 0);   // user thread yang sedang dibuka

// ── Hapus percakapan (seluruh thread satu user) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_thread' && $uid) {
    // Ambil info user untuk log sebelum dihapus
    $tu = $pdo->prepare("SELECT nama_lengkap, role, nim, nidn FROM users WHERE id=?");
    $tu->execute([$uid]);
    $tuData = $tu->fetch();
    $jumlah = (int)$pdo->prepare("SELECT COUNT(*) FROM pesan WHERE user_id=?")->execute([$uid]) ?:
              (int)$pdo->query("SELECT COUNT(*) FROM pesan WHERE user_id=$uid")->fetchColumn();

    $pdo->prepare("DELETE FROM pesan WHERE user_id=?")->execute([$uid]);
    writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_hapus_chat',
        "Hapus percakapan dengan {$tuData['nama_lengkap']} ({$tuData['role']})");
    $_SESSION['flash'] = ['type'=>'success',
        'msg' => $lang==='id' ? 'Percakapan berhasil dihapus.' : 'Conversation deleted.'];
    header("Location: /admin/chat.php");
    exit;
}

// ── AJAX: fetch pesan baru setelah ID tertentu ──
if (isset($_GET['action']) && $_GET['action'] === 'fetch' && $uid) {
    $after = (int)($_GET['after'] ?? 0);
    $stmt  = $pdo->prepare(
        "SELECT id, pengirim_role, isi, created_at FROM pesan
         WHERE user_id=? AND id>? ORDER BY created_at ASC"
    );
    $stmt->execute([$uid, $after]);
    $rows = $stmt->fetchAll();
    // Tandai pesan user ini sebagai dibaca
    $pdo->prepare("UPDATE pesan SET dibaca=1 WHERE user_id=? AND pengirim_role='user' AND dibaca=0")
        ->execute([$uid]);
    header('Content-Type: application/json');
    echo json_encode(['messages' => $rows]);
    exit;
}

// ── AJAX / POST: kirim balasan ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uid && ($_POST['action'] ?? '') !== 'delete_thread') {
    $isi    = trim($_POST['isi'] ?? '');
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($isi !== '') {
        $pdo->prepare("INSERT INTO pesan (user_id, pengirim_role, isi) VALUES (?,?,?)")
            ->execute([$uid, 'admin', $isi]);
        // Log setiap pesan admin (hanya non-AJAX untuk tidak membanjiri log)
        if (!$isAjax) {
            writeLog($pdo, (int)$_SESSION['user_id'], 'admin', 'admin_kirim_chat',
                mb_strimwidth($isi, 0, 100, '…'));
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    header("Location: /admin/chat.php?uid={$uid}");
    exit;
}

// ── Tandai pesan user ini sebagai dibaca saat thread dibuka ──
if ($uid) {
    $pdo->prepare("UPDATE pesan SET dibaca=1 WHERE user_id=? AND pengirim_role='user' AND dibaca=0")
        ->execute([$uid]);
}

// ── Daftar user yang pernah mengirim pesan (dengan unread count) ──
$userList = $pdo->query("
    SELECT u.id, u.nama_lengkap, u.email, u.role,
           COUNT(p.id) AS total_msg,
           SUM(CASE WHEN p.pengirim_role='user' AND p.dibaca=0 THEN 1 ELSE 0 END) AS unread,
           MAX(p.created_at) AS last_msg
    FROM pesan p
    JOIN users u ON u.id = p.user_id
    GROUP BY u.id
    ORDER BY last_msg DESC
")->fetchAll();

// ── Thread percakapan untuk user yang dipilih ──
$messages   = [];
$last_id    = 0;
$threadUser = null;
if ($uid) {
    $stmt = $pdo->prepare(
        "SELECT id, pengirim_role, isi, created_at FROM pesan
         WHERE user_id=? ORDER BY created_at ASC"
    );
    $stmt->execute([$uid]);
    $messages = $stmt->fetchAll();
    $last_id  = empty($messages) ? 0 : (int)end($messages)['id'];

    $tu = $pdo->prepare("SELECT id, nama_lengkap, email, role, nim, nidn FROM users WHERE id=?");
    $tu->execute([$uid]);
    $threadUser = $tu->fetch();
}

// Total unread global (untuk badge)
$total_unread = (int)$pdo->query(
    "SELECT COUNT(*) FROM pesan WHERE pengirim_role='user' AND dibaca=0"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $lang==='id'?'Pesan Masuk':'Inbox' ?> — LPPM Admin</title>
<link rel="stylesheet" href="../assets/css/style.css?v=4">
<style>
/* ── Two-panel chat layout ── */
.chat-panel {
  display:grid;
  grid-template-columns:280px 1fr;
  height:calc(100vh - 120px);
  background:#fff;
  border-radius:14px;
  border:1.5px solid #e2e8f0;
  overflow:hidden;
}
@media(max-width:680px){
  .chat-panel{ grid-template-columns:1fr; }
  .chat-thread{ display:<?= $uid ? 'flex' : 'none' ?>; }
  .chat-userlist{ display:<?= $uid ? 'none' : 'flex' ?>; }
}

/* ── User list (left panel) ── */
.chat-userlist {
  display:flex; flex-direction:column;
  border-right:1.5px solid #f1f5f9;
  overflow:hidden;
}
.chat-list-hd {
  padding:14px 16px; border-bottom:1.5px solid #f1f5f9;
  font-size:13px; font-weight:700; color:#1e293b;
  background:#fafbfc;
  display:flex; align-items:center; justify-content:space-between;
}
.chat-list-scroll { flex:1; overflow-y:auto; }
.chat-user-item {
  display:flex; align-items:center; gap:10px;
  padding:11px 14px; cursor:pointer;
  border-bottom:1px solid #f8fafc;
  text-decoration:none; color:inherit;
  transition:background .12s;
}
.chat-user-item:hover   { background:#f8fafc; }
.chat-user-item.active  { background:#eff6ff; }
.cu-avatar {
  width:36px; height:36px; border-radius:50%; flex-shrink:0;
  background:#1e3a5f; color:#fff;
  display:flex; align-items:center; justify-content:center;
  font-size:13px; font-weight:700;
}
.cu-body    { flex:1; min-width:0; }
.cu-name    { font-size:13px; font-weight:600; color:#1e293b; }
.cu-sub     { font-size:11px; color:#94a3b8; margin-top:1px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cu-unread  {
  flex-shrink:0; min-width:18px; height:18px; padding:0 5px;
  background:#ef4444; color:#fff; font-size:10px; font-weight:700;
  border-radius:9px; display:flex; align-items:center; justify-content:center;
}
.cu-empty {
  padding:32px 16px; text-align:center; color:#94a3b8; font-size:13px;
}

/* ── Thread (right panel) ── */
.chat-thread {
  display:flex; flex-direction:column; overflow:hidden;
}
.chat-thread-hd {
  padding:13px 18px; border-bottom:1.5px solid #f1f5f9;
  background:#fafbfc;
  display:flex; align-items:center; gap:12px;
}
.ct-av {
  width:36px; height:36px; border-radius:50%;
  background:#e0e7ff; color:#3730a3;
  display:flex; align-items:center; justify-content:center;
  font-size:13px; font-weight:700; flex-shrink:0;
}
.ct-name { font-size:14px; font-weight:700; color:#1e293b; }
.ct-sub  { font-size:11px; color:#94a3b8; margin-top:1px; }

.chat-messages {
  flex:1; overflow-y:auto; padding:16px;
  display:flex; flex-direction:column; gap:10px;
  scroll-behavior:smooth;
}
.chat-empty {
  flex:1; display:flex; flex-direction:column;
  align-items:center; justify-content:center;
  color:#94a3b8; gap:10px;
}
.chat-empty svg { opacity:.3; }
.chat-placeholder {
  flex:1; display:flex; flex-direction:column;
  align-items:center; justify-content:center;
  color:#94a3b8; gap:12px; padding:32px;
}

/* Bubbles — same as user side */
.bubble-row { display:flex; align-items:flex-end; gap:8px; }
.bubble-row.from-admin { flex-direction:row-reverse; }
.bubble-avatar {
  width:28px; height:28px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700;
}
.bubble-avatar.admin-av { background:#1e3a5f; color:#fff; }
.bubble-avatar.user-av  { background:#e0e7ff; color:#3730a3; }
.bubble {
  max-width:68%; padding:10px 14px; border-radius:14px;
  font-size:13px; line-height:1.6; word-break:break-word;
  white-space:pre-wrap;
}
.bubble.from-user  { background:#f1f5f9; color:#1e293b; border-bottom-left-radius:4px; }
.bubble.from-admin { background:#1e3a5f; color:#fff;    border-bottom-right-radius:4px; }
.bubble-time {
  font-size:10px; color:#94a3b8; margin-top:3px;
  text-align:right;
}
.bubble-row.from-user .bubble-time { text-align:left; }

/* Input area */
.chat-input-area {
  padding:10px 14px; border-top:1.5px solid #f1f5f9;
  background:#fafbfc; display:flex; align-items:flex-end; gap:8px;
}
.chat-textarea {
  flex:1; resize:none; border:1.5px solid #e2e8f0; border-radius:10px;
  padding:9px 12px; font-size:13px; line-height:1.5; font-family:inherit;
  max-height:100px; min-height:40px; outline:none; transition:border-color .15s;
}
.chat-textarea:focus { border-color:#2563eb; }
.chat-send-btn {
  width:38px; height:38px; flex-shrink:0;
  background:#1e3a5f; color:#fff; border:none; border-radius:10px;
  cursor:pointer; display:flex; align-items:center; justify-content:center;
  transition:background .15s;
}
.chat-send-btn:hover    { background:#2d5282; }
.chat-send-btn:disabled { background:#94a3b8; cursor:default; }
</style>
</head>
<body>
<div class="wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div>
        <div class="topbar-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:-2px;margin-right:6px">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <?= $lang==='id'?'Pesan Masuk':'Inbox' ?>
          <?php if ($total_unread): ?>
          <span class="nav-badge" style="font-size:11px;padding:1px 7px;vertical-align:middle;margin-left:6px">
            <?= $total_unread ?> <?= $lang==='id'?'belum dibaca':'unread' ?>
          </span>
          <?php endif; ?>
          <span class="breadcrumb"><?= $lang==='id'?'Kelola percakapan dengan pengguna':'Manage conversations with users' ?></span>
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

    <?php
    $chatFlash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($chatFlash):
    ?>
    <div class="alert alert-<?= $chatFlash['type'] ?>" style="margin-bottom:12px"><?= $chatFlash['msg'] ?></div>
    <?php endif; ?>

    <div class="chat-panel">

      <!-- LEFT: User list -->
      <div class="chat-userlist">
        <div class="chat-list-hd">
          <span><?= $lang==='id'?'Percakapan':'Conversations' ?></span>
          <?php if ($total_unread): ?>
          <span class="cu-unread"><?= $total_unread ?></span>
          <?php endif; ?>
        </div>
        <div class="chat-list-scroll">
          <?php if (empty($userList)): ?>
          <div class="cu-empty">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"
                 style="display:block;margin:0 auto 10px;opacity:.35">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <?= $lang==='id'?'Belum ada pesan masuk':'No messages yet' ?>
          </div>
          <?php else: ?>
          <?php foreach ($userList as $u):
            $initial = strtoupper(mb_substr($u['nama_lengkap'], 0, 1));
            $isActive = ($u['id'] == $uid);
            $subInfo  = $u['role'] === 'dosen'
                ? 'Dosen'
                : (htmlspecialchars($u['nim'] ?? $u['role']));
          ?>
          <a href="?uid=<?= $u['id'] ?>"
             class="chat-user-item <?= $isActive ? 'active' : '' ?>">
            <div class="cu-avatar"><?= $initial ?></div>
            <div class="cu-body">
              <div class="cu-name"><?= htmlspecialchars(mb_strimwidth($u['nama_lengkap'],0,24,'…')) ?></div>
              <div class="cu-sub"><?= $subInfo ?> &middot; <?= $u['total_msg'] ?> <?= $lang==='id'?'pesan':'msgs' ?></div>
            </div>
            <?php if ($u['unread'] > 0): ?>
            <div class="cu-unread"><?= $u['unread'] ?></div>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: Thread -->
      <div class="chat-thread">
        <?php if (!$uid || !$threadUser): ?>
        <div class="chat-placeholder">
          <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <p style="font-size:13px;text-align:center;max-width:220px;margin:0">
            <?= $lang==='id'
              ? 'Pilih percakapan dari daftar kiri untuk memulai membalas.'
              : 'Select a conversation from the left to start replying.' ?>
          </p>
        </div>
        <?php else: ?>

        <!-- Thread header -->
        <div class="chat-thread-hd">
          <a href="?uid=" style="color:#94a3b8;text-decoration:none;display:flex;align-items:center;gap:4px;font-size:12px;margin-right:4px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          </a>
          <div class="ct-av"><?= strtoupper(mb_substr($threadUser['nama_lengkap'], 0, 1)) ?></div>
          <div style="flex:1">
            <div class="ct-name"><?= htmlspecialchars($threadUser['nama_lengkap']) ?></div>
            <div class="ct-sub">
              <?= htmlspecialchars($threadUser['email']) ?>
              &middot; <?= ucfirst($threadUser['role']) ?>
              <?php if ($threadUser['nim']): ?>
              &middot; <?= htmlspecialchars($threadUser['nim']) ?>
              <?php elseif ($threadUser['nidn']): ?>
              &middot; NIDN <?= htmlspecialchars($threadUser['nidn']) ?>
              <?php endif; ?>
            </div>
          </div>
          <!-- Tombol hapus percakapan -->
          <button onclick="confirmDeleteThread()" title="<?= $lang==='id'?'Hapus percakapan':'Delete conversation' ?>"
                  style="flex-shrink:0;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;
                         border-radius:8px;padding:5px 10px;font-size:11px;font-weight:600;
                         cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
            </svg>
            <?= $lang==='id'?'Hapus':'Delete' ?>
          </button>
        </div>

        <!-- Form hapus (tersembunyi, di-submit via JS) -->
        <form id="form-delete-thread" method="POST" action="<?= BASE_URL ?>/admin/chat.php?uid=<?= $uid ?>" style="display:none">
          <input type="hidden" name="action" value="delete_thread">
        </form>

        <!-- Messages -->
        <div class="chat-messages" id="chatMessages">
          <?php if (empty($messages)): ?>
          <div class="chat-empty" id="chatEmpty">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <p style="font-size:12px;margin:0"><?= $lang==='id'?'Belum ada pesan':'No messages yet' ?></p>
          </div>
          <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <?php
            $isAdmin = ($m['pengirim_role'] === 'admin');
            $rowCls  = $isAdmin ? 'from-admin' : '';
            $bubCls  = $isAdmin ? 'from-admin' : 'from-user';
            $avCls   = $isAdmin ? 'admin-av'   : 'user-av';
            $avLtr   = $isAdmin ? 'A' : strtoupper(mb_substr($threadUser['nama_lengkap'], 0, 1));
            $dt      = date('d M H:i', strtotime($m['created_at']));
            ?>
            <div class="bubble-row <?= $rowCls ?>" data-id="<?= $m['id'] ?>">
              <div class="bubble-avatar <?= $avCls ?>"><?= $avLtr ?></div>
              <div>
            <div class="bubble <?= $bubCls ?>"><?= htmlspecialchars($m['isi'], ENT_QUOTES) ?></div>
                <div class="bubble-time"><?= $dt ?></div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Reply input -->
        <div class="chat-input-area">
          <textarea id="chatInput" class="chat-textarea" rows="1"
            placeholder="<?= $lang==='id'?'Tulis balasan…':'Type a reply…' ?>"
            onkeydown="handleKey(event)"
            oninput="autoResize(this)"></textarea>
          <button class="chat-send-btn" id="sendBtn" onclick="sendReply()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </button>
        </div>

        <?php endif; // end thread ?>
      </div><!-- /chat-thread -->

    </div><!-- /chat-panel -->
    </div><!-- /page-content -->
  </div>
</div>

<script>
const LANG    = '<?= $lang ?>';
const UID     = <?= (int)$uid ?>;
const U_INIT  = <?= json_encode($threadUser ? strtoupper(mb_substr($threadUser['nama_lengkap'],0,1)) : 'U') ?>;
const LAST_ID = { val: <?= $last_id ?> };

function buildBubble(msg) {
    const isAdmin = msg.pengirim_role === 'admin';
    const dt = new Date(msg.created_at).toLocaleString(LANG==='id'?'id-ID':'en-GB',
        {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    const div = document.createElement('div');
    div.className = 'bubble-row' + (isAdmin ? ' from-admin' : '');
    div.dataset.id = msg.id;
    div.innerHTML =
        `<div class="bubble-avatar ${isAdmin?'admin-av':'user-av'}">${isAdmin?'A':U_INIT}</div>` +
        `<div>` +
          `<div class="bubble ${isAdmin?'from-admin':'from-user'}">${escHtml(msg.isi)}</div>` +
          `<div class="bubble-time">${dt}</div>` +
        `</div>`;
    return div;
}
function escHtml(s){
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;').replace(/\n/g,'<br>');
}
function scrollBottom(){
    const el = document.getElementById('chatMessages');
    if (el) el.scrollTop = el.scrollHeight;
}
function sendReply(){
    const inp = document.getElementById('chatInput');
    const isi = inp.value.trim();
    if (!isi || !UID) return;
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    fetch('?uid='+UID, {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest',
                 'Content-Type':'application/x-www-form-urlencoded'},
        body:'isi='+encodeURIComponent(isi)
    }).then(r=>r.json()).then(d=>{
        if(d.ok){ inp.value=''; inp.style.height=''; pollNew(); }
        btn.disabled = false;
    }).catch(()=>{ btn.disabled=false; });
}
function handleKey(e){
    if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendReply(); }
}
function autoResize(el){
    el.style.height='';
    el.style.height=Math.min(el.scrollHeight,100)+'px';
}

// Pengaturan Adaptive Polling
let pollInterval = 10000;
const MAX_INTERVAL = 60000;
let pollTimer = null;

function pollNew(){
    if(!UID) return;
    fetch('?uid='+UID+'&action=fetch&after='+LAST_ID.val, {
        headers:{'X-Requested-With':'XMLHttpRequest'}
    }).then(r=>r.json()).then(d=>{
        const container = document.getElementById('chatMessages');
        const empty     = document.getElementById('chatEmpty');
        if(!container) return;
        
        if (d.messages && d.messages.length > 0) {
            d.messages.forEach(m=>{
                if(empty){ empty.remove(); }
                container.appendChild(buildBubble(m));
                if(+m.id > LAST_ID.val) LAST_ID.val = +m.id;
            });
            scrollBottom();
            pollInterval = 10000; // Kembalikan ke agresif jika ada pesan
        } else {
            pollInterval = Math.min(pollInterval + 5000, MAX_INTERVAL);
        }
        pollTimer = setTimeout(pollNew, pollInterval);
    }).catch(()=>{
        pollTimer = setTimeout(pollNew, pollInterval);
    });
}
document.addEventListener('DOMContentLoaded', function(){
    scrollBottom();
    if(UID) pollTimer = setTimeout(pollNew, pollInterval);
});

const chatInput = document.getElementById('chatInput');
if (chatInput) {
    chatInput.addEventListener('focus', function() {
        if (pollInterval > 10000) {
            pollInterval = 10000;
            clearTimeout(pollTimer);
            pollTimer = setTimeout(pollNew, pollInterval);
        }
    });
}
function confirmDeleteThread(){
    const msg = LANG==='id'
        ? 'Hapus seluruh percakapan ini? Semua pesan tidak bisa dikembalikan.'
        : 'Delete this entire conversation? All messages cannot be recovered.';
    if (confirm(msg)) {
        document.getElementById('form-delete-thread').submit();
    }
}
</script>
</body>
</html>
