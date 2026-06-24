<?php
require_once __DIR__ . '/../config.php';
bb_require_permission('rooms');

// ── Hardcoded branch definitions ──────────────────────────────────
$branches = [
    'annex'          => ['name' => 'BB Apartelle',   'tag' => 'Comfort & Style',          'color' => '#2563eb'],
    'mtv'            => ['name' => 'MTV3',            'tag' => 'City-view Rooms',           'color' => '#0891b2'],
    'dormitel'       => ['name' => 'ELTI Dormitel',   'tag' => 'Budget-friendly Stays',     'color' => '#7c3aed'],
    'aps'            => ['name' => 'APS',             'tag' => 'Attendance & Payroll',      'color' => '#0f766e'],
    'euroasia_stall' => ['name' => 'Euroasia Stall',  'tag' => 'Stall Operations',          'color' => '#b45309'],
    'annex_stall'    => ['name' => 'Annex Stall',     'tag' => 'Stall Operations',          'color' => '#be185d'],
];

$branchKey = $_GET['branch'] ?? '';
if (!isset($branches[$branchKey])) {
    header('Location: dashboard.php');
    exit;
}

// ── Load custom settings from JSON ──────────────────────────────────
$settingsFile   = __DIR__ . '/data/property_settings.json';
$customSettings = file_exists($settingsFile)
    ? (json_decode(file_get_contents($settingsFile), true) ?: [])
    : [];

// ── Override with custom values if they exist ─────────────────────
$b = $branches[$branchKey];
if (!empty($customSettings[$branchKey])) {
    $c = $customSettings[$branchKey];
    if (!empty($c['name']))        $b['name'] = $c['name'];
    if (!empty($c['description'])) $b['tag']  = $c['description'];
    // Image is handled separately in the hero (if needed)
}

$branchName = $b['name'];
$branchTag  = $b['tag'];
$branchColor = $b['color'];

$isLodging = in_array($branchKey, ['annex', 'mtv', 'dormitel']);

// Build action cards visible to this user
$cards = [];

if ($isLodging) {
    $cards[] = [
        'href'  => 'layout_1st_floor.php?branch=' . urlencode($branchKey),
        'icon'  => '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="3" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="3" y="13" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>',
        'name'  => 'Floor Layout',
        'desc'  => 'Interactive room map with live status',
        'badge' => 'Live',
        'color' => '#2563eb',
    ];
    $cards[] = [
        'href'  => 'reservations.php?branch=' . urlencode($branchKey),
        'icon'  => '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M16 3v4M8 3v4M3 10h18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="15" r="1.1" fill="currentColor"/><circle cx="12" cy="15" r="1.1" fill="currentColor"/><circle cx="16" cy="15" r="1.1" fill="currentColor"/></svg>',
        'name'  => 'Calendar',
        'desc'  => 'Reservations, check-ins & availability',
        'badge' => '',
        'color' => '#0891b2',
    ];
}

if (bb_has_permission('reports')) {
    $cards[] = [
        'href'  => 'reports.php?branch=' . urlencode($branchKey),
        'icon'  => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 20V14M9 20V8M14 20V11M19 20V4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        'name'  => 'Reports',
        'desc'  => 'Occupancy, revenue & performance',
        'badge' => '',
        'color' => '#059669',
    ];
}

if (bb_has_permission('guests')) {
    $cards[] = [
        'href'  => 'guests.php?branch=' . urlencode($branchKey),
        'icon'  => '<svg viewBox="0 0 24 24" fill="none"><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M19 8v6M22 11h-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        'name'  => 'Guests',
        'desc'  => 'Guest profiles & stay history',
        'badge' => '',
        'color' => '#7c3aed',
    ];
}

$cards[] = [
    'href'  => 'audit.php?branch=' . urlencode($branchKey),
    'icon'  => '<svg viewBox="0 0 24 24" fill="none"><path d="M9 12h6M9 16h4M6 4h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M9 8h6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
    'name'  => 'Audit Log',
    'desc'  => 'Track all actions and changes',
    'badge' => '',
    'color' => '#b45309',
];

if (bb_is_admin()) {
    $cards[] = [
        'href'  => 'users.php?branch=' . urlencode($branchKey),
        'icon'  => '<svg viewBox="0 0 24 24" fill="none"><circle cx="8" cy="7" r="3.5" stroke="currentColor" stroke-width="1.7"/><circle cx="16.5" cy="7" r="3.5" stroke="currentColor" stroke-width="1.7"/><path d="M1.5 20c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M16 14a6.5 6.5 0 0 1 6.5 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
        'name'  => 'Users & Staff',
        'desc'  => 'Manage accounts and permissions',
        'badge' => 'Admin',
        'color' => '#be185d',
    ];
}

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
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
<style>
  :root { --brand: <?= htmlspecialchars($branchColor) ?>; }

  /* ─── Minimal back link ──────────────────────────────────────────── */
  .prop-back {
    position: absolute;
    top: 20px;
    left: 24px;
    z-index: 10;
    display: flex;
    align-items: center;
    gap: 6px;
    color: rgba(255,255,255,0.7);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    font-family: 'Inter', sans-serif;
    transition: color 200ms, transform 200ms;
  }
  .prop-back svg {
    width: 18px;
    height: 18px;
  }
  .prop-back:hover {
    color: #fff;
    transform: translateX(-2px);
  }

  .prop-hero {
    position: relative;
  }
</style>
</head>
<body class="property-body">

<!-- ── Hero ────────────────────────────────────────────── -->
<div class="prop-hero">
    <!-- Single "Dashboard" link -->
    <a href="<?= bb_role_home() ?>" class="prop-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Dashboard
    </a>

    <div class="prop-hero__bg" style="--brand:<?= htmlspecialchars($branchColor) ?>;"></div>
    <div class="prop-hero__content">
        <span class="prop-hero__eyebrow"><?= htmlspecialchars($branchTag) ?></span>
        <h1 class="prop-hero__title"><?= htmlspecialchars($branchName) ?></h1>
        <p class="prop-hero__sub">Select a tool below to manage this property</p>
    </div>
</div>

<!-- ── Tool cards ──────────────────────────────────────── -->
<main class="prop-main">
    <div class="prop-grid">
        <?php foreach ($cards as $i => $card): ?>
        <a href="<?= htmlspecialchars($card['href']) ?>"
           class="prop-card"
           style="--card-color:<?= htmlspecialchars($card['color']) ?>; --d:<?= $i ?>;">
            <div class="prop-card__icon-wrap">
                <?= $card['icon'] ?>
            </div>
            <?php if ($card['badge']): ?>
            <span class="prop-card__badge"><?= htmlspecialchars($card['badge']) ?></span>
            <?php endif; ?>
            <div class="prop-card__body">
                <span class="prop-card__name"><?= htmlspecialchars($card['name']) ?></span>
                <span class="prop-card__desc"><?= htmlspecialchars($card['desc']) ?></span>
            </div>
            <span class="prop-card__arrow">
                <svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</main>

<footer class="property-footer">
    <p class="property-footer__copy">&copy; <?= date('Y') ?> Bluebookers &mdash; <?= htmlspecialchars($branchName) ?>. All rights reserved.</p>
</footer>

</body>
</html>