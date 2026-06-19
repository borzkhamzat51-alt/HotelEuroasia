<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_admin(); // Audit log is admin-only

// ── Filters ──────────────────────────────────────────────────────────────────
$search      = trim($_GET['search']      ?? '');
$filterUser  = (int) ($_GET['user_id']   ?? 0);
$filterType  = $_GET['target_type']      ?? '';
$filterAction= $_GET['action']           ?? '';
$dateFrom    = $_GET['date_from']        ?? '';
$dateTo      = $_GET['date_to']          ?? '';
$page        = max(1, (int) ($_GET['page'] ?? 1));
$perPage     = 50;
$offset      = ($page - 1) * $perPage;

$filters = array_filter([
    'search'      => $search      ?: null,
    'user_id'     => $filterUser  ?: null,
    'target_type' => $filterType  ?: null,
    'action'      => $filterAction?: null,
    'date_from'   => $dateFrom    ?: null,
    'date_to'     => $dateTo      ?: null,
    'limit'       => $perPage,
    'offset'      => $offset,
], fn($v) => $v !== null);

$logs      = db_list_audit_log($filters);
$totalRows = db_count_audit_log(array_diff_key($filters, ['limit' => 1, 'offset' => 1]));
$totalPages= max(1, (int) ceil($totalRows / $perPage));

// Users who have ever been logged — for the filter dropdown
$auditUsers = db_list_audit_users();

// ── Action label map ──────────────────────────────────────────────────────────
$actionLabels = [
    'auth.login'                => 'Logged in',
    'auth.logout'               => 'Logged out',
    'user.create_staff'         => 'Created staff account',
    'user.create_admin'         => 'Created admin account',
    'user.delete'               => 'Deleted account',
    'profile.update'            => 'Updated profile',
    'profile.password_change'   => 'Changed password',
    'reservation.create'        => 'Created reservation',
    'reservation.update'        => 'Updated reservation',
    'reservation.delete'        => 'Deleted reservation',
    'room.check_in'             => 'Checked guest in',
    'room.check_out'            => 'Checked guest out',
    'room.set_maintenance'      => 'Set room to maintenance',
    'room.clear_maintenance'    => 'Cleared room maintenance',
];

$actionCategories = [
    'auth'        => ['auth.login', 'auth.logout'],
    'user'        => ['user.create_staff', 'user.create_admin', 'user.delete'],
    'profile'     => ['profile.update', 'profile.password_change'],
    'reservation' => ['reservation.create', 'reservation.update', 'reservation.delete'],
    'room'        => ['room.check_in', 'room.check_out', 'room.set_maintenance', 'room.clear_maintenance'],
];

function action_badge_class($action) {
    if (str_starts_with($action, 'auth'))        return 'badge--blue';
    if (str_starts_with($action, 'user.delete')) return 'badge--red';
    if (str_starts_with($action, 'user'))        return 'badge--purple';
    if (str_starts_with($action, 'profile'))     return 'badge--gray';
    if ($action === 'reservation.delete')        return 'badge--red';
    if (str_starts_with($action, 'reservation')) return 'badge--green';
    if (str_contains($action, 'maintenance'))    return 'badge--amber';
    if (str_starts_with($action, 'room'))        return 'badge--teal';
    return 'badge--gray';
}

function format_action($action, $labels) {
    return $labels[$action] ?? ucwords(str_replace(['.', '_'], ' ', $action));
}

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];

// ── Query string helper (preserves all filters except changed key) ───────────
function qs($overrides = []) {
    $params = array_merge([
        'search'      => $_GET['search']      ?? '',
        'user_id'     => $_GET['user_id']     ?? '',
        'target_type' => $_GET['target_type'] ?? '',
        'action'      => $_GET['action']      ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'page'        => $_GET['page']        ?? 1,
    ], $overrides);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== '0' && $v !== 0));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<style>
/* ── Audit page layout ───────────────────────────────────────────── */
.audit-main {
    flex: 1;
    padding: clamp(20px, 4vw, 48px) clamp(16px, 5vw, 56px);
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
    box-sizing: border-box;
}

.audit-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.audit-header__title {
    font-family: var(--font-serif);
    font-size: clamp(1.4rem, 3vw, 2rem);
    font-weight: 700;
    color: var(--blue-900);
    margin: 0;
}
.audit-header__sub {
    font-size: 0.82rem;
    color: var(--blue-500);
    margin: 4px 0 0;
}

