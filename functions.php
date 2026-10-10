<?php
// functions.php - Shared helpers (loaded by config.php)

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** plural(1, 'customer', 'customers') -> "1 customer" */
function plural($n, $one, $many) {
    return number_format($n) . ' ' . ($n == 1 ? $one : $many);
}

/* ---------- CSRF protection for admin forms (needs session_start) ---------- */

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Stops the request if the form did not come from this site */
function csrf_check() {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Go back, refresh the page and try again.');
    }
}

/* ---------- Phone numbers ---------- */

/**
 * Normalise a Malaysian mobile number to 60XXXXXXXXX.
 * Accepts 012-345 6789, 0123456789, 60123456789, +60 12 345 6789, 123456789.
 * Returns null if it is not a valid mobile number.
 */
function normalize_phone($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (strpos($digits, '60') === 0) {
        // already international
    } elseif (strpos($digits, '0') === 0) {
        $digits = '6' . $digits;
    } elseif (strpos($digits, '1') === 0) {
        $digits = '60' . $digits;
    }
    return preg_match('/^601\d{8,9}$/', $digits) ? $digits : null;
}

/** 60123456789 -> +60 12-345 6789 */
function format_phone($phone) {
    if (preg_match('/^60(1\d)(\d{3,4})(\d{4})$/', $phone, $m)) {
        return "+60 {$m[1]}-{$m[2]} {$m[3]}";
    }
    return $phone;
}

/** Athirah binti Ali -> A****** b**** A** */
function mask_name($name) {
    return implode(' ', array_map(function ($word) {
        $len = mb_strlen($word);
        return $len <= 1 ? $word : mb_substr($word, 0, 1) . str_repeat('*', $len - 1);
    }, preg_split('/\s+/', trim($name))));
}

/** ABCD-EFGH -> ABCD-**GH */
function mask_code($code) {
    $len = strlen($code);
    if ($len <= 4) return $code;
    return substr($code, 0, 4) . preg_replace('/[A-Za-z0-9]/', '*', substr($code, 4, $len - 6)) . substr($code, -2);
}

/* ---------- Product codes ---------- */

/** Random, hard-to-guess code such as K7QF-9XM2 (no 0/O/1/I to avoid confusion) */
function generate_product_code() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/**
 * Office Wi-Fi setup check: does the IP address inside the QR codes ($base_url) still
 * belong to this computer? Routers can hand out a new IP after a restart, and then
 * every printed QR code leads to the wrong device. Returns a warning (HTML) or ''.
 * Domains (online hosting) are not checked.
 */
function qr_link_warning() {
    global $base_url;
    $host = parse_url($base_url, PHP_URL_HOST);
    if (!filter_var($host, FILTER_VALIDATE_IP)) return '';

    $mine = gethostbynamel(gethostname()) ?: [];
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    if ($server !== '' && $server !== '::1' && $server !== '127.0.0.1') $mine[] = $server;
    $mine = array_values(array_unique(array_filter($mine, function ($ip) { return strpos($ip, '127.') !== 0; })));
    if (!$mine || in_array($host, $mine, true)) return '';

    $suggest = 'http://' . $mine[0] . rtrim((string)parse_url($base_url, PHP_URL_PATH), '/');
    return alert_box('warning', '<b>Your QR codes point to the wrong address.</b> They link to <span class="mono">' . e($host)
        . '</span>, but this computer is now <span class="mono">' . e(implode(', ', $mine)) . '</span>, so phones will not reach this system. '
        . 'Change <span class="mono">$base_url</span> in config.php to <span class="mono">' . e($suggest) . '</span> and reprint your QR codes. '
        . 'To stop this happening, reserve a fixed IP for this computer in your router.');
}

/** Placeholder that qr_render_script() fills with a QR code drawn in the browser */
function qr_box($data, $label) {
    return '<div class="qr" data-qr="' . e($data) . '" role="img" aria-label="QR ' . e($label) . '"></div>';
}

/** Loads the QR drawing scripts, which fill every qr_box() on the page (see assets/qr-draw.js) */
function qr_render_script() {
    echo '<script src="assets/qrcode.min.js"></script><script src="assets/qr-draw.js"></script>';
}

function entry_url($code) {
    global $base_url;
    return rtrim($base_url, '/') . '/lucky.php?pid=' . urlencode($code);
}

/* ---------- Campaign settings ---------- */

function get_settings($pdo) {
    static $cache = null;
    if ($cache === null) {
        $cache = [
            'campaign_title' => 'Cabutan Bertuah Raya',
            'campaign_start' => '',
            'campaign_end'   => '',
            'prize_info'     => '',

            // WhatsApp (Settings → WhatsApp)
            'wa_buttons'  => '1',   // '1' = show WhatsApp buttons on the Customers and Lucky draw pages
            'msg_winner'  => DEFAULT_MESSAGES['msg_winner'],
            'msg_confirm' => DEFAULT_MESSAGES['msg_confirm'],
        ];
        foreach ($pdo->query("SELECT name, value FROM settings") as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache;
}

function save_setting($pdo, $name, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$name, $value]);
}

