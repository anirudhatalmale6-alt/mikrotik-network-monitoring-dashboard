<?php
/**
 * Minimal RouterOS API client in pure PHP.
 *
 * No composer, no extensions beyond sockets - so the whole monitor can be dropped
 * onto any PHP host. It speaks the same binary API as WinBox's API service
 * (/ip service api, default port 8728).
 *
 * Protocol in one paragraph: everything is a "word" written as a length prefix
 * followed by raw bytes; a group of words terminated by a zero-length word is a
 * "sentence". You send a command sentence, then read reply sentences until one
 * starts with !done. !re carries a data row, !trap carries an error message,
 * !fatal means the router is closing the connection.
 *
 * This client only ever READS from the router. It sends no /set, /add or /remove.
 */

class RouterOsException extends Exception {}

class RouterOs
{
    /** @var resource|null */
    private $sock = null;
    private $timeout;

    public function __construct($timeout = 6) {
        $this->timeout = max(2, (int)$timeout);
    }

    /**
     * Connect and log in. Returns the seconds the TCP handshake took, which is a
     * useful latency figure on hosts where ICMP ping is not permitted.
     * @throws RouterOsException
     */
    public function connect($host, $port, $user, $pass) {
        // Resolve BEFORE starting the clock, and connect to the address rather than
        // the name. Timing fsockopen($host) measures name resolution as well as the
        // round trip - measured at 40-65 ms of the total on this network - and that
        // is not latency to the router.
        $ip = $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $resolved = gethostbyname($host);
            // gethostbyname hands back the input unchanged when it cannot resolve.
            if ($resolved !== $host) $ip = $resolved;
        }

        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        // @ suppressed: a refused or filtered port is an expected outcome here and
        // the message is reported through the exception, not a PHP warning.
        $this->sock = @fsockopen($ip, (int)$port, $errno, $errstr, $this->timeout);
        if (!$this->sock) {
            throw new RouterOsException($errstr !== '' ? $errstr : 'connection failed', (int)$errno);
        }
        $tcpMs = (microtime(true) - $t0) * 1000;
        stream_set_timeout($this->sock, $this->timeout);
        $this->login($user, (string)$pass);
        return $tcpMs;
    }

    private function login($user, $pass) {
        // RouterOS 6.43 and newer: plain login. Older firmware answers this with a
        // challenge in =ret= instead, and then wants the MD5 response below.
        $reply = $this->talk(['/login', '=name=' . $user, '=password=' . $pass]);

        foreach ($reply as $s) {
            if ($s['type'] === '!trap') {
                throw new RouterOsException('login rejected: ' . ($s['attrs']['message'] ?? 'unknown'));
            }
        }
        $challenge = null;
        foreach ($reply as $s) {
            if (isset($s['attrs']['ret'])) $challenge = $s['attrs']['ret'];
        }
        if ($challenge === null) return true;   // new-style login already succeeded

        // Legacy challenge-response (RouterOS < 6.43).
        $bin = '';
        for ($i = 0; $i < strlen($challenge); $i += 2) {
            $bin .= chr(hexdec(substr($challenge, $i, 2)));
        }
        $md5 = md5(chr(0) . $pass . $bin, true);
        $reply = $this->talk(['/login', '=name=' . $user, '=response=00' . bin2hex($md5)]);
        foreach ($reply as $s) {
            if ($s['type'] === '!trap') {
                throw new RouterOsException('login rejected: ' . ($s['attrs']['message'] ?? 'unknown'));
            }
        }
        return true;
    }

    public function close() {
        if ($this->sock) { @fclose($this->sock); $this->sock = null; }
    }

    /**
     * Run a command and return only its data rows (!re sentences), as plain arrays.
     * @throws RouterOsException on !trap or !fatal, so a caller can never mistake an
     *         error for "the router has nothing to report".
     */
    public function query($command, array $args = []) {
        $words = array_merge([$command], $args);
        $rows = [];
        foreach ($this->talk($words) as $s) {
            if ($s['type'] === '!re')    $rows[] = $s['attrs'];
            if ($s['type'] === '!trap')  throw new RouterOsException($command . ': ' . ($s['attrs']['message'] ?? 'error'));
            if ($s['type'] === '!fatal') throw new RouterOsException($command . ': connection closed by router');
        }
        return $rows;
    }

    /** Send one sentence, read sentences until !done. */
    private function talk(array $words) {
        foreach ($words as $w) $this->writeWord($w);
        $this->writeWord('');            // empty word terminates the sentence

        $out = [];
        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === null) throw new RouterOsException('connection lost while reading reply');
            $out[] = $sentence;
            if ($sentence['type'] === '!done' || $sentence['type'] === '!fatal') break;
        }
        return $out;
    }

    private function readSentence() {
        $type = $this->readWord();
        if ($type === null) return null;
        $attrs = [];
        while (true) {
            $w = $this->readWord();
            if ($w === null) return null;
            if ($w === '') break;                    // end of sentence
            if ($w[0] === '=') {
                // "=key=value"; the value may itself contain '=' so split on the
                // FIRST separator only.
                $pos = strpos($w, '=', 1);
                if ($pos === false) { $attrs[substr($w, 1)] = ''; }
                else { $attrs[substr($w, 1, $pos - 1)] = substr($w, $pos + 1); }
            }
        }
        return ['type' => $type, 'attrs' => $attrs];
    }

    private function writeWord($word) {
        $this->writeLength(strlen($word));
        if ($word !== '') $this->writeRaw($word);
    }

    private function readWord() {
        $len = $this->readLength();
        if ($len === null) return null;
        if ($len === 0) return '';
        return $this->readRaw($len);
    }

    /** RouterOS length encoding: 1 to 5 bytes depending on magnitude. */
    private function writeLength($len) {
        if ($len < 0x80) {
            $this->writeRaw(chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $this->writeRaw(chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            $this->writeRaw(chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            $this->writeRaw(chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF)
                          . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            $this->writeRaw(chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF)
                          . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
    }

    private function readLength() {
        $b = $this->readRaw(1);
        if ($b === null) return null;
        $c = ord($b);
        if (($c & 0x80) === 0x00) return $c;
        if (($c & 0xC0) === 0x80) return (($c & ~0xC0) << 8)  + ord($this->readRaw(1));
        if (($c & 0xE0) === 0xC0) { $c = ($c & ~0xE0) << 16; $c += ord($this->readRaw(1)) << 8;  $c += ord($this->readRaw(1)); return $c; }
        if (($c & 0xF0) === 0xE0) { $c = ($c & ~0xF0) << 24; $c += ord($this->readRaw(1)) << 16; $c += ord($this->readRaw(1)) << 8; $c += ord($this->readRaw(1)); return $c; }
        if (($c & 0xF8) === 0xF0) { $c = ord($this->readRaw(1)) << 24; $c += ord($this->readRaw(1)) << 16; $c += ord($this->readRaw(1)) << 8; $c += ord($this->readRaw(1)); return $c; }
        throw new RouterOsException('bad length byte from router');
    }

    private function writeRaw($data) {
        $left = strlen($data);
        while ($left > 0) {
            $n = @fwrite($this->sock, $data);
            if ($n === false || $n === 0) throw new RouterOsException('write failed');
            $data = substr($data, $n);
            $left -= $n;
        }
    }

    /** fread can return short reads on a socket, so keep going until satisfied. */
    private function readRaw($len) {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = @fread($this->sock, $len - strlen($buf));
            $meta = stream_get_meta_data($this->sock);
            if (!empty($meta['timed_out'])) throw new RouterOsException('read timed out');
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) return null;
                throw new RouterOsException('read failed');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
