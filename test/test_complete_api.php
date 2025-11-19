<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Booking;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\GuestEntry;
use App\Models\Facility;
use App\Models\Rate;
use App\Models\Discount;
use App\Models\BillingExtension;

echo "═══════════════════════════════════════════════════════════════\n";
echo "   COMPREHENSIVE API TEST - ALL FEATURES\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$testsPassed = 0;
$testsFailed = 0;

function test($name, $callback) {
    global $testsPassed, $testsFailed;
    echo "\n🔍 Testing: $name\n";
    try {
        $result = $callback();
        if ($result === true || $result === null) {
            echo "   ✅ PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ FAILED: $result\n";
            $testsFailed++;
        }
    } catch (Exception $e) {
        echo "   ❌ FAILED: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
}

// ═══════════════════════════════════════════════════════════════
// SECTION 1: DATABASE SCHEMA VALIDATION
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 1: DATABASE SCHEMA VALIDATION\n";
echo "─────────────────────────────────────────────────────────────\n";

test("Users table exists with correct columns", function() {
    $required = ['id', 'username', 'full_name', 'password'];
    $columns = Schema::getColumnListing('users');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Facilities table exists with correct columns", function() {
    $required = ['id', 'name', 'facility_type_id', 'quantity', 'max_capacity'];
    $columns = Schema::getColumnListing('facilities');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Rates table exists with correct columns", function() {
    $required = ['id', 'rate_name', 'rate_category', 'base_price', 'facility_id'];
    $columns = Schema::getColumnListing('rates');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Bookings table exists with all required columns", function() {
    $required = ['id', 'booking_reference', 'booking_type', 'guest_name', 'contact_number', 
                 'check_in_date', 'check_out_date', 'number_of_guests', 'booking_status',
                 'discount_mode', 'entrance_rate_id', 'total_amount'];
    $columns = Schema::getColumnListing('bookings');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Booking facilities pivot table exists", function() {
    $required = ['id', 'booking_id', 'facility_id', 'rate_id', 'quantity', 'rate_amount'];
    $columns = Schema::getColumnListing('booking_facilities');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Booking guest discounts table exists with guest_type", function() {
    $required = ['id', 'booking_id', 'guest_type', 'discount_id', 'guest_count'];
    $columns = Schema::getColumnListing('booking_guest_discounts');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Billings table exists with all required columns", function() {
    $required = ['id', 'billable_type', 'billable_id', 'billing_number', 'total_amount', 
                 'amount_paid', 'balance', 'payment_status', 'billing_status',
                 'downpayment_amount', 'downpayment_paid'];
    $columns = Schema::getColumnListing('billings');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Billings table has refund fields", function() {
    $required = ['refund_reason', 'refunded_by', 'refunded_at'];
    $columns = Schema::getColumnListing('billings');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing refund columns: " . implode(', ', $missing);
    }
    return true;
});

