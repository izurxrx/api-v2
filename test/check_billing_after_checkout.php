<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== BILLING STATUS ENUM ===\n";
$col = DB::selectOne("SHOW COLUMNS FROM billings WHERE Field = 'billing_status'");
echo $col->Type . "\n\n";

echo "=== PAYMENT STATUS ENUM ===\n";
$col2 = DB::selectOne("SHOW COLUMNS FROM billings WHERE Field = 'payment_status'");
echo $col2->Type . "\n\n";

echo "=== Sample Walk-in with Billing ===\n";
$sample = DB::selectOne("
    SELECT 
        ge.id as guest_entry_id,
        ge.entry_reference,
        ge.is_checked_out,
        ge.checkout_datetime,
        b.id as billing_id,
        b.billing_status,
        b.payment_status,
        b.total_amount,
        b.amount_paid,
        b.balance
    FROM guest_entries ge
    LEFT JOIN billings b ON b.billable_type = 'App\\\\Models\\\\GuestEntry' 
        AND b.billable_id = ge.id
    WHERE ge.entry_type = 'walk_in'
    ORDER BY ge.id DESC
    LIMIT 1
");

if ($sample) {
    print_r($sample);
} else {
    echo "No walk-in entries found\n";
}
