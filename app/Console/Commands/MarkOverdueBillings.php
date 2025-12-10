<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Billing;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MarkOverdueBillings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billings:mark-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark unpaid/partial billings as overdue when past their due date';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        // Find billings that are past due date and not fully paid
        $overdueBillings = Billing::whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->whereIn('payment_status', ['pending', 'partial'])
            ->get();

        $count = 0;

        foreach ($overdueBillings as $billing) {
            // Update payment status to overdue
            $billing->update([
                'payment_status' => 'overdue',
            ]);

            // Get billable info for logging
            $billableType = class_basename($billing->billable_type);
            $billableId = $billing->billable_id;
            $reference = $billing->billable?->booking_reference ??
                        ($billing->billable?->entry_reference ?? 'N/A');

            Log::info('Billing marked as overdue', [
                'billing_id' => $billing->id,
                'billable_type' => $billableType,
                'billable_id' => $billableId,
                'reference' => $reference,
                'due_date' => $billing->due_date,
                'total_amount' => $billing->total_amount,
                'balance' => $billing->balance,
                'marked_at' => $now,
            ]);

            $this->info("✓ Billing #{$billing->id} ({$billableType} {$reference}) marked as overdue");
            $count++;
        }

        $this->info("Completed: {$count} billing(s) marked as overdue");

        Log::info('Overdue billing check completed', [
            'billings_marked' => $count,
            'checked_at' => $now,
        ]);

        return Command::SUCCESS;
    }
}
