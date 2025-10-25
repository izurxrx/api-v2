<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Billing;
use App\Models\Payment;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get paginated list of payments with filters
     */
    public function index(Request $request)
    {
        // ✅ Check permission using YOUR permission name
        if (!auth()->user()->can('view-payments')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to view payments.',
            ], 403);
        }

        $query = Payment::with([
            'billing.billable',
            'receivedBy'
        ]);

        // Filter by transaction type (via billing's billable)
        if ($request->has('transaction_type') && $request->transaction_type !== 'all') {
            $query->whereHasMorph('billing.billable', 
                [$request->transaction_type], 
                function ($q) {}
            );
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        // Filter by payment method
        if ($request->has('payment_method') && $request->payment_method !== 'all') {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by payment type
        if ($request->has('payment_type') && $request->payment_type !== 'all') {
            $query->where('payment_type', $request->payment_type);
        }

        // Search by payment number, billing number, or guest name
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            
            $query->where(function($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('billing', function($billingQuery) use ($search) {
                      $billingQuery->where('billing_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('billing.billable', function($billableQuery) use ($search) {
                      $billableQuery->where('guest_name', 'like', "%{$search}%");
                  });
            });
        }

        // Sort by latest first
        $query->orderBy('payment_date', 'desc')
              ->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $payments = $query->paginate($perPage);

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
        // ✅ Check permission
        if (!auth()->user()->can('view-payments')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to view payments.',
            ], 403);
        }

        $payment = Payment::with([
            'billing.billable',
            'receivedBy'
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new PaymentResource($payment),
        ]);
    }

    /**
     * Store a new payment (additional payment for existing billing)
     */
    public function store(StorePaymentRequest $request)
    {
        // ✅ Check permission
        if (!auth()->user()->can('process-payments')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to process payments.',
            ], 403);
        }

        DB::beginTransaction();
        try {
            $validated = $request->validated();
            
            // Get billing record
            $billing = Billing::with('billable')->findOrFail($validated['billing_id']);
            
            // Check if billing is already paid
            if ($billing->payment_status === 'paid') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This billing is already fully paid',
                    'billing_number' => $billing->billing_number,
                ], 400);
            }

            // Check if billing is cancelled/voided
            if (in_array($billing->billing_status, ['voided', 'cancelled'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot accept payment for a cancelled/voided billing',
                    'billing_status' => $billing->billing_status,
                ], 400);
            }

            // Validate payment amount
            $amountPaid = $validated['amount_paid'];
            
            if ($amountPaid > $billing->balance) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment amount exceeds remaining balance',
                    'balance' => $billing->balance,
                    'amount_paid' => $amountPaid,
                ], 400);
            }

            if ($amountPaid <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment amount must be greater than zero',
                ], 400);
            }

            // Record payment using BillingService
            $paymentData = [
                'amount_paid' => $amountPaid,
                'payment_method' => $validated['payment_method'],
                'change_amount' => $validated['change_amount'] ?? 0,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            $payment = $this->billingService->recordPayment($billing, $paymentData);

            DB::commit();

            Log::info('Payment recorded', [
                'payment_id' => $payment->id,
                'billing_id' => $billing->id,
                'amount' => $amountPaid,
                'received_by' => auth()->id(),
            ]);

            // Load relationships
            $payment->load(['billing.billable', 'receivedBy']);

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully',
                'data' => new PaymentResource($payment),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Payment creation failed', [
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

    /**
     * ✅ NEW: Reverse a payment (Manager/Admin only)
     */
    public function reverse(Request $request, $id)
    {
        // ✅ Check permission - Only Manager and Admin can reverse
        if (!auth()->user()->can('reverse-payments')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only Managers and Admins can reverse payments.',
            ], 403);
        }

        $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        return DB::transaction(function () use ($request, $id) {
            try {
                $payment = Payment::with('billing.billable')->findOrFail($id);

                // Check if already reversed
                if ($payment->payment_type === 'reversal') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This is already a reversal payment',
                    ], 400);
                }

                // Check if payment was already reversed
                if (str_contains($payment->notes ?? '', '[REVERSED')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This payment has already been reversed',
                    ], 400);
                }

                // Reverse payment using BillingService
                $reversal = $this->billingService->reversePayment($payment, $request->reason);

                Log::warning('Payment reversed via API', [
                    'original_payment_id' => $payment->id,
                    'reversal_payment_id' => $reversal->id,
                    'amount' => $payment->amount,
                    'reason' => $request->reason,
                    'reversed_by' => auth()->id(),
                    'reversed_by_role' => auth()->user()->getRoleNames()->first(),
                ]);

                // Load relationships
                $reversal->load(['billing.billable', 'receivedBy']);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment reversed successfully',
                    'data' => [
                        'reversal' => new PaymentResource($reversal),
                        'original_payment' => new PaymentResource($payment->fresh()),
                    ],
                ]);

            } catch (\Exception $e) {
                Log::error('Payment reversal failed', [
                    'payment_id' => $id,
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
        });
    }

    /**
     * ❌ DEPRECATED: Delete payment functionality removed
     * Use reverse() instead for proper accounting
     */
    public function destroy($id)
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Payment deletion is no longer supported. Please use the reverse payment endpoint instead.',
            'alternative_endpoint' => route('payments.reverse', $id),
        ], 410); // 410 Gone
    }

    /**
     * Get all payments for a specific billing
     */
    public function getPaymentsByBilling($billingId)
    {
        // ✅ Check permission
        if (!auth()->user()->can('view-payments')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to view payments.',
            ], 403);
        }

        $billing = Billing::with(['billable', 'payments.receivedBy'])
            ->findOrFail($billingId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'billing' => [
                    'id' => $billing->id,
                    'billing_number' => $billing->billing_number,
                    'total_amount' => $billing->total_amount,
                    'amount_paid' => $billing->amount_paid,
                    'balance' => $billing->balance,
                    'payment_status' => $billing->payment_status,
                ],
                'payments' => PaymentResource::collection($billing->payments),
            ],
        ]);
    }

    /**
     * Get payment summary/statistics
     */
    public function summary(Request $request)
    {
        // ✅ Check permission - Only Manager/Admin can view summaries
        if (!auth()->user()->can('view-financial-reports')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to view payment summaries.',
            ], 403);
        }

        $query = Payment::query();

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('payment_date', '<=', $request->date_to);
        }

        // Calculate totals
        $totalPayments = $query->count();
        $totalAmount = $query->sum('amount');

        // Group by payment method
        $byMethod = Payment::query()
            ->when($request->has('date_from'), function($q) use ($request) {
                $q->whereDate('payment_date', '>=', $request->date_from);
            })
            ->when($request->has('date_to'), function($q) use ($request) {
                $q->whereDate('payment_date', '<=', $request->date_to);
            })
            ->select('payment_method', 
                DB::raw('COUNT(*) as count'), 
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('payment_method')
            ->get();

        // Group by payment type
        $byType = Payment::query()
            ->when($request->has('date_from'), function($q) use ($request) {
                $q->whereDate('payment_date', '>=', $request->date_from);
            })
            ->when($request->has('date_to'), function($q) use ($request) {
                $q->whereDate('payment_date', '<=', $request->date_to);
            })
            ->select('payment_type', 
                DB::raw('COUNT(*) as count'), 
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('payment_type')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_payments' => $totalPayments,
                'total_amount' => number_format($totalAmount, 2),
                'by_payment_method' => $byMethod,
                'by_payment_type' => $byType,
            ],
        ]);
    }
}