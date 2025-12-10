<?php

/**
 * Test Script: Verify Billing Linkage Between Booking and Guest Entry
 *
 * This script tests that when a booking is checked in:
 * 1. The guest entry is created with booking_id
 * 2. The booking's billing is linked to the guest entry via guest_entry_id
 * 3. The guest entry can access the billing and payments
 * 4. Both booking and guest entry share the same billing
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Billing;
use App\Models\Rate;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=================================================================\n";
echo "Testing Billing Linkage Between Booking and Guest Entry\n";
echo "=================================================================\n\n";

try {
    DB::beginTransaction();

    // ========================================
    // STEP 1: Find or create test booking with billing
    // ========================================
    echo "STEP 1: Finding a confirmed booking with billing...\n";

    $booking = Booking::with(['billing', 'billing.payments'])
        ->where('booking_status', 'Confirmed')
        ->whereHas('billing', function($q) {
            $q->where('is_downpayment_paid', true);
        })
        ->whereDoesntHave('guestEntry')
        ->first();

    if (!$booking) {
        echo "   ❌ No suitable booking found. Creating test booking...\n";

        // Create a test booking with billing
        $user = User::first();
        if (!$user) {
            throw new Exception("No users found in database");
        }

        $entranceRate = Rate::where('rate_category', 'Entrance')->first();
        $facility = Facility::with('facilityType')->first();

        if (!$entranceRate || !$facility) {
            throw new Exception("Required rates or facilities not found");
        }

        $booking = Booking::create([
            'booking_reference' => 'TEST-' . strtoupper(uniqid()),
            'booking_type' => 'Swimming',
            'entrance_rate_id' => $entranceRate->id,
            'guest_name' => 'Test Guest for Billing Linkage',
            'contact_number' => '09123456789',
            'check_in_date' => now(),
            'check_out_date' => now(),
            'check_in_datetime' => now(),
            'check_out_datetime' => now()->addHours(8),
            'number_of_guests' => 5,
            'entrance_subtotal' => $entranceRate->base_price * 5,
            'facility_subtotal' => 500,
            'subtotal' => ($entranceRate->base_price * 5) + 500,
            'total_amount' => ($entranceRate->base_price * 5) + 500,
            'booking_status' => 'Confirmed',
            'created_by' => $user->id,
        ]);

        // Create billing
        $billing = Billing::create([
            'billable_type' => Booking::class,
            'billable_id' => $booking->id,
            'billing_number' => 'BILL-' . strtoupper(uniqid()),
            'subtotal' => $booking->subtotal,
            'total_amount' => $booking->total_amount,
            'downpayment_amount' => $booking->total_amount * 0.5,
            'downpayment_paid' => $booking->total_amount * 0.5,
            'is_downpayment_paid' => true,
            'amount_paid' => $booking->total_amount * 0.5,
            'balance' => $booking->total_amount * 0.5,
            'payment_status' => 'partial',
            'billing_status' => 'confirmed',
            'billed_at' => now(),
            'created_by' => $user->id,
        ]);

        $booking->load(['billing', 'billing.payments']);
        echo "   ✅ Test booking created: {$booking->booking_reference}\n";
    } else {
        echo "   ✅ Found booking: {$booking->booking_reference}\n";
    }

    echo "   Booking ID: {$booking->id}\n";
    echo "   Billing ID: {$booking->billing->id}\n";
    echo "   Billing Number: {$booking->billing->billing_number}\n";
    echo "   Total Amount: ₱" . number_format($booking->billing->total_amount, 2) . "\n";
    echo "   Amount Paid: ₱" . number_format($booking->billing->amount_paid, 2) . "\n";
    echo "   Balance: ₱" . number_format($booking->billing->balance, 2) . "\n";
    echo "   Payment Count: " . $booking->billing->payments->count() . "\n\n";

    $billingId = $booking->billing->id;
    $originalBillingData = [
        'total_amount' => $booking->billing->total_amount,
        'amount_paid' => $booking->billing->amount_paid,
        'balance' => $booking->billing->balance,
        'payment_count' => $booking->billing->payments->count(),
    ];

    // ========================================
    // STEP 2: Create guest entry (simulate check-in)
    // ========================================
    echo "STEP 2: Creating guest entry from booking (simulating check-in)...\n";

    $guestEntry = GuestEntry::create([
        'booking_id' => $booking->id,
        'entry_type' => 'booking',
        'entry_reference' => 'ENTRY-' . strtoupper(uniqid()),
        'entry_date' => now()->toDateString(),
        'entry_time' => now()->format('H:i:s'),
        'check_in_datetime' => now(),
        'entrance_rate_id' => $booking->entrance_rate_id,
        'guest_name' => $booking->guest_name,
        'contact_number' => $booking->contact_number,
        'total_guests' => $booking->number_of_guests,
        'entrance_subtotal' => $booking->entrance_subtotal ?? 0,
        'facility_subtotal' => $booking->facility_subtotal,
        'subtotal' => $booking->subtotal,
        'discount_amount' => $booking->discount_amount,
        'total_amount' => $booking->total_amount,
        'is_checked_out' => false,
        'created_by' => $booking->created_by,
    ]);

    echo "   ✅ Guest entry created: {$guestEntry->entry_reference}\n";
    echo "   Guest Entry ID: {$guestEntry->id}\n";
    echo "   Entry Type: {$guestEntry->entry_type}\n";
    echo "   Linked Booking ID: {$guestEntry->booking_id}\n\n";

    // ========================================
    // STEP 3: Link billing to guest entry
    // ========================================
    echo "STEP 3: Linking billing to guest entry...\n";

    $booking->billing->update([
        'guest_entry_id' => $guestEntry->id,
    ]);

    echo "   ✅ Billing updated with guest_entry_id\n\n";

    // ========================================
    // STEP 4: Verify linkage from both sides
    // ========================================
    echo "STEP 4: Verifying billing linkage...\n\n";

    // Refresh models
    $booking->refresh();
    $guestEntry->refresh();

    // Test 1: Booking can access billing
    echo "   Test 1: Booking → Billing\n";
    $bookingBilling = $booking->billing;
    if ($bookingBilling) {
        echo "      ✅ Booking can access billing\n";
        echo "      Billing ID: {$bookingBilling->id}\n";
        echo "      Billing Number: {$bookingBilling->billing_number}\n";
        echo "      Guest Entry ID: " . ($bookingBilling->guest_entry_id ?? 'null') . "\n";
    } else {
        echo "      ❌ ERROR: Booking cannot access billing!\n";
    }
    echo "\n";

    // Test 2: Guest Entry can access billing
    echo "   Test 2: Guest Entry → Billing\n";
    $guestEntryBilling = $guestEntry->billing;
    if ($guestEntryBilling) {
        echo "      ✅ Guest entry can access billing\n";
        echo "      Billing ID: {$guestEntryBilling->id}\n";
        echo "      Billing Number: {$guestEntryBilling->billing_number}\n";
        echo "      Total Amount: ₱" . number_format($guestEntryBilling->total_amount, 2) . "\n";
        echo "      Amount Paid: ₱" . number_format($guestEntryBilling->amount_paid, 2) . "\n";
        echo "      Balance: ₱" . number_format($guestEntryBilling->balance, 2) . "\n";
    } else {
        echo "      ❌ ERROR: Guest entry cannot access billing!\n";
    }
    echo "\n";

    // Test 3: Both access the same billing
    echo "   Test 3: Same Billing Instance\n";
    if ($bookingBilling && $guestEntryBilling && $bookingBilling->id === $guestEntryBilling->id) {
        echo "      ✅ Both booking and guest entry access the SAME billing\n";
        echo "      Billing ID: {$bookingBilling->id}\n";
    } else {
        echo "      ❌ ERROR: Booking and guest entry access different billings!\n";
        if ($bookingBilling) echo "      Booking Billing ID: {$bookingBilling->id}\n";
        if ($guestEntryBilling) echo "      Guest Entry Billing ID: {$guestEntryBilling->id}\n";
    }
    echo "\n";

    // Test 4: Guest Entry can access payments
    echo "   Test 4: Guest Entry → Billing → Payments\n";
    $payments = $guestEntry->billing?->payments;
    if ($payments) {
        echo "      ✅ Guest entry can access billing payments\n";
        echo "      Payment Count: {$payments->count()}\n";
        foreach ($payments as $payment) {
            echo "         - Payment #{$payment->payment_number}: ₱" . number_format($payment->amount, 2) . " ({$payment->payment_method})\n";
        }
    } else {
        echo "      ❌ ERROR: Guest entry cannot access payments!\n";
    }
    echo "\n";

    // Test 5: Billing data integrity
    echo "   Test 5: Billing Data Integrity\n";
    $currentBilling = Billing::find($billingId);
    $dataMatches = (
        $currentBilling->total_amount == $originalBillingData['total_amount'] &&
        $currentBilling->amount_paid == $originalBillingData['amount_paid'] &&
        $currentBilling->balance == $originalBillingData['balance']
    );

    if ($dataMatches) {
        echo "      ✅ Billing amounts unchanged after linking\n";
        echo "      Total Amount: ₱" . number_format($currentBilling->total_amount, 2) . "\n";
        echo "      Amount Paid: ₱" . number_format($currentBilling->amount_paid, 2) . "\n";
        echo "      Balance: ₱" . number_format($currentBilling->balance, 2) . "\n";
    } else {
        echo "      ❌ WARNING: Billing amounts changed!\n";
        echo "      Original Total: ₱" . number_format($originalBillingData['total_amount'], 2) . "\n";
        echo "      Current Total: ₱" . number_format($currentBilling->total_amount, 2) . "\n";
    }
    echo "\n";

    // Test 6: Billing relationship to guest entry
    echo "   Test 6: Billing → Guest Entry (Reverse Relationship)\n";
    $billingGuestEntry = $currentBilling->guestEntry;
    if ($billingGuestEntry && $billingGuestEntry->id === $guestEntry->id) {
        echo "      ✅ Billing can access its guest entry\n";
        echo "      Guest Entry ID: {$billingGuestEntry->id}\n";
        echo "      Entry Reference: {$billingGuestEntry->entry_reference}\n";
    } else {
        echo "      ❌ ERROR: Billing cannot access guest entry!\n";
    }
    echo "\n";

    // ========================================
    // SUMMARY
    // ========================================
    echo "=================================================================\n";
    echo "SUMMARY\n";
    echo "=================================================================\n\n";

    $allTestsPassed = (
        $bookingBilling !== null &&
        $guestEntryBilling !== null &&
        $bookingBilling->id === $guestEntryBilling->id &&
        $payments !== null &&
        $dataMatches &&
        $billingGuestEntry !== null
    );

    if ($allTestsPassed) {
        echo "✅ ALL TESTS PASSED!\n\n";
        echo "Billing linkage is working correctly:\n";
        echo "  • Booking has billing: YES\n";
        echo "  • Guest Entry has billing: YES\n";
        echo "  • Same billing instance: YES\n";
        echo "  • Payments accessible: YES\n";
        echo "  • Data integrity: YES\n";
        echo "  • Reverse relationship: YES\n";
    } else {
        echo "❌ SOME TESTS FAILED!\n\n";
        echo "Please review the test results above.\n";
    }

    echo "\n";
    DB::rollBack();
    echo "🔄 Database rolled back (test data cleaned up)\n\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
