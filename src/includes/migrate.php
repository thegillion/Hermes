<?php

declare(strict_types=1);

// ─── migrate.php ──────────────────────────────────────────────────────────────
// One-time migration of messages.json (and read_ids.json) into SQLite.
// Once complete, writes a "migrated" flag into messages.json so this page
// never shows again.

define('LEGACY_MSG_FILE',  DATA_DIR . '/messages.json');
define('LEGACY_READ_FILE', DATA_DIR . '/read_ids.json');
define('MIGRATION_FLAG',   'migrated_to_sqlite');

// ─── Check migration status ───────────────────────────────────────────────────
function getMigrationStatus(): array {
    if (!file_exists(LEGACY_MSG_FILE)) {
        return ['needed' => false, 'reason' => 'no_json'];
    }
    $json = json_decode(file_get_contents(LEGACY_MSG_FILE), true);
    if (!is_array($json)) {
        return ['needed' => false, 'reason' => 'invalid_json'];
    }
    if (isset($json[MIGRATION_FLAG]) && $json[MIGRATION_FLAG] === true) {
        return ['needed' => false, 'reason' => 'already_done'];
    }
    // Count migratable messages (skip the flag key if it ever ended up in there)
    $messages = array_filter($json, fn($m) => is_array($m) && isset($m['body']));
    return ['needed' => true, 'count' => count($messages), 'messages' => array_values($messages)];
}

