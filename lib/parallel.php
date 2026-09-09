<?php
/**
 * Talking to every router at the same time.
 *
 * The dashboard used to poll one router after another, asking each one six
 * separate questions. Every question is a full round trip - about 160 ms to
 * Bangladesh and back - so ten routers meant sixty trips in a queue. Measured
 * against ten routers at that distance: 24 seconds for one poll, and the
 * traffic figures were at the back of the queue. That is why adding routers
 * made the dashboard look broken.
 *
 * Two changes fix it, and neither needs a PHP extension or a background
 * process, because the host this runs on allows neither:
 *
 *   1. All routers are worked on together. The sockets are non-blocking and
 *      driven by stream_select, so waiting for ten routers costs the same as
 *      waiting for one.
 *   2. Each router is asked everything in ONE go. The RouterOS API lets you
 *      queue several commands and tag them, so six round trips per router
 *      become one.
 *
 * The result is that a poll takes about as long as the slowest single router,
 * whether there are two of them or twenty.
 *
 * Nothing here writes to a router. Only print commands are sent.
 */

require_once __DIR__ . '/routeros.php';

class MtConn {
    public $dev;                 // the devices row
    public $sock = null;
    public $err  = '';
    public $tcpMs = 0.0;
    public $rbuf = '';           // bytes read but not yet parsed
    public $wbuf = '';           // bytes to write
    public $t0   = 0.0;
    public $legacy = false;      // RouterOS < 6.43 wants the MD5 challenge
    public $sentences = [];      // parsed sentences for the current phase
    public $doneTags = [];       // which tagged commands have finished
    public $results = [];        // tag => rows
    public $finished = false;    // this phase is complete for this connection
    public $due = [];            // which slow-clock jobs this router is due for
    public $slowCmds = [];       // the second batch for this router
    public $slowResults = [];    // and its answers
    public $fastResults = [];    // the first batch, kept because mt_par_batch()
                                 // clears results when it starts the next one

    public function fail($msg) {
        $this->err = $msg;
        $this->close();
    }
    public function close() {
        if (is_resource($this->sock)) @fclose($this->sock);
        $this->sock = null;
    }
    public function alive() { return $this->err === '' && is_resource($this->sock); }
}

/* ------------------------------------------------------------------ framing */

function mt_par_word($w) {
    $len = strlen($w);
    if ($len < 0x80)          $p = chr($len);
    elseif ($len < 0x4000)    { $len |= 0x8000;     $p = chr(($len >> 8) & 0xFF) . chr($len & 0xFF); }
    elseif ($len < 0x200000)  { $len |= 0xC00000;   $p = chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF); }
    elseif ($len < 0x10000000){ $len |= 0xE0000000; $p = chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF); }
    else                      $p = chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    return $p . $w;
}

function mt_par_sentence(array $words) {
    $out = '';
    foreach ($words as $w) $out .= mt_par_word($w);
    return $out . chr(0);           // empty word ends the sentence
}

/**
 * Pull one complete sentence out of the buffer, or return null if not all of
 * it has arrived yet. The buffer is only consumed when a whole sentence is
 * there, so a half-received reply is simply waited for rather than mis-parsed.
 */
function mt_par_take_sentence(&$buf) {
    $pos = 0;
    $len = strlen($buf);

    $readLen = function () use (&$pos, $buf, $len) {
        if ($pos >= $len) return null;
        $c = ord($buf[$pos]);
        if (($c & 0x80) === 0x00) { $pos += 1; return $c; }
        if (($c & 0xC0) === 0x80) {
            if ($pos + 2 > $len) return null;
            $v = (($c & 0x3F) << 8) + ord($buf[$pos + 1]); $pos += 2; return $v;
        }
        if (($c & 0xE0) === 0xC0) {
            if ($pos + 3 > $len) return null;
            $v = (($c & 0x1F) << 16) + (ord($buf[$pos + 1]) << 8) + ord($buf[$pos + 2]); $pos += 3; return $v;
        }
        if (($c & 0xF0) === 0xE0) {
            if ($pos + 4 > $len) return null;
            $v = (($c & 0x0F) << 24) + (ord($buf[$pos + 1]) << 16) + (ord($buf[$pos + 2]) << 8) + ord($buf[$pos + 3]);
            $pos += 4; return $v;
        }
        if ($pos + 5 > $len) return null;
        $v = (ord($buf[$pos + 1]) << 24) + (ord($buf[$pos + 2]) << 16) + (ord($buf[$pos + 3]) << 8) + ord($buf[$pos + 4]);
        $pos += 5; return $v;
    };

    $words = [];
    while (true) {
        $before = $pos;
        $wlen = $readLen();
        if ($wlen === null) return null;                 // incomplete
        if ($wlen === 0) {                               // sentence terminator
            $buf = substr($buf, $pos);
            $type  = array_shift($words);
            $attrs = [];
            $tag   = '';
            foreach ($words as $w) {
                if ($w === '') continue;
                if ($w[0] === '=') {
                    $eq = strpos($w, '=', 1);
                    if ($eq === false) $attrs[substr($w, 1)] = '';
                    else               $attrs[substr($w, 1, $eq - 1)] = substr($w, $eq + 1);
                } elseif (strpos($w, '.tag=') === 0) {
                    $tag = substr($w, 5);
                }
            }
            return ['type' => (string)$type, 'attrs' => $attrs, 'tag' => $tag];
        }
        if ($pos + $wlen > $len) { $pos = $before; return null; }   // incomplete
        $words[] = substr($buf, $pos, $wlen);
        $pos += $wlen;
    }
}

