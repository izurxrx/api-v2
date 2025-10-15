<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestEntryResource;
use App\Models\Discount;
use App\Models\Facility;
use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Models\GuestEntryFacility;
use App\Models\Rate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestMonitoringController extends Controller
{
    /**
     * Display a listing of guest entries
     */
    public function index(Request $request)
    {
        $query = GuestEntry::with([
            'details.rate',
            'details.autoDiscount',
            'details.manualDiscount',
            'facilities.facility.facilityType',
            'createdBy',
        ]);

        // Filters
        if ($request->has('date')) {
            $query->whereDate('entry_date', $request->date);
        }

        if ($request->has('status')) {
            if ($request->status === 'checked_out') {
                $query->where('is_checked_out', true);
            } else {
                $query->where('is_checked_out', false);
            }
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_reference', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('contact_number', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 5);
        $entries = $query->latest()->paginate($perPage);

        return $this->paginatedCollection($entries, GuestEntryResource::class);
    }

    /**
     * Store a newly created guest entry
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'entry_date' => 'required|date',
            'entry_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string',
            
            // Guest details array (guest groups)
            'guests' => 'required|array|min:1',
            'guests.*.rate_id' => 'required|exists:rates,id',
            'guests.*.guest_count' => 'required|integer|min:1',
            'guests.*.auto_discount_id' => 'nullable|exists:discounts,id',
            'guests.*.manual_discount_id' => 'nullable|exists:discounts,id',
            
            // Facility rentals array (optional)
            'facilities' => 'nullable|array',
            'facilities.*.facility_id' => 'required|exists:facilities,id',
            'facilities.*.rate_id' => 'required|exists:rates,id',
            'facilities.*.start_datetime' => 'required|date',
            'facilities.*.end_datetime' => 'required|date|after:facilities.*.start_datetime',
        ]);

        DB::beginTransaction();
        try {
            // Generate reference number
            $reference = $this->generateReferenceNumber();
            
            // Calculate total guests
            $totalGuests = collect($validated['guests'])->sum('guest_count');
            
            // Create main entry
            $guestEntry = GuestEntry::create([
                'entry_reference' => $reference,
                'entry_date' => $validated['entry_date'],
                'entry_time' => $validated['entry_time'] ?? now()->format('H:i:s'),
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'] ?? null,
                'total_guests' => $totalGuests,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Process guest details (entrance fees)
            $entranceSubtotal = 0;
            foreach ($validated['guests'] as $guest) {
                $detail = $this->createGuestDetail($guestEntry->id, $guest);
                $entranceSubtotal += $detail->total_amount;
            }

            // Process facility rentals (if any)
            $facilitySubtotal = 0;
            if (isset($validated['facilities']) && count($validated['facilities']) > 0) {
                foreach ($validated['facilities'] as $facility) {
                    $rental = $this->createFacilityRental($guestEntry->id, $facility);
                    $facilitySubtotal += $rental->subtotal;
                }
            }

            // Update totals
            $guestEntry->update([
                'entrance_subtotal' => $entranceSubtotal,
                'facility_subtotal' => $facilitySubtotal,
                'subtotal' => $entranceSubtotal + $facilitySubtotal,
                'total_amount' => $entranceSubtotal + $facilitySubtotal,
                'balance' => $entranceSubtotal + $facilitySubtotal,
            ]);

            DB::commit();

            // Load relationships for response
            $guestEntry->load([
                'details.rate',
                'details.autoDiscount',
                'details.manualDiscount',
                'facilities.facility.facilityType',
                'facilities.rate',
                'createdBy',
            ]);

            return response()->json([
                'message' => 'Guest entry created successfully',
                'data' => new GuestEntryResource($guestEntry),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create guest entry',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified guest entry
     */
    public function show($id)
    {
        $entry = GuestEntry::with([
            'details.rate',
            'details.autoDiscount',
            'details.manualDiscount',
            'facilities.facility.facilityType',
            'facilities.rate',
            'payments.receivedBy',
            'createdBy',
        ])->findOrFail($id);

        return response()->json([
            'data' => new GuestEntryResource($entry),
        ]);
    }

    /**
     * Update the specified guest entry
     */
    public function update(Request $request, $id)
    {
        $entry = GuestEntry::findOrFail($id);

        $validated = $request->validate([
            'guest_name' => 'sometimes|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $entry->update($validated);

        // Recalculate totals if discount changed
        if (isset($validated['discount_amount'])) {
            $entry->recalculateTotals();
        }

        $entry->load([
            'details.rate',
            'details.autoDiscount',
            'details.manualDiscount',
            'facilities.facility',
            'createdBy',
        ]);

        return response()->json([
            'message' => 'Guest entry updated successfully',
            'data' => new GuestEntryResource($entry),
        ]);
    }

    /**
     * Check-out guest
     */
    public function checkout(Request $request, $id)
    {
        $entry = GuestEntry::findOrFail($id);

        if ($entry->is_checked_out) {
            return response()->json([
                'message' => 'Guest already checked out',
            ], 400);
        }

        $validated = $request->validate([
            'facility_extensions' => 'nullable|array',
            'facility_extensions.*.id' => 'required|exists:guest_entry_facilities,id',
            'facility_extensions.*.additional_hours' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Process facility extensions if any
            if (isset($validated['facility_extensions'])) {
                foreach ($validated['facility_extensions'] as $extension) {
                    $this->processFacilityExtension($extension);
                }
                
                // Recalculate totals
                $entry->recalculateTotals();
            }

            $entry->update([
                'is_checked_out' => true,
            ]);

            DB::commit();

            $entry->load([
                'details.rate',
                'details.autoDiscount',
                'details.manualDiscount',
                'facilities.facility',
                'payments',
            ]);

            return response()->json([
                'message' => 'Guest checked out successfully',
                'data' => new GuestEntryResource($entry),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to checkout guest',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified guest entry
     */
    public function destroy($id)
    {
        $entry = GuestEntry::findOrFail($id);
        $entry->delete();

        return response()->json([
            'message' => 'Guest entry deleted successfully',
        ]);
    }

    // ============================================
    // HELPER METHODS - UPDATED
    // ============================================

    private function generateReferenceNumber()
    {
        $date = now()->format('Ymd');
        $count = GuestEntry::whereDate('created_at', today())->count() + 1;
        return 'EN' . $date . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create guest detail without guest_type_id
     * Discounts are now explicitly provided
     */
    private function createGuestDetail($guestEntryId, array $guestData)
    {
        $rate = Rate::findOrFail($guestData['rate_id']);
        $baseRate = $rate->base_price;
        
        $autoDiscountId = $guestData['auto_discount_id'] ?? null;
        $autoDiscountAmount = 0;

        // Apply automatic discount if provided (e.g., Senior Citizen, PWD)
        if ($autoDiscountId) {
            $autoDiscount = Discount::findOrFail($autoDiscountId);
            $autoDiscountAmount = $autoDiscount->calculateDiscountAmount($baseRate);
        }

        // Apply manual discount if provided (additional promotional discounts)
        $manualDiscountId = $guestData['manual_discount_id'] ?? null;
        $manualDiscountAmount = 0;
        if ($manualDiscountId) {
            $manualDiscount = Discount::findOrFail($manualDiscountId);
            $rateAfterAuto = $baseRate - $autoDiscountAmount;
            $manualDiscountAmount = $manualDiscount->calculateDiscountAmount($rateAfterAuto);
        }

        $finalRate = $baseRate - $autoDiscountAmount - $manualDiscountAmount;
        $totalAmount = $finalRate * $guestData['guest_count'];

        return GuestEntryDetail::create([
            'guest_entry_id' => $guestEntryId,
            'rate_id' => $rate->id,
            'guest_count' => $guestData['guest_count'],
            'base_rate' => $baseRate,
            'auto_discount_id' => $autoDiscountId,
            'auto_discount_amount' => $autoDiscountAmount,
            'manual_discount_id' => $manualDiscountId,
            'manual_discount_amount' => $manualDiscountAmount,
            'final_rate' => max(0, $finalRate),
            'total_amount' => max(0, $totalAmount),
        ]);
    }

    private function createFacilityRental($guestEntryId, array $facilityData)
    {
        $rate = Rate::findOrFail($facilityData['rate_id']);
        $facility = Facility::findOrFail($facilityData['facility_id']);
        
        $start = new \DateTime($facilityData['start_datetime']);
        $end = new \DateTime($facilityData['end_datetime']);
        
        $duration = $start->diff($end);
        $durationHours = $duration->h + ($duration->days * 24) + ($duration->i / 60);
        
        $includedHours = $rate->duration ?? 1;
        $baseAmount = $rate->base_price;
        
        $extensionHours = max(0, $durationHours - $includedHours);
        $extensionAmount = $extensionHours * ($rate->extension_fee ?? 0);
        
        $subtotal = $baseAmount + $extensionAmount;

        return GuestEntryFacility::create([
            'guest_entry_id' => $guestEntryId,
            'facility_id' => $facility->id,
            'rate_id' => $rate->id,
            'start_datetime' => $facilityData['start_datetime'],
            'end_datetime' => $facilityData['end_datetime'],
            'duration_hours' => round($durationHours, 2),
            'base_amount' => $baseAmount,
            'extension_hours' => round($extensionHours, 2),
            'extension_amount' => $extensionAmount,
            'subtotal' => $subtotal,
        ]);
    }

    private function processFacilityExtension(array $extension)
    {
        $facility = GuestEntryFacility::findOrFail($extension['id']);
        $additionalHours = $extension['additional_hours'];
        $extensionFee = $facility->rate->extension_fee ?? 0;
        $additionalAmount = $additionalHours * $extensionFee;

        $facility->update([
            'extension_hours' => $facility->extension_hours + $additionalHours,
            'extension_amount' => $facility->extension_amount + $additionalAmount,
            'subtotal' => $facility->base_amount + $facility->extension_amount + $additionalAmount,
        ]);
    }

    public function archived()
    {
        $entries = GuestEntry::onlyTrashed()->with([
            'details.rate',
            'details.autoDiscount',
            'details.manualDiscount',
            'facilities.facility.facilityType',
            'createdBy',
        ])->paginate(5);

        return $this->paginatedCollection($entries, GuestEntryResource::class);
    }

    public function restore($id)
    {
        $entry = GuestEntry::onlyTrashed()->findOrFail($id);
        $entry->restore();

        return response()->json([
            'message' => 'Guest entry restored successfully',
            'data' => new GuestEntryResource($entry),
        ]);
    }
}