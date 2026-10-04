<?php

declare(strict_types=1);

use Twilio\Rest\Client as TwilioClient;

// UISP passes _clientId (underscore prefix) in widget iframe URLs
parse_str($_SERVER['QUERY_STRING'] ?? '', $widgetQuery);
$widgetClientId = isset($widgetQuery['_clientId']) && $widgetQuery['_clientId'] !== ''
    ? (int) $widgetQuery['_clientId']
    : (isset($_GET['clientId']) ? (int) $_GET['clientId'] : null);

// Fetch the full individual client record — the bulk /clients list does not
// include contacts/phones, only the single-client endpoint does.
$widgetClient = null;
if ($widgetClientId && ($api = directoryApi())) {
    try {
        $widgetClient = $api->get("clients/{$widgetClientId}");
        // Keep the shared directory fresh for this client while we have it.
        if (is_array($widgetClient)) {
            directoryStoreClient($widgetClient, $widgetClient['contacts'] ?? null);
        }
    } catch (\Throwable $e) {
        $log->appendLog("[Hermes] Widget could not fetch client {$widgetClientId}: " . $e->getMessage());
    }
}

$widgetPhone = $widgetClient ? clientFirstPhone($widgetClient) : '';
$widgetName  = $widgetClient ? clientDisplayName($widgetClient) : 'Client';

// ─── Handle send from widget ──────────────────────────────────────────────────
$widgetSuccess = '';
$widgetError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['widget_send']) && !$configMissing) {
    $wTo   = normalizePhone(trim($_POST['toNumber'] ?? $widgetPhone));
    $wBody = trim($_POST['message'] ?? '');

    if ($wTo && $wBody) {
        try {
            $twilio = new TwilioClient($accountSid, $authToken);
            $sent   = $twilio->messages->create($wTo, [
                'from' => $fromNumber,
                'body' => $wBody,
            ]);
            addMessage([
                'id'        => $sent->sid,
                'direction' => 'outbound',
                'from'      => $fromNumber,
                'to'        => $wTo,
                'body'      => $wBody,
                'timestamp' => date('c'),
                'clientId'  => $widgetClientId,
            ]);
            $log->appendLog('[Hermes] Widget sent SMS to ' . $wTo . ': ' . mb_strimwidth($wBody, 0, 80, '…'));
            header('Location: ?page=clientwidget&_clientId=' . $widgetClientId);
            exit;
        } catch (\Throwable $e) {
            $widgetError = $e->getMessage();
            $log->appendLog('[Hermes] Widget send error: ' . $e->getMessage());
        }
    } else {
        $widgetError = 'Phone number and message are required.';
    }
}

