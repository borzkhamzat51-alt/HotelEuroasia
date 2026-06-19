<?php
require_once __DIR__ . '/../config.php';
bb_require_admin();
$displayName = $_SESSION['full_name'] ?: $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings · Bluebookers Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/property.css">
<link rel="stylesheet" href="../assets/css/account.css">
</head>
<body class="property-body">

<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Dashboard
    </a>
    <div class="ptopbar__breadcrumb">
        <span aria-current="page">System Settings</span>
    </div>
    <a href="../logout.php" class="ptopbar__logout">Log out</a>
</header>

<main class="property-main" style="max-width:640px; margin:0 auto;">
    <div class="property-heading">
        <p class="property-heading__eyebrow">Admin only</p>
        <h1 class="property-heading__title">System Settings</h1>
    </div>

    <div class="account-panel account-panel--centered">
        <p>Hotel-wide configuration (branches, room types, pricing rules, notification settings) goes here next.</p>
    </div>
</main>

</body>
</html>
