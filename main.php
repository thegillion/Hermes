<?php

declare(strict_types=1);

// main.php is called by UISP for scheduled/background tasks and plugin events.
// Hermes uses it to handle UISP webhook events for automated SMS notifications.

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/config.php';

// UISP fires plugin events as POST requests to main.php.
// Route them to the notifications handler.
require __DIR__ . '/includes/notifications.php';