/* ------------------------------------------------------------------- phases */

/**
 * Open every socket at once. Non-blocking connects are all started, then one
 * stream_select waits for whichever complete - so N routers cost one wait, not
 * N waits.
 */
function mt_par_open(array $devs, $timeout) {
    $conns = [];
    foreach ($devs as $d) {
        $c = new MtConn();
        $c->dev = $d;
        $c->t0  = microtime(true);

        // Resolve first and connect to the address: timing a name lookup as if
        // it were the router's latency is how a 40 ms DNS answer ends up being
        // reported as the router being slow.
        $host = $d['host'];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $r = gethostbyname($host);
            if ($r !== $host) $host = $r;
        }
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client(
            'tcp://' . $host . ':' . (int)$d['api_port'], $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if ($sock === false) {
            $c->fail($errstr !== '' ? $errstr : 'connection failed');
        } else {
            stream_set_blocking($sock, false);
            $c->sock = $sock;
        }
        $conns[] = $c;
    }

    $deadline = microtime(true) + $timeout;
    while (true) {
        $w = [];
        foreach ($conns as $i => $c) {
            if ($c->alive() && $c->tcpMs === 0.0) $w[$i] = $c->sock;
        }
        if (!$w) break;
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            foreach ($w as $i => $s) $conns[$i]->fail('connection timed out');
            break;
        }
        $r = $e = [];
        $wsel = $w;
        $n = @stream_select($r, $wsel, $e, (int)$left, (int)(($left - (int)$left) * 1000000));
        if ($n === false) {
            foreach ($w as $i => $s) $conns[$i]->fail('select failed');
            break;
        }
        if ($n === 0) continue;
        foreach ($wsel as $s) {
            $i = array_search($s, $w, true);
            if ($i === false) continue;
            $c = $conns[$i];
            // Writable can also mean "refused". Asking for the peer name is the
            // portable way to tell a finished connect from a failed one.
            if (@stream_socket_get_name($s, true) === false) {
                $c->fail('connection refused');
                continue;
            }
            $c->tcpMs = (microtime(true) - $c->t0) * 1000;
        }
    }
    return $conns;
}

/**
 * Write everything queued, then read until every connection has finished the
 * phase. One wait covers all of them.
 *
 * $isDone($conn, $sentence) decides when a connection has heard enough.
 */
function mt_par_pump(array $conns, $timeout, callable $isDone) {
    $deadline = microtime(true) + $timeout;
    while (true) {
        $r = $w = $e = [];
        foreach ($conns as $i => $c) {
            if (!$c->alive() || $c->finished) continue;
            if ($c->wbuf !== '') $w[$i] = $c->sock;
            else                 $r[$i] = $c->sock;
        }
        if (!$r && !$w) break;

        $left = $deadline - microtime(true);
        if ($left <= 0) {
            foreach ($conns as $c) {
                if ($c->alive() && !$c->finished) $c->fail('router did not answer in time');
            }
            break;
        }
        $rs = $r; $ws = $w; $es = [];
        $n = @stream_select($rs, $ws, $es, (int)$left, (int)(($left - (int)$left) * 1000000));
        if ($n === false) {
            foreach ($conns as $c) if ($c->alive() && !$c->finished) $c->fail('select failed');
            break;
        }
        if ($n === 0) continue;

        foreach ($ws as $s) {
            $i = array_search($s, $w, true);
            if ($i === false) continue;
            $c = $conns[$i];
            $sent = @fwrite($c->sock, $c->wbuf);
            if ($sent === false) { $c->fail('write failed'); continue; }
            $c->wbuf = substr($c->wbuf, $sent);
        }

        foreach ($rs as $s) {
            $i = array_search($s, $r, true);
            if ($i === false) continue;
            $c = $conns[$i];
            $chunk = @fread($c->sock, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($c->sock)) $c->fail('router closed the connection');
                continue;
            }
            $c->rbuf .= $chunk;
            while (($s2 = mt_par_take_sentence($c->rbuf)) !== null) {
                $c->sentences[] = $s2;
                if ($isDone($c, $s2)) { $c->finished = true; break; }
            }
        }
    }
    return $conns;
}

