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

$rangeStart = new DateTime('first day of this month');
$rangeEnd   = (clone $rangeStart)->modify('+1 month');
$rangeLabel = $rangeStart->format('F Y');

$kpis  = db_report_kpis($branch, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
$trend = db_report_monthly_trend($branch, 6);

// ─── Get current checked‑in guests (live from DB) ──────────────
$checkedInCount = db_count_checked_in($branch);

function rpt_money($n)
{
    return '₱' . number_format((float) $n, 2);
}

function rpt_pct($n)
{
    return number_format((float) $n, 1) . '%';
}

function rpt_money_compact($n)
{
    $n = (float) $n;
    if ($n >= 1000000) return '₱' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000)    return '₱' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return '₱' . number_format($n, 0);
}

/**
 * Combo bar+line chart: monthly billed revenue as bars (left axis),
 * occupancy rate as a line (right axis, 0-100%). Plain inline SVG.
 */
function rpt_trend_svg($trend)
{
    $months = count($trend);
    if ($months === 0) {
        return '<p class="rpt-empty">No data yet.</p>';
    }

    $W = 760; $H = 280; $padL = 70; $padR = 60; $padT = 20; $padB = 36;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $maxBilled = 0;
    foreach ($trend as $r) {
        $maxBilled = max($maxBilled, (float) $r['billed']);
    }
    if ($maxBilled <= 0) {
        $maxBilled = 1;
    }
    $magnitude = 10 ** max(0, floor(log10($maxBilled)) - 1);
    $niceMax = ceil($maxBilled / $magnitude) * $magnitude;
    if ($niceMax <= 0) {
        $niceMax = 1;
    }

    $slot = $plotW / $months;
    $barW = $slot * 0.46;

    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="rpt-trend-svg" role="img" aria-label="Revenue and occupancy trend, last 6 months">';

    // Gridlines + left axis (revenue) labels
    for ($g = 0; $g <= 4; $g++) {
        $frac = $g / 4;
        $y = $padT + $plotH - $frac * $plotH;
        $val = $niceMax * $frac;
        $svg .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($W - $padR) . '" y2="' . round($y, 1) . '" stroke="#dceaf8" stroke-width="1" />';
        $svg .= '<text x="' . ($padL - 10) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="rpt-axis-label">' . htmlspecialchars(rpt_money_compact($val)) . '</text>';
    }
    // Right axis (occupancy %) labels
    foreach ([0, 50, 100] as $p) {
        $y = $padT + $plotH - ($p / 100) * $plotH;
        $svg .= '<text x="' . ($W - $padR + 12) . '" y="' . round($y + 4, 1) . '" text-anchor="start" class="rpt-axis-label rpt-axis-label--amber">' . $p . '%</text>';
    }

    // Bars + month labels
    $points = [];
    foreach ($trend as $i => $row) {
        $cx = $padL + $slot * $i + $slot / 2;
        $barH = ($row['billed'] / $niceMax) * $plotH;
        $barY = $padT + $plotH - $barH;
        $barX = $cx - $barW / 2;
        $svg .= '<rect x="' . round($barX, 1) . '" y="' . round($barY, 1) . '" width="' . round($barW, 1) . '" height="' . round(max(0, $barH), 1) . '" rx="4" fill="#3b7dd8"><title>' . htmlspecialchars($row['label'] . ': ' . rpt_money($row['billed']) . ' billed') . '</title></rect>';
        $svg .= '<text x="' . round($cx, 1) . '" y="' . ($H - 12) . '" text-anchor="middle" class="rpt-axis-label">' . htmlspecialchars($row['label']) . '</text>';

        $occClamped = min(100, max(0, (float) $row['occupancy_rate']));
        $oy = $padT + $plotH - ($occClamped / 100) * $plotH;
        $points[] = [round($cx, 1), round($oy, 1), $row];
    }

    // Occupancy line + points (drawn after bars so it sits on top)
    $polyPoints = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $points));
    $svg .= '<polyline points="' . $polyPoints . '" fill="none" stroke="#f0a857" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />';
    foreach ($points as $p) {
        [$cx, $cy, $row] = $p;
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="4.2" fill="#f0a857" stroke="#ffffff" stroke-width="1.5"><title>' . htmlspecialchars($row['label'] . ': ' . rpt_pct($row['occupancy_rate']) . ' occupancy') . '</title></circle>';
    }

    $svg .= '</svg>';
    return $svg;
}

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branchLabel) ?> — Reports &amp; Analytics · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
.reports-main {
    flex: 1;
    padding: clamp(20px, 4vw, 48px) clamp(16px, 5vw, 56px);
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}
.reports-header { margin-bottom: 24px; }
.reports-header__title {
    font-family: var(--font-serif);
    font-size: clamp(1.4rem, 3vw, 2rem);
    font-weight: 700;
    color: var(--blue-900);
    margin: 0;
}
.reports-header__sub {
    font-size: 0.82rem;
    color: var(--blue-500);
    margin: 4px 0 0;
}

