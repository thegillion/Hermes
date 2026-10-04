<?php

declare(strict_types=1);

use Twilio\Rest\Client as TwilioClient;

// ─── send.php — Handles outbound SMS sending ──────────────────────────────────
// Called when a POST request comes in with action=send.

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $configMissing) {
    return;
}

$toNumber = normalizePhone(trim($_POST['toNumber'] ?? ''));
$body     = trim($_POST['message'] ?? '');
$clientId = isset($_POST['clientId']) && $_POST['clientId'] !== '' ? (int) $_POST['clientId'] : null;

if (!$toNumber || !$body) {
    $errorMsg = 'A phone number and message are both required.';
    return;
}

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

    $log->appendLog('[Hermes] Sent SMS to ' . $toNumber . ': ' . mb_strimwidth($body, 0, 80, '…'));

    // Redirect to the conversation thread after sending
    header('Location: ?phone=' . urlencode($toNumber));
    exit;

} catch (\Twilio\Exceptions\RestException $e) {
    $errorMsg = 'Twilio error (' . $e->getStatusCode() . '): ' . $e->getMessage();
    $log->appendLog('[Hermes] Send failed to ' . $toNumber . ': ' . $e->getMessage());
} catch (\Throwable $e) {
    $errorMsg = 'Unexpected error: ' . $e->getMessage();
    $log->appendLog('[Hermes] Unexpected send error: ' . $e->getMessage());
}
