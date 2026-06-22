<?php
/**
 * process_reservation.php
 * Handles reservation CRUD via AJAX. Always returns JSON.
 * Now merges existing reservation data on updates so drag operations never fail validation.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);

function respond($data) {
    echo json_encode($data);
    exit;
}

$roomStatusFor = ['checked_in' => 'occupied', 'reserved' => 'reserved'];

// --- Helper: sync room status ---
function syncRoomStatusFromReservation($roomId, $previousStatus = null, $newStatus = null) {
    global $roomStatusFor;
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

// --- Permission ---
if (!bb_has_permission('reservations')) {
    http_response_code(403);
    respond(['success' => false, 'message' => 'Reservations permission required.']);
}

// --- GET: fetch a room's active reservation (full row + room_number) ---
// Layout's room cards only carry a reduced field set in their data-*
// attributes (no reservation id, address, valid ID, payment fields,
// etc.) — this is what lets the Layout-side reservation menu (check
// in/out, cancel, edit, move, etc.) work with complete, current data
// instead of needing to inline the entire reservation onto every card.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_active_reservation') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    if (!$roomId) {
        respond(['success' => false, 'message' => 'Room ID required.']);
    }
    $res = db_find_active_reservation_for_room($roomId);
    if ($res) {
        $room = db_find_room($roomId);
        if ($room) $res['room_number'] = $room['room_number'];
    }
    respond(['success' => true, 'reservation' => $res ?: null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

/**
 * Enriches a fetched reservation row with the room number, matching the
 * same field reservations.php's PHP-rendered data-reservation JSON
 * carries. Without this, anything reading r.room_number client-side
 * (folio/print/move UI) shows "RM?" right after an AJAX update.
 */
function enrichWithRoomNumber($reservation) {
    if (!$reservation) return $reservation;
    $room = db_find_room($reservation['room_id']);
    if ($room) $reservation['room_number'] = $room['room_number'];
    return $reservation;
}

/**
 * The fields calendar.js's updateRoomSidebar() needs to refresh a room's
 * panel (number, type, floor, price, status pill, availability dot,
 * maintenance badge, filter attributes) without a page reload.
 * syncRoomStatusFromReservation() above already keeps room_status/
 * cleaning_status correct in the database on every create, update, and
 * delete — this is what exposes that (and the room's other editable
 * fields) to the client, so the sidebar can actually be told about it.
 */
