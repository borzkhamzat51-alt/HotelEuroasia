<?php
require_once __DIR__ . '/config.php';

if (!bb_is_logged_in()) {
    bb_redirect('index.php');
}
if (bb_is_admin()) {
    bb_redirect('admin/dashboard.php');
}

$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Bookings · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/property.css">
<link rel="stylesheet" href="assets/css/account.css">
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="dashboard.php" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <nav class="ptopbar__breadcrumb" aria-label="Breadcrumb">
        <span aria-current="page">My Bookings</span>
    </nav>
    <a href="logout.php" class="ptopbar__logout">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span>Log out</span>
    </a>
</header>

<main class="property-main">
    <div class="property-heading" data-animate-item style="--d:0">
        <p class="property-heading__eyebrow">Your stays</p>
        <h1 class="property-heading__title">My Bookings</h1>
    </div>

    <div class="account-panel account-panel--centered" style="max-width:560px;">
        <p><strong>No bookings yet, <?= htmlspecialchars($displayName) ?>.</strong></p>
        <p>
            Once the reservation flow is built out, your upcoming and past
            stays — with check-in/out dates and status — will show up here.
            This page is already guarded so only you can see your own bookings.
        </p>
        <a href="dashboard.php" class="btn btn--primary account-btn">Browse Properties</a>
    </div>
</main>

</body>
</html>
