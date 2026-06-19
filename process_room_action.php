<?php
/**
 * process_room_action.php
 * AJAX endpoint for the floor-plan console (assets/js/layout.js).
 *
 * Two separate concepts live here, on purpose:
 *  - room_status (on the `rooms` row): available / occupied / reserved /
 *    maintenance — the room's day-to-day operational state.
 *  - reservations.status: reserved / checked_in / checked_out / cancelled
 *    — a single booking's lifecycle, shared with the Calendar module.
 * Earlier versions of this file tried to write room_status values
 * straight into reservations.status, which doesn't allow them and threw
 * "Data truncated for column 'status'" on every save. This version maps
 * between the two instead of conflating them.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

function respond($data)
{
    echo json_encode($data);
    exit;
}

if (!bb_has_permission('rooms')) {
    http_response_code(403);
    respond(['success' => false, 'message' => 'Rooms permission required.']);
}

// --- GET: activity history for a room (all reservations ever tied to it) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_history') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    if (!$roomId) {
        respond(['success' => false, 'message' => 'Room ID required.']);
    }
    $stmt = bb_db()->prepare('SELECT id FROM reservations WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $resIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $history = [];
    foreach ($resIds as $rid) {
        $history = array_merge($history, db_get_reservation_activity($rid));
    }
    usort($history, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    respond(['success' => true, 'history' => $history]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

$action = $_POST['action'] ?? '';
$roomId = (int) ($_POST['room_id'] ?? 0);

if (empty($roomId)) {
    respond(['success' => false, 'message' => 'Room ID required.']);
}

$room = db_find_room($roomId);
if (!$room) {
    respond(['success' => false, 'message' => "Room ID $roomId not found."]);
}

// Maps a floor-plan room_status onto the reservations.status value an
// active booking should carry while the room is in that state.
$reservationStatusFor = ['occupied' => 'checked_in', 'reserved' => 'reserved'];

/**
 * Closes out whatever active reservation a room has (marks it
 * checked_out) so it stops blocking future Calendar bookings. Used any
 * time a room moves to 'available' or 'maintenance'.
 */
function closeActiveReservation($roomId, $userId)
{
    $res = db_find_active_reservation_for_room($roomId);
    if ($res) {
        db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_out', 'user_id' => $userId]);
        db_log_reservation_activity($res['id'], $userId, 'edited', 'Checked out from floor plan');
    }
}

