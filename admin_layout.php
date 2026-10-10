<?php
// admin_layout.php - Shared admin shell: sidebar navigation + page header

const ADMIN_NAV = [
    'overview'  => ['admin_lucky.php', 'overview', 'Overview'],
    'customers' => ['admin_customers.php', 'users', 'Customers'],
    'codes'     => ['generate_qr.php', 'qr', 'QR codes'],
    'draw'      => ['admin_draw.php', 'gift', 'Lucky draw'],
    'settings'  => ['admin_settings.php', 'settings', 'Settings'],
];

/** Opens the page. $active is a key of ADMIN_NAV; the browser title uses its label. */
function admin_header($active, $title = null) {
    $title = $title ?? ADMIN_NAV[$active][2];
    $user = $_SESSION['admin_username'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> · Lucky Draw Admin</title>
    <link rel="icon" href="image.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="shell" id="shell">
    <div class="topbar">
        <button class="btn btn-quiet btn-sm" id="menuBtn" aria-label="Open menu"><?php echo icon('menu'); ?></button>
        <div class="brand"><img src="image.png" alt="" onerror="this.style.display='none'"><b>Lucky Draw</b></div>
    </div>
    <div class="overlay" id="overlay"></div>

    <aside class="sidebar">
        <div class="brand">
            <img src="image.png" alt="" onerror="this.style.display='none'">
            <div><b>Lucky Draw</b><small>ASTECH Legacy</small></div>
            <button class="btn btn-quiet btn-sm sidebar-close" id="menuClose" aria-label="Close menu"><?php echo icon('close'); ?></button>
        </div>
        <nav class="nav">
            <?php foreach (ADMIN_NAV as $key => [$href, $ico, $label]): ?>
                <a href="<?php echo $href; ?>" class="<?php echo $key === $active ? 'active' : ''; ?>"><?php echo icon($ico) . e($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot nav">
            <a href="index.php" target="_blank"><?php echo icon('external'); ?>Customer page</a>
            <a href="admin_lucky.php?logout=1"><?php echo icon('logout'); ?>Log out</a>
            <div class="user"><span class="avatar"><?php echo e(mb_substr($user, 0, 1)); ?></span><b><?php echo e($user); ?></b></div>
        </div>
    </aside>

    <main class="main"><div class="page">
    <?php
    echo qr_link_warning();
}

/**
 * Standard page header. $actions is HTML for buttons on the right;
 * $crumb = [label, href] adds a "back to" link above the title.
 */
function page_head($title, $subtitle = '', $actions = '', $crumb = null) {
    ?>
    <div class="page-head">
        <div>
            <?php if ($crumb): ?><a class="crumbs" href="<?php echo e($crumb[1]); ?>"><?php echo icon('back') . e($crumb[0]); ?></a><?php endif; ?>
            <h1><?php echo e($title); ?></h1>
            <?php if ($subtitle !== ''): ?><p><?php echo $subtitle; ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?><div class="btn-row no-print"><?php echo $actions; ?></div><?php endif; ?>
    </div>
    <?php
}

function admin_footer() {
    ?>
    </div></main>
</div>
<script>
(function () {
    // Mobile menu: open with the menu button; close with ×, a tap outside, or Esc
    var shell = document.getElementById('shell');
    function closeMenu() { shell.classList.remove('nav-open'); }
    document.getElementById('menuBtn').addEventListener('click', function () { shell.classList.add('nav-open'); });
    document.getElementById('menuClose').addEventListener('click', closeMenu);
    document.getElementById('overlay').addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
})();
</script>
</body>
</html>
    <?php
}
