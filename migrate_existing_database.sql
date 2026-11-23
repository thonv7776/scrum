-- Use this only if you already imported the ORIGINAL SQL, and have not applied these changes.
USE `privatehire_cars_db`;
ALTER TABLE customers MODIFY account_status ENUM('Pending','Pending Verification','Active') NOT NULL DEFAULT 'Pending Verification';
UPDATE customers SET account_status='Pending Verification' WHERE account_status='Pending';
ALTER TABLE customers MODIFY account_status ENUM('Pending Verification','Active') NOT NULL DEFAULT 'Pending Verification';
ALTER TABLE bookings
 ADD COLUMN include_driver TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN driver_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
 ADD COLUMN pickup_key VARCHAR(40) NULL,
 ADD COLUMN destination_key VARCHAR(40) NULL;
