<?php
/**
 * What is connected behind a MikroTik.
 *
 * The router already knows. Three of its own tables, read only:
 *
 *   /ip/neighbor            MNDP + CDP + LLDP - the other NETWORK devices
 *                           announce themselves here, with their identity,
 *                           platform, model and firmware. This is where a
 *                           Ubiquiti radio, a Cisco switch or a TP-Link AP
 *                           tells the router what it is.
 *   /ip/arp                 every address the router has actually talked to
 *   /ip/dhcp-server/lease   the hostname the client asked to be called
 *
 * Merged on the MAC address, which is the only key all three share. Nothing is
 * guessed: a field that no table supplied stays empty rather than being filled
 * with a plausible value.
 */

require_once __DIR__ . '/db.php';

/** "DC-9F-DB-11-22-33", "dc9fdb112233" -> "DC:9F:DB:11:22:33". '' if not a MAC. */
function mt_mac_norm($mac) {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$mac));
    if (strlen($hex) !== 12) return '';
    return implode(':', str_split($hex, 2));
}

/**
 * Vendor from the first three bytes, against the IEEE registry in lib/oui.txt.
 *
 * The file is ~40k lines, so it is streamed once for the whole batch and stops
 * as soon as every prefix asked for has been found - not opened per device.
 */
function mt_oui_vendors(array $macs) {
    $want = [];
    foreach ($macs as $m) {
        $p = str_replace(':', '', substr(mt_mac_norm($m), 0, 8));
        if (strlen($p) === 6) $want[$p] = '';
    }
    if (!$want) return [];

    $fh = @fopen(__DIR__ . '/oui.txt', 'r');
    if (!$fh) return $want;
    $left = count($want);
    while ($left > 0 && ($line = fgets($fh)) !== false) {
        $p = substr($line, 0, 6);
        if (isset($want[$p]) && $want[$p] === '') {
            $want[$p] = trim(substr($line, 7));
            $left--;
        }
    }
    fclose($fh);

    foreach ($want as $p => $v) {
        // MikroTik's own registrations are filed under the board brand.
        if (stripos($v, 'routerboard') !== false) $want[$p] = 'MikroTik';
    }
    return $want;
}

/**
 * A MAC with the "locally administered" bit set was made up by the client, not
 * assigned by IEEE - every modern phone does this by default. Looking it up
 * would return nothing and read as "unknown device"; it is worth naming.
 */
function mt_mac_is_random($mac) {
    $mac = mt_mac_norm($mac);
    if ($mac === '') return false;
    return (hexdec(substr($mac, 0, 2)) & 0x02) !== 0;
}

/** Which family a device belongs to, from whatever the router told us about it. */
function mt_device_kind($vendor, $platform, $board) {
    $s = strtolower($vendor . ' ' . $platform . ' ' . $board);
    if (strpos($s, 'mikrotik') !== false || strpos($s, 'routerboard') !== false) return 'MikroTik';
    if (strpos($s, 'ubiquiti') !== false || strpos($s, 'ubnt') !== false
        || strpos($s, 'airmax') !== false || strpos($s, 'unifi') !== false)     return 'Ubiquiti';
    if (strpos($s, 'cisco') !== false)                                          return 'Cisco';
    if (strpos($s, 'tp-link') !== false || strpos($s, 'tplink') !== false
        || strpos($s, 'omada') !== false)                                       return 'TP-Link';
    if (strpos($s, 'huawei') !== false)                                         return 'Huawei';
    if (strpos($s, 'zte') !== false)                                            return 'ZTE';
    return '';
}

/**
 * Read one router's tables and store what is behind it.
 *
 * Returns [total, infrastructure, error]. A table the router refuses (no
 * DHCP server configured, API user without the right policy) is not a failure
 * of the whole scan - the other two still count, and the reason is reported.
 */
