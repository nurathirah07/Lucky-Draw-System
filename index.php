<?php
// index.php - Customer homepage
require_once 'config.php';

$settings = get_settings($pdo);
$state = campaign_state($settings);
$prizes = array_filter(array_map('trim', explode("\n", $settings['prize_info'])));

$countdownTarget = null;
if ($state === 'upcoming') $countdownTarget = $settings['campaign_start'];
elseif ($state === 'active' && $settings['campaign_end'] !== '') $countdownTarget = $settings['campaign_end'];

public_head($settings['campaign_title']);
?>
<div class="wrap">
    <?php brand_block($settings['campaign_title']); ?>

    <div class="card">
        <div class="text-center" style="margin-bottom:14px">
            <?php if ($state === 'active'): ?>
                <span class="badge badge-green"><span class="dot"></span> Penyertaan dibuka</span>
            <?php elseif ($state === 'upcoming'): ?>
                <span class="badge badge-blue"><span class="dot"></span> Akan bermula</span>
            <?php else: ?>
                <span class="badge badge-red"><span class="dot"></span> Penyertaan telah ditutup</span>
            <?php endif; ?>
        </div>

        <?php if ($countdownTarget): ?>
            <p class="text-center muted" style="font-size:14px">
                <?php echo $state === 'upcoming' ? 'Bermula pada' : 'Tamat pada'; ?>
                <strong style="color:var(--text)"><?php echo e(format_datetime($countdownTarget)); ?></strong>
            </p>
            <div class="countdown" data-target="<?php echo strtotime($countdownTarget) * 1000; ?>">
                <div><b data-u="d">0</b><span>Hari</span></div>
                <div><b data-u="h">0</b><span>Jam</span></div>
                <div><b data-u="m">0</b><span>Minit</span></div>
                <div><b data-u="s">0</b><span>Saat</span></div>
            </div>
        <?php elseif ($state === 'ended'): ?>
            <p class="text-center muted">Terima kasih kepada semua peserta! Pemenang akan dihubungi melalui telefon / WhatsApp.</p>
        <?php else: ?>
            <p class="lead" style="margin-bottom:0">Imbas QR code pada produk anda dan sertai cabutan bertuah untuk memenangi hadiah menarik!</p>
        <?php endif; ?>

        <div class="steps">
            <div class="step"><div class="step-num">1</div><div><div class="step-title">Imbas QR code</div><div class="step-desc">Gunakan kamera telefon pada label produk</div></div></div>
            <div class="step"><div class="step-num">2</div><div><div class="step-title">Isi maklumat</div><div class="step-desc">Nama penuh &amp; nombor telefon bimbit</div></div></div>
            <div class="step"><div class="step-num">3</div><div><div class="step-title">Tunggu cabutan</div><div class="step-desc">Pemenang dihubungi melalui WhatsApp</div></div></div>
        </div>

        <div class="highlight">
            <h3>🎯 Setiap produk = 1 peluang</h3>
            <p>Lebih banyak produk yang anda beli, lebih tinggi peluang untuk menang!</p>
        </div>

        <a href="check.php" class="btn btn-ghost btn-block mt-2">🔎 Semak penyertaan saya</a>
    </div>

    <?php if ($prizes): ?>
    <div class="card">
        <h3 style="font-size:16px;margin-bottom:12px">🏆 Hadiah</h3>
        <div class="list">
            <?php foreach ($prizes as $i => $prize): ?>
                <div class="list-item"><span><?php echo e($prize); ?></span><span class="badge <?php echo $i === 0 ? 'badge-gold' : ''; ?>">#<?php echo $i + 1; ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.countdown').forEach(function (el) {
    var target = +el.dataset.target;
    function tick() {
        var diff = Math.max(0, target - Date.now());
        var parts = { d: Math.floor(diff / 864e5), h: Math.floor(diff / 36e5) % 24, m: Math.floor(diff / 6e4) % 60, s: Math.floor(diff / 1e3) % 60 };
        for (var k in parts) el.querySelector('[data-u="' + k + '"]').textContent = String(parts[k]).padStart(2, '0');
        if (diff === 0) { clearInterval(timer); setTimeout(function () { location.reload(); }, 1500); }
    }
    var timer = setInterval(tick, 1000);
    tick();
});
</script>
<?php public_foot(); ?>
