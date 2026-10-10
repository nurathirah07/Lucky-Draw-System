<?php
// index.php - Customer homepage
require_once 'config.php';

$settings = get_settings($pdo);
$state = campaign_state($settings);
$prizes = array_values(array_filter(array_map('trim', explode("\n", $settings['prize_info']))));

$countdownTarget = null;
if ($state === 'upcoming') $countdownTarget = $settings['campaign_start'];
elseif ($state === 'active' && $settings['campaign_end'] !== '') $countdownTarget = $settings['campaign_end'];

public_page_start($settings['campaign_title'], $settings['campaign_title']);
?>
<div class="card" style="text-align:center">
    <?php echo campaign_badge($state, 'ms'); ?>
    <?php if ($countdownTarget): ?>
        <p class="muted" style="margin-top:12px"><?php echo $state === 'upcoming' ? 'Bermula' : 'Tamat'; ?> pada <b style="color:var(--text)"><?php echo e(format_datetime($countdownTarget)); ?></b></p>
        <div class="countdown" data-remaining="<?php echo max(0, strtotime($countdownTarget) - time()) * 1000; ?>">
            <div><b data-u="d">00</b><span>Hari</span></div>
            <div><b data-u="h">00</b><span>Jam</span></div>
            <div><b data-u="m">00</b><span>Minit</span></div>
            <div><b data-u="s">00</b><span>Saat</span></div>
        </div>
    <?php elseif ($state === 'ended'): ?>
        <p class="muted" style="margin-top:12px">Terima kasih kepada semua peserta. Pemenang akan dihubungi melalui WhatsApp.</p>
    <?php else: ?>
        <p class="muted" style="margin-top:12px">Imbas QR code pada label produk anda dengan kamera telefon untuk menyertai cabutan bertuah.</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><div><div class="card-title">Cara menyertai</div><div class="card-sub">Guna aplikasi Kamera telefon anda.</div></div></div>
    <div class="steps">
        <div class="step"><span class="step-n">1</span><div><b>Buka aplikasi Kamera</b><span>Halakan kamera ke QR code pada label produk.</span></div></div>
        <div class="step"><span class="step-n">2</span><div><b>Tekan pautan yang muncul</b><span>Pautan akan muncul di skrin apabila QR code dikesan. Tekan untuk membuka borang.</span></div></div>
        <div class="step"><span class="step-n">3</span><div><b>Isi nama &amp; nombor telefon</b><span>Guna nombor yang sama untuk setiap produk.</span></div></div>
        <div class="step"><span class="step-n">4</span><div><b>Tunggu cabutan</b><span>Pemenang akan dihubungi melalui WhatsApp.</span></div></div>
    </div>
    <hr class="divider">
    <p class="small muted">Setiap produk = 1 penyertaan. Ada beberapa produk? Imbas setiap QR code satu persatu untuk lebih peluang menang.</p>
</div>

<?php if ($prizes): ?>
<div class="card">
    <div class="card-head"><div class="card-title">Hadiah</div></div>
    <div class="kv">
        <?php foreach ($prizes as $i => $prize): ?>
            <div class="kv-row"><span><?php echo e($prize); ?></span><span class="faint small">#<?php echo $i + 1; ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php public_actions([['Semak penyertaan saya', 'check.php', 'search']]); ?>

<script>
// Counts down from the time left according to the server, so a wrong phone clock
// cannot break it. Reloads once when the campaign opens or closes.
document.querySelectorAll('.countdown').forEach(function (el) {
    var remaining = +el.dataset.remaining, started = Date.now();
    function tick() {
        var diff = Math.max(0, remaining - (Date.now() - started));
        var parts = { d: Math.floor(diff / 864e5), h: Math.floor(diff / 36e5) % 24, m: Math.floor(diff / 6e4) % 60, s: Math.floor(diff / 1e3) % 60 };
        for (var k in parts) el.querySelector('[data-u="' + k + '"]').textContent = String(parts[k]).padStart(2, '0');
        if (diff === 0) {
            clearInterval(timer);
            if (remaining > 0) setTimeout(function () { location.reload(); }, 1500);
        }
    }
    var timer = setInterval(tick, 1000);
    tick();
});
</script>
<?php public_page_end(); ?>
