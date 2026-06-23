<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reservations');

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$allBranches = [
    'annex' => 'BB Apartelle',
    'mtv' => 'MTV3',
    'dormitel' => 'ELTI Dormitel',
    'aps' => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall' => 'Annex Stall',
];
$lodgingKeys = ['annex', 'mtv', 'dormitel'];
$lodgingBranches = array_intersect_key($allBranches, array_flip($lodgingKeys));
$defaultBranch = 'mtv';
$branch = $_GET['branch'] ?? $defaultBranch;
if (!isset($allBranches[$branch])) {
    $monthParam = isset($_GET['month']) ? '&month=' . urlencode($_GET['month']) : '';
    header('Location: ?branch=' . $defaultBranch . $monthParam);
    exit;
}
$branchName = $allBranches[$branch];
$isLodging = in_array($branch, $lodgingKeys);

$monthParam = $_GET['month'] ?? '';
$monthDate = DateTime::createFromFormat('Y-m-d', $monthParam . '-01');
if (!$monthDate) $monthDate = new DateTime('first day of this month');
$monthDate->modify('first day of this month');
$daysInMonth = (int) $monthDate->format('t');
$monthLabel = $monthDate->format('F Y');
$todayStr = (new DateTime())->format('Y-m-d');
$prevMonth = (clone $monthDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthDate)->modify('+1 month')->format('Y-m');
$thisMonth = (new DateTime('first day of this month'))->format('Y-m');

$days = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = (clone $monthDate)->modify('+' . ($d - 1) . ' days');
    $weekday = $date->format('D');
    $days[] = ['num' => $d, 'date' => $date->format('Y-m-d'), 'weekday' => $weekday, 'is_today' => $date->format('Y-m-d') === $todayStr, 'is_weekend' => in_array($weekday, ['Sat', 'Sun'], true)];
}

$rooms = $reservations = $reservationsByRoom = [];
if ($isLodging) {
    $rooms = db_list_rooms_by_branch($branch);
    $roomIds = array_column($rooms, 'id');
    $rangeStart = $monthDate->format('Y-m-d');
    $rangeEnd = (clone $monthDate)->modify('+1 month')->format('Y-m-d');
    if (!empty($roomIds)) $reservations = db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd);
    foreach ($reservations as $r) {
        $checkIn = new DateTime($r['check_in']);
        $checkOut = new DateTime($r['check_out']);
        $monthEnd = new DateTime($rangeEnd);

        if ($checkIn > $monthDate) {
            $effectiveStart = clone $checkIn;
        } else {
            $effectiveStart = clone $monthDate;
        }

        if ($checkOut < $monthEnd) {
            $effectiveEnd = clone $checkOut;
        } else {
            $effectiveEnd = clone $monthEnd;
        }

        if ($effectiveStart >= $effectiveEnd) continue;

        $startOffset = (int) $monthDate->diff($effectiveStart)->days;
        $spanDays = max(1, (int) $effectiveStart->diff($effectiveEnd)->days);

        $r['_left_pct'] = round(($startOffset / $daysInMonth) * 100, 4);
        $r['_width_pct'] = round(($spanDays / $daysInMonth) * 100, 4);
        $reservationsByRoom[$r['room_id']][] = $r;
    }
}

// ─── Helper functions ──────────────────────────────────────────────
// NOTE: roomStatusLabel()/roomStatusKey() in the floor-layout pages
// (layout.php, layout_1st_floor.php, layout_2nd_floor.php) mirror
// cal_room_status_label()/cal_room_status_key() below EXACTLY, on
// purpose, so a room's status word/color always matches between the
// floor map and the calendar. If you change the logic here, update
// the mirrored copies there too.
function cal_room_status_label($room) {
    if ($room['room_status'] === 'maintenance') return 'Out of Order';
    if ($room['room_status'] === 'occupied') return 'Checked In';
    if ($room['room_status'] === 'reserved') return 'Reserved';
    return ($room['cleaning_status'] !== 'Clean') ? 'Vacant Dirty' : 'Vacant Clean';
}

