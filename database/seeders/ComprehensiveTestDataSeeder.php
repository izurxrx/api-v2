<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Rate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ComprehensiveTestDataSeeder extends Seeder
{
    public function run()
    {
        DB::beginTransaction();
        
        try {
            echo "🌱 Seeding comprehensive test data...\n\n";
            
            // Ensure we have facilities and rates
            $this->ensureMasterData();
            
            // Get first facility and rate for testing
            $facility = Facility::first();
            $rate = Rate::first();
            
            if (!$facility || !$rate) {
                throw new \Exception("Please create at least one facility and rate first!");
            }
            
            echo "Using Facility: {$facility->facility_name}\n";
            echo "Using Rate: {$rate->rate_name}\n\n";
            
            // 1. Create completed bookings (last 30 days)
            $this->seedCompletedBookings($facility, $rate);
            
            // 2. Create completed walk-ins (last 30 days)
            $this->seedCompletedWalkIns($facility, $rate);
            
            // 3. Create pending bookings (future)
            $this->seedPendingBookings($facility, $rate);
            
            // 4. Create cancelled booking with forfeited downpayment
            $this->seedCancelledBooking($facility, $rate);
            
            // 5. Create bookings with outstanding balance
            $this->seedBookingsWithBalance($facility, $rate);
            
            DB::commit();
            
            // Summary
            echo "\n════════════════════════════════════════\n";
            echo "✅ TEST DATA SEEDED SUCCESSFULLY!\n";
            echo "════════════════════════════════════════\n";
            echo "  📊 " . Booking::count() . " total bookings\n";
            echo "  🚶 " . GuestEntry::count() . " total walk-ins\n";
            echo "  💰 " . Billing::count() . " total billings\n";
            echo "  💵 " . Payment::count() . " total payments\n";
            echo "  💸 ₱" . number_format(Payment::sum('amount'), 2) . " total revenue\n";
            echo "════════════════════════════════════════\n";
            
        } catch (\Exception $e) {
            DB::rollBack();
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    private function ensureMasterData()
    {
        // Create facility type if none exists
        if (FacilityType::count() === 0) {
            FacilityType::create([
                'name' => 'Cottage',
                'description' => 'Standard cottage for day use'
            ]);
            echo "✓ Created facility type: Cottage\n";
        }
        
        // Create facility if none exists
        if (Facility::count() === 0) {
            $facilityType = FacilityType::first();
            Facility::create([
                'facility_name' => 'Test Cottage A1',
                'facility_type_id' => $facilityType->id,
                'capacity' => 10,
                'status' => 'available'
            ]);
            echo "✓ Created facility: Test Cottage A1\n";
        }
        
        // Create rate if none exists
        if (Rate::count() === 0) {
            $facilityType = FacilityType::first();
            Rate::create([
                'rate_name' => 'Day Rate',
                'facility_type_id' => $facilityType->id,
                'rate_type' => 'day',
                'rate_amount' => 3000,
                'guest_type_name' => null
            ]);
            echo "✓ Created rate: Day Rate\n";
        }
        
        echo "\n";
    }
    
    private function seedCompletedBookings($facility, $rate)
    {
        echo "📅 Creating completed bookings...\n";
        
        for ($i = 1; $i <= 15; $i++) {
            $daysAgo = rand(1, 30);
            $checkIn = Carbon::now()->subDays($daysAgo)->setTime(8, 0, 0);
            $checkOut = $checkIn->copy()->addDays(rand(1, 3))->setTime(17, 0, 0);
            
            $amount = rand(2000, 5000);
            
            $booking = Booking::create([
                'booking_reference' => 'BK' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'guest_name' => "Guest " . $i,
                'contact_number' => '0912' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'facility_id' => $facility->id,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'check_in_datetime' => $checkIn,
                'check_out_datetime' => $checkOut,
                'actual_check_in_datetime' => $checkIn,
                'actual_check_out_datetime' => $checkOut,
                'booking_status' => 'Checked_Out',
                'number_of_guests' => rand(2, 8),
                'facility_subtotal' => $amount,
                'subtotal' => $amount,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'created_by' => 17,
                'checked_in_by' => 18,
                'checked_out_by' => 19,
                'created_at' => $checkIn->copy()->subDays(rand(3, 10)),
            ]);
            
            $billing = Billing::create([
                'billable_type' => Booking::class,
                'billable_id' => $booking->id,
                'billing_number' => 'BILL' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'subtotal' => $amount,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'amount_paid' => $amount,
                'balance' => 0,
                'payment_status' => 'paid',
                'billing_status' => 'completed',
                'billed_at' => $checkOut,
                'paid_at' => $checkOut,
                'created_by' => 18,
            ]);
            
            $paymentMethod = ['cash', 'gcash', 'bank_transfer', 'credit_card'][rand(0, 3)];
            Payment::create([
                'billing_id' => $billing->id,
                'payment_number' => 'PAY' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_type' => 'full',
                'payment_date' => $checkOut,
                'received_by' => 20,
            ]);
            
            echo "  ✓ #{$i}: {$booking->booking_reference} - ₱" . number_format($amount) . " ({$paymentMethod})\n";
        }
        
        echo "\n";
    }
    
    private function seedCompletedWalkIns($facility, $rate)
    {
        echo "🚶 Creating completed walk-ins...\n";
        
        for ($i = 1; $i <= 10; $i++) {
            $daysAgo = rand(1, 30);
            $checkIn = Carbon::now()->subDays($daysAgo)->setTime(rand(9, 14), 0, 0);
            $checkOut = $checkIn->copy()->addHours(rand(3, 6));
            
            $entranceFee = rand(300, 800);
            $facilityFee = rand(1000, 2500);
            $total = $entranceFee + $facilityFee;
            
            $guestEntry = GuestEntry::create([
                'entry_reference' => 'GE' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'guest_name' => "Walk-in " . $i,
                'contact_number' => '0919' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'entry_date' => $checkIn->toDateString(),
                'check_in_datetime' => $checkIn,
                'checkout_datetime' => $checkOut,
                'is_checked_out' => true,
                'total_guests' => rand(2, 6),
                'entrance_subtotal' => $entranceFee,
                'facility_subtotal' => $facilityFee,
                'subtotal' => $total,
                'discount_amount' => 0,
                'total_amount' => $total,
                'created_by' => 19,
                'created_at' => $checkIn,
            ]);
            
            $billing = Billing::create([
                'billable_type' => GuestEntry::class,
                'billable_id' => $guestEntry->id,
                'billing_number' => 'BILL-GE' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'subtotal' => $total,
                'discount_amount' => 0,
                'total_amount' => $total,
                'amount_paid' => $total,
                'balance' => 0,
                'payment_status' => 'paid',
                'billing_status' => 'completed',
                'billed_at' => $checkOut,
                'paid_at' => $checkOut,
                'created_by' => 17,
            ]);
            
            $paymentMethod = ['cash', 'gcash'][rand(0, 1)];
            Payment::create([
                'billing_id' => $billing->id,
                'payment_number' => 'PAY-GE' . now()->format('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT),
                'amount' => $total,
                'payment_method' => $paymentMethod,
                'payment_type' => 'full',
                'payment_date' => $checkOut,
                'received_by' => 18,
            ]);
            
            echo "  ✓ #{$i}: {$guestEntry->entry_reference} - ₱" . number_format($total) . " ({$paymentMethod})\n";
        }
        
        echo "\n";
    }
    
    private function seedPendingBookings($facility, $rate)
    {
        echo "⏳ Creating pending bookings...\n";
        
        for ($i = 1; $i <= 3; $i++) {
            $checkIn = Carbon::now()->addDays(rand(5, 15))->setTime(8, 0, 0);
            $checkOut = $checkIn->copy()->addDays(rand(1, 2))->setTime(17, 0, 0);
            
            $amount = rand(2000, 4000);
            
            $booking = Booking::create([
                'booking_reference' => 'BK' . now()->format('Ymd') . 'P' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'guest_name' => "Pending Guest " . $i,
                'contact_number' => '0918' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'facility_id' => $facility->id,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'check_in_datetime' => $checkIn,
                'check_out_datetime' => $checkOut,
                'booking_status' => 'Pending',
                'number_of_guests' => rand(2, 6),
                'facility_subtotal' => $amount,
                'subtotal' => $amount,
                'total_amount' => $amount,
                'created_by' => 17,
            ]);
            
            echo "  ✓ #{$i}: {$booking->booking_reference} (No payment yet)\n";
        }
        
        echo "\n";
    }
    
    private function seedCancelledBooking($facility, $rate)
    {
        echo "❌ Creating cancelled booking with forfeited downpayment...\n";
        
        $checkIn = Carbon::now()->addDays(10);
        $checkOut = $checkIn->copy()->addDays(2);
        $amount = 3000;
        $downpayment = $amount * 0.3;
        
        $booking = Booking::create([
            'booking_reference' => 'BK' . now()->format('Ymd') . 'C001',
            'guest_name' => 'Cancelled Guest',
            'contact_number' => '09171234567',
            'facility_id' => $facility->id,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'check_in_datetime' => $checkIn,
            'check_out_datetime' => $checkOut,
            'booking_status' => 'Cancelled',
            'number_of_guests' => 4,
            'facility_subtotal' => $amount,
            'subtotal' => $amount,
            'total_amount' => $amount,
            'cancellation_reason' => 'Guest requested cancellation',
            'cancelled_at' => now(),
            'cancelled_by' => 18,
            'created_by' => 20,
        ]);
        
        Billing::create([
            'billable_type' => Booking::class,
            'billable_id' => $booking->id,
            'billing_number' => 'BILL' . now()->format('Ymd') . 'C001',
            'subtotal' => $amount,
            'total_amount' => $amount,
            'downpayment_amount' => $downpayment,
            'downpayment_paid' => $downpayment,
            'is_downpayment_paid' => true,
            'amount_paid' => $downpayment,
            'balance' => 0,
            'payment_status' => 'paid',
            'billing_status' => 'voided',
            'created_by' => 17,
        ]);
        
        echo "  ✓ Forfeited downpayment: ₱" . number_format($downpayment, 2) . "\n\n";
    }
    
    private function seedBookingsWithBalance($facility, $rate)
    {
        echo "💳 Creating bookings with outstanding balance...\n";
        
        for ($i = 1; $i <= 2; $i++) {
            $daysAgo = rand(1, 10);
            $checkIn = Carbon::now()->subDays($daysAgo)->setTime(8, 0, 0);
            $checkOut = $checkIn->copy()->addDays(1)->setTime(17, 0, 0);
            
            $amount = rand(3000, 5000);
            $paid = rand(1000, $amount - 500);
            $balance = $amount - $paid;
            
            $booking = Booking::create([
                'booking_reference' => 'BK' . now()->format('Ymd') . 'B' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'guest_name' => "Balance Guest " . $i,
                'contact_number' => '0917' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'facility_id' => $facility->id,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'check_in_datetime' => $checkIn,
                'check_out_datetime' => $checkOut,
                'actual_check_in_datetime' => $checkIn,
                'actual_check_out_datetime' => $checkOut,
                'booking_status' => 'Checked_Out',
                'number_of_guests' => rand(2, 6),
                'facility_subtotal' => $amount,
                'subtotal' => $amount,
                'total_amount' => $amount,
                'created_by' => 18,
                'checked_in_by' => 19,
                'checked_out_by' => 20,
            ]);
            
            $billing = Billing::create([
                'billable_type' => Booking::class,
                'billable_id' => $booking->id,
                'billing_number' => 'BILL' . now()->format('Ymd') . 'B' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'subtotal' => $amount,
                'total_amount' => $amount,
                'amount_paid' => $paid,
                'balance' => $balance,
                'payment_status' => 'partial',
                'billing_status' => 'active',
                'billed_at' => $checkOut,
                'created_by' => 19,
            ]);
            
            Payment::create([
                'billing_id' => $billing->id,
                'payment_number' => 'PAY' . now()->format('Ymd') . 'B' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'amount' => $paid,
                'payment_method' => 'cash',
                'payment_type' => 'partial',
                'payment_date' => $checkOut,
                'received_by' => 19,
            ]);
            
            echo "  ✓ #{$i}: Paid ₱" . number_format($paid) . ", Balance ₱" . number_format($balance) . "\n";
        }
        
        echo "\n";
    }
}