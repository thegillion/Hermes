<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;

// ─── discord.php — Posts incoming SMS to a Discord channel ───────────────────
// Configure "Discord Webhook URL" in plugin settings (Discord: Server Settings
// → Integrations → Webhooks → New Webhook → Copy Webhook URL).

function discordWebhookUrl(array $config): string {
    $url = trim((string) ($config['discordWebhookUrl'] ?? ''));
    // Only post to real Discord webhook URLs, so a typo can't send customer
    // texts somewhere else.
    return preg_match('#^https://(?:\w+\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+/?$#', $url) ? $url : '';
}

function discordNotifyInbound(array $config, string $from, string $body, ?array $client): void {
    global $log;

    $url = discordWebhookUrl($config);
    if ($url === '') {
        if (trim((string) ($config['discordWebhookUrl'] ?? '')) !== '') {
            $log->appendLog('[Hermes] Discord: Webhook URL is not a valid Discord webhook — skipping.');
        }
        return;
    }

    $name   = $client ? clientDisplayName($client) : 'Unknown number';
    $fields = [['name' => 'Phone', 'value' => $from, 'inline' => true]];

    $publicUrl = rtrim(trim((string) ($config['publicUrl'] ?? '')), '/');
    if ($client && $publicUrl !== '') {
        $fields[] = [
            'name'   => 'Client',
            'value'  => '[Open in UISP](' . $publicUrl . '/crm/client/' . (int) $client['id'] . ')',
            'inline' => true,
        ];
    }

    $payload = [
        'username' => 'Hermes SMS',
        // Never let a customer's text ping @everyone, @here, roles or users.
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title'       => '📨 ' . mb_strimwidth($name, 0, 250, '…'),
            'description' => mb_strimwidth($body, 0, 4000, '…'),
            'color'       => $client ? 0x6366F1 : 0xF59E0B,
            'fields'      => $fields,
            'footer'      => ['text' => 'Reply from the Hermes SMS Inbox in UISP'],
            'timestamp'   => date('c'),
        ]],
    ];

    try {
        (new HttpClient(['timeout' => 5, 'connect_timeout' => 3]))
            ->post($url, ['json' => $payload]);
    } catch (\Throwable $e) {
        $log->appendLog('[Hermes] Discord: post failed — ' . $e->getMessage());
    }
}

// ─── Unanswered-text reminders ────────────────────────────────────────────────
// If an incoming SMS gets no reply (and isn't marked read) within the configured
// number of minutes, ping the Discord channel once for that conversation.
// Runs on every plugin request and on each scheduled main.php run.

function discordReminderMinutes(array $config): int {
    return max(0, (int) trim((string) ($config['discordReminderMinutes'] ?? '')));
}

// Turn the "who to ping" setting into message text + Discord allowed_mentions.
// Accepts @here, @everyone, a role (<@&123> or role:123) or a user (<@123>).
function discordMention(array $config): array {
    $raw = trim((string) ($config['discordReminderMention'] ?? '')) ?: '@here';

    if ($raw === '@here' || $raw === '@everyone') {
        return [$raw, ['parse' => ['everyone']]];
    }
    if (preg_match('/^(?:<@&|role:)(\d+)>?$/', $raw, $m)) {
        return ["<@&{$m[1]}>", ['parse' => [], 'roles' => [$m[1]]]];
    }
    if (preg_match('/^<@!?(\d+)>$/', $raw, $m)) {
        return ["<@{$m[1]}>", ['parse' => [], 'users' => [$m[1]]]];
    }
    return ['', ['parse' => []]];
}

function discordCheckReminders(array $config): void {
    global $log;

    $minutes = discordReminderMinutes($config);
    $url     = discordWebhookUrl($config);
    if (!$minutes || $url === '') return;

    $db = getDb();

    // Only remind about texts received after reminders were switched on, so
    // turning the feature on doesn't ping for every old unanswered message.
    $since = metaGet('reminders_since');
    if ($since === null) {
        $since = (string) (int) $db->query('SELECT COALESCE(MAX(rowid), 0) FROM messages')->fetchColumn();
        metaSet('reminders_since', $since);
    }

    // Oldest unhandled incoming text per conversation, with no later staff reply.
    $st = $db->prepare("
        SELECT m.rowid AS rid, m.* FROM messages m
        WHERE m.direction = 'inbound' AND m.is_read = 0 AND m.reminded = 0
          AND m.rowid > :since
          AND NOT EXISTS (
              SELECT 1 FROM messages r
              WHERE r.phone_key = m.phone_key AND r.direction = 'outbound'
                AND r.is_auto = 0 AND r.rowid > m.rowid
          )
        ORDER BY m.rowid
    ");
    $st->execute([':since' => (int) $since]);

    $due = [];
    foreach ($st->fetchAll() as $row) {
        $key = $row['phone_key'];
        if (isset($due[$key])) {
            $due[$key]['count']++;
            continue;
        }
        if (time() - strtotime($row['timestamp']) >= $minutes * 60) {
            $due[$key] = ['row' => $row, 'count' => 1];
        }
    }

    [$mentionText, $allowed] = discordMention($config);
    $publicUrl = rtrim(trim((string) ($config['publicUrl'] ?? '')), '/');

    foreach ($due as $key => $item) {
        $row = $item['row'];

        // Claim the conversation first so overlapping requests ping only once.
        $claim = $db->prepare("
            UPDATE messages SET reminded = 1
            WHERE phone_key = :key AND direction = 'inbound' AND reminded = 0 AND rowid >= :rid
        ");
        $claim->execute([':key' => $key, ':rid' => $row['rid']]);
        if ($claim->rowCount() === 0) continue;

        $client = $row['client_id'] !== null
            ? directoryGetClient((int) $row['client_id'])
            : directoryLookupPhone($row['from_number']);
        $name   = $client ? clientDisplayName($client) : $row['from_number'];
        $waited = (int) floor((time() - strtotime($row['timestamp'])) / 60);

        $fields = [
            ['name' => 'Phone',   'value' => $row['from_number'], 'inline' => true],
            ['name' => 'Waiting', 'value' => $waited . ' min' . ($item['count'] > 1 ? " · {$item['count']} texts" : ''), 'inline' => true],
        ];
        if ($client && $publicUrl !== '') {
            $fields[] = [
                'name'   => 'Client',
                'value'  => '[Open in UISP](' . $publicUrl . '/crm/client/' . (int) $client['id'] . ')',
                'inline' => true,
            ];
        }

        $payload = [
            'username'         => 'Hermes SMS',
            'content'          => trim($mentionText . ' ⏰ Text from **' . str_replace(['*', '_', '`', '~', '|', '@'], '', $name) . "** has had no reply for {$waited} minutes."),
            'allowed_mentions' => $allowed,
            'embeds'           => [[
                'title'       => '⏰ ' . mb_strimwidth($name, 0, 250, '…'),
                'description' => mb_strimwidth($row['body'], 0, 4000, '…'),
                'color'       => 0xEF4444,
                'fields'      => $fields,
                'footer'      => ['text' => 'Reply or mark as read in Hermes to clear'],
                'timestamp'   => date('c', strtotime($row['timestamp'])),
            ]],
        ];

        try {
            (new HttpClient(['timeout' => 5, 'connect_timeout' => 3]))->post($url, ['json' => $payload]);
            $log->appendLog("[Hermes] Discord: reminder sent for {$row['from_number']} ({$waited} min without reply).");
        } catch (\Throwable $e) {
            $log->appendLog('[Hermes] Discord: reminder failed — ' . $e->getMessage());
        }
    }
}
