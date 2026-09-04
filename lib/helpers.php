<?php
/** Small shared formatting helpers. */

function mt_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Link speeds are quoted decimal, the way an ISP does: 1 Mbps = 1,000,000 bits. */
function mt_bps($bps) {
    $bps = (float)$bps;
    if ($bps >= 1e9) return round($bps / 1e9, 2) . ' Gbps';
    if ($bps >= 1e6) return round($bps / 1e6, 2) . ' Mbps';
    if ($bps >= 1e3) return round($bps / 1e3, 1) . ' kbps';
    return (int)$bps . ' bps';
}

/** Volumes are quoted binary, the way a disk or an invoice does. */
function mt_bytes($b) {
    $b = (float)$b;
    if ($b >= 1099511627776) return round($b / 1099511627776, 2) . ' TB';
    if ($b >= 1073741824)    return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)       return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)          return round($b / 1024, 1) . ' KB';
    return (int)$b . ' B';
}

function mt_ago($ts) {
    if (!$ts) return 'never';
    $s = time() - strtotime($ts);
    if ($s < 0)     return 'just now';
    if ($s < 60)    return $s . 's ago';
    if ($s < 3600)  return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    return floor($s / 86400) . 'd ago';
}
