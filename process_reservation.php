<?php
/**
 * process_reservation.php
 * AJAX endpoint for Calendar CRUD.
 * Located at project root – requires config.php and db.php from the same folder.
 *
 * Layout/Calendar sync: a reservation created or edited here also drives
 * the room's floor-plan badge (rooms.room_status) whenever it's the
 * reservation actually covering today — see syncRoomStatusFromReservation().
 * The reverse direction (a room flagged Out of Order on the floor plan)
 * blocks new bookings here too, in the validation step below.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

function respond($data) {
    echo json_encode($data);
    exit;
}

// Maps a reservation's lifecycle status to the room's floor-plan badge,
// the same mapping process_room_action.php uses going the other direction.
$roomStatusFor = ['checked_in' => 'occupied', 'reserved' => 'reserved'];

/**
 * Recomputes a room's floor-plan badge from whatever reservation (if
 * any) actually covers today. Never overrides an independent
 * Out-of-Order flag, since that isn't reservation-driven. When a stay
 * that covered today just ended (checked_in -> checked_out), also
 * marks the room dirty, matching what checking out via the floor plan
 * already does.
 */
function syncRoomStatusFromReservation($roomId, $previousStatus = null, $newStatus = null)
{
    global $roomStatusFor;
    $active = db_find_active_reservation_for_room($roomId);

    if ($active && isset($roomStatusFor[$active['status']])) {
        db_set_room_status($roomId, $roomStatusFor[$active['status']]);
        return;
    }

    $room = db_find_room($roomId);
    if (!$room || $room['room_status'] === 'maintenance') {
        return; // an independent Out-of-Order flag isn't reservation-driven
    }
    db_set_room_status($roomId, 'available');

    $justCheckedOut = $previousStatus === 'checked_in' && $newStatus === 'checked_out';
    if ($justCheckedOut) {
        db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);
    }
}

// Permission check
if (!bb_has_permission('reservations')) {
    http_response_code(403);
    respond(['success' => false, 'message' => 'Reservations permission required.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

try {
    $action = $_POST['action'] ?? '';

    // --- Delete (admin only) ---
    if ($action === 'delete') {
        if (!bb_is_admin()) {
            http_response_code(403);
            respond(['success' => false, 'message' => 'Only admins can delete.']);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $existing = db_find_reservation($id);
        if (!$existing) {
            respond(['success' => false, 'message' => 'Reservation not found.']);
        }
        db_log_reservation_activity($id, $_SESSION['user_id'], 'deleted', 'Deleted for ' . $existing['guest_full_name']);
        db_delete_reservation($id);
        syncRoomStatusFromReservation($existing['room_id']);
        respond(['success' => true]);
    }

    // --- Create / Update ---
    if (!in_array($action, ['create', 'update'])) {
        respond(['success' => false, 'message' => 'Unknown action.']);
    }

    $reservationId = $action === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $existingReservation = $action === 'update' ? db_find_reservation($reservationId) : null;

    $data = [
        'room_id'          => (int) ($_POST['room_id'] ?? 0),
        'guest_full_name'  => trim($_POST['guest_full_name'] ?? ''),
        'contact_number'   => trim($_POST['contact_number'] ?? ''),
        'email'            => trim($_POST['email'] ?? ''),
        'address'          => trim($_POST['address'] ?? ''),
        'valid_id_type'    => trim($_POST['valid_id_type'] ?? ''),
        'valid_id_number'  => trim($_POST['valid_id_number'] ?? ''),
        'check_in'         => trim($_POST['check_in'] ?? ''),
        'check_out'        => trim($_POST['check_out'] ?? ''),
        'num_adults'       => max(0, (int) ($_POST['num_adults'] ?? 1)),
        'num_children'     => max(0, (int) ($_POST['num_children'] ?? 0)),
        'status'           => $_POST['status'] ?? 'reserved',
        'room_rate'        => (float) ($_POST['room_rate'] ?? 0),
        'security_deposit' => (float) ($_POST['security_deposit'] ?? 0),
        'total_amount'     => (float) ($_POST['total_amount'] ?? 0),
        'amount_paid'      => (float) ($_POST['amount_paid'] ?? 0),
        'payment_method'   => $_POST['payment_method'] ?: null,
        'notes'            => trim($_POST['notes'] ?? ''),
        'special_requests' => trim($_POST['special_requests'] ?? ''),
        'user_id'          => $_SESSION['user_id'],
    ];

    // --- Validation ---
    $errors = [];
    if ($data['guest_full_name'] === '') {
        $errors['guest_full_name'] = 'Guest name is required.';
    }
    $targetRoom = db_find_room($data['room_id']);
    if (!$targetRoom) {
        $errors['room_id'] = 'Select a valid room.';
    }
    $checkInDate  = DateTime::createFromFormat('Y-m-d', $data['check_in']);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $data['check_out']);
    if (!$checkInDate || !$checkOutDate) {
        $errors['check_in'] = 'Enter valid dates (YYYY-MM-DD).';
    } elseif ($checkOutDate <= $checkInDate) {
        $errors['check_out'] = 'Check‑out must be after check‑in.';
    }
    if (!in_array($data['status'], ['reserved', 'checked_in', 'checked_out', 'cancelled'])) {
        $errors['status'] = 'Invalid status.';
    }
    if ($data['payment_method'] !== null && !in_array($data['payment_method'], ['cash', 'gcash', 'bank_transfer', 'card'])) {
        $errors['payment_method'] = 'Invalid payment method.';
    }
    // A room flagged Out of Order on the floor plan can't be newly booked
    // here — only blocks *new* claims on it, not editing/cancelling/
    // checking out a reservation that was already active before the
    // room went into maintenance.
    $wasAlreadyActive = $existingReservation && in_array($existingReservation['status'], ['reserved', 'checked_in'], true);
    $isNewActivation = in_array($data['status'], ['reserved', 'checked_in'], true) && !$wasAlreadyActive;
    if ($targetRoom && $targetRoom['room_status'] === 'maintenance' && $isNewActivation) {
        $errors['room_id'] = 'This room is currently marked Out of Order — clear that on the floor plan before booking it.';
    }
    if (!empty($errors)) {
        respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors]);
    }

    // Double‑booking check
    if ($data['status'] !== 'cancelled' && db_room_has_conflict($data['room_id'], $data['check_in'], $data['check_out'], $reservationId)) {
        respond(['success' => false, 'message' => 'That room is already booked for an overlapping date range.']);
    }

    if ($action === 'create') {
        $newId = db_create_reservation($data);
        db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created for ' . $data['guest_full_name']);
        syncRoomStatusFromReservation($data['room_id']);
        respond(['success' => true, 'id' => $newId]);
    }

    // Update
    if (!$existingReservation) {
        respond(['success' => false, 'message' => 'Reservation not found.']);
    }
    db_update_reservation($reservationId, $data);
    db_log_reservation_activity($reservationId, $_SESSION['user_id'], 'edited', 'Updated for ' . $data['guest_full_name']);
    syncRoomStatusFromReservation($data['room_id'], $existingReservation['status'], $data['status']);
    // The room may have changed between the old and new reservation data —
    // keep the previous room in sync too if so.
    if ($existingReservation['room_id'] != $data['room_id']) {
        syncRoomStatusFromReservation($existingReservation['room_id'], $existingReservation['status'], 'checked_out');
    }
    respond(['success' => true, 'id' => $reservationId]);

} catch (Exception $e) {
    error_log("Reservation error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    respond([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()  // Shows real error temporarily
    ]);
}