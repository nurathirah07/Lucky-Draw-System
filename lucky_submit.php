<?php
// lucky_submit.php - Handles registration, then redirects to a result page
session_start();
require_once 'config.php';

$settings = get_settings($pdo);

// ---------- Show result (after redirect, so refreshing never resubmits) ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $result = $_SESSION['entry_result'] ?? null;
    if (!$result) {
        header('Location: index.php');
        exit;
    }
    public_page_start($result['ok'] ? 'Berjaya' : 'Tidak berjaya', $settings['campaign_title']);
    ?>
<div class="card">
    <?php if ($result['ok']): ?>
        <?php status_block('ok', 'Penyertaan berjaya', 'Terima kasih, ' . $result['name'] . '. Penyertaan anda dalam ' . $settings['campaign_title'] . ' telah berjaya direkodkan.'); ?>
        <div class="highlight-num" style="margin-top:20px">
            <small>ID Pelanggan</small>
            <strong class="mono"><?php echo e($result['customer_id']); ?></strong>
        </div>
        <div class="kv" style="margin-top:16px">
            <div class="kv-row"><span>Kod produk</span><b class="mono"><?php echo e($result['code']); ?></b></div>
            <div class="kv-row"><span>Jumlah penyertaan anda</span><b><?php echo (int)$result['total']; ?></b></div>
        </div>
        <p class="small muted" style="margin-top:16px">Sila simpan ID Pelanggan ini untuk rujukan. Setiap kod produk yang didaftarkan memberi anda satu peluang untuk menang. Pemenang akan dihubungi melalui WhatsApp selepas cabutan dijalankan.</p>
        <?php if ($result['name_changed']): ?>
            <div style="margin-top:16px"><?php echo alert_box('info', 'Nama untuk nombor ini telah dikemas kini kepada <b>' . e($result['name']) . '</b>.'); ?></div>
        <?php endif; ?>
    <?php else: ?>
        <?php status_block('error', 'Pendaftaran tidak berjaya', $result['error']); ?>
    <?php endif; ?>
</div>
<?php
$open = campaign_state($settings) === 'active';
if ($result['ok']) {
    if ($open) echo scan_next_hint();
    public_actions([['Semak penyertaan saya', 'check.php', 'search']]);
} else {
    public_actions(array_filter([
        !empty($result['code']) ? ['Cuba lagi', 'lucky.php?pid=' . urlencode($result['code']), 'back', true] : null,
        ['Semak penyertaan saya', 'check.php', 'search'],
    ]));
}
?>
    <?php
    if ($result['ok']) confetti_script();
    public_page_end();
    exit;
}

// ---------- Process registration ----------
function finish(array $result) {
    $_SESSION['entry_result'] = $result;
    header('Location: lucky_submit.php');
    exit;
}

$purchase_id = strtoupper(trim($_POST['purchase_id'] ?? ''));
$customer_name = preg_replace('/\s+/', ' ', trim($_POST['customer_name'] ?? ''));
$customer_phone = normalize_phone($_POST['customer_phone'] ?? '');
$fail = function ($msg) use ($purchase_id) { finish(['ok' => false, 'error' => $msg, 'code' => $purchase_id]); };

if ($purchase_id === '' || $customer_name === '' || trim($_POST['customer_phone'] ?? '') === '') {
    $fail('Sila isi semua maklumat.');
}
// PDPA: registration needs the customer's consent (checkbox on the form, see privacy.php)
if (empty($_POST['consent'])) {
    $fail('Sila tandakan kotak persetujuan Notis Privasi untuk menyertai.');
}
if (mb_strlen($customer_name) < 2 || mb_strlen($customer_name) > 100) {
    $fail('Sila masukkan nama penuh yang sah.');
}
if ($customer_phone === null) {
    $fail('Nombor telefon tidak sah. Sila masukkan nombor telefon bimbit Malaysia, contoh 012-345 6789.');
}

$state = campaign_state($settings);
if ($state !== 'active') {
    $fail($state === 'upcoming' ? 'Penyertaan belum dibuka.' : 'Tempoh penyertaan telah tamat.');
}

$stmt = $pdo->prepare("SELECT code FROM product_codes WHERE code = ?");
$stmt->execute([$purchase_id]);
if (!$stmt->fetch()) {
    $fail('Kod produk tidak sah. Sila imbas QR code asal pada produk anda.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT customer_id, customer_name FROM lucky_customers WHERE customer_phone = ? FOR UPDATE");
    $stmt->execute([$customer_phone]);
    $existing = $stmt->fetch();
    $nameChanged = false;

    if ($existing) {
        $customer_id = $existing['customer_id'];
        $nameChanged = mb_strtolower($existing['customer_name']) !== mb_strtolower($customer_name);
        // Keep the customer record in line with the latest name they entered
        $pdo->prepare("UPDATE lucky_customers SET customer_name = ?, last_entry_date = NOW(), total_entries = total_entries + 1 WHERE customer_id = ?")
            ->execute([$customer_name, $customer_id]);
    } else {
        // Insert with a temporary ID, then derive CUST-xxxxx from the auto-increment number
        $tempId = 'TMP-' . bin2hex(random_bytes(6));
        $pdo->prepare("INSERT INTO lucky_customers (customer_id, customer_name, customer_phone, first_entry_date, last_entry_date, total_entries) VALUES (?, ?, ?, NOW(), NOW(), 1)")
            ->execute([$tempId, $customer_name, $customer_phone]);
        $customer_id = sprintf('CUST-%05d', $pdo->lastInsertId());
        $pdo->prepare("UPDATE lucky_customers SET customer_id = ? WHERE customer_id = ?")->execute([$customer_id, $tempId]);
    }

    $pdo->prepare("INSERT INTO lucky_draw_entries (customer_id, purchase_id, product_code, customer_name, customer_phone, ip_address, entry_date) VALUES (?, ?, NULL, ?, ?, ?, NOW())")
        ->execute([$customer_id, $purchase_id, $customer_name, $customer_phone, $_SERVER['REMOTE_ADDR'] ?? null]);

    $stmt = $pdo->prepare("SELECT total_entries FROM lucky_customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $total = (int)$stmt->fetchColumn();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        // Unique key clash: the product code was registered at the same moment
        $fail('Kod produk ini sudah didaftarkan. Setiap kod produk hanya boleh didaftarkan sekali.');
    }
    error_log('Registration failed for ' . $purchase_id . ': ' . $e->getMessage());
    $fail('Sila cuba sebentar lagi.');
}

finish([
    'ok' => true,
    'name' => $customer_name,
    'customer_id' => $customer_id,
    'code' => $purchase_id,
    'total' => $total,
    'name_changed' => $nameChanged,
]);
