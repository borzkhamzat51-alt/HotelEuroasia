-- ============================================================================
-- Bluebookers — MySQL schema
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
  permissions   TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO users (username, email, password_hash, full_name, role, permissions)
VALUES (
  'admin',
  'admin@bluebookers.test',
  '$2y$10$liGj0qniuP10FIi9EH3wuOgN0.82W5E2qDP3ggmUZajSfVyjvxYeO',
  'Hotel Admin',
  'admin',
  NULL
) ON DUPLICATE KEY UPDATE username = username;

INSERT INTO users (username, email, password_hash, full_name, role, permissions)
VALUES (
  'frontdesk',
  'frontdesk@bluebookers.test',
  '$2y$10$lr7Ts3cRK.EWq/9qsG6lUOql8n0N89P7SNZldNH0cfEPxsF3PfBri',
  'Front Desk Staff',
  'staff',
  'dashboard,reservations,guests'
) ON DUPLICATE KEY UPDATE username = username;

CREATE TABLE IF NOT EXISTS rooms (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  branch             VARCHAR(50) NOT NULL,
  room_number        VARCHAR(20) NOT NULL,
  room_type          VARCHAR(100) NOT NULL,
  price_per_night    DECIMAL(10,2) NOT NULL DEFAULT 0,
  room_status        ENUM('available','occupied','reserved','maintenance') NOT NULL DEFAULT 'available',
  cleaning_status    VARCHAR(40) NOT NULL DEFAULT 'Clean',
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
  expected_payment_date DATE NULL,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_activity_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id  INT NOT NULL,
  user_id         INT NULL,
  action          ENUM('created','edited','deleted') NOT NULL,
  details         TEXT,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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