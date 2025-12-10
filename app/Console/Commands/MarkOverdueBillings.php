<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GuestEntry;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MarkOverdueBillings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'entries:mark-overstaying';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect guests who exceeded their checkout time (overstaying)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();
        $gracePeriodMinutes = config('billing.overtime.grace_period_minutes', 15);
        
        $overstayingCount = 0;

        // ========================================
        // 1. Check Guest Entries (Walk-in & Booking Check-ins)
        // ========================================
        $activeEntries = GuestEntry::where('is_checked_out', false)
            ->with(['facilities.rate', 'booking'])
            ->get();

        foreach ($activeEntries as $entry) {
            $isOverstaying = false;
            $scheduledCheckout = null;

            // Determine scheduled checkout based on entry type
            if ($entry->entry_type === 'walk_in') {
                // For walk-ins: calculate from check-in + facility duration
                foreach ($entry->facilities as $facility) {
                    if ($facility->duration_hours) {
                        $facilityCheckout = $entry->check_in_datetime
                            ->copy()
                            ->addHours($facility->duration_hours)
                            ->addMinutes($gracePeriodMinutes);

                        if ($now->gt($facilityCheckout)) {
                            $isOverstaying = true;
                            $scheduledCheckout = $facilityCheckout;
                            break;
                        }
                    }
                }
            } elseif ($entry->booking) {
                // For booking check-ins: use booking's checkout datetime
                $scheduledCheckout = $entry->booking->check_out_datetime
                    ->copy()
                    ->addMinutes($gracePeriodMinutes);

                if ($now->gt($scheduledCheckout)) {
                    $isOverstaying = true;
                }
            }

            // Log overstaying guests
            if ($isOverstaying) {
                Log::warning('Guest overstaying detected', [
                    'entry_id' => $entry->id,
                    'entry_reference' => $entry->entry_reference,
                    'entry_type' => $entry->entry_type,
                    'guest_name' => $entry->guest_name,
                    'scheduled_checkout' => $scheduledCheckout?->toDateTimeString(),
                    'current_time' => $now->toDateTimeString(),
                    'minutes_over' => $scheduledCheckout ? $now->diffInMinutes($scheduledCheckout) : null,
                ]);

                $this->warn("⚠ {$entry->entry_reference} ({$entry->guest_name}) - Overstaying since {$scheduledCheckout?->format('M d, Y h:i A')}");
                $overstayingCount++;
            }
        }

        $this->info("Completed: {$overstayingCount} guest(s) currently overstaying");

        Log::info('Overstay check completed', [
            'overstaying_count' => $overstayingCount,
            'checked_at' => $now,
        ]);

        return Command::SUCCESS;
    }
}
