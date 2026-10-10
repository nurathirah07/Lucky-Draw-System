<?php
// check.php - Customers check their own entries by phone number
session_start();
require_once 'config.php';

$settings = get_settings($pdo);
$error = '';
$customer = null;
$entries = [];
$wins = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic throttle: max 10 lookups per 10 minutes per session
    $now = time();
    $_SESSION['check_hits'] = array_filter($_SESSION['check_hits'] ?? [], function ($t) use ($now) { return $t > $now - 600; });
    $_SESSION['check_hits'][] = $now;

    $phone = normalize_phone($_POST['phone'] ?? '');
    if (count($_SESSION['check_hits']) > 10) {
        $error = 'Terlalu banyak semakan. Sila cuba lagi dalam beberapa minit.';
    } elseif ($phone === null) {
        $error = 'Nombor telefon tidak sah. Contoh: 012-345 6789';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM lucky_customers WHERE customer_phone = ?");
        $stmt->execute([$phone]);
        $customer = $stmt->fetch();
        if (!$customer) {
            $error = 'Tiada penyertaan dijumpai untuk nombor ini.';
        } else {
            $stmt = $pdo->prepare("SELECT purchase_id, entry_date FROM lucky_draw_entries WHERE customer_id = ? ORDER BY entry_date DESC");
            $stmt->execute([$customer['customer_id']]);
            $entries = $stmt->fetchAll();
            $stmt = $pdo->prepare("SELECT prize FROM winners WHERE customer_id = ? ORDER BY drawn_at");
            $stmt->execute([$customer['customer_id']]);
            $wins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

public_page_start('Semak penyertaan', 'Semak penyertaan');
?>
<div class="card">
    <form method="POST">
        <div class="field">
            <label for="phone">No. telefon bimbit yang didaftarkan</label>
            <input type="tel" id="phone" name="phone" required inputmode="tel" placeholder="Contoh: 012-345 6789" value="<?php echo e($_POST['phone'] ?? ''); ?>">
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit"><?php echo icon('search'); ?>Semak</button></div>
    </form>
</div>

<?php if ($error): ?>
    <?php echo alert_box('error', e($error)); ?>
<?php elseif ($customer): ?>
    <?php if ($wins): ?>
        <?php echo alert_box('warning', '<b>Tahniah, anda seorang pemenang!</b><br>' . implode('<br>', array_map(function ($p) { return e($p ?: 'Hadiah cabutan bertuah'); }, $wins)) . '<br><span class="muted small">Pihak kami akan menghubungi anda.</span>'); ?>
    <?php endif; ?>
    <div class="card">
        <div class="kv">
            <div class="kv-row"><span>Nama</span><b><?php echo e(mask_name($customer['customer_name'])); ?></b></div>
            <div class="kv-row"><span>ID Pelanggan</span><b class="mono"><?php echo e($customer['customer_id']); ?></b></div>
            <div class="kv-row"><span>Jumlah penyertaan</span><b><?php echo (int)$customer['total_entries']; ?></b></div>
        </div>
        <hr class="divider">
        <div class="label">Kod produk</div>
        <div class="kv">
            <?php foreach ($entries as $entry): ?>
                <div class="kv-row"><b class="mono"><?php echo e(mask_code($entry['purchase_id'])); ?></b><span class="faint small"><?php echo e(format_datetime($entry['entry_date'])); ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php
public_actions([['Kembali ke laman utama', 'index.php', 'home']]);
public_page_end();
?>
