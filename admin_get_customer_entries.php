<?php
// admin_get_customer_entries.php
session_start();
require_once 'config.php';
require_once 'admin_auth.php';

// Require admin login
requireAdminLogin();

header('Content-Type: application/json');

$customer_id = isset($_GET['customer_id']) ? trim($_GET['customer_id']) : '';

if (empty($customer_id)) {
    echo json_encode(['success' => false, 'message' => 'Customer ID required']);
    exit;
}

try {
    // Get customer info
    $stmt = $pdo->prepare("SELECT * FROM lucky_customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }
    
    // Get all entries for this customer
    $stmt = $pdo->prepare("SELECT purchase_id, entry_date, ip_address FROM lucky_draw_entries WHERE customer_id = ? ORDER BY entry_date ASC");
    $stmt->execute([$customer_id]);
    $entries = $stmt->fetchAll();
    
    $formattedEntries = [];
    foreach ($entries as $entry) {
        $formattedEntries[] = [
            'purchase_id' => $entry['purchase_id'],
            'entry_date' => format_datetime($entry['entry_date']),
            'ip_address' => $entry['ip_address']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'customer_id' => $customer['customer_id'],
        'customer_name' => $customer['customer_name'],
        'customer_phone' => $customer['customer_phone'],
        'total_entries' => (int)$customer['total_entries'],
        'entries' => $formattedEntries
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>