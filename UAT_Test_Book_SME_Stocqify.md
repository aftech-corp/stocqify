# UAT Test Book — SME (Stocqify)

Version: 1.0
Generated: 2026-08-05

Overview
--------
This UAT test book lists user acceptance test cases for the SME (Stocqify) application. Tests are grouped by user role and feature area. Each test case includes: Test ID, Description, Preconditions, Steps, Expected Result, and Comments.

Roles covered
-------------
- Admin
- Branch Manager
- Cashier / Sales Clerk
- Accountant
- Supplier
- Customer
- Support Agent

How to use
----------
- Walk through test steps in a staging environment matching production configuration.
- Record actual vs expected results, attach screenshots, and mark test status: Pass / Fail / Blocked.
- Report defects with reproduction steps and link to test case ID.

Legend
------
- [P] = Priority (High / Medium / Low)

************************************************************************

**Admin Features**

Admin-001: Login and Authentication [P:High]
Description: Verify admin can authenticate and access admin dashboard.
Preconditions: Admin account exists; staging URL accessible.
Steps:
1. Navigate to public/login.php.
2. Enter admin credentials and submit.
3. Verify email verification or force password change flows if required.
Expected Result: Admin is authenticated, redirected to public/dashboard.php and admin menu visible.
Comments: Test force_change_password.php and reset_admin.php flows as separate cases.

Admin-002: Manage Businesses (modules/admin/businesses.php) [P:High]
Description: Create, edit, delete business records.
Preconditions: Admin logged in.
Steps:
1. Open modules/admin/businesses.php.
2. Add a new business with valid details and save.
3. Edit the created business and update a field.
4. Delete the test business.
Expected Result: Business appears in list after create; updates persist; deletion removes the record.
Comments: Check DB businesses table and uploads in uploads/businesses/ for any logo files.

Admin-003: Users Management (modules/admin/users.php) [P:High]
Description: Add, edit, enable/disable user accounts and reset passwords.
Preconditions: Admin logged in.
Steps:
1. Open modules/admin/users.php.
2. Create a new user for role Branch Manager.
3. Edit user's details and change role.
4. Trigger reset password and verify public/reset_password.php email flow.
Expected Result: User creation and edits saved; reset password email sent (or token created) and password reset works.
Comments: Check public/verify_email.php and public/reset_password.php for end-to-end flows.

Admin-004: Settings and Subscriptions (modules/admin/settings.php, subscriptions.php) [P:Medium]
Description: Modify system settings and check subscription status/features.
Preconditions: Admin logged in.
Steps:
1. Open modules/admin/settings.php and change a configuration (e.g., company name).
2. Save settings and refresh dashboard.
3. Check modules/admin/subscriptions.php for subscription plan details.
Expected Result: Changes reflected in UI and persisted in app/config/config.php or database.
Comments: Backup config file before testing.

************************************************************************

**Branch Manager Features**

Branch-001: Branch CRUD (modules/branches) [P:High]
Description: Add, edit, switch and list branches.
Preconditions: Branch Manager or Admin with branch privileges.
Steps:
1. Go to modules/branches/add.php and create a branch.
2. Edit the branch in modules/branches/edit.php.
3. Use modules/branches/switch.php to switch current branch.
4. Verify branch appears in branch listing.
Expected Result: Branch CRUD works and switching changes context (e.g., available inventory, sales tied to branch).
Comments: Verify branch-specific uploads and settings.

