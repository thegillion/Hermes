<?php

declare(strict_types=1);

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('<div style="font-family:sans-serif;padding:30px;color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;max-width:600px;margin:40px auto;">
        <h2 style="margin-bottom:10px;">⚠️ Plugin not fully installed</h2>
        <p>The <code>vendor/</code> directory is missing. You need to run <strong>composer install</strong> inside the <code>src/</code> folder before packing and uploading this plugin.</p>
        <pre style="margin-top:14px;background:#fff;padding:12px;border-radius:4px;font-size:13px;">cd src/
composer install
./vendor/bin/pack-plugin</pre>
        <p style="margin-top:12px;">Then re-upload the generated zip to UISP.</p>
    </div>');
}

require_once __DIR__ . '/vendor/autoload.php';

use Twilio\Rest\Client as TwilioClient;
use Twilio\Security\RequestValidator;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;

// ─── Constants ────────────────────────────────────────────────────────────────
define('MSG_FILE',    __DIR__ . '/data/messages.json');
define('MAX_STORED',  2000);   // Total messages kept before oldest are trimmed
define('PAGE_SIZE',   50);     // Messages shown per conversation page

// ─── Services ─────────────────────────────────────────────────────────────────
$log    = PluginLogManager::create();
$config = PluginConfigManager::create()->loadConfig();

$accountSid = trim($config['twilioAccountSid']  ?? '');
$authToken  = trim($config['twilioAuthToken']   ?? '');
$fromNumber = trim($config['twilioFromNumber']  ?? '');

// ─── Message Storage Helpers ──────────────────────────────────────────────────
function loadMessages(): array {
    if (!file_exists(MSG_FILE)) {
        return [];
    }
    $json = file_get_contents(MSG_FILE);
    return $json ? (json_decode($json, true) ?? []) : [];
}

function saveMessages(array $messages): void {
    // Trim to MAX_STORED, keeping the most recent
    if (count($messages) > MAX_STORED) {
        $messages = array_slice($messages, -MAX_STORED);
    }
    file_put_contents(MSG_FILE, json_encode($messages, JSON_PRETTY_PRINT), LOCK_EX);
}

function addMessage(array $msg): void {
    $messages   = loadMessages();
    $messages[] = $msg;
    saveMessages($messages);
}

function generateId(): string {
    return bin2hex(random_bytes(8));
}

function normalizePhone(string $phone): string {
    return preg_replace('/[^0-9+]/', '', $phone);
}

function stripToDigits(string $phone): string {
    return preg_replace('/\D/', '', $phone);
}

// Normalize to last 10 digits for matching.
// Handles +18593216507, 18593216507, and 8593216507 all -> 8593216507
function last10(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

// ─── Twilio Webhook — Incoming SMS ───────────────────────────────────────────
// Twilio POSTs here when a message is received.
// Set this URL in: Twilio Console → Phone Numbers → Your Number → Messaging
$action = $_GET['action'] ?? '';

if ($action === 'webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate the request is genuinely from Twilio.
    // Use pluginPublicUrl from UcrmOptionsManager rather than reconstructing
    // from $_SERVER — UISP runs behind a reverse proxy so HTTP_HOST and HTTPS
    // may not match the real public URL, breaking Twilio HMAC validation.
    if ($authToken) {
        $validator = new RequestValidator($authToken);
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

        try {
            $opts            = UcrmOptionsManager::create()->loadOptions();
            $pluginPublicUrl = rtrim($opts->pluginPublicUrl ?? '', '/');
            $webhookUrl      = $pluginPublicUrl . '?action=webhook';
        } catch (\Throwable $e) {
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUri    = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
            $webhookUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $baseUri . '?action=webhook';
        }

        if (!$validator->validate($signature, $webhookUrl, $_POST)) {
            $log->appendLog('[Hermes] Invalid Twilio signature. Expected URL: ' . $webhookUrl);
            http_response_code(403);
            header('Content-Type: text/xml');
            echo '<Response></Response>';
            exit;
        }
    }

    $from = $_POST['From'] ?? '';
    $to   = $_POST['To']   ?? '';
    $body = $_POST['Body'] ?? '';
    $sid  = $_POST['MessageSid'] ?? generateId();

    if ($from && $body) {
        addMessage([
            'id'        => $sid,
            'direction' => 'inbound',
            'from'      => $from,
            'to'        => $to,
            'body'      => $body,
            'timestamp' => date('c'),
        ]);
        $log->appendLog("[Hermes] Incoming SMS from {$from}: " . mb_strimwidth($body, 0, 80, '…'));
    }

    // Respond with empty TwiML — no auto-reply
    header('Content-Type: text/xml');
    echo '<Response></Response>';
    exit;
}

// ─── Auth: All other pages require a logged-in UISP user ─────────────────────
$security = UcrmSecurity::create();
$user     = $security->getUser();

if (!$user) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;color:#dc2626;padding:20px;">Access denied. Please log in to UISP first.</p>');
}

$configMissing = !$accountSid || !$authToken || !$fromNumber;