function cal_room_status_key($room) {
    if ($room['room_status'] === 'available' && $room['cleaning_status'] !== 'Clean') return 'needs_cleaning';
    return $room['room_status'];
}

function cal_room_code($roomType) {
    static $known = [
        'Studio w/ Veranda' => 'STD-V',
        'Studio' => 'STD',
        'Family A 1BR w/ Veranda' => 'FAM-A',
        'Family B 1BR w/ Veranda' => 'FAM-B',
    ];
    if (isset($known[$roomType])) return $known[$roomType];
    $stop = ['w/', 'with', 'the', 'and', '1br'];
    $letters = '';
    foreach (preg_split('/\s+/', $roomType) as $word) {
        $clean = strtolower(trim($word, '/'));
        if ($clean === '' || in_array($clean, $stop, true)) continue;
        $letters .= strtoupper($word[0]);
    }
    return $letters !== '' ? substr($letters, 0, 4) : 'RM';
}

function cal_room_floor($roomNumber) {
    return $roomNumber !== '' ? substr($roomNumber, 0, 1) : '?';
}

function cal_room_category($roomType) {
    $t = strtolower($roomType);
    if (strpos($t, 'suite') !== false) return 'Suite';
    if (strpos($t, 'deluxe') !== false) return 'Deluxe';
    if (strpos($t, 'family') !== false) return 'Deluxe';
    return 'Standard';
}

// ─── Filter options ──────────────────────────────────────────────
$roomTypeOptions = [];
$floorOptions = [];
foreach ($rooms as $room) {
    $roomTypeOptions[$room['room_type']] = true;
    $floorOptions[cal_room_floor($room['room_number'])] = true;
}
$roomTypeOptions = array_keys($roomTypeOptions);
sort($roomTypeOptions);
$floorOptions = array_keys($floorOptions);
sort($floorOptions);

$statusLabels = ['reserved' => 'Reserved', 'checked_in' => 'Checked In', 'checked_out' => 'Checked Out', 'cancelled' => 'Cancelled'];
$paymentLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'bank_transfer' => 'Bank Transfer', 'card' => 'Credit/Debit Card'];
$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Calendar · <?= htmlspecialchars($branchName) ?> · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/dashboard.css"><link rel="stylesheet" href="../assets/css/calendar.css">
<style>.cal-page-main { flex:1; padding: clamp(20px,4vw,48px) clamp(16px,5vw,56px); max-width:1400px; margin:0 auto; width:100%; box-sizing:border-box; }</style>
</head>
<body class="dashboard-body">
<header class="topbar">
    <div class="topbar__brand"><span class="topbar__brand-mark">B</span><span class="topbar__brand-name">Bluebookers<span class="topbar__brand-suffix">.admin</span></span></div>
    <div class="topbar__right">
        <div class="topbar__user"><span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span><span class="topbar__user-role"><?= bb_is_admin() ? 'Admin' : 'Staff' ?></span></div>
        <a href="../logout.php" class="topbar__logout"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Log out</span></a>
        <button class="topbar__menu-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>
