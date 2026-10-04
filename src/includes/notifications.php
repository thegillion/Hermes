<?php

declare(strict_types=1);

use Twilio\Rest\Client as TwilioClient;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;

// ─── notifications.php ────────────────────────────────────────────────────────
// Called from public.php when UISP fires a plugin webhook event.
// UISP sends the full entity data inside extraData.entity so we don't
// need to make separate API calls for invoice/payment/service data —
// we only call the API once to get the client's phone number.

$log->appendLog('[Hermes] Notification: main.php triggered.');

// ─── Read payload ─────────────────────────────────────────────────────────────
// Body may be pre-read in public.php to detect the event type
$rawBody = defined('UISP_EVENT_BODY') ? UISP_EVENT_BODY : file_get_contents('php://input');

if (!$rawBody) {
    $log->appendLog('[Hermes] Notification: empty request body.');
    exit;
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    $log->appendLog('[Hermes] Notification: invalid JSON — ' . json_last_error_msg());
    exit;
}

$eventName  = $payload['eventName']  ?? '';
$changeType = $payload['changeType'] ?? '';
$entity     = $payload['entity']     ?? '';
$entityId   = (int) ($payload['entityId'] ?? 0);
$entityData = $payload['extraData']['entity'] ?? [];

$log->appendLog("[Hermes] Notification: event={$eventName} entity={$entity} id={$entityId}");

// Ignore UISP test pings
if ($changeType === 'test') {
    $log->appendLog('[Hermes] Notification: test ping received — OK.');
    exit;
}

// ─── Check if this event has a message template configured ────────────────────
$configKey = 'event_' . str_replace('.', '_', $eventName);
$template  = trim($config[$configKey] ?? '');

if (!$template) {
    $log->appendLog("[Hermes] Notification: no template configured for '{$eventName}' — skipping.");
    exit;
}

if (!$entityId || empty($entityData)) {
    $log->appendLog("[Hermes] Notification: missing entity data for event '{$eventName}'.");
    exit;
}

// ─── Get clientId from entity data ───────────────────────────────────────────
$clientId = (int) ($entityData['clientId'] ?? 0);

if (!$clientId) {
    $log->appendLog("[Hermes] Notification: no clientId found in entity data.");
    exit;
}

// ─── Skip $0 invoices unless option enabled ───────────────────────────────────
if ($entity === 'invoice') {
    $send0 = filter_var($config['send_0_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$send0 && (float) ($entityData['total'] ?? 0) === 0.0) {
        $log->appendLog('[Hermes] Notification: skipping $0 invoice.');
        exit;
    }
}

// ─── Fetch client phone number from API ──────────────────────────────────────
// The webhook payload doesn't include contact phone numbers so we
// need one API call to get them.
try {
    $api    = UcrmApi::create();
    $client = $api->get("clients/{$clientId}");
} catch (\Throwable $e) {
    $log->appendLog("[Hermes] Notification: could not fetch client {$clientId} — " . $e->getMessage());
    exit;
}

if (empty($client)) {
    $log->appendLog("[Hermes] Notification: client {$clientId} not found.");
    exit;
}

// Find phone — prefer billing contact for invoice/payment events
$toNumber = '';
$contacts = $client['contacts'] ?? [];

$preferBilling = in_array($entity, ['invoice', 'payment'], true);

foreach ($contacts as $contact) {
    if (empty($contact['phone'])) continue;
    $match = $preferBilling
        ? ($contact['isBilling'] ?? false)
        : ($contact['isContact'] ?? false);
    if ($match) {
        $toNumber = $contact['phone'];
        break;
    }
}

// Fallback: any phone number
if (!$toNumber) {
    foreach ($contacts as $contact) {
        if (!empty($contact['phone'])) {
            $toNumber = $contact['phone'];
            break;
        }
    }
}

if (!$toNumber) {
    $log->appendLog("[Hermes] Notification: client {$clientId} has no phone number — skipping.");
    exit;
}

// ─── Build message from template ─────────────────────────────────────────────
// Replace %%entity.field%% placeholders with values from the entity data.
// Also support %%client.firstName%% etc from the entity data directly
// (UISP embeds clientFirstName, clientLastName etc in invoice payloads).
$tokens = [];

// Map entity fields → %%entity.field%%
foreach ($entityData as $key => $value) {
    if (is_array($value)) continue;

    // Format date fields
    if ($value && preg_match('/Date/i', $key)) {
        try {
            $dt = new \DateTime($value);
            $value = $dt->format('M j, Y');
        } catch (\Throwable $e) {}
    }

    $tokens['%%' . $entity . '.' . $key . '%%'] = (string) ($value ?? '');
}

// Client fields come from the client record we fetched above, so they work
// for every event (payment payloads don't embed client names).
foreach ($client as $key => $value) {
    if (is_scalar($value) || $value === null) {
        $tokens['%%client.' . $key . '%%'] = (string) ($value ?? '');
    }
}
$tokens['%%client.name%%'] = clientDisplayName($client);

// Invoice payloads also embed clientFirstName etc. — prefer those when present
// since they reflect the name printed on the invoice.
$clientFieldMap = [
    'clientFirstName'   => 'firstName',
    'clientLastName'    => 'lastName',
    'clientCompanyName' => 'companyName',
];
foreach ($clientFieldMap as $entityKey => $clientKey) {
    if (isset($entityData[$entityKey])) {
        $tokens['%%client.' . $clientKey . '%%'] = (string) $entityData[$entityKey];
    }
}

// Format currency fields nicely
foreach (['total', 'subtotal', 'amountPaid', 'amountToPay', 'amount'] as $field) {
    $tokenKey = '%%' . $entity . '.' . $field . '%%';
    if (isset($tokens[$tokenKey]) && is_numeric($tokens[$tokenKey])) {
        $currency = $entityData['currencyCode'] ?? 'USD';
        $tokens[$tokenKey] = number_format((float) $tokens[$tokenKey], 2) . ' ' . $currency;
    }
}

$message = str_replace(array_keys($tokens), array_values($tokens), $template);

$log->appendLog("[Hermes] Notification: sending '{$eventName}' SMS to {$toNumber} — " . mb_strimwidth($message, 0, 60, '…'));

// ─── Send via Twilio ──────────────────────────────────────────────────────────
try {
    $twilio = new TwilioClient($accountSid, $authToken);
    $sent   = $twilio->messages->create($toNumber, [
        'from' => $fromNumber,
        'body' => $message,
    ]);

    addMessage([
        'id'        => $sent->sid,
        'direction' => 'outbound',
        'from'      => $fromNumber,
        'to'        => $toNumber,
        'body'      => $message,
        'timestamp' => date('c'),
        'clientId'  => $clientId,
        'auto'      => true,
    ]);

    $log->appendLog("[Hermes] Notification: SMS sent successfully to {$toNumber} (SID: {$sent->sid})");

} catch (\Twilio\Exceptions\RestException $e) {
    $log->appendLog('[Hermes] Notification Twilio error (' . $e->getStatusCode() . '): ' . $e->getMessage());
} catch (\Throwable $e) {
    $log->appendLog('[Hermes] Notification unexpected error: ' . $e->getMessage());
}