<?php

declare(strict_types=1);

// ─── Build threads and determine active conversation ──────────────────────────
$threads = buildThreads();

// clientId auto-routing: ?clientId=X jumps straight to that client's thread
if (isset($_GET['clientId']) && !isset($_GET['phone']) && !isset($_GET['new']) && $action !== 'send') {
    $incomingClientId = (int) $_GET['clientId'];
    $incomingClient   = directoryGetClient($incomingClientId);
    $clientPhone      = $incomingClient ? clientFirstPhone($incomingClient) : '';
    if ($clientPhone) {
        $digits = last10($clientPhone);
        if (isset($threads[$digits])) {
            header('Location: ?phone=' . urlencode($threads[$digits]['phone']));
        } else {
            header('Location: ?new=1&clientId=' . $incomingClientId);
        }
        exit;
    }
}

$activePhone  = $_GET['phone'] ?? '';
$activeKey    = last10($activePhone);
$activeThread = $threads[$activeKey] ?? null;
$pageNum      = max(1, (int) ($_GET['page'] ?? 1));

// Pagination
$pagedMessages = [];
$totalPages    = 1;
$hasOlderPages = false;

if ($activeThread) {
    $total         = countThreadMessages($activePhone);
    $totalPages    = (int) ceil($total / PAGE_SIZE) ?: 1;
    $pageNum       = min($pageNum, $totalPages);
    // Page 1 = most recent; flip page number so page 1 fetches the last PAGE_SIZE rows
    $fetchPage     = $totalPages - $pageNum + 1;
    $pagedMessages = getThreadMessages($activePhone, $fetchPage);
    $hasOlderPages = $pageNum < $totalPages;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Inbox — Hermes</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5; height: 100vh;
            display: flex; flex-direction: column; overflow: hidden;
            font-size: 14px; color: #1a1a2e;
        }
        .topbar {
            background: #1e1e2e; color: #fff;
            padding: 0 18px; height: 48px;
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .topbar h1 { font-size: 15px; font-weight: 600; }
        .topbar .spacer { flex: 1; }
        .topbar .from-badge {
            font-size: 11px; background: #2d2d44;
            padding: 3px 10px; border-radius: 20px; color: #a5b4fc;
        }
        .banner {
            padding: 8px 18px; font-size: 12px; flex-shrink: 0;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .banner.warn { background: #fffbeb; color: #92400e; border-bottom: 2px solid #f59e0b; }
        .banner.info { background: #eff6ff; color: #1e40af; border-bottom: 1px solid #bfdbfe; }
        .banner code {
            background: rgba(0,0,0,.06); padding: 1px 5px;
            border-radius: 3px; font-size: 11px; user-select: all; word-break: break-all;
        }
        .layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar {
            width: 268px; background: #fff;
            border-right: 1px solid #e5e7eb;
            display: flex; flex-direction: column; flex-shrink: 0;
        }
        .sidebar-hdr {
            padding: 11px 14px; border-bottom: 1px solid #e5e7eb;
            display: flex; align-items: center; justify-content: space-between;
        }
        .sidebar-hdr span { font-weight: 600; font-size: 13px; }
        .btn-new {
            background: #6366f1; color: #fff; border: none; border-radius: 6px;
            padding: 4px 11px; font-size: 12px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: background .15s;
        }
        .btn-new:hover { background: #4f46e5; }
        .thread-list { overflow-y: auto; flex: 1; }
        .t-item {
            display: block; padding: 11px 14px; border-bottom: 1px solid #f3f4f6;
            text-decoration: none; color: inherit; transition: background .1s;
        }
        .t-item:hover  { background: #f9fafb; }
        .t-item.active { background: #eef2ff; border-left: 3px solid #6366f1; padding-left: 11px; }
        .t-name    { font-weight: 600; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .t-preview { font-size: 12px; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .t-meta    { font-size: 11px; color: #9ca3af; margin-top: 3px; display: flex; justify-content: space-between; }
        .no-threads { padding: 28px 14px; text-align: center; color: #9ca3af; font-size: 13px; line-height: 1.6; }
        .chat-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .chat-hdr {
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 11px 18px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .chat-hdr .c-name  { font-weight: 600; font-size: 15px; }
        .chat-hdr .c-phone { font-size: 12px; color: #6b7280; }
        .chat-hdr .c-link  { margin-left: auto; font-size: 12px; color: #6366f1; text-decoration: none; white-space: nowrap; }
        .chat-hdr .c-link:hover { text-decoration: underline; }
        .load-older { text-align: center; padding: 10px; flex-shrink: 0; }
        .load-older a {
            font-size: 12px; color: #6366f1; text-decoration: none;
            background: #eef2ff; padding: 4px 12px; border-radius: 20px; display: inline-block;
        }
        .load-older a:hover { background: #e0e7ff; }
        .messages {
            flex: 1; overflow-y: auto; padding: 16px 18px;
            display: flex; flex-direction: column; gap: 7px;
        }
        .day-divider {
            text-align: center; font-size: 11px; color: #9ca3af; margin: 6px 0 2px; position: relative;
        }
        .day-divider::before, .day-divider::after {
            content: ''; position: absolute; top: 50%; width: 38%; height: 1px; background: #e5e7eb;
        }
        .day-divider::before { left: 0; }
        .day-divider::after  { right: 0; }
        .bw     { display: flex; flex-direction: column; }
        .bw.out { align-items: flex-end; }
        .bw.in  { align-items: flex-start; }
        .bubble {
            max-width: 68%; padding: 8px 13px; border-radius: 16px;
            font-size: 14px; line-height: 1.45; word-break: break-word;
        }
        .bubble.out { background: #6366f1; color: #fff; border-bottom-right-radius: 4px; }
        .bubble.in  { background: #fff; color: #111; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
        .bubble.auto { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-bottom-right-radius: 4px; font-size: 13px; }
        .b-time { font-size: 11px; color: #9ca3af; margin-top: 2px; padding: 0 3px; }
        .auto-tag { font-size: 10px; color: #16a34a; margin-top: 1px; padding: 0 3px; }
        .empty-state {
            flex: 1; display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 8px; color: #9ca3af;
        }
        .empty-state .icon { font-size: 36px; }
        .empty-state p { font-size: 13px; }
        .compose { background: #fff; border-top: 1px solid #e5e7eb; padding: 12px 18px; flex-shrink: 0; }
        .compose-fields { display: flex; flex-direction: column; gap: 8px; }
        .compose-row { display: flex; gap: 8px; align-items: flex-end; }
        .compose input[type="text"], .compose select {
            flex: 1; padding: 8px 11px; border: 1px solid #ddd;
            border-radius: 8px; font-size: 13px; background: #fafafa; color: #1a1a2e;
        }
        .compose textarea {
            flex: 1; resize: none; border: 1px solid #ddd; border-radius: 8px;
            padding: 8px 11px; font-size: 13px; font-family: inherit;
            background: #fafafa; min-height: 42px; max-height: 110px; color: #1a1a2e;
        }
        .compose input:focus, .compose select:focus, .compose textarea:focus {
            outline: none; border-color: #6366f1; background: #fff;
        }
        .send-btn {
            background: #6366f1; color: #fff; border: none; border-radius: 8px;
            padding: 8px 18px; font-size: 13px; font-weight: 600;
            cursor: pointer; white-space: nowrap; flex-shrink: 0; transition: background .15s;
        }
        .send-btn:hover:not(:disabled) { background: #4f46e5; }
        .send-btn:disabled { background: #a5a6f6; cursor: not-allowed; }
        .char-count { font-size: 11px; color: #9ca3af; text-align: right; }
        .char-count.warn { color: #f59e0b; }
        .char-count.over { color: #ef4444; }
        .flash { border-radius: 6px; padding: 7px 11px; font-size: 12px; margin-bottom: 7px; }
        .flash.ok  { background: #ecfdf5; color: #065f46; }
        .flash.err { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>

<div class="topbar">
    <span>📱</span>
    <h1>Hermes SMS</h1>
    <div class="spacer"></div>
    <?php if ($fromNumber): ?>
        <div class="from-badge">From: <?= htmlspecialchars($fromNumber, ENT_QUOTES) ?></div>
    <?php endif; ?>
</div>

<?php if ($configMissing): ?>
    <div class="banner warn">
        ⚠️ <span>Twilio credentials not set. Go to <strong>System → Plugins → Hermes → Configure</strong>.</span>
    </div>
<?php endif; ?>

<?php if ($webhookKey === ''):
    $suggestedKey = bin2hex(random_bytes(16));
    $eventBase    = (string) strtok($webhookUrl, '?');
    if (!preg_match('#^https?://#', $eventBase)) {
        $eventBase = 'https://your-uisp-domain.com/crm/_plugins/hermes/public.php';
    }
    $eventUrl     = $eventBase . '?key=' . $suggestedKey;
?>
    <div class="banner warn">
        🔑 <span>
            Billing notifications are accepting unauthenticated webhook events. Set
            <strong>Webhook Key</strong> in <strong>System → Plugins → Hermes → Configure</strong>
            (e.g. <code><?= htmlspecialchars($suggestedKey, ENT_QUOTES) ?></code>), then change the endpoint in
            <strong>System → Webhooks → Endpoints</strong> to
            <code><?= htmlspecialchars($eventUrl, ENT_QUOTES) ?></code>
        </span>
    </div>
<?php endif; ?>

<?php
// Show migration banner if messages.json exists and hasn't been migrated yet
$legacyFile = DATA_DIR . '/messages.json';
$showMigrationBanner = false;
if (file_exists($legacyFile)) {
    $legacyData = json_decode(file_get_contents($legacyFile), true);
    $showMigrationBanner = is_array($legacyData)
        && !isset($legacyData[0]['migrated_to_sqlite'])
        && count(array_filter($legacyData, fn($m) => is_array($m) && isset($m['body']))) > 0;
}
?>
<?php if ($showMigrationBanner): ?>
    <div class="banner" style="background:#fefce8;color:#854d0e;border-bottom:2px solid #fbbf24;flex-shrink:0;">
        📦 <span>
            You have existing messages in <code>messages.json</code> that haven't been imported to SQLite yet.
            <a href="?page=migrate" style="color:#92400e;font-weight:600;margin-left:6px;">Run Migration →</a>
        </span>
    </div>
<?php endif; ?>

<div class="layout">
    <div class="sidebar">
        <div class="sidebar-hdr">
            <span>Conversations</span>
            <a class="btn-new" href="?new=1">+ New</a>
        </div>
        <div class="thread-list">
            <?php if (empty($threads)): ?>
                <div class="no-threads">No messages yet.<br>Click <strong>+ New</strong> to start one.</div>
            <?php else: ?>
                <?php foreach ($threads as $key => $thread):
                    $c        = $thread['client'];
                    $name     = $c ? htmlspecialchars(clientDisplayName($c), ENT_QUOTES) : htmlspecialchars($thread['phone'], ENT_QUOTES);
                    $lastMsg  = $thread['lastMsg'];
                    $preview  = htmlspecialchars(mb_strimwidth($lastMsg['body'] ?? '', 0, 46, '…'), ENT_QUOTES);
                    $arrow    = $lastMsg['direction'] === 'outbound' ? '↑ ' : '↓ ';
                    $time     = !empty($lastMsg['timestamp']) ? date('M j, g:ia', strtotime($lastMsg['timestamp'])) : '';
                    $isActive = (last10($thread['phone']) === $activeKey);
                ?>
                    <a class="t-item <?= $isActive ? 'active' : '' ?>" href="?phone=<?= urlencode($thread['phone']) ?>">
                        <div class="t-name"><?= $name ?></div>
                        <div class="t-preview"><?= $arrow ?><?= $preview ?></div>
                        <div class="t-meta">
                            <span><?= htmlspecialchars($thread['phone'], ENT_QUOTES) ?></span>
                            <span><?= $time ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="chat-area">
        <?php
        if (isset($_GET['new'])) {
            require __DIR__ . '/new_message.php';
        } elseif ($activeThread) {
            require __DIR__ . '/thread.php';
        } else {
            require __DIR__ . '/empty.php';
        }
        ?>
    </div>
</div>

<script>
    const msgList = document.getElementById('msgList');

    function scrollToBottom(force) {
        if (!msgList) return;
        const nearBottom = msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 120;
        if (force || nearBottom) {
            requestAnimationFrame(() => { msgList.scrollTop = msgList.scrollHeight; });
        }
    }

    const justRefreshed = sessionStorage.getItem('hermes_refresh') === '1';
    sessionStorage.removeItem('hermes_refresh');
    scrollToBottom(justRefreshed);

    function updateCount(taId, countId) {
        const ta  = document.getElementById(taId);
        const cnt = document.getElementById(countId);
        if (!ta || !cnt) return;
        const len  = ta.value.length;
        const segs = Math.ceil(len / 160) || 1;
        cnt.textContent = len <= 160 ? `${len} / 160` : `${len} chars · ${segs} segments`;
        cnt.className = 'char-count' + (len > 320 ? ' over' : len > 160 ? ' warn' : '');
    }

    function ctrlEnterSubmit(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            e.target.closest('form').submit();
        }
    }

    function onClientPick(select) {
        const phone = select.options[select.selectedIndex]?.dataset?.phone || '';
        const field = document.getElementById('toNumber');
        if (field && phone) field.value = phone;
    }

    let lastCount = msgList ? msgList.querySelectorAll('.bw').length : 0;
    setInterval(() => {
        if (document.activeElement?.tagName === 'TEXTAREA') return;
        fetch(window.location.href)
            .then(r => r.text())
            .then(html => {
                const doc      = new DOMParser().parseFromString(html, 'text/html');
                const newList  = doc.getElementById('msgList');
                const newCount = newList ? newList.querySelectorAll('.bw').length : 0;
                if (newCount > lastCount) {
                    sessionStorage.setItem('hermes_refresh', '1');
                    window.location.reload();
                }
            })
            .catch(() => {});
    }, 10000);
</script>
</body>
</html>