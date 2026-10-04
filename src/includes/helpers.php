<?php

declare(strict_types=1);

// ─── Phone normalization ──────────────────────────────────────────────────────

function normalizePhone(string $phone): string {
    return preg_replace('/[^0-9+]/', '', $phone);
}

function stripToDigits(string $phone): string {
    return preg_replace('/\D/', '', $phone);
}

// Normalize to last 10 digits for matching.
// Handles +18593216507, 18593216507, and 8593216507 → all become 8593216507
function last10(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

function generateId(): string {
    return bin2hex(random_bytes(8));
}

// ─── SQLite Database ──────────────────────────────────────────────────────────

function getDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 10);

    // Enable WAL mode for better concurrent read/write performance
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    // ── Messages table ────────────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id          TEXT    PRIMARY KEY,
            direction   TEXT    NOT NULL CHECK(direction IN ('inbound','outbound')),
            from_number TEXT    NOT NULL,
            to_number   TEXT    NOT NULL,
            body        TEXT    NOT NULL,
            timestamp   TEXT    NOT NULL,
            client_id   INTEGER,
            is_read     INTEGER NOT NULL DEFAULT 0,
            is_auto     INTEGER NOT NULL DEFAULT 0
        )
    ");

    // phone_key = last 10 digits of the conversation partner's number.
    // Added in 1.4.0 so threads group reliably regardless of number formatting.
    $cols = array_column($db->query('PRAGMA table_info(messages)')->fetchAll(), 'name');
    if (!in_array('phone_key', $cols, true)) {
        $db->exec('ALTER TABLE messages ADD COLUMN phone_key TEXT');
    }
    // reminded = 1 once a Discord "no reply yet" ping covered this message.
    if (!in_array('reminded', $cols, true)) {
        $db->exec('ALTER TABLE messages ADD COLUMN reminded INTEGER NOT NULL DEFAULT 0');
    }

    // Indexes for common queries
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_timestamp   ON messages(timestamp DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_client      ON messages(client_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_read        ON messages(is_read)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_phone_key   ON messages(phone_key, timestamp)');

    // ── Client directory cache (see clients.php) ──────────────────────────────
    $db->exec('
        CREATE TABLE IF NOT EXISTS clients (
            id                 INTEGER PRIMARY KEY,
            name               TEXT    NOT NULL,
            first_phone        TEXT,
            contacts_synced_at INTEGER
        )
    ');
    $db->exec('
        CREATE TABLE IF NOT EXISTS client_phones (
            phone_key TEXT    NOT NULL,
            client_id INTEGER NOT NULL,
            phone     TEXT    NOT NULL,
            PRIMARY KEY (phone_key, client_id)
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_client_phones_client ON client_phones(client_id)');
    $db->exec('
        CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        )
    ');

    backfillPhoneKeys($db);

    return $db;
}

// Fill phone_key for rows written before 1.4.0 or by the JSON migration.
function backfillPhoneKeys(PDO $db): void {
    $rows = $db->query('
        SELECT id, direction, from_number, to_number FROM messages WHERE phone_key IS NULL
    ')->fetchAll();
    if (!$rows) return;

    $st = $db->prepare('UPDATE messages SET phone_key = :key WHERE id = :id');
    $db->beginTransaction();
    foreach ($rows as $row) {
        $partner = $row['direction'] === 'inbound' ? $row['from_number'] : $row['to_number'];
        $st->execute([':key' => last10($partner), ':id' => $row['id']]);
    }
    $db->commit();
}

function metaGet(string $key): ?string {
    $st = getDb()->prepare('SELECT value FROM meta WHERE key = :key');
    $st->execute([':key' => $key]);
    $value = $st->fetchColumn();
    return $value === false ? null : (string) $value;
}

function metaSet(string $key, string $value): void {
    getDb()->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (:key, :value)')
           ->execute([':key' => $key, ':value' => $value]);
}

// ─── Message CRUD ─────────────────────────────────────────────────────────────

// Returns false when the message id already exists (e.g. a Twilio retry).
function addMessage(array $msg): bool {
    $db      = getDb();
    $partner = $msg['direction'] === 'inbound' ? $msg['from'] : $msg['to'];
    $st = $db->prepare('
        INSERT OR IGNORE INTO messages
            (id, direction, from_number, to_number, body, timestamp, client_id, is_auto, phone_key)
        VALUES
            (:id, :direction, :from, :to, :body, :timestamp, :clientId, :auto, :phoneKey)
    ');
    $st->execute([
        ':id'        => $msg['id']        ?? generateId(),
        ':direction' => $msg['direction'],
        ':from'      => $msg['from'],
        ':to'        => $msg['to'],
        ':body'      => $msg['body'],
        ':timestamp' => $msg['timestamp'] ?? date('c'),
        ':clientId'  => $msg['clientId']  ?? null,
        ':auto'      => ($msg['auto'] ?? false) ? 1 : 0,
        ':phoneKey'  => last10($partner),
    ]);
    return $st->rowCount() > 0;
}

// Messages are returned oldest-first. Page 1 is the OLDEST page; use
// getRecentThreadMessages() when you want the latest messages.
function getThreadMessages(string $phone, int $page = 1): array {
    $st = getDb()->prepare('
        SELECT * FROM messages
        WHERE phone_key = :key
        ORDER BY timestamp ASC
        LIMIT :limit OFFSET :offset
    ');
    $st->execute([
        ':key'    => last10($phone),
        ':limit'  => PAGE_SIZE,
        ':offset' => (max(1, $page) - 1) * PAGE_SIZE,
    ]);
    return $st->fetchAll() ?: [];
}

// The most recent $limit messages in a thread, oldest-first.
function getRecentThreadMessages(string $phone, int $limit = PAGE_SIZE): array {
    $st = getDb()->prepare('
        SELECT * FROM (
            SELECT * FROM messages
            WHERE phone_key = :key
            ORDER BY timestamp DESC
            LIMIT :limit
        ) ORDER BY timestamp ASC
    ');
    $st->execute([':key' => last10($phone), ':limit' => $limit]);
    return $st->fetchAll() ?: [];
}

function countThreadMessages(string $phone): int {
    $st = getDb()->prepare('SELECT COUNT(*) FROM messages WHERE phone_key = :key');
    $st->execute([':key' => last10($phone)]);
    return (int) $st->fetchColumn();
}

// ─── Thread building ──────────────────────────────────────────────────────────

// One entry per conversation partner, newest first, keyed by phone_key.
// Each thread's client is resolved from the most recent client_id recorded
// on its messages, falling back to the cached phone → client directory.
function buildThreads(): array {
    $rows = getDb()->query('
        SELECT m.*,
               CASE WHEN m.direction = \'inbound\' THEN m.from_number ELSE m.to_number END AS partner,
               (SELECT client_id FROM messages c
                 WHERE c.phone_key = m.phone_key AND c.client_id IS NOT NULL
                 ORDER BY c.timestamp DESC LIMIT 1) AS thread_client_id
        FROM messages m
        WHERE m.rowid = (
            SELECT rowid FROM messages x
            WHERE x.phone_key = m.phone_key
            ORDER BY x.timestamp DESC, x.rowid DESC
            LIMIT 1
        )
        ORDER BY m.timestamp DESC
    ')->fetchAll();

    $threads = [];
    foreach ($rows as $row) {
        $client = $row['thread_client_id'] !== null
            ? directoryGetClient((int) $row['thread_client_id'])
            : null;

        $threads[$row['phone_key']] = [
            'phone'    => $row['partner'],
            'lastMsg'  => rowToMsg($row),
            'client'   => $client ?? directoryLookupPhone($row['partner']),
            'lastTime' => $row['timestamp'],
        ];
    }

    return $threads;
}

// Convert a DB row to the message array format used in views
function rowToMsg(array $row): array {
    return [
        'id'        => $row['id'],
        'direction' => $row['direction'],
        'from'      => $row['from_number'],
        'to'        => $row['to_number'],
        'body'      => $row['body'],
        'timestamp' => $row['timestamp'],
        'clientId'  => $row['client_id'] ?? null,
        'auto'      => (bool) ($row['is_auto'] ?? 0),
        'is_read'   => (bool) ($row['is_read'] ?? 0),
    ];
}

// ─── Read/unread tracking ─────────────────────────────────────────────────────

function markAsRead(array $ids): void {
    if (empty($ids)) return;
    $db          = getDb();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE messages SET is_read = 1 WHERE id IN ({$placeholders})")
       ->execute($ids);
}

function getUnreadMessages(): array {
    $db = getDb();
    $st = $db->query("
        SELECT * FROM messages
        WHERE direction = 'inbound' AND is_read = 0
        ORDER BY timestamp DESC
    ");
    return array_map('rowToMsg', $st->fetchAll() ?: []);
}

function isUnread(array $msg): bool {
    return $msg['direction'] === 'inbound' && !($msg['is_read'] ?? false);
}

// ─── Client helpers ───────────────────────────────────────────────────────────

// Works for both UCRM API client records and rows from the clients cache table.
function clientDisplayName(array $c): string {
    if (!empty($c['name'])) {
        return $c['name'];
    }
    $person  = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
    $company = trim($c['companyName'] ?? '');
    $isCompany = (int) ($c['clientType'] ?? 1) === 2;

    if ($isCompany && $company !== '') return $company;
    if ($person !== '')                return $person;
    if ($company !== '')               return $company;
    return isset($c['id']) ? 'Client #' . $c['id'] : 'Unknown';
}

function clientFirstPhone(array $c): string {
    if (!empty($c['first_phone'])) {
        return $c['first_phone'];
    }
    foreach (($c['contacts'] ?? []) as $contact) {
        if (!empty($contact['phone'])) {
            return $contact['phone'];
        }
    }
    return '';
}
