<?php
// Compute <base> href so relative links within module pages resolve via clean URLs
$__self = $_SERVER['PHP_SELF'];
if (preg_match('|/modules/([^/]+)/|', $__self, $__m)) {
    $__seg = $__m[1] === 'service_orders' ? 'service-orders' : $__m[1];
    $__baseHref = SITE_URL . '/' . $__seg . '/';
} else {
    $__baseHref = SITE_URL . '/';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= $__baseHref ?>">
    <title><?= h($pageTitle ?? 'Dashboard') ?> | <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: '#1d4ed8', light: '#3b82f6', dark: '#1e3a8a' },
                        accent:  { DEFAULT: '#f59e0b' },
                    }
                }
            }
        }
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .table-responsive { overflow-x: auto; }
        /* Smooth page transitions */
        main { animation: fadeIn .18s ease; }
        @keyframes fadeIn { from { opacity:.7; transform:translateY(4px); } to { opacity:1; transform:none; } }
    </style>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="flex h-screen overflow-hidden">
