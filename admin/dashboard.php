<?php
require_once __DIR__ . '/../config.php';
bb_require_permission('dashboard');

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];

$properties = [
    ['key' => 'annex',         'name' => 'BB Apartelle',      'tag' => 'Comfort & Style'],
    ['key' => 'mtv',           'name' => 'MTV3',              'tag' => 'City-view rooms'],
    ['key' => 'dormitel',      'name' => 'ELTI Dormitel',     'tag' => 'Budget-friendly stays'],
    ['key' => 'aps',           'name' => 'APS',              'tag' => 'Attendance and payroll system'],
    ['key' => 'stalls',      'name' => 'Euoroasia Stalls',     'tag' => 'n/a'],
    ['key' => 'stalls',      'name' => 'Annex Stalls',     'tag' => 'n/a'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=EB+Garamond:ital@1&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body class="dashboard-body">

<!-- ===================== TOP BAR ===================== -->
<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<span class="topbar__brand-suffix">.admin</span></span>
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

<!-- ===================== NAV BAR – DEEP CLEANED ===================== -->
<nav class="navbar" id="navbar">
    <?php if (bb_has_permission('reservations')): ?>
        <a class="navbar__item" href="reservations.php">Calendar</a>
    <?php endif; ?>

    <?php if (bb_has_permission('guests')): ?>
        <a class="navbar__item" href="guests.php">Guests</a>
    <?php endif; ?>

    <?php if (bb_has_permission('reports')): ?>
        <a class="navbar__item" href="reports.php">Reports</a>
    <?php endif; ?>

    <?php if (bb_is_admin()): ?>
        <a class="navbar__item" href="users.php">Users &amp; Staff</a>
    <?php endif; ?>

    <?php if (bb_is_admin() || bb_has_permission('settings')): ?>
    <div class="navbar__dropdown" id="settingsDropdown">
        <button type="button" class="navbar__item navbar__item--dropdown" id="settingsToggle" aria-haspopup="true" aria-expanded="false">
            Settings
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="navbar__dropdown-menu" id="settingsMenu">
            <?php if (bb_is_admin()): ?>
                <a href="register-user.php">Register User</a>
                <a href="register-admin.php">Register Admin</a>
            <?php endif; ?>
            <a href="../profile.php">Account Settings</a>
        </div>
    </div>
    <?php endif; ?>
</nav>

<!-- ===================== MAIN ===================== -->
<main class="dashboard-main">

    <div class="dashboard-welcome" data-animate-item style="--d:0">
        <div class="ornament">
            <span class="ornament__line"></span>
            <svg class="ornament__glyph" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 21c0-5 3.5-8.5 8-9-1 5-3.5 8-8 9Z" fill="currentColor"/>
                <path d="M12 21c0-5-3.5-8.5-8-9 1 5 3.5 8 8 9Z" fill="currentColor"/>
                <path d="M12 21c0-6 0-11 0-15" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
            <span class="ornament__line"></span>
        </div>
        <h1 class="dashboard-welcome__title">Welcome, <?= htmlspecialchars(strtoupper($displayName)) ?></h1>
        <p class="dashboard-welcome__subtitle">Choose a property to manage.</p>
    </div>

    <div class="property-grid">
        <?php foreach ($properties as $i => $p): ?>
        <article class="property-card" data-animate-item style="--d:<?= $i + 1 ?>">
            <a href="property.php?branch=<?= htmlspecialchars($p['key']) ?>" class="property-card__link">
                <div class="property-card__image-frame">
                    <img class="property-card__image"
                         src="../assets/images/properties/<?= htmlspecialchars($p['key']) ?>.jpg"
                         onerror="this.onerror=null;this.src='../assets/images/properties/<?= htmlspecialchars($p['key']) ?>.svg';"
                         alt="<?= htmlspecialchars($p['name']) ?>">
                    <div class="property-card__shade"></div>
                </div>
                <div class="property-card__body">
                    <h2 class="property-card__name"><?= htmlspecialchars($p['name']) ?></h2>
                    <p class="property-card__tag"><?= htmlspecialchars($p['tag']) ?></p>
                </div>
            </a>
            <?php if (bb_has_permission('rooms')): ?>
            <a href="property.php?branch=<?= htmlspecialchars($p['key']) ?>" class="property-card__manage" aria-label="Manage <?= htmlspecialchars($p['name']) ?>">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15.5 4.5l3 3-9 9-3.5 1 1-3.5 9-9.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                <span>Manage</span>
            </a>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>

</main>

<!-- ===================== FOOTER ===================== -->
<footer class="dashboard-footer">
    <p class="dashboard-footer__copy">&copy; <?= date('Y') ?> Bluebookers. All rights reserved.</p>
</footer>

<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>