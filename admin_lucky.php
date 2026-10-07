<?php
// admin_lucky.php - Admin login + dashboard
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $result = validateAdminCredentials(trim($_POST['username']), $_POST['password'] ?? '', $pdo);
    if ($result['success']) {
        session_regenerate_id(true);
        setAdminSession($result['user']);
        header('Location: admin_lucky.php');
        exit;
    }
    $login_error = $result['message'];
}

if (isset($_GET['logout'])) {
    logoutAdmin();
    header('Location: admin_lucky.php');
    exit;
}

// ---------- Login page ----------
if (!isAdminLoggedIn()) {
    public_head('Admin login');
    ?>
<div class="wrap auth-card">
    <?php brand_block('Admin Panel'); ?>
    <div class="card">
        <?php if ($login_error): ?><div class="alert alert-error"><?php echo e($login_error); ?></div><?php endif; ?>
        <form method="POST">
            <div class="field">
                <label for="u">Username</label>
                <input type="text" id="u" name="username" required autofocus autocomplete="username" value="<?php echo e($_POST['username'] ?? ''); ?>">
            </div>
            <div class="field">
                <label for="p">Password</label>
                <input type="password" id="p" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Log in</button>
        </form>
        <p class="text-center muted mt-2" style="font-size:14px">No account? <a href="admin_register.php">Register as admin</a></p>
    </div>
</div>
    <?php
    public_foot();
    exit;
}

// ---------- Dashboard data ----------
$settings = get_settings($pdo);
$state = campaign_state($settings);

$totalEntries   = (int)$pdo->query("SELECT COUNT(*) FROM lucky_draw_entries")->fetchColumn();
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM lucky_customers")->fetchColumn();
$totalCodes     = (int)$pdo->query("SELECT COUNT(*) FROM product_codes")->fetchColumn();
$scannedCodes   = (int)$pdo->query("SELECT COUNT(DISTINCT s.purchase_id) FROM qr_scan_log s JOIN product_codes p ON p.code = s.purchase_id")->fetchColumn();
$totalScans     = (int)$pdo->query("SELECT COUNT(*) FROM qr_scan_log s JOIN product_codes p ON p.code = s.purchase_id")->fetchColumn();
$totalWinners   = (int)$pdo->query("SELECT COUNT(*) FROM winners")->fetchColumn();
$todayEntries   = (int)$pdo->query("SELECT COUNT(*) FROM lucky_draw_entries WHERE entry_date >= CURDATE()")->fetchColumn();

$pct = function ($a, $b) { return $b > 0 ? round($a / $b * 100, 1) : 0; };
$scanToReg = $pct($totalEntries, $scannedCodes);

// Entries per day - last 14 days
$days = 14;
$perDay = [];
for ($i = $days - 1; $i >= 0; $i--) $perDay[date('Y-m-d', strtotime("-$i days"))] = 0;
$stmt = $pdo->prepare("SELECT DATE(entry_date) d, COUNT(*) c FROM lucky_draw_entries WHERE entry_date >= ? GROUP BY DATE(entry_date)");
$stmt->execute([array_key_first($perDay)]);
foreach ($stmt as $row) $perDay[$row['d']] = (int)$row['c'];

function bar_chart_svg(array $data) {
    $w = 640; $h = 220; $padL = 8; $padB = 26; $padT = 22;
    $max = max(1, max($data));
    $n = count($data);
    $slot = ($w - $padL * 2) / $n;
    $barW = $slot * 0.62;
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Entries per day">';
    $out .= '<defs><linearGradient id="barGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fb7185"/><stop offset="1" stop-color="#e11d48"/></linearGradient></defs>';
    for ($g = 0; $g <= 3; $g++) {
        $y = $padT + ($h - $padT - $padB) * $g / 3;
        $out .= '<line class="grid-line" x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>';
    }
    $i = 0;
    foreach ($data as $date => $value) {
        $bh = ($h - $padT - $padB) * $value / $max;
        $x = $padL + $slot * $i + ($slot - $barW) / 2;
        $y = $h - $padB - $bh;
        $label = date('j/n', strtotime($date));
        $out .= '<rect class="bar" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round(max($bh, $value ? 2 : 0), 1) . '" rx="5"><title>' . $label . ': ' . $value . ' entries</title></rect>';
        if ($value) $out .= '<text class="val" x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 6, 1) . '" text-anchor="middle">' . $value . '</text>';
        $out .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . $label . '</text>';
        $i++;
    }
    return $out . '</svg>';
}

