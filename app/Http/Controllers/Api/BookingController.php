<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    // List active bookings
    public function index()
    {
        return response()->json(Booking::with('facility', 'createdBy')->get());
    }

    // Show single booking
    public function show(Booking $booking)
    {
        return response()->json($booking->load('facility', 'createdBy'));
    }

    // Create booking
    public function store(Request $request)
    {
        $data = $request->validate([
            'guest_name' => 'required|string|max:100',
            'facility_id' => 'required|exists:facilities,id',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after_or_equal:check_in_date',
            'number_of_guests' => 'required|integer|min:1',
            'created_by' => 'required|exists:users,id',
            // Add other fields if necessary
        ]);

        $booking = Booking::create($data);
        return response()->json($booking, 201);
    }

    // Update booking
    public function update(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'guest_name' => 'sometimes|string|max:100',
            'facility_id' => 'sometimes|exists:facilities,id',
            'check_in_date' => 'sometimes|date',
            'check_out_date' => ['sometimes','date','after_or_equal:check_in_date'],
            'number_of_guests' => 'sometimes|integer|min:1',
        ]);

        $booking->update($data);
        return response()->json($booking);
    }

    // Soft delete booking
    public function destroy(Booking $booking)
    {
        $booking->delete();
        return response()->json(['message' => 'Booking deleted']);
    }

    // List archived bookings
    public function archived()
    {
        return response()->json(Booking::onlyTrashed()->with('facility', 'createdBy')->get());
    }

    // Restore archived booking
    public function restore($id)
    {
        $booking = Booking::onlyTrashed()->findOrFail($id);
        $booking->restore();
        return response()->json($booking);
    }
}