Branch-002: Branch Reports (modules/reports/*) [P:Medium]
Description: Generate branch-specific reports (sales, inventory).
Preconditions: Data exists for branch.
Steps:
1. Navigate to modules/reports/sales.php and select branch/date range.
2. Generate and download report.
Expected Result: Report shows correct data for selected branch/date range.
Comments: Verify exported formats and totals.

************************************************************************

**Cashier / Sales Clerk Features**

Sales-001: Add Sale (modules/sales/add.php) [P:High]
Description: Create a sales transaction and issue invoice.
Preconditions: Product inventory exists; cashier logged in.
Steps:
1. Go to modules/sales/add.php.
2. Add products to cart, set quantities, apply discounts/taxes if applicable.
3. Complete transaction using a payment method.
4. Open modules/sales/invoice.php for the sale and print/download.
Expected Result: Sale recorded, stock reduced, invoice generated with correct totals.
Comments: Test multiple payment types and partial payments.

Sales-002: Edit Sale (modules/sales/edit.php) [P:Medium]
Description: Modify a saved sale (if allowed by permissions) and update inventory.
Preconditions: Unfinalized sale exists.
Steps:
1. Open an existing sale in modules/sales/edit.php.
2. Change product quantities and save.
Expected Result: Changes apply to the sale and inventory adjusts accordingly.
Comments: Confirm audit logs or notes exist for changes.

Sales-003: Product Management at POS (modules/products/add.php, edit.php) [P:Medium]
Description: Add quick products and manage categories.
Preconditions: Clerk has permission; product categories exist.
Steps:
1. Create a product via modules/products/add.php.
2. Assign category and price, upload image.
3. Use product in sale flow.
Expected Result: Product available at POS and inventory updated when sold.
Comments: Verify images saved to uploads/products/.

************************************************************************

**Accountant Features**

Finance-001: Expenses CRUD (modules/expenses) [P:High]
Description: Add, categorize, and report expenses.
Preconditions: Accountant login.
Steps:
1. Go to modules/expenses/add.php and create an expense with category.
2. Edit the expense and verify category reassignment.
3. Run expense reports by date/category in modules/reports/financial.php.
Expected Result: Expenses saved and reflected in financial reports.
Comments: Test file attachments and recurring expenses if implemented.

Finance-002: Payments & Debts (modules/payments, modules/debts) [P:High]
Description: Record supplier/customer payments and debt tracking.
Preconditions: Debts exist or invoices outstanding.
Steps:
1. Navigate to a debt record in modules/debts/view.php.
2. Record a payment via modules/debts/payment.php.
3. Verify outstanding balance updates and ledger entries.
Expected Result: Payment reduces owed amount and creates payment record.
Comments: Validate receipts and accounting mapping.

Finance-003: Reports — Balance Sheet, Financials (modules/reports/balance_sheet.php, financial.php) [P:High]
Description: Generate financial reports and validate totals.
Preconditions: Relevant transactions exist.
Steps:
1. Run modules/reports/balance_sheet.php for a date range.
2. Cross-check totals against ledger and export.
Expected Result: Reports generate and numbers reconcile with transactions.
Comments: Test currency formatting and rounding.

************************************************************************

**Supplier Features**

Supplier-001: Purchase Flow (modules/suppliers/purchase.php) [P:High]
Description: Create purchase orders, receive items, and record payables.
Preconditions: Supplier and products exist.
Steps:
1. Open modules/suppliers/purchase.php and create a purchase order.
2. Mark items as received and record inventory increments.
3. Record payment via modules/suppliers/pay.php.
Expected Result: Inventory increases, payable recorded, supplier balance updated.
Comments: Test partial deliveries and returns.

Supplier-002: Supplier View (modules/suppliers/view.php) [P:Medium]
Description: View supplier details, transactions, and balance.
Preconditions: Transactions exist.
Steps:
1. Open modules/suppliers/view.php for a supplier.
2. Verify transaction history and outstanding amounts.
Expected Result: Accurate listing and totals.
Comments: Test contact updates and uploaded documents.

************************************************************************

**Customer Features**

Customer-001: Customer CRUD and View (modules/customers) [P:High]
Description: Add customers, edit details, view purchase history.
Preconditions: Clerk or admin with permissions.
Steps:
1. Create a customer via modules/customers/add.php.
2. Edit details and upload avatar to uploads/avatars/.
3. View customer's purchase history in modules/customers/view.php.
Expected Result: Customer record saved and history shows purchases.
Comments: Test unique constraints (email/phone) and validation.

Customer-002: Customer Reports (modules/reports/customers.php) [P:Medium]
Description: Generate customer reports by activity, spend.
Preconditions: Transaction data exists.
Steps:
1. Run modules/reports/customers.php with filters.
Expected Result: Report displays segmented customer data.

************************************************************************

**Service & Inventory Features**

Service-001: Service Orders & Invoices (modules/service_orders) [P:High]
Description: Create service orders, invoice customers, and record payments.
Preconditions: Service items exist in modules/services.
Steps:
1. Create a service order in modules/service_orders/add.php.
2. Convert to invoice and send to customer.
3. Record payment and verify service revenue reports.
Expected Result: Order and invoice record correctly, revenue reports reflect sale.

Inventory-001: Product Categories & Stock Adjustments (modules/products/adjust.php, categories.php) [P:High]
Description: Manage categories, adjust stock levels, and validate inventory reports.
Preconditions: Products exist.
Steps:
1. Adjust a product's stock via modules/products/adjust.php.
2. Check modules/reports/inventory.php for updated stock.
Expected Result: Stock updates applied and reported.

************************************************************************

**Support & Notifications**

Support-001: Tickets & Messages (modules/support) [P:Medium]
Description: Create support tickets, respond, and close tickets.
Preconditions: Support user login.
Steps:
1. Create a ticket in modules/support/index.php or tickets.php.
2. Assign to an agent, post responses, and close the ticket.
Expected Result: Ticket lifecycle recorded; notifications sent where applicable.
Comments: Test public/api/notifications.php and notifications_read.php for notification behavior.

Support-002: Email functions (includes/mail.php, lib/Mailer.php) [P:High]
Description: Verify outgoing email for registration, password reset, invoices.
Preconditions: Mail server configured or local mail capture setup.
Steps:
1. Trigger an email flow (e.g., registration, reset password, invoice send).
2. Inspect email content and links.
Expected Result: Emails queued/sent with correct content and working links.
Comments: Use mail capture/stub in staging to avoid sending real emails.

************************************************************************

**API & Integrations (public/api)**

API-001: Notifications API (public/api/notifications.php) [P:Medium]
Description: Fetch and mark notifications as read via API.
Preconditions: Auth token/session for user.
Steps:
1. Call public/api/notifications.php authenticated and verify JSON response.
2. Call public/api/notifications_read.php to mark items read.
Expected Result: API returns notifications and marking as read updates status.

API-002: File Uploads (uploads/*) [P:Medium]
Description: Upload images for products/customers and validate storage.
Preconditions: Write permissions in uploads/.
Steps:
1. Upload an image via product/customer form.
2. Verify file exists in the correct uploads/ subfolder and is accessible.
Expected Result: File stored and path saved in database.

************************************************************************

**Security & Access Control**

SEC-001: Role-Based Access (includes/auth.php, modules/* permissions) [P:High]
Description: Validate that pages and actions are protected by role permissions.
Preconditions: Multiple user accounts with different roles.
Steps:
1. Attempt to access admin pages while logged in as non-admin.
2. Attempt to perform restricted actions (delete, settings) as lower-privilege user.
Expected Result: Access denied or redirected to includes/403.php.

SEC-002: Input Validation & CSRF (forms across app) [P:High]
Description: Test input validation, XSS, and CSRF protections where present.
Preconditions: Forms accessible.
Steps:
1. Submit forms with invalid data and XSS payloads.
2. Confirm server-side validation and sanitization.
Expected Result: Invalid inputs rejected; output encoded; CSRF tokens verified.

************************************************************************

Appendix — Newly Updated Features
---------------------------------
Note: Run these tests with extra focus if recent commits modified the feature.

- New: [specify updated feature names here if known] — add regression test cases ensuring backward compatibility and data migration checks.
- If config.php sample changed, validate setup and setup.php flow.

Delivery & Export
-----------------
- File saved as UAT_Test_Book_SME_Stocqify.md in repository root.
- To create a PDF, use any Markdown-to-PDF converter (e.g., Pandoc or VS Code extension).

Comments: This document is a comprehensive starting point; you can request conversion to Excel/CSV format or a printable PDF.

Expanded Module-level Test Cases
-------------------------------
The following tests extend coverage to every module and system feature in the workspace. Perform these in staging with a seeded database where relevant.

- ALERTS (alerts/index.php)
	- ALERT-001: Verify system alerts display, dismiss, and persist status.
	- Preconditions: Alerts exist in DB.
	- Steps: Open `alerts/index.php`, dismiss an alert, refresh page.
	- Expected Result: Alert dismissed and not shown again if dismissed-permanently.

- DRAWINGS (drawings/*)
	- DRAW-001: Create a drawing entry, attach reference, and validate record removal.

- INCOME (income/*)
	- INC-001: Add income entries, categorize, and view income reports.

- PAYMENTS (payments/index.php)
	- PAY-001: Create payment records for customers and suppliers; validate ledger.

- PROFILE (profile/index.php)
	- PROF-001: Update user profile, upload avatar, change password, and verify session continuity.

- PRODUCTS (modules/products/* - add, adjust, categories, edit, delete)
	- PROD-001: Validate product lifecycle: create, edit, adjust stock, delete and confirm cascading effects on sales, purchase, reports.

- REPORTS (modules/reports/*)
	- REP-001: Validate each report page (`balance_sheet.php`, `customers.php`, `debs.php`, `financial.php`, `inventory.php`, `payments.php`, `sales.php`, `service_revenue.php`) for date filters, export, totals, and reconciliation.

- SUPPORT (support/*)
	- SUP-001: Validate ticket attachments, assignments, escalation, and closure workflows.

- UPDATES & MIGRATIONS
	- MIG-001: When schema changes are made, ensure `database/seed.sql` and `database/schema.sql` apply cleanly and no data loss occurs.

- SETUP and INSTALL (public/setup.php, config files)
	- SETUP-001: Run `public/setup.php` on new environment, confirm database config step, admin creation, and initial seeding.

- ASSETS & STATIC FILES (public/assets, uploads)
	- ASSET-001: Verify CSS/JS loads and image assets served; check upload write-permissions and file size/type validation.

- SEARCH, FILTERS & PAGINATION
	- UX-001: For list pages with pagination or filters (customers, products, sales), verify filters produce correct subsets and paging works at boundary conditions.

- EXPORTS & PRINTING
	- EXP-001: Validate CSV/PDF/print outputs for invoices, reports, and receipts; ensure header/footer and page breaks are correct.

- LOGIN FLOWS & SESSIONS
	- AUTH-002: Session timeout and forced logout behavior; concurrent session handling if applicable.

- AUDIT & LOGGING
	- LOG-001: Verify that critical actions (create/delete/update financials, user management) are logged with user and timestamp.

Regression Focus
----------------
- For newly updated features, add regression cases that verify previous behavior remains intact. Examples:
	- If a product import/export feature was updated, run prior import/export scenarios and compare results.
	- If permission checks changed, re-run SEC-001 across multiple roles.

Prioritization
--------------
- Execute high-priority tests first (`[P:High]`), then medium, then low. Record severity for any defects.

