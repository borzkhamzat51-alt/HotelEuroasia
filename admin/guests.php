<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('guests');

// ── Filters ──────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$status   = $_GET['status']        ?? '';
$branch   = $_GET['branch']        ?? '';
$sort     = $_GET['sort']          ?? 'check_in_desc';

// ── Fetch guests (from reservations + rooms) ──────────────────────────────────
$pdo = bb_db();

$validStatuses = ['reserved','checked_in','checked_out','cancelled'];
$validSorts    = [
    'check_in_desc'  => 'r.check_in DESC',
    'check_in_asc'   => 'r.check_in ASC',
    'name_asc'       => 'r.guest_full_name ASC',
    'name_desc'      => 'r.guest_full_name DESC',
    'created_desc'   => 'r.created_at DESC',
];
$orderBy = $validSorts[$sort] ?? 'r.check_in DESC';

$where   = ['1=1'];
$params  = [];

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

// ── Stats bar ─────────────────────────────────────────────────────────────────
$statsStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM reservations GROUP BY status");
$rawStats  = $statsStmt->fetchAll();
$stats     = ['reserved'=>0,'checked_in'=>0,'checked_out'=>0,'cancelled'=>0,'total'=>0];
foreach ($rawStats as $s) {
    if (isset($stats[$s['status']])) $stats[$s['status']] = (int)$s['cnt'];
    $stats['total'] += (int)$s['cnt'];
}

$allBranches = [
    'annex'    => 'BB Apartelle',
    'mtv'      => 'MTV3',
    'dormitel' => 'ELTI Dormitel',
];

$statusLabels  = ['reserved'=>'Reserved','checked_in'=>'Checked In','checked_out'=>'Checked Out','cancelled'=>'Cancelled'];
$statusColors  = ['reserved'=>'#3b7dd8','checked_in'=>'#2ecc71','checked_out'=>'#8a9aa8','cancelled'=>'#e74c3c'];
$paymentLabels = ['cash'=>'Cash','gcash'=>'GCash','bank_transfer'=>'Bank Transfer','card'=>'Card'];

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guests · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/property.css">
<link rel="stylesheet" href="../assets/css/account.css">
<style>
/* ── Guests page ─────────────────────────────────────────────────── */
.guests-main { padding: 28px clamp(16px,4vw,48px) 60px; }

/* Stats bar */
.stats-bar {
  display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 28px;
}
.stat-chip {
  background: var(--white); border: 1px solid var(--sky-200);
  border-radius: 999px; padding: 8px 18px;
  font-size: 0.82rem; font-weight: 600; color: var(--ink-700);
  display: flex; align-items: center; gap: 7px;
}
.stat-chip__dot { width:8px; height:8px; border-radius:50%; }

/* Filter bar */
.filter-bar {
  display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; align-items: center;
}
.filter-bar input, .filter-bar select {
  padding: 9px 14px; border-radius: var(--radius-md);
  border: 1px solid var(--sky-200); font-size: 0.88rem;
  font-family: inherit; background: var(--white); color: var(--ink-900);
  outline: none;
}
.filter-bar input { flex: 1; min-width: 200px; }
.filter-bar input:focus, .filter-bar select:focus {
  border-color: var(--blue-500); box-shadow: 0 0 0 3px rgba(59,125,216,.12);
}
.filter-results { font-size: 0.82rem; color: var(--ink-500); margin-left:auto; white-space:nowrap; }

/* Table */
.guests-table-wrap { overflow-x: auto; border-radius: var(--radius-xl); background: var(--white); border: 1px solid var(--sky-200); }
.guests-table {
  width: 100%; border-collapse: collapse; font-size: 0.86rem;
}
.guests-table th {
  background: var(--sky-50); padding: 12px 16px; text-align: left;
  font-size: 0.75rem; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; color: var(--ink-500);
  border-bottom: 1px solid var(--sky-200); white-space: nowrap;
}
.guests-table td {
  padding: 14px 16px; border-bottom: 1px solid var(--sky-100);
  vertical-align: middle; color: var(--ink-900);
}
.guests-table tr:last-child td { border-bottom: none; }
.guests-table tr:hover td { background: var(--sky-50); cursor: pointer; }
.guest-name { font-weight: 600; color: var(--ink-900); }
.guest-meta { font-size: 0.78rem; color: var(--ink-500); margin-top: 2px; }

