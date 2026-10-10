<?php
// admin_customers.php - Customer list with search, winner filter and entry details
session_start();
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'admin_layout.php';
requireAdminLogin();

$search = trim($_GET['search'] ?? '');
$filter = ($_GET['filter'] ?? '') === 'winners' ? 'winners' : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;

$conditions = [];
$params = [];
if ($search !== '') {
    // Phone numbers are stored as 60XXXXXXXXX, so match "012 345..." by its digits without the leading 0
    $isPhone = preg_match('/^[\d\s+\-()]+$/', $search);
    $phoneSearch = $isPhone ? '%' . ltrim(preg_replace('/\D+/', '', $search), '0') . '%' : "%$search%";
    $conditions[] = "(c.customer_id LIKE ? OR c.customer_name LIKE ? OR c.customer_phone LIKE ?)";
    array_push($params, "%$search%", "%$search%", $phoneSearch);
}
if ($filter === 'winners') {
    $conditions[] = "c.customer_id IN (SELECT customer_id FROM winners)";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM lucky_customers c $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT c.*, (SELECT GROUP_CONCAT(w.prize SEPARATOR ', ') FROM winners w WHERE w.customer_id = c.customer_id) AS prizes,
           (SELECT GROUP_CONCAT(e.purchase_id ORDER BY e.entry_date SEPARATOR ', ') FROM lucky_draw_entries e WHERE e.customer_id = c.customer_id) AS codes,
           EXISTS (SELECT 1 FROM winners w WHERE w.customer_id = c.customer_id) AS won
    FROM lucky_customers c $where
    ORDER BY c.total_entries DESC, c.first_entry_date ASC
    LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$settings = get_settings($pdo);
$waButtons = $settings['wa_buttons'] === '1';
$allCount = (int)$pdo->query("SELECT COUNT(*) FROM lucky_customers")->fetchColumn();
$winnerCount = (int)$pdo->query("SELECT COUNT(DISTINCT customer_id) FROM winners")->fetchColumn();
$entryCount = (int)$pdo->query("SELECT COUNT(*) FROM lucky_draw_entries")->fetchColumn();

$qs = function ($overrides) use ($search, $filter) {
    return '?' . http_build_query(array_filter(array_merge(['search' => $search, 'filter' => $filter === 'all' ? '' : $filter], $overrides)));
};

admin_header('customers');
page_head(
    'Customers',
    plural($allCount, 'customer', 'customers') . ' · ' . plural($entryCount, 'entry', 'entries') . '. Click a customer to see their product codes.',
    '<a class="btn" href="admin_lucky_export.php">' . icon('download') . 'Export CSV</a>'
);
?>
<div class="btn-row" style="justify-content:space-between">
    <div class="tabs">
        <a href="<?php echo e($qs(['filter' => '', 'page' => ''])); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All <span class="faint"><?php echo $allCount; ?></span></a>
        <a href="<?php echo e($qs(['filter' => 'winners', 'page' => ''])); ?>" class="<?php echo $filter === 'winners' ? 'active' : ''; ?>">Winners <span class="faint"><?php echo $winnerCount; ?></span></a>
    </div>
    <form method="GET" class="search-box" style="flex:1;max-width:340px;min-width:220px">
        <?php echo icon('search'); ?>
        <?php if ($filter === 'winners'): ?><input type="hidden" name="filter" value="winners"><?php endif; ?>
        <input type="search" name="search" placeholder="Search name, phone or customer ID" value="<?php echo e($search); ?>" autocomplete="off">
    </form>
</div>

<div class="card card-flush">
    <?php if (!$customers): ?>
        <div class="empty"><?php echo icon('users'); ?>
            <p><?php echo $search !== '' ? 'No customers match "' . e($search) . '".' : ($filter === 'winners' ? 'No winners yet.' : 'No customers yet.'); ?></p>
            <?php if ($search === '' && $filter === 'all'): ?><p class="small faint">Customers appear here after their first registration.</p><?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Customer</th><th class="hide-xs">Phone</th><th class="num">Entries</th><th class="hide-sm">First entry</th><th class="hide-sm">Last entry</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <tr class="expandable" data-customer="<?php echo e($c['customer_id']); ?>">
                    <td class="nowrap"><?php echo icon('chevron', 'chev'); ?><?php echo e($c['customer_name']); ?>
                        <?php if ($c['won']): ?> <span class="badge badge-warning plain" title="<?php echo e($c['prizes']); ?>">Winner</span><?php endif; ?>
                        <div class="sub-line mono" style="padding-left:22px"><?php echo e($c['customer_id']); ?><span class="show-xs"> · <?php echo e(format_phone($c['customer_phone'])); ?></span></div></td>
                    <td class="mono nowrap hide-xs"><?php echo e(format_phone($c['customer_phone'])); ?></td>
                    <td class="num"><?php echo (int)$c['total_entries']; ?></td>
                    <td class="muted nowrap hide-sm"><?php echo e(format_datetime($c['first_entry_date'])); ?></td>
                    <td class="muted nowrap hide-sm"><?php echo e(format_datetime($c['last_entry_date'])); ?></td>
                    <td class="num"><?php if ($waButtons): ?><a class="btn btn-sm" target="_blank" rel="noopener" title="Send entry confirmation on WhatsApp" href="<?php echo e(wa_link($c['customer_phone'], build_message($settings, 'msg_confirm', [
                        'name' => $c['customer_name'], 'customer_id' => $c['customer_id'], 'product_code' => $c['codes'], 'entries' => $c['total_entries'],
                    ]))); ?>"><?php echo icon('chat', 'icon-wa'); ?><span class="hide-xs">WhatsApp</span></a><?php endif; ?></td>
                </tr>
                <tr class="detail" data-detail="<?php echo e($c['customer_id']); ?>"><td colspan="6"><div class="entry-list faint">Loading…</div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-foot">
        <span class="small faint">Showing <?php echo $offset + 1; ?>–<?php echo $offset + count($customers); ?> of <?php echo number_format($total); ?></span>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?><span class="current"><?php echo $i; ?></span>
                    <?php else: ?><a href="<?php echo e($qs(['page' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

document.querySelectorAll('tr.expandable').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        var id = row.dataset.customer;
        var detail = document.querySelector('tr.detail[data-detail="' + CSS.escape(id) + '"]');
        var open = detail.classList.toggle('show');
        row.classList.toggle('open', open);
        if (!open || detail.dataset.loaded) return;
        detail.dataset.loaded = 1;
        var box = detail.querySelector('.entry-list');
        fetch('admin_get_customer_entries.php?customer_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.entries.length) { box.textContent = 'No product codes found.'; return; }
                box.classList.remove('faint');
                box.innerHTML = data.entries.map(function (en) {
                    return '<div><b class="mono">' + esc(en.purchase_id) + '</b><span>' + esc(en.entry_date) + '</span></div>';
                }).join('');
            })
            .catch(function () { box.textContent = 'Could not load product codes.'; });
    });
});

var searchInput = document.querySelector('.search-box input[type=search]');
var timer;
searchInput.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { searchInput.form.submit(); }, 450);
});
if (searchInput.value) { searchInput.focus(); searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length); }
</script>
<?php admin_footer(); ?>
