<?php
/**
 * process_room_action.php
 * Handles room-level actions: cleaning status, maintenance toggle,
 * room details edit, staff notes, and room status sync.
 * Always returns JSON.
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

// --- GET handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $getAction = $_GET['action'] ?? '';

    // Reservation history for a room (used by layout right-click menu)
    if ($getAction === 'get_history') {
        if (!bb_has_permission('rooms')) {
            http_response_code(403);
            respond(['success' => false, 'message' => 'Permission required.']);
        }
        $roomId = (int)($_GET['room_id'] ?? 0);
        if (!$roomId) respond(['success' => false, 'message' => 'Room ID required.']);

        // Get all reservation IDs for this room, then fetch activity
        $stmt = bb_db()->prepare(
            "SELECT id FROM reservations WHERE room_id = ? ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([$roomId]);
        $resvIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $history = [];
        foreach ($resvIds as $rid) {
            $entries = db_get_reservation_activity($rid);
            foreach ($entries as $e) {
                $history[] = $e;
            }
        }
        // Sort by created_at desc
        usort($history, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        respond(['success' => true, 'history' => array_slice($history, 0, 30)]);
    }

    respond(['success' => false, 'message' => 'Unknown action.']);
}

// --- Permission check for POST ---
if (!bb_has_permission('rooms')) {
    http_response_code(403);
    respond(['success' => false, 'message' => 'Rooms permission required.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(['success' => false, 'message' => 'Invalid request method.']);
}

/**
 * Full room payload sent back to realtime-room-sync.js / calendar.js
 */
function roomPayload($room) {
    if (!$room) return null;
    return [
        'id'                 => $room['id'],
        'room_number'        => $room['room_number'],
        'room_type'          => $room['room_type'],
        'price_per_night'    => $room['price_per_night'],
        'room_status'        => $room['room_status'],
        'cleaning_status'    => $room['cleaning_status'],
        'maintenance_status' => $room['maintenance_status'] ?? '',
        'last_occupancy'     => $room['last_occupancy'] ?? '',
        'staff_notes'        => $room['staff_notes'] ?? '',
    ];
}

/**
 * Sync room_status from active reservation.
 */
function syncRoomStatus($roomId, $newReservationStatus = null) {
    $roomStatusFor = ['checked_in' => 'occupied', 'reserved' => 'reserved'];
    $active = db_find_active_reservation_for_room($roomId);
    if ($active && isset($roomStatusFor[$active['status']])) {
        db_set_room_status($roomId, $roomStatusFor[$active['status']]);
        return;
    }
    $room = db_find_room($roomId);
    if (!$room || $room['room_status'] === 'maintenance') return;
    db_set_room_status($roomId, 'available');
    if ($newReservationStatus === 'checked_out') {
        db_update_room_meta($roomId, 'Pending', null, date('Y-m-d'), null);
    }
}

