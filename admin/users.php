<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_admin();

$branches = [
    'annex'          => 'BB Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'ELTI Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];
$branchKey  = $_GET['branch'] ?? '';
$branchName = $branches[$branchKey] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    $token = $_POST['csrf_token'] ?? '';
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        if ($deleteId === (int) $_SESSION['user_id']) {
            $_SESSION['users_flash'] = "You can't delete your own account while logged in as it.";
        } else {
            $targetUser = db_find_user_by_id($deleteId);
            db_delete_user($deleteId);
            db_audit_log('user.delete', 'user', $deleteId, $targetUser ? $targetUser['username'] : "id:$deleteId");
            $_SESSION['users_flash'] = 'Account deleted.';
        }
    }
    bb_redirect('admin/users.php' . ($branchKey ? '?branch=' . urlencode($branchKey) : ''));
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['users_flash'] ?? null;
unset($_SESSION['users_flash']);

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
$accounts = db_list_users();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $branchName ? htmlspecialchars($branchName) . ' — ' : '' ?>Users &amp; Staff · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
.users-main {
    flex: 1;
    padding: clamp(20px, 4vw, 48px) clamp(16px, 5vw, 56px);
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}
.users-header { margin-bottom: 24px; }
.users-header__title {
    font-family: var(--font-serif);
    font-size: clamp(1.4rem, 3vw, 2rem);
    font-weight: 700;
    color: var(--blue-900);
    margin: 0;
}
.users-header__sub { font-size: 0.82rem; color: var(--blue-500); margin: 4px 0 0; }
.users-actions { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
.users-card {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}
.user-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--blue-50);
    transition: background 120ms;
}
.user-row:last-child { border-bottom: none; }
.user-row:hover { background: var(--blue-50); }
.user-info__name { font-weight: 600; color: var(--blue-900); font-size: 0.95rem; }
.user-info__meta { color: var(--blue-500); font-size: 0.82rem; margin-top: 3px; }
.user-role-badge {
    display: inline-block; padding: 2px 8px; border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; margin-left: 6px; vertical-align: middle;
}
.user-role-badge--admin { background: #fef3e2; color: #c47a1a; }
.user-role-badge--staff { background: var(--blue-50); color: var(--blue-700); border: 1px solid var(--blue-100); }
.user-permissions { margin-top: 5px; }
.perm-tag {
    display: inline-block; background: var(--blue-50); color: var(--blue-700);
    border: 1px solid var(--blue-100); border-radius: 4px;
    padding: 1px 7px; margin: 2px 2px 0 0;
    font-size: 0.72rem; font-weight: 600; text-transform: capitalize;
}
.full-access { font-size: 0.78rem; color: var(--blue-500); margin-top: 3px; }
.delete-btn {
    flex-shrink: 0; padding: 7px 16px; font-size: 0.82rem;
    border-radius: var(--radius-sm); border: 1px solid var(--blue-100);
    background: var(--white); color: var(--blue-500);
    cursor: pointer; font-family: inherit; font-weight: 500;
    transition: background 120ms, border-color 120ms, color 120ms;
}
.delete-btn:hover { background: #fff0f0; border-color: #f5c6c6; color: #c0392b; }
.you-label { font-size: 0.78rem; color: var(--blue-500); padding: 7px 16px; flex-shrink: 0; }
.flash-msg {
    background: var(--blue-50); border: 1px solid var(--blue-100);
    border-radius: var(--radius-md); padding: 12px 18px;
    margin-bottom: 20px; font-size: 0.88rem; color: var(--blue-900);
}
</style>
</head>
<body class="dashboard-body">

<!-- ── TOP BAR ─────────────────────────────────────────────────── -->
<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<?php if (bb_is_admin()): ?><span class="topbar__brand-suffix">.admin</span><?php endif; ?></span>
    </div>
    <div class="topbar__right">
        <div class="topbar__user">
            <span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span>
            <span class="topbar__user-role"><?= bb_is_admin() ? 'Admin' : 'Staff' ?></span>
        </div>
        <a href="../logout.php" class="topbar__logout">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ── NAV BAR ─────────────────────────────────────────────────── -->
<?php if ($branchKey): include __DIR__ . '/includes/property_navbar.php'; else: include __DIR__ . '/includes/navbar.php'; endif; ?>

<!-- ── MAIN ────────────────────────────────────────────────────── -->
<main class="users-main">

    <div class="users-header">
        <h1 class="users-header__title"><?= $branchName ? htmlspecialchars($branchName) . ' — ' : '' ?>Users &amp; Staff</h1>
        <p class="users-header__sub">Manage accounts and staff permissions.</p>
    </div>

    <?php if ($flash): ?>
        <div class="flash-msg"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="users-actions">
        <a href="register-user.php" class="btn btn--primary" style="width:auto;padding:10px 22px;">+ Register Staff</a>
        <a href="register-admin.php" class="btn btn--ghost" style="width:auto;padding:10px 22px;">+ Register Admin</a>
    </div>

    <div class="users-card">
        <?php foreach ($accounts as $a): ?>
        <div class="user-row">
            <div class="user-info">
                <div class="user-info__name">
                    <?= htmlspecialchars($a['full_name'] ?: $a['username']) ?>
                    <span class="user-role-badge user-role-badge--<?= $a['role'] ?>">
                        <?= $a['role'] === 'admin' ? 'Admin' : 'Staff' ?>
                    </span>
                </div>
                <div class="user-info__meta">
                    @<?= htmlspecialchars($a['username']) ?> · <?= htmlspecialchars($a['email']) ?>
                </div>
                <?php if ($a['role'] === 'staff' && $a['permissions']): ?>
                    <div class="user-permissions">
                        <?php foreach (array_map('trim', explode(',', $a['permissions'])) as $perm): ?>
                            <span class="perm-tag"><?= htmlspecialchars($perm) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($a['role'] === 'admin'): ?>
                    <div class="full-access">Full access</div>
                <?php endif; ?>
            </div>
            <?php if ((int)$a['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="post" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($a['username'])) ?>? This can\'t be undone.');">
                <input type="hidden" name="delete_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="delete-btn">Delete</button>
            </form>
            <?php else: ?>
                <span class="you-label">(you)</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

</main>

<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>