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

        $bookedToday = Booking::whereDate('check_in_date', $today)
            ->whereIn('booking_status', ['confirmed', 'checked_in'])
            ->count();
        return response()->json($bookedToday);
    }

    public function getUpcomingBookings(Request $request)
    {
        $upcomingBookings = Booking::where('check_in_date', '>', Carbon::today())
            ->whereIn('booking_status', ['confirmed', 'checked_in'])
            ->count();
        return response()->json($upcomingBookings);
    }

    public function totalGuestToday(Request $request)
    {
        $today = Carbon::today();

        $activeGuests = GuestEntry::where('is_checked_out', false)
            ->whereDate('entry_date', '<=', $today)
            ->sum('number_of_guests');
        return response()->json(['total_guests' => $activeGuests]);
    }
}
