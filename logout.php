<?php
/**
 * logout.php
 * Destroys the local PHP session. No external service to revoke
 * anything with — MySQL doesn't issue tokens, so there's nothing to
 * call out to.
 */

require_once __DIR__ . '/config.php';

$_SESSION = [];
session_destroy();

bb_redirect('index.php');
