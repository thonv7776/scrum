-- PRIVATEHIRE CARS | COMPLETE CLEAN FIRST INSTALL (CUSTOMER + STAFF + PAYMENTS + NOTIFICATIONS)
-- MySQL 8.x / MariaDB 10.4+; no real payment is processed.
-- ONLY for a fresh, EMPTY database. If the database contains existing customers/bookings,
-- NEVER run this file: use upgrade.sql after a backup instead.
-- No DROP TABLE commands. Existing tables intentionally cause an error, not data loss.
CREATE DATABASE IF NOT EXISTS `privatehire_cars_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `privatehire_cars_db`;
SET NAMES utf8mb4;

-- Reference: US01/03 customer accounts and verification
CREATE TABLE `customers` (
 `customer_id` INT NOT NULL AUTO_INCREMENT,
 `full_name` VARCHAR(100) NOT NULL,
 `email` VARCHAR(150) NOT NULL,
 `password_hash` VARCHAR(255) NOT NULL,
 `residential_address` VARCHAR(255) NOT NULL,
 `telephone_number` VARCHAR(15) NOT NULL,
 `account_status` ENUM('Pending Verification','Active') NOT NULL DEFAULT 'Pending Verification',
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`customer_id`), UNIQUE KEY `uq_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US54/55/05/06 transport services, vehicle size and rate cards
CREATE TABLE `transport_services` (
 `service_id` INT NOT NULL AUTO_INCREMENT,
 `service_name` VARCHAR(80) NOT NULL,
 `short_tagline` VARCHAR(150) NOT NULL,
 `scope_description` TEXT NOT NULL,
 `base_flag_fall_rate` DECIMAL(8,2) NOT NULL,
 `airport_surcharge` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
 `daily_hire_rate` DECIMAL(8,2) DEFAULT NULL,
 PRIMARY KEY (`service_id`), UNIQUE KEY `uq_transport_services_name` (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `minicab_categories` (
 `category_id` INT NOT NULL AUTO_INCREMENT,
 `category_name` VARCHAR(50) NOT NULL,
 `max_passengers` TINYINT NOT NULL,
 `max_luggage` TINYINT NOT NULL,
 `per_mile_rate` DECIMAL(8,2) NOT NULL,
 PRIMARY KEY (`category_id`), UNIQUE KEY `uq_minicab_categories_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `minicabs` (
 `minicab_id` INT NOT NULL AUTO_INCREMENT,
 `category_id` INT NOT NULL,
 `registration_no` VARCHAR(20) NOT NULL,
 `make_and_model` VARCHAR(80) NOT NULL,
 `vehicle_colour` VARCHAR(30) NOT NULL,
 `is_active` TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY (`minicab_id`), UNIQUE KEY `uq_minicabs_registration_no` (`registration_no`),
 KEY `idx_minicabs_active_category` (`category_id`,`is_active`),
 CONSTRAINT `fk_minicabs_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_minicab_categories` (
 `service_id` INT NOT NULL, `category_id` INT NOT NULL,
 PRIMARY KEY (`service_id`,`category_id`), KEY `fk_smc_category` (`category_id`),
 CONSTRAINT `fk_smc_service` FOREIGN KEY (`service_id`) REFERENCES `transport_services` (`service_id`) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT `fk_smc_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US56/57/59/60 role-administered staff accounts
CREATE TABLE `staff_accounts` (
 `staff_id` INT NOT NULL AUTO_INCREMENT,
 `employee_id` VARCHAR(8) NOT NULL,
 `full_name` VARCHAR(100) NOT NULL,
 `work_email` VARCHAR(150) NOT NULL,
 `phone` VARCHAR(11) NOT NULL,
 `role` ENUM('Admin','Manager','Call Centre Staff') NOT NULL,
 `password_hash` VARCHAR(255) NOT NULL,
 `status` ENUM('Active','Deactivated') NOT NULL DEFAULT 'Active',
 `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
 `session_version` INT UNSIGNED NOT NULL DEFAULT 1,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `last_login_at` DATETIME DEFAULT NULL,
 `deactivated_at` DATETIME DEFAULT NULL,
 PRIMARY KEY (`staff_id`), UNIQUE KEY `uq_staff_employee` (`employee_id`), UNIQUE KEY `uq_staff_email` (`work_email`),
 KEY `idx_staff_role_status` (`role`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US07/09/10/11/12/31 trip details, reservation holds and telephone bookings
CREATE TABLE `bookings` (
 `booking_id` INT NOT NULL AUTO_INCREMENT,
 `customer_id` INT NOT NULL,
 `service_id` INT NOT NULL,
 `category_id` INT DEFAULT NULL,
 `minicab_id` INT DEFAULT NULL,
 `flight_number` VARCHAR(20) DEFAULT NULL,
 `hire_days` TINYINT DEFAULT NULL,
 `pickup_location` VARCHAR(255) DEFAULT NULL,
 `destination` VARCHAR(255) DEFAULT NULL,
 `pickup_key` VARCHAR(40) DEFAULT NULL,
 `destination_key` VARCHAR(40) DEFAULT NULL,
 `distance_miles` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
 `passenger_count` TINYINT NOT NULL DEFAULT 1,
 `pickup_datetime` DATETIME DEFAULT NULL,
 `estimated_end_datetime` DATETIME DEFAULT NULL,
 `booking_channel` ENUM('Online','Telephone','Walk-In') NOT NULL DEFAULT 'Online',
 `base_total_fare` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
 `online_discount_15` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
 `final_payable_fare` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
 `slot_hold_until` DATETIME DEFAULT NULL,
 `booking_status` ENUM('Draft','Held','Paid & Confirmed','Telephone Confirmed','Forwarded to Control Centre') NOT NULL DEFAULT 'Draft',
 `include_driver` TINYINT(1) NOT NULL DEFAULT 0,
 `driver_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
 `opt_in_email` TINYINT(1) NOT NULL DEFAULT 1,
 `email_status` ENUM('Not requested','Queued','Sent','Failed') NOT NULL DEFAULT 'Not requested',
 `email_retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
 `forwarded_at` DATETIME DEFAULT NULL,
 `created_by_staff_id` INT DEFAULT NULL,
 PRIMARY KEY (`booking_id`),
 KEY `fk_bookings_customer` (`customer_id`),KEY `fk_bookings_service` (`service_id`),
 KEY `idx_bookings_availability` (`category_id`,`minicab_id`,`pickup_datetime`,`booking_status`,`slot_hold_until`),
 KEY `idx_bookings_overlap` (`minicab_id`,`pickup_datetime`,`estimated_end_datetime`),
 KEY `idx_bookings_creator` (`created_by_staff_id`),
 CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
 CONSTRAINT `fk_bookings_service` FOREIGN KEY (`service_id`) REFERENCES `transport_services` (`service_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
 CONSTRAINT `fk_bookings_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
 CONSTRAINT `fk_bookings_minicab` FOREIGN KEY (`minicab_id`) REFERENCES `minicabs` (`minicab_id`) ON DELETE SET NULL ON UPDATE CASCADE,
 CONSTRAINT `fk_s2_created_by_staff` FOREIGN KEY (`created_by_staff_id`) REFERENCES `staff_accounts` (`staff_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `verification_tokens` (
 `token_id` INT NOT NULL AUTO_INCREMENT,
 `customer_id` INT NOT NULL,
 `token_hash` VARCHAR(255) NOT NULL,
 `expires_at` DATETIME NOT NULL,
 `is_used` TINYINT(1) NOT NULL DEFAULT 0,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`token_id`), UNIQUE KEY `uq_verification_tokens_hash` (`token_hash`),
 KEY `fk_verification_tokens_customer` (`customer_id`),
 CONSTRAINT `fk_verification_tokens_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US15/17 sandbox payments and receipts
CREATE TABLE `payments` (
 `payment_id` BIGINT NOT NULL AUTO_INCREMENT,
 `booking_id` INT NOT NULL,
 `transaction_id` VARCHAR(100) NOT NULL,
 `card_last4` CHAR(4) NOT NULL,
 `payment_method` VARCHAR(40) NOT NULL DEFAULT 'Credit Card',
 `amount_gbp` DECIMAL(10,2) NOT NULL,
 `payment_status` ENUM('Approved','Declined') NOT NULL,
 `gateway_mode` ENUM('Sandbox','Live') NOT NULL DEFAULT 'Sandbox',
 `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`payment_id`), UNIQUE KEY `uq_payments_booking` (`booking_id`), UNIQUE KEY `uq_payments_transaction` (`transaction_id`),
 CONSTRAINT `fk_s2_pay_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US28/33 vehicle dispatch, simulated or external GPS provider
CREATE TABLE `vehicle_dispatch_profiles` (
 `minicab_id` INT NOT NULL,
 `driver_full_name` VARCHAR(100) NOT NULL,
 `zone` VARCHAR(80) NOT NULL DEFAULT 'Central London',
 `operational_status` ENUM('Available','On job','Out of service') NOT NULL DEFAULT 'Available',
 PRIMARY KEY (`minicab_id`),
 CONSTRAINT `fk_s2_dp_car` FOREIGN KEY (`minicab_id`) REFERENCES `minicabs` (`minicab_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dispatch_jobs` (
 `dispatch_id` BIGINT NOT NULL AUTO_INCREMENT,
 `booking_id` INT NOT NULL,
 `status` ENUM('Pending','Forwarded','Synced','Failed') NOT NULL DEFAULT 'Pending',
 `payload_json` LONGTEXT DEFAULT NULL,
 `forwarded_at` DATETIME DEFAULT NULL,
 `gps_reference` VARCHAR(120) DEFAULT NULL,
 `last_error` TEXT DEFAULT NULL,
 `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`dispatch_id`),UNIQUE KEY `uq_dispatch_booking` (`booking_id`),
 CONSTRAINT `fk_s2_dispatch_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference: US20/23 email, SMS, retry statuses, 10-second payment SMS
CREATE TABLE `notification_jobs` (
 `notification_id` BIGINT NOT NULL AUTO_INCREMENT,
 `booking_id` INT NOT NULL,
 `channel` ENUM('Email','SMS') NOT NULL,
 `event_key` VARCHAR(150) NOT NULL,
 `recipient` VARCHAR(160) NOT NULL,
 `subject` VARCHAR(200) DEFAULT NULL,
 `body_text` TEXT NOT NULL,
 `status` ENUM('Queued','Sent','Failed') NOT NULL DEFAULT 'Queued',
 `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
 `due_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `trigger_at` DATETIME DEFAULT NULL,
 `sent_at` DATETIME DEFAULT NULL,
 `provider_reference` VARCHAR(100) DEFAULT NULL,
 `last_error` VARCHAR(400) DEFAULT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`notification_id`),UNIQUE KEY `uq_notification_event` (`event_key`),
 KEY `idx_s2_pending` (`status`,`due_at`),KEY `idx_s2_notif_booking` (`booking_id`,`channel`),
 CONSTRAINT `fk_s2_notif_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `arrival_events` (
 `arrival_id` BIGINT NOT NULL AUTO_INCREMENT,
 `booking_id` INT NOT NULL,
 `provider_event_id` VARCHAR(120) NOT NULL,
 `eta_minutes` INT NOT NULL,
 `event_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `notification_id` BIGINT DEFAULT NULL,
 PRIMARY KEY (`arrival_id`), UNIQUE KEY `uq_arrival_event` (`provider_event_id`),
 KEY `idx_arrival_booking` (`booking_id`),KEY `idx_arrival_notification` (`notification_id`),
 CONSTRAINT `fk_s2_arr_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_s2_arr_notification` FOREIGN KEY (`notification_id`) REFERENCES `notification_jobs` (`notification_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data seed: identical IDs and tariff values to the uploaded working project.
INSERT INTO `transport_services` (`service_id`, `service_name`, `short_tagline`, `scope_description`, `base_flag_fall_rate`, `airport_surcharge`, `daily_hire_rate`) VALUES
(1, 'Local/Long Distance Journeys', 'Point-to-point private hire travel across local boroughs and intercity routes.', 'Door-to-door minicab service for local errands or long-distance intercity journeys. Priced by base flag-fall plus distance mileage rate according to vehicle category.', 5.00, 0.00, NULL),
(2, 'Airport & Hotel Transfers', 'Reliable scheduled transfers between major airports, hotels, and residences.', 'Direct transfer service to and from all major airports and hotels. Includes standard kerbside pickup/drop-off and luggage assistance.', 8.00, 5.00, NULL),
(3, 'Meet & Greet at Airports', 'Chauffeur waiting inside the arrivals hall with a personalised name board.', 'Priority airport pickup service where the driver tracks your flight number, waits inside the terminal arrivals hall, and assists with luggage to the minicab. Includes airport surcharge and 45 minutes free waiting time after landing.', 10.00, 12.00, NULL),
(4, 'Driver & Car for Daily Hire', 'Dedicated private driver and minicab booked by the day for flexible itineraries.', 'Full-day bespoke vehicle and driver hire (up to 10 hours per day) for corporate roadshows, sightseeing, or multi-stop events.', 15.00, 0.00, 140.00);

INSERT INTO `minicab_categories` (`category_id`, `category_name`, `max_passengers`, `max_luggage`, `per_mile_rate`) VALUES
(1, '4-Seat Saloon', 4, 2, 2.00),
(2, '6-Seat MPV', 6, 4, 2.75),
(3, '8-Seat Minibus', 8, 8, 3.50);

INSERT INTO `minicabs` (`minicab_id`, `category_id`, `registration_no`, `make_and_model`, `vehicle_colour`, `is_active`) VALUES
(1, 1, 'LD24 AAA', 'Toyota Prius Hybrid', 'Silver', 1),
(2, 1, 'LD24 AAB', 'Skoda Octavia Saloon', 'Black', 1),
(3, 2, 'LD24 MPV', 'Volkswagen Sharan MPV', 'Grey', 1),
(4, 3, 'LD24 BUS', 'Mercedes-Benz Vito Tourer 8', 'Black', 1);

INSERT INTO `service_minicab_categories` (`service_id`, `category_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(1, 2),
(2, 2),
(3, 2),
(4, 2),
(1, 3),
(2, 3),
(3, 3),
(4, 3);

INSERT INTO `vehicle_dispatch_profiles` (`minicab_id`,`driver_full_name`,`zone`)
SELECT `minicab_id`,CONCAT('Demo driver for car ',`minicab_id`),
 CASE WHEN `minicab_id` IN (1,3) THEN 'Heathrow'
      WHEN `minicab_id`=4 THEN 'Gatwick' ELSE 'Central London' END
FROM `minicabs`;
