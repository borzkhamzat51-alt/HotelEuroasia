<?php
/**
 * process_layout_poll.php
 * AJAX endpoint — returns current room states + active reservations.
 * Called every 30 s by realtime-room-sync.js to keep all floor layout
 * pages in sync with Calendar, Reports, and Reservation modules.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('rooms');

header('Content-Type: application/json');

$branch = trim($_GET['branch'] ?? 'mtv');
$validBranches = ['annex','mtv','dormitel','aps','euroasia_stall','annex_stall'];
if (!in_array($branch, $validBranches, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid branch']);
    exit;
}

try {
    $rooms = db_list_rooms_by_branch($branch);
    if (empty($rooms)) {
        echo json_encode(['success' => true, 'rooms' => [], 'reservations' => []]);
        exit;
    }

    $roomIds    = array_column($rooms, 'id');
    $monthDate  = new DateTime('first day of this month');
    $rangeStart = $monthDate->format('Y-m-d');
    $rangeEnd   = (clone $monthDate)->modify('+2 months')->format('Y-m-d');
    $allRes     = db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd);

    // Build active reservation map (room_id → reservation)
    $activeByRoom = [];
    foreach ($allRes as $r) {
        if (in_array($r['status'], ['cancelled', 'checked_out'], true)) continue;
        // Only overwrite if no active res yet, or prefer checked_in over reserved
        $rid = $r['room_id'];
        if (!isset($activeByRoom[$rid]) || $r['status'] === 'checked_in') {
            $activeByRoom[$rid] = $r;
        }
    }

    // Build response arrays
    $roomsOut = [];
    foreach ($rooms as $room) {
        $roomsOut[] = [
            'id'               => $room['id'],
            'room_number'      => $room['room_number'],
            'room_type'        => $room['room_type'],
            'price_per_night'  => $room['price_per_night'],
            'room_status'      => $room['room_status'],
            'cleaning_status'  => $room['cleaning_status'],
            'maintenance_status'=> $room['maintenance_status'] ?? '',
            'last_occupancy'   => $room['last_occupancy'] ?? '',
        ];
    }

    $resvOut = [];
    foreach ($activeByRoom as $roomId => $r) {
        $resvOut[] = [
            'room_id'         => $r['room_id'],
            'status'          => $r['status'],
            'guest_full_name' => $r['guest_full_name'] ?? '',
            'check_in'        => $r['check_in']  ?? '',
            'check_out'       => $r['check_out'] ?? '',
            'num_adults'      => $r['num_adults'] ?? '',
            'contact_number'  => $r['contact_number'] ?? '',
            'email'           => $r['email'] ?? '',
        ];
    }

    echo json_encode([
        'success'      => true,
        'rooms'        => $roomsOut,
        'reservations' => $resvOut,
        'polled_at'    => date('H:i:s'),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error']);
}