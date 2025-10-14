<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacilityTypeResource;
use App\Models\FacilityType;
use Illuminate\Http\Request;

class FacilityTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = FacilityType::withCount('facilities');

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $perPage = $request->input('per_page', 5);
        $facilityTypes = $query->paginate($perPage);

        return $this->paginatedCollection($facilityTypes, FacilityTypeResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:facility_types,name',
            'description' => 'nullable|string',
        ]);

        $facilityType = FacilityType::create($validated);

        return response()->json([
            'message' => 'Facility type created successfully',
            'data' => new FacilityTypeResource($facilityType),
        ], 201);
    }

    public function show($id)
    {
        $facilityType = FacilityType::with('facilities')->findOrFail($id);

        return response()->json([
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }

    public function update(Request $request, $id)
    {
        $facilityType = FacilityType::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:facility_types,name,' . $id,
            'description' => 'nullable|string',
        ]);

        $facilityType->update($validated);

        return response()->json([
            'message' => 'Facility type updated successfully',
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }

    public function destroy($id)
    {
        $facilityType = FacilityType::findOrFail($id);
        
        // Check if has facilities
        if ($facilityType->facilities()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete facility type with existing facilities',
            ], 400);
        }

        $facilityType->delete();

        return response()->json([
            'message' => 'Facility type deleted successfully',
        ]);
    }

    public function archived()
    {
        $facilityTypes = FacilityType::onlyTrashed()->withCount('facilities')->paginate(5);

        return $this->paginatedCollection($facilityTypes, FacilityTypeResource::class);
    }

    public function restore($id)
    {
        $facilityType = FacilityType::onlyTrashed()->findOrFail($id);
        $facilityType->restore();

        return response()->json([
            'message' => 'Facility type restored successfully',
            'data' => new FacilityTypeResource($facilityType),
        ]);
    }
}