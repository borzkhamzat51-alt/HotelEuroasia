<?php
/**
 * logout.php
 * Destroys the local PHP session. No external service to revoke
 * anything with — MySQL doesn't issue tokens, so there's nothing to
 * call out to.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Log before destroying the session (we need session data for the log)
if (bb_is_logged_in()) {
    db_audit_log('auth.logout', 'user', $_SESSION['user_id'] ?? null, $_SESSION['username'] ?? null);
}

$_SESSION = [];
session_destroy();

bb_redirect('index.php');
