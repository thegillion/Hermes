<?php declare(strict_types=1); ?>

<div class="chat-hdr">
    <div>
        <div class="c-name">New Message</div>
        <div class="c-phone">Select a client or enter a number manually</div>
    </div>
</div>

<div class="messages">
    <div class="empty-state">
        <div class="icon">✉️</div>
        <p>Compose your message below</p>
    </div>
</div>

<div class="compose">
    <?php if (!empty($errorMsg)): ?><div class="flash err">❌ <?= htmlspecialchars($errorMsg, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="POST" action="?action=send&new=1" class="compose-fields">
        <div class="compose-row">
            <select name="clientId" onchange="onClientPick(this)">
                <option value="">— Select a client (optional) —</option>
                <?php foreach ($clients as $c):
                    $cId    = (int) $c['id'];
                    $cName  = htmlspecialchars(clientDisplayName($c), ENT_QUOTES);
                    $cPhone = htmlspecialchars(clientFirstPhone($c), ENT_QUOTES);
                ?>
                    <option value="<?= $cId ?>" data-phone="<?= $cPhone ?>"><?= $cName ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="toNumber" id="toNumber" placeholder="+15551234567" required>
        </div>
        <div class="compose-row">
            <textarea name="message" id="newMsg"
                      placeholder="Type your message…"
                      oninput="updateCount('newMsg','newCount')"
                      onkeydown="ctrlEnterSubmit(event)"
                      required></textarea>
            <button type="submit" class="send-btn" <?= $configMissing ? 'disabled' : '' ?>>Send</button>
        </div>
        <div class="char-count" id="newCount">0 / 160</div>
    </form>
</div>
