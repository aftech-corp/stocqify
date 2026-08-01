<?php
/**
 * Salone Bizness - Admin Reset & Seed Loader
 * Visit this page to fix login issues or reload demo data.
 * DELETE this file after use.
 */
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';

$message = '';
$errors  = [];
$info    = [];

// Generate a fresh bcrypt hash once — used when inserting/resetting users
$freshHash = password_hash('password', PASSWORD_BCRYPT);

try {
    $db = getDB();

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $info[] = 'Connected to database. Tables: ' . (count($tables) ? implode(', ', $tables) : 'NONE (run setup.php first)');

    $hasTables = in_array('users', $tables) && in_array('roles', $tables);

    if ($hasTables) {
        $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $roleCount = $db->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        $info[] = "Rows — users: {$userCount} | roles: {$roleCount}";
    }

    // ── POST actions ──────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTables) {
        $action = $_POST['action'] ?? '';

        // ---- Reset / create admin user ----
        if ($action === 'reset_password') {
            // Ensure admin role exists
            $roleRow = $db->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1")->fetch();
            if (!$roleRow) {
                $db->exec("INSERT INTO roles (name, slug, permissions) VALUES
                    ('Administrator','admin','{\"all\":true}'),
                    ('Business Owner','owner','{\"dashboard\":true,\"sales\":true,\"inventory\":true,\"customers\":true,\"debts\":true,\"payments\":true,\"expenses\":true,\"reports\":true,\"alerts\":true,\"users\":true}'),
                    ('Manager','manager','{\"dashboard\":true,\"sales\":true,\"inventory\":true,\"customers\":true,\"debts\":true,\"payments\":true,\"expenses\":true,\"reports\":true,\"alerts\":true}'),
                    ('Sales Officer','sales_officer','{\"dashboard\":true,\"sales\":true,\"customers\":true,\"payments\":true}'),
                    ('Accountant','accountant','{\"dashboard\":true,\"payments\":true,\"expenses\":true,\"reports\":true,\"debts\":true}')");
                $roleRow = $db->query("SELECT id FROM roles WHERE slug='admin' LIMIT 1")->fetch();
            }

            $existing = $db->prepare("SELECT id FROM users WHERE email=?");
            $existing->execute(['admin@sme.sl']);
            if ($existing->fetch()) {
                $db->prepare("UPDATE users SET password=?, is_active=1 WHERE email=?")
                   ->execute([$freshHash, 'admin@sme.sl']);
                $message = 'Admin password reset to "password". You can now log in.';
            } else {
                $db->prepare("INSERT INTO users (business_id,role_id,name,email,phone,password,is_active) VALUES (NULL,?,?,?,?,?,1)")
                   ->execute([$roleRow['id'], 'System Administrator', 'admin@sme.sl', '+232 76 000 001', $freshHash]);
                $message = 'Admin user created with password "password". You can now log in.';
            }
        }

        // ---- Load / reload all seed data ----
        if ($action === 'load_seed') {
            $inserted = 0;
            $skipped  = 0;

            // 1. Roles
            $roleDefs = [
                ['Administrator',  'admin',        '{"all":true}'],
                ['Business Owner', 'owner',        '{"dashboard":true,"sales":true,"inventory":true,"customers":true,"debts":true,"payments":true,"expenses":true,"reports":true,"alerts":true,"users":true}'],
                ['Manager',        'manager',      '{"dashboard":true,"sales":true,"inventory":true,"customers":true,"debts":true,"payments":true,"expenses":true,"reports":true,"alerts":true}'],
                ['Sales Officer',  'sales_officer','{"dashboard":true,"sales":true,"customers":true,"payments":true}'],
                ['Accountant',     'accountant',   '{"dashboard":true,"payments":true,"expenses":true,"reports":true,"debts":true}'],
            ];
            $roleIds = [];
            foreach ($roleDefs as [$name, $slug, $perms]) {
                $r = $db->prepare("SELECT id FROM roles WHERE slug=?");
                $r->execute([$slug]);
                $row = $r->fetch();
                if (!$row) {
                    $db->prepare("INSERT INTO roles (name,slug,permissions) VALUES (?,?,?)")->execute([$name,$slug,$perms]);
                    $row = $db->query("SELECT id FROM roles WHERE slug='{$slug}' LIMIT 1")->fetch();
                    $inserted++;
                }
                $roleIds[$slug] = $row['id'];
            }

            // 2. Default business
            $biz = $db->query("SELECT id FROM businesses WHERE name='Demo Business - Freetown' LIMIT 1")->fetch();
            if (!$biz) {
                $db->prepare("INSERT INTO businesses (name,address,phone,email,currency) VALUES (?,?,?,?,?)")
                   ->execute(['Demo Business - Freetown','27 Siaka Stevens Street, Freetown','+232 76 000 000','demo@business.sl','SLE']);
                $biz = $db->query("SELECT id FROM businesses ORDER BY id DESC LIMIT 1")->fetch();
                $inserted++;
            }
            $bizId = $biz['id'];

            // 3. Users
            $users = [
                [null,   'admin',        'System Administrator', 'admin@sme.sl',   '+232 76 000 001'],
                [$bizId, 'owner',        'Business Owner',       'owner@demo.sl',  '+232 76 000 002'],
                [$bizId, 'manager',      'John Manager',         'manager@demo.sl','+232 76 000 003'],
                [$bizId, 'sales_officer','Mary Sales',           'sales@demo.sl',  '+232 76 000 004'],
            ];
            foreach ($users as [$bid, $roleSlug, $name, $email, $phone]) {
                $check = $db->prepare("SELECT id FROM users WHERE email=?");
                $check->execute([$email]);
                if (!$check->fetch()) {
                    $db->prepare("INSERT INTO users (business_id,role_id,name,email,phone,password,is_active) VALUES (?,?,?,?,?,?,1)")
                       ->execute([$bid, $roleIds[$roleSlug], $name, $email, $phone, $freshHash]);
                    $inserted++;
                } else {
                    // Reset password on existing users so they work
                    $db->prepare("UPDATE users SET password=?, is_active=1 WHERE email=?")->execute([$freshHash, $email]);
                    $skipped++;
                }
            }

            // 4. Product categories
            $cats = ['Food & Groceries'=>'Foodstuff and grocery items','Beverages'=>'Drinks and beverages','Electronics'=>'Electronic devices','Clothing'=>'Apparel and clothing','Household'=>'Household supplies'];
            $catIds = [];
            foreach ($cats as $catName => $catDesc) {
                $c = $db->prepare("SELECT id FROM categories WHERE business_id=? AND name=?");
                $c->execute([$bizId, $catName]);
                $row = $c->fetch();
                if (!$row) {
                    $db->prepare("INSERT INTO categories (business_id,name,description) VALUES (?,?,?)")->execute([$bizId,$catName,$catDesc]);
                    $row = ['id' => $db->lastInsertId()];
                    $inserted++;
                }
                $catIds[$catName] = $row['id'];
            }

            // 5. Products
            $products = [
                [$catIds['Food & Groceries'], 'Rice 25kg',       'RIC-25KG',  'bag',    180000, 220000, 50,  10, 200],
                [$catIds['Food & Groceries'], 'Palm Oil 1L',     'POL-1L',    'bottle',  15000,  20000,120,  20, 500],
                [$catIds['Food & Groceries'], 'Sugar 2kg',       'SUG-2KG',   'pack',    18000,  24000, 80,  15, 300],
                [$catIds['Food & Groceries'], 'Flour 2kg',       'FLO-2KG',   'pack',    16000,  22000, 60,  15, 250],
                [$catIds['Beverages'],        'Coca Cola 1.5L',  'COC-1.5L',  'bottle',   8000,  12000,144,  24, 500],
                [$catIds['Beverages'],        'Water 600ml',     'WAT-600ML', 'bottle',   1500,   2500,240,  48,1000],
                [$catIds['Beverages'],        'Malta 330ml',     'MAL-330ML', 'can',      5000,   7000, 96,  24, 400],
                [$catIds['Food & Groceries'], 'Cooking Oil 2L',  'COO-2L',    'bottle',  32000,  42000,  8,  15, 200],
                [$catIds['Food & Groceries'], 'Tomato Paste 400g','TOM-400G', 'tin',     12000,  16000,  3,  10, 150],
                [$catIds['Clothing'],         'T-Shirt (M)',     'TSH-M',     'piece',   40000,  70000, 25,   5, 100],
            ];
            foreach ($products as [$catId, $name, $sku, $unit, $cost, $sell, $stock, $reorder, $max]) {
                $p = $db->prepare("SELECT id FROM products WHERE business_id=? AND sku=?");
                $p->execute([$bizId, $sku]);
                if (!$p->fetch()) {
                    $db->prepare("INSERT INTO products (business_id,category_id,name,sku,unit,cost_price,selling_price,stock_quantity,reorder_level,max_stock) VALUES (?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$bizId,$catId,$name,$sku,$unit,$cost,$sell,$stock,$reorder,$max]);
                    $inserted++;
                }
            }

            // 6. Customers
            $customers = [
                ['Mohamed Kamara',  '+232 76 123 456','mkamara@gmail.com', '15 Wilberforce Street, Freetown','Kamara Trading',  500000],
                ['Fatima Sesay',    '+232 78 234 567', null,               'Kissy Road, Freetown',           null,              200000],
                ['Ibrahim Koroma',  '+232 79 345 678','ikoroma@mail.com', 'Lumley Beach Road, Freetown',    'IK Stores',      1000000],
                ['Aminata Bangura', '+232 76 456 789', null,               'Congo Cross, Freetown',          null,              150000],
                ['David Johnson',   '+232 78 567 890','djohnson@business.com','Goderich Street, Freetown',  'Johnson Enterprises',2000000],
            ];
            foreach ($customers as [$name,$phone,$email,$address,$bizName,$limit]) {
                $c = $db->prepare("SELECT id FROM customers WHERE business_id=? AND name=?");
                $c->execute([$bizId,$name]);
                if (!$c->fetch()) {
                    $db->prepare("INSERT INTO customers (business_id,name,phone,email,address,business_name,credit_limit) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$bizId,$name,$phone,$email,$address,$bizName,$limit]);
                    $inserted++;
                }
            }

            // 7. Expense categories
            foreach (['Rent','Utilities','Salaries','Transport','Marketing','Maintenance','Supplies','Miscellaneous'] as $eCat) {
                $ec = $db->prepare("SELECT id FROM expense_categories WHERE business_id=? AND name=?");
                $ec->execute([$bizId,$eCat]);
                if (!$ec->fetch()) {
                    $db->prepare("INSERT INTO expense_categories (business_id,name) VALUES (?,?)")->execute([$bizId,$eCat]);
                    $inserted++;
                }
            }

            $message = "Seed data loaded successfully! {$inserted} records inserted, {$skipped} existing users had passwords reset.";
        }

        // Refresh counts
        $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $info[] = "Users after action: {$userCount}";
    }

    // Fetch users for display
    $users = $hasTables
        ? $db->query("SELECT u.name, u.email, u.is_active, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id")->fetchAll()
        : [];

} catch (PDOException $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
    $hasTables = false;
    $users = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix / Reset | Salone Bizness</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-6">
<div class="max-w-2xl mx-auto">

    <div class="bg-amber-50 border border-amber-300 rounded-xl p-3 mb-5 text-sm text-amber-900">
        <strong>Security:</strong> Delete <code>reset_admin.php</code> from <code>public/</code> immediately after fixing.
    </div>

    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h1 class="text-xl font-bold text-gray-800 mb-4">Salone Bizness — Diagnostics &amp; Fix</h1>

        <?php foreach ($errors as $e): ?>
        <div class="bg-red-50 border border-red-300 text-red-800 rounded-lg p-3 mb-3 text-sm"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-300 text-green-800 rounded-lg p-3 mb-3 text-sm font-medium">
            ✓ <?= htmlspecialchars($message) ?>
            <a href="login.php" class="ml-3 underline text-green-700">→ Go to Login</a>
        </div>
        <?php endif; ?>

        <?php foreach ($info as $line): ?>
        <p class="text-xs font-mono text-gray-500 mb-0.5"><?= htmlspecialchars($line) ?></p>
        <?php endforeach; ?>
    </div>

    <!-- Users table -->
    <?php if ($hasTables): ?>
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h2 class="font-semibold text-gray-700 mb-3">Users in Database</h2>
        <?php if (empty($users)): ?>
        <p class="text-red-600 text-sm font-semibold">No users found — use "Load All Seed Data" below.</p>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase border-b"><tr>
                <th class="text-left py-1 pr-3">Name</th>
                <th class="text-left py-1 pr-3">Email</th>
                <th class="text-left py-1 pr-3">Role</th>
                <th class="text-left py-1">Active</th>
            </tr></thead>
            <tbody class="divide-y">
            <?php foreach ($users as $u): ?>
            <tr>
                <td class="py-1.5 pr-3"><?= htmlspecialchars($u['name']) ?></td>
                <td class="py-1.5 pr-3 font-mono text-xs text-blue-700"><?= htmlspecialchars($u['email']) ?></td>
                <td class="py-1.5 pr-3 text-gray-500 text-xs"><?= htmlspecialchars($u['role']) ?></td>
                <td class="py-1.5"><?= $u['is_active'] ? '<span class="text-green-600">✓ Yes</span>' : '<span class="text-red-500">✗ No</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Fix actions -->
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h2 class="font-semibold text-gray-700 mb-4">Fix Options</h2>
        <form method="POST" class="space-y-3">
            <div class="border border-blue-200 bg-blue-50 rounded-lg p-4">
                <p class="font-medium text-gray-800 text-sm mb-1">Option 1 — Load All Seed Data</p>
                <p class="text-xs text-gray-600 mb-3">Inserts all demo roles, users, products, customers, and expense categories. Skips rows that already exist. <strong>Also resets all demo user passwords to <code>password</code>.</strong></p>
                <button type="submit" name="action" value="load_seed"
                    class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    Load All Seed Data
                </button>
            </div>
            <div class="border rounded-lg p-4">
                <p class="font-medium text-gray-800 text-sm mb-1">Option 2 — Reset Admin Password Only</p>
                <p class="text-xs text-gray-600 mb-3">Creates or resets <code>admin@sme.sl</code> with password <code>password</code>.</p>
                <button type="submit" name="action" value="reset_password"
                    class="bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-800">
                    Reset Admin Password
                </button>
            </div>
        </form>
    </div>

    <div class="bg-gray-50 rounded-xl border p-4 text-sm text-gray-600">
        <p class="font-semibold mb-2">Demo Credentials (password: <code class="bg-gray-200 px-1 rounded">password</code>)</p>
        <div class="grid grid-cols-2 gap-1 text-xs font-mono">
            <p>admin@sme.sl</p><p>Admin</p>
            <p>owner@demo.sl</p><p>Business Owner</p>
            <p>manager@demo.sl</p><p>Manager</p>
            <p>sales@demo.sl</p><p>Sales Officer</p>
        </div>
    </div>

    <?php else: ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-800">
        Database tables are missing. <a href="setup.php" class="underline font-medium">Run setup.php first</a> to create the schema.
    </div>
    <?php endif; ?>

</div>
</body>
</html>
