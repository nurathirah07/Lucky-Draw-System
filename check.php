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
            $stmt = $pdo->prepare("SELECT prize, drawn_at FROM winners WHERE customer_id = ? ORDER BY drawn_at");
            $stmt->execute([$customer['customer_id']]);
            $wins = $stmt->fetchAll();
        }
    }
}

public_head('Semak penyertaan');
?>
<div class="wrap">
    <?php brand_block('Semak Penyertaan'); ?>

    <div class="card">
        <form method="POST">
            <div class="field">
                <label for="phone">No. telefon bimbit yang didaftarkan</label>
                <input type="tel" id="phone" name="phone" required inputmode="tel" placeholder="Contoh: 012-345 6789" value="<?php echo e($_POST['phone'] ?? ''); ?>">
            </div>
            <button class="btn btn-primary btn-block" type="submit">🔎 Semak</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="card"><div class="alert alert-error" style="margin:0"><?php echo e($error); ?></div></div>
    <?php elseif ($customer): ?>
        <div class="card">
            <?php if ($wins): ?>
                <div class="alert alert-warn">🏆 <strong>Tahniah! Anda seorang pemenang:</strong>
                    <?php foreach ($wins as $w): ?><br>• <?php echo e($w['prize'] ?: 'Hadiah cabutan bertuah'); ?><?php endforeach; ?>
                    <br><small>Pihak kami akan menghubungi anda.</small>
                </div>
            <?php endif; ?>
            <div class="text-center">
                <div class="muted" style="font-size:14px"><?php echo e(mask_name($customer['customer_name'])); ?> · <?php echo e($customer['customer_id']); ?></div>
                <div class="big-id"><small>Jumlah peluang anda</small><strong><?php echo (int)$customer['total_entries']; ?></strong></div>
            </div>
            <div class="list">
                <?php foreach ($entries as $entry): ?>
                    <div class="list-item">
                        <strong class="mono"><?php echo e(mask_code($entry['purchase_id'])); ?></strong>
                        <span class="faint" style="font-size:13px"><?php echo e(format_datetime($entry['entry_date'], 'd M Y, g:i A')); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-center mt-2"><a href="index.php">← Laman utama</a></div>
</div>
<?php public_foot(); ?>