<?php include __DIR__ . '/includes/navbar.php'; ?>
<main class="cal-page-main">
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

    <?php if ($isLodging && !empty($rooms)): ?>
    <div class="cal-filter-bar" id="calFilterBar">
        <div class="cal-filter">
            <label for="filterRoomType">Room Type</label>
            <select id="filterRoomType">
                <option value="">All Types</option>
                <?php foreach ($roomTypeOptions as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cal-filter">
            <label for="filterRoomStatus">Room Status</label>
            <select id="filterRoomStatus">
                <option value="">All Statuses</option>
                <option value="available">Vacant Clean</option>
                <option value="needs_cleaning">Vacant Dirty</option>
                <option value="occupied">Checked In</option>
                <option value="reserved">Reserved</option>
                <option value="maintenance">Out of Order</option>
            </select>
        </div>
        <div class="cal-filter">
            <label for="filterFloor">Floor</label>
            <select id="filterFloor">
                <option value="">All Floors</option>
                <?php foreach ($floorOptions as $f): ?>
                    <option value="<?= htmlspecialchars($f) ?>">Floor <?= htmlspecialchars($f) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cal-filter">
            <label for="filterAvailability">Availability</label>
            <select id="filterAvailability">
                <option value="">All</option>
                <option value="1">Available Now</option>
                <option value="0">Unavailable Now</option>
            </select>
        </div>
        <div class="cal-filter">
            <label for="filterOccupancy">Occupancy (this month)</label>
            <select id="filterOccupancy">
                <option value="">All</option>
                <option value="1">Has Bookings</option>
                <option value="0">No Bookings</option>
            </select>
        </div>
        <button type="button" class="cal-filter-reset" id="calFilterReset">Reset Filters</button>
        <span class="cal-filter-count" id="calFilterCount"></span>
    </div>
    <?php endif; ?>

    <?php if (!$isLodging): ?>
        <div class="account-panel account-panel--centered" style="margin-top:20px;"><p style="font-size:1.2rem; font-weight:600; margin-bottom:0.5rem;">Calendar Not Applicable</p><p style="color:var(--ink-500);">Calendar functionality is not applicable to <strong><?= htmlspecialchars($branchName) ?></strong>.</p></div>
    <?php elseif (empty($rooms)): ?>
        <div class="account-panel account-panel--centered" style="margin-top:20px;"><p>No rooms have been set up for <?= htmlspecialchars($branchName) ?> yet.</p></div>
    <?php else: ?>
        <div class="cal-body">

            <!-- ── Left legend panel ──────────────────────────────── -->
            <aside class="cal-legend-panel">
                <p class="cal-legend-panel__heading">Legend</p>

                <div class="cal-legend-group">
                    <p class="cal-legend-group__label">Bookings</p>
                    <div class="cal-legend-item">
                        <span class="cal-legend-swatch cal-legend-swatch--reserved"></span>
                        <span>Reserved</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-swatch cal-legend-swatch--checked_in"></span>
                        <span>Checked In</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-swatch cal-legend-swatch--checked_out"></span>
                        <span>Checked Out</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-swatch cal-legend-swatch--cancelled"></span>
                        <span>Cancelled</span>
                    </div>
                </div>

                <div class="cal-legend-group">
                    <p class="cal-legend-group__label">Room Status</p>
                    <div class="cal-legend-item">
                        <span class="cal-legend-pip" style="background:#10b981;"></span>
                        <span>Vacant Clean</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-pip" style="background:#eab308;"></span>
                        <span>Vacant Dirty</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-pip" style="background:#ef4444;"></span>
                        <span>Maintenance</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-pip" style="background:#d97706;"></span>
                        <span>Occupied</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-pip" style="background:#3b82f6;"></span>
                        <span>Reserved</span>
                    </div>
                </div>

                <div class="cal-legend-group">
                    <p class="cal-legend-group__label">Indicators</p>
                    <div class="cal-legend-item">
                        <span class="cal-legend-today-chip"></span>
                        <span>Today</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-weekend-chip"></span>
                        <span>Weekend</span>
                    </div>
                </div>
            </aside>

            <!-- ── Calendar grid ──────────────────────────────────── -->
            <div class="cal-grid-area">
                <div class="cal-top-scroll" id="calTopScroll"><div class="cal-top-scroll__spacer" id="calTopScrollSpacer"></div></div>
                <div class="cal-grid-wrap">
                    <div class="cal-grid" style="--days: <?= $daysInMonth ?>;">

                        <!-- ===== HEADER ROW ===== -->
                        <div class="cal-header-row">
                            <!-- Sticky left block (header) -->
                            <div class="cal-label-col cal-label-col--header" data-room-id="">
                                <div class="cal-room-row cal-room-row--header">
                                    <span class="cal-room-name">Room</span>
                                    <span class="cal-status-pill cal-status-pill--header">Status</span>
                                    <span class="cal-room-number">Room No.</span>
                                    <span class="cal-room-category">Category</span>
                                </div>
                            </div>
                            <!-- Scrollable date headers -->
                            <div class="cal-days-track">
                                <?php foreach ($days as $day): ?>
                                    <div class="cal-day-header <?= $day['is_today'] ? 'is-today' : '' ?> <?= $day['is_weekend'] ? 'is-weekend' : '' ?>">
                                        <span class="cal-day-header__num"><?= $day['num'] ?></span>
                                        <span class="cal-day-header__wd"><?= $day['weekday'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ===== BODY ROWS =====
                             Every value below comes straight from $room, the SAME
                             array returned by db_list_rooms_by_branch() that the
                             floor-layout pages (layout.php / layout_1st_floor.php /
                             layout_2nd_floor.php) use to render their room cards.
                             room_type, room_number, room_status, cleaning_status,
                             and id are read here exactly as they are there — so the
                             calendar can never show a room name/number/status that
                             disagrees with what the floor map shows. -->
                        <?php foreach ($rooms as $room): ?>
                            <?php
                                $isMaintenance = ($room['room_status'] === 'maintenance');
                                $statusKey = cal_room_status_key($room);
                                $statusLabel = cal_room_status_label($room);
                                $category = cal_room_category($room['room_type']);
                                $hasBookingsThisMonth = !empty($reservationsByRoom[$room['id']]);
                            ?>
                            <div class="cal-row <?= $isMaintenance ? 'maintenance' : '' ?>"
                                 data-room-id="<?= $room['id'] ?>"
                                 data-room-type="<?= htmlspecialchars($room['room_type']) ?>"
                                 data-status-key="<?= htmlspecialchars($statusKey) ?>"
                                 data-floor="<?= htmlspecialchars(cal_room_floor($room['room_number'])) ?>"
                                 data-available="<?= $room['room_status'] === 'available' ? '1' : '0' ?>"
                                 data-has-bookings="<?= $hasBookingsThisMonth ? '1' : '0' ?>">

                                <!-- Sticky left block (body) -->
                                <div class="cal-label-col" data-room-id="<?= $room['id'] ?>" data-room-number="<?= htmlspecialchars($room['room_number']) ?>">
                                    <div class="cal-room-row">
                                        <span class="cal-room-name"><?= htmlspecialchars($room['room_type']) ?></span>
                                        <span class="cal-status-pill cal-status-pill--<?= htmlspecialchars($statusKey) ?>">
                                            <?= htmlspecialchars($statusLabel) ?>
                                        </span>
                                        <span class="cal-room-number">RM<?= htmlspecialchars($room['room_number']) ?></span>
                                        <span class="cal-room-category"><?= htmlspecialchars($category) ?></span>
                                    </div>
                                    <?php if ($isMaintenance): ?>
                                        <span class="maintenance-badge">⚠ Out of Order</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Scrollable day cells + reservation bars -->
                                <div class="cal-days-track cal-row__track" data-room-id="<?= $room['id'] ?>">
                                    <?php foreach ($days as $day): ?>
                                        <div class="cal-day-slot <?= $day['is_today'] ? 'is-today' : '' ?> <?= $day['is_weekend'] ? 'is-weekend' : '' ?>"
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
            </div><!-- /.cal-grid-area -->

        </div><!-- /.cal-body -->
    <?php endif; ?>
</main>
<div id="reservationModal" class="modal-overlay" hidden>
  <div class="modal modal--wide"><button class="modal__close" id="reservationModalClose">&times;</button><div id="reservationModalContent" class="modal__content"></div></div>
</div>
<script>window.BB_CALENDAR = { branch: <?= json_encode($branch) ?>, branchLabel: <?= json_encode($branchName) ?>, rooms: <?= json_encode($rooms) ?>, statusLabels: <?= json_encode($statusLabels) ?>, paymentLabels: <?= json_encode($paymentLabels) ?>, canDelete: <?= bb_is_admin() ? 'true' : 'false' ?>, csrfToken: <?= json_encode($_SESSION['csrf_token'] ?? '') ?> };</script>
<script src="../assets/js/dashboard.js" defer></script>
<script src="../assets/js/calendar.js" defer></script>
</body>
</html>