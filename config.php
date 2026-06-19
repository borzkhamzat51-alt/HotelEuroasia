<?php
/**
 * config.php
 * Shared bootstrap: MySQL (PDO) connection, session handling, and the
 * RBAC contract every other file relies on.
 *
 * SESSION CONTRACT (the only session keys any page should ever read):
 *   $_SESSION['logged_in']   bool
 *   $_SESSION['user_id']     int
 *   $_SESSION['username']    string
 *   $_SESSION['full_name']   string
 *   $_SESSION['role']        'admin' | 'user'
 */

// --- Session setup -----------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================================
 * Tiny .env loader (no Composer needed). Optional — if .env doesn't
 * exist, the hardcoded XAMPP defaults below are used as-is.
 * ========================================================================= */
function bb_load_env($path)
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $key = trim($parts[0]);
        $value = trim($parts[1], "\"' \t");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}
bb_load_env(__DIR__ . '/.env');

/* ============================================================================
 * Database connection
 * ========================================================================= */

// Stock XAMPP defaults — override any of these in .env if yours differ
// (e.g. you set a root password, or used a different DB name).
define('BB_DB_HOST', isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'localhost');
define('BB_DB_NAME', isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : 'bluebookers');
define('BB_DB_USER', isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : 'root');
define('BB_DB_PASS', isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : '');

/**
 * Lazy PDO singleton — every db_*() helper in db.php calls this instead
 * of touching $pdo directly, so there's exactly one connection per request
 * no matter how many files need it.
 */
function bb_db()
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . BB_DB_HOST . ';dbname=' . BB_DB_NAME . ';charset=utf8mb4',
                BB_DB_USER,
                BB_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Database connection failed. Make sure MySQL is running in XAMPP (XAMPP Control Panel -> Start next to MySQL) and that you\'ve imported schema.sql in phpMyAdmin to create the "bluebookers" database.');
        }
    }

    return $pdo;
}

/* ============================================================================
 * RBAC core
 * ========================================================================= */

define('BB_ROLE_ADMIN', 'admin');
define('BB_ROLE_STAFF', 'staff');

// Canonical permission keys, in the order they should appear in the
// checklist and the nav. Each maps to the page that permission unlocks.
define('BB_PERMISSIONS', ['dashboard', 'reports', 'rooms', 'reservations', 'guests', 'billing', 'settings']);

define('BB_PERMISSION_ROUTES', [
    'dashboard'    => '/admin/dashboard.php',
    'reports'      => '/admin/reports.php',
    'rooms'        => '/admin/property.php',
    'reservations' => '/admin/reservations.php',
    'guests'       => '/admin/guests.php',
    'billing'      => '/admin/billing.php',
    'settings'     => '/profile.php',
]);

function bb_is_logged_in()
{
    return !empty($_SESSION['logged_in']);
}

function bb_current_role()
{
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

function bb_is_admin()
{
    return bb_is_logged_in() && bb_current_role() === BB_ROLE_ADMIN;
}

function bb_is_staff()
{
    return bb_is_logged_in() && bb_current_role() === BB_ROLE_STAFF;
}

/**
 * Admins implicitly hold every permission. Staff hold only what's in
 * their comma-separated $_SESSION['permissions'], filtered against the
 * canonical list (so a stray/old value in the DB can't grant something
 * that no longer exists).
 */
function bb_user_permissions()
{
    if (bb_is_admin()) {
        return BB_PERMISSIONS;
    }
    if (!bb_is_staff() || empty($_SESSION['permissions'])) {
        return [];
    }
    $granted = array_map('trim', explode(',', $_SESSION['permissions']));
    return array_values(array_intersect(BB_PERMISSIONS, $granted));
}

function bb_has_permission($key)
{
    return bb_is_admin() || in_array($key, bb_user_permissions(), true);
}

/**
 * Where should this person land right after login, or if they bounce
 * off a page they're not allowed to see? For staff, this is their
 * *first* permitted section in canonical order — not always the
 * Dashboard, since a staff member might not even have that permission.
 */
function bb_role_home()
{
    if (bb_is_admin()) {
        return '/admin/dashboard.php';
    }
    if (bb_is_staff()) {
        foreach (BB_PERMISSIONS as $key) {
            if (bb_has_permission($key)) {
                return BB_PERMISSION_ROUTES[$key];
            }
        }
        return '/access-denied.php'; // logged in, but zero permissions assigned
    }
    return '/index.php';
}

/**
 * Root-absolute URL — works correctly no matter which folder depth the
 * current script lives in (unlike a relative "../" path).
 */
function bb_url($rootRelativePath)
{
    return '/' . ltrim($rootRelativePath, '/');
}

function bb_redirect($rootRelativePath)
{
    header('Location: ' . bb_url($rootRelativePath));
    exit;
}

function bb_require_login()
{
    if (!bb_is_logged_in()) {
        bb_redirect('index.php');
    }
}

function bb_require_role($role)
{
    if (!bb_is_logged_in()) {
        bb_redirect('index.php');
    }
    if (bb_current_role() !== $role) {
        bb_redirect('access-denied.php');
    }
}

/**
 * Hard admin-only gate — for pages no permission can ever unlock for
 * staff (creating/deleting accounts). Use bb_require_permission() for
 * everything else.
 */
function bb_require_admin()
{
    bb_require_role(BB_ROLE_ADMIN);
}

/**
 * The main route guard for permission-gated admin pages. Admins always
 * pass. Staff pass only if they hold this specific permission.
 */
function bb_require_permission($key)
{
    if (!bb_is_logged_in()) {
        bb_redirect('index.php');
    }
    if (!bb_has_permission($key)) {
        bb_redirect('access-denied.php');
    }
}