// ─── Get real plugin public URL for webhook display ───────────────────────────
try {
    $options         = UcrmOptionsManager::create()->loadOptions();
    $pluginPublicUrl = rtrim($options->pluginPublicUrl ?? '', '/');
    $webhookUrl      = $pluginPublicUrl . '?action=webhook';
} catch (\Throwable $e) {
    // Fallback if options aren't available
    $protocol        = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUri         = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $webhookUrl      = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-uisp.example.com') . $baseUri . '?action=webhook';
}

// ─── UCRM API: Load clients and build phone lookup map ───────────────────────
$api     = UcrmApi::create();
$clients = $api->get('clients', ['limit' => 500]) ?? [];

// Map normalized digits → client record for fast lookup.
// The bulk /clients list does not include contacts, so we fetch /clients/contacts
// which returns all contacts with phone numbers across all clients.
$phoneToClient = [];
try {
    $allContacts = $api->get('clients/contacts', ['limit' => 2000]) ?? [];
    $clientById  = [];
    foreach ($clients as $c) {
        $clientById[(int) $c['id']] = $c;
    }
    foreach ($allContacts as $contact) {
        $digits   = last10($contact['phone'] ?? '');
        $cid      = (int) ($contact['clientId'] ?? 0);
        if ($digits && $cid && isset($clientById[$cid])) {
            $phoneToClient[$digits] = $clientById[$cid];
        }
    }
} catch (\Throwable $e) {
    // Fallback: read contacts from bulk list (may be empty on some UISP versions)
    foreach ($clients as $c) {
        foreach (($c['contacts'] ?? []) as $contact) {
            $digits = last10($contact['phone'] ?? '');
            if ($digits) {
                $phoneToClient[$digits] = $c;
            }
        }
    }
}

function lookupClient(array $map, string $phone): ?array {
    return $map[last10($phone)] ?? null;
}

function clientDisplayName(array $c): string {
    return trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')) ?: 'Unknown';
}

function clientFirstPhone(array $c): string {
    foreach (($c['contacts'] ?? []) as $contact) {
        if (!empty($contact['phone'])) {
            return $contact['phone'];
        }
    }
    return '';
}

// ─── Handle Outbound Send (POST) ─────────────────────────────────────────────
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send' && !$configMissing) {
    $toNumber = normalizePhone(trim($_POST['toNumber'] ?? ''));
    $body     = trim($_POST['message'] ?? '');
    $clientId = isset($_POST['clientId']) && $_POST['clientId'] !== '' ? (int) $_POST['clientId'] : null;

    if (!$toNumber || !$body) {
        $errorMsg = 'A phone number and message are both required.';
    } else {
        try {
            $twilio = new TwilioClient($accountSid, $authToken);
            $sent   = $twilio->messages->create($toNumber, [
                'from' => $fromNumber,
                'body' => $body,
            ]);

            addMessage([
                'id'        => $sent->sid,
                'direction' => 'outbound',
                'from'      => $fromNumber,
                'to'        => $toNumber,
                'body'      => $body,
                'timestamp' => date('c'),
                'clientId'  => $clientId,
            ]);

            $log->appendLog("[Hermes] Sent SMS to {$toNumber}: " . mb_strimwidth($body, 0, 80, '…'));

            // Redirect to the conversation after sending
            header('Location: ?phone=' . urlencode($toNumber));
            exit;

        } catch (\Twilio\Exceptions\RestException $e) {
            $errorMsg = 'Twilio error (' . $e->getStatusCode() . '): ' . $e->getMessage();
            $log->appendLog('[Hermes] Send failed to ' . $toNumber . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            $errorMsg = 'Unexpected error: ' . $e->getMessage();
            $log->appendLog('[Hermes] Unexpected error: ' . $e->getMessage());
        }
    }
}

// ─── Build Conversation Threads ───────────────────────────────────────────────
$allMessages = loadMessages();
$threads     = [];

foreach ($allMessages as $msg) {
    $otherNumber = $msg['direction'] === 'inbound' ? ($msg['from'] ?? '') : ($msg['to'] ?? '');
    if (!$otherNumber) continue;

    $key = last10($otherNumber);
    if (!isset($threads[$key])) {
        $threads[$key] = [
            'phone'    => $otherNumber,
            'messages' => [],
            'client'   => lookupClient($phoneToClient, $otherNumber),
            'lastTime' => '',
        ];
    }
    $threads[$key]['messages'][] = $msg;
    $threads[$key]['lastTime']   = $msg['timestamp'];
}

// Sort threads newest-first
uasort($threads, fn($a, $b) => strcmp($b['lastTime'], $a['lastTime']));

