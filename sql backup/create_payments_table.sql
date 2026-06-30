-- ============================================================
-- Bluebookers — Create payments table (no foreign keys)
-- Run this in phpMyAdmin → bluebookers → SQL tab
-- ============================================================

CREATE TABLE IF NOT EXISTS `payments` (
    `id`             INT           NOT NULL AUTO_INCREMENT,
    `reservation_id` INT           NOT NULL,
    `amount`         DECIMAL(12,2) NOT NULL,
    `payment_date`   DATE          NOT NULL,
    `payment_method` VARCHAR(30)       NULL DEFAULT NULL,
    `remarks`        TEXT              NULL DEFAULT NULL,
    `created_by`     INT               NULL DEFAULT NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_payments_reservation` (`reservation_id`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;