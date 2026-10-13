-- ============================================================================
-- Balangoda Municipal Council Utility Warning System
-- Resident Verification & Registry Schema
-- Designed for Local XAMPP (MySQL/MariaDB) and InfinityFree Cloud Hosting
-- ============================================================================

-- Table 1: council_registry
-- Pre-loaded municipal registry dataset from official CEB/NWSDB utilities
CREATE TABLE IF NOT EXISTS `council_registry` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NULL,
  `phone_number` VARCHAR(20) NULL,
  `residential_address` TEXT NULL,
  `gn_division` VARCHAR(100) NULL,
  `electricity_account_no` VARCHAR(50) NULL,
  `water_account_no` VARCHAR(50) NULL,
  `service_preferences` TEXT NULL,
  `registered_status` ENUM('unclaimed', 'claimed') NOT NULL DEFAULT 'unclaimed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_registry_electricity` (`electricity_account_no`),
  INDEX `idx_registry_water` (`water_account_no`),
  INDEX `idx_registry_status` (`registered_status`),
  INDEX `idx_registry_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: residents
-- Verified registered citizens with self-created credentials and portal access
CREATE TABLE IF NOT EXISTS `residents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `registry_id` INT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `physical_address` TEXT NOT NULL,
  `electricity_account_no` VARCHAR(50) NULL,
  `water_account_no` VARCHAR(50) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `verification_status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'verified',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_resident_email` (`email`),
  INDEX `idx_resident_registry_id` (`registry_id`),
  INDEX `idx_resident_electricity` (`electricity_account_no`),
  INDEX `idx_resident_water` (`water_account_no`),
  CONSTRAINT `fk_residents_council_registry` 
    FOREIGN KEY (`registry_id`) 
    REFERENCES `council_registry` (`id`) 
    ON DELETE SET NULL 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
