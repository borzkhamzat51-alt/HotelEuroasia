<?php
/**
 * process_room_action.php
 * Handles AJAX requests from the PMS Dashboard.
 * Now: real DB updates, audit logging, and returns fresh room/reservation data.
 */

// XAMPP defaults to display_errors = On which causes PHP warnings to mix
// into JSON responses, breaking JSON.parse() on the client. Always suppress
// display_errors on AJAX endpoints — errors are logged server-side instead.
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!bb_has_permission('rooms')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Rooms permission required.']);
    exit;
}

// --- GET: activity history for a room ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_history') {
    $roomId = (int) ($_GET['room_id'] ?? 0);
    if (!$roomId) {
        echo json_encode(['success' => false, 'message' => 'Room ID required.']);
        exit;
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
    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';
$roomId = (int) ($_POST['room_id'] ?? 0);

if (empty($roomId)) {
    echo json_encode(['success' => false, 'message' => 'Room ID required.']);
    exit;
}

$room = db_find_room($roomId);
if (!$room) {
    echo json_encode(['success' => false, 'message' => "Room ID $roomId not found."]);
    exit;
}

// Maps room_status -> reservation.status
$reservationStatusFor = ['occupied' => 'checked_in', 'reserved' => 'reserved'];

function closeActiveReservation($roomId, $userId)
{
    $res = db_find_active_reservation_for_room($roomId);
    if ($res) {
        db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_out', 'user_id' => $userId]);
        db_log_reservation_activity($res['id'], $userId, 'edited', 'Checked out from floor plan');
    }
}

/**
 * Builds the same 'room' / 'reservation' payload shape
 * process_reservation.php's responses carry, so calendar.js's
 * updateRoomSidebar()/updateUIFromServer() can consume either file's
 * response identically instead of needing a second parsing path.
 * Re-fetches both rather than trusting in-memory state, so this can't
 * drift from what's actually in the database.
 */
function freshRoomActionPayload($roomId) {
    $freshRoom = db_find_room($roomId);
    $activeRes = db_find_active_reservation_for_room($roomId);
    if ($activeRes && $freshRoom) {
        $activeRes['room_number'] = $freshRoom['room_number'];
    }
    return [
        'room' => $freshRoom ? [
            'id' => $freshRoom['id'],
            'room_number' => $freshRoom['room_number'],
            'room_type' => $freshRoom['room_type'],
            'price_per_night' => $freshRoom['price_per_night'],
            'room_status' => $freshRoom['room_status'],
            'cleaning_status' => $freshRoom['cleaning_status'],
        ] : null,
        'reservation' => $activeRes ?: null,
    ];
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
            $cleaning = $_POST['cleaning'] ?? null;
            $maintenance = $_POST['maintenance_status'] ?? null;
            $lastOccupancy = $_POST['last_occupancy'] ?? null;
            $notes = array_key_exists('notes', $_POST) ? trim($_POST['notes']) : null;

            if (!in_array($status, ['available', 'occupied', 'reserved', 'maintenance'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }
            if (($status === 'occupied' || $status === 'reserved') && $guestName === '') {
                echo json_encode(['success' => false, 'message' => 'Guest name required for occupied/reserved rooms.']);
                exit;
            }

            if (isset($reservationStatusFor[$status])) {
                if (empty($checkIn)) $checkIn = date('Y-m-d');
                if (empty($checkOut)) $checkOut = date('Y-m-d', strtotime($checkIn . ' +30 days'));
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
                    db_audit_log('reservation.update', 'reservation', $existing['id'], $guestName, 'status:' . $status);
                } else {
                    $newId = db_create_reservation($data);
                    db_log_reservation_activity($newId, $_SESSION['user_id'], 'created', 'Created from floor plan');
                    db_audit_log('reservation.create', 'reservation', $newId, $guestName, 'status:' . $status);
                }
            } else {
                // available / maintenance – close any active booking
                $active = db_find_active_reservation_for_room($roomId);
                if ($active) {
                    db_audit_log('reservation.update', 'reservation', $active['id'], $active['guest_full_name'], 'status:checked_out (from floor plan)');
                }
                closeActiveReservation($roomId, $_SESSION['user_id']);
            }

            db_set_room_status($roomId, $status);
            db_update_room_meta($roomId, $cleaning, $maintenance, $lastOccupancy, $notes);
            db_audit_log('room.update', 'room', $roomId, 'RM' . $room['room_number'], 'status:' . $status);

            // Return fresh data for frontend update
            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Room data saved.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        case 'update_status':
            $newStatus = $_POST['new_status'] ?? 'available';
            if (!in_array($newStatus, ['available', 'occupied', 'reserved', 'maintenance', 'needs_cleaning'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }
            $roomStatus = $newStatus === 'needs_cleaning' ? 'available' : $newStatus;

            if (isset($reservationStatusFor[$roomStatus])) {
                $existing = db_find_active_reservation_for_room($roomId);
                if ($existing) {
                    db_update_reservation($existing['id'], ['room_id' => $roomId, 'status' => $reservationStatusFor[$roomStatus], 'user_id' => $_SESSION['user_id']]);
                    db_log_reservation_activity($existing['id'], $_SESSION['user_id'], 'edited', "Status changed to $roomStatus");
                    db_audit_log('reservation.update', 'reservation', $existing['id'], $existing['guest_full_name'], 'status:' . $roomStatus);
                } else {
                    // Create a walk‑in reservation
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
                    db_audit_log('reservation.create', 'reservation', $newId, 'Walk-in Guest', 'status:' . $roomStatus);
                }
            } else {
                $hadActiveGuest = db_find_active_reservation_for_room($roomId) !== null;
                closeActiveReservation($roomId, $_SESSION['user_id']);
                if ($roomStatus === 'available') {
                    $cleaning = ($newStatus === 'needs_cleaning' || $hadActiveGuest) ? 'Pending' : 'Clean';
                    db_update_room_meta($roomId, $cleaning, null, $hadActiveGuest ? date('Y-m-d') : null, null);
                }
                if ($hadActiveGuest) {
                    db_audit_log('reservation.update', 'reservation', null, null, 'checked_out via status change to ' . $roomStatus);
                }
            }

            db_set_room_status($roomId, $roomStatus);
            db_audit_log('room.update', 'room', $roomId, 'RM' . $room['room_number'], 'status:' . $roomStatus);
            echo json_encode(array_merge(['success' => true, 'message' => 'Status updated.'], freshRoomActionPayload($roomId)));
            break;

        case 'check_in':
            $res = db_find_active_reservation_for_room($roomId);
            if (!$res) {
                echo json_encode(['success' => false, 'message' => 'No active reservation to check in.']);
                exit;
            }
            db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_in', 'user_id' => $_SESSION['user_id']]);
            db_log_reservation_activity($res['id'], $_SESSION['user_id'], 'edited', 'Checked in');
            db_audit_log('room.check_in', 'room', $roomId, 'RM' . $room['room_number'], 'guest:' . $res['guest_full_name']);
            db_set_room_status($roomId, 'occupied');
            echo json_encode(array_merge(['success' => true, 'message' => 'Guest checked in.'], freshRoomActionPayload($roomId)));
            break;

        case 'check_out':
            $res = db_find_active_reservation_for_room($roomId);
            if (!$res) {
                echo json_encode(['success' => false, 'message' => 'No active reservation to check out.']);
                exit;
            }
            db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_out', 'user_id' => $_SESSION['user_id']]);
            db_log_reservation_activity($res['id'], $_SESSION['user_id'], 'edited', 'Checked out');
            db_audit_log('room.check_out', 'room', $roomId, 'RM' . $room['room_number'], 'guest:' . $res['guest_full_name']);
            db_set_room_status($roomId, 'available');
            db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);
            echo json_encode(array_merge(['success' => true, 'message' => 'Guest checked out.'], freshRoomActionPayload($roomId)));
            break;

        case 'set_maintenance':
            db_set_room_status($roomId, 'maintenance');
            db_update_room_meta($roomId, null, 'Pending Repair', null, null);
            db_audit_log('room.set_maintenance', 'room', $roomId, 'RM' . $room['room_number']);
            echo json_encode(array_merge(['success' => true, 'message' => 'Room marked out of order.'], freshRoomActionPayload($roomId)));
            break;

        case 'clear_maintenance':
            db_set_room_status($roomId, 'available');
            db_update_room_meta($roomId, null, 'Cleared', null, null);
            db_audit_log('room.clear_maintenance', 'room', $roomId, 'RM' . $room['room_number']);
            echo json_encode(array_merge(['success' => true, 'message' => 'Maintenance cleared.'], freshRoomActionPayload($roomId)));
            break;

        // Editing room number/type/price wasn't previously possible from
        // either Layout or Calendar — these fields had no admin-facing
        // edit path at all before this.
        case 'update_room_details':
            $newNumber = trim($_POST['room_number'] ?? '');
            $newType = trim($_POST['room_type'] ?? '');
            $newPrice = (float) ($_POST['price_per_night'] ?? 0);

            if ($newNumber === '') {
                echo json_encode(['success' => false, 'message' => 'Room number is required.']);
                exit;
            }
            if ($newType === '') {
                echo json_encode(['success' => false, 'message' => 'Room type is required.']);
                exit;
            }
            if ($newPrice < 0) {
                echo json_encode(['success' => false, 'message' => 'Price cannot be negative.']);
                exit;
            }
            if (db_room_number_taken($room['branch'], $newNumber, $roomId)) {
                echo json_encode(['success' => false, 'message' => 'Room number RM' . $newNumber . ' is already in use on this branch.']);
                exit;
            }

            $before = 'RM' . $room['room_number'] . ' (' . $room['room_type'] . ', ₱' . $room['price_per_night'] . ')';
            db_update_room_details($roomId, $newNumber, $newType, $newPrice);
            db_audit_log('room.update_details', 'room', $roomId, 'RM' . $newNumber, 'was ' . $before);
            echo json_encode(array_merge(['success' => true, 'message' => 'Room details updated.'], freshRoomActionPayload($roomId)));
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    error_log('Room action error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}