/** Log in to every router at once, including the old MD5 challenge firmware. */
function mt_par_login(array $conns, $timeout) {
    foreach ($conns as $c) {
        if (!$c->alive()) continue;
        $c->sentences = [];
        $c->finished  = false;
        $c->wbuf = mt_par_sentence(['/login',
            '=name=' . $c->dev['username'], '=password=' . (string)$c->dev['password']]);
    }
    mt_par_pump($conns, $timeout, function ($c, $s) {
        return $s['type'] === '!done' || $s['type'] === '!trap' || $s['type'] === '!fatal';
    });

    // RouterOS below 6.43 answers the plain login with a challenge instead.
    $legacy = [];
    foreach ($conns as $c) {
        if (!$c->alive()) continue;
        $ok = false; $trap = ''; $challenge = '';
        foreach ($c->sentences as $s) {
            if ($s['type'] === '!done' && isset($s['attrs']['ret'])) $challenge = $s['attrs']['ret'];
            if ($s['type'] === '!done') $ok = true;
            if ($s['type'] === '!trap') $trap = $s['attrs']['message'] ?? 'login refused';
        }
        if ($challenge !== '') { $legacy[] = $c; $c->legacy = true; continue; }
        if (!$ok || $trap !== '') $c->fail($trap !== '' ? $trap : 'login failed');
    }

    if ($legacy) {
        foreach ($legacy as $c) {
            $challenge = '';
            foreach ($c->sentences as $s) {
                if (isset($s['attrs']['ret'])) $challenge = $s['attrs']['ret'];
            }
            $bin = '';
            for ($i = 0; $i < strlen($challenge); $i += 2) $bin .= chr(hexdec(substr($challenge, $i, 2)));
            $hash = md5(chr(0) . (string)$c->dev['password'] . $bin, true);
            $c->sentences = [];
            $c->finished  = false;
            $c->wbuf = mt_par_sentence(['/login', '=name=' . $c->dev['username'],
                                        '=response=00' . bin2hex($hash)]);
        }
        mt_par_pump($legacy, $timeout, function ($c, $s) {
            return $s['type'] === '!done' || $s['type'] === '!trap' || $s['type'] === '!fatal';
        });
        foreach ($legacy as $c) {
            if (!$c->alive()) continue;
            foreach ($c->sentences as $s) {
                if ($s['type'] === '!trap') $c->fail($s['attrs']['message'] ?? 'login refused');
            }
        }
    }
    return $conns;
}

/**
 * Send the whole list of commands to every router at once and collect the
 * answers. The commands are tagged, so the router may answer them in any order
 * and each reply still lands under the right one.
 *
 * $commands is [tag => [command, ...args]]. Results end up in $c->results[tag].
 */
function mt_par_batch(array $conns, array $commands, $timeout) {
    $tags = array_keys($commands);
    foreach ($conns as $c) {
        if (!$c->alive()) continue;
        $c->sentences = [];
        $c->doneTags  = [];
        $c->results   = [];
        $c->finished  = false;
        $out = '';
        foreach ($commands as $tag => $words) {
            $line = array_merge($words, ['.tag=' . $tag]);
            $out .= mt_par_sentence($line);
        }
        $c->wbuf = $out;
    }

    $total = count($tags);
    mt_par_pump($conns, $timeout, function ($c, $s) use ($total) {
        $tag = $s['tag'];
        if ($s['type'] === '!re') {
            if ($tag !== '') $c->results[$tag][] = $s['attrs'];
            return false;
        }
        // A !trap is NOT the end of a reply - the router still sends its !done
        // afterwards. Counting the trap as the terminator leaves that !done in
        // the socket and every later reply is read one place out of step.
        if ($s['type'] === '!done') {
            if ($tag !== '') {
                $c->doneTags[$tag] = true;
                // "print count-only" answers with the number in =ret= and no rows.
                if (isset($s['attrs']['ret']) && empty($c->results[$tag])) {
                    $c->results[$tag] = [['ret' => $s['attrs']['ret']]];
                }
            }
            return count($c->doneTags) >= $total;
        }
        if ($s['type'] === '!fatal') { $c->fail('router closed the connection'); return true; }
        return false;
    });
    return $conns;
}

/** Close every socket. */
function mt_par_close(array $conns) {
    foreach ($conns as $c) $c->close();
}
