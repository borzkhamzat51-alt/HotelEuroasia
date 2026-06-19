<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reservations');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── All branches ───
$allBranches = [
    'annex'          => 'BB Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'ELTI Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];

// ─── Lodging properties (shown in tabs) ───
$lodgingKeys = ['annex', 'mtv', 'dormitel'];
$lodgingBranches = array_intersect_key($allBranches, array_flip($lodgingKeys));

// ─── Non-lodging (for placeholder) ───
$nonLodgingKeys = ['aps', 'euroasia_stall', 'annex_stall'];

// Default branch: the only property with real room data right now
$defaultBranch = 'mtv';

// Validate incoming branch – if not in allBranches, redirect to default
$branch = $_GET['branch'] ?? $defaultBranch;
if (!isset($allBranches[$branch])) {
    $monthParam = isset($_GET['month']) ? '&month=' . urlencode($_GET['month']) : '';
    header('Location: ?branch=' . $defaultBranch . $monthParam);
    exit;
}

$branchName = $allBranches[$branch];
$isLodging = in_array($branch, $lodgingKeys);

// Month being viewed, format YYYY-MM. Defaults to the current month.
$monthParam = $_GET['month'] ?? '';
$monthDate = DateTime::createFromFormat('Y-m-d', $monthParam . '-01');
if (!$monthDate) {
    $monthDate = new DateTime('first day of this month');
}
$monthDate->modify('first day of this month');

$daysInMonth = (int) $monthDate->format('t');
$monthLabel  = $monthDate->format('F Y');
$todayStr    = (new DateTime())->format('Y-m-d');

$prevMonth = (clone $monthDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthDate)->modify('+1 month')->format('Y-m');
$thisMonth = (new DateTime('first day of this month'))->format('Y-m');

// Build the day list for the header row + per-cell click targets
$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = (clone $monthDate)->modify('+' . ($d - 1) . ' days');
    $days[] = [
        'num'      => $d,
        'date'     => $date->format('Y-m-d'),
        'weekday'  => $date->format('D'),
        'is_today' => $date->format('Y-m-d') === $todayStr,
    ];
}

// ─── PROPERTY‑AWARE DATA FETCHING (only for lodging) ──────────────
$rooms = [];
$reservations = [];
$reservationsByRoom = [];

if ($isLodging) {
    $rooms = db_list_rooms_by_branch($branch);
    $roomIds = array_column($rooms, 'id');

    $rangeStart = $monthDate->format('Y-m-d');
    $rangeEnd   = (clone $monthDate)->modify('+1 month')->format('Y-m-d');

    if (!empty($roomIds)) {
        $reservations = db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd);
    }

    // Group by room + compute bar positions
    foreach ($reservations as $r) {
        $checkIn  = new DateTime($r['check_in']);
        $checkOut = new DateTime($r['check_out']);
        $effectiveStart = max($checkIn, $monthDate);
        $effectiveEnd   = min($checkOut, new DateTime($rangeEnd));

        $startOffset = (int) $monthDate->diff($effectiveStart)->days;
        $spanDays    = max(1, (int) $effectiveStart->diff($effectiveEnd)->days);

        $r['_left_pct']  = round(($startOffset / $daysInMonth) * 100, 4);
        $r['_width_pct'] = round(($spanDays / $daysInMonth) * 100, 4);
        $r['_activity']  = db_get_reservation_activity($r['id']);

        $reservationsByRoom[$r['room_id']][] = $r;
    }
}

$statusLabels = ['reserved' => 'Reserved', 'checked_in' => 'Checked In', 'checked_out' => 'Checked Out', 'cancelled' => 'Cancelled'];
$paymentLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'bank_transfer' => 'Bank Transfer', 'card' => 'Credit/Debit Card'];

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar · <?= htmlspecialchars($branchName) ?> · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/property.css">
<link rel="stylesheet" href="../assets/css/account.css">
<link rel="stylesheet" href="../assets/css/calendar.css">
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <div class="ptopbar__breadcrumb">
        <span aria-current="page">Calendar</span>
    </div>
    <a href="../logout.php" class="ptopbar__logout">Log out</a>
</header>