// Customers table
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE customer_id LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?";
    // Phone numbers are stored as 60XXXXXXXXX, so match "012 345..." by its digits without the leading 0
    $isPhone = preg_match('/^[\d\s+\-()]+$/', $search);
    $phoneSearch = $isPhone ? '%' . ltrim(preg_replace('/\D+/', '', $search), '0') . '%' : "%$search%";
    $params = ["%$search%", "%$search%", $phoneSearch];
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM lucky_customers $where");
$stmt->execute($params);
$totalPages = max(1, (int)ceil($stmt->fetchColumn() / $limit));
$page = min($page, $totalPages);
$stmt = $pdo->prepare("SELECT * FROM lucky_customers $where ORDER BY total_entries DESC, first_entry_date ASC LIMIT $limit OFFSET " . (($page - 1) * $limit));
$stmt->execute($params);
$customers = $stmt->fetchAll();

$stateBadge = ['upcoming' => ['badge-blue', 'Not started'], 'active' => ['badge-green', 'Open'], 'ended' => ['badge-red', 'Ended']][$state];

admin_header('Dashboard', 'dashboard');
?>
<div class="page-head">
    <div>
        <h1><?php echo e($settings['campaign_title']); ?></h1>
        <p>
            <?php if ($settings['campaign_start'] && $settings['campaign_end']): ?>
                <?php echo e(format_datetime($settings['campaign_start'])); ?> → <?php echo e(format_datetime($settings['campaign_end'])); ?>
            <?php elseif ($settings['campaign_start']): ?>
                From <?php echo e(format_datetime($settings['campaign_start'])); ?> · no end date
            <?php elseif ($settings['campaign_end']): ?>
                Until <?php echo e(format_datetime($settings['campaign_end'])); ?>
            <?php else: ?>
                No campaign dates set - <a href="admin_settings.php">set them</a>
            <?php endif; ?>
        </p>
    </div>
    <span class="badge <?php echo $stateBadge[0]; ?>"><span class="dot"></span> <?php echo $stateBadge[1]; ?></span>
</div>

<div class="stats">
    <div class="stat"><div class="stat-label">🎟️ Total entries</div><div class="stat-value"><?php echo number_format($totalEntries); ?></div><div class="stat-sub">+<?php echo $todayEntries; ?> today</div></div>
    <div class="stat"><div class="stat-label">👥 Customers</div><div class="stat-value"><?php echo number_format($totalCustomers); ?></div><div class="stat-sub"><?php echo $totalCustomers ? number_format($totalEntries / $totalCustomers, 1) : 0; ?> entries each</div></div>
    <div class="stat"><div class="stat-label">🏷️ QR codes issued</div><div class="stat-value"><?php echo number_format($totalCodes); ?></div><div class="stat-sub"><?php echo $pct($totalEntries, $totalCodes); ?>% registered</div></div>
    <div class="stat"><div class="stat-label">🏆 Winners drawn</div><div class="stat-value"><?php echo number_format($totalWinners); ?></div><div class="stat-sub"><a href="admin_draw.php">Open spin wheel →</a></div></div>
</div>

