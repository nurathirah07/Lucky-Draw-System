<?php
// generate_qr.php - QR codes: batches, batch print sheet, connection test
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$error = '';

// Delete a batch. Registered entries are kept unless the admin chooses to delete them too.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_batch') {
    csrf_check();
    $batch = (string)($_POST['batch'] ?? '');
    $deleteEntries = ($_POST['entries'] ?? 'keep') === 'delete';
    $run = function ($sql, $params) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    };

    $pdo->beginTransaction();
    try {
        $kept = (int)$run("SELECT COUNT(*) FROM lucky_draw_entries e JOIN product_codes p ON p.code = e.purchase_id WHERE p.batch_name = ?", [$batch])->fetchColumn();
        $removed = ['entries' => 0, 'winners' => 0, 'customers' => 0];

        if ($deleteEntries && $kept > 0) {
            $affected = $run("SELECT DISTINCT e.customer_id FROM lucky_draw_entries e JOIN product_codes p ON p.code = e.purchase_id WHERE p.batch_name = ?", [$batch])->fetchAll(PDO::FETCH_COLUMN);
            $removed['winners'] = $run("DELETE w FROM winners w JOIN lucky_draw_entries e ON e.id = w.entry_id JOIN product_codes p ON p.code = e.purchase_id WHERE p.batch_name = ?", [$batch])->rowCount();
            $removed['entries'] = $run("DELETE e FROM lucky_draw_entries e JOIN product_codes p ON p.code = e.purchase_id WHERE p.batch_name = ?", [$batch])->rowCount();

            // Customers with no entries left are removed; the others get their counts and dates recalculated
            $in = implode(',', array_fill(0, count($affected), '?'));
            $removed['customers'] = $run("DELETE FROM lucky_customers WHERE customer_id IN ($in) AND NOT EXISTS (SELECT 1 FROM lucky_draw_entries e WHERE e.customer_id = lucky_customers.customer_id)", $affected)->rowCount();
            $run("UPDATE lucky_customers c SET
                    total_entries    = (SELECT COUNT(*) FROM lucky_draw_entries e WHERE e.customer_id = c.customer_id),
                    first_entry_date = (SELECT MIN(e.entry_date) FROM lucky_draw_entries e WHERE e.customer_id = c.customer_id),
                    last_entry_date  = (SELECT MAX(e.entry_date) FROM lucky_draw_entries e WHERE e.customer_id = c.customer_id)
                  WHERE c.customer_id IN ($in)", $affected);
            $kept = 0;
        }

        $run("DELETE s FROM qr_scan_log s JOIN product_codes p ON p.code = s.purchase_id WHERE p.batch_name = ?", [$batch]);
        $codes = $run("DELETE FROM product_codes WHERE batch_name = ?", [$batch])->rowCount();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    $msg = 'Batch "' . $batch . '" deleted (' . plural($codes, 'code', 'codes') . ').';
    if ($kept) $msg .= ' ' . plural($kept, 'registered entry was', 'registered entries were') . ' kept in the lucky draw.';
    if ($removed['entries']) {
        $parts = [plural($removed['entries'], 'entry', 'entries')];
        if ($removed['customers']) $parts[] = plural($removed['customers'], 'customer', 'customers');
        if ($removed['winners']) $parts[] = plural($removed['winners'], 'winner', 'winners');
        $msg .= ' Also removed ' . implode(', ', $parts) . '.';
    }
    $_SESSION['flash'] = $msg;
    header('Location: generate_qr.php');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $admin = $_SESSION['admin_username'] ?? null;
    $random = ($_POST['mode'] ?? 'random') === 'random';
    $qty = (int)($_POST['quantity'] ?? 0);

    // "My own codes": clean the list and note what cannot be used
    $codes = $invalid = [];
    if (!$random) {
        foreach (preg_split('/\R/', $_POST['codes'] ?? '') as $line) {
            $c = strtoupper(trim($line));
            if ($c === '') continue;
            if (preg_match('/^[A-Z0-9\-_]{3,100}$/', $c)) $codes[$c] = true;
            else $invalid[] = $c;
        }
        $codes = array_keys($codes);   // also removes duplicates within the list
    }

    if ($random && ($qty < 1 || $qty > 1000)) {
        $error = 'Quantity must be between 1 and 1000.';
    } elseif (!$random && !$codes) {
        $error = 'Enter at least one code (letters, numbers, - or _ only, 3+ characters).';
    } else {
        // One batch at a time (other admins, double clicks): a named database lock
        $pdo->query("SELECT GET_LOCK('astech_create_batch', 15)");
        try {
            // Every batch gets its own name: "Batch 8 Oct 2026, 2:20 PM" -> "... (2)" if taken
            $nameBase = mb_substr(trim($_POST['batch_name'] ?? ''), 0, 90) ?: 'Batch ' . date('j M Y, g:i A');
            $exists = $pdo->prepare("SELECT 1 FROM product_codes WHERE batch_name = ? LIMIT 1");
            $batch = $nameBase;
            for ($n = 2; $exists->execute([$batch]) && $exists->fetchColumn(); $n++) $batch = "$nameBase ($n)";

            $insert = $pdo->prepare("INSERT IGNORE INTO product_codes (code, batch_name, created_by) VALUES (?, ?, ?)");
            $pdo->beginTransaction();
            $added = 0;
            if ($random) {
                // `code` is the primary key, so a code can never exist twice;
                // in the (very rare) case a random code is already taken, a new one is drawn
                while ($added < $qty) {
                    $insert->execute([generate_product_code(), $batch, $admin]);
                    $added += $insert->rowCount();
                }
                $skipped = [];
            } else {
                $skipped = [];
                foreach ($codes as $code) {
                    $insert->execute([$code, $batch, $admin]);
                    if ($insert->rowCount()) $added++; else $skipped[] = $code;
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('astech_create_batch')");
        }

        if ($added === 0) {
            $error = 'No codes were added: ' . (count($skipped) === 1 ? 'that code already exists.' : 'all of these codes already exist.');
        } else {
            $msg = 'Batch "' . $batch . '" created with ' . plural($added, 'code', 'codes') . '.';
            if ($skipped) $msg .= ' Skipped ' . plural(count($skipped), 'code that already exists', 'codes that already exist') . ': ' . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? '…' : '') . '.';
            if ($invalid) $msg .= ' Ignored ' . plural(count($invalid), 'line', 'lines') . ' that are not valid codes.';
            $_SESSION['flash'] = $msg;
            header('Location: generate_qr.php?batch=' . urlencode($batch));
            exit;
        }
    }
}

$base = rtrim($base_url, '/');
$viewBatch = $_GET['batch'] ?? null;
$tab = ($_GET['tab'] ?? '') === 'test' ? 'test' : 'batches';

/** Shows (once) the message saved before a redirect, e.g. after creating or deleting a batch */
function show_flash() {
    if (empty($_SESSION['flash'])) return;
    echo alert_box('success', e($_SESSION['flash']));
    unset($_SESSION['flash']);
}

function qr_tabs($tab) {
    ?>
    <div class="tabs">
        <a href="generate_qr.php" class="<?php echo $tab === 'batches' ? 'active' : ''; ?>"><?php echo icon('qr'); ?>Batches</a>
        <a href="generate_qr.php?tab=test" class="<?php echo $tab === 'test' ? 'active' : ''; ?>"><?php echo icon('wifi'); ?>Connection test</a>
    </div>
    <?php
}

/**
 * Per-batch numbers: codes, registered entries, customers and winners linked to it,
 * and how many customers have entries only in this batch (they would be removed).
 */
function batch_stats($pdo) {
    $rows = $pdo->query("
        SELECT p.batch_name, COUNT(*) total, COUNT(e.id) used, COUNT(DISTINCT e.customer_id) customers,
               COUNT(w.id) winners, MIN(p.created_at) created
        FROM product_codes p
        LEFT JOIN lucky_draw_entries e ON e.purchase_id = p.code
        LEFT JOIN winners w ON w.entry_id = e.id
        GROUP BY p.batch_name ORDER BY created DESC
    ")->fetchAll();
    $only = $pdo->query("
        SELECT batch_name, COUNT(*) n FROM (
            SELECT e.customer_id, MIN(p.batch_name) batch_name, COUNT(DISTINCT IFNULL(p.batch_name, '')) batches, SUM(p.code IS NULL) orphan
            FROM lucky_draw_entries e LEFT JOIN product_codes p ON p.code = e.purchase_id
            GROUP BY e.customer_id
        ) x WHERE batches = 1 AND orphan = 0 GROUP BY batch_name
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats = [];
    foreach ($rows as $r) {
        $r['only_here'] = (int)($only[$r['batch_name']] ?? 0);
        $stats[$r['batch_name']] = $r;
    }
    return $stats;
}

/** Delete button; opens the confirmation dialog rendered by delete_batch_dialog() */
function delete_batch_button(array $b, $label = '', $size = 'btn-sm') {
    $data = ['name' => $b['batch_name'], 'total' => (int)$b['total'], 'used' => (int)$b['used'],
             'customers' => (int)$b['customers'], 'winners' => (int)$b['winners'], 'only' => (int)$b['only_here']];
    return '<button type="button" class="btn ' . $size . ' btn-danger" title="Delete batch" data-delete-batch="' . e(json_encode($data)) . '">'
        . icon('trash') . e($label) . '</button>';
}

function delete_batch_dialog() {
    ?>
    <div class="modal" id="deleteModal">
        <form method="POST" action="generate_qr.php" class="modal-card dialog">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_batch">
            <input type="hidden" name="batch" id="dmBatch">
            <h2>Delete batch “<span id="dmName"></span>”?</h2>
            <p class="muted" id="dmSummary"></p>

            <div id="dmChoices" class="options">
                <label class="option">
                    <input type="radio" name="entries" value="keep" checked>
                    <span><b>Keep the registered entries</b><span id="dmKeep"></span></span>
                </label>
                <label class="option">
                    <input type="radio" name="entries" value="delete">
                    <span><b>Also delete the registered entries</b><span id="dmDelete"></span></span>
                </label>
            </div>
            <p class="muted small" id="dmUnused">None of these codes are registered. The codes and their scan history will be deleted.</p>
            <p class="small faint">This cannot be undone.</p>

            <div class="btn-row" style="justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn" id="dmCancel">Cancel</button>
                <button type="submit" class="btn btn-primary"><?php echo icon('trash'); ?>Delete batch</button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var modal = document.getElementById('deleteModal');
        function n(count, one, many) { return count + ' ' + (count === 1 ? one : many); }
        function $(id) { return document.getElementById(id); }

        document.querySelectorAll('[data-delete-batch]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var b = JSON.parse(btn.dataset.deleteBatch);
                $('dmBatch').value = b.name;
                $('dmName').textContent = b.name;
                $('dmSummary').textContent = n(b.total, 'code', 'codes') + ' · ' + b.used + ' registered';
                $('dmChoices').classList.toggle('hidden', b.used === 0);
                $('dmUnused').classList.toggle('hidden', b.used > 0);
                $('dmKeep').textContent = n(b.used, 'entry stays', 'entries stay') + ' in the lucky draw. Only the codes are deleted, so they cannot be registered again.';
                var del = 'Removes ' + n(b.used, 'entry', 'entries') + ' from ' + n(b.customers, 'customer', 'customers') + ' and updates their entry counts.';
                if (b.only) del += ' ' + n(b.only, 'customer has', 'customers have') + ' no other entries and will be removed.';
                if (b.winners) del += ' ' + n(b.winners, 'winner', 'winners') + ' drawn from these entries will be removed.';
                $('dmDelete').textContent = del;
                modal.querySelector('input[value=keep]').checked = true;
                modal.classList.add('show');
            });
        });
        function close() { modal.classList.remove('show'); }
        $('dmCancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    })();
    </script>
    <?php
}

// ---------- One batch: print sheet ----------
if ($viewBatch !== null) {
    $stmt = $pdo->prepare("SELECT p.code, e.id IS NOT NULL used FROM product_codes p LEFT JOIN lucky_draw_entries e ON e.purchase_id = p.code WHERE p.batch_name = ? ORDER BY p.created_at, p.code");
    $stmt->execute([$viewBatch]);
    $codes = $stmt->fetchAll();
    $used = count(array_filter(array_column($codes, 'used')));
    $hideUsed = isset($_GET['unused']);
    $toggle = '?batch=' . urlencode($viewBatch) . ($hideUsed ? '' : '&unused=1');
    $stats = batch_stats($pdo)[$viewBatch] ?? null;

    admin_header('codes', $viewBatch);
    page_head(
        $viewBatch,
        plural(count($codes), 'code', 'codes') . ' · ' . $used . ' registered · links open <span class="mono">' . e($base) . '</span>',
        ($stats ? delete_batch_button($stats, 'Delete batch', '') : '')
        . '<a class="btn" href="' . e($toggle) . '">' . ($hideUsed ? 'Show all codes' : 'Hide registered') . '</a>'
        . '<button class="btn btn-primary" id="printBtn" onclick="window.print()">' . icon('printer') . 'Print</button>',
        ['QR codes', 'generate_qr.php']
    );
    show_flash();
    ?>
    <div class="card">
        <?php if (!$codes): ?>
            <div class="empty"><?php echo icon('qr'); ?><p>This batch has no codes.</p></div>
        <?php else: ?>
            <p class="small faint no-print" style="margin-bottom:16px"><span id="qrStatus">Preparing QR codes…</span> Print on A4 sticker paper (4 per row). Registered codes are faded.</p>
            <div class="qr-grid">
                <?php foreach ($codes as $c): if ($hideUsed && $c['used']) continue; ?>
                    <div class="qr-card<?php echo $c['used'] ? ' used' : ''; ?>">
                        <?php echo qr_box(entry_url($c['code']), $c['code']); ?>
                        <div class="code"><?php echo e($c['code']); ?></div>
                        <div class="cap">Imbas untuk cabutan bertuah</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    qr_render_script();
    delete_batch_dialog();
    admin_footer();
    exit;
}

// ---------- Connection test ----------
if ($tab === 'test') {
    $baseHost = parse_url($base, PHP_URL_HOST);
    $serverIp = $_SERVER['SERVER_ADDR'] ?? '';
    if ($serverIp === '' || $serverIp === '::1' || $serverIp === '127.0.0.1') $serverIp = gethostbyname(gethostname());
    $sampleCodes = $pdo->query("SELECT p.code FROM product_codes p LEFT JOIN lucky_draw_entries e ON e.purchase_id = p.code WHERE e.id IS NULL ORDER BY p.created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);

    // Two setups: an IP address on the office Wi-Fi, or a domain name when hosted online
    $isIp = (bool)filter_var($baseHost, FILTER_VALIDATE_IP);
    $isHttps = parse_url($base, PHP_URL_SCHEME) === 'https';
    if (in_array($baseHost, ['localhost', '127.0.0.1'], true)) {
        $check = alert_box('error', 'The base URL uses <b>' . e($baseHost) . '</b>, which only works on this computer. Set <span class="mono">$base_url</span> in config.php to your domain, or to <b class="mono">http://' . e($serverIp) . '/astech_lucky</b> for the office Wi-Fi.');
    } elseif ($isIp && $baseHost !== $serverIp) {
        $check = alert_box('warning', 'The base URL IP (<b>' . e($baseHost) . '</b>) is different from this computer\'s IP (<b>' . e($serverIp) . '</b>). If the IP changed, update <span class="mono">$base_url</span> in config.php before printing.');
    } elseif ($isIp) {
        $check = alert_box('info', 'Office Wi-Fi setup: phones must be on the same Wi-Fi as this computer. Customers scan with a photo, because live camera scanning needs HTTPS.');
    } elseif (!$isHttps) {
        $check = alert_box('warning', 'Your domain is not using HTTPS. Turn on SSL in your hosting panel and change <span class="mono">$base_url</span> to <b class="mono">https://' . e($baseHost) . '</b>.');
    } else {
        $check = alert_box('success', 'Online setup with HTTPS: phones on any network can open your QR codes, and live camera scanning works.');
    }

    admin_header('codes', 'Connection test');
    page_head('QR codes', 'Only codes created here can be registered. Each code is one entry.');
    qr_tabs('test');
    ?>
    <div class="grid grid-main">
        <div class="card">
            <div class="card-head"><div><div class="card-title">Scan with a phone</div><div class="card-sub">The homepage code is safe to scan. Registering with a real code uses it up.</div></div></div>
            <div class="qr-grid">
                <div class="qr-card">
                    <?php echo qr_box($base . '/index.php', 'homepage'); ?>
                    <div class="code">HOMEPAGE</div>
                    <div class="cap">Should open the customer page</div>
                </div>
                <?php foreach ($sampleCodes as $code): ?>
                    <div class="qr-card">
                        <?php echo qr_box(entry_url($code), $code); ?>
                        <div class="code"><?php echo e($code); ?></div>
                        <div class="cap">Real product code</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><div><div class="card-title">QR code link</div><div class="card-sub">Every QR code opens this address</div></div></div>
            <div class="label">Base URL</div>
            <div class="mono small" style="word-break:break-all;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg)"><?php echo e($base); ?></div>
            <?php if ($isIp): ?>
                <div class="kv" style="margin-top:12px"><div class="kv-row"><span>This computer</span><b class="mono"><?php echo e($serverIp); ?></b></div></div>
            <?php endif; ?>
            <div style="margin-top:16px"><?php echo $check; ?></div>
        </div>
    </div>
    <?php
    qr_render_script();
    admin_footer();
    exit;
}

// ---------- Batches ----------
$batches = batch_stats($pdo);
$mode = ($_POST['mode'] ?? 'random') === 'manual' ? 'manual' : 'random';

admin_header('codes');
page_head('QR codes', 'Only codes created here can be registered. Each code is one entry.');
qr_tabs('batches');
if ($error) echo alert_box('error', e($error));
show_flash();
?>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-head"><div><div class="card-title">Batches</div><div class="card-sub">Open a batch to print its QR codes.</div></div></div>
        <?php if (!$batches): ?>
            <div class="empty"><?php echo icon('qr'); ?><p>No codes yet.</p><p class="small faint">Create your first batch with the form.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Batch</th><th class="num">Codes</th><th>Registered</th><th class="hide-sm">Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($batches as $b): $p = $b['total'] ? round($b['used'] / $b['total'] * 100) : 0; ?>
                    <tr>
                        <td><?php echo e($b['batch_name'] ?: '(no name)'); ?></td>
                        <td class="num"><?php echo (int)$b['total']; ?></td>
                        <td style="min-width:120px"><div class="meter-top small"><span class="muted"><?php echo (int)$b['used']; ?> used</span><span class="faint"><?php echo $p; ?>%</span></div><div class="bar"><i style="width:<?php echo $p; ?>%"></i></div></td>
                        <td class="muted nowrap hide-sm"><?php echo e(format_datetime($b['created'])); ?></td>
                        <td class="num nowrap">
                            <a href="?batch=<?php echo urlencode($b['batch_name']); ?>" class="btn btn-sm"><?php echo icon('printer'); ?>Open</a>
                            <?php echo delete_batch_button($b); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <form method="POST" class="card" id="newBatch">
        <?php echo csrf_field(); ?>
        <div class="card-head"><div><div class="card-title">New batch</div><div class="card-sub">Creates codes you can print and stick on products.</div></div></div>
        <div class="field">
            <div class="tabs" style="display:flex">
                <label style="flex:1"><input type="radio" name="mode" value="random" <?php echo $mode === 'random' ? 'checked' : ''; ?>><span style="flex:1;justify-content:center">Random codes</span></label>
                <label style="flex:1"><input type="radio" name="mode" value="manual" <?php echo $mode === 'manual' ? 'checked' : ''; ?>><span style="flex:1;justify-content:center">My own codes</span></label>
            </div>
        </div>
        <div class="field" data-mode="random">
            <label for="qty">How many codes?</label>
            <input type="number" id="qty" name="quantity" min="1" max="1000" value="<?php echo (int)($_POST['quantity'] ?? 50); ?>">
            <div class="hint">Random codes like <span class="mono"><?php echo generate_product_code(); ?></span> cannot be guessed.</div>
        </div>
        <div class="field" data-mode="manual">
            <label for="codes">Codes, one per line</label>
            <textarea id="codes" name="codes" rows="4" placeholder="INV-2026-0001&#10;INV-2026-0002"><?php echo e($_POST['codes'] ?? ''); ?></textarea>
            <div class="hint">e.g. invoice or serial numbers. Sequential numbers are easy to guess.</div>
        </div>
        <div class="field">
            <label for="batch">Batch name <span class="faint">(optional)</span></label>
            <input type="text" id="batch" name="batch_name" maxlength="100" placeholder="e.g. Raya stock – Kedai A" value="<?php echo e($_POST['batch_name'] ?? ''); ?>">
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit"><?php echo icon('plus'); ?>Create batch</button></div>
    </form>
</div>
<script>
(function () {
    var form = document.getElementById('newBatch');
    function sync() {
        var mode = form.querySelector('input[name=mode]:checked').value;
        form.querySelectorAll('[data-mode]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.mode !== mode); });
    }
    form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();

    // Send the form only once, even if the button is clicked twice
    form.addEventListener('submit', function (e) {
        if (form.dataset.sent) { e.preventDefault(); return; }
        form.dataset.sent = '1';
        form.querySelector('button[type=submit]').classList.add('loading');
    });
})();
</script>
<?php
delete_batch_dialog();
admin_footer();
