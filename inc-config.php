<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - configuration
// Constants where PHP 5.4 allows them. Arrays are UPPERCASE variables because
// array constants need PHP 5.6 (const) / 7.0 (define).
// ---------------------------------------------------------------------------

// Local file storage (must be writable by the web server, keep it out of the web root)
define('DATA_DIR', __DIR__ . '/data/');

// ZOSCII MQ (messages and history). Reached over HTTP only, so it can be on
// another machine; it does need the same clock, since message names are times.
define('MQ_URL', 'http://localhost/zosciimq/indexmq.php');   // MQ's index.php
define('MQ_QUEUE_PREFIX', 'cc');                           // keeps chat queues apart from radio etc.
define('MQ_CHANNEL_RETENTION_DAYS', 30);                   // channel history kept this long
define('MQ_PERSON_RETENTION_DAYS', 1);                     // direct events to a person
define('MQ_TIMEOUT', 5);                                   // seconds for one MQ call

// File locks (lock files created with fopen 'x')
define('LOCK_WAIT_US', 10000);              // microseconds between attempts
define('LOCK_MAX_WAIT_US', 3000000);        // give up after this long
define('LOCK_STALE', 5);                    // seconds before a leftover lock is treated as dead

// Server identity
define('SERVER_NAME', 'irc.cyborgunicorn.com.au');
define('SERVER_VERSION', 'cyborgscif-0.2');
define('SERVER_CREATED', '2026-09-18');
define('NETWORK_NAME', 'CyborgNet');
define('USER_HOST', 'cyborgscif');               // host part of nick!user@host

// Device login
define('DEVICE_COOKIE', 'cyborgscif_guid');      // cookie read when no guid parameter is sent

// Polling
define('PING_TIMEOUT', 120);                // seconds with no call before a device is dropped
define('REAP_INTERVAL', 10);                // seconds between checks for timed out devices
define('POLL_INTERVAL', 2);                 // seconds, handed to the client at LOGIN
define('ESB_FETCH_MAX', 100);               // max messages per FETCH (one MQ call each)

// Limits
define('NICK_MAX_LEN', 30);
define('USERNAME_MAX_LEN', 20);
define('REALNAME_MAX_LEN', 50);
define('CHANNEL_MAX_LEN', 50);
define('TOPIC_MAX_LEN', 390);
define('TEXT_MAX_LEN', 400);
define('AWAY_MAX_LEN', 200);
define('KEY_MAX_LEN', 50);
define('MAX_CHANNELS_PER_PERSON', 20);
define('NAMES_PER_ROW', 50);

// History paging (history is the channel's ZOSCII MQ queue)
define('HISTORY_PAGE_SIZE', 50);            // rows per HISTORY call when count not given
define('HISTORY_PAGE_MAX', 200);            // most rows a client can ask for

// Channel defaults
define('DEFAULT_CHANNEL_MODES', 'nt');

// Result codes
define('RESULT_SUCCESS', 0);
define('RESULT_ERROR', 1);

// Arrays
$RESERVED_NICKS = ['server', 'admin', 'nickserv', 'chanserv'];

// PING sources: name reported by PING => function($arrDevice, $arrPerson) that
// returns true while that subsystem has something waiting for the device.
// PING answers with the names of every source that returns true.
$PING_SOURCES = [
    'messages' => 'esb_HasPending'
];

$MOTD_LINES = [
    'Welcome to ' . NETWORK_NAME . '.',
    'IRC over webservices.'
];
