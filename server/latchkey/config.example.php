<?php
/**
 * Latchkey settings. Copy to config.php in this same folder, which must be
 * OUTSIDE your web root, and keep it private (chmod 600).
 * Set PINs with setpin.php - never put a plain PIN in this file.
 */
return [
    // Your Home Assistant webhook. Any URL that reaches HA works; the webhook_id
    // must match the one in the "Latchkey - web button" blueprint.
    // NOTE: some shared hosts block outgoing connections to port 8123 - see README.
    'ha_webhook_url' => 'https://YOUR-HA-ADDRESS:8123/api/webhook/YOUR-LONG-RANDOM-WEBHOOK-ID',

    // Shared secret: long and random (e.g. `openssl rand -hex 32`). Must match
    // the blueprint input and secrets.yaml. Never sent to visitors' browsers.
    'secret' => 'REPLACE-WITH-A-LONG-RANDOM-SECRET',

    // One entry per public page. The key is the page name used in the page's
    // stub ($LATCHKEY_PAGE) and sent to HA as trigger.json.page.
    'pages' => [
        'neighbours' => [
            'pin_hash'    => 'set-with-setpin.php',
            'show_status' => true,      // adds "Has anyone been home?" (needs the presence push)
        ],
        'basic' => [
            'pin_hash'    => 'set-with-setpin.php',
            'show_status' => false,     // button only - never reveals presence
            // 'text' => ['title' => 'Side gate'],   // optional per-page text overrides
        ],
    ],

    // Text shown on every page (a page's 'text' entries override these).
    'text' => [
        'title'          => 'Front gate',
        'button'         => 'Open / close gate',
        'done'           => 'Done - the gate is moving.',
        'note'           => "One press opens or closes the gate. Pressing while it's moving stops it.",
        'check_button'   => 'Has anyone been home?',
        'status_recent'  => 'Someone has been home in the last 24 hours.',
        'status_away'    => 'Nobody has been home for over 24 hours.',
        'status_unknown' => 'Home status is unknown right now.',
    ],

    'max_fails'           => 5,     // wrong PINs within fail_window ...
    'fail_window'         => 900,   // ... seconds
    'lockout'             => 1800,  // then that page locks for this many seconds (and HA is told)
    'cooldown'            => 10,    // seconds between accepted presses (shared by all pages)
    'status_window_hours' => 24,    // "someone home in the last N hours"
    'status_stale_hours'  => 3,     // presence older than this shows as unknown
];
