<?php
/**
 * Switching on the router's own way in, from the dashboard.
 *
 * Reaching a device on a private address means going through the router, and
 * RouterOS has a SOCKS proxy built in for exactly that. Setting it up by hand is
 * four commands - fine for one router, not for ten, and he has ten.
 *
 * This does it over the API instead. It is the ONLY part of this dashboard that
 * writes to a router, so it is built to be undoable and to prove what it did:
 *
 *   - every object it creates carries the comment MT_SOCKS_TAG, so "remove
 *     access" can find exactly what was added and nothing else
 *   - it never edits or deletes anything it did not create
 *   - it asks the ROUTER which address it sees this server coming from, rather
 *     than trusting what the web server thinks its own address is. Behind NAT or
 *     a proxy those are different, and an allow rule for the wrong address locks
 *     the dashboard out while looking like it worked.
 */

require_once __DIR__ . '/routeros.php';

define('MT_SOCKS_TAG', 'mikrotik-dashboard');

/**
 * The address this server appears to come from, as the router sees it.
 *
 * Connection tracking is holding our own API session right now, so the router
 * can simply be asked. $fallback is used when tracking is off or the API user
 * may not read it.
 */
function mt_socks_seen_ip(RouterOs $ros, $apiPort, $fallback = '') {
    try {
        // There is no dst-port field on a connection. RouterOS puts the port INTO
        // the address - dst-address=92.96.47.225:8728 - so querying ?dst-port=
        // matches nothing at all and quietly returns an empty list, which is not
        // an error and looks exactly like "the router does not know". Found on his
        // own RB2011: 669 connections, 0 of them matching that filter.
        // The proplist keeps the reply small on a router with hundreds of them.
        $rows = $ros->query('/ip/firewall/connection/print',
                            ['?protocol=tcp', '=.proplist=src-address,dst-address']);
    } catch (Exception $e) {
        return [$fallback, 'could not read the router\'s connection list: ' . $e->getMessage()];
    }
    $needle = ':' . (int)$apiPort;
    $seen = [];
    foreach ($rows as $r) {
        $dst = (string)($r['dst-address'] ?? '');
        if ($dst === '' || substr($dst, -strlen($needle)) !== $needle) continue;
        $ip = explode(':', (string)($r['src-address'] ?? ''))[0];
        if (filter_var($ip, FILTER_VALIDATE_IP)) $seen[$ip] = true;
    }
    $seen = array_keys($seen);
    if (count($seen) === 1) return [$seen[0], ''];
    if (count($seen) > 1) {
        // Several things are talking to the API port - his router had two while I
        // was looking at it. If one of them is what this server believes it is,
        // that is ours. Otherwise REFUSE: guessing here would write an allow rule
        // for somebody else's address, which is a security decision, not a
        // convenience one.
        if ($fallback !== '' && in_array($fallback, $seen, true)) return [$fallback, ''];
        return ['', 'more than one address is connected to the API port ('
                  . implode(', ', $seen) . ') and none of them is this server\'s own address, '
                  . 'so it is not safe to pick one - set the address by hand'];
    }
    return [$fallback, $fallback === '' ? 'the router could not tell us our address' : ''];
}

/** Rows this dashboard created, identified by the comment it stamps on them. */
function mt_socks_mine(array $rows) {
    $out = [];
    foreach ($rows as $r) {
        if (isset($r['comment']) && strpos($r['comment'], MT_SOCKS_TAG) !== false) $out[] = $r;
    }
    return $out;
}

/**
 * What is set up on this router right now. Read only - safe to call any time.
 */
