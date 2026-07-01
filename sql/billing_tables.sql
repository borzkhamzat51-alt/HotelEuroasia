-- ============================================================
-- Bluebookers PMS — Billing Module Database Migration
-- Run in phpMyAdmin → SQL tab
-- ============================================================

-- 1. System Settings (billing cutoff, rates config)
CREATE TABLE IF NOT EXISTS `billing_settings` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `branch`        varchar(30) NOT NULL DEFAULT '',
  `setting_key`   varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by`    int(11) DEFAULT NULL,
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_key` (`branch`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT IGNORE INTO `billing_settings` (`branch`, `setting_key`, `setting_value`) VALUES
('mtv',      'billing_cutoff_time', '17:00'),
('annex',    'billing_cutoff_time', '17:00'),
('dormitel', 'billing_cutoff_time', '17:00'),
('mtv',      'late_penalty_percent', '0'),
('annex',    'late_penalty_percent', '0'),
('dormitel', 'late_penalty_percent', '0');

-- 2. Property Utility Rates (per-property rate configuration)
CREATE TABLE IF NOT EXISTS `utility_rates` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `branch`        varchar(30) NOT NULL DEFAULT '',
  `utility_type`  varchar(50) NOT NULL,
  `rate_per_unit` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `unit_label`    varchar(20) NOT NULL DEFAULT 'kWh',
  `effective_date` date NOT NULL DEFAULT '2020-01-01',
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_branch_type` (`branch`, `utility_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default rates
INSERT IGNORE INTO `utility_rates` (`branch`, `utility_type`, `rate_per_unit`, `unit_label`) VALUES
('mtv', 'Electricity', 13.5800, 'kWh'),
('mtv', 'Water',        35.0000, 'cu.m.'),
('annex', 'Electricity', 13.5800, 'kWh'),
('annex', 'Water',        35.0000, 'cu.m.'),
('dormitel', 'Electricity', 13.5800, 'kWh'),
('dormitel', 'Water',        35.0000, 'cu.m.');

-- 3. Utility Reading Sessions (one per reservation per utility type)
CREATE TABLE IF NOT EXISTS `utility_sessions` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id`  int(11) NOT NULL,
  `branch`          varchar(30) NOT NULL DEFAULT '',
  `room_id`         int(11) NOT NULL,
  `utility_type`    varchar(50) NOT NULL,
  `initial_reading` decimal(14,2) NOT NULL DEFAULT 0.00,
  `final_reading`   decimal(14,2) DEFAULT NULL,
  `total_consumption` decimal(14,2) NOT NULL DEFAULT 0.00,
  `rate_per_unit`   decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_charge`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`          enum('Active','Closed','Cancelled') NOT NULL DEFAULT 'Active',
  `started_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at`       datetime DEFAULT NULL,
  `created_by`      int(11) DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_branch_status` (`branch`, `status`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Daily Utility Readings (the logbook — one row per day per session)
CREATE TABLE IF NOT EXISTS `utility_readings` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `session_id`      int(11) NOT NULL,
  `reservation_id`  int(11) NOT NULL,
  `reading_date`    date NOT NULL,
  `previous_reading` decimal(14,2) NOT NULL DEFAULT 0.00,
  `present_reading`  decimal(14,2) NOT NULL DEFAULT 0.00,
  `consumption`     decimal(14,2) NOT NULL DEFAULT 0.00,
  `rate`            decimal(12,4) NOT NULL DEFAULT 0.0000,
  `charge`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `entered_by`      int(11) DEFAULT NULL,
  `notes`           varchar(255) DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_date` (`session_id`, `reading_date`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_date` (`reading_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Monthly Bills (generated per reservation per billing cycle)
CREATE TABLE IF NOT EXISTS `monthly_bills` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id`  int(11) NOT NULL,
  `branch`          varchar(30) NOT NULL DEFAULT '',
  `room_id`         int(11) NOT NULL,
  `guest_name`      varchar(200) NOT NULL DEFAULT '',
  `billing_period`  varchar(7) NOT NULL DEFAULT '',
  `period_start`    date NOT NULL,
  `period_end`      date NOT NULL,
  `room_rental`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `reservation_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `garbage_fee`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `security_deposit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `utilities_deposit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `electricity_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `water_charge`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `internet_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_charges`   decimal(12,2) NOT NULL DEFAULT 0.00,
  `misc_charges`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_penalty`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax`             decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance`         decimal(12,2) NOT NULL DEFAULT 0.00,
  `status`          enum('Draft','Generated','Sent','Paid','Partial','Overdue','Void') NOT NULL DEFAULT 'Draft',
  `due_date`        date DEFAULT NULL,
  `generated_by`    int(11) DEFAULT NULL,
  `generated_at`    datetime DEFAULT NULL,
  `locked`          tinyint(1) NOT NULL DEFAULT 0,
  `notes`           text DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resv_period` (`reservation_id`, `billing_period`),
  KEY `idx_branch_period` (`branch`, `billing_period`),
  KEY `idx_status` (`status`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Bill Line Items (individual charges within a monthly bill)
CREATE TABLE IF NOT EXISTS `bill_items` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `bill_id`     int(11) NOT NULL,
  `item_type`   varchar(50) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `quantity`    decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price`  decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00,
  `sort_order`  int(11) NOT NULL DEFAULT 0,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Billing Adjustments (corrections without editing locked bills)
CREATE TABLE IF NOT EXISTS `billing_adjustments` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id`  int(11) NOT NULL,
  `bill_id`         int(11) DEFAULT NULL,
  `adjustment_type` enum('Credit','Debit','Discount','Penalty','Refund','Void') NOT NULL,
  `amount`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason`          text NOT NULL,
  `authorized_by`   int(11) DEFAULT NULL,
  `created_by`      int(11) DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Payment Allocations (which bill each payment covers)
CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `payment_id`  int(11) NOT NULL,
  `bill_id`     int(11) NOT NULL,
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_bill` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Billing Audit Log
CREATE TABLE IF NOT EXISTS `billing_audit_log` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id`  int(11) DEFAULT NULL,
  `bill_id`         int(11) DEFAULT NULL,
  `action`          varchar(100) NOT NULL DEFAULT '',
  `details`         text DEFAULT NULL,
  `performed_by`    int(11) DEFAULT NULL,
  `performer_name`  varchar(200) DEFAULT NULL,
  `ip_address`      varchar(45) DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_bill` (`bill_id`),
  KEY `idx_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Add new columns to existing reservations table if they don't exist
-- Run these one at a time; skip any that error with "Duplicate column name"
ALTER TABLE `reservations` ADD COLUMN `emergency_contact` varchar(50) DEFAULT NULL AFTER `contact_number`;
ALTER TABLE `reservations` ADD COLUMN `reservation_fee` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `room_rate`;
ALTER TABLE `reservations` ADD COLUMN `garbage_fee` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `reservation_fee`;
ALTER TABLE `reservations` ADD COLUMN `utilities_deposit` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `security_deposit`;
ALTER TABLE `reservations` ADD COLUMN `checkin_time` time DEFAULT NULL AFTER `check_in`;
ALTER TABLE `reservations` ADD COLUMN `checkout_time` time DEFAULT NULL AFTER `check_out`;
ALTER TABLE `reservations` ADD COLUMN `billing_start_date` date DEFAULT NULL AFTER `checkout_time`;

-- 11. Ensure utility_charges table exists (from earlier migration)
CREATE TABLE IF NOT EXISTS `utility_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `utility_type` varchar(50) NOT NULL DEFAULT '',
  `billing_period` varchar(7) NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_status` (`status`),
  KEY `idx_billing_period` (`billing_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;