function mt_discover(RouterOs $ros, PDO $db, $deviceId) {
    $found = [];   // MAC => row
    $errs  = [];

    $row = function ($mac) use (&$found) {
        $mac = mt_mac_norm($mac);
        if ($mac === '') return null;
        if (!isset($found[$mac])) {
            $found[$mac] = ['mac' => $mac, 'ip' => '', 'hostname' => '', 'identity' => '',
                            'platform' => '', 'board' => '', 'version' => '', 'iface' => '',
                            'comment' => '', 'sources' => [], 'is_infra' => 0];
        }
        return $mac;
    };
    // Only fills a field that is still empty: the neighbour table is read first
    // and is the most specific, so a later ARP entry never overwrites it.
    $fill = function ($mac, $key, $value) use (&$found) {
        $value = trim((string)$value);
        if ($value === '' || $value === '0.0.0.0') return;
        if ($found[$mac][$key] === '') $found[$mac][$key] = $value;
    };

    // 1. The devices that announce themselves: MNDP, CDP and LLDP.
    try {
        foreach ($ros->query('/ip/neighbor/print') as $n) {
            $mac = $row($n['mac-address'] ?? '');
            if ($mac === null) continue;
            $found[$mac]['is_infra'] = 1;
            $found[$mac]['sources']['neighbor'] = 1;
            $fill($mac, 'ip', $n['address'] ?? '');
            $fill($mac, 'identity', $n['identity'] ?? '');
            $fill($mac, 'platform', $n['platform'] ?? '');
            $fill($mac, 'board', $n['board'] ?? ($n['board-name'] ?? ''));
            $fill($mac, 'version', $n['version'] ?? '');
            $fill($mac, 'iface', $n['interface'] ?? '');
            // LLDP switches often send only this, and it names the model.
            if ($found[$mac]['platform'] === '') $fill($mac, 'platform', $n['system-description'] ?? '');
        }
    } catch (Exception $e) { $errs[] = 'neighbours: ' . $e->getMessage(); }

    // 2. Everything the router has an address for.
    try {
        foreach ($ros->query('/ip/arp/print') as $a) {
            if (($a['invalid'] ?? 'false') === 'true') continue;
            $mac = $row($a['mac-address'] ?? '');
            if ($mac === null) continue;
            $found[$mac]['sources']['arp'] = 1;
            $fill($mac, 'ip', $a['address'] ?? '');
            $fill($mac, 'iface', $a['interface'] ?? '');
            $fill($mac, 'comment', $a['comment'] ?? '');
        }
    } catch (Exception $e) { $errs[] = 'ARP: ' . $e->getMessage(); }

    // 3. The name the client asked for. A router with no DHCP server is normal.
    try {
        foreach ($ros->query('/ip/dhcp-server/lease/print') as $l) {
            $mac = $row($l['mac-address'] ?? ($l['active-mac-address'] ?? ''));
            if ($mac === null) continue;
            $found[$mac]['sources']['dhcp'] = 1;
            $fill($mac, 'ip', $l['active-address'] ?? ($l['address'] ?? ''));
            $fill($mac, 'hostname', $l['host-name'] ?? '');
            $fill($mac, 'comment', $l['comment'] ?? '');
        }
    } catch (Exception $e) { $errs[] = 'DHCP leases: ' . $e->getMessage(); }

    if (!$found && $errs) {
        return [0, 0, implode('; ', $errs)];
    }

    $vendors = mt_oui_vendors(array_keys($found));
    $now     = date('Y-m-d H:i:s');
    $infra   = 0;

    $sel = $db->prepare("SELECT id FROM lan_devices WHERE device_id=? AND mac=?");
    $upd = $db->prepare("UPDATE lan_devices SET ip=?, hostname=?, vendor=?, identity=?, platform=?,
                            board=?, version=?, iface=?, comment=?, kind=?, sources=?, is_infra=?,
                            online=1, last_seen=? WHERE id=?");
    $ins = $db->prepare("INSERT INTO lan_devices
                            (device_id, mac, ip, hostname, vendor, identity, platform, board, version,
                             iface, comment, kind, sources, is_infra, online, first_seen, last_seen)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)");

    if (!mt_begin_write($db)) {
        return [0, 0, 'the database was busy - another scan or poll was writing. Try again.'];
    }
    try {
        foreach ($found as $mac => $f) {
            $prefix = str_replace(':', '', substr($mac, 0, 8));
            $vendor = $vendors[$prefix] ?? '';
            if ($vendor === '' && mt_mac_is_random($mac)) $vendor = 'Randomised address';
            $kind = mt_device_kind($vendor, $f['platform'], $f['board']);
            if ($f['is_infra']) $infra++;

            $sources = implode(',', array_keys($f['sources']));
            $sel->execute([$deviceId, $mac]);
            $id = $sel->fetchColumn();
            if ($id) {
                $upd->execute([$f['ip'], $f['hostname'], $vendor, $f['identity'], $f['platform'],
                               $f['board'], $f['version'], $f['iface'], $f['comment'], $kind,
                               $sources, $f['is_infra'], $now, $id]);
            } else {
                $ins->execute([$deviceId, $mac, $f['ip'], $f['hostname'], $vendor, $f['identity'],
                               $f['platform'], $f['board'], $f['version'], $f['iface'], $f['comment'],
                               $kind, $sources, $f['is_infra'], $now, $now]);
            }
        }
        // Anything not in this pass is gone for now. The row stays - "was here an
        // hour ago" is the useful answer - but it stops counting as connected.
        $db->prepare("UPDATE lan_devices SET online=0 WHERE device_id=? AND last_seen<>?")
           ->execute([$deviceId, $now]);
        $db->exec('COMMIT');
    } catch (Exception $e) {
        try { $db->exec('ROLLBACK'); } catch (Exception $e2) { /* nothing to roll back */ }
        return [0, 0, $e->getMessage()];
    }

    // Forget what has not been seen for a while, so a router that has served
    // thousands of one-off clients does not grow without limit.
    $keep = max(1, (int)mt_setting('discover_keep_days', 7));
    mt_db_write($db, "DELETE FROM lan_devices WHERE device_id=? AND online=0 AND last_seen < ?",
                [$deviceId, date('Y-m-d H:i:s', time() - $keep * 86400)]);

    return [count($found), $infra, implode('; ', $errs)];
}
