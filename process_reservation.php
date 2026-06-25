<?php
/**
 * process_reservation.php
 * Handles reservation CRUD via AJAX. Always returns JSON.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

function respond($data) {
    echo json_encode($data);
    exit;
}

// --- Helper: sync room status ---
function syncRoomStatusFromReservation($roomId, $previousStatus = null, $newStatus = null) {
    $roomStatusFor = ['checked_in' => 'occupied', 'reserved' => 'reserved'];
    $active = db_find_active_reservation_for_room($roomId);
    if ($active && isset($roomStatusFor[$active['status']])) {
        db_set_room_status($roomId, $roomStatusFor[$active['status']]);
        return;
    }
    $room = db_find_room($roomId);
    if (!$room || $room['room_status'] === 'maintenance') return;
    db_set_room_status($roomId, 'available');
    if ($newStatus === 'checked_out') {
        db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);
    }
}

// --- Helper: check if room is maintenance ---
function isRoomMaintenance($roomId) {
    $room = db_find_room($roomId);
    if (!$room) return false;
    return ($room['room_status'] === 'maintenance');
}

// --- Permission ---
if (!bb_has_permission('reservations')) {
    http_response_code(403);
    respond(['success' => false, 'message' => 'Reservations permission required.']);
}

// ============================================================
// GET handlers
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $getAction = $_GET['action'] ?? '';

    // --- Fetch full reservation + payment data (Payment Modal / Folio) ---
    if ($getAction === 'get_reservation_for_payment') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'Reservation ID required.']);
        $res = db_find_reservation($id);
        if (!$res) respond(['success' => false, 'message' => 'Reservation not found.']);
        $room = db_find_room($res['room_id']);
        if ($room) {
            $res['room_number'] = $room['room_number'];
            $res['room_type']   = $room['room_type'];
            $res['branch']      = $room['branch'];
        }
        respond([
            'success'          => true,
            'reservation'      => $res,
            'months'           => db_get_payment_months($id),
            'outstanding_balance' => db_get_outstanding_balance($id),
            'payment_status'   => db_get_payment_status($id),
        ]);
    }

    // --- Fetch a single reservation by its own ID ---
    // Used by calendar.js checkout flow to re-fetch before submitting status change.
    if ($getAction === 'get_reservation') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'Reservation ID required.']);
        $res = db_find_reservation($id);
        if (!$res) respond(['success' => false, 'reservation' => null]);
        $room = db_find_room($res['room_id']);
        if ($room) $res['room_number'] = $room['room_number'];
        respond(['success' => true, 'reservation' => $res]);
    }

    // --- Fetch a room's active reservation (by room_id) ---
    // Used by the context menu and room card sidebar.
    if ($getAction === 'get_active_reservation') {
        $roomId = (int) ($_GET['room_id'] ?? 0);
        if (!$roomId) respond(['success' => false, 'message' => 'Room ID required.']);

        $res = db_find_active_reservation_for_room($roomId);

        // FIX: db_find_active_reservation_for_room only looks at TODAY.
        // But the calendar checkout flow calls this right after a status change,
        // so race conditions can cause a miss. Also fetch by reservation_id if provided.
        if (!$res && isset($_GET['reservation_id'])) {
            $rid = (int) $_GET['reservation_id'];
            $candidate = db_find_reservation($rid);
            // Accept it if it belongs to this room and isn't already cancelled/checked_out
            if ($candidate && (int)$candidate['room_id'] === $roomId
                && !in_array($candidate['status'], ['cancelled', 'checked_out'], true)) {
                $res = $candidate;
            }
        }

        if ($res) {
            $room = db_find_room($roomId);
            if ($room) $res['room_number'] = $room['room_number'];
        }
        respond(['success' => true, 'reservation' => $res ?: null]);
    }

    respond(['success' => false, 'message' => 'Unknown GET action.']);
}

// ============================================================
// POST handlers
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

/**
 * Enriches a fetched reservation row with the room number.
 */
function enrichWithRoomNumber($reservation) {
    if (!$reservation) return $reservation;
    $room = db_find_room($reservation['room_id']);
    if ($room) $reservation['room_number'] = $room['room_number'];
    return $reservation;
}

/**
 * Sidebar payload for a room.
 */
function roomSidebarPayload($room) {
    if (!$room) return null;
    return [
        'id'              => $room['id'],
        'room_number'     => $room['room_number'],
        'room_type'       => $room['room_type'],
        'price_per_night' => $room['price_per_night'],
        'room_status'     => $room['room_status'],
        'cleaning_status' => $room['cleaning_status'],
    ];
}

