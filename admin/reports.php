<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
bb_require_permission('reports');
$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports &amp; Analytics · Bluebookers Admin</title>
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
.reports-header { margin-bottom: 28px; }
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
.coming-soon {
    background: var(--white);
    border: 1px solid var(--blue-100);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
    padding: 48px 32px;
    text-align: center;
    color: var(--blue-500);
}
.coming-soon__icon { font-size: 2.5rem; margin-bottom: 16px; }
.coming-soon h2 { font-family: var(--font-serif); color: var(--blue-900); margin: 0 0 8px; font-size: 1.3rem; }
.coming-soon p { margin: 0; font-size: 0.88rem; }
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
<?php include __DIR__ . '/includes/navbar.php'; ?>

<!-- ── MAIN ────────────────────────────────────────────────────── -->
<main class="reports-main">
    <div class="reports-header">
        <h1 class="reports-header__title">Reports &amp; Analytics</h1>
        <p class="reports-header__sub">Occupancy trends, revenue, and operational summaries.</p>
    </div>

    <div class="coming-soon">
        <div class="coming-soon__icon">📊</div>
        <h2>Coming Soon</h2>
        <p>Occupancy trends, revenue breakdowns, and operational reports will appear here.</p>
    </div>
</main>

<script src="../assets/js/dashboard.js" defer></script>
</body>
</html>