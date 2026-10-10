<?php
// lucky.php - Registration form (opened by scanning a product QR code)
require_once 'config.php';

$settings = get_settings($pdo);
$state = campaign_state($settings);
$purchase_id = strtoupper(trim($_GET['pid'] ?? ''));
$error = null;   // [status type, title, message]

if ($purchase_id === '') {
    $error = ['error', 'QR code tidak sah', 'Sila imbas QR code pada label produk anda.'];
} else {
    $stmt = $pdo->prepare("SELECT code FROM product_codes WHERE code = ?");
    $stmt->execute([$purchase_id]);
    if (!$stmt->fetch()) {
        $error = ['error', 'Kod produk tidak sah', 'Sila imbas QR code asal pada produk anda.'];
    } else {
        // Log scans of valid codes only (ticks "Test with a phone" on the Overview checklist)
        $pdo->prepare("INSERT INTO qr_scan_log (purchase_id) VALUES (?)")->execute([$purchase_id]);

        $stmt = $pdo->prepare("SELECT id FROM lucky_draw_entries WHERE purchase_id = ?");
        $stmt->execute([$purchase_id]);
        if ($stmt->fetch()) {
            $error = ['error', 'Kod sudah didaftarkan', 'Setiap kod produk hanya boleh didaftarkan sekali.'];
        } elseif ($state === 'upcoming') {
            $error = ['wait', 'Belum bermula', 'Penyertaan dibuka pada ' . format_datetime($settings['campaign_start']) . '.'];
        } elseif ($state === 'ended') {
            $error = ['error', 'Penyertaan ditutup', 'Tempoh penyertaan telah tamat pada ' . format_datetime($settings['campaign_end']) . '.'];
        }
    }
}

public_page_start('Daftar penyertaan', $settings['campaign_title']);
?>
<div class="card">
    <?php if ($error): ?>
        <?php status_block(...$error); ?>
    <?php else: ?>
        <div class="kv" style="margin-bottom:20px">
            <div class="kv-row"><span>Kod produk</span><b class="mono"><?php echo e($purchase_id); ?></b></div>
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
                <div class="hint">Pemenang dihubungi melalui nombor ini. Guna nombor yang sama untuk setiap produk.</div>
            </div>
            <div class="field">
                <label class="check consent">
                    <input type="checkbox" name="consent" value="1" required>
                    <span>Saya bersetuju nama dan nombor telefon saya digunakan oleh ASTECH Legacy untuk cabutan bertuah ini, termasuk untuk menghubungi saya melalui WhatsApp, seperti dalam <a class="link" href="privacy.php" target="_blank">Notis Privasi</a>.</span>
                </label>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg btn-block" id="submitBtn">Sertai cabutan bertuah</button></div>
        </form>
    <?php endif; ?>
</div>
<?php
if ($error) {
    if ($state === 'active') echo scan_next_hint('Ada QR code lain?');
    public_actions([['Semak penyertaan saya', 'check.php', 'search']]);
} else {
    public_actions([['Kembali ke laman utama', 'index.php', 'home']]);
}
?>
<script>
var form = document.getElementById('regForm');
if (form) form.addEventListener('submit', function () { document.getElementById('submitBtn').classList.add('loading'); });
</script>
<?php public_page_end(); ?>