/* ── Filter bar ─────────────────────────────────────────────────── */
.filter-bar {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
    box-shadow: var(--shadow-card);
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
}
.filter-group label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--blue-500);
}
.filter-group input,
.filter-group select {
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-sm);
    padding: 7px 10px;
    font-size: 0.83rem;
    font-family: var(--font-sans);
    color: var(--blue-900);
    background: var(--blue-50);
    outline: none;
    transition: border-color 150ms;
}
.filter-group input:focus,
.filter-group select:focus {
    border-color: var(--blue-500);
    background: var(--white);
}
.filter-group--search { flex: 1; min-width: 200px; }

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}
.btn-filter {
    padding: 7px 18px;
    border-radius: var(--radius-sm);
    font-size: 0.83rem;
    font-weight: 600;
    font-family: var(--font-sans);
    cursor: pointer;
    border: none;
    transition: opacity 150ms;
}
.btn-filter--apply  { background: var(--blue-500); color: var(--white); }
.btn-filter--reset  { background: var(--blue-100); color: var(--blue-900); }
.btn-filter:hover   { opacity: 0.85; }

/* ── Summary strip ────────────────────────────────────────────── */
.audit-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    gap: 12px;
    flex-wrap: wrap;
}
.audit-meta__count {
    font-size: 0.82rem;
    color: var(--blue-500);
}
.audit-meta__count strong { color: var(--blue-900); }

/* ── Table ───────────────────────────────────────────────────── */
.audit-card {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    overflow: hidden;
}
.audit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.83rem;
}
.audit-table th {
    background: var(--blue-50);
    color: var(--blue-500);
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid var(--blue-100);
    white-space: nowrap;
}
.audit-table td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--blue-50);
    vertical-align: middle;
    color: var(--blue-900);
}
.audit-table tr:last-child td { border-bottom: none; }
.audit-table tr:hover td { background: var(--blue-50); }

.audit-table .col-time   { white-space: nowrap; width: 140px; }
.audit-table .col-user   { width: 160px; }
.audit-table .col-action { width: 200px; }
.audit-table .col-target { width: 180px; }
.audit-table .col-detail { }
.audit-table .col-ip     { white-space: nowrap; width: 110px; font-family: monospace; font-size: 0.75rem; color: var(--blue-500); }

.time-primary { font-weight: 600; color: var(--blue-900); }
.time-sub     { font-size: 0.72rem; color: var(--blue-500); margin-top: 2px; }

.user-name  { font-weight: 600; }
.user-role  { font-size: 0.71rem; color: var(--blue-500); text-transform: uppercase; letter-spacing: 0.04em; }

.target-type { font-size: 0.71rem; color: var(--blue-500); text-transform: uppercase; letter-spacing: 0.04em; }
.target-label { font-weight: 500; }

.details-text { color: var(--blue-500); font-size: 0.78rem; word-break: break-word; max-width: 260px; }

/* ── Badges ──────────────────────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 0.71rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.badge--blue   { background: #dbeafe; color: #1d4ed8; }
.badge--green  { background: #d1fae5; color: #065f46; }
.badge--red    { background: #fee2e2; color: #b91c1c; }
.badge--purple { background: #ede9fe; color: #6d28d9; }
.badge--amber  { background: #fef3c7; color: #92400e; }
.badge--teal   { background: #ccfbf1; color: #0f766e; }
.badge--gray   { background: #f1f5f9; color: #475569; }

/* ── Empty state ───────────────────────────────────────────────── */
.audit-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--blue-500);
}
.audit-empty__icon {
    font-size: 2.5rem;
    margin-bottom: 12px;
    opacity: 0.4;
}
.audit-empty__text { font-size: 0.9rem; }

/* ── Pagination ────────────────────────────────────────────────── */
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 16px 0 4px;
    flex-wrap: wrap;
}
.page-btn {
    padding: 6px 13px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--blue-100);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    background: var(--white);
    color: var(--blue-900);
    text-decoration: none;
    transition: background 150ms, border-color 150ms;
}
.page-btn:hover           { background: var(--blue-50); border-color: var(--blue-200); }
.page-btn.active          { background: var(--blue-500); color: var(--white); border-color: var(--blue-500); }
.page-btn[disabled]       { opacity: 0.4; pointer-events: none; }

/* ── Navbar active ─────────────────────────────────────────────── */
.navbar__item--active {
    color: var(--blue-900) !important;
    font-weight: 700;
    border-bottom: 2px solid var(--blue-500);
}

@media (max-width: 700px) {
    .audit-table .col-ip,
    .audit-table .col-detail { display: none; }
    .filter-group { min-width: 120px; }
}
</style>
</head>
<body class="dashboard-body">

<!-- ── TOP BAR ─────────────────────────────────────────────────── -->
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
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            <span>Log out</span>
        </a>
        <button class="topbar__menu-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- ── NAV BAR ─────────────────────────────────────────────────── -->
