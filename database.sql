-- Balangoda Utility Outage Warnings & Complaints Database Schema
CREATE DATABASE IF NOT EXISTS `balangoda_utility_warnings` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `balangoda_utility_warnings`;

-- 1. Admins Table
CREATE TABLE IF NOT EXISTS `admins` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL UNIQUE,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Admin User (username: admin, password: admin123)
INSERT INTO `admins` (`admin_id`, `username`, `password_hash`, `created_at`) 
VALUES (1, 'admin', '$2y$10$Bj1EbLEEC2khD/0K6.byT.UgZnIHbshWDN0o8vnAyRmjC2ihYGMFy', NOW())
ON DUPLICATE KEY UPDATE `username`=`username`;

-- 2. Warnings Table
CREATE TABLE IF NOT EXISTS `warnings` (
  `warning_id` int(11) NOT NULL AUTO_INCREMENT,
  `utility_type` enum('Power','Water','Road') NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `color_code` varchar(7) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`warning_id`),
  KEY `posted_by` (`posted_by`),
  CONSTRAINT `fk_warnings_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Complaints Table
CREATE TABLE IF NOT EXISTS `complaints` (
  `complaint_id` int(11) NOT NULL AUTO_INCREMENT,
  `utility_type` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `resident_name` varchar(100) NOT NULL,
  `contact_info` varchar(100) NOT NULL,
  `status` varchar(30) DEFAULT 'Pending Review',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Notifications Table
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `target_type`     ENUM('admin','client') NOT NULL,
  `target_ref`      VARCHAR(100) DEFAULT NULL,
  `message`         TEXT NOT NULL,
  `link`            VARCHAR(255) DEFAULT NULL,
  `is_read`         TINYINT(1) DEFAULT 0,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Customers Table (resident accounts)
CREATE TABLE IF NOT EXISTS `customers` (
  `customer_id`         INT AUTO_INCREMENT PRIMARY KEY,
  `full_name`           VARCHAR(100) NOT NULL,
  `phone`               VARCHAR(20)  NOT NULL,
  `email`               VARCHAR(120) NOT NULL UNIQUE,
  `address`             TEXT         NOT NULL,
  `profile_pic`         VARCHAR(255) DEFAULT NULL,
  `electricity_bill_no` VARCHAR(50)  DEFAULT NULL,
  `water_bill_no`       VARCHAR(50)  DEFAULT NULL,
  `password_hash`       VARCHAR(255) NOT NULL,
  `email_verified`      TINYINT(1)   DEFAULT 0,
  `verify_token`        VARCHAR(64)  DEFAULT NULL,
  `created_at`          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
