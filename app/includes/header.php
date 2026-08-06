<?php
// Compute <base> href so relative links within module pages resolve via clean URLs
$__self = $_SERVER['PHP_SELF'];
if (preg_match('|/modules/([^/]+)/|', $__self, $__m)) {
    $__seg = $__m[1] === 'service_orders' ? 'service-orders' : ($__m[1] === 'bulk' ? 'import' : $__m[1]);
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
    <title><?= h(APP_NAME) ?> | <?= h($pageTitle ?? 'Dashboard') ?></title>
    <link rel="icon" type="image/png" href="<?= APP_LOGO ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: { DEFAULT: '#1B3263', light: '#2952a3', dark: '#142552', 50: '#eef1f8' },
                        accent:  { DEFAULT: '#C9A84C', dark: '#a8892e', light: '#dfc471' },
                        // Override Tailwind blue → brand navy so bg-blue-xxx/text-blue-xxx match brand throughout
                        blue: {
                            50:  '#eef1f8',
                            100: '#dce5f2',
                            200: '#bacceb',
                            300: '#8aaad9',
                            400: '#5c88c9',
                            500: '#2952a3',
                            600: '#1B3263',
                            700: '#142552',
                            800: '#0e1f3f',
                            900: '#091428',
                            950: '#050e22',
                        }
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
