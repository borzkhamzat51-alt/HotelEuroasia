-- Add payment_allocations JSON column to reservations
ALTER TABLE reservations
ADD COLUMN payment_allocations JSON NULL AFTER amount_paid;