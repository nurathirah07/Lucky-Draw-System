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

admin_header('Spin & Winners', 'draw');
?>
<div class="page-head">
    <div>
        <h1>Spin the wheel</h1>
        <p>Each registered product is one ticket. The winner is picked securely on the server.</p>
    </div>
    <button class="btn btn-ghost no-print" id="fullscreenBtn">⛶ Full screen</button>
</div>

<div class="wheel-stage" id="stage">
    <div class="panel">
        <div class="wheel-box">
            <div class="wheel-pointer"></div>
            <canvas id="wheel" width="1040" height="1040"></canvas>
            <div class="wheel-hub">SPIN</div>
        </div>
        <div class="text-center mt-3">
            <button class="btn btn-gold" id="spinBtn" style="font-size:18px;padding:16px 44px">🎡 Spin!</button>
            <p class="muted mt-1" style="font-size:13px" id="poolInfo"></p>
        </div>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h2>Draw settings</h2></div>
            <div class="field">
                <label for="prizeSelect">Prize</label>
                <select id="prizeSelect">
                    <?php foreach ($prizes as $prize): ?>
                        <option value="<?php echo e($prize); ?>"<?php echo $prize === $nextPrize ? ' selected' : ''; ?>><?php echo e($prize); ?><?php echo in_array($prize, $awarded, true) ? ' (awarded)' : ''; ?></option>
                    <?php endforeach; ?>
                    <option value="__custom"<?php echo $prizes ? '' : ' selected'; ?>>Other prize…</option>
                </select>
            </div>
            <div class="field<?php echo $prizes ? ' hidden' : ''; ?>" id="customPrizeField">
                <input type="text" id="customPrize" maxlength="150" placeholder="e.g. Baucar RM50">
            </div>
            <label class="check"><input type="checkbox" id="excludeWinners" checked> Exclude customers who have already won</label>
            <?php if (!$prizes): ?><p class="faint mt-2" style="font-size:13px">Tip: add your prize list in <a href="admin_settings.php">Campaign settings</a>.</p><?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head"><div><h2>Winners</h2><p><?php echo count($winners); ?> drawn</p></div></div>
            <div class="list" id="winnersList">
                <?php if (!$winners): ?><div class="empty" id="noWinners">No winners yet.</div><?php endif; ?>
                <?php foreach ($winners as $w): ?>
                    <div class="list-item" data-id="<?php echo (int)$w['id']; ?>" style="flex-wrap:wrap">
                        <div style="min-width:0">
                            <strong><?php echo e($w['customer_name']); ?></strong> <span class="badge badge-gold"><?php echo e($w['prize'] ?: 'Prize'); ?></span>
                            <div class="faint" style="font-size:12px"><span class="mono"><?php echo e($w['purchase_id']); ?></span> · <?php echo e(format_phone($w['customer_phone'])); ?> · <?php echo e(format_datetime($w['drawn_at'])); ?></div>
                        </div>
                        <div class="btn-row">
                            <a class="btn btn-wa btn-sm" target="_blank" rel="noopener" href="<?php echo e(wa_link($w['customer_phone'], winner_message($w, $settings))); ?>">💬 Notify</a>
                            <?php if (wa_enabled($wa_template_winner)): ?><button class="btn btn-ghost btn-sm" data-notify="<?php echo (int)$w['id']; ?>">Auto-send</button><?php endif; ?>
                            <button class="btn btn-danger btn-sm" data-delete="<?php echo (int)$w['id']; ?>">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="winModal">
    <div class="modal-card">
        <h2>🏆 We have a winner!</h2>
        <div class="winner-name" id="wName"></div>
        <div class="muted mono" id="wMeta"></div>
        <div class="big-id"><small>Prize</small><strong id="wPrize" style="font-size:20px"></strong></div>
        <div class="btn-row" style="justify-content:center">
            <a class="btn btn-wa" id="wWa" target="_blank" rel="noopener">💬 Notify via WhatsApp</a>
            <button class="btn btn-ghost" id="wClose">Close</button>
        </div>
    </div>
</div>

<script>
var participants = <?php echo json_encode($participants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var MAX_SEGMENTS = 24;
var COLORS = ['#e11d48', '#18181b', '#fbbf24', '#27272a', '#be123c', '#3f3f46'];
var canvas = document.getElementById('wheel');
var ctx = canvas.getContext('2d');
var segments = [];
var rotation = 0;      // radians, clockwise
var spinning = false;

function eligible() {
    var ex = document.getElementById('excludeWinners').checked;
    return participants.filter(function (p) { return !(ex && p.won); });
}

function shuffle(a) {
    for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; }
    return a;
}

