<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\GuestEntry;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get paginated list of billings with filters
     */
    public function index(Request $request)
    {
        $query = Billing::with([
            'billable',
            'payments',
            'createdBy',
        ]);

        // Filter by payment status
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by billing status
        if ($request->has('billing_status') && $request->billing_status !== 'all') {
            $query->where('billing_status', $request->billing_status);
        }

        // Filter by billable type (Booking or GuestEntry)
        if ($request->has('billable_type') && $request->billable_type !== 'all') {
            $type = $request->billable_type === 'Booking' 
                ? Booking::class 
                : GuestEntry::class;
            $query->where('billable_type', $type);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('billed_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('billed_at', '<=', $request->date_to);
        }

        // Search by billing number or guest name
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            
            $query->where(function($q) use ($search) {
                $q->where('billing_number', 'like', "%{$search}%")
                  ->orWhereHasMorph('billable', [Booking::class, GuestEntry::class], 
                    function($billableQuery) use ($search) {
                        $billableQuery->where('guest_name', 'like', "%{$search}%");
                    }
                  );
            });
        }

        // Sort by latest first
        $query->orderBy('billed_at', 'desc')
              ->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $billings = $query->paginate($perPage);

        return BillingResource::collection($billings)->additional([
            'status' => 'success',
            'message' => 'Billings retrieved successfully'
        ]);
    }

    /**
     * Get single billing with all details
     */
    public function show($id)
    {
        $billing = Billing::with([
            'billable.facilities.facility',
            'billable.thirdPartyServices',
            'payments.receivedBy',
            'createdBy',
            'cancelledBy',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new BillingResource($billing),
        ]);
    }

    /**
     * Get all unpaid billings
     */
    public function unpaid(Request $request)
    {
        $query = Billing::with([
            'billable',
            'payments',
        ])->whereIn('payment_status', ['unpaid', 'partial']);

        // Filter by billable type
        if ($request->has('billable_type') && $request->billable_type !== 'all') {
            $type = $request->billable_type === 'Booking' 
                ? Booking::class 
                : GuestEntry::class;
            $query->where('billable_type', $type);
        }

        // Filter by overdue
        if ($request->has('overdue') && $request->overdue === 'true') {
            $query->where('due_date', '<', now())
                  ->whereNotNull('due_date');
        }

        $perPage = $request->input('per_page', 15);
        $billings = $query->orderBy('due_date', 'asc')->paginate($perPage);

        return BillingResource::collection($billings)->additional([
            'status' => 'success',
            'message' => 'Unpaid billings retrieved successfully'
        ]);
    }

    /**
     * Record a payment for a billing
     */
    public function recordPayment(Request $request, $id)
    {
        $request->validate([
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,credit_card,debit_card,other',
            'change_amount' => 'nullable|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $id) {
            try {
                $billing = Billing::with('billable')->findOrFail($id);

                // Validate billing status
                if ($billing->payment_status === 'paid') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This billing is already fully paid',
                    ], 400);
                }

                if (in_array($billing->billing_status, ['voided', 'cancelled'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot accept payment for a cancelled/voided billing',
                    ], 400);
                }

                // Record payment
                $paymentData = [
                    'amount_paid' => $request->amount_paid,
                    'payment_method' => $request->payment_method,
                    'change_amount' => $request->change_amount ?? 0,
                    'reference_number' => $request->reference_number,
                    'notes' => $request->notes,
                ];

                $payment = $this->billingService->recordPayment($billing, $paymentData);

                Log::info('Payment recorded via billing', [
                    'billing_id' => $billing->id,
                    'payment_id' => $payment->id,
                    'amount' => $request->amount_paid,
                    'received_by' => auth()->id(),
                ]);

                // Load updated billing
                $billing->load(['billable', 'payments.receivedBy', 'createdBy']);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment recorded successfully',
                    'data' => new BillingResource($billing),
                ], 201);

            } catch (\Exception $e) {
                Log::error('Payment recording failed', [
                    'billing_id' => $id,
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
     * Cancel/void a billing
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $id) {
            try {
                $billing = Billing::with('billable')->findOrFail($id);

                // Check if already cancelled
                if (in_array($billing->billing_status, ['voided', 'cancelled'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Billing is already cancelled/voided',
                    ], 400);
                }

                // Check if completed
                if ($billing->billing_status === 'completed') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot cancel a completed billing',
                    ], 400);
                }

                // Cancel billing
                $this->billingService->cancelBilling($billing, $request->reason);

                Log::info('Billing cancelled', [
                    'billing_id' => $billing->id,
                    'cancelled_by' => auth()->id(),
                    'reason' => $request->reason,
                ]);

                $billing->load(['billable', 'payments', 'createdBy', 'cancelledBy']);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Billing cancelled successfully',
                    'data' => new BillingResource($billing),
                ]);

            } catch (\Exception $e) {
                Log::error('Billing cancellation failed', [
                    'billing_id' => $id,
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
     * Get billing summary/statistics
     */
    public function summary(Request $request)
    {
        $query = Billing::query();

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('billed_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('billed_at', '<=', $request->date_to);
        }

        // Total billings
        $totalBillings = $query->count();

        // Total amounts
        $totalAmount = $query->sum('total_amount');
        $totalPaid = $query->sum('amount_paid');
        $totalBalance = $query->sum('balance');

        // By payment status
        $byPaymentStatus = Billing::query()
            ->when($request->has('date_from'), function($q) use ($request) {
                $q->whereDate('billed_at', '>=', $request->date_from);
            })
            ->when($request->has('date_to'), function($q) use ($request) {
                $q->whereDate('billed_at', '<=', $request->date_to);
            })
            ->select('payment_status', 
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('SUM(amount_paid) as paid'),
                DB::raw('SUM(balance) as balance')
            )
            ->groupBy('payment_status')
            ->get();

        // By billable type
        $byType = Billing::query()
            ->when($request->has('date_from'), function($q) use ($request) {
                $q->whereDate('billed_at', '>=', $request->date_from);
            })
            ->when($request->has('date_to'), function($q) use ($request) {
                $q->whereDate('billed_at', '<=', $request->date_to);
            })
            ->select('billable_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('SUM(amount_paid) as paid')
            )
            ->groupBy('billable_type')
            ->get()
            ->map(function($item) {
                $item->billable_type = class_basename($item->billable_type);
                return $item;
            });

        // Overdue billings
        $overdueBillings = Billing::where('payment_status', '!=', 'paid')
            ->where('due_date', '<', now())
            ->whereNotNull('due_date')
            ->count();

        $overdueAmount = Billing::where('payment_status', '!=', 'paid')
            ->where('due_date', '<', now())
            ->whereNotNull('due_date')
            ->sum('balance');

        return response()->json([
            'status' => 'success',
            'data' => [
                'overview' => [
                    'total_billings' => $totalBillings,
                    'total_amount' => number_format($totalAmount, 2),
                    'total_paid' => number_format($totalPaid, 2),
                    'total_balance' => number_format($totalBalance, 2),
                    'collection_rate' => $totalAmount > 0 
                        ? number_format(($totalPaid / $totalAmount) * 100, 2) . '%'
                        : '0%',
                ],
                'by_payment_status' => $byPaymentStatus,
                'by_type' => $byType,
                'overdue' => [
                    'count' => $overdueBillings,
                    'amount' => number_format($overdueAmount, 2),
                ],
            ],
        ]);
    }

    /**
     * Get billing by booking or guest entry
     */
    public function getByReference(Request $request)
    {
        $request->validate([
            'type' => 'required|in:Booking,GuestEntry',
            'id' => 'required|integer',
        ]);

        $type = $request->type === 'Booking' ? Booking::class : GuestEntry::class;

        $billing = Billing::with([
            'billable',
            'payments.receivedBy',
            'createdBy',
        ])->where('billable_type', $type)
          ->where('billable_id', $request->id)
          ->first();

        if (!$billing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Billing not found for this reference',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new BillingResource($billing),
        ]);
    }
}