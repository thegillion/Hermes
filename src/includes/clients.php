<?php

declare(strict_types=1);

use Ubnt\UcrmPluginSdk\Service\UcrmApi;

// ─── Client directory ─────────────────────────────────────────────────────────
// Resolves phone numbers to UISP clients for the inbox, dashboard widget and
// incoming-SMS webhook. Results are cached in SQLite (clients, client_phones)
// so pages don't hit the UCRM API for every client on every load.
//
// Why a cache: the previous version called GET /clients/contacts, which is not
// a UCRM endpoint, so it always fell back to the bulk /clients list — capped at
// 500 clients and not guaranteed to include contacts. Only the client widget
// worked, because it fetches /clients/{id} directly.
//
// Sync strategy:
//   1. Page through GET /clients (all clients, not just the first 500).
//      If the list includes contacts, index their phones straight away.
//   2. Otherwise fetch GET /clients/{id}/contacts for each client, oldest-synced
//      first, within a time budget. Progress persists, so a large install fills
//      in over a few page loads (or one scheduled main.php run).

const DIRECTORY_LIST_TTL     = 900;    // re-list clients every 15 minutes
const DIRECTORY_CONTACTS_TTL = 86400;  // re-fetch each client's contacts daily
const DIRECTORY_PAGE_SIZE    = 500;
const DIRECTORY_MISSING_TTL  = 86400;  // re-check deleted client ids daily

function directoryApi(): ?UcrmApi {
    static $api = false;
    if ($api === false) {
        try {
            $api = UcrmApi::create();
        } catch (\Throwable $e) {
            $api = null;
        }
    }
    return $api;
}

function directoryLog(string $message): void {
    global $log;
    if (isset($log)) {
        $log->appendLog('[Hermes] Directory: ' . $message);
    }
}

// Store a client and, when contacts are known, replace its indexed phones.
function directoryStoreClient(array $client, ?array $contacts): void {
    $db = getDb();
    $id = (int) ($client['id'] ?? 0);
    if (!$id) return;

    $phones = [];
    foreach ($contacts ?? [] as $contact) {
        $phone = trim((string) ($contact['phone'] ?? ''));
        if ($phone !== '' && last10($phone) !== '') {
            $phones[last10($phone)] = $phone;
        }
    }

    $db->prepare('
        INSERT INTO clients (id, name, first_phone, contacts_synced_at)
        VALUES (:id, :name, :phone, :synced)
        ON CONFLICT(id) DO UPDATE SET
            name               = excluded.name,
            first_phone        = COALESCE(excluded.first_phone, clients.first_phone),
            contacts_synced_at = COALESCE(excluded.contacts_synced_at, clients.contacts_synced_at)
    ')->execute([
        ':id'     => $id,
        ':name'   => clientDisplayName($client),
        ':phone'  => $contacts === null ? null : (reset($phones) ?: ''),
        ':synced' => $contacts === null ? null : time(),
    ]);

    if ($contacts === null) return;

    $db->prepare('DELETE FROM client_phones WHERE client_id = :id')->execute([':id' => $id]);
    $ins = $db->prepare('
        INSERT OR IGNORE INTO client_phones (phone_key, client_id, phone) VALUES (:key, :id, :phone)
    ');
    foreach ($phones as $key => $phone) {
        $ins->execute([':key' => $key, ':id' => $id, ':phone' => $phone]);
    }
}

