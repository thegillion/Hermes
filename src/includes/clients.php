<?php

declare(strict_types=1);

use Ubnt\UcrmPluginSdk\Service\UcrmApi;

$api     = UcrmApi::create();
$clients = $api->get('clients', ['limit' => 500]) ?? [];

// ─── Build phone → client lookup map ─────────────────────────────────────────
// The bulk /clients list doesn't include contacts, so we fetch /clients/contacts
// separately — this returns all contacts across all clients with phone numbers.
$phoneToClient = [];

try {
    $allContacts = $api->get('clients/contacts', ['limit' => 2000]) ?? [];

    $clientById = [];
    foreach ($clients as $c) {
        $clientById[(int) $c['id']] = $c;
    }

    foreach ($allContacts as $contact) {
        $digits   = last10($contact['phone'] ?? '');
        $clientId = (int) ($contact['clientId'] ?? 0);
        if ($digits && $clientId && isset($clientById[$clientId])) {
            $phoneToClient[$digits] = $clientById[$clientId];
        }
    }
} catch (\Throwable $e) {
    // Fallback: try reading contacts directly from bulk list
    foreach ($clients as $c) {
        foreach (($c['contacts'] ?? []) as $contact) {
            $digits = last10($contact['phone'] ?? '');
            if ($digits) {
                $phoneToClient[$digits] = $c;
            }
        }
    }
}
