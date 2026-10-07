<?php
// admin_layout.php - Shared admin shell (sidebar + page wrapper)

function admin_header($title, $active) {
    $nav = [
        'dashboard' => ['admin_lucky.php', '📊', 'Dashboard'],
        'draw'      => ['admin_draw.php', '🎡', 'Spin & Winners'],
        'codes'     => ['generate_qr.php', '🏷️', 'QR Codes'],
        'settings'  => ['admin_settings.php', '⚙️', 'Campaign'],
        'export'    => ['admin_lucky_export.php', '📥', 'Export CSV'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> | Lucky Draw Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="admin">
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="image.png" alt="" onerror="this.style.display='none'">
        <div><b>Lucky Draw</b><small>ASTECH LEGACY</small></div>
    </div>
    <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a href="<?php echo $href; ?>" class="nav-link<?php echo $key === $active ? ' active' : ''; ?>">
            <span class="ico"><?php echo $icon; ?></span><span class="label"><?php echo $label; ?></span>
        </a>
    <?php endforeach; ?>
    <a href="index.php" target="_blank" class="nav-link"><span class="ico">🌐</span><span class="label">Public page</span></a>
    <a href="admin_lucky.php?logout=1" class="nav-link"><span class="ico">🚪</span><span class="label">Logout</span></a>
    <div class="sidebar-foot">Signed in as <b><?php echo e($_SESSION['admin_username'] ?? ''); ?></b></div>
</aside>
<main class="main">
    <?php
}

function admin_footer() {
    ?>
</main>
</body>
</html>
    <?php
}
