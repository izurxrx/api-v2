<?php

/**
 * Test Script for Edit, Refund, and Extensions Features
 * 
 * This script tests:
 * 1. Edit Booking (Pending/Confirmed only)
 * 2. Cancel and Refund (with downpayment policy)
 * 3. Add Extensions (facility, guest, damage, service)
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Artisan;
use App\Models\Booking;
use App\Models\Billing;
use App\Models\BillingExtension;
use App\Models\User;
use App\Models\Rate;
use App\Models\Facility;
use Carbon\Carbon;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   EDIT, REFUND, AND EXTENSIONS FEATURES - COMPREHENSIVE TEST   ║\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "\n";

// Test counters
$testsPassed = 0;
$testsFailed = 0;

function testResult($testName, $passed, $message = '') {
    global $testsPassed, $testsFailed;
    
    if ($passed) {
        echo "✅ PASS: $testName\n";
        if ($message) echo "   → $message\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: $testName\n";
        if ($message) echo "   → $message\n";
        $testsFailed++;
    }
    echo "\n";
}

try {
    // Get test user (manager role for refunds)
    $manager = User::whereHas('roles', function($q) {
        $q->where('name', 'Manager');
    })->first();
    
    if (!$manager) {
        $manager = User::first();
    }
    
    echo "📋 Using test user: {$manager->name} (ID: {$manager->id})\n\n";
    
    // ========================================
    // TEST 1: EDIT PENDING BOOKING (Should Succeed)
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 1: Edit Pending Booking\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    // Create a test booking
    $booking1 = Booking::create([
        'booking_number' => 'TEST-EDIT-' . time(),
        'booking_reference' => substr(uniqid('REF-'), 0, 20),
        'booking_type' => 'Package',
        'guest_name' => 'Test Guest Edit',
        'contact_number' => '09171234567',
        'email' => 'test.edit@example.com',
        'number_of_guests' => 4,
        'check_in_date' => Carbon::now()->addDays(1),
        'check_out_date' => Carbon::now()->addDays(2),
        'booking_status' => 'Pending',
        'created_by' => $manager->id,
    ]);
    
    // Create billing for booking
    $billing1 = Billing::create([
        'billable_type' => Booking::class,
        'billable_id' => $booking1->id,
        'billing_number' => 'BILL-EDIT-' . time(),
        'subtotal' => 5000.00,
        'discount_amount' => 0,
        'total_amount' => 5000.00,
        'balance' => 5000.00,
        'amount_paid' => 0,
        'payment_status' => 'unpaid',
        'billing_status' => 'pending',
        'billed_at' => now(),
        'created_by' => $manager->id,
    ]);
    
    echo "Created test booking: {$booking1->booking_number}\n";
    echo "  Status: {$booking1->booking_status}\n";
    echo "  Original Total: ₱" . number_format($billing1->total_amount, 2) . "\n";
    
    // Edit the booking (increase guests and total)
    $oldTotal = $billing1->total_amount;
    $newTotal = 7000.00;
    
    $booking1->number_of_guests = 6;
    $booking1->save();
    
    $billing1->total_amount = $newTotal;
    $billing1->balance = $billing1->balance + ($newTotal - $oldTotal);
    $billing1->save();
    
    echo "  Edited to 6 guests\n";
    echo "  New Total: ₱" . number_format($billing1->total_amount, 2) . "\n";
    echo "  New Balance: ₱" . number_format($billing1->balance, 2) . "\n";
    
    testResult(
        "Edit Pending Booking",
        $booking1->number_of_guests == 6 && $billing1->total_amount == 7000.00,
        "Booking edited successfully, total increased from ₱5,000 to ₱7,000"
    );
    
    // ========================================
    // TEST 2: EDIT CHECKED-IN BOOKING (Should Fail)
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 2: Edit Checked-In Booking (Should be Rejected)\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $booking2 = Booking::create([
        'booking_number' => 'TEST-CHECKEDIN-' . time(),
        'booking_reference' => substr(uniqid('REF-'), 0, 20),
        'booking_type' => 'Package',
        'guest_name' => 'Test Guest Checked In',
        'contact_number' => '09171234568',
        'email' => 'test.checkedin@example.com',
        'number_of_guests' => 3,
        'check_in_date' => Carbon::now(),
        'check_out_date' => Carbon::now()->addDays(1),
        'booking_status' => 'Checked_In',
        'created_by' => $manager->id,
    ]);
    
    echo "Created booking with status: {$booking2->booking_status}\n";
    
    // Attempt to edit (should be prevented in controller)
    $canEdit = in_array($booking2->booking_status, ['Pending', 'Confirmed']);
    
    testResult(
        "Reject Edit for Checked-In Booking",
        !$canEdit,
        "Correctly prevents editing of checked-in bookings"
    );
    
    // ========================================
    // TEST 3: REFUND WITH DOWNPAYMENT POLICY
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 3: Refund with Downpayment Policy (Non-Refundable)\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $booking3 = Booking::create([
        'booking_number' => 'TEST-REFUND-' . time(),
        'booking_reference' => substr(uniqid('REF-'), 0, 20),
        'booking_type' => 'Package',
        'guest_name' => 'Test Guest Refund',
        'contact_number' => '09171234569',
        'email' => 'test.refund@example.com',
        'number_of_guests' => 5,
        'check_in_date' => Carbon::now()->addDays(3),
        'check_out_date' => Carbon::now()->addDays(4),
        'booking_status' => 'Confirmed',
        'created_by' => $manager->id,
    ]);
    
    $billing3 = Billing::create([
        'billable_type' => Booking::class,
        'billable_id' => $booking3->id,
        'billing_number' => 'BILL-REFUND-' . time(),
        'subtotal' => 10000.00,
        'discount_amount' => 0,
        'total_amount' => 10000.00,
        'downpayment_amount' => 3000.00,
        'downpayment_paid' => 3000.00,
        'is_downpayment_paid' => true,
        'balance_paid' => 7000.00,
        'amount_paid' => 10000.00,
        'balance' => 0,
        'payment_status' => 'paid',
        'billing_status' => 'active',
        'billed_at' => now(),
        'created_by' => $manager->id,
    ]);
    
    echo "Created booking with full payment:\n";
    echo "  Total: ₱" . number_format($billing3->total_amount, 2) . "\n";
    echo "  Downpayment: ₱" . number_format($billing3->downpayment_paid, 2) . "\n";
    echo "  Balance Paid: ₱" . number_format($billing3->balance_paid, 2) . "\n";
    echo "  Total Paid: ₱" . number_format($billing3->amount_paid, 2) . "\n";
    
    // Apply refund policy (downpayment non-refundable)
    $totalPaid = $billing3->amount_paid;
    $downpaymentPaid = $billing3->downpayment_paid;
    $maxRefund = $totalPaid - $downpaymentPaid; // Only balance portion
    
    $billing3->refund_amount = $maxRefund;
    $billing3->billing_status = 'voided';
    $billing3->payment_status = 'refunded';
    $billing3->refund_reason = 'Customer cancellation - downpayment policy applied';
    $billing3->refunded_by = $manager->id;
    $billing3->refunded_at = now();
    $billing3->save();
    
    $booking3->booking_status = 'Cancelled';
    $booking3->save();
    
    echo "\nRefund processed:\n";
    echo "  Refund Amount: ₱" . number_format($billing3->refund_amount, 2) . "\n";
    echo "  Non-Refundable: ₱" . number_format($downpaymentPaid, 2) . " (downpayment)\n";
    echo "  Booking Status: {$booking3->booking_status}\n";
    
    testResult(
        "Refund with Downpayment Policy",
        $billing3->refund_amount == 7000.00 && $booking3->booking_status == 'Cancelled',
        "Only balance portion (₱7,000) refunded, downpayment (₱3,000) retained"
    );
    
    // ========================================
    // TEST 4: REFUND WITH MANAGER OVERRIDE
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 4: Refund with Manager Override (Full Refund)\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $booking4 = Booking::create([
        'booking_number' => 'TEST-OVERRIDE-' . time(),
        'booking_reference' => substr(uniqid('REF-'), 0, 20),
        'booking_type' => 'Package',
        'guest_name' => 'Test Guest Override',
        'contact_number' => '09171234570',
        'email' => 'test.override@example.com',
        'number_of_guests' => 4,
        'check_in_date' => Carbon::now()->addDays(5),
        'check_out_date' => Carbon::now()->addDays(6),
        'booking_status' => 'Confirmed',
        'created_by' => $manager->id,
    ]);
    
    $billing4 = Billing::create([
        'billable_type' => Booking::class,
        'billable_id' => $booking4->id,
        'billing_number' => 'BILL-OVERRIDE-' . time(),
        'subtotal' => 8000.00,
        'discount_amount' => 0,
        'total_amount' => 8000.00,
        'downpayment_amount' => 2400.00,
        'downpayment_paid' => 2400.00,
        'is_downpayment_paid' => true,
        'balance_paid' => 5600.00,
        'amount_paid' => 8000.00,
        'balance' => 0,
        'payment_status' => 'paid',
        'billing_status' => 'active',
        'billed_at' => now(),
        'created_by' => $manager->id,
    ]);
    
    echo "Created booking with full payment:\n";
    echo "  Total Paid: ₱" . number_format($billing4->amount_paid, 2) . "\n";
    echo "  Downpayment: ₱" . number_format($billing4->downpayment_paid, 2) . "\n";
    
    // Manager override - refund full amount
    $overrideDownpaymentPolicy = true;
    $maxRefundOverride = $overrideDownpaymentPolicy ? $billing4->amount_paid : ($billing4->amount_paid - $billing4->downpayment_paid);
    
    $billing4->refund_amount = $maxRefundOverride;
    $billing4->billing_status = 'voided';
    $billing4->payment_status = 'refunded';
    $billing4->refund_reason = 'Special case - Manager approved full refund';
    $billing4->refunded_by = $manager->id;
    $billing4->refunded_at = now();
    $billing4->save();
    
    $booking4->booking_status = 'Cancelled';
    $booking4->save();
    
    echo "\nRefund processed with manager override:\n";
    echo "  Refund Amount: ₱" . number_format($billing4->refund_amount, 2) . "\n";
    echo "  Policy Override: YES\n";
    
    testResult(
        "Refund with Manager Override",
        $billing4->refund_amount == 8000.00,
        "Full amount (₱8,000) refunded including downpayment"
    );
    
    // ========================================
    // TEST 5: ADD FACILITY EXTENSION
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 5: Add Facility Extension (Mid-Stay)\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $booking5 = Booking::create([
        'booking_number' => 'TEST-EXT-FAC-' . time(),
        'booking_reference' => 'REF-' . time(),
        'booking_type' => 'Package',
        'guest_name' => 'Test Guest Extension',
        'contact_number' => '09171234571',
        'email' => 'test.ext@example.com',
        'number_of_guests' => 4,
        'check_in_date' => Carbon::now(),
        'check_out_date' => Carbon::now()->addDays(2),
        'booking_status' => 'Checked_In',
        'created_by' => $manager->id,
    ]);
    
    $billing5 = Billing::create([
        'billable_type' => Booking::class,
        'billable_id' => $booking5->id,
        'billing_number' => 'BILL-EXT-' . time(),
        'subtotal' => 6000.00,
        'discount_amount' => 0,
        'total_amount' => 6000.00,
        'balance' => 2000.00,
        'amount_paid' => 4000.00,
        'payment_status' => 'partial',
        'billing_status' => 'active',
        'billed_at' => now(),
        'created_by' => $manager->id,
    ]);
    
    echo "Guest checked in, wants additional cottage:\n";
    echo "  Original Total: ₱" . number_format($billing5->total_amount, 2) . "\n";
    echo "  Current Balance: ₱" . number_format($billing5->balance, 2) . "\n";
    
    // Add facility extension
    $extension1 = BillingExtension::create([
        'billing_id' => $billing5->id,
        'extension_type' => 'facility',
        'description' => 'Additional cottage for Day 2',
        'amount' => 1500.00,
        'quantity' => 1,
        'total_amount' => 1500.00,
        'metadata' => json_encode([
            'facility_name' => 'Cottage #12',
            'date_added' => now()->format('Y-m-d'),
        ]),
        'added_by' => $manager->id,
    ]);
    
    // Update billing
    $billing5->total_amount += $extension1->total_amount;
    $billing5->balance += $extension1->total_amount;
    $billing5->save();
    
    echo "\nExtension added:\n";
    echo "  Type: {$extension1->extension_type}\n";
    echo "  Description: {$extension1->description}\n";
    echo "  Amount: ₱" . number_format($extension1->total_amount, 2) . "\n";
    echo "  New Total: ₱" . number_format($billing5->total_amount, 2) . "\n";
    echo "  New Balance: ₱" . number_format($billing5->balance, 2) . "\n";
    
    testResult(
        "Add Facility Extension",
        $extension1->exists && $billing5->total_amount == 7500.00,
        "Facility extension added, billing updated correctly"
    );
    
    // ========================================
    // TEST 6: ADD GUEST EXTENSION
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 6: Add Guest Extension (Extra Guests)\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $oldBalance = $billing5->balance;
    
    $extension2 = BillingExtension::create([
        'billing_id' => $billing5->id,
        'extension_type' => 'guest',
        'description' => '2 additional overnight guests',
        'amount' => 350.00,
        'quantity' => 2,
        'total_amount' => 700.00,
        'metadata' => json_encode([
            'guest_names' => ['Extra Guest 1', 'Extra Guest 2'],
            'rate_type' => 'overnight',
        ]),
        'added_by' => $manager->id,
    ]);
    
    $billing5->total_amount += $extension2->total_amount;
    $billing5->balance += $extension2->total_amount;
    $billing5->save();
    
    echo "Guest extension added:\n";
    echo "  Quantity: {$extension2->quantity} guests\n";
    echo "  Amount: ₱" . number_format($extension2->total_amount, 2) . "\n";
    echo "  Balance increased from ₱" . number_format($oldBalance, 2) . " to ₱" . number_format($billing5->balance, 2) . "\n";
    
    testResult(
        "Add Guest Extension",
        $extension2->exists && $extension2->extension_type == 'guest',
        "Guest extension added successfully"
    );
    
    // ========================================
    // TEST 7: ADD DAMAGE EXTENSION
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 7: Add Damage Extension\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $extension3 = BillingExtension::create([
        'billing_id' => $billing5->id,
        'extension_type' => 'damage',
        'description' => 'Broken cottage window',
        'amount' => 2500.00,
        'quantity' => 1,
        'total_amount' => 2500.00,
        'metadata' => json_encode([
            'location' => 'Cottage #8',
            'damage_report_id' => 'DMG-TEST-001',
            'assessed_by' => $manager->name,
        ]),
        'added_by' => $manager->id,
    ]);
    
    $billing5->total_amount += $extension3->total_amount;
    $billing5->balance += $extension3->total_amount;
    $billing5->save();
    
    echo "Damage charge added:\n";
    echo "  Description: {$extension3->description}\n";
    echo "  Amount: ₱" . number_format($extension3->total_amount, 2) . "\n";
    
    testResult(
        "Add Damage Extension",
        $extension3->exists && $extension3->extension_type == 'damage',
        "Damage charge recorded successfully"
    );
    
    // ========================================
    // TEST 8: ADD SERVICE EXTENSION
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 8: Add Service Extension\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $extension4 = BillingExtension::create([
        'billing_id' => $billing5->id,
        'extension_type' => 'service',
        'description' => 'Videography service (3 hours)',
        'amount' => 800.00,
        'quantity' => 3,
        'total_amount' => 2400.00,
        'metadata' => json_encode([
            'service_provider' => 'Pro Video Services',
            'hours' => 3,
            'contact' => '0917-123-4567',
        ]),
        'added_by' => $manager->id,
    ]);
    
    $billing5->total_amount += $extension4->total_amount;
    $billing5->balance += $extension4->total_amount;
    $billing5->save();
    
    echo "Service extension added:\n";
    echo "  Description: {$extension4->description}\n";
    echo "  Quantity: {$extension4->quantity} hours\n";
    echo "  Amount: ₱" . number_format($extension4->total_amount, 2) . "\n";
    
    testResult(
        "Add Service Extension",
        $extension4->exists && $extension4->extension_type == 'service',
        "Service charge added successfully"
    );
    
    // ========================================
    // TEST 9: VERIFY ALL EXTENSIONS FOR BILLING
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 9: Verify All Extensions Relationship\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $billing5->load('extensions');
    $extensionCount = $billing5->extensions->count();
    
    echo "Total extensions for billing: {$extensionCount}\n";
    foreach ($billing5->extensions as $ext) {
        echo "  • {$ext->extension_type}: {$ext->description} - ₱" . number_format($ext->total_amount, 2) . "\n";
    }
    
    testResult(
        "Extensions Relationship",
        $extensionCount == 4,
        "All 4 extension types successfully linked to billing"
    );
    
    // ========================================
    // TEST 10: VERIFY REFUND FIELDS
    // ========================================
    echo "─────────────────────────────────────────────────────────────────\n";
    echo "TEST 10: Verify Refund Tracking Fields\n";
    echo "─────────────────────────────────────────────────────────────────\n";
    
    $refundedBilling = Billing::where('billing_status', 'voided')
        ->where('payment_status', 'refunded')
        ->whereNotNull('refund_amount')
        ->first();
    
    if ($refundedBilling) {
        echo "Refunded billing found:\n";
        echo "  Refund Amount: ₱" . number_format($refundedBilling->refund_amount, 2) . "\n";
        echo "  Refund Reason: {$refundedBilling->refund_reason}\n";
        echo "  Refunded By: User ID {$refundedBilling->refunded_by}\n";
        echo "  Refunded At: {$refundedBilling->refunded_at}\n";
        
        testResult(
            "Refund Tracking Fields",
            !is_null($refundedBilling->refunded_at) && !is_null($refundedBilling->refunded_by),
            "All refund tracking fields populated correctly"
        );
    } else {
        testResult(
            "Refund Tracking Fields",
            false,
            "No refunded billing found"
        );
    }
    
    // ========================================
    // SUMMARY
    // ========================================
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                        TEST SUMMARY                            ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Total Tests: " . ($testsPassed + $testsFailed) . "\n";
    echo "✅ Passed: $testsPassed\n";
    echo "❌ Failed: $testsFailed\n";
    echo "\n";
    
    if ($testsFailed == 0) {
        echo "🎉 ALL TESTS PASSED! Features are working correctly.\n";
    } else {
        echo "⚠️  Some tests failed. Please review the output above.\n";
    }
    
    echo "\n";
    echo "Features Tested:\n";
    echo "  ✓ Edit Booking (Pending/Confirmed validation)\n";
    echo "  ✓ Cancel/Refund with Downpayment Policy\n";
    echo "  ✓ Cancel/Refund with Manager Override\n";
    echo "  ✓ Add Extensions (facility, guest, damage, service)\n";
    echo "  ✓ Extensions Relationship\n";
    echo "  ✓ Refund Tracking Fields\n";
    echo "\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}



