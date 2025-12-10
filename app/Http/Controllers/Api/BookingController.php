<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingFacility;
use App\Models\BookingGuestDiscount;
use App\Models\Facility;
use App\Models\Rate;
use App\Models\Discount;
use App\Models\BillingExtension;
use App\Services\BillingService;
use App\Services\FacilityAvailabilityService;
use App\Services\OvertimeCalculationService;
use App\Services\BookingOverlapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    protected $billingService;
    protected $availabilityService;
    protected $overlapService;

    public function __construct(
        BillingService $billingService,
        FacilityAvailabilityService $availabilityService,
        BookingOverlapService $overlapService
    ) {
        $this->billingService = $billingService;
        $this->availabilityService = $availabilityService;
        $this->overlapService = $overlapService;
    }

    /**
     * Store a new booking
     * ✅ FIXED: Properly handles Swimming vs Package bookings
     */
    public function store(StoreBookingRequest $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                // ✅ STEP 1: Calculate entrance fees (Swimming only)
                $entranceSubtotal = 0;
                $entranceDiscountAmount = 0;
                
                if ($request->booking_type === 'Swimming' && $request->entrance_rate_id) {
                    $entranceRate = Rate::findOrFail($request->entrance_rate_id);
                    $baseEntranceTotal = $entranceRate->base_price * $request->number_of_guests;
                    $entranceSubtotal = $baseEntranceTotal;
                    
                    // ✅ Apply entrance discounts based on mode
                    if ($request->discount_mode === 'Direct' && $request->has('guest_discounts')) {
                        // Calculate per-guest discounts
                        foreach ($request->guest_discounts as $guestDiscount) {
                            $discount = Discount::find($guestDiscount['discount_id']);
                            if ($discount && $discount->is_active) {
                                $guestCount = $guestDiscount['count'];
                                
                                if ($discount->type === 'Percentage') {
                                    $discountPerGuest = $entranceRate->base_price * ($discount->value / 100);
                                } else {
                                    $discountPerGuest = min($discount->value, $entranceRate->base_price);
                                }
                                
                                $entranceDiscountAmount += $discountPerGuest * $guestCount;
                            }
                        }
                    } elseif ($request->discount_mode === 'Seasonal' && $request->discount_id) {
                        // Apply seasonal discount to total entrance
                        $discount = Discount::findOrFail($request->discount_id);
                        if ($discount->is_active) {
                            if ($discount->type === 'Percentage') {
                                $entranceDiscountAmount = $baseEntranceTotal * ($discount->value / 100);
                            } else {
                                $entranceDiscountAmount = min($discount->value, $baseEntranceTotal);
                            }
                        }
                    }
                    
                    $entranceSubtotal = max(0, $baseEntranceTotal - $entranceDiscountAmount);
                }
                
                // ✅ STEP 2: Calculate facility fees
                $facilitySubtotal = 0;
                foreach ($request->facilities as $facilityData) {
                    $facilitySubtotal += $facilityData['rate_amount'] * $facilityData['quantity'];
                }
                
                // ✅ STEP 3: Calculate third party services
                $servicesTotal = 0;
                if ($request->has('third_party_services')) {
                    foreach ($request->third_party_services as $service) {
                        $servicesTotal += $service['amount'];
                    }
                }
                
                // ✅ STEP 4: Apply manual discount if present
                $manualDiscountAmount = 0;
                if ($request->discount_mode === 'Manual' && $request->manual_discount_amount) {
                    $manualDiscountAmount = $request->manual_discount_amount;
                }
                
                // ✅ STEP 5: Calculate totals
                $subtotal = $entranceSubtotal + $facilitySubtotal + $servicesTotal;
                $totalDiscountAmount = $entranceDiscountAmount + $manualDiscountAmount;
                $totalAmount = max(0, $subtotal - $manualDiscountAmount);
                
                // ✅ STEP 6: Prepare datetime fields
                $checkInDate = Carbon::parse($request->check_in_date);
                $checkInTime = $request->check_in_time ?: '14:00';
                $checkOutDate = Carbon::parse($request->check_out_date);
                $checkOutTime = $request->check_out_time ?: '12:00';

                $checkInDateTime = Carbon::parse("{$checkInDate->toDateString()} {$checkInTime}");
                $checkOutDateTime = Carbon::parse("{$checkOutDate->toDateString()} {$checkOutTime}");

                // Calculate duration
                $durationHours = $checkInDateTime->diffInHours($checkOutDateTime);

                // ✅ STEP 6.5: Check for booking proximity conflicts (2-hour window)
                $overlapCheck = $this->overlapService->checkProximity(
                    $checkInDateTime,
                    $checkOutDateTime,
                    null, // No booking to exclude (this is a new booking)
                    2 // 2-hour proximity window
                );

                // If conflicts found and no manager override provided
                if ($overlapCheck['has_conflicts'] && !$request->has('manager_override')) {
                    Log::warning('Booking proximity conflict detected', [
                        'check_in' => $checkInDateTime->toDateTimeString(),
                        'check_out' => $checkOutDateTime->toDateTimeString(),
                        'conflicts' => $overlapCheck['conflicts'],
                    ]);

                    return response()->json([
                        'status' => 'warning',
                        'message' => 'Booking conflicts detected. Manager override required.',
                        'warning_type' => 'booking_proximity_conflict',
                        'conflicts' => $overlapCheck['conflicts'],
                        'conflict_count' => $overlapCheck['conflict_count'],
                        'requires_manager_override' => true,
                        'override_instructions' => [
                            'message' => 'To proceed with this booking, a manager must provide their password and reason for override.',
                            'required_fields' => [
                                'manager_override.password' => 'Manager\'s password',
                                'manager_override.reason' => 'Reason for overriding the proximity warning',
                            ],
                        ],
                    ], 422);
                }

                // If conflicts found and manager override provided, validate it
                if ($overlapCheck['has_conflicts'] && $request->has('manager_override')) {
                    $this->validateManagerOverride($request->manager_override, $overlapCheck);
                }

                // ✅ STEP 7: Create booking record
                $booking = Booking::create([
                    'booking_reference' => Booking::generateBookingReference(),
                    'booking_type' => $request->booking_type,
                    'entrance_rate_id' => $request->booking_type === 'Swimming' ? $request->entrance_rate_id : null,
                    'guest_name' => $request->guest_name,
                    'contact_number' => $request->contact_number,
                    'check_in_date' => $checkInDate,
                    'check_out_date' => $checkOutDate,
                    'check_in_datetime' => $checkInDateTime,
                    'check_out_datetime' => $checkOutDateTime,
                    'check_in_time' => $checkInTime,
                    'check_out_time' => $checkOutTime,
                    'duration_hours' => $durationHours,
                    'number_of_guests' => $request->number_of_guests,
                    'guest_breakdown' => $request->guest_breakdown ? json_encode($request->guest_breakdown) : null,
                    'facility_subtotal' => $facilitySubtotal,
                    'entrance_subtotal' => $entranceSubtotal,
                    'subtotal' => $subtotal,
                    'third_party_service_amount' => $servicesTotal,
                    'discount_mode' => $request->determineDiscountMode(), // ✅ Auto-determine from actual values
                    'discount_id' => $request->discount_id ?? null,
                    'manual_discount_amount' => $manualDiscountAmount,
                    'discount_amount' => $totalDiscountAmount,
                    'total_amount' => $totalAmount,
                    'booking_status' => 'Pending',
                    'cancellation_deadline' => $checkInDateTime->copy()->subHours(72),
                    'special_requests' => $request->special_requests,
                    'notes' => $request->notes,
                    'created_by' => auth()->id(),
                ]);
                
                // ✅ STEP 8: Save facilities
                foreach ($request->facilities as $facilityData) {
                    $rate = Rate::findOrFail($facilityData['rate_id']);
                    
                    BookingFacility::create([
                        'booking_id' => $booking->id,
                        'facility_id' => $facilityData['facility_id'],
                        'rate_id' => $facilityData['rate_id'],
                        'quantity' => $facilityData['quantity'],
                        'rate_amount' => $rate->base_price,
                        'subtotal' => $rate->base_price * $facilityData['quantity'],
                    ]);
                }
                
                // ✅ STEP 9: Save guest discounts (for Direct mode)
                if ($request->discount_mode === 'Direct' && $request->has('guest_discounts')) {
                    foreach ($request->guest_discounts as $guestDiscount) {
                        BookingGuestDiscount::create([
                            'booking_id' => $booking->id,
                            'guest_type' => $guestDiscount['guest_type'],
                            'guest_count' => $guestDiscount['count'],
                            'discount_id' => $guestDiscount['discount_id'],
                        ]);
                    }
                }
                
                // ✅ STEP 10: Save third party services
                if ($request->has('third_party_services')) {
                    foreach ($request->third_party_services as $service) {
                        $booking->thirdPartyServices()->create([
                            'service_name' => $service['service_name'],
                            'amount' => $service['amount'],
                        ]);
                    }
                }
                
                // ✅ STEP 11: Create billing WITHOUT payment
                // Payment is now handled separately in the Billing module
                $billing = $this->billingService->createBillingForBooking(
                    $booking, 
                    null, // No payment data
                    50 // 50% downpayment requirement
                );
                
                // ✅ STEP 12: Check for capacity warnings
                $warnings = session('capacity_warnings', []);
                
                // ✅ STEP 13: Log booking creation
                Log::info('Booking created', [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'booking_type' => $booking->booking_type,
                    'total_amount' => $totalAmount,
                    'billing_id' => $billing->id,
                    'created_by' => auth()->id(),
                ]);
                
                // Load relationships
                $booking->load([
                    'entranceRate',
                    'facilities.facility',
                    'facilities.rate',
                    'guestDiscounts.discount',
                    'thirdPartyServices',
                    'billing.payments',
                    'createdBy',
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking created successfully',
                    'data' => new BookingResource($booking),
                    'warnings' => $warnings, // Include capacity warnings
                ], 201);
                
            } catch (\Exception $e) {
                Log::error('Booking creation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'request' => $request->all(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Get paginated list of bookings
     */
    public function index(Request $request)
    {
        $query = Booking::with([
            'entranceRate',
            'facilities.facility',
            'guestDiscounts.discount',
            'billing.payments',
            'billing.extensions',
            'createdBy',
        ]);

        // Filter by booking type
        if ($request->has('booking_type') && $request->booking_type !== 'all') {
            $query->where('booking_type', $request->booking_type);
        }

        // ✅ FIXED: Filter by booking status (support both 'status' and 'booking_status' params)
        $bookingStatus = $request->input('booking_status') ?? $request->input('status');
        if ($bookingStatus && $bookingStatus !== 'all') {
            $query->where('booking_status', $bookingStatus);
            
            // ✅ NEW: Exclude already checked-in bookings from "Confirmed" list
            // When filtering for "Confirmed" bookings, only show those that haven't been checked in yet
            if ($bookingStatus === 'Confirmed') {
                $query->doesntHave('guestEntry');
            }
        }

        // ✅ NEW: Filter by payment status (from billing relationship)
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->whereHas('billing', function($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            });
        }

        // ✅ NEW: Filter by facility
        if ($request->has('facility_id') && $request->facility_id) {
            $query->whereHas('facilities', function($q) use ($request) {
                $q->where('facility_id', $request->facility_id);
            });
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('check_in_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('check_in_date', '<=', $request->date_to);
        }

        // Search by reference or guest name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate
        $perPage = $request->input('per_page', 15);
        $bookings = $query->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'status' => 'success',
            'message' => 'Bookings retrieved successfully',
        ]);
    }

    /**
     * Get single booking
     */
    public function show($id)
    {
        $booking = Booking::with([
            'entranceRate',
            'facilities.facility.facilityType',
            'facilities.rate',
            'guestDiscounts.discount',
            'thirdPartyServices',
            'billing.payments.receivedBy',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'cancelledBy',
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * Update booking
     * ✅ FIXED: Now respects booking type rules
     */
    public function update(UpdateBookingRequest $request, $id)
    {
        $booking = Booking::findOrFail($id);
        
        // ✅ VALIDATION: Cannot edit checked-in or checked-out bookings
        if (in_array($booking->booking_status, ['Checked_In', 'Checked_Out'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot edit bookings that are already checked-in or checked-out',
            ], 422);
        }
        
        // ✅ VALIDATION: Confirmed bookings can only update contact details
        if ($booking->booking_status === 'Confirmed') {
            return DB::transaction(function () use ($request, $booking) {
                $booking->update([
                    'guest_name' => $request->guest_name,
                    'contact_number' => $request->contact_number,
                    'special_requests' => $request->special_requests,
                    'updated_by' => auth()->id(),
                ]);
                
                Log::info('Confirmed booking contact details updated', [
                    'booking_id' => $booking->id,
                    'updated_by' => auth()->id(),
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking contact details updated successfully. Use billing extensions to add facilities, guests, or services.',
                    'data' => new BookingResource($booking->fresh()),
                    'hint' => 'To modify facilities, guests, or amounts, use: POST /api/billings/{billing_id}/extensions',
                ]);
            });
        }
        
        // ✅ Full edit allowed only for Pending bookings
        return DB::transaction(function () use ($request, $booking) {
            try {
                // Store old total for comparison
                $oldTotal = $booking->billing->total_amount;
                
                // ✅ STEP 1: Recalculate entrance fees if changed
                $entranceSubtotal = 0;
                $entranceDiscountAmount = 0;
                
                if ($request->booking_type === 'Swimming' && $request->entrance_rate_id) {
                    $entranceRate = Rate::findOrFail($request->entrance_rate_id);
                    $baseEntranceTotal = $entranceRate->base_price * $request->number_of_guests;
                    $entranceSubtotal = $baseEntranceTotal;
                    
                    // Apply entrance discounts
                    if ($request->discount_mode === 'Direct' && $request->has('guest_discounts')) {
                        foreach ($request->guest_discounts as $guestDiscount) {
                            $discount = Discount::find($guestDiscount['discount_id']);
                            if ($discount && $discount->is_active) {
                                $guestCount = $guestDiscount['count'];
                                
                                if ($discount->type === 'Percentage') {
                                    $discountPerGuest = $entranceRate->base_price * ($discount->value / 100);
                                } else {
                                    $discountPerGuest = min($discount->value, $entranceRate->base_price);
                                }
                                
                                $entranceDiscountAmount += $discountPerGuest * $guestCount;
                            }
                        }
                    } elseif ($request->discount_mode === 'Seasonal' && $request->discount_id) {
                        $discount = Discount::findOrFail($request->discount_id);
                        if ($discount->is_active) {
                            if ($discount->type === 'Percentage') {
                                $entranceDiscountAmount = $baseEntranceTotal * ($discount->value / 100);
                            } else {
                                $entranceDiscountAmount = min($discount->value, $baseEntranceTotal);
                            }
                        }
                    }
                    
                    $entranceSubtotal = max(0, $baseEntranceTotal - $entranceDiscountAmount);
                }
                
                // ✅ STEP 2: Recalculate facility fees
                $facilitySubtotal = 0;
                
                // Delete old facilities
                BookingFacility::where('booking_id', $booking->id)->delete();
                
                // Add new facilities
                foreach ($request->facilities as $facilityData) {
                    BookingFacility::create([
                        'booking_id' => $booking->id,
                        'facility_id' => $facilityData['facility_id'],
                        'rate_id' => $facilityData['rate_id'],
                        'quantity' => $facilityData['quantity'],
                        'rate_amount' => $facilityData['rate_amount'],
                    ]);
                    
                    $facilitySubtotal += $facilityData['rate_amount'] * $facilityData['quantity'];
                }
                
                // ✅ STEP 3: Calculate third party services
                $servicesTotal = 0;
                if ($request->has('third_party_services')) {
                    foreach ($request->third_party_services as $service) {
                        $servicesTotal += $service['amount'];
                    }
                }
                
                // ✅ STEP 4: Apply manual discount if present
                $manualDiscountAmount = $request->manual_discount_amount ?? 0;
                
                // ✅ STEP 5: Calculate final total
                $finalTotal = $entranceSubtotal + $facilitySubtotal + $servicesTotal - $manualDiscountAmount;
                
                // ✅ STEP 6: Update booking
                $booking->update([
                    'booking_type' => $request->booking_type,
                    'number_of_guests' => $request->number_of_guests,
                    'check_in_date' => $request->check_in_date,
                    'check_out_date' => $request->check_out_date,
                    'entrance_rate_id' => $request->entrance_rate_id,
                    'discount_mode' => $request->discount_mode ?? 'None',
                    'discount_id' => $request->discount_id,
                    'manual_discount_amount' => $manualDiscountAmount,
                    'special_requests' => $request->special_requests,
                    'updated_by' => auth()->id(),
                ]);
                
                // ✅ STEP 7: Update guest discounts
                if ($request->has('guest_discounts')) {
                    BookingGuestDiscount::where('booking_id', $booking->id)->delete();
                    foreach ($request->guest_discounts as $guestDiscount) {
                        BookingGuestDiscount::create([
                            'booking_id' => $booking->id,
                            'discount_id' => $guestDiscount['discount_id'],
                            'guest_count' => $guestDiscount['count'],
                        ]);
                    }
                }
                
                // ✅ STEP 8: Update billing
                $newBalance = $booking->billing->balance + ($finalTotal - $oldTotal);
                
                $booking->billing->update([
                    'entrance_subtotal' => $entranceSubtotal,
                    'entrance_discount_amount' => $entranceDiscountAmount,
                    'facilities_subtotal' => $facilitySubtotal,
                    'third_party_services_total' => $servicesTotal,
                    'manual_discount_amount' => $manualDiscountAmount,
                    'total_amount' => $finalTotal,
                    'balance' => max(0, $newBalance),
                ]);
                
                Log::info('Booking updated', [
                    'booking_id' => $booking->id,
                    'updated_by' => auth()->id(),
                    'old_total' => $oldTotal,
                    'new_total' => $finalTotal,
                    'difference' => $finalTotal - $oldTotal,
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking updated successfully',
                    'data' => new BookingResource($booking->fresh()),
                    'changes' => [
                        'old_total' => $oldTotal,
                        'new_total' => $finalTotal,
                        'difference' => $finalTotal - $oldTotal,
                        'new_balance' => $newBalance,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('Booking update failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Cancel booking
     */
    public function cancel(CancelBookingRequest $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with('billing')->findOrFail($id);
            
            // Update booking status
            $booking->update([
                'booking_status' => 'Cancelled',
                'cancellation_reason' => $request->cancellation_reason,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ]);
            
            // Cancel associated billing
            if ($booking->billing) {
                $this->billingService->cancelBilling(
                    $booking->billing,
                    $request->cancellation_reason
                );
            }
            
            Log::info('Booking cancelled', [
                'booking_id' => $booking->id,
                'cancelled_by' => auth()->id(),
                'reason' => $request->cancellation_reason,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Booking cancelled successfully',
                'data' => new BookingResource($booking->fresh()),
            ]);
        });
    }

    /**
     * Check-in a booking
     * 
     * ⚠️ DEPRECATED: Use GuestMonitoringController::checkInBooking() instead
     * This method is kept for backward compatibility but should not be used for new implementations.
     * Check-ins should be done through the Guest Monitoring module to maintain a unified check-in/out workflow.
     * 
     * @deprecated Use POST /guest-monitoring/check-in-booking/{bookingId} instead
     */
    public function checkIn(Request $request, $id)
    {
        $request->validate([
            'actual_guests' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);
        
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with('billing', 'facilities.facility')->findOrFail($id);
            
            // ✅ VALIDATION 1: Only Confirmed bookings can be checked in
            if ($booking->booking_status !== 'Confirmed') {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot check-in booking with status: {$booking->booking_status}. Only Confirmed bookings can be checked in.",
                ], 400);
            }
            
            // ✅ VALIDATION 2: Downpayment must be paid
            if (!$booking->billing || !$booking->billing->is_downpayment_paid) {
                $required = $booking->billing ? $booking->billing->downpayment_amount : 0;
                $paid = $booking->billing ? $booking->billing->downpayment_paid : 0;
                
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Cannot check-in. Downpayment required: ₱%.2f, Paid: ₱%.2f',
                        $required,
                        $paid
                    ),
                    'required_downpayment' => $required,
                    'amount_paid' => $paid,
                    'balance' => $required - $paid,
                ], 400);
            }
            
            // ✅ VALIDATION 3: Check if within check-in window
            $now = now();
            $checkInTime = $booking->check_in_datetime;
            $earlyCheckIn = $checkInTime->copy()->subHours(2); // Allow 2 hours early
            $lateCheckIn = $checkInTime->copy()->addHours(4); // Allow 4 hours late
            
            if ($now->lt($earlyCheckIn)) {
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Too early to check-in. Check-in time: %s (You can check-in from %s onwards)',
                        $checkInTime->format('M d, Y h:i A'),
                        $earlyCheckIn->format('M d, Y h:i A')
                    ),
                ], 400);
            }
            
            // ✅ VALIDATION 4: Check for No-Show (more than 4 hours late)
            if ($now->gt($lateCheckIn)) {
                // Auto-mark as No-Show
                $booking->update([
                    'booking_status' => 'No_Show',
                    'notes' => sprintf(
                        '%s | Auto-marked as No-Show: Guest did not check-in within 4 hours of scheduled time.',
                        $booking->notes ?? ''
                    ),
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => sprintf(
                        'Check-in window expired. Scheduled check-in: %s. Booking marked as No-Show.',
                        $checkInTime->format('M d, Y h:i A')
                    ),
                ], 400);
            }
            
            // ✅ VALIDATION 5: Verify facilities are still available
            foreach ($booking->facilities as $bookingFacility) {
                $facility = $bookingFacility->facility;
                
                if (!$facility || $facility->trashed()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Facility '{$facility->name}' is no longer available.",
                    ], 400);
                }
                
                // Check if facility was double-booked or quantity changed
                $available = $this->availabilityService->getAvailableQuantity(
                    $facility->id,
                    $booking->check_in_datetime,
                    $booking->check_out_datetime,
                    $booking->id // Exclude current booking
                );
                
                if ($available < $bookingFacility->quantity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => sprintf(
                            "Facility '%s' is no longer available in requested quantity. Available: %d, Required: %d",
                            $facility->name,
                            $available,
                            $bookingFacility->quantity
                        ),
                    ], 400);
                }
            }
            
            // ✅ All validations passed - proceed with check-in
            $booking->update([
                'booking_status' => 'Checked_In',
                'actual_check_in_datetime' => $now,
                'checked_in_by' => auth()->id(),
                'actual_guests' => $request->actual_guests ?? $booking->number_of_guests,
                'notes' => $request->notes ? ($booking->notes . ' | ' . $request->notes) : $booking->notes,
            ]);
            
            Log::info('Booking checked in', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'checked_in_by' => auth()->id(),
                'actual_guests' => $booking->actual_guests,
            ]);
            
            // Reload booking with all relationships
            $booking->load([
                'entranceRate',
                'facilities.facility',
                'billing.payments',
                'createdBy',
                'checkedInBy',
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked in successfully',
                'data' => new BookingResource($booking),
            ]);
        });
    }

    /**
     * Check-out a booking
     * 
     * ⚠️ DEPRECATED: Use GuestMonitoringController::checkout() instead
     * This method is kept for backward compatibility but should not be used for new implementations.
     * Check-outs should be done through the Guest Monitoring module to maintain a unified check-in/out workflow.
     * 
     * ✅ NEW: Calculates overtime based on facility-specific checkout times
     * 
     * @deprecated Use POST /guest-monitoring/{guestEntryId}/checkout instead
     */
    public function checkOut(Request $request, $id)
    {
        $request->validate([
            'actual_checkout_datetime' => 'nullable|date',
            'apply_overtime' => 'nullable|boolean',
            'additional_charges' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with([
                'billing.payments',
                'facilities.rate',
                'facilities.facility',
            ])->findOrFail($id);
            
            // Validate booking can be checked out
            if ($booking->booking_status !== 'Checked_In') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only checked-in bookings can be checked out',
                ], 400);
            }
            
            // Use provided checkout time or current timestamp
            $actualCheckout = $request->actual_checkout_datetime 
                ? Carbon::parse($request->actual_checkout_datetime) 
                : now();
            
            $billing = $booking->billing;
            
            // ✅ NEW: Apply overtime charges if explicitly requested (opt-in)
            $overtimeTotal = 0;
            $overtimeDetails = [];
            
            // Staff must explicitly opt-in to apply overtime charges
            if ($request->has('apply_overtime') && $request->apply_overtime === true) {
                $overtimeService = new OvertimeCalculationService();
                $overtimeCharges = $overtimeService->calculateBookingOvertime($booking, $actualCheckout);
                
                if ($overtimeCharges->isNotEmpty()) {
                    foreach ($overtimeCharges as $charge) {
                        // Create billing extension record
                        $extension = BillingExtension::create([
                            'billing_id' => $billing->id,
                            'facility_id' => $charge['facility_id'],
                            'rate_id' => $charge['rate_id'],
                            'discount_id' => $charge['discount_id'] ?? null,
                            'is_overtime' => true,
                            'hours' => $charge['overtime_hours'],
                            'total_amount' => $charge['final_amount'],
                            'discount_amount' => $charge['discount_amount'] ?? 0,
                            'facility_start_datetime' => $charge['scheduled_checkout'],
                            'facility_end_datetime' => $actualCheckout,
                            'metadata' => json_encode([
                                'extension_fee' => $charge['extension_fee'],
                                'quantity' => $charge['quantity'],
                                'calculation_method' => config('billing.overtime.calculation_method'),
                                'grace_period_applied' => true,
                            ]),
                            'created_by' => auth()->id(),
                        ]);
                        
                        // Update booking_facility with extension data
                        $facilityBooking = $booking->facilities->firstWhere('id', $charge['booking_facility_id']);
                        if ($facilityBooking) {
                            $facilityBooking->update([
                                'extension_hours' => $charge['overtime_hours'],
                                'extension_amount' => $charge['final_amount'],
                                'end_datetime' => $actualCheckout,
                            ]);
                        }
                        
                        $overtimeTotal += $charge['final_amount'];
                        $overtimeDetails[] = [
                            'facility_id' => $charge['facility_id'],
                            'facility_name' => $charge['facility_name'],
                            'rate_name' => $charge['rate_name'],
                            'overtime_hours' => $charge['overtime_hours'],
                            'extension_fee' => $charge['extension_fee'],
                            'quantity' => $charge['quantity'],
                            'subtotal' => $charge['subtotal'],
                            'discount_amount' => $charge['discount_amount'] ?? 0,
                            'final_amount' => $charge['final_amount'],
                            'formatted_amount' => '₱' . number_format($charge['final_amount'], 2),
                        ];
                    }
                    
                    // Update billing totals
                    $billing->update([
                        'subtotal' => $billing->subtotal + $overtimeTotal,
                        'total_amount' => $billing->total_amount + $overtimeTotal,
                        'balance' => $billing->balance + $overtimeTotal,
                    ]);
                }
            }
            
            // Add additional manual charges if any
            $additionalCharges = $request->additional_charges ?? 0;
            if ($additionalCharges > 0 && $billing) {
                $billing->update([
                    'additional_charges' => $additionalCharges,
                    'total_amount' => $billing->total_amount + $additionalCharges,
                    'balance' => $billing->balance + $additionalCharges,
                ]);
            }
            
            // ✅ CRITICAL: Validate payment is complete before checkout
            $billing->refresh();
            if ($billing->balance > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot checkout with outstanding balance',
                    'balance' => $billing->balance,
                    'overtime_total' => $overtimeTotal,
                    'additional_charges' => $additionalCharges,
                    'total_due' => $billing->balance,
                ], 422);
            }
            
            // Update booking
            $booking->update([
                'booking_status' => 'Checked_Out',
                'actual_check_out_datetime' => $actualCheckout,
                'check_out_datetime' => $actualCheckout, // ✅ CRITICAL: Revenue report uses this field
                'checked_out_by' => auth()->id(),
                'checkout_notes' => $request->notes,
            ]);
            
            Log::info('Booking checked out', [
                'booking_id' => $booking->id,
                'checked_out_by' => auth()->id(),
                'overtime_charges' => $overtimeDetails,
                'overtime_total' => $overtimeTotal,
                'additional_charges' => $additionalCharges,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Guest checked out successfully',
                'data' => new BookingResource($booking->fresh([
                    'facilities.facility',
                    'facilities.rate',
                    'billing.payments',
                    'billing.extensions',
                ])),
                'overtime' => [
                    'has_overtime' => $overtimeTotal > 0,
                    'total' => $overtimeTotal,
                    'formatted_total' => '₱' . number_format($overtimeTotal, 2),
                    'details' => $overtimeDetails,
                ],
                'additional_charges' => $additionalCharges,
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
            'actual_checkout_datetime' => 'nullable|date',
        ]);

        $booking = Booking::with([
            'billing.payments',
            'facilities.rate',
            'facilities.facility',
        ])->findOrFail($id);

        // ✅ Validate booking can be previewed
        if ($booking->booking_status !== 'Checked_In') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only checked-in bookings can be previewed for checkout',
            ], 422);
        }

        // Use provided checkout time or current timestamp
        $actualCheckout = $request->actual_checkout_datetime 
            ? Carbon::parse($request->actual_checkout_datetime) 
            : now();

        // ✅ Preview overtime calculation (does not save to database)
        $overtimeTotal = 0;
        $overtimeDetails = [];

        if (config('billing.overtime.auto_calculate', true)) {
            $overtimeService = new OvertimeCalculationService();
            $overtimePreview = $overtimeService->previewOvertime($booking, $actualCheckout);
            
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
                        'quantity' => $charge['quantity'],
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

        $billing = $booking->billing;
        $currentBalance = (float) $billing->balance;
        $newBalance = $currentBalance + $overtimeTotal;

        return response()->json([
            'status' => 'success',
            'message' => 'Checkout preview generated',
            'data' => [
                'booking_id' => $booking->id,
                'actual_checkout_datetime' => $actualCheckout->toIso8601String(),
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
     * Get available entrance rates for Swimming bookings
     */
    public function getEntranceRates()
    {
        $rates = Rate::where('rate_category', 'Entrance')
            ->whereNull('facility_id')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $rates->map(function($rate) {
                return [
                    'id' => $rate->id,
                    'name' => $rate->rate_name,
                    'price' => $rate->base_price,
                    'duration_hours' => $rate->duration,
                    'time_slot' => $this->getTimeSlot($rate->rate_name),
                ];
            }),
        ]);
    }

    /**
     * Get time slot from rate name
     */
    private function getTimeSlot($rateName)
    {
        if (str_contains($rateName, 'Day Rate')) {
            return '8:00 AM - 5:00 PM';
        } elseif (str_contains($rateName, 'Night Rate')) {
            return '5:00 PM - 10:00 PM';
        } elseif (str_contains($rateName, 'Day & Night')) {
            return '8:00 AM - 10:00 PM';
        }
        return null;
    }

    /**
     * Get booking summary/dashboard data
     */
    public function summary(Request $request)
    {
        $today = Carbon::today();
        
        // Today's stats
        $todayCheckIns = Booking::whereDate('check_in_date', $today)
            ->whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
            
        $todayCheckOuts = Booking::whereDate('check_out_date', $today)
            ->where('booking_status', 'Checked_In')
            ->count();
        
        // Active bookings
        $activeBookings = Booking::whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
        
        // Booking types breakdown
        $bookingTypes = Booking::select('booking_type', DB::raw('count(*) as count'))
            ->whereIn('booking_status', ['Confirmed', 'Checked_In', 'Checked_Out'])
            ->groupBy('booking_type')
            ->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'today' => [
                    'check_ins' => $todayCheckIns,
                    'check_outs' => $todayCheckOuts,
                ],
                'active_bookings' => $activeBookings,
                'booking_types' => $bookingTypes,
            ],
        ]);
    }

    /**
     * Cancel and Refund booking - Manager only
     */
    public function cancelRefund(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);
        
        // ✅ VALIDATION: Only Pending and Confirmed bookings can be cancelled
        if (!in_array($booking->booking_status, ['Pending', 'Confirmed'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only Pending and Confirmed bookings can be cancelled. Current status: ' . $booking->booking_status,
            ], 422);
        }
        
        $request->validate([
            'refund_amount' => 'required|numeric|min:0',
            'refund_reason' => 'required|string|max:500',
            'override_downpayment_policy' => 'boolean',
        ]);
        
        return DB::transaction(function () use ($request, $booking) {
            try {
                $billing = $booking->billing;
                $totalPaid = $billing->downpayment_paid + $billing->balance_paid;
                $downpaymentPaid = $billing->downpayment_paid ?? 0;
                $balancePaid = $billing->balance_paid ?? 0;

                // ✅ POLICY: Downpayment (50% of booking total) is 100% non-refundable
                // Example: ₱10,000 booking → ₱5,000 downpayment (100% non-refundable)
                // Only balance payments exceeding the downpayment can be refunded
                $maxRefundAmount = $balancePaid; // Only balance payments are refundable

                // ✅ OVERRIDE: Manager can override downpayment policy to refund 100%
                if ($request->override_downpayment_policy === true) {
                    $maxRefundAmount = $totalPaid; // Refund everything including non-refundable downpayment
                }

                $refundAmount = min($request->refund_amount, $maxRefundAmount);
                
                // ✅ Update billing status to voided (cancelled) and payment status to refunded
                $billing->update([
                    'billing_status' => 'voided',
                    'payment_status' => 'refunded',
                    'refund_amount' => $refundAmount,
                    'refund_reason' => $request->refund_reason,
                    'refunded_by' => auth()->id(),
                    'refunded_at' => now(),
                ]);
                
                // ✅ Update booking status
                $booking->update([
                    'booking_status' => 'Cancelled',
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                    'cancellation_reason' => $request->refund_reason,
                ]);
                
                Log::info('Booking cancelled and refunded', [
                    'booking_id' => $booking->id,
                    'refund_amount' => $refundAmount,
                    'total_paid' => $totalPaid,
                    'downpayment_paid' => $downpaymentPaid,
                    'balance_paid' => $balancePaid,
                    'non_refundable_downpayment' => $downpaymentPaid,
                    'downpayment_overridden' => $request->override_downpayment_policy ?? false,
                    'refunded_by' => auth()->id(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Booking cancelled and refunded successfully',
                    'data' => [
                        'booking' => new BookingResource($booking->fresh()),
                        'refund' => [
                            'refund_amount' => $refundAmount,
                            'payment_breakdown' => [
                                'total_paid' => $totalPaid,
                                'downpayment_paid' => $downpaymentPaid,
                                'balance_paid' => $balancePaid,
                            ],
                            'refund_breakdown' => [
                                'refundable_from_downpayment' => 0, // Downpayment is 100% non-refundable
                                'non_refundable_from_downpayment' => $downpaymentPaid,
                                'refundable_from_balance' => $balancePaid,
                                'max_refundable' => $maxRefundAmount,
                            ],
                            'policy' => [
                                'downpayment_policy' => 'Downpayment (50% of booking total) is 100% non-refundable',
                                'downpayment_policy_overridden' => $request->override_downpayment_policy ?? false,
                            ],
                            'non_refundable_amount' => $totalPaid - $refundAmount,
                        ],
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('Booking refund failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Validate manager override for booking proximity conflicts
     *
     * @param array $overrideData
     * @param array $overlapCheck
     * @throws \Illuminate\Validation\ValidationException
     * @return void
     */
    private function validateManagerOverride($overrideData, $overlapCheck)
    {
        $user = auth()->user();

        // ✅ VALIDATION 1: Check if user is authenticated
        if (!$user) {
            abort(401, 'Unauthenticated. Please log in to override booking conflicts.');
        }

        // ✅ VALIDATION 2: Check if user has Manager or Admin role
        if (!$user->hasRole(['Manager', 'Admin'])) {
            Log::warning('Unauthorized override attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames(),
                'conflicts' => $overlapCheck['conflicts'],
            ]);

            abort(403, 'Only Managers or Admins can override booking proximity warnings.');
        }

        // ✅ VALIDATION 3: Validate password field exists
        if (!isset($overrideData['password'])) {
            abort(422, 'Manager password is required to override booking conflicts.');
        }

        // ✅ VALIDATION 4: Verify password matches current user
        if (!Hash::check($overrideData['password'], $user->password)) {
            Log::warning('Failed manager override - incorrect password', [
                'user_id' => $user->id,
                'username' => $user->username,
                'conflicts' => $overlapCheck['conflicts'],
            ]);

            abort(401, 'Incorrect password. Please verify your credentials.');
        }

        // ✅ VALIDATION 5: Validate reason field exists
        if (!isset($overrideData['reason']) || empty(trim($overrideData['reason']))) {
            abort(422, 'A reason is required to override booking conflicts.');
        }

        // ✅ VALIDATION 6: Reason must be at least 10 characters
        if (strlen(trim($overrideData['reason'])) < 10) {
            abort(422, 'Override reason must be at least 10 characters long.');
        }

        // ✅ All validations passed - Log the override
        Log::info('✅ Manager override approved for booking proximity conflict', [
            'manager_id' => $user->id,
            'manager_name' => $user->full_name,
            'manager_role' => $user->getRoleNames(),
            'override_reason' => $overrideData['reason'],
            'conflicts_overridden' => $overlapCheck['conflicts'],
            'conflict_count' => $overlapCheck['conflict_count'],
            'severity_levels' => collect($overlapCheck['conflicts'])->pluck('severity')->unique()->toArray(),
        ]);

        // Log to activity log for audit trail
        activity()
            ->causedBy($user)
            ->withProperties([
                'action' => 'booking_proximity_override',
                'reason' => $overrideData['reason'],
                'conflicts' => $overlapCheck['conflicts'],
                'conflict_count' => $overlapCheck['conflict_count'],
            ])
            ->log('Manager overrode booking proximity warning');
    }
}
