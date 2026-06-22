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
              AND status NOT IN ('cancelled', 'checked_out')
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
            WHERE room_id = ? AND status NOT IN ('cancelled', 'checked_out')
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

/**
 * True if another room in the same branch already has this room number
 * (excludes $excludeRoomId so a room checking against its own current
 * number doesn't flag itself). Mirrors the schema's
 * UNIQUE KEY branch_room (branch, room_number) constraint at the
 * application layer, so a violation surfaces as a normal validation
 * message instead of an uncaught PDO exception.
 */
function db_room_number_taken($branch, $roomNumber, $excludeRoomId = null)
{
    $sql = 'SELECT 1 FROM rooms WHERE branch = ? AND room_number = ?';
    $params = [$branch, $roomNumber];
    if ($excludeRoomId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeRoomId;
    }
    $sql .= ' LIMIT 1';
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/**
 * Updates a room's core identity fields — number, type, nightly price.
 * None of the existing room-update functions touch these (db_set_room_status
 * is day-to-day operational state, db_update_room_meta is cleaning/
 * maintenance/notes); previously the only way to set them was direct
 * DB/seed access, with no admin-facing edit path from either Layout or
 * Calendar.
 */
function db_update_room_details($roomId, $roomNumber, $roomType, $pricePerNight)
{
    $stmt = bb_db()->prepare('UPDATE rooms SET room_number = ?, room_type = ?, price_per_night = ? WHERE id = ?');
    $stmt->execute([$roomNumber, $roomType, $pricePerNight, $roomId]);
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
 * When 'branch' is supplied, restricts to entries whose target room
 * (directly for room actions, via the reservation for reservation actions)
 * belongs to that branch — so each property sees only its own activity.
 */
function db_list_audit_log($filters = [])
{
    $where  = ['1=1'];
    $params = [];

    // Branch filter: join rooms/reservations to find branch-specific entries.
    // Auth, user, and profile actions are system-wide and excluded from a
    // branch-filtered view since they're not tied to any specific property.
    $joinClause = '';
    if (!empty($filters['branch'])) {
        $joinClause = "
            LEFT JOIN rooms          r_direct ON (al.target_type = 'room'        AND r_direct.id = al.target_id)
            LEFT JOIN reservations   resv_j   ON (al.target_type = 'reservation' AND resv_j.id   = al.target_id)
            LEFT JOIN rooms          r_resv   ON resv_j.room_id = r_resv.id";
        $where[]  = "(r_direct.branch = ? OR r_resv.branch = ?)";
        $params[] = $filters['branch'];
        $params[] = $filters['branch'];
    }

    if (!empty($filters['user_id'])) {
        $where[]  = 'al.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $where[]  = 'al.action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['target_type'])) {
        $where[]  = 'al.target_type = ?';
        $params[] = $filters['target_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'al.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'al.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $like     = '%' . $filters['search'] . '%';
        $where[]  = '(al.username LIKE ? OR al.full_name LIKE ? OR al.target_label LIKE ? OR al.details LIKE ?)';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }

    $limit  = isset($filters['limit'])  ? (int) $filters['limit']  : 200;
    $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

    $sql = 'SELECT al.* FROM audit_log al ' . $joinClause
         . ' WHERE ' . implode(' AND ', $where)
         . ' ORDER BY al.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_count_audit_log($filters = [])
{
    $where  = ['1=1'];
    $params = [];

    $joinClause = '';
    if (!empty($filters['branch'])) {
        $joinClause = "
            LEFT JOIN rooms          r_direct ON (al.target_type = 'room'        AND r_direct.id = al.target_id)
            LEFT JOIN reservations   resv_j   ON (al.target_type = 'reservation' AND resv_j.id   = al.target_id)
            LEFT JOIN rooms          r_resv   ON resv_j.room_id = r_resv.id";
        $where[]  = "(r_direct.branch = ? OR r_resv.branch = ?)";
        $params[] = $filters['branch'];
        $params[] = $filters['branch'];
    }

    if (!empty($filters['user_id'])) {
        $where[]  = 'al.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $where[]  = 'al.action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['target_type'])) {
        $where[]  = 'al.target_type = ?';
        $params[] = $filters['target_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'al.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'al.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $like     = '%' . $filters['search'] . '%';
        $where[]  = '(al.username LIKE ? OR al.full_name LIKE ? OR al.target_label LIKE ? OR al.details LIKE ?)';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql  = 'SELECT COUNT(*) FROM audit_log al ' . $joinClause
          . ' WHERE ' . implode(' AND ', $where);
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function db_list_audit_users()
{
    $stmt = bb_db()->query('SELECT DISTINCT user_id, username, full_name FROM audit_log WHERE user_id IS NOT NULL ORDER BY username ASC');
    return $stmt->fetchAll();
}

/* ============================================================================
 * Reports & Analytics
 *
 * Every figure here is derived straight from reservations + rooms — there's
 * no separate payments/invoices table, so "revenue" reads total_amount and
 * "collected" reads amount_paid on the reservation itself.
 *
 * Two different ways of counting are used on purpose:
 *   - "Booked in period"  -> r.check_in falls inside [start, end). This is
 *     how Revenue, Collected, Reservations and Cancellations are counted —
 *     it answers "what did the bookings arriving this period add up to".
 *   - "Occupied in period" -> the stay's [check_in, check_out) date range
 *     overlaps [start, end), restricted to status IN ('checked_in',
 *     'checked_out') since only those represent nights a room was actually
 *     slept in. This is how Occupancy/ADR/RevPAR are counted, and a stay
 *     that crosses a period boundary only contributes the nights that fall
 *     inside that period (via LEAST/GREATEST + DATEDIFF).
 *
 * $branch is one of 'annex' | 'mtv' | 'dormitel' | 'all' (all three lodging
 * branches combined). $rangeStart/$rangeEnd are 'YYYY-MM-DD' strings, end
 * exclusive — same half-open convention as db_list_reservations_in_range().
 * ========================================================================= */

const BB_LODGING_BRANCHES = ['annex', 'mtv', 'dormitel'];

/**
 * WHERE-clause fragment + params for filtering rooms/reservations (joined
 * to rooms as alias `ro`) down to one branch or all lodging branches.
 */
function db_report_branch_filter($branch)
{
    if ($branch === 'all' || $branch === null || $branch === '') {
        $placeholders = implode(',', array_fill(0, count(BB_LODGING_BRANCHES), '?'));
        return ['ro.branch IN (' . $placeholders . ')', BB_LODGING_BRANCHES];
    }
    return ['ro.branch = ?', [$branch]];
}

function db_report_room_count($branch)
{
    [$where, $params] = db_report_branch_filter($branch);
    $stmt = bb_db()->prepare("SELECT COUNT(*) FROM rooms ro WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Headline KPI block for one branch (or 'all') over [rangeStart, rangeEnd).
 * Returns booked-in-period totals (revenue/collected/reservations/
 * cancellations) and occupied-in-period totals (nights/ADR/occupancy/RevPAR)
 * in a single flat array, plus the room count and day count used to derive
 * the rates — so the view layer never has to re-derive anything.
 */
function db_report_kpis($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);

    // ── Occupied-in-period: actual stays only (checked_in / checked_out) ──
    $sqlOccupied = "
        SELECT
            COALESCE(SUM(DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS occupied_nights,
            COALESCE(SUM(r.room_rate * DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS room_revenue,
            COALESCE(AVG(DATEDIFF(r.check_out, r.check_in)), 0) AS avg_los,
            COUNT(*) AS stays_count
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where
          AND r.status IN ('checked_in','checked_out')
          AND r.check_in < ? AND r.check_out > ?
    ";
    $stmt = bb_db()->prepare($sqlOccupied);
    $stmt->execute(array_merge([$rangeEnd, $rangeStart, $rangeEnd, $rangeStart], $params, [$rangeEnd, $rangeStart]));
    $occ = $stmt->fetch();

    // ── Booked-in-period: revenue/collected/reservation & cancellation counts ──
    $sqlBooked = "
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count,
            COALESCE(SUM(CASE WHEN r.status = 'cancelled' THEN r.total_amount ELSE 0 END), 0) AS cancelled_amount,
            COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN r.total_amount ELSE 0 END), 0) AS billed,
            COALESCE(SUM(CASE WHEN r.status != 'cancelled' THEN r.amount_paid ELSE 0 END), 0) AS collected
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where
          AND r.check_in >= ? AND r.check_in < ?
    ";
    $stmt = bb_db()->prepare($sqlBooked);
    $stmt->execute(array_merge($params, [$rangeStart, $rangeEnd]));
    $booked = $stmt->fetch();

    $roomCount = db_report_room_count($branch);
    $days = max(1, (int) (new DateTime($rangeStart))->diff(new DateTime($rangeEnd))->days);
    $availableNights = $roomCount * $days;

    $occupiedNights = (float) $occ['occupied_nights'];
    $roomRevenue    = (float) $occ['room_revenue'];
    $billed         = (float) $booked['billed'];
    $collected      = (float) $booked['collected'];
    $totalCount     = (int) $booked['total_count'];
    $cancelledCount = (int) $booked['cancelled_count'];

    return [
        'room_count'        => $roomCount,
        'days'              => $days,
        'available_nights'  => $availableNights,
        'occupied_nights'   => $occupiedNights,
        'occupancy_rate'    => $availableNights > 0 ? round($occupiedNights / $availableNights * 100, 1) : 0.0,
        'room_revenue'      => $roomRevenue,
        'adr'               => $occupiedNights > 0 ? round($roomRevenue / $occupiedNights, 2) : 0.0,
        'revpar'            => $availableNights > 0 ? round($roomRevenue / $availableNights, 2) : 0.0,
        'avg_los'           => round((float) $occ['avg_los'], 1),
        'billed'            => $billed,
        'collected'         => $collected,
        'outstanding'       => round($billed - $collected, 2),
        'reservation_count' => $totalCount - $cancelledCount,
        'cancelled_count'   => $cancelledCount,
        'cancelled_amount'  => (float) $booked['cancelled_amount'],
        'cancellation_rate' => $totalCount > 0 ? round($cancelledCount / $totalCount * 100, 1) : 0.0,
    ];
}

/**
 * One row per month for the trailing $monthsBack months (oldest first,
 * current month last) — booked revenue + occupied-nights occupancy, ready
 * for the trend chart. Each month is computed with the same logic as
 * db_report_kpis(), just looped — simple and easy to follow rather than a
 * single clever recursive query, since this only ever runs a handful of
 * times per page load.
 */
function db_report_monthly_trend($branch, $monthsBack = 6)
{
    $rows = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . ($monthsBack - 1) . ' months');

    for ($i = 0; $i < $monthsBack; $i++) {
        $monthStart = $cursor->format('Y-m-d');
        $monthEnd   = (clone $cursor)->modify('+1 month')->format('Y-m-d');

        $kpis = db_report_kpis($branch, $monthStart, $monthEnd);
        $rows[] = [
            'month'           => $cursor->format('Y-m'),
            'label'           => $cursor->format('M Y'),
            'billed'          => $kpis['billed'],
            'collected'       => $kpis['collected'],
            'occupancy_rate'  => $kpis['occupancy_rate'],
            'occupied_nights' => $kpis['occupied_nights'],
            'reservations'    => $kpis['reservation_count'],
        ];

        $cursor->modify('+1 month');
    }

    return $rows;
}

/**
 * Reservation counts + billed revenue grouped by lifecycle status, for the
 * status-mix chart. Booked-in-period (check_in based), same convention as
 * db_report_kpis().
 */
function db_report_status_breakdown($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);
    $sql = "
        SELECT r.status, COUNT(*) AS cnt, COALESCE(SUM(r.total_amount), 0) AS total
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where AND r.check_in >= ? AND r.check_in < ?
        GROUP BY r.status
    ";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute(array_merge($params, [$rangeStart, $rangeEnd]));
    $out = ['reserved' => 0, 'checked_in' => 0, 'checked_out' => 0, 'cancelled' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['status']] = (int) $row['cnt'];
    }
    return $out;
}

/**
 * How guests are paying — grouped by payment_method among non-cancelled
 * bookings in period that have actually recorded a method (a reservation
 * with no payment yet has payment_method = NULL and is excluded).
 */
function db_report_payment_breakdown($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);
    $sql = "
        SELECT r.payment_method, COUNT(*) AS cnt, COALESCE(SUM(r.amount_paid), 0) AS collected
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where AND r.status != 'cancelled'
          AND r.check_in >= ? AND r.check_in < ?
          AND r.payment_method IS NOT NULL
        GROUP BY r.payment_method
        ORDER BY collected DESC
    ";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute(array_merge($params, [$rangeStart, $rangeEnd]));
    return $stmt->fetchAll();
}

/**
 * Per room-type performance: how many rooms of that type exist, how many
 * nights they sold (occupied-in-period), the resulting occupancy % and
 * ADR for that type specifically, and total room revenue.
 */
function db_report_room_type_performance($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);
    $days = max(1, (int) (new DateTime($rangeStart))->diff(new DateTime($rangeEnd))->days);

    // Room counts per type (independent of bookings — a type with zero
    // bookings this period should still show up with 0s, not disappear).
    $sqlTypes = "SELECT ro.room_type, COUNT(*) AS room_count FROM rooms ro WHERE $where GROUP BY ro.room_type";
    $stmt = bb_db()->prepare($sqlTypes);
    $stmt->execute($params);
    $types = [];
    foreach ($stmt->fetchAll() as $row) {
        $types[$row['room_type']] = [
            'room_type'       => $row['room_type'],
            'room_count'      => (int) $row['room_count'],
            'occupied_nights' => 0,
            'revenue'         => 0.0,
            'reservations'    => 0,
        ];
    }

    $sqlPerf = "
        SELECT
            ro.room_type,
            COUNT(*) AS reservations,
            COALESCE(SUM(DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS occupied_nights,
            COALESCE(SUM(r.room_rate * DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS revenue
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where
          AND r.status IN ('checked_in','checked_out')
          AND r.check_in < ? AND r.check_out > ?
        GROUP BY ro.room_type
    ";
    $stmt = bb_db()->prepare($sqlPerf);
    $stmt->execute(array_merge([$rangeEnd, $rangeStart, $rangeEnd, $rangeStart], $params, [$rangeEnd, $rangeStart]));
    foreach ($stmt->fetchAll() as $row) {
        $type = $row['room_type'];
        if (!isset($types[$type])) {
            $types[$type] = ['room_type' => $type, 'room_count' => 0, 'occupied_nights' => 0, 'revenue' => 0.0, 'reservations' => 0];
        }
        $types[$type]['occupied_nights'] = (int) $row['occupied_nights'];
        $types[$type]['revenue']         = (float) $row['revenue'];
        $types[$type]['reservations']    = (int) $row['reservations'];
    }

    foreach ($types as &$t) {
        $availableNights = $t['room_count'] * $days;
        $t['occupancy_rate'] = $availableNights > 0 ? round($t['occupied_nights'] / $availableNights * 100, 1) : 0.0;
        $t['adr'] = $t['occupied_nights'] > 0 ? round($t['revenue'] / $t['occupied_nights'], 2) : 0.0;
    }
    unset($t);

    $types = array_values($types);
    usort($types, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    return $types;
}

/**
 * Top individual rooms by room revenue (occupied-in-period), for the
 * "best performers" leaderboard.
 */
function db_report_top_rooms($branch, $rangeStart, $rangeEnd, $limit = 8)
{
    [$where, $params] = db_report_branch_filter($branch);
    $limit = max(1, (int) $limit);
    $sql = "
        SELECT
            ro.id, ro.branch, ro.room_number, ro.room_type,
            COUNT(*) AS reservations,
            COALESCE(SUM(DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS occupied_nights,
            COALESCE(SUM(r.room_rate * DATEDIFF(LEAST(r.check_out, ?), GREATEST(r.check_in, ?))), 0) AS revenue
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where
          AND r.status IN ('checked_in','checked_out')
          AND r.check_in < ? AND r.check_out > ?
        GROUP BY ro.id, ro.branch, ro.room_number, ro.room_type
        ORDER BY revenue DESC
        LIMIT $limit
    ";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute(array_merge([$rangeEnd, $rangeStart, $rangeEnd, $rangeStart], $params, [$rangeEnd, $rangeStart]));
    return $stmt->fetchAll();
}

/**
 * Side-by-side summary for every lodging branch, regardless of which
 * branch tab is currently selected — used by the "All Lodging" view to
 * compare properties. Branches with zero rooms (no layout built yet)
 * still appear with all-zero figures rather than being omitted.
 */
function db_report_branch_comparison($rangeStart, $rangeEnd)
{
    $rows = [];
    foreach (BB_LODGING_BRANCHES as $branch) {
        $kpis = db_report_kpis($branch, $rangeStart, $rangeEnd);
        $rows[] = array_merge(['branch' => $branch], $kpis);
    }
    return $rows;
}

/**
 * Current accounts-receivable snapshot — every non-cancelled reservation
 * with a positive balance, regardless of date, ordered by largest balance
 * first. This is intentionally NOT period-filtered: an unpaid balance
 * from three months ago is still owed today.
 */
function db_report_outstanding_balances($branch, $limit = 15)
{
    [$where, $params] = db_report_branch_filter($branch);
    $limit = max(1, (int) $limit);
    $sql = "
        SELECT
            r.id, r.guest_full_name, r.contact_number, r.check_in, r.check_out, r.status,
            r.total_amount, r.amount_paid, (r.total_amount - r.amount_paid) AS balance,
            ro.branch, ro.room_number, ro.room_type
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where
          AND r.status != 'cancelled'
          AND r.total_amount > r.amount_paid
        ORDER BY balance DESC
        LIMIT $limit
    ";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Earliest check_in on record for this branch (or all lodging branches),
 * used as the start bound for the "All Time" report range. Null if there
 * are no reservations yet.
 */
function db_report_earliest_checkin($branch)
{
    [$where, $params] = db_report_branch_filter($branch);
    $sql = "SELECT MIN(r.check_in) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE $where";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val ?: null;
}

/**
 * Total of every positive balance across non-cancelled reservations for
 * this branch, regardless of date — the headline number the outstanding
 * balances table is summarizing.
 */
function db_report_total_outstanding($branch)
{
    [$where, $params] = db_report_branch_filter($branch);
    $sql = "
        SELECT COALESCE(SUM(r.total_amount - r.amount_paid), 0)
        FROM reservations r
        JOIN rooms ro ON ro.id = r.room_id
        WHERE $where AND r.status != 'cancelled' AND r.total_amount > r.amount_paid
    ";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}