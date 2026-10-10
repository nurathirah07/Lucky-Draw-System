<?php
// admin_draw_api.php - Draws winners for the spin wheel (JSON API)
session_start();
require_once 'config.php';
require_once 'admin_auth.php';

header('Content-Type: application/json');

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!isAdminLoggedIn()) respond(['success' => false, 'message' => 'Please log in again.'], 401);

// JSON-only requests: other websites cannot send these with the admin's cookies (CSRF protection)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    respond(['success' => false, 'message' => 'Invalid request.'], 400);
}
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$settings = get_settings($pdo);

function winner_payload($w, $settings) {
    return [
        'id' => (int)$w['id'],
        'customer_id' => $w['customer_id'],
        'customer_name' => $w['customer_name'],
        'phone' => format_phone($w['customer_phone']),
        'purchase_id' => $w['purchase_id'],
        'prize' => $w['prize'],
        'drawn_at' => format_datetime($w['drawn_at']),
        'wa_link' => wa_link($w['customer_phone'], winner_message($w, $settings)),    ];
}

switch ($action) {
    case 'draw':
        $prize = mb_substr(trim($input['prize'] ?? ''), 0, 150);
        $exclude = !empty($input['exclude_winners']);

        // Every entry (product) is one ticket; an entry can never win twice
        $where = "e.id NOT IN (SELECT entry_id FROM winners)";
        if ($exclude) $where .= " AND e.customer_id NOT IN (SELECT customer_id FROM winners)";

        $count = (int)$pdo->query("SELECT COUNT(*) FROM lucky_draw_entries e WHERE $where")->fetchColumn();
        if ($count === 0) respond(['success' => false, 'message' => 'No eligible entries left to draw.']);

        $offset = random_int(0, $count - 1);  // cryptographically secure
        $entry = $pdo->query("SELECT e.id, e.customer_id, e.purchase_id, c.customer_name, c.customer_phone
                              FROM lucky_draw_entries e JOIN lucky_customers c ON c.customer_id = e.customer_id
                              WHERE $where ORDER BY e.id LIMIT 1 OFFSET $offset")->fetch();

        $pdo->prepare("INSERT INTO winners (entry_id, customer_id, customer_name, customer_phone, purchase_id, prize, drawn_by, drawn_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$entry['id'], $entry['customer_id'], $entry['customer_name'], $entry['customer_phone'], $entry['purchase_id'], $prize, $_SESSION['admin_username'] ?? null]);

        $stmt = $pdo->prepare("SELECT * FROM winners WHERE id = ?");
        $stmt->execute([$pdo->lastInsertId()]);
        respond(['success' => true, 'winner' => winner_payload($stmt->fetch(), $settings), 'pool' => $count]);

    case 'delete':
        $pdo->prepare("DELETE FROM winners WHERE id = ?")->execute([(int)($input['id'] ?? 0)]);
        respond(['success' => true]);

    default:
        respond(['success' => false, 'message' => 'Unknown action.'], 400);
}
