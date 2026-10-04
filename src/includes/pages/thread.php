<?php

declare(strict_types=1);

$c       = $activeThread['client'];
$label   = $c ? clientDisplayName($c) : $activePhone;
$cId     = $c ? (int) $c['id'] : null;
$lastDay = '';

?>
<div class="chat-hdr">
    <div>
        <div class="c-name"><?= htmlspecialchars($label, ENT_QUOTES) ?></div>
        <div class="c-phone"><?= htmlspecialchars($activePhone, ENT_QUOTES) ?></div>
    </div>
    <?php if ($cId): ?>
        <a class="c-link" href="/crm/client/<?= $cId ?>" target="_parent">View Client Profile →</a>
    <?php endif; ?>
</div>

<?php if ($hasOlderPages): ?>
    <div class="load-older">
        <a href="?phone=<?= urlencode($activePhone) ?>&page=<?= $pageNum + 1 ?>">⬆ Load older messages</a>
    </div>
<?php endif; ?>

<div class="messages" id="msgList">
    <?php foreach ($pagedMessages as $row):
        $msg     = rowToMsg($row);
        $dir     = $msg['direction'] === 'outbound' ? 'out' : 'in';
        $isAuto  = $msg['auto'] ?? false;
        $ts      = strtotime($msg['timestamp'] ?? 'now');
        $day     = date('l, F j Y', $ts);
        $timeStr = date('g:ia', $ts);
    ?>
        <?php if ($day !== $lastDay): $lastDay = $day; ?>
            <div class="day-divider"><?= htmlspecialchars($day, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <div class="bw <?= $dir ?>">
            <div class="bubble <?= $isAuto ? 'auto' : $dir ?>">
                <?= nl2br(htmlspecialchars($msg['body'], ENT_QUOTES)) ?>
            </div>
            <div class="b-time"><?= $timeStr ?><?= $isAuto ? ' · <span class="auto-tag">⚡ auto</span>' : '' ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="compose">
    <?php if (!empty($successMsg)): ?><div class="flash ok">✅ <?= htmlspecialchars($successMsg, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if (!empty($errorMsg)):   ?><div class="flash err">❌ <?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="POST" action="?action=send&phone=<?= urlencode($activePhone) ?>" class="compose-fields">
        <input type="hidden" name="toNumber" value="<?= htmlspecialchars($activePhone, ENT_QUOTES) ?>">
        <input type="hidden" name="clientId" value="<?= $cId ?? '' ?>">
        <div class="compose-row">
            <textarea name="message" id="replyMsg"
                      placeholder="Type a reply… (Ctrl+Enter to send)"
                      oninput="updateCount('replyMsg','replyCount')"
                      onkeydown="ctrlEnterSubmit(event)"
                      required></textarea>
            <button type="submit" class="send-btn" <?= $configMissing ? 'disabled' : '' ?>>Send</button>
        </div>
        <div class="char-count" id="replyCount">0 / 160</div>
    </form>
</div>
