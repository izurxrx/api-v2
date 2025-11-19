<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Check guest_entries discount_mode column
$columns = DB::select("SHOW COLUMNS FROM guest_entries WHERE Field = 'discount_mode'");

echo "=== GUEST_ENTRIES discount_mode column ===\n";
print_r($columns);

// Check bookings discount_mode column
$bookingColumns = DB::select("SHOW COLUMNS FROM bookings WHERE Field = 'discount_mode'");

echo "\n=== BOOKINGS discount_mode column ===\n";
print_r($bookingColumns);
