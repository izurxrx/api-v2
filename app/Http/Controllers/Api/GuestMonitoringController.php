<?php

namespace App\Http\Controllers\Api;

use App\Models\GuestEntry;
use App\Models\GuestEntryDetail;
use App\Models\GuestEntryFacility;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestEntryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestMonitoringController extends Controller
{
    // GET /guest-monitoring
    public function index()
    {
        return GuestEntryResource::collection(GuestEntry::with(['details', 'facilities'])->get());
    }

    // GET /guest-monitoring/{id}
    public function show(GuestEntry $guestEntry)
    {
        return new GuestEntryResource($guestEntry->load(['details', 'facilities']));
    }

    // POST /guest-monitoring
    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_reference' => 'required|string|max:50|unique:guest_entries,entry_reference',
            'entry_date' => 'required|date',
            'entry_time' => 'required|date_format:H:i:s',
            'guest_name' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'total_guests' => 'required|integer|min:1',
            'subtotal' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'details' => 'nullable|array',
            'details.*.rate_id' => 'required|exists:rates,id',
            'details.*.guest_type_id' => 'required|exists:guest_types,id',
            'details.*.guest_count' => 'required|integer|min:1',
            'details.*.base_rate' => 'required|numeric|min:0',
            'details.*.final_rate' => 'required|numeric|min:0',
            'details.*.total_amount' => 'required|numeric|min:0',
            'facilities' => 'nullable|array',
            'facilities.*.facility_id' => 'required|exists:facilities,id',
            'facilities.*.rate_id' => 'nullable|exists:rates,id',
            'facilities.*.start_datetime' => 'required|date',
            'facilities.*.end_datetime' => 'required|date|after_or_equal:facilities.*.start_datetime',
            'facilities.*.duration_hours' => 'required|numeric|min:0',
            'facilities.*.base_amount' => 'required|numeric|min:0',
            'facilities.*.extension_hours' => 'nullable|numeric|min:0',
            'facilities.*.extension_amount' => 'nullable|numeric|min:0',
            'facilities.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::transaction(function() use ($data, &$guestEntry) {
            $guestEntry = GuestEntry::create($data);

            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    $guestEntry->details()->create($detail);
                }
            }

            if (!empty($data['facilities'])) {
                foreach ($data['facilities'] as $facility) {
                    $guestEntry->facilities()->create($facility);
                }
            }
        });

        return new GuestEntryResource($guestEntry->load(['details', 'facilities']));
    }

    // PUT /guest-monitoring/{id}
    public function update(Request $request, GuestEntry $guestEntry)
    {
        $data = $request->validate([
            'entry_reference' => 'sometimes|string|max:50|unique:guest_entries,entry_reference,' . $guestEntry->id,
            'entry_date' => 'sometimes|date',
            'entry_time' => 'sometimes|date_format:H:i:s',
            'guest_name' => 'sometimes|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'total_guests' => 'sometimes|integer|min:1',
            'subtotal' => 'sometimes|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string',
            'details' => 'nullable|array',
            'details.*.id' => 'sometimes|exists:guest_entry_details,id',
            'details.*.rate_id' => 'required_with:details|exists:rates,id',
            'details.*.guest_type_id' => 'required_with:details|exists:guest_types,id',
            'details.*.guest_count' => 'required_with:details|integer|min:1',
            'details.*.base_rate' => 'required_with:details|numeric|min:0',
            'details.*.final_rate' => 'required_with:details|numeric|min:0',
            'details.*.total_amount' => 'required_with:details|numeric|min:0',
            'facilities' => 'nullable|array',
            'facilities.*.id' => 'sometimes|exists:guest_entry_facilities,id',
            'facilities.*.facility_id' => 'required_with:facilities|exists:facilities,id',
            'facilities.*.rate_id' => 'nullable|exists:rates,id',
            'facilities.*.start_datetime' => 'required_with:facilities|date',
            'facilities.*.end_datetime' => 'required_with:facilities|date|after_or_equal:facilities.*.start_datetime',
            'facilities.*.duration_hours' => 'required_with:facilities|numeric|min:0',
            'facilities.*.base_amount' => 'required_with:facilities|numeric|min:0',
            'facilities.*.extension_hours' => 'nullable|numeric|min:0',
            'facilities.*.extension_amount' => 'nullable|numeric|min:0',
            'facilities.*.subtotal' => 'required_with:facilities|numeric|min:0',
        ]);

        DB::transaction(function() use ($guestEntry, $data) {
            $guestEntry->update($data);

            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    if (!empty($detail['id'])) {
                        $guestEntry->details()->find($detail['id'])->update($detail);
                    } else {
                        $guestEntry->details()->create($detail);
                    }
                }
            }

            if (!empty($data['facilities'])) {
                foreach ($data['facilities'] as $facility) {
                    if (!empty($facility['id'])) {
                        $guestEntry->facilities()->find($facility['id'])->update($facility);
                    } else {
                        $guestEntry->facilities()->create($facility);
                    }
                }
            }
        });

        return new GuestEntryResource($guestEntry->load(['details', 'facilities']));
    }

    // DELETE /guest-monitoring/{id}
    public function destroy(GuestEntry $guestEntry)
    {
        $guestEntry->delete();
        return response()->json(['message' => 'Guest entry deleted']);
    }

    // GET /guest-monitoring/archived
    public function archived()
    {
        return GuestEntryResource::collection(GuestEntry::onlyTrashed()->with(['details', 'facilities'])->get());
    }

    // POST /guest-monitoring/restore/{id}
    public function restore($id)
    {
        $guestEntry = GuestEntry::onlyTrashed()->findOrFail($id);
        $guestEntry->restore();
        return new GuestEntryResource($guestEntry->load(['details', 'facilities']));
    }
}
