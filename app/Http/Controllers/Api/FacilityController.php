<?php

namespace App\Http\Controllers\Api;

use App\Models\Facility;
use App\Http\Resources\FacilityResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class FacilityController extends Controller
{

    public function archived(Request $request)
    {
        $query = Facility::onlyTrashed()->with('facilityType');

        // Dynamic filters
        $filters = [
            'facility_type_id' => fn($q, $v) => $q->where('facility_type_id', $v),
            'available_only' => fn($q, $v) => $v ? $q->available() : null,
            'bookable_only' => fn($q, $v) => $v ? $q->forBooking() : null,
            'is_maintenance' => fn($q, $v) => $q->where('is_maintenance', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
        ];

        foreach ($filters as $param => $callback) {
            if ($request->filled($param)) {
                $callback($query, $request->input($param));
            }
        }

        // Search
        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Sorting
        $allowedSorts = ['name', 'quantity', 'expected_capacity', 'max_capacity', 'created_at'];
        $sortBy = in_array($request->get('sort_by'), $allowedSorts) ? $request->get('sort_by') : 'name';
        $sortDir = strtolower($request->get('sort_dir')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $facilities = $query->paginate($perPage);

        return $this->successResponse(
            FacilityResource::collection($facilities),
            'Archived facilities retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = Facility::with('facilityType');

        // Dynamic filters
        $filters = [
            'facility_type_id' => fn($q, $v) => $q->where('facility_type_id', $v),
            'available_only' => fn($q, $v) => $v ? $q->available() : null,
            'bookable_only' => fn($q, $v) => $v ? $q->forBooking() : null,
            'is_maintenance' => fn($q, $v) => $q->where('is_maintenance', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
        ];

        foreach ($filters as $param => $callback) {
            if ($request->filled($param)) {
                $callback($query, $request->input($param));
            }
        }

        // Search
        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Sorting
        $allowedSorts = ['name', 'quantity', 'expected_capacity', 'max_capacity', 'created_at'];
        $sortBy = in_array($request->get('sort_by'), $allowedSorts) ? $request->get('sort_by') : 'name';
        $sortDir = strtolower($request->get('sort_dir')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $facilities = $query->paginate($perPage);

        return $this->successResponse(
            FacilityResource::collection($facilities),
            'Facilities retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'facility_type_id' => 'required|exists:facility_types,id',
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'expected_capacity' => 'required|integer|min:1',
            'max_capacity' => 'required|integer|min:1|gte:expected_capacity',
            'description' => 'nullable|string|max:1000',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ]);

        $facility = Facility::create($validated);

        return $this->successResponse(
            new FacilityResource($facility->load('facilityType')),
            'Facility created successfully',
            201
        );
    }

    public function show(Facility $facility)
    {
        $facility->load(['facilityType', 'rates']);
        return $this->successResponse(
            new FacilityResource($facility),
            'Facility retrieved successfully'
        );
    }

    public function update(Request $request, Facility $facility)
    {
        $validated = $request->validate([
            'facility_type_id' => 'required|exists:facility_types,id',
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'expected_capacity' => 'required|integer|min:1',
            'max_capacity' => 'required|integer|min:1|gte:expected_capacity',
            'description' => 'nullable|string|max:1000',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ]);

        $facility->update($validated);

        return $this->successResponse(
            new FacilityResource($facility->fresh()->load('facilityType')),
            'Facility updated successfully'
        );
    }

    public function destroy(Facility $facility)
    {
        if ($facility->bookings()->exists() || $facility->rates()->exists()) {
            return $this->errorResponse(
                'Cannot delete facility. It has associated bookings or rates.',
                422
            );
        }

        $facility->delete();

        return $this->successResponse(null, 'Facility deleted successfully');
    }

    public function restore($id)
    {
        $facility = Facility::onlyTrashed()->findOrFail($id);
        
        $facility->restore();
        
        return $this->successResponse(
            new FacilityResource($facility->fresh()->load('facilityType')),
            'Facility restored successfully'
        );
    }

    public function toggleMaintenance(Facility $facility)
    {
        $facility->is_maintenance = !$facility->is_maintenance;
        $facility->save();
        $facility->refresh();

        $status = $facility->is_maintenance ? 'enabled' : 'disabled';
        return $this->successResponse(
            new FacilityResource($facility),
            "Maintenance mode {$status} for facility"
        );
    }

    public function toggleBookingAvailability(Facility $facility)
    {
        $facility->is_available_for_booking = !$facility->is_available_for_booking;
        $facility->save();
        $facility->refresh();

        $status = $facility->is_available_for_booking ? 'enabled' : 'disabled';
        return $this->successResponse(
            new FacilityResource($facility),
            "Booking availability {$status} for facility"
        );
    }
}