try {
    $action = $_POST['action'] ?? '';
    $roomId = (int) ($_POST['room_id'] ?? 0);

    error_log("[process_room_action] Action: $action, Room ID: $roomId");

    if (!$roomId) {
        respond(['success' => false, 'message' => 'Room ID required.']);
    }

    $room = db_find_room($roomId);
    if (!$room) {
        respond(['success' => false, 'message' => 'Room not found.']);
    }

    // ── update_status ────────────────────────────────────────────────────────
    // Called by calendar.js (updateRoomStatus) and realtime-room-sync.js
    // after reservation changes to re-sync the room card.
    //
    // JS sends: action=update_status, room_id=X, new_status=Y
    // Possible new_status values from JS:
    //   'available'      → mark room_status=available, cleaning_status=Clean
    //   'needs_cleaning' → mark room_status=available, cleaning_status=Dirty
    //   'occupied'       → mark room_status=occupied
    //   'reserved'       → mark room_status=reserved
    //   'maintenance'    → mark room_status=maintenance
    if ($action === 'update_status') {
        // JS sends the status as 'new_status', not 'status'
        $newStatus = trim($_POST['new_status'] ?? $_POST['status'] ?? '');

        switch ($newStatus) {
            case 'available':
                // Mark clean and available
                db_set_room_status($roomId, 'available');
                db_update_room_meta($roomId, 'Clean', null, null, null);
                break;

            case 'needs_cleaning':
                // Mark available but dirty
                db_set_room_status($roomId, 'available');
                db_update_room_meta($roomId, 'Dirty', null, null, null);
                break;

            case 'occupied':
            case 'reserved':
                db_set_room_status($roomId, $newStatus);
                break;

            case 'maintenance':
                db_set_room_status($roomId, 'maintenance');
                break;

            default:
                // No explicit status or unrecognised — derive from active reservation
                syncRoomStatus($roomId);
                break;
        }

        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── mark_clean ───────────────────────────────────────────────────────────
    // Direct action from layout right-click menu (older path, kept for compat)
    if ($action === 'mark_clean') {
        db_update_room_meta($roomId, 'Clean', null, null, null);
        db_audit_log('room.mark_clean', 'room', $roomId, $room['room_number'], null);
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── mark_dirty ───────────────────────────────────────────────────────────
    if ($action === 'mark_dirty') {
        db_update_room_meta($roomId, 'Dirty', null, null, null);
        db_audit_log('room.mark_dirty', 'room', $roomId, $room['room_number'], null);
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── mark_pending ─────────────────────────────────────────────────────────
    if ($action === 'mark_pending') {
        db_update_room_meta($roomId, 'Pending', null, null, null);
        db_audit_log('room.mark_pending', 'room', $roomId, $room['room_number'], null);
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── set_maintenance ──────────────────────────────────────────────────────
    if ($action === 'set_maintenance') {
        if (!bb_is_admin()) {
            http_response_code(403);
            respond(['success' => false, 'message' => 'Only admins can change maintenance status.']);
        }
        $enable = filter_var($_POST['enable'] ?? false, FILTER_VALIDATE_BOOLEAN);
        db_set_room_status($roomId, $enable ? 'maintenance' : 'available');
        $note = trim($_POST['notes'] ?? '');
        if ($note !== '') {
            db_update_room_meta($roomId, null, $enable ? 'Under Maintenance' : null, null, $note);
        } elseif (!$enable) {
            db_update_room_meta($roomId, null, null, null, null);
        }
        db_audit_log(
            'room.' . ($enable ? 'set_maintenance' : 'clear_maintenance'),
            'room', $roomId, $room['room_number'],
            $note ?: null
        );
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── update_notes ─────────────────────────────────────────────────────────
    if ($action === 'update_notes') {
        $notes = trim($_POST['notes'] ?? '');
        db_update_room_meta($roomId, null, null, null, $notes);
        db_audit_log('room.update_notes', 'room', $roomId, $room['room_number'], null);
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── update_details / update_room_details (admin only) ────────────────────
    // Both action names used: calendar.js sends 'update_details',
    // realtime-room-sync.js sends 'update_room_details'
    if ($action === 'update_details' || $action === 'update_room_details') {
        if (!bb_is_admin()) {
            http_response_code(403);
            respond(['success' => false, 'message' => 'Only admins can edit room details.']);
        }
        $roomNumber    = trim($_POST['room_number'] ?? '');
        $roomType      = trim($_POST['room_type'] ?? '');
        $pricePerNight = (float) ($_POST['price_per_night'] ?? 0);

        if ($roomNumber === '') {
            respond(['success' => false, 'message' => 'Room number is required.']);
        }
        if (db_room_number_taken($room['branch'], $roomNumber, $roomId)) {
            respond(['success' => false, 'message' => 'That room number is already taken on this branch.']);
        }

        db_update_room_details($roomId, $roomNumber, $roomType, $pricePerNight);
        db_audit_log('room.update_details', 'room', $roomId, $roomNumber, "type:$roomType price:$pricePerNight");
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    // ── update_last_occupancy ────────────────────────────────────────────────
    if ($action === 'update_last_occupancy') {
        $date = trim($_POST['date'] ?? '');
        if ($date && !strtotime($date)) {
            respond(['success' => false, 'message' => 'Invalid date.']);
        }
        db_update_room_meta($roomId, null, null, $date ?: null, null);
        $updated = db_find_room($roomId);
        respond(['success' => true, 'room' => roomPayload($updated)]);
    }

    respond(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    error_log("[ERROR] process_room_action.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}