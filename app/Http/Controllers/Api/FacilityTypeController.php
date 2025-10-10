<?php

namespace App\Http\Controllers\Api;

use App\Models\FacilityType;
use App\Http\Resources\FacilityTypeResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class FacilityTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = FacilityType::query();

        // Include facilities count
        if ($request->boolean('with_facilities_count')) {
            $query->withCount('facilities');
        }

        // Include facilities
        if ($request->boolean('with_facilities')) {
            $query->with('facilities');
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }
        
        $facilityTypes = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            FacilityTypeResource::collection($facilityTypes),
            'Facility types retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:facility_types',
        ]);

        $facilityType = FacilityType::create($validated);

        return $this->successResponse(
            new FacilityTypeResource($facilityType),
            'Facility type created successfully',
            201
        );
    }

    public function show(FacilityType $facilityType)
    {
        $facilityType->load('facilities');
        return $this->successResponse(
            new FacilityTypeResource($facilityType),
            'Facility type retrieved successfully'
        );
    }
    
    public function archived(Request $request)
    {
        $query = FacilityType::onlyTrashed();

        // Include facilities count
        if ($request->boolean('with_facilities_count')) {
            $query->withCount(['facilities' => function ($q) {
                $q->withTrashed();
            }]);
        }

        // Include facilities
        if ($request->boolean('with_facilities')) {
            $query->with(['facilities' => function ($q) {
                $q->withTrashed();
            }]);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $facilityTypes = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->successResponse(
            FacilityTypeResource::collection($facilityTypes),
            'Archived facility types retrieved successfully'
        );
    }

    public function update(Request $request, FacilityType $facilityType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:facility_types,name,' . $facilityType->id
        ]);

        $facilityType->update($validated);

        return $this->successResponse(
            new FacilityTypeResource($facilityType->fresh()),
            'Facility type updated successfully'
        );
    }

    public function destroy(FacilityType $facilityType)
    {
        // Check if facility type has facilities
        if ($facilityType->facilities()->exists()) {
            return $this->errorResponse(
                'Cannot delete facility type. It has associated facilities.',
                422
            );
        }

        $facilityType->delete();

        return $this->successResponse(
            null,
            'Facility type deleted successfully'
        );
    }

    public function restore($id)
    {
        $facilityType = FacilityType::onlyTrashed()->findOrFail($id);

        $facilityType->restore();
        
        return $this->successResponse(
            new FacilityTypeResource($facilityType->fresh()),
            'Facility type restored successfully'
        );
    }
}