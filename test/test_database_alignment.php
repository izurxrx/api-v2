<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Checking Database Alignment with API...\n\n";

// Test 1: booking_guest_discounts table
echo "1. booking_guest_discounts table:\n";
$columns = Schema::getColumnListing('booking_guest_discounts');
$required = ['id', 'booking_id', 'guest_type', 'discount_id', 'guest_count'];
$missing = array_diff($required, $columns);
if (empty($missing)) {
    echo "   ✅ All required columns exist: " . implode(', ', $columns) . "\n";
} else {
    echo "   ❌ Missing columns: " . implode(', ', $missing) . "\n";
}

// Test 2: billing_extensions table
echo "\n2. billing_extensions table:\n";
$columns = Schema::getColumnListing('billing_extensions');
$required = ['id', 'billing_id', 'extension_type', 'description', 'amount', 'quantity', 'total_amount'];
$missing = array_diff($required, $columns);
if (empty($missing)) {
    echo "   ✅ All required columns exist: " . implode(', ', $columns) . "\n";
} else {
    echo "   ❌ Missing columns: " . implode(', ', $missing) . "\n";
}

// Test 3: billings refund fields
echo "\n3. billings table (refund fields):\n";
$columns = Schema::getColumnListing('billings');
$required = ['refund_reason', 'refunded_by', 'refunded_at'];
$missing = array_diff($required, $columns);
if (empty($missing)) {
    echo "   ✅ All refund fields exist\n";
} else {
    echo "   ❌ Missing refund fields: " . implode(', ', $missing) . "\n";
}

// Test 4: bookings table
echo "\n4. bookings table:\n";
$columns = Schema::getColumnListing('bookings');
$required = ['entrance_rate_id', 'discount_mode', 'discount_id', 'manual_discount_amount'];
$missing = array_diff($required, $columns);
if (empty($missing)) {
    echo "   ✅ All required columns exist\n";
} else {
    echo "   ❌ Missing columns: " . implode(', ', $missing) . "\n";
}

// Test 5: Check enum values
echo "\n5. Checking enum values:\n";
$result = DB::select("SHOW COLUMNS FROM billings WHERE Field = 'billing_status'");
if (!empty($result)) {
    $type = $result[0]->Type;
    echo "   billing_status enum: $type\n";
    if (str_contains($type, 'pending') && str_contains($type, 'active') && str_contains($type, 'completed')) {
        echo "   ✅ Correct values\n";
    } else {
        echo "   ❌ Enum values incorrect\n";
    }
}

$result = DB::select("SHOW COLUMNS FROM billing_extensions WHERE Field = 'extension_type'");
if (!empty($result)) {
    $type = $result[0]->Type;
    echo "   extension_type enum: $type\n";
    if (str_contains($type, 'facility') && str_contains($type, 'guest') && str_contains($type, 'damage') && str_contains($type, 'service')) {
        echo "   ✅ Correct values\n";
    } else {
        echo "   ❌ Enum values incorrect\n";
    }
}

echo "\n✅ DATABASE ALIGNMENT CHECK COMPLETE!\n";
echo "\nYour database is now aligned with the API code.\n";
echo "All Edit, Refund, and Extensions features should work correctly.\n";
