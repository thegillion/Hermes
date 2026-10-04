<?php

declare(strict_types=1);

// Handle mark-as-read AJAX POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $ids = json_decode($_POST['mark_read'], true) ?: [];
    markAsRead($ids);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$unread      = getUnreadMessages();
$totalUnread = count($unread);

// Group by sender
$grouped = [];
foreach ($unread as $msg) {
    $key = last10($msg['from']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'phone'    => $msg['from'],
            'client'   => $msg['clientId'] ? directoryGetClient((int) $msg['clientId']) : directoryLookupPhone($msg['from']),
            'messages' => [],
        ];
    }
    $grouped[$key]['messages'][] = $msg;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hermes — New Messages</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fff; height: 100vh; display: flex; flex-direction: column;
            overflow: hidden; font-size: 13px; color: #1a1a2e;
        }
        .widget-header {
            background: #1e1e2e; color: #fff; padding: 10px 16px;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }
        .widget-header .left { display: flex; align-items: center; gap: 10px; }
        .widget-header h2 { font-size: 14px; font-weight: 600; }
        .badge { background: #ef4444; color: #fff; border-radius: 12px; padding: 2px 8px; font-size: 11px; font-weight: 700; min-width: 20px; text-align: center; }
        .badge.zero { background: #6b7280; }
        .widget-header a { font-size: 11px; color: #a5b4fc; text-decoration: none; background: #2d2d44; padding: 3px 9px; border-radius: 12px; white-space: nowrap; }
        .widget-header a:hover { background: #3d3d5c; }
        .mark-all-bar { background: #f0f2f5; border-bottom: 1px solid #e5e7eb; padding: 7px 16px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; font-size: 12px; color: #6b7280; }
        .mark-all-bar button { background: none; border: 1px solid #d1d5db; border-radius: 6px; padding: 3px 10px; font-size: 11px; cursor: pointer; color: #374151; }
        .mark-all-bar button:hover { background: #e5e7eb; }
        .msg-list { flex: 1; overflow-y: auto; }
        .empty-state { flex: 1; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; color: #9ca3af; padding: 30px; }
        .empty-state .icon { font-size: 40px; }
        .empty-state p { font-size: 13px; text-align: center; }
        .sender-group { border-bottom: 1px solid #f3f4f6; }
        .sender-header { display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: #fafafa; cursor: pointer; user-select: none; }
        .sender-header:hover { background: #f3f4f6; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex-shrink: 0; }
        .sender-info { flex: 1; min-width: 0; }
        .sender-name { font-weight: 600; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sender-phone { font-size: 11px; color: #6b7280; }
        .sender-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
        .unread-count { background: #6366f1; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; font-weight: 700; }
        .sender-time { font-size: 10px; color: #9ca3af; }
        .msg-bubbles { padding: 6px 16px 10px 62px; display: none; flex-direction: column; gap: 5px; }
        .msg-bubble { background: #f3f4f6; border-left: 3px solid #6366f1; border-radius: 0 8px 8px 0; padding: 6px 10px; font-size: 12px; line-height: 1.4; color: #111; word-break: break-word; }
        .msg-bubble-time { font-size: 10px; color: #9ca3af; margin-top: 2px; }
        .bubble-actions { display: flex; gap: 8px; align-items: center; margin-top: 6px; }
        .open-link { font-size: 11px; color: #6366f1; text-decoration: none; font-weight: 600; }
        .open-link:hover { text-decoration: underline; }
        .mark-read-btn { background: none; border: 1px solid #e5e7eb; border-radius: 6px; padding: 2px 8px; font-size: 11px; color: #6b7280; cursor: pointer; }
        .mark-read-btn:hover { background: #f3f4f6; color: #374151; }
    </style>
</head>
<body>

<div class="widget-header">
    <div class="left">
        <h2>📨 New SMS Messages</h2>
        <span class="badge <?= $totalUnread === 0 ? 'zero' : '' ?>"><?= $totalUnread ?></span>
    </div>
    <a href="?" target="_blank">Open Full Inbox ↗</a>
</div>

<?php if ($totalUnread > 0): ?>
<div class="mark-all-bar">
    <span><?= $totalUnread ?> unread from <?= count($grouped) ?> sender<?= count($grouped) !== 1 ? 's' : '' ?></span>
    <button onclick="markAllRead()">✓ Mark all as read</button>
</div>
<?php endif; ?>

<div class="msg-list">
    <?php if (empty($grouped)): ?>
        <div class="empty-state">
            <div class="icon">✅</div>
            <p>You're all caught up!<br>No new incoming messages.</p>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $key => $group):
            $client   = $group['client'];
            $name     = $client ? clientDisplayName($client) : $group['phone'];
            $initial  = strtoupper(mb_substr($name, 0, 1));
            $msgs     = $group['messages'];
            $lastMsg  = $msgs[0];
            $lastTime = date('M j, g:ia', strtotime($lastMsg['timestamp']));
            $msgIds   = array_column($msgs, 'id');
        ?>
        <div class="sender-group" data-ids='<?= htmlspecialchars(json_encode($msgIds), ENT_QUOTES) ?>'>
            <div class="sender-header" onclick="toggleGroup(this)">
                <div class="avatar"><?= htmlspecialchars($initial, ENT_QUOTES) ?></div>
                <div class="sender-info">
                    <div class="sender-name"><?= htmlspecialchars($name, ENT_QUOTES) ?></div>
                    <div class="sender-phone"><?= htmlspecialchars($group['phone'], ENT_QUOTES) ?></div>
                </div>
                <div class="sender-meta">
                    <span class="unread-count"><?= count($msgs) ?> new</span>
                    <span class="sender-time"><?= $lastTime ?></span>
                </div>
            </div>
            <div class="msg-bubbles">
                <?php foreach (array_reverse($msgs) as $msg): ?>
                    <div class="msg-bubble">
                        <?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?>
                        <div class="msg-bubble-time"><?= date('g:ia', strtotime($msg['timestamp'])) ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="bubble-actions">
                    <a class="open-link" href="?phone=<?= urlencode($group['phone']) ?>" target="_blank"
                       onclick="markGroupRead(this.closest('.sender-group'))">💬 Open Conversation</a>
                    <button class="mark-read-btn" onclick="markGroupRead(this.closest('.sender-group'))">✓ Mark as read</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    function toggleGroup(header) {
        const b = header.nextElementSibling;
        const open = b.style.display === 'flex';
        b.style.display = open ? 'none' : 'flex';
        b.style.flexDirection = 'column';
    }
    function markGroupRead(groupEl) {
        postMarkRead(JSON.parse(groupEl.dataset.ids || '[]'), () => groupEl.remove());
    }
    function markAllRead() {
        const ids = [];
        document.querySelectorAll('.sender-group').forEach(g => ids.push(...JSON.parse(g.dataset.ids || '[]')));
        postMarkRead(ids, () => window.location.reload());
    }
    function postMarkRead(ids, onSuccess) {
        const body = new FormData();
        body.append('mark_read', JSON.stringify(ids));
        fetch('?page=adminwidget', { method: 'POST', body })
            .then(r => r.json()).then(d => { if (d.ok) onSuccess(); }).catch(console.error);
    }
    setTimeout(() => window.location.reload(), 60000);
</script>
</body>
</html>
<?php exit;
