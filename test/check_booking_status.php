<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$col = DB::selectOne("SHOW COLUMNS FROM bookings WHERE Field = 'booking_status'");
echo "Booking Status Enum Values:\n";
echo $col->Type . "\n";
