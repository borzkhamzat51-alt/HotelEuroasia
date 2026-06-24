<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('rooms');

$branch = $_GET['branch'] ?? 'mtv';
if ($branch !== 'mtv') { include __DIR__ . '/layout_placeholder.php'; exit; }

$rooms = db_list_rooms_by_branch('mtv');
$reservationsByRoom = [];
if (!empty($rooms)) {
    $roomIds    = array_column($rooms, 'id');
    $monthDate  = new DateTime('first day of this month');
    $rangeStart = $monthDate->format('Y-m-d');
    $rangeEnd   = (clone $monthDate)->modify('+1 month')->format('Y-m-d');
    foreach (db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd) as $r)
        $reservationsByRoom[$r['room_id']][] = $r;
}

function rl_active_res($list) {
    foreach ($list as $r)
        if (!in_array($r['status'], ['cancelled','checked_out'], true)) return $r;
    return null;
}
function rl_dates($ci, $co, $status, $dirty) {
    if ($ci && $co) return date('M d', strtotime($ci)) . ' - ' . date('M d', strtotime($co));
    if ($status === 'available') return $dirty ? 'Vacant Dirty' : 'Vacant Clean';
    return '';
}
function rl_room_data($room, $activeRes) {
    return [
        'id'                 => $room['id'],
        'number'             => $room['room_number'],
        'type_main'          => $room['room_type'],
        'type_sub'           => '',
        'status'             => $room['room_status'],
        'price'              => $room['price_per_night'],
        'guest_name'         => ($activeRes && $room['room_status'] !== 'available') ? $activeRes['guest_full_name'] : '',
        'check_in'           => $activeRes ? $activeRes['check_in']       : '',
        'check_out'          => $activeRes ? $activeRes['check_out']      : '',
        'phone'              => $activeRes ? $activeRes['contact_number'] : '',
        'email'              => $activeRes ? $activeRes['email']          : '',
        'pax'                => $activeRes ? $activeRes['num_adults']     : '',
        'cleaning'           => $room['cleaning_status'],
        'maintenance_status' => $room['maintenance_status'],
        'last_occupancy'     => $room['last_occupancy'],
        'notes'              => $room['staff_notes'] ?? '',
        'is_dirty'           => $room['cleaning_status'] !== 'Clean',
    ];
}
function rl_card($room) {
    $dirty = ($room['status'] === 'available' && $room['is_dirty']) ? ' room-card--dirty' : '';
    $dates = htmlspecialchars(rl_dates($room['check_in'], $room['check_out'], $room['status'], $room['is_dirty']));
    return '<div class="room-card status-' . htmlspecialchars($room['status']) . $dirty . '"
         data-room-id="'        . htmlspecialchars($room['id'])                   . '"
         data-room-number="'    . htmlspecialchars($room['number'])               . '"
         data-status="'         . htmlspecialchars($room['status'])               . '"
         data-type-main="'      . htmlspecialchars($room['type_main'])            . '"
         data-type-sub="'       . htmlspecialchars($room['type_sub'])             . '"
         data-guest-name="'     . htmlspecialchars($room['guest_name'])           . '"
         data-check-in="'       . htmlspecialchars($room['check_in'])             . '"
         data-check-out="'      . htmlspecialchars($room['check_out'])            . '"
         data-price="'          . htmlspecialchars($room['price'])                . '"
         data-phone="'          . htmlspecialchars($room['phone'])                . '"
         data-email="'          . htmlspecialchars($room['email'])                . '"
         data-pax="'            . htmlspecialchars($room['pax'])                  . '"
         data-cleaning="'       . htmlspecialchars($room['cleaning'])             . '"
         data-maintenance="'    . htmlspecialchars($room['maintenance_status'])   . '"
         data-last-occupancy="' . htmlspecialchars($room['last_occupancy'] ?? '') . '"
         data-notes="'          . htmlspecialchars($room['notes'])                . '">
        <div class="rc-content">
            <h3 class="rc-title">'      . htmlspecialchars($room['type_main'])  . '</h3>
            <span class="rc-subtitle">' . htmlspecialchars($room['type_sub'])   . '</span>
            <div class="rc-dates">'     . $dates                                . '</div>
            <div class="rc-price">'     . ($room['price'] ? '₱' . number_format($room['price']) : '--') . '</div>
            <div class="rc-guest">'     . htmlspecialchars($room['guest_name']) . '</div>
        </div>
        <div class="rc-footer">RM ' . htmlspecialchars($room['number']) . '</div>
    </div>';
}

