<?php

declare(strict_types=1);

// main.php is run by UISP on the plugin's execution schedule (System → Plugins
// → Hermes → Execution period) and by "Execute manually". Hermes uses it to
// send Discord "no reply yet" reminders and keep the client directory cache
// fresh. UISP webhook events go to public.php instead.

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/discord.php';

// Reminders first: they're time-sensitive and cheap.
discordCheckReminders($config);

// Refresh the directory only when it's due, so a frequent execution period
// (needed for timely reminders) doesn't re-list every client each run.
$started = microtime(true);
directorySync(240.0);

$counts = getDb()->query('
    SELECT (SELECT COUNT(*) FROM clients)       AS clients,
           (SELECT COUNT(*) FROM client_phones) AS phones,
           (SELECT COUNT(*) FROM clients WHERE contacts_synced_at IS NULL) AS pending
')->fetch();

$log->appendLog(sprintf(
    '[Hermes] Directory sync: %d clients, %d phone numbers indexed, %d pending (%.1fs).',
    $counts['clients'], $counts['phones'], $counts['pending'], microtime(true) - $started
));
