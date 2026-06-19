# Bluebookers — Role-Based Access Control (MySQL / XAMPP)

## Setup
1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Import the database: open phpMyAdmin (XAMPP Control Panel -> Admin),
   go to the **Import** tab, and choose `schema.sql`. This creates the
   `bluebookers` database, the `users` table, and one seeded admin
   account (`admin` / `Admin@123`).
3. Copy this project folder into `htdocs` (e.g.
   `C:\xampp\htdocs\bluebookers` on Windows, `/Applications/XAMPP/htdocs/bluebookers`
   on Mac, `/opt/lampp/htdocs/bluebookers` on Linux).
4. `cp .env.example .env` — the defaults already match a stock XAMPP
   install (`root` user, no password), so you usually don't need to
   change anything.
5. Visit `http://localhost/bluebookers/` in your browser.

## The session contract
Set once, in `process_login.php`, right after the password check —
never trust a role coming from anywhere else (a hidden form field, a
query string, etc.):
```
$_SESSION['logged_in']  // bool
$_SESSION['user_id']
$_SESSION['username']
$_SESSION['full_name']
$_SESSION['role']       // 'admin' | 'user' — straight from the users table
```

## The guard functions (config.php)
```php
bb_is_logged_in() / bb_is_admin() / bb_is_user()
bb_current_role()       // 'admin' | 'user' | null
bb_role_home()          // /admin/dashboard.php or /dashboard.php
bb_require_login()      // not logged in -> /index.php
bb_require_admin()      // not logged in -> /index.php · wrong role -> /access-denied.php
```
Every protected page starts with one line:
```php
require_once __DIR__ . '/config.php';
bb_require_admin();
```
`admin/process_room_action.php` is the one exception — it's a JSON
endpoint, so it checks `bb_is_admin()` itself and returns a plain `403`
instead of redirecting (a `fetch()` call expecting JSON would choke
trying to parse a redirected HTML page).

## Route map
| Route | Who | What happens otherwise |
|---|---|---|
| `/index.php` | anyone | logged-in users are bounced to their own home |
| `/register.php` | anyone | creates a `role=user` account — no UI path creates an admin |
| `/dashboard.php`, `/rooms.php`, `/book.php`, `/my-bookings.php`, `/profile.php` | **user** | admin -> their own dashboard; not logged in -> login |
| `/admin/dashboard.php`, `/admin/property.php`, `/admin/layout*.php` | **admin** | wrong role -> `/access-denied.php` |
| `/admin/reservations.php`, `/admin/reports.php`, `/admin/users.php`, `/admin/settings.php` | **admin** | same — guard + nav are real, features are flagged next steps |
| `/admin/process_room_action.php` | **admin** | non-admin gets `403` JSON |

## Accounts
One table, `users` (see `schema.sql`) — `role` is the column everything
hinges on. Passwords are stored as bcrypt hashes via PHP's
`password_hash()` / verified with `password_verify()`, never in plain
text. Self-registration through `register.php` always inserts
`role='user'`. To create another admin, insert a row directly (see the
commented example at the bottom of `schema.sql`) — there's no signup
path that can do it.

## File map
```
index.php                  ← login page
process_login.php          ← validates the POST, queries users, starts the session
process_register.php       ← creates a new role='user' account
register.php
db.php                     ← every SQL query lives here, behind named functions
config.php                  ← .env loader, PDO connection, session bootstrap, RBAC helpers
logout.php
dashboard.php               ← USER dashboard
rooms.php / book.php / my-bookings.php / profile.php   ← user-only pages
access-denied.php
admin/dashboard.php         ← ADMIN dashboard
admin/property.php, admin/layout*.php, admin/process_room_action.php
admin/reservations.php, admin/reports.php, admin/users.php, admin/settings.php
schema.sql                  ← import this in phpMyAdmin once
.env.example                ← copy to .env
assets/
```

## Property images
`dashboard.php` and `admin/dashboard.php` try
`assets/images/properties/{annex,mtv,dormitel}.jpg` first; since those
don't exist yet, they fall back to a small original SVG illustration in
your actual blue palette (not a stock photo). Drop real photos in at
those same `.jpg` paths whenever you have them — no code change needed.

## Honest gaps (next steps, not silently skipped)
- `admin/reservations.php`, `admin/reports.php`, `admin/users.php`,
  `admin/settings.php`, `book.php`, and `my-bookings.php` are guarded
  and routed correctly, but the features behind them are placeholders.
- Room data is still hardcoded PHP arrays rather than a `rooms` table.
  Natural next step: one MySQL table, admin and guest views both
  reading from it with different columns selected.
- Individual room cards don't have photos/borders — that layout is
  tuned pretty precisely for the floor-blueprint grid, so I held off
  without checking first.
