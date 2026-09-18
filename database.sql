-- Balangoda Utility Outage Warnings & Complaints Database Schema
CREATE DATABASE IF NOT EXISTS `balangoda_utility_warnings` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `balangoda_utility_warnings`;

-- 1. Admins Table
CREATE TABLE IF NOT EXISTS `admins` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(100) DEFAULT NULL UNIQUE,
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