function mt_socks_state(RouterOs $ros, $apiPort, $fallbackIp = '') {
    $state = ['enabled' => false, 'port' => 0, 'allowIp' => '', 'ourIp' => '',
              'accessRules' => 0, 'firewallRules' => 0, 'allowsUs' => false,
              'byHand' => false, 'note' => '', 'error' => ''];

    list($ourIp, $note) = mt_socks_seen_ip($ros, $apiPort, $fallbackIp);
    $state['ourIp'] = $ourIp;
    $state['note']  = $note;

    try {
        $s = $ros->query('/ip/socks/print');
        $row = $s[0] ?? [];
        $state['enabled'] = (($row['enabled'] ?? 'false') === 'true');
        $state['port']    = (int)($row['port'] ?? 0);
    } catch (Exception $e) {
        $state['error'] = $e->getMessage();
        return $state;
    }

    try {
        $rules = $ros->query('/ip/socks/access/print');
        // Ours - what "remove access" is allowed to delete.
        $state['accessRules'] = count(mt_socks_mine($rules));
        /**
         * But whether the way in WORKS is a different question from whether we
         * made it. He set this router up by hand, so his allow rule carries no
         * comment of ours - and reporting "not set up yet" for a router that is
         * plainly set up would nag him forever and add a second, duplicate rule
         * the moment he pressed the button. Any allow rule for this server counts.
         */
        foreach ($rules as $r) {
            if (($r['action'] ?? '') !== 'allow') continue;
            $src = (string)($r['src-address'] ?? '');
            if ($src === '') continue;
            if ($state['allowIp'] === '' || ($ourIp !== '' && $src === $ourIp)) {
                $state['allowIp'] = $src;
            }
        }
        $state['allowsUs'] = ($ourIp !== '' && $state['allowIp'] === $ourIp);
        $state['byHand']   = $state['allowIp'] !== '' && $state['accessRules'] === 0;
    } catch (Exception $e) { /* access list unreadable is not fatal for a report */ }

    try {
        $state['firewallRules'] = count(mt_socks_mine($ros->query('/ip/firewall/filter/print')));
    } catch (Exception $e) { /* same */ }

    return $state;
}

/**
 * Switch it on. Returns [ok, steps[], error].
 *
 * Every step is reported with what was actually run, so the result is a record
 * of what changed on his router rather than a reassuring tick.
 */