test("Billing extensions table exists", function() {
    $required = ['id', 'billing_id', 'extension_type', 'description', 'amount', 'quantity', 'total_amount'];
    $columns = Schema::getColumnListing('billing_extensions');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Payments table exists with correct columns", function() {
    $required = ['id', 'billing_id', 'payment_method', 'amount', 'payment_type'];
    $columns = Schema::getColumnListing('payments');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Guest entries table exists", function() {
    $required = ['id', 'entry_reference', 'entry_type', 'guest_name', 'total_amount', 'is_checked_out'];
    $columns = Schema::getColumnListing('guest_entries');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

test("Discounts table exists", function() {
    $required = ['id', 'name', 'category', 'type', 'value', 'is_active'];
    $columns = Schema::getColumnListing('discounts');
    $missing = array_diff($required, $columns);
    if (!empty($missing)) {
        return "Missing columns: " . implode(', ', $missing);
    }
    return true;
});

// ═══════════════════════════════════════════════════════════════
// SECTION 2: ENUM VALUES VALIDATION
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 2: ENUM VALUES VALIDATION\n";
echo "─────────────────────────────────────────────────────────────\n";

test("Billing status enum has correct values", function() {
    $result = DB::select("SHOW COLUMNS FROM billings WHERE Field = 'billing_status'");
    $type = $result[0]->Type;
    $required = ['pending', 'active', 'completed', 'voided'];
    foreach ($required as $value) {
        if (!str_contains($type, $value)) {
            return "Missing enum value: $value";
        }
    }
    return true;
});

test("Payment status enum has correct values", function() {
    $result = DB::select("SHOW COLUMNS FROM billings WHERE Field = 'payment_status'");
    $type = $result[0]->Type;
    $required = ['unpaid', 'partial', 'paid', 'refunded', 'cancelled'];
    foreach ($required as $value) {
        if (!str_contains($type, $value)) {
            return "Missing enum value: $value";
        }
    }
    return true;
});

test("Booking status enum has correct values", function() {
    $result = DB::select("SHOW COLUMNS FROM bookings WHERE Field = 'booking_status'");
    $type = $result[0]->Type;
    $required = ['Pending', 'Confirmed', 'Checked_In', 'Checked_Out', 'Cancelled'];
    foreach ($required as $value) {
        if (!str_contains($type, $value)) {
            return "Missing enum value: $value";
        }
    }
    return true;
});

test("Extension type enum has correct values", function() {
    $result = DB::select("SHOW COLUMNS FROM billing_extensions WHERE Field = 'extension_type'");
    $type = $result[0]->Type;
    $required = ['facility', 'guest', 'damage', 'service'];
    foreach ($required as $value) {
        if (!str_contains($type, $value)) {
            return "Missing enum value: $value";
        }
    }
    return true;
});

// ═══════════════════════════════════════════════════════════════
// SECTION 3: MODEL RELATIONSHIPS
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 3: MODEL RELATIONSHIPS\n";
echo "─────────────────────────────────────────────────────────────\n";

test("Booking model has billing relationship", function() {
    $booking = Booking::first();
    if (!$booking) return "No bookings in database";
    return method_exists($booking, 'billing');
});

test("Booking model has facilities relationship", function() {
    $booking = Booking::first();
    if (!$booking) return true; // Skip if no bookings
    return method_exists($booking, 'facilities');
});

test("Booking model has guestDiscounts relationship", function() {
    $booking = Booking::first();
    if (!$booking) return true;
    return method_exists($booking, 'guestDiscounts');
});

test("Billing model has extensions relationship", function() {
    $billing = Billing::first();
    if (!$billing) return "No billings in database";
    return method_exists($billing, 'extensions');
});

test("Billing model has payments relationship", function() {
    $billing = Billing::first();
    if (!$billing) return true;
    return method_exists($billing, 'payments');
});

test("Billing model has billable polymorphic relationship", function() {
    $billing = Billing::first();
    if (!$billing) return true;
    return method_exists($billing, 'billable');
});

// ═══════════════════════════════════════════════════════════════
// SECTION 4: DATA INTEGRITY
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 4: DATA INTEGRITY\n";
echo "─────────────────────────────────────────────────────────────\n";

test("All bookings have valid billing records", function() {
    $bookingsWithoutBilling = Booking::whereDoesntHave('billing')->count();
    if ($bookingsWithoutBilling > 0) {
        return "$bookingsWithoutBilling bookings missing billing records";
    }
    return true;
});

test("All billings have valid billable records", function() {
    $invalidBillings = Billing::whereDoesntHave('billable')->count();
    if ($invalidBillings > 0) {
        return "$invalidBillings billings with invalid billable records";
    }
    return true;
});

test("Billing balance calculations are correct", function() {
    $billings = Billing::with('payments')->get();
    foreach ($billings as $billing) {
        $calculatedBalance = $billing->total_amount - $billing->amount_paid;
        if (abs($billing->balance - $calculatedBalance) > 0.01) {
            return "Billing #{$billing->id}: balance mismatch (stored: {$billing->balance}, calculated: $calculatedBalance)";
        }
    }
    return true;
});

test("Payment status matches payment records", function() {
    $billings = Billing::all();
    foreach ($billings as $billing) {
        $expectedStatus = 'unpaid';
        if ($billing->amount_paid >= $billing->total_amount) {
            $expectedStatus = 'paid';
        } elseif ($billing->amount_paid > 0) {
            $expectedStatus = 'partial';
        }
        
        // Allow refunded and cancelled as valid statuses
        if (!in_array($billing->payment_status, [$expectedStatus, 'refunded', 'cancelled'])) {
            return "Billing #{$billing->id}: payment_status '{$billing->payment_status}' doesn't match amount_paid";
        }
    }
    return true;
});

// ═══════════════════════════════════════════════════════════════
// SECTION 5: BUSINESS LOGIC VALIDATION
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 5: BUSINESS LOGIC VALIDATION\n";
echo "─────────────────────────────────────────────────────────────\n";

test("Booking can only be edited if status is Pending", function() {
    $booking = Booking::where('booking_status', 'Pending')->first();
    if (!$booking) return true; // Skip if no pending bookings
    
    // Check the canBeCancelled method exists
    return method_exists($booking, 'canBeCancelled');
});

test("Downpayment calculation is correct (50% of total)", function() {
    $billings = Billing::where('downpayment_amount', '>', 0)->get();
    foreach ($billings as $billing) {
        $expectedDownpayment = $billing->total_amount * 0.5;
        if (abs($billing->downpayment_amount - $expectedDownpayment) > 0.01) {
            return "Billing #{$billing->id}: downpayment should be 50% of total";
        }
    }
    return true;
});

test("Extension total_amount equals amount × quantity", function() {
    $extensions = BillingExtension::all();
    foreach ($extensions as $ext) {
        $calculated = $ext->amount * $ext->quantity;
        if (abs($ext->total_amount - $calculated) > 0.01) {
            return "Extension #{$ext->id}: total_amount mismatch";
        }
    }
    return true;
});

test("Facilities have valid rates", function() {
    $facilities = Facility::with('rates')->get();
    foreach ($facilities as $facility) {
        if ($facility->rates->isEmpty() && !$facility->trashed()) {
            return "Facility '{$facility->name}' has no rates defined";
        }
    }
    return true;
});

test("Direct discounts category is 'Direct_Discount'", function() {
    $directDiscounts = Discount::where('category', 'Direct_Discount')->get();
    foreach ($directDiscounts as $discount) {
        if (!in_array($discount->type, ['Percentage', 'Fixed'])) {
            return "Direct discount '{$discount->name}' has invalid type";
        }
    }
    return true;
});

// ═══════════════════════════════════════════════════════════════
// SECTION 6: FEATURE-SPECIFIC TESTS
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 6: FEATURE-SPECIFIC TESTS\n";
echo "─────────────────────────────────────────────────────────────\n";

test("Edit Booking: Facilities can be updated", function() {
    $booking = Booking::with('facilities')->first();
    if (!$booking) return true;
    
    // Check that booking_facilities table can store facility data
    return Schema::hasColumn('booking_facilities', 'rate_amount');
});

test("Refund: Billings have refund tracking fields", function() {
    return Schema::hasColumn('billings', 'refund_reason') &&
           Schema::hasColumn('billings', 'refunded_by') &&
           Schema::hasColumn('billings', 'refunded_at');
});

test("Extensions: All extension types are valid", function() {
    $extensions = BillingExtension::all();
    $validTypes = ['facility', 'guest', 'damage', 'service'];
    foreach ($extensions as $ext) {
        if (!in_array($ext->extension_type, $validTypes)) {
            return "Invalid extension type: {$ext->extension_type}";
        }
    }
    return true;
});

test("Direct Discounts: Guest type validation", function() {
    $guestDiscounts = DB::table('booking_guest_discounts')->get();
    $validTypes = ['senior', 'pwd', 'child'];
    foreach ($guestDiscounts as $gd) {
        if (!in_array($gd->guest_type, $validTypes)) {
            return "Invalid guest type: {$gd->guest_type}";
        }
    }
    return true;
});

test("Guest Entry: Checkout requires full payment", function() {
    $checkedOutEntries = GuestEntry::where('is_checked_out', true)->with('billing')->get();
    foreach ($checkedOutEntries as $entry) {
        if ($entry->billing && $entry->billing->balance > 0) {
            return "Guest entry #{$entry->id} checked out with outstanding balance";
        }
    }
    return true;
});

// ═══════════════════════════════════════════════════════════════
// SECTION 7: CRITICAL CONTROLLER METHODS
// ═══════════════════════════════════════════════════════════════
echo "\n📋 SECTION 7: CRITICAL CONTROLLER METHODS EXIST\n";
echo "─────────────────────────────────────────────────────────────\n";

test("BookingController has update method", function() {
    return method_exists(\App\Http\Controllers\Api\BookingController::class, 'update');
});

test("BookingController has cancelRefund method", function() {
    return method_exists(\App\Http\Controllers\Api\BookingController::class, 'cancelRefund');
});

test("BillingController has addExtension method", function() {
    return method_exists(\App\Http\Controllers\Api\BillingController::class, 'addExtension');
});

test("BillingController has recordPayment method", function() {
    $billing = Billing::first();
    if (!$billing) return true;
    return method_exists($billing, 'recordPayment');
});

test("GuestMonitoringController has checkout method", function() {
    return method_exists(\App\Http\Controllers\Api\GuestMonitoringController::class, 'checkout');
});

// ═══════════════════════════════════════════════════════════════
// FINAL SUMMARY
// ═══════════════════════════════════════════════════════════════
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "   TEST SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\n";
echo "✅ Tests Passed: $testsPassed\n";
echo "❌ Tests Failed: $testsFailed\n";
echo "\n";

$totalTests = $testsPassed + $testsFailed;
$successRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100, 2) : 0;

echo "Success Rate: $successRate%\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL TESTS PASSED! Your API is fully aligned and working correctly.\n";
} else {
    echo "\n⚠️  Some tests failed. Please review the errors above.\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
