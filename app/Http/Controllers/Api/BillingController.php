<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use App\Models\BillingExtension;
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
     * ✅ UPDATED: Default to show only unpaid/partial (exclude fully paid)
     */
    public function index(Request $request)
    {
        $query = Billing::with([
            'billable',
            'payments',
            'createdBy',
        ]);

        // ✅ UPDATED: Filter by payment status
        if ($request->has('payment_status')) {
            if ($request->payment_status === 'all') {
                // Show all payment statuses
            } else {
                $query->where('payment_status', $request->payment_status);
            }
        }
        // ✅ REMOVED: Default filter that excludes paid billings
        // Billings should remain visible even after full payment until checkout

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

    /**
     * Add extension charge to billing (mid-stay)
     * ✅ ENHANCED: Mirrors booking creation logic with facility/rate/discount selection
     * Supports both smart mode (facility_id + rate_id) and simple mode (amount + quantity)
     */
    public function addExtension(\App\Http\Requests\Billing\AddExtensionRequest $request, $id)
    {
        $billing = Billing::with('billable')->findOrFail($id);
        
        // ✅ VALIDATION: Extensions can only be added to pending, confirmed, or active billings
        if (!in_array($billing->billing_status, ['pending', 'confirmed', 'active'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Extensions can only be added to pending, confirmed, or active billings. Current status: ' . $billing->billing_status,
            ], 422);
        }
        
        return DB::transaction(function () use ($request, $billing) {
            try {
                $createdExtensions = [];
                $calculationBreakdown = [
                    'facility_subtotal' => 0,
                    'guest_charges_subtotal' => 0,
                    'services_subtotal' => 0,
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'total_amount' => 0,
                ];
                
                // ========================================
                // STEP 1: Process Facility Extensions (Smart Mode)
                // ========================================
                if ($request->has('facilities') && !empty($request->facilities)) {
                    foreach ($request->facilities as $facilityData) {
                        $facility = \App\Models\Facility::findOrFail($facilityData['facility_id']);
                        $rate = \App\Models\Rate::findOrFail($facilityData['rate_id']);
                        $quantity = $facilityData['quantity'];
                        $hours = $facilityData['hours'] ?? null;
                        
                        // Calculate amount based on rate type
                        if ($hours && $rate->extension_fee) {
                            // Hourly rate: extension_fee × hours × quantity
                            $amount = $rate->extension_fee;
                            $totalAmount = $rate->extension_fee * $hours * $quantity;
                            $description = sprintf(
                                '%s - %s (%.1f hours × %d)',
                                $facility->name,
                                $rate->rate_name,
                                $hours,
                                $quantity
                            );
                        } else {
                            // Day rate: base_price × quantity
                            $amount = $rate->base_price;
                            $totalAmount = $rate->base_price * $quantity;
                            $hours = null;
                            $description = sprintf(
                                '%s - %s (× %d)',
                                $facility->name,
                                $rate->rate_name,
                                $quantity
                            );
                        }
                        
                        // Create facility extension record
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'facility_id' => $facility->id,
                            'rate_id' => $rate->id,
                            'extension_type' => 'facility',
                            'description' => $description,
                            'amount' => $amount,
                            'quantity' => $quantity,
                            'hours' => $hours,
                            'total_amount' => $totalAmount,
                            'metadata' => [
                                'facility_name' => $facility->name,
                                'rate_name' => $rate->rate_name,
                                'calculation_method' => $hours ? 'per_hour' : 'per_unit',
                            ],
                            'added_by' => auth()->id(),
                        ]);
                        
                        $createdExtensions[] = $extension;
                        $calculationBreakdown['facility_subtotal'] += $totalAmount;
                    }
                }
                
                // ========================================
                // STEP 2: Process Guest Charges with Direct Discounts
                // ========================================
                if ($request->has('guest_charges') && !empty($request->guest_charges)) {
                    foreach ($request->guest_charges as $guestCharge) {
                        $guestCount = $guestCharge['count'];
                        $ratePerGuest = $guestCharge['rate_per_guest'];
                        $baseAmount = $ratePerGuest * $guestCount;
                        $discountAmount = 0;
                        $discountId = null;
                        
                        // Apply Direct discount if provided
                        if (isset($guestCharge['discount_id']) && $guestCharge['discount_id']) {
                            $discount = \App\Models\Discount::find($guestCharge['discount_id']);
                            if ($discount && $discount->is_active && $discount->category === 'Direct_Discount') {
                                $discountId = $discount->id;
                                
                                if ($discount->type === 'Percentage') {
                                    $discountAmount = ($ratePerGuest * ($discount->value / 100)) * $guestCount;
                                } else {
                                    $discountAmount = min($discount->value, $ratePerGuest) * $guestCount;
                                }
                            }
                        }
                        
                        $finalAmount = $baseAmount - $discountAmount;
                        
                        // Create guest extension record
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'discount_id' => $discountId,
                            'extension_type' => 'guest',
                            'description' => sprintf(
                                '%d additional %s guest(s)%s',
                                $guestCount,
                                $guestCharge['guest_type'],
                                $discountAmount > 0 ? ' (with discount)' : ''
                            ),
                            'amount' => $ratePerGuest,
                            'quantity' => $guestCount,
                            'discount_amount' => $discountAmount,
                            'total_amount' => $finalAmount,
                            'metadata' => [
                                'guest_type' => $guestCharge['guest_type'],
                                'rate_per_guest' => $ratePerGuest,
                                'discount_applied' => $discountAmount > 0,
                            ],
                            'added_by' => auth()->id(),
                        ]);
                        
                        $createdExtensions[] = $extension;
                        $calculationBreakdown['guest_charges_subtotal'] += $finalAmount;
                        $calculationBreakdown['discount_amount'] += $discountAmount;
                    }
                }
                
                // ========================================
                // STEP 3: Process Guest Discounts (Alternative Format)
                // ========================================
                if ($request->has('guest_discounts') && !empty($request->guest_discounts)) {
                    // This follows the exact same pattern as guest_charges but assumes entrance rate context
                    foreach ($request->guest_discounts as $guestDiscount) {
                        $guestCount = $guestDiscount['count'];
                        $discount = \App\Models\Discount::findOrFail($guestDiscount['discount_id']);
                        
                        // You would need to get the base rate from context (e.g., entrance rate)
                        // For now, this creates a placeholder that should be filled with actual rate
                        $baseRatePerGuest = 0; // TODO: Get from billing context or require in request
                        
                        if ($discount->type === 'Percentage') {
                            $discountAmount = ($baseRatePerGuest * ($discount->value / 100)) * $guestCount;
                        } else {
                            $discountAmount = min($discount->value, $baseRatePerGuest) * $guestCount;
                        }
                        
                        $finalAmount = ($baseRatePerGuest * $guestCount) - $discountAmount;
                        
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'discount_id' => $discount->id,
                            'extension_type' => 'guest',
                            'description' => sprintf(
                                '%d %s guest(s) with %s',
                                $guestCount,
                                $guestDiscount['guest_type'],
                                $discount->discount_name
                            ),
                            'amount' => $baseRatePerGuest,
                            'quantity' => $guestCount,
                            'discount_amount' => $discountAmount,
                            'total_amount' => $finalAmount,
                            'metadata' => [
                                'guest_type' => $guestDiscount['guest_type'],
                                'discount_name' => $discount->discount_name,
                            ],
                            'added_by' => auth()->id(),
                        ]);
                        
                        $createdExtensions[] = $extension;
                        $calculationBreakdown['guest_charges_subtotal'] += $finalAmount;
                        $calculationBreakdown['discount_amount'] += $discountAmount;
                    }
                }
                
                // ========================================
                // STEP 4: Process Third-Party Services
                // ========================================
                if ($request->has('third_party_services') && !empty($request->third_party_services)) {
                    foreach ($request->third_party_services as $service) {
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'extension_type' => 'service',
                            'description' => $service['service_name'],
                            'amount' => $service['amount'],
                            'quantity' => 1,
                            'total_amount' => $service['amount'],
                            'metadata' => [
                                'service_type' => 'third_party',
                            ],
                            'added_by' => auth()->id(),
                        ]);
                        
                        $createdExtensions[] = $extension;
                        $calculationBreakdown['services_subtotal'] += $service['amount'];
                    }
                }
                
                // ========================================
                // STEP 5: Process Simple Mode (Damage/Service with manual amount)
                // ========================================
                if ($request->has('amount') && $request->amount > 0) {
                    $quantity = $request->quantity ?? 1;
                    $totalAmount = $request->amount * $quantity;
                    
                    $extension = BillingExtension::create([
                        'billing_id' => $billing->id,
                        'extension_type' => $request->extension_type ?? 'service',
                        'description' => $request->description ?? 'Additional charge',
                        'amount' => $request->amount,
                        'quantity' => $quantity,
                        'total_amount' => $totalAmount,
                        'metadata' => $request->metadata ?? [],
                        'added_by' => auth()->id(),
                    ]);
                    
                    $createdExtensions[] = $extension;
                    
                    // Add to appropriate subtotal based on type
                    if ($request->extension_type === 'facility') {
                        $calculationBreakdown['facility_subtotal'] += $totalAmount;
                    } elseif ($request->extension_type === 'guest') {
                        $calculationBreakdown['guest_charges_subtotal'] += $totalAmount;
                    } else {
                        $calculationBreakdown['services_subtotal'] += $totalAmount;
                    }
                }
                
                // ========================================
                // STEP 6: Calculate Totals
                // ========================================
                $calculationBreakdown['subtotal'] = 
                    $calculationBreakdown['facility_subtotal'] +
                    $calculationBreakdown['guest_charges_subtotal'] +
                    $calculationBreakdown['services_subtotal'];
                
                // Apply Seasonal discount if provided
                if ($request->discount_id) {
                    $seasonalDiscount = \App\Models\Discount::find($request->discount_id);
                    if ($seasonalDiscount && $seasonalDiscount->is_active && $seasonalDiscount->category === 'Seasonal_Discount') {
                        if ($seasonalDiscount->type === 'Percentage') {
                            $seasonalDiscountAmount = $calculationBreakdown['subtotal'] * ($seasonalDiscount->value / 100);
                        } else {
                            $seasonalDiscountAmount = min($seasonalDiscount->value, $calculationBreakdown['subtotal']);
                        }
                        
                        $calculationBreakdown['discount_amount'] += $seasonalDiscountAmount;
                        $calculationBreakdown['seasonal_discount'] = $seasonalDiscountAmount;
                    }
                }
                
                // Apply Manual discount if provided
                if ($request->manual_discount_amount && $request->manual_discount_amount > 0) {
                    $calculationBreakdown['discount_amount'] += $request->manual_discount_amount;
                    $calculationBreakdown['manual_discount'] = $request->manual_discount_amount;
                }
                
                $calculationBreakdown['total_amount'] = max(0, $calculationBreakdown['subtotal'] - $calculationBreakdown['discount_amount']);
                
                // ========================================
                // STEP 7: Update Billing Totals
                // ========================================
                $billing->update([
                    'total_amount' => $billing->total_amount + $calculationBreakdown['total_amount'],
                    'balance' => $billing->balance + $calculationBreakdown['total_amount'],
                ]);
                
                // ========================================
                // STEP 8: Record Payment (if provided)
                // ========================================
                $payment = null;
                if ($request->payment_required && $request->payment_amount > 0) {
                    $payment = $billing->recordPayment(
                        amount: $request->payment_amount,
                        paymentMethod: $request->payment_method ?? 'Cash',
                        paymentType: 'partial',
                        changeAmount: 0,
                        referenceNumber: null,
                        notes: 'Extension payment',
                        receivedBy: auth()->id()
                    );
                }
                
                // ========================================
                // STEP 9: Log Extension
                // ========================================
                Log::info('Billing extensions added', [
                    'billing_id' => $billing->id,
                    'extension_count' => count($createdExtensions),
                    'total_amount' => $calculationBreakdown['total_amount'],
                    'discount_amount' => $calculationBreakdown['discount_amount'],
                    'added_by' => auth()->id(),
                    'payment_made' => $payment !== null,
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => count($createdExtensions) > 1 
                        ? sprintf('%d extensions added successfully', count($createdExtensions))
                        : 'Extension added successfully',
                    'data' => [
                        'extensions' => $createdExtensions,
                        'billing' => new BillingResource($billing->fresh(['billable', 'payments', 'extensions'])),
                        'payment' => $payment,
                        'calculation_breakdown' => $calculationBreakdown,
                    ],
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to add billing extension', [
                    'billing_id' => $billing->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }
}