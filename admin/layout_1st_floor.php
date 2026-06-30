<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('rooms');

$branch = $_GET['branch'] ?? '';
if ($branch !== 'mtv') { include __DIR__ . '/layout_placeholder.php'; exit; }

$rooms = db_list_rooms_by_branch('mtv');

// FIX: Look back 12 months so long-stay guests whose check_in predates this
// month are included. Forward 3 months covers upcoming reservations.
// Previously used 'first day of this month' as rangeStart, which excluded any
// reservation with check_in before the 1st of the current month.
$reservationsByRoom = [];
if (!empty($rooms)) {
    $roomIds    = array_column($rooms, 'id');
    $rangeStart = (new DateTime('today'))->modify('-12 months')->format('Y-m-d');
    $rangeEnd   = (new DateTime('today'))->modify('+3 months')->format('Y-m-d');
    foreach (db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd) as $r)
        $reservationsByRoom[$r['room_id']][] = $r;
}

/**
 * Find the "current" reservation for a room:
 * - If there is an active reservation (checked_in) covering today, return it.
 * - Otherwise, return the earliest future reservation (check_in > today) that is not cancelled/checked_out.
 * - Return null if none.
 */
function rl_current_reservation($reservations) {
    $today = new DateTime('today');
    $future = null;
    foreach ($reservations as $r) {
        if (in_array($r['status'], ['cancelled', 'checked_out'], true)) continue;
        $checkIn = new DateTime($r['check_in']);
        $checkOut = new DateTime($r['check_out']);
        // Active today?
        if ($checkIn <= $today && $checkOut > $today) {
            return $r; // active reservation
        }
        // Future reservation (check_in > today)
        if ($checkIn > $today) {
            if ($future === null || $checkIn < new DateTime($future['check_in'])) {
                $future = $r;
            }
        }
    }
    return $future; // may be null
}

function rl_card($room, $currentRes) {
    $isDirty = ($room['cleaning_status'] !== 'Clean');
    $isMaintenance = ($room['room_status'] === 'maintenance');

    // Determine status
    if ($isMaintenance) {
        $status = 'maintenance';
        $statusLabel = 'Out of Order';
        $statusKey = 'maintenance';
    } elseif ($currentRes) {
        if ($currentRes['status'] === 'checked_in') {
            $status = 'occupied';
            $statusLabel = 'Occupied';
            $statusKey = 'occupied';
        } else {
            // reserved (future or today)
            $status = 'reserved';
            $statusLabel = 'Reserved';
            $statusKey = 'reserved';
        }
    } else {
        // No reservation – use cleaning status
        $status = 'available';
        $statusLabel = $isDirty ? 'Vacant Dirty' : 'Vacant Clean';
        $statusKey = $isDirty ? 'needs_cleaning' : 'available';
    }

    $dirtyClass = ($status === 'available' && $isDirty) ? ' room-card--dirty' : '';

    // Guest name
    $guestName = $currentRes ? trim($currentRes['guest_full_name']) : '';
    $guestDisplay = $guestName ?: 'No Guest Assigned';
    $guestClass = $guestName ? 'rc-guest-name' : 'rc-guest-name rc-guest-name--empty';

    // Room number
    $roomNum = 'ROOM ' . htmlspecialchars($room['room_number']);

    // Dates
    $datesHtml = '';
    if ($currentRes && !empty($currentRes['check_in']) && !empty($currentRes['check_out'])) {
        $ci = date('M d', strtotime($currentRes['check_in']));
        $co = date('M d, Y', strtotime($currentRes['check_out']));
        $datesHtml = '<div class="rc-dates">' . $ci . ' - ' . $co . '</div>';
    } elseif ($status === 'available') {
        $datesHtml = '<div class="rc-dates">' . ($isDirty ? 'Vacant Dirty' : 'Vacant Clean') . '</div>';
    }

    // Rate
    $rateHtml = '';
    if ($currentRes && !empty($currentRes['room_rate']) && (float)$currentRes['room_rate'] > 0) {
        $rateHtml = '<div class="rc-rate">Rate: ₱' . number_format((float)$currentRes['room_rate']) . '/month</div>';
    } elseif (!empty($room['price_per_night']) && (float)$room['price_per_night'] > 0) {
        // fallback to room price if no reservation
        $rateHtml = '<div class="rc-rate">Rate: ₱' . number_format((float)$room['price_per_night']) . '/month</div>';
    }

    // Duration (if reservation)
    $durationHtml = '';
    if ($currentRes && !empty($currentRes['check_in']) && !empty($currentRes['check_out'])) {
        $start = new DateTime($currentRes['check_in']);
        $end = new DateTime($currentRes['check_out']);
        $diff = $start->diff($end);
        $months = ($diff->y * 12) + $diff->m;
        $days = $diff->d;
        $parts = [];
        if ($months > 0) $parts[] = $months . ' Month' . ($months !== 1 ? 's' : '');
        if ($days > 0)   $parts[] = $days . ' Day' . ($days !== 1 ? 's' : '');
        $durationHtml = '<div class="rc-duration">' . implode(' ', $parts) . '</div>';
    }

    // Room type
    $typeHtml = '<div class="rc-room-type">' . htmlspecialchars($room['room_type']) . '</div>';

    // Build card
    $card = '<div class="room-card status-' . $status . $dirtyClass . '"
         data-room-id="' . $room['id'] . '"
         data-room-number="' . htmlspecialchars($room['room_number']) . '"
         data-status="' . $status . '"
         data-status-key="' . $statusKey . '"
         data-type-main="' . htmlspecialchars($room['room_type']) . '"
         data-guest-name="' . htmlspecialchars($guestName) . '"
         data-check-in="' . ($currentRes ? htmlspecialchars($currentRes['check_in']) : '') . '"
         data-check-out="' . ($currentRes ? htmlspecialchars($currentRes['check_out']) : '') . '"
         data-price="' . ($currentRes ? htmlspecialchars($currentRes['room_rate']) : htmlspecialchars($room['price_per_night'])) . '"
         data-cleaning="' . htmlspecialchars($room['cleaning_status']) . '"
         data-maintenance="' . htmlspecialchars($room['maintenance_status']) . '"
         data-last-occupancy="' . htmlspecialchars($room['last_occupancy'] ?? '') . '"
         data-notes="' . htmlspecialchars($room['staff_notes'] ?? '') . '"
         data-reservation-id="' . ($currentRes ? $currentRes['id'] : '') . '">
        <div class="rc-body">
            <p class="' . $guestClass . '" title="' . htmlspecialchars($guestDisplay) . '">' . htmlspecialchars($guestDisplay) . '</p>
            <div class="rc-room-num">' . $roomNum . '</div>
            ' . $datesHtml . '
            ' . $durationHtml . '
            ' . $rateHtml . '
            ' . $typeHtml . '
        </div>
    </div>';
    return $card;
}

