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
    $ok = $result['ok'];
    public_head($ok ? 'Berjaya' : 'Tidak berjaya');
    ?>
<div class="wrap">
    <?php brand_block($settings['campaign_title']); ?>
    <div class="card text-center">
        <?php if ($ok): ?>
            <div class="result-icon ok">🎉</div>
            <h2 style="font-size:22px">Tahniah, <?php echo e($result['name']); ?>!</h2>
            <p class="muted">Anda telah berjaya menyertai cabutan bertuah. Semoga bertuah!</p>

            <div class="big-id">
                <small>ID Pelanggan anda</small>
                <strong class="mono"><?php echo e($result['customer_id']); ?></strong>
            </div>

            <div class="list" style="text-align:left">
                <div class="list-item"><span class="muted">Kod produk</span><strong class="mono"><?php echo e($result['code']); ?></strong></div>
                <div class="list-item"><span class="muted">Jumlah penyertaan anda</span><span class="badge badge-gold"><?php echo (int)$result['total']; ?> peluang</span></div>
            </div>

            <?php if ($result['name_changed']): ?>
                <div class="alert alert-info mt-2" style="text-align:left">ℹ️ Nama untuk nombor ini telah dikemas kini kepada <strong><?php echo e($result['name']); ?></strong>.</div>
            <?php endif; ?>

            <?php if ($result['wa_sent']): ?>
                <div class="alert alert-success mt-2">✅ Pengesahan telah dihantar ke WhatsApp anda.</div>
            <?php elseif ($result['wa_link']): ?>
                <a href="<?php echo e($result['wa_link']); ?>" class="btn btn-wa btn-block mt-2" target="_blank" rel="noopener">💬 Simpan bukti di WhatsApp</a>
            <?php endif; ?>

            <div class="btn-row mt-2">
                <a href="check.php" class="btn btn-ghost" style="flex:1">🔎 Semak penyertaan</a>
                <a href="index.php" class="btn btn-ghost" style="flex:1">🏠 Laman utama</a>
            </div>
        <?php else: ?>
            <div class="result-icon bad">⚠️</div>
            <div class="alert alert-error"><?php echo e($result['error']); ?></div>
            <?php if (!empty($result['code'])): ?>
                <a href="lucky.php?pid=<?php echo urlencode($result['code']); ?>" class="btn btn-primary btn-block">← Cuba lagi</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-ghost btn-block mt-1">🏠 Laman utama</a>
        <?php endif; ?>
    </div>
</div>
    <?php if ($ok): ?>
<script>
(function () {
    var colors = ['#e11d48', '#fbbf24', '#fb7185', '#22c55e', '#60a5fa', '#fff'];
    for (var i = 0; i < 90; i++) {
        var c = document.createElement('div');
        c.className = 'confetti';
        c.style.left = Math.random() * 100 + 'vw';
        c.style.background = colors[i % colors.length];
        document.body.appendChild(c);
        c.animate([
            { transform: 'translateY(0) rotate(0)', opacity: 1 },
            { transform: 'translateY(' + (innerHeight + 40) + 'px) rotate(' + (Math.random() * 720) + 'deg)', opacity: .8 }
        ], { duration: 2200 + Math.random() * 1800, delay: Math.random() * 600, easing: 'cubic-bezier(.25,.6,.4,1)', fill: 'forwards' });
    }
})();
</script>
    <?php endif;
    public_foot();
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
if (mb_strlen($customer_name) < 2 || mb_strlen($customer_name) > 100) {
    $fail('Sila masukkan nama penuh yang sah.');
}
if ($customer_phone === null) {
    $fail('Nombor telefon tidak sah. Sila masukkan nombor telefon bimbit Malaysia, contoh 012-345 6789.');
}

$state = campaign_state($settings);
if ($state !== 'active') {
    $fail($state === 'upcoming' ? 'Cabutan bertuah belum bermula.' : 'Maaf, tempoh penyertaan telah tamat.');
}

$stmt = $pdo->prepare("SELECT code FROM product_codes WHERE code = ?");
$stmt->execute([$purchase_id]);
if (!$stmt->fetch()) {
    $fail('Kod produk ini tidak sah.');
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
        $fail('Produk ini telah didaftarkan untuk cabutan bertuah.');
    }
    error_log('Registration failed for ' . $purchase_id . ': ' . $e->getMessage());
    $fail('Pendaftaran tidak berjaya. Sila cuba sebentar lagi.');
}

// ---------- WhatsApp confirmation ----------
$waSent = wa_send_template($customer_phone, $wa_template_confirm, [$customer_name, $customer_id, $purchase_id]);
$waLink = null;
if (!$waSent && $wa_business_number !== '') {
    $waLink = wa_link($wa_business_number,
        "Saya telah menyertai {$settings['campaign_title']}.\nNama: $customer_name\nID Pelanggan: $customer_id\nKod produk: $purchase_id");
}

finish([
    'ok' => true,
    'name' => $customer_name,
    'customer_id' => $customer_id,
    'code' => $purchase_id,
    'total' => $total,
    'name_changed' => $nameChanged,
    'wa_sent' => $waSent,
    'wa_link' => $waLink,
]);
