<?php
// admin_lucky_export.php - Grouped by Customer
session_start();
require_once 'config.php';
require_once 'admin_auth.php';

// Require admin login
requireAdminLogin();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lucky_draw_export_grouped_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers for grouped data
fputcsv($output, [
    'Customer ID',
    'Customer Name',
    'Phone Number',
    'Total Entries',
    'First Entry Date',
    'Last Entry Date',
    'Products (Purchase IDs)',
    'Entry Dates',
    'Prizes Won'
]);

// Get all customers with their entries grouped
$stmt = $pdo->query("
    SELECT 
        c.customer_id,
        c.customer_name,
        c.customer_phone,
        c.total_entries,
        c.first_entry_date,
        c.last_entry_date,
        GROUP_CONCAT(DISTINCT e.purchase_id ORDER BY e.entry_date SEPARATOR '; ') as purchase_ids,
        GROUP_CONCAT(DISTINCT DATE_FORMAT(e.entry_date, '%d/%m/%Y %H:%i') ORDER BY e.entry_date SEPARATOR '; ') as entry_dates,
        (SELECT GROUP_CONCAT(w.prize SEPARATOR '; ') FROM winners w WHERE w.customer_id = c.customer_id) as prizes
    FROM lucky_customers c
    LEFT JOIN lucky_draw_entries e ON c.customer_id = e.customer_id
    GROUP BY c.customer_id, c.customer_name, c.customer_phone, c.total_entries, c.first_entry_date, c.last_entry_date
    ORDER BY c.total_entries DESC, c.first_entry_date ASC
");

$customers = $stmt->fetchAll();

foreach ($customers as $customer) {
    fputcsv($output, [
        $customer['customer_id'],
        $customer['customer_name'],
        format_phone($customer['customer_phone']),
        $customer['total_entries'],
        date('d/m/Y H:i:s', strtotime($customer['first_entry_date'])),
        date('d/m/Y H:i:s', strtotime($customer['last_entry_date'])),
        $customer['purchase_ids'] ?? '',
        $customer['entry_dates'] ?? '',
        $customer['prizes'] ?? ''
    ]);
}

fclose($output);
exit;
?>