function roomSidebarPayload($room) {
    if (!$room) return null;
    return [
        'id' => $room['id'],
        'room_number' => $room['room_number'],
        'room_type' => $room['room_type'],
        'price_per_night' => $room['price_per_night'],
        'room_status' => $room['room_status'],
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

    // --- Create / Update ---
    if (!in_array($action, ['create', 'update'])) {
        respond(['success' => false, 'message' => 'Unknown action.']);
    }

    $reservationId = $action === 'update' ? (int) ($_POST['id'] ?? 0) : null;

    // Build data array from POST
    $data = [
        'room_id' => (int) ($_POST['room_id'] ?? 0),
        'guest_full_name' => trim($_POST['guest_full_name'] ?? ''),
        'contact_number' => trim($_POST['contact_number'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'valid_id_type' => trim($_POST['valid_id_type'] ?? ''),
        'valid_id_number' => trim($_POST['valid_id_number'] ?? ''),
        'check_in' => trim($_POST['check_in'] ?? ''),
        'check_out' => trim($_POST['check_out'] ?? ''),
        'num_adults' => max(0, (int) ($_POST['num_adults'] ?? 1)),
        'num_children' => max(0, (int) ($_POST['num_children'] ?? 0)),
        'status' => $_POST['status'] ?? 'reserved',
        'room_rate' => (float) ($_POST['room_rate'] ?? 0),
        'security_deposit' => (float) ($_POST['security_deposit'] ?? 0),
        'total_amount' => (float) ($_POST['total_amount'] ?? 0),
        'amount_paid' => (float) ($_POST['amount_paid'] ?? 0),
        'payment_method' => $_POST['payment_method'] ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
        'special_requests' => trim($_POST['special_requests'] ?? ''),
        'user_id' => $_SESSION['user_id'],
    ];

    // --- For updates, fill in any field truly missing from the request
    // (key absent from $_POST entirely) using the existing saved value.
    //
    // IMPORTANT: this checks isset($_POST[$key]), not the resolved $data
    // value. An earlier version of this merge checked things like
    // `$value === 0` / `$value === ''` to decide whether to fall back —
    // but that can't tell "user explicitly cleared this to 0/empty" apart
    // from "field wasn't sent at all", so it was silently reverting
    // legitimate zero-value edits (num_children, security_deposit,
    // amount_paid, room_rate, total_amount all have valid all-zero
    // states) back to whatever was already saved. isset() on the raw
    // POST avoids that: a field that was actually submitted, even as 0
    // or "", is respected; only a field genuinely absent falls back.
    if ($action === 'update') {
        $existing = db_find_reservation($reservationId);
        if (!$existing) {
            respond(['success' => false, 'message' => 'Reservation not found.']);
        }
        foreach ($data as $key => $value) {
            if ($key === 'user_id') continue; // always the current session user, never merged from the old row
            if (!isset($_POST[$key]) && isset($existing[$key]) && $existing[$key] !== '' && $existing[$key] !== null) {
                $data[$key] = $existing[$key];
            }
        }
    }

    // --- Validation ---
    $errors = [];
    if ($data['guest_full_name'] === '') $errors['guest_full_name'] = 'Guest name is required.';
    if (!db_find_room($data['room_id'])) $errors['room_id'] = 'Select a valid room.';
    $checkInDate = DateTime::createFromFormat('Y-m-d', $data['check_in']);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $data['check_out']);
    if (!$checkInDate || !$checkOutDate) $errors['check_in'] = 'Enter valid dates (YYYY-MM-DD).';
    elseif ($checkOutDate <= $checkInDate) $errors['check_out'] = 'Check‑out must be after check‑in.';
    if (!in_array($data['status'], ['reserved','checked_in','checked_out','cancelled'])) $errors['status'] = 'Invalid status.';
    if ($data['payment_method'] !== null && !in_array($data['payment_method'], ['cash','gcash','bank_transfer','card'])) $errors['payment_method'] = 'Invalid payment method.';

    if ($data['status'] !== 'cancelled' && db_room_has_conflict($data['room_id'], $data['check_in'], $data['check_out'], $reservationId)) {
        respond(['success' => false, 'message' => 'That room is already booked for an overlapping date range.']);
    }
    if (!empty($errors)) respond(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors]);

    // --- Create ---
    if ($action === 'create') {
        $newId = db_create_reservation($data);
        db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created for ' . $data['guest_full_name']);
        db_audit_log('reservation.create', 'reservation', $newId, $data['guest_full_name'], 'check_in:' . $data['check_in'] . ' check_out:' . $data['check_out'] . ' status:' . $data['status']);
        syncRoomStatusFromReservation($data['room_id']);
        $created = enrichWithRoomNumber(db_find_reservation($newId));
        respond([
            'success' => true,
            'id' => $newId,
            'reservation' => $created,
            'rooms' => [roomSidebarPayload(db_find_room($data['room_id']))]
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

    // Fetch the updated reservation to return
    $updated = enrichWithRoomNumber(db_find_reservation($reservationId));
    // Both rooms need a sidebar refresh when this was a room move — the
    // destination room just gained an occupant/booking, and the original
    // room may have just become free. When the room didn't change, this
    // is simply the one room, still worth refreshing (e.g. a status
    // change like check-in/check-out affects this same room's panel).
    $affectedRoomIds = array_unique([$data['room_id'], $oldRoomId]);
    $affectedRooms = [];
    foreach ($affectedRoomIds as $rid) {
        $payload = roomSidebarPayload(db_find_room($rid));
        if ($payload) $affectedRooms[] = $payload;
    }
    respond([
        'success' => true,
        'id' => $reservationId,
        'reservation' => $updated,
        'rooms' => $affectedRooms
    ]);

} catch (Exception $e) {
    error_log("[ERROR] process_reservation.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}