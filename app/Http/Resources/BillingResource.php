<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Booking;
use App\Models\GuestEntry;

class BillingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billable = $this->billable;
        
        return [
            'id' => $this->id,
            'billing_number' => $this->billing_number,
            
            // Transaction Information
            'billable_type' => class_basename($this->billable_type),
            'billable_id' => $this->billable_id,
            'transaction_reference' => $this->getTransactionReference($billable),
            'guest_name' => $billable?->guest_name,
            'contact_number' => $billable?->contact_number,
            
            // Amounts
            'subtotal' => number_format($this->subtotal, 2),
            'subtotal_raw' => (float) $this->subtotal,
            'discount_amount' => number_format($this->discount_amount, 2),
            'discount_amount_raw' => (float) $this->discount_amount,
            'total_amount' => number_format($this->total_amount, 2),
            'total_amount_raw' => (float) $this->total_amount,
            'amount_paid' => number_format($this->amount_paid, 2),
            'amount_paid_raw' => (float) $this->amount_paid,
            'balance' => number_format($this->balance, 2),
            'balance_raw' => (float) $this->balance,
            'refund_amount' => number_format($this->refund_amount, 2),
            'refund_amount_raw' => (float) $this->refund_amount,
            
            // Downpayment
            'downpayment_amount' => (float) $this->downpayment_amount,
            'downpayment_paid' => (float) $this->downpayment_paid,
            'is_downpayment_paid' => (bool) $this->is_downpayment_paid,
            
            // Status
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->getPaymentStatusLabel(),
            'billing_status' => $this->billing_status,
            'billing_status_label' => $this->getBillingStatusLabel(),
            
            // Dates
            'billed_at' => $this->billed_at?->format('Y-m-d H:i:s'),
            'billed_at_formatted' => $this->billed_at?->format('M d, Y h:i A'),
            'due_date' => $this->due_date?->format('Y-m-d H:i:s'),
            'due_date_formatted' => $this->due_date?->format('M d, Y'),
            'paid_at' => $this->paid_at?->format('Y-m-d H:i:s'),
            'paid_at_formatted' => $this->paid_at?->format('M d, Y h:i A'),
            'voided_at' => $this->voided_at?->format('Y-m-d H:i:s'),
            'voided_at_formatted' => $this->voided_at?->format('M d, Y h:i A'),
            
            // Overdue status
            'is_overdue' => $this->isOverdue(),
            'days_overdue' => $this->getDaysOverdue(),
            
            // Cancellation
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->when($this->cancelledBy, [
                'id' => $this->cancelledBy?->id,
                'name' => $this->cancelledBy?->full_name,
            ]),
            
            // Refund tracking (NEW)
            'refund_reason' => $this->refund_reason,
            'refunded_by' => $this->refunded_by,
            'refunded_by_user' => $this->when($this->refundedBy, [
                'id' => $this->refundedBy?->id,
                'name' => $this->refundedBy?->full_name,
            ]),
            'refunded_at' => $this->refunded_at?->format('Y-m-d H:i:s'),
            'refunded_at_formatted' => $this->refunded_at?->format('M d, Y h:i A'),
            
            // Staff
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->full_name,
                'username' => $this->createdBy?->username,
            ],
            
            // Payments
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'payment_count' => $this->payments?->count() ?? 0,
            
            // Extensions (NEW)
            'extensions' => $this->whenLoaded('extensions', function() {
                return $this->extensions->map(function($extension) {
                    return [
                        'id' => $extension->id,
                        'billing_id' => $extension->billing_id,
                        'extension_type' => $extension->extension_type,
                        'description' => $extension->description,
                        'amount' => number_format($extension->amount, 2),
                        'amount_raw' => (float) $extension->amount,
                        'quantity' => $extension->quantity,
                        'total_amount' => number_format($extension->total_amount, 2),
                        'total_amount_raw' => (float) $extension->total_amount,
                        'metadata' => $extension->metadata,
                        'added_by' => $extension->added_by,
                        'added_by_user' => $extension->addedBy ? [
                            'id' => $extension->addedBy->id,
                            'name' => $extension->addedBy->full_name,
                        ] : null,
                        'created_at' => $extension->created_at->format('Y-m-d H:i:s'),
                    ];
                });
            }),
            'extensions_count' => $this->extensions?->count() ?? 0,
            
            // Billable details (when loaded)
            'billable' => $this->when($this->relationLoaded('billable'), function() use ($billable) {
                if ($billable instanceof Booking) {
                    return [
                        'type' => 'Booking',
                        'reference' => $billable->booking_reference,
                        'check_in' => $billable->check_in_datetime?->format('Y-m-d H:i:s'),
                        'check_out' => $billable->check_out_datetime?->format('Y-m-d H:i:s'),
                        'status' => $billable->booking_status,
                    ];
                } elseif ($billable instanceof GuestEntry) {
                    return [
                        'type' => 'GuestEntry',
                        'reference' => $billable->entry_reference,
                        'entry_date' => $billable->entry_date?->format('Y-m-d'),
                        'total_guests' => $billable->total_guests,
                        'is_checked_out' => $billable->is_checked_out,
                    ];
                }
                return null;
            }),
            
            'notes' => $this->notes,
            
            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get transaction reference
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

    /**
     * Get payment status label
     */
    private function getPaymentStatusLabel(): string
    {
        return match($this->payment_status) {
            'unpaid' => 'Unpaid',
            'partial' => 'Partially Paid',
            'paid' => 'Fully Paid',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    /**
     * Get billing status label
     */
    private function getBillingStatusLabel(): string
    {
        return match($this->billing_status) {
            'pending' => 'Pending',
            'active' => 'Active',
            'completed' => 'Completed',
            'voided' => 'Voided',
            default => 'Unknown',
        };
    }

    /**
     * Check if billing is overdue
     */
    private function isOverdue(): bool
    {
        if (!$this->due_date || in_array($this->payment_status, ['paid', 'refunded', 'cancelled'])) {
            return false;
        }
        
        return now()->isAfter($this->due_date);
    }

    /**
     * Get days overdue
     */
    private function getDaysOverdue(): ?int
    {
        if (!$this->isOverdue()) {
            return null;
        }
        
        return now()->diffInDays($this->due_date);
    }
}