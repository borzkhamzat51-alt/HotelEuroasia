<?php
/**
 * admin/includes/navbar.php
 * Reusable admin navigation – include this on every admin page.
 * Self‑contained with embedded 3D styles – no external CSS needed for the navbar.
 */
$currentFile = basename($_SERVER['PHP_SELF']);
?>

<style>
/* ─── NAVBAR (Minimized hover animation) ──────────────────────────── */
.navbar {
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(12px) saturate(150%);
  -webkit-backdrop-filter: blur(12px) saturate(150%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.3);
  display: flex;
  justify-content: center;
  gap: 4px;
  padding: 8px 16px;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
  perspective: 800px;
  transform-style: preserve-3d;
}

.navbar__item {
  background: transparent;
  border: none;
  border-radius: 10px;
  color: var(--gray-700, #4a5b6b);
  font-family: 'Inter', sans-serif;
  font-size: 0.86rem;
  font-weight: 500;
  letter-spacing: 0.01em;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  text-decoration: none;
  position: relative;
  flex-shrink: 0;
  transition: all 250ms cubic-bezier(.16, 1, .3, 1);
  transform-style: preserve-3d;
  transform: translateZ(0);
}

.navbar__item svg {
  width: 14px;
  height: 14px;
  opacity: 0.5;
  transition: opacity 250ms cubic-bezier(.16, 1, .3, 1);
}

/* ─── HOVER – Subtle lift ────────────────────────────────────────── */
.navbar__item:hover {
  background: rgba(59, 125, 216, 0.08);
  color: var(--blue-700, #2861b3);
  transform: translateY(-2px) translateZ(4px);
}

.navbar__item:hover svg {
  opacity: 1;
}

/* ─── ACTIVE – Slightly pressed ──────────────────────────────────── */
.navbar__item--active {
  background: rgba(59, 125, 216, 0.12);
  color: var(--blue-700, #2861b3);
  font-weight: 500;
  transform: translateZ(-2px);
}

.navbar__item--active::after {
  content: '';
  position: absolute;
  bottom: 4px;
  left: 50%;
  transform: translateX(-50%);
  width: 12px;
  height: 2px;
  border-radius: 2px;
  background: var(--blue-500, #3b7dd8);
  transition: width 250ms cubic-bezier(.16, 1, .3, 1);
}

.navbar__item--active svg {
  opacity: 1;
}

/* ─── CLICK FEEDBACK ──────────────────────────────────────────────── */
.navbar__item:active {
  transform: scale(0.97);
  transition-duration: 80ms;
}

/* ─── SETTINGS DROPDOWN ───────────────────────────────────────────── */
.navbar__dropdown {
  position: relative;
  flex-shrink: 0;
}

.navbar__item--dropdown {
  font-family: inherit;
}
.navbar__item--dropdown svg:last-child {
  transition: transform 250ms cubic-bezier(.16, 1, .3, 1);
}
.navbar__item--dropdown[aria-expanded="true"] svg:last-child {
  transform: rotate(180deg);
}

.navbar__dropdown-menu {
  position: absolute;
  top: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%) translateY(-4px) scale(0.96);
  min-width: 210px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(12px) saturate(150%);
  -webkit-backdrop-filter: blur(12px) saturate(150%);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 12px;
  box-shadow: 0 16px 48px -12px rgba(20, 42, 82, 0.15);
  padding: 6px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: all 250ms cubic-bezier(.16, 1, .3, 1);
  z-index: 200;
}

.navbar__dropdown.is-open .navbar__dropdown-menu {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateX(-50%) translateY(0) scale(1);
}

.navbar__dropdown-menu a {
  display: block;
  padding: 10px 16px;
  border-radius: 8px;
  color: var(--gray-700, #4a5b6b);
  text-decoration: none;
  font-size: 0.85rem;
  font-weight: 500;
  transition: all 180ms cubic-bezier(.16, 1, .3, 1);
  background: transparent;
}

.navbar__dropdown-menu a:hover {
  background: rgba(59, 125, 216, 0.08);
  color: var(--blue-700, #2861b3);
}

.navbar__dropdown-menu .divider {
  height: 1px;
  background: rgba(0,0,0,0.06);
  margin: 4px 8px;
}

/* ─── RESPONSIVE – Mobile ─────────────────────────────────────────── */
@media (max-width: 720px) {
  .navbar {
    flex-direction: column;
    max-height: 0;
    overflow: hidden;
    transition: max-height 280ms cubic-bezier(.16, 1, .3, 1);
    padding: 0;
    background: var(--white, #ffffff);
    backdrop-filter: none;
    perspective: none;
    box-shadow: none;
    border-bottom: none;
  }
  .navbar.is-open { max-height: 420px; }

  .navbar__item {
    width: 100%;
    justify-content: flex-start;
    padding: 14px 24px;
    border-radius: 0;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    flex-shrink: 1;
    transform: none !important;
  }
  .navbar__item:hover {
    transform: none !important;
    background: rgba(59, 125, 216, 0.06) !important;
  }
  .navbar__item:active {
    transform: none !important;
  }
  .navbar__item--active {
    transform: none !important;
    background: rgba(59, 125, 216, 0.1) !important;
  }
  .navbar__item--active::after {
    bottom: 10px;
    width: 12px;
    height: 2px;
  }

  .navbar__dropdown-menu {
    position: static;
    transform: none !important;
    box-shadow: none;
    border: none;
    border-radius: 0;
    background: rgba(59, 125, 216, 0.04);
    padding: 4px 0 4px 24px;
    backdrop-filter: none;
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transition: none;
    min-width: auto;
    width: 100%;
  }
  .navbar__dropdown.is-open .navbar__dropdown-menu {
    transform: none !important;
  }
  .navbar__dropdown-menu a {
    padding: 10px 16px;
    border-radius: 6px;
  }
}
</style>

<nav class="navbar" id="navbar">

    <?php if (bb_has_permission('dashboard')): ?>
        <a class="navbar__item <?= $currentFile === 'dashboard.php' ? 'navbar__item--active' : '' ?>" href="dashboard.php">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Dashboard
        </a>
    <?php endif; ?>

    <?php if (bb_is_admin()): ?>
        <a class="navbar__item <?= $currentFile === 'audit.php' ? 'navbar__item--active' : '' ?>" href="audit.php">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            Audit Log
        </a>
    <?php endif; ?>

    <?php if (bb_has_permission('guests')): ?>
        <a class="navbar__item <?= $currentFile === 'guests.php' ? 'navbar__item--active' : '' ?>" href="guests.php">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-4c0-1.104-.396-2.105-1.05-2.85M7 20H2v-2a3 3 0 015.356-1.857M7 20v-4c0-1.104.396-2.105 1.05-2.85M8 6a4 4 0 118 0 4 4 0 01-8 0z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Guests
        </a>
    <?php endif; ?>

    <?php if (bb_has_permission('reports')): ?>
        <a class="navbar__item <?= $currentFile === 'reports.php' ? 'navbar__item--active' : '' ?>" href="reports.php">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Reports
        </a>
    <?php endif; ?>

    <?php if (bb_is_admin()): ?>
        <a class="navbar__item <?= $currentFile === 'users.php' ? 'navbar__item--active' : '' ?>" href="users.php">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Users &amp; Staff
        </a>
    <?php endif; ?>

    <?php if (bb_is_admin() || bb_has_permission('settings')): ?>
    <div class="navbar__dropdown" id="settingsDropdown">
        <button type="button" class="navbar__item navbar__item--dropdown" id="settingsToggle" aria-haspopup="true" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
            Settings
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="navbar__dropdown-menu" id="settingsMenu">
            <?php if (bb_is_admin()): ?>
                <a href="register-user.php">Register Staff</a>
                <a href="register-admin.php">Register Admin</a>
                <div class="divider"></div>
            <?php endif; ?>
            <a href="../profile.php">Account Settings</a>
        </div>
    </div>
    <?php endif; ?>

</nav>