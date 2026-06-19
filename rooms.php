<?php
require_once __DIR__ . '/config.php';

if (!bb_is_logged_in()) {
    bb_redirect('index.php');
}
if (bb_is_admin()) {
    bb_redirect('admin/dashboard.php');
}

$branches = [
    'annex'          => 'PP Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'Elti Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];

$underConstruction = ['aps', 'euroasia_stall', 'annex_stall'];

$branchKey = $_GET['branch'] ?? '';
if (!isset($branches[$branchKey])) {
    bb_redirect('dashboard.php');
}
$branchName = $branches[$branchKey];
$isUnderConstruction = in_array($branchKey, $underConstruction);

$rooms = [
    ['number' => '101', 'floor' => 1, 'type' => 'Studio w/ Veranda', 'price' => 9000,  'status' => 'available'],
    ['number' => '102', 'floor' => 1, 'type' => 'Studio w/ Veranda', 'price' => 9000,  'status' => 'occupied'],
    ['number' => '103', 'floor' => 1, 'type' => 'Studio w/ Veranda', 'price' => 9000,  'status' => 'available'],
    ['number' => '201', 'floor' => 2, 'type' => 'Family B 1BR',      'price' => 13000, 'status' => 'reserved'],
    ['number' => '205', 'floor' => 2, 'type' => 'Studio',            'price' => 8000,  'status' => 'available'],
    ['number' => '206', 'floor' => 2, 'type' => 'Studio',            'price' => 8000,  'status' => 'maintenance'],
    ['number' => '301', 'floor' => 3, 'type' => 'Family A 1BR',      'price' => 16000, 'status' => 'available'],
    ['number' => '305', 'floor' => 3, 'type' => 'Studio',            'price' => 8000,  'status' => 'occupied'],
];

$statusLabels = [
    'available'   => 'Available',
    'occupied'    => 'Booked',
    'reserved'    => 'Reserved',
    'maintenance' => 'Unavailable',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branchName) ?> Rooms · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/property.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/account.css">
<style>
  .guest-room-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; padding: 0 24px 48px; max-width: 1100px; margin: 0 auto; }
  .floor-tabs { display: flex; justify-content: center; gap: 10px; margin: 24px 0; flex-wrap: wrap; }
  .floor-tabs button { padding: 8px 20px; border-radius: 999px; border: 1.5px solid var(--sky-200); background: var(--white); cursor: pointer; font-weight: 600; font-size: 0.85rem; color: var(--ink-700); transition: background 180ms var(--ease-out), color 180ms var(--ease-out); }
  .floor-tabs button:hover { background: var(--sky-100); }
  .floor-tabs button.is-active { background: var(--blue-500); color: var(--white); border-color: var(--blue-500); }
  .room-card { cursor: default; }
  .room-card.is-bookable { cursor: pointer; }
  .construction-message { text-align: center; padding: 60px 20px; }
  .construction-message h2 { font-family: 'Playfair Display', serif; font-size: 2rem; color: var(--ink-900); }
  .construction-message p { color: var(--ink-500); max-width: 500px; margin: 12px auto; }
</style>
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="dashboard.php" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <div class="ptopbar__breadcrumb">
        <a href="dashboard.php">Properties</a>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span aria-current="page"><?= htmlspecialchars($branchName) ?> Rooms</span>
    </div>
    <a href="logout.php" class="ptopbar__logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
        Log out
    </a>
</header>

<main class="property-main">
    <div class="property-heading">
        <p class="property-heading__eyebrow">Browse &amp; Book</p>
        <h1 class="property-heading__title"><?= htmlspecialchars($branchName) ?></h1>
    </div>

    <?php if ($isUnderConstruction): ?>
        <div class="construction-message">
            <h2>🚧 Under Construction</h2>
            <p>We're working hard to bring you this property. Please check back later!</p>
            <a href="dashboard.php" class="btn btn--primary account-btn" style="display:inline-flex; align-items:center; gap:8px; width:auto; padding:12px 28px; text-decoration:none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px;">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Back to Dashboard
            </a>
        </div>
    <?php else: ?>
        <div class="floor-tabs" id="floorTabs">
            <button type="button" class="is-active" data-floor="all">All Floors</button>
            <button type="button" data-floor="1">1st Floor</button>
            <button type="button" data-floor="2">2nd Floor</button>
            <button type="button" data-floor="3">3rd Floor</button>
        </div>

        <div class="guest-room-grid" id="roomGrid">
            <?php foreach ($rooms as $room): ?>
                <?php $bookable = $room['status'] === 'available'; ?>
                <div class="room-card status-<?= $room['status'] ?> <?= $bookable ? 'is-bookable' : '' ?>"
                     data-floor="<?= $room['floor'] ?>"
                     data-room-number="<?= htmlspecialchars($room['number']) ?>"
                     data-type="<?= htmlspecialchars($room['type']) ?>"
                     data-price="<?= $room['price'] ?>"
                     data-status="<?= $room['status'] ?>">
                    <span class="rc-badge"><?= $statusLabels[$room['status']] ?></span>
                    <div class="rc-content">
                        <h3 class="rc-title"><?= htmlspecialchars($room['type']) ?></h3>
                        <span class="rc-subtitle">Floor <?= $room['floor'] ?></span>
                        <div class="rc-price">&#8369;<?= number_format($room['price']) ?> / night</div>
                    </div>
                    <div class="rc-footer">Room <?= htmlspecialchars($room['number']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div id="roomDetailModal" class="modal-overlay" hidden>
  <div class="modal">
    <button class="modal__close" id="roomDetailClose">&times;</button>
    <div id="roomDetailContent" class="modal__content"></div>
  </div>
</div>

<footer class="property-footer">
    <p class="property-footer__copy">&copy; <?= date('Y') ?> Bluebookers. All rights reserved.</p>
</footer>

<script src="assets/js/rooms-guest.js" defer></script>
</body>
</html>