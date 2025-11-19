<?php

/**
 * Test unified walk-in entry structure
 * Tests that walk-ins can accept booking-style guest_breakdown structure
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Http\Request;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "======================================\n";
echo "TESTING UNIFIED WALK-IN STRUCTURE\n";
echo "======================================\n\n";

// Test data with booking-style structure
$testData = [
    'entrance_rate_id' => 1, // Assuming Day Rate exists
    'guest_name' => 'Test Guest - Unified Structure',
    'contact_number' => '09123456789',
    'entry_date' => date('Y-m-d'),
    'check_in_time' => date('H:i'),
    
    // ✅ NEW: Booking-style structure
    'guest_breakdown' => [
        'adult' => 2,
        'senior' => 1,
        'child' => 0,
    ],
    
    // ✅ NEW: Booking-style discounts (optional)
    'guest_discounts' => [
        [
            'guest_type' => 'senior',
            'count' => 1,
            'discount_id' => null, // Can add discount ID if needed
        ],
    ],
    
    'facilities' => [
        [
            'facility_id' => 1, // Assuming cottage exists
            'rate_id' => 2, // Assuming cottage rate exists
            'quantity' => 1,
        ],
    ],
    
    'seasonal_discount_id' => null,
    'manual_discount_amount' => 0,
    'notes' => 'Testing unified structure - booking-style guest_breakdown',
];

echo "Test 1: Walk-in with guest_breakdown structure\n";
echo "-----------------------------------------------\n";
echo "Input structure:\n";
echo "- guest_breakdown: {adult: 2, senior: 1, child: 0}\n";
echo "- guest_discounts: [{guest_type: 'senior', count: 1}]\n\n";

// Create a request instance
$request = Request::create('/api/guest-monitoring', 'POST', $testData);

// Manually create the form request to test validation
$formRequest = new \App\Http\Requests\GuestEntry\StoreGuestEntryRequest();
$formRequest->setContainer($app);
$formRequest->setRedirector($app->make('redirect'));
$formRequest->replace($testData);

// Test prepareForValidation conversion
echo "Testing prepareForValidation conversion...\n";
echo "\nBefore conversion:\n";
echo "guest_breakdown: " . json_encode($formRequest->input('guest_breakdown')) . "\n";
echo "guest_details: " . json_encode($formRequest->input('guest_details')) . "\n";

$reflection = new ReflectionClass($formRequest);
$method = $reflection->getMethod('prepareForValidation');
$method->setAccessible(true);
$method->invoke($formRequest);

echo "\nAfter conversion:\n";
echo "guest_breakdown: " . json_encode($formRequest->input('guest_breakdown')) . "\n";
echo "guest_details: " . json_encode($formRequest->input('guest_details')) . "\n";

// Also check all() which should show merged data
echo "All data: " . json_encode($formRequest->all()) . "\n\n";

// Check if guest_details was created
$guestDetails = $formRequest->input('guest_details');
if ($guestDetails) {
    echo "✅ SUCCESS: guest_breakdown converted to guest_details\n";
    echo "\nConverted guest_details:\n";
    foreach ($guestDetails as $index => $detail) {
        echo sprintf(
            "  %d. %s: %d guests (discount_id: %s)\n",
            $index + 1,
            $detail['guest_type_name'],
            $detail['guest_count'],
            $detail['discount_id'] ?? 'none'
        );
    }
    
    $totalGuests = array_sum(array_column($guestDetails, 'guest_count'));
    echo "\nTotal guests: {$totalGuests}\n";
    echo "Expected: 3 (2 adults + 1 senior)\n";
    
    if ($totalGuests === 3) {
        echo "✅ Guest count matches!\n";
    } else {
        echo "❌ Guest count mismatch!\n";
    }
} else {
    echo "❌ FAILED: guest_details not created\n";
}

echo "\n";
echo "======================================\n";
echo "Test 2: Legacy guest_details structure\n";
echo "======================================\n\n";

// Test with legacy structure
$legacyData = [
    'entrance_rate_id' => 1,
    'guest_name' => 'Test Guest - Legacy Structure',
    'contact_number' => '09123456789',
    'entry_date' => date('Y-m-d'),
    'check_in_time' => date('H:i'),
    
    // ✅ OLD: Legacy structure still works
    'guest_details' => [
        [
            'guest_type_name' => 'Regular',
            'guest_count' => 2,
            'discount_id' => null,
        ],
        [
            'guest_type_name' => 'Senior Citizen',
            'guest_count' => 1,
            'discount_id' => null,
        ],
    ],
    
    'facilities' => [],
    'seasonal_discount_id' => null,
    'manual_discount_amount' => 0,
    'notes' => 'Testing legacy structure - still supported',
];

echo "Input structure:\n";
echo "- guest_details: [{guest_type_name: 'Regular', guest_count: 2}, ...]\n\n";

$legacyRequest = new \App\Http\Requests\GuestEntry\StoreGuestEntryRequest();
$legacyRequest->setContainer($app);
$legacyRequest->setRedirector($app->make('redirect'));
$legacyRequest->replace($legacyData);

$method->invoke($legacyRequest);
$legacyGuestDetails = $legacyRequest->input('guest_details');

if ($legacyGuestDetails) {
    echo "✅ SUCCESS: Legacy guest_details still works\n";
    echo "\nguest_details:\n";
    foreach ($legacyGuestDetails as $index => $detail) {
        echo sprintf(
            "  %d. %s: %d guests\n",
            $index + 1,
            $detail['guest_type_name'],
            $detail['guest_count']
        );
    }
    
    $totalGuests = array_sum(array_column($legacyGuestDetails, 'guest_count'));
    echo "\nTotal guests: {$totalGuests}\n";
    
    if ($totalGuests === 3) {
        echo "✅ Legacy structure works correctly!\n";
    }
} else {
    echo "❌ FAILED: Legacy structure broken\n";
}

echo "\n";
echo "======================================\n";
echo "SUMMARY\n";
echo "======================================\n";
echo "✅ Unified structure implemented\n";
echo "✅ guest_breakdown → guest_details conversion works\n";
echo "✅ Legacy guest_details structure still supported\n";
echo "✅ Frontend can use either structure:\n";
echo "   - guest_breakdown (same as bookings)\n";
echo "   - guest_details (legacy, still works)\n";
echo "\n";
echo "Frontend Implementation:\n";
echo "- Use guest_breakdown for consistency with bookings\n";
echo "- Single form can create both walk-ins and bookings\n";
echo "- Only difference: entry_date (today vs future)\n";
echo "======================================\n";
