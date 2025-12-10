<?php

/**
 * Test Check-in Billing Response
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\GuestEntry;
use App\Http\Resources\GuestEntryResource;
use Illuminate\Http\Request;

echo "=================================================================\n";
echo "Testing Guest Entry Billing in API Response\n";
echo "=================================================================\n\n";

try {
    // Get guest entry #90
    $guestEntry = GuestEntry::with([
        'booking',
        'entranceRate',
        'guestDetails',
        'facilities.facility',
        'billing.payments',
        'createdBy',
    ])->find(90);

    if (!$guestEntry) {
        echo "❌ Guest entry not found\n";
        exit(1);
    }

    echo "Guest Entry Found:\n";
    echo "  ID: {$guestEntry->id}\n";
    echo "  Reference: {$guestEntry->entry_reference}\n";
    echo "  Entry Type: {$guestEntry->entry_type}\n";
    echo "  Booking ID: {$guestEntry->booking_id}\n\n";

    echo "Billing Check:\n";
    if ($guestEntry->billing) {
        echo "  ✅ Billing loaded\n";
        echo "     Billing ID: {$guestEntry->billing->id}\n";
        echo "     Billing Number: {$guestEntry->billing->billing_number}\n";
        echo "     Total: ₱" . number_format($guestEntry->billing->total_amount, 2) . "\n";
        echo "     Paid: ₱" . number_format($guestEntry->billing->amount_paid, 2) . "\n";
        echo "     Status: {$guestEntry->billing->payment_status}\n";

        if ($guestEntry->billing->payments) {
            echo "     Payments: {$guestEntry->billing->payments->count()}\n";
        }
    } else {
        echo "  ❌ Billing is NULL\n";
    }
    echo "\n";

    // Create API resource
    echo "Creating API Resource...\n";
    $request = Request::create('/test', 'GET');
    $resource = new GuestEntryResource($guestEntry);
    $response = $resource->toArray($request);

    echo "API Response Keys:\n";
    foreach (array_keys($response) as $key) {
        echo "  - {$key}\n";
    }
    echo "\n";

    // Check billing in response
    echo "Billing in Response:\n";
    if (isset($response['billing']) && $response['billing']) {
        echo "  ✅ Billing included in response\n";
        echo json_encode($response['billing'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "  ❌ Billing NOT included or is null\n";
        echo "  billing value: " . json_encode($response['billing'] ?? 'key not set') . "\n";
    }
    echo "\n";

    // Check payment-related fields
    echo "Payment Fields in Response:\n";
    echo "  payment_status: {$response['payment_status']}\n";
    echo "  amount_paid: {$response['amount_paid']}\n";
    echo "  balance: {$response['balance']}\n";
    echo "  payment_method: " . ($response['payment_method'] ?? 'null') . "\n";
    echo "\n";

    echo "=================================================================\n";
    echo "RESULT\n";
    echo "=================================================================\n\n";

    if (isset($response['billing']) && $response['billing']) {
        echo "✅ SUCCESS! Billing is included in the API response.\n";
        echo "\nThe frontend will now receive:\n";
        echo "  - Full billing information\n";
        echo "  - Payment history\n";
        echo "  - Payment status\n";
    } else {
        echo "❌ ISSUE: Billing is still not appearing in API response.\n";
        echo "\nPossible causes:\n";
        echo "  - Resource not loading the relationship properly\n";
        echo "  - whenLoaded() condition not met\n";
    }

} catch (\Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
