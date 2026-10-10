<?php
// admin_settings.php - Settings: Campaign tab + WhatsApp tab
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$settings = get_settings($pdo);
$tab = ($_GET['tab'] ?? '') === 'whatsapp' ? 'whatsapp' : 'campaign';
$error = '';

// ---------- Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = $_POST['form'] ?? '';

    if ($form === 'campaign') {
        $tab = 'campaign';
        $title = trim($_POST['campaign_title'] ?? '');
        $start = trim($_POST['campaign_start'] ?? '');
        $end = trim($_POST['campaign_end'] ?? '');
        $startTs = $start !== '' ? strtotime($start) : null;
        $endTs = $end !== '' ? strtotime($end) : null;

        if ($title === '') {
            $error = 'Campaign name is required.';
        } elseif ($startTs === false || $endTs === false) {
            $error = 'One of the dates is not valid.';
        } elseif ($startTs && $endTs && $endTs <= $startTs) {
            $error = 'The end date must be after the start date.';
        } else {
            save_setting($pdo, 'campaign_title', $title);
            save_setting($pdo, 'campaign_start', $startTs ? date('Y-m-d H:i:s', $startTs) : '');
            save_setting($pdo, 'campaign_end', $endTs ? date('Y-m-d H:i:s', $endTs) : '');
            save_setting($pdo, 'prize_info', trim($_POST['prize_info'] ?? ''));
            header('Location: admin_settings.php?saved=1');
            exit;
        }
    }

    if ($form === 'whatsapp') {
        $tab = 'whatsapp';
        $new = ['wa_buttons' => isset($_POST['wa_buttons']) ? '1' : '0'];
        foreach (['msg_winner', 'msg_confirm'] as $key) {
            $new[$key] = str_replace("\r\n", "\n", trim($_POST[$key] ?? ''));
        }

        if (in_array('', [$new['msg_winner'], $new['msg_confirm']], true)) {
            $error = 'Messages cannot be empty. Use "Reset to default" to restore one.';
        } else {
            foreach ($new as $key => $value) save_setting($pdo, $key, $value);
            header('Location: admin_settings.php?tab=whatsapp&saved=1');
            exit;
        }
        // Show what the admin typed when there is an error
        $settings = array_merge($settings, $new);
    }
}

$state = campaign_state($settings);
$toInput = function ($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; };
$campaignVal = function ($key) use ($settings, $error, $tab, $toInput) {
    if ($error && $tab === 'campaign') return $_POST[$key] ?? '';
    return in_array($key, ['campaign_start', 'campaign_end'], true) ? $toInput($settings[$key]) : $settings[$key];
};
$checked = function ($key) use ($settings) { return $settings[$key] === '1' ? 'checked' : ''; };

admin_header('settings');
page_head('Settings', 'Campaign details shown to customers, and how WhatsApp is used.', campaign_badge($state));
?>
<div class="tabs">
    <a href="admin_settings.php" class="<?php echo $tab === 'campaign' ? 'active' : ''; ?>"><?php echo icon('settings'); ?>Campaign</a>
    <a href="admin_settings.php?tab=whatsapp" class="<?php echo $tab === 'whatsapp' ? 'active' : ''; ?>"><?php echo icon('chat'); ?>WhatsApp</a>
</div>
<?php
if (isset($_GET['saved'])) echo alert_box('success', 'Settings saved.');
if ($error) echo alert_box('error', e($error));

// ---------- Campaign tab ----------
if ($tab === 'campaign'):
?>
<form method="POST" class="card">
    <?php echo csrf_field(); ?><input type="hidden" name="form" value="campaign">
    <div class="card-head"><div><div class="card-title">Campaign</div><div class="card-sub">Customers can only register between the start and end date.</div></div></div>
    <div class="field">
        <label for="title">Campaign name</label>
        <input type="text" id="title" name="campaign_title" required maxlength="100" value="<?php echo e($campaignVal('campaign_title')); ?>">
    </div>
    <div class="field form-row">
        <div class="field">
            <label for="start">Start</label>
            <input type="datetime-local" id="start" name="campaign_start" value="<?php echo e($campaignVal('campaign_start')); ?>">
            <div class="hint">Empty = open now</div>
        </div>
        <div class="field">
            <label for="end">End</label>
            <input type="datetime-local" id="end" name="campaign_end" value="<?php echo e($campaignVal('campaign_end')); ?>">
            <div class="hint">Empty = no end date</div>
        </div>
    </div>
    <div class="field">
        <label for="prizes">Prizes <span class="faint">(one per line, top prize first)</span></label>
        <textarea id="prizes" name="prize_info" rows="5" placeholder="e.g.&#10;Hadiah Utama: Motosikal&#10;Hadiah Kedua: Peti Sejuk"><?php echo e($campaignVal('prize_info')); ?></textarea>
        <div class="hint">Shown on the customer page and offered as choices in the lucky draw.</div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