try {
    $action = $_POST['action'] ?? '';
    error_log("[process_reservation] Action: $action");

    // --- Delete ---
    if ($action === 'delete') {
        if (!bb_is_admin()) {
            http_response_code(403);
            respond(['success' => false, 'message' => 'Only admins can delete.']);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $existing = db_find_reservation($id);
        if (!$existing) respond(['success' => false, 'message' => 'Reservation not found.']);
        db_log_reservation_activity($id, $_SESSION['user_id'], 'deleted', 'Deleted for ' . $existing['guest_full_name']);
        db_audit_log('reservation.delete', 'reservation', $id, $existing['guest_full_name'], 'room_id:' . $existing['room_id']);
        db_delete_reservation($id);
        syncRoomStatusFromReservation($existing['room_id']);
        respond(['success' => true, 'rooms' => [roomSidebarPayload(db_find_room($existing['room_id']))]]);
    }

    // --- Quick status-only update (checkout, checkin shortcuts from context menu) ---
    // calendar.js posts: action=update_reservation_status, id=X, status=Y
    if ($action === 'update_reservation_status') {
        $id     = (int) ($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!$id)     respond(['success' => false, 'message' => 'Reservation ID required.']);
        if (!$status) respond(['success' => false, 'message' => 'Status required.']);
        if (!in_array($status, ['reserved','checked_in','checked_out','cancelled'])) {
            respond(['success' => false, 'message' => 'Invalid status.']);
        }
        $existing = db_find_reservation($id);
        if (!$existing) respond(['success' => false, 'message' => 'Reservation not found.']);

        $data = array_merge($existing, [
            'status'  => $status,
            'user_id' => $_SESSION['user_id'],
        ]);
        $oldStatus = $existing['status'];
        db_update_reservation($id, $data);
        db_log_reservation_activity($id, $_SESSION['user_id'], 'status_change', "$oldStatus → $status");
        db_audit_log('reservation.status_change', 'reservation', $id, $existing['guest_full_name'], "status:$status");
        syncRoomStatusFromReservation($existing['room_id'], $oldStatus, $status);

        $updated = enrichWithRoomNumber(db_find_reservation($id));
        respond([
            'success'     => true,
            'id'          => $id,
            'reservation' => $updated,
            'rooms'       => [roomSidebarPayload(db_find_room($existing['room_id']))]
        ]);
    }

    // --- Create / Update ---
    if (!in_array($action, ['create', 'update'])) {
        respond(['success' => false, 'message' => 'Unknown action.']);
    }

    $reservationId = $action === 'update' ? (int) ($_POST['id'] ?? 0) : null;

    // Build data array from POST
    $data = [
        'room_id'               => (int) ($_POST['room_id'] ?? 0),
        'guest_full_name'       => trim($_POST['guest_full_name'] ?? ''),
        'contact_number'        => trim($_POST['contact_number'] ?? ''),
        'email'                 => trim($_POST['email'] ?? ''),
        'address'               => trim($_POST['address'] ?? ''),
        'valid_id_type'         => trim($_POST['valid_id_type'] ?? ''),
        'valid_id_number'       => trim($_POST['valid_id_number'] ?? ''),
        'check_in'              => trim($_POST['check_in'] ?? ''),
        'check_out'             => trim($_POST['check_out'] ?? ''),
        'num_adults'            => max(0, (int) ($_POST['num_adults'] ?? 1)),
        'num_children'          => max(0, (int) ($_POST['num_children'] ?? 0)),
        'status'                => $_POST['status'] ?? 'reserved',
        'room_rate'             => (float) ($_POST['room_rate'] ?? 0),
        'security_deposit'      => (float) ($_POST['security_deposit'] ?? 0),
        'total_amount'          => (float) ($_POST['total_amount'] ?? 0),
        'amount_paid'           => (float) ($_POST['amount_paid'] ?? 0),
        'payment_method'        => $_POST['payment_method'] ?: null,
        'notes'                 => trim($_POST['notes'] ?? ''),
        'special_requests'      => trim($_POST['special_requests'] ?? ''),
        'expected_payment_date' => $_POST['expected_payment_date'] ?? null,
        'user_id'               => $_SESSION['user_id'],
    ];

    error_log("[process_reservation] Received data: " . print_r($data, true));

    // --- For updates, fill in missing fields from existing record ---
    if ($action === 'update') {
        $existing = db_find_reservation($reservationId);
        if (!$existing) {
            respond(['success' => false, 'message' => 'Reservation not found.']);
        }
        foreach ($data as $key => $value) {
            if ($key === 'user_id') continue;
            if (!isset($_POST[$key]) && isset($existing[$key]) && $existing[$key] !== '' && $existing[$key] !== null) {
                $data[$key] = $existing[$key];
            }
        }
    }

    // --- Validate room ---
    $roomId = $data['room_id'];
    $room   = db_find_room($roomId);
    if (!$room) {
        respond(['success' => false, 'message' => 'Invalid room selected.']);
    }

    error_log("[process_reservation] Room ID: $roomId, status: " . $room['room_status'] . ", cleaning: " . $room['cleaning_status']);

    // 1. Block maintenance rooms
    if (isRoomMaintenance($roomId)) {
        respond(['success' => false, 'message' => 'This room is under maintenance and cannot accept reservations.']);
    }

    // 2. Overlap / conflict check — only real date guard needed.
    //    (The old hasActiveCheckedInGuest guard was removed: it incorrectly blocked
    //    future reservations on rooms that had a current checked-in guest, even when
    //    the dates didn't overlap. db_room_has_conflict handles this correctly.)
    $conflict = ($data['status'] !== 'cancelled')
        ? db_room_has_conflict($roomId, $data['check_in'], $data['check_out'], $reservationId)
        : false;
    error_log("[process_reservation] Conflict check: " . ($conflict ? 'YES' : 'NO'));
    if ($conflict) {
        respond(['success' => false, 'message' => 'That room is already booked for an overlapping date range.']);
    }

    // --- Field validation ---
    $errors = [];
    if ($data['guest_full_name'] === '') $errors['guest_full_name'] = 'Guest name is required.';
    $checkInDate  = DateTime::createFromFormat('Y-m-d', $data['check_in']);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $data['check_out']);
    if (!$checkInDate || !$checkOutDate) $errors['check_in'] = 'Enter valid dates (YYYY-MM-DD).';
    elseif ($checkOutDate <= $checkInDate) $errors['check_out'] = 'Check-out must be after check-in.';
    if (!in_array($data['status'], ['reserved','checked_in','checked_out','cancelled'])) $errors['status'] = 'Invalid status.';
    if ($data['payment_method'] !== null && !in_array($data['payment_method'], ['cash','gcash','bank_transfer','card'])) $errors['payment_method'] = 'Invalid payment method.';
    if ($data['expected_payment_date'] && !strtotime($data['expected_payment_date'])) $errors['expected_payment_date'] = 'Invalid expected payment date.';

    if (!empty($errors)) respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors]);

    // --- Create ---
    if ($action === 'create') {
        $newId = db_create_reservation($data);
        db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created for ' . $data['guest_full_name']);
        db_audit_log('reservation.create', 'reservation', $newId, $data['guest_full_name'], 'check_in:' . $data['check_in'] . ' check_out:' . $data['check_out'] . ' status:' . $data['status']);
        syncRoomStatusFromReservation($data['room_id']);
        $created = enrichWithRoomNumber(db_find_reservation($newId));
        respond([
            'success'     => true,
            'id'          => $newId,
            'reservation' => $created,
            'rooms'       => [roomSidebarPayload(db_find_room($data['room_id']))]
        ]);
    }

    // --- Update ---
    $oldStatus = $existing['status'];
    $oldRoomId = $existing['room_id'];

    db_update_reservation($reservationId, $data);
    db_log_reservation_activity($reservationId, $_SESSION['user_id'], 'edited', 'Updated for ' . $data['guest_full_name']);
    db_audit_log('reservation.update', 'reservation', $reservationId, $data['guest_full_name'], 'status:' . $data['status'] . ' check_in:' . $data['check_in'] . ' check_out:' . $data['check_out']);
    syncRoomStatusFromReservation($data['room_id'], $oldStatus, $data['status']);
    if ($oldRoomId != $data['room_id']) syncRoomStatusFromReservation($oldRoomId);

    $updated = enrichWithRoomNumber(db_find_reservation($reservationId));
    $affectedRoomIds = array_unique([$data['room_id'], $oldRoomId]);
    $affectedRooms   = [];
    foreach ($affectedRoomIds as $rid) {
        $payload = roomSidebarPayload(db_find_room($rid));
        if ($payload) $affectedRooms[] = $payload;
    }
    respond([
        'success'     => true,
        'id'          => $reservationId,
        'reservation' => $updated,
        'rooms'       => $affectedRooms
    ]);

} catch (Exception $e) {
    error_log("[ERROR] process_reservation.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}