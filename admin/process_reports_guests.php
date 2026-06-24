<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reports');

header('Content-Type: application/json');

$branch = trim($_GET['branch'] ?? 'mtv');
$type   = trim($_GET['type']   ?? 'arrivals');
$today  = date('Y-m-d');

$branches = ['annex','mtv','dormitel','aps','euroasia_stall','annex_stall'];
if (!in_array($branch, $branches, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid branch']);
    exit;
}

[$where, $params] = db_report_branch_filter($branch);

function rptMoney($n) {
    $n = (float)$n;
    return '₱' . number_format($n, 2);
}
function rptViewBtn($rId, $branch) {
    return '<a class="rpt-view-cal" 
               href="reservations.php?branch=' . urlencode($branch) . '"
               data-reservation-id="' . (int)$rId . '"
               data-branch="' . htmlspecialchars($branch) . '"
               title="View in Calendar"
               onclick="rptGoToCalendar(event,this)">
               <svg viewBox="0 0 24 24" fill="none" width="14" height="14"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M16 3v4M8 3v4M3 10h18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </a>';
}
function rptStatus($s) {
    $map = [
        'reserved'    => '<span class="rpt-pill rpt-pill--reserved">Reserved</span>',
        'checked_in'  => '<span class="rpt-pill rpt-pill--checked-in">Checked In</span>',
        'checked_out' => '<span class="rpt-pill rpt-pill--checked-out">Checked Out</span>',
        'cancelled'   => '<span class="rpt-pill rpt-pill--cancelled">Cancelled</span>',
    ];
    return $map[$s] ?? htmlspecialchars(ucfirst($s));
}

try {
    switch ($type) {

        // ── Expected Arrivals ───────────────────────────────────────────
        case 'arrivals':
            $sql = "
                SELECT r.id, r.guest_full_name, r.check_in, r.check_out,
                       r.status, ro.room_number, ro.room_type,
                       DATEDIFF(r.check_out, r.check_in) AS nights
                FROM reservations r
                JOIN rooms ro ON ro.id = r.room_id
                WHERE $where
                  AND r.check_in = ?
                  AND r.status IN ('reserved','checked_in')
                ORDER BY ro.room_number
            ";
            $stmt = bb_db()->prepare($sql);
            $stmt->execute(array_merge($params, [$_GET['date'] ?? date('Y-m-d')]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $thead = '<tr>
                <th>Guest Name</th>
                <th>Reservation #</th>
                <th>Room</th>
                <th>Arrival Date</th>
                <th>Nights</th>
                <th>Status</th>
                <th></th>
            </tr>';
            $tbody = '';
            foreach ($rows as $r) {
                $tbody .= '<tr>
                    <td class="rpt-td--name">' . htmlspecialchars($r['guest_full_name']) . '</td>
                    <td class="rpt-td--mono">#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT) . '</td>
                    <td class="rpt-td--room">RM ' . htmlspecialchars($r['room_number']) . '</td>
                    <td>' . date('M d, Y', strtotime($r['check_in'])) . '</td>
                    <td class="rpt-td--num">' . $r['nights'] . '</td>
                    <td>' . rptStatus($r['status']) . '</td>
                    <td class="rpt-td--action">' . rptViewBtn($r['id'], $branch) . '</td>
                </tr>';
            }
            break;

        // ── Move-Outs ───────────────────────────────────────────────────
        case 'moveouts':
            $sql = "
                SELECT r.id, r.guest_full_name, r.check_out, r.status,
                       ro.room_number,
                       r.total_amount - COALESCE(r.amount_paid, 0) AS balance
                FROM reservations r
                JOIN rooms ro ON ro.id = r.room_id
                WHERE $where
                  AND r.check_out = ?
                  AND r.status IN ('checked_in','checked_out')
                ORDER BY ro.room_number
            ";
            $stmt = bb_db()->prepare($sql);
            $stmt->execute(array_merge($params, [$_GET['date'] ?? date('Y-m-d')]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $thead = '<tr>
                <th>Guest Name</th>
                <th>Room</th>
                <th>Departure Date</th>
                <th>Outstanding Balance</th>
                <th>Status</th>
                <th></th>
            </tr>';
            $tbody = '';
            foreach ($rows as $r) {
                $bal = (float)$r['balance'];
                $balClass = $bal > 0 ? ' rpt-td--red' : ' rpt-td--green';
                $tbody .= '<tr>
                    <td class="rpt-td--name">' . htmlspecialchars($r['guest_full_name']) . '</td>
                    <td class="rpt-td--room">RM ' . htmlspecialchars($r['room_number']) . '</td>
                    <td>' . date('M d, Y', strtotime($r['check_out'])) . '</td>
                    <td class="rpt-td--money' . $balClass . '">' . rptMoney($bal) . '</td>
                    <td>' . rptStatus($r['status']) . '</td>
                    <td class="rpt-td--action">' . rptViewBtn($r['id'], $branch) . '</td>
                </tr>';
            }
            break;

        // ── In-House Guests ─────────────────────────────────────────────
        case 'inhouse':
        default:
            $todayParam = $_GET['date'] ?? date('Y-m-d');
            $sql = "
                SELECT r.id, r.guest_full_name, r.check_in, r.check_out,
                       r.status, ro.room_number,
                       DATEDIFF(r.check_out, r.check_in) AS nights,
                       r.total_amount - COALESCE(r.amount_paid, 0) AS balance
                FROM reservations r
                JOIN rooms ro ON ro.id = r.room_id
                WHERE $where
                  AND r.check_in <= ?
                  AND r.check_out > ?
                  AND r.status IN ('checked_in','reserved')
                ORDER BY ro.room_number
            ";
            $stmt = bb_db()->prepare($sql);
            $stmt->execute(array_merge($params, [$todayParam, $todayParam]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $thead = '<tr>
                <th>Guest Name</th>
                <th>Room</th>
                <th>Check-In</th>
                <th>Check-Out</th>
                <th>Nights</th>
                <th>Current Balance</th>
                <th>Status</th>
                <th></th>
            </tr>';
            $tbody = '';
            foreach ($rows as $r) {
                $bal = (float)$r['balance'];
                $balClass = $bal > 0 ? ' rpt-td--red' : ' rpt-td--green';
                $tbody .= '<tr>
                    <td class="rpt-td--name">' . htmlspecialchars($r['guest_full_name']) . '</td>
                    <td class="rpt-td--room">RM ' . htmlspecialchars($r['room_number']) . '</td>
                    <td>' . date('M d', strtotime($r['check_in'])) . '</td>
                    <td>' . date('M d', strtotime($r['check_out'])) . '</td>
                    <td class="rpt-td--num">' . $r['nights'] . '</td>
                    <td class="rpt-td--money' . $balClass . '">' . rptMoney($bal) . '</td>
                    <td>' . rptStatus($r['status']) . '</td>
                    <td class="rpt-td--action">' . rptViewBtn($r['id'], $branch) . '</td>
                </tr>';
            }
            break;
    }

    echo json_encode([
        'success' => true,
        'count'   => count($rows),
        'thead'   => $thead,
        'tbody'   => $tbody ?: '<tr><td colspan="7" class="rpt-empty-row">No guests found.</td></tr>',
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}