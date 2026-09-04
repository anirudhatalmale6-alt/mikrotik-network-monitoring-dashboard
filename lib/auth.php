<?php
/**
 * Admin authentication.
 *
 * Real password verification against a bcrypt hash in SQLite. A wrong password is
 * refused - there is no "any password of four characters will do" shortcut, which
 * would make the login decorative rather than a control.
 */

require_once __DIR__ . '/db.php';

function mt_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('MTMONSESS');
        session_start();
    }
}

function mt_login($username, $password) {
    mt_session_start();
    $st = mt_db()->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
    $st->execute([(string)$username]);
    $u = $st->fetch();

    // Compare against a dummy hash when the user does not exist so that a wrong
    // username and a wrong password take the same time to answer.
    $hash = $u ? $u['password_hash'] : '$2y$10$usesomesillystringforsalt0000000000000000000000000000000';
    $ok = password_verify((string)$password, $hash);

    if (!$u || !$ok) {
        usleep(400000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['mt_admin_id']   = $u['id'];
    $_SESSION['mt_admin_user'] = $u['username'];
    return true;
}

function mt_logout() {
    mt_session_start();
    $_SESSION = [];
    session_destroy();
}

function mt_is_admin() {
    mt_session_start();
    return !empty($_SESSION['mt_admin_id']);
}

function mt_admin_name() {
    mt_session_start();
    return $_SESSION['mt_admin_user'] ?? '';
}

/** Stop here unless signed in. Used by every endpoint that changes something. */
function mt_require_admin() {
    if (!mt_is_admin()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Admin login required.']);
        exit;
    }
}

function mt_csrf() {
    mt_session_start();
    if (empty($_SESSION['mt_csrf'])) $_SESSION['mt_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['mt_csrf'];
}

function mt_csrf_ok($token) {
    mt_session_start();
    return !empty($_SESSION['mt_csrf']) && is_string($token) && hash_equals($_SESSION['mt_csrf'], $token);
}

function mt_change_password($username, $newPassword) {
    $st = mt_db()->prepare("UPDATE admins SET password_hash = ? WHERE username = ?");
    $st->execute([password_hash((string)$newPassword, PASSWORD_DEFAULT), (string)$username]);
    // The "still on the default password" banner comes down only when the password
    // is genuinely something else.
    if ((string)$newPassword !== 'admin123') mt_set_setting('default_password_in_use', '0');
    return $st->rowCount() > 0;
}
