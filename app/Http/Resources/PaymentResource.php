<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Booking;
use App\Models\GuestEntry;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billable = $this->billing?->billable;
        
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'billing_id' => $this->billing_id,
            'billing_number' => $this->billing?->billing_number,
            
            // Transaction Information
            'transaction_type' => $billable ? class_basename(get_class($billable)) : null,
            'transaction_id' => $billable?->id,
            'transaction_reference' => $this->getTransactionReference($billable),
            'guest_name' => $billable?->guest_name,
            
            // Payment Details
            'amount' => number_format($this->amount, 2),
            'amount_raw' => (float) $this->amount,
            'change_amount' => number_format($this->change_amount, 2),
            'change_amount_raw' => (float) $this->change_amount,
            'payment_method' => $this->payment_method,
            'payment_type' => $this->payment_type,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            
            // Dates
            'payment_date' => $this->payment_date?->format('Y-m-d H:i:s'),
            'payment_date_formatted' => $this->payment_date?->format('M d, Y h:i A'),
            
            // Staff Information
            'received_by' => [
                'id' => $this->receivedBy?->id,
                'name' => $this->receivedBy?->full_name,
                'username' => $this->receivedBy?->username,
            ],
            
            // Billing Summary (optional - only when needed)
            'billing' => $this->when($request->input('include_billing'), function() {
                return [
                    'total_amount' => number_format($this->billing->total_amount, 2),
                    'amount_paid' => number_format($this->billing->amount_paid, 2),
                    'balance' => number_format($this->billing->balance, 2),
                    'payment_status' => $this->billing->payment_status,
                ];
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get transaction reference based on billable type
     */
    private function getTransactionReference($billable): ?string
    {
        if ($billable instanceof Booking) {
            return $billable->booking_reference;
        } elseif ($billable instanceof GuestEntry) {
            return $billable->entry_reference;
        }
        
        return null;
    }
}