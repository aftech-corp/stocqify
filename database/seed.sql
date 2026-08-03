-- ============================================================
-- SME Management System - Seed Data
-- Run after schema.sql
-- ============================================================

USE `sme_management`;

-- Roles
INSERT INTO `roles` (`name`, `slug`, `permissions`) VALUES
('Administrator', 'admin', '{"all": true}'),
('Business Owner', 'owner', '{"dashboard":true,"sales":true,"inventory":true,"customers":true,"debts":true,"payments":true,"expenses":true,"reports":true,"alerts":true,"users":true}'),
('Manager', 'manager', '{"dashboard":true,"sales":true,"inventory":true,"customers":true,"debts":true,"payments":true,"expenses":true,"reports":true,"alerts":true}'),
('Sales Officer', 'sales_officer', '{"dashboard":true,"sales":true,"customers":true,"payments":true}'),
('Accountant', 'accountant', '{"dashboard":true,"payments":true,"expenses":true,"reports":true,"debts":true}');

-- Default business
INSERT INTO `businesses` (`name`, `address`, `phone`, `email`, `currency`) VALUES
('Demo Business - Freetown', '27 Siaka Stevens Street, Freetown', '+232 76 000 000', 'demo@business.sl', 'SLE');

-- Admin user  (password: password)
INSERT INTO `users` (`business_id`, `role_id`, `name`, `email`, `phone`, `password`) VALUES
(NULL, 1, 'System Administrator', 'admin@sme.sl', '+232 76 000 001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(1, 2, 'Business Owner', 'owner@demo.sl', '+232 76 000 002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(1, 3, 'John Manager', 'manager@demo.sl', '+232 76 000 003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(1, 4, 'Mary Sales', 'sales@demo.sl', '+232 76 000 004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Product Categories
INSERT INTO `categories` (`business_id`, `name`, `description`) VALUES
(1, 'Food & Groceries', 'Foodstuff and grocery items'),
(1, 'Beverages', 'Drinks and beverages'),
(1, 'Electronics', 'Electronic devices and accessories'),
(1, 'Clothing', 'Apparel and clothing items'),
(1, 'Household', 'Household items and supplies');

-- Products
INSERT INTO `products` (`business_id`, `category_id`, `name`, `sku`, `unit`, `cost_price`, `selling_price`, `stock_quantity`, `reorder_level`, `max_stock`) VALUES
(1, 1, 'Rice 25kg', 'RIC-25KG', 'bag', 180000.00, 220000.00, 50.00, 10.00, 200.00),
(1, 1, 'Palm Oil 1L', 'POL-1L', 'bottle', 15000.00, 20000.00, 120.00, 20.00, 500.00),
(1, 1, 'Sugar 2kg', 'SUG-2KG', 'pack', 18000.00, 24000.00, 80.00, 15.00, 300.00),
(1, 1, 'Flour 2kg', 'FLO-2KG', 'pack', 16000.00, 22000.00, 60.00, 15.00, 250.00),
(1, 2, 'Coca Cola 1.5L', 'COC-1.5L', 'bottle', 8000.00, 12000.00, 144.00, 24.00, 500.00),
(1, 2, 'Water 600ml', 'WAT-600ML', 'bottle', 1500.00, 2500.00, 240.00, 48.00, 1000.00),
(1, 2, 'Malta 330ml', 'MAL-330ML', 'can', 5000.00, 7000.00, 96.00, 24.00, 400.00),
(1, 1, 'Cooking Oil 2L', 'COO-2L', 'bottle', 32000.00, 42000.00, 8.00, 15.00, 200.00),
(1, 1, 'Tomato Paste 400g', 'TOM-400G', 'tin', 12000.00, 16000.00, 3.00, 10.00, 150.00),
(1, 4, 'T-Shirt (M)', 'TSH-M', 'piece', 40000.00, 70000.00, 25.00, 5.00, 100.00);

-- Customers
INSERT INTO `customers` (`business_id`, `name`, `phone`, `email`, `address`, `business_name`, `credit_limit`) VALUES
(1, 'Mohamed Kamara', '+232 76 123 456', 'mkamara@gmail.com', '15 Wilberforce Street, Freetown', 'Kamara Trading', 500000.00),
(1, 'Fatima Sesay', '+232 78 234 567', NULL, 'Kissy Road, Freetown', NULL, 200000.00),
(1, 'Ibrahim Koroma', '+232 79 345 678', 'ikoroma@mail.com', 'Lumley Beach Road, Freetown', 'IK Stores', 1000000.00),
(1, 'Aminata Bangura', '+232 76 456 789', NULL, 'Congo Cross, Freetown', NULL, 150000.00),
(1, 'David Johnson', '+232 78 567 890', 'djohnson@business.com', 'Goderich Street, Freetown', 'Johnson Enterprises', 2000000.00);

-- Expense Categories
INSERT INTO `expense_categories` (`business_id`, `name`) VALUES
(1, 'Rent'),
(1, 'Utilities'),
(1, 'Salaries'),
(1, 'Transport'),
(1, 'Marketing'),
(1, 'Maintenance'),
(1, 'Supplies'),
(1, 'Miscellaneous');
