<?php
// admin_lucky.php - Admin sign-in + Overview
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

// ---------- Sign-in page ----------
if (!isAdminLoggedIn()) {
    public_page_start('Sign in', 'Admin sign in', 'en');
    ?>
    <div class="card">
        <?php if ($login_error): ?><div style="margin-bottom:16px"><?php echo alert_box('error', e($login_error)); ?></div><?php endif; ?>
        <form method="POST">
            <div class="field">
                <label for="u">Username</label>
                <input type="text" id="u" name="username" required autofocus autocomplete="username" value="<?php echo e($_POST['username'] ?? ''); ?>">
            </div>
            <div class="field">
                <label for="p">Password</label>
                <input type="password" id="p" name="password" required autocomplete="current-password">
            </div>
            <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Sign in</button></div>
        </form>
    </div>
    <?php if (adminRegistrationOpen($pdo)): ?>
        <p class="small muted" style="text-align:center">No account yet? <a class="link" href="admin_register.php">Create an admin account</a></p>
    <?php endif; ?>
    <?php
    public_page_end(['Customer page' => 'index.php']);
    exit;
}

// ---------- Overview ----------
$settings = get_settings($pdo);
$state = campaign_state($settings);

$num = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
$totalEntries   = $num("SELECT COUNT(*) FROM lucky_draw_entries");
$todayEntries   = $num("SELECT COUNT(*) FROM lucky_draw_entries WHERE entry_date >= CURDATE()");
$totalCustomers = $num("SELECT COUNT(*) FROM lucky_customers");
$totalCodes     = $num("SELECT COUNT(*) FROM product_codes");
$registeredCodes = $num("SELECT COUNT(*) FROM lucky_draw_entries e JOIN product_codes p ON p.code = e.purchase_id");  // excludes entries whose batch was deleted
$newCodes       = $num("SELECT COUNT(*) FROM product_codes WHERE batch_name <> 'Legacy'");
$scannedCodes   = $num("SELECT COUNT(DISTINCT s.purchase_id) FROM qr_scan_log s JOIN product_codes p ON p.code = s.purchase_id");
$totalWinners   = $num("SELECT COUNT(*) FROM winners");
$pct = function ($a, $b) { return $b > 0 ? round($a / $b * 100) : 0; };

// Entries per day - last 14 days
$days = 14;
$perDay = [];
for ($i = $days - 1; $i >= 0; $i--) $perDay[date('Y-m-d', strtotime("-$i days"))] = 0;
$stmt = $pdo->prepare("SELECT DATE(entry_date) d, COUNT(*) c FROM lucky_draw_entries WHERE entry_date >= ? GROUP BY DATE(entry_date)");
$stmt->execute([array_key_first($perDay)]);
foreach ($stmt as $row) $perDay[$row['d']] = (int)$row['c'];

// Use the customer record's name so it matches the Customers page
$recent = $pdo->query("SELECT c.customer_name, e.customer_id, e.purchase_id, e.entry_date FROM lucky_draw_entries e JOIN lucky_customers c ON c.customer_id = e.customer_id ORDER BY e.entry_date DESC, e.id DESC LIMIT 6")->fetchAll();

// Getting-started checklist (hidden once the first three steps are done)
$steps = [
    ['Set campaign dates', 'Start, end and prizes', 'admin_settings.php', $settings['campaign_start'] !== '' || $settings['campaign_end'] !== ''],
    ['Create QR codes', 'Generate and print a batch', 'generate_qr.php', $newCodes > 0],
    ['Test with a phone', 'Scan one of your codes', 'generate_qr.php?tab=test', $scannedCodes > 0],
    ['Run the lucky draw', 'Spin the wheel when it ends', 'admin_draw.php', $totalWinners > 0],
];
$showChecklist = !($steps[0][3] && $steps[1][3] && $steps[2][3]);

function bar_chart_svg(array $data) {
    $w = 640; $h = 200; $padB = 24; $padT = 18;
    $max = max(1, max($data));
    $slot = $w / count($data);
    $barW = $slot * 0.56;
    $out = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Entries per day">';
    $i = 0;
    foreach ($data as $date => $value) {
        $bh = ($h - $padT - $padB) * $value / $max;
        $x = round($slot * $i + ($slot - $barW) / 2, 1);
        $y = round($h - $padB - max($bh, 3), 1);
        $label = date('j M', strtotime($date));
        $out .= '<rect class="' . ($value ? 'col' : 'col-empty') . '" x="' . $x . '" y="' . $y . '" width="' . round($barW, 1) . '" height="' . round(max($bh, 3), 1) . '" rx="3"><title>' . $label . ': ' . $value . ' entries</title></rect>';
        if ($value) $out .= '<text class="val" x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 6, 1) . '" text-anchor="middle">' . $value . '</text>';
        if ($i % 2 === 1 || count($data) <= 7) $out .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($h - 6) . '" text-anchor="middle">' . $label . '</text>';
        $i++;
    }
    return $out . '</svg>';
}