/** Returns 'upcoming', 'active' or 'ended' */
function campaign_state($settings) {
    $now = time();
    if ($settings['campaign_start'] !== '' && $now < strtotime($settings['campaign_start'])) return 'upcoming';
    if ($settings['campaign_end'] !== '' && $now > strtotime($settings['campaign_end'])) return 'ended';
    return 'active';
}

/** The one date format used across the system, e.g. 7 Oct 2026, 1:23 PM */
function format_datetime($value, $format = 'j M Y, g:i A') {
    return $value ? date($format, strtotime($value)) : '-';
}

/* ---------- WhatsApp ---------- */

/** Default message texts; admins can edit them in Settings → WhatsApp */
const DEFAULT_MESSAGES = [
    // Sent by the admin to a winner
    'msg_winner' => "Salam Sejahtera / Assalamualaikum {name},\n\nTahniah! 🎉\n\nAnda telah terpilih sebagai salah seorang pemenang {prize} dalam {campaign} ASTECH Legacy.\n\nMaklumat Pemenang:\n• ID Pelanggan: {customer_id}\n• Kod Produk: {product_code}\n• Hadiah: {prize}\n\nSila balas mesej ini dengan menyatakan [Nama Penuh] dan [Nombor Kad Pengenalan / Salinan Resit] untuk proses pengesahan dan penebusan hadiah anda.\n\nTerima kasih atas sokongan anda kepada ASTECH Legacy! ✨",
    // Sent by the admin to a customer to confirm their entries
    'msg_confirm' => "Salam Sejahtera / Assalamualaikum {name},\n\nTerima kasih kerana menyertai {campaign} ASTECH Legacy. 🎉\n\nPenyertaan anda telah berjaya direkodkan.\n\nMaklumat Penyertaan:\n• ID Pelanggan: {customer_id}\n• Kod Produk: {product_code}\n• Jumlah Penyertaan: {entries}\n\nSetiap kod produk yang didaftarkan memberi anda satu peluang untuk menang. Pemenang akan dihubungi melalui WhatsApp selepas cabutan dijalankan.\n\nTerima kasih atas sokongan anda kepada ASTECH Legacy! ✨",
];

/** Placeholders available in the message texts */
const MESSAGE_PLACEHOLDERS = [
    '{name}' => 'Customer name',
    '{customer_id}' => 'Customer ID, e.g. CUST-00001',
    '{product_code}' => 'Product code(s)',
    '{entries}' => 'Number of entries',
    '{prize}' => 'Prize (winner message)',
    '{campaign}' => 'Campaign name',
];

/** Fill a message text ('msg_winner' or 'msg_confirm') with real values */
function build_message($settings, $key, array $values) {
    $values += ['name' => '', 'customer_id' => '', 'product_code' => '', 'entries' => '', 'prize' => 'hadiah', 'campaign' => $settings['campaign_title']];
    $replace = [];
    foreach ($values as $k => $v) $replace['{' . $k . '}'] = (string)$v;
    return strtr($settings[$key], $replace);
}

function winner_message($winner, $settings) {
    return build_message($settings, 'msg_winner', [
        'name' => $winner['customer_name'],
        'customer_id' => $winner['customer_id'],
        'product_code' => $winner['purchase_id'],
        'prize' => $winner['prize'] ?: 'hadiah',
    ]);
}

/** Click-to-chat link that opens WhatsApp with a pre-filled message */
function wa_link($phone, $text) {
    return 'https://wa.me/' . preg_replace('/\D+/', '', $phone) . '?text=' . rawurlencode($text);
}

/* ---------- UI building blocks (shared by public + admin pages) ---------- */

