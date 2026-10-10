<?php
// admin_draw.php - Spin wheel lucky draw + winners list
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$settings = get_settings($pdo);
$prizes = array_values(array_filter(array_map('trim', explode("\n", $settings['prize_info']))));

// Participants: entries that have not won yet, grouped by customer
$participants = $pdo->query("
    SELECT c.customer_id, c.customer_name,
           COUNT(e.id) AS entries,
           EXISTS (SELECT 1 FROM winners w WHERE w.customer_id = c.customer_id) AS won
    FROM lucky_customers c
    JOIN lucky_draw_entries e ON e.customer_id = c.customer_id
    WHERE e.id NOT IN (SELECT entry_id FROM winners)
    GROUP BY c.customer_id, c.customer_name
")->fetchAll();
foreach ($participants as &$p) { $p['entries'] = (int)$p['entries']; $p['won'] = (bool)$p['won']; }
unset($p);

$winners = $pdo->query("SELECT * FROM winners ORDER BY drawn_at DESC, id DESC")->fetchAll();
$awarded = array_column($winners, 'prize');
$nextPrize = '';
foreach ($prizes as $prize) { if (!in_array($prize, $awarded, true)) { $nextPrize = $prize; break; } }
$waButtons = $settings['wa_buttons'] === '1';

admin_header('draw');
page_head(
    'Lucky draw',
    'Each registered product code is one entry. The winner is picked securely on the server.',
    '<button class="btn" id="fullscreenBtn">' . icon('expand') . 'Full screen</button>'
);
?>
<div id="stage">
<div class="draw">
    <div class="card">
        <div class="wheel-wrap">
            <div class="wheel-pointer"></div>
            <canvas id="wheel" width="1040" height="1040"></canvas>
            <button class="wheel-hub" id="hubBtn" aria-label="Spin"><?php echo icon('spin'); ?></button>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><div class="card-title">Draw</div></div>
            <div class="field">
                <label for="prizeSelect">Prize</label>
                <select id="prizeSelect">
                    <?php foreach ($prizes as $prize): ?>
                        <option value="<?php echo e($prize); ?>"<?php echo $prize === $nextPrize ? ' selected' : ''; ?>><?php echo e($prize); ?><?php echo in_array($prize, $awarded, true) ? ' (already drawn)' : ''; ?></option>
                    <?php endforeach; ?>
                    <option value="__custom"<?php echo $prizes ? '' : ' selected'; ?>>Other prize…</option>
                </select>
                <?php if (!$prizes): ?><div class="hint">Add your prize list in <a class="link" href="admin_settings.php">Settings</a>.</div><?php endif; ?>
            </div>
            <div class="field<?php echo $prizes ? ' hidden' : ''; ?>" id="customPrizeField">
                <input type="text" id="customPrize" maxlength="150" placeholder="Prize name, e.g. Baucar RM50">
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" id="excludeWinners" checked> Skip customers who already won</label>
            </div>
            <hr class="divider">
            <div class="kv">
                <div class="kv-row"><span>Customers in the draw</span><b id="poolCustomers">0</b></div>
                <div class="kv-row"><span>Entries in the draw</span><b id="poolEntries">0</b></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary btn-lg btn-block" id="spinBtn"><?php echo icon('spin'); ?>Spin the wheel</button></div>
        </div>

        <div class="card card-flush">
            <div class="card-head"><div><div class="card-title">Winners</div><div class="card-sub"><?php echo count($winners); ?> drawn · removing a winner puts the entry back in the draw</div></div></div>
            <?php if (!$winners): ?>
                <div class="empty"><?php echo icon('trophy'); ?><p>No winners yet.</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="winnersTable">
                    <tbody>
                    <?php foreach ($winners as $w): ?>
                        <tr>
                            <td>
                                <?php echo e($w['customer_name']); ?> <span class="badge badge-warning plain"><?php echo e($w['prize'] ?: 'Prize'); ?></span>
                                <div class="sub-line"><span class="mono"><?php echo e($w['purchase_id']); ?></span> · <?php echo e(format_phone($w['customer_phone'])); ?> · <?php echo e(format_datetime($w['drawn_at'])); ?></div>
                            </td>
                            <td class="num nowrap">
                                <?php if ($waButtons): ?><a class="btn btn-sm" target="_blank" rel="noopener" title="Notify on WhatsApp" href="<?php echo e(wa_link($w['customer_phone'], winner_message($w, $settings))); ?>"><?php echo icon('chat', 'icon-wa'); ?></a><?php endif; ?>                                <button class="btn btn-sm btn-danger" data-delete="<?php echo (int)$w['id']; ?>" title="Remove winner"><?php echo icon('trash'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal" id="winModal">
    <div class="modal-card">
        <div class="eyebrow" id="wPrize"></div>
        <h2 id="wName"></h2>
        <p class="muted">is the winner</p>
        <div class="kv">
            <div class="kv-row"><span>Customer ID</span><b class="mono" id="wId"></b></div>
            <div class="kv-row"><span>Product code</span><b class="mono" id="wCode"></b></div>
            <div class="kv-row"><span>Phone</span><b class="mono" id="wPhone"></b></div>
        </div>
        <div class="btn-row" style="justify-content:center">
            <?php if ($waButtons): ?><a class="btn btn-primary" id="wWa" target="_blank" rel="noopener"><?php echo icon('chat'); ?>Notify on WhatsApp</a><?php endif; ?>
            <button class="btn" id="wClose">Done</button>
        </div>
    </div>
</div>
</div>

<script>
var participants = <?php echo json_encode($participants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var MAX_SEGMENTS = 24;
var REDS = ['#e5484d', '#c43c40'], GREYS = ['#1c1c20', '#2a2a30'];

// Alternate red / grey so neighbouring slices never share a colour
function sliceColor(i, n) {
    if (i % 2 === 1) return GREYS[((i - 1) / 2) % 2];
    if (n > 1 && i === n - 1) return '#33333a';   // odd count: last slice touches the first (red)
    return REDS[(i / 2) % 2];
}
var canvas = document.getElementById('wheel');
var ctx = canvas.getContext('2d');
var segments = [];
var rotation = 0;      // radians, clockwise
var spinning = false;

function eligible() {
    var skip = document.getElementById('excludeWinners').checked;
    return participants.filter(function (p) { return !(skip && p.won); });
}

function shuffle(a) {
    for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; }
    return a;
}

// Small draws: slice size = number of entries.
// Big draws: show a sample of names with equal slices (the real odds are applied on the server).
function buildSegments(list) {
    var shown = list.length > MAX_SEGMENTS ? shuffle(list.slice()).slice(0, MAX_SEGMENTS) : list.slice();
    var weighted = list.length <= MAX_SEGMENTS;
    var total = shown.reduce(function (s, p) { return s + (weighted ? p.entries : 1); }, 0);
    var minShare = shown.length ? 0.35 / shown.length : 0;   // keep tiny slices visible
    var shares = shown.map(function (p) { return Math.max((weighted ? p.entries : 1) / total, minShare); });
    var sum = shares.reduce(function (a, b) { return a + b; }, 0);
    var start = 0;
    segments = shown.map(function (p, i) {
        var size = shares[i] / sum * Math.PI * 2;
        var seg = { id: p.customer_id, name: p.customer_name, start: start, end: start + size };
        start += size;
        return seg;
    });
}

function draw() {
    var size = canvas.width, r = size / 2;
    ctx.clearRect(0, 0, size, size);
    ctx.save();
    ctx.translate(r, r);
    ctx.rotate(rotation);
    if (!segments.length) {
        ctx.fillStyle = '#18181b'; ctx.beginPath(); ctx.arc(0, 0, r, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#6b6b74'; ctx.font = '500 32px Inter, sans-serif'; ctx.textAlign = 'center';
        ctx.fillText('No entries to draw', 0, -r / 2);
        ctx.restore(); return;
    }
    segments.forEach(function (s, i) {
        var a0 = s.start - Math.PI / 2, a1 = s.end - Math.PI / 2;
        var color = sliceColor(i, segments.length);
        ctx.beginPath(); ctx.moveTo(0, 0); ctx.arc(0, 0, r, a0, a1); ctx.closePath();
        ctx.fillStyle = color; ctx.fill();
        if (segments.length > 1) { ctx.strokeStyle = '#0a0a0b'; ctx.lineWidth = 4; ctx.stroke(); }

        var arc = s.end - s.start, mid = (a0 + a1) / 2;
        // Keep names upright: flip labels whose slice is on the left half of the wheel
        var flip = Math.cos(mid + rotation) < 0;
        ctx.save();
        ctx.rotate(flip ? mid + Math.PI : mid);
        ctx.textAlign = flip ? 'left' : 'right'; ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ededef';
        ctx.font = '600 ' + Math.max(18, Math.min(34, arc * r * 0.42)) + 'px Inter, sans-serif';
        var label = s.name.length > 16 ? s.name.slice(0, 15) + '…' : s.name;
        if (arc * r > 20) ctx.fillText(label, flip ? -(r - 40) : r - 40, 0);
        ctx.restore();
    });
    ctx.restore();
}

function segmentAtPointer() {
    var a = ((-rotation) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
    for (var i = 0; i < segments.length; i++) if (a >= segments[i].start && a < segments[i].end) return i;
    return -1;
}

function refresh() {
    if (spinning) return;
    var list = eligible();
    buildSegments(list);
    document.getElementById('poolCustomers').textContent = list.length;
    document.getElementById('poolEntries').textContent = list.reduce(function (s, p) { return s + p.entries; }, 0);
    document.getElementById('spinBtn').disabled = !list.length;
    draw();
}

var audio;
function tick() {
    try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
        var o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = 1400; o.type = 'square';
        g.gain.setValueAtTime(0.05, audio.currentTime);
        g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.04);
        o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + 0.05);
    } catch (e) {}
}

function spinTo(winnerId, done) {
    var idx = segments.findIndex(function (s) { return s.id === winnerId; });
    if (idx === -1) {
        // Winner not among the sampled names: put them in a random slice
        idx = Math.floor(Math.random() * segments.length);
        var w = participants.find(function (p) { return p.customer_id === winnerId; });
        segments[idx].id = winnerId; segments[idx].name = w ? w.customer_name : 'Winner';
        draw();
    }
    var s = segments[idx];
    var margin = (s.end - s.start) * 0.15;
    var target = s.start + margin + Math.random() * (s.end - s.start - margin * 2);
    var full = Math.PI * 2;
    var current = ((rotation % full) + full) % full;
    var delta = (full - target - current + full * 2) % full + full * 8; // 8 extra turns
    var from = rotation, duration = 8000, t0 = performance.now(), last = segmentAtPointer();

    function frame(now) {
        var t = Math.min(1, (now - t0) / duration);
        rotation = from + delta * (1 - Math.pow(1 - t, 4));
        draw();
        var seg = segmentAtPointer();
        if (seg !== last) { tick(); last = seg; }
        if (t < 1) requestAnimationFrame(frame); else done();
    }
    requestAnimationFrame(frame);
}

function confetti() {
    var colors = ['#e5484d', '#ededef', '#f5a524', '#3dd68c', '#52a9ff'];
    for (var i = 0; i < 140; i++) {
        var c = document.createElement('div');
        c.className = 'confetti';
        c.style.left = Math.random() * 100 + 'vw';
        c.style.background = colors[i % colors.length];
        (document.fullscreenElement || document.body).appendChild(c);
        c.animate([
            { transform: 'translateY(0) rotate(0)', opacity: 1 },
            { transform: 'translateY(' + (innerHeight + 40) + 'px) rotate(' + (Math.random() * 900) + 'deg)', opacity: .9 }
        ], { duration: 2500 + Math.random() * 2000, delay: Math.random() * 700, easing: 'cubic-bezier(.25,.6,.4,1)', fill: 'forwards' })
         .onfinish = (function (el) { return function () { el.remove(); }; })(c);
    }
}

function api(body) {
    return fetch('admin_draw_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json(); });
}

function startSpin() {
    var btn = document.getElementById('spinBtn');
    if (spinning || btn.disabled) return;
    var sel = document.getElementById('prizeSelect').value;
    var prize = sel === '__custom' ? document.getElementById('customPrize').value.trim() : sel;
    spinning = true; btn.classList.add('loading');
    api({ action: 'draw', prize: prize, exclude_winners: document.getElementById('excludeWinners').checked })
        .then(function (res) {
            if (!res.success) throw new Error(res.message);
            spinTo(res.winner.customer_id, function () {
                var w = res.winner;
                document.getElementById('wPrize').textContent = w.prize || 'Lucky draw prize';
                document.getElementById('wName').textContent = w.customer_name;
                document.getElementById('wId').textContent = w.customer_id;
                document.getElementById('wCode').textContent = w.purchase_id;
                document.getElementById('wPhone').textContent = w.phone;
                var wa = document.getElementById('wWa');
                if (wa) wa.href = w.wa_link;
                document.getElementById('winModal').classList.add('show');
                confetti();
            });
        })
        .catch(function (err) {
            alert(err.message || 'The draw failed. Please try again.');
            spinning = false; btn.classList.remove('loading');
        });
}

document.getElementById('spinBtn').addEventListener('click', startSpin);
document.getElementById('hubBtn').addEventListener('click', startSpin);
document.getElementById('wClose').addEventListener('click', function () { location.reload(); });
document.getElementById('prizeSelect').addEventListener('change', function () {
    document.getElementById('customPrizeField').classList.toggle('hidden', this.value !== '__custom');
});
document.getElementById('excludeWinners').addEventListener('change', refresh);

var table = document.getElementById('winnersTable');
if (table) table.addEventListener('click', function (e) {
    var del = e.target.closest('[data-delete]');
    if (del && confirm('Remove this winner? Their entry goes back into the draw.')) {
        api({ action: 'delete', id: +del.dataset.delete }).then(function () { location.reload(); });
    }
});

document.getElementById('fullscreenBtn').addEventListener('click', function () {
    var el = document.getElementById('stage');
    if (!document.fullscreenElement) { el.requestFullscreen && el.requestFullscreen(); } else { document.exitFullscreen(); }
});

document.fonts && document.fonts.ready.then(draw);
refresh();
</script>
<?php admin_footer(); ?>