$dates = 'No dates set';
if ($settings['campaign_start'] && $settings['campaign_end']) $dates = format_datetime($settings['campaign_start']) . ' – ' . format_datetime($settings['campaign_end']);
elseif ($settings['campaign_start']) $dates = 'From ' . format_datetime($settings['campaign_start']);
elseif ($settings['campaign_end']) $dates = 'Until ' . format_datetime($settings['campaign_end']);

admin_header('overview');
page_head(
    'Overview',
    e($settings['campaign_title']) . ' · ' . e($dates),
    campaign_badge($state) . '<a class="btn" href="admin_lucky_export.php">' . icon('download') . 'Export CSV</a>'
);
?>

<?php if ($showChecklist): ?>
<div class="card">
    <div class="card-head"><div><div class="card-title">Getting started</div><div class="card-sub">Follow these steps to run a campaign.</div></div></div>
    <div class="checklist">
        <?php foreach ($steps as $n => [$title, $desc, $href, $done]): ?>
            <a href="<?php echo e($href); ?>" class="<?php echo $done ? 'done' : ''; ?>">
                <span class="step-dot"><?php echo $done ? icon('check') : $n + 1; ?></span>
                <span><b><?php echo e($title); ?></b><span><?php echo e($desc); ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat"><div class="stat-label">Entries</div><div class="stat-value"><?php echo number_format($totalEntries); ?></div><div class="stat-note"><?php echo $todayEntries; ?> today</div></div>
    <div class="stat"><div class="stat-label">Customers</div><div class="stat-value"><?php echo number_format($totalCustomers); ?></div><div class="stat-note"><?php echo $totalCustomers ? number_format($totalEntries / $totalCustomers, 1) : 0; ?> entries per customer</div></div>
    <div class="stat"><div class="stat-label">QR codes used</div><div class="stat-value"><?php echo $pct($registeredCodes, $totalCodes); ?>%</div><div class="stat-note"><?php echo number_format($registeredCodes); ?> of <?php echo number_format($totalCodes); ?> codes</div></div>
    <div class="stat"><div class="stat-label">Winners</div><div class="stat-value"><?php echo number_format($totalWinners); ?></div><div class="stat-note"><a class="link" href="admin_draw.php">Go to lucky draw</a></div></div>
</div>

<div class="grid grid-main">
    <div class="card chart">
        <div class="card-head"><div><div class="card-title">Entries per day</div><div class="card-sub">Last <?php echo $days; ?> days</div></div></div>
        <?php echo bar_chart_svg($perDay); ?>
    </div>
    <div class="card">
        <div class="card-head"><div><div class="card-title">QR codes used</div><div class="card-sub">How many of your codes customers have registered</div></div></div>
        <div class="meter-top"><span class="muted">Used</span><b><?php echo $pct($registeredCodes, $totalCodes); ?>%</b></div>
        <div class="bar"><i style="width:<?php echo $pct($registeredCodes, $totalCodes); ?>%"></i></div>
        <div class="kv" style="margin-top:16px">
            <div class="kv-row"><span>Codes created</span><b><?php echo number_format($totalCodes); ?></b></div>
            <div class="kv-row"><span>Registered by customers</span><b><?php echo number_format($registeredCodes); ?></b></div>
            <div class="kv-row"><span>Not used yet</span><b><?php echo number_format(max(0, $totalCodes - $registeredCodes)); ?></b></div>
        </div>
        <div class="form-actions"><a class="btn btn-sm" href="generate_qr.php"><?php echo icon('qr'); ?>Manage QR codes</a></div>
    </div>
</div>

<div class="card card-flush">
    <div class="card-head"><div><div class="card-title">Latest entries</div></div></div>
    <?php if (!$recent): ?>
        <div class="empty"><?php echo icon('ticket'); ?><p>No entries yet.</p><p class="small faint">Entries appear here when customers scan a code and register.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Customer</th><th>Product code</th><th>Registered</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo e($r['customer_name']); ?><div class="sub-line mono"><?php echo e($r['customer_id']); ?></div></td>
                        <td class="mono"><?php echo e($r['purchase_id']); ?></td>
                        <td class="muted nowrap"><?php echo e(format_datetime($r['entry_date'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <div class="card-foot"><span class="small faint"><?php echo number_format($totalCustomers) . ($totalCustomers === 1 ? ' customer' : ' customers'); ?> in total</span><a class="btn btn-sm" href="admin_customers.php">View all customers<?php echo icon('arrow'); ?></a></div>
</div>
<?php admin_footer(); ?>
