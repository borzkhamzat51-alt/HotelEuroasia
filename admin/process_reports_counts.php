<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reports');

header('Content-Type: application/json');

$branches = ['annex', 'mtv', 'dormitel', 'aps', 'euroasia_stall', 'annex_stall'];
$branch = trim($_GET['branch'] ?? 'mtv');
if (!in_array($branch, $branches, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid branch']);
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

function count_activity($branch, $date, $type) {
    [$where, $params] = db_report_branch_filter($branch);
    $pdo = bb_db();
    switch ($type) {
        case 'arrivals':
            $sql = "SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE $where AND r.check_in = ? AND r.status IN ('reserved','checked_in')";
            $params[] = $date;
            break;
        case 'moveouts':
            $sql = "SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE $where AND r.check_out = ? AND r.status IN ('checked_in','checked_out')";
            $params[] = $date;
            break;
        case 'inhouse':
            $sql = "SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id = r.room_id WHERE $where AND r.check_in <= ? AND r.check_out > ? AND r.status IN ('checked_in','reserved')";
            $params[] = $date;
            $params[] = $date;
            break;
        default:
            return 0;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$counts = [
    'arrivals' => count_activity($branch, $date, 'arrivals'),
    'moveouts' => count_activity($branch, $date, 'moveouts'),
    'inhouse'  => count_activity($branch, $date, 'inhouse'),
];

echo json_encode(['success' => true, 'counts' => $counts]);