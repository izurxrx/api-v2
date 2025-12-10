<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MarkNoShowBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:mark-no-show';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-mark bookings: Pending→No-Show (no payment), Confirmed→Cancelled (missed check-in)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gracePeriodHours = 4; // 4 hours after scheduled check-in
        $cutoffTime = Carbon::now()->subHours($gracePeriodHours);
        
        $pendingCount = 0;
        $confirmedCount = 0;
        
        // 1. Mark PENDING bookings as No-Show (no downpayment paid)
        $pendingBookings = Booking::where('booking_status', 'Pending')
            ->where('check_in_datetime', '<', $cutoffTime)
            ->get();
        
        foreach ($pendingBookings as $booking) {
            $booking->update([
                'booking_status' => 'No_Show',
                'notes' => sprintf(
                    '%s | Auto-marked as No-Show on %s. Guest did not pay downpayment and missed check-in time (%s).',
                    $booking->notes ?? '',
                    now()->format('M d, Y h:i A'),
                    $booking->check_in_datetime->format('M d, Y h:i A')
                ),
            ]);
            
            Log::info('Pending booking marked as No-Show (no payment)', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'scheduled_check_in' => $booking->check_in_datetime,
                'marked_at' => now(),
            ]);
            
            $this->info("✓ Marked pending booking {$booking->booking_reference} as No-Show (no payment)");
            $pendingCount++;
        }
        
        // 2. Mark CONFIRMED bookings as Cancelled (paid but didn't show up)
        $confirmedBookings = Booking::where('booking_status', 'Confirmed')
            ->where('check_in_datetime', '<', $cutoffTime)
            ->get();
        
        foreach ($confirmedBookings as $booking) {
            $booking->update([
                'booking_status' => 'Cancelled',
                'notes' => sprintf(
                    '%s | Auto-cancelled on %s. Guest paid downpayment but did not check-in within %d hours of scheduled time (%s).',
                    $booking->notes ?? '',
                    now()->format('M d, Y h:i A'),
                    $gracePeriodHours,
                    $booking->check_in_datetime->format('M d, Y h:i A')
                ),
            ]);
            
            Log::info('Confirmed booking auto-cancelled (paid but no-show)', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'scheduled_check_in' => $booking->check_in_datetime,
                'marked_at' => now(),
            ]);
            
            $this->info("✓ Cancelled confirmed booking {$booking->booking_reference} (paid downpayment, no check-in)");
            $confirmedCount++;
        }
        
        $this->info("Completed: {$pendingCount} No-Show, {$confirmedCount} Cancelled");
        
        Log::info('Booking auto-processing completed', [
            'pending_marked_no_show' => $pendingCount,
            'confirmed_cancelled' => $confirmedCount,
            'cutoff_time' => $cutoffTime,
        ]);
        
        return Command::SUCCESS;
    }
}
