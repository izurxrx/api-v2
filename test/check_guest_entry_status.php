<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Checking guest_entries table for status columns ===\n\n";

$columns = DB::select("SHOW COLUMNS FROM guest_entries LIKE '%status%'");

if (empty($columns)) {
    echo "❌ No status column found in guest_entries table\n\n";
} else {
    foreach($columns as $col) {
        echo "✅ Found: {$col->Field}\n";
        echo "   Type: {$col->Type}\n";
        echo "   Default: {$col->Default}\n";
        echo "   Null: {$col->Null}\n\n";
    }
}

echo "=== Checking guest_entries for is_checked_out ===\n\n";
$checkoutCol = DB::select("SHOW COLUMNS FROM guest_entries WHERE Field = 'is_checked_out'");
foreach($checkoutCol as $col) {
    echo "✅ Found: {$col->Field}\n";
    echo "   Type: {$col->Type}\n";
    echo "   Default: {$col->Default}\n\n";
}

echo "=== Sample walk-in guest entry ===\n";
$sample = DB::selectOne("
    SELECT id, entry_reference, entry_type, is_checked_out, checkout_datetime 
    FROM guest_entries 
    WHERE entry_type = 'Walk_In' 
    LIMIT 1
");
if ($sample) {
    print_r($sample);
} else {
    echo "No walk-in entries found\n";
}
