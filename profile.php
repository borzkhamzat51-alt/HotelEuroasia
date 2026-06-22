<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
bb_require_permission('settings'); // admins always pass; staff need the Settings permission

$userId = $_SESSION['user_id'];
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) < 2) {
        $errors['full_name'] = 'Please enter a full name.';
    }
    if ($username === '' || !preg_match('/^[a-z0-9_.]{3,50}$/i', $username)) {
        $errors['username'] = 'Username must be 3+ characters (letters, numbers, _ or . only).';
    } elseif (db_username_taken($username, $userId)) {
        $errors['username'] = 'That username is already taken.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (db_email_taken($email, $userId)) {
        $errors['email'] = 'That email is already registered.';
    }

    if (empty($errors)) {
        db_update_profile($userId, $fullName, $username, $email);
        db_audit_log('profile.update', 'user', $userId, $username);
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username']  = $username;
        $success = 'Profile updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new      = (string) ($_POST['new_password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, db_get_password_hash($userId))) {
        $errors['current_password'] = 'That current password is incorrect.';
    } elseif ($new === '' || strlen($new) < 8) {
        $errors['new_password'] = 'Use at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors['new_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        db_update_password($userId, password_hash($new, PASSWORD_DEFAULT));
        db_audit_log('profile.password_change', 'user', $userId, $_SESSION['username'] ?? null);
        $success = 'Password changed.';
    }
}

$user = db_find_user_by_id($userId);
$backHref = bb_is_admin() ? 'admin/dashboard.php' : ltrim(bb_role_home(), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Settings · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/account-manage.css">
</head>
<body class="am-body">

<header class="am-topbar">
    <a href="<?= htmlspecialchars($backHref) ?>" class="am-topbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <span class="am-topbar__title">Account Settings</span>
    <a href="logout.php" class="am-topbar__logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 16l4-4-4-4"/><path d="M20 12H9"/></svg>
        Log out
    </a>
</header>

<main class="am-main">
<div class="am-col">

    <div class="am-heading">
        <p class="am-heading__eyebrow">Account</p>
        <h1 class="am-heading__title">Account Settings</h1>
    </div>

    <?php if ($success): ?>
        <div class="am-notice am-notice--success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m22 4-10 10-3-3"/></svg>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" class="am-card" novalidate>
        <input type="hidden" name="form" value="profile">
        <h2 class="am-card__title">Profile Information</h2>

        <div class="field">
            <label for="full_name">Full Name</label>
            <div class="field__control"><input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required></div>
            <?php if (!empty($errors['full_name'])): ?><span class="field__error"><?= htmlspecialchars($errors['full_name']) ?></span><?php endif; ?>
        </div>
        <div class="field">
            <label for="username">Username</label>
            <div class="field__control"><input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required></div>
            <?php if (!empty($errors['username'])): ?><span class="field__error"><?= htmlspecialchars($errors['username']) ?></span><?php endif; ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <div class="field__control"><input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required></div>
            <?php if (!empty($errors['email'])): ?><span class="field__error"><?= htmlspecialchars($errors['email']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary">Save Changes</button>
    </form>

    <form method="post" class="am-card" novalidate>
        <input type="hidden" name="form" value="password">
        <h2 class="am-card__title">Change Password</h2>

        <div class="field">
            <label for="current_password">Current Password</label>
            <div class="field__control"><input type="password" id="current_password" name="current_password" required></div>
            <?php if (!empty($errors['current_password'])): ?><span class="field__error"><?= htmlspecialchars($errors['current_password']) ?></span><?php endif; ?>
        </div>
        <div class="field">
            <label for="new_password">New Password</label>
            <div class="field__control"><input type="password" id="new_password" name="new_password" required></div>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm New Password</label>
            <div class="field__control"><input type="password" id="confirm_password" name="confirm_password" required></div>
            <?php if (!empty($errors['new_password'])): ?><span class="field__error"><?= htmlspecialchars($errors['new_password']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary">Change Password</button>
    </form>

</div>
</main>

</body>
</html>