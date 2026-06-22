<?php
require_once __DIR__ . '/../config.php';
bb_require_permission('rooms');

$isAdmin = true;

$branches = [
    'annex'          => 'BB Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'ELTI Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];

$branchKey = $_GET['branch'] ?? '';
if (!isset($branches[$branchKey])) {
    header('Location: dashboard.php');
    exit;
}
$branchName = $branches[$branchKey];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branchName) ?> · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/property.css">
</head>
<body class="property-body">

<!-- ===================== TOP BAR ===================== -->
<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Dashboard</span>
    </a>

    <nav class="ptopbar__breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">Properties</a>
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span aria-current="page"><?= htmlspecialchars($branchName) ?></span>
    </nav>

    <a href="../logout.php" class="ptopbar__logout">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span>Log out</span>
    </a>
</header>

<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<!-- ===================== MAIN ===================== -->
<main class="property-main">

    <div class="property-heading" data-animate-item style="--d:0">
        <p class="property-heading__eyebrow"><?= $isAdmin ? 'Admin tools' : 'Branch overview' ?></p>
        <h1 class="property-heading__title"><?= htmlspecialchars($branchName) ?></h1>
    </div>

    <div class="action-grid">
        <a href="layout_1st_floor.php?branch=<?= htmlspecialchars($branchKey) ?>" class="action-card" data-animate-item style="--d:1">
            <span class="action-card__icon">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="4" y="4" width="7" height="7" rx="1.2" stroke="currentColor" stroke-width="1.6"/>
                    <rect x="13" y="4" width="7" height="7" rx="1.2" stroke="currentColor" stroke-width="1.6"/>
                    <rect x="4" y="13" width="7" height="7" rx="1.2" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M15.5 15.5l3.8 3.8M19.5 15.5l-3.8 3.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="action-card__name">Layout</span>
            <span class="action-card__desc">View and edit the floor plan</span>
            <span class="action-card__arrow" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </a>

        <a href="reservations.php?branch=<?= htmlspecialchars($branchKey) ?>" class="action-card" data-animate-item style="--d:2">
            <span class="action-card__icon">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="3.5" y="5.5" width="17" height="15" rx="2" stroke="currentColor" stroke-width="1.6"/>
                    <path d="M8 3.5v4M16 3.5v4M3.5 10h17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    <circle cx="8.2" cy="14" r="1" fill="currentColor"/>
                    <circle cx="12" cy="14" r="1" fill="currentColor"/>
                    <circle cx="15.8" cy="14" r="1" fill="currentColor"/>
                </svg>
            </span>
            <span class="action-card__name">Calendar</span>
            <span class="action-card__desc">Manage bookings and availability</span>
            <span class="action-card__arrow" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </a>
    </div>

</main>

<!-- ===================== FOOTER ===================== -->
<footer class="property-footer">
    <div class="property-footer__social">
        <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="5" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.5"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor"/></svg></a>
        <a href="#" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="none"><path d="M14 9h2.5V6H14c-1.66 0-3 1.34-3 3v2H9v3h3v6h3v-6h2.2l.3-3H14V9.5c0-.28.22-.5.5-.5H14V9Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></a>
        <a href="#" aria-label="WhatsApp"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M8.5 9.3c.3-1 1.1-1 1.4-.6l.6 1.1c.2.4.1.7-.1 1-.3.4-.6.6-.3 1.1.5.9 1.5 1.8 2.4 2.2.5.2.7 0 1-.3.3-.3.6-.4 1-.2l1.1.6c.4.3.4 1.1-.6 1.4-1.4.5-3.5-.5-5-2s-2.5-3.6-2-5Z" fill="currentColor"/></svg></a>
    </div>
    <p class="property-footer__copy">&copy; <?= date('Y') ?> Bluebookers. All rights reserved.</p>
</footer>

</body>
</html>