try {
    switch ($action) {

        case 'save_room_data':
            $status = $_POST['status'] ?? 'available';
            $guestName = trim($_POST['guest_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pax = (int) ($_POST['pax'] ?? 1);
            $checkIn = $_POST['check_in'] ?? '';
            $checkOut = $_POST['check_out'] ?? '';
            // "Payment Status" here (Unpaid / 50% Deposit / Fully Paid) describes
            // how much has been paid, not a payment method — reservations.payment_method
            // is an ENUM of cash/gcash/bank_transfer/card and doesn't have a slot for
            // this, so it isn't persisted from this console. Use the Calendar's
            // reservation form for tracking actual amounts paid.

            // Meta fields are only present in the form for available/maintenance
            // rooms (see layout.js) — null here means "not submitted," which
            // db_update_room_meta() correctly treats as "leave unchanged."
            $cleaning = $_POST['cleaning'] ?? null;
            $maintenance = $_POST['maintenance_status'] ?? null;
            $lastOccupancy = $_POST['last_occupancy'] ?? null;
            $notes = array_key_exists('notes', $_POST) ? trim($_POST['notes']) : null;

            if (!in_array($status, ['available', 'occupied', 'reserved', 'maintenance'], true)) {
                respond(['success' => false, 'message' => 'Invalid status.']);
            }
            if (($status === 'occupied' || $status === 'reserved') && $guestName === '') {
                respond(['success' => false, 'message' => 'Guest name is required for occupied or reserved rooms.']);
            }

            if (isset($reservationStatusFor[$status])) {
                if (empty($checkIn)) {
                    $checkIn = date('Y-m-d');
                }
                if (empty($checkOut)) {
                    $checkOut = date('Y-m-d', strtotime($checkIn . ' +30 days'));
                }
                $data = [
                    'room_id' => $roomId, 'guest_full_name' => $guestName, 'contact_number' => $phone,
                    'email' => $email, 'address' => '', 'valid_id_type' => '', 'valid_id_number' => '',
                    'check_in' => $checkIn, 'check_out' => $checkOut, 'num_adults' => max(1, $pax),
                    'num_children' => 0, 'status' => $reservationStatusFor[$status],
                    'room_rate' => $room['price_per_night'], 'security_deposit' => 0, 'total_amount' => 0,
                    'amount_paid' => 0, 'payment_method' => null, 'notes' => $notes ?? '',
                    'special_requests' => '', 'user_id' => $_SESSION['user_id'],
                ];
                $existing = db_find_active_reservation_for_room($roomId);
                if ($existing) {
                    db_update_reservation($existing['id'], $data);
                    db_log_reservation_activity($existing['id'], $_SESSION['user_id'], 'edited', 'Updated from floor plan');
                } else {
                    $newId = db_create_reservation($data);
                    db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created from floor plan');
                }
            } else {
                // available / maintenance — no guest, close out any active booking
                closeActiveReservation($roomId, $_SESSION['user_id']);
            }

            db_set_room_status($roomId, $status);
            db_update_room_meta($roomId, $cleaning, $maintenance, $lastOccupancy, $notes);
            respond(['success' => true, 'message' => 'Room data saved.']);
            break;

        case 'update_status':
            $newStatus = $_POST['new_status'] ?? 'available';
            if (!in_array($newStatus, ['available', 'occupied', 'reserved', 'maintenance', 'needs_cleaning'], true)) {
                respond(['success' => false, 'message' => 'Invalid status.']);
            }
            // 'needs_cleaning' is a flavor of 'available' (vacant but dirty) —
            // cleaning_status carries that distinction, room_status doesn't.
            $roomStatus = $newStatus === 'needs_cleaning' ? 'available' : $newStatus;

            if (isset($reservationStatusFor[$roomStatus])) {
                $existing = db_find_active_reservation_for_room($roomId);
                if ($existing) {
                    db_update_reservation($existing['id'], ['room_id' => $roomId, 'status' => $reservationStatusFor[$roomStatus], 'user_id' => $_SESSION['user_id']]);
                    db_log_reservation_activity($existing['id'], $_SESSION['user_id'], 'edited', "Status changed to $roomStatus");
                } else {
                    $data = [
                        'room_id' => $roomId, 'guest_full_name' => 'Walk-in Guest', 'contact_number' => '',
                        'email' => '', 'address' => '', 'valid_id_type' => '', 'valid_id_number' => '',
                        'check_in' => date('Y-m-d'), 'check_out' => date('Y-m-d', strtotime('+30 days')),
                        'num_adults' => 1, 'num_children' => 0, 'status' => $reservationStatusFor[$roomStatus],
                        'room_rate' => $room['price_per_night'], 'security_deposit' => 0, 'total_amount' => 0,
                        'amount_paid' => 0, 'payment_method' => null, 'notes' => '', 'special_requests' => '',
                        'user_id' => $_SESSION['user_id'],
                    ];
                    $newId = db_create_reservation($data);
                    db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', "Created from status change to $roomStatus");
                }
            } else {
                $hadActiveGuest = db_find_active_reservation_for_room($roomId) !== null;
                closeActiveReservation($roomId, $_SESSION['user_id']);
                if ($roomStatus === 'available') {
                    // Picking "Needs Cleaning," or losing an active guest the
                    // same way check_out does, both mean dirty. Explicitly
                    // picking "Available" on an already-empty room means "I've
                    // cleaned it" — set cleaning to Clean.
                    $cleaning = ($newStatus === 'needs_cleaning' || $hadActiveGuest) ? 'Pending' : 'Clean';
                    db_update_room_meta($roomId, $cleaning, null, $hadActiveGuest ? date('Y-m-d') : null, null);
                }
            }

            db_set_room_status($roomId, $roomStatus);
            respond(['success' => true, 'message' => 'Status updated.']);
            break;

        case 'check_in':
            $res = db_find_active_reservation_for_room($roomId);
            if (!$res) {
                respond(['success' => false, 'message' => 'No active reservation to check in.']);
            }
            db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_in', 'user_id' => $_SESSION['user_id']]);
            db_log_reservation_activity($res['id'], $_SESSION['user_id'], 'edited', 'Checked in');
            db_set_room_status($roomId, 'occupied');
            respond(['success' => true, 'message' => 'Guest checked in.']);
            break;

        case 'check_out':
            $res = db_find_active_reservation_for_room($roomId);
            if (!$res) {
                respond(['success' => false, 'message' => 'No active reservation to check out.']);
            }
            db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_out', 'user_id' => $_SESSION['user_id']]);
            db_log_reservation_activity($res['id'], $_SESSION['user_id'], 'edited', 'Checked out');
            db_set_room_status($roomId, 'available');
            // A room a guest just left is dirty by default — housekeeping has
            // to explicitly mark it Clean again before it reads as ready.
            db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);
            respond(['success' => true, 'message' => 'Guest checked out.']);
            break;

        case 'set_maintenance':
            db_set_room_status($roomId, 'maintenance');
            db_update_room_meta($roomId, null, 'Pending Repair', null, null);
            respond(['success' => true, 'message' => 'Room marked out of order.']);
            break;

        case 'clear_maintenance':
            db_set_room_status($roomId, 'available');
            db_update_room_meta($roomId, null, 'Cleared', null, null);
            respond(['success' => true, 'message' => 'Maintenance cleared.']);
            break;

        default:
            respond(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    error_log('Room action error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}