// Group rooms by columns for 1st floor
$leftRooms  = ['101','102','103'];
$rightRooms = ['104','105','106'];
$columns    = ['left' => [], 'right' => []];

foreach ($rooms as $room) {
    $num = $room['room_number'];
    $reservations = $reservationsByRoom[$room['id']] ?? [];
    $currentRes = rl_current_reservation($reservations);
    if (in_array($num, $leftRooms, true)) {
        $columns['left'][] = ['room' => $room, 'res' => $currentRes];
    } elseif (in_array($num, $rightRooms, true)) {
        $columns['right'][] = ['room' => $room, 'res' => $currentRes];
    }
}

$legendItems = [
    ['key' => 'reserved',     'label' => 'Reserved',     'type' => 'reservation', 'color' => '#fbbf24'],
    ['key' => 'checked_in',   'label' => 'Checked In',   'type' => 'reservation', 'color' => '#34d399'],
    ['key' => 'checked_out',  'label' => 'Checked Out',  'type' => 'reservation', 'color' => '#94a3b8'],
    ['key' => 'cancelled',    'label' => 'Cancelled',    'type' => 'reservation', 'color' => '#f87171'],
    ['key' => 'available',    'label' => 'Vacant Clean', 'type' => 'room', 'color' => '#10b981'],
    ['key' => 'needs_cleaning','label' => 'Vacant Dirty', 'type' => 'room', 'color' => '#f97316'],
    ['key' => 'occupied',     'label' => 'Occupied',     'type' => 'room', 'color' => '#6b7280'],
    ['key' => 'maintenance',  'label' => 'Out of Order', 'type' => 'room', 'color' => '#ef4444'],
];

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>1st Floor Layout · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/layout.css">
<link rel="stylesheet" href="../assets/css/rl.css">
</head>
<body class="dashboard-body" data-admin="true">

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
            <svg viewBox="0 0 24 24" fill="none"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>

