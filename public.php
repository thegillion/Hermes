<?php

declare(strict_types=1);

// ─── Autoloader ───────────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('<div style="font-family:sans-serif;padding:30px;color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;max-width:600px;margin:40px auto;">
        <h2 style="margin-bottom:10px;">⚠️ Plugin not fully installed</h2>
        <p>The <code>vendor/</code> directory is missing. Run <strong>composer install</strong> inside <code>src/</code> then re-upload the plugin zip.</p>
        <pre style="margin-top:14px;background:#fff;padding:12px;border-radius:4px;font-size:13px;">cd src/
composer install
./vendor/bin/pack-plugin</pre>
    </div>');
}

require_once __DIR__ . '/vendor/autoload.php';

// ─── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/config.php';

$action = $_GET['action'] ?? '';
$page   = $_GET['page']   ?? '';

// ─── Route: UISP event webhook (invoice, payment, service notifications) ──────
// UISP posts JSON with an "eventName" field directly to public.php.
// Detect this before auth so no login session is needed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'webhook') {
    $rawBody = file_get_contents('php://input');
    if ($rawBody) {
        $uispEvent = json_decode($rawBody, true);
        if (is_array($uispEvent) && isset($uispEvent['eventName'])) {
            define('UISP_EVENT_BODY', $rawBody);
            require __DIR__ . '/includes/notifications.php';
            exit;
        }
    }
}

// ─── Route: Twilio incoming SMS webhook (no auth required) ────────────────────
if ($action === 'webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/includes/ears.php';
    exit;
}

// ─── Auth: everything else requires a logged-in UISP user ─────────────────────
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

$security = UcrmSecurity::create();
$user     = $security->getUser();

if (!$user) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;color:#dc2626;padding:20px;">Access denied. Please log in to UISP.</p>');
}

// ─── Load clients / contacts (needed by all authenticated pages) ──────────────
require_once __DIR__ . '/includes/clients.php';

// ─── Route: widgets ───────────────────────────────────────────────────────────
if ($page === 'adminwidget') {
    require __DIR__ . '/includes/pages/widget_admin.php';
    exit;
}

if ($page === 'clientwidget') {
    require __DIR__ . '/includes/pages/widget_client.php';
    exit;
}

// ─── Route: migration page ────────────────────────────────────────────────────
if ($page === 'migrate') {
    // AJAX: return current SQLite message count
    if (($_GET['action'] ?? '') === 'db_count') {
        header('Content-Type: application/json');
        try {
            $count = getDb()->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        } catch (\Throwable $e) {
            $count = 0;
        }
        echo json_encode(['count' => (int) $count]);
        exit;
    }
    require __DIR__ . '/includes/migrate.php';
    exit;
}

// ─── Route: outbound send (POST), then fall through to inbox ──────────────────
if ($action === 'send') {
    require __DIR__ . '/includes/send.php';
}

// ─── Main inbox ───────────────────────────────────────────────────────────────
require __DIR__ . '/includes/pages/inbox.php';