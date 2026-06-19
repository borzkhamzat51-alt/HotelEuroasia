<?php
/**
 * process_reservation.php
 * AJAX endpoint for Calendar CRUD.
 * Now properly syncs with the Layout floor plan.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

function respond($data) {
    echo json_encode($data);
    exit;
}

// Maps reservation.status → room_status
$roomStatusFor = ['checked_in' => 'occupied', 'reserved' => 'reserved'];

/**
 * Recomputes a room's floor-plan badge from whatever reservation
 * actually covers today.
 */
function syncRoomStatusFromReservation($roomId, $previousStatus = null, $newStatus = null)
{
    global $roomStatusFor;
    $active = db_find_active_reservation_for_room($roomId);

    if ($active && isset($roomStatusFor[$active['status']])) {
        db_set_room_status($roomId, $roomStatusFor[$active['status']]);
        // Also update guest name on the card via room meta (optional)
        return;
    }

    $room = db_find_room($roomId);
    if (!$room || $room['room_status'] === 'maintenance') {
        return;
    }
    db_set_room_status($roomId, 'available');

    // If a guest just checked out, mark the room dirty
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
        db_audit_log('reservation.delete', 'reservation', $id, $existing['guest_full_name'], 'room_id:' . $existing['room_id']);
        db_delete_reservation($id);
        
        // ─── SYNC: Update room status after deletion ────────────────
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
    if (!db_find_room($data['room_id'])) {
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

    // ─── CONFLICT CHECK ──────────────────────────────────────────────
    if ($data['status'] !== 'cancelled' && db_room_has_conflict($data['room_id'], $data['check_in'], $data['check_out'], $reservationId)) {
        respond(['success' => false, 'message' => 'That room is already booked for an overlapping date range.']);
    }

    if (!empty($errors)) {
        respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors]);
    }

    // ─── CREATE ──────────────────────────────────────────────────────
    if ($action === 'create') {
        $newId = db_create_reservation($data);
        db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created for ' . $data['guest_full_name']);
        db_audit_log('reservation.create', 'reservation', $newId, $data['guest_full_name'], 'check_in:' . $data['check_in'] . ' check_out:' . $data['check_out'] . ' status:' . $data['status']);
        
        // ─── SYNC: Update room status after creation ────────────────
        syncRoomStatusFromReservation($data['room_id']);
        
        respond(['success' => true, 'id' => $newId]);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────
    $existing = db_find_reservation($reservationId);
    if (!$existing) {
        respond(['success' => false, 'message' => 'Reservation not found.']);
    }
    
    $oldStatus = $existing['status'];
    $oldRoomId = $existing['room_id'];
    
    db_update_reservation($reservationId, $data);
    db_log_reservation_activity($reservationId, $_SESSION['user_id'], 'edited', 'Updated for ' . $data['guest_full_name']);
    db_audit_log('reservation.update', 'reservation', $reservationId, $data['guest_full_name'], 'status:' . $data['status'] . ' check_in:' . $data['check_in'] . ' check_out:' . $data['check_out']);
    
    // ─── SYNC: Update room status after update ──────────────────────
    // Sync the new room
    syncRoomStatusFromReservation($data['room_id'], $oldStatus, $data['status']);
    
    // If the room changed, also sync the old room
    if ($oldRoomId != $data['room_id']) {
        syncRoomStatusFromReservation($oldRoomId);
    }
    
    respond(['success' => true, 'id' => $reservationId]);

} catch (Exception $e) {
    error_log("Reservation error: " . $e->getMessage());
    respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}