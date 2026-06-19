-- ============================================================================
-- Bluebookers — MySQL schema
-- Import this via phpMyAdmin (XAMPP Control Panel -> Admin -> phpMyAdmin ->
-- Import tab) or run it through the mysql CLI:
--   mysql -u root -p < schema.sql
--
-- This DROPS and recreates the "bluebookers" database every time you
-- import it, on purpose — so it always works cleanly whether you're
-- starting fresh or you already have an older version of this table
-- (e.g. one without the `permissions` column, which is what caused a
-- "Unknown column 'permissions'" error before this line was added).
-- There's no real production data here yet, so this is safe. Once you
-- have real data, swap this for a proper migration instead.
-- ============================================================================

DROP DATABASE IF EXISTS bluebookers;

CREATE DATABASE IF NOT EXISTS bluebookers
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bluebookers;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  email         VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(150) NULL,
  role          ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
  -- Comma-separated permission keys, e.g. "reports,reservations,guests".
  -- Ignored entirely for role='admin' — admins always have full access.
  permissions   TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One seeded admin account so you can log in right away.
-- Username: admin   Password: Admin@123
INSERT INTO users (username, email, password_hash, full_name, role, permissions)
VALUES (
  'admin',
  'admin@bluebookers.test',
  '$2y$10$liGj0qniuP10FIi9EH3wuOgN0.82W5E2qDP3ggmUZajSfVyjvxYeO',
  'Hotel Admin',
  'admin',
  NULL
)
ON DUPLICATE KEY UPDATE username = username; -- re-running this file won't error or duplicate

-- One seeded demo staff account with PARTIAL access, so you can test
-- permission enforcement immediately: this one can only reach the
-- Dashboard, Reservations, and Guests pages — Reports, Rooms, Billing,
-- and Settings are all hidden/blocked for them.
-- Username: frontdesk   Password: FrontDesk@123
INSERT INTO users (username, email, password_hash, full_name, role, permissions)
VALUES (
  'frontdesk',
  'frontdesk@bluebookers.test',
  '$2y$10$lr7Ts3cRK.EWq/9qsG6lUOql8n0N89P7SNZldNH0cfEPxsF3PfBri',
  'Front Desk Staff',
  'staff',
  'dashboard,reservations,guests'
)
ON DUPLICATE KEY UPDATE username = username;

-- There is no self-service signup anywhere in this app on purpose.
-- New accounts are only ever created by an admin, from
-- admin/register-user.php (role=staff, with a permission checklist) or
-- admin/register-admin.php (role=admin, automatic full access).


-- ============================================================================
-- Calendar / Reservations module
-- ============================================================================

CREATE TABLE IF NOT EXISTS rooms (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  branch             VARCHAR(50) NOT NULL,
  room_number        VARCHAR(20) NOT NULL,
  room_type          VARCHAR(100) NOT NULL,
  price_per_night    DECIMAL(10,2) NOT NULL DEFAULT 0,
  -- Day-to-day operational state shown on the floor-plan console. This is
  -- deliberately separate from reservations.status (which tracks a single
  -- booking's lifecycle) — a room's physical state (e.g. under maintenance)
  -- isn't a booking, and trying to store both in one ENUM is what caused
  -- "Data truncated for column 'status'" errors before this column existed.
  room_status        ENUM('available','occupied','reserved','maintenance') NOT NULL DEFAULT 'available',
  cleaning_status     VARCHAR(40) NOT NULL DEFAULT 'Clean',
  maintenance_status VARCHAR(40) NOT NULL DEFAULT 'Cleared',
  last_occupancy     DATE NULL,
  staff_notes        TEXT NULL,
  UNIQUE KEY branch_room (branch, room_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  room_id           INT NOT NULL,
  guest_full_name   VARCHAR(150) NOT NULL,
  contact_number    VARCHAR(30),
  email             VARCHAR(255),
  address           VARCHAR(255),
  valid_id_type     VARCHAR(50),
  valid_id_number   VARCHAR(100),
  check_in          DATE NOT NULL,
  check_out         DATE NOT NULL,
  num_adults        INT NOT NULL DEFAULT 1,
  num_children      INT NOT NULL DEFAULT 0,
  status            ENUM('reserved','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'reserved',
  room_rate         DECIMAL(10,2) NOT NULL DEFAULT 0,
  security_deposit  DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_paid       DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method    ENUM('cash','gcash','bank_transfer','card') NULL,
  notes             TEXT,
  special_requests  TEXT,
  created_by        INT NULL,
  updated_by        INT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Who created/edited/deleted each reservation, and when. Reservations
-- themselves are never hard-deleted except by an explicit admin action
-- (status='cancelled' is the normal way a booking goes away), so this
-- plus the reservations table together are the "complete history".
CREATE TABLE IF NOT EXISTS reservation_activity_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id  INT NOT NULL,
  user_id         INT NULL,
  action          ENUM('created','edited','deleted') NOT NULL,
  details         TEXT,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed rooms — all 26 currently live under one building (the 3 floors
-- already built out in admin/layout*.php), mapped to the 'mtv' branch
-- (MTV3 — the only property with real floor-plan data so far). 'annex'
-- and 'dormitel' have no rooms yet on purpose — the Calendar and
-- floor-plan pages both handle that gracefully (empty state) until
-- those properties get their own layouts.
INSERT INTO rooms (branch, room_number, room_type, price_per_night) VALUES
('mtv', '101', 'Studio w/ Veranda', 9000),
('mtv', '102', 'Studio w/ Veranda', 9000),
('mtv', '103', 'Studio w/ Veranda', 9000),
('mtv', '104', 'Studio w/ Veranda', 9000),
('mtv', '105', 'Studio w/ Veranda', 9000),
('mtv', '106', 'Studio w/ Veranda', 9000),
('mtv', '201', 'Family B 1BR w/ Veranda', 13000),
('mtv', '202', 'Studio w/ Veranda', 9000),
('mtv', '203', 'Studio w/ Veranda', 9000),
('mtv', '204', 'Studio', 8000),
('mtv', '205', 'Studio', 8000),
('mtv', '206', 'Studio', 8000),
('mtv', '207', 'Studio', 8000),
('mtv', '208', 'Studio', 8000),
('mtv', '209', 'Studio', 8000),
('mtv', '210', 'Studio', 8000),
('mtv', '301', 'Family A 1BR w/ Veranda', 16000),
('mtv', '302', 'Studio w/ Veranda', 9000),
('mtv', '303', 'Studio w/ Veranda', 9000),
('mtv', '304', 'Studio', 8000),
('mtv', '305', 'Studio', 8000),
('mtv', '306', 'Studio', 8000),
('mtv', '307', 'Studio', 8000),
('mtv', '308', 'Studio', 8000),
('mtv', '309', 'Studio', 8000),
('mtv', '310', 'Studio', 8000);