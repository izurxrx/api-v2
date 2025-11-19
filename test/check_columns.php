<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;

echo "Checking actual column names...\n\n";

echo "USERS TABLE:\n";
print_r(Schema::getColumnListing('users'));

echo "\nFACILITIES TABLE:\n";
print_r(Schema::getColumnListing('facilities'));

echo "\nPAYMENTS TABLE:\n";
print_r(Schema::getColumnListing('payments'));

echo "\nDISCOUNTS TABLE:\n";
print_r(Schema::getColumnListing('discounts'));
