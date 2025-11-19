<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\GuestEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function getBookedToday(Request $request)
    {
        $today = Carbon::today();

        $bookedToday = Booking::whereDate('check_in_datetime', $today)
            ->whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
        
        return response()->json([
            'booked_today' => $bookedToday
        ]);
    }

    public function getUpcomingBookings(Request $request)
    {
        $upcomingBookings = Booking::whereDate('check_in_datetime', '>', Carbon::today())
            ->whereIn('booking_status', ['Confirmed', 'Checked_In'])
            ->count();
        
        return response()->json([
            'upcoming_bookings' => $upcomingBookings
        ]);
    }

    public function totalGuestToday(Request $request)
    {
        $today = Carbon::today();

        // Count guests currently in the resort (checked in but not checked out)
        $activeGuests = GuestEntry::where('is_checked_out', false)
            ->whereDate('entry_date', '<=', $today)
            ->where(function($query) use ($today) {
                $query->whereNull('exit_date')
                      ->orWhereDate('exit_date', '>=', $today);
            })
            ->sum('total_guests');
        return response()->json(['total_guests' => $activeGuests]);
    }
}
