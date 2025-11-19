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
use App\Models\BillingExtension;
use App\Models\ThirdPartyService;
use App\Services\BillingService;
use App\Services\FacilityAvailabilityService;
use App\Services\OvertimeCalculationService;
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
                $seasonalDiscountId = null;
                
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
                        $seasonalDiscountId = $seasonalDiscount->id;
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
                    'entry_type' => 'walk_in',
                    'entrance_fee' => $entranceSubtotal + $directDiscountTotal + $seasonalDiscountAmount, // Original before discount
                    'facility_fee' => $facilitySubtotal,
                    'subtotal' => $subtotal,
                    'discount_mode' => $discountMode,
                    'discount_id' => $discountId, // For Direct discounts (if needed)
                    'seasonal_discount_id' => $seasonalDiscountId, // ✅ FIXED: Use proper field
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
                
                // ✅ STEP 11: Create billing with required full payment
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
                    'billing_status' => 'active',
                    'billed_at' => now(),
                    'created_by' => auth()->id(),
                    'notes' => 'Walk-in guest entry - awaiting payment',
                ]);
                
                // ✅ STEP 11.5: Record payment if provided (optional)
                if ($request->has('payment')) {
                    $billing->recordPayment(
                        amount: $request->input('payment.amount_paid'),
                        paymentMethod: $request->input('payment.payment_method'),
                        paymentType: 'full',
                        changeAmount: $request->input('payment.change_amount', 0),
                        referenceNumber: $request->input('payment.reference_number'),
                        notes: $request->input('payment.notes'),
                        receivedBy: auth()->id()
                    );
                    
                    // Reload billing to get updated payment_status and billing_status
                    $billing->refresh();
                }
                
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
            'thirdPartyServices',
            'billing.payments',
            'createdBy',
            'booking',  // ✅ Load booking to get booking_type
        ]);

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_checked_out', false);
            } elseif ($request->status === 'checked_out') {
                $query->where('is_checked_out', true);
            }
        }

        // Filter by entry_type
        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->entry_type);
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
            'guestDetails.discount',
            'guestDetails.rate',
            'facilities.facility.facilityType',
            'facilities.rate',
            'thirdPartyServices',
            'billing.payments.receivedBy',
            'createdBy',
            'checkedOutBy',
            'booking',  // ✅ Load booking to get booking_type
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
        return DB::transaction(function () use ($request, $id) {
            $guestEntry = GuestEntry::with([
                'details',
                'facilities',
                'thirdPartyServices',
                'billing.payments',
            ])->findOrFail($id);
            
            // ✅ Block all walk-in edits - extensions only
            if ($guestEntry->entry_type === 'walk_in') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Walk-in entries cannot be edited. Use billing extensions to add facilities, guests, or services.',
                    'hint' => 'POST /api/billings/{billing_id}/extensions',
                ], 403);
            }
            
            // ✅ Block updates to checked-out entries
            if ($guestEntry->is_checked_out) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot update a checked-out guest entry',
                ], 403);
            }
            
            // ✅ For checked-in bookings, only allow name and contact updates
            if ($guestEntry->booking_id) {
                $guestEntry->update([
                    'guest_name' => $request->guest_name,
                    'contact_number' => $request->contact_number,
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Guest contact details updated. Use billing extensions to add facilities, guests, or services.',
                    'data' => new GuestEntryResource($guestEntry->fresh([
                        'entranceRate',
                        'details.rate',
                        'facilities.facility',
                        'thirdPartyServices',
                        'createdBy',
                        'billing.payments',
                    ])),
                ]);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid update request',
            ], 400);
        });
    }

    /**
     * Checkout a guest entry with automatic overtime calculation
     * ✅ NEW: Calculates overtime for facilities with extension fees
     */
    public function checkout(CheckoutGuestEntryRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $guestEntry = GuestEntry::with(['billing.payments', 'facilities.rate', 'facilities.facility', 'booking'])->findOrFail($id);
            
            // ✅ Block checkout for walk-in guests (they don't need checkout)
            if ($guestEntry->entry_type === 'walk_in') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Walk-in guests do not require checkout. Transaction is complete upon payment. Guests may leave the facility freely.',
                    'entry_type' => 'walk_in',
                ], 403);
            }
            
            // ✅ Block checkout for Swimming bookings (day use only, no checkout needed)
            if ($guestEntry->booking_id) {
                $booking = $guestEntry->booking;
                
                if ($booking && $booking->booking_type === 'Swimming') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Swimming bookings do not require checkout. These are day-use bookings with fixed time slots. Transaction completes after check-in and payment.',
                        'booking_type' => 'Swimming',
                        'entry_type' => $guestEntry->entry_type,
                    ], 403);
                }
            }
            
            // Validate not already checked out
            if ($guestEntry->is_checked_out) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Guest has already been checked out',
                ], 400);
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
            
            // ✅ NEW: Apply overtime charges if explicitly requested (opt-in)
            $overtimeTotal = 0;
            $overtimeDetails = [];
            
            // Staff must explicitly opt-in to apply overtime charges
            if ($request->has('apply_overtime') && $request->apply_overtime === true) {
                $overtimeService = new OvertimeCalculationService();
                $overtimeCharges = $overtimeService->calculateGuestEntryOvertime($guestEntry, $exitDateTime);
                
                if ($overtimeCharges->isNotEmpty()) {
                    foreach ($overtimeCharges as $charge) {
                        // Create billing extension record
                        $extension = BillingExtension::create([
                            'billing_id' => $guestEntry->billing->id,
                            'facility_id' => $charge['facility_id'],
                            'rate_id' => $charge['rate_id'],
                            'discount_id' => $charge['discount_id'],
                            'extension_type' => 'facility',
                            'is_overtime' => true,
                            'description' => sprintf(
                                'Overtime: %s - %.2f hours @ ₱%.2f/hour',
                                $charge['facility_name'],
                                $charge['overtime_hours'],
                                $charge['extension_fee_per_hour']
                            ),
                            'facility_start_datetime' => $guestEntry->check_in_datetime,
                            'facility_end_datetime' => $exitDateTime,
                            'amount' => $charge['extension_fee_per_hour'],
                            'hours' => $charge['overtime_hours'],
                            'discount_amount' => $charge['discount_amount'],
                            'quantity' => 1,
                            'total_amount' => $charge['final_amount'],
                            'metadata' => [
                                'scheduled_end' => $charge['scheduled_end'],
                                'actual_end' => $charge['actual_end'],
                                'overtime_minutes' => $charge['overtime_minutes'],
                                'grace_period_applied' => config('billing.overtime.grace_period_minutes', 15),
                            ],
                            'added_by' => auth()->id(),
                        ]);
                        
                        // Update guest_entry_facility with extension data
                        if (isset($charge['guest_entry_facility_id'])) {
                            $facilityEntry = $guestEntry->facilities->find($charge['guest_entry_facility_id']);
                            if ($facilityEntry) {
                                $facilityEntry->update([
                                    'extension_hours' => $charge['overtime_hours'],
                                    'extension_amount' => $charge['final_amount'],
                                    'end_datetime' => $exitDateTime,
                                ]);
                            }
                        }
                        
                        $overtimeTotal += $charge['final_amount'];
                        $overtimeDetails[] = [
                            'facility' => $charge['facility_name'],
                            'hours' => $charge['overtime_hours'],
                            'rate' => $charge['extension_fee_per_hour'],
                            'amount' => $charge['final_amount'],
                        ];
                    }
                    
                    // Update billing totals
                    $billing = $guestEntry->billing;
                    $billing->update([
                        'subtotal' => $billing->subtotal + $overtimeTotal,
                        'total_amount' => $billing->total_amount + $overtimeTotal,
                        'balance' => $billing->balance + $overtimeTotal,
                    ]);
                }
            }
            
            // ✅ CRITICAL: Validate payment is complete AFTER adding overtime
            if ($guestEntry->billing) {
                $balance = $guestEntry->billing->fresh()->balance;
                
                if ($balance > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            'Cannot checkout with outstanding balance. Please collect payment of ₱%.2f%s before checkout.',
                            $balance,
                            $overtimeTotal > 0 ? ' (includes ₱' . number_format($overtimeTotal, 2) . ' overtime charges)' : ''
                        ),
                        'balance' => $balance,
                        'overtime_charges' => $overtimeTotal,
                        'overtime_details' => $overtimeDetails,
                        'billing_id' => $guestEntry->billing->id,
                    ], 400);
                }
            }
            
            // Update guest entry
            $guestEntry->update([
                'exit_date' => $exitDate,
                'exit_time' => $exitTime,
                'checkout_datetime' => $exitDateTime, // ✅ CRITICAL FIX: Use correct field name (no underscore)
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
            
            // ✅ CRITICAL: If this guest entry is from a booking, update booking status and datetime
            if ($guestEntry->booking_id) {
                $booking = Booking::find($guestEntry->booking_id);
                if ($booking && $booking->booking_status === 'Checked_In') {
                    $booking->update([
                        'booking_status' => 'Checked_Out',
                        'actual_check_out_datetime' => $exitDateTime,
                        'check_out_datetime' => $exitDateTime, // ✅ For revenue report
                        'checked_out_by' => auth()->id(),
                    ]);
                    
                    Log::info('Booking status updated to Checked_Out via guest entry checkout', [
                        'booking_id' => $booking->id,
                        'guest_entry_id' => $guestEntry->id,
                        'checkout_datetime' => $exitDateTime,
                    ]);
                }
            }
            
            Log::info('Walk-in guest checked out', [
                'guest_entry_id' => $guestEntry->id,
                'checked_out_by' => auth()->id(),
                'exit_datetime' => $exitDateTime,
                'overtime_charges' => $overtimeDetails,
                'overtime_total' => $overtimeTotal,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked out successfully',
                'data' => new GuestEntryResource($guestEntry->fresh([
                    'entranceRate',
                    'guestDetails',
                    'facilities.facility',
                    'facilities.rate',
                    'thirdPartyServices',
                    'billing.payments',
                    'billing.extensions',
                    'createdBy',
                    'checkedOutBy',
                ])),
                'overtime' => [
                    'has_overtime' => $overtimeTotal > 0,
                    'total' => $overtimeTotal,
                    'formatted_total' => '₱' . number_format($overtimeTotal, 2),
                    'details' => $overtimeDetails,
                ],
            ]);
        });
    }

    /**
     * Preview checkout to show overtime charges before actual checkout
     * 
     * ✅ NEW: Preview overtime without modifying records
     */
    public function previewCheckout(Request $request, $id)
    {
        $request->validate([
            'exit_datetime' => 'nullable|date',
        ]);

        $guestEntry = GuestEntry::with([
            'billing.payments',
            'facilities.rate',
            'facilities.facility',
            'booking',  // ✅ Load booking to check booking_type
        ])->findOrFail($id);

        // ✅ Block preview for walk-in guests (they don't checkout)
        if ($guestEntry->entry_type === 'walk_in') {
            return response()->json([
                'status' => 'error',
                'message' => 'Checkout preview not available for walk-in guests. Walk-ins do not require checkout.',
                'entry_type' => 'walk_in',
            ], 403);
        }
        
        // ✅ Block preview for Swimming bookings (day use only, no checkout)
        if ($guestEntry->booking_id) {
            $booking = $guestEntry->booking;
            
            if ($booking && $booking->booking_type === 'Swimming') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Checkout preview not available for Swimming bookings. Day-use bookings do not require checkout.',
                    'booking_type' => 'Swimming',
                ], 403);
            }
        }

        // ✅ Validate entry is not already checked out
        if ($guestEntry->is_checked_out) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest entry is already checked out',
            ], 422);
        }

        // Use provided exit_datetime or current timestamp
        $exitDateTime = $request->exit_datetime 
            ? Carbon::parse($request->exit_datetime) 
            : now();

        // ✅ Preview overtime calculation (does not save to database)
        $overtimeTotal = 0;
        $overtimeDetails = [];

        if (config('billing.overtime.auto_calculate', true)) {
            $overtimeService = new OvertimeCalculationService();
            $overtimePreview = $overtimeService->previewOvertime($guestEntry, $exitDateTime);
            
            if ($overtimePreview['has_overtime']) {
                $overtimeTotal = $overtimePreview['total_overtime_amount'];
                
                foreach ($overtimePreview['overtime_charges'] as $charge) {
                    $overtimeDetails[] = [
                        'facility_id' => $charge['facility_id'],
                        'facility_name' => $charge['facility_name'],
                        'rate_id' => $charge['rate_id'],
                        'rate_name' => $charge['rate_name'],
                        'overtime_hours' => $charge['overtime_hours'],
                        'extension_fee' => $charge['extension_fee'],
                        'subtotal' => $charge['subtotal'],
                        'discount_id' => $charge['discount_id'] ?? null,
                        'discount_name' => $charge['discount_name'] ?? null,
                        'discount_amount' => $charge['discount_amount'] ?? 0,
                        'final_amount' => $charge['final_amount'],
                        'formatted_amount' => '₱' . number_format($charge['final_amount'], 2),
                    ];
                }
            }
        }

        $billing = $guestEntry->billing;
        $currentBalance = (float) $billing->balance;
        $newBalance = $currentBalance + $overtimeTotal;

        return response()->json([
            'status' => 'success',
            'message' => 'Checkout preview generated',
            'data' => [
                'guest_entry_id' => $guestEntry->id,
                'exit_datetime' => $exitDateTime->toIso8601String(),
                'current_billing' => [
                    'subtotal' => $billing->subtotal,
                    'total_amount' => $billing->total_amount,
                    'balance' => $currentBalance,
                    'formatted_balance' => '₱' . number_format($currentBalance, 2),
                ],
                'overtime' => [
                    'has_overtime' => $overtimeTotal > 0,
                    'total' => $overtimeTotal,
                    'formatted_total' => '₱' . number_format($overtimeTotal, 2),
                    'details' => $overtimeDetails,
                    'requires_explicit_application' => true,  // ✅ Indicates opt-in required
                    'note' => $overtimeTotal > 0 ? 'To apply these charges, pass apply_overtime=true in checkout request' : null,
                ],
                'new_billing' => [
                    'total_amount' => $billing->total_amount + $overtimeTotal,
                    'balance' => $newBalance,
                    'formatted_balance' => '₱' . number_format($newBalance, 2),
                    'payment_required' => $newBalance > 0,
                ],
            ],
        ]);
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
     * Release a facility (mark as returned/no longer in use)
     * For day-use guests who can leave freely
     */
    public function releaseFacility(Request $request, $guestEntryId, $extensionId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $guestEntry = GuestEntry::with(['billing.extensions', 'booking'])->findOrFail($guestEntryId);
            
            // Find the billing extension (facility rental)
            $extension = BillingExtension::where('id', $extensionId)
                ->where('billing_id', $guestEntry->billing->id)
                ->where('extension_type', 'facility')
                ->firstOrFail();

            // Check if already released
            if ($extension->is_released) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facility already released',
                ], 400);
            }

            // Mark as released
            $extension->update([
                'is_released' => true,
                'released_at' => now(),
                'released_by' => auth()->id(),
            ]);

            // Check if all facilities are released and payment is complete
            $this->checkAndAutoComplete($guestEntry);

            return response()->json([
                'status' => 'success',
                'message' => 'Facility released successfully',
                'data' => [
                    'extension_id' => $extension->id,
                    'facility' => $extension->facility ? $extension->facility->facility_name : null,
                    'released_at' => $extension->released_at->format('Y-m-d H:i:s'),
                    'released_by' => $extension->releasedBy->full_name ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to release facility', [
                'guest_entry_id' => $guestEntryId,
                'extension_id' => $extensionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to release facility: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Release an initial facility (from guest_entry_facilities)
     * For day-use guests who can leave freely
     */
    public function releaseInitialFacility(Request $request, $guestEntryId, $guestEntryFacilityId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $guestEntry = GuestEntry::with(['facilities', 'billing', 'booking'])->findOrFail($guestEntryId);
            
            // Only allow for day-use entries (walk-in or Swimming bookings)
            $isDayUse = $guestEntry->entry_type === 'walk_in' || 
                        ($guestEntry->booking && $guestEntry->booking->booking_type === 'Swimming');
            
            if (!$isDayUse) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facility release is only available for day-use guests (walk-ins and Swimming bookings). Package bookings require formal checkout.',
                ], 403);
            }
            
            // Find the initial facility
            $facility = GuestEntryFacility::where('id', $guestEntryFacilityId)
                ->where('guest_entry_id', $guestEntry->id)
                ->firstOrFail();

            // Check if already released
            if ($facility->is_released) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facility already released',
                ], 400);
            }

            // Mark as released
            $facility->update([
                'is_released' => true,
                'released_at' => now(),
                'released_by' => auth()->id(),
            ]);

            Log::info('Initial facility released', [
                'guest_entry_id' => $guestEntry->id,
                'facility_id' => $facility->facility_id,
                'guest_entry_facility_id' => $facility->id,
                'released_by' => auth()->id(),
                'notes' => $request->notes,
            ]);

            // Check if all facilities are released and payment is complete
            $this->checkAndAutoComplete($guestEntry);

            return response()->json([
                'status' => 'success',
                'message' => 'Facility released successfully',
                'data' => [
                    'guest_entry_facility_id' => $facility->id,
                    'facility' => $facility->facility ? $facility->facility->name : null,
                    'released_at' => $facility->released_at->format('Y-m-d H:i:s'),
                    'released_by' => $facility->releasedBy->full_name ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to release initial facility', [
                'guest_entry_id' => $guestEntryId,
                'guest_entry_facility_id' => $guestEntryFacilityId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to release facility: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if guest entry should auto-complete
     * For day-use guests (walk-ins and Swimming bookings)
     */
    private function checkAndAutoComplete(GuestEntry $guestEntry)
    {
        // Only for day-use guests (entry_type = walk_in OR booking_type = Swimming)
        $isDayUse = $guestEntry->entry_type === 'walk_in' || 
                    ($guestEntry->booking && $guestEntry->booking->booking_type === 'Swimming');

        if (!$isDayUse) {
            return; // Package bookings need formal checkout
        }

        $billing = $guestEntry->billing;

        // Check if all INITIAL facilities released (guest_entry_facilities)
        $allInitialFacilitiesReleased = $guestEntry->facilities()
            ->where('is_released', false)
            ->count() === 0;

        // Check if all EXTENSION facilities released (billing_extensions)
        $allExtensionFacilitiesReleased = $billing->extensions()
            ->where('extension_type', 'facility')
            ->where('is_released', false)
            ->count() === 0;

        // Both types must be released
        $allFacilitiesReleased = $allInitialFacilitiesReleased && $allExtensionFacilitiesReleased;

        // Check if fully paid
        $isFullyPaid = $billing->balance <= 0 && $billing->payment_status === 'paid';

        // Auto-complete if both conditions met
        if ($allFacilitiesReleased && $isFullyPaid) {
            $guestEntry->update([
                'is_checked_out' => true,
                'checkout_datetime' => now(),
            ]);

            $billing->update([
                'billing_status' => 'completed',
            ]);

            // If from Swimming booking, update booking status
            if ($guestEntry->booking && $guestEntry->booking->booking_type === 'Swimming') {
                $guestEntry->booking->update([
                    'booking_status' => 'Checked_Out',
                    'actual_check_out_datetime' => now(),
                    'check_out_datetime' => now(),
                ]);
            }

            Log::info('Guest entry auto-completed', [
                'guest_entry_id' => $guestEntry->id,
                'entry_type' => $guestEntry->entry_type,
                'booking_type' => $guestEntry->booking?->booking_type,
                'initial_facilities_released' => $allInitialFacilitiesReleased,
                'extension_facilities_released' => $allExtensionFacilitiesReleased,
            ]);
        }
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