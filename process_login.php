<?php
/**
 * process_login.php
 * One login form, two possible destinations. Role comes straight from
 * the `users` row in the database — never from the request.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

function respond(array $data): void
{
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

// --- CSRF check ----------------------------------------------------------
$token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    respond(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']);
}

$username = trim($_POST['username'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    respond(['success' => false, 'message' => 'Please fill in both fields.']);
}

$user = db_find_user_by_username($username);

// Same message whether the username doesn't exist or the password is
// wrong — never reveal which one it was.
if (!$user || !password_verify($password, $user['password_hash'])) {
    respond(['success' => false, 'message' => 'Invalid username or password.']);
}

// --- Success: start the session using the canonical contract ----------------
session_regenerate_id(true);
$_SESSION['logged_in']    = true;
$_SESSION['user_id']      = $user['id'];
$_SESSION['username']     = $user['username'];
$_SESSION['full_name']    = $user['full_name'];
$_SESSION['role']         = $user['role']; // 'admin' or 'staff' — from the DB, never the client
$_SESSION['permissions']  = $user['permissions']; // comma-separated, ignored entirely for admins

respond([
    'success'  => true,
    'redirect' => ltrim(bb_role_home(), '/'),
]);
