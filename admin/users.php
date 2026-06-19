<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_admin(); // hard admin-only — staff can never reach this, no permission unlocks it

// Handle the delete action (simple POST-then-redirect, no JS needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    $token = $_POST['csrf_token'] ?? '';
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        if ($deleteId === (int) $_SESSION['user_id']) {
            $_SESSION['users_flash'] = "You can't delete your own account while logged in as it.";
        } else {
            db_delete_user($deleteId);
            $_SESSION['users_flash'] = 'Account deleted.';
        }
    }
    bb_redirect('admin/users.php');
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
<title>Users &amp; Staff · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/property.css">
<link rel="stylesheet" href="../assets/css/account.css">
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <div class="ptopbar__breadcrumb">
        <span aria-current="page">Users &amp; Staff</span>
    </div>
    <a href="../logout.php" class="ptopbar__logout">Log out</a>
</header>

<main class="property-main" style="max-width:760px; margin:0 auto;">
    <div class="property-heading">
        <p class="property-heading__eyebrow">Admin only</p>
        <h1 class="property-heading__title">Users &amp; Staff</h1>
    </div>

    <?php if ($flash): ?>
        <div class="account-panel" style="margin-bottom:20px;"><p style="margin:0;"><?= htmlspecialchars($flash) ?></p></div>
    <?php endif; ?>

    <div style="display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap;">
        <a href="register-user.php" class="btn btn--primary" style="width:auto; padding:12px 22px;">+ Register User</a>
        <a href="register-admin.php" class="btn btn--ghost" style="width:auto; padding:12px 22px;">+ Register Admin</a>
    </div>

    <div class="account-panel">
        <?php foreach ($accounts as $a): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; padding:14px 0; border-bottom:1px solid var(--sky-200);">
            <div>
                <strong><?= htmlspecialchars($a['full_name'] ?: $a['username']) ?></strong>
                <div style="color:var(--ink-500); font-size:0.85rem;">
                    @<?= htmlspecialchars($a['username']) ?> &middot; <?= htmlspecialchars($a['email']) ?>
                    &middot; <span style="text-transform:capitalize;"><?= htmlspecialchars($a['role']) ?></span>
                    <?php if ($a['role'] === 'staff' && $a['permissions']): ?>
                        &middot; <?= htmlspecialchars($a['permissions']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" onsubmit="return confirm('Delete this account? This can\'t be undone.');">
                <input type="hidden" name="delete_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn btn--ghost" style="width:auto; padding:8px 16px; font-size:0.85rem;">Delete</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</main>

</body>
</html>
