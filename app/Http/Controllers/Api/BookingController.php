<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function archived()
    {
        $bookings = Booking::onlyTrashed()->with([
            'facility.facilityType',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'payments',
        ])->latest('deleted_at')->paginate(5);

        return $this->paginatedCollection($bookings, BookingResource::class);
    }

    public function restore($id)
    {
        $booking = Booking::onlyTrashed()->findOrFail($id);
        $booking->restore();

        return response()->json([
            'message' => 'Booking restored successfully',
            'data' => new BookingResource($booking->load(['facility.facilityType', 'createdBy', 'checkedInBy', 'checkedOutBy', 'payments'])),
        ]);
    }

    /**
     * Display a listing of bookings.
     */
    public function index(Request $request)
    {
        $query = Booking::with([
            'facility.facilityType',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'payments',
        ]);

        // --- Filtering ---
        if ($request->has('status')) {
            $query->where('booking_status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('date')) {
            $query->whereDate('check_in_date', $request->date);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('booking_reference', 'like', "%{$search}%");
            });
        }

        $perPage = $request->input('per_page', 5);
        $bookings = $query->latest()->paginate($perPage);

        return $this->paginatedCollection($bookings, BookingResource::class);
    }

    /**
     * Store a newly created booking.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'facility_id' => 'required|exists:facilities,id',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after_or_equal:check_in_date',
            'check_in_time' => 'nullable|string',
            'check_out_time' => 'nullable|string',
            'number_of_guests' => 'required|integer|min:1',
            'guest_breakdown' => 'nullable|array',
            'subtotal' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'third_party_service_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $reference = $this->generateReferenceNumber();

            $booking = Booking::create([
                'booking_reference' => $reference,
                'guest_name' => $validated['guest_name'],
                'contact_number' => $validated['contact_number'] ?? null,
                'facility_id' => $validated['facility_id'],
                'check_in_date' => $validated['check_in_date'],
                'check_out_date' => $validated['check_out_date'],
                'check_in_time' => $validated['check_in_time'] ?? null,
                'check_out_time' => $validated['check_out_time'] ?? null,
                'number_of_guests' => $validated['number_of_guests'],
                'guest_breakdown' => $validated['guest_breakdown'] ?? [],
                'subtotal' => $validated['subtotal'],
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'third_party_service_amount' => $validated['third_party_service_amount'] ?? 0,
                'total_amount' => $validated['total_amount'],
                'amount_paid' => $validated['amount_paid'] ?? 0,
                'balance' => ($validated['total_amount'] - ($validated['amount_paid'] ?? 0)),
                'booking_status' => 'Pending',
                'payment_status' => ($validated['amount_paid'] ?? 0) >= $validated['total_amount']
                    ? 'Paid'
                    : 'Unpaid',
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            $booking->load([
                'facility.facilityType',
                'createdBy',
            ]);

            return response()->json([
                'message' => 'Booking created successfully',
                'data' => new BookingResource($booking),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create booking',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified booking.
     */
    public function show($id)
    {
        $booking = Booking::with([
            'facility.facilityType',
            'createdBy',
            'checkedInBy',
            'checkedOutBy',
            'payments',
        ])->findOrFail($id);

        return response()->json([
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * Update booking information.
     */
    public function update(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'guest_name' => 'sometimes|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'check_in_date' => 'nullable|date',
            'check_out_date' => 'nullable|date|after_or_equal:check_in_date',
            'number_of_guests' => 'nullable|integer|min:1',
            'guest_breakdown' => 'nullable|array',
            'subtotal' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'third_party_service_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $booking->update($validated);

        // Recalculate balance & payment status
        if (isset($validated['total_amount']) || isset($validated['amount_paid'])) {
            $booking->update([
                'balance' => $booking->total_amount - $booking->amount_paid,
                'payment_status' => $booking->amount_paid >= $booking->total_amount
                    ? 'Paid'
                    : 'Unpaid',
            ]);
        }

        $booking->load(['facility.facilityType', 'createdBy']);

        return response()->json([
            'message' => 'Booking updated successfully',
            'data' => new BookingResource($booking),
        ]);
    }

    /**
     * Check-in a guest booking.
     */
    public function checkIn(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->booking_status === 'Checked-in') {
            return response()->json(['message' => 'Guest already checked in'], 400);
        }

        $booking->update([
            'booking_status' => 'Checked-in',
            'actual_check_in_datetime' => now(),
            'checked_in_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Guest checked in successfully',
            'data' => new BookingResource($booking->fresh(['checkedInBy'])),
        ]);
    }

    /**
     * Check-out a guest booking.
     */
    public function checkOut(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->booking_status === 'Checked-out') {
            return response()->json(['message' => 'Guest already checked out'], 400);
        }

        $booking->update([
            'booking_status' => 'Checked-out',
            'actual_check_out_datetime' => now(),
            'checked_out_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Guest checked out successfully',
            'data' => new BookingResource($booking->fresh(['checkedOutBy'])),
        ]);
    }

    /**
     * Remove the specified booking (soft delete).
     */
    public function destroy($id)
    {
        $booking = Booking::findOrFail($id);
        $booking->delete();

        return response()->json([
            'message' => 'Booking deleted successfully',
        ]);
    }

    // ============================================
    // Helper Method
    // ============================================

    private function generateReferenceNumber()
    {
        $date = now()->format('Ymd');
        $count = Booking::whereDate('created_at', today())->count() + 1;
        return 'BK' . $date . str_pad($count, 3, '0', STR_PAD_LEFT);
    }
}
