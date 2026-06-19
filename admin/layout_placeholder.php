<?php
// layout_placeholder.php
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Layout · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/property.css"><link rel="stylesheet" href="../assets/css/account.css">
</head>
<body class="property-body">
<header class="ptopbar">
    <a href="<?= bb_role_home() ?>" class="ptopbar__back"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg> Dashboard</a>
    <div class="ptopbar__breadcrumb"><span aria-current="page">Floor Plan</span></div>
    <a href="../logout.php" class="ptopbar__logout">Log out</a>
</header>
<main class="property-main" style="max-width:600px; margin:0 auto;">
    <div class="account-panel account-panel--centered" style="margin-top:40px;">
        <p style="font-size:1.2rem; font-weight:600; margin-bottom:0.5rem;">🚧 Layout Not Available</p>
        <p style="color:var(--ink-500);">The floor plan is currently only available for <strong>MTV3</strong>.</p>
        <a href="dashboard.php" class="btn btn--primary account-btn" style="display:inline-flex; align-items:center; gap:8px; width:auto; padding:12px 28px; text-decoration:none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px; height:18px;"><path d="m15 18-6-6 6-6"/></svg>
            Back to Dashboard
        </a>
    </div>
</main>
</body>
</html>