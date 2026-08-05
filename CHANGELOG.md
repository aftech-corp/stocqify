# CHANGELOG — Stocqify SME Platform

> **Purpose:** Track every file and database change made to the platform so the production
> environment can be kept in sync. When deploying an update, run **all SQL in the version
> sections that are newer than your current production version**, in order (oldest first).
>
> **Versions:** v1.0.0 → v1.1.0 → v1.2.0

---

## [v1.2.0] — 2026-08-05 — Plan Limits, User Feature Access & Production Prep

### Files Changed

| Status | File | Change |
|--------|------|--------|
| NEW    | `app/includes/feature_definitions.php` | Central registry of all feature keys (shared by subscriptions and users modules) |
| MOD    | `modules/admin/subscriptions.php` | Added quota columns to plan form; auto-include new features in plans; updated save handler |
| MOD    | `modules/admin/users.php` | Full-width form; per-user feature access panel; `user_feature_permissions` persistence |
| MOD    | `app/includes/functions.php` | Added `planLimitCheck()`; updated `featureStatus()` to check per-user overrides first |
| MOD    | `modules/branches/add.php` | Enforce `max_branches` plan quota before INSERT |
| MOD    | `modules/sales/add.php` | Enforce `max_orders_per_month` plan quota before INSERT |

### Database Changes

#### Table: `subscription_plans` — Add quota/limit columns

