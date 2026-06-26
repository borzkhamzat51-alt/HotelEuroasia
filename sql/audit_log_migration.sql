-- ============================================================================
-- Bluebookers — Audit Log migration
-- Run this once in phpMyAdmin → SQL tab.
-- Safe to run multiple times — uses CREATE TABLE IF NOT EXISTS.
-- ============================================================================

USE bluebookers;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,                          -- NULL if user was deleted
  username    VARCHAR(50)  NULL,                 -- snapshot at time of action
  full_name   VARCHAR(150) NULL,
  role        ENUM('admin','staff') NULL,
  action      VARCHAR(80)  NOT NULL,             -- e.g. 'login', 'reservation.create'
  target_type VARCHAR(50)  NULL,                 -- e.g. 'reservation', 'user', 'room'
  target_id   INT          NULL,                 -- PK of the affected row
  target_label VARCHAR(255) NULL,                -- human-readable (guest name, room no.)
  details     TEXT         NULL,                 -- extra JSON/text context
  ip_address  VARCHAR(45)  NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user    (user_id),
  INDEX idx_action  (action),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;