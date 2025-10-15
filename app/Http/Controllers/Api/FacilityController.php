<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    public function index(Request $request)
    {
        $query = Facility::with('facilityType');

        if ($request->has('facility_type_id')) {
            $query->where('facility_type_id', $request->facility_type_id);
        }

        if ($request->has('available')) {
            $query->where('is_available_for_booking', true)
                  ->where('is_maintenance', false);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $perPage = $request->input('per_page', 5);

        if ($perPage == 'all' || (is_numeric($perPage) && (int)$perPage <= 0)) {
            $facilities = $query->get();
            return response()->json([
                'data' => FacilityResource::collection($facilities),
            ]);
        }
        
        $facilities = $query->paginate($perPage);

        return $this->paginatedCollection($facilities, FacilityResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'facility_type_id' => 'required|exists:facility_types,id',
            'name' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1',
            'expected_capacity' => 'required|integer|min:0',
            'max_capacity' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ]);

        $facility = Facility::create($validated);
        $facility->load('facilityType');

        return response()->json([
            'message' => 'Facility created successfully',
            'data' => new FacilityResource($facility),
        ], 201);
    }

    public function show($id)
    {
        $facility = Facility::with('facilityType', 'rates')->findOrFail($id);

        return response()->json([
            'data' => new FacilityResource($facility),
        ]);
    }

    public function update(Request $request, $id)
    {
        $facility = Facility::findOrFail($id);

        $validated = $request->validate([
            'facility_type_id' => 'sometimes|exists:facility_types,id',
            'name' => 'sometimes|string|max:100',
            'quantity' => 'sometimes|integer|min:1',
            'expected_capacity' => 'sometimes|integer|min:0',
            'max_capacity' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
            'is_maintenance' => 'boolean',
            'is_available_for_booking' => 'boolean',
        ]);

        $facility->update($validated);
        $facility->load('facilityType');

        return response()->json([
            'message' => 'Facility updated successfully',
            'data' => new FacilityResource($facility),
        ]);
    }

    public function destroy($id)
    {
        $facility = Facility::findOrFail($id);
        $facility->delete();

        return response()->json([
            'message' => 'Facility deleted successfully',
        ]);
    }

    public function archived()
    {
        $facilities = Facility::onlyTrashed()->with('facilityType')->paginate(5);

        return $this->paginatedCollection($facilities, FacilityResource::class);
    }

    public function restore($id)
    {
        $facility = Facility::onlyTrashed()->findOrFail($id);

        $facility->restore();
        return response()->json([
            'message' => 'Facility restored successfully',
            'data' => new FacilityResource($facility),
        ]);
    }
}