<main class="property-main cal-main">

    <div class="cal-toolbar">
        <div class="cal-branch-tabs">
            <?php foreach ($lodgingBranches as $key => $label): ?>
                <a href="?branch=<?= $key ?>&month=<?= $monthDate->format('Y-m') ?>" class="<?= $key === $branch ? 'is-active' : '' ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="cal-month-nav">
            <a href="?branch=<?= $branch ?>&month=<?= $prevMonth ?>" class="cal-nav-btn" aria-label="Previous month">&#8249;</a>
            <a href="?branch=<?= $branch ?>&month=<?= $thisMonth ?>" class="cal-today-btn">Today</a>
            <span class="cal-month-label"><?= $monthLabel ?></span>
            <a href="?branch=<?= $branch ?>&month=<?= $nextMonth ?>" class="cal-nav-btn" aria-label="Next month">&#8250;</a>
        </div>

        <?php if ($isLodging && !empty($rooms)): ?>
            <button type="button" class="btn btn--primary" id="newReservationBtn" style="width:auto; padding:10px 20px;">+ New Reservation</button>
        <?php endif; ?>
    </div>

    <?php if (!$isLodging): ?>
        <!-- Non‑lodging property placeholder -->
        <div class="account-panel account-panel--centered" style="margin-top:20px;">
            <p style="font-size:1.2rem; font-weight:600; margin-bottom:0.5rem;">Calendar Not Applicable</p>
            <p style="color:var(--ink-500);">Calendar functionality is not applicable to <strong><?= htmlspecialchars($branchName) ?></strong>.</p>
            <p style="color:var(--ink-500); font-size:0.9rem;">Please select a lodging property from the tabs above.</p>
        </div>

    <?php elseif (empty($rooms)): ?>
        <!-- Lodging property with no rooms yet -->
        <div class="account-panel account-panel--centered" style="margin-top:20px;">
            <p>No rooms have been set up for <?= htmlspecialchars($branchName) ?> yet.</p>
        </div>

    <?php else: ?>
        <!-- ─── RENDER CALENDAR GRID ─── -->
        <div class="cal-grid-wrap">
            <div class="cal-grid" style="--days: <?= $daysInMonth ?>;">

                <div class="cal-header-row">
                    <div class="cal-label-col cal-label-col--header">Room</div>
                    <div class="cal-days-track">
                        <?php foreach ($days as $day): ?>
                            <div class="cal-day-header <?= $day['is_today'] ? 'is-today' : '' ?>">
                                <span class="cal-day-header__num"><?= $day['num'] ?></span>
                                <span class="cal-day-header__wd"><?= $day['weekday'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php foreach ($rooms as $room): ?>
                <div class="cal-row">
                    <div class="cal-label-col" data-room-id="<?= $room['id'] ?>" data-room-number="<?= htmlspecialchars($room['room_number']) ?>">
                        <strong>RM<?= htmlspecialchars($room['room_number']) ?></strong>
                        <span><?= htmlspecialchars($room['room_type']) ?></span>
                    </div>
                    <div class="cal-days-track cal-row__track">
                        <?php foreach ($days as $day): ?>
                            <div class="cal-day-slot <?= $day['is_today'] ? 'is-today' : '' ?>"
                                 data-room-id="<?= $room['id'] ?>"
                                 data-date="<?= $day['date'] ?>"></div>
                        <?php endforeach; ?>

                        <?php foreach (($reservationsByRoom[$room['id']] ?? []) as $r): ?>
                            <div class="cal-bar cal-bar--<?= $r['status'] ?>"
                                 style="left:<?= $r['_left_pct'] ?>%; width:<?= $r['_width_pct'] ?>%;"
                                 title="<?= htmlspecialchars($r['guest_full_name'] . ' • ' . $r['check_in'] . ' to ' . $r['check_out'] . ' • ' . $statusLabels[$r['status']]) ?>"
                                 data-reservation="<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>">
                                <span><?= htmlspecialchars($r['guest_full_name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>

        <div class="cal-legend">
            <span><i class="cal-bar--reserved"></i> Reserved</span>
            <span><i class="cal-bar--checked_in"></i> Checked In</span>
            <span><i class="cal-bar--checked_out"></i> Checked Out</span>
        </div>

    <?php endif; ?>
</main>

<!-- ===================== RESERVATION MODAL ===================== -->
<div id="reservationModal" class="modal-overlay" hidden>
  <div class="modal modal--wide">
    <button class="modal__close" id="reservationModalClose">&times;</button>
    <div id="reservationModalContent" class="modal__content"></div>
  </div>
</div>

<script>
  window.BB_CALENDAR = {
    branch: <?= json_encode($branch) ?>,
    branchLabel: <?= json_encode($branchName) ?>,
    rooms: <?= json_encode($rooms) ?>,
    statusLabels: <?= json_encode($statusLabels) ?>,
    paymentLabels: <?= json_encode($paymentLabels) ?>,
    canDelete: <?= bb_is_admin() ? 'true' : 'false' ?>,
    csrfToken: <?= json_encode($_SESSION['csrf_token'] ?? '') ?>
  };
</script>
<script src="../assets/js/calendar.js" defer></script>
</body>
</html>