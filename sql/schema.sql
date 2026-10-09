-- ===========================================================================
-- Diwan MVP - full schema (PLAN-000 shared foundation)
-- Spec: docs/ai-sdlc/specs/SPEC-000-shared.md
-- MySQL 8 / MariaDB 10.4+ (CHECK constraints required), InnoDB, utf8mb4
--
-- Rules enforced here:
--   * every tenant table carries hub_id + index (INV-01)
--   * status columns are VARCHAR(20) + CHECK, not ENUM, so later plans can add
--     a status without ALTER
--   * NO partial UNIQUE on bookings (INV-04 forbids it) - double booking is
--     prevented by the transaction in PLAN-004
--   * payments_cash UNIQUE(booking_id) = "no duplicate payment" at DB level
--
-- Import: phpMyAdmin > select this file > Go  (DROP section wipes the old data)
-- ===========================================================================

CREATE DATABASE IF NOT EXISTS `diwan`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `diwan`;

SET NAMES utf8mb4;

-- Greenfield schema: drop so re-import always works. users <-> hubs reference
-- each other, so the FK check is lifted for the drop block only.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `payments_cash`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `emergency_closures`;
DROP TABLE IF EXISTS `shifts`;
DROP TABLE IF EXISTS `seats`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `branches`;
DROP TABLE IF EXISTS `hub_subscriptions`;
DROP TABLE IF EXISTS `hubs`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------------------------
-- users - one table for all 4 roles (super_admin, hub_admin, staff, client)
-- --------------------------------------------------------------------------
CREATE TABLE `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hub_id`        INT UNSIGNED    NULL,                    -- NULL for super_admin / client
    `role`          VARCHAR(20)     NOT NULL,
    `name`          VARCHAR(100)    NOT NULL,
    `phone`         VARCHAR(20)     NOT NULL,                -- login identifier, unique
    `password_hash` VARCHAR(255)    NOT NULL,                -- password_hash() / password_verify()
    `active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_phone` (`phone`),
    KEY `idx_users_hub_role` (`hub_id`, `role`),
    CONSTRAINT `chk_users_role` CHECK (`role` IN ('super_admin', 'hub_admin', 'staff', 'client'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- hubs - the tenant itself. status drives search visibility (SPEC-001)
-- --------------------------------------------------------------------------
CREATE TABLE `hubs` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT UNSIGNED  NULL,
    `name`          VARCHAR(120) NOT NULL,
    `city`          VARCHAR(80)  NOT NULL,
    `phone`         VARCHAR(20)  NOT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    `note`          VARCHAR(255) NULL,                        -- reject / suspend reason
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hubs_status_city` (`status`, `city`),
    CONSTRAINT `chk_hubs_status` CHECK (`status` IN ('pending', 'active', 'rejected', 'suspended')),
    CONSTRAINT `fk_hubs_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- users.hub_id FK added after `hubs` exists (circular reference).
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------------------------
-- hub_subscriptions - INV-03 gate: hubs.status='active' AND end_date >= TODAY
-- No CHECK(end_date >= start_date): PLAN-001 setHubStatus() expires a
-- subscription by setting end_date = CURDATE() - 1 DAY, so an expired row
-- legitimately has end_date < start_date. INV-03 reads end_date only.
-- --------------------------------------------------------------------------
CREATE TABLE `hub_subscriptions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hub_id`     INT UNSIGNED NOT NULL,
    `start_date` DATE         NOT NULL,
    `end_date`   DATE         NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sub_hub_end` (`hub_id`, `end_date`),
    CONSTRAINT `fk_sub_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- branches - MVP keeps 1 branch per hub, table stays tenant keyed
-- Card fields required by AGENTS.md: electricity, internet, generator/solar.
-- --------------------------------------------------------------------------
CREATE TABLE `branches` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hub_id`             INT UNSIGNED NOT NULL,
    `name`               VARCHAR(120) NOT NULL,
    `city`               VARCHAR(80)  NOT NULL,
    `address`            VARCHAR(255) NULL,
    `phone`              VARCHAR(20)  NULL,
    `electricity_status` VARCHAR(20)  NOT NULL DEFAULT 'none',   -- none | grid | generator | solar
    `internet_strength`  VARCHAR(20)  NOT NULL DEFAULT 'none',   -- none | weak | good | excellent
    `power_source`       VARCHAR(20)  NOT NULL DEFAULT 'none',   -- none | generator | solar
    `price_per_hour`     DECIMAL(8, 2) NOT NULL DEFAULT 0.00,    -- PLAN-002: single price, no tiers
    `active`             TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_branches_hub_active` (`hub_id`, `active`),
    KEY `idx_branches_hub_city` (`hub_id`, `city`, `active`),   -- PLAN-003 search by city
    CONSTRAINT `fk_branch_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_branch_electricity` CHECK (`electricity_status` IN ('none', 'grid', 'generator', 'solar')),
    CONSTRAINT `chk_branch_internet` CHECK (`internet_strength` IN ('none', 'weak', 'good', 'excellent')),
    CONSTRAINT `chk_branch_power` CHECK (`power_source` IN ('none', 'generator', 'solar'))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- rooms - kept for the schema contract; hourly room tiers are OUT of MVP scope
-- --------------------------------------------------------------------------
CREATE TABLE `rooms` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `hub_id`       INT UNSIGNED   NOT NULL,
    `branch_id`    INT UNSIGNED   NOT NULL,
    `name`         VARCHAR(120)   NOT NULL,
    `capacity`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `price_hourly` DECIMAL(8, 2)  NOT NULL DEFAULT 0.00,
    `active`       TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rooms_hub_branch` (`hub_id`, `branch_id`, `active`),
    CONSTRAINT `fk_room_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_room_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- seats - seat_code unique per branch; active=1 is the occupancy denominator
-- --------------------------------------------------------------------------
CREATE TABLE `seats` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `hub_id`     INT UNSIGNED  NOT NULL,
    `branch_id`  INT UNSIGNED  NOT NULL,
    `room_id`    INT UNSIGNED  NULL,
    `seat_code`  VARCHAR(30)   NOT NULL,
    `price`      DECIMAL(8, 2) NOT NULL DEFAULT 0.00,          -- price per hour, ILS
    `active`     TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seats_branch_code` (`branch_id`, `seat_code`),
    KEY `idx_seats_hub_branch` (`hub_id`, `branch_id`, `active`),
    CONSTRAINT `fk_seat_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seat_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_seat_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- shifts - configurable per hub (decision 2026-10-09), >= 2 active per branch
-- Overlap is rejected by createShift() in PLAN-002, start<end by this CHECK.
-- --------------------------------------------------------------------------
CREATE TABLE `shifts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hub_id`     INT UNSIGNED NOT NULL,
    `branch_id`  INT UNSIGNED NOT NULL,
    `name_ar`    VARCHAR(60)  NOT NULL,
    `start_time` TIME         NOT NULL,
    `end_time`   TIME         NOT NULL,
    `active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shifts_branch_times` (`branch_id`, `start_time`, `end_time`),
    KEY `idx_shifts_hub_branch` (`hub_id`, `branch_id`, `active`),
    CONSTRAINT `fk_shift_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shift_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_shift_times` CHECK (`start_time` < `end_time`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- bookings - INV-04: NO unique on (seat,date,shift) here, the transaction in
-- createBooking() uses SELECT ... FOR UPDATE instead.
-- qr_code is the pre-generated offline attendance string (SPEC-005).
-- --------------------------------------------------------------------------
CREATE TABLE `bookings` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`    INT UNSIGNED NOT NULL,
    `hub_id`       INT UNSIGNED NOT NULL,
    `branch_id`    INT UNSIGNED NOT NULL,
    `seat_id`      INT UNSIGNED NOT NULL,
    `shift_id`     INT UNSIGNED NOT NULL,
    `booking_date` DATE         NOT NULL,
    `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending',
    `qr_code`      VARCHAR(40)  NOT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bookings_qr` (`qr_code`),
    KEY `idx_bookings_seat_date_shift` (`seat_id`, `booking_date`, `shift_id`, `status`),
    KEY `idx_bookings_client` (`client_id`),
    KEY `idx_bookings_hub` (`hub_id`),
    CONSTRAINT `chk_bookings_status` CHECK (`status` IN ('pending', 'confirmed', 'attended', 'cancelled')),
    CONSTRAINT `fk_booking_client` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_seat` FOREIGN KEY (`seat_id`) REFERENCES `seats` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- payments_cash - cash on arrival only (SPEC-005), UNIQUE(booking_id) makes a
-- duplicate payment impossible even under a double QR scan.
-- --------------------------------------------------------------------------
CREATE TABLE `payments_cash` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `booking_id`  INT UNSIGNED  NOT NULL,
    `hub_id`      INT UNSIGNED  NOT NULL,
    `amount`      DECIMAL(10, 2) NOT NULL,
    `received_by` INT UNSIGNED  NOT NULL,
    `received_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_booking` (`booking_id`),
    KEY `idx_payment_hub` (`hub_id`, `received_at`),
    CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- emergency_closures - one row per branch per closed date (SPEC-006)
-- --------------------------------------------------------------------------
CREATE TABLE `emergency_closures` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hub_id`     INT UNSIGNED NOT NULL,
    `branch_id`  INT UNSIGNED NOT NULL,
    `close_date` DATE         NOT NULL,
    `reason`     VARCHAR(200) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_closure_branch_date` (`branch_id`, `close_date`),
    KEY `idx_closure_hub_date` (`hub_id`, `close_date`),
    CONSTRAINT `fk_closure_hub` FOREIGN KEY (`hub_id`) REFERENCES `hubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_closure_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_closure_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ===========================================================================
-- SEED - 1 super_admin (step 5 of PLAN-000).
-- DEV CREDENTIALS: phone 0599000000 / password Diwan@2026
-- CHANGE THE PASSWORD BEFORE PUTTING THIS ON REAL SHARED HOSTING.
-- ===========================================================================
INSERT INTO `users` (`hub_id`, `role`, `name`, `phone`, `password_hash`, `active`)
VALUES (NULL, 'super_admin', 'مدير المنصة', '0599000000',
        '$2y$10$t/dY4ZJQjPvPvs4XrQ/J/.nuqlbSrlENFq4nkCnFvneB8bmFTTjWG', 1);