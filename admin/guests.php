<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('guests');

$allBranches = [
    'annex'          => 'BB Apartelle',
    'mtv'            => 'MTV3',
    'dormitel'       => 'ELTI Dormitel',
    'aps'            => 'APS',
    'euroasia_stall' => 'Euroasia Stall',
    'annex_stall'    => 'Annex Stall',
];
$branchKey  = $_GET['branch'] ?? '';
$branchName = $allBranches[$branchKey] ?? '';
$branch     = $branchKey; // alias for property_navbar.php

$search = trim($_GET['search'] ?? '');
$status = $_GET['status']        ?? '';
$branch = $_GET['branch']        ?? '';
$sort   = $_GET['sort']          ?? 'check_in_desc';

function formatDuration($checkIn, $checkOut) {
    $start  = new DateTime($checkIn);
    $end    = new DateTime($checkOut);
    $diff   = $start->diff($end);
    $months = $diff->m + ($diff->y * 12);
    $days   = $diff->d;
    if ($months == 0 && $days == 0) return '0 Days';
    $parts = [];
    if ($months > 0) $parts[] = $months . ' Month' . ($months > 1 ? 's' : '');
    if ($days   > 0) $parts[] = $days   . ' Day'   . ($days   > 1 ? 's' : '');
    return implode(' ', $parts);
}

$pdo = bb_db();

$validStatuses = ['pending','reserved','checked_in','checked_out','cancelled'];
$validSorts    = [
    'check_in_desc' => 'r.check_in DESC',
    'check_in_asc'  => 'r.check_in ASC',
    'name_asc'      => 'r.guest_full_name ASC',
    'name_desc'     => 'r.guest_full_name DESC',
    'created_desc'  => 'r.created_at DESC',
];
$orderBy = $validSorts[$sort] ?? 'r.check_in DESC';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(r.guest_full_name LIKE ? OR r.contact_number LIKE ? OR r.email LIKE ? OR r.valid_id_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $where[]  = 'r.status = ?';
    $params[] = $status;
}
if ($branch !== '') {
    $where[]  = 'ro.branch = ?';
    $params[] = $branch;
}

$sql  = "SELECT r.*, ro.room_number, ro.room_type, ro.branch, ro.price_per_night
         FROM reservations r
         JOIN rooms ro ON ro.id = r.room_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY $orderBy";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$guests = $stmt->fetchAll();

foreach ($guests as &$g) {
    $g['duration'] = formatDuration($g['check_in'], $g['check_out']);
}
unset($g);

$statsStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM reservations GROUP BY status");
$rawStats  = $statsStmt->fetchAll();
$stats     = ['pending'=>0,'reserved'=>0,'checked_in'=>0,'checked_out'=>0,'cancelled'=>0,'total'=>0];
foreach ($rawStats as $s) {
    if (isset($stats[$s['status']])) $stats[$s['status']] = (int)$s['cnt'];
    $stats['total'] += (int)$s['cnt'];
}

