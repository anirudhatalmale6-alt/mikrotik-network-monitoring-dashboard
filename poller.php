<?php
/**
 * Background poller.
 *
 *   php poller.php --once      one round over every enabled device (cron)
 *   php poller.php --loop      keep polling forever (systemd service)
 *   php poller.php --once -v   print what each device answered
 *
 *   php poller.php --bw                live bandwidth lane, forever (service)
 *   php poller.php --bw --seconds=120  ... or for two minutes, then exit
 *
 * The dashboard works without this - api.php starts a poll itself when the data
 * goes stale - but running it as a service means no page view ever waits for a
 * router, and the graph keeps filling in while nobody is looking at the screen.
 *
 * The two modes are meant to run side by side and deliberately do not share a
 * process: the bandwidth lane must answer every second, and a full poll of three
 * routers takes sixteen.
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

if (in_array('--bw', $opts, true)) {
    require_once __DIR__ . '/lib/bwlane.php';
    $seconds = 0;
    foreach ($opts as $o) {
        if (strpos($o, '--seconds=') === 0) $seconds = max(0, (int)substr($o, 10));
    }
    // As a service (--seconds=0) this waits its turn instead of giving up: a page
    // view may have started a short-lived lane of its own, and a supervised
    // process that exits because of that would just be restarted in a loop.
    $lock = mt_bw_lock();
    if ($lock === false && $seconds === 0) {
        mt_say('another bandwidth lane holds the lock - waiting for it to finish');
        while ($lock === false) { sleep(5); $lock = mt_bw_lock(); }
    }
    if ($lock === false) { if ($verbose) mt_say('the bandwidth lane is already running'); exit(0); }
    if ($lock === null)  mt_say('WARNING: could not open the lane lock - running without one');
    try { mt_bw_run($db, $seconds, $verbose); }
    catch (Throwable $e) { mt_say('bandwidth lane stopped: ' . $e->getMessage()); }
    finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    exit(0);
}

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
