<?php
require_once __DIR__ . '/config.php';

// Already logged in? Skip straight to wherever this role belongs.
if (bb_is_logged_in()) {
    bb_redirect(bb_role_home());
}

// Simple CSRF token for the login form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In · Bluebookers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=EB+Garamond:ital@1&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<main class="login-page">

    <!-- ===================== LEFT: BRAND PANEL ===================== -->
    <section class="brand-panel" data-animate>

        <div class="brand-logo" data-animate-item style="--d:0">
            <span>LOGO</span>
        </div>

        <h1 class="brand-name" data-animate-item style="--d:1">Bluebookers</h1>

        <div class="ornament" data-animate-item style="--d:2">
            <span class="ornament__line"></span>
            <svg class="ornament__glyph" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 21c0-5 3.5-8.5 8-9-1 5-3.5 8-8 9Z" fill="currentColor"/>
                <path d="M12 21c0-5-3.5-8.5-8-9 1 5 3.5 8 8 9Z" fill="currentColor"/>
                <path d="M12 21c0-6 0-11 0-15" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                <path d="M12 6c-2.2 0-4-1.6-4-4 2.2 0 4 1.6 4 4Z" fill="currentColor"/>
                <path d="M12 6c2.2 0 4-1.6 4-4-2.2 0-4 1.6-4 4Z" fill="currentColor"/>
            </svg>
            <span class="ornament__line"></span>
        </div>

        <p class="brand-tagline" data-animate-item style="--d:3">Luxury &middot; Comfort &middot; Excellence</p>

        <blockquote class="brand-quote" data-animate-item style="--d:4">
            &ldquo;Every stay should feel like the best room in the house
            was saved just for you.&rdquo;
        </blockquote>

        <ul class="feature-list" data-animate-item style="--d:5">
            <li class="feature-list__item">
                <span class="feature-list__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 9h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </span>
                <span>Easy Reservations</span>
            </li>
            <li class="feature-list__item">
                <span class="feature-list__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" stroke="currentColor" stroke-width="1.5"/><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span>Secure Payments</span>
            </li>
            <li class="feature-list__item">
                <span class="feature-list__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M4 13a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><rect x="3" y="13" width="4" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5" stroke="currentColor" stroke-width="1.5"/></svg>
                </span>
                <span>24/7 Customer Support</span>
            </li>
        </ul>

    </section>

    <!-- ===================== RIGHT: LOGIN CARD ===================== -->
    <section class="card-wrap" data-animate-card>
        <form class="login-card" id="loginForm" novalidate autocomplete="off">

            <h2 class="login-card__title">Log In</h2>

            <div class="ornament ornament--small">
                <span class="ornament__line"></span>
                <svg class="ornament__glyph" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 21c0-5 3.5-8.5 8-9-1 5-3.5 8-8 9Z" fill="currentColor"/>
                    <path d="M12 21c0-5-3.5-8.5-8-9 1 5 3.5 8 8 9Z" fill="currentColor"/>
                    <path d="M12 21c0-6 0-11 0-15" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                <span class="ornament__line"></span>
            </div>

            <p class="form-alert" id="formAlert" role="alert" hidden></p>

            <div class="field">
                <label for="username">Username</label>
                <div class="field__control">
                    <svg class="field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 20c1.2-3.5 4-5.5 7-5.5s5.8 2 7 5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
                <span class="field__error" id="usernameError"></span>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="field__control">
                    <svg class="field__icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.5"/></svg>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <button type="button" class="field__toggle" id="togglePassword" aria-label="Show password">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/></svg>
                    </button>
                </div>
                <span class="field__error" id="passwordError"></span>
                <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn btn--primary" id="loginBtn">
                <span class="btn__label">Log In</span>
                <span class="btn__spinner" aria-hidden="true"></span>
            </button>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        </form>
    </section>

</main>

<script src="assets/js/script.js" defer></script>
</body>
</html>