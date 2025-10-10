<?php

namespace App\Http\Controllers\Api;

use App\Models\BookingService;
use App\Http\Resources\BookingServiceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class BookingServiceController extends Controller
{
    public function archived(Request $request)
    {
        $query = BookingService::onlyTrashed()->with('booking');

        // Filter by booking
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->booking_id);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('service_name', 'like', "%{$search}%")
                  ->orWhere('service_provider', 'like', "%{$search}%");
            });
        }

        $services = $query->orderBy('deleted_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            BookingServiceResource::collection($services),
            'Archived booking services retrieved successfully'
        );
    }

    public function index(Request $request)
    {
        $query = BookingService::with('booking');

        // Filter by booking
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->booking_id);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('service_name', 'like', "%{$search}%")
                  ->orWhere('service_provider', 'like', "%{$search}%");
            });
        }

        $services = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            BookingServiceResource::collection($services),
            'Booking services retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'service_name' => 'required|string|max:100',
            'service_provider' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        
        try {
            $service = BookingService::create($validated);

            // Update booking third party service amount
            $booking = $service->booking;
            $totalServiceAmount = $booking->services()->sum('amount');
            
            $booking->update([
                'third_party_service_amount' => $totalServiceAmount,
                'total_amount' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount,
                'balance' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount - $booking->amount_paid,
            ]);

            DB::commit();

            return $this->successResponse(
                new BookingServiceResource($service->load('booking')),
                'Booking service created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to create booking service: ' . $e->getMessage(), 500);
        }
    }

    public function show(BookingService $bookingService)
    {
        $bookingService->load('booking');
        return $this->successResponse(
            new BookingServiceResource($bookingService),
            'Booking service retrieved successfully'
        );
    }

    public function update(Request $request, BookingService $bookingService)
    {
        $validated = $request->validate([
            'service_name' => 'required|string|max:100',
            'service_provider' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        
        try {
            $bookingService->update($validated);

            // Update booking third party service amount
            $booking = $bookingService->booking;
            $totalServiceAmount = $booking->services()->sum('amount');
            
            $booking->update([
                'third_party_service_amount' => $totalServiceAmount,
                'total_amount' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount,
                'balance' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount - $booking->amount_paid,
            ]);

            DB::commit();

            return $this->successResponse(
                new BookingServiceResource($bookingService->fresh()->load('booking')),
                'Booking service updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to update booking service: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(BookingService $bookingService)
    {
        DB::beginTransaction();
        
        try {
            $booking = $bookingService->booking;
            $bookingService->delete();

            // Update booking third party service amount
            $totalServiceAmount = $booking->services()->sum('amount');
            
            $booking->update([
                'third_party_service_amount' => $totalServiceAmount,
                'total_amount' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount,
                'balance' => $booking->subtotal - $booking->discount_amount + $totalServiceAmount - $booking->amount_paid,
            ]);

            DB::commit();

            return $this->successResponse(
                null,
                'Booking service deleted successfully'
            );

        } catch (\Exception $e) {
            DB::rollback();
            return $this->errorResponse('Failed to delete booking service: ' . $e->getMessage(), 500);
        }
    }
}