// ─── clientId auto-routing ────────────────────────────────────────────────────
// When accessed with ?clientId=X (e.g. admin copies ID from client URL),
// look up that client's phone and redirect to their thread — or open new compose.
if (isset($_GET['clientId']) && !isset($_GET['phone']) && !isset($_GET['new']) && $action !== 'send') {
    $incomingClientId = (int) $_GET['clientId'];
    $clientPhone      = '';

    foreach ($clients as $c) {
        if ((int) $c['id'] === $incomingClientId) {
            $clientPhone = clientFirstPhone($c);
            break;
        }
    }

    if ($clientPhone) {
        $digits = last10($clientPhone);
        if (isset($threads[$digits])) {
            // Existing conversation — go straight to it
            header('Location: ?phone=' . urlencode($threads[$digits]['phone']));
        } else {
            // No conversation yet — open new compose pre-filled
            header('Location: ?new=1&clientId=' . $incomingClientId);
        }
        exit;
    }
}

// ─── Active Thread + Pagination ───────────────────────────────────────────────
$activePhone  = $_GET['phone'] ?? '';
$activeKey    = last10($activePhone);
$activeThread = $threads[$activeKey] ?? null;
$page         = max(1, (int) ($_GET['page'] ?? 1));

$pagedMessages  = [];
$totalPages     = 1;
$hasOlderPages  = false;

