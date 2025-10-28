<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestEntry\StoreGuestEntryRequest;
use App\Http\Requests\GuestEntry\UpdateGuestEntryRequest;
use App\Http\Requests\GuestEntry\CheckoutGuestEntryRequest;
use App\Http\Resources\GuestEntryResource;
use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Models\GuestEntryFacility;
use App\Models\Rate;
use App\Models\Discount;
use App\Models\Booking;
use App\Models\Billing;
use App\Models\ThirdPartyService;
use App\Services\BillingService;
use App\Services\FacilityAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GuestMonitoringController extends Controller
{
    protected $billingService;
    protected $availabilityService;

    public function __construct(
        BillingService $billingService,
        FacilityAvailabilityService $availabilityService
    ) {
        $this->billingService = $billingService;
        $this->availabilityService = $availabilityService;
    }

    /**
     * Store a new walk-in guest entry
     * ✅ FIXED: Properly implements Direct discounts per guest
     */
    public function store(StoreGuestEntryRequest $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                // ✅ STEP 1: Get entrance rate details
                $entranceRate = Rate::findOrFail($request->entrance_rate_id);
                
                // ✅ STEP 2: Prepare datetime
                $entryDate = Carbon::parse($request->entry_date);
                $checkInTime = $request->check_in_time ?: Carbon::now()->format('H:i');
                $checkInDateTime = Carbon::parse("{$entryDate->toDateString()} {$checkInTime}");
                
                // ✅ STEP 3: Calculate entrance fees with Direct discounts
                $entranceSubtotal = 0;
                $directDiscountTotal = 0;
                $guestDetailsForSaving = [];
                
                foreach ($request->guest_details as $guestDetail) {
                    $guestCount = $guestDetail['guest_count'];
                    $baseAmount = $entranceRate->base_price * $guestCount;
                    $discountAmount = 0;
                    
                    // Apply Direct discount if specified
                    if (isset($guestDetail['discount_id']) && $guestDetail['discount_id']) {
                        $discount = Discount::find($guestDetail['discount_id']);
                        if ($discount && $discount->category === 'Direct_Discount' && $discount->is_active) {
                            if ($discount->type === 'Percentage') {
                                $discountAmount = ($entranceRate->base_price * ($discount->value / 100)) * $guestCount;
                            } else {
                                $discountAmount = min($discount->value, $entranceRate->base_price) * $guestCount;
                            }
                            $directDiscountTotal += $discountAmount;
                        }
                    }
                    
                    $finalAmount = $baseAmount - $discountAmount;
                    $entranceSubtotal += $finalAmount;
                    
                    // Store for later saving
                    $guestDetailsForSaving[] = [
                        'guest_type_name' => $guestDetail['guest_type_name'],
                        'guest_count' => $guestCount,
                        'rate_id' => $request->entrance_rate_id,
                        'base_rate' => $entranceRate->base_price,
                        'discount_mode' => isset($guestDetail['discount_id']) ? 'Direct' : 'None',
                        'discount_id' => $guestDetail['discount_id'] ?? null,
                        'discount_amount' => $discountAmount / $guestCount, // Per guest
                        'final_rate' => ($baseAmount - $discountAmount) / $guestCount,
                        'total_amount' => $finalAmount,
                    ];
                }
                
                // ✅ STEP 4: Apply Seasonal discount (if any) to entrance total
                $seasonalDiscountAmount = 0;
                $discountMode = 'None';
                $discountId = null;
                
                if ($directDiscountTotal > 0) {
                    $discountMode = 'Direct';
                    // Note: Direct + Seasonal not allowed (validated in request)
                } elseif ($request->seasonal_discount_id) {
                    $seasonalDiscount = Discount::find($request->seasonal_discount_id);
                    if ($seasonalDiscount && $seasonalDiscount->is_active) {
                        if ($seasonalDiscount->type === 'Percentage') {
                            $seasonalDiscountAmount = $entranceSubtotal * ($seasonalDiscount->value / 100);
                        } else {
                            $seasonalDiscountAmount = min($seasonalDiscount->value, $entranceSubtotal);
                        }
                        $entranceSubtotal -= $seasonalDiscountAmount;
                        $discountMode = 'Seasonal';
                        $discountId = $seasonalDiscount->id;
                    }
                }
                
                // ✅ STEP 5: Calculate facility fees
                $facilitySubtotal = 0;
                $facilitiesForSaving = [];
                
                if ($request->has('facilities')) {
                    foreach ($request->facilities as $facilityData) {
                        $rate = Rate::findOrFail($facilityData['rate_id']);
                        $subtotal = $rate->base_price * $facilityData['quantity'];
                        $facilitySubtotal += $subtotal;
                        
                        $facilitiesForSaving[] = [
                            'facility_id' => $facilityData['facility_id'],
                            'rate_id' => $facilityData['rate_id'],
                            'quantity' => $facilityData['quantity'],
                            'rate_amount' => $rate->base_price,
                            'subtotal' => $subtotal,
                        ];
                    }
                }
                
                // ✅ STEP 6: Apply Manual discount (if any)
                $manualDiscountAmount = $request->manual_discount_amount ?? 0;
                if ($manualDiscountAmount > 0) {
                    if ($discountMode === 'None') {
                        $discountMode = 'Manual';
                    }
                    // Manual can stack with Direct or Seasonal
                }
                
                // ✅ STEP 7: Calculate totals
                $subtotal = $entranceSubtotal + $facilitySubtotal;
                $totalDiscountAmount = $directDiscountTotal + $seasonalDiscountAmount + $manualDiscountAmount;
                $totalAmount = max(0, $subtotal - $manualDiscountAmount);
                
                // ✅ STEP 8: Create guest entry record
                $guestEntry = GuestEntry::create([
                    'entry_reference' => GuestEntry::generateEntryReference(),
                    'entrance_rate_id' => $request->entrance_rate_id,
                    'guest_name' => $request->guest_name,
                    'contact_number' => $request->contact_number,
                    'number_of_guests' => $request->number_of_guests,
                    'entry_date' => $entryDate,
                    'check_in_datetime' => $checkInDateTime,
                    'entry_type' => 'Walk_In',
                    'entrance_fee' => $entranceSubtotal + $directDiscountTotal + $seasonalDiscountAmount, // Original before discount
                    'facility_fee' => $facilitySubtotal,
                    'subtotal' => $subtotal,
                    'discount_mode' => $discountMode,
                    'discount_id' => $discountId,
                    'seasonal_discount_amount' => $seasonalDiscountAmount,
                    'manual_discount_amount' => $manualDiscountAmount,
                    'discount_amount' => $totalDiscountAmount,
                    'total_amount' => $totalAmount,
                    'entry_status' => 'Active',
                    'is_checked_out' => false,
                    'notes' => $request->notes,
                    'created_by' => auth()->id(),
                ]);
                
                // ✅ STEP 9: Save guest details
                foreach ($guestDetailsForSaving as $detail) {
                    GuestEntryDetail::create(array_merge($detail, [
                        'guest_entry_id' => $guestEntry->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
                
                // ✅ STEP 10: Save facilities
                foreach ($facilitiesForSaving as $facility) {
                    GuestEntryFacility::create(array_merge($facility, [
                        'guest_entry_id' => $guestEntry->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
                
                // ✅ STEP 11: Create billing WITHOUT payment
                // Payment is now handled separately in the Billing module
                $billing = Billing::create([
                    'billable_type' => GuestEntry::class,
                    'billable_id' => $guestEntry->id,
                    'billing_number' => Billing::generateBillingNumber(),
                    'subtotal' => $guestEntry->subtotal,
                    'discount_amount' => $guestEntry->discount_amount ?? 0,
                    'total_amount' => $totalAmount,
                    'amount_paid' => 0,
                    'balance' => $totalAmount,
                    'payment_status' => 'unpaid',
                    'billing_status' => 'active', // Walk-ins are active immediately
                    'billed_at' => now(),
                    'created_by' => auth()->id(),
                    'notes' => 'Walk-in guest entry - payment to be collected',
                ]);
                
                // ✅ STEP 12: Get capacity warnings
                $warnings = session('capacity_warnings', []);
                
                // ✅ STEP 13: Log entry creation
                Log::info('Walk-in guest entry created', [
                    'guest_entry_id' => $guestEntry->id,
                    'entry_reference' => $guestEntry->entry_reference,
                    'total_amount' => $totalAmount,
                    'billing_id' => $billing->id,
                    'created_by' => auth()->id(),
                ]);
                
                // Load relationships
                $guestEntry->load([
                    'entranceRate',
                    'guestDetails',
                    'facilities.facility',
                    'billing.payments',
                    'createdBy',
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Walk-in guest entry created successfully',
                    'data' => new GuestEntryResource($guestEntry),
                    'warnings' => $warnings,
                ], 201);
                
            } catch (\Exception $e) {
                Log::error('Guest entry creation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request->all(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Get paginated list of guest entries
     */
    public function index(Request $request)
    {
        $query = GuestEntry::with([
            'entranceRate',
            'guestDetails',
            'facilities.facility',
            'billing',
            'createdBy',
        ]);

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_checked_out', false);
            } elseif ($request->status === 'checked_out') {
                $query->where('is_checked_out', true);
            }
        }

        // Filter by date
        if ($request->has('date')) {
            $query->whereDate('entry_date', $request->date);
        } elseif ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('entry_date', [$request->date_from, $request->date_to]);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('entry_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sort
        $query->orderBy('created_at', 'desc');

        // Paginate
        $perPage = $request->input('per_page', 15);
        $entries = $query->paginate($perPage);

        return GuestEntryResource::collection($entries)->additional([
            'status' => 'success',
            'message' => 'Guest entries retrieved successfully',
        ]);
    }

    /**
     * Get single guest entry
     */
    public function show($id)
    {
        $guestEntry = GuestEntry::with([
            'entranceRate',
            'guestDetails',
            'facilities.facility.facilityType',
            'facilities.rate',
            'billing.payments.receivedBy',
            'createdBy',
            'checkedOutBy',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new GuestEntryResource($guestEntry),
        ]);
    }

    /**
     * Update guest entry
     */
    public function update(UpdateGuestEntryRequest $request, $id)
    {
        $guestEntry = GuestEntry::findOrFail($id);
        
        // Prevent updates to checked-out entries
        if ($guestEntry->is_checked_out) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot update a checked-out guest entry',
            ], 400);
        }
        
        $guestEntry->update($request->validated());
        
        return response()->json([
            'status' => 'success',
            'message' => 'Guest entry updated successfully',
            'data' => new GuestEntryResource($guestEntry->fresh()),
        ]);
    }

    /**
     * Check out a guest entry
     * ✅ FIXED: No overstay fees for swimming walk-ins
     */
    public function checkout(CheckoutGuestEntryRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $guestEntry = GuestEntry::with('billing')->findOrFail($id);
            
            // Validate not already checked out
            if ($guestEntry->is_checked_out) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Guest has already been checked out',
                ], 400);
            }
            
            // ✅ CRITICAL: Validate payment is complete before checkout
            if ($guestEntry->billing) {
                $balance = $guestEntry->billing->balance;
                
                if ($balance > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Cannot checkout with outstanding balance. Please collect payment of ₱%.2f before checkout.',
                            $balance
                        ),
                        'balance' => $balance,
                        'billing_id' => $guestEntry->billing->id,
                    ], 400);
                }
            }
            
            // Prepare checkout datetime
            $exitDate = Carbon::parse($request->exit_date);
            $exitTime = $request->exit_time ?: Carbon::now()->format('H:i');
            $exitDateTime = Carbon::parse("{$exitDate->toDateString()} {$exitTime}");

            // ✅ NEW: Validate checkout date
            $checkInDate = Carbon::parse($guestEntry->check_in_datetime)->startOfDay();
            $checkoutDate = $exitDate->copy()->startOfDay();
            
            // Cannot checkout before check-in date
            if ($checkoutDate->lt($checkInDate)) {
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Cannot checkout before check-in date. Check-in: %s, Checkout: %s',
                        $checkInDate->format('F d, Y'),
                        $checkoutDate->format('F d, Y')
                    ),
                ], 400);
            }

            // Validate checkout datetime is not in the future (reasonable grace period of 1 hour)
            $now = Carbon::now();
            if ($exitDateTime->gt($now->copy()->addHour())) {
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Cannot checkout with a future date/time. Selected: %s, Current: %s',
                        $exitDateTime->format('F d, Y h:i A'),
                        $now->format('F d, Y h:i A')
                    ),
                ], 400);
            }
            
            // ✅ NO OVERSTAY CALCULATION FOR SWIMMING WALK-INS
            // Swimming has fixed check-out time based on entrance rate
            // Extension fee = 0.00 for entrance rates per specification
            
            // Update guest entry
            $guestEntry->update([
                'exit_date' => $exitDate,
                'exit_time' => $exitTime,
                'check_out_datetime' => $exitDateTime,
                'is_checked_out' => true,
                'checked_out_by' => auth()->id(),
                'checkout_notes' => $request->notes,
            ]);
            
            // Update billing status to completed (payment already verified above)
            if ($guestEntry->billing) {
                $guestEntry->billing->update([
                    'billing_status' => 'completed',
                ]);
            }
            
            Log::info('Walk-in guest checked out', [
                'guest_entry_id' => $guestEntry->id,
                'checked_out_by' => auth()->id(),
                'exit_datetime' => $exitDateTime,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked out successfully',
                'data' => new GuestEntryResource($guestEntry->fresh()),
            ]);
        });
    }

    /**
     * Get active guests (not checked out)
     */
    public function activeGuests(Request $request)
    {
        $query = GuestEntry::with([
            'entranceRate',
            'guestDetails',
            'facilities.facility',
        ])
        ->where('is_checked_out', false)
        ->whereDate('entry_date', Carbon::today());

        // Get entrance rate filter
        if ($request->has('entrance_rate_id')) {
            $query->where('entrance_rate_id', $request->entrance_rate_id);
        }

        $guests = $query->orderBy('check_in_datetime', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => GuestEntryResource::collection($guests),
            'summary' => [
                'total_active_guests' => $guests->sum('number_of_guests'),
                'total_entries' => $guests->count(),
            ],
        ]);
    }

    /**
     * Get today's summary
     */
    public function todaySummary()
    {
        $today = Carbon::today();
        
        // Total entries today
        $totalEntries = GuestEntry::whereDate('entry_date', $today)->count();
        
        // Active guests
        $activeGuests = GuestEntry::where('is_checked_out', false)
            ->whereDate('entry_date', $today)
            ->sum('number_of_guests');
        
        // Checked out
        $checkedOut = GuestEntry::where('is_checked_out', true)
            ->whereDate('entry_date', $today)
            ->count();
        
        // Revenue today
        $revenue = GuestEntry::whereDate('entry_date', $today)
            ->sum('total_amount');
        
        // By entrance rate
        $byEntranceRate = GuestEntry::with('entranceRate')
            ->whereDate('entry_date', $today)
            ->select('entrance_rate_id', DB::raw('count(*) as count'), DB::raw('sum(number_of_guests) as total_guests'))
            ->groupBy('entrance_rate_id')
            ->get()
            ->map(function($item) {
                return [
                    'rate_name' => $item->entranceRate ? $item->entranceRate->rate_name : 'Unknown',
                    'entries' => $item->count,
                    'total_guests' => $item->total_guests,
                ];
            });
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'date' => $today->toDateString(),
                'total_entries' => $totalEntries,
                'active_guests' => $activeGuests,
                'checked_out' => $checkedOut,
                'revenue' => $revenue,
                'by_entrance_rate' => $byEntranceRate,
            ],
        ]);
    }

    /**
     * Get available discounts for walk-in guests
     */
    public function getAvailableDiscounts()
    {
        $today = Carbon::today();
        
        // Get Direct discounts (per-guest)
        $directDiscounts = Discount::where('category', 'Direct_Discount')
            ->where('is_active', true)
            ->get()
            ->map(function($discount) {
                return [
                    'id' => $discount->id,
                    'name' => $discount->name,
                    'type' => $discount->type,
                    'value' => $discount->value,
                    'description' => $discount->description,
                ];
            });
        
        // Get active Seasonal discount
        $seasonalDiscount = Discount::where('category', 'Seasonal_Discount')
            ->where('is_active', true)
            ->where(function($q) use ($today) {
                $q->whereNull('valid_from')
                  ->orWhere('valid_from', '<=', $today);
            })
            ->where(function($q) use ($today) {
                $q->whereNull('valid_until')
                  ->orWhere('valid_until', '>=', $today);
            })
            ->first();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'direct_discounts' => $directDiscounts,
                'seasonal_discount' => $seasonalDiscount ? [
                    'id' => $seasonalDiscount->id,
                    'name' => $seasonalDiscount->name,
                    'type' => $seasonalDiscount->type,
                    'value' => $seasonalDiscount->value,
                    'valid_from' => $seasonalDiscount->valid_from,
                    'valid_until' => $seasonalDiscount->valid_until,
                ] : null,
                'stacking_rules' => [
                    'Direct + Manual' => 'Allowed',
                    'Seasonal + Manual' => 'Allowed',
                    'Direct + Seasonal' => 'Not Allowed',
                ],
            ],
        ]);
    }

    /**
     * ✅ NEW: Check in a booking to create guest entry
     * This links a confirmed booking to an active guest entry
     */
    public function checkInBooking(Request $request, $bookingId)
    {
        $request->validate([
            'actual_guests' => 'nullable|integer|min:1',
            'check_in_notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $bookingId) {
            try {
                // ✅ STEP 1: Find and validate booking
                $booking = Booking::with([
                    'entranceRate',
                    'facilities.facility',
                    'facilities.rate',
                    'guestDiscounts.discount',
                    'thirdPartyServices',
                    'billing.payments',
                ])->findOrFail($bookingId);

                // ✅ STEP 2: Validate booking can be checked in
                if ($booking->booking_status !== 'Confirmed') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only confirmed bookings can be checked in. Current status: ' . $booking->booking_status,
                    ], 400);
                }

                // Check if already checked in
                if ($booking->guestEntry) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This booking has already been checked in.',
                        'guest_entry_id' => $booking->guestEntry->id,
                    ], 400);
                }

                // ✅ NEW: Validate check-in date (cannot check in before booking date)
                $bookingCheckInDate = Carbon::parse($booking->check_in_datetime)->startOfDay();
                $today = Carbon::now()->startOfDay();
                
                if ($today->lt($bookingCheckInDate)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Cannot check in before booking date. Booking check-in date: %s. Today: %s',
                            $bookingCheckInDate->format('F d, Y'),
                            $today->format('F d, Y')
                        ),
                        'booking_check_in_date' => $bookingCheckInDate->toDateString(),
                        'current_date' => $today->toDateString(),
                    ], 400);
                }

                // ✅ FIXED: Check payment status with better error messages
                if (!$booking->billing) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Billing record not found for this booking. Please contact administrator.',
                    ], 400);
                }

                if (!$booking->billing->is_downpayment_paid) {
                    $remainingDownpayment = $booking->billing->downpayment_amount - $booking->billing->downpayment_paid;
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Downpayment must be paid before check-in. Required: ₱%.2f, Paid: ₱%.2f, Remaining: ₱%.2f',
                            $booking->billing->downpayment_amount,
                            $booking->billing->downpayment_paid,
                            $remainingDownpayment
                        ),
                        'required_downpayment' => $booking->billing->downpayment_amount,
                        'downpayment_paid' => $booking->billing->downpayment_paid,
                        'remaining_downpayment' => $remainingDownpayment,
                    ], 400);
                }

                // ✅ STEP 3: Create guest entry from booking
                $actualGuests = $request->actual_guests ?? $booking->number_of_guests;
                $checkInDateTime = now();

                $guestEntry = GuestEntry::create([
                    'booking_id' => $booking->id,
                    'entry_type' => 'booking',
                    'entry_reference' => $this->generateEntryReference(),
                    'entry_date' => $checkInDateTime->toDateString(),
                    'entry_time' => $checkInDateTime->format('H:i:s'),
                    'check_in_datetime' => $checkInDateTime,
                    'entrance_rate_id' => $booking->entrance_rate_id,
                    'guest_name' => $booking->guest_name,
                    'contact_number' => $booking->contact_number,
                    'total_guests' => $actualGuests,
                    'discount_mode' => $booking->discount_mode,
                    'discount_id' => $booking->discount_id,
                    'manual_discount_amount' => $booking->manual_discount_amount,
                    'entrance_subtotal' => $booking->entrance_subtotal ?? 0,
                    'facility_subtotal' => $booking->facility_subtotal,
                    'third_party_service_amount' => $booking->third_party_service_amount,
                    'subtotal' => $booking->subtotal,
                    'discount_amount' => $booking->discount_amount,
                    'total_amount' => $booking->total_amount,
                    'is_checked_out' => false,
                    'notes' => $request->check_in_notes ?? $booking->notes,
                    'created_by' => auth()->id(),
                ]);

                // ✅ STEP 4: Copy guest details (for Swimming bookings with guest breakdown)
                if ($booking->booking_type === 'Swimming' && $booking->guestDiscounts()->count() > 0) {
                    foreach ($booking->guestDiscounts as $guestDiscount) {
                        $rate = $booking->entranceRate;
                        $baseAmount = $rate->base_price * $guestDiscount->guest_count;
                        $discountAmount = 0;

                        if ($guestDiscount->discount) {
                            if ($guestDiscount->discount->type === 'Percentage') {
                                $discountAmount = ($rate->base_price * ($guestDiscount->discount->value / 100)) * $guestDiscount->guest_count;
                            } else {
                                $discountAmount = min($guestDiscount->discount->value, $rate->base_price) * $guestDiscount->guest_count;
                            }
                        }

                        GuestEntryDetail::create([
                            'guest_entry_id' => $guestEntry->id,
                            'guest_type_name' => ucfirst($guestDiscount->guest_type),
                            'guest_count' => $guestDiscount->guest_count,
                            'rate_id' => $rate->id,
                            'base_rate' => $rate->base_price,
                            'discount_id' => $guestDiscount->discount_id,
                            'discount_amount' => $discountAmount,
                            'final_rate' => $rate->base_price - ($discountAmount / $guestDiscount->guest_count),
                            'total_amount' => $baseAmount - $discountAmount,
                        ]);
                    }
                } else {
                    // Create single detail entry for non-Swimming or bookings without guest breakdown
                    if ($booking->entranceRate) {
                        GuestEntryDetail::create([
                            'guest_entry_id' => $guestEntry->id,
                            'guest_type_name' => 'Regular',
                            'guest_count' => $actualGuests,
                            'rate_id' => $booking->entranceRate->id,
                            'base_rate' => $booking->entranceRate->base_price,
                            'discount_id' => null,
                            'discount_amount' => 0,
                            'final_rate' => $booking->entranceRate->base_price,
                            'total_amount' => $booking->entranceRate->base_price * $actualGuests,
                        ]);
                    }
                }

                // ✅ STEP 5: Copy facilities
                foreach ($booking->facilities as $bookingFacility) {
                    // Calculate subtotal if it's null (for older bookings)
                    $facilitySubtotal = $bookingFacility->subtotal;
                    if ($facilitySubtotal === null) {
                        $facilitySubtotal = ($bookingFacility->rate_amount ?? 0) * ($bookingFacility->quantity ?? 1);
                    }
                    
                    GuestEntryFacility::create([
                        'guest_entry_id' => $guestEntry->id,
                        'facility_id' => $bookingFacility->facility_id,
                        'rate_id' => $bookingFacility->rate_id,
                        'quantity' => $bookingFacility->quantity,
                        'rate_amount' => $bookingFacility->rate_amount,
                        'subtotal' => $facilitySubtotal,
                    ]);
                }

                // ✅ STEP 6: Copy third-party services
                foreach ($booking->thirdPartyServices as $service) {
                    ThirdPartyService::create([
                        'guest_entry_id' => $guestEntry->id,
                        'service_name' => $service->service_name,
                        'amount' => $service->amount,
                    ]);
                }

                // ✅ STEP 7: Update booking status to Checked_In
                $booking->update([
                    'booking_status' => 'Checked_In',
                    'actual_check_in_datetime' => $checkInDateTime,
                    'checked_in_by' => auth()->id(),
                    'actual_guests' => $actualGuests,
                ]);

                // ✅ STEP 8: Log check-in
                Log::info('Booking checked in via Guest Monitoring', [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'guest_entry_id' => $guestEntry->id,
                    'entry_reference' => $guestEntry->entry_reference,
                    'checked_in_by' => auth()->id(),
                ]);

                // Load relationships for response
                $guestEntry->load([
                    'booking',
                    'entranceRate',
                    'guestDetails',
                    'facilities.facility',
                    'billing.payments',
                    'createdBy',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking checked in successfully',
                    'data' => new GuestEntryResource($guestEntry),
                    'booking' => [
                        'id' => $booking->id,
                        'reference' => $booking->booking_reference,
                        'status' => $booking->booking_status,
                    ],
                ], 201);

            } catch (\Exception $e) {
                Log::error('Booking check-in failed', [
                    'booking_id' => $bookingId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to check in booking: ' . $e->getMessage(),
                ], 500);
            }
        });
    }

    /**
     * Helper: Generate entry reference
     */
    private function generateEntryReference()
    {
        $date = now()->format('Ymd');
        $lastEntry = GuestEntry::whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastEntry ? (intval(substr($lastEntry->entry_reference, -4)) + 1) : 1;

        return 'GE-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}