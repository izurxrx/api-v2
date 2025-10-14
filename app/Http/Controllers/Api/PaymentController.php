<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\GuestEntry;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with('receivedBy');

        // Filter by transaction type
        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        // Filter by payment method
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Search
        if ($request->has('search')) {
            $query->where('transaction_reference', 'like', "%{$request->search}%");
        }

        $perPage = $request->input('per_page', 5);
        $payments = $query->latest('payment_date')->paginate($perPage);

        return $this->paginatedCollection($payments, PaymentResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_type' => 'required|in:Booking,Walk_In_Entry',
            'transaction_id' => 'required|integer',
            'payment_date' => 'required|date',
            'payment_time' => 'nullable|date_format:H:i',
            'payment_method' => 'required|string|max:20',
            'amount_paid' => 'required|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Verify transaction exists and get balance
            $transaction = $this->getTransaction($validated['transaction_type'], $validated['transaction_id']);
            
            if (!$transaction) {
                return response()->json([
                    'message' => 'Transaction not found',
                ], 404);
            }

            // Check if amount is valid
            if ($validated['amount_paid'] > $transaction->balance) {
                return response()->json([
                    'message' => 'Payment amount exceeds balance',
                ], 400);
            }

            // Generate payment reference
            $reference = $this->generatePaymentReference();

            // Create payment
            $payment = Payment::create([
                'transaction_reference' => $reference,
                'transaction_type' => $validated['transaction_type'],
                'transaction_id' => $validated['transaction_id'],
                'payment_date' => $validated['payment_date'],
                'payment_time' => $validated['payment_time'] ?? now()->format('H:i:s'),
                'payment_method' => $validated['payment_method'],
                'amount_paid' => $validated['amount_paid'],
                'change_amount' => $validated['change_amount'] ?? 0,
                'received_by' => auth()->id(),
                'payment_reference' => $validated['payment_reference'],
                'notes' => $validated['notes'],
            ]);

            // Update transaction payment status
            $newAmountPaid = $transaction->amount_paid + $validated['amount_paid'];
            $newBalance = $transaction->total_amount - $newAmountPaid;

            $paymentStatus = 'Unpaid';
            if ($newBalance <= 0) {
                $paymentStatus = 'Paid';
            } elseif ($newAmountPaid > 0) {
                $paymentStatus = 'Partial';
            }

            $transaction->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'payment_status' => $paymentStatus,
            ]);

            DB::commit();

            $payment->load('receivedBy');

            return response()->json([
                'message' => 'Payment recorded successfully',
                'data' => new PaymentResource($payment),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $payment = Payment::with('receivedBy')->findOrFail($id);

        return response()->json([
            'data' => new PaymentResource($payment),
        ]);
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);

        DB::beginTransaction();
        try {
            // Get transaction and reverse payment
            $transaction = $this->getTransaction($payment->transaction_type, $payment->transaction_id);
            
            if ($transaction) {
                $newAmountPaid = $transaction->amount_paid - $payment->amount_paid;
                $newBalance = $transaction->total_amount - $newAmountPaid;

                $paymentStatus = 'Unpaid';
                if ($newBalance <= 0) {
                    $paymentStatus = 'Paid';
                } elseif ($newAmountPaid > 0) {
                    $paymentStatus = 'Partial';
                }

                $transaction->update([
                    'amount_paid' => $newAmountPaid,
                    'balance' => $newBalance,
                    'payment_status' => $paymentStatus,
                ]);
            }

            $payment->delete();

            DB::commit();

            return response()->json([
                'message' => 'Payment deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper methods
    private function getTransaction($type, $id)
    {
        if ($type === 'Booking') {
            return Booking::find($id);
        }
        return GuestEntry::find($id);
    }

    private function generatePaymentReference()
    {
        $date = now()->format('Ymd');
        $count = Payment::whereDate('created_at', today())->count() + 1;
        return 'PAY' . $date . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}