# Stocqify — SME Management Platform

**Version:** 1.0.0 · **Last Updated:** 2026-08-04 · **Live URL:** https://stocqify.com

> A multi-tenant SaaS platform for small and medium-sized businesses. Each business manages its own inventory, sales, customers, debts, finances, and reports. A single system administrator manages all businesses on the platform.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [Directory Structure](#3-directory-structure)
4. [Routing System](#4-routing-system)
5. [Configuration](#5-configuration)
6. [Authentication & Security](#6-authentication--security)
7. [Database Schema](#7-database-schema)
8. [Modules Reference](#8-modules-reference)
9. [Shared Components](#9-shared-components)
10. [API Endpoints](#10-api-endpoints)
11. [Deployment Guide](#11-deployment-guide)
12. [Security Notes](#12-security-notes)
13. [Changelog](#13-changelog)

---

## 1. Project Overview

Stocqify is a vanilla PHP web application (no framework) built for multi-tenant SME management. The platform operates on two tiers:

| Tier | Role | Scope |
|------|------|-------|
| **System Admin** | `admin` slug | Manages all businesses, users, subscriptions, and platform settings |
| **Business Users** | `owner`, `manager`, `sales_officer`, `accountant` | Manage one assigned business |

**Business types** determine which modules are available in the sidebar:
- **`products`** — inventory, sales, stock management
- **`services`** — service catalog, service orders (different workflow)

**Application constants:**
- `APP_NAME` → `Stocqify`
- `APP_TAGLINE` → `Manage Smarter. Grow Faster.`
- `APP_VERSION` → `1.0.0`
- `INVOICE_PREFIX` → `INV-`
- `DEFAULT_REORDER_LEVEL` → `5`
- `RECORDS_PER_PAGE` → `25`
- `SESSION_LIFETIME` → `7200` (2 hours)

---

## 2. Technology Stack

| Layer | Technology | Notes |
|-------|-----------|-------|
| Language | **PHP 8.0+** | Requires `match()`, `mixed` type, `str_starts_with()`, `str_contains()`, `ENT_SUBSTITUTE` |
| Database | **MySQL 5.7+ / MariaDB 10.3+** | InnoDB, utf8mb4 charset throughout |
| Server | **Apache** with `mod_rewrite` | `.htaccess` for clean URL routing |
| CSS Framework | **Tailwind CSS 3** (CDN) | Custom brand color overrides in `tailwind.config` |
| Icons | **Font Awesome 6.5.1** (CDN) | Solid style (`fa-solid`) |
| Charts | **Chart.js 4.4.0** (CDN) | Dashboard only — bar and line charts |
| PHP Framework | **None** | Vanilla PHP, no Composer, no autoloader |
| SMTP | Custom socket-based `Mailer` class | `app/lib/Mailer.php` — no PHPMailer dependency |
| Local Dev | **XAMPP** | Apache + MySQL, `http://localhost/SME/` |
| Production | **Hostinger** shared hosting | Document root is `public_html/` |

**Minimum PHP extensions required:** `pdo`, `pdo_mysql`, `openssl`, `mbstring`, `json`, `fileinfo`

---

## 3. Directory Structure

```
SME/                                  ← project root (public_html/ on Hostinger)
│
├── .htaccess                         ← Apache routing, security headers, directory blocks
├── .gitignore                        ← Excludes credentials, uploads, SQL dumps
├── README.md                         ← This file
│
├── app/
│   ├── config/
│   │   ├── config.php                ← All application constants (APP_URL, SITE_URL, etc.)
│   │   ├── config.local.php          ← ⚠ GITIGNORED — production URL + timezone overrides
│   │   ├── config.local.sample.php   ← Template for config.local.php
│   │   ├── database.php              ← ⚠ GITIGNORED — DB credentials + getDB() singleton
│   │   └── database.sample.php       ← Template for database.php
│   │
│   ├── includes/
│   │   ├── auth.php                  ← Session startup, login(), logout(), requireLogin(), CSRF
│   │   ├── functions.php             ← Global helpers: url(), h(), redirect(), post(), flash()
│   │   ├── header.php                ← HTML <head>, <base> tag, Tailwind config, Font Awesome
│   │   ├── sidebar.php               ← Navigation sidebar, notification bell, business context
│   │   ├── footer.php                ← Closing </div></body></html>
│   │   ├── mail.php                  ← appMail() and appMailer() helpers (loads SMTP from DB)
│   │   ├── image_uploader.php        ← Reusable drag-and-drop image upload component
│   │   ├── countries.php             ← ISO country list array (170+ entries)
│   │   └── 403.php                   ← HTTP 403 Forbidden page
│   │
│   └── lib/
│       └── Mailer.php                ← Socket-based SMTP mailer (TLS/SSL/plain, AUTH LOGIN)
│
├── database/
│   ├── schema.sql                    ← 30 CREATE TABLE IF NOT EXISTS statements (no USE/CREATE DB)
│   └── seed.sql                      ← Roles, demo business, users, categories, products, customers
│
├── modules/
│   ├── admin/
│   │   ├── businesses.php            ← CRUD for registered businesses
│   │   ├── demos.php                 ← Demo request queue from landing page
│   │   ├── settings.php              ← SMTP config, currencies, feature flags, danger zone
│   │   ├── subscriptions.php         ← Business subscription plans and billing
│   │   └── users.php                 ← Platform user management
│   │
│   ├── alerts/
│   │   └── index.php                 ← Low-stock alert list
│   │
│   ├── customers/
│   │   ├── index.php                 ← Customer list with search/filter
│   │   ├── add.php                   ← Create customer
│   │   ├── edit.php                  ← Edit customer (?id=N)
│   │   ├── view.php                  ← Customer detail + purchase history (?id=N)
│   │   └── delete.php                ← Soft/hard delete (POST only)
│   │
│   ├── debts/
│   │   ├── index.php                 ← Debt list (active, overdue, partial, written_off)
│   │   ├── view.php                  ← Debt detail + payment history (?id=N)
│   │   └── payment.php               ← Record debt payment (?debt=N)
│   │
│   ├── expenses/
│   │   ├── index.php                 ← Expense list with date + category filters
│   │   ├── add.php                   ← Add expense
│   │   ├── edit.php                  ← Edit expense (?id=N)
│   │   ├── categories.php            ← Manage expense categories
│   │   └── delete.php                ← Delete expense (POST only)
│   │
│   ├── income/
│   │   ├── index.php                 ← Non-sale income list
│   │   ├── add.php                   ← Add income record
│   │   ├── edit.php                  ← Edit income (?id=N)
│   │   └── delete.php                ← Delete income (POST only)
│   │
│   ├── payments/
│   │   └── index.php                 ← All payment transactions (sales + service orders)
│   │
│   ├── products/
│   │   ├── index.php                 ← Product list with category filter
│   │   ├── add.php                   ← Add product with image upload
│   │   ├── edit.php                  ← Edit product (?id=N)
│   │   ├── adjust.php                ← Manual stock adjustment (?id=N)
│   │   ├── categories.php            ← Manage product categories
│   │   └── delete.php                ← Delete product (POST only)
│   │
│   ├── profile/
│   │   └── index.php                 ← User profile: name, avatar, password change
│   │
│   ├── reports/
│   │   ├── sales.php                 ← Sales report with date range + product filters
│   │   ├── inventory.php             ← Stock levels, low-stock, value report
│   │   ├── financial.php             ← Revenue vs expenses P&L
│   │   ├── debts.php                 ← Outstanding debt report
│   │   ├── payments.php              ← Payment collection report
│   │   ├── customers.php             ← Customer purchase analysis
│   │   └── service_revenue.php       ← Service order revenue (service businesses only)
│   │
│   ├── sales/
│   │   ├── index.php                 ← Sales list with status + date filters
│   │   ├── add.php                   ← New sale: multi-item cart, customer, discount, tax
│   │   ├── edit.php                  ← Edit pending/unpaid sale (?id=N)
│   │   ├── view.php                  ← Sale detail (?id=N)
│   │   └── invoice.php               ← Printable invoice (?id=N)
│   │
│   ├── service_orders/
│   │   ├── index.php                 ← Service order list with status filter
│   │   ├── add.php                   ← New service order with service line items
│   │   ├── edit.php                  ← Edit service order (?id=N)
│   │   ├── view.php                  ← Order detail + status workflow (?id=N)
│   │   └── invoice.php               ← Printable service invoice (?id=N)
│   │
│   ├── services/
│   │   ├── index.php                 ← Service catalog list
│   │   ├── add.php                   ← Add service (fixed/hourly/custom pricing)
│   │   └── edit.php                  ← Edit service (?id=N)
│   │
│   └── support/
│       ├── index.php                 ← Business-facing ticket submission and list
│       ├── tickets.php               ← Admin-only: all tickets across businesses
│       └── view.php                  ← Ticket thread with reply form (?id=N)
│
├── public/
│   ├── api/
│   │   ├── notifications.php         ← GET — new notifications since last ID (polling)
│   │   └── notifications_read.php    ← POST — mark notification(s) as read
│   │
│   ├── assets/
│   │   └── img/
│   │       └── logo.png              ← Application logo
│   │
│   ├── uploads/
│   │   └── avatars/                  ← ⚠ GITIGNORED — user avatar files
│   │
│   ├── index.php                     ← Entry point: redirects to /dashboard or /landing
│   ├── dashboard.php                 ← Role-aware dashboard (admin stats / business KPIs)
│   ├── login.php                     ← Login form + session creation
│   ├── logout.php                    ← Session destruction → /login?msg=logged_out
│   ├── landing.php                   ← Public marketing/landing page
│   ├── setup.php                     ← First-run setup wizard (create DB + admin account)
│   ├── forgot_password.php           ← Email-based password reset request
│   ├── reset_password.php            ← Token-based password reset form
│   ├── force_change_password.php     ← Forced password change (session flag)
│   ├── verify_email.php              ← Email verification token handler
│   └── reset_admin.php               ← Emergency admin password reset (to be deleted post-use)
│
└── uploads/
    └── businesses/                   ← ⚠ GITIGNORED — business logo files
```

---

## 4. Routing System

### How It Works

All HTTP requests hit the project root `.htaccess`. Apache's `mod_rewrite` maps clean URLs to their physical PHP files. The rewrite engine does **not** touch the `public/` directory directly — files there are served as-is by Apache unless a specific rewrite rule matches.

### Critical: RewriteBase

```apache
RewriteBase /SME/     ← local XAMPP (app lives at localhost/SME/)
RewriteBase /         ← Hostinger production (app is at domain root)
```

**This must be updated manually on Hostinger** via File Manager before the site works. The committed default is `/SME/` for local development.

### Security Rules

```apache
Options -Indexes
RewriteRule ^(app|database)(/|$) - [F,L]   ← Blocks direct access to app/ and database/
```

Security headers are also set via `mod_headers`:
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

Sensitive file extensions are blocked: `.sql`, `.log`, `.env`, `.bak`, `.sh`, `.ini`, `.lock`

### URL Route Map

| Clean URL | PHP File | Auth Required |
|-----------|---------|:---:|
| `/` | `public/index.php` | Redirects only |
| `/landing` | `public/landing.php` | No |
| `/login` | `public/login.php` | No |
| `/logout` | `public/logout.php` | Yes |
| `/dashboard` | `public/dashboard.php` | Yes |
| `/setup` | `public/setup.php` | No (delete after use) |
| `/reset-admin` | `public/reset_admin.php` | No (delete after use) |
| `/forgot-password` | `public/forgot_password.php` | No |
| `/reset-password` | `public/reset_password.php` | No |
| `/verify-email` | `public/verify_email.php` | No |
| `/change-password` | `public/force_change_password.php` | Yes |
| `/alerts` | `modules/alerts/index.php` | Yes |
| `/customers` | `modules/customers/index.php` | Yes |
| `/customers/add` | `modules/customers/add.php` | Yes |
| `/customers/edit` | `modules/customers/edit.php` | Yes |
| `/customers/view` | `modules/customers/view.php` | Yes |
| `/products` | `modules/products/index.php` | Yes |
| `/products/add` | `modules/products/add.php` | Yes |
| `/products/edit` | `modules/products/edit.php` | Yes |
| `/products/categories` | `modules/products/categories.php` | Yes |
| `/products/adjust` | `modules/products/adjust.php` | Yes |
| `/sales` | `modules/sales/index.php` | Yes |
| `/sales/add` | `modules/sales/add.php` | Yes |
| `/sales/edit` | `modules/sales/edit.php` | Yes |
| `/sales/view` | `modules/sales/view.php` | Yes |
| `/sales/invoice` | `modules/sales/invoice.php` | Yes |
| `/services` | `modules/services/index.php` | Yes |
| `/services/add` | `modules/services/add.php` | Yes |
| `/services/edit` | `modules/services/edit.php` | Yes |
| `/service-orders` | `modules/service_orders/index.php` | Yes |
| `/service-orders/add` | `modules/service_orders/add.php` | Yes |
| `/service-orders/edit` | `modules/service_orders/edit.php` | Yes |
| `/service-orders/view` | `modules/service_orders/view.php` | Yes |
| `/service-orders/invoice` | `modules/service_orders/invoice.php` | Yes |
| `/debts` | `modules/debts/index.php` | Yes |
| `/debts/view` | `modules/debts/view.php` | Yes |
| `/debts/payment` | `modules/debts/payment.php` | Yes |
| `/payments` | `modules/payments/index.php` | Yes |
| `/expenses` | `modules/expenses/index.php` | Yes |
| `/expenses/add` | `modules/expenses/add.php` | Yes |
| `/expenses/edit` | `modules/expenses/edit.php` | Yes |
| `/expenses/categories` | `modules/expenses/categories.php` | Yes |
| `/income` | `modules/income/index.php` | Yes |
| `/income/add` | `modules/income/add.php` | Yes |
| `/income/edit` | `modules/income/edit.php` | Yes |
| `/profile` | `modules/profile/index.php` | Yes |
| `/support` | `modules/support/index.php` | Yes |
| `/support/view` | `modules/support/view.php` | Yes |
| `/support/tickets` | `modules/support/tickets.php` | Admin only |
| `/admin/users` | `modules/admin/users.php` | Admin only |
| `/admin/businesses` | `modules/admin/businesses.php` | Admin only |
| `/admin/subscriptions` | `modules/admin/subscriptions.php` | Admin only |
| `/admin/demos` | `modules/admin/demos.php` | Admin only |
| `/admin/settings` | `modules/admin/settings.php` | Admin only |
| `/reports/sales` | `modules/reports/sales.php` | Yes |
| `/reports/inventory` | `modules/reports/inventory.php` | Yes |
| `/reports/financial` | `modules/reports/financial.php` | Yes |
| `/reports/debts` | `modules/reports/debts.php` | Yes |
| `/reports/payments` | `modules/reports/payments.php` | Yes |
| `/reports/customers` | `modules/reports/customers.php` | Yes |
| `/reports/service-revenue` | `modules/reports/service_revenue.php` | Yes |

### API Routing

API endpoints live in `public/api/` and are served **directly by Apache** — no rewrite rule is needed. The `API` JavaScript constant is set to `APP_URL` (e.g. `https://stocqify.com/public`), so AJAX calls go to `https://stocqify.com/public/api/notifications.php`.

| URL Path | File | Method |
|----------|------|--------|
| `/public/api/notifications.php` | `public/api/notifications.php` | `GET` |
| `/public/api/notifications_read.php` | `public/api/notifications_read.php` | `POST` |

### `.php` Passthrough Rules

A catch-all rule at the bottom of `.htaccess` allows relative `?id=N` links generated by module pages to resolve correctly even when accessed via clean URLs:

```apache
RewriteRule ^customers/([a-z_]+\.php)$ modules/customers/$1 [L,QSA]
RewriteRule ^products/([a-z_]+\.php)$  modules/products/$1  [L,QSA]
# ... (repeated for all modules)
```

---

## 5. Configuration

### Config Loading Order

```
app/config/config.php
  └── loads config.local.php first (if it exists)
  └── defines constants with if (!defined(...)) guards
```

This means `config.local.php` always wins. Every constant in `config.php` is wrapped in `if (!defined())` so production values set in `config.local.php` are never overwritten.

### `app/config/config.php` — Constants Reference

```php
// URLs
APP_URL   = 'http://localhost/SME/public'  // Full URL to the public/ directory
SITE_URL  = 'http://localhost/SME'         // Domain root — NO trailing slash

// Branding
APP_NAME    = 'Stocqify'
APP_TAGLINE = 'Manage Smarter. Grow Faster.'
APP_LOGO    = APP_URL . '/assets/img/logo.png'
APP_VERSION = '1.0.0'

// Paths (server filesystem)
BASE_PATH    = dirname(__DIR__, 2)   // project root
APP_PATH     = dirname(__DIR__)      // app/ directory
PUBLIC_PATH  = BASE_PATH . '/public'
MODULES_PATH = BASE_PATH . '/modules'

// Localisation
CURRENCY        = 'USD'
CURRENCY_SYMBOL = '$'
DATE_FORMAT     = 'd/m/Y'
DATETIME_FORMAT = 'd/m/Y H:i'
APP_TIMEZONE    = 'UTC'              // overridable in config.local.php

// Session
SESSION_NAME     = 'sme_session'
SESSION_LIFETIME = 7200             // seconds (2 hours)

// Display / Pagination
RECORDS_PER_PAGE     = 25
INVOICE_PREFIX       = 'INV-'
DEFAULT_REORDER_LEVEL = 5
```

### `app/config/config.local.php` — Production Overrides

> **Gitignored.** Never commit this file. Copy `config.local.sample.php` and fill in real values.

```php
<?php
define('APP_URL',      'https://stocqify.com/public');
define('SITE_URL',     'https://stocqify.com');        // NO trailing slash
define('APP_TIMEZONE', 'Africa/Freetown');
```

### `app/config/database.php` — DB Credentials

> **Gitignored.** Never commit this file. Copy `database.sample.php` and fill in real values.

```php
<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    'u123456789_stocqify');   // Hostinger format
define('DB_USER',    'u123456789_stocqify');
define('DB_PASS',    'your_password_here');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO { ... }   // PDO singleton, ERRMODE_EXCEPTION
```

The `getDB()` function is a static singleton — it creates the PDO connection once per request and reuses it. On connection failure it calls `die()` with a user-friendly HTML error.

### `url()` Helper — How All Links Are Built

```php
function url(string $path = ''): string {
    return SITE_URL . '/' . ltrim($path, '/');
}
```

All internal links use `url('path')`. This is why `SITE_URL` must never have a trailing slash — the function always adds one separator.

---

## 6. Authentication & Security

### Session Startup (`app/includes/auth.php`)

```php
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
```

Session ID is regenerated every 30 minutes to prevent fixation attacks.

### Session Variables (set on login)

| Key | Description |
|-----|-------------|
| `$_SESSION['user_id']` | User's primary key |
| `$_SESSION['user_name']` | Display name |
| `$_SESSION['user_email']` | Email address |
| `$_SESSION['user_role']` | Role slug (`admin`, `owner`, `manager`, etc.) |
| `$_SESSION['user_role_name']` | Role display name |
| `$_SESSION['user_avatar']` | Avatar file path (nullable) |
| `$_SESSION['business_id']` | Assigned business ID (null for admin) |
| `$_SESSION['business_name']` | Business display name |
| `$_SESSION['business_currency']` | Business currency symbol |
| `$_SESSION['business_type']` | `products` or `services` |
| `$_SESSION['permissions']` | Decoded JSON permissions array |
| `$_SESSION['force_password_change']` | `true` → redirect to /change-password |
| `$_SESSION['last_regenerated']` | Timestamp of last session ID regeneration |
| `$_SESSION['csrf_token']` | 64-char hex CSRF token |

### Roles & Permissions

Permissions are stored as JSON in the `roles.permissions` column and loaded into the session on login.

| Role | Slug | Permissions |
|------|------|-------------|
| Administrator | `admin` | `{"all": true}` — full access, bypasses all permission checks |
| Business Owner | `owner` | dashboard, sales, inventory, customers, debts, payments, expenses, reports, alerts, users |
| Manager | `manager` | dashboard, sales, inventory, customers, debts, payments, expenses, reports, alerts |
| Sales Officer | `sales_officer` | dashboard, sales, customers, payments |
| Accountant | `accountant` | dashboard, payments, expenses, reports, debts |

**Permission check functions:**

```php
isLoggedIn()                     // checks $_SESSION['user_id']
isAdmin()                        // checks $_SESSION['user_role'] === 'admin'
hasPermission('sales')           // admin always returns true; others check JSON
requireLogin()                   // redirects to /login if not authenticated
requirePermission('inventory')   // calls requireLogin() + hasPermission() → 403 if denied
```

### CSRF Protection

Every state-changing form includes a hidden `csrf_token` field. The token is generated once per session and stored in `$_SESSION['csrf_token']`.

```php
// In forms:
<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

// In handlers:
verifyCsrf();  // dies with HTTP 403 on mismatch
```

`verifyCsrf()` accepts the token from `$_POST['csrf_token']`, `$_GET['csrf_token']`, `$_GET['csrf']`, or the `X-CSRF-TOKEN` header.

### Password Hashing

```php
password_hash($password, PASSWORD_BCRYPT)   // cost factor 10 (PHP default)
password_verify($input, $storedHash)
```

The known valid hash for `"password"` (cost 10):
```
$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
```

---

## 7. Database Schema

The schema is in `database/schema.sql`. It uses `CREATE TABLE IF NOT EXISTS` throughout and requires no `CREATE DATABASE` or `USE` statement — the database must exist before import and be selected via phpMyAdmin.

**Import order:** `schema.sql` first, then `seed.sql`.

### Tables Overview (30 tables)

#### Core Platform Tables

| Table | Purpose | Key Relationships |
|-------|---------|------------------|
| `businesses` | Registered businesses | Parent of most data |
| `roles` | User roles with JSON permissions | Referenced by `users` |
| `users` | All platform users | `business_id` → `businesses`, `role_id` → `roles` |

#### Inventory & Products

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `categories` | Product categories per business | `business_id`, `name` |
| `products` | Product catalog | `sku`, `cost_price`, `selling_price`, `stock_quantity`, `reorder_level`, `max_stock`, `business_type` |
| `inventory_transactions` | Audit trail for stock changes | `type` ENUM(`purchase`, `sale`, `adjustment`, `return`, `damage`), `before_quantity`, `after_quantity` |

#### Sales & Revenue

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `sales` | Sale records | `invoice_number`, `sale_type` (cash/credit), `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `amount_paid`, `balance_due`, `payment_status` (paid/partial/unpaid) |
| `sale_items` | Line items per sale | `product_id`, `quantity`, `unit_price`, `cost_price`, `discount`, `total` |

#### Debt Management

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `debts` | Outstanding customer debts | `original_amount`, `amount_paid`, `balance`, `due_date`, `status` (active/partial/paid/overdue/written_off) |
| `debt_payments` | Payments against debts | `amount`, `payment_method`, `payment_date` |
| `payments` | General payment records | Links to `sales` or `service_orders`; `payment_method` |

#### Expenses & Income

| Table | Purpose |
|-------|---------|
| `expense_categories` | Category labels for expenses |
| `expenses` | Business expenditure records |
| `income` | Non-sale income entries |

#### Services (Service Businesses)

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `services` | Service catalog | `price`, `price_type` (fixed/hourly/custom), `duration_minutes` |
| `service_orders` | Service job orders | `order_number`, `status` (pending/in_progress/completed/cancelled), `payment_status`, `scheduled_at`, `completed_at` |
| `service_order_items` | Line items per service order | `service_name`, `unit_price`, `quantity`, `discount`, `total` |

#### CRM

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `customers` | Customer records | `credit_limit`, `is_active`, `business_name` |

#### Platform & Admin Tables

| Table | Purpose |
|-------|---------|
| `notifications` | In-app user notifications (bell icon) |
| `business_features` | Per-business feature flag toggles (enabled/disabled/coming_soon) |
| `business_subscriptions` | Subscription plan tracking (free/starter/professional/enterprise) |
| `system_settings` | Key-value store for platform config (SMTP, currencies, etc.) |
| `audit_logs` | JSON-diff change log for every significant action |

#### Support

| Table | Purpose |
|-------|---------|
| `support_tickets` | Tickets submitted by business users to admin |
| `support_replies` | Thread replies on tickets |

#### Auth Tokens

| Table | Purpose |
|-------|---------|
| `password_resets` | 64-char token, 1-hour expiry |
| `email_verifications` | Email verification tokens |

#### Landing Page

| Table | Purpose |
|-------|---------|
| `landing_demos` | Demo booking requests from landing page |
| `landing_newsletter` | Newsletter subscriber emails |
| `landing_contacts` | Contact form submissions |

### Payment Methods (used across multiple tables)

```sql
ENUM('cash', 'orange_money', 'afrimoney', 'qmoney', 'bank_transfer')
-- sales also adds: 'mixed'
```

---

## 8. Modules Reference

### Page Load Pattern

Every protected page follows this exact pattern:

```php
<?php
require_once __DIR__ . '/../../app/includes/auth.php';   // starts session
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();   // or requirePermission('module')

// ... data fetching ...

$pageTitle = 'Page Title';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<!-- HTML content -->
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
```

### Dashboard (`public/dashboard.php`)

The dashboard renders different content based on role:

**Admin Dashboard** — platform-wide KPIs:
- Total/active businesses, new this month
- Total/active users, new this month, logins today
- Recent businesses table (last 8)
- Recent user registrations (last 6)
- Business registrations bar chart (Chart.js, last 6 months)

**Business User Dashboard** — business-scoped KPIs:
- Sales today, revenue this month, expenses this month, net profit
- Outstanding debts, overdue debts, active customers, low-stock items
- Recent sales (last 8), low-stock products (last 6), recent payments (last 5)
- Top customers by revenue (last 5), daily sales line chart (last 7 days)

All dashboard DB queries use the `dbStat()` helper which is wrapped in try-catch to prevent HTTP 500 when tables are missing on a fresh deployment.

### Customers Module

- **List** `/customers` — search by name/phone/email, filter by status
- **Add** `/customers/add` — name, phone, email, address, business name, credit limit
- **Edit** `/customers/edit?id=N`
- **View** `/customers/view?id=N` — profile + full purchase/debt history
- **Delete** `/customers/delete` — POST only, CSRF protected

All customer data is scoped to `business_id` from the session. Walk-in customers (no account) can be named inline on sales.

### Products & Inventory Module

- **List** `/products` — search, category filter, low-stock highlight
- **Add** `/products/add` — name, SKU, category, unit, cost price, selling price, opening stock, reorder level, max stock, image upload
- **Edit** `/products/edit?id=N`
- **Adjust** `/products/adjust?id=N` — manual stock adjustment (add/subtract/set), writes to `inventory_transactions`
- **Categories** `/products/categories` — CRUD for product categories
- **Delete** `/products/delete` — POST only

### Sales Module

- **List** `/sales` — filter by status (paid/partial/unpaid), date range, search
- **Add** `/sales/add` — multi-item sale builder:
  - Select customer (or walk-in name)
  - Add product lines with quantity, price override, per-line discount
  - Order-level discount and tax
  - Payment method, amount paid (auto-calculates balance)
  - If balance_due > 0 → creates debt record automatically
  - Auto-generates `INV-YYYYMMDD-NNNN` invoice number
- **Edit** `/sales/edit?id=N` — only for unpaid/partial sales
- **View** `/sales/view?id=N`
- **Invoice** `/sales/invoice?id=N` — printable, print-dialog-friendly CSS

### Services Module (service businesses only)

- **List** `/services` — service catalog
- **Add** `/services/add` — name, category, price, price type (fixed/hourly/custom), duration
- **Edit** `/services/edit?id=N`

### Service Orders Module (service businesses only)

- **List** `/service-orders` — filter by status
- **Add** `/service-orders/add` — customer selection, service line items, scheduling date/time, payment
- **Edit** `/service-orders/edit?id=N`
- **View** `/service-orders/view?id=N` — status workflow (pending → in_progress → completed)
- **Invoice** `/service-orders/invoice?id=N` — printable

### Debts Module

Debts are auto-created when a sale has a `balance_due > 0`. They can also be managed manually.

- **List** `/debts` — filter by status
- **View** `/debts/view?id=N` — debt details + payment history
- **Payment** `/debts/payment?debt=N` — record a payment, automatically updates `balance`, `amount_paid`, and `status`

### Expenses & Income Modules

Both follow the same CRUD pattern. Expenses link to optional categories and can attach a receipt path. Income records non-sale revenue (grants, rental income, etc.).

Payment methods: `cash`, `orange_money`, `afrimoney`, `qmoney`, `bank_transfer`

### Reports Module

All reports respect `business_id` scoping. Date ranges are configurable.

| Report | Key Metrics |
|--------|------------|
| Sales | Revenue, units sold, average sale, top products |
| Inventory | Current stock levels, low-stock, stock value |
| Financial | Revenue vs expenses P&L, net profit trend |
| Debts | Outstanding by customer, overdue amounts |
| Payments | Collections by method, date range totals |
| Customers | Top buyers, purchase frequency, debt exposure |
| Service Revenue | Service order totals (service businesses only) |

### Admin Module (`admin` slug only)

#### Businesses (`/admin/businesses`)
CRUD for registered businesses. Can activate/deactivate, set business type, currency, country.

#### Users (`/admin/users`)
Create/edit/deactivate platform users. Assign to businesses and roles. Can force password change on next login.

#### Subscriptions (`/admin/subscriptions`)
Manage each business's subscription plan (free/starter/professional/enterprise) and status (trial/active/expired/suspended/cancelled).

#### Demos (`/admin/demos`)
Process demo requests submitted via the landing page. Assign a slot date/time and mark status.

#### Settings (`/admin/settings`) — Tabs

| Tab | Purpose |
|-----|---------|
| `smtp` | Configure SMTP host, port, username, password, from address, encryption (TLS/SSL/none). Send test email. |
| `currencies` | Manage currency options shown to businesses |
| `features` | Enable/disable per-business feature flags (`business_features` table) |
| `general` | Platform-wide settings |
| `danger` | Administrative danger zone actions |

SMTP config is stored in `system_settings` as key-value pairs with keys prefixed `smtp_`.

### Support Module

- **Business view** `/support` — submit tickets, view own tickets
- **Admin view** `/support/tickets` — all tickets across all businesses
- **Ticket thread** `/support/view?id=N` — message thread with admin/business reply distinction

Tickets have `admin_read` and `business_read` flags for unread badge counts shown in the sidebar bell icon.

### Profile (`/profile`)

User can update name, phone, avatar (image upload), and change their password. Password change requires current password verification.

### Alerts (`/alerts`)

Shows all products where `stock_quantity <= reorder_level` for the current business.

---

## 9. Shared Components

### `app/includes/header.php`

Outputs `<!DOCTYPE html>` through opening `<body>`. Key features:
- Computes `<base href="...">` dynamically so relative module links resolve correctly through clean URLs. The base is set to `SITE_URL/{module-segment}/` for module pages, or `SITE_URL/` for root pages.
- Loads Tailwind CSS from CDN with a custom `tailwind.config` block that extends the brand colour palette (navy blues, gold accent).
- Loads Font Awesome 6.5.1.

### `app/includes/sidebar.php`

The most complex include. Runs on every authenticated page load:

1. **Business context refresh** — for non-admin users, re-fetches the assigned business from DB (name, currency, type, logo, is_active). If business is deactivated → forces logout.
2. **Feature flags** — loads `business_features` for the current business into `$__bizFeatures`.
3. **Notification bell** — queries `support_tickets` for unread count (admin sees cross-business; users see own business).
4. **Demo pending badge** — admin only, queries `landing_demos` for pending count.
5. **Notification dropdown** — loads last 15 `notifications` for the current user.
6. **Sidebar links** — rendered differently for admin vs. product business vs. service business using `match()` to detect the active section.
7. **Notification JS** — sets `const API = '<?= APP_URL ?>'` and `const CSRF_TOK = '<?= csrfToken() ?>'` for AJAX calls. Polls `/public/api/notifications.php` every 15 seconds.

### `app/includes/footer.php`

Closes the main content wrapper and `</body></html>`.

### Image Uploader (`app/includes/image_uploader.php`)

A reusable drag-and-drop image upload component. Two functions:

```php
// Render the uploader zone (call once per upload field):
renderImageUploader(
    id: 'logo',
    inputName: 'logo',
    label: 'Business Logo',
    existingUrl: $currentLogoUrl,
    removeFieldName: 'remove_logo',
    height: '200px'
);

// Emit shared CSS + JS (call once per page, before </body>):
imageUploaderAssets();
```

Features: click-to-browse, drag-and-drop, image preview, remove button with hidden `remove_flag` field, 2MB limit (validated client-side), accepts JPEG/PNG/GIF/WEBP.

### Notification System

**Server-side creation:**
```php
createNotification(int $userId, string $type, string $title, string $body, string $link);
notifyAdmins(string $type, string $title, string $body, string $link);
```

**Notification types:** `success`, `warning`, `error`, `support`, `info`

**Client-side polling** (in `sidebar.php`):
- Polls `GET /public/api/notifications.php?since={lastId}` every 15 seconds
- Shows toast popups for new items
- Updates unread badge count
- Mark-as-read via `POST /public/api/notifications_read.php` with `ids=all` or `ids={id}`

### Email / Mailer

**`app/lib/Mailer.php`** — a socket-based SMTP client with no external dependencies.

- Supports TLS (STARTTLS on port 587), SSL (port 465), and plain connections
- AUTH LOGIN with base64 credentials
- Builds `multipart/alternative` MIME with both plain-text and HTML parts
- UTF-8 encoded headers (RFC 2047 `=?UTF-8?B?...?=`)

**`app/includes/mail.php`** — the application helper:

```php
appMail(string $to, string $subject, string $htmlBody, string $toName = ''): bool
```

Loads SMTP credentials from `system_settings` (`smtp_host`, `smtp_port`, `smtp_username`, `smtp_password`, `smtp_from_email`, `smtp_from_name`, `smtp_encryption`). Returns `false` if SMTP is not configured.

### Audit Logging

```php
auditLog(string $action, string $module, ?int $recordId, array $old, array $new);
```

Writes to `audit_logs` with JSON-encoded before/after values, user ID, business ID, IP address, and user agent. Failures are swallowed silently.

---

## 10. API Endpoints

Both endpoints require an active session (`requireLogin()`).

### `GET /public/api/notifications.php`

Polls for new notifications since a given ID.

**Query params:**
- `since` — last known notification ID (integer, defaults to 0)

**Response:**
```json
{
  "ok": true,
  "new": [
    { "id": 42, "type": "success", "title": "Sale recorded", "body": "...", "link": "/sales/view?id=5", "is_read": 0, "created_at": "..." }
  ],
  "unread_count": 3
}
```

Auto-creates the `notifications` table if missing. Returns `{"ok": false, ...}` on error.

### `POST /public/api/notifications_read.php`

Marks one or all notifications as read for the current user.

**POST body:**
- `csrf_token` — required CSRF token
- `ids` — `"all"` to mark all, or a comma-separated list of IDs

**Response:**
```json
{ "ok": true, "unread_count": 0 }
```

---

## 11. Deployment Guide

### Files to Exclude from Production Upload

Never upload these to the server:

```
app/config/database.php       ← contains real DB credentials
app/config/config.local.php   ← contains production URLs
public/uploads/               ← user-generated files (manage separately)
uploads/                      ← business logos (manage separately)
database/dump_*.sql           ← database exports
*.sql (root level)            ← any SQL dumps
.claude/                      ← IDE config
.env / *.env                  ← environment files
```

Use `.gitignore` as the reference — anything ignored should not be deployed via Git.

### Step-by-Step Hostinger Deployment

**1. Upload files** via Git integration or File Manager (exclude the files above).

**2. Edit `.htaccess`** in File Manager — change:
```apache
RewriteBase /SME/    →    RewriteBase /
```

**3. Create `app/config/config.local.php`** in File Manager:
```php
<?php
define('APP_URL',      'https://stocqify.com/public');
define('SITE_URL',     'https://stocqify.com');
define('APP_TIMEZONE', 'Africa/Freetown');
```

**4. Create `app/config/database.php`** in File Manager:
```php
<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    'u235415229_stocqify');
define('DB_USER',    'u235415229_stocqify');
define('DB_PASS',    'your_actual_password');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626;"><h2>Database Connection Error</h2></div>');
        }
    }
    return $pdo;
}
```

**5. Import the database schema** via phpMyAdmin:
- Hostinger hPanel → Databases → phpMyAdmin
- Select your database
- Import tab → choose `database/schema.sql` → Go

**6. Create the admin user** manually in phpMyAdmin → SQL tab:
```sql
-- First ensure roles exist:
INSERT IGNORE INTO roles (name, slug, permissions) VALUES
('Administrator', 'admin', '{"all": true}');

-- Create admin user (password: your_secure_password → bcrypt hash):
INSERT INTO users (business_id, role_id, name, email, password, is_active)
VALUES (NULL, 1, 'Your Name', 'your@email.com',
'$2y$10$YOUR_BCRYPT_HASH_HERE', 1);
```

To generate a bcrypt hash for a custom password:
```php
<?php echo password_hash('your_secure_password', PASSWORD_BCRYPT);
```

**7. Set PHP version** in Hostinger hPanel → PHP Configuration → select **PHP 8.2** → Update.

**8. Set SMTP** — log in as admin → Admin → Settings → SMTP tab.

**9. Delete setup files** after first successful login:
```
public/setup.php
public/reset_admin.php
```

### Local XAMPP Development

1. Clone/copy project to `C:/xampp/htdocs/SME/`
2. Ensure `.htaccess` has `RewriteBase /SME/`
3. Create `app/config/database.php` with local credentials (DB_HOST: `localhost`, DB_USER: `root`, DB_PASS: `''`)
4. Import `database/schema.sql` then `database/seed.sql` into a database named `sme_management`
5. Access at `http://localhost/SME/`
6. Default login (from seed): `admin@sme.sl` / `password`

---

## 12. Security Notes

| Area | Implementation |
|------|---------------|
| Passwords | bcrypt via `PASSWORD_BCRYPT` (cost 10) |
| CSRF | Per-session token verified on all POST handlers |
| Session | `httponly`, `samesite=Strict`, `secure` on HTTPS |
| SQL | PDO prepared statements throughout — no raw string interpolation |
| XSS | `h()` wrapper (`htmlspecialchars` with `ENT_QUOTES|ENT_SUBSTITUTE`) on all output |
| Directory listing | `Options -Indexes` in `.htaccess` |
| Sensitive directories | `app/` and `database/` blocked at `.htaccess` level |
| File uploads | Type whitelist (JPEG/PNG/GIF/WEBP), stored outside web root where possible |
| Credential files | `database.php` and `config.local.php` gitignored — never committed |
| Session regeneration | `session_regenerate_id(true)` every 30 minutes |
| Business isolation | Every query scoped to `business_id` from `$_SESSION` — never from user input |
| Deactivated accounts | `is_active = 1` checked in `login()` JOIN; suspended businesses force logout on next request |

---

## 13. Changelog

### v1.0.0 — 2026-08-04 (Initial Production Release)

**Core Platform**
- Multi-tenant architecture with system admin + business user tiers
- 5 user roles with granular JSON permission system
- Session-based authentication with CSRF protection and session regeneration
- Dual business type support: `products` (inventory) and `services` (service catalog + orders)

**Modules Shipped**
- Customer management (CRUD + purchase history)
- Product catalog with SKU, pricing, multi-unit support
- Inventory tracking with full transaction audit trail (purchase/sale/adjustment/return/damage)
- Sales module with multi-item cart, discounts, tax, payment status tracking
- Auto-generated `INV-YYYYMMDD-NNNN` invoice numbers with printable invoice view
- Debt management with automatic creation on credit sales
- Debt payments with balance recalculation
- Expense tracking with categories and receipt attachments
- Non-sale income recording
- Payment ledger (all transactions in one view)
- Service catalog and service orders (for service-type businesses)
- 7 financial and operational reports
- Low-stock alerts
- Support ticket system (business ↔ admin)
- User profile management with avatar upload
- In-app notification system (real-time polling, toast popups)

**Admin Panel**
- Business registration and management
- Platform user management with forced password-change flag
- Subscription plan tracking
- Demo request queue from landing page
- SMTP configuration with live test-send
- Per-business feature flags
- System settings key-value store

**Infrastructure**
- Socket-based SMTP mailer (no PHPMailer dependency) with TLS/SSL/AUTH LOGIN
- Reusable drag-and-drop image uploader component
- Audit log for all significant data changes
- Apache clean URL routing (60+ routes via `.htaccess`)
- First-run setup wizard
- Password reset via email token (1-hour expiry)
- Email verification flow
- Emergency admin reset utility

**Security Fixes (pre-release)**
- `database.sample.php` scrubbed of real Hostinger credentials (were committed accidentally)
- `config.local.sample.php` reset to placeholder values
- `session_set_cookie_params secure` made HTTPS-aware (was hardcoded `false`)
- Dashboard DB queries wrapped in try-catch (prevented HTTP 500 on fresh deployments with incomplete schema)

---

*Documentation maintained in `README.md`. Update this file whenever a new module, route, table, or configuration constant is added.*
