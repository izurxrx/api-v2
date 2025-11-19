<?php

/**
 * Test Extension API with Facility/Rate/Discount Selection
 * 
 * Run this script: php test_extension_api.php
 */

require __DIR__ . '/vendor/autoload.php';

$baseUrl = 'http://localhost:8000/api';
$token = 'YOUR_AUTH_TOKEN'; // Replace with actual token

echo "🧪 Testing Enhanced Extension API\n";
echo "==================================\n\n";

// Test Data
$billingId = 1; // Replace with actual billing ID

// ========================================
// TEST 1: Add Facility Extension (Smart Mode)
// ========================================
echo "TEST 1: Add Facility Extension with Rate Selection\n";
echo "---------------------------------------------------\n";

$facilityExtension = [
    'facilities' => [
        [
            'facility_id' => 1,
            'rate_id' => 1,
            'quantity' => 1,
            'hours' => 4 // Optional: for hourly rates
        ]
    ],
    'discount_mode' => 'None',
    'payment_required' => true,
    'payment_amount' => 1500.00,
    'payment_method' => 'Cash'
];

echo "Request:\n";
echo json_encode($facilityExtension, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/billings/{$billingId}/add-extension");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$token}"
    ],
    CURLOPT_POSTFIELDS => json_encode($facilityExtension)
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response ({$statusCode}):\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// ========================================
// TEST 2: Add Guest Extension with Discount
// ========================================
echo "TEST 2: Add Guest Extension with Senior Discount\n";
echo "--------------------------------------------------\n";

$guestExtension = [
    'guest_charges' => [
        [
            'guest_type' => 'senior',
            'count' => 2,
            'rate_per_guest' => 350.00,
            'discount_id' => 1 // Senior citizen discount
        ]
    ],
    'discount_mode' => 'Direct'
];

echo "Request:\n";
echo json_encode($guestExtension, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/billings/{$billingId}/add-extension");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$token}"
    ],
    CURLOPT_POSTFIELDS => json_encode($guestExtension)
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response ({$statusCode}):\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// ========================================
// TEST 3: Add Multiple Extensions with Seasonal Discount
// ========================================
echo "TEST 3: Add Multiple Extensions with Seasonal Discount\n";
echo "-------------------------------------------------------\n";

$multipleExtensions = [
    'facilities' => [
        [
            'facility_id' => 2,
            'rate_id' => 3,
            'quantity' => 1
        ]
    ],
    'third_party_services' => [
        [
            'service_name' => 'Videography (3 hours)',
            'amount' => 2400.00
        ]
    ],
    'discount_mode' => 'Seasonal',
    'discount_id' => 5, // Holiday promo
    'payment_required' => false
];

echo "Request:\n";
echo json_encode($multipleExtensions, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/billings/{$billingId}/add-extension");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$token}"
    ],
    CURLOPT_POSTFIELDS => json_encode($multipleExtensions)
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response ({$statusCode}):\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

// ========================================
// TEST 4: Simple Mode (Damage Charge)
// ========================================
echo "TEST 4: Simple Mode - Damage Charge\n";
echo "------------------------------------\n";

$damageCharge = [
    'extension_type' => 'damage',
    'description' => 'Broken cottage window',
    'amount' => 2500.00,
    'quantity' => 1,
    'metadata' => [
        'item_damaged' => 'Window',
        'location' => 'Cottage A'
    ]
];

echo "Request:\n";
echo json_encode($damageCharge, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init("{$baseUrl}/billings/{$billingId}/add-extension");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer {$token}"
    ],
    CURLOPT_POSTFIELDS => json_encode($damageCharge)
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response ({$statusCode}):\n";
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n\n";

echo "✅ All tests completed!\n";
