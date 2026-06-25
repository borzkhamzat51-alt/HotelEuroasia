<?php
/**
 * db.php
 * Every direct database query lives here, behind small named functions.
 * Added debug logging for reservation range queries.
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

function db_create_staff($username, $email, $passwordHash, $fullName, $permissionsCsv)
{
    $stmt = bb_db()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, permissions) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, $email, $passwordHash, $fullName, BB_ROLE_STAFF, $permissionsCsv]);
    return (int) bb_db()->lastInsertId();
}

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

function db_list_reservations_in_range($roomIds, $rangeStart, $rangeEnd)
{
    if (empty($roomIds)) {
        error_log("[db_list_reservations_in_range] No room IDs provided.");
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
    $params = array_merge($roomIds, [$rangeEnd, $rangeStart]);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    error_log("[db_list_reservations_in_range] Range: $rangeStart to $rangeEnd, Room IDs: " . implode(',', $roomIds) . ", Found: " . count($results) . " reservations.");
    if (count($results) > 0) {
        error_log("[db_list_reservations_in_range] First reservation: " . print_r($results[0], true));
    } else {
        error_log("[db_list_reservations_in_range] No reservations found.");
    }
    return $results;
}

function db_find_reservation($id)
{
    $stmt = bb_db()->prepare('SELECT * FROM reservations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

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

function db_create_reservation($data)
{
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
        'expected_payment_date' => null,
    ];
    $required = ['room_id', 'guest_full_name', 'check_in', 'check_out', 'num_adults', 'status', 'user_id'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }
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
             notes, special_requests, created_by, updated_by, expected_payment_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['room_id'], $data['guest_full_name'], $data['contact_number'], $data['email'], $data['address'],
        $data['valid_id_type'], $data['valid_id_number'], $data['check_in'], $data['check_out'],
        $data['num_adults'], $data['num_children'], $data['status'],
        $data['room_rate'], $data['security_deposit'], $data['total_amount'], $data['amount_paid'], $data['payment_method'],
        $data['notes'], $data['special_requests'], $data['user_id'], $data['user_id'],
        $data['expected_payment_date'],
    ]);
    $newId = (int) bb_db()->lastInsertId();
    error_log("[db_create_reservation] Created reservation ID: $newId, room_id: " . $data['room_id'] . ", status: " . $data['status'] . ", dates: " . $data['check_in'] . " to " . $data['check_out']);
    return $newId;
}

function db_update_reservation($id, $data)
{
    $required = ['room_id', 'user_id'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            throw new Exception("Missing required field: $field");
        }
    }

    $existing = db_find_reservation($id);
    if (!$existing) {
        throw new Exception("Reservation $id not found");
    }
    $mergeableFields = [
        'guest_full_name', 'contact_number', 'email', 'address', 'valid_id_type', 'valid_id_number',
        'check_in', 'check_out', 'num_adults', 'num_children', 'status',
        'room_rate', 'security_deposit', 'total_amount', 'amount_paid', 'payment_method',
        'notes', 'special_requests', 'expected_payment_date',
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
            notes = ?, special_requests = ?, updated_by = ?, expected_payment_date = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $data['room_id'], $data['guest_full_name'], $data['contact_number'], $data['email'], $data['address'],
        $data['valid_id_type'], $data['valid_id_number'], $data['check_in'], $data['check_out'],
        $data['num_adults'], $data['num_children'], $data['status'],
        $data['room_rate'], $data['security_deposit'], $data['total_amount'], $data['amount_paid'], $data['payment_method'],
        $data['notes'], $data['special_requests'], $data['user_id'],
        $data['expected_payment_date'], $id,
    ]);
    error_log("[db_update_reservation] Updated reservation ID: $id, room_id: " . $data['room_id'] . ", status: " . $data['status']);
}

function db_delete_reservation($id)
{
    $stmt = bb_db()->prepare('DELETE FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    error_log("[db_delete_reservation] Deleted reservation ID: $id");
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

function db_find_active_reservation_for_room($roomId, $date = null)
{
    $date = $date ?: date('Y-m-d');
    $stmt = bb_db()->prepare(
        "SELECT * FROM reservations
         WHERE room_id = ? AND status IN ('reserved','checked_in')
           AND check_in <= ? AND check_out > ?
         ORDER BY (status = 'checked_in') DESC, check_in ASC
         LIMIT 1"
    );
    $stmt->execute([$roomId, $date, $date]);
    return $stmt->fetch() ?: null;
}

/* ============================================================================
 * Payments — Phase 1 Financial Foundation
 * ========================================================================= */

