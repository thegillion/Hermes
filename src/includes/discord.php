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
