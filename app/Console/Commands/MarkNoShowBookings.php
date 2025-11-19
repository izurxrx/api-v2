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
    protected $description = 'Mark confirmed bookings as No-Show if guest did not check-in within grace period';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gracePeriodHours = 4; // 4 hours after scheduled check-in
        $cutoffTime = Carbon::now()->subHours($gracePeriodHours);
        
        $noShowBookings = Booking::where('booking_status', 'Confirmed')
            ->where('check_in_datetime', '<', $cutoffTime)
            ->get();
        
        $count = 0;
        
        foreach ($noShowBookings as $booking) {
            $booking->update([
                'booking_status' => 'No_Show',
                'notes' => sprintf(
                    '%s | Auto-marked as No-Show on %s. Guest did not check-in within %d hours of scheduled time (%s).',
                    $booking->notes ?? '',
                    now()->format('M d, Y h:i A'),
                    $gracePeriodHours,
                    $booking->check_in_datetime->format('M d, Y h:i A')
                ),
            ]);
            
            Log::info('Booking marked as No-Show', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'scheduled_check_in' => $booking->check_in_datetime,
                'marked_at' => now(),
            ]);
            
            $this->info("✓ Marked booking {$booking->booking_reference} as No-Show");
            $count++;
        }
        
        $this->info("Completed: {$count} booking(s) marked as No-Show");
        
        Log::info('No-Show check completed', [
            'bookings_marked' => $count,
            'cutoff_time' => $cutoffTime,
        ]);
        
        return Command::SUCCESS;
    }
}