$lodgingBranches = ['annex'=>'BB Apartelle','mtv'=>'MTV3','dormitel'=>'ELTI Dormitel'];
$statusLabels    = ['pending'=>'Pending','reserved'=>'Reserved','checked_in'=>'Checked In','checked_out'=>'Checked Out','cancelled'=>'Cancelled'];
$paymentLabels   = ['cash'=>'Cash','gcash'=>'GCash','bank_transfer'=>'Bank Transfer','card'=>'Card'];

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $branchName ? htmlspecialchars($branchName).' — ' : '' ?>Guests · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
.guests-main{flex:1;padding:clamp(20px,4vw,48px) clamp(16px,5vw,56px);max-width:1400px;margin:0 auto;width:100%;box-sizing:border-box;}
.guests-header{margin-bottom:24px;}
.guests-header__title{font-family:var(--font-serif);font-size:clamp(1.4rem,3vw,2rem);font-weight:700;color:var(--blue-900);margin:0;}
.guests-header__sub{font-size:.82rem;color:var(--blue-500);margin:4px 0 0;}
.stats-bar{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px;}
.stat-chip{background:var(--white);border:1px solid var(--blue-100);border-radius:999px;padding:8px 18px;font-size:.82rem;font-weight:600;color:var(--blue-900);display:flex;align-items:center;gap:7px;box-shadow:var(--shadow-card);}
.stat-chip__dot{width:8px;height:8px;border-radius:50%;}
.filter-bar{background:var(--white);border:1px solid var(--blue-100);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;box-shadow:var(--shadow-card);}
.filter-bar input,.filter-bar select{padding:9px 14px;border-radius:var(--radius-md);border:1px solid var(--blue-100);font-size:.88rem;font-family:inherit;background:var(--white);color:var(--blue-900);outline:none;}
.filter-bar input{flex:1;min-width:200px;}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--blue-500);box-shadow:0 0 0 3px rgba(59,125,216,.12);}
.filter-results{font-size:.82rem;color:var(--blue-500);margin-left:auto;white-space:nowrap;}
.guests-table-wrap{overflow-x:auto;border-radius:var(--radius-lg);background:var(--white);border:1px solid var(--blue-100);box-shadow:var(--shadow-card);}
.guests-table{width:100%;border-collapse:collapse;font-size:.86rem;}
.guests-table th{background:var(--blue-50);padding:12px 16px;text-align:left;font-size:.75rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--blue-500);border-bottom:1px solid var(--blue-100);white-space:nowrap;}
.guests-table td{padding:14px 16px;border-bottom:1px solid var(--blue-50);vertical-align:middle;color:var(--blue-900);}
.guests-table tr:last-child td{border-bottom:none;}
.guests-table tr:hover td{background:var(--blue-50);cursor:pointer;}
.guest-name{font-weight:600;color:var(--blue-900);}
.guest-meta{font-size:.78rem;color:var(--blue-500);margin-top:2px;}
.status-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.74rem;font-weight:700;letter-spacing:.03em;white-space:nowrap;}
.status-badge--reserved{background:#dceaf8;color:#2861b3;}
.status-badge--pending{background:#ede9fe;color:#6d28d9;}
.status-badge--confirmed{background:#e0f2fe;color:#0369a1;}
.status-badge--checked_in{background:#d4f7e7;color:#1a7a46;}
.status-badge--no_show{background:#fef3c7;color:#92400e;}
.status-badge--checked_out{background:#eef0f2;color:#5b7693;}
.status-badge--cancelled{background:#fde8e8;color:#b91c1c;}
.empty-state{text-align:center;padding:60px 20px;color:var(--blue-500);}
.empty-state__icon{font-size:2.4rem;margin-bottom:12px;}
.empty-state h3{color:var(--blue-900);margin:0 0 6px;}

/* ── Modal overlay ─────────────────────────────────────────── */
.bb-overlay{position:fixed;inset:0;background:rgba(22,50,79,.5);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px;}
.bb-overlay[hidden]{display:none;}

/* ── Folio modal ──────────────────────────────────────────── */
.folio-modal{background:var(--white);border-radius:var(--radius-lg);width:100%;max-width:940px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 32px 80px -20px rgba(22,50,79,.35);overflow:hidden;}
.folio-header{background:var(--blue-900);color:var(--white);padding:18px 24px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-shrink:0;}
.folio-header h2{margin:0;font-family:'Playfair Display',serif;font-size:1.1rem;}
.folio-header__sub{font-size:.8rem;opacity:.65;margin-top:3px;}
.folio-header__actions{display:flex;gap:8px;align-items:center;flex-shrink:0;}
.fhbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--radius-md);font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:filter 150ms;white-space:nowrap;}
.fhbtn:hover{filter:brightness(1.12);}
.fhbtn--ghost{background:rgba(255,255,255,.18);color:#fff;}
.fhbtn--primary{background:#3b7dd8;color:#fff;}
.fhbtn--success{background:#16a34a;color:#fff;}
.folio-close{background:none;border:none;color:var(--white);cursor:pointer;font-size:1.5rem;line-height:1;padding:2px 0 0;opacity:.7;}
.folio-close:hover{opacity:1;}
.folio-body{overflow-y:auto;padding:24px;flex:1;}
.folio-loading{display:flex;align-items:center;justify-content:center;gap:10px;padding:60px;color:var(--blue-500);font-size:.88rem;}
.folio-spinner{width:20px;height:20px;border:2px solid var(--blue-100);border-top-color:var(--blue-500);border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

/* Summary cards */
.folio-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:28px;}
.fc{background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius-md);padding:14px 16px;}
.fc__lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--blue-500);}
.fc__val{font-size:1rem;font-weight:700;color:var(--blue-900);margin-top:5px;}
.fc--blue{border-color:var(--blue-400);background:#ebf3fc;}
.fc--blue .fc__val{color:var(--blue-700);}
.fc--green{border-color:#86efac;background:#f0fdf4;}
.fc--green .fc__val{color:#15803d;}
.fc--red{border-color:#fca5a5;background:#fff1f1;}
.fc--red .fc__val{color:#b91c1c;}

/* Section titles */
.folio-sec{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--blue-500);margin:28px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--blue-100);}
.folio-sec:first-of-type{margin-top:0;}

/* Info grid */
.folio-info{display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;margin-bottom:4px;font-size:.86rem;}
.folio-row{display:flex;flex-direction:column;gap:2px;}
.folio-row .lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--blue-500);}
.folio-row .val{color:var(--blue-900);font-weight:600;}

/* Tables */
.folio-tbl-wrap{overflow-x:auto;border-radius:var(--radius-md);border:1px solid var(--blue-100);margin-bottom:4px;}
.folio-tbl{width:100%;border-collapse:collapse;font-size:.84rem;}
.folio-tbl th{background:var(--blue-50);padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--blue-500);border-bottom:1px solid var(--blue-100);white-space:nowrap;}
.folio-tbl td{padding:10px 12px;border-bottom:1px solid var(--blue-50);color:var(--blue-900);vertical-align:middle;}
.folio-tbl tbody tr:last-child td{border-bottom:none;}
.folio-tbl .r{text-align:right;font-weight:600;font-variant-numeric:tabular-nums;}
.folio-tbl .g{text-align:right;font-weight:700;color:#15803d;font-variant-numeric:tabular-nums;}
.folio-tbl tfoot td{font-weight:700;background:var(--blue-50);border-top:2px solid var(--blue-100);padding:11px 12px;}
.no-data{text-align:center;padding:24px;color:var(--blue-500);font-size:.85rem;}
.pay-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:700;background:#dceaf8;color:#2861b3;}
.pay-status{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:700;}
.ps--paid{background:#d4f7e7;color:#15803d;}
.ps--partial{background:#fef9c3;color:#854d0e;}
.ps--overdue{background:#fde8e8;color:#b91c1c;}
.ps--unpaid{background:#f1f5f9;color:#64748b;}

.pay-del{background:none;border:none;cursor:pointer;color:#b91c1c;font-size:.78rem;opacity:.6;padding:2px 6px;border-radius:4px;}
.pay-del:hover{opacity:1;background:#fff1f1;}

/* Utility status badges */
.util-status{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:700;}
.us--paid{background:#d4f7e7;color:#15803d;}
.us--unpaid{background:#f1f5f9;color:#64748b;}
.us--partial{background:#fef9c3;color:#854d0e;}

/* ── Record Payment modal ─────────────────────────────────── */
.pay-modal{background:var(--white);border-radius:var(--radius-lg);width:100%;max-width:460px;box-shadow:0 24px 60px -12px rgba(22,50,79,.35);overflow:hidden;}
.pay-modal__head{background:var(--blue-900);color:#fff;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;}
.pay-modal__head h3{margin:0;font-family:'Playfair Display',serif;font-size:1rem;}
.pay-modal__body{padding:22px;}
.pay-field{display:flex;flex-direction:column;gap:5px;margin-bottom:16px;}
.pay-field label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--blue-700);}
.pay-field input,.pay-field select,.pay-field textarea{padding:10px 13px;border:1.5px solid var(--blue-100);border-radius:var(--radius-md);font-family:inherit;font-size:.88rem;color:var(--blue-900);background:var(--white);outline:none;transition:border-color 150ms;}
.pay-field input:focus,.pay-field select:focus,.pay-field textarea:focus{border-color:var(--blue-500);box-shadow:0 0 0 3px rgba(59,125,216,.12);}
.pay-field textarea{resize:vertical;min-height:60px;}
.pay-remaining{background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;font-size:.86rem;}
.pay-remaining strong{font-size:1rem;font-weight:700;}
.pay-remaining.overpaid strong{color:#b91c1c;}
.pay-actions{display:flex;gap:10px;justify-content:flex-end;}

@media(max-width:600px){
  .folio-info{grid-template-columns:1fr;}
  .folio-summary{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body class="dashboard-body">

<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<?php if(bb_is_admin()):?><span class="topbar__brand-suffix">.admin</span><?php endif;?></span>
    </div>
    <div class="topbar__right">
        <div class="topbar__user">
            <span class="topbar__user-name"><?=htmlspecialchars($displayName)?></span>
            <span class="topbar__user-role"><?=bb_is_admin()?'Admin':'Staff'?></span>
        </div>
        <a href="../logout.php" class="topbar__logout">
            <svg viewBox="0 0 24 24" fill="none"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>

<?php if($branchKey): include __DIR__.'/includes/property_navbar.php'; else: include __DIR__.'/includes/navbar.php'; endif; ?>

<main class="guests-main">
    <div class="guests-header">
        <h1 class="guests-header__title"><?=$branchName?htmlspecialchars($branchName).' — ':''?>Guests &amp; Folios</h1>
        <p class="guests-header__sub"><?=$branchName?'Guest reservations for '.htmlspecialchars($branchName):'All guest reservations across every property.'?></p>
    </div>

    <div class="stats-bar">
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#8a9aa8"></span>All: <?=$stats['total']?></div>
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#a78bfa"></span>Pending: <?=$stats['pending']?></div>
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#3b7dd8"></span>Reserved: <?=$stats['reserved']?></div>
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#2ecc71"></span>Checked In: <?=$stats['checked_in']?></div>
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#8a9aa8"></span>Checked Out: <?=$stats['checked_out']?></div>
        <div class="stat-chip"><span class="stat-chip__dot" style="background:#e74c3c"></span>Cancelled: <?=$stats['cancelled']?></div>
    </div>

    <form method="get" class="filter-bar">
        <input type="text" name="search" placeholder="Search name, phone, email, ID…" value="<?=htmlspecialchars($search)?>">
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach($statusLabels as $k=>$v): ?>
            <option value="<?=$k?>" <?=$status===$k?'selected':''?>><?=$v?></option>
            <?php endforeach; ?>
        </select>
        <select name="branch">
            <option value="">All Properties</option>
            <?php foreach($lodgingBranches as $k=>$v): ?>
            <option value="<?=$k?>" <?=$branch===$k?'selected':''?>><?=$v?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <option value="check_in_desc" <?=$sort==='check_in_desc'?'selected':''?>>Check-in ↓</option>
            <option value="check_in_asc"  <?=$sort==='check_in_asc'?'selected':''?>>Check-in ↑</option>
            <option value="name_asc"      <?=$sort==='name_asc'?'selected':''?>>Name A–Z</option>
            <option value="name_desc"     <?=$sort==='name_desc'?'selected':''?>>Name Z–A</option>
            <option value="created_desc"  <?=$sort==='created_desc'?'selected':''?>>Newest First</option>
        </select>
        <button type="submit" class="btn btn--primary" style="width:auto;padding:9px 20px;">Filter</button>
        <?php if($search||$status||$branch): ?>
        <a href="guests.php" class="btn btn--ghost" style="width:auto;padding:9px 20px;">Reset</a>
        <?php endif; ?>
        <span class="filter-results"><?=count($guests)?> record<?=count($guests)!==1?'s':''?></span>
    </form>

    <?php if(empty($guests)): ?>
    <div class="empty-state">
        <div class="empty-state__icon">🏨</div>
        <h3>No guests found</h3>
        <p>Try adjusting your filters or add a new reservation from the Calendar.</p>
    </div>
    <?php else: ?>
    <div class="guests-table-wrap">
        <table class="guests-table">
            <thead>
                <tr>
                    <th>#</th><th>Guest</th><th>Property</th><th>Room</th>
                    <th>Check-In</th><th>Check-Out</th><th>Duration</th><th>Status</th>
                    <th>Amount Due</th><th>Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($guests as $g):
                $brLabel2 = $allBranches[$g['branch']] ?? ucfirst($g['branch']);
                $bal      = (float)$g['total_amount'] - (float)$g['amount_paid'];
            ?>
            <tr class="guest-row" data-reservation='<?=htmlspecialchars(json_encode($g),ENT_QUOTES)?>'>
                <td style="color:var(--blue-500);font-size:.78rem;">#<?=$g['id']?></td>
                <td>
                    <div class="guest-name"><?=htmlspecialchars($g['guest_full_name'])?></div>
                    <div class="guest-meta">
                        <?php if($g['contact_number']):?><?=htmlspecialchars($g['contact_number'])?><?php endif;?>
                        <?php if($g['email']):?> · <?=htmlspecialchars($g['email'])?><?php endif;?>
                    </div>
                </td>
                <td><?=htmlspecialchars($brLabel2)?></td>
                <td><strong>RM <?=htmlspecialchars($g['room_number'])?></strong><div class="guest-meta"><?=htmlspecialchars($g['room_type'])?></div></td>
                <td><?=htmlspecialchars($g['check_in'])?></td>
                <td><?=htmlspecialchars($g['check_out'])?></td>
                <td><?=htmlspecialchars($g['duration'])?></td>
                <td><span class="status-badge status-badge--<?=$g['status']?>"><?=$statusLabels[$g['status']] ?? ucwords(str_replace('_',' ',$g['status']))?></span></td>
                <td>₱<?=number_format((float)$g['total_amount'],2)?></td>
                <td style="color:<?=$bal>0?'#b91c1c':'#1a7a46'?>;font-weight:700;">₱<?=number_format($bal,2)?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- ══════════════════════════════════════════════════════════════
     FOLIO MODAL
══════════════════════════════════════════════════════════════ -->
<div id="folioModal" class="bb-overlay" hidden>
  <div class="folio-modal">
    <div class="folio-header">
      <div>
        <h2 id="folioTitle">Guest Folio</h2>
        <div class="folio-header__sub" id="folioSubtitle"></div>
      </div>
      <div class="folio-header__actions">
        <button class="fhbtn fhbtn--success" id="folioPayBtn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Record Payment
        </button>
        <button class="fhbtn fhbtn--ghost" id="folioPrintBtn">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Print
        </button>
        <button class="folio-close" id="folioClose">&times;</button>
      </div>
    </div>
    <div class="folio-body" id="folioBody">
      <div class="folio-loading"><div class="folio-spinner"></div>Loading folio…</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     RECORD PAYMENT MODAL
══════════════════════════════════════════════════════════════ -->
<div id="payModal" class="bb-overlay" hidden>
  <div class="pay-modal">
    <div class="pay-modal__head">
      <h3>Record Payment</h3>
      <button class="folio-close" id="payClose">&times;</button>
    </div>
    <div class="pay-modal__body">
      <div class="pay-remaining" id="payRemaining">
        <span>Outstanding Balance</span>
        <strong id="payBalanceDisplay">₱0.00</strong>
      </div>
      <div class="pay-field">
        <label>Amount Being Paid *</label>
        <input type="number" id="payAmount" min="1" step="0.01" placeholder="e.g. 10000">
      </div>
      <div class="pay-field">
        <label>Payment Date *</label>
        <input type="date" id="payDate">
      </div>
      <div class="pay-field">
        <label>Payment Method *</label>
        <select id="payMethod">
          <option value="">— Select —</option>
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="card">Credit / Debit Card</option>
        </select>
      </div>
      <div class="pay-field">
        <label>Remarks / Reference No.</label>
        <textarea id="payRemarks" placeholder="Optional notes, GCash ref, bank ref…"></textarea>
      </div>
      <p id="payError" style="color:#b91c1c;font-size:.82rem;margin:0 0 12px;display:none;"></p>
      <div class="pay-actions">
        <button class="btn btn--ghost" id="payCancelBtn">Cancel</button>
        <button class="btn btn--primary" id="paySubmitBtn">Save Payment</button>
      </div>
    </div>
  </div>
</div>

<script>
const STATUS_LABELS  = <?=json_encode($statusLabels)?>;
const PAYMENT_LABELS = <?=json_encode($paymentLabels)?>;
const ALL_BRANCHES   = <?=json_encode($allBranches)?>;
const CAN_DELETE     = <?=bb_is_admin()?'true':'false'?>;

// ── Formatters ──────────────────────────────────────────────────
function fmt(n) {
    return '₱' + parseFloat(n||0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '—';
    return d.innerHTML;
}
function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.includes('T') ? s : s + 'T00:00:00');
    return d.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
}
function formatDuration(ci, co) {
    const start = new Date(ci + 'T00:00:00');
    const end   = new Date(co + 'T00:00:00');
    const ms    = end - start;
    if (ms <= 0) return '0 Days';
    // Compute exact months + leftover days using date arithmetic
    let y = end.getFullYear() - start.getFullYear();
    let m = end.getMonth()    - start.getMonth();
    let d = end.getDate()     - start.getDate();
    if (d < 0) { m--; const prev = new Date(end.getFullYear(), end.getMonth(), 0); d += prev.getDate(); }
    if (m < 0) { y--; m += 12; }
    const months = y * 12 + m;
    const parts  = [];
    if (months > 0) parts.push(months + ' Month' + (months > 1 ? 's' : ''));
    if (d > 0)      parts.push(d + ' Day' + (d > 1 ? 's' : ''));
    return parts.join(' ') || '0 Days';
}

// ── State ───────────────────────────────────────────────────────
let _folio = null;   // current reservation object
let _payments = [];  // current payment records

// ── Open folio ──────────────────────────────────────────────────
document.querySelectorAll('.guest-row').forEach(row => {
    row.addEventListener('click', () => openFolio(JSON.parse(row.dataset.reservation)));
});

function openFolio(r) {
    _folio    = r;
    _payments = [];

    const folioNo = '#' + String(r.id).padStart(10, '0');
    document.getElementById('folioTitle').textContent    = 'Guest Folio [' + folioNo + ']';
    document.getElementById('folioSubtitle').textContent = (r.guest_full_name ?? '') + ' · ' + (ALL_BRANCHES[r.branch] ?? r.branch);
    document.getElementById('folioBody').innerHTML =
        '<div class="folio-loading"><div class="folio-spinner"></div>Loading folio…</div>';
    document.getElementById('folioModal').removeAttribute('hidden');

    fetch('/process_reservation.php?action=get_reservation_for_payment&id=' + r.id)
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Load failed.');
            _payments = data.months || [];
            renderFolio(r, _payments, data.outstanding_balance, data.payment_status);
            loadUtilities(r.id);
        })
        .catch(err => {
            console.error('[folio] load error:', err);
            renderFolio(r, [], null, null, true);
            loadUtilities(r.id);
        });
}

// ── Render folio ─────────────────────────────────────────────────
function renderFolio(r, payments, outstanding, paymentStatus, fallback) {
    const totalDue  = parseFloat(r.total_amount) || 0;

    // Always sum real payment records for accuracy.
    // The server's outstanding_balance can be 0 when fin_outstanding_balance()
    // isn't loaded yet, so we never rely on it — we calculate directly.
    const totalPaid = payments.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
    const balance   = totalDue - totalPaid;

    const brLabel  = ALL_BRANCHES[r.branch] ?? r.branch;
    const stLabel  = STATUS_LABELS[r.status] ?? r.status;
    const dur      = formatDuration(r.check_in, r.check_out);

    // Derive payment status client-side from real numbers —
    // don't depend on the server's fin_payment_status() function.
    const today = new Date().toISOString().split('T')[0];
    let psCls, psLbl;
    if (balance <= 0) {
        psCls = 'ps--paid';    psLbl = 'Fully Paid';
    } else if (totalPaid > 0) {
        // Has a balance but some payments made — check if overdue
        const isOverdue = r.check_out && r.check_out < today && r.status !== 'cancelled';
        psCls = isOverdue ? 'ps--overdue' : 'ps--partial';
        psLbl = isOverdue ? 'Overdue'     : 'Partially Paid';
    } else {
        // No payments at all
        const isOverdue = r.check_out && r.check_out < today && r.status !== 'cancelled';
        psCls = isOverdue ? 'ps--overdue' : 'ps--unpaid';
        psLbl = isOverdue ? 'Overdue'     : 'Unpaid';
    }

    // Monthly charges: one row per rental month + security deposit
    const charges    = buildMonthlySchedule(r);
    const secDeposit = parseFloat(r.security_deposit) || 0;
    const rentTotal  = charges.reduce((s, c) => s + parseFloat(c.amount), 0);
    const grandTotal = rentTotal + secDeposit;   // what the guest truly owes

    let chargeRows = charges.length
        ? charges.map(c => `<tr>
            <td>${esc(c.period)}</td>
            <td>${esc(c.description)}</td>
            <td class="r">${fmt(c.rate)}</td>
            <td class="r">${fmt(c.amount)}</td>
          </tr>`).join('')
        : '<tr><td colspan="4" class="no-data">No rate set — add a monthly rate to the reservation.</td></tr>';

    if (secDeposit > 0) {
        chargeRows += `<tr style="background:#f8fbff;">
            <td>—</td>
            <td><em>Security Deposit</em></td>
            <td class="r">—</td>
            <td class="r" style="color:var(--blue-700);">${fmt(secDeposit)}</td>
          </tr>`;
    }

    // Payment history rows
    const payRows = fallback
        ? '<tr><td colspan="5" class="no-data">Could not load payments.</td></tr>'
        : payments.length
            ? payments.map(p => {
                const pmLbl = PAYMENT_LABELS[p.payment_method] ?? (p.payment_method || '—');
                const who   = p.full_name || p.username || '—';
                const delBtn = CAN_DELETE
                    ? `<button class="pay-del" onclick="deletePayment(${p.id})" title="Delete">✕</button>`
                    : '';
                return `<tr>
                    <td>${esc(fmtDate(p.payment_date || p.created_at))}</td>
                    <td><span class="pay-badge">${esc(pmLbl)}</span></td>
                    <td style="color:var(--blue-500);font-size:.8rem;">${esc(who)}</td>
                    <td>${esc(p.remarks || '—')}</td>
                    <td class="g">${fmt(p.amount)}</td>
                    ${CAN_DELETE ? '<td>' + delBtn + '</td>' : ''}
                  </tr>`;
            }).join('')
            : '<tr><td colspan="5" class="no-data">No payments recorded yet. Use "Record Payment" to add one.</td></tr>';

    const payColspan = CAN_DELETE ? 5 : 4;

    document.getElementById('folioBody').innerHTML = `
      <!-- Summary cards -->
      <div class="folio-summary">
        <div class="fc fc--blue"><div class="fc__lbl">Total Due</div><div class="fc__val">${fmt(totalDue)}</div></div>
        <div class="fc fc--green"><div class="fc__lbl">Total Paid</div><div class="fc__val">${fmt(totalPaid)}</div></div>
        <div class="fc ${balance > 0 ? 'fc--red' : 'fc--green'}"><div class="fc__lbl">Balance</div><div class="fc__val">${fmt(balance)}</div></div>
        <div class="fc"><div class="fc__lbl">Payment Status</div><div class="fc__val" style="font-size:.85rem;"><span class="pay-status ${psCls}">${esc(psLbl)}</span></div></div>
        <div class="fc"><div class="fc__lbl">Room</div><div class="fc__val" style="font-size:.9rem;">RM ${esc(r.room_number)}</div></div>
        <div class="fc"><div class="fc__lbl">Status</div><div class="fc__val" style="font-size:.9rem;">${esc(stLabel)}</div></div>
        <div class="fc"><div class="fc__lbl">Arrival</div><div class="fc__val" style="font-size:.9rem;">${esc(fmtDate(r.check_in))}</div></div>
        <div class="fc"><div class="fc__lbl">Departure</div><div class="fc__val" style="font-size:.9rem;">${esc(fmtDate(r.check_out))}</div></div>
        <div class="fc"><div class="fc__lbl">Duration</div><div class="fc__val" style="font-size:.9rem;">${esc(dur)}</div></div>
        <div class="fc"><div class="fc__lbl">Monthly Rent</div><div class="fc__val" style="font-size:.9rem;">${fmt(r.room_rate)}/mo</div></div>
        ${secDeposit > 0 ? `<div class="fc"><div class="fc__lbl">Security Deposit</div><div class="fc__val" style="font-size:.9rem;">${fmt(secDeposit)}</div></div>` : ''}
      </div>

      <!-- Guest info -->
      <div class="folio-sec">Guest Information</div>
      <div class="folio-info" style="margin-bottom:24px;">
        <div class="folio-row"><span class="lbl">Full Name</span><span class="val">${esc(r.guest_full_name)}</span></div>
        <div class="folio-row"><span class="lbl">Contact #</span><span class="val">${esc(r.contact_number||'—')}</span></div>
        <div class="folio-row"><span class="lbl">Email</span><span class="val">${esc(r.email||'—')}</span></div>
        <div class="folio-row"><span class="lbl">Address</span><span class="val">${esc(r.address||'—')}</span></div>
        <div class="folio-row"><span class="lbl">Valid ID</span><span class="val">${esc(r.valid_id_type||'—')} ${r.valid_id_number?'#'+esc(r.valid_id_number):''}</span></div>
        <div class="folio-row"><span class="lbl">Adults / Children</span><span class="val">${esc(r.num_adults)} / ${esc(r.num_children)}</span></div>
        <div class="folio-row"><span class="lbl">Reservation Fee</span><span class="val">${fmt(r.reservation_fee||0)}</span></div>
        <div class="folio-row"><span class="lbl">Garbage Fee</span><span class="val">${fmt(r.garbage_fee||0)}</span></div>
        <div class="folio-row"><span class="lbl">Security Deposit</span><span class="val">${fmt(r.security_deposit)}</span></div>
        <div class="folio-row"><span class="lbl">Utilities Deposit</span><span class="val">${fmt(r.utilities_deposit||0)}</span></div>
        <div class="folio-row"><span class="lbl">Property</span><span class="val">${esc(brLabel)}</span></div>
        ${r.special_requests?`<div class="folio-row" style="grid-column:1/-1"><span class="lbl">Special Requests</span><span class="val">${esc(r.special_requests)}</span></div>`:''}
        ${r.notes?`<div class="folio-row" style="grid-column:1/-1"><span class="lbl">Notes</span><span class="val">${esc(r.notes)}</span></div>`:''}
      </div>

      <!-- Monthly charges -->
      <div class="folio-sec">Monthly Charges Schedule</div>
      <div class="folio-tbl-wrap">
        <table class="folio-tbl">
          <thead><tr><th>Period</th><th>Description</th><th style="text-align:right">Monthly Rent</th><th style="text-align:right">Amount Due</th></tr></thead>
          <tbody>${chargeRows}</tbody>
          <tfoot><tr><td colspan="3">Total Charges</td><td class="r">${fmt(grandTotal || totalDue)}</td></tr></tfoot>
        </table>
      </div>

      <!-- Payment history -->
      <div class="folio-sec" style="margin-top:24px;">Payment History</div>
      <div class="folio-tbl-wrap" id="payHistoryWrap">
        <table class="folio-tbl">
          <thead><tr><th>Date</th><th>Method</th><th>Recorded By</th><th>Remarks</th><th style="text-align:right">Amount</th>${CAN_DELETE?'<th></th>':''}</tr></thead>
          <tbody id="payHistoryBody">${payRows}</tbody>
          <tfoot><tr><td colspan="${payColspan}">Total Paid</td><td class="g">${fmt(totalPaid)}</td>${CAN_DELETE?'<td></td>':''}</tr></tfoot>
        </table>
      </div>

      <!-- Utilities Payments -->
      <div class="folio-sec" style="margin-top:24px;">Utilities Payments</div>
      <div class="folio-tbl-wrap" id="utilHistoryWrap">
        <table class="folio-tbl" id="utilTable">
          <thead><tr><th>Utility Type</th><th>Billing Period</th><th style="text-align:right">Amount</th><th>Status</th>${CAN_DELETE?'<th></th>':''}</tr></thead>
          <tbody id="utilHistoryBody"><tr><td colspan="${CAN_DELETE?5:4}" class="no-data" id="utilLoadingMsg">Loading utilities…</td></tr></tbody>
          <tfoot><tr><td colspan="${CAN_DELETE?3:2}">Total Utilities</td><td class="r" id="utilTotal">₱0.00</td><td></td>${CAN_DELETE?'<td></td>':''}</tr></tfoot>
        </table>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="utilAddRow">
        <select id="utilType" style="padding:7px 10px;border:1.5px solid var(--blue-100);border-radius:var(--radius-md);font-family:inherit;font-size:.84rem;">
          <option value="">— Utility Type —</option>
          <option value="Electricity">Electricity</option>
          <option value="Water">Water</option>
          <option value="Internet">Internet</option>
          <option value="Cable TV">Cable TV</option>
          <option value="Parking">Parking</option>
          <option value="Other">Other Utility Charges</option>
        </select>
        <input type="month" id="utilPeriod" style="padding:7px 10px;border:1.5px solid var(--blue-100);border-radius:var(--radius-md);font-family:inherit;font-size:.84rem;" placeholder="Billing Period">
        <input type="number" id="utilAmount" min="0" step="0.01" placeholder="Amount (₱)" style="width:120px;padding:7px 10px;border:1.5px solid var(--blue-100);border-radius:var(--radius-md);font-family:inherit;font-size:.84rem;">
        <select id="utilStatus" style="padding:7px 10px;border:1.5px solid var(--blue-100);border-radius:var(--radius-md);font-family:inherit;font-size:.84rem;">
          <option value="Unpaid">Unpaid</option>
          <option value="Paid">Paid</option>
          <option value="Partial">Partial</option>
        </select>
        <button class="fhbtn fhbtn--primary" id="utilAddBtn" style="padding:7px 14px;">+ Add Utility</button>
        <p id="utilErr" style="color:#b91c1c;font-size:.8rem;margin:0;display:none;"></p>
      </div>
    `;
}

// ── Monthly charges schedule ──────────────────────────────────────
// Produces ONE clean row per FULL calendar month at the monthly rate.
// Partial first/last months get their own row labelled "(partial)".
function buildMonthlySchedule(r) {
    const checkIn   = new Date(r.check_in  + 'T00:00:00');
    const checkOut  = new Date(r.check_out + 'T00:00:00');
    const monthRate = parseFloat(r.room_rate) || 0;
    if (!monthRate || checkOut <= checkIn) return [];

    // Use RENTAL month intervals anchored to the check-in date, not calendar months.
    // e.g. check-in June 26, check-out Aug 26 = 2 months:
    //   Month 1: Jun 26 – Jul 26  → ₱10,000
    //   Month 2: Jul 26 – Aug 26  → ₱10,000
    // This avoids the 3-row problem caused by splitting on calendar month boundaries.

    const rows = [];
    let monthNum = 0;

    while (true) {
        // Start of this rental month
        const periodStart = new Date(checkIn);
        periodStart.setMonth(periodStart.getMonth() + monthNum);

        if (periodStart >= checkOut) break;

        // End of this rental month (same day next month)
        const periodEnd = new Date(checkIn);
        periodEnd.setMonth(periodEnd.getMonth() + monthNum + 1);

        // Clamp to check-out
        const effectiveEnd = periodEnd <= checkOut ? periodEnd : checkOut;

        // Is this a full rental month?
        const isFullMonth = (effectiveEnd.getTime() === periodEnd.getTime());

        let amount, desc;
        if (isFullMonth) {
            amount = monthRate;
            desc   = 'Monthly Rent – RM ' + r.room_number;
        } else {
            // Partial last month — prorate by days
            const totalDays  = (periodEnd   - periodStart)   / 86400000;
            const actualDays = (effectiveEnd - periodStart)   / 86400000;
            amount = monthRate * (actualDays / totalDays);
            desc   = 'Monthly Rent – RM ' + r.room_number +
                     ' (' + Math.round(actualDays) + ' / ' + Math.round(totalDays) + ' days)';
        }

        const period = periodStart.toLocaleDateString('en-US', {month:'long', year:'numeric'});
        rows.push({ period, description: desc, rate: monthRate, amount });

        monthNum++;
        // Safety cap — no reservation is longer than 120 months
        if (monthNum > 120) break;
    }

    return rows;
}

// ── Record Payment modal ─────────────────────────────────────────
document.getElementById('folioPayBtn').addEventListener('click', openPayModal);

function openPayModal() {
    if (!_folio) return;
    // Always compute from real payment records, not amount_paid summary field
    const totalPaid = _payments.reduce((s, p) => s + parseFloat(p.amount || 0), 0);
    const totalDue  = parseFloat(_folio.total_amount) || 0;
    const balance   = totalDue - totalPaid;

    const balEl = document.getElementById('payBalanceDisplay');
    balEl.textContent = fmt(balance);
    document.getElementById('payRemaining').classList.toggle('overpaid', balance <= 0);

    // Pre-fill sensible defaults
    document.getElementById('payAmount').value   = balance > 0 ? balance.toFixed(2) : '';
    document.getElementById('payDate').value     = new Date().toISOString().split('T')[0];
    document.getElementById('payMethod').value   = '';
    document.getElementById('payRemarks').value  = '';
    document.getElementById('payError').style.display = 'none';

    document.getElementById('payModal').removeAttribute('hidden');
    document.getElementById('payAmount').focus();
}

document.getElementById('payClose').addEventListener('click', closePayModal);
document.getElementById('payCancelBtn').addEventListener('click', closePayModal);
function closePayModal() {
    document.getElementById('payModal').setAttribute('hidden', '');
}
document.getElementById('payModal').addEventListener('click', e => {
    if (e.target === document.getElementById('payModal')) closePayModal();
});

document.getElementById('paySubmitBtn').addEventListener('click', function () {
    const errEl  = document.getElementById('payError');
    errEl.style.display = 'none';

    const amount = parseFloat(document.getElementById('payAmount').value);
    const date   = document.getElementById('payDate').value;
    const method = document.getElementById('payMethod').value;
    const remarks= document.getElementById('payRemarks').value.trim();

    if (!amount || amount <= 0) { showPayErr('Enter a valid amount.'); return; }
    if (!date)   { showPayErr('Select a payment date.'); return; }
    if (!method) { showPayErr('Select a payment method.'); return; }

    const btn = document.getElementById('paySubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('action',         'record_payment');
    fd.append('reservation_id', _folio.id);
    fd.append('amount',         amount);
    fd.append('payment_date',   date);
    fd.append('payment_method', method);
    fd.append('remarks',        remarks);

    fetch('/process_reservation.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled    = false;
            btn.textContent = 'Save Payment';
            if (!data.success) { showPayErr(data.message || 'Could not save payment.'); return; }
            closePayModal();
            // Reload folio with fresh data
            _folio = Object.assign({}, _folio, {
                amount_paid: data.new_amount_paid,
                total_amount: _folio.total_amount,
            });
            openFolio(_folio);
            // Update the row in the table
            refreshTableRow(_folio.id, data.new_amount_paid);
        })
        .catch(err => {
            btn.disabled    = false;
            btn.textContent = 'Save Payment';
            showPayErr('Network error: ' + err.message);
        });
});

function showPayErr(msg) {
    const el = document.getElementById('payError');
    el.textContent  = msg;
    el.style.display = 'block';
}

// ── Delete a payment (admin only) ────────────────────────────────
function deletePayment(paymentId) {
    if (!confirm('Delete this payment record? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action',     'delete_payment');
    fd.append('payment_id', paymentId);
    fd.append('reservation_id', _folio.id);
    fetch('/process_reservation.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Could not delete payment.'); return; }
            _folio = Object.assign({}, _folio, { amount_paid: data.new_amount_paid });
            openFolio(_folio);
            refreshTableRow(_folio.id, data.new_amount_paid);
        })
        .catch(err => alert('Network error: ' + err.message));
}

// Refresh the amount_paid and balance cells in the guests table row
function refreshTableRow(resvId, newPaid) {
    const row = document.querySelector('.guest-row[data-reservation]');
    document.querySelectorAll('.guest-row').forEach(row => {
        try {
            const r = JSON.parse(row.dataset.reservation);
            if (String(r.id) !== String(resvId)) return;
            const cells = row.querySelectorAll('td');
            const totalDue = parseFloat(r.total_amount) || 0;
            const paid     = parseFloat(newPaid) || 0;
            const bal      = totalDue - paid;
            // col 8 = Amount Due, col 9 = Balance (0-indexed, after removing Paid column)
            if (cells[9]) {
                cells[9].textContent = '₱' + Math.abs(bal).toLocaleString('en-PH', {minimumFractionDigits:2});
                cells[9].style.color = bal > 0 ? '#b91c1c' : '#1a7a46';
            }
            // Update stored data
            row.dataset.reservation = JSON.stringify(Object.assign({}, r, { amount_paid: newPaid }));
        } catch(e) {}
    });
}

// ── Utility Charges ─────────────────────────────────────────────────
function loadUtilities(resvId) {
    const tbody = document.getElementById('utilHistoryBody');
    const totalEl = document.getElementById('utilTotal');
    if (!tbody) return;

    fetch('/process_reservation.php?action=get_utilities&reservation_id=' + resvId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="' + (CAN_DELETE ? 5 : 4) + '" class="no-data">Could not load utilities.</td></tr>';
                return;
            }
            const items = data.utilities || [];
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + (CAN_DELETE ? 5 : 4) + '" class="no-data">No utility charges recorded yet.</td></tr>';
                if (totalEl) totalEl.textContent = '₱0.00';
                return;
            }
            let total = 0;
            tbody.innerHTML = items.map(u => {
                const amt = parseFloat(u.amount || 0);
                total += amt;
                const statusCls = u.status === 'Paid' ? 'us--paid' : u.status === 'Partial' ? 'us--partial' : 'us--unpaid';
                const delCell = CAN_DELETE
                    ? '<td><button class="pay-del" onclick="deleteUtility(' + u.id + ',' + resvId + ')" title="Delete">✕</button></td>'
                    : '';
                return '<tr>' +
                    '<td>' + esc(u.utility_type) + '</td>' +
                    '<td>' + esc(u.billing_period || '—') + '</td>' +
                    '<td class="r">' + fmt(amt) + '</td>' +
                    '<td><span class="util-status ' + statusCls + '">' + esc(u.status) + '</span></td>' +
                    delCell +
                '</tr>';
            }).join('');
            if (totalEl) totalEl.textContent = fmt(total);
        })
        .catch(() => {
            if (tbody) tbody.innerHTML = '<tr><td colspan="' + (CAN_DELETE ? 5 : 4) + '" class="no-data">Could not load utilities.</td></tr>';
        });

    // Wire add button
    const addBtn = document.getElementById('utilAddBtn');
    if (addBtn && !addBtn._wired) {
        addBtn._wired = true;
        addBtn.addEventListener('click', function() {
            const errEl = document.getElementById('utilErr');
            errEl.style.display = 'none';
            const typeVal   = document.getElementById('utilType').value;
            const periodVal = document.getElementById('utilPeriod').value;
            const amtVal    = parseFloat(document.getElementById('utilAmount').value);
            const statusVal = document.getElementById('utilStatus').value;
            if (!typeVal)          { errEl.textContent = 'Select a utility type.'; errEl.style.display = 'block'; return; }
            if (!periodVal)        { errEl.textContent = 'Enter a billing period.'; errEl.style.display = 'block'; return; }
            if (!amtVal || amtVal <= 0) { errEl.textContent = 'Enter a valid amount.'; errEl.style.display = 'block'; return; }

            addBtn.disabled = true;
            addBtn.textContent = 'Saving…';
            const fd = new FormData();
            fd.append('action',         'add_utility');
            fd.append('reservation_id', resvId);
            fd.append('utility_type',   typeVal);
            fd.append('billing_period', periodVal);
            fd.append('amount',         amtVal);
            fd.append('status',         statusVal);
            fetch('/process_reservation.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    addBtn.disabled    = false;
                    addBtn.textContent = '+ Add Utility';
                    if (!data.success) { errEl.textContent = data.message || 'Error.'; errEl.style.display = 'block'; return; }
                    document.getElementById('utilType').value   = '';
                    document.getElementById('utilPeriod').value = '';
                    document.getElementById('utilAmount').value = '';
                    document.getElementById('utilStatus').value = 'Unpaid';
                    loadUtilities(resvId);
                })
                .catch(err => {
                    addBtn.disabled    = false;
                    addBtn.textContent = '+ Add Utility';
                    errEl.textContent  = 'Network error.';
                    errEl.style.display = 'block';
                });
        });
    }
}

function deleteUtility(utilId, resvId) {
    if (!confirm('Delete this utility charge? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('action',         'delete_utility');
    fd.append('utility_id',     utilId);
    fd.append('reservation_id', resvId);
    fetch('/process_reservation.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(data.message || 'Could not delete utility.'); return; }
            loadUtilities(resvId);
        })
        .catch(err => alert('Network error: ' + err.message));
}

// ── Print ────────────────────────────────────────────────────────
document.getElementById('folioPrintBtn').addEventListener('click', printFolio);

function printFolio() {
    if (!_folio) return;
    const r       = _folio;
    const charges = buildMonthlySchedule(r);
    const totalPaid = _payments.reduce((s, p) => s + parseFloat(p.amount||0), 0);
    const balance   = (parseFloat(r.total_amount)||0) - totalPaid;
    const brLabel   = ALL_BRANCHES[r.branch] ?? r.branch;
    const stLabel   = STATUS_LABELS[r.status] ?? r.status;
    const dur       = formatDuration(r.check_in, r.check_out);

    const printSecDeposit  = parseFloat(r.security_deposit)    || 0;
    const printResvFee     = parseFloat(r.reservation_fee)     || 0;
    const printGarbageFee  = parseFloat(r.garbage_fee)         || 0;
    const printUtilsDep    = parseFloat(r.utilities_deposit)   || 0;
    const printRentTotal   = charges.reduce((s,c) => s + parseFloat(c.amount), 0);
    const printGrandTotal  = printRentTotal + printSecDeposit + printResvFee + printGarbageFee + printUtilsDep;

    let chargeRowsHtml = charges.map(c => `
        <tr><td>${c.period}</td><td>${c.description}</td>
        <td style="text-align:right">₱${parseFloat(c.rate).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        <td style="text-align:right">₱${parseFloat(c.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</td></tr>`
    ).join('') || '<tr><td colspan="4" style="text-align:center;color:#888;">No rate set.</td></tr>';

    const oneTimeCharges = [
        {label:'Reservation Fee', amount:printResvFee},
        {label:'Garbage Fee',     amount:printGarbageFee},
        {label:'Security Deposit',amount:printSecDeposit},
        {label:'Utilities Deposit',amount:printUtilsDep},
    ];
    oneTimeCharges.forEach(function(c){
        if (c.amount > 0) {
            chargeRowsHtml += '<tr style="background:#f8fbff;"><td>—</td><td><em>'+c.label+'</em></td><td style="text-align:right">—</td>' +
            '<td style="text-align:right">₱'+c.amount.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td></tr>';
        }
    });

    const payRowsHtml = _payments.map(p => {
        const pmLbl = PAYMENT_LABELS[p.payment_method] ?? (p.payment_method||'—');
        return `<tr><td>${fmtDate(p.payment_date||p.created_at)}</td>
            <td>${pmLbl}</td><td>${p.remarks||'—'}</td>
            <td style="text-align:right;color:#15803d;font-weight:700;">₱${parseFloat(p.amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td></tr>`;
    }).join('') || '<tr><td colspan="4" style="text-align:center;color:#888;">No payments recorded.</td></tr>';

    // Collect utility rows from DOM for print
    let utilRowsHtml = '';
    let utilTotal = 0;
    const utilBody = document.getElementById('utilHistoryBody');
    if (utilBody) {
        const utilRows = utilBody.querySelectorAll('tr');
        utilRows.forEach(function(row) {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 4 && !cells[0].classList.contains('no-data')) {
                const amt = parseFloat(cells[2].textContent.replace(/[^\d.]/g, '')) || 0;
                utilTotal += amt;
                utilRowsHtml += '<tr><td>' + cells[0].textContent + '</td><td>' + cells[1].textContent + '</td>' +
                    '<td style="text-align:right">₱' + amt.toLocaleString('en-PH',{minimumFractionDigits:2}) + '</td>' +
                    '<td>' + (cells[3] ? cells[3].textContent : '') + '</td></tr>';
            }
        });
    }
    if (!utilRowsHtml) utilRowsHtml = '<tr><td colspan="4" style="text-align:center;color:#888;">No utility charges.</td></tr>';

    const w = window.open('', '_blank', 'width=860,height=720');
    if (!w) { alert('Allow pop-ups to print.'); return; }
    w.document.write(`<!DOCTYPE html><html><head><title>Folio — ${r.guest_full_name}</title>
<style>
*{box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:13px;color:#1a2332;padding:40px 48px;max-width:820px;margin:0 auto;}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #16324f;padding-bottom:14px;margin-bottom:22px;}
.brand{font-size:1.5rem;font-weight:800;color:#16324f;}
.fno{text-align:right;font-size:.8rem;color:#5b7693;}
.fno strong{display:block;font-size:1.1rem;color:#16324f;margin-top:2px;}
.sumgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;}
.scard{background:#f0f5fb;border-radius:5px;padding:10px 14px;}
.scard .l{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5b7693;}
.scard .v{font-size:.95rem;font-weight:700;color:#16324f;margin-top:3px;}
.scard.blue{background:#dceaf8;}.scard.green .v{color:#15803d;}.scard.red .v{color:#b91c1c;}
h2{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#5b7693;border-bottom:1px solid #dceaf8;padding-bottom:6px;margin:20px 0 10px;}
.igrid{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;margin-bottom:12px;font-size:.84rem;}
.irow .l{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#5b7693;}
.irow .v{font-weight:600;color:#16324f;}
table{width:100%;border-collapse:collapse;margin-bottom:16px;font-size:.82rem;}
th{background:#f0f5fb;padding:7px 10px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#5b7693;border-bottom:2px solid #dceaf8;}
td{padding:7px 10px;border-bottom:1px solid #f0f5fb;}
tfoot td{font-weight:700;background:#f0f5fb;border-top:2px solid #dceaf8;}
.footer{margin-top:28px;padding-top:12px;border-top:1px solid #dceaf8;text-align:center;font-size:.72rem;color:#5b7693;}
@media print{body{padding:24px;}}
</style></head><body>
<div class="hdr">
  <div><div class="brand">Bluebookers</div><div style="font-size:.8rem;color:#5b7693;margin-top:3px;">${brLabel}</div></div>
  <div class="fno">Guest Folio<strong>#${String(r.id).padStart(10,'0')}</strong><div>${new Date().toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'})}</div></div>
</div>
<div class="sumgrid">
  <div class="scard blue"><div class="l">Total Due</div><div class="v">₱${parseFloat(r.total_amount||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="scard green"><div class="l">Total Paid</div><div class="v">₱${totalPaid.toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="scard ${balance>0?'red':'green'}"><div class="l">Balance</div><div class="v">₱${balance.toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="scard"><div class="l">Guest</div><div class="v">${r.guest_full_name||'—'}</div></div>
  <div class="scard"><div class="l">Room</div><div class="v">RM ${r.room_number} – ${r.room_type}</div></div>
  <div class="scard"><div class="l">Status</div><div class="v">${stLabel}</div></div>
  <div class="scard"><div class="l">Arrival</div><div class="v">${r.check_in}</div></div>
  <div class="scard"><div class="l">Departure</div><div class="v">${r.check_out}</div></div>
  <div class="scard"><div class="l">Duration</div><div class="v">${dur}</div></div>
  ${printSecDeposit > 0 ? `<div class="scard"><div class="l">Security Deposit</div><div class="v">₱${printSecDeposit.toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>` : ''}
</div>
<h2>Guest Information</h2>
<div class="igrid">
  <div class="irow"><div class="l">Contact</div><div class="v">${r.contact_number||'—'}</div></div>
  <div class="irow"><div class="l">Email</div><div class="v">${r.email||'—'}</div></div>
  <div class="irow"><div class="l">Address</div><div class="v">${r.address||'—'}</div></div>
  <div class="irow"><div class="l">Valid ID</div><div class="v">${r.valid_id_type||'—'}${r.valid_id_number?' #'+r.valid_id_number:''}</div></div>
  <div class="irow"><div class="l">Adults / Children</div><div class="v">${r.num_adults||1} / ${r.num_children||0}</div></div>
  <div class="irow"><div class="l">Monthly Rent</div><div class="v">₱${parseFloat(r.room_rate||0).toLocaleString('en-PH',{minimumFractionDigits:2})}/mo</div></div>
  <div class="irow"><div class="l">Reservation Fee</div><div class="v">₱${parseFloat(r.reservation_fee||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="irow"><div class="l">Garbage Fee</div><div class="v">₱${parseFloat(r.garbage_fee||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="irow"><div class="l">Security Deposit</div><div class="v">₱${parseFloat(r.security_deposit||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  <div class="irow"><div class="l">Utilities Deposit</div><div class="v">₱${parseFloat(r.utilities_deposit||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>
  ${r.notes?`<div class="irow" style="grid-column:1/-1"><div class="l">Notes</div><div class="v">${r.notes}</div></div>`:''}
</div>
<h2>Monthly Charges Schedule</h2>
<table>
  <thead><tr><th>Period</th><th>Description</th><th style="text-align:right">Rate/Month</th><th style="text-align:right">Amount Due</th></tr></thead>
  <tbody>${chargeRowsHtml}</tbody>
  <tfoot><tr><td colspan="3">Total Charges</td><td style="text-align:right">₱${printGrandTotal.toLocaleString('en-PH',{minimumFractionDigits:2})}</td></tr></tfoot>
</table>
<h2>Utilities Payments</h2>
<table>
  <thead><tr><th>Utility Type</th><th>Billing Period</th><th style="text-align:right">Amount</th><th>Status</th></tr></thead>
  <tbody>${utilRowsHtml}</tbody>
  <tfoot><tr><td colspan="2">Total Utilities</td><td style="text-align:right">₱${utilTotal.toLocaleString('en-PH',{minimumFractionDigits:2})}</td><td></td></tr></tfoot>
</table>
<h2>Payment History</h2>
<table>
  <thead><tr><th>Date</th><th>Method</th><th>Remarks</th><th style="text-align:right">Amount Paid</th></tr></thead>
  <tbody>${payRowsHtml}</tbody>
  <tfoot><tr><td colspan="3">Total Paid</td><td style="text-align:right;color:#15803d;font-weight:700;">₱${totalPaid.toLocaleString('en-PH',{minimumFractionDigits:2})}</td></tr></tfoot>
</table>
<div class="footer">Generated by Bluebookers PMS &nbsp;·&nbsp; ${new Date().toLocaleString('en-PH')}</div>
<script>setTimeout(()=>window.print(),400);<\/script>
</body></html>`);
    w.document.close();
}

// ── Modal close ──────────────────────────────────────────────────
document.getElementById('folioClose').addEventListener('click', () =>
    document.getElementById('folioModal').setAttribute('hidden', ''));
document.getElementById('folioModal').addEventListener('click', e => {
    if (e.target === document.getElementById('folioModal'))
        document.getElementById('folioModal').setAttribute('hidden', '');
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (!document.getElementById('payModal').hasAttribute('hidden')) { closePayModal(); return; }
        document.getElementById('folioModal').setAttribute('hidden', '');
    }
});
</script>
<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>