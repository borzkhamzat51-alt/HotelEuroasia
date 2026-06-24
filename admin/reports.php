<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reports');

$branches = [
    'annex'          => 'BB Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'ELTI Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];

$branchKey   = $_GET['branch'] ?? 'mtv';
if (!isset($branches[$branchKey])) $branchKey = 'mtv';
$branchLabel = $branches[$branchKey];
$branch      = $branchKey;
$today       = date('Y-m-d');

$rangeStart  = new DateTime('first day of this month');
$rangeEnd    = (clone $rangeStart)->modify('+1 month');
$rangeLabel  = $rangeStart->format('F Y');

$kpis  = db_report_kpis($branch, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
$trend = db_report_monthly_trend($branch, 6);

/* ── Local KPI helpers ──────────────────────────────────────────── */
function rpt_q($sql, $p = []) {
    $s = bb_db()->prepare($sql);
    $s->execute($p);
    return $s;
}
function rpt_bf($branch) { return db_report_branch_filter($branch); }

function rpt_sales_today($branch) {
    [$w,$p] = rpt_bf($branch);
    return (float) rpt_q("SELECT COALESCE(SUM(r.amount_paid),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status NOT IN('cancelled') AND DATE(r.created_at)=CURDATE()", $p)->fetchColumn();
}
function rpt_expected_revenue($branch) {
    [$w,$p] = rpt_bf($branch);
    return (float) rpt_q("SELECT COALESCE(SUM(r.total_amount),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status IN('reserved','checked_in')", $p)->fetchColumn();
}
function rpt_overdue($branch) {
    [$w,$p] = rpt_bf($branch);
    return (float) rpt_q("SELECT COALESCE(SUM(r.total_amount-COALESCE(r.amount_paid,0)),0) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.status NOT IN('cancelled') AND r.check_out<CURDATE() AND r.total_amount>COALESCE(r.amount_paid,0)", $p)->fetchColumn();
}
function rpt_activity_count($branch, $type) {
    [$w,$p] = rpt_bf($branch);
    $today = date('Y-m-d');
    switch ($type) {
        case 'arrivals': return (int) rpt_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_in=? AND r.status IN('reserved','checked_in')", array_merge($p,[$today]))->fetchColumn();
        case 'moveouts': return (int) rpt_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_out=? AND r.status IN('checked_in','checked_out')", array_merge($p,[$today]))->fetchColumn();
        default:         return (int) rpt_q("SELECT COUNT(*) FROM reservations r JOIN rooms ro ON ro.id=r.room_id WHERE $w AND r.check_in<=? AND r.check_out>? AND r.status IN('checked_in','reserved')", array_merge($p,[$today,$today]))->fetchColumn();
    }
}

$salesToday      = rpt_sales_today($branch);
$expectedRevenue = rpt_expected_revenue($branch);
$overdueAmount   = rpt_overdue($branch);
$activityCounts  = [
    'arrivals' => rpt_activity_count($branch, 'arrivals'),
    'moveouts' => rpt_activity_count($branch, 'moveouts'),
    'inhouse'  => rpt_activity_count($branch, 'inhouse'),
];

/* ── Format helpers ─────────────────────────────────────────────── */
function rpt_money($n)         { return '₱'.number_format((float)$n,2); }
function rpt_pct($n)           { return number_format((float)$n,1).'%'; }
function rpt_money_compact($n) {
    $n=(float)$n;
    if($n>=1000000) return '₱'.rtrim(rtrim(number_format($n/1000000,1),'0'),'.').'M';
    if($n>=1000)    return '₱'.rtrim(rtrim(number_format($n/1000,1),'0'),'.').'K';
    return '₱'.number_format($n,0);
}

/* ── Trend SVG ──────────────────────────────────────────────────── */
function rpt_trend_svg($trend) {
    $months=count($trend);
    if(!$months) return '<p class="rpt-empty">No data yet.</p>';
    $W=760;$H=260;$pL=70;$pR=58;$pT=18;$pB=34;
    $plW=$W-$pL-$pR;$plH=$H-$pT-$pB;
    $mx=1; foreach($trend as $r) $mx=max($mx,(float)$r['billed']);
    $mag=10**max(0,floor(log10($mx))-1);
    $nm=max(1,ceil($mx/$mag)*$mag);
    $slot=$plW/$months;$bw=$slot*0.44;
    $svg='<svg viewBox="0 0 '.$W.' '.$H.'" class="rpt-trend-svg">';
    $svg.='<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2563eb"/><stop offset="100%" stop-color="#1d4ed8" stop-opacity="0.7"/></linearGradient></defs>';
    for($g=0;$g<=4;$g++){
        $frac=$g/4;$y=round($pT+$plH-$frac*$plH,1);
        $svg.='<line x1="'.$pL.'" y1="'.$y.'" x2="'.($W-$pR).'" y2="'.$y.'" stroke="#e8f0f8" stroke-width="1"/>';
        $svg.='<text x="'.($pL-10).'" y="'.($y+4).'" text-anchor="end" class="rpt-ax">'.htmlspecialchars(rpt_money_compact($nm*$frac)).'</text>';
    }
    foreach([0,50,100] as $p){
        $y=round($pT+$plH-($p/100)*$plH,1);
        $svg.='<text x="'.($W-$pR+10).'" y="'.($y+4).'" text-anchor="start" class="rpt-ax rpt-ax--amber">'.$p.'%</text>';
    }
    $pts=[];
    foreach($trend as $i=>$row){
        $cx=round($pL+$slot*$i+$slot/2,1);
        $bh=($row['billed']/$nm)*$plH;
        $by=round($pT+$plH-$bh,1);
        $bx=round($cx-$bw/2,1);
        $svg.='<rect x="'.$bx.'" y="'.$by.'" width="'.round($bw,1).'" height="'.round(max(0,$bh),1).'" rx="5" fill="url(#bg)"><title>'.htmlspecialchars($row['label'].': '.rpt_money($row['billed'])).'</title></rect>';
        $svg.='<text x="'.$cx.'" y="'.($H-8).'" text-anchor="middle" class="rpt-ax">'.htmlspecialchars($row['label']).'</text>';
        $oc=min(100,max(0,(float)$row['occupancy_rate']));
        $oy=round($pT+$plH-($oc/100)*$plH,1);
        $pts[]=[$cx,$oy,$row];
    }
    $poly=implode(' ',array_map(fn($p)=>$p[0].','.$p[1],$pts));
    $svg.='<polyline points="'.$poly.'" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
    foreach($pts as $p){[$cx,$cy,$row]=$p;$svg.='<circle cx="'.$cx.'" cy="'.$cy.'" r="4.5" fill="#f59e0b" stroke="#fff" stroke-width="2"><title>'.htmlspecialchars($row['label'].': '.rpt_pct($row['occupancy_rate']).' occ.').'</title></circle>';}
    return $svg.'</svg>';
}

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branchLabel) ?> — Reports · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/reports.css">
</head>
<body class="dashboard-body">