// ─── Load thread for this client ─────────────────────────────────────────────
$rawMsgs    = $widgetPhone ? getRecentThreadMessages($widgetPhone) : [];
$widgetMsgs = array_map('rowToMsg', $rawMsgs);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMS — <?= htmlspecialchars($widgetName, ENT_QUOTES) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fff;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            font-size: 13px;
            color: #1a1a2e;
        }
        .widget-header {
            background: #1e1e2e; color: #fff;
            padding: 10px 14px; display: flex;
            align-items: center; justify-content: space-between; flex-shrink: 0;
        }
        .widget-header .title { font-weight: 600; font-size: 13px; }
        .widget-header .phone { font-size: 11px; color: #a5b4fc; }
        .widget-header a {
            font-size: 11px; color: #a5b4fc; text-decoration: none;
            background: #2d2d44; padding: 3px 9px; border-radius: 12px;
        }
        .widget-header a:hover { background: #3d3d5c; }
        .no-phone {
            flex: 1; display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 8px; color: #9ca3af; padding: 20px; text-align: center;
        }
        .no-phone .icon { font-size: 32px; }
        .messages {
            flex: 1; overflow-y: auto; padding: 12px 14px;
            display: flex; flex-direction: column; gap: 6px; background: #f9fafb;
        }
        .no-msgs { color: #9ca3af; text-align: center; margin: auto; font-size: 12px; }
        .bw     { display: flex; flex-direction: column; }
        .bw.out { align-items: flex-end; }
        .bw.in  { align-items: flex-start; }
        .bubble {
            max-width: 80%; padding: 7px 11px; border-radius: 14px;
            font-size: 13px; line-height: 1.4; word-break: break-word;
        }
        .bubble.out  { background: #6366f1; color: #fff; border-bottom-right-radius: 3px; }
        .bubble.in   { background: #fff; color: #111; border: 1px solid #e5e7eb; border-bottom-left-radius: 3px; }
        .bubble.auto { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-bottom-right-radius: 3px; font-size: 12px; }
        .b-time { font-size: 10px; color: #9ca3af; margin-top: 2px; padding: 0 2px; }
        .auto-tag { font-size: 10px; color: #16a34a; }
        .day-div { text-align: center; font-size: 10px; color: #9ca3af; margin: 4px 0; }
        .compose { background: #fff; border-top: 1px solid #e5e7eb; padding: 10px 14px; flex-shrink: 0; }
        .flash { font-size: 11px; padding: 5px 9px; border-radius: 5px; margin-bottom: 7px; }
        .flash.ok  { background: #ecfdf5; color: #065f46; }
        .flash.err { background: #fef2f2; color: #991b1b; }
        .compose-row { display: flex; gap: 7px; align-items: flex-end; }
        .compose textarea {
            flex: 1; resize: none; border: 1px solid #ddd; border-radius: 8px;
            padding: 7px 10px; font-size: 13px; font-family: inherit;
            background: #fafafa; min-height: 38px; max-height: 90px;
        }
        .compose textarea:focus { outline: none; border-color: #6366f1; background: #fff; }
        .send-btn {
            background: #6366f1; color: #fff; border: none; border-radius: 8px;
            padding: 7px 14px; font-size: 13px; font-weight: 600;
            cursor: pointer; white-space: nowrap; flex-shrink: 0;
        }
        .send-btn:hover { background: #4f46e5; }
        .send-btn:disabled { background: #a5b4fc; cursor: not-allowed; }
        .char-count { font-size: 10px; color: #9ca3af; text-align: right; margin-top: 3px; }
    </style>
</head>
<body>

<div class="widget-header">
    <div>
        <div class="title">📱 SMS — <?= htmlspecialchars($widgetName, ENT_QUOTES) ?></div>
        <?php if ($widgetPhone): ?>
            <div class="phone"><?= htmlspecialchars($widgetPhone, ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
    <a href="?phone=<?= urlencode($widgetPhone) ?>" target="_blank">Open Full Inbox ↗</a>
</div>

<?php if (!$widgetPhone): ?>
    <div class="no-phone">
        <div class="icon">📵</div>
        <div>No phone number on file for this client.<br>Add one in their contact info to enable SMS.</div>
    </div>
<?php else: ?>

    <div class="messages" id="msgList">
        <?php if (empty($widgetMsgs)): ?>
            <div class="no-msgs">No messages yet. Send one below!</div>
        <?php else:
            $lastDay = '';
            foreach ($widgetMsgs as $msg):
                $dir     = $msg['direction'] === 'outbound' ? 'out' : 'in';
                $isAuto  = $msg['auto'] ?? false;
                $ts      = strtotime($msg['timestamp'] ?? 'now');
                $day     = date('M j, Y', $ts);
                $timeStr = date('g:ia', $ts);
        ?>
            <?php if ($day !== $lastDay): $lastDay = $day; ?>
                <div class="day-div"><?= htmlspecialchars($day, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <div class="bw <?= $dir ?>">
                <div class="bubble <?= $isAuto ? 'auto' : $dir ?>">
                    <?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?>
                </div>
                <div class="b-time">
                    <?= $timeStr ?><?= $isAuto ? ' · <span class="auto-tag">⚡ auto</span>' : '' ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="compose">
        <?php if ($widgetSuccess): ?><div class="flash ok">✅ <?= htmlspecialchars($widgetSuccess, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($widgetError):   ?><div class="flash err">❌ <?= htmlspecialchars($widgetError, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if ($configMissing): ?>
            <div class="flash err">⚠️ Twilio not configured — go to System → Plugins → Hermes → Configure.</div>
        <?php else: ?>
            <form method="POST" action="?page=clientwidget&_clientId=<?= $widgetClientId ?>">
                <input type="hidden" name="widget_send" value="1">
                <input type="hidden" name="toNumber" value="<?= htmlspecialchars($widgetPhone, ENT_QUOTES) ?>">
                <div class="compose-row">
                    <textarea name="message" id="wMsg"
                              placeholder="Type a message… (Ctrl+Enter to send)"
                              oninput="updateCount()"
                              onkeydown="ctrlSend(event)"
                              required></textarea>
                    <button type="submit" class="send-btn">Send</button>
                </div>
                <div class="char-count" id="wCount">0 / 160</div>
            </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<script>
    const msgList = document.getElementById('msgList');

    function scrollToBottom(force) {
        if (!msgList) return;
        const nearBottom = msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 120;
        if (force || nearBottom) {
            requestAnimationFrame(() => { msgList.scrollTop = msgList.scrollHeight; });
        }
    }

    const justRefreshed = sessionStorage.getItem('hermes_widget_refresh') === '1';
    sessionStorage.removeItem('hermes_widget_refresh');
    scrollToBottom(justRefreshed);

    function updateCount() {
        const ta  = document.getElementById('wMsg');
        const cnt = document.getElementById('wCount');
        if (!ta || !cnt) return;
        const len = ta.value.length;
        cnt.textContent = len <= 160 ? `${len} / 160` : `${len} chars · ${Math.ceil(len/160)} segments`;
    }

    function ctrlSend(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            e.target.closest('form').submit();
        }
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
                    sessionStorage.setItem('hermes_widget_refresh', '1');
                    window.location.reload();
                }
            })
            .catch(() => {});
    }, 10000);
</script>
</body>
</html>
<?php
exit;