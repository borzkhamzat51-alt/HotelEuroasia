<?php
/**
 * db.php
 * Every direct database query lives here, behind small named functions.
 */

function db_find_user_by_username($username)
{
    $stmt = bb_db()->prepare('SELECT id, username, email, password_hash, role, permissions, full_name FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function db_find_user_by_id($id)
{
    $stmt = bb_db()->prepare('SELECT id, username, email, role, permissions, full_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function db_list_users()
{
    $stmt = bb_db()->query('SELECT id, username, email, full_name, role, permissions FROM users ORDER BY role DESC, username ASC');
    return $stmt->fetchAll();
}

function db_username_taken($username, $excludeId = null)
{
    if ($excludeId !== null) {
        $stmt = bb_db()->prepare('SELECT 1 FROM users WHERE username = ? AND id != ? LIMIT 1');
        $stmt->execute([$username, $excludeId]);
    } else {
        $stmt = bb_db()->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
    }
    return (bool) $stmt->fetchColumn();
}

function db_email_taken($email, $excludeId = null)
{
    if ($excludeId !== null) {
        $stmt = bb_db()->prepare('SELECT 1 FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([$email, $excludeId]);
    } else {
        $stmt = bb_db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
    }
    return (bool) $stmt->fetchColumn();
}

/**
 * Creates a staff account with a specific permission set. Never used
 * to create an admin — see db_create_admin() for that.
 */
function db_create_staff($username, $email, $passwordHash, $fullName, $permissionsCsv)
{
    $stmt = bb_db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, permissions) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, $email, $passwordHash, $fullName, BB_ROLE_STAFF, $permissionsCsv]);
    return (int) bb_db()->lastInsertId();
}

/**
 * Creates an admin account. Permissions column stays NULL — admins
 * don't need one, bb_has_permission() always returns true for them.
 */
function db_create_admin($username, $email, $passwordHash, $fullName)
{
    $stmt = bb_db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, permissions) VALUES (?, ?, ?, ?, ?, NULL)'
    );
    $stmt->execute([$username, $email, $passwordHash, $fullName, BB_ROLE_ADMIN]);
    return (int) bb_db()->lastInsertId();
}

function db_update_profile($id, $fullName, $username, $email)
{
    $stmt = bb_db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?');
    $stmt->execute([$fullName, $username, $email, $id]);
}

function db_update_password($id, $passwordHash)
{
    $stmt = bb_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$passwordHash, $id]);
}

function db_get_password_hash($id)
{
    $stmt = bb_db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}

