<?php
// admin_settings.php - Campaign settings (title, start/end date, prizes)
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['campaign_title'] ?? '');
    $start = trim($_POST['campaign_start'] ?? '');
    $end = trim($_POST['campaign_end'] ?? '');
    $prizes = trim($_POST['prize_info'] ?? '');

    $startTs = $start !== '' ? strtotime($start) : null;
    $endTs = $end !== '' ? strtotime($end) : null;

    if ($title === '') {
        $error = 'Campaign title is required.';
    } elseif ($start !== '' && $startTs === false || $end !== '' && $endTs === false) {
        $error = 'Invalid date.';
    } elseif ($startTs && $endTs && $endTs <= $startTs) {
        $error = 'End date must be after the start date.';
    } else {
        save_setting($pdo, 'campaign_title', $title);
        save_setting($pdo, 'campaign_start', $startTs ? date('Y-m-d H:i:s', $startTs) : '');
        save_setting($pdo, 'campaign_end', $endTs ? date('Y-m-d H:i:s', $endTs) : '');
        save_setting($pdo, 'prize_info', $prizes);
        header('Location: admin_settings.php?saved=1');
        exit;
    }
}
if (isset($_GET['saved'])) $message = 'Campaign settings saved.';

$settings = get_settings($pdo);
$state = campaign_state($settings);
$toInput = function ($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; };
$stateBadge = [
    'upcoming' => ['badge-blue', 'Not started yet'],
    'active'   => ['badge-green', 'Open for entries'],
    'ended'    => ['badge-red', 'Ended - entries closed'],
][$state];

admin_header('Campaign settings', 'settings');
?>
<div class="page-head">
    <div>
        <h1>Campaign settings</h1>
        <p>Control when customers can register and what prizes are shown.</p>
    </div>
    <span class="badge <?php echo $stateBadge[0]; ?>"><span class="dot"></span> <?php echo $stateBadge[1]; ?></span>
</div>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo e($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo e($error); ?></div><?php endif; ?>

<div class="grid-2">
    <form method="POST" class="panel">
        <div class="panel-head"><h2>Campaign</h2></div>
        <div class="field">
            <label for="title">Campaign title</label>
            <input type="text" id="title" name="campaign_title" required maxlength="100" value="<?php echo e($settings['campaign_title']); ?>">
        </div>
        <div class="form-row">
            <div class="field">
                <label for="start">Start date &amp; time</label>
                <input type="datetime-local" id="start" name="campaign_start" value="<?php echo e($toInput($settings['campaign_start'])); ?>">
                <div class="hint">Leave blank to open immediately.</div>
            </div>
            <div class="field">
                <label for="end">End date &amp; time</label>
                <input type="datetime-local" id="end" name="campaign_end" value="<?php echo e($toInput($settings['campaign_end'])); ?>">
                <div class="hint">Leave blank for no end date.</div>
            </div>
        </div>
        <div class="field">
            <label for="prizes">Prizes (one per line)</label>
            <textarea id="prizes" name="prize_info" rows="5" placeholder="Hadiah Utama: Motosikal&#10;Hadiah Kedua: Peti Sejuk&#10;Hadiah Ketiga: Baucar RM100"><?php echo e($settings['prize_info']); ?></textarea>
            <div class="hint">Shown on the public homepage and offered as choices on the spin wheel.</div>
        </div>
        <button class="btn btn-primary" type="submit">Save settings</button>
    </form>

    <div class="panel">
        <div class="panel-head"><h2>How it works</h2></div>
        <div class="list">
            <div class="list-item"><span>Before start</span><span class="badge badge-blue">Registration closed</span></div>
            <div class="list-item"><span>Between start &amp; end</span><span class="badge badge-green">Open</span></div>
            <div class="list-item"><span>After end</span><span class="badge badge-red">Registration closed</span></div>
        </div>
        <p class="muted mt-2" style="font-size:14px">The homepage shows a countdown to the start or end. Times use Malaysia time (GMT+8). You can still run the spin wheel after the campaign ends.</p>
    </div>
</div>
<?php admin_footer(); ?>
