<?php

declare(strict_types=1);

use Twilio\Security\RequestValidator;

// ─── ears.php — Listens for incoming SMS from Twilio ─────────────────────────
// Twilio POSTs here when a message is received on your number.
// Set this URL in: Twilio Console → Phone Numbers → Messaging

$signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

// ─── Determine if this host is a local/private address ───────────────────────
// If running on a private IP or localhost, Twilio cannot reach this server
// directly, so signature validation is skipped (test/dev environment).
function isPrivateHost(string $host): bool {
    // Strip port if present
    $host = strtolower(preg_replace('/:\d+$/', '', $host));

    // Localhost variants
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return true;
    }

    // Private IP ranges: 10.x.x.x, 172.16-31.x.x, 192.168.x.x
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($host);
        return (
            ($long & 0xFF000000) === ip2long('10.0.0.0')       ||  // 10.0.0.0/8
            ($long & 0xFFF00000) === ip2long('172.16.0.0')     ||  // 172.16.0.0/12
            ($long & 0xFFFF0000) === ip2long('192.168.0.0')    ||  // 192.168.0.0/16
            ($long & 0xFF000000) === ip2long('100.0.0.0')          // 100.64.0.0/10 (CGNAT)
        );
    }

    return false;
}

// ─── Validate Twilio signature ────────────────────────────────────────────────
if ($authToken) {

    // Determine the host being used — check forwarded headers first
    $host = !empty($_SERVER['HTTP_X_FORWARDED_HOST'])
        ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0])
        : ($_SERVER['HTTP_HOST'] ?? 'localhost');

    if (isPrivateHost($host)) {
        // Local/private network — Twilio can't reach this server so
        // signature validation is meaningless. Log and continue.
        $log->appendLog(
            '[Hermes] Webhook received on private/local host (' . $host . ') — ' .
            'skipping Twilio signature validation. ' .
            'Set the Public URL in plugin config when deploying to production.'
        );
    } else {
        // Public host — validate the signature using the canonical webhook URL
        // built in config.php from the admin-configured Public URL.
        $validator = new RequestValidator($authToken);

        if (!$validator->validate($signature, $webhookUrl, $_POST)) {
            $log->appendLog(
                '[Hermes] Invalid Twilio signature.' .
                ' Tried: ' . $webhookUrl .
                ' — If this URL is wrong, set the correct Public URL in plugin config.'
            );
            http_response_code(403);
            header('Content-Type: text/xml');
            echo '<Response></Response>';
            exit;
        }
    }
}

// ─── Store the incoming message ───────────────────────────────────────────────
$from = $_POST['From'] ?? '';
$to   = $_POST['To']   ?? '';
$body = $_POST['Body'] ?? '';
$sid  = $_POST['MessageSid'] ?? generateId();

$isNew  = false;
$client = null;

if ($from && $body) {
    $client = directoryLookupPhone($from);
    $isNew  = addMessage([
        'id'        => $sid,
        'direction' => 'inbound',
        'from'      => $from,
        'to'        => $to,
        'body'      => $body,
        'timestamp' => date('c'),
        'clientId'  => $client['id'] ?? null,
    ]);
    $log->appendLog('[Hermes] Incoming SMS from ' . $from . ': ' . mb_strimwidth($body, 0, 80, '…'));
}

// Respond with empty TwiML — no auto-reply
header('Content-Type: text/xml');
echo '<Response></Response>';

// Answer Twilio before talking to Discord so a slow Discord can't delay it.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if ($isNew) {
    discordNotifyInbound($config, $from, $body, $client);
}
discordCheckReminders($config);
exit;