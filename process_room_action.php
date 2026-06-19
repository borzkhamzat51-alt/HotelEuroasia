<?php
/**
 * process_room_action.php
 * Handles AJAX requests from the PMS Dashboard.
 * Now with robust error handling for checkout and all actions.
 */

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

$reservationStatusFor = ['occupied' => 'checked_in', 'reserved' => 'reserved'];

function closeActiveReservation($roomId, $userId)
{
    $res = db_find_active_reservation_for_room($roomId);
    if ($res) {
        db_update_reservation($res['id'], ['room_id' => $roomId, 'status' => 'checked_out', 'user_id' => $userId]);
        db_log_reservation_activity($res['id'], $userId, 'edited', 'Checked out from floor plan');
        db_audit_log('reservation.update', 'reservation', $res['id'], $res['guest_full_name'], 'status:checked_out (from floor plan)');
    }
    return $res; // return the closed reservation
}

try {
    switch ($action) {

        // ─── SAVE ROOM DATA ──────────────────────────────────────────
        case 'save_room_data':
            $status = $_POST['status'] ?? 'available';
            $guestName = trim($_POST['guest_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $validIdType = trim($_POST['valid_id_type'] ?? '');
            $validIdNumber = trim($_POST['valid_id_number'] ?? '');
            $pax = (int) ($_POST['pax'] ?? 1);
            $checkIn = $_POST['check_in'] ?? '';
            $checkOut = $_POST['check_out'] ?? '';
            $roomRate = (float) ($_POST['room_rate'] ?? 0);
            $securityDeposit = (float) ($_POST['security_deposit'] ?? 0);
            $totalAmount = (float) ($_POST['total_amount'] ?? 0);
            $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? null;
            $specialRequests = trim($_POST['special_requests'] ?? '');
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
                    'room_id' => $roomId,
                    'guest_full_name' => $guestName,
                    'contact_number' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'valid_id_type' => $validIdType,
                    'valid_id_number' => $validIdNumber,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'num_adults' => max(1, $pax),
                    'num_children' => 0,
                    'status' => $reservationStatusFor[$status],
                    'room_rate' => $roomRate,
                    'security_deposit' => $securityDeposit,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'payment_method' => $paymentMethod ?: null,
                    'notes' => $notes ?? '',
                    'special_requests' => $specialRequests,
                    'user_id' => $_SESSION['user_id'],
                ];

                // Conflict check
                $existingReservation = db_find_active_reservation_for_room($roomId);
                $excludeId = $existingReservation ? $existingReservation['id'] : null;

                if (db_room_has_conflict($roomId, $checkIn, $checkOut, $excludeId)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'This room is already booked for that date range. Please choose different dates or check out the existing guest first.'
                    ]);
                    exit;
                }

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

            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Room data saved.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        // ─── QUICK STATUS DROPDOWN CHANGE ────────────────────────────
        case 'update_status':
            $newStatus = $_POST['new_status'] ?? 'available';
            if (!in_array($newStatus, ['available', 'occupied', 'reserved', 'maintenance', 'needs_cleaning'], true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }

            $roomStatus = $newStatus === 'needs_cleaning' ? 'available' : $newStatus;

            if (isset($reservationStatusFor[$roomStatus])) {
                // Conflict check
                $checkIn = date('Y-m-d');
                $checkOut = date('Y-m-d', strtotime('+30 days'));
                $existingReservation = db_find_active_reservation_for_room($roomId);
                $excludeId = $existingReservation ? $existingReservation['id'] : null;

                if (db_room_has_conflict($roomId, $checkIn, $checkOut, $excludeId)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'This room is already booked for that date range. Please check out the existing guest first.'
                    ]);
                    exit;
                }

                $existing = db_find_active_reservation_for_room($roomId);
                if ($existing) {
                    db_update_reservation($existing['id'], [
                        'room_id' => $roomId,
                        'status' => $reservationStatusFor[$roomStatus],
                        'user_id' => $_SESSION['user_id']
                    ]);
                    db_log_reservation_activity($existing['id'], $_SESSION['user_id'], 'edited', "Status changed to $roomStatus");
                    db_audit_log('reservation.update', 'reservation', $existing['id'], $existing['guest_full_name'], 'status:' . $roomStatus);
                } else {
                    $data = [
                        'room_id' => $roomId,
                        'guest_full_name' => 'Walk-in Guest',
                        'contact_number' => '',
                        'email' => '',
                        'address' => '',
                        'valid_id_type' => '',
                        'valid_id_number' => '',
                        'check_in' => date('Y-m-d'),
                        'check_out' => date('Y-m-d', strtotime('+30 days')),
                        'num_adults' => 1,
                        'num_children' => 0,
                        'status' => $reservationStatusFor[$roomStatus],
                        'room_rate' => $room['price_per_night'],
                        'security_deposit' => 0,
                        'total_amount' => 0,
                        'amount_paid' => 0,
                        'payment_method' => null,
                        'notes' => '',
                        'special_requests' => '',
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

            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Status updated.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        // ─── CHECK IN ──────────────────────────────────────────────────
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

            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Guest checked in.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        // ─── CHECK OUT ─────────────────────────────────────────────────
        case 'check_out':
            try {
                // Ensure user_id is set
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
                    exit;
                }

                $res = db_find_active_reservation_for_room($roomId);
                if (!$res) {
                    echo json_encode(['success' => false, 'message' => 'No active reservation to check out.']);
                    exit;
                }

                // Update reservation status to checked_out
                $updateData = [
                    'room_id' => $roomId,
                    'status' => 'checked_out',
                    'user_id' => $userId,
                ];
                db_update_reservation($res['id'], $updateData);
                db_log_reservation_activity($res['id'], $userId, 'edited', 'Checked out');
                db_audit_log('room.check_out', 'room', $roomId, 'RM' . $room['room_number'], 'guest:' . $res['guest_full_name']);

                // Update room status to available and set cleaning to pending
                db_set_room_status($roomId, 'available');
                db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);

                $updatedRoom = db_find_room($roomId);
                $activeRes = db_find_active_reservation_for_room($roomId);
                echo json_encode([
                    'success' => true,
                    'message' => 'Guest checked out.',
                    'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
                ]);
            } catch (Exception $e) {
                error_log('Checkout error: ' . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => 'Checkout failed: ' . $e->getMessage()
                ]);
            }
            break;

        // ─── SET MAINTENANCE ──────────────────────────────────────────
        case 'set_maintenance':
            db_set_room_status($roomId, 'maintenance');
            db_update_room_meta($roomId, null, 'Pending Repair', null, null);
            db_audit_log('room.set_maintenance', 'room', $roomId, 'RM' . $room['room_number']);

            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Room marked out of order.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        // ─── CLEAR MAINTENANCE ────────────────────────────────────────
        case 'clear_maintenance':
            db_set_room_status($roomId, 'available');
            db_update_room_meta($roomId, null, 'Cleared', null, null);
            db_audit_log('room.clear_maintenance', 'room', $roomId, 'RM' . $room['room_number']);

            $updatedRoom = db_find_room($roomId);
            $activeRes = db_find_active_reservation_for_room($roomId);
            echo json_encode([
                'success' => true,
                'message' => 'Maintenance cleared.',
                'data' => ['room' => $updatedRoom, 'reservation' => $activeRes]
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    error_log('Room action error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}