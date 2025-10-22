<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Get paginated list of payments with filters
     */
    public function index(Request $request)
    {
        // ✅ REMOVED ->with(['receivedBy']) to prevent eager loading
        $query = Payment::query();

        // Filter by transaction type
        if ($request->has('transaction_type') && $request->transaction_type !== 'all') {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        // Search by reference, guest name, or payment reference
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            
            $query->where(function($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%")
                ->orWhere('payment_reference', 'like', "%{$search}%")
                ->orWhere('payment_method', 'like', "%{$search}%");
                
                $q->orWhereHas('booking', function($bookingQuery) use ($search) {
                    $bookingQuery->where('guest_name', 'like', "%{$search}%");
                });
                
                $q->orWhereHas('guestEntry', function($entryQuery) use ($search) {
                    $entryQuery->where('guest_name', 'like', "%{$search}%");
                });
            });
        }

        // Sort by latest first
        $query->orderBy('payment_date', 'desc')
            ->orderBy('payment_time', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $payments = $query->paginate($perPage);

        // ✅ The Resource will handle loading receivedBy when needed
        return PaymentResource::collection($payments)->additional([
            'status' => 'success',
            'message' => 'Payments retrieved successfully'
        ]);
    }

    /**
     * Get single payment by ID
     */
    public function show($id)
    {
        // ✅ REMOVED ->with(['receivedBy']) here too
        $payment = Payment::with(['booking', 'guestEntry'])
            ->findOrFail($id);

        return response()->json([
            'data' => new PaymentResource($payment),
        ]);
    }

    /**
     * Store a new payment
     */
    public function store(StorePaymentRequest $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            
            // Get the transaction (booking or guest entry)
            $transaction = $this->getTransaction(
                $validated['transaction_type'], 
                $validated['transaction_id']
            );
            
            if (!$transaction) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction not found',
                ], 404);
            }

            // Validate payment doesn't exceed balance
            if ($validated['amount_paid'] > $transaction->balance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment amount exceeds remaining balance',
                    'balance' => $transaction->balance,
                ], 400);
            }

            // Generate unique payment reference
            $reference = $this->generatePaymentReference();

            // Create payment record
            $payment = Payment::create([
                'transaction_reference' => $reference,
                'transaction_type' => $validated['transaction_type'],
                'transaction_id' => $validated['transaction_id'],
                'payment_date' => $validated['payment_date'],
                'payment_time' => $validated['payment_time'] ?? now()->format('H:i'),
                'payment_method' => $validated['payment_method'],
                'amount_paid' => $validated['amount_paid'],
                'change_amount' => $validated['change_amount'] ?? 0,
                'received_by' => auth()->id(),
                'payment_reference' => $validated['payment_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update transaction amounts
            $newAmountPaid = $transaction->amount_paid + $validated['amount_paid'];
            $newBalance = $transaction->total_amount - $newAmountPaid;

            // Determine payment status
            $paymentStatus = 'Unpaid';
            if ($newBalance <= 0) {
                $paymentStatus = 'Paid';
            } elseif ($newAmountPaid > 0) {
                $paymentStatus = 'Partial';
            }

            // Update transaction
            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'balance' => max(0, $newBalance),
                'payment_status' => $paymentStatus,
            ]);

            DB::commit();

            // ✅ REMOVED ->load('receivedBy') - let Resource handle it
            // The resource will lazy load it only when needed

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully',
                'data' => new PaymentResource($payment),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Payment creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user' => auth()->id(),
                'request' => $request->except('password')
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record payment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);

        // ✅ CHECK TIME RESTRICTION
        $createdAt = $payment->created_at;
        $minutesSinceCreation = $createdAt->diffInMinutes(now());
        
        if ($minutesSinceCreation > 5) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment can only be deleted within 5 minutes of creation',
                'created_at' => $payment->created_at,
                'current_time' => now(),
            ], 403);
        }

        return DB::transaction(function () use ($payment, $id) {
            try {
                // Get the transaction
                $transaction = $this->getTransaction(
                    $payment->transaction_type, 
                    $payment->transaction_id
                );
                
                if (!$transaction) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Associated transaction not found'
                    ], 404);
                }

                // ✅ CHECK TRANSACTION STATUS
                if ($payment->transaction_type === 'Booking') {
                    if (in_array($transaction->booking_status, ['Checked_In', 'Checked_Out', 'Cancelled'])) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Cannot delete payment for a booking that is checked in, checked out, or cancelled',
                            'booking_status' => $transaction->booking_status,
                        ], 400);
                    }
                } elseif ($payment->transaction_type === 'GuestEntry') {
                    if ($transaction->is_checked_out) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Cannot delete payment for a checked-out guest entry'
                        ], 400);
                    }
                }
                
                // Reverse the payment amounts
                $newAmountPaid = max(0, $transaction->amount_paid - $payment->amount_paid);
                $newBalance = $transaction->total_amount - $newAmountPaid;

                // Determine new payment status
                $paymentStatus = 'Unpaid';
                if ($newBalance <= 0) {
                    $paymentStatus = 'Paid';
                } elseif ($newAmountPaid > 0) {
                    $paymentStatus = 'Partial';
                }

                // Update transaction
                $transaction->update([
                    'amount_paid' => $newAmountPaid,
                    'balance' => max(0, $newBalance),
                    'payment_status' => $paymentStatus,
                ]);

                // Delete payment
                $payment->delete();

                Log::info('Payment deleted', [
                    'payment_id' => $id,
                    'transaction_type' => $payment->transaction_type,
                    'transaction_id' => $payment->transaction_id,
                    'deleted_by' => auth()->id(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment deleted successfully',
                ]);

            } catch (\Exception $e) {
                Log::error('Payment deletion failed', [
                    'payment_id' => $id,
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                ]);
                
                throw $e;
            }
        });
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Get transaction (Booking or GuestEntry) by type and ID
     */
    private function getTransaction($type, $id)
    {
        if ($type === 'Booking') {
            return Booking::find($id);
        }
        return GuestEntry::find($id);
    }

    /**
     * Generate unique payment reference number
     */
    private function generatePaymentReference()
    {
        $date = now()->format('Ymd');
        $lastPayment = Payment::whereDate('created_at', today())
            ->where('transaction_reference', 'like', "PAY{$date}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int)substr($lastPayment->transaction_reference, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "PAY{$date}{$newNumber}";
    }
}