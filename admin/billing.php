<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('billing');

$branch      = $_GET['branch'] ?? '';
$branchNames = ['mtv' => 'MTV3', 'annex' => 'BB Apartelle', 'dormitel' => 'ELTI Dormitel'];
$branchLabel = $branchNames[$branch] ?? 'Property';
if (!$branch || !isset($branchNames[$branch])) {
    header('Location: dashboard.php');
    exit;
}
$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
$isAdmin     = bb_is_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branchLabel) ?> — Billing · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/property.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/account.css">
<style>
/* ── Layout ────────────────────────────────────────────────────────── */
.billing-main { flex:1; padding:clamp(20px,4vw,48px) clamp(16px,5vw,56px); max-width:1200px; margin:0 auto; width:100%; box-sizing:border-box; }
.billing-heading { margin-bottom:24px; }
.billing-heading__eyebrow { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--blue-500); margin:0 0 6px; }
.billing-heading__title { font-family:'Playfair Display',serif; font-size:clamp(1.5rem,3vw,2.2rem); font-weight:700; color:var(--blue-900,#16324f); margin:0; }

/* ── Tabs ──────────────────────────────────────────────────────────── */
.btabs { display:flex; gap:0; border-bottom:2px solid var(--blue-100,#dceaf8); margin-bottom:24px; overflow-x:auto; }
.btab { padding:12px 20px; font-size:.88rem; font-weight:600; color:var(--blue-500,#5b7693); background:none; border:none; cursor:pointer; white-space:nowrap; font-family:inherit; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all 150ms; }
.btab:hover { color:var(--blue-900,#16324f); background:var(--blue-50,#eef5fc); }
.btab.is-active { color:var(--blue-700,#2861b3); border-bottom-color:var(--blue-700,#2861b3); }
.bpanel { display:none; }
.bpanel.is-active { display:block; }

/* ── Section card ──────────────────────────────────────────────────── */
.bsec { background:#fff; border:1px solid var(--blue-100,#dceaf8); border-radius:16px; box-shadow:0 4px 16px -4px rgba(22,50,79,.08); margin-bottom:24px; overflow:hidden; }
.bsec__head { background:var(--blue-50,#eef5fc); border-bottom:1px solid var(--blue-100,#dceaf8); padding:14px 22px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.bsec__title { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--blue-700,#2861b3); margin:0; }
.bsec__body { padding:20px 22px 16px; }

/* ── Summary cards ─────────────────────────────────────────────────── */
.sgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
.scard { background:var(--blue-50,#eef5fc); border:1px solid var(--blue-100,#dceaf8); border-radius:10px; padding:14px 16px; }
.scard__lbl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--blue-500,#5b7693); }
.scard__val { font-size:1.15rem; font-weight:700; color:var(--blue-900,#16324f); margin-top:6px; }
.scard--blue  .scard__val { color:var(--blue-700,#2861b3); }
.scard--green .scard__val { color:#15803d; }
.scard--red   .scard__val { color:#b91c1c; }

/* ── Tables ────────────────────────────────────────────────────────── */
.btbl-wrap { overflow-x:auto; border:1px solid var(--blue-100,#dceaf8); border-radius:10px; }
.btbl { width:100%; border-collapse:collapse; font-size:.85rem; }
.btbl th { background:var(--blue-50,#eef5fc); padding:10px 14px; text-align:left; font-size:.72rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--blue-500,#5b7693); border-bottom:1px solid var(--blue-100,#dceaf8); white-space:nowrap; }
.btbl td { padding:11px 14px; border-bottom:1px solid #f0f5fb; color:var(--blue-900,#16324f); vertical-align:middle; }
.btbl tbody tr:last-child td { border-bottom:none; }
.btbl tbody tr:hover td { background:#f8fbff; }
.btbl tfoot td { font-weight:700; background:var(--blue-50,#eef5fc); border-top:2px solid var(--blue-100,#dceaf8); }
.btbl .r { text-align:right; font-weight:600; font-variant-numeric:tabular-nums; }
.no-data { text-align:center; color:var(--blue-400,#8dafc8); padding:28px; font-size:.84rem; }

/* ── Badges ────────────────────────────────────────────────────────── */
.bstatus { display:inline-block; padding:3px 10px; border-radius:999px; font-size:.74rem; font-weight:700; }
.bs--active,.bs--paid   { background:#d4f7e7; color:#15803d; }
.bs--unpaid,.bs--draft  { background:#f1f5f9; color:#64748b; }
.bs--partial            { background:#fef9c3; color:#854d0e; }
.bs--overdue            { background:#fde8e8; color:#b91c1c; }
.bs--closed,.bs--void   { background:#e2e8f0; color:#475569; }
.bs--generated          { background:#dbeafe; color:#1d4ed8; }

/* ── Buttons ───────────────────────────────────────────────────────── */
.bbtn { padding:9px 18px; border-radius:8px; font-family:inherit; font-size:.84rem; font-weight:600; cursor:pointer; border:none; transition:background 150ms; }
.bbtn--primary { background:#3b7dd8; color:#fff; }
.bbtn--primary:hover { background:#2861b3; }
.bbtn--success { background:#16a34a; color:#fff; }
.bbtn--success:hover { background:#15803d; }
.bbtn--ghost { background:none; border:1.5px solid var(--blue-100,#dceaf8); color:var(--blue-700,#2861b3); }
.bbtn--ghost:hover { background:var(--blue-50,#eef5fc); }
.bbtn--sm { padding:6px 12px; font-size:.78rem; }
.bbtn--danger { background:#dc2626; color:#fff; }
.bbtn--danger:hover { background:#b91c1c; }

/* ── Forms ──────────────────────────────────────────────────────────── */
.bform-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:16px; padding-top:16px; border-top:1px solid var(--blue-100,#dceaf8); }
.bfg { display:flex; flex-direction:column; gap:4px; }
.bfg label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--blue-600,#3b7dd8); }
.bfg input,.bfg select { padding:8px 11px; border:1.5px solid var(--blue-100,#dceaf8); border-radius:8px; font-family:inherit; font-size:.84rem; color:var(--blue-900,#16324f); background:#fff; outline:none; }
.bfg input:focus,.bfg select:focus { border-color:var(--blue-500,#3b7dd8); box-shadow:0 0 0 3px rgba(59,125,216,.12); }

/* ── Filter bar ────────────────────────────────────────────────────── */
.bfilters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; align-items:flex-end; }

/* ── Modal ──────────────────────────────────────────────────────────── */
.bmodal-overlay { position:fixed; inset:0; background:rgba(22,50,79,.45); backdrop-filter:blur(3px); display:flex; align-items:center; justify-content:center; z-index:10000; padding:20px; }
.bmodal { background:#fff; border-radius:16px; max-width:900px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 24px 64px -16px rgba(22,50,79,.25); }
.bmodal__head { padding:18px 24px; border-bottom:1px solid var(--blue-100,#dceaf8); display:flex; align-items:center; justify-content:space-between; }
.bmodal__head h2 { font-family:'Playfair Display',serif; font-size:1.2rem; font-weight:700; color:var(--blue-900,#16324f); margin:0; }
.bmodal__close { background:none; border:none; font-size:1.4rem; color:var(--blue-500,#5b7693); cursor:pointer; padding:4px 8px; }
.bmodal__body { padding:20px 24px; }
.berr { color:#b91c1c; font-size:.8rem; margin:6px 0 0; display:none; }

@media (max-width:680px) {
    .btabs { gap:0; }
    .btab { padding:10px 14px; font-size:.8rem; }
    .sgrid { grid-template-columns:1fr 1fr; }
    .bform-row { flex-direction:column; align-items:stretch; }
}
@media print {
    .topbar,.btabs,.bform-row,.bbtn,.bfilters,header,nav { display:none !important; }
    .bpanel { display:block !important; }
    .bsec { box-shadow:none; border:1px solid #ccc; page-break-inside:avoid; }
}
</style>
</head>
<body class="dashboard-body">

<header class="topbar">
    <div class="topbar__brand">
        <span class="topbar__brand-mark">B</span>
        <span class="topbar__brand-name">Bluebookers<span class="topbar__brand-suffix">.admin</span></span>
    </div>
    <div class="topbar__right">
        <div class="topbar__user">
            <span class="topbar__user-name"><?= htmlspecialchars($displayName) ?></span>
            <span class="topbar__user-role"><?= $isAdmin ? 'Admin' : 'Staff' ?></span>
        </div>
        <a href="../logout.php" class="topbar__logout">
            <svg viewBox="0 0 24 24" fill="none"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
</header>

<?php include __DIR__ . '/includes/property_navbar.php'; ?>

<main class="billing-main">
    <div class="billing-heading">
        <p class="billing-heading__eyebrow"><?= htmlspecialchars($branchLabel) ?></p>
        <h1 class="billing-heading__title">Billing &amp; Payments</h1>
    </div>

    <!-- ── Tab Navigation ──────────────────────────────────────────────── -->
    <div class="btabs" id="billingTabs">
        <button class="btab is-active" data-tab="summary">Summary</button>
        <button class="btab" data-tab="readings">Utility Readings</button>
        <button class="btab" data-tab="bills">Monthly Bills</button>
        <button class="btab" data-tab="payments">Payments</button>
        <button class="btab" data-tab="settings">Settings</button>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB 1: SUMMARY                                                     -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bpanel is-active" id="tab-summary">
        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Financial Summary</h2></div>
            <div class="bsec__body">
                <div class="sgrid" id="summaryCards">
                    <div class="scard scard--blue"><div class="scard__lbl">Total Revenue</div><div class="scard__val" id="sumRevenue">Loading…</div></div>
                    <div class="scard scard--green"><div class="scard__lbl">Total Collected</div><div class="scard__val" id="sumCollected">—</div></div>
                    <div class="scard scard--red"><div class="scard__lbl">Outstanding</div><div class="scard__val" id="sumBalance">—</div></div>
                    <div class="scard"><div class="scard__lbl">Active Stays</div><div class="scard__val" id="sumActive">—</div></div>
                    <div class="scard"><div class="scard__lbl">Unpaid Utilities</div><div class="scard__val" id="sumUtilUnpaid">—</div></div>
                </div>
            </div>
        </div>

        <!-- Quick utility charges table -->
        <div class="bsec">
            <div class="bsec__head">
                <h2 class="bsec__title">Utility Charges</h2>
                <div class="bfilters" style="margin:0">
                    <select id="utilFilterType" class="bfg" style="padding:6px 10px;border:1px solid #dceaf8;border-radius:6px;font-size:.8rem;">
                        <option value="">All Types</option>
                        <option value="Electricity">Electricity</option>
                        <option value="Water">Water</option>
                        <option value="Internet">Internet</option>
                        <option value="Other">Other</option>
                    </select>
                    <select id="utilFilterStatus" class="bfg" style="padding:6px 10px;border:1px solid #dceaf8;border-radius:6px;font-size:.8rem;">
                        <option value="">All</option>
                        <option value="Unpaid">Unpaid</option>
                        <option value="Paid">Paid</option>
                    </select>
                    <button class="bbtn bbtn--ghost bbtn--sm" onclick="loadUtilCharges()">Filter</button>
                </div>
            </div>
            <div class="bsec__body">
                <div class="btbl-wrap">
                    <table class="btbl">
                        <thead><tr><th>Guest</th><th>Room</th><th>Type</th><th>Period</th><th class="r">Amount</th><th>Status</th><th></th></tr></thead>
                        <tbody id="utilBody"><tr><td colspan="7" class="no-data">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Invoices &amp; Folios</h2></div>
            <div class="bsec__body">
                <p style="color:var(--blue-500);font-size:.88rem;margin:0;">
                    Detailed guest folios and payment histories are accessible from
                    <a href="guests.php?branch=<?= urlencode($branch) ?>" style="color:var(--blue-700);font-weight:600;">Guests &amp; Folios</a>.
                </p>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB 2: UTILITY READINGS (THE LOGBOOK)                              -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bpanel" id="tab-readings">
        <div class="bsec">
            <div class="bsec__head">
                <h2 class="bsec__title">Active Rooms — Enter Daily Readings</h2>
                <span style="font-size:.78rem;color:var(--blue-500)">Like a paper logbook — enter readings daily per room.</span>
            </div>
            <div class="bsec__body">
                <div class="btbl-wrap">
                    <table class="btbl" id="readingsTable">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Guest</th>
                                <th>Utility</th>
                                <th class="r">Last Reading</th>
                                <th class="r">Total Used</th>
                                <th class="r">Total Charge</th>
                                <th>Last Updated</th>
                                <th>Enter Reading</th>
                            </tr>
                        </thead>
                        <tbody id="activeRoomsBody"><tr><td colspan="8" class="no-data">Loading active rooms…</td></tr></tbody>
                    </table>
                </div>

                <!-- Start new session -->
                <div class="bform-row" id="newSessionRow">
                    <div class="bfg"><label>Reservation #</label><input type="number" id="nsResvId" placeholder="e.g. 5" style="width:100px"></div>
                    <div class="bfg"><label>Room ID</label><input type="number" id="nsRoomId" placeholder="Room ID" style="width:100px"></div>
                    <div class="bfg"><label>Utility</label>
                        <select id="nsType"><option value="Electricity">Electricity</option><option value="Water">Water</option></select>
                    </div>
                    <div class="bfg"><label>Initial Reading</label><input type="number" id="nsInitial" step="0.01" placeholder="e.g. 1100" style="width:120px"></div>
                    <button class="bbtn bbtn--success" id="nsStartBtn">Start Session</button>
                    <p class="berr" id="nsErr"></p>
                </div>
            </div>
        </div>

        <!-- Reading history modal area -->
        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Reading History</h2>
                <div class="bfilters" style="margin:0">
                    <div class="bfg"><label>Session ID</label><input type="number" id="rhSessionId" placeholder="Session #" style="width:100px"></div>
                    <button class="bbtn bbtn--ghost bbtn--sm" onclick="loadReadingHistory()">Load History</button>
                </div>
            </div>
            <div class="bsec__body">
                <div class="btbl-wrap">
                    <table class="btbl">
                        <thead><tr><th>Date</th><th>Previous</th><th>Present</th><th class="r">Consumption</th><th class="r">Rate</th><th class="r">Charge</th><th>By</th></tr></thead>
                        <tbody id="readingHistBody"><tr><td colspan="7" class="no-data">Select a session to view its daily reading log.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB 3: MONTHLY BILLS                                               -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bpanel" id="tab-bills">
        <div class="bsec">
            <div class="bsec__head">
                <h2 class="bsec__title">Monthly Bills</h2>
                <div class="bfilters" style="margin:0">
                    <div class="bfg"><label>Period</label><input type="month" id="billFilterPeriod" style="width:150px"></div>
                    <button class="bbtn bbtn--ghost bbtn--sm" onclick="loadMonthlyBills()">Filter</button>
                </div>
            </div>
            <div class="bsec__body">
                <div class="btbl-wrap">
                    <table class="btbl">
                        <thead><tr><th>Period</th><th>Guest</th><th>Room</th><th class="r">Rental</th><th class="r">Utilities</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th></th></tr></thead>
                        <tbody id="billsBody"><tr><td colspan="10" class="no-data">Loading bills…</td></tr></tbody>
                    </table>
                </div>

                <!-- Generate bill form -->
                <div class="bform-row">
                    <div class="bfg"><label>Reservation #</label><input type="number" id="genResvId" placeholder="e.g. 5" style="width:100px"></div>
                    <div class="bfg"><label>Billing Period</label><input type="month" id="genPeriod" style="width:150px"></div>
                    <button class="bbtn bbtn--primary" id="genBillBtn">Generate Bill</button>
                    <p class="berr" id="genErr"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB 4: PAYMENTS                                                    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bpanel" id="tab-payments">
        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Record Payment</h2></div>
            <div class="bsec__body">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="bfg"><label>Reservation #</label><input type="number" id="rpResvId" placeholder="e.g. 5" style="width:100px"></div>
                    <div class="bfg"><label>Amount (₱)</label><input type="number" id="rpAmount" min="0.01" step="0.01" placeholder="0.00" style="width:130px"></div>
                    <div class="bfg"><label>Method</label>
                        <select id="rpMethod"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_transfer">Bank Transfer</option><option value="card">Credit Card</option></select>
                    </div>
                    <div class="bfg"><label>Date</label><input type="date" id="rpDate" style="width:140px"></div>
                    <div class="bfg"><label>Remarks</label><input type="text" id="rpRemarks" placeholder="ref no., notes" style="width:180px"></div>
                    <button class="bbtn bbtn--success" id="rpAddBtn">Record Payment</button>
                    <p class="berr" id="rpErr"></p>
                </div>
            </div>
        </div>

        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Statement of Account</h2></div>
            <div class="bsec__body">
                <div style="display:flex;gap:10px;align-items:flex-end;">
                    <div class="bfg"><label>Reservation #</label><input type="number" id="soaResvId" placeholder="e.g. 5" style="width:100px"></div>
                    <button class="bbtn bbtn--primary" id="soaBtn">Print Statement</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- TAB 5: SETTINGS                                                    -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div class="bpanel" id="tab-settings">
        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Billing Settings — <?= htmlspecialchars($branchLabel) ?></h2></div>
            <div class="bsec__body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:500px;">
                    <div class="bfg">
                        <label>Billing Cutoff Time</label>
                        <input type="time" id="setCutoff" value="17:00">
                        <span style="font-size:.75rem;color:var(--blue-500);">Check-ins after this time start billing the next day.</span>
                    </div>
                    <div class="bfg">
                        <label>Late Penalty (%)</label>
                        <input type="number" id="setLatePenalty" min="0" step="0.5" value="0">
                    </div>
                </div>
                <div style="margin-top:16px;"><button class="bbtn bbtn--primary" id="saveSettingsBtn">Save Settings</button></div>
            </div>
        </div>

        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Utility Rates — <?= htmlspecialchars($branchLabel) ?></h2></div>
            <div class="bsec__body">
                <div class="btbl-wrap" style="max-width:500px;">
                    <table class="btbl" id="ratesTable">
                        <thead><tr><th>Utility</th><th class="r">Rate/Unit</th><th>Unit</th><th></th></tr></thead>
                        <tbody id="ratesBody"><tr><td colspan="4" class="no-data">Loading…</td></tr></tbody>
                    </table>
                </div>
                <div class="bform-row" style="max-width:500px;">
                    <div class="bfg"><label>Utility</label>
                        <select id="rateType"><option value="Electricity">Electricity</option><option value="Water">Water</option><option value="Internet">Internet</option><option value="Gas">Gas</option></select>
                    </div>
                    <div class="bfg"><label>Rate/Unit</label><input type="number" id="rateValue" step="0.0001" placeholder="13.58" style="width:120px"></div>
                    <div class="bfg"><label>Unit</label><input type="text" id="rateUnit" value="kWh" style="width:80px"></div>
                    <button class="bbtn bbtn--success" id="saveRateBtn">Save Rate</button>
                    <p class="berr" id="rateErr"></p>
                </div>
            </div>
        </div>

        <!-- Billing Audit Log -->
        <div class="bsec">
            <div class="bsec__head"><h2 class="bsec__title">Billing Audit Log</h2></div>
            <div class="bsec__body">
                <div class="btbl-wrap">
                    <table class="btbl">
                        <thead><tr><th>Date</th><th>Action</th><th>Details</th><th>By</th></tr></thead>
                        <tbody id="auditBody"><tr><td colspan="4" class="no-data">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                         -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<script>
const BRANCH   = <?= json_encode($branch) ?>;
const BR_LABEL = <?= json_encode($branchLabel) ?>;
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const API      = '/process_billing.php';
const API_R    = '/process_reservation.php';

function fmt(n) { return '₱' + parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { var d=document.createElement('div'); d.textContent=s??'—'; return d.innerHTML; }
function fdate(s) { if (!s) return '—'; return new Date(s+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); }

// ── Tabs ──────────────────────────────────────────────────────────────
document.querySelectorAll('.btab').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.btab').forEach(b => b.classList.remove('is-active'));
        document.querySelectorAll('.bpanel').forEach(p => p.classList.remove('is-active'));
        this.classList.add('is-active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('is-active');
        // Load data for tab
        switch(this.dataset.tab) {
            case 'summary':  loadSummary(); loadUtilCharges(); break;
            case 'readings': loadActiveRooms(); break;
            case 'bills':    loadMonthlyBills(); break;
            case 'settings': loadSettings(); loadRates(); loadAuditLog(); break;
        }
    });
});

// ── Summary ──────────────────────────────────────────────────────────
function loadSummary() {
    fetch(API + '?action=billing_summary&branch=' + BRANCH)
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            var s = d.summary || {};
            document.getElementById('sumRevenue').textContent    = fmt(s.total_revenue);
            document.getElementById('sumCollected').textContent  = fmt(s.total_collected);
            document.getElementById('sumBalance').textContent    = fmt(s.total_balance);
            document.getElementById('sumActive').textContent     = (s.active_stays||0) + ' guests';
            document.getElementById('sumUtilUnpaid').textContent = fmt(s.util_unpaid);
        }).catch(function(){ document.getElementById('sumRevenue').textContent = 'N/A'; });
}

// ── Utility Charges (from utility_charges table) ─────────────────────
function loadUtilCharges() {
    var body = document.getElementById('utilBody');
    var type = document.getElementById('utilFilterType').value;
    var status = document.getElementById('utilFilterStatus').value;
    body.innerHTML = '<tr><td colspan="7" class="no-data">Loading…</td></tr>';
    var url = API_R + '?action=get_all_utilities&branch=' + BRANCH;
    if (type)   url += '&utility_type=' + encodeURIComponent(type);
    if (status) url += '&status=' + encodeURIComponent(status);
    fetch(url).then(r=>r.json()).then(function(d){
        var items = d.utilities || [];
        if (!items.length) { body.innerHTML = '<tr><td colspan="7" class="no-data">No utility charges.</td></tr>'; return; }
        body.innerHTML = items.map(function(u){
            var cls = u.status==='Paid'?'bs--paid':u.status==='Partial'?'bs--partial':'bs--unpaid';
            return '<tr><td>'+esc(u.guest_full_name||'—')+'</td><td>RM '+esc(u.room_number||'—')+'</td>' +
                '<td>'+esc(u.utility_type)+'</td><td>'+esc(u.billing_period||'—')+'</td>' +
                '<td class="r">'+fmt(u.amount)+'</td><td><span class="bstatus '+cls+'">'+esc(u.status)+'</span></td>' +
                '<td></td></tr>';
        }).join('');
    }).catch(function(){ body.innerHTML = '<tr><td colspan="7" class="no-data">Error.</td></tr>'; });
}

// ── Active Rooms (utility sessions) ──────────────────────────────────
function loadActiveRooms() {
    var body = document.getElementById('activeRoomsBody');
    body.innerHTML = '<tr><td colspan="8" class="no-data">Loading…</td></tr>';
    fetch(API + '?action=get_active_rooms&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            var rooms = d.rooms || [];
            if (!rooms.length) { body.innerHTML = '<tr><td colspan="8" class="no-data">No active utility sessions. Start one below or check in a guest.</td></tr>'; return; }
            body.innerHTML = rooms.map(function(rm){
                var lastR = rm.last_reading || rm.initial_reading || 0;
                return '<tr data-sid="'+rm.session_id+'" data-rid="'+rm.reservation_id+'">' +
                    '<td><strong>RM '+esc(rm.room_number)+'</strong></td>' +
                    '<td>'+esc(rm.guest_full_name)+'</td>' +
                    '<td>'+esc(rm.utility_type)+'</td>' +
                    '<td class="r">'+parseFloat(lastR).toFixed(2)+'</td>' +
                    '<td class="r">'+parseFloat(rm.total_consumption||0).toFixed(2)+'</td>' +
                    '<td class="r">'+fmt(rm.total_charge)+'</td>' +
                    '<td>'+fdate(rm.last_reading_date)+'</td>' +
                    '<td style="white-space:nowrap">' +
                        '<input type="number" step="0.01" placeholder="Present" style="width:100px;padding:6px 8px;border:1.5px solid #c5deef;border-radius:6px;font-size:.82rem;" class="reading-input" data-last="'+lastR+'">' +
                        ' <button class="bbtn bbtn--success bbtn--sm reading-save-btn">Save</button>' +
                        ' <button class="bbtn bbtn--ghost bbtn--sm reading-hist-btn" title="View history">📋</button>' +
                    '</td></tr>';
            }).join('');
            // Wire save buttons
            document.querySelectorAll('.reading-save-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var tr = this.closest('tr');
                    var input = tr.querySelector('.reading-input');
                    var val = parseFloat(input.value);
                    var sid = tr.dataset.sid;
                    var rid = tr.dataset.rid;
                    if (!val || val <= 0) { alert('Enter a valid reading.'); return; }
                    this.disabled = true; this.textContent = '…';
                    var self = this;
                    var fd = new FormData();
                    fd.append('action', 'add_reading');
                    fd.append('branch', BRANCH);
                    fd.append('session_id', sid);
                    fd.append('reservation_id', rid);
                    fd.append('present_reading', val);
                    fd.append('reading_date', new Date().toISOString().split('T')[0]);
                    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
                        self.disabled = false; self.textContent = 'Save';
                        if (!d.success) { alert(d.message || 'Error saving reading.'); return; }
                        input.value = '';
                        loadActiveRooms();
                    }).catch(function(){ self.disabled=false; self.textContent='Save'; alert('Network error.'); });
                });
            });
            // Wire history buttons
            document.querySelectorAll('.reading-hist-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var sid = this.closest('tr').dataset.sid;
                    document.getElementById('rhSessionId').value = sid;
                    loadReadingHistory();
                });
            });
        })
        .catch(function(){ body.innerHTML = '<tr><td colspan="8" class="no-data">Error loading rooms.</td></tr>'; });
}

// ── Reading History ──────────────────────────────────────────────────
function loadReadingHistory() {
    var sid = document.getElementById('rhSessionId').value;
    var body = document.getElementById('readingHistBody');
    if (!sid) { body.innerHTML = '<tr><td colspan="7" class="no-data">Enter a session ID above.</td></tr>'; return; }
    body.innerHTML = '<tr><td colspan="7" class="no-data">Loading…</td></tr>';
    fetch(API + '?action=get_readings&session_id=' + sid + '&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            var rows = d.readings || [];
            if (!rows.length) { body.innerHTML = '<tr><td colspan="7" class="no-data">No readings recorded yet.</td></tr>'; return; }
            body.innerHTML = rows.map(function(r){
                return '<tr><td>'+fdate(r.reading_date)+'</td>' +
                    '<td class="r">'+parseFloat(r.previous_reading).toFixed(2)+'</td>' +
                    '<td class="r">'+parseFloat(r.present_reading).toFixed(2)+'</td>' +
                    '<td class="r"><strong>'+parseFloat(r.consumption).toFixed(2)+'</strong></td>' +
                    '<td class="r">'+parseFloat(r.rate).toFixed(4)+'</td>' +
                    '<td class="r"><strong>'+fmt(r.charge)+'</strong></td>' +
                    '<td>'+esc(r.entered_by_name||'—')+'</td></tr>';
            }).join('');
        }).catch(function(){ body.innerHTML = '<tr><td colspan="7" class="no-data">Error.</td></tr>'; });
}

// ── Start Utility Session ────────────────────────────────────────────
document.getElementById('nsStartBtn').addEventListener('click', function(){
    var err = document.getElementById('nsErr'); err.style.display = 'none';
    var resvId  = parseInt(document.getElementById('nsResvId').value);
    var roomId  = parseInt(document.getElementById('nsRoomId').value);
    var type    = document.getElementById('nsType').value;
    var initial = parseFloat(document.getElementById('nsInitial').value);
    if (!resvId) { err.textContent = 'Enter a reservation #.'; err.style.display = 'block'; return; }
    if (!roomId) { err.textContent = 'Enter a room ID.'; err.style.display = 'block'; return; }
    if (isNaN(initial)) { err.textContent = 'Enter initial reading.'; err.style.display = 'block'; return; }
    var btn = this; btn.disabled = true; btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'start_utility_session');
    fd.append('branch', BRANCH);
    fd.append('reservation_id', resvId);
    fd.append('room_id', roomId);
    fd.append('utility_type', type);
    fd.append('initial_reading', initial);
    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
        btn.disabled = false; btn.textContent = 'Start Session';
        if (!d.success) { err.textContent = d.message; err.style.display = 'block'; return; }
        document.getElementById('nsInitial').value = '';
        loadActiveRooms();
    }).catch(function(){ btn.disabled=false; btn.textContent='Start Session'; err.textContent='Network error.'; err.style.display='block'; });
});

// ── Monthly Bills ────────────────────────────────────────────────────
function loadMonthlyBills() {
    var body = document.getElementById('billsBody');
    var period = document.getElementById('billFilterPeriod').value;
    body.innerHTML = '<tr><td colspan="10" class="no-data">Loading…</td></tr>';
    var url = API + '?action=get_monthly_bills&branch=' + BRANCH;
    if (period) url += '&period=' + period;
    fetch(url).then(r=>r.json()).then(function(d){
        var bills = d.bills || [];
        if (!bills.length) { body.innerHTML = '<tr><td colspan="10" class="no-data">No bills generated yet.</td></tr>'; return; }
        body.innerHTML = bills.map(function(b){
            var utils = parseFloat(b.electricity_charge||0) + parseFloat(b.water_charge||0) + parseFloat(b.internet_charge||0) + parseFloat(b.other_charges||0);
            var cls = b.status==='Paid'?'bs--paid':b.status==='Partial'?'bs--partial':b.status==='Overdue'?'bs--overdue':b.status==='Void'?'bs--void':'bs--generated';
            return '<tr><td><strong>'+esc(b.billing_period)+'</strong></td>' +
                '<td>'+esc(b.guest_name)+'</td>' +
                '<td>RM '+(b.room_number||'—')+'</td>' +
                '<td class="r">'+fmt(b.room_rental)+'</td>' +
                '<td class="r">'+fmt(utils)+'</td>' +
                '<td class="r"><strong>'+fmt(b.grand_total)+'</strong></td>' +
                '<td class="r" style="color:#15803d">'+fmt(b.amount_paid)+'</td>' +
                '<td class="r" style="color:'+(parseFloat(b.balance)>0?'#b91c1c':'#15803d')+'"><strong>'+fmt(b.balance)+'</strong></td>' +
                '<td><span class="bstatus '+cls+'">'+esc(b.status)+'</span></td>' +
                '<td><button class="bbtn bbtn--ghost bbtn--sm" onclick="viewBill('+b.id+')">View</button></td></tr>';
        }).join('');
    }).catch(function(){ body.innerHTML = '<tr><td colspan="10" class="no-data">Error.</td></tr>'; });
}

// ── Generate Bill ────────────────────────────────────────────────────
document.getElementById('genBillBtn').addEventListener('click', function(){
    var err = document.getElementById('genErr'); err.style.display = 'none';
    var resvId = parseInt(document.getElementById('genResvId').value);
    var period = document.getElementById('genPeriod').value;
    if (!resvId) { err.textContent = 'Enter a reservation #.'; err.style.display = 'block'; return; }
    if (!period) { err.textContent = 'Select a billing period.'; err.style.display = 'block'; return; }
    var btn = this; btn.disabled = true; btn.textContent = 'Generating…';
    var fd = new FormData();
    fd.append('action', 'generate_monthly_bill');
    fd.append('branch', BRANCH);
    fd.append('reservation_id', resvId);
    fd.append('billing_period', period);
    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
        btn.disabled = false; btn.textContent = 'Generate Bill';
        if (!d.success) { err.textContent = d.message; err.style.display = 'block'; return; }
        alert('Bill generated. Total: ' + fmt(d.grand_total) + ', Balance: ' + fmt(d.balance));
        loadMonthlyBills();
        loadSummary();
    }).catch(function(){ btn.disabled=false; btn.textContent='Generate Bill'; err.textContent='Network error.'; err.style.display='block'; });
});

// ── View Bill Detail ─────────────────────────────────────────────────
function viewBill(billId) {
    fetch(API + '?action=get_bill_detail&bill_id=' + billId + '&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            if (!d.success) { alert(d.message || 'Could not load bill.'); return; }
            var b = d.bill;
            var rdgs = b.readings || [];
            var pays = b.payments || [];
            var html = '<div class="bmodal__head"><h2>Bill — '+esc(b.billing_period)+' — '+esc(b.guest_name)+'</h2><button class="bmodal__close" onclick="this.closest(\'.bmodal-overlay\').remove()">&times;</button></div>' +
                '<div class="bmodal__body">' +
                '<div class="sgrid" style="margin-bottom:16px">' +
                    '<div class="scard"><div class="scard__lbl">Room Rental</div><div class="scard__val">'+fmt(b.room_rental)+'</div></div>' +
                    '<div class="scard"><div class="scard__lbl">Electricity</div><div class="scard__val">'+fmt(b.electricity_charge)+'</div></div>' +
                    '<div class="scard"><div class="scard__lbl">Water</div><div class="scard__val">'+fmt(b.water_charge)+'</div></div>' +
                    '<div class="scard scard--blue"><div class="scard__lbl">Grand Total</div><div class="scard__val">'+fmt(b.grand_total)+'</div></div>' +
                    '<div class="scard scard--green"><div class="scard__lbl">Paid</div><div class="scard__val">'+fmt(b.amount_paid)+'</div></div>' +
                    '<div class="scard scard--red"><div class="scard__lbl">Balance</div><div class="scard__val">'+fmt(b.balance)+'</div></div>' +
                '</div>';
            if (b.reservation_fee > 0 || b.garbage_fee > 0 || b.security_deposit > 0 || b.utilities_deposit > 0) {
                html += '<p style="font-size:.82rem;color:var(--blue-500);">One-time charges: Reservation Fee '+fmt(b.reservation_fee)+' · Garbage Fee '+fmt(b.garbage_fee)+' · Security Deposit '+fmt(b.security_deposit)+' · Utilities Deposit '+fmt(b.utilities_deposit)+'</p>';
            }
            if (rdgs.length) {
                html += '<h3 style="font-size:.8rem;text-transform:uppercase;color:var(--blue-500);margin:16px 0 8px;letter-spacing:.05em;">Utility Readings</h3>';
                html += '<div class="btbl-wrap"><table class="btbl"><thead><tr><th>Date</th><th>Type</th><th class="r">Prev</th><th class="r">Present</th><th class="r">Consumption</th><th class="r">Charge</th></tr></thead><tbody>';
                html += rdgs.map(function(r){ return '<tr><td>'+fdate(r.reading_date)+'</td><td>'+esc(r.utility_type)+'</td><td class="r">'+parseFloat(r.previous_reading).toFixed(2)+'</td><td class="r">'+parseFloat(r.present_reading).toFixed(2)+'</td><td class="r"><strong>'+parseFloat(r.consumption).toFixed(2)+'</strong></td><td class="r">'+fmt(r.charge)+'</td></tr>'; }).join('');
                html += '</tbody></table></div>';
            }
            if (pays.length) {
                html += '<h3 style="font-size:.8rem;text-transform:uppercase;color:var(--blue-500);margin:16px 0 8px;letter-spacing:.05em;">Payments</h3>';
                html += '<div class="btbl-wrap"><table class="btbl"><thead><tr><th>Date</th><th>Method</th><th>Remarks</th><th class="r">Amount</th></tr></thead><tbody>';
                html += pays.map(function(p){ return '<tr><td>'+fdate(p.payment_date)+'</td><td>'+esc(p.payment_method)+'</td><td>'+esc(p.remarks||'—')+'</td><td class="r" style="color:#15803d;font-weight:700">'+fmt(p.amount)+'</td></tr>'; }).join('');
                html += '</tbody></table></div>';
            }
            html += '<div style="margin-top:16px;display:flex;gap:8px;">' +
                '<button class="bbtn bbtn--primary" onclick="printStatement('+b.reservation_id+')">Print Statement</button>' +
                (b.locked ? '<span class="bstatus bs--closed" style="align-self:center">Locked</span>' : '<button class="bbtn bbtn--ghost" onclick="lockBill('+b.id+')">Lock Bill</button>') +
                '</div></div>';
            var overlay = document.createElement('div');
            overlay.className = 'bmodal-overlay';
            overlay.innerHTML = '<div class="bmodal">' + html + '</div>';
            overlay.addEventListener('click', function(e){ if(e.target===overlay) overlay.remove(); });
            document.body.appendChild(overlay);
        }).catch(function(){ alert('Error loading bill.'); });
}

function lockBill(billId) {
    if (!confirm('Lock this bill? It cannot be edited after locking.')) return;
    var fd = new FormData();
    fd.append('action', 'lock_bill');
    fd.append('branch', BRANCH);
    fd.append('bill_id', billId);
    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
        if (!d.success) { alert(d.message); return; }
        document.querySelector('.bmodal-overlay').remove();
        loadMonthlyBills();
    });
}

// ── Record Payment ───────────────────────────────────────────────────
document.getElementById('rpAddBtn').addEventListener('click', function(){
    var err = document.getElementById('rpErr'); err.style.display = 'none';
    var resvId  = parseInt(document.getElementById('rpResvId').value);
    var amount  = parseFloat(document.getElementById('rpAmount').value);
    var method  = document.getElementById('rpMethod').value;
    var date    = document.getElementById('rpDate').value || new Date().toISOString().split('T')[0];
    var remarks = document.getElementById('rpRemarks').value;
    if (!resvId) { err.textContent = 'Enter a reservation #.'; err.style.display = 'block'; return; }
    if (!amount || amount <= 0) { err.textContent = 'Enter a valid amount.'; err.style.display = 'block'; return; }
    var btn = this; btn.disabled = true; btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'record_payment');
    fd.append('reservation_id', resvId);
    fd.append('amount', amount);
    fd.append('payment_method', method);
    fd.append('payment_date', date);
    fd.append('remarks', remarks);
    fetch(API_R, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
        btn.disabled = false; btn.textContent = 'Record Payment';
        if (!d.success) { err.textContent = d.message || 'Error.'; err.style.display = 'block'; return; }
        document.getElementById('rpAmount').value = '';
        document.getElementById('rpRemarks').value = '';
        loadSummary();
        alert('Payment recorded.');
    }).catch(function(){ btn.disabled=false; btn.textContent='Record Payment'; err.textContent='Network error.'; err.style.display='block'; });
});

// ── Statement of Account ─────────────────────────────────────────────
document.getElementById('soaBtn').addEventListener('click', function(){
    var resvId = parseInt(document.getElementById('soaResvId').value);
    if (!resvId) { alert('Enter a reservation #.'); return; }
    printStatement(resvId);
});

function printStatement(resvId) {
    fetch(API + '?action=get_statement&reservation_id=' + resvId + '&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            if (!d.success) { alert(d.message || 'Could not load statement.'); return; }
            var r = d.reservation;
            var bills = d.bills || [];
            var readings = d.readings || [];
            var payments = d.payments || [];
            var totalPaid = d.total_paid || 0;

            // Calculate totals
            var monthlyRent = parseFloat(r.room_rate || 0);
            var secDeposit  = parseFloat(r.security_deposit || 0);
            var resvFee     = parseFloat(r.reservation_fee || 0);
            var garbageFee  = parseFloat(r.garbage_fee || 0);
            var utilsDep    = parseFloat(r.utilities_deposit || 0);

            // Group readings by type — get first/last for summary
            var elecReadings = readings.filter(function(rd){ return rd.utility_type === 'Electricity'; });
            var waterReadings = readings.filter(function(rd){ return rd.utility_type === 'Water'; });

            var elecPresent = 0, elecPrevious = 0, elecKwh = 0, elecRate = 0, elecCharge = 0;
            if (elecReadings.length) {
                elecPrevious = parseFloat(elecReadings[0].previous_reading);
                elecPresent  = parseFloat(elecReadings[elecReadings.length-1].present_reading);
                elecKwh      = elecReadings.reduce(function(s,x){ return s + parseFloat(x.consumption||0); }, 0);
                elecRate     = parseFloat(elecReadings[0].rate || 0);
                elecCharge   = elecReadings.reduce(function(s,x){ return s + parseFloat(x.charge||0); }, 0);
            }

            var waterPresent = 0, waterPrevious = 0, waterCum = 0, waterRate = 0, waterCharge = 0;
            if (waterReadings.length) {
                waterPrevious = parseFloat(waterReadings[0].previous_reading);
                waterPresent  = parseFloat(waterReadings[waterReadings.length-1].present_reading);
                waterCum      = waterReadings.reduce(function(s,x){ return s + parseFloat(x.consumption||0); }, 0);
                waterRate     = parseFloat(waterReadings[0].rate || 0);
                waterCharge   = waterReadings.reduce(function(s,x){ return s + parseFloat(x.charge||0); }, 0);
            }

            var rentalSubtotal = monthlyRent + secDeposit + resvFee + garbageFee + utilsDep;
            var totalAmountDue = rentalSubtotal + elecCharge + waterCharge;
            var elecDateRange  = elecReadings.length ? fdate(elecReadings[0].reading_date) + ' – ' + fdate(elecReadings[elecReadings.length-1].reading_date) : '—';
            var waterDateRange = waterReadings.length ? fdate(waterReadings[0].reading_date) + ' – ' + fdate(waterReadings[waterReadings.length-1].reading_date) : '—';

            var w = window.open('', '_blank', 'width=860,height=900');
            if (!w) { alert('Allow pop-ups to print.'); return; }

            w.document.write('<!DOCTYPE html><html><head><title>Statement of Account — '+esc(r.guest_full_name)+'</title>' +
'<style>' +
'* { box-sizing:border-box; margin:0; padding:0; }' +
'body { font-family: Arial, sans-serif; font-size: 12px; color: #000; padding: 30px 40px; max-width: 800px; margin: 0 auto; }' +

/* Header */
'.soa-header { text-align: center; margin-bottom: 20px; }' +
'.soa-header .company { font-size: 16px; font-weight: 700; text-decoration: underline; color: #1a237e; }' +
'.soa-header .address { font-size: 11px; color: #1a237e; margin-top: 2px; }' +
'.soa-header .contact { font-size: 10px; color: #1a237e; margin-top: 1px; }' +

/* Guest line */
'.soa-guest { font-size: 13px; font-weight: 700; margin: 12px 0 4px; }' +
'.soa-title-line { font-size: 12px; font-weight: 700; text-align: center; text-decoration: underline; margin: 10px 0 14px; }' +

/* Tables */
'table { width: 100%; border-collapse: collapse; margin-bottom: 2px; font-size: 11px; }' +
'th, td { border: 1px solid #333; padding: 5px 8px; vertical-align: middle; }' +
'th { background: #f5f5f5; font-weight: 700; text-align: center; font-size: 10px; text-transform: uppercase; }' +
'.r { text-align: right; }' +
'.c { text-align: center; }' +
'.b { font-weight: 700; }' +
'.no-border { border: none; }' +
'.subtotal-row td { font-weight: 700; }' +
'.section-header { background: #f9f9f9; font-weight: 700; }' +

/* Total */
'.total-due { background: #ffff00; font-size: 13px; font-weight: 700; }' +

/* Footer */
'.soa-footer { font-size: 10px; margin-top: 16px; line-height: 1.6; }' +
'.soa-footer .note { color: #1a237e; font-weight: 700; margin-top: 10px; }' +
'.sig-area { display: flex; justify-content: space-between; margin-top: 30px; font-size: 11px; }' +
'.sig-area .sig-block { width: 45%; }' +
'.sig-line { border-top: 1px solid #000; margin-top: 30px; padding-top: 4px; }' +

'@media print { body { padding: 15px 25px; } }' +
'</style></head><body>' +

/* ── HEADER ── */
'<div class="soa-header">' +
  '<div class="company">BLUEBOOKERS CORP.</div>' +
  '<div class="address">Don Juico Ave., Malabanias Road, Angeles City</div>' +
  '<div class="contact">Tel Nos.: +63-917-117-1192</div>' +
  '<div class="contact">bluebookers@gmail.com</div>' +
'</div>' +

/* ── GUEST NAME ── */
'<div class="soa-guest">GUEST NAME: ' + esc(r.guest_full_name).toUpperCase() + '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ROOM: ' + esc(r.room_number) + '</div>' +
'<div class="soa-title-line">STATEMENT OF ACCOUNT AS OF ' + new Date().toLocaleDateString('en-PH', {month:'long', day:'numeric', year:'numeric'}).toUpperCase() + '</div>' +

/* ── ROOM RENTAL TABLE ── */
'<table>' +
  '<thead>' +
    '<tr><th>DATE</th><th>PARTICULARS</th><th>RENTAL</th><th>RENTAL AMOUNT DUE</th><th>TOTAL AMOUNT DUE</th></tr>' +
  '</thead>' +
  '<tbody>' +
    '<tr class="section-header"><td colspan="5">ROOM RENTAL FOR THE MONTH OF:</td></tr>' +
    '<tr>' +
      '<td>' + esc(r.check_in) + ' – ' + esc(r.check_out) + '</td>' +
      '<td></td>' +
      '<td class="r">' + monthlyRent.toLocaleString('en-PH') + '</td>' +
      '<td class="r">₱ ' + monthlyRent.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td>' +
      '<td class="r">₱ ' + monthlyRent.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td>' +
    '</tr>');

            // One-time charges rows
            if (secDeposit > 0) {
                w.document.write('<tr><td></td><td>Security Deposit</td><td></td><td class="r">₱ '+secDeposit.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td><td class="r">₱ '+secDeposit.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td></tr>');
            }
            if (resvFee > 0) {
                w.document.write('<tr><td></td><td>Reservation Fee</td><td></td><td class="r">₱ '+resvFee.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td><td class="r">₱ '+resvFee.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td></tr>');
            }
            if (garbageFee > 0) {
                w.document.write('<tr><td></td><td>Garbage Fee</td><td></td><td class="r">₱ '+garbageFee.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td><td class="r">₱ '+garbageFee.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td></tr>');
            }
            if (utilsDep > 0) {
                w.document.write('<tr><td></td><td>Utilities Deposit</td><td></td><td class="r">₱ '+utilsDep.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td><td class="r">₱ '+utilsDep.toLocaleString('en-PH',{minimumFractionDigits:2})+'</td></tr>');
            }

            w.document.write(
    '<tr class="subtotal-row"><td colspan="3"></td><td class="r">Sub total:</td><td class="r">₱ ' + rentalSubtotal.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td></tr>' +
  '</tbody></table>' +

/* ── ELECTRICITY TABLE ── */
'<table>' +
  '<thead>' +
    '<tr><th colspan="2">PARTICULARS</th><th colspan="4">READING</th><th></th></tr>' +
    '<tr><th>ELECTRICITY</th><th>PRESENT</th><th>PREVIOUS</th><th>KWH</th><th>RATE</th><th colspan="2"></th></tr>' +
  '</thead>' +
  '<tbody>' +
    '<tr>' +
      '<td>' + elecDateRange + '</td>' +
      '<td class="r">' + elecPresent.toFixed(2) + '</td>' +
      '<td class="r">' + elecPrevious.toFixed(2) + '</td>' +
      '<td class="r">' + elecKwh.toFixed(2) + '</td>' +
      '<td class="r">' + elecRate.toFixed(2) + '</td>' +
      '<td class="r">₱ ' + elecCharge.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td>' +
    '</tr>' +
    '<tr class="subtotal-row"><td colspan="4"></td><td class="r">SUBTOTAL</td><td class="r">₱ ' + elecCharge.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td></tr>' +
  '</tbody></table>' +

/* ── WATER TABLE ── */
'<table>' +
  '<thead>' +
    '<tr><th>WATER</th><th>PRESENT</th><th>PREVIOUS</th><th>CU.M.</th><th>RATE</th><th></th></tr>' +
  '</thead>' +
  '<tbody>' +
    '<tr>' +
      '<td>' + waterDateRange + '</td>' +
      '<td class="r">' + waterPresent.toFixed(2) + '</td>' +
      '<td class="r">' + waterPrevious.toFixed(2) + '</td>' +
      '<td class="r">' + waterCum.toFixed(3) + '</td>' +
      '<td class="r">' + waterRate.toFixed(2) + '</td>' +
      '<td class="r">₱ ' + waterCharge.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td>' +
    '</tr>' +
    '<tr class="subtotal-row"><td colspan="4"></td><td class="r">SUBTOTAL</td><td class="r">₱ ' + waterCharge.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td></tr>' +
  '</tbody></table>' +

/* ── OTHERS TABLE ── */
'<table>' +
  '<thead>' +
    '<tr><th>OTHERS</th><th>2% PER DAY</th><th>TOTAL</th></tr>' +
  '</thead>' +
  '<tbody>' +
    '<tr><td>LATE PAYMENT PENALTY</td><td></td><td></td></tr>' +
    '<tr class="subtotal-row"><td></td><td class="r">Sub total:</td><td class="r">₱ -</td></tr>' +
  '</tbody></table>' +

/* ── TOTAL AMOUNT DUE ── */
'<table>' +
  '<tbody>' +
    '<tr class="total-due"><td colspan="2" class="r" style="border:2px solid #000;">TOTAL AMOUNT DUE</td><td class="r" style="border:2px solid #000;font-size:14px;">₱ ' + totalAmountDue.toLocaleString('en-PH', {minimumFractionDigits:2}) + '</td></tr>' +
  '</tbody></table>' +

/* ── FOOTER ── */
'<div class="soa-footer">' +
  '<p>Please inform us of any discrepancy in the contents of your Statement of Account within 5 days from receipt. Otherwise, Bluebookers Corp. will deem the statement true and correct.</p>' +
  '<p>For inquiries, don\'t hesitate to contact us.</p>' +
  '<p><strong>DUE DATE:</strong> _____________________</p>' +
  '<br>' +
  '<p>Please Make Check Payable to</p>' +
  '<p><em>This is a computer generated statement no need for signature.</em></p>' +
  '<div class="note">' +
    '<p><u>NOTE:</u></p>' +
    '<p>BANK ACCT. #: 010218004738</p>' +
    '<p>BANK NAME: # BANCO DE ORO ( BDO )</p>' +
  '</div>' +
'</div>' +

/* ── SIGNATURE AREA ── */
'<div class="sig-area">' +
  '<div class="sig-block"><div class="sig-line">PREPARED BY: ___________________</div></div>' +
  '<div class="sig-block"><div class="sig-line">RECEIVED BY: ___________________<br>DATE: ___________________</div></div>' +
'</div>' +

'<script>setTimeout(function(){window.print()},500);<\/script>' +
'</body></html>');
            w.document.close();
        }).catch(function(){ alert('Error loading statement.'); });
}

// ── Settings ─────────────────────────────────────────────────────────
function loadSettings() {
    fetch(API + '?action=get_settings&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            var s = d.settings || {};
            document.getElementById('setCutoff').value = s.billing_cutoff_time || '17:00';
            document.getElementById('setLatePenalty').value = s.late_penalty_percent || '0';
        });
}

document.getElementById('saveSettingsBtn').addEventListener('click', function(){
    var settings = [
        { key: 'billing_cutoff_time', value: document.getElementById('setCutoff').value },
        { key: 'late_penalty_percent', value: document.getElementById('setLatePenalty').value },
    ];
    var remaining = settings.length;
    settings.forEach(function(s){
        var fd = new FormData();
        fd.append('action', 'save_setting');
        fd.append('branch', BRANCH);
        fd.append('setting_key', s.key);
        fd.append('setting_value', s.value);
        fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(){
            remaining--;
            if (remaining === 0) alert('Settings saved.');
        });
    });
});

// ── Utility Rates ────────────────────────────────────────────────────
function loadRates() {
    var body = document.getElementById('ratesBody');
    fetch(API + '?action=get_utility_rates&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            var rates = d.rates || [];
            if (!rates.length) { body.innerHTML = '<tr><td colspan="4" class="no-data">No rates configured.</td></tr>'; return; }
            body.innerHTML = rates.map(function(r){
                return '<tr><td><strong>'+esc(r.utility_type)+'</strong></td><td class="r">'+parseFloat(r.rate_per_unit).toFixed(4)+'</td><td>'+esc(r.unit_label)+'</td><td></td></tr>';
            }).join('');
        });
}

document.getElementById('saveRateBtn').addEventListener('click', function(){
    var err = document.getElementById('rateErr'); err.style.display = 'none';
    var type  = document.getElementById('rateType').value;
    var rate  = parseFloat(document.getElementById('rateValue').value);
    var unit  = document.getElementById('rateUnit').value;
    if (!rate || rate <= 0) { err.textContent = 'Enter a valid rate.'; err.style.display = 'block'; return; }
    var fd = new FormData();
    fd.append('action', 'save_utility_rate');
    fd.append('branch', BRANCH);
    fd.append('utility_type', type);
    fd.append('rate_per_unit', rate);
    fd.append('unit_label', unit);
    fetch(API, {method:'POST', body:fd}).then(r=>r.json()).then(function(d){
        if (!d.success) { err.textContent = d.message; err.style.display = 'block'; return; }
        loadRates();
    });
});

// ── Audit Log ────────────────────────────────────────────────────────
function loadAuditLog() {
    var body = document.getElementById('auditBody');
    fetch(API + '?action=get_audit_log&branch=' + BRANCH)
        .then(r=>r.json()).then(function(d){
            var logs = d.logs || [];
            if (!logs.length) { body.innerHTML = '<tr><td colspan="4" class="no-data">No billing actions logged yet.</td></tr>'; return; }
            body.innerHTML = logs.map(function(l){
                return '<tr><td style="white-space:nowrap">'+esc(l.created_at)+'</td><td><strong>'+esc(l.action)+'</strong></td><td style="font-size:.8rem;color:var(--blue-500)">'+esc(l.details)+'</td><td>'+esc(l.performer_name)+'</td></tr>';
            }).join('');
        });
}

// ── Init: set defaults and load first tab ────────────────────────────
document.getElementById('rpDate').value = new Date().toISOString().split('T')[0];
document.getElementById('genPeriod').value = new Date().toISOString().slice(0,7);
loadSummary();
loadUtilCharges();
</script>
</body>
</html>