function db_list_payments(int $reservationId): array
{
    $stmt = bb_db()->prepare(
        'SELECT p.*, u.username, u.full_name
         FROM payments p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.reservation_id = ?
         ORDER BY p.payment_date ASC, p.id ASC'
    );
    $stmt->execute([$reservationId]);
    return $stmt->fetchAll();
}

function db_create_payment(array $data): int
{
    $stmt = bb_db()->prepare(
        'INSERT INTO payments (reservation_id, amount, payment_date, payment_method, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['reservation_id'],
        (float)$data['amount'],
        $data['payment_date'],
        $data['payment_method'] ?: null,
        $data['remarks'] ?: null,
        $data['created_by'] ?? null,
    ]);
    return (int)bb_db()->lastInsertId();
}

function db_delete_payment(int $paymentId): void
{
    $stmt = bb_db()->prepare('DELETE FROM payments WHERE id = ?');
    $stmt->execute([$paymentId]);
}

function db_find_payment(int $id): ?array
{
    $stmt = bb_db()->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function db_get_payment_months(int $reservationId): array
{
    return db_list_payments($reservationId);
}

function db_get_outstanding_balance(int $reservationId): float
{
    if (!function_exists('fin_outstanding_balance')) {
        return 0.0;
    }
    $resv = db_find_reservation($reservationId);
    if (!$resv) return 0.0;
    return fin_outstanding_balance($resv);
}

function db_get_payment_status(int $reservationId): string
{
    if (!function_exists('fin_payment_status')) {
        return 'Unknown';
    }
    $resv = db_find_reservation($reservationId);
    if (!$resv) return 'Unknown';
    return fin_payment_status($resv);
}

function db_set_room_status($roomId, $status)
{
    $stmt = bb_db()->prepare('UPDATE rooms SET room_status = ? WHERE id = ?');
    $stmt->execute([$status, $roomId]);
}

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

function db_update_room_details($roomId, $roomNumber, $roomType, $pricePerNight)
{
    $stmt = bb_db()->prepare('UPDATE rooms SET room_number = ?, room_type = ?, price_per_night = ? WHERE id = ?');
    $stmt->execute([$roomNumber, $roomType, $pricePerNight, $roomId]);
}

/* ============================================================================
 * Audit Log
 * ========================================================================= */

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
        error_log('audit_log error: ' . $e->getMessage());
    }
}

function db_list_audit_log($filters = [])
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
 * ========================================================================= */

const BB_LODGING_BRANCHES = ['annex', 'mtv', 'dormitel'];

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

function db_report_kpis($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);

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

function db_report_room_type_performance($branch, $rangeStart, $rangeEnd)
{
    [$where, $params] = db_report_branch_filter($branch);
    $days = max(1, (int) (new DateTime($rangeStart))->diff(new DateTime($rangeEnd))->days);

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

function db_report_branch_comparison($rangeStart, $rangeEnd)
{
    $rows = [];
    foreach (BB_LODGING_BRANCHES as $branch) {
        $kpis = db_report_kpis($branch, $rangeStart, $rangeEnd);
        $rows[] = array_merge(['branch' => $branch], $kpis);
    }
    return $rows;
}

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

function db_report_earliest_checkin($branch)
{
    [$where, $params] = db_report_branch_filter($branch);
    $sql = "SELECT MIN(r.check_in) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE $where";
    $stmt = bb_db()->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val ?: null;
}

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

function db_count_checked_in($branch)
{
    if ($branch === 'all' || $branch === null || $branch === '') {
        $stmt = bb_db()->prepare('SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE r.status = "checked_in"');
        $stmt->execute();
    } else {
        $stmt = bb_db()->prepare('SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE r.status = "checked_in" AND ro.branch = ?');
        $stmt->execute([$branch]);
    }
    return (int) $stmt->fetchColumn();
}