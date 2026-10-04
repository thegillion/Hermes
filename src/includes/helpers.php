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

    // Enable WAL mode for better concurrent read/write performance
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    // ── Messages table ────────────────────────────────────────────────────────
    $db->exec('
        CREATE TABLE IF NOT EXISTS messages (
            id          TEXT    PRIMARY KEY,
            direction   TEXT    NOT NULL CHECK(direction IN ("inbound","outbound")),
            from_number TEXT    NOT NULL,
            to_number   TEXT    NOT NULL,
            body        TEXT    NOT NULL,
            timestamp   TEXT    NOT NULL,
            client_id   INTEGER,
            is_read     INTEGER NOT NULL DEFAULT 0,
            is_auto     INTEGER NOT NULL DEFAULT 0
        )
    ');

    // Indexes for common queries
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_timestamp   ON messages(timestamp DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_from        ON messages(from_number)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_to          ON messages(to_number)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_client      ON messages(client_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_read        ON messages(is_read)');

    return $db;
}

// ─── Message CRUD ─────────────────────────────────────────────────────────────

function addMessage(array $msg): void {
    $db = getDb();
    $st = $db->prepare('
        INSERT OR IGNORE INTO messages
            (id, direction, from_number, to_number, body, timestamp, client_id, is_auto)
        VALUES
            (:id, :direction, :from, :to, :body, :timestamp, :clientId, :auto)
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
    ]);
}

function getMessages(int $limit = 2000, int $offset = 0): array {
    $db = getDb();
    $st = $db->prepare('
        SELECT * FROM messages
        ORDER BY timestamp ASC
        LIMIT :limit OFFSET :offset
    ');
    $st->execute([':limit' => $limit, ':offset' => $offset]);
    return $st->fetchAll() ?: [];
}

function getThreadMessages(string $phone, int $page = 1): array {
    $db      = getDb();
    $key     = last10($phone);
    $offset  = ($page - 1) * PAGE_SIZE;

    // Match on last 10 digits of both from and to columns
    $st = $db->prepare('
        SELECT * FROM (
            SELECT * FROM messages
            WHERE substr(replace(replace(replace(replace(replace(replace(
                replace(replace(replace(replace(from_number,
                "+",""),"-","")," ",""),"(",""),")",""),".",
                ""),"[",""),"]",""),"x",""),"ext",""), -10) = :key
            OR substr(replace(replace(replace(replace(replace(replace(
                replace(replace(replace(replace(to_number,
                "+",""),"-","")," ",""),"(",""),")",""),".",
                ""),"[",""),"]",""),"x",""),"ext",""), -10) = :key
        )
        ORDER BY timestamp ASC
        LIMIT :limit OFFSET :offset
    ');
    $st->execute([':key' => $key, ':limit' => PAGE_SIZE, ':offset' => $offset]);
    return $st->fetchAll() ?: [];
}

function countThreadMessages(string $phone): int {
    $db  = getDb();
    $key = last10($phone);
    $st  = $db->prepare('
        SELECT COUNT(*) FROM messages
        WHERE substr(replace(replace(replace(replace(replace(replace(
            replace(replace(replace(replace(from_number,
            "+",""),"-","")," ",""),"(",""),")",""),".",
            ""),"[",""),"]",""),"x",""),"ext",""), -10) = :key
        OR substr(replace(replace(replace(replace(replace(replace(
            replace(replace(replace(replace(to_number,
            "+",""),"-","")," ",""),"(",""),")",""),".",
            ""),"[",""),"]",""),"x",""),"ext",""), -10) = :key
    ');
    $st->execute([':key' => $key]);
    return (int) $st->fetchColumn();
}

// ─── Thread building ──────────────────────────────────────────────────────────

function buildThreads(array $phoneToClient): array {
    $db = getDb();

    // Get the most recent message per conversation partner using a single query
    $rows = $db->query('
        SELECT
            m.*,
            CASE WHEN m.direction = "inbound" THEN m.from_number ELSE m.to_number END AS partner
        FROM messages m
        INNER JOIN (
            SELECT
                CASE WHEN direction = "inbound" THEN from_number ELSE to_number END AS p,
                MAX(timestamp) AS max_ts
            FROM messages
            GROUP BY p
        ) latest ON
            (CASE WHEN m.direction = "inbound" THEN m.from_number ELSE m.to_number END) = latest.p
            AND m.timestamp = latest.max_ts
        ORDER BY m.timestamp DESC
    ')->fetchAll();

    $threads = [];
    foreach ($rows as $row) {
        $phone = $row['partner'];
        $key   = last10($phone);
        if (isset($threads[$key])) continue; // deduplicate

        $threads[$key] = [
            'phone'    => $phone,
            'lastMsg'  => rowToMsg($row),
            'client'   => lookupClient($phoneToClient, $phone),
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

function lookupClient(array $map, string $phone): ?array {
    return $map[last10($phone)] ?? null;
}

function clientDisplayName(array $c): string {
    return trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')) ?: 'Unknown';
}

function clientFirstPhone(array $c): string {
    foreach (($c['contacts'] ?? []) as $contact) {
        if (!empty($contact['phone'])) {
            return $contact['phone'];
        }
    }
    return '';
}
