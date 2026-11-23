-- PrivateHire Cars COMP1807 - Sprint 2 SAFE, RE-RUNNABLE upgrade for WAMP/MySQL/MariaDB.
-- 1) BACKUP DATABASE in phpMyAdmin Export -> SQL before any migration.
-- 2) Select database privatehire_cars_db and Import this file, even if an older
--    sprint2_migration.sql partly succeeded. NEVER re-import Sprint 1 init SQL on an existing DB.
-- 3) Existing rows and Sprint 1 PHP/JS/HTML files are NEVER dropped or overwritten.
--    This upgrade extends bookings and, only if required, converts its storage ENGINE to
--    InnoDB so foreign keys/atomic reservations can work. Engine is a database change.
USE `privatehire_cars_db`;

DELIMITER $$
DROP PROCEDURE IF EXISTS phc_sprint2_upgrade_safe$$
CREATE PROCEDURE phc_sprint2_upgrade_safe()
BEGIN
  DECLARE v_engine VARCHAR(64);
  DECLARE v_status_type VARCHAR(512);
  DECLARE v_col INT DEFAULT 0;

  SELECT ENGINE INTO v_engine FROM information_schema.TABLES
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND TABLE_TYPE='BASE TABLE';
  IF v_engine IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing Sprint 1 bookings table; import init_database.sql ONLY to a fresh empty database first.';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
     ('customers','minicabs','minicab_categories','transport_services')) <> 4 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sprint 1 base tables are missing; restore the Sprint 1 database backup first.';
  END IF;

  -- On some WAMP installations, the legacy Sprint 1 SQL omits ENGINE=InnoDB
  -- for bookings. MyISAM cannot be a target for the payments foreign key (#1824).
  -- Conversion preserves bookings and is required for atomic Sprint 2 checkout.
  IF UPPER(v_engine)<>'INNODB' THEN
    ALTER TABLE `bookings` ENGINE=InnoDB;
  END IF;

  SELECT COLUMN_TYPE INTO v_status_type FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='booking_status';
  IF v_status_type IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Sprint 1 bookings schema: booking_status is missing.';
  END IF;
  IF LOCATE('Paid & Confirmed',v_status_type)=0
     OR LOCATE('Telephone Confirmed',v_status_type)=0
     OR LOCATE('Forwarded to Control Centre',v_status_type)=0 THEN
    ALTER TABLE `bookings` MODIFY `booking_status`
      ENUM('Draft','Held','Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre')
      NOT NULL DEFAULT 'Draft';
  END IF;

  -- Any prior partially successful migration is handled column-by-column.
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='include_driver') THEN
    ALTER TABLE `bookings` ADD COLUMN `include_driver` TINYINT(1) NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='driver_fee') THEN
    ALTER TABLE `bookings` ADD COLUMN `driver_fee` DECIMAL(8,2) NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='pickup_key') THEN
    ALTER TABLE `bookings` ADD COLUMN `pickup_key` VARCHAR(40) NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='destination_key') THEN
    ALTER TABLE `bookings` ADD COLUMN `destination_key` VARCHAR(40) NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='opt_in_email') THEN
    ALTER TABLE `bookings` ADD COLUMN `opt_in_email` TINYINT(1) NOT NULL DEFAULT 1;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='email_status') THEN
    ALTER TABLE `bookings` ADD COLUMN `email_status` ENUM('Not requested','Queued','Sent','Failed') NOT NULL DEFAULT 'Not requested';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='email_retry_count') THEN
    ALTER TABLE `bookings` ADD COLUMN `email_retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='forwarded_at') THEN
    ALTER TABLE `bookings` ADD COLUMN `forwarded_at` DATETIME NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='created_by_staff_id') THEN
    ALTER TABLE `bookings` ADD COLUMN `created_by_staff_id` INT NULL;
  END IF;

  CREATE TABLE IF NOT EXISTS `staff_accounts` (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(8) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    work_email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(11) NOT NULL,
    role ENUM('Admin','Manager','Call Centre Staff') NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('Active','Deactivated') NOT NULL DEFAULT 'Active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    deactivated_at DATETIME NULL,
    INDEX idx_staff_role_status (role,status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  IF NOT EXISTS (SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND CONSTRAINT_NAME='fk_s2_created_by_staff') THEN
    ALTER TABLE `bookings` ADD CONSTRAINT `fk_s2_created_by_staff`
    FOREIGN KEY (`created_by_staff_id`) REFERENCES `staff_accounts` (`staff_id`) ON DELETE SET NULL;
  END IF;

  CREATE TABLE IF NOT EXISTS `payments` (
    payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE,
    transaction_id VARCHAR(100) NOT NULL UNIQUE,
    card_last4 CHAR(4) NOT NULL,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'Credit Card',
    amount_gbp DECIMAL(10,2) NOT NULL,
    payment_status ENUM('Approved','Declined') NOT NULL,
    gateway_mode ENUM('Sandbox','Live') NOT NULL DEFAULT 'Sandbox',
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_s2_pay_booking FOREIGN KEY (booking_id)
      REFERENCES bookings(booking_id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS `vehicle_dispatch_profiles` (
    minicab_id INT PRIMARY KEY,
    driver_full_name VARCHAR(100) NOT NULL,
    zone VARCHAR(80) NOT NULL DEFAULT 'Central London',
    operational_status ENUM('Available','On job','Out of service') NOT NULL DEFAULT 'Available',
    CONSTRAINT fk_s2_dp_car FOREIGN KEY (minicab_id)
      REFERENCES minicabs(minicab_id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  INSERT IGNORE INTO vehicle_dispatch_profiles(minicab_id,driver_full_name,zone)
  SELECT minicab_id,CONCAT('Demo driver for car ',minicab_id),
    CASE WHEN minicab_id=1 OR minicab_id=3 THEN 'Heathrow'
         WHEN minicab_id=4 THEN 'Gatwick' ELSE 'Central London' END
  FROM minicabs;

  CREATE TABLE IF NOT EXISTS `dispatch_jobs` (
    dispatch_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE,
    status ENUM('Pending','Forwarded','Synced','Failed') NOT NULL DEFAULT 'Pending',
    payload_json LONGTEXT NULL,
    forwarded_at DATETIME NULL,
    gps_reference VARCHAR(120) NULL,
    last_error TEXT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_s2_dispatch_booking FOREIGN KEY (booking_id)
      REFERENCES bookings(booking_id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS `notification_jobs` (
    notification_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    channel ENUM('Email','SMS') NOT NULL,
    event_key VARCHAR(150) NOT NULL UNIQUE,
    recipient VARCHAR(160) NOT NULL,
    subject VARCHAR(200) NULL,
    body_text TEXT NOT NULL,
    status ENUM('Queued','Sent','Failed') NOT NULL DEFAULT 'Queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    due_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    trigger_at DATETIME NULL,
    sent_at DATETIME NULL,
    provider_reference VARCHAR(100) NULL,
    last_error VARCHAR(400) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_s2_pending (status,due_at),
    INDEX idx_s2_notif_booking (booking_id,channel),
    CONSTRAINT fk_s2_notif_booking FOREIGN KEY (booking_id)
      REFERENCES bookings(booking_id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS `arrival_events` (
    arrival_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    provider_event_id VARCHAR(120) NOT NULL UNIQUE,
    eta_minutes INT NOT NULL,
    event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notification_id BIGINT NULL,
    CONSTRAINT fk_s2_arr_booking FOREIGN KEY (booking_id)
      REFERENCES bookings(booking_id) ON DELETE RESTRICT,
    CONSTRAINT fk_s2_arr_notification FOREIGN KEY (notification_id)
      REFERENCES notification_jobs(notification_id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
END$$
DELIMITER ;

CALL phc_sprint2_upgrade_safe();
DROP PROCEDURE IF EXISTS phc_sprint2_upgrade_safe;

-- A SELECT result after Import/SQL means all 6 Sprint 2 tables exist.
SELECT 'SPRINT 2 DATABASE UPGRADE COMPLETE' AS result,
       (SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings') AS bookings_engine,
       (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
       ('staff_accounts','payments','vehicle_dispatch_profiles','dispatch_jobs','notification_jobs','arrival_events')) AS sprint2_tables_created;