// Bring the cache up to date, spending at most $budgetSeconds on API calls.
function directorySync(float $budgetSeconds = 3.0, bool $force = false): void {
    $api = directoryApi();
    if (!$api) return;

    $deadline = microtime(true) + $budgetSeconds;
    $db       = getDb();

    // ── Step 1: list every client ─────────────────────────────────────────────
    $listedAt = (int) (metaGet('clients_listed_at') ?? 0);
    if ($force || time() - $listedAt > DIRECTORY_LIST_TTL) {
        try {
            $offset = 0;
            do {
                $page = $api->get('clients', [
                    'limit'  => DIRECTORY_PAGE_SIZE,
                    'offset' => $offset,
                ]) ?? [];

                $db->beginTransaction();
                foreach ($page as $client) {
                    $contacts = array_key_exists('contacts', $client) && is_array($client['contacts'])
                        ? $client['contacts']
                        : null;
                    directoryStoreClient($client, $contacts);
                }
                $db->commit();

                $offset += DIRECTORY_PAGE_SIZE;
            } while (count($page) === DIRECTORY_PAGE_SIZE);

            metaSet('clients_listed_at', (string) time());
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            directoryLog('could not list clients — ' . $e->getMessage());
            return;
        }
    }

    // ── Step 2: fetch contacts for clients the list didn't cover ──────────────
    $st = $db->prepare('
        SELECT id FROM clients
        WHERE contacts_synced_at IS NULL OR contacts_synced_at < :stale
        ORDER BY contacts_synced_at IS NOT NULL, contacts_synced_at, id
    ');
    $st->execute([':stale' => time() - DIRECTORY_CONTACTS_TTL]);
    $pending = $st->fetchAll(PDO::FETCH_COLUMN);

    foreach ($pending as $clientId) {
        if (microtime(true) >= $deadline) break;
        try {
            $contacts = $api->get("clients/{$clientId}/contacts") ?? [];
            $row      = $db->query('SELECT id, name FROM clients WHERE id = ' . (int) $clientId)->fetch();
            directoryStoreClient($row, $contacts);
        } catch (\Throwable $e) {
            directoryLog("could not fetch contacts for client {$clientId} — " . $e->getMessage());
            // Mark as synced anyway so one bad client can't block the queue.
            $db->prepare('UPDATE clients SET contacts_synced_at = :t WHERE id = :id')
               ->execute([':t' => time(), ':id' => $clientId]);
        }
    }

    directoryBackfillMessages();
}

// Tag messages that have no client yet (e.g. inbound SMS that arrived before
// the client's number was indexed) with the client their number belongs to.
function directoryBackfillMessages(): void {
    $db   = getDb();
    $keys = $db->query('
        SELECT DISTINCT phone_key FROM messages WHERE client_id IS NULL AND phone_key IS NOT NULL
    ')->fetchAll(PDO::FETCH_COLUMN);

    $upd = $db->prepare('UPDATE messages SET client_id = :cid WHERE phone_key = :key AND client_id IS NULL');
    foreach ($keys as $key) {
        $client = directoryLookupKey((string) $key);
        if ($client) {
            $upd->execute([':cid' => (int) $client['id'], ':key' => $key]);
        }
    }
}

function directoryLookupKey(string $key): ?array {
    if ($key === '') return null;
    $st = getDb()->prepare('
        SELECT c.* FROM client_phones p
        JOIN clients c ON c.id = p.client_id
        WHERE p.phone_key = :key
        ORDER BY c.id
        LIMIT 1
    ');
    $st->execute([':key' => $key]);
    return $st->fetch() ?: null;
}

// Cache-only lookup: never calls the API.
function directoryLookupPhone(string $phone): ?array {
    return directoryLookupKey(last10($phone));
}

// Look up a client by id, fetching it from the API on a cache miss.
function directoryGetClient(int $clientId): ?array {
    static $memo = [];
    if (array_key_exists($clientId, $memo)) return $memo[$clientId];

    $st = getDb()->prepare('SELECT * FROM clients WHERE id = :id');
    $st->execute([':id' => $clientId]);
    $row = $st->fetch() ?: null;

    if (!$row && !directoryKnownMissing($clientId) && ($api = directoryApi())) {
        try {
            $client = $api->get("clients/{$clientId}");
            if (is_array($client) && !empty($client['id'])) {
                directoryStoreClient($client, $client['contacts'] ?? null);
                getDb()->prepare('DELETE FROM missing_clients WHERE id = :id')->execute([':id' => $clientId]);
                $st->execute([':id' => $clientId]);
                $row = $st->fetch() ?: null;
            }
        } catch (\Throwable $e) {
            if ($e->getCode() === 404) {
                // Client was deleted in UISP. Remember that so we don't ask again
                // on every page load; callers fall back to the phone lookup.
                getDb()->prepare('INSERT OR REPLACE INTO missing_clients (id, checked_at) VALUES (:id, :t)')
                       ->execute([':id' => $clientId, ':t' => time()]);
                directoryLog("client {$clientId} no longer exists in UISP — using phone lookup for its messages.");
            } else {
                directoryLog("could not fetch client {$clientId} — " . $e->getMessage());
            }
        }
    }

    return $memo[$clientId] = $row;
}

function directoryKnownMissing(int $clientId): bool {
    $st = getDb()->prepare('SELECT checked_at FROM missing_clients WHERE id = :id');
    $st->execute([':id' => $clientId]);
    $checkedAt = $st->fetchColumn();
    return $checkedAt !== false && time() - (int) $checkedAt < DIRECTORY_MISSING_TTL;
}

// All cached clients, sorted by name, for the New Message picker.
function directoryAllClients(): array {
    return getDb()->query('SELECT * FROM clients ORDER BY name COLLATE NOCASE')->fetchAll() ?: [];
}