<div class="grid-2">
    <div class="panel chart">
        <div class="panel-head"><div><h2>Entries per day</h2><p>Last <?php echo $days; ?> days</p></div></div>
        <?php echo bar_chart_svg($perDay); ?>
    </div>
    <div class="panel">
        <div class="panel-head"><div><h2>Scan → registration</h2><p>Of the codes scanned, how many registered</p></div></div>
        <div class="big-rate"><?php echo $scanToReg; ?>%</div>
        <div class="funnel mt-2">
            <div class="funnel-row"><div class="top"><span>Codes issued</span><b><?php echo number_format($totalCodes); ?></b></div><div class="track"><div style="width:100%"></div></div></div>
            <div class="funnel-row"><div class="top"><span>Codes scanned</span><b><?php echo number_format($scannedCodes); ?></b></div><div class="track gold"><div style="width:<?php echo $pct($scannedCodes, $totalCodes); ?>%"></div></div></div>
            <div class="funnel-row"><div class="top"><span>Registered</span><b><?php echo number_format($totalEntries); ?></b></div><div class="track green"><div style="width:<?php echo $pct($totalEntries, $totalCodes); ?>%"></div></div></div>
        </div>
        <p class="faint mt-2" style="font-size:12px"><?php echo number_format($totalScans); ?> total scans (including repeat scans)</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div><h2>Customers</h2><p>Click a row to see the products they registered</p></div>
        <form method="GET" class="toolbar" style="flex:1;max-width:420px">
            <div class="search"><input type="search" name="search" placeholder="Search ID, name or phone" value="<?php echo e($search); ?>" autocomplete="off"></div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Customer</th><th>Phone</th><th>Entries</th><th>First entry</th><th>Last entry</th><th></th></tr></thead>
            <tbody>
            <?php if (!$customers): ?>
                <tr><td colspan="6"><div class="empty"><?php echo $search ? 'No customers match your search.' : 'No entries yet. Share your QR codes to get started!'; ?></div></td></tr>
            <?php endif; ?>
            <?php foreach ($customers as $c): ?>
                <tr class="row-click" data-customer="<?php echo e($c['customer_id']); ?>">
                    <td><span class="chev">›</span><strong><?php echo e($c['customer_name']); ?></strong><br><span class="faint mono" style="font-size:12px;margin-left:18px;white-space:nowrap"><?php echo e($c['customer_id']); ?></span></td>
                    <td class="mono" style="white-space:nowrap"><?php echo e(format_phone($c['customer_phone'])); ?></td>
                    <td><span class="badge badge-red"><?php echo (int)$c['total_entries']; ?></span></td>
                    <td class="muted" style="white-space:nowrap"><?php echo e(format_datetime($c['first_entry_date'], 'd/m/Y H:i')); ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo e(format_datetime($c['last_entry_date'], 'd/m/Y H:i')); ?></td>
                    <td><a class="btn btn-wa btn-sm" target="_blank" rel="noopener" href="<?php echo e(wa_link($c['customer_phone'], 'Salam ' . $c['customer_name'] . ', ')); ?>">WhatsApp</a></td>
                </tr>
                <tr class="detail-row" data-detail="<?php echo e($c['customer_id']); ?>"><td colspan="6"><div class="entries muted">Loading…</div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === $page): ?><span class="active"><?php echo $i; ?></span>
                <?php else: ?><a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a><?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

document.querySelectorAll('.row-click').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        var id = row.dataset.customer;
        var detail = document.querySelector('.detail-row[data-detail="' + CSS.escape(id) + '"]');
        var open = detail.classList.toggle('show');
        row.classList.toggle('open', open);
        if (!open || detail.dataset.loaded) return;
        detail.dataset.loaded = 1;
        var box = detail.querySelector('.entries');
        fetch('admin_get_customer_entries.php?customer_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.entries.length) { box.textContent = 'No products found.'; return; }
                box.classList.remove('muted');
                box.innerHTML = data.entries.map(function (en, i) {
                    return '<div class="entry"><b class="mono">' + (i + 1) + '. ' + esc(en.purchase_id) + '</b><span>' + esc(en.entry_date) + ' · IP ' + esc(en.ip_address || '-') + '</span></div>';
                }).join('');
            })
            .catch(function () { box.textContent = 'Could not load products.'; });
    });
});

var searchInput = document.querySelector('.search input');
var searchTimer;
searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { searchInput.form.submit(); }, 500);
});
if (searchInput.value) { searchInput.focus(); searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length); }
</script>
<?php admin_footer(); ?>