// ─── Run the migration ────────────────────────────────────────────────────────
function runMigration(): array {
    $status = getMigrationStatus();
    if (!$status['needed']) {
        return ['ok' => false, 'error' => 'Migration not needed or already complete.'];
    }

    $messages = $status['messages'];
    $readIds  = [];

    if (file_exists(LEGACY_READ_FILE)) {
        $readIds = json_decode(file_get_contents(LEGACY_READ_FILE), true) ?: [];
    }

    $db       = getDb();
    $inserted = 0;
    $skipped  = 0;

    $db->beginTransaction();
    try {
        $st = $db->prepare('
            INSERT OR IGNORE INTO messages
                (id, direction, from_number, to_number, body, timestamp, client_id, is_read, is_auto)
            VALUES
                (:id, :direction, :from, :to, :body, :timestamp, :clientId, :isRead, :isAuto)
        ');

        foreach ($messages as $msg) {
            if (!isset($msg['body'], $msg['direction'])) {
                $skipped++;
                continue;
            }

            $id      = $msg['id']        ?? generateId();
            $isRead  = in_array($id, $readIds, true) ? 1 : 0;
            $isAuto  = ($msg['auto']     ?? false) ? 1 : 0;
            $from    = $msg['from']      ?? '';
            $to      = $msg['to']        ?? '';

            if (!$from || !$to) {
                $skipped++;
                continue;
            }

            $st->execute([
                ':id'        => $id,
                ':direction' => $msg['direction'],
                ':from'      => $from,
                ':to'        => $to,
                ':body'      => $msg['body'],
                ':timestamp' => $msg['timestamp'] ?? date('c'),
                ':clientId'  => $msg['clientId']  ?? null,
                ':isRead'    => $isRead,
                ':isAuto'    => $isAuto,
            ]);

            $inserted++;
        }

        $db->commit();

    } catch (\Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // ── Write the migration flag into messages.json ───────────────────────────
    // We preserve the original data so it can be used as a backup,
    // but add the flag so the migration page never shows again.
    $flagged = $messages;
    array_unshift($flagged, [MIGRATION_FLAG => true, 'migrated_at' => date('c'), 'records_migrated' => $inserted]);
    file_put_contents(LEGACY_MSG_FILE, json_encode($flagged, JSON_PRETTY_PRINT), LOCK_EX);

    return [
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'readIds'  => count($readIds),
    ];
}

// ─── Handle AJAX run request ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_migration') {
    header('Content-Type: application/json');
    echo json_encode(runMigration());
    exit;
}

$status = getMigrationStatus();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hermes — Data Migration</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            color: #1a1a2e;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.1);
            padding: 36px;
            max-width: 560px;
            width: 100%;
        }
        .card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .card-icon { font-size: 36px; }
        .card-header h1 { font-size: 20px; font-weight: 700; }
        .card-header p  { font-size: 13px; color: #6b7280; margin-top: 3px; }

        .stat-row {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat {
            flex: 1;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px;
            text-align: center;
        }
        .stat .num   { font-size: 28px; font-weight: 700; color: #6366f1; }
        .stat .label { font-size: 12px; color: #6b7280; margin-top: 3px; }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13px;
            color: #1e40af;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .info-box strong { display: block; margin-bottom: 4px; }

        .btn {
            width: 100%;
            padding: 13px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-primary { background: #6366f1; color: #fff; }
        .btn-primary:hover:not(:disabled) { background: #4f46e5; }
        .btn-primary:disabled { background: #a5b4fc; cursor: not-allowed; }
        .btn-secondary { background: #f3f4f6; color: #374151; margin-top: 10px; }
        .btn-secondary:hover { background: #e5e7eb; }

        /* Progress */
        .progress-wrap { margin-bottom: 24px; display: none; }
        .progress-bar-bg {
            background: #e5e7eb;
            border-radius: 99px;
            height: 8px;
            overflow: hidden;
        }
        .progress-bar-fill {
            background: #6366f1;
            height: 100%;
            width: 0%;
            border-radius: 99px;
            transition: width .3s ease;
        }
        .progress-label { font-size: 12px; color: #6b7280; margin-top: 6px; text-align: center; }

        /* Result states */
        .result { border-radius: 8px; padding: 16px; font-size: 14px; margin-bottom: 20px; display: none; }
        .result.ok  { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .result.err { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .result h3  { font-size: 15px; margin-bottom: 8px; }
        .result ul  { padding-left: 18px; font-size: 13px; line-height: 1.8; }

        .done-state { text-align: center; }
        .done-state .done-icon { font-size: 48px; margin-bottom: 12px; }
        .done-state h2 { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .done-state p  { font-size: 13px; color: #6b7280; }
    </style>
</head>
<body>
<div class="card">

    <?php if (!$status['needed']): ?>
        <!-- ── Already done / no data to migrate ── -->
        <div class="done-state">
            <div class="done-icon">✅</div>
            <h2>
                <?= $status['reason'] === 'already_done' ? 'Migration Already Complete' : 'Nothing to Migrate' ?>
            </h2>
            <p>
                <?php if ($status['reason'] === 'already_done'): ?>
                    Your messages have already been migrated to SQLite.<br>
                    The old <code>messages.json</code> has been kept as a backup.
                <?php elseif ($status['reason'] === 'no_json'): ?>
                    No <code>messages.json</code> file was found — you're starting fresh with SQLite already.
                <?php else: ?>
                    The <code>messages.json</code> file could not be parsed.
                <?php endif; ?>
            </p>
            <button class="btn btn-secondary" onclick="window.location='?'">← Back to Inbox</button>
        </div>

    <?php else: ?>
        <!-- ── Migration ready ── -->
        <div class="card-header">
            <div class="card-icon">🗄️</div>
            <div>
                <h1>Migrate to SQLite</h1>
                <p>One-time import of your existing message history</p>
            </div>
        </div>

        <div class="stat-row">
            <div class="stat">
                <div class="num"><?= number_format($status['count']) ?></div>
                <div class="label">Messages to import</div>
            </div>
            <div class="stat">
                <div class="num" id="dbCount">—</div>
                <div class="label">Already in SQLite</div>
            </div>
        </div>

        <div class="info-box">
            <strong>What this does:</strong>
            Reads all messages from <code>messages.json</code> and imports them into the new
            SQLite database. Read/unread status is preserved from <code>read_ids.json</code>
            if it exists. When complete, a <em>migrated</em> flag is written to
            <code>messages.json</code> so this prompt never appears again.
            The original JSON file is kept as a backup.
        </div>

        <div class="progress-wrap" id="progressWrap">
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" id="progressFill"></div>
            </div>
            <div class="progress-label" id="progressLabel">Starting migration…</div>
        </div>

        <div class="result" id="resultOk">
            <h3>✅ Migration complete!</h3>
            <ul id="resultList"></ul>
        </div>
        <div class="result" id="resultErr">
            <h3>❌ Migration failed</h3>
            <p id="resultErrMsg"></p>
        </div>

        <button class="btn btn-primary" id="migrateBtn" onclick="runMigration()">
            Import <?= number_format($status['count']) ?> Messages into SQLite
        </button>
        <button class="btn btn-secondary" onclick="window.location='?'">← Skip for now</button>

    <?php endif; ?>
</div>

<script>
    // Show current SQLite count
    fetch('?page=migrate&action=db_count')
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('dbCount');
            if (el) el.textContent = (d.count ?? 0).toLocaleString();
        })
        .catch(() => {});

    function runMigration() {
        const btn      = document.getElementById('migrateBtn');
        const progress = document.getElementById('progressWrap');
        const fill     = document.getElementById('progressFill');
        const label    = document.getElementById('progressLabel');

        btn.disabled       = true;
        btn.textContent    = 'Migrating…';
        progress.style.display = 'block';

        // Animate progress bar while waiting
        let pct = 0;
        const ticker = setInterval(() => {
            pct = Math.min(pct + Math.random() * 8, 90);
            fill.style.width  = pct + '%';
            label.textContent = `Processing… ${Math.round(pct)}%`;
        }, 200);

        const body = new FormData();
        body.append('action', 'run_migration');

        fetch('?page=migrate', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                clearInterval(ticker);
                fill.style.width  = '100%';
                label.textContent = 'Done!';

                if (data.ok) {
                    const ok   = document.getElementById('resultOk');
                    const list = document.getElementById('resultList');
                    ok.style.display = 'block';
                    list.innerHTML = `
                        <li><strong>${data.inserted.toLocaleString()}</strong> messages imported successfully</li>
                        <li><strong>${data.skipped}</strong> messages skipped (missing required fields)</li>
                        <li><strong>${data.readIds}</strong> read/unread states restored</li>
                    `;
                    btn.textContent = '✓ Done — Go to Inbox';
                    btn.disabled    = false;
                    btn.onclick     = () => window.location = '?';
                } else {
                    document.getElementById('resultErr').style.display = 'block';
                    document.getElementById('resultErrMsg').textContent = data.error ?? 'Unknown error.';
                    btn.disabled    = false;
                    btn.textContent = 'Retry Migration';
                    btn.onclick     = runMigration;
                }
            })
            .catch(err => {
                clearInterval(ticker);
                document.getElementById('resultErr').style.display = 'block';
                document.getElementById('resultErrMsg').textContent = err.toString();
                btn.disabled    = false;
                btn.textContent = 'Retry';
            });
    }
</script>
</body>
</html>
<?php exit;
