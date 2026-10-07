<?php
// functions.php - Shared helpers (loaded by config.php)

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function qr_image_url($data, $size = 220) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&margin=8&data=' . urlencode($data);
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

function format_datetime($value, $format = 'd M Y, g:i A') {
    return $value ? date($format, strtotime($value)) : '-';
}

/* ---------- WhatsApp ---------- */

/** Click-to-chat link that opens WhatsApp with a pre-filled message */
function wa_link($phone, $text) {
    return 'https://wa.me/' . preg_replace('/\D+/', '', $phone) . '?text=' . rawurlencode($text);
}

function winner_message($winner, $settings) {
    global $wa_business_number;
    $msg = "Tahniah {$winner['customer_name']}! 🎉\n\nAnda telah memenangi *" . ($winner['prize'] ?: 'hadiah') . "* dalam {$settings['campaign_title']} ASTECH Legacy.\n\n"
         . "Kod produk: {$winner['purchase_id']}\nID Pelanggan: {$winner['customer_id']}\n\nSila balas mesej ini untuk menuntut hadiah anda.";
    if ($wa_business_number !== '') $msg .= "\nHubungi kami: +" . $wa_business_number;
    return $msg;
}

function wa_enabled($template) {
    global $wa_api_token, $wa_phone_number_id;
    return $wa_api_token !== '' && $wa_phone_number_id !== '' && $template !== '';
}

/**
 * Send an approved template message through the WhatsApp Cloud API.
 * Returns true on success; failures are logged, never shown to visitors.
 */
function wa_send_template($phone, $template, array $params) {
    global $wa_api_token, $wa_phone_number_id, $wa_template_lang;
    if (!wa_enabled($template) || !function_exists('curl_init')) return false;

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'template',
        'template' => [
            'name' => $template,
            'language' => ['code' => $wa_template_lang],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(function ($p) {
                    return ['type' => 'text', 'text' => (string)$p];
                }, $params),
            ]],
        ],
    ];

    $ch = curl_init("https://graph.facebook.com/v20.0/{$wa_phone_number_id}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $wa_api_token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log("WhatsApp send to $phone failed (HTTP $status): $response");
        return false;
    }
    return true;
}

/* ---------- Public page shell ---------- */

function public_head($title) {
    ?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> | ASTECH Legacy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="public">
<div class="glow glow-1"></div><div class="glow glow-2"></div>
    <?php
}

function brand_block($title) {
    ?>
    <div class="brand">
        <img src="image.png" alt="ASTECH Legacy" class="brand-logo" onerror="this.style.display='none'">
        <div class="brand-eyebrow">ASTECH LEGACY</div>
        <h1 class="brand-title"><?php echo e($title); ?></h1>
    </div>
    <?php
}

function public_foot() {
    ?>
    <footer class="public-footer">© <?php echo date('Y'); ?> ASTECH Legacy</footer>
</body>
</html>
    <?php
}
