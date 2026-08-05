-- ============================================================
-- Stocqify — Complete Production Schema
-- MySQL 5.7+ / MariaDB 10.3+
--
-- IMPORT INSTRUCTIONS (Hostinger phpMyAdmin):
--   1. Create a new database in your hosting control panel.
--   2. Open phpMyAdmin, select that database.
--   3. Click Import → choose this file → Go.
--   Do NOT export/re-import via phpMyAdmin (causes duplicate FK errors).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE            = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone           = "+00:00";

-- ============================================================
-- BUSINESSES
-- ============================================================
CREATE TABLE IF NOT EXISTS `businesses` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(200)  NOT NULL,
  `address`       VARCHAR(500)  DEFAULT NULL,
  `phone`         VARCHAR(20)   DEFAULT NULL,
  `email`         VARCHAR(100)  DEFAULT NULL,
  `logo`          VARCHAR(255)  DEFAULT NULL,
  `currency`      VARCHAR(10)   NOT NULL DEFAULT 'USD',
  `business_type` ENUM('products','services') NOT NULL DEFAULT 'products',
  `country`       VARCHAR(100)  DEFAULT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ROLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL,
  `slug`        VARCHAR(50)  NOT NULL,
  `permissions` JSON         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id`           INT UNSIGNED DEFAULT NULL,
  `role_id`               INT UNSIGNED NOT NULL,
  `name`                  VARCHAR(150) NOT NULL,
  `email`                 VARCHAR(100) NOT NULL,
  `phone`                 VARCHAR(20)  DEFAULT NULL,
  `password`              VARCHAR(255) NOT NULL,
  `avatar`                VARCHAR(255) DEFAULT NULL,
  `force_password_change` TINYINT(1)   NOT NULL DEFAULT 0,
  `email_verified_at`     DATETIME     DEFAULT NULL,
  `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`            TIMESTAMP    NULL DEFAULT NULL,
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_business` (`business_id`),
  KEY `fk_users_role`     (`role_id`),
  CONSTRAINT `fk_users_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role`     FOREIGN KEY (`role_id`)     REFERENCES `roles`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CUSTOMERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`   INT UNSIGNED  NOT NULL,
  `name`          VARCHAR(200)  NOT NULL,
  `phone`         VARCHAR(20)   DEFAULT NULL,
  `email`         VARCHAR(100)  DEFAULT NULL,
  `address`       TEXT          DEFAULT NULL,
  `business_name` VARCHAR(200)  DEFAULT NULL,
  `credit_limit`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes`         TEXT          DEFAULT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_customers_business` (`business_id`),
  CONSTRAINT `fk_customers_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CATEGORIES (Product Categories)
