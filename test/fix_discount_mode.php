<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Updating guest_entries discount_mode column...\n";

DB::statement("
    ALTER TABLE guest_entries 
    MODIFY COLUMN discount_mode ENUM('None', 'Seasonal', 'Manual', 'Direct') 
    DEFAULT 'None'
");

echo "✅ Successfully updated guest_entries.discount_mode to include 'Direct'\n\n";

// Verify
$columns = DB::select("SHOW COLUMNS FROM guest_entries WHERE Field = 'discount_mode'");
echo "Verification:\n";
print_r($columns[0]);
