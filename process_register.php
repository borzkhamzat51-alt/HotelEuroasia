<?php
/**
 * process_register.php
 * Creates a real `role='user'` account directly in MySQL. There is no
 * path here that can create an admin — admins are seeded in
 * schema.sql or created later from admin/users.php.
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

// --- Basic input handling -------------------------------------------------
$fullName = trim($_POST['full_name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$confirm  = (string) ($_POST['confirm_password'] ?? '');

$fieldErrors = [];

if ($fullName === '' || mb_strlen($fullName) < 2) {
    $fieldErrors['full_name'] = 'Please enter your full name.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fieldErrors['email'] = 'Enter a valid email address.';
}
if ($phone === '' || !preg_match('/^[0-9+()\-\s]{7,}$/', $phone)) {
    $fieldErrors['phone'] = 'Enter a valid phone number.';
}
if ($password === '' || strlen($password) < 8) {
    $fieldErrors['password'] = 'Use at least 8 characters.';
} elseif ($password !== $confirm) {
    $fieldErrors['password'] = 'Passwords do not match.';
}

if (!empty($fieldErrors)) {
    respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $fieldErrors]);
}

if (db_email_taken($email)) {
    respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => ['email' => 'That email is already registered.']]);
}

// Username: derive one from the email's local part, de-duped if it's taken.
// (The register form only collects full name / email / phone / password —
// if you'd rather ask for a username explicitly, add that field and skip this.)
$baseUsername = preg_replace('/[^a-z0-9_.]/', '', strtolower(strstr($email . '@', '@', true)));
$username = $baseUsername !== '' ? $baseUsername : 'guest';
$suffix = 0;
while (db_username_taken($suffix === 0 ? $username : $username . $suffix)) {
    $suffix++;
}
if ($suffix > 0) {
    $username .= $suffix;
}

try {
    db_create_user($username, $email, password_hash($password, PASSWORD_DEFAULT), $fullName);
} catch (PDOException $e) {
    error_log('User creation failed: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Could not create your account. Please try again.']);
}

respond([
    'success'  => true,
    'redirect' => 'index.php',
]);