<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- ── MAIN ────────────────────────────────────────────────────── -->
<main class="audit-main">

    <div class="audit-header">
        <div>
            <h1 class="audit-header__title">Audit Log</h1>
            <p class="audit-header__sub">Full trail of every action performed on the system</p>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="audit.php" class="filter-bar">
        <div class="filter-group filter-group--search">
            <label for="search">Search</label>
            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, username, guest, details…">
        </div>

        <div class="filter-group">
            <label for="user_id">User</label>
            <select id="user_id" name="user_id">
                <option value="">All users</option>
                <?php foreach ($auditUsers as $u): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $filterUser === (int)$u['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">All actions</option>
                <?php foreach ($actionCategories as $cat => $actions): ?>
                    <optgroup label="<?= ucfirst($cat) ?>">
                        <?php foreach ($actions as $a): ?>
                            <option value="<?= $a ?>" <?= $filterAction === $a ? 'selected' : '' ?>>
                                <?= format_action($a, $actionLabels) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="target_type">Type</label>
            <select id="target_type" name="target_type">
                <option value="">All types</option>
                <option value="user"        <?= $filterType === 'user'        ? 'selected' : '' ?>>User</option>
                <option value="reservation" <?= $filterType === 'reservation' ? 'selected' : '' ?>>Reservation</option>
                <option value="room"        <?= $filterType === 'room'        ? 'selected' : '' ?>>Room</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>

        <div class="filter-group">
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn-filter btn-filter--apply">Filter</button>
            <a href="audit.php" class="btn-filter btn-filter--reset">Reset</a>
        </div>
    </form>

    <!-- Meta row -->
    <div class="audit-meta">
        <p class="audit-meta__count">
            Showing <strong><?= count($logs) ?></strong> of <strong><?= number_format($totalRows) ?></strong> entries
            <?php if ($page > 1): ?>· page <?= $page ?> of <?= $totalPages ?><?php endif; ?>
        </p>
    </div>

    <!-- Table card -->
    <div class="audit-card">
        <?php if (empty($logs)): ?>
            <div class="audit-empty">
                <div class="audit-empty__icon">📋</div>
                <p class="audit-empty__text">No audit entries match your filters.</p>
            </div>
        <?php else: ?>
        <table class="audit-table">
            <thead>
                <tr>
                    <th class="col-time">Time</th>
                    <th class="col-user">User</th>
                    <th class="col-action">Action</th>
                    <th class="col-target">Target</th>
                    <th class="col-detail">Details</th>
                    <th class="col-ip">IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log):
                $dt = new DateTime($log['created_at']);
                $date = $dt->format('M j, Y');
                $time = $dt->format('g:i A');
                $actionClass = action_badge_class($log['action']);
                $actionLabel = format_action($log['action'], $actionLabels);
            ?>
                <tr>
                    <td class="col-time">
                        <div class="time-primary"><?= $date ?></div>
                        <div class="time-sub"><?= $time ?></div>
                    </td>

                    <td class="col-user">
                        <?php if ($log['full_name'] || $log['username']): ?>
                            <div class="user-name"><?= htmlspecialchars($log['full_name'] ?: $log['username']) ?></div>
                            <?php if ($log['username'] && $log['full_name']): ?>
                                <div class="user-role">@<?= htmlspecialchars($log['username']) ?></div>
                            <?php endif; ?>
                            <?php if ($log['role']): ?>
                                <div class="user-role"><?= $log['role'] ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--blue-300)">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="col-action">
                        <span class="badge <?= $actionClass ?>">
                            <?= htmlspecialchars($actionLabel) ?>
                        </span>
                    </td>

                    <td class="col-target">
                        <?php if ($log['target_label']): ?>
                            <div class="target-label"><?= htmlspecialchars($log['target_label']) ?></div>
                        <?php endif; ?>
                        <?php if ($log['target_type']): ?>
                            <div class="target-type">
                                <?= htmlspecialchars($log['target_type']) ?>
                                <?= $log['target_id'] ? '#' . $log['target_id'] : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$log['target_label'] && !$log['target_type']): ?>
                            <span style="color:var(--blue-300)">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="col-detail">
                        <?php if ($log['details']): ?>
                            <span class="details-text"><?= htmlspecialchars($log['details']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--blue-200)">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="col-ip">
                        <?= $log['ip_address'] ? htmlspecialchars($log['ip_address']) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a class="page-btn" href="<?= qs(['page' => $page - 1]) ?>" <?= $page <= 1 ? 'disabled' : '' ?>>&lsaquo; Prev</a>

            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?><a class="page-btn" href="<?= qs(['page' => 1]) ?>">1</a><?php if ($start > 2): ?><span style="padding:0 4px;color:var(--blue-300)">…</span><?php endif; endif;
            for ($i = $start; $i <= $end; $i++): ?>
                <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= qs(['page' => $i]) ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $totalPages): if ($end < $totalPages - 1): ?><span style="padding:0 4px;color:var(--blue-300)">…</span><?php endif; ?><a class="page-btn" href="<?= qs(['page' => $totalPages]) ?>"><?= $totalPages ?></a><?php endif; ?>

            <a class="page-btn" href="<?= qs(['page' => $page + 1]) ?>" <?= $page >= $totalPages ? 'disabled' : '' ?>>Next &rsaquo;</a>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</main>

<!-- ── FOOTER ──────────────────────────────────────────────────── -->
<footer class="dashboard-footer">
    <p class="dashboard-footer__copy">&copy; <?= date('Y') ?> Bluebookers. All rights reserved.</p>
</footer>

<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>