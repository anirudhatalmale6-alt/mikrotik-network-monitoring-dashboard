<?php
/**
 * Background poller.
 *
 *   php poller.php --once      one round over every enabled device (cron)
 *   php poller.php --loop      keep polling forever (systemd service)
 *   php poller.php --once -v   print what each device answered
 *
 * The dashboard works without this - api.php starts a poll itself when the data
 * goes stale - but running it as a service means no page view ever waits for a
 * router, and the graph keeps filling in while nobody is looking at the screen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("poller.php is a command line script.\n");
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/poll.php';

$opts    = $argv ?? [];
$verbose = in_array('-v', $opts, true) || in_array('--verbose', $opts, true);
$loop    = in_array('--loop', $opts, true);

function mt_say($msg) { fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $msg . "\n"); }

$db = mt_db();

if (!$loop) {
    $lock = mt_lock(15);
    if ($lock === false) { if ($verbose) echo "another poll is already running\n"; exit(0); }
    if ($lock === null)  mt_say('WARNING: could not open the lock file - running without a lock');
    try { $n = mt_poll_all($db, $verbose); } finally { mt_unlock($lock); }
    if ($verbose) echo "polled $n device(s)\n";
    exit(0);
}

while (true) {
    $started = microtime(true);
    $lock = null;
    mt_settings_reset();   // pick up a poll interval changed from the dashboard
    try {
        $lock = mt_lock(5);
        if ($lock === false)      mt_say('skipped a round: another poll held the lock');
        else                      mt_poll_all($db, $verbose);
        if ($lock === null)       mt_say('WARNING: could not open the lock file - running without a lock');
    } catch (Throwable $e) {
        mt_say('poll failed: ' . $e->getMessage());
    } finally {
        mt_unlock($lock);
    }
    $poll  = max(5, (int)mt_setting('poll_seconds', 10));
    $sleep = $poll - (microtime(true) - $started);
    if ($sleep > 0) usleep((int)($sleep * 1000000));
}
