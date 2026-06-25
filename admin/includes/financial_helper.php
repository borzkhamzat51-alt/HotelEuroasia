<?php
/**
 * admin/includes/financial_helper.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Bluebookers — Phase 1: Centralized Financial Foundation
 *
 * ALL financial calculations must go through these functions.
 * No module should independently calculate rental totals, balances, or
 * payment status. Require this file wherever those numbers appear:
 *
 *   require_once __DIR__ . '/../includes/financial_helper.php';
 *
 * The functions here rely only on:
 *   - The reservation row (array from db_find_reservation / db_list_*).
 *   - The room row (array from db_find_room), when a monthly rate override
 *     is needed. Pass null to fall back to the reservation's own room_rate.
 *   - The payments table (via db_list_payments) for amount-paid totals.
 *
 * Nothing in here writes to the database.
 */

if (!function_exists('db_list_payments')) {
    throw new RuntimeException('financial_helper.php requires db.php to be loaded first.');
}

/* ── Money formatter ───────────────────────────────────────────────────────── */
function fin_format_money(float $amount, bool $showSign = false): string
{
    $str = '₱' . number_format($amount, 2);
    return ($showSign && $amount > 0) ? '+' . $str : $str;
}

/* ── Monthly rate ──────────────────────────────────────────────────────────── */
/**
 * The agreed monthly rent for this reservation.
 * Uses reservation.room_rate if set and non-zero, otherwise falls back
 * to the room's current price_per_night (which stores the monthly rate
 * for this hotel).
 */
function fin_monthly_rate(array $reservation, ?array $room = null): float
{
    $rate = (float)($reservation['room_rate'] ?? 0);
    if ($rate > 0) {
        return $rate;
    }
    if ($room !== null) {
        return (float)($room['price_per_night'] ?? 0);
    }
    // Last resort: look up the room
    $r = db_find_room($reservation['room_id']);
    return $r ? (float)($r['price_per_night'] ?? 0) : 0.0;
}

/* ── Rental duration in whole months ───────────────────────────────────────── */
/**
 * Calendar-month count between check_in and check_out.
 * "Jun 24 – Dec 24" = 6 months exactly.
 * Partial months (e.g. Jun 24 – Jul 10) count as 1 month minimum.
 */
function fin_rental_months(array $reservation): int
{
    if (empty($reservation['check_in']) || empty($reservation['check_out'])) {
        return 0;
    }
    $start = new DateTime($reservation['check_in']);
    $end   = new DateTime($reservation['check_out']);
    if ($end <= $start) {
        return 0;
    }
    $diff   = $start->diff($end);
    $months = ($diff->y * 12) + $diff->m;
    // Any remaining days beyond whole months count as one more month
    if ($diff->d > 0) {
        $months++;
    }
    return max(1, $months);
}

/* ── Human-readable duration string ─────────────────────────────────────────── */
function fin_rental_duration_label(array $reservation): string
{
    if (empty($reservation['check_in']) || empty($reservation['check_out'])) {
        return '—';
    }
    $start = new DateTime($reservation['check_in']);
    $end   = new DateTime($reservation['check_out']);
    if ($end <= $start) return '—';
    $diff   = $start->diff($end);
    $months = ($diff->y * 12) + $diff->m;
    $days   = $diff->d;
    $parts  = [];
    if ($months > 0) $parts[] = $months . ' Month' . ($months !== 1 ? 's' : '');
    if ($days   > 0) $parts[] = $days   . ' Day'   . ($days   !== 1 ? 's' : '');
    return implode(' ', $parts) ?: '—';
}

/* ── Total rental amount ───────────────────────────────────────────────────── */
/**
 * The gross amount owed for the full stay: monthly_rate × rental_months.
 * This is the authoritative "Amount Due" — not reservations.total_amount
 * (which is a legacy free-entry field).
 */
function fin_total_rental_amount(array $reservation, ?array $room = null): float
{
    return fin_monthly_rate($reservation, $room) * fin_rental_months($reservation);
}

/* ── Total amount paid (from the payments table) ────────────────────────────── */
/**
 * Sum of all payment records for this reservation.
 * This is the single authoritative source — reservations.amount_paid is
 * a legacy field kept for backward compatibility only.
 */
function fin_total_amount_paid(int $reservationId): float
{
    $payments = db_list_payments($reservationId);
    return array_sum(array_column($payments, 'amount'));
}

/* ── Outstanding balance ────────────────────────────────────────────────────── */
function fin_outstanding_balance(array $reservation, ?array $room = null): float
{
    $total = fin_total_rental_amount($reservation, $room);
    $paid  = fin_total_amount_paid((int)$reservation['id']);
    return max(0.0, $total - $paid);
}

/* ── Payment status ─────────────────────────────────────────────────────────── */
/**
 * Unpaid | Partially Paid | Fully Paid | Overdue
 *
 * Overdue: outstanding balance > 0 AND today > expected_payment_date.
 * Expected payment date defaults to check_out if not set.
 */
function fin_payment_status(array $reservation, ?array $room = null): string
{
    $balance    = fin_outstanding_balance($reservation, $room);
    $paid       = fin_total_amount_paid((int)$reservation['id']);
    $today      = new DateTime('today');
    $expectedDate = !empty($reservation['expected_payment_date'])
        ? new DateTime($reservation['expected_payment_date'])
        : (!empty($reservation['check_out']) ? new DateTime($reservation['check_out']) : null);

    if ($balance <= 0) {
        return 'Fully Paid';
    }
    if ($expectedDate !== null && $today > $expectedDate && $balance > 0) {
        return 'Overdue';
    }
    if ($paid > 0 && $balance > 0) {
        return 'Partially Paid';
    }
    return 'Unpaid';
}

/* ── CSS class for payment status pill ─────────────────────────────────────── */
function fin_status_class(string $status): string
{
    return [
        'Fully Paid'     => 'fin-status--paid',
        'Partially Paid' => 'fin-status--partial',
        'Overdue'        => 'fin-status--overdue',
        'Unpaid'         => 'fin-status--unpaid',
    ][$status] ?? 'fin-status--unpaid';
}

/* ── Complete financial summary for one reservation ───────────────────────── */
/**
 * Returns all calculated values in one call so callers that need
 * multiple values don't trigger redundant DB queries.
 */
function fin_summary(array $reservation, ?array $room = null): array
{
    $monthlyRate   = fin_monthly_rate($reservation, $room);
    $rentalMonths  = fin_rental_months($reservation);
    $totalRental   = $monthlyRate * $rentalMonths;
    $paid          = fin_total_amount_paid((int)$reservation['id']);
    $balance       = max(0.0, $totalRental - $paid);
    $today         = new DateTime('today');
    $expectedDate  = !empty($reservation['expected_payment_date'])
        ? new DateTime($reservation['expected_payment_date'])
        : (!empty($reservation['check_out']) ? new DateTime($reservation['check_out']) : null);

    if ($balance <= 0) {
        $status = 'Fully Paid';
    } elseif ($expectedDate && $today > $expectedDate) {
        $status = 'Overdue';
    } elseif ($paid > 0) {
        $status = 'Partially Paid';
    } else {
        $status = 'Unpaid';
    }

    return [
        'monthly_rate'       => $monthlyRate,
        'rental_months'      => $rentalMonths,
        'duration_label'     => fin_rental_duration_label($reservation),
        'total_rental'       => $totalRental,
        'total_paid'         => $paid,
        'outstanding_balance'=> $balance,
        'payment_status'     => $status,
        'status_class'       => fin_status_class($status),
    ];
}