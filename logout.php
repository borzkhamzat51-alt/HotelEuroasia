<?php
require_once __DIR__ . '/config.php';
session_start();

try {
    if (isset($_SESSION['user_id']) && function_exists('db_audit_log')) {
        db_audit_log('user.logout', 'user', $_SESSION['user_id'], $_SESSION['username'] ?? 'unknown', 'Logged out');
    }
} catch (Exception $e) {
    error_log('Logout audit failed: ' . $e->getMessage());
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: index.php');
exit;