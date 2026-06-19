<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('rooms');

$branch = $_GET['branch'] ?? 'mtv';
if ($branch !== 'mtv') {
    include __DIR__ . '/layout_placeholder.php';
    exit;
}

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

function formatDateDisplay($checkIn, $checkOut, $status, $isDirty = false)
{
    if ($status === 'available') return $isDirty ? 'Needs Cleaning' : 'Vacant - Ready';
    if ($status === 'maintenance') return 'Out of Order';
    if (!empty($checkIn) && !empty($checkOut)) {
        return date('M d', strtotime($checkIn)) . ' - ' . date('M d', strtotime($checkOut));
    }
    return '—';
}

// 3rd floor columns
$leftRooms = ['303', '302', '301'];
$middleRooms = ['306', '305', '304'];
$rightRooms = ['310', '309', '308', '307'];
$columns = ['left' => [], 'middle' => [], 'right' => []];

foreach ($rooms as $room) {
    $num = $room['room_number'];
    $resList = $reservationsByRoom[$room['id']] ?? [];
    $activeRes = null;
    foreach ($resList as $r) {
        if (!in_array($r['status'], ['cancelled', 'checked_out'], true)) {
            $activeRes = $r;
            break;
        }
    }
    $roomData = [
        'id' => $room['id'],
        'number' => $room['room_number'],
        'type_main' => $room['room_type'],
        'type_sub' => '',
        'status' => $room['room_status'],
        'price' => $room['price_per_night'],
        'guest_name' => $activeRes ? $activeRes['guest_full_name'] : '',
        'check_in' => $activeRes ? $activeRes['check_in'] : '',
        'check_out' => $activeRes ? $activeRes['check_out'] : '',
        'cleaning' => $room['cleaning_status'],
        'maintenance_status' => $room['maintenance_status'],
        'last_occupancy' => $room['last_occupancy'],
        'notes' => $room['staff_notes'] ?? '',
        'is_dirty' => $room['cleaning_status'] !== 'Clean',
    ];
    if (in_array($num, $leftRooms, true)) {
        $columns['left'][] = $roomData;
    } elseif (in_array($num, $middleRooms, true)) {
        $columns['middle'][] = $roomData;
    } elseif (in_array($num, $rightRooms, true)) {
        $columns['right'][] = $roomData;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>3rd Floor Plan · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/property.css"><link rel="stylesheet" href="../assets/css/layout.css">
</head>
<body class="property-body" data-admin="true">
<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg> Dashboard</a>
    <div class="ptopbar__breadcrumb"><a href="#">Properties</a><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg><span aria-current="page">3rd Floor</span></div>
    <a href="../logout.php" class="ptopbar__logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg> Log out</a>
</header>
<main class="property-main layout-main-override">
    <div class="property-heading">
        <p class="property-heading__eyebrow">Interactive Map</p>
        <h1 class="property-heading__title">3rd Floor Layout</h1>
        <div style="margin-top:24px; display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
            <a href="layout_1st_floor.php?branch=mtv" class="btn" style="width:auto; padding:10px 24px; background:var(--white); border:2px solid var(--sky-200); color:var(--blue-700);">1st Floor</a>
            <a href="layout_2nd_floor.php?branch=mtv" class="btn" style="width:auto; padding:10px 24px; background:var(--white); border:2px solid var(--sky-200); color:var(--blue-700);">2nd Floor</a>
            <a href="layout.php?branch=mtv" class="btn" style="width:auto; padding:10px 24px; background:var(--blue-500); border:2px solid var(--blue-500); color:var(--white);">3rd Floor</a>
        </div>
    </div>
    <?php if (empty($rooms)): ?>
        <div class="account-panel account-panel--centered" style="margin-top:20px;">
            <p>No rooms have been set up for this floor yet.</p>
        </div>
    <?php else: ?>
    <div class="elegant-floor-container">
        <div class="ef-zone ef-zone--parking"><span class="ef-vertical-text ef-up">PARKING</span><div class="ef-divider"></div></div>
        <?php $colCount = 0; foreach ($columns as $colName => $columnRooms): ?>
            <div class="ef-room-col">
                <?php foreach ($columnRooms as $room): ?>
                    <div class="room-card status-<?= htmlspecialchars($room['status']) ?><?= ($room['status'] === 'available' && $room['is_dirty']) ? ' room-card--dirty' : '' ?>"
                         data-room-id="<?= htmlspecialchars($room['id']) ?>"
                         data-room-number="<?= htmlspecialchars($room['number']) ?>"
                         data-status="<?= htmlspecialchars($room['status']) ?>"
                         data-type-main="<?= htmlspecialchars($room['type_main']) ?>"
                         data-type-sub="<?= htmlspecialchars($room['type_sub']) ?>"
                         data-guest-name="<?= htmlspecialchars($room['guest_name']) ?>"
                         data-check-in="<?= htmlspecialchars($room['check_in']) ?>"
                         data-check-out="<?= htmlspecialchars($room['check_out']) ?>"
                         data-price="<?= htmlspecialchars($room['price']) ?>"
                         data-cleaning="<?= htmlspecialchars($room['cleaning']) ?>"
                         data-maintenance="<?= htmlspecialchars($room['maintenance_status']) ?>"
                         data-last-occupancy="<?= htmlspecialchars($room['last_occupancy'] ?? '') ?>"
                         data-notes="<?= htmlspecialchars($room['notes']) ?>">
                        <div class="rc-content">
                            <h3 class="rc-title"><?= htmlspecialchars($room['type_main']) ?></h3>
                            <span class="rc-subtitle"><?= htmlspecialchars($room['type_sub']) ?></span>
                            <div class="rc-dates"><?= htmlspecialchars(formatDateDisplay($room['check_in'], $room['check_out'], $room['status'], $room['is_dirty'])) ?></div>
                            <div class="rc-price"><?= $room['price'] ? '₱' . number_format($room['price']) : '--' ?></div>
                            <?php if (!empty($room['guest_name'])): ?>
                                <div class="rc-guest" style="font-weight:600; font-size:0.9rem; color:var(--blue-700); margin-top:4px;"><?= htmlspecialchars($room['guest_name']) ?></div>
                            <?php endif; ?>
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
<footer class="property-footer">...</footer>
<div id="roomModal" class="modal-overlay" hidden><div class="modal"><button class="modal__close" id="modalClose">&times;</button><div id="modalContent" class="modal__content"></div></div></div>
<script src="../assets/js/layout.js" defer></script>
</body>
</html>