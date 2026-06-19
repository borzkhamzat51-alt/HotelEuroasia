<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Access Denied · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/property.css">
<link rel="stylesheet" href="assets/css/account.css">
</head>
<body>
<main class="access-denied-page">
  <div class="access-denied-page__icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10.3 3.9 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
  </div>
  <h1>Access Denied</h1>
  <p>
    <?php if (bb_is_logged_in()): ?>
      Your account doesn't have permission to view that page.
    <?php else: ?>
      You need to log in to view that page.
    <?php endif; ?>
  </p>
  <a href="<?= htmlspecialchars(bb_role_home()) ?>" class="btn btn--primary account-btn">
    <?= bb_is_logged_in() ? 'Back to my dashboard' : 'Go to login' ?>
  </a>
</main>
</body>
</html>