function db_delete_user($id)
{
    $stmt = bb_db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

/* ============================================================================
 * Calendar / Reservations
 * ========================================================================= */

function db_list_rooms_by_branch($branch)
{
    $stmt = bb_db()->prepare('SELECT * FROM rooms WHERE branch = ? ORDER BY room_number ASC');
    $stmt->execute([$branch]);
    return $stmt->fetchAll();
}

function db_find_room($id)
{
    $stmt = bb_db()->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $room = $stmt->fetch();
    return $room ?: null;
}

/**
 * All non-cancelled reservations for the given room ids that overlap
 * [$rangeStart, $rangeEnd] (inclusive), for rendering the calendar grid.
 */
function db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd)
{
    if (empty($roomIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $sql = "SELECT * FROM reservations
            WHERE room_id IN ($placeholders)
              AND status != 'cancelled'
              AND check_in < ?
              AND check_out > ?
            ORDER BY check_in ASC";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute(array_merge($roomIds, [$rangeEnd, $rangeStart]));
    return $stmt->fetchAll();
}

function db_find_reservation($id)
{
    $stmt = bb_db()->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * True if [$checkIn, $checkOut) overlaps an existing non-cancelled
 * reservation on this room. Checkout day itself doesn't count as
 * occupied (same-day turnover is allowed), matching normal hotel rules.
 */
function db_room_has_conflict($roomId, $checkIn, $checkOut, $excludeReservationId = null)
{
    $sql = "SELECT 1 FROM reservations
            WHERE room_id = ? AND status != 'cancelled'
              AND check_in < ? AND check_out > ?";
    $params = [$roomId, $checkOut, $checkIn];
    if ($excludeReservationId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeReservationId;
    }
    $sql .= ' LIMIT 1';
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

// ─── UPDATED: db_create_reservation with required field checks and defaults ───
function db_create_reservation($data)
{
    // Provide safe defaults for all optional columns
    $defaults = [
        'contact_number'   => '',
        'email'            => '',
        'address'          => '',
        'valid_id_type'    => '',
        'valid_id_number'  => '',
        'num_children'     => 0,
        'room_rate'        => 0,
        'security_deposit' => 0,
        'total_amount'     => 0,
        'amount_paid'      => 0,
        'payment_method'   => null,
        'notes'            => '',
        'special_requests' => '',
    ];
    // Required fields – will throw exception if missing
    $required = ['room_id', 'guest_full_name', 'check_in', 'check_out', 'num_adults', 'status', 'user_id'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }
    // Apply defaults
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            $data[$key] = $default;
        }
    }

    $stmt = bb_db()->prepare(
        'INSERT INTO reservations
            (room_id, guest_full_name, contact_number, email, address, valid_id_type, valid_id_number,
             check_in, check_out, num_adults, num_children, status,
             room_rate, security_deposit, total_amount, amount_paid, payment_method,
             notes, special_requests, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['room_id'], $data['guest_full_name'], $data['contact_number'], $data['email'], $data['address'],
        $data['valid_id_type'], $data['valid_id_number'], $data['check_in'], $data['check_out'],
        $data['num_adults'], $data['num_children'], $data['status'],
        $data['room_rate'], $data['security_deposit'], $data['total_amount'], $data['amount_paid'], $data['payment_method'],
        $data['notes'], $data['special_requests'], $data['user_id'], $data['user_id'],
    ]);
    return (int) bb_db()->lastInsertId();
}

// ─── UPDATED: db_update_reservation with required field checks and defaults ───
function db_update_reservation($id, $data)
{
    $required = ['room_id', 'user_id'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }

    // Merge onto the existing row so partial updates (e.g. just flipping
    // status on check-in/check-out) don't blank out the rest of the
    // reservation's data or null out NOT NULL columns like check_in.
    $existing = db_find_reservation($id);
    if (!$existing) {
        throw new Exception("Reservation $id not found");
    }
    $mergeableFields = [
        'guest_full_name', 'contact_number', 'email', 'address', 'valid_id_type', 'valid_id_number',
        'check_in', 'check_out', 'num_adults', 'num_children', 'status',
        'room_rate', 'security_deposit', 'total_amount', 'amount_paid', 'payment_method',
        'notes', 'special_requests',
    ];
    foreach ($mergeableFields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            $data[$field] = $existing[$field];
        }
    }

    $stmt = bb_db()->prepare(
        'UPDATE reservations SET
            room_id = ?, guest_full_name = ?, contact_number = ?, email = ?, address = ?,
            valid_id_type = ?, valid_id_number = ?, check_in = ?, check_out = ?,
            num_adults = ?, num_children = ?, status = ?,
            room_rate = ?, security_deposit = ?, total_amount = ?, amount_paid = ?, payment_method = ?,
            notes = ?, special_requests = ?, updated_by = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $data['room_id'], $data['guest_full_name'], $data['contact_number'], $data['email'], $data['address'],
        $data['valid_id_type'], $data['valid_id_number'], $data['check_in'], $data['check_out'],
        $data['num_adults'], $data['num_children'], $data['status'],
        $data['room_rate'], $data['security_deposit'], $data['total_amount'], $data['amount_paid'], $data['payment_method'],
        $data['notes'], $data['special_requests'], $data['user_id'], $id,
    ]);
}

