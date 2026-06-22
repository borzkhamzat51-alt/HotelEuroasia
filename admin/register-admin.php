<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_admin(); // only admins create admins, full stop

$errors = [];
$values = ['full_name' => '', 'username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['full_name'] = trim($_POST['full_name'] ?? '');
    $values['username']  = trim($_POST['username'] ?? '');
    $values['email']     = trim($_POST['email'] ?? '');
    $password             = (string) ($_POST['password'] ?? '');
    $confirm              = (string) ($_POST['confirm_password'] ?? '');

    if ($values['full_name'] === '' || mb_strlen($values['full_name']) < 2) {
        $errors['full_name'] = 'Please enter a full name.';
    }
    if ($values['username'] === '' || !preg_match('/^[a-z0-9_.]{3,50}$/i', $values['username'])) {
        $errors['username'] = 'Username must be 3+ characters (letters, numbers, _ or . only).';
    } elseif (db_username_taken($values['username'])) {
        $errors['username'] = 'That username is already taken.';
    }
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (db_email_taken($values['email'])) {
        $errors['email'] = 'That email is already registered.';
    }
    if ($password === '' || strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors['password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $newId = db_create_admin($values['username'], $values['email'], password_hash($password, PASSWORD_DEFAULT), $values['full_name']);
        db_audit_log('user.create_admin', 'user', $newId, $values['username']);
        $_SESSION['users_flash'] = 'Admin account "' . $values['username'] . '" created — full access, no permission checklist needed.';
        bb_redirect('admin/users.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register Admin · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/account-manage.css">
</head>
<body class="am-body">

<header class="am-topbar">
    <a href="users.php" class="am-topbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Users &amp; Staff
    </a>
    <span class="am-topbar__title">Register Admin</span>
    <a href="../logout.php" class="am-topbar__logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M16 16l4-4-4-4"/><path d="M20 12H9"/></svg>
        Log out
    </a>
</header>

<main class="am-main">
<div class="am-col">

    <div class="am-heading">
        <p class="am-heading__eyebrow">Admin only</p>
        <h1 class="am-heading__title">Register Admin Account</h1>
    </div>

    <div class="am-notice">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
        <span>This account will have unrestricted access to every page and feature — no permission checklist, because admins bypass it entirely.</span>
    </div>

    <form method="post" class="am-card" novalidate>
        <h2 class="am-card__title">Account Details</h2>

        <div class="field">
            <label for="full_name">Full Name</label>
            <div class="field__control"><input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($values['full_name']) ?>" required></div>
            <?php if (!empty($errors['full_name'])): ?><span class="field__error"><?= htmlspecialchars($errors['full_name']) ?></span><?php endif; ?>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <div class="field__control"><input type="text" id="username" name="username" value="<?= htmlspecialchars($values['username']) ?>" required></div>
            <?php if (!empty($errors['username'])): ?><span class="field__error"><?= htmlspecialchars($errors['username']) ?></span><?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <div class="field__control"><input type="email" id="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required></div>
            <?php if (!empty($errors['email'])): ?><span class="field__error"><?= htmlspecialchars($errors['email']) ?></span><?php endif; ?>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="field__control"><input type="password" id="password" name="password" required></div>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <div class="field__control"><input type="password" id="confirm_password" name="confirm_password" required></div>
            <?php if (!empty($errors['password'])): ?><span class="field__error"><?= htmlspecialchars($errors['password']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn--primary">Create Admin Account</button>
    </form>

</div>
</main>

</body>
</html>