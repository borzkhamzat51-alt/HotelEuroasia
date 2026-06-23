<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('rooms');

$branch = $_GET['branch'] ?? 'mtv';
if ($branch !== 'mtv') { include __DIR__ . '/layout_placeholder.php'; exit; }

$rooms = db_list_rooms_by_branch('mtv');
$reservations = [];
if (!empty($rooms)) {
    $roomIds = array_column($rooms, 'id');
    $monthDate = new DateTime('first day of this month');
    $rangeStart = $monthDate->format('Y-m-d');
    $rangeEnd = (clone $monthDate)->modify('+1 month')->format('Y-m-d');
    $reservations = db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd);
}
$reservationsByRoom = [];
foreach ($reservations as $r) {
    $reservationsByRoom[$r['room_id']][] = $r;
}

function formatDateDisplay($checkIn, $checkOut, $status, $isDirty = false) {
    if (!empty($checkIn) && !empty($checkOut)) {
        return date('M d', strtotime($checkIn)) . ' - ' . date('M d', strtotime($checkOut));
    }
    if ($status === 'available') return $isDirty ? 'Vacant Dirty' : 'Vacant Clean';
    return '';
}

function roomStatusLabel($status, $isDirty = false) {
    if ($status === 'maintenance') return 'Out of Order';
    if ($status === 'occupied') return 'Checked In';
    if ($status === 'reserved') return 'Reserved';
    return $isDirty ? 'Vacant Dirty' : 'Vacant Clean';
}

function roomStatusKey($status, $isDirty = false) {
    if ($status === 'available' && $isDirty) return 'needs_cleaning';
    return $status;
}

// 3rd floor: left = 303,302,301 ; middle = 306,305,304 ; right = 310,309,308,307
$leftRooms = ['303','302','301'];
$middleRooms = ['306','305','304'];
$rightRooms = ['310','309','308','307'];
$columns = ['left' => [], 'middle' => [], 'right' => []];

foreach ($rooms as $room) {
    $num = $room['room_number'];
    $resList = $reservationsByRoom[$room['id']] ?? [];
    $activeRes = null;
    foreach ($resList as $r) {
        if (!in_array($r['status'], ['cancelled','checked_out'], true)) {
            $activeRes = $r; break;
        }
    }
    $roomData = [
        'id' => $room['id'],
        'number' => $room['room_number'],
        'type_main' => $room['room_type'],
        'type_sub' => '',
        'status' => $room['room_status'],
        'price' => $room['price_per_night'],
        'guest_name' => ($activeRes && $room['room_status'] !== 'available') ? $activeRes['guest_full_name'] : '',
        'check_in' => $activeRes ? $activeRes['check_in'] : '',
        'check_out' => $activeRes ? $activeRes['check_out'] : '',
        'phone' => $activeRes ? $activeRes['contact_number'] : '',
        'email' => $activeRes ? $activeRes['email'] : '',
        'pax' => $activeRes ? $activeRes['num_adults'] : '',
        'cleaning' => $room['cleaning_status'],
        'maintenance_status' => $room['maintenance_status'],
        'last_occupancy' => $room['last_occupancy'],
        'notes' => $room['staff_notes'] ?? '',
        'is_dirty' => $room['cleaning_status'] !== 'Clean',
    ];
    if (in_array($num, $leftRooms, true)) $columns['left'][] = $roomData;
    elseif (in_array($num, $middleRooms, true)) $columns['middle'][] = $roomData;
    elseif (in_array($num, $rightRooms, true)) $columns['right'][] = $roomData;
}

$branches = [
    'annex' => 'BB Apartelle',
    'mtv' => 'MTV3',
    'dormitel' => 'ELTI Dormitel',
];
$branchName = $branches[$branch] ?? ucfirst($branch);

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>3rd Floor Plan · <?= htmlspecialchars($branchName) ?> · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/dashboard.css"><link rel="stylesheet" href="../assets/css/layout.css">
<style>
.layout-main { flex:1; padding: clamp(20px,4vw,48px) clamp(16px,5vw,56px); max-width:1400px; margin:0 auto; width:100%; box-sizing:border-box; }
.floor-switch { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; margin-top:24px; }
.floor-switch .btn { width:auto; padding:10px 28px; border-radius:999px; font-weight:600; transition:all 220ms cubic-bezier(.16,1,.3,1); text-decoration:none; cursor:pointer; border:2px solid transparent; font-family:'Inter',sans-serif; font-size:0.85rem; }
.floor-switch .btn--floor { background:var(--white); border-color:var(--sky-200); color:var(--blue-700); }
.floor-switch .btn--floor:hover { transform:translateY(-2px); box-shadow:0 8px 24px -8px rgba(28,70,130,0.2); border-color:var(--blue-300); }
.floor-switch .btn--floor:active { transform:scale(0.96); }
.floor-switch .btn--active { background:var(--blue-500); border-color:var(--blue-500); color:var(--white); box-shadow:0 4px 16px -4px rgba(59,125,216,0.35); }
.floor-switch .btn--active:hover { background:var(--blue-600); border-color:var(--blue-600); transform:translateY(-2px); }
</style>
</head>
<body class="dashboard-body" data-admin="true">