function db_delete_reservation($id)
{
    $stmt = bb_db()->prepare('DELETE FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
}

function db_log_reservation_activity($reservationId, $userId, $action, $details)
{
    $stmt = bb_db()->prepare(
        'INSERT INTO reservation_activity_log (reservation_id, user_id, action, details) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$reservationId, $userId, $action, $details]);
}

function db_get_reservation_activity($reservationId)
{
    $stmt = bb_db()->prepare(
        'SELECT l.*, u.username, u.full_name
         FROM reservation_activity_log l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.reservation_id = ?
         ORDER BY l.created_at DESC'
    );
    $stmt->execute([$reservationId]);
    return $stmt->fetchAll();
}

function db_find_active_reservation_for_room($roomId)
{
    $stmt = bb_db()->prepare("SELECT * FROM reservations WHERE room_id = ? AND status NOT IN ('cancelled','checked_out') LIMIT 1");
    $stmt->execute([$roomId]);
    return $stmt->fetch() ?: null;
}

/**
 * The floor-plan console's headline badge state: available / occupied /
 * reserved / maintenance. Lives on the room itself, separate from any
 * one reservation's lifecycle status.
 */
function db_set_room_status($roomId, $status)
{
    $stmt = bb_db()->prepare('UPDATE rooms SET room_status = ? WHERE id = ?');
    $stmt->execute([$status, $roomId]);
}

/**
 * Updates only the fields that are passed as non-null — callers like
 * "check out" only want to bump last_occupancy without touching
 * cleaning/maintenance/notes, and passing null for those previously
 * meant "wipe this field," which silently erased real data on every
 * partial update. null now means "leave it alone."
 */
function db_update_room_meta($roomId, $cleaning, $maintenance, $lastOccupancy, $notes)
{
    $fields = [];
    $params = [];
    if ($cleaning !== null) { $fields[] = 'cleaning_status = ?'; $params[] = $cleaning; }
    if ($maintenance !== null) { $fields[] = 'maintenance_status = ?'; $params[] = $maintenance; }
    if ($lastOccupancy !== null) { $fields[] = 'last_occupancy = ?'; $params[] = $lastOccupancy; }
    if ($notes !== null) { $fields[] = 'staff_notes = ?'; $params[] = $notes; }
    if (empty($fields)) {
        return;
    }
    $params[] = $roomId;
    $stmt = bb_db()->prepare('UPDATE rooms SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
}

/* ============================================================================
 * Audit Log
 * ========================================================================= */

/**
 * Write one audit entry. Safe to call anywhere — silently swallows DB
 * errors so a logging failure never crashes the actual operation.
 *
 * @param string      $action       Dot-namespaced action key e.g. 'reservation.create'
 * @param string|null $targetType   e.g. 'reservation', 'user', 'room'
 * @param int|null    $targetId     PK of the affected row
 * @param string|null $targetLabel  Human-readable label (guest name, room no., etc.)
 * @param string|null $details      Extra context (plain text or JSON string)
 */
function db_audit_log($action, $targetType = null, $targetId = null, $targetLabel = null, $details = null)
{
    try {
        $userId    = $_SESSION['user_id']   ?? null;
        $username  = $_SESSION['username']  ?? null;
        $fullName  = $_SESSION['full_name'] ?? null;
        $role      = $_SESSION['role']      ?? null;
        $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = bb_db()->prepare(
            'INSERT INTO audit_log
                (user_id, username, full_name, role, action, target_type, target_id, target_label, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $username, $fullName, $role, $action, $targetType, $targetId, $targetLabel, $details, $ip]);
    } catch (Exception $e) {
        // Never let audit logging crash the main operation
        error_log('audit_log error: ' . $e->getMessage());
    }
}

/**
 * Fetch audit log entries with optional filters.
 * Returns newest-first by default.
 */
function db_list_audit_log($filters = [])
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[]  = 'user_id = ?';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $where[]  = 'action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['target_type'])) {
        $where[]  = 'target_type = ?';
        $params[] = $filters['target_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $like     = '%' . $filters['search'] . '%';
        $where[]  = '(username LIKE ? OR full_name LIKE ? OR target_label LIKE ? OR details LIKE ?)';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }

    $limit  = isset($filters['limit']) ? (int) $filters['limit'] : 200;
    $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

    $sql = 'SELECT * FROM audit_log WHERE ' . implode(' AND ', $where)
         . ' ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_count_audit_log($filters = [])
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[]  = 'user_id = ?';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $where[]  = 'action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['target_type'])) {
        $where[]  = 'target_type = ?';
        $params[] = $filters['target_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $like     = '%' . $filters['search'] . '%';
        $where[]  = '(username LIKE ? OR full_name LIKE ? OR target_label LIKE ? OR details LIKE ?)';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql  = 'SELECT COUNT(*) FROM audit_log WHERE ' . implode(' AND ', $where);
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function db_list_audit_users()
{
    $stmt = bb_db()->query('SELECT DISTINCT user_id, username, full_name FROM audit_log WHERE user_id IS NOT NULL ORDER BY username ASC');
    return $stmt->fetchAll();
}