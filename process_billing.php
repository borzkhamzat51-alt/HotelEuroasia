<?php
/**
 * process_billing.php — Billing Module API
 * Handles: utility readings, monthly bills, billing settings, statement of account
 * All actions are property-scoped via the 'branch' parameter.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
bb_require_permission('billing');

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$branch = $_GET['branch'] ?? $_POST['branch'] ?? '';
$userId = $_SESSION['user_id'] ?? 0;
$userName = $_SESSION['full_name'] ?: ($_SESSION['username'] ?? 'System');

function json_out($data) { echo json_encode($data); exit; }
function json_ok($data = []) { json_out(array_merge(['success' => true], $data)); }
function json_err($msg, $code = 400) { http_response_code($code); json_out(['success' => false, 'message' => $msg]); }

// ── Audit helper ──────────────────────────────────────────────────
function billing_audit($action, $resvId = null, $billId = null, $details = '') {
    global $userId, $userName;
    $pdo = get_pdo();
    $stmt = $pdo->prepare("INSERT INTO billing_audit_log 
        (reservation_id, bill_id, action, details, performed_by, performer_name, ip_address) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$resvId, $billId, $action, $details, $userId, $userName, $_SERVER['REMOTE_ADDR'] ?? '']);
}

// ── Get billing cutoff time for branch ────────────────────────────
function get_cutoff_time($branch) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT setting_value FROM billing_settings WHERE branch = ? AND setting_key = 'billing_cutoff_time'");
    $stmt->execute([$branch]);
    return $stmt->fetchColumn() ?: '17:00';
}

// ── Calculate billing start date using cutoff rule ────────────────
function calc_billing_start($checkInDate, $checkInTime, $branch) {
    $cutoff = get_cutoff_time($branch);
    $time = $checkInTime ?: '12:00'; // default to noon if no time
    if ($time < $cutoff) {
        return $checkInDate; // checked in before cutoff: charge that day
    } else {
        $d = new DateTime($checkInDate);
        $d->modify('+1 day');
        return $d->format('Y-m-d'); // checked in after cutoff: charge starts next day
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION ROUTER
// ═══════════════════════════════════════════════════════════════════

switch ($action) {

// ── UTILITY RATES ─────────────────────────────────────────────────
case 'get_utility_rates':
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM utility_rates WHERE branch = ? AND is_active = 1 ORDER BY utility_type");
    $stmt->execute([$branch]);
    json_ok(['rates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

case 'save_utility_rate':
    $type = $_POST['utility_type'] ?? '';
    $rate = floatval($_POST['rate_per_unit'] ?? 0);
    $unit = $_POST['unit_label'] ?? 'kWh';
    if (!$type || $rate <= 0) json_err('Utility type and rate required.');
    $pdo = get_pdo();
    // Upsert
    $stmt = $pdo->prepare("SELECT id FROM utility_rates WHERE branch = ? AND utility_type = ? AND is_active = 1");
    $stmt->execute([$branch, $type]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare("UPDATE utility_rates SET rate_per_unit = ?, unit_label = ?, updated_at = NOW() WHERE id = ?")->execute([$rate, $unit, $existing]);
    } else {
        $pdo->prepare("INSERT INTO utility_rates (branch, utility_type, rate_per_unit, unit_label) VALUES (?, ?, ?, ?)")->execute([$branch, $type, $rate, $unit]);
    }
    billing_audit('rate_updated', null, null, "$type rate set to $rate per $unit for $branch");
    json_ok();

// ── UTILITY SESSIONS ──────────────────────────────────────────────
case 'start_utility_session':
    $resvId = intval($_POST['reservation_id'] ?? 0);
    $roomId = intval($_POST['room_id'] ?? 0);
    $type   = $_POST['utility_type'] ?? '';
    $initial = floatval($_POST['initial_reading'] ?? 0);
    if (!$resvId || !$type) json_err('Reservation and utility type required.');
    $pdo = get_pdo();
    // Get rate
    $stmt = $pdo->prepare("SELECT rate_per_unit FROM utility_rates WHERE branch = ? AND utility_type = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$branch, $type]);
    $rate = floatval($stmt->fetchColumn()) ?: 0;
    // Check for existing active session
    $stmt = $pdo->prepare("SELECT id FROM utility_sessions WHERE reservation_id = ? AND utility_type = ? AND status = 'Active'");
    $stmt->execute([$resvId, $type]);
    if ($stmt->fetchColumn()) json_err("An active $type session already exists for this reservation.");
    $pdo->prepare("INSERT INTO utility_sessions 
        (reservation_id, branch, room_id, utility_type, initial_reading, rate_per_unit, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$resvId, $branch, $roomId, $type, $initial, $rate, $userId]);
    $sessionId = $pdo->lastInsertId();
    billing_audit('session_started', $resvId, null, "$type session started. Initial reading: $initial");
    json_ok(['session_id' => $sessionId]);

case 'close_utility_session':
    $sessionId = intval($_POST['session_id'] ?? 0);
    $finalReading = floatval($_POST['final_reading'] ?? 0);
    if (!$sessionId) json_err('Session ID required.');
    $pdo = get_pdo();
    $session = $pdo->prepare("SELECT * FROM utility_sessions WHERE id = ?");
    $session->execute([$sessionId]);
    $s = $session->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_err('Session not found.');
    $consumption = max(0, $finalReading - $s['initial_reading']);
    $charge = round($consumption * floatval($s['rate_per_unit']), 2);
    $pdo->prepare("UPDATE utility_sessions SET final_reading = ?, total_consumption = ?, total_charge = ?, status = 'Closed', closed_at = NOW() WHERE id = ?")
        ->execute([$finalReading, $consumption, $charge, $sessionId]);
    billing_audit('session_closed', $s['reservation_id'], null, "{$s['utility_type']} session closed. Final: $finalReading, Consumption: $consumption, Charge: $charge");
    json_ok(['consumption' => $consumption, 'charge' => $charge]);

case 'get_utility_sessions':
    $resvId = intval($_GET['reservation_id'] ?? 0);
    $pdo = get_pdo();
    if ($resvId) {
        $stmt = $pdo->prepare("SELECT * FROM utility_sessions WHERE reservation_id = ? ORDER BY utility_type");
        $stmt->execute([$resvId]);
    } else {
        $stmt = $pdo->prepare("SELECT us.*, r.guest_full_name, rm.room_number 
            FROM utility_sessions us
            LEFT JOIN reservations r ON r.id = us.reservation_id
            LEFT JOIN rooms rm ON rm.id = us.room_id
            WHERE us.branch = ? AND us.status = 'Active' 
            ORDER BY rm.room_number, us.utility_type");
        $stmt->execute([$branch]);
    }
    json_ok(['sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

// ── DAILY UTILITY READINGS (THE LOGBOOK) ──────────────────────────
case 'add_reading':
    $sessionId = intval($_POST['session_id'] ?? 0);
    $resvId    = intval($_POST['reservation_id'] ?? 0);
    $date      = $_POST['reading_date'] ?? date('Y-m-d');
    $present   = floatval($_POST['present_reading'] ?? 0);
    $notes     = $_POST['notes'] ?? '';
    if (!$sessionId) json_err('Session ID required.');
    $pdo = get_pdo();
    // Get session info and last reading
    $session = $pdo->prepare("SELECT * FROM utility_sessions WHERE id = ? AND status = 'Active'");
    $session->execute([$sessionId]);
    $s = $session->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_err('Active session not found.');
    // Find previous reading (latest reading or initial)
    $lastStmt = $pdo->prepare("SELECT present_reading FROM utility_readings WHERE session_id = ? ORDER BY reading_date DESC, id DESC LIMIT 1");
    $lastStmt->execute([$sessionId]);
    $lastReading = floatval($lastStmt->fetchColumn() ?: $s['initial_reading']);
    if ($present < $lastReading) json_err("Present reading ($present) cannot be less than previous reading ($lastReading).");
    $consumption = round($present - $lastReading, 2);
    $rate = floatval($s['rate_per_unit']);
    $charge = round($consumption * $rate, 2);
    // Upsert (allow re-entry for same date)
    $existing = $pdo->prepare("SELECT id FROM utility_readings WHERE session_id = ? AND reading_date = ?");
    $existing->execute([$sessionId, $date]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        $pdo->prepare("UPDATE utility_readings SET previous_reading = ?, present_reading = ?, consumption = ?, rate = ?, charge = ?, entered_by = ?, notes = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$lastReading, $present, $consumption, $rate, $charge, $userId, $notes, $existingId]);
    } else {
        $pdo->prepare("INSERT INTO utility_readings (session_id, reservation_id, reading_date, previous_reading, present_reading, consumption, rate, charge, entered_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$sessionId, $resvId ?: $s['reservation_id'], $date, $lastReading, $present, $consumption, $rate, $charge, $userId, $notes]);
    }
    // Update session totals
    $totals = $pdo->prepare("SELECT SUM(consumption) as tc, SUM(charge) as tch FROM utility_readings WHERE session_id = ?");
    $totals->execute([$sessionId]);
    $t = $totals->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE utility_sessions SET total_consumption = ?, total_charge = ? WHERE id = ?")
        ->execute([$t['tc'] ?? 0, $t['tch'] ?? 0, $sessionId]);
    billing_audit('reading_added', $s['reservation_id'], null, "{$s['utility_type']} reading: $date prev=$lastReading present=$present consumption=$consumption charge=$charge");
    json_ok(['consumption' => $consumption, 'charge' => $charge, 'previous' => $lastReading]);

case 'get_readings':
    $sessionId = intval($_GET['session_id'] ?? 0);
    $resvId    = intval($_GET['reservation_id'] ?? 0);
    $pdo = get_pdo();
    if ($sessionId) {
        $stmt = $pdo->prepare("SELECT ur.*, u.username as entered_by_name FROM utility_readings ur LEFT JOIN users u ON u.id = ur.entered_by WHERE ur.session_id = ? ORDER BY ur.reading_date ASC");
        $stmt->execute([$sessionId]);
    } elseif ($resvId) {
        $stmt = $pdo->prepare("SELECT ur.*, us.utility_type, u.username as entered_by_name FROM utility_readings ur LEFT JOIN utility_sessions us ON us.id = ur.session_id LEFT JOIN users u ON u.id = ur.entered_by WHERE ur.reservation_id = ? ORDER BY ur.reading_date ASC, us.utility_type");
        $stmt->execute([$resvId]);
    } else {
        json_err('Session ID or Reservation ID required.');
    }
    json_ok(['readings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

// ── ACTIVE ROOMS NEEDING READINGS ─────────────────────────────────
case 'get_active_rooms':
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT us.id as session_id, us.reservation_id, us.utility_type, us.initial_reading,
               us.total_consumption, us.total_charge, us.rate_per_unit,
               r.guest_full_name, r.check_in, r.check_out,
               rm.room_number, rm.room_type,
               (SELECT present_reading FROM utility_readings WHERE session_id = us.id ORDER BY reading_date DESC LIMIT 1) as last_reading,
               (SELECT reading_date FROM utility_readings WHERE session_id = us.id ORDER BY reading_date DESC LIMIT 1) as last_reading_date
        FROM utility_sessions us
        JOIN reservations r ON r.id = us.reservation_id
        JOIN rooms rm ON rm.id = us.room_id
        WHERE us.branch = ? AND us.status = 'Active'
        ORDER BY rm.room_number, us.utility_type
    ");
    $stmt->execute([$branch]);
    json_ok(['rooms' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

// ── MONTHLY BILL GENERATION ───────────────────────────────────────
case 'generate_monthly_bill':
    $resvId = intval($_POST['reservation_id'] ?? 0);
    $period = $_POST['billing_period'] ?? ''; // YYYY-MM
    if (!$resvId || !$period) json_err('Reservation and billing period required.');
    $pdo = get_pdo();
    // Check for existing bill
    $existing = $pdo->prepare("SELECT id, locked FROM monthly_bills WHERE reservation_id = ? AND billing_period = ?");
    $existing->execute([$resvId, $period]);
    $ex = $existing->fetch(PDO::FETCH_ASSOC);
    if ($ex && $ex['locked']) json_err('This bill has been locked and cannot be regenerated. Create an adjustment instead.');
    // Get reservation data
    $resv = $pdo->prepare("SELECT r.*, rm.room_number, rm.room_type FROM reservations r LEFT JOIN rooms rm ON rm.id = r.room_id WHERE r.id = ?");
    $resv->execute([$resvId]);
    $r = $resv->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_err('Reservation not found.');
    // Determine period dates
    $periodStart = new DateTime($period . '-01');
    $periodEnd   = (clone $periodStart)->modify('last day of this month');
    $checkIn     = new DateTime($r['check_in']);
    $checkOut    = new DateTime($r['check_out']);
    // Clamp to reservation dates
    if ($periodStart < $checkIn) $periodStart = clone $checkIn;
    if ($periodEnd > $checkOut)  $periodEnd   = clone $checkOut;
    $days = max(1, $periodStart->diff($periodEnd)->days);
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, intval($periodStart->format('m')), intval($periodStart->format('Y')));
    // Calculate rental
    $monthlyRent = floatval($r['room_rate'] ?? 0);
    $rentalFraction = $days / $daysInMonth;
    $roomRental = round($monthlyRent * $rentalFraction, 2);
    // One-time fees (only charge in first month)
    $isFirstMonth = ($period === (new DateTime($r['check_in']))->format('Y-m'));
    $resvFee     = $isFirstMonth ? floatval($r['reservation_fee'] ?? 0) : 0;
    $garbageFee  = $isFirstMonth ? floatval($r['garbage_fee'] ?? 0) : 0;
    $secDeposit  = $isFirstMonth ? floatval($r['security_deposit'] ?? 0) : 0;
    $utilsDep    = $isFirstMonth ? floatval($r['utilities_deposit'] ?? 0) : 0;
    // Get utility charges for this period
    $utilStmt = $pdo->prepare("SELECT us.utility_type, SUM(ur.charge) as total_charge
        FROM utility_readings ur
        JOIN utility_sessions us ON us.id = ur.session_id
        WHERE ur.reservation_id = ? AND DATE_FORMAT(ur.reading_date, '%Y-%m') = ?
        GROUP BY us.utility_type");
    $utilStmt->execute([$resvId, $period]);
    $utilCharges = $utilStmt->fetchAll(PDO::FETCH_ASSOC);
    $elecCharge = 0; $waterCharge = 0; $internetCharge = 0; $otherCharges = 0;
    foreach ($utilCharges as $uc) {
        $ch = floatval($uc['total_charge']);
        switch ($uc['utility_type']) {
            case 'Electricity': $elecCharge += $ch; break;
            case 'Water':       $waterCharge += $ch; break;
            case 'Internet':    $internetCharge += $ch; break;
            default:            $otherCharges += $ch;
        }
    }
    // Also add manual utility charges from utility_charges table
    $manualUtil = $pdo->prepare("SELECT utility_type, SUM(amount) as total FROM utility_charges WHERE reservation_id = ? AND billing_period = ? GROUP BY utility_type");
    $manualUtil->execute([$resvId, $period]);
    foreach ($manualUtil->fetchAll(PDO::FETCH_ASSOC) as $mu) {
        switch ($mu['utility_type']) {
            case 'Electricity': $elecCharge += floatval($mu['total']); break;
            case 'Water':       $waterCharge += floatval($mu['total']); break;
            case 'Internet':    $internetCharge += floatval($mu['total']); break;
            default:            $otherCharges += floatval($mu['total']);
        }
    }
    // Calculate totals
    $subtotal = $roomRental + $resvFee + $garbageFee + $secDeposit + $utilsDep
              + $elecCharge + $waterCharge + $internetCharge + $otherCharges;
    $grandTotal = $subtotal; // tax/penalty/discount can be added later
    // Get payments for this reservation in this period
    $payStmt = $pdo->prepare("SELECT SUM(amount) FROM payment_history WHERE reservation_id = ? AND DATE_FORMAT(payment_date, '%Y-%m') = ?");
    $payStmt->execute([$resvId, $period]);
    $paid = floatval($payStmt->fetchColumn() ?: 0);
    $balance = $grandTotal - $paid;
    // Insert or update bill
    if ($ex) {
        $pdo->prepare("UPDATE monthly_bills SET 
            guest_name=?, period_start=?, period_end=?, room_rental=?, reservation_fee=?, garbage_fee=?,
            security_deposit=?, utilities_deposit=?, electricity_charge=?, water_charge=?, internet_charge=?,
            other_charges=?, subtotal=?, grand_total=?, amount_paid=?, balance=?, status=?, generated_by=?, generated_at=NOW(), updated_at=NOW()
            WHERE id=?")
            ->execute([$r['guest_full_name'], $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'),
                $roomRental, $resvFee, $garbageFee, $secDeposit, $utilsDep,
                $elecCharge, $waterCharge, $internetCharge, $otherCharges,
                $subtotal, $grandTotal, $paid, $balance,
                $balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Generated'),
                $userId, $ex['id']]);
        $billId = $ex['id'];
    } else {
        $pdo->prepare("INSERT INTO monthly_bills 
            (reservation_id, branch, room_id, guest_name, billing_period, period_start, period_end,
             room_rental, reservation_fee, garbage_fee, security_deposit, utilities_deposit,
             electricity_charge, water_charge, internet_charge, other_charges,
             subtotal, grand_total, amount_paid, balance, status, due_date, generated_by, generated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$resvId, $r['branch'] ?? $branch, $r['room_id'], $r['guest_full_name'], $period,
                $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'),
                $roomRental, $resvFee, $garbageFee, $secDeposit, $utilsDep,
                $elecCharge, $waterCharge, $internetCharge, $otherCharges,
                $subtotal, $grandTotal, $paid, $balance,
                $balance <= 0 ? 'Paid' : 'Generated',
                $periodEnd->format('Y-m-d'),
                $userId]);
        $billId = $pdo->lastInsertId();
    }
    billing_audit('bill_generated', $resvId, $billId, "Monthly bill for $period. Total: $grandTotal, Paid: $paid, Balance: $balance");
    json_ok(['bill_id' => $billId, 'grand_total' => $grandTotal, 'balance' => $balance]);

case 'get_monthly_bills':
    $resvId = intval($_GET['reservation_id'] ?? 0);
    $pdo = get_pdo();
    if ($resvId) {
        $stmt = $pdo->prepare("SELECT * FROM monthly_bills WHERE reservation_id = ? ORDER BY billing_period DESC");
        $stmt->execute([$resvId]);
    } else {
        $stmt = $pdo->prepare("SELECT mb.*, rm.room_number FROM monthly_bills mb LEFT JOIN rooms rm ON rm.id = mb.room_id WHERE mb.branch = ? ORDER BY mb.billing_period DESC, rm.room_number LIMIT 100");
        $stmt->execute([$branch]);
    }
    json_ok(['bills' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

case 'get_bill_detail':
    $billId = intval($_GET['bill_id'] ?? 0);
    if (!$billId) json_err('Bill ID required.');
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT mb.*, rm.room_number, rm.room_type FROM monthly_bills mb LEFT JOIN rooms rm ON rm.id = mb.room_id WHERE mb.id = ?");
    $stmt->execute([$billId]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bill) json_err('Bill not found.');
    // Get utility readings for this period
    $readings = $pdo->prepare("SELECT ur.*, us.utility_type FROM utility_readings ur JOIN utility_sessions us ON us.id = ur.session_id WHERE ur.reservation_id = ? AND DATE_FORMAT(ur.reading_date, '%Y-%m') = ? ORDER BY ur.reading_date, us.utility_type");
    $readings->execute([$bill['reservation_id'], $bill['billing_period']]);
    $bill['readings'] = $readings->fetchAll(PDO::FETCH_ASSOC);
    // Get payments allocated to this bill
    $payments = $pdo->prepare("SELECT ph.* FROM payment_history ph WHERE ph.reservation_id = ? AND DATE_FORMAT(ph.payment_date, '%Y-%m') = ? ORDER BY ph.payment_date");
    $payments->execute([$bill['reservation_id'], $bill['billing_period']]);
    $bill['payments'] = $payments->fetchAll(PDO::FETCH_ASSOC);
    json_ok(['bill' => $bill]);

case 'lock_bill':
    $billId = intval($_POST['bill_id'] ?? 0);
    if (!$billId) json_err('Bill ID required.');
    $pdo = get_pdo();
    $pdo->prepare("UPDATE monthly_bills SET locked = 1, updated_at = NOW() WHERE id = ?")->execute([$billId]);
    billing_audit('bill_locked', null, $billId, "Bill locked — no further edits allowed.");
    json_ok();

// ── BILLING SUMMARY ───────────────────────────────────────────────
case 'billing_summary':
    $pdo = get_pdo();
    // Total revenue (sum of all bill grand_totals)
    $rev = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM monthly_bills WHERE branch = ? AND status != 'Void'");
    $rev->execute([$branch]);
    $totalRevenue = floatval($rev->fetchColumn());
    // If no monthly bills yet, fall back to reservation totals
    if ($totalRevenue == 0) {
        $rev2 = $pdo->prepare("SELECT COALESCE(SUM(r.total_amount),0) FROM reservations r JOIN rooms rm ON rm.id = r.room_id WHERE rm.branch = ? AND r.status NOT IN ('cancelled')");
        $rev2->execute([$branch]);
        $totalRevenue = floatval($rev2->fetchColumn());
    }
    // Total collected
    $col = $pdo->prepare("SELECT COALESCE(SUM(ph.amount),0) FROM payment_history ph JOIN reservations r ON r.id = ph.reservation_id JOIN rooms rm ON rm.id = r.room_id WHERE rm.branch = ?");
    $col->execute([$branch]);
    $totalCollected = floatval($col->fetchColumn());
    // Active stays
    $act = $pdo->prepare("SELECT COUNT(*) FROM reservations r JOIN rooms rm ON rm.id = r.room_id WHERE rm.branch = ? AND r.status = 'checked_in'");
    $act->execute([$branch]);
    $activeStays = intval($act->fetchColumn());
    // Unpaid utilities
    $upd = $pdo->prepare("SELECT COALESCE(SUM(uc.amount),0) FROM utility_charges uc JOIN reservations r ON r.id = uc.reservation_id JOIN rooms rm ON rm.id = r.room_id WHERE rm.branch = ? AND uc.status != 'Paid'");
    $upd->execute([$branch]);
    $utilUnpaid = floatval($upd->fetchColumn());
    json_ok(['summary' => [
        'total_revenue'   => $totalRevenue,
        'total_collected' => $totalCollected,
        'total_balance'   => $totalRevenue - $totalCollected,
        'active_stays'    => $activeStays,
        'util_unpaid'     => $utilUnpaid,
    ]]);

// ── BILLING SETTINGS ─────────────────────────────────────────────
case 'get_settings':
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM billing_settings WHERE branch = ?");
    $stmt->execute([$branch]);
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    json_ok(['settings' => $settings]);

case 'save_setting':
    $key = $_POST['setting_key'] ?? '';
    $val = $_POST['setting_value'] ?? '';
    if (!$key) json_err('Setting key required.');
    $pdo = get_pdo();
    $existing = $pdo->prepare("SELECT id FROM billing_settings WHERE branch = ? AND setting_key = ?");
    $existing->execute([$branch, $key]);
    if ($existing->fetchColumn()) {
        $pdo->prepare("UPDATE billing_settings SET setting_value = ?, updated_by = ? WHERE branch = ? AND setting_key = ?")
            ->execute([$val, $userId, $branch, $key]);
    } else {
        $pdo->prepare("INSERT INTO billing_settings (branch, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)")
            ->execute([$branch, $key, $val, $userId]);
    }
    billing_audit('setting_changed', null, null, "Setting '$key' changed to '$val' for $branch");
    json_ok();

// ── BILLING AUDIT LOG ─────────────────────────────────────────────
case 'get_audit_log':
    $resvId = intval($_GET['reservation_id'] ?? 0);
    $pdo = get_pdo();
    if ($resvId) {
        $stmt = $pdo->prepare("SELECT * FROM billing_audit_log WHERE reservation_id = ? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$resvId]);
    } else {
        $stmt = $pdo->prepare("SELECT bal.*, r.guest_full_name, rm.room_number 
            FROM billing_audit_log bal 
            LEFT JOIN reservations r ON r.id = bal.reservation_id
            LEFT JOIN rooms rm ON rm.id = r.room_id
            WHERE rm.branch = ? OR bal.reservation_id IS NULL
            ORDER BY bal.created_at DESC LIMIT 200");
        $stmt->execute([$branch]);
    }
    json_ok(['logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

// ── STATEMENT OF ACCOUNT DATA ─────────────────────────────────────
case 'get_statement':
    $resvId = intval($_GET['reservation_id'] ?? 0);
    $billId = intval($_GET['bill_id'] ?? 0);
    if (!$resvId && !$billId) json_err('Reservation or Bill ID required.');
    $pdo = get_pdo();
    // Get reservation
    if ($resvId) {
        $resv = $pdo->prepare("SELECT r.*, rm.room_number, rm.room_type, rm.branch FROM reservations r LEFT JOIN rooms rm ON rm.id = r.room_id WHERE r.id = ?");
        $resv->execute([$resvId]);
    } else {
        $bill = $pdo->prepare("SELECT reservation_id FROM monthly_bills WHERE id = ?");
        $bill->execute([$billId]);
        $resvId = intval($bill->fetchColumn());
        $resv = $pdo->prepare("SELECT r.*, rm.room_number, rm.room_type, rm.branch FROM reservations r LEFT JOIN rooms rm ON rm.id = r.room_id WHERE r.id = ?");
        $resv->execute([$resvId]);
    }
    $r = $resv->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_err('Reservation not found.');
    // Get all monthly bills
    $bills = $pdo->prepare("SELECT * FROM monthly_bills WHERE reservation_id = ? ORDER BY billing_period");
    $bills->execute([$resvId]);
    $allBills = $bills->fetchAll(PDO::FETCH_ASSOC);
    // Get all utility sessions
    $sessions = $pdo->prepare("SELECT * FROM utility_sessions WHERE reservation_id = ? ORDER BY utility_type");
    $sessions->execute([$resvId]);
    $allSessions = $sessions->fetchAll(PDO::FETCH_ASSOC);
    // Get all readings
    $readings = $pdo->prepare("SELECT ur.*, us.utility_type FROM utility_readings ur JOIN utility_sessions us ON us.id = ur.session_id WHERE ur.reservation_id = ? ORDER BY ur.reading_date, us.utility_type");
    $readings->execute([$resvId]);
    $allReadings = $readings->fetchAll(PDO::FETCH_ASSOC);
    // Get all payments
    $payments = $pdo->prepare("SELECT * FROM payment_history WHERE reservation_id = ? ORDER BY payment_date");
    $payments->execute([$resvId]);
    $allPayments = $payments->fetchAll(PDO::FETCH_ASSOC);
    $totalPaid = array_sum(array_column($allPayments, 'amount'));
    json_ok([
        'reservation' => $r,
        'bills'       => $allBills,
        'sessions'    => $allSessions,
        'readings'    => $allReadings,
        'payments'    => $allPayments,
        'total_paid'  => $totalPaid,
    ]);

default:
    json_err('Unknown billing action: ' . $action, 404);
}