// Build wheel segments. Small draws: slice size = number of entries.
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
        ctx.fillStyle = '#71717a'; ctx.font = '600 34px sans-serif'; ctx.textAlign = 'center';
        ctx.fillText('No eligible entries', 0, -r / 2);
        ctx.restore(); return;
    }
    segments.forEach(function (s, i) {
        var a0 = s.start - Math.PI / 2, a1 = s.end - Math.PI / 2;
        var color = COLORS[i % COLORS.length];
        if (segments.length > 1 && i === segments.length - 1 && color === COLORS[0]) color = COLORS[4];
        ctx.beginPath(); ctx.moveTo(0, 0); ctx.arc(0, 0, r, a0, a1); ctx.closePath();
        ctx.fillStyle = color; ctx.fill();
        if (segments.length > 1) { ctx.strokeStyle = 'rgba(0,0,0,.35)'; ctx.lineWidth = 3; ctx.stroke(); }

        var mid = (a0 + a1) / 2, arc = s.end - s.start;
        ctx.save();
        ctx.rotate(mid);
        ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        ctx.fillStyle = color === '#fbbf24' ? '#1c1917' : '#fafafa';
        var fs = Math.max(16, Math.min(36, arc * r * 0.45));
        ctx.font = '700 ' + fs + 'px "Plus Jakarta Sans", sans-serif';
        var label = s.name.length > 16 ? s.name.slice(0, 15) + '…' : s.name;
        if (arc * r > 18) ctx.fillText(label, r - 34, 0);
        ctx.restore();
    });
    // rim dots
    for (var d = 0; d < 36; d++) {
        var a = d / 36 * Math.PI * 2;
        ctx.beginPath(); ctx.arc(Math.cos(a) * (r - 12), Math.sin(a) * (r - 12), 5, 0, Math.PI * 2);
        ctx.fillStyle = d % 2 ? '#fbbf24' : '#fff7ed'; ctx.fill();
    }
    ctx.restore();
}

function segmentAtPointer() {
    var a = ((-rotation) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
    for (var i = 0; i < segments.length; i++) if (a >= segments[i].start && a < segments[i].end) return i;
    return -1;
}

function updatePool() {
    var list = eligible();
    var tickets = list.reduce(function (s, p) { return s + p.entries; }, 0);
    document.getElementById('poolInfo').textContent =
        list.length + (list.length === 1 ? ' customer · ' : ' customers · ') + tickets + (tickets === 1 ? ' ticket' : ' tickets') +
        (list.length > MAX_SEGMENTS ? ' · wheel shows ' + MAX_SEGMENTS + ' random names' : '');
    document.getElementById('spinBtn').disabled = !list.length;
}

function refresh() { if (spinning) return; buildSegments(eligible()); updatePool(); draw(); }

// Tick sound
var audio;
function tick() {
    try {
        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
        var o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = 1400; o.type = 'square';
        g.gain.setValueAtTime(0.06, audio.currentTime);
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
        var eased = 1 - Math.pow(1 - t, 4);
        rotation = from + delta * eased;
        draw();
        var seg = segmentAtPointer();
        if (seg !== last) { tick(); last = seg; }
        if (t < 1) requestAnimationFrame(frame); else done();
    }
    requestAnimationFrame(frame);
}

function confetti() {
    var colors = ['#e11d48', '#fbbf24', '#fb7185', '#22c55e', '#60a5fa', '#fff'];
    for (var i = 0; i < 160; i++) {
        var c = document.createElement('div');
        c.className = 'confetti';
        c.style.left = Math.random() * 100 + 'vw';
        c.style.background = colors[i % colors.length];
        document.body.appendChild(c);
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

document.getElementById('spinBtn').addEventListener('click', function () {
    if (spinning) return;
    var sel = document.getElementById('prizeSelect').value;
    var prize = sel === '__custom' ? document.getElementById('customPrize').value.trim() : sel;
    var btn = this;
    spinning = true; btn.classList.add('loading');
    api({ action: 'draw', prize: prize, exclude_winners: document.getElementById('excludeWinners').checked })
        .then(function (res) {
            if (!res.success) throw new Error(res.message);
            btn.classList.remove('loading'); btn.disabled = true;
            spinTo(res.winner.customer_id, function () {
                var w = res.winner;
                document.getElementById('wName').textContent = w.customer_name;
                document.getElementById('wMeta').textContent = w.customer_id + ' · ' + w.purchase_id + ' · ' + w.phone;
                document.getElementById('wPrize').textContent = w.prize || 'Lucky draw prize';
                document.getElementById('wWa').href = w.wa_link;
                document.getElementById('winModal').classList.add('show');
                confetti();
            });
        })
        .catch(function (err) {
            alert(err.message || 'Draw failed.');
            spinning = false; btn.classList.remove('loading');
        });
});

document.getElementById('wClose').addEventListener('click', function () { location.reload(); });

document.getElementById('prizeSelect').addEventListener('change', function () {
    document.getElementById('customPrizeField').classList.toggle('hidden', this.value !== '__custom');
});
document.getElementById('excludeWinners').addEventListener('change', refresh);

document.getElementById('winnersList').addEventListener('click', function (e) {
    var del = e.target.closest('[data-delete]'), notify = e.target.closest('[data-notify]');
    if (del && confirm('Remove this winner? Their entry goes back into the draw.')) {
        api({ action: 'delete', id: +del.dataset.delete }).then(function () { location.reload(); });
    }
    if (notify) {
        notify.classList.add('loading');
        api({ action: 'notify', id: +notify.dataset.notify }).then(function (res) {
            notify.classList.remove('loading'); alert(res.message);
        });
    }
});

document.getElementById('fullscreenBtn').addEventListener('click', function () {
    var el = document.getElementById('stage');
    if (!document.fullscreenElement) { el.requestFullscreen && el.requestFullscreen(); } else { document.exitFullscreen(); }
});

document.fonts && document.fonts.ready.then(draw);
refresh();
</script>
<style>
#stage:fullscreen { background: var(--bg); padding: 32px; overflow: auto; }
</style>
<?php admin_footer(); ?>
