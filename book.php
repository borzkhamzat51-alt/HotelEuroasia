<?php
require_once __DIR__ . '/config.php';

if (!bb_is_logged_in()) {
    bb_redirect('index.php');
}
if (bb_is_admin()) {
    bb_redirect('admin/dashboard.php');
}

$room = $_GET['room'] ?? '';
$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reserve Room <?= htmlspecialchars($room) ?> · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/property.css">
<link rel="stylesheet" href="assets/css/account.css">
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="javascript:history.back()" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Back
    </a>
    <nav class="ptopbar__breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">Properties</a>
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span aria-current="page">Reserve Room <?= htmlspecialchars($room) ?></span>
    </nav>
    <a href="logout.php" class="ptopbar__logout">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 16l4-4-4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <span>Log out</span>
    </a>
</header>

<main class="property-main">
    <div class="property-heading" data-animate-item style="--d:0">
        <p class="property-heading__eyebrow">Booking</p>
        <h1 class="property-heading__title">Reserve Room <?= htmlspecialchars($room) ?></h1>
    </div>

    <div class="account-panel account-panel--centered" style="max-width:480px;">
        <p><strong>Hi <?= htmlspecialchars($displayName) ?> 👋</strong></p>
        <p>
            The reservation form (dates, payment, confirmation) goes here next —
            this page is wired up and guarded for the <strong>user</strong> role only,
            ready for that to be built in.
        </p>
        <a href="my-bookings.php" class="btn btn--primary account-btn">View My Bookings</a>
    </div>
</main>

</body>
</html>