<header class="topbar">
    <div class="topbar__brand"><span class="topbar__brand-mark">B</span><span class="topbar__brand-name">Bluebookers<span class="topbar__brand-suffix">.admin</span></span></div>
    <div class="topbar__right">
        <div class="topbar__user"><span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span><span class="topbar__user-role"><?= bb_is_admin() ? 'Admin' : 'Staff' ?></span></div>
        <a href="../logout.php" class="topbar__logout"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg><span>Log out</span></a>
        <button class="topbar__menu-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>

<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<main class="layout-main">
    <div class="property-heading">
        <p class="property-heading__eyebrow">Interactive Map</p>
        <h1 class="property-heading__title">3rd Floor Layout</h1>
        <div class="floor-switch">
            <a href="layout_1st_floor.php?branch=mtv" class="btn btn--floor">1st Floor</a>
            <a href="layout_2nd_floor.php?branch=mtv" class="btn btn--floor">2nd Floor</a>
            <a href="layout.php?branch=mtv" class="btn btn--floor btn--active">3rd Floor</a>
        </div>
    </div>
    <?php if (empty($rooms)): ?>
        <div class="account-panel account-panel--centered" style="margin-top:20px;"><p>No rooms have been set up for this floor yet.</p></div>
    <?php else: ?>
    <div class="elegant-floor-container">
        <div class="ef-zone ef-zone--parking"><span class="ef-vertical-text ef-up">PARKING</span><div class="ef-divider"></div></div>
        <?php $colCount = 0; foreach ($columns as $colName => $columnRooms): ?>
            <div class="ef-room-col">
                <?php foreach ($columnRooms as $room): ?>
                    <div class="room-card status-<?= htmlspecialchars($room['status']) ?><?= ($room['status'] === 'available' && $room['is_dirty']) ? ' room-card--dirty' : '' ?>"
                         title="RM<?= htmlspecialchars($room['number']) ?> — <?= htmlspecialchars(formatDateDisplay($room['check_in'], $room['check_out'], $room['status'], $room['is_dirty'])) ?>"
                         data-room-id="<?= htmlspecialchars($room['id']) ?>"
                         data-room-number="<?= htmlspecialchars($room['number']) ?>"
                         data-status="<?= htmlspecialchars($room['status']) ?>"
                         data-type-main="<?= htmlspecialchars($room['type_main']) ?>"
                         data-type-sub="<?= htmlspecialchars($room['type_sub']) ?>"
                         data-guest-name="<?= htmlspecialchars($room['guest_name']) ?>"
                         data-check-in="<?= htmlspecialchars($room['check_in']) ?>"
                         data-check-out="<?= htmlspecialchars($room['check_out']) ?>"
                         data-price="<?= htmlspecialchars($room['price']) ?>"
                         data-phone="<?= htmlspecialchars($room['phone']) ?>"
                         data-email="<?= htmlspecialchars($room['email']) ?>"
                         data-pax="<?= htmlspecialchars($room['pax']) ?>"
                         data-cleaning="<?= htmlspecialchars($room['cleaning']) ?>"
                         data-maintenance="<?= htmlspecialchars($room['maintenance_status']) ?>"
                         data-last-occupancy="<?= htmlspecialchars($room['last_occupancy'] ?? '') ?>"
                         data-notes="<?= htmlspecialchars($room['notes']) ?>">
                        <div class="rc-content">
                            <h3 class="rc-title"><?= htmlspecialchars($room['type_main']) ?></h3>
                            <span class="rc-subtitle"><?= htmlspecialchars($room['type_sub']) ?></span>
                            <div class="rc-dates"><?= htmlspecialchars(formatDateDisplay($room['check_in'], $room['check_out'], $room['status'], $room['is_dirty'])) ?></div>
                            <div class="rc-price"><?= $room['price'] ? '₱' . number_format($room['price']) : '--' ?></div>
                            <div class="rc-guest"><?= htmlspecialchars($room['guest_name']) ?></div>
                        </div>
                        <div class="rc-footer">RM <?= htmlspecialchars($room['number']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($colCount < 2): ?>
                <div class="ef-zone ef-zone--hallway">
                    <svg class="ef-arrow ef-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                    <span class="ef-vertical-text ef-up">HALLWAY</span>
                    <svg class="ef-arrow ef-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                </div>
            <?php endif; ?>
        <?php $colCount++; endforeach; ?>
        <div class="ef-zone ef-zone--rightside"><div class="ef-divider"></div><span class="ef-vertical-text ef-down">RIGHTSIDE</span></div>
    </div>
    <?php endif; ?>
</main>

<div id="roomModal" class="modal-overlay" hidden><div class="modal"><button class="modal__close" id="modalClose">&times;</button><div id="modalContent" class="modal__content"></div></div></div>

<script src="../assets/js/dashboard.js" defer></script>
<script>window.BB_LAYOUT_ROOMS = <?= json_encode(array_map(function($r) {
    return ['id' => $r['id'], 'room_number' => $r['room_number'], 'room_type' => $r['room_type'], 'price_per_night' => $r['price_per_night'], 'room_status' => $r['room_status'], 'cleaning_status' => $r['cleaning_status']];
}, $rooms)) ?>;</script>
<script src="../assets/js/layout.js" defer></script>
<script src="../assets/js/realtime-room-sync.js" defer></script>
</body>
</html>