.rpt-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
}
.rpt-kpi-card {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 20px 22px;
}
.rpt-kpi-card__label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--blue-500);
    margin: 0 0 8px;
}
.rpt-kpi-card__value {
    font-family: var(--font-serif);
    font-size: 1.7rem;
    font-weight: 700;
    color: var(--blue-900);
    line-height: 1.15;
}
.rpt-kpi-card__sub {
    font-size: 0.76rem;
    color: var(--gray-500);
    margin-top: 6px;
}
.rpt-kpi-card__sub strong {
    color: var(--blue-700);
}

/* ── Trend chart ─────────────────────────────────────────────────── */
.rpt-section {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 22px 24px;
    margin-top: 22px;
}
.rpt-section__title {
    font-family: var(--font-serif);
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--blue-900);
    margin: 0 0 2px;
}
.rpt-section__sub {
    font-size: 0.78rem;
    color: var(--blue-500);
    margin: 0 0 16px;
}
.rpt-trend-svg { width: 100%; height: auto; display: block; }
.rpt-axis-label { font-size: 11px; fill: var(--blue-500); font-family: var(--font-sans); }
.rpt-axis-label--amber { fill: #c98a2e; }
.rpt-legend { display: flex; gap: 18px; margin-top: 10px; font-size: 0.78rem; color: var(--blue-500); flex-wrap: wrap; }
.rpt-legend span { display: inline-flex; align-items: center; gap: 6px; }
.rpt-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.rpt-empty { color: var(--gray-500); font-size: 0.85rem; padding: 12px 0; }

/* ─── Checked‑in card special style ────────────────────────────── */
.rpt-kpi-card--checked-in {
    border-left: 4px solid #34d399;
}
.rpt-kpi-card--checked-in .rpt-kpi-card__value {
    color: #0f7a4f;
}
</style>
</head>
<body class="dashboard-body">

<!-- ── TOP BAR ─────────────────────────────────────────────────── -->
<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<?php if (bb_is_admin()): ?><span class="topbar__brand-suffix">.admin</span><?php endif; ?></span>
    </div>
    <div class="topbar__right">
        <div class="topbar__user">
            <span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span>
            <span class="topbar__user-role"><?= bb_is_admin() ? 'Admin' : 'Staff' ?></span>
        </div>
        <a href="../logout.php" class="topbar__logout">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ── NAV BAR ─────────────────────────────────────────────────── -->
<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<!-- ── MAIN ────────────────────────────────────────────────────── -->
<main class="reports-main">
    <div class="reports-header">
        <h1 class="reports-header__title"><?= htmlspecialchars($branchLabel) ?> — Reports &amp; Analytics</h1>
        <p class="reports-header__sub"><?= htmlspecialchars($rangeLabel) ?></p>
    </div>

    <div class="rpt-kpi-grid">
        <div class="rpt-kpi-card">
            <p class="rpt-kpi-card__label">Revenue</p>
            <div class="rpt-kpi-card__value"><?= rpt_money($kpis['billed']) ?></div>
            <p class="rpt-kpi-card__sub">Bookings made this month</p>
        </div>
        <div class="rpt-kpi-card">
            <p class="rpt-kpi-card__label">Reservations</p>
            <div class="rpt-kpi-card__value"><?= number_format($kpis['reservation_count']) ?></div>
            <p class="rpt-kpi-card__sub"><?= $kpis['cancelled_count'] ?> cancelled</p>
        </div>
        <div class="rpt-kpi-card">
            <p class="rpt-kpi-card__label">Outstanding Balance</p>
            <div class="rpt-kpi-card__value"><?= rpt_money($kpis['outstanding']) ?></div>
            <p class="rpt-kpi-card__sub">Billed, not yet collected</p>
        </div>
        <div class="rpt-kpi-card rpt-kpi-card--checked-in">
            <p class="rpt-kpi-card__label">Currently Checked‑In</p>
            <div class="rpt-kpi-card__value"><?= number_format($checkedInCount) ?></div>
            <p class="rpt-kpi-card__sub"><strong><?= htmlspecialchars($branchLabel) ?></strong> · live from reservations</p>
        </div>
    </div>

    <div class="rpt-section">
        <h2 class="rpt-section__title">Revenue &amp; Occupancy — Last 6 Months</h2>
        <p class="rpt-section__sub"><?= htmlspecialchars($branchLabel) ?>, trailing the current month — independent of the KPI cards above</p>
        <?= rpt_trend_svg($trend) ?>
        <div class="rpt-legend">
            <span><i style="background:#3b7dd8"></i> Revenue billed (bars, left axis)</span>
            <span><i style="background:#f0a857;border-radius:50%"></i> Occupancy rate (line, right axis)</span>
        </div>
    </div>
</main>

<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>