<!-- ── Top bar ──────────────────────────────────────────── -->
<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<span class="topbar__brand-suffix">.admin</span></span>
    </div>
    <div class="topbar__right">
        <div class="topbar__user">
            <span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span>
            <span class="topbar__user-role"><?= bb_is_admin() ? 'Admin' : 'Staff' ?></span>
        </div>
        <a href="../logout.php" class="topbar__logout">
            <svg viewBox="0 0 24 24" fill="none"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>

<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<main class="rpt-main">

    <!-- ── Page header ──────────────────────────────────── -->
    <div class="rpt-page-header">
        <div>
            <h1 class="rpt-page-header__title"><?= htmlspecialchars($branchLabel) ?></h1>
            <p class="rpt-page-header__sub">Reports &amp; Analytics &mdash; <?= htmlspecialchars($rangeLabel) ?></p>
        </div>
        <div class="rpt-page-header__actions">
            <a href="layout_1st_floor.php?branch=<?= urlencode($branchKey) ?>" class="rpt-nav-btn rpt-nav-btn--layout">
                <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="3" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="3" y="13" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="8" height="8" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
                Floor Layout
            </a>
            <a href="reservations.php?branch=<?= urlencode($branchKey) ?>" class="rpt-nav-btn rpt-nav-btn--calendar">
                <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M16 3v4M8 3v4M3 10h18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="15" r="1.1" fill="currentColor"/><circle cx="12" cy="15" r="1.1" fill="currentColor"/><circle cx="16" cy="15" r="1.1" fill="currentColor"/></svg>
                Calendar
            </a>
            <div class="rpt-live-badge" id="rptLiveBadge" title="Live data sync status">
                <span class="rpt-live-dot" id="rptLiveDot"></span>
                <span id="rptLiveLabel">Connecting…</span>
            </div>
            <div class="rpt-page-header__date">
                <svg viewBox="0 0 24 24" fill="none" width="15" height="15"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M16 3v4M8 3v4M3 10h18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <?= date('l, F j, Y') ?>
            </div>
        </div>
    </div>

    <!-- ── 4 KPI cards ──────────────────────────────────── -->
    <div class="rpt-kpi-grid">

        <!-- 1. Sales for the Day -->
        <div class="rpt-kpi-card rpt-kpi-card--blue">
            <div class="rpt-kpi-card__icon">
                <svg viewBox="0 0 24 24" fill="none"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <p class="rpt-kpi-card__label">Sales for the Day</p>
            <div class="rpt-kpi-card__value" data-kpi="sales_today"><?= rpt_money($salesToday) ?></div>
            <p class="rpt-kpi-card__sub">Collected today &mdash; <?= date('M d') ?></p>
        </div>

        <!-- 2. Expected Revenue -->
        <div class="rpt-kpi-card rpt-kpi-card--indigo">
            <div class="rpt-kpi-card__icon">
                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </div>
            <p class="rpt-kpi-card__label">Expected Revenue</p>
            <div class="rpt-kpi-card__value" data-kpi="expected_revenue"><?= rpt_money($expectedRevenue) ?></div>
            <p class="rpt-kpi-card__sub">Active &amp; future bookings</p>
        </div>

        <!-- 3. Overdue -->
        <div class="rpt-kpi-card rpt-kpi-card--red">
            <div class="rpt-kpi-card__icon">
                <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </div>
            <p class="rpt-kpi-card__label">Overdue</p>
            <div class="rpt-kpi-card__value" data-kpi="overdue"><?= rpt_money($overdueAmount) ?></div>
            <p class="rpt-kpi-card__sub">Past check-out, unpaid</p>
        </div>

        <!-- 4. Guest Activity (dropdown card) -->
        <div class="rpt-kpi-card rpt-kpi-card--activity" id="activityCard">
            <div class="rpt-activity-header">
                <div class="rpt-kpi-card__icon rpt-kpi-card__icon--activity">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="rpt-activity-select-wrap">
                    <select class="rpt-activity-select" id="activitySelect">
                        <option value="arrivals">Expected Arrivals</option>
                        <option value="moveouts">Move-Out Guests</option>
                        <option value="inhouse" selected>In-House Guests</option>
                    </select>
                    <svg class="rpt-activity-chevron" viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            </div>
            <div class="rpt-activity-count" id="activityCount">
                <span class="rpt-kpi-card__value" id="activityValue"><?= $activityCounts['inhouse'] ?></span>
                <span class="rpt-activity-unit" id="activityUnit">Guests</span>
            </div>
            <p class="rpt-kpi-card__sub" id="activitySub">Currently staying in-house</p>
            <div class="rpt-activity-dots">
                <span class="rpt-dot rpt-dot--arrivals" data-type="arrivals" title="Arrivals: <?= $activityCounts['arrivals'] ?>"></span>
                <span class="rpt-dot rpt-dot--moveouts" data-type="moveouts" title="Move-outs: <?= $activityCounts['moveouts'] ?>"></span>
                <span class="rpt-dot rpt-dot--inhouse rpt-dot--active" data-type="inhouse" title="In-house: <?= $activityCounts['inhouse'] ?>"></span>
            </div>
        </div>

    </div>

    <!-- ── Guest list panel ──────────────────────────────── -->
    <div class="rpt-panel" id="guestPanel">
        <div class="rpt-panel__header">
            <div class="rpt-panel__title-wrap">
                <h2 class="rpt-panel__title" id="guestPanelTitle">In-House Guests</h2>
                <span class="rpt-count-badge" id="guestCount"></span>
            </div>
            <div class="rpt-panel__controls">
                <input type="date" class="rpt-date-input" id="guestDate" value="<?= $today ?>">
            </div>
        </div>
        <div class="rpt-table-wrap">
            <div class="rpt-loading" id="guestLoading">
                <div class="rpt-spinner"></div>
                <span>Loading guests…</span>
            </div>
            <table class="rpt-table" id="guestTable" style="display:none;">
                <thead id="guestThead"></thead>
                <tbody id="guestTbody"></tbody>
            </table>
        </div>
    </div>

    <!-- ── Trend chart ───────────────────────────────────── -->
    <div class="rpt-panel">
        <div class="rpt-panel__header">
            <div>
                <h2 class="rpt-panel__title">Revenue &amp; Occupancy Trend</h2>
                <p class="rpt-panel__sub">Last 6 months &mdash; <?= htmlspecialchars($branchLabel) ?></p>
            </div>
        </div>
        <?= rpt_trend_svg($trend) ?>
        <div class="rpt-legend">
            <span><i style="background:#2563eb;border-radius:3px;"></i> Revenue billed (bars, left axis)</span>
            <span><i style="background:#f59e0b;border-radius:50%;"></i> Occupancy rate (line, right axis)</span>
        </div>
    </div>

</main>

<script>
window.RPT_BRANCH  = <?= json_encode($branchKey) ?>;
window.RPT_TODAY   = <?= json_encode($today) ?>;
window.RPT_COUNTS  = <?= json_encode($activityCounts) ?>;
</script>
<script src="../assets/js/dashboard.js" defer></script>
<script src="../assets/js/reports.js" defer></script>
</body>
</html>