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
     * Add multiple extensions to a billing (bulk endpoint)
     * Atomic transaction - all succeed or all fail
     * Entry type restrictions enforced per extension
     * Optional payment recording after all extensions
     */
    public function addBulkExtensions(\App\Http\Requests\Billing\AddBulkExtensionRequest $request, $id)
    {
        $billing = Billing::with('billable')->findOrFail($id);
        
        return DB::transaction(function () use ($billing, $request) {
            try {
                $allExtensions = [];
                $allCalculations = [];
                $grandTotal = 0;

                // Process each extension in the array
                foreach ($request->extensions as $extensionIndex => $extensionData) {
                    $extensionTotal = 0;
                    $extensions = [];
                    $calculations = [];

                    // Get billable entity
                    $billable = $billing->billable;
                    if (!$billable) {
                        return response()->json([
                            'message' => 'Billable entity not found for this billing.',
                            'success' => false,
                        ], 404);
                    }

                    // Determine entry type
                    $entryType = $billing->getEntryType();
                    $isWalkIn = $entryType === 'walk_in';
                    $isBooking = $entryType === 'booking';
                    $bookingType = null;

                    if ($isBooking && $billable instanceof \App\Models\Booking) {
                        $bookingType = $billable->booking_type;
                    }

                    // === STEP 1: Validate entry type restrictions ===
                    if (!empty($extensionData['facilities'])) {
                        if ($isBooking && $bookingType === 'Swimming') {
                            return response()->json([
                                'message' => "Extension #{$extensionIndex}: Cannot add facilities to Swimming bookings.",
                                'success' => false,
                            ], 400);
                        }
                    }

                    if (!empty($extensionData['guest_charges'])) {
                        if ($isBooking && $bookingType === 'Package') {
                            return response()->json([
                                'message' => "Extension #{$extensionIndex}: Cannot add guests to Package bookings.",
                                'success' => false,
                            ], 400);
                        }
                    }

                    // === STEP 2: Process facilities ===
                    if (!empty($extensionData['facilities'])) {
                        foreach ($extensionData['facilities'] as $facilityExtension) {
                            $facility = \App\Models\Facility::findOrFail($facilityExtension['facility_id']);
                            $rate = \App\Models\Rate::findOrFail($facilityExtension['rate_id']);

                            $hours = $facilityExtension['hours'] ?? 1;
                            if ($isWalkIn && $billable instanceof \App\Models\GuestEntry) {
                                $hours = $billable->number_of_hours ?? 1;
                            }

                            $amount = $rate->base_price;
                            $quantity = $facilityExtension['quantity'] ?? 1;
                            $subtotal = $amount * $hours * $quantity;

                            $extension = BillingExtension::create([
                                'billing_id' => $billing->id,
                                'extension_type' => 'facility',
                                'amount' => $amount,
                                'quantity' => $quantity,
                                'hours' => $hours,
                                'total_amount' => $subtotal,
                                'metadata' => [
                                    'facility_id' => $facility->id,
                                    'facility_name' => $facility->facility_name,
                                    'rate_id' => $rate->id,
                                    'rate_type' => $rate->rate_type,
                                ],
                            ]);

                            $extensionTotal += $subtotal;
                            $extensions[] = $extension;

                            $calculations[] = [
                                'type' => 'facility',
                                'facility' => $facility->facility_name,
                                'rate' => $rate->rate_type,
                                'amount' => $amount,
                                'hours' => $hours,
                                'quantity' => $quantity,
                                'subtotal' => $subtotal,
                            ];
                        }
                    }

                    // === STEP 3: Process guest charges ===
                    if (!empty($extensionData['guest_charges'])) {
                        $perGuestRates = $billing->getPerGuestRates() ?? [];

                        foreach ($extensionData['guest_charges'] as $guestCharge) {
                            $guestType = $guestCharge['guest_type'];
                            $count = $guestCharge['count'];

                            $ratePerGuest = $guestCharge['rate_per_guest'] ?? null;
                            if (!$ratePerGuest) {
                                $guestTypeLower = strtolower($guestType);
                                $ratePerGuest = $perGuestRates[$guestTypeLower] ?? 0;

                                if (!$ratePerGuest) {
                                    return response()->json([
                                        'message' => "Extension #{$extensionIndex}: Rate not found for guest type: {$guestType}. Please provide rate_per_guest.",
                                        'success' => false,
                                    ], 400);
                                }
                            }

                            $subtotal = $ratePerGuest * $count;

                            $extension = BillingExtension::create([
                                'billing_id' => $billing->id,
                                'extension_type' => 'guest',
                                'amount' => $ratePerGuest,
                                'quantity' => $count,
                                'total_amount' => $subtotal,
                                'metadata' => [
                                    'guest_type' => $guestType,
                                ],
                            ]);

                            $extensionTotal += $subtotal;
                            $extensions[] = $extension;

                            $calculations[] = [
                                'type' => 'guest',
                                'guest_type' => $guestType,
                                'rate_per_guest' => $ratePerGuest,
                                'count' => $count,
                                'subtotal' => $subtotal,
                            ];
                        }
                    }

                    // === STEP 4: Process third-party services ===
                    if (!empty($extensionData['third_party_services'])) {
                        foreach ($extensionData['third_party_services'] as $service) {
                            $amount = $service['amount'];
                            $serviceName = $service['service_name'];

                            $extension = BillingExtension::create([
                                'billing_id' => $billing->id,
                                'extension_type' => 'service',
                                'amount' => $amount,
                                'quantity' => 1,
                                'total_amount' => $amount,
                                'metadata' => [
                                    'service_name' => $serviceName,
                                ],
                            ]);

                            $extensionTotal += $amount;
                            $extensions[] = $extension;

                            $calculations[] = [
                                'type' => 'service',
                                'service_name' => $serviceName,
                                'amount' => $amount,
                            ];
                        }
                    }

                    // === STEP 5: Process simple mode ===
                    if (!empty($extensionData['simple_mode']) && isset($extensionData['amount'])) {
                        $amount = $extensionData['amount'];
                        $quantity = $extensionData['quantity'] ?? 1;
                        $subtotal = $amount * $quantity;

                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'extension_type' => 'damage',
                            'amount' => $amount,
                            'quantity' => $quantity,
                            'total_amount' => $subtotal,
                            'metadata' => [],
                        ]);

                        $extensionTotal += $subtotal;
                        $extensions[] = $extension;

                        $calculations[] = [
                            'type' => 'damage',
                            'amount' => $amount,
                            'quantity' => $quantity,
                            'subtotal' => $subtotal,
                        ];
                    }

                    $grandTotal += $extensionTotal;
                    $allExtensions = array_merge($allExtensions, $extensions);
                    $allCalculations = array_merge($allCalculations, $calculations);
                }

                // === STEP 6: Update billing totals ===
                $billing->subtotal += $grandTotal;
                $billing->total_amount += $grandTotal;
                $billing->balance += $grandTotal;
                $billing->save();

                // === STEP 7: Record optional payment ===
                $payment = null;
                if ($request->amount_paid > 0) {
                    $payment = \App\Models\Payment::create([
                        'billing_id' => $billing->id,
                        'amount' => $request->amount_paid,
                        'payment_method' => $request->payment_method,
                        'payment_date' => now(),
                        'notes' => $request->payment_notes ?? 'Payment for ' . count($allExtensions) . ' extension(s)',
                        'received_by' => auth()->id(),
                        'is_verified' => true,
                    ]);

                    $billing->amount_paid += $request->amount_paid;
                    $billing->balance -= $request->amount_paid;

                    if ($billing->balance <= 0) {
                        $billing->payment_status = 'paid';
                    } else {
                        $billing->payment_status = 'partial';
                    }

                    $billing->save();
                }

                // === STEP 8: Log and return ===
                Log::info('Bulk extensions added to billing', [
                    'billing_id' => $billing->id,
                    'extensions_count' => count($allExtensions),
                    'total_amount' => $grandTotal,
                    'payment_amount' => $request->amount_paid ?? 0,
                    'detailed_calculations' => $allCalculations,
                ]);

                return response()->json([
                    'message' => count($allExtensions) . ' extension(s) added successfully',
                    'success' => true,
                    'data' => [
                        'billing' => new \App\Http\Resources\BillingResource($billing->fresh(['extensions', 'payments'])),
                        'extensions' => $allExtensions,
                        'extension_summary' => [
                            'total_extension_amount' => $grandTotal,
                            'extensions_count' => count($allExtensions),
                            'detailed_calculations' => $allCalculations,
                        ],
                        'payment' => $payment,
                    ],
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error adding bulk extensions to billing', [
                    'billing_id' => $billing->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Add extension to a billing
     * Simplified version - NO discount logic (discounts only at creation)
     * Entry type restrictions enforced (walk_in, booking:Swimming, booking:Package)
     */
    public function addExtension(\App\Http\Requests\Billing\AddExtensionRequest $request, $id)
    {
        $billing = Billing::with('billable')->findOrFail($id);
        
        return DB::transaction(function () use ($billing, $request) {
            try {
                $totalExtensionAmount = 0;
                $extensions = [];
                $detailedCalculations = [];

                // Get billable (GuestEntry or Booking)
                $billable = $billing->billable;
                if (!$billable) {
                    return response()->json([
                        'message' => 'Billable entity not found for this billing.',
                        'success' => false,
                    ], 404);
                }

                // Determine entry type from metadata or billable type
                $entryType = $billing->getEntryType();
                $isWalkIn = $entryType === 'walk_in';
                $isBooking = $entryType === 'booking';
                $bookingType = null;

                if ($isBooking && $billable instanceof Booking) {
                    $bookingType = $billable->booking_type;
                }

                // === STEP 1: Entry Type Validation ===
                // Walk-ins: Can add facilities, guests, services (NO overtime)
                // Swimming bookings: Can add guests only (NO facilities, NO overtime)
                // Package bookings: Can add facilities, services (NO guests, YES overtime)

                // Validate facilities
                if (!empty($request->facilities)) {
                    if ($isBooking && $bookingType === 'Swimming') {
                        return response()->json([
                            'message' => 'Cannot add facilities to Swimming bookings.',
                            'success' => false,
                        ], 400);
                    }
                }

                // Validate guest charges
                if (!empty($request->guest_charges)) {
                    if ($isBooking && $bookingType === 'Package') {
                        return response()->json([
                            'message' => 'Cannot add guests to Package bookings (fixed capacity).',
                            'success' => false,
                        ], 400);
                    }
                }

                // === STEP 2: Process Facility Extensions ===
                if (!empty($request->facilities)) {
                    foreach ($request->facilities as $facilityExtension) {
                        $facility = \App\Models\Facility::findOrFail($facilityExtension['facility_id']);
                        $rate = \App\Models\Rate::findOrFail($facilityExtension['rate_id']);

                        // For walk-ins: hours auto-inherit from original entry
                        $hours = $facilityExtension['hours'] ?? 1;
                        if ($isWalkIn && $billable instanceof GuestEntry) {
                            $hours = $billable->number_of_hours ?? 1;
                        }

                        $amount = $rate->base_price;
                        $quantity = $facilityExtension['quantity'] ?? 1;
                        $subtotal = $amount * $hours * $quantity;

                        // Create extension (NO discount calculation)
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'extension_type' => 'facility',
                            'amount' => $amount,
                            'quantity' => $quantity,
                            'hours' => $hours,
                            'total_amount' => $subtotal,
                            'metadata' => [
                                'facility_id' => $facility->id,
                                'facility_name' => $facility->facility_name,
                                'rate_id' => $rate->id,
                                'rate_type' => $rate->rate_type,
                            ],
                        ]);

                        $totalExtensionAmount += $subtotal;
                        $extensions[] = $extension;

                        $detailedCalculations[] = [
                            'type' => 'facility',
                            'facility' => $facility->facility_name,
                            'rate' => $rate->rate_type,
                            'amount' => $amount,
                            'hours' => $hours,
                            'quantity' => $quantity,
                            'subtotal' => $subtotal,
                        ];
                    }
                }

                // === STEP 3: Process Guest Charges (Walk-in or Swimming Entrance Extensions) ===
                if (!empty($request->guest_charges)) {
                    // Fetch per-guest rates from billing metadata
                    $perGuestRates = $billing->getPerGuestRates() ?? [];

                    foreach ($request->guest_charges as $guestCharge) {
                        $guestType = $guestCharge['guest_type'];
                        $count = $guestCharge['count'];

                        // Use provided rate or fetch from metadata
                        $ratePerGuest = $guestCharge['rate_per_guest'] ?? null;
                        if (!$ratePerGuest) {
                            $guestTypeLower = strtolower($guestType);
                            $ratePerGuest = $perGuestRates[$guestTypeLower] ?? 0;

                            if (!$ratePerGuest) {
                                return response()->json([
                                    'message' => "Rate not found for guest type: {$guestType}. Please provide rate_per_guest.",
                                    'success' => false,
                                ], 400);
                            }
                        }

                        // Calculate: rate × count (NO discount)
                        $subtotal = $ratePerGuest * $count;

                        // Create extension
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'extension_type' => 'guest',
                            'amount' => $ratePerGuest,
                            'quantity' => $count,
                            'total_amount' => $subtotal,
                            'metadata' => [
                                'guest_type' => $guestType,
                            ],
                        ]);

                        $totalExtensionAmount += $subtotal;
                        $extensions[] = $extension;

                        $detailedCalculations[] = [
                            'type' => 'guest',
                            'guest_type' => $guestType,
                            'rate_per_guest' => $ratePerGuest,
                            'count' => $count,
                            'subtotal' => $subtotal,
                        ];
                    }
                }

                // === STEP 4: Process Third-party Services ===
                if (!empty($request->third_party_services)) {
                    foreach ($request->third_party_services as $service) {
                        $amount = $service['amount'];
                        $serviceName = $service['service_name'];

                        // Create extension (quantity always 1, NO discount)
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'extension_type' => 'service',
                            'amount' => $amount,
                            'quantity' => 1,
                            'total_amount' => $amount,
                            'metadata' => [
                                'service_name' => $serviceName,
                            ],
                        ]);

                        $totalExtensionAmount += $amount;
                        $extensions[] = $extension;

                        $detailedCalculations[] = [
                            'type' => 'service',
                            'service_name' => $serviceName,
                            'amount' => $amount,
                        ];
                    }
                }

                // === STEP 5: Simple Mode (Damage/Custom Charges) ===
                if ($request->simple_mode) {
                    $amount = $request->amount;
                    $quantity = $request->quantity ?? 1;
                    $subtotal = $amount * $quantity;

                    // Create extension (NO discount)
                    $extension = BillingExtension::create([
                        'billing_id' => $billing->id,
                        'extension_type' => 'damage',
                        'amount' => $amount,
                        'quantity' => $quantity,
                        'total_amount' => $subtotal,
                        'metadata' => [],
                    ]);

                    $totalExtensionAmount += $subtotal;
                    $extensions[] = $extension;

                    $detailedCalculations[] = [
                        'type' => 'damage',
                        'amount' => $amount,
                        'quantity' => $quantity,
                        'subtotal' => $subtotal,
                    ];
                }

                // === STEP 6: Calculate Final Amounts (NO DISCOUNT CALCULATION) ===
                $finalExtensionAmount = $totalExtensionAmount; // No discounts applied
                
                // === STEP 7: Update Billing Totals ===
                $billing->amount_due += $finalExtensionAmount;
                $billing->total_amount += $finalExtensionAmount;
                $billing->save();

                // === STEP 8: Record Optional Payment ===
                $payment = null;
                if ($request->amount_paid > 0) {
                    $payment = \App\Models\Payment::create([
                        'billing_id' => $billing->id,
                        'amount' => $request->amount_paid,
                        'payment_method' => $request->payment_method ?? 'Cash',
                        'payment_date' => now(),
                        'is_verified' => true,
                    ]);

                    // Update billing balance
                    $billing->amount_paid += $request->amount_paid;
                    $billing->amount_due -= $request->amount_paid;

                    // Update payment status
                    if ($billing->amount_due <= 0) {
                        $billing->payment_status = 'Paid';
                    } else {
                        $billing->payment_status = 'Partial';
                    }

                    $billing->save();
                }

                // === STEP 9: Log and Return ===
                Log::info('Extension added to billing (no discounts)', [
                    'billing_id' => $billing->id,
                    'billable_type' => get_class($billable),
                    'billable_id' => $billable->id,
                    'entry_type' => $entryType,
                    'booking_type' => $bookingType,
                    'extension_mode' => $request->simple_mode ? 'simple' : 'smart',
                    'total_extension_amount' => $totalExtensionAmount,
                    'detailed_calculations' => $detailedCalculations,
                ]);

                return response()->json([
                    'message' => 'Extension added successfully',
                    'success' => true,
                    'data' => [
                        'billing' => new BillingResource($billing->fresh()),
                        'extensions' => $extensions,
                        'extension_summary' => [
                            'total_extension_amount' => $totalExtensionAmount,
                            'final_extension_amount' => $finalExtensionAmount,
                            'detailed_calculations' => $detailedCalculations,
                        ],
                        'payment' => $payment,
                    ],
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error adding extension to billing', [
                    'billing_id' => $billing->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }
}