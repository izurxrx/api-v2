<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    public function archived(Request $request)
    {
        $query = Payment::onlyTrashed()->with(['receiver', 'booking', 'guestEntry']);

        // Filter by transaction type
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('payment_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        // Filter by receiver
        if ($request->filled('received_by')) {
            $query->where('received_by', $request->received_by);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'deleted_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $payments = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            PaymentResource::collection($payments),
            'Archived payments retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = Payment::with(['receiver', 'booking', 'guestEntry']);

        // Filter by transaction type
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('payment_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        // Filter by receiver
        if ($request->filled('received_by')) {
            $query->where('received_by', $request->received_by);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_reference', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'payment_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $payments = $query->paginate($request->get('per_page', 15));

        return $this->successResponse(
            PaymentResource::collection($payments),
            'Payments retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_type' => 'required|in:Guest_Entry,Booking',
            'transaction_id' => 'required|integer',
            'payment_method' => 'required|in:Cash,Credit_Card,Debit_Card,Bank_Transfer,GCash,PayMaya',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        // Validate transaction exists and get balance
        $transaction = $this->getTransaction($validated['transaction_type'], $validated['transaction_id']);
        
        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        // Check if payment amount doesn't exceed balance
        if ($validated['amount_paid'] > $transaction->balance) {
            return $this->errorResponse(
                "Payment amount ({$validated['amount_paid']}) exceeds outstanding balance ({$transaction->balance})",
                422
            );
        }

        DB::beginTransaction();
        
        try {
            // Generate transaction reference
            $transactionReference = $this->generateTransactionReference();

            // Create payment
            $payment = Payment::create([
                'transaction_reference' => $transactionReference,
                'transaction_type' => $validated['transaction_type'],
                'transaction_id' => $validated['transaction_id'],
                'payment_date' => $validated['payment_date'],
                'payment_time' => $validated['payment_time'] ?? now()->format('H:i:s'),
                'payment_method' => $validated['payment_method'],
                'amount_paid' => $validated['amount_paid'],
                'received_by' => Auth::id(),
                'notes' => $validated['notes'],
            ]);

            // Update transaction amounts
            $newAmountPaid = $transaction->amount_paid + $validated['amount_paid'];
            $newBalance = $transaction->total_amount - $newAmountPaid;
            $paymentStatus = $newBalance <= 0 ? 'Paid' : ($newAmountPaid > 0 ? 'Partial' : 'Unpaid');

            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'payment_status' => $paymentStatus,
            ]);

            DB::commit();

            return $this->successResponse(
                new PaymentResource($payment->load(['receiver', 'booking', 'guestEntry'])),
                'Payment recorded successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to record payment: ' . $e->getMessage(), 500);
        }
    }

    public function show(Payment $payment)
    {
        $payment->load(['receiver', 'booking', 'guestEntry']);
        return $this->successResponse(
            new PaymentResource($payment),
            'Payment retrieved successfully'
        );
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:Cash,Credit_Card,Debit_Card,Bank_Transfer,GCash,PayMaya',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        
        try {
            $oldAmount = $payment->amount_paid;
            $transaction = $this->getTransaction($payment->transaction_type, $payment->transaction_id);

            // Calculate new totals
            $adjustedBalance = $transaction->balance + $oldAmount; // Remove old payment
            
            // Check if new amount doesn't exceed adjusted balance
            if ($validated['amount_paid'] > $adjustedBalance) {
                return $this->errorResponse(
                    "Updated payment amount exceeds available balance",
                    422
                );
            }

            // Update payment
            $payment->update($validated);

            // Update transaction amounts
            $newAmountPaid = $transaction->amount_paid - $oldAmount + $validated['amount_paid'];
            $newBalance = $transaction->total_amount - $newAmountPaid;
            $paymentStatus = $newBalance <= 0 ? 'Paid' : ($newAmountPaid > 0 ? 'Partial' : 'Unpaid');

            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'payment_status' => $paymentStatus,
            ]);

            DB::commit();

            return $this->successResponse(
                new PaymentResource($payment->fresh()->load(['receiver', 'booking', 'guestEntry'])),
                'Payment updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to update payment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Payment $payment)
    {
        DB::beginTransaction();
        
        try {
            $transaction = $this->getTransaction($payment->transaction_type, $payment->transaction_id);
            
            // Reverse payment amounts
            $newAmountPaid = $transaction->amount_paid - $payment->amount_paid;
            $newBalance = $transaction->total_amount - $newAmountPaid;
            $paymentStatus = $newBalance <= 0 ? 'Paid' : ($newAmountPaid > 0 ? 'Partial' : 'Unpaid');

            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'payment_status' => $paymentStatus,
            ]);

            $payment->delete();

            DB::commit();

            return $this->successResponse(
                null,
                'Payment deleted successfully'
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to delete payment: ' . $e->getMessage(), 500);
        }
    }

    public function getPaymentMethods()
    {
        return $this->successResponse([
            'Cash',
            'Credit_Card',
            'Debit_Card',
            'Bank_Transfer',
            'GCash',
            'PayMaya'
        ]);
    }

    private function getTransaction($type, $id)
    {
        switch ($type) {
            case 'Guest_Entry':
                return \App\Models\GuestEntry::find($id);
            case 'Booking':
                return \App\Models\Booking::find($id);
            default:
                return null;
        }
    }

    private function generateTransactionReference()
    {
        $date = now()->format('Ymd');
        $lastPayment = Payment::whereDate('created_at', today())
                             ->orderBy('id', 'desc')
                             ->first();

        $sequence = $lastPayment ? (int) substr($lastPayment->transaction_reference, -3) + 1 : 1;

        return 'PAY' . $date . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}