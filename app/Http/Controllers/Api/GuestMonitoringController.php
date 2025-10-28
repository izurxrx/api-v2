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
                
                // ✅ STEP 11: Create billing and process payment (full payment for walk-ins)
                $paymentData = [
                    'amount_paid' => $request->payment['amount_paid'],
                    'payment_method' => $request->payment['payment_method'],
                    'change_amount' => $request->payment['change_amount'] ?? 0,
                    'reference_number' => $request->payment['reference_number'] ?? null,
                    'notes' => 'Walk-in guest payment',
                ];
                
                $billing = $this->billingService->createBillingForGuestEntry($guestEntry, $paymentData);
                
                // ✅ STEP 12: Get capacity warnings
                $warnings = session('capacity_warnings', []);
                
                // ✅ STEP 13: Log entry creation
                Log::info('Walk-in guest entry created', [
                    'guest_entry_id' => $guestEntry->id,
                    'entry_reference' => $guestEntry->entry_reference,
                    'total_amount' => $totalAmount,
                    'payment_received' => $request->payment['amount_paid'],
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
                    'request' => $request->except(['payment']),
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
            
            // Prepare checkout datetime
            $exitDate = Carbon::parse($request->exit_date);
            $exitTime = $request->exit_time ?: Carbon::now()->format('H:i');
            $exitDateTime = Carbon::parse("{$exitDate->toDateString()} {$exitTime}");
            
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
            
            // Update billing status if fully paid
            if ($guestEntry->billing && $guestEntry->billing->payment_status === 'paid') {
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
}