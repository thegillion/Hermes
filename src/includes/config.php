<?php

declare(strict_types=1);

use Ubnt\UcrmPluginSdk\Service\PluginConfigManager;
use Ubnt\UcrmPluginSdk\Service\PluginLogManager;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;

// ─── Storage paths ────────────────────────────────────────────────────────────
define('DATA_DIR', __DIR__ . '/../data');
define('DB_FILE',  DATA_DIR . '/hermes.db');
define('PAGE_SIZE', 50);

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// ─── Services ─────────────────────────────────────────────────────────────────
$log    = PluginLogManager::create();
$config = PluginConfigManager::create()->loadConfig();

$accountSid = trim($config['twilioAccountSid']  ?? '');
$authToken  = trim($config['twilioAuthToken']   ?? '');
$fromNumber = trim($config['twilioFromNumber']  ?? '');

$configMissing = !$accountSid || !$authToken || !$fromNumber;

// Shared secret UISP must send as ?key= on the event webhook URL.
$webhookKey = trim((string) ($config['webhookKey'] ?? ''));

// ─── Canonical webhook URL ────────────────────────────────────────────────────
// Priority order:
//   1. Admin-configured Public URL (most reliable — bypasses proxy header issues)
//   2. UcrmOptionsManager pluginPublicUrl
//   3. Reconstructed from forwarded/server headers (last resort)
//
// On local/private IP installs the URL will still resolve to an internal address —
// that's fine because ears.php skips signature validation for private hosts.

$baseUri   = strtok($_SERVER['REQUEST_URI'] ?? '/crm/_plugins/hermes/public.php', '?');
$publicUrl = rtrim(trim($config['publicUrl'] ?? ''), '/');

if ($publicUrl) {
    // Admin has entered their real public domain
    $webhookUrl = $publicUrl . $baseUri . '?action=webhook';
} else {
    // Fallback 1: UcrmOptionsManager
    try {
        $options         = UcrmOptionsManager::create()->loadOptions();
        $pluginPublicUrl = rtrim($options->pluginPublicUrl ?? '', '/');
        $webhookUrl      = $pluginPublicUrl . '?action=webhook';
    } catch (\Throwable $e) {
        // Fallback 2: reconstruct from headers
        $proto      = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            ? strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]))
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host       = !empty($_SERVER['HTTP_X_FORWARDED_HOST'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0])
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $webhookUrl = $proto . '://' . $host . $baseUri . '?action=webhook';
    }
}