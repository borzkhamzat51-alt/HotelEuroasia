<?php
/**
 * process_reports_kpis.php
 * AJAX endpoint — returns fresh KPI values for Reports live update.
 * Called by reports.js whenever the WebSocket fires reservations_changed
 * or rooms_changed, so the Reports page reflects the latest data without
 * a full page reload.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reports');

header('Content-Type: application/json');

$branches = ['annex','mtv','dormitel','aps','euroasia_stall','annex_stall'];
$branch   = trim($_GET['branch'] ?? 'mtv');
if (!in_array($branch, $branches, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid branch']);
    exit;
}

$today      = date('Y-m-d');
$rangeStart = date('Y-m-01');
$rangeEnd   = date('Y-m-01', strtotime('+1 month'));

function kpi_q($sql, $p = []) {
    $s = bb_db()->prepare($sql);
    $s->execute($p);
    return $s;
}
function kpi_bf($branch) { return db_report_branch_filter($branch); }

function kpi_sales_today($branch) {
    [$w,$p] = kpi_bf($branch);
    return (float) kpi_q("SELECT COALESCE(SUM(r.amount_paid),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status NOT IN('cancelled') AND DATE(r.created_at)=CURDATE()", $p)->fetchColumn();
}
function kpi_expected_revenue($branch) {
    [$w,$p] = kpi_bf($branch);
    return (float) kpi_q("SELECT COALESCE(SUM(r.total_amount),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status IN('reserved','checked_in')", $p)->fetchColumn();
}
function kpi_overdue($branch) {
    [$w,$p] = kpi_bf($branch);
    return (float) kpi_q("SELECT COALESCE(SUM(r.total_amount-COALESCE(r.amount_paid,0)),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status NOT IN('cancelled') AND r.check_out<CURDATE() AND r.total_amount>COALESCE(r.amount_paid,0)", $p)->fetchColumn();
}
function kpi_activity_count($branch, $type) {
    [$w,$p] = kpi_bf($branch);
    $today = date('Y-m-d');
    switch ($type) {
        case 'arrivals': return (int) kpi_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_in=? AND r.status IN('reserved','checked_in')", array_merge($p,[$today]))->fetchColumn();
        case 'moveouts': return (int) kpi_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_out=? AND r.status IN('checked_in','checked_out')", array_merge($p,[$today]))->fetchColumn();
        default:         return (int) kpi_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_in<=? AND r.check_out>? AND r.status IN('checked_in','reserved')", array_merge($p,[$today,$today]))->fetchColumn();
    }
}

try {
    echo json_encode([
        'success'          => true,
        'sales_today'      => kpi_sales_today($branch),
        'expected_revenue' => kpi_expected_revenue($branch),
        'overdue'          => kpi_overdue($branch),
        'activity'         => [
            'arrivals' => kpi_activity_count($branch, 'arrivals'),
            'moveouts' => kpi_activity_count($branch, 'moveouts'),
            'inhouse'  => kpi_activity_count($branch, 'inhouse'),
        ],
        'updated_at'       => date('H:i:s'),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error']);
}