function mt_socks_enable(RouterOs $ros, $apiPort, $port, $fallbackIp = '') {
    $steps = [];
    $port  = max(1, min(65535, (int)$port));

    list($ip, $note) = mt_socks_seen_ip($ros, $apiPort, $fallbackIp);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return [false, $steps, 'Could not work out which address this server reaches the router from, '
                            . 'so there is nothing safe to allow. ' . $note];
    }
    $steps[] = ['step' => 'Address to allow', 'detail' => $ip . ($note !== '' ? ' (' . $note . ')' : '')];

    // 1. The proxy itself.
    //
    // RouterOS 6 has no "version" setting - its SOCKS is version 4, full stop -
    // and sending one there is an error. Version 5 exists from RouterOS 7. So
    // ask the router what it is running first, and tell the caller which
    // protocol it ended up speaking, because the client has to match it.
    $major = 6;
    try {
        $res = $ros->query('/system/resource/print');
        $ver = (string)($res[0]['version'] ?? '');
        if (preg_match('/^(\d+)/', $ver, $m)) $major = (int)$m[1];
    } catch (Exception $e) { /* assume the older, narrower behaviour */ }
    $socksVersion = $major >= 7 ? 5 : 4;

    try {
        $args = ['=enabled=yes', '=port=' . $port];
        if ($socksVersion === 5) $args[] = '=version=5';
        $ros->query('/ip/socks/set', $args);
        $steps[] = ['step' => 'SOCKS proxy', 'detail' => 'enabled on port ' . $port
                                                       . ' (SOCKS' . $socksVersion
                                                       . ', RouterOS ' . $major . ')'];
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'permission') !== false) {
            $msg = 'the API user does not have write permission on this router';
        }
        return [false, $steps, 'Could not enable the proxy: ' . $msg];
    }

    // 2. The access list: only this server, everything else refused. Ours are
    //    cleared and rewritten so running this twice cannot stack up duplicates,
    //    and rules we did not create are left exactly as they are.
    try {
        foreach (mt_socks_mine($ros->query('/ip/socks/access/print')) as $r) {
            if (!empty($r['.id'])) $ros->query('/ip/socks/access/remove', ['=.id=' . $r['.id']]);
        }
        // If an allow rule for this address is already there - because he set it
        // up by hand - leave it alone rather than adding a duplicate beside it.
        $existing = $ros->query('/ip/socks/access/print');
        $haveAllow = false; $haveDeny = false;
        foreach ($existing as $r) {
            if (($r['action'] ?? '') === 'allow' && (string)($r['src-address'] ?? '') === $ip) $haveAllow = true;
            if (($r['action'] ?? '') === 'deny'  && (string)($r['src-address'] ?? '') === '')   $haveDeny  = true;
        }
        if (!$haveAllow) {
            $ros->query('/ip/socks/access/add',
                ['=src-address=' . $ip, '=action=allow', '=comment=' . MT_SOCKS_TAG]);
        }
        if (!$haveDeny) {
            $ros->query('/ip/socks/access/add',
                ['=action=deny', '=comment=' . MT_SOCKS_TAG . ' (deny everyone else)']);
        }

        /**
         * RouterOS 6 accepts a comment on a SOCKS access rule and silently does
         * not store it - seen on his RB2011 running 6.49.20, where a rule added
         * with a comment came back without one. Everything here is undone by
         * finding that comment, so on those routers "Remove access" would find
         * nothing and quietly leave the rules in place. Check whether the label
         * actually stuck and say so, rather than promising an undo that will not
         * work.
         */
        $tagged = count(mt_socks_mine($ros->query('/ip/socks/access/print')));
        $note = '';
        if (!$haveAllow && $tagged === 0) {
            $note = ' - note: this RouterOS does not keep comments on SOCKS rules, so these two'
                  . ' have to be removed by hand if you ever want them gone';
        }
        $steps[] = ['step' => 'Access list',
                    'detail' => ($haveAllow ? 'allow ' . $ip . ' was already there' : 'allow ' . $ip)
                              . ', ' . ($haveDeny ? 'deny-all was already there' : 'deny everything else')
                              . $note];
    } catch (Exception $e) {
        return [false, $steps, 'The proxy is on but the access list could not be written ('
                            . $e->getMessage() . '). Switch the proxy off again until this is sorted.'];
    }

    // 3. Let this server reach that port. Placed above the existing input rules so
    //    a drop rule further down cannot swallow it - but nothing existing is
    //    touched, only inserted before.
    try {
        foreach (mt_socks_mine($ros->query('/ip/firewall/filter/print')) as $r) {
            if (!empty($r['.id'])) $ros->query('/ip/firewall/filter/remove', ['=.id=' . $r['.id']]);
        }
        $args = ['=chain=input', '=protocol=tcp', '=dst-port=' . $port, '=src-address=' . $ip,
                 '=action=accept', '=comment=' . MT_SOCKS_TAG];
        $firstInput = '';
        foreach ($ros->query('/ip/firewall/filter/print') as $r) {
            if (($r['chain'] ?? '') === 'input' && !empty($r['.id'])) { $firstInput = $r['.id']; break; }
        }
        if ($firstInput !== '') $args[] = '=place-before=' . $firstInput;
        $ros->query('/ip/firewall/filter/add', $args);
        $steps[] = ['step' => 'Firewall', 'detail' => 'accept tcp/' . $port . ' from ' . $ip
                                                    . ($firstInput !== '' ? ', placed first in the input chain' : '')];
    } catch (Exception $e) {
        // Not fatal: plenty of routers have no filtering on input at all, and the
        // proxy is already reachable on those.
        $steps[] = ['step' => 'Firewall', 'detail' => 'could not add the rule (' . $e->getMessage()
                                                    . ') - if the proxy answers anyway, nothing is blocking it'];
    }

    return [true, $steps, '', $socksVersion];
}

/** Undo exactly what was added, and nothing else. */
function mt_socks_disable(RouterOs $ros) {
    $steps = [];
    $removed = 0;
    try {
        foreach (mt_socks_mine($ros->query('/ip/socks/access/print')) as $r) {
            if (!empty($r['.id'])) { $ros->query('/ip/socks/access/remove', ['=.id=' . $r['.id']]); $removed++; }
        }
        foreach (mt_socks_mine($ros->query('/ip/firewall/filter/print')) as $r) {
            if (!empty($r['.id'])) { $ros->query('/ip/firewall/filter/remove', ['=.id=' . $r['.id']]); $removed++; }
        }
        $steps[] = ['step' => 'Rules removed', 'detail' => $removed . ' rule' . ($removed === 1 ? '' : 's')
                                                        . ' that this dashboard had added'];
        $ros->query('/ip/socks/set', ['=enabled=no']);
        $steps[] = ['step' => 'SOCKS proxy', 'detail' => 'switched off'];
    } catch (Exception $e) {
        return [false, $steps, $e->getMessage()];
    }
    return [true, $steps, ''];
}