<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<!-- ── Unified layout shell ──────────────────────────────────────── -->
<div class="rl-page">

    <aside class="rl-sidebar" id="rlSidebar">
        <div class="rl-sidebar__inner">
            <div class="rl-sidebar__head">Legend</div>

            <div class="rl-legend-group">
                <div class="rl-legend-group__label">Reservation Status</div>
                <?php foreach ($legendItems as $item): ?>
                    <?php if ($item['type'] === 'reservation'): ?>
                        <div class="rl-legend-item" data-rl-filter="<?= $item['key'] ?>">
                            <span class="rl-legend-swatch rl-legend-swatch--<?= $item['key'] ?>"></span>
                            <span><?= $item['label'] ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="rl-legend-group">
                <div class="rl-legend-group__label">Room Status</div>
                <?php foreach ($legendItems as $item): ?>
                    <?php if ($item['type'] === 'room'): ?>
                        <div class="rl-legend-item" data-rl-filter="<?= $item['key'] ?>">
                            <span class="rl-legend-pip" style="background:<?= $item['color'] ?>;"></span>
                            <span><?= $item['label'] ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="rl-legend-divider"></div>
            <div class="rl-sidebar__footer" id="rlCount"><?= count($rooms) ?> rooms</div>
        </div>
    </aside>

    <main class="rl-main">

        <div class="rl-heading">
            <p class="rl-eyebrow">Interactive Map</p>
            <h1 class="rl-title">1st Floor Layout</h1>
            <div class="rl-floor-switch">
                <a href="layout_1st_floor.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn is-active">1st Floor</a>
                <a href="layout_2nd_floor.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn">2nd Floor</a>
                <a href="layout.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn">3rd Floor</a>
            </div>
        </div>

        <?php if (empty($rooms)): ?>
            <p style="color:#8dafc8;padding:24px 0;">No rooms configured for this floor.</p>
        <?php else: ?>
        <div class="rl-floor-plan elegant-floor-container">
            <div class="ef-zone ef-zone--parking">
                <span class="ef-vertical-text ef-up">PARKING</span>
                <div class="ef-divider"></div>
            </div>
            <?php $ci = 0; foreach ($columns as $cols): ?>
                <div class="ef-room-col">
                    <?php foreach ($cols as $data): echo rl_card($data['room'], $data['res']); endforeach; ?>
                </div>
                <?php if ($ci < 1): ?>
                <div class="ef-zone ef-zone--hallway">
                    <svg class="ef-arrow ef-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                    <span class="ef-vertical-text ef-up">HALLWAY</span>
                    <svg class="ef-arrow ef-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                </div>
                <?php endif; $ci++; endforeach; ?>
            <div class="ef-zone ef-zone--rightside">
                <div class="ef-divider"></div>
                <span class="ef-vertical-text ef-down">RIGHTSIDE</span>
            </div>
        </div>
        <?php endif; ?>

    </main>

</div>

<div id="roomModal" class="modal-overlay" hidden>
    <div class="modal">
        <button class="modal__close" id="modalClose">&times;</button>
        <div id="modalContent" class="modal__content"></div>
    </div>
</div>

<script>
window.BB_LAYOUT_ROOMS = <?= json_encode(array_map(fn($r) => [
    'id'              => $r['id'],
    'room_number'     => $r['room_number'],
    'room_type'       => $r['room_type'],
    'price_per_night' => $r['price_per_night'],
    'room_status'     => $r['room_status'],
    'cleaning_status' => $r['cleaning_status'],
], $rooms)) ?>;

<?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
window.BB_CALENDAR = {
    branch: <?= json_encode($branch) ?>,
    branchLabel: <?= json_encode(['mtv'=>'MTV3','annex'=>'BB Apartelle','dormitel'=>'ELTI Dormitel'][$branch] ?? $branch) ?>,
    rooms: window.BB_LAYOUT_ROOMS,
    statusLabels: {"pending":"Pending","reserved":"Reserved","checked_in":"Checked In","checked_out":"Checked Out","cancelled":"Cancelled"},
    paymentLabels: {"cash":"Cash","gcash":"GCash","bank_transfer":"Bank Transfer","card":"Card"},
    canDelete: <?= bb_is_admin() ? 'true' : 'false' ?>,
    csrfToken: <?= json_encode($_SESSION['csrf_token']) ?>
};
</script>
<script src="../assets/js/dashboard.js" defer></script>
<script src="../assets/js/layout.js" defer></script>
<script src="../assets/js/realtime-room-sync.js" defer></script>
</body>
</html>