.status-badge {
  display: inline-block; padding: 3px 10px; border-radius: 999px;
  font-size: 0.74rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap;
}
.status-badge--reserved    { background:#dceaf8; color:#2861b3; }
.status-badge--checked_in  { background:#d4f7e7; color:#1a7a46; }
.status-badge--checked_out { background:#eef0f2; color:#5b7693; }
.status-badge--cancelled   { background:#fde8e8; color:#b91c1c; }

.empty-state {
  text-align:center; padding: 60px 20px; color: var(--ink-500);
}
.empty-state__icon { font-size: 2.4rem; margin-bottom: 12px; }
.empty-state h3 { color: var(--ink-900); margin: 0 0 6px; }

/* ── Folio modal ─────────────────────────────────────────────────── */
.modal-overlay {
  position:fixed; inset:0; background:rgba(22,50,79,.45);
  display:flex; align-items:center; justify-content:center;
  z-index:1000; padding:20px;
}
.modal-overlay[hidden]{ display:none; }
.folio-modal {
  background: var(--white); border-radius: var(--radius-xl);
  width: 100%; max-width: 860px; max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 32px 80px -20px rgba(22,50,79,.35);
  overflow: hidden;
}
.folio-header {
  background: var(--ink-900); color: var(--white);
  padding: 18px 24px; display: flex; justify-content: space-between; align-items: flex-start;
}
.folio-header h2 { margin:0; font-family:'Playfair Display',serif; font-size:1.1rem; }
.folio-header__sub { font-size:0.8rem; opacity:.65; margin-top:3px; }
.folio-close {
  background: none; border: none; color: var(--white); cursor: pointer;
  font-size: 1.4rem; line-height:1; padding:0; opacity:.7;
}
.folio-close:hover { opacity:1; }

.folio-body { overflow-y: auto; padding: 24px; flex:1; }

/* Summary cards */
.folio-summary {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr));
  gap: 14px; margin-bottom: 24px;
}
.folio-card {
  background: var(--sky-50); border: 1px solid var(--sky-200);
  border-radius: var(--radius-md); padding: 14px 16px;
}
.folio-card__label { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-500); }
.folio-card__value { font-size:1.05rem; font-weight:700; color:var(--ink-900); margin-top:4px; }
.folio-card--highlight { border-color: var(--blue-500); background: #ebf3fc; }
.folio-card--highlight .folio-card__value { color: var(--blue-700); }
.folio-card--danger .folio-card__value { color: #b91c1c; }

/* Guest info grid */
.folio-info-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px;
  margin-bottom: 24px; font-size: 0.86rem;
}
.folio-info-row { display:flex; flex-direction:column; gap:2px; }
.folio-info-row span:first-child { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-500); }
.folio-info-row span:last-child { color:var(--ink-900); font-weight:600; }

/* Charges table */
.folio-charges-title {
  font-size:0.78rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.05em; color:var(--ink-500); margin-bottom:10px;
}
.folio-charges-table { width:100%; border-collapse:collapse; font-size:0.84rem; }
.folio-charges-table th {
  background:var(--sky-50); padding:9px 12px; text-align:left;
  font-size:0.72rem; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; color:var(--ink-500);
  border-bottom: 1px solid var(--sky-200);
}
.folio-charges-table td {
  padding: 10px 12px; border-bottom: 1px solid var(--sky-100);
  color: var(--ink-900);
}
.folio-charges-table tr:last-child td { border-bottom: none; }
.folio-charges-table .amount { text-align:right; font-weight:600; }
.folio-charges-table .amount-paid { text-align:right; color: #1a7a46; }
.folio-charges-table .tfoot-row td {
  font-weight:700; background:var(--sky-50); border-top:2px solid var(--sky-200);
}
.no-charges { text-align:center; padding:20px; color:var(--ink-500); font-size:0.85rem; }

@media(max-width:600px){
  .folio-info-grid{ grid-template-columns:1fr; }
  .folio-summary{ grid-template-columns:1fr 1fr; }
}
</style>
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <div class="ptopbar__breadcrumb">
        <span aria-current="page">Guests</span>
    </div>
    <a href="../logout.php" class="ptopbar__logout">Log out</a>
</header>

<main class="guests-main">

    <div style="margin-bottom:20px;">
        <h1 style="font-family:'Playfair Display',serif; font-size:1.7rem; margin:0 0 4px; color:var(--ink-900);">Guests &amp; Folios</h1>
        <p style="color:var(--ink-500); margin:0; font-size:0.88rem;">All guest reservations across every property.</p>
    </div>

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat-chip">
            <span class="stat-chip__dot" style="background:#8a9aa8"></span>
            All: <?= $stats['total'] ?>
        </div>
        <div class="stat-chip">
            <span class="stat-chip__dot" style="background:#3b7dd8"></span>
            Reserved: <?= $stats['reserved'] ?>
        </div>
        <div class="stat-chip">
            <span class="stat-chip__dot" style="background:#2ecc71"></span>
            Checked In: <?= $stats['checked_in'] ?>
        </div>
        <div class="stat-chip">
            <span class="stat-chip__dot" style="background:#8a9aa8"></span>
            Checked Out: <?= $stats['checked_out'] ?>
        </div>
        <div class="stat-chip">
            <span class="stat-chip__dot" style="background:#e74c3c"></span>
            Cancelled: <?= $stats['cancelled'] ?>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="get" class="filter-bar">
        <input type="text" name="search" placeholder="Search name, phone, email, ID…" value="<?= htmlspecialchars($search) ?>">
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="branch">
            <option value="">All Properties</option>
            <?php foreach ($allBranches as $k => $v): ?>
                <option value="<?= $k ?>" <?= $branch === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <option value="check_in_desc"  <?= $sort==='check_in_desc'?'selected':'' ?>>Check-in ↓</option>
            <option value="check_in_asc"   <?= $sort==='check_in_asc'?'selected':'' ?>>Check-in ↑</option>
            <option value="name_asc"        <?= $sort==='name_asc'?'selected':'' ?>>Name A–Z</option>
            <option value="name_desc"       <?= $sort==='name_desc'?'selected':'' ?>>Name Z–A</option>
            <option value="created_desc"    <?= $sort==='created_desc'?'selected':'' ?>>Newest First</option>
        </select>
        <button type="submit" class="btn btn--primary" style="width:auto;padding:9px 20px;">Filter</button>
        <?php if ($search || $status || $branch): ?>
            <a href="guests.php" class="btn btn--ghost" style="width:auto;padding:9px 20px;">Clear</a>
        <?php endif; ?>
        <span class="filter-results"><?= count($guests) ?> record<?= count($guests) !== 1 ? 's' : '' ?></span>
    </form>

    <!-- Table -->
    <?php if (empty($guests)): ?>
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
                    <th>#</th>
                    <th>Guest</th>
                    <th>Property</th>
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Nights</th>
                    <th>Status</th>
                    <th>Amount Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($guests as $g):
                $branchLabel = $allBranches[$g['branch']] ?? ucfirst($g['branch']);
                $nights = (new DateTime($g['check_in']))->diff(new DateTime($g['check_out']))->days;
                $balance = (float)$g['total_amount'] - (float)$g['amount_paid'];
            ?>
                <tr class="guest-row" data-id="<?= $g['id'] ?>" data-reservation='<?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>'>
                    <td style="color:var(--ink-500); font-size:0.78rem;">#<?= $g['id'] ?></td>
                    <td>
                        <div class="guest-name"><?= htmlspecialchars($g['guest_full_name']) ?></div>
                        <div class="guest-meta">
                            <?php if ($g['contact_number']): ?><?= htmlspecialchars($g['contact_number']) ?><?php endif; ?>
                            <?php if ($g['email']): ?> · <?= htmlspecialchars($g['email']) ?><?php endif; ?>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($branchLabel) ?></td>
                    <td><strong>RM <?= htmlspecialchars($g['room_number']) ?></strong><div class="guest-meta"><?= htmlspecialchars($g['room_type']) ?></div></td>
                    <td><?= htmlspecialchars($g['check_in']) ?></td>
                    <td><?= htmlspecialchars($g['check_out']) ?></td>
                    <td><?= $nights ?></td>
                    <td><span class="status-badge status-badge--<?= $g['status'] ?>"><?= $statusLabels[$g['status']] ?></span></td>
                    <td>₱<?= number_format((float)$g['total_amount'], 2) ?></td>
                    <td style="color:#1a7a46; font-weight:600;">₱<?= number_format((float)$g['amount_paid'], 2) ?></td>
                    <td style="color:<?= $balance > 0 ? '#b91c1c' : '#1a7a46' ?>; font-weight:700;">₱<?= number_format($balance, 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>

<!-- ── Folio Modal ─────────────────────────────────────────────────────────── -->
<div id="folioModal" class="modal-overlay" hidden>
  <div class="folio-modal">
    <div class="folio-header">
        <div>
            <h2 id="folioTitle">Master Folio</h2>
            <div class="folio-header__sub" id="folioSubtitle"></div>
        </div>
        <button class="folio-close" id="folioClose">&times;</button>
    </div>
    <div class="folio-body" id="folioBody">
        <!-- Populated by JS -->
    </div>
  </div>
</div>

<script>
const statusLabels  = <?= json_encode($statusLabels) ?>;
const paymentLabels = <?= json_encode($paymentLabels) ?>;
const allBranches   = <?= json_encode($allBranches) ?>;

function fmt(n){ return '₱' + parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ const d=document.createElement('div'); d.textContent=s??'—'; return d.innerHTML; }

document.querySelectorAll('.guest-row').forEach(row => {
    row.addEventListener('click', () => {
        const r = JSON.parse(row.dataset.reservation);
        openFolio(r);
    });
});

function openFolio(r) {
    const nights  = Math.round((new Date(r.check_out) - new Date(r.check_in)) / 86400000);
    const balance = (parseFloat(r.total_amount)||0) - (parseFloat(r.amount_paid)||0);
    const brLabel = allBranches[r.branch] ?? r.branch;
    const stLabel = statusLabels[r.status] ?? r.status;
    const pmLabel = paymentLabels[r.payment_method] ?? (r.payment_method || '—');

    document.getElementById('folioTitle').textContent    = 'Master Folio [#' + String(r.id).padStart(10,'0') + ']';
    document.getElementById('folioSubtitle').textContent = (r.guest_full_name ?? '') + ' · ' + brLabel;

    document.getElementById('folioBody').innerHTML = `
      <div class="folio-summary">
        <div class="folio-card">
          <div class="folio-card__label">Folio No.</div>
          <div class="folio-card__value">#${String(r.id).padStart(10,'0')}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Booking Type</div>
          <div class="folio-card__value">${esc(stLabel)}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Room</div>
          <div class="folio-card__value">RM ${esc(r.room_number)} – ${esc(r.room_type)}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">No. Nights</div>
          <div class="folio-card__value">${nights} Night${nights!==1?'s':''}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Arrival</div>
          <div class="folio-card__value">${esc(r.check_in)}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Departure</div>
          <div class="folio-card__value">${esc(r.check_out)}</div>
        </div>
        <div class="folio-card folio-card--highlight">
          <div class="folio-card__label">Amount Due</div>
          <div class="folio-card__value">${fmt(r.total_amount)}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Amount Paid</div>
          <div class="folio-card__value">${fmt(r.amount_paid)}</div>
        </div>
        <div class="folio-card ${balance>0?'folio-card--danger':''}">
          <div class="folio-card__label">Total Balance</div>
          <div class="folio-card__value">${fmt(balance)}</div>
        </div>
        <div class="folio-card">
          <div class="folio-card__label">Payment Method</div>
          <div class="folio-card__value">${esc(pmLabel)}</div>
        </div>
      </div>

      <div class="folio-info-grid">
        <div class="folio-info-row"><span>Guest</span><span>${esc(r.guest_full_name)}</span></div>
        <div class="folio-info-row"><span>Contact #</span><span>${esc(r.contact_number||'—')}</span></div>
        <div class="folio-info-row"><span>Email</span><span>${esc(r.email||'—')}</span></div>
        <div class="folio-info-row"><span>Address</span><span>${esc(r.address||'—')}</span></div>
        <div class="folio-info-row"><span>Valid ID Type</span><span>${esc(r.valid_id_type||'—')}</span></div>
        <div class="folio-info-row"><span>Valid ID No.</span><span>${esc(r.valid_id_number||'—')}</span></div>
        <div class="folio-info-row"><span>Adults</span><span>${esc(r.num_adults)}</span></div>
        <div class="folio-info-row"><span>Children</span><span>${esc(r.num_children)}</span></div>
        <div class="folio-info-row"><span>Room Rate / Night</span><span>${fmt(r.room_rate)}</span></div>
        <div class="folio-info-row"><span>Security Deposit</span><span>${fmt(r.security_deposit)}</span></div>
        ${r.special_requests ? `<div class="folio-info-row" style="grid-column:1/-1"><span>Special Requests</span><span>${esc(r.special_requests)}</span></div>` : ''}
        ${r.notes ? `<div class="folio-info-row" style="grid-column:1/-1"><span>Notes</span><span>${esc(r.notes)}</span></div>` : ''}
      </div>

      <!-- Room charges per night breakdown -->
      <div class="folio-charges-title">Room Charges Breakdown</div>
      <div style="overflow-x:auto;">
      <table class="folio-charges-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Room No.</th>
            <th>Description</th>
            <th>Qty</th>
            <th style="text-align:right">Price</th>
            <th style="text-align:right">Amount Due</th>
            <th style="text-align:right">Amount Paid</th>
          </tr>
        </thead>
        <tbody id="chargesBody">
          <tr><td colspan="7" class="no-charges">Loading charges…</td></tr>
        </tbody>
        <tfoot>
          <tr class="tfoot-row">
            <td colspan="5">Total</td>
            <td class="amount">${fmt(r.total_amount)}</td>
            <td class="amount-paid">${fmt(r.amount_paid)}</td>
          </tr>
        </tfoot>
      </table>
      </div>
    `;

    document.getElementById('folioModal').removeAttribute('hidden');

    // Build per-night room charge rows
    buildNightlyCharges(r, nights);
}

function buildNightlyCharges(r, nights) {
    const tbody = document.getElementById('chargesBody');
    if (!tbody) return;
    if (nights <= 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="no-charges">No nightly charges to display.</td></tr>';
        return;
    }
    let rows = '';
    const checkIn = new Date(r.check_in);
    for (let i = 0; i < nights; i++) {
        const d = new Date(checkIn);
        d.setDate(d.getDate() + i);
        const dateStr = d.toISOString().split('T')[0];
        rows += `<tr>
            <td>${dateStr}</td>
            <td>${esc(r.room_number)}</td>
            <td>RM ${esc(r.room_number)} – ${esc(r.room_type)}</td>
            <td>1</td>
            <td class="amount">${fmt(r.room_rate)}</td>
            <td class="amount">${fmt(r.room_rate)}</td>
            <td class="amount-paid">0.00</td>
        </tr>`;
    }
    // Security deposit row
    if (parseFloat(r.security_deposit) > 0) {
        rows += `<tr>
            <td>${r.check_in}</td>
            <td>${esc(r.room_number)}</td>
            <td>Security Deposit</td>
            <td>1</td>
            <td class="amount">${fmt(r.security_deposit)}</td>
            <td class="amount">${fmt(r.security_deposit)}</td>
            <td class="amount-paid">0.00</td>
        </tr>`;
    }
    tbody.innerHTML = rows;
}

document.getElementById('folioClose').addEventListener('click', () => {
    document.getElementById('folioModal').setAttribute('hidden', '');
});
document.getElementById('folioModal').addEventListener('click', e => {
    if (e.target === document.getElementById('folioModal')) {
        document.getElementById('folioModal').setAttribute('hidden', '');
    }
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('folioModal').setAttribute('hidden', '');
});
</script>
</body>
</html>