**Schema before v1.2.0:**
```sql
CREATE TABLE `subscription_plans` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(50)  NOT NULL UNIQUE,
  `description` TEXT,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `is_popular`  TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Migration SQL (run on production):**
```sql
ALTER TABLE subscription_plans ADD COLUMN max_branches         INT UNSIGNED DEFAULT NULL AFTER sort_order;
ALTER TABLE subscription_plans ADD COLUMN max_orders_per_month INT UNSIGNED DEFAULT NULL AFTER max_branches;
ALTER TABLE subscription_plans ADD COLUMN max_products         INT UNSIGNED DEFAULT NULL AFTER max_orders_per_month;
ALTER TABLE subscription_plans ADD COLUMN max_customers        INT UNSIGNED DEFAULT NULL AFTER max_products;
ALTER TABLE subscription_plans ADD COLUMN max_users            INT UNSIGNED DEFAULT NULL AFTER max_customers;
```
> NULL = unlimited (no enforcement). Only set a value to impose a cap.

**Schema after v1.2.0:**
```sql
CREATE TABLE `subscription_plans` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`                  VARCHAR(100) NOT NULL,
  `slug`                  VARCHAR(50)  NOT NULL UNIQUE,
  `description`           TEXT,
  `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
  `is_popular`            TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`            SMALLINT     NOT NULL DEFAULT 0,
  `max_branches`          INT UNSIGNED DEFAULT NULL,
  `max_orders_per_month`  INT UNSIGNED DEFAULT NULL,
  `max_products`          INT UNSIGNED DEFAULT NULL,
  `max_customers`         INT UNSIGNED DEFAULT NULL,
  `max_users`             INT UNSIGNED DEFAULT NULL,
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `user_feature_permissions` — NEW in v1.2.0

**Schema before v1.2.0:** Table did not exist.

**Migration SQL (run on production):**
```sql
CREATE TABLE IF NOT EXISTS `user_feature_permissions` (
  `user_id`     INT UNSIGNED NOT NULL,
  `feature_key` VARCHAR(100) NOT NULL,
  `status`      ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
  PRIMARY KEY (`user_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Schema after v1.2.0:** Table as above.

---

---

## [v1.1.0] — 2026-08-03 — Subscriptions, Demo Fee, Landing Page & UX Fixes

### Files Changed

| Status | File | Change |
|--------|------|--------|
| NEW    | `modules/admin/subscriptions.php` | Full subscriptions & plan management for admin |
| NEW    | `modules/admin/demos.php` | Admin view/manage demo booking requests |
| NEW    | `public/landing.php` | Public marketing + demo booking page |
| MOD    | `modules/admin/settings.php` | Added "Demo Fee" settings tab |
| MOD    | `app/includes/auth.php` | `requireLogin()` stores redirect in session instead of URL param |
| MOD    | `public/login.php` | Reads redirect from `$_SESSION['login_redirect']` on success |
| MOD    | `app/includes/header.php` | Page title format changed to `APP_NAME | Page Title` |
| MOD    | `app/includes/sidebar.php` | Subscription notification bar (countdown / upgrade); notification bell; branch switcher |
| MOD    | `public/force_change_password.php` | Updated page title format |
| MOD    | `public/forgot_password.php` | Updated page title format |
| MOD    | `public/reset_password.php` | Updated page title format |
| MOD    | `public/verify_email.php` | Updated page title format |
| MOD    | `public/index.php` | Root redirect: logged-in → dashboard, guest → landing |

### Database Changes

#### Table: `users` — Add auth columns

**Schema before v1.1.0 (original):**
```sql
CREATE TABLE `users` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED DEFAULT NULL,
  `role_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `email`       VARCHAR(255) NOT NULL UNIQUE,
  `phone`       VARCHAR(30)  DEFAULT NULL,
  `password`    VARCHAR(255) NOT NULL,
  `avatar`      VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`  DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Migration SQL:**
```sql
ALTER TABLE users ADD COLUMN force_password_change TINYINT     NOT NULL DEFAULT 0    AFTER is_active;
ALTER TABLE users ADD COLUMN email_verified_at     DATETIME    DEFAULT NULL           AFTER force_password_change;
```

**Schema after v1.1.0:**
```sql
CREATE TABLE `users` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`           INT UNSIGNED DEFAULT NULL,
  `role_id`               INT UNSIGNED NOT NULL,
  `name`                  VARCHAR(150) NOT NULL,
  `email`                 VARCHAR(255) NOT NULL UNIQUE,
  `phone`                 VARCHAR(30)  DEFAULT NULL,
  `password`              VARCHAR(255) NOT NULL,
  `avatar`                VARCHAR(255) DEFAULT NULL,
  `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
  `force_password_change` TINYINT      NOT NULL DEFAULT 0,
  `email_verified_at`     DATETIME     DEFAULT NULL,
  `last_login`            DATETIME     DEFAULT NULL,
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `businesses` — Add business type

**Migration SQL:**
```sql
ALTER TABLE businesses ADD COLUMN business_type ENUM('products','services') NOT NULL DEFAULT 'products' AFTER currency;
```

---

#### Table: `email_verifications` — NEW in v1.1.0

```sql
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
```

---

#### Table: `password_resets` — NEW in v1.1.0

```sql
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
```

---

#### Table: `subscription_plans` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(50)  NOT NULL UNIQUE,
  `description` TEXT,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `is_popular`  TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
> Apply the v1.2.0 quota columns immediately after if deploying fresh.

---

#### Table: `subscription_plan_prices` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `subscription_plan_prices` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `plan_id`       INT UNSIGNED NOT NULL,
  `currency_code` VARCHAR(10)  NOT NULL,
  `monthly_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `yearly_price`  DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY `plan_currency` (`plan_id`, `currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `subscription_plan_features` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `subscription_plan_features` (
  `plan_id`     INT UNSIGNED NOT NULL,
  `feature_key` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`plan_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `business_subscriptions` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `business_subscriptions` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`      INT UNSIGNED NOT NULL UNIQUE,
  `plan_id`          INT UNSIGNED NOT NULL DEFAULT 0,
  `status`           ENUM('active','trial','expired','cancelled') NOT NULL DEFAULT 'active',
  `billing_period`   ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `currency_code`    VARCHAR(10)    NOT NULL DEFAULT 'NLE',
  `amount_paid`      DECIMAL(14,2)  DEFAULT 0,
  `payment_method`   VARCHAR(50)    DEFAULT NULL,
  `payment_reference` VARCHAR(200)  DEFAULT NULL,
  `starts_at`        DATE           NOT NULL DEFAULT (CURRENT_DATE),
  `expires_at`       DATE           DEFAULT NULL,
  `notes`            TEXT           DEFAULT NULL,
  `created_by`       INT UNSIGNED   DEFAULT NULL,
  `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
> If upgrading a pre-existing `business_subscriptions` table (without most columns), run each
> `ALTER TABLE` below. Each is wrapped in a try/catch in PHP so it is safe to run idempotently.

```sql
-- Run only if upgrading from a minimal pre-existing business_subscriptions table
ALTER TABLE business_subscriptions ADD COLUMN plan_id         INT UNSIGNED NOT NULL DEFAULT 0         AFTER business_id;
ALTER TABLE business_subscriptions ADD COLUMN billing_period  ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER status;
ALTER TABLE business_subscriptions ADD COLUMN currency_code   VARCHAR(10) NOT NULL DEFAULT 'NLE'      AFTER billing_period;
ALTER TABLE business_subscriptions ADD COLUMN amount_paid     DECIMAL(14,2) DEFAULT 0                 AFTER currency_code;
ALTER TABLE business_subscriptions ADD COLUMN payment_method  VARCHAR(50) DEFAULT NULL               AFTER amount_paid;
ALTER TABLE business_subscriptions ADD COLUMN payment_reference VARCHAR(200) DEFAULT NULL            AFTER payment_method;
ALTER TABLE business_subscriptions ADD COLUMN starts_at       DATE NOT NULL DEFAULT '2024-01-01'      AFTER payment_reference;
ALTER TABLE business_subscriptions ADD COLUMN expires_at      DATE DEFAULT NULL                       AFTER starts_at;
ALTER TABLE business_subscriptions ADD COLUMN notes           TEXT DEFAULT NULL                       AFTER expires_at;
ALTER TABLE business_subscriptions ADD COLUMN created_by      INT UNSIGNED DEFAULT NULL               AFTER notes;
ALTER TABLE business_subscriptions ADD COLUMN updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Deduplicate (keep newest row per business) then enforce unique constraint
DELETE bs1 FROM business_subscriptions bs1
  INNER JOIN business_subscriptions bs2
    ON bs1.business_id = bs2.business_id AND bs1.id < bs2.id;
ALTER TABLE business_subscriptions ADD UNIQUE INDEX uidx_bsub_business (business_id);
```

---

#### Table: `business_features` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `business_features` (
  `business_id` INT UNSIGNED NOT NULL,
  `feature_key` VARCHAR(60)  NOT NULL,
  `status`      ENUM('enabled','disabled','coming_soon') NOT NULL DEFAULT 'enabled',
  PRIMARY KEY (`business_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `notifications` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       VARCHAR(30)  NOT NULL DEFAULT 'info',
  `title`      VARCHAR(150) NOT NULL,
  `body`       TEXT         DEFAULT NULL,
  `link`       VARCHAR(500) DEFAULT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_read` (`user_id`, `is_read`),
  KEY `created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `support_tickets` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_id`   INT UNSIGNED NULL DEFAULT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `subject`       VARCHAR(255) NOT NULL,
  `message`       TEXT         NOT NULL,
  `priority`      ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status`        ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `admin_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `business_read` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `support_replies` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `support_replies` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`      INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `message`        TEXT         NOT NULL,
  `is_admin_reply` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sr_ticket` (`ticket_id`),
  CONSTRAINT `fk_sr_ticket` FOREIGN KEY (`ticket_id`)
    REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `landing_demos` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `landing_demos` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(100)  DEFAULT NULL,
  `email`             VARCHAR(255)  DEFAULT NULL,
  `business_name`     VARCHAR(150)  DEFAULT NULL,
  `phone`             VARCHAR(30)   DEFAULT NULL,
  `country`           VARCHAR(100)  DEFAULT NULL,
  `language`          VARCHAR(50)   DEFAULT NULL,
  `payment_reference` VARCHAR(200)  DEFAULT NULL,
  `message`           TEXT          DEFAULT NULL,
  `status`            VARCHAR(20)   DEFAULT NULL,
  `created_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `landing_newsletter` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `landing_newsletter` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(255) UNIQUE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### Table: `landing_contacts` — NEW in v1.1.0

```sql
CREATE TABLE IF NOT EXISTS `landing_contacts` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) DEFAULT NULL,
  `email`      VARCHAR(255) DEFAULT NULL,
  `subject`    VARCHAR(200) DEFAULT NULL,
  `message`    TEXT         DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

#### `system_settings` data — Demo Fee keys inserted by settings form (no schema change)

```sql
-- These rows are written automatically when the admin saves Demo Fee settings.
-- You do not need to run this manually; it documents the expected keys.
-- INSERT INTO system_settings (`key`,`value`) VALUES
--   ('demo_fee_enabled',  '0'),
--   ('demo_fee_amount',   ''),
--   ('demo_fee_currency', ''),
--   ('demo_payment_instructions', '');
```

---

---

## [v1.0.0] — Initial Build — Core Platform

> All core tables were created by `public/setup.php` on first run.
> The schemas below are the canonical starting point for any fresh production deployment.

### Core Tables

```sql
-- Roles
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(50)  NOT NULL UNIQUE,
  `permissions` JSON         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Businesses
CREATE TABLE IF NOT EXISTS `businesses` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`      VARCHAR(200) NOT NULL,
  `email`     VARCHAR(255) DEFAULT NULL,
  `phone`     VARCHAR(30)  DEFAULT NULL,
  `address`   TEXT         DEFAULT NULL,
  `country`   VARCHAR(100) DEFAULT NULL,
  `currency`  VARCHAR(10)  NOT NULL DEFAULT 'NLE',
  `logo`      VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users (see v1.1.0 for additional columns)
CREATE TABLE IF NOT EXISTS `users` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED DEFAULT NULL,
  `role_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `email`       VARCHAR(255) NOT NULL UNIQUE,
  `phone`       VARCHAR(30)  DEFAULT NULL,
  `password`    VARCHAR(255) NOT NULL,
  `avatar`      VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`  DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Branches
CREATE TABLE IF NOT EXISTS `branches` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`     INT UNSIGNED NOT NULL,
  `name`            VARCHAR(150) NOT NULL,
  `code`            VARCHAR(30)  DEFAULT NULL UNIQUE,
  `address`         TEXT         DEFAULT NULL,
  `phone`           VARCHAR(30)  DEFAULT NULL,
  `email`           VARCHAR(255) DEFAULT NULL,
  `manager_user_id` INT UNSIGNED DEFAULT NULL,
  `manager_name`    VARCHAR(150) DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products
CREATE TABLE IF NOT EXISTS `products` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`     INT UNSIGNED NOT NULL,
  `branch_id`       INT UNSIGNED DEFAULT NULL,
  `category_id`     INT UNSIGNED DEFAULT NULL,
  `name`            VARCHAR(200) NOT NULL,
  `sku`             VARCHAR(100) DEFAULT NULL,
  `unit`            VARCHAR(30)  DEFAULT 'unit',
  `cost_price`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `selling_price`   DECIMAL(14,2) NOT NULL DEFAULT 0,
  `stock_quantity`  DECIMAL(14,3) NOT NULL DEFAULT 0,
  `reorder_level`   DECIMAL(14,3) NOT NULL DEFAULT 5,
  `description`     TEXT         DEFAULT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customers
CREATE TABLE IF NOT EXISTS `customers` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `branch_id`   INT UNSIGNED DEFAULT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `phone`       VARCHAR(30)  DEFAULT NULL,
  `email`       VARCHAR(255) DEFAULT NULL,
  `address`     TEXT         DEFAULT NULL,
  `notes`       TEXT         DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales
CREATE TABLE IF NOT EXISTS `sales` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`     INT UNSIGNED NOT NULL,
  `branch_id`       INT UNSIGNED DEFAULT NULL,
  `customer_id`     INT UNSIGNED DEFAULT NULL,
  `walkin_name`     VARCHAR(150) DEFAULT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `invoice_number`  VARCHAR(30)  NOT NULL UNIQUE,
  `sale_type`       ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  `subtotal`        DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_amount`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `amount_paid`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `balance_due`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_method`  VARCHAR(50)  NOT NULL DEFAULT 'cash',
  `payment_status`  ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid',
  `notes`           TEXT         DEFAULT NULL,
  `sale_date`       DATE         NOT NULL,
  `due_date`        DATE         DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sale Items
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sale_id`     INT UNSIGNED NOT NULL,
  `product_id`  INT UNSIGNED NOT NULL,
  `quantity`    DECIMAL(14,3) NOT NULL,
  `unit_price`  DECIMAL(14,2) NOT NULL,
  `cost_price`  DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total`       DECIMAL(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory Transactions
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`     INT UNSIGNED NOT NULL,
  `product_id`      INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `type`            VARCHAR(30)  NOT NULL,
  `quantity`        DECIMAL(14,3) NOT NULL,
  `before_quantity` DECIMAL(14,3) NOT NULL,
  `after_quantity`  DECIMAL(14,3) NOT NULL,
  `reference`       VARCHAR(100)  DEFAULT NULL,
  `notes`           TEXT          DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `branch_id`   INT UNSIGNED DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `amount`      DECIMAL(14,2) NOT NULL,
  `description` TEXT          DEFAULT NULL,
  `expense_date` DATE         NOT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expense Categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Income
CREATE TABLE IF NOT EXISTS `income` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `branch_id`   INT UNSIGNED DEFAULT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `amount`      DECIMAL(14,2) NOT NULL,
  `description` TEXT          DEFAULT NULL,
  `income_date` DATE          NOT NULL,
  `source`      VARCHAR(100)  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drawings (Owner Withdrawals)
CREATE TABLE IF NOT EXISTS `drawings` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`  INT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NOT NULL,
  `amount`       DECIMAL(14,2) NOT NULL,
  `description`  TEXT          DEFAULT NULL,
  `drawing_date` DATE          NOT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Debts
CREATE TABLE IF NOT EXISTS `debts` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`     INT UNSIGNED NOT NULL,
  `customer_id`     INT UNSIGNED NOT NULL,
  `sale_id`         INT UNSIGNED DEFAULT NULL,
  `original_amount` DECIMAL(14,2) NOT NULL,
  `amount_paid`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `balance`         DECIMAL(14,2) NOT NULL,
  `due_date`        DATE          DEFAULT NULL,
  `status`          ENUM('active','partial','paid') NOT NULL DEFAULT 'active',
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments
CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id`    INT UNSIGNED NOT NULL,
  `sale_id`        INT UNSIGNED DEFAULT NULL,
  `customer_id`    INT UNSIGNED DEFAULT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL,
  `payment_method` VARCHAR(50)  NOT NULL DEFAULT 'cash',
  `payment_date`   DATE         NOT NULL,
  `notes`          TEXT         DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System Settings (key-value store)
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(100) NOT NULL UNIQUE,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit Log
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(30)  NOT NULL,
  `table_name`  VARCHAR(60)  NOT NULL,
  `record_id`   INT UNSIGNED DEFAULT NULL,
  `old_data`    JSON         DEFAULT NULL,
  `new_data`    JSON         DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Production Deployment Checklist

### Before First Deploy
- [ ] Create `app/config/config.local.php` (gitignored) with production DB credentials and URLs:
  ```php
  <?php
  define('APP_URL',  'https://yourdomain.com/public');
  define('SITE_URL', 'https://yourdomain.com');
  define('APP_TIMEZONE', 'Africa/Freetown');
  ```
- [ ] Update `.htaccess` `RewriteBase /SME/` to `RewriteBase /` if deploying to domain root
- [ ] Run `public/setup.php` once to create the database and seed roles/admin user
- [ ] **Delete `public/setup.php`** immediately after successful setup
- [ ] **Delete `public/reset_admin.php`** — it is for local dev only
- [ ] Configure SMTP settings in Admin → Settings → Email
- [ ] Set up SSL (HTTPS) — required for `secure` session cookies

### When Upgrading Production
1. Back up the production database first
2. Upload changed files
3. Run the migration SQL for the new version(s) in the correct order
4. Verify the application loads without errors
5. Check `/login`, `/dashboard`, `/admin/subscriptions`

### Notes on SQL Compatibility
- `ADD COLUMN IF NOT EXISTS` syntax works on **MariaDB** only.
  The PHP code uses this inside `try/catch` for runtime safety,
  but for manual production migrations use `ADD COLUMN` without `IF NOT EXISTS`
  and run each statement once.
- `DEFAULT (CURRENT_DATE)` for DATE columns requires **MySQL 8.0.13+** or **MariaDB 10.2.7+**.
  On older MySQL, change to `DEFAULT '2024-01-01'` and update via PHP after insert.