// 3rd floor columns — same structure as 2nd floor
$leftRooms   = ['303','302','301'];
$middleRooms = ['306','305','304'];
$rightRooms  = ['310','309','308','307'];
$columns     = ['left' => [], 'middle' => [], 'right' => []];

foreach ($rooms as $room) {
    $num       = $room['room_number'];
    $activeRes = rl_active_res($reservationsByRoom[$room['id']] ?? []);
    $rd        = rl_room_data($room, $activeRes);
    if (in_array($num, $leftRooms, true))        $columns['left'][]   = $rd;
    elseif (in_array($num, $middleRooms, true))  $columns['middle'][] = $rd;
    elseif (in_array($num, $rightRooms, true))   $columns['right'][]  = $rd;
}

$legendItems = [
    ['key'=>'available',      'label'=>'Vacant Clean', 'color'=>'#10b981'],
    ['key'=>'needs_cleaning', 'label'=>'Vacant Dirty', 'color'=>'#f97316'],
    ['key'=>'occupied',       'label'=>'Occupied',     'color'=>'#6b7280'],
    ['key'=>'reserved',       'label'=>'Reserved',     'color'=>'#2563eb'],
    ['key'=>'maintenance',    'label'=>'Out of Order', 'color'=>'#ef4444'],
];

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>3rd Floor Plan · Bluebookers</title>
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

<!-- ── Page heading ──────────────────────────────────────── -->
<div class="rl-heading">
    <p class="rl-eyebrow">Interactive Map</p>
    <h1 class="rl-title">3rd Floor Layout</h1>
    <div class="rl-floor-switch">
        <a href="layout_1st_floor.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn">1st Floor</a>
        <a href="layout_2nd_floor.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn">2nd Floor</a>
        <a href="layout.php?branch=<?= urlencode($branch) ?>" class="rl-floor-btn is-active">3rd Floor</a>
    </div>
</div>

<!-- ══ TWO-COLUMN PAGE ══════════════════════════════════════ -->
<div class="rl-page">

    <!-- ── SIDEBAR ──────────────────────────────────────────── -->
    <aside class="rl-sidebar" id="rlSidebar">
        <div class="rl-sidebar__inner">
        <div class="rl-sidebar__head">Legend</div>
        <div class="rl-legend-group-label">Room Status</div>
        <?php foreach ($legendItems as $item): ?>
        <div class="rl-legend-item" data-rl-filter="<?= $item['key'] ?>">
            <span class="rl-legend-swatch rl-legend-swatch--<?= $item['key'] ?>"></span>
            <span><?= $item['label'] ?></span>
        </div>
        <?php endforeach; ?>
        <div class="rl-legend-divider"></div>
        <div class="rl-legend-showall" data-rl-filter="all">&#8635; Show All</div>
        <div class="rl-sidebar__footer" id="rlCount"><?= count($rooms) ?> rooms</div>
        </div>
    </aside>

    <!-- ── MAIN: elegant floor plan ─────────────────────────── -->
    <main class="rl-main">
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
                    <?php foreach ($cols as $room): echo rl_card($room); endforeach; ?>
                </div>
                <?php if ($ci < 2): ?>
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

</div><!-- /.rl-page -->

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
</script>
<script src="../assets/js/dashboard.js" defer></script>
<script src="../assets/js/layout.js" defer></script>
<script src="../assets/js/realtime-room-sync.js" defer></script>
<script src="../assets/js/layout-legend.js" defer></script>
</body>
</html>