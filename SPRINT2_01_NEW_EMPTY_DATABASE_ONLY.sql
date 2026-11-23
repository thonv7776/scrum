-- PRIVATEHIRE CARS / WAMP - FRESH DATABASE ONLY, based on original Sprint 1 SQL.
-- This is a safer derivative for EMPTY DATABASES. The original init_database.sql
-- in the package is UNCHANGED. All original fields/seed values remain the same;
-- only explicit ENGINE=InnoDB for legacy bookings and minicab_categories; NO DROP TABLE.
-- STOP: If you already have Sprint 1 customers/bookings, do NOT import this script.
-- Import sprint2_migration.sql after this script finishes successfully.

CREATE DATABASE IF NOT EXISTS `privatehire_cars_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `privatehire_cars_db`;
-- Abort if the Sprint 1 tables already exist. No table drops are performed by this script.
DELIMITER $$
DROP PROCEDURE IF EXISTS phc_sprint2_fresh_guard$$
CREATE PROCEDURE phc_sprint2_fresh_guard()
BEGIN
 IF EXISTS(SELECT 1 FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('bookings','customers','minicabs','minicab_categories','transport_services','verification_tokens')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='NOT AN EMPTY DATABASE: do not use fresh installer. Use sprint2_migration.sql only.';
 END IF;
END$$
DELIMITER ;
CALL phc_sprint2_fresh_guard();
DROP PROCEDURE IF EXISTS phc_sprint2_fresh_guard;


-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 10, 2026 at 12:30 PM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `privatehire_cars_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `bookings` (
  `booking_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `service_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `minicab_id` int DEFAULT NULL,
  `flight_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hire_days` tinyint DEFAULT NULL,
  `pickup_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `distance_miles` decimal(6,2) NOT NULL DEFAULT '0.00',
  `passenger_count` tinyint NOT NULL DEFAULT '1',
  `pickup_datetime` datetime DEFAULT NULL,
  `estimated_end_datetime` datetime DEFAULT NULL,
  `booking_channel` enum('Online','Telephone','Walk-In') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Online',
  `base_total_fare` decimal(8,2) NOT NULL DEFAULT '0.00',
  `online_discount_15` decimal(8,2) NOT NULL DEFAULT '0.00',
  `final_payable_fare` decimal(8,2) NOT NULL DEFAULT '0.00',
  `slot_hold_until` datetime DEFAULT NULL,
  `booking_status` enum('Draft','Held') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  PRIMARY KEY (`booking_id`),
  KEY `fk_bookings_customer` (`customer_id`),
  KEY `fk_bookings_service` (`service_id`),
  KEY `idx_bookings_availability` (`category_id`,`minicab_id`,`pickup_datetime`,`booking_status`,`slot_hold_until`),
  KEY `idx_bookings_overlap` (`minicab_id`,`pickup_datetime`,`estimated_end_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `customers` (
  `customer_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `residential_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telephone_number` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_status` enum('Pending Verification','Active') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending Verification',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `uq_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `minicabs`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `minicabs` (
  `minicab_id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `registration_no` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `make_and_model` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_colour` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`minicab_id`),
  UNIQUE KEY `uq_minicabs_registration_no` (`registration_no`),
  KEY `idx_minicabs_active_category` (`category_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `minicabs`
--

INSERT INTO `minicabs` (`minicab_id`, `category_id`, `registration_no`, `make_and_model`, `vehicle_colour`, `is_active`) VALUES
(1, 1, 'LD24 AAA', 'Toyota Prius Hybrid', 'Silver', 1),
(2, 1, 'LD24 AAB', 'Skoda Octavia Saloon', 'Black', 1),
(3, 2, 'LD24 MPV', 'Volkswagen Sharan MPV', 'Grey', 1),
(4, 3, 'LD24 BUS', 'Mercedes-Benz Vito Tourer 8', 'Black', 1);

-- --------------------------------------------------------

--
-- Table structure for table `minicab_categories`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `minicab_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_passengers` tinyint NOT NULL COMMENT 'Allowed values: 4, 6, 8',
  `max_luggage` tinyint NOT NULL,
  `per_mile_rate` decimal(8,2) NOT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_minicab_categories_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `minicab_categories`
--

INSERT INTO `minicab_categories` (`category_id`, `category_name`, `max_passengers`, `max_luggage`, `per_mile_rate`) VALUES
(1, '4-Seat Saloon', 4, 2, 2.00),
(2, '6-Seat MPV', 6, 4, 2.75),
(3, '8-Seat Minibus', 8, 8, 3.50);

-- --------------------------------------------------------

--
-- Table structure for table `service_minicab_categories`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `service_minicab_categories` (
  `service_id` int NOT NULL,
  `category_id` int NOT NULL,
  PRIMARY KEY (`service_id`,`category_id`),
  KEY `fk_smc_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_minicab_categories`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `transport_services`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `transport_services` (
  `service_id` int NOT NULL AUTO_INCREMENT,
  `service_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_tagline` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_flag_fall_rate` decimal(8,2) NOT NULL,
  `airport_surcharge` decimal(8,2) NOT NULL DEFAULT '0.00',
  `daily_hire_rate` decimal(8,2) DEFAULT NULL,
  PRIMARY KEY (`service_id`),
  UNIQUE KEY `uq_transport_services_name` (`service_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transport_services`
--

INSERT INTO `transport_services` (`service_id`, `service_name`, `short_tagline`, `scope_description`, `base_flag_fall_rate`, `airport_surcharge`, `daily_hire_rate`) VALUES
(1, 'Local/Long Distance Journeys', 'Point-to-point private hire travel across local boroughs and intercity routes.', 'Door-to-door minicab service for local errands or long-distance intercity journeys. Priced by base flag-fall plus distance mileage rate according to vehicle category.', 5.00, 0.00, NULL),
(2, 'Airport & Hotel Transfers', 'Reliable scheduled transfers between major airports, hotels, and residences.', 'Direct transfer service to and from all major airports and hotels. Includes standard kerbside pickup/drop-off and luggage assistance.', 8.00, 5.00, NULL),
(3, 'Meet & Greet at Airports', 'Chauffeur waiting inside the arrivals hall with a personalised name board.', 'Priority airport pickup service where the driver tracks your flight number, waits inside the terminal arrivals hall, and assists with luggage to the minicab. Includes airport surcharge and 45 minutes free waiting time after landing.', 10.00, 12.00, NULL),
(4, 'Driver & Car for Daily Hire', 'Dedicated private driver and minicab booked by the day for flexible itineraries.', 'Full-day bespoke vehicle and driver hire (up to 10 hours per day) for corporate roadshows, sightseeing, or multi-stop events.', 15.00, 0.00, 140.00);

-- --------------------------------------------------------

--
-- Table structure for table `verification_tokens`
--

-- Fresh installer deliberately avoids DROP TABLE.
CREATE TABLE IF NOT EXISTS `verification_tokens` (
  `token_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_verification_tokens_hash` (`token_hash`),
  KEY `fk_verification_tokens_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_minicab` FOREIGN KEY (`minicab_id`) REFERENCES `minicabs` (`minicab_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bookings_service` FOREIGN KEY (`service_id`) REFERENCES `transport_services` (`service_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `minicabs`
--
ALTER TABLE `minicabs`
  ADD CONSTRAINT `fk_minicabs_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `service_minicab_categories`
--
ALTER TABLE `service_minicab_categories`
  ADD CONSTRAINT `fk_smc_category` FOREIGN KEY (`category_id`) REFERENCES `minicab_categories` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_smc_service` FOREIGN KEY (`service_id`) REFERENCES `transport_services` (`service_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `verification_tokens`
--
ALTER TABLE `verification_tokens`
  ADD CONSTRAINT `fk_verification_tokens_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Sprint 1 extension. Driver add-on pricing belongs in config.php.
ALTER TABLE `bookings`
 ADD COLUMN `include_driver` TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN `driver_fee` DECIMAL(8,2) NOT NULL DEFAULT 0,
 ADD COLUMN `pickup_key` VARCHAR(40) NULL,
 ADD COLUMN `destination_key` VARCHAR(40) NULL;