</form>

<?php
// ---------- WhatsApp tab ----------
else:
    $prizes = array_values(array_filter(array_map('trim', explode("\n", $settings['prize_info']))));
    $sample = [
        'name' => 'Nur Aisyah binti Ahmad', 'customer_id' => 'CUST-00001', 'product_code' => 'K7QF-9XM2',
        'entries' => '3', 'prize' => $prizes[0] ?? 'Baucar RM50', 'campaign' => $settings['campaign_title'],
    ];
    $messages = [
        'msg_winner'  => ['Winner message', 'Sent by you to a winner from the Lucky draw page.'],
        'msg_confirm' => ['Entry confirmation', 'Sent by you to a customer from the Customers page.'],
    ];
?>
<form method="POST" id="waForm">
    <?php echo csrf_field(); ?><input type="hidden" name="form" value="whatsapp">
    <div class="grid grid-main">
        <div class="stack">
            <div class="card">
                <div class="card-head"><div><div class="card-title">WhatsApp buttons</div><div class="card-sub">Buttons open WhatsApp with the message ready to send. Free, no setup needed.</div></div></div>
                <label class="switch-row">
                    <div class="switch-body"><b>Admin WhatsApp buttons</b><span>Show "WhatsApp" buttons on the Customers and Lucky draw pages.</span></div>
                    <input type="checkbox" class="switch" name="wa_buttons" <?php echo $checked('wa_buttons'); ?>>
                </label>
            </div>

            <div class="card">
                <div class="card-head"><div><div class="card-title">Messages</div><div class="card-sub">Click a message to preview it. Placeholders like <span class="mono">{name}</span> are filled in automatically.</div></div></div>
                <?php foreach ($messages as $key => [$label, $desc]): ?>
                    <div class="field">
                        <div class="btn-row" style="justify-content:space-between;margin-bottom:6px">
                            <label for="<?php echo $key; ?>" style="margin:0"><?php echo e($label); ?> <span class="faint" style="font-weight:400">· <?php echo e($desc); ?></span></label>
                            <button type="button" class="btn btn-quiet btn-sm" data-reset="<?php echo $key; ?>">Reset to default</button>
                        </div>
                        <textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="8" data-message data-default="<?php echo e(DEFAULT_MESSAGES[$key]); ?>"><?php echo e($settings[$key]); ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sticky-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><div><div class="card-title">Preview</div><div class="card-sub" id="previewLabel">Winner message</div></div></div>
                <div class="message-preview" id="preview"></div>
                <p class="hint">Sample data. *Bold* in WhatsApp shows as bold text.</p>
            </div>
            <div class="card">
                <div class="card-head"><div><div class="card-title">Placeholders</div><div class="card-sub">Click one to insert it.</div></div></div>
                <div class="placeholders">
                    <?php foreach (MESSAGE_PLACEHOLDERS as $ph => $desc): ?>
                        <div><code data-insert="<?php echo e($ph); ?>"><?php echo e($ph); ?></code><span><?php echo e($desc); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</form>
<script>
(function () {
    var sample = <?php echo json_encode($sample, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var labels = <?php echo json_encode(array_map(function ($m) { return $m[0]; }, $messages)); ?>;
    var preview = document.getElementById('preview');
    var current = document.getElementById('msg_winner');

    function render() {
        preview.textContent = current.value.replace(/\{(\w+)\}/g, function (m, k) { return k in sample ? sample[k] : m; });
        document.getElementById('previewLabel').textContent = labels[current.id];
    }
    document.querySelectorAll('[data-message]').forEach(function (ta) {
        ta.addEventListener('focus', function () { current = ta; render(); });
        ta.addEventListener('input', render);
    });
    document.querySelectorAll('[data-reset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ta = document.getElementById(btn.dataset.reset);
            ta.value = ta.dataset.default; current = ta; render();
        });
    });
    document.querySelectorAll('[data-insert]').forEach(function (code) {
        code.addEventListener('click', function () {
            var s = current.selectionStart, t = code.dataset.insert;
            current.value = current.value.slice(0, s) + t + current.value.slice(current.selectionEnd);
            current.focus(); current.setSelectionRange(s + t.length, s + t.length); render();
        });
    });
    render();
})();
</script>
<?php endif;
admin_footer();