-- ============================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_categories_business` (`business_id`),
  CONSTRAINT `fk_categories_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`    INT UNSIGNED  NOT NULL,
  `category_id`    INT UNSIGNED  DEFAULT NULL,
  `name`           VARCHAR(200)  NOT NULL,
  `sku`            VARCHAR(100)  DEFAULT NULL,
  `description`    TEXT          DEFAULT NULL,
  `image`          VARCHAR(255)  DEFAULT NULL,
  `unit`           VARCHAR(50)   DEFAULT 'piece',
  `cost_price`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `selling_price`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `reorder_level`  DECIMAL(15,2) NOT NULL DEFAULT 5.00,
  `max_stock`      DECIMAL(15,2) DEFAULT NULL,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_products_business` (`business_id`),
  KEY `fk_products_category` (`category_id`),
  CONSTRAINT `fk_products_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INVENTORY TRANSACTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`     INT UNSIGNED  NOT NULL,
  `product_id`      INT UNSIGNED  NOT NULL,
  `user_id`         INT UNSIGNED  DEFAULT NULL,
  `type`            ENUM('purchase','sale','adjustment','return','damage') NOT NULL,
  `quantity`        DECIMAL(15,2) NOT NULL,
  `before_quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `after_quantity`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `reference`       VARCHAR(100)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_inv_business` (`business_id`),
  KEY `fk_inv_product`  (`product_id`),
  CONSTRAINT `fk_inv_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_inv_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`   (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SALES
-- ============================================================
CREATE TABLE IF NOT EXISTS `sales` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`    INT UNSIGNED  NOT NULL,
  `customer_id`    INT UNSIGNED  DEFAULT NULL,
  `walkin_name`    VARCHAR(150)  DEFAULT NULL,
  `user_id`        INT UNSIGNED  NOT NULL,
  `invoice_number` VARCHAR(50)   NOT NULL,
  `sale_type`      ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  `subtotal`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount`DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance_due`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer','mixed') DEFAULT 'cash',
  `payment_status` ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  `notes`          TEXT          DEFAULT NULL,
  `sale_date`      DATE          NOT NULL,
  `due_date`       DATE          DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`, `business_id`),
  KEY `fk_sales_business`  (`business_id`),
  KEY `fk_sales_customer`  (`customer_id`),
  KEY `fk_sales_user`      (`user_id`),
  CONSTRAINT `fk_sales_business`  FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_sales_customer`  FOREIGN KEY (`customer_id`) REFERENCES `customers`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_user`      FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SALE ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sale_id`    INT UNSIGNED  NOT NULL,
  `product_id` INT UNSIGNED  NOT NULL,
  `quantity`   DECIMAL(15,2) NOT NULL,
  `unit_price` DECIMAL(15,2) NOT NULL,
  `cost_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total`      DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_si_sale`    (`sale_id`),
  KEY `fk_si_product` (`product_id`),
  CONSTRAINT `fk_si_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DEBTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `debts` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`     INT UNSIGNED  NOT NULL,
  `customer_id`     INT UNSIGNED  DEFAULT NULL,
  `sale_id`         INT UNSIGNED  DEFAULT NULL,
  `original_amount` DECIMAL(15,2) NOT NULL,
  `amount_paid`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance`         DECIMAL(15,2) NOT NULL,
  `due_date`        DATE          DEFAULT NULL,
  `status`          ENUM('active','partial','paid','overdue','written_off') NOT NULL DEFAULT 'active',
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_debts_business`  (`business_id`),
  KEY `fk_debts_customer`  (`customer_id`),
  KEY `fk_debts_sale`      (`sale_id`),
  CONSTRAINT `fk_debts_business`  FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_debts_customer`  FOREIGN KEY (`customer_id`) REFERENCES `customers`  (`id`),
  CONSTRAINT `fk_debts_sale`      FOREIGN KEY (`sale_id`)     REFERENCES `sales`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DEBT PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `debt_payments` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `debt_id`          INT UNSIGNED  NOT NULL,
  `business_id`      INT UNSIGNED  NOT NULL,
  `user_id`          INT UNSIGNED  NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `payment_method`   ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `payment_date`     DATE          NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_dp_debt`     (`debt_id`),
  KEY `fk_dp_business` (`business_id`),
  KEY `fk_dp_user`     (`user_id`),
  CONSTRAINT `fk_dp_debt`     FOREIGN KEY (`debt_id`)     REFERENCES `debts`     (`id`),
  CONSTRAINT `fk_dp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`),
  CONSTRAINT `fk_dp_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`     (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PAYMENTS (General / Sale payments)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`      INT UNSIGNED  NOT NULL,
  `sale_id`          INT UNSIGNED  DEFAULT NULL,
  `service_order_id` INT UNSIGNED  DEFAULT NULL,
  `customer_id`      INT UNSIGNED  DEFAULT NULL,
  `user_id`          INT UNSIGNED  NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `payment_method`   ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `payment_date`     DATE          NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pay_business`  (`business_id`),
  KEY `fk_pay_sale`      (`sale_id`),
  KEY `fk_pay_customer`  (`customer_id`),
  KEY `fk_pay_user`      (`user_id`),
  CONSTRAINT `fk_pay_business`  FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_pay_sale`      FOREIGN KEY (`sale_id`)     REFERENCES `sales`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_customer`  FOREIGN KEY (`customer_id`) REFERENCES `customers`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_user`      FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EXPENSE CATEGORIES
-- ============================================================
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_expcat_business` (`business_id`),
  CONSTRAINT `fk_expcat_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EXPENSES
-- ============================================================
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`      INT UNSIGNED  NOT NULL,
  `category_id`      INT UNSIGNED  DEFAULT NULL,
  `user_id`          INT UNSIGNED  NOT NULL,
  `title`            VARCHAR(200)  NOT NULL,
  `description`      TEXT          DEFAULT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `payment_method`   ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `expense_date`     DATE          NOT NULL,
  `receipt_path`     VARCHAR(255)  DEFAULT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_exp_business` (`business_id`),
  KEY `fk_exp_category` (`category_id`),
  KEY `fk_exp_user`     (`user_id`),
  CONSTRAINT `fk_exp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`       (`id`),
  CONSTRAINT `fk_exp_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_exp_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`            (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INCOME
-- ============================================================
CREATE TABLE IF NOT EXISTS `income` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`      INT UNSIGNED  NOT NULL,
  `user_id`          INT UNSIGNED  NOT NULL,
  `title`            VARCHAR(200)  NOT NULL,
  `description`      TEXT          DEFAULT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `payment_method`   ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `income_date`      DATE          NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_inc_business` (`business_id`),
  KEY `fk_inc_user`     (`user_id`),
  CONSTRAINT `fk_inc_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_inc_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SERVICES
-- ============================================================
CREATE TABLE IF NOT EXISTS `services` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`      INT UNSIGNED  NOT NULL,
  `category_id`      INT UNSIGNED  DEFAULT NULL,
  `name`             VARCHAR(255)  NOT NULL,
  `description`      TEXT          DEFAULT NULL,
  `price`            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `price_type`       ENUM('fixed','hourly','custom') NOT NULL DEFAULT 'fixed',
  `duration_minutes` INT UNSIGNED  DEFAULT NULL,
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_svc_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SERVICE ORDERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `service_orders` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`    INT UNSIGNED  NOT NULL,
  `customer_id`    INT UNSIGNED  DEFAULT NULL,
  `walkin_name`    VARCHAR(255)  DEFAULT NULL,
  `walkin_phone`   VARCHAR(50)   DEFAULT NULL,
  `order_number`   VARCHAR(50)   NOT NULL DEFAULT '',
  `status`         ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `scheduled_at`   DATETIME      DEFAULT NULL,
  `completed_at`   DATETIME      DEFAULT NULL,
  `subtotal`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount`DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance_due`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(50)   DEFAULT 'cash',
  `notes`          TEXT          DEFAULT NULL,
  `user_id`        INT UNSIGNED  DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_so_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SERVICE ORDER ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `service_order_items` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED  NOT NULL,
  `service_id`   INT UNSIGNED  DEFAULT NULL,
  `service_name` VARCHAR(255)  NOT NULL,
  `unit_price`   DECIMAL(15,2) NOT NULL,
  `quantity`     DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `discount`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total`        DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_soi_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `type`       VARCHAR(30)   NOT NULL DEFAULT 'info',
  `title`      VARCHAR(150)  NOT NULL,
  `body`       TEXT          DEFAULT NULL,
  `link`       VARCHAR(500)  DEFAULT NULL,
  `is_read`    TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_read` (`user_id`, `is_read`),
  KEY `created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BUSINESS FEATURES
-- ============================================================
CREATE TABLE IF NOT EXISTS `business_features` (
  `business_id`  INT UNSIGNED NOT NULL,
  `feature_key`  VARCHAR(60)  NOT NULL,
  `status`       ENUM('enabled','disabled','coming_soon') NOT NULL DEFAULT 'enabled',
  PRIMARY KEY (`business_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BUSINESS SUBSCRIPTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `business_subscriptions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED NOT NULL,
  `plan`        ENUM('free','starter','professional','enterprise') NOT NULL DEFAULT 'free',
  `status`      ENUM('trial','active','expired','suspended','cancelled') NOT NULL DEFAULT 'trial',
  `starts_at`   DATE         NOT NULL,
  `expires_at`  DATE         DEFAULT NULL,
  `notes`       TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `biz_id` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SYSTEM SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPORT TICKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id`   INT UNSIGNED DEFAULT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `subject`       VARCHAR(255) NOT NULL,
  `message`       TEXT         NOT NULL,
  `priority`      ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status`        ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `admin_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `business_read` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_st_business`  (`business_id`),
  KEY `idx_st_status`    (`status`),
  KEY `idx_st_admin_read`(`admin_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPORT REPLIES
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_replies` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`     INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `message`       TEXT         NOT NULL,
  `is_admin_reply`TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_ticket` (`ticket_id`),
  CONSTRAINT `fk_support_replies_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- AUDIT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED DEFAULT NULL,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `module`      VARCHAR(50)  NOT NULL,
  `record_id`   INT UNSIGNED DEFAULT NULL,
  `old_values`  JSON         DEFAULT NULL,
  `new_values`  JSON         DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(500) DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_business` (`business_id`),
  KEY `idx_audit_user`     (`user_id`),
  KEY `idx_audit_module`   (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSWORD RESETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_token` (`token`),
  KEY `idx_pr_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- EMAIL VERIFICATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_token` (`token`),
  KEY `idx_ev_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LANDING — DEMO REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `landing_demos` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(100) DEFAULT NULL,
  `email`            VARCHAR(255) DEFAULT NULL,
  `business_name`    VARCHAR(150) DEFAULT NULL,
  `phone`            VARCHAR(30)  DEFAULT NULL,
  `message`          TEXT         DEFAULT NULL,
  `status`           VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `slot_date`        DATE         DEFAULT NULL,
  `slot_time`        TIME         DEFAULT NULL,
  `slot_notes`       TEXT         DEFAULT NULL,
  `slot_assigned_at` TIMESTAMP    NULL DEFAULT NULL,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LANDING — NEWSLETTER SUBSCRIBERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `landing_newsletter` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LANDING — CONTACT MESSAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS `landing_contacts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) DEFAULT NULL,
  `email`      VARCHAR(255) DEFAULT NULL,
  `subject`    VARCHAR(200) DEFAULT NULL,
  `message`    TEXT         DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPLIERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`     INT UNSIGNED  NOT NULL,
  `name`            VARCHAR(200)  NOT NULL,
  `phone`           VARCHAR(30)   DEFAULT NULL,
  `email`           VARCHAR(100)  DEFAULT NULL,
  `address`         TEXT          DEFAULT NULL,
  `contact_person`  VARCHAR(150)  DEFAULT NULL,
  `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes`           TEXT          DEFAULT NULL,
  `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_biz` (`business_id`),
  CONSTRAINT `fk_sup_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPLIER PURCHASES (Accounts Payable)