if ($activeThread) {
    $threadMsgs = $activeThread['messages'];
    usort($threadMsgs, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

    $total      = count($threadMsgs);
    $totalPages = (int) ceil($total / PAGE_SIZE);
    $page       = min($page, $totalPages);

    // Page 1 = most recent messages
    $offset       = $total - ($page * PAGE_SIZE);
    $length       = PAGE_SIZE;
    if ($offset < 0) { $length += $offset; $offset = 0; }
    $pagedMessages  = array_slice($threadMsgs, $offset, $length);
    $hasOlderPages  = $page < $totalPages;
}

// ─── Read Tracking Helpers ────────────────────────────────────────────────────
define('READ_FILE', __DIR__ . '/data/read_ids.json');

function loadReadIds(): array {
    if (!file_exists(READ_FILE)) return [];
    return json_decode(file_get_contents(READ_FILE), true) ?: [];
}

function markAsRead(array $ids): void {
    $existing = loadReadIds();
    $merged   = array_unique(array_merge($existing, $ids));
    // Only keep the last 5000 IDs to prevent unbounded growth
    if (count($merged) > 5000) {
        $merged = array_slice($merged, -5000);
    }
    file_put_contents(READ_FILE, json_encode(array_values($merged)), LOCK_EX);
}

function isUnread(array $msg, array $readIds): bool {
    return $msg['direction'] === 'inbound' && !in_array($msg['id'], $readIds, true);
}

// ─── Dashboard Admin Widget ───────────────────────────────────────────────────
if ($page === 'adminwidget') {

    // Handle mark-as-read action (called via fetch from the widget)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
        $ids = json_decode($_POST['mark_read'], true) ?: [];
        markAsRead($ids);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    $allMessages = loadMessages();
    $readIds     = loadReadIds();

    // Collect all unread inbound messages, newest first
    $unread = [];
    foreach ($allMessages as $msg) {
        if (isUnread($msg, $readIds)) {
            $unread[] = $msg;
        }
    }
    usort($unread, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

    // Group by phone so we show one entry per sender with a count
    $grouped = [];
    foreach ($unread as $msg) {
        $key = last10($msg['from']);
        if (!isset($grouped[$key])) {
            $client = lookupClient($phoneToClient, $msg['from']);
            $grouped[$key] = [
                'phone'    => $msg['from'],
                'client'   => $client,
                'messages' => [],
            ];
        }
        $grouped[$key]['messages'][] = $msg;
    }

    $totalUnread = count($unread);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>SMS — New Messages</title>
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #fff;
                height: 100vh;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                font-size: 13px;
                color: #1a1a2e;
            }

            .widget-header {
                background: #1e1e2e;
                color: #fff;
                padding: 10px 16px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-shrink: 0;
            }
            .widget-header .left { display: flex; align-items: center; gap: 10px; }
            .widget-header h2 { font-size: 14px; font-weight: 600; }
            .badge {
                background: #ef4444;
                color: #fff;
                border-radius: 12px;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 700;
                min-width: 20px;
                text-align: center;
            }
            .badge.zero { background: #6b7280; }
            .widget-header a {
                font-size: 11px;
                color: #a5b4fc;
                text-decoration: none;
                background: #2d2d44;
                padding: 3px 9px;
                border-radius: 12px;
                white-space: nowrap;
            }
            .widget-header a:hover { background: #3d3d5c; }

            .mark-all-bar {
                background: #f0f2f5;
                border-bottom: 1px solid #e5e7eb;
                padding: 7px 16px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-shrink: 0;
                font-size: 12px;
                color: #6b7280;
            }
            .mark-all-bar button {
                background: none;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                padding: 3px 10px;
                font-size: 11px;
                cursor: pointer;
                color: #374151;
            }
            .mark-all-bar button:hover { background: #e5e7eb; }

            .msg-list {
                flex: 1;
                overflow-y: auto;
            }

            .empty-state {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                gap: 10px;
                color: #9ca3af;
                padding: 30px;
            }
            .empty-state .icon { font-size: 40px; }
            .empty-state p { font-size: 13px; text-align: center; }

            .sender-group {
                border-bottom: 1px solid #f3f4f6;
            }
            .sender-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 16px;
                background: #fafafa;
                cursor: pointer;
                user-select: none;
            }
            .sender-header:hover { background: #f3f4f6; }
            .avatar {
                width: 36px; height: 36px;
                border-radius: 50%;
                background: #6366f1;
                color: #fff;
                display: flex; align-items: center; justify-content: center;
                font-weight: 700; font-size: 14px;
                flex-shrink: 0;
            }
            .sender-info { flex: 1; min-width: 0; }
            .sender-name {
                font-weight: 600;
                font-size: 13px;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .sender-phone { font-size: 11px; color: #6b7280; }
            .sender-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
            .unread-count {
                background: #6366f1; color: #fff;
                border-radius: 10px; padding: 1px 7px;
                font-size: 11px; font-weight: 700;
            }
            .sender-time { font-size: 10px; color: #9ca3af; }

            .msg-bubbles { padding: 6px 16px 10px 62px; display: flex; flex-direction: column; gap: 5px; }
            .msg-bubble {
                background: #f3f4f6;
                border-left: 3px solid #6366f1;
                border-radius: 0 8px 8px 0;
                padding: 6px 10px;
                font-size: 12px;
                line-height: 1.4;
                color: #111;
                word-break: break-word;
            }
            .msg-bubble-time { font-size: 10px; color: #9ca3af; margin-top: 2px; }

            .open-link {
                display: inline-block;
                margin-top: 6px;
                font-size: 11px;
                color: #6366f1;
                text-decoration: none;
                font-weight: 600;
            }
            .open-link:hover { text-decoration: underline; }

            .mark-read-btn {
                background: none;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 2px 8px;
                font-size: 11px;
                color: #6b7280;
                cursor: pointer;
                margin-top: 4px;
            }
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
        <span><?= $totalUnread ?> unread message<?= $totalUnread !== 1 ? 's' : '' ?> from <?= count($grouped) ?> sender<?= count($grouped) !== 1 ? 's' : '' ?></span>
        <button onclick="markAllRead()">✓ Mark all as read</button>
    </div>
    <?php endif; ?>

    <div class="msg-list" id="msgList">
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
                $cId      = $client ? (int) $client['id'] : null;
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
                <div class="msg-bubbles" style="display:none;">
                    <?php foreach (array_reverse($msgs) as $msg): ?>
                        <div class="msg-bubble">
                            <?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?>
                            <div class="msg-bubble-time"><?= date('g:ia', strtotime($msg['timestamp'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <div style="display:flex; gap:8px; align-items:center; margin-top:2px;">
                        <a class="open-link" href="?phone=<?= urlencode($group['phone']) ?>" target="_blank"
                           onclick="markGroupRead(this.closest('.sender-group'))">
                            💬 Open Conversation
                        </a>
                        <button class="mark-read-btn" onclick="markGroupRead(this.closest('.sender-group'))">
                            ✓ Mark as read
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        const MARK_URL = '?page=adminwidget';

        function toggleGroup(header) {
            const bubbles = header.nextElementSibling;
            bubbles.style.display = bubbles.style.display === 'none' ? 'flex' : 'none';
            bubbles.style.flexDirection = 'column';
        }

        function markGroupRead(groupEl) {
            const ids = JSON.parse(groupEl.dataset.ids || '[]');
            postMarkRead(ids, () => groupEl.remove());
        }

        function markAllRead() {
            const allIds = [];
            document.querySelectorAll('.sender-group').forEach(g => {
                allIds.push(...JSON.parse(g.dataset.ids || '[]'));
            });
            postMarkRead(allIds, () => window.location.reload());
        }

        function postMarkRead(ids, onSuccess) {
            const body = new FormData();
            body.append('mark_read', JSON.stringify(ids));
            fetch(MARK_URL, { method: 'POST', body })
                .then(r => r.json())
                .then(data => { if (data.ok) onSuccess(); })
                .catch(console.error);
        }

        // Auto-refresh every 60s to pick up new incoming messages
        setTimeout(() => window.location.reload(), 60000);
    </script>
    </body>
    </html>
    <?php
    exit;
}

// ─── Client Overview Widget ───────────────────────────────────────────────────
// Rendered when UISP embeds the plugin on the client/overview page via widgets[].
// UISP automatically appends ?clientId=X to the iframeUrlParameters.
$page = $_GET['page'] ?? '';

if ($page === 'clientwidget') {
    // UISP passes the client ID as _clientId (underscore prefix) in widget iframes
    parse_str($_SERVER['QUERY_STRING'] ?? '', $widgetQuery);
    $widgetClientId = isset($widgetQuery['_clientId']) && $widgetQuery['_clientId'] !== ''
        ? (int) $widgetQuery['_clientId']
        : (isset($_GET['clientId']) ? (int) $_GET['clientId'] : null); // fallback for manual linking

    // Fetch the full individual client record — the bulk clients list does not
    // include contacts/phone numbers, only the single-client endpoint does.
    $widgetClient = null;
    if ($widgetClientId) {
        try {
            $widgetClient = $api->get("clients/{$widgetClientId}");
        } catch (\Throwable $e) {
            $log->appendLog("[Hermes] Widget could not fetch client {$widgetClientId}: " . $e->getMessage());
        }
    }

    $widgetPhone  = $widgetClient ? clientFirstPhone($widgetClient) : '';
    $widgetName   = $widgetClient ? clientDisplayName($widgetClient) : 'Client';
    $widgetKey    = last10($widgetPhone);
    $widgetThread = $threads[$widgetKey] ?? null;

    // Handle send from widget
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
                $log->appendLog("[Hermes] Widget sent SMS to {$wTo}: " . mb_strimwidth($wBody, 0, 80, '…'));
                $widgetSuccess = 'Message sent!';
                // Reload to show new message in thread
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

    // Reload thread after possible send
    $allMessages  = loadMessages();
    $threads      = [];
    foreach ($allMessages as $msg) {
        $other = $msg['direction'] === 'inbound' ? ($msg['from'] ?? '') : ($msg['to'] ?? '');
        if (!$other) continue;
        $k = last10($other);
        if (!isset($threads[$k])) {
            $threads[$k] = ['phone' => $other, 'messages' => [], 'client' => lookupClient($phoneToClient, $other), 'lastTime' => ''];
        }
        $threads[$k]['messages'][] = $msg;
        $threads[$k]['lastTime']   = $msg['timestamp'];
    }
    $widgetThread = $threads[$widgetKey] ?? null;

    $widgetMsgs = [];
    if ($widgetThread) {
        $widgetMsgs = $widgetThread['messages'];
        usort($widgetMsgs, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
        $widgetMsgs = array_slice($widgetMsgs, -30); // Show last 30 messages
    }

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
                background: #1e1e2e;
                color: #fff;
                padding: 10px 14px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-shrink: 0;
            }
            .widget-header .title { font-weight: 600; font-size: 13px; }
            .widget-header .phone { font-size: 11px; color: #a5b4fc; }
            .widget-header a {
                font-size: 11px;
                color: #a5b4fc;
                text-decoration: none;
                background: #2d2d44;
                padding: 3px 9px;
                border-radius: 12px;
            }
            .widget-header a:hover { background: #3d3d5c; }

            .no-phone {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                gap: 8px;
                color: #9ca3af;
                padding: 20px;
                text-align: center;
            }
            .no-phone .icon { font-size: 32px; }

            .messages {
                flex: 1;
                overflow-y: auto;
                padding: 12px 14px;
                display: flex;
                flex-direction: column;
                gap: 6px;
                background: #f9fafb;
            }
            .no-msgs {
                color: #9ca3af;
                text-align: center;
                margin: auto;
                font-size: 12px;
            }

            .bw     { display: flex; flex-direction: column; }
            .bw.out { align-items: flex-end; }
            .bw.in  { align-items: flex-start; }

            .bubble {
                max-width: 80%;
                padding: 7px 11px;
                border-radius: 14px;
                font-size: 13px;
                line-height: 1.4;
                word-break: break-word;
            }
            .bubble.out { background: #6366f1; color: #fff; border-bottom-right-radius: 3px; }
            .bubble.in  { background: #fff; color: #111; border: 1px solid #e5e7eb; border-bottom-left-radius: 3px; }
            .b-time { font-size: 10px; color: #9ca3af; margin-top: 2px; padding: 0 2px; }

            .day-div {
                text-align: center;
                font-size: 10px;
                color: #9ca3af;
                margin: 4px 0;
            }

            .compose {
                background: #fff;
                border-top: 1px solid #e5e7eb;
                padding: 10px 14px;
                flex-shrink: 0;
            }
            .flash { font-size: 11px; padding: 5px 9px; border-radius: 5px; margin-bottom: 7px; }
            .flash.ok  { background: #ecfdf5; color: #065f46; }
            .flash.err { background: #fef2f2; color: #991b1b; }

            .compose-row { display: flex; gap: 7px; align-items: flex-end; }
            .compose textarea {
                flex: 1;
                resize: none;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 7px 10px;
                font-size: 13px;
                font-family: inherit;
                background: #fafafa;
                min-height: 38px;
                max-height: 90px;
            }
            .compose textarea:focus { outline: none; border-color: #6366f1; background: #fff; }
            .send-btn {
                background: #6366f1; color: #fff;
                border: none; border-radius: 8px;
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
                    $ts      = strtotime($msg['timestamp'] ?? 'now');
                    $day     = date('M j, Y', $ts);
                    $timeStr = date('g:ia', $ts);
            ?>
                <?php if ($day !== $lastDay): $lastDay = $day; ?>
                    <div class="day-div"><?= htmlspecialchars($day, ENT_QUOTES) ?></div>
                <?php endif; ?>
                <div class="bw <?= $dir ?>">
                    <div class="bubble <?= $dir ?>"><?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?></div>
                    <div class="b-time"><?= $timeStr ?></div>
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
                        <textarea name="message" id="wMsg" placeholder="Type a message… (Ctrl+Enter to send)"
                                  oninput="updateCount()" onkeydown="ctrlSend(event)" required></textarea>
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

        // Poll every 10s — only reload if new messages arrived
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
            background: #f0f2f5;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-size: 14px;
            color: #1a1a2e;
        }

        /* ── Top bar ── */
        .topbar {
            background: #1e1e2e;
            color: #fff;
            padding: 0 18px;
            height: 48px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .topbar h1 { font-size: 15px; font-weight: 600; }
        .topbar .spacer { flex: 1; }
        .topbar .from-badge {
            font-size: 11px;
            background: #2d2d44;
            padding: 3px 10px;
            border-radius: 20px;
            color: #a5b4fc;
        }

        /* ── Config warning ── */
        .banner {
            padding: 8px 18px;
            font-size: 12px;
            flex-shrink: 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .banner.warn { background: #fffbeb; color: #92400e; border-bottom: 2px solid #f59e0b; }
        .banner.info { background: #eff6ff; color: #1e40af; border-bottom: 1px solid #bfdbfe; }
        .banner code {
            background: rgba(0,0,0,.06);
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 11px;
            user-select: all;
            word-break: break-all;
        }

        /* ── Layout ── */
        .layout { display: flex; flex: 1; overflow: hidden; }

        /* ── Sidebar ── */
        .sidebar {
            width: 268px;
            background: #fff;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-hdr {
            padding: 11px 14px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-hdr span { font-weight: 600; font-size: 13px; }
        .btn-new {
            background: #6366f1; color: #fff;
            border: none; border-radius: 6px;
            padding: 4px 11px; font-size: 12px; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: background .15s;
        }
        .btn-new:hover { background: #4f46e5; }

        .thread-list { overflow-y: auto; flex: 1; }

        .t-item {
            display: block;
            padding: 11px 14px;
            border-bottom: 1px solid #f3f4f6;
            text-decoration: none;
            color: inherit;
            transition: background .1s;
        }
        .t-item:hover  { background: #f9fafb; }
        .t-item.active { background: #eef2ff; border-left: 3px solid #6366f1; padding-left: 11px; }
        .t-name    { font-weight: 600; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .t-preview { font-size: 12px; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .t-meta    { font-size: 11px; color: #9ca3af; margin-top: 3px; display: flex; justify-content: space-between; }

        .no-threads { padding: 28px 14px; text-align: center; color: #9ca3af; font-size: 13px; line-height: 1.6; }

        /* ── Chat area ── */
        .chat-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        .chat-hdr {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 11px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .chat-hdr .c-name  { font-weight: 600; font-size: 15px; }
        .chat-hdr .c-phone { font-size: 12px; color: #6b7280; }
        .chat-hdr .c-link  { margin-left: auto; font-size: 12px; color: #6366f1; text-decoration: none; white-space: nowrap; }
        .chat-hdr .c-link:hover { text-decoration: underline; }

        /* Older messages link */
        .load-older {
            text-align: center;
            padding: 10px;
            flex-shrink: 0;
        }
        .load-older a {
            font-size: 12px;
            color: #6366f1;
            text-decoration: none;
            background: #eef2ff;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        .load-older a:hover { background: #e0e7ff; }

        /* Messages */
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .day-divider {
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            margin: 6px 0 2px;
            position: relative;
        }
        .day-divider::before, .day-divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 38%;
            height: 1px;
            background: #e5e7eb;
        }
        .day-divider::before { left: 0; }
        .day-divider::after  { right: 0; }

        .bw      { display: flex; flex-direction: column; }
        .bw.out  { align-items: flex-end; }
        .bw.in   { align-items: flex-start; }

        .bubble {
            max-width: 68%;
            padding: 8px 13px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.45;
            word-break: break-word;
        }
        .bubble.out { background: #6366f1; color: #fff; border-bottom-right-radius: 4px; }
        .bubble.in  { background: #fff; color: #111; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
        .b-time { font-size: 11px; color: #9ca3af; margin-top: 2px; padding: 0 3px; }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 8px;
            color: #9ca3af;
        }
        .empty-state .icon { font-size: 36px; }
        .empty-state p { font-size: 13px; }

        /* Compose */
        .compose {
            background: #fff;
            border-top: 1px solid #e5e7eb;
            padding: 12px 18px;
            flex-shrink: 0;
        }
        .compose-fields { display: flex; flex-direction: column; gap: 8px; }
        .compose-row    { display: flex; gap: 8px; align-items: flex-end; }

        .compose input[type="text"],
        .compose select {
            flex: 1;
            padding: 8px 11px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            background: #fafafa;
            color: #1a1a2e;
        }
        .compose textarea {
            flex: 1;
            resize: none;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 13px;
            font-family: inherit;
            background: #fafafa;
            min-height: 42px;
            max-height: 110px;
            color: #1a1a2e;
        }
        .compose input:focus, .compose select:focus, .compose textarea:focus {
            outline: none; border-color: #6366f1; background: #fff;
        }

        .send-btn {
            background: #6366f1; color: #fff;
            border: none; border-radius: 8px;
            padding: 8px 18px; font-size: 13px; font-weight: 600;
            cursor: pointer; white-space: nowrap; flex-shrink: 0;
            transition: background .15s;
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

<!-- Top bar -->
<div class="topbar">
    <span>📱</span>
    <h1>SMS Inbox</h1>
    <div class="spacer"></div>
    <?php if ($fromNumber): ?>
        <div class="from-badge">From: <?= htmlspecialchars($fromNumber, ENT_QUOTES) ?></div>
    <?php endif; ?>
</div>

<!-- Config / webhook banner -->
<?php if ($configMissing): ?>
    <div class="banner warn">
        ⚠️ <span>Twilio credentials not set. Go to <strong>System → Plugins → Hermes → Configure</strong> and fill in your Account SID, Auth Token, and From Number.</span>
    </div>
<?php else: ?>
    <div class="banner info">
        🔗 <span>Twilio webhook URL — paste into <strong>Twilio Console → Phone Numbers → Messaging (incoming)</strong>:&nbsp;
        <code><?= htmlspecialchars($webhookUrl, ENT_QUOTES) ?></code></span>
    </div>
<?php endif; ?>

<div class="layout">

    <!-- ── Sidebar ── -->
    <div class="sidebar">
        <div class="sidebar-hdr">
            <span>Conversations</span>
            <a class="btn-new" href="?new=1">+ New</a>
        </div>
        <div class="thread-list">
            <?php if (empty($threads)): ?>
                <div class="no-threads">No messages yet.<br>Click <strong>+ New</strong> to send the first one.</div>
            <?php else: ?>
                <?php foreach ($threads as $key => $thread):
                    $c       = $thread['client'];
                    $name    = $c ? htmlspecialchars(clientDisplayName($c), ENT_QUOTES) : htmlspecialchars($thread['phone'], ENT_QUOTES);
                    $lastMsg = end($thread['messages']);
                    $preview = htmlspecialchars(mb_strimwidth($lastMsg['body'] ?? '', 0, 46, '…'), ENT_QUOTES);
                    $arrow   = ($lastMsg['direction'] ?? '') === 'outbound' ? '↑ ' : '↓ ';
                    $time    = !empty($lastMsg['timestamp']) ? date('M j, g:ia', strtotime($lastMsg['timestamp'])) : '';
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

    <!-- ── Chat area ── -->
    <div class="chat-area">

        <?php if (isset($_GET['new'])): ?>
            <!-- ── New message ── -->
            <div class="chat-hdr">
                <div>
                    <div class="c-name">New Message</div>
                    <div class="c-phone">Select a client or enter a number manually</div>
                </div>
            </div>
            <div class="messages">
                <div class="empty-state">
                    <div class="icon">✉️</div>
                    <p>Compose your message below</p>
                </div>
            </div>
            <div class="compose">
                <?php if ($errorMsg): ?><div class="flash err">❌ <?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></div><?php endif; ?>
                <form method="POST" action="?action=send&new=1" class="compose-fields">
                    <div class="compose-row">
                        <select name="clientId" onchange="onClientPick(this)">
                            <option value="">— Select a client (optional) —</option>
                            <?php foreach ($clients as $c):
                                $cId    = (int) $c['id'];
                                $cName  = htmlspecialchars(clientDisplayName($c), ENT_QUOTES);
                                $cPhone = htmlspecialchars(clientFirstPhone($c), ENT_QUOTES);
                            ?>
                                <option value="<?= $cId ?>" data-phone="<?= $cPhone ?>"><?= $cName ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="toNumber" id="toNumber" placeholder="+15551234567" required>
                    </div>
                    <div class="compose-row">
                        <textarea name="message" id="newMsg" placeholder="Type your message…"
                                  oninput="updateCount('newMsg','newCount')"
                                  onkeydown="ctrlEnterSubmit(event)"
                                  required></textarea>
                        <button type="submit" class="send-btn" <?= $configMissing ? 'disabled' : '' ?>>Send</button>
                    </div>
                    <div class="char-count" id="newCount">0 / 160</div>
                </form>
            </div>

        <?php elseif ($activeThread): ?>
            <?php
                $c     = $activeThread['client'];
                $label = $c ? clientDisplayName($c) : $activePhone;
                $cId   = $c ? (int) $c['id'] : null;
                $lastDay = '';
            ?>
            <!-- ── Thread header ── -->
            <div class="chat-hdr">
                <div>
                    <div class="c-name"><?= htmlspecialchars($label, ENT_QUOTES) ?></div>
                    <div class="c-phone"><?= htmlspecialchars($activePhone, ENT_QUOTES) ?></div>
                </div>
                <?php if ($cId): ?>
                    <a class="c-link" href="/crm/client/<?= $cId ?>" target="_parent">View Client Profile →</a>
                <?php endif; ?>
            </div>

            <!-- ── Older messages pagination ── -->
            <?php if ($hasOlderPages): ?>
                <div class="load-older">
                    <a href="?phone=<?= urlencode($activePhone) ?>&page=<?= $page + 1 ?>">⬆ Load older messages</a>
                </div>
            <?php endif; ?>

            <!-- ── Message bubbles ── -->
            <div class="messages" id="msgList">
                <?php foreach ($pagedMessages as $msg):
                    $dir     = $msg['direction'] === 'outbound' ? 'out' : 'in';
                    $ts      = strtotime($msg['timestamp'] ?? 'now');
                    $day     = date('l, F j Y', $ts);
                    $timeStr = date('g:ia', $ts);
                ?>
                    <?php if ($day !== $lastDay): $lastDay = $day; ?>
                        <div class="day-divider"><?= htmlspecialchars($day, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div class="bw <?= $dir ?>">
                        <div class="bubble <?= $dir ?>"><?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?></div>
                        <div class="b-time"><?= $timeStr ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Reply compose ── -->
            <div class="compose">
                <?php if ($successMsg): ?><div class="flash ok">✅ <?= htmlspecialchars($successMsg, ENT_QUOTES) ?></div><?php endif; ?>
                <?php if ($errorMsg):   ?><div class="flash err">❌ <?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></div><?php endif; ?>
                <form method="POST" action="?action=send&phone=<?= urlencode($activePhone) ?>" class="compose-fields">
                    <input type="hidden" name="toNumber" value="<?= htmlspecialchars($activePhone, ENT_QUOTES) ?>">
                    <input type="hidden" name="clientId" value="<?= $cId ?? '' ?>">
                    <div class="compose-row">
                        <textarea name="message" id="replyMsg"
                                  placeholder="Type a reply… (Ctrl+Enter to send)"
                                  oninput="updateCount('replyMsg','replyCount')"
                                  onkeydown="ctrlEnterSubmit(event)"
                                  required></textarea>
                        <button type="submit" class="send-btn" <?= $configMissing ? 'disabled' : '' ?>>Send</button>
                    </div>
                    <div class="char-count" id="replyCount">0 / 160</div>
                </form>
            </div>

        <?php else: ?>
            <!-- ── No thread selected ── -->
            <div class="empty-state">
                <div class="icon">📨</div>
                <p>Select a conversation on the left, or click <strong>+ New</strong> to start one.</p>
            </div>
        <?php endif; ?>

    </div><!-- /chat-area -->
</div><!-- /layout -->

<script>
    const msgList = document.getElementById('msgList');

    // Scroll to bottom reliably after full render
    function scrollToBottom(force) {
        if (!msgList) return;
        // If user has scrolled up manually, don't hijack — unless forced (new message arrived)
        const nearBottom = msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 120;
        if (force || nearBottom) {
            requestAnimationFrame(() => {
                msgList.scrollTop = msgList.scrollHeight;
            });
        }
    }

    // On load: check if we just refreshed after a new message
    const justRefreshed = sessionStorage.getItem('hermes_refresh') === '1';
    sessionStorage.removeItem('hermes_refresh');
    scrollToBottom(justRefreshed); // force scroll if we just refreshed

    // SMS character counter with segment awareness
    function updateCount(taId, countId) {
        const ta  = document.getElementById(taId);
        const cnt = document.getElementById(countId);
        if (!ta || !cnt) return;
        const len  = ta.value.length;
        const segs = Math.ceil(len / 160) || 1;
        cnt.textContent = len <= 160
            ? `${len} / 160`
            : `${len} chars · ${segs} SMS segments`;
        cnt.className = 'char-count' + (len > 320 ? ' over' : len > 160 ? ' warn' : '');
    }

    // Ctrl+Enter / Cmd+Enter to submit form
    function ctrlEnterSubmit(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            e.target.closest('form').submit();
        }
    }

    // Auto-fill phone number when a client is selected from the dropdown
    function onClientPick(select) {
        const phone = select.options[select.selectedIndex]?.dataset?.phone || '';
        const field = document.getElementById('toNumber');
        if (field && phone) field.value = phone;
    }

    // Poll for new messages every 5 seconds via fetch instead of full page reload.
    // Only does a full reload when there's actually something new, and marks it
    // so we force-scroll to the bottom on reload.
    const currentUrl = window.location.href;
    let lastMessageCount = msgList ? msgList.querySelectorAll('.bw').length : 0;

    setInterval(() => {
        if (document.activeElement?.tagName === 'TEXTAREA') return;
        fetch(currentUrl)
            .then(r => r.text())
            .then(html => {
                const parser   = new DOMParser();
                const doc      = parser.parseFromString(html, 'text/html');
                const newList  = doc.getElementById('msgList');
                const newCount = newList ? newList.querySelectorAll('.bw').length : 0;
                if (newCount > lastMessageCount) {
                    // New messages arrived — reload and scroll to bottom
                    sessionStorage.setItem('hermes_refresh', '1');
                    window.location.reload();
                }
            })
            .catch(() => {}); // Silently ignore network errors
    }, 10000);
</script>

</body>
</html>