/** Inline line icon (Lucide-style, 24x24 grid, stroke = currentColor) */
function icon($name, $class = '') {
    static $paths = [
        'overview'  => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'qr'        => '<rect x="3" y="3" width="5" height="5" rx="1"/><rect x="16" y="3" width="5" height="5" rx="1"/><rect x="3" y="16" width="5" height="5" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'gift'      => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'settings'  => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
        'external'  => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'printer'   => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'wifi'      => '<path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.86a10 10 0 0 1 14 0"/><path d="M8.5 16.43a5 5 0 0 1 7 0"/>',
        'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'trophy'    => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'chevron'   => '<path d="m9 18 6-6-6-6"/>',
        'back'      => '<path d="m15 18-6-6 6-6"/>',
        'arrow'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'alert'     => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'chat'      => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'trash'     => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'expand'    => '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'ticket'    => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
        'scan'      => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'phone'     => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'spin'      => '<path d="M21 12a9 9 0 1 1-6.22-8.56"/><path d="M21 3v6h-6"/>',
        'home'      => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'camera'    => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'close'     => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    ];
    return '<svg class="icon ' . $class . '" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Status badge for the campaign - same colours on public and admin pages */
function campaign_badge($state, $lang = 'en') {
    $labels = [
        'en' => ['upcoming' => 'Not started', 'active' => 'Open', 'ended' => 'Ended'],
        'ms' => ['upcoming' => 'Belum bermula', 'active' => 'Penyertaan dibuka', 'ended' => 'Penyertaan ditutup'],
    ];
    $class = ['upcoming' => 'badge-info', 'active' => 'badge-success', 'ended' => 'badge-danger'][$state];
    return '<span class="badge ' . $class . '">' . $labels[$lang][$state] . '</span>';
}

/** $message is HTML - escape user data before passing it in */
function alert_box($type, $message) {
    $icons = ['error' => 'alert', 'success' => 'check', 'warning' => 'alert', 'info' => 'alert'];
    return '<div class="alert alert-' . $type . '">' . icon($icons[$type]) . '<div>' . $message . '</div></div>';
}

/* ---------- Page shell for customer-facing and sign-in pages ---------- */

function public_page_start($title, $heading, $lang = 'ms') {
    ?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> · ASTECH Legacy</title>
    <link rel="icon" href="image.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="public-body">
<div class="public">
    <header class="public-head">
        <img src="image.png" alt="ASTECH Legacy" onerror="this.style.display='none'">
        <div class="eyebrow">ASTECH LEGACY</div>
        <h1><?php echo e($heading); ?></h1>
    </header>
    <?php
}

/**
 * Closes a public page. $links: [label => href] for the footer; by default the customer
 * pages, leaving out the page you are on.
 */
function public_page_end(?array $links = null) {
    if ($links === null) {
        $links = array_filter(['Laman utama' => 'index.php', 'Semak penyertaan' => 'check.php', 'Notis Privasi' => 'privacy.php'], function ($href) {
            return $href !== basename($_SERVER['SCRIPT_NAME']);
        });
    }
    ?>
</div>
<footer class="public-foot">
    <?php foreach ($links as $label => $href): ?><a href="<?php echo e($href); ?>"><?php echo e($label); ?></a><?php endforeach; ?>
    <span>© <?php echo date('Y'); ?> ASTECH Legacy</span>
</footer>
</body>
</html>
    <?php
}

/**
 * Full-width buttons shown below the card on customer pages, so every page offers its
 * next steps the same way. Each action: [label, href, icon, primary?].
 */
function public_actions(array $actions) {
    if (!$actions) return;
    echo '<div class="public-actions">';
    foreach ($actions as $a) {
        [$label, $href, $ico] = $a;
        $class = !empty($a[3]) ? 'btn btn-primary btn-lg btn-block' : 'btn btn-lg btn-block';
        echo '<a href="' . e($href) . '" class="' . $class . '">' . ($ico ? icon($ico) : '') . e($label) . '</a>';
    }
    echo '</div>';
}

/** Reminder shown after registering: the next QR code is scanned with the phone's Camera app */
function scan_next_hint($title = 'Ada produk lain?') {
    return '<div class="alert alert-info">' . icon('camera') . '<div><b>' . e($title) . '</b><br>'
        . '<span class="muted">Buka aplikasi Kamera telefon dan halakan ke QR code pada produk seterusnya, kemudian tekan pautan yang muncul.</span></div></div>';
}

/** Centered result block: icon + title + message ($type: ok | error | wait) */
function status_block($type, $title, $message = '') {
    $icons = ['ok' => 'check', 'error' => 'alert', 'wait' => 'clock'];
    ?>
    <div class="status">
        <div class="status-icon <?php echo $type; ?>"><?php echo icon($icons[$type]); ?></div>
        <h2><?php echo e($title); ?></h2>
        <?php if ($message !== ''): ?><p><?php echo e($message); ?></p><?php endif; ?>
    </div>
    <?php
}

function confetti_script($count = 90) {
    ?>
<script>
(function () {
    var colors = ['#e5484d', '#ededef', '#f5a524', '#3dd68c', '#52a9ff'];
    for (var i = 0; i < <?php echo (int)$count; ?>; i++) {
        var c = document.createElement('div');
        c.className = 'confetti';
        c.style.left = Math.random() * 100 + 'vw';
        c.style.background = colors[i % colors.length];
        document.body.appendChild(c);
        c.animate([
            { transform: 'translateY(0) rotate(0)', opacity: 1 },
            { transform: 'translateY(' + (innerHeight + 40) + 'px) rotate(' + (Math.random() * 720) + 'deg)', opacity: .8 }
        ], { duration: 2200 + Math.random() * 1800, delay: Math.random() * 600, easing: 'cubic-bezier(.25,.6,.4,1)', fill: 'forwards' })
         .onfinish = (function (el) { return function () { el.remove(); }; })(c);
    }
})();
</script>
    <?php
}
