<?php
// lucky.php - Registration form (opened by scanning a product QR code)
require_once 'config.php';

$settings = get_settings($pdo);
$state = campaign_state($settings);
$purchase_id = strtoupper(trim($_GET['pid'] ?? ''));
$error = '';

if ($purchase_id === '') {
    $error = 'QR code tidak sah. Sila imbas QR code pada produk.';
} else {
    $stmt = $pdo->prepare("SELECT code FROM product_codes WHERE code = ?");
    $stmt->execute([$purchase_id]);
    if (!$stmt->fetch()) {
        $error = 'Kod produk ini tidak sah. Sila imbas QR code asal pada produk anda.';
    } else {
        // Log scans of valid codes only (used for the scan-to-registration chart)
        $pdo->prepare("INSERT INTO qr_scan_log (purchase_id) VALUES (?)")->execute([$purchase_id]);

        $stmt = $pdo->prepare("SELECT id FROM lucky_draw_entries WHERE purchase_id = ?");
        $stmt->execute([$purchase_id]);
        if ($stmt->fetch()) {
            $error = 'Produk ini telah didaftarkan untuk cabutan bertuah.';
        } elseif ($state === 'upcoming') {
            $error = 'Cabutan bertuah belum bermula. Penyertaan dibuka pada ' . format_datetime($settings['campaign_start']) . '.';
        } elseif ($state === 'ended') {
            $error = 'Maaf, tempoh penyertaan telah tamat pada ' . format_datetime($settings['campaign_end']) . '.';
        }
    }
}

public_head('Daftar penyertaan');
?>
<div class="wrap">
    <?php brand_block($settings['campaign_title']); ?>

    <div class="card">
        <?php if ($error): ?>
            <div class="result-icon <?php echo $state === 'upcoming' ? 'wait' : 'bad'; ?>"><?php echo $state === 'upcoming' ? '⏳' : '⚠️'; ?></div>
            <div class="alert alert-error text-center"><?php echo e($error); ?></div>
            <p class="muted text-center" style="font-size:14px">💡 Setiap produk hanya boleh didaftarkan <strong>sekali</strong> sahaja.</p>
            <div class="btn-row mt-2">
                <a href="index.php" class="btn btn-ghost" style="flex:1">🏠 Laman utama</a>
                <a href="check.php" class="btn btn-ghost" style="flex:1">🔎 Semak penyertaan</a>
            </div>
        <?php else: ?>
            <div class="code-chip">
                <span>🏷️ Kod produk</span>
                <strong class="mono"><?php echo e($purchase_id); ?></strong>
            </div>

            <form method="POST" action="lucky_submit.php" id="regForm">
                <input type="hidden" name="purchase_id" value="<?php echo e($purchase_id); ?>">
                <div class="field">
                    <label for="name">Nama penuh</label>
                    <input type="text" id="name" name="customer_name" required maxlength="100" autocomplete="name" placeholder="Contoh: Ali bin Ahmad">
                </div>
                <div class="field">
                    <label for="phone">No. telefon bimbit</label>
                    <input type="tel" id="phone" name="customer_phone" required inputmode="tel" autocomplete="tel" placeholder="Contoh: 012-345 6789">
                    <div class="hint">Pemenang akan dihubungi melalui nombor ini. Guna nombor yang sama untuk setiap produk.</div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="submitBtn">🎯 Sertai cabutan bertuah</button>
            </form>

            <p class="muted text-center mt-2" style="font-size:13px">🎉 Setiap produk = 1 peluang menang!</p>
        <?php endif; ?>
    </div>
</div>
<script>
var form = document.getElementById('regForm');
if (form) form.addEventListener('submit', function () {
    var btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.textContent = 'Memproses ';
});
</script>
<?php public_foot(); ?>