-- ============================================================
CREATE TABLE IF NOT EXISTS `supplier_purchases` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`    INT UNSIGNED  NOT NULL,
  `supplier_id`    INT UNSIGNED  NOT NULL,
  `user_id`        INT UNSIGNED  NOT NULL,
  `reference`      VARCHAR(100)  DEFAULT NULL,
  `description`    VARCHAR(500)  NOT NULL,
  `total_amount`   DECIMAL(15,2) NOT NULL,
  `amount_paid`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `purchase_date`  DATE          NOT NULL,
  `due_date`       DATE          DEFAULT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_suppur_biz`  (`business_id`),
  KEY `idx_suppur_sup`  (`supplier_id`),
  KEY `idx_suppur_user` (`user_id`),
  CONSTRAINT `fk_suppur_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_suppur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_suppur_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SUPPLIER PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`      INT UNSIGNED  NOT NULL,
  `supplier_id`      INT UNSIGNED  NOT NULL,
  `purchase_id`      INT UNSIGNED  DEFAULT NULL,
  `user_id`          INT UNSIGNED  NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `payment_method`   ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `reference_number` VARCHAR(100)  DEFAULT NULL,
  `notes`            TEXT          DEFAULT NULL,
  `payment_date`     DATE          NOT NULL,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_suppay_biz` (`business_id`),
  KEY `idx_suppay_sup` (`supplier_id`),
  KEY `idx_suppay_pur` (`purchase_id`),
  CONSTRAINT `fk_suppay_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`        (`id`),
  CONSTRAINT `fk_suppay_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`         (`id`),
  CONSTRAINT `fk_suppay_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `supplier_purchases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DRAWINGS (Owner Withdrawals)
-- ============================================================
CREATE TABLE IF NOT EXISTS `drawings` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id`    INT UNSIGNED  NOT NULL,
  `user_id`        INT UNSIGNED  NOT NULL,
  `amount`         DECIMAL(15,2) NOT NULL,
  `description`    VARCHAR(300)  NOT NULL,
  `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
  `drawing_date`   DATE          NOT NULL,
  `notes`          TEXT          DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_draw_biz`  (`business_id`),
  KEY `idx_draw_user` (`user_id`),
  CONSTRAINT `fk_draw_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_draw_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CAPITAL ACCOUNTS (Opening Capital & Contributions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `capital_accounts` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `business_id` INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `type`        ENUM('opening','injection') NOT NULL DEFAULT 'opening',
  `amount`      DECIMAL(15,2) NOT NULL,
  `description` VARCHAR(300)  DEFAULT NULL,
  `entry_date`  DATE          NOT NULL,
  `notes`       TEXT          DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cap_biz` (`business_id`),
  CONSTRAINT `fk_cap_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DEFAULT DATA
-- ============================================================
INSERT IGNORE INTO `roles` (`name`, `slug`, `permissions`) VALUES
  ('Administrator', 'admin',    '["all"]'),
  ('Manager',       'manager',  '["sales","inventory","customers","reports"]'),
  ('Staff',         'staff',    '["sales","customers"]');

SET FOREIGN_KEY_CHECKS = 1;
