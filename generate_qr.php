<?php
// generate_qr.php - Create valid product codes and print their QR codes
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch = mb_substr(trim($_POST['batch_name'] ?? ''), 0, 100) ?: 'Batch ' . date('Y-m-d H:i');
    $insert = $pdo->prepare("INSERT IGNORE INTO product_codes (code, batch_name, created_by) VALUES (?, ?, ?)");
    $admin = $_SESSION['admin_username'] ?? null;

    if (($_POST['mode'] ?? '') === 'random') {
        $qty = (int)($_POST['quantity'] ?? 0);
        if ($qty < 1 || $qty > 1000) {
            $error = 'Quantity must be between 1 and 1000.';
        } else {
            $made = 0;
            while ($made < $qty) {
                $insert->execute([generate_product_code(), $batch, $admin]);
                $made += $insert->rowCount();   // a (very rare) duplicate is simply retried
            }
        }
    } else {
        // Existing codes, e.g. invoice or serial numbers
        $codes = array_unique(array_filter(array_map(function ($c) {
            return strtoupper(trim($c));
        }, explode("\n", $_POST['codes'] ?? ''))));
        $codes = array_filter($codes, function ($c) { return preg_match('/^[A-Z0-9\-_]{3,100}$/', $c); });
        if (!$codes) {
            $error = 'Enter at least one code (letters, numbers, - or _ only).';
        } else {
            foreach ($codes as $code) $insert->execute([$code, $batch, $admin]);
        }
    }
    if (!$error) {
        header('Location: generate_qr.php?batch=' . urlencode($batch));
        exit;
    }
}

$viewBatch = $_GET['batch'] ?? null;

$batches = $pdo->query("
    SELECT p.batch_name, COUNT(*) total, SUM(e.id IS NOT NULL) used, MIN(p.created_at) created
    FROM product_codes p LEFT JOIN lucky_draw_entries e ON e.purchase_id = p.code
    GROUP BY p.batch_name ORDER BY created DESC
")->fetchAll();

admin_header('QR codes', 'codes');

if ($viewBatch !== null):
    $stmt = $pdo->prepare("SELECT p.code, e.id IS NOT NULL used FROM product_codes p LEFT JOIN lucky_draw_entries e ON e.purchase_id = p.code WHERE p.batch_name = ? ORDER BY p.created_at, p.code");
    $stmt->execute([$viewBatch]);
    $codes = $stmt->fetchAll();
    $hideUsed = isset($_GET['unused']);
?>
<div class="page-head">
    <div>
        <h1><?php echo e($viewBatch); ?></h1>
        <p><?php echo count($codes); ?> codes · QR links point to <span class="mono"><?php echo e(rtrim($base_url, '/')); ?></span></p>
    </div>
    <div class="btn-row no-print">
        <a href="generate_qr.php" class="btn btn-ghost">← All batches</a>
        <a href="?batch=<?php echo urlencode($viewBatch); ?><?php echo $hideUsed ? '' : '&unused=1'; ?>" class="btn btn-ghost"><?php echo $hideUsed ? 'Show all' : 'Hide registered'; ?></a>
        <button class="btn btn-primary" onclick="window.print()">🖨️ Print</button>
    </div>
</div>
<div class="alert alert-info no-print">Tip: print on sticker paper (4 per row). Registered codes appear faded.</div>
<div class="panel">
    <div class="qr-grid">
        <?php foreach ($codes as $c): if ($hideUsed && $c['used']) continue; ?>
            <div class="qr-card<?php echo $c['used'] ? ' used' : ''; ?>">
                <img src="<?php echo e(qr_image_url(entry_url($c['code']))); ?>" alt="QR <?php echo e($c['code']); ?>" loading="lazy">
                <div class="code"><?php echo e($c['code']); ?></div>
                <div class="cap">Imbas · Cabutan Bertuah</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="page-head">
    <div>
        <h1>QR codes</h1>
        <p>Only codes created here can be registered. Random codes cannot be guessed.</p>
    </div>
    <a href="test_qr.php" class="btn btn-ghost">📶 Test phone connection</a>
</div>

<?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo e($error); ?></div><?php endif; ?>

<div class="grid-even" style="margin-bottom:16px">
    <form method="POST" class="panel">
        <input type="hidden" name="mode" value="random">
        <div class="panel-head"><div><h2>Generate random codes</h2><p>Recommended - e.g. <span class="mono"><?php echo generate_product_code(); ?></span></p></div></div>
        <div class="form-row">
            <div class="field"><label>Quantity</label><input type="number" name="quantity" min="1" max="1000" value="50" required></div>
            <div class="field"><label>Batch name</label><input type="text" name="batch_name" maxlength="100" placeholder="e.g. Raya stock - Kedai A"></div>
        </div>
        <button class="btn btn-primary" type="submit">✨ Generate</button>
    </form>

    <form method="POST" class="panel">
        <input type="hidden" name="mode" value="manual">
        <div class="panel-head"><div><h2>Add existing codes</h2><p>Use your own invoice or serial numbers</p></div></div>
        <div class="field"><textarea name="codes" rows="3" placeholder="INV-2026-0001&#10;INV-2026-0002" required></textarea></div>
        <div class="form-row">
            <div class="field"><input type="text" name="batch_name" maxlength="100" placeholder="Batch name (optional)"></div>
            <div><button class="btn btn-ghost btn-block" type="submit">➕ Add codes</button></div>
        </div>
        <p class="faint" style="font-size:12px">⚠️ Sequential numbers are easy to guess - prefer random codes.</p>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2>Batches</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Batch</th><th>Codes</th><th>Registered</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php if (!$batches): ?><tr><td colspan="5"><div class="empty">No codes yet. Generate your first batch above.</div></td></tr><?php endif; ?>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td><strong><?php echo e($b['batch_name'] ?: '(no name)'); ?></strong></td>
                    <td><?php echo (int)$b['total']; ?></td>
                    <td>
                        <span class="badge <?php echo $b['used'] ? 'badge-green' : ''; ?>"><?php echo (int)$b['used']; ?> / <?php echo (int)$b['total']; ?></span>
                    </td>
                    <td class="muted"><?php echo e(format_datetime($b['created'])); ?></td>
                    <td><a href="?batch=<?php echo urlencode($b['batch_name']); ?>" class="btn btn-ghost btn-sm">View &amp; print</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif;
admin_footer();
