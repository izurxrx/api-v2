<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
    public function archived(Request $request)
    {
        $query = User::onlyTrashed();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('contact_no', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->get('per_page', 5);
        $users = $query->paginate($perPage);

        return UserResource::collection($users)->additional([
            'status' => 'success',
            'message' => 'Archived users retrieved successfully',
        ]);
    }
    
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('contact_no', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->get('per_page', 5);
        $users = $query->paginate($perPage);

        return UserResource::collection($users)->additional([
            'status' => 'success',
            'message' => 'Users retrieved successfully',
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();
        $role = $validated['role'];

        if ($role === 'Admin' && User::role('Admin')->exists()) {
            return response()->json(['message' => 'Cannot create another Admin.'], 422);
        }

        if ($role === 'Manager' && auth()->user()?->hasRole('Manager')) {
            return response()->json(['message' => 'Managers cannot create Admins.'], 422);
        }

        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);
        $user->assignRole($role);

        return $this->successResponse(new UserResource($user), 'User created successfully', 201);
    }

    public function show(User $user)
    {
        return $this->successResponse(
            new UserResource($user),
            'User retrieved successfully'
        );
    }

    public function update(UpdateUserRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validated();
        $currentUser = auth()->user();

        if ($currentUser->isManager() && $user->isAdmin()) {
            return response()->json(['message' => 'Managers cannot edit Admins.'], 403);
        }

        if ($currentUser->isStaff() && ($user->isAdmin() || $user->isManager())) {
            return response()->json(['message' => 'Staff cannot edit Admins or Managers.'], 403);
        }

        $updateData = [
            'username' => $validated['username'],
            'full_name' => $validated['full_name'],
            'contact_no' => $validated['contact_no'] ?? null,
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        if (isset($validated['role']) && $currentUser->can('assign roles')) {
            $user->syncRoles([$validated['role']]);
        }

        return $this->successResponse(new UserResource($user->fresh()), 'User updated successfully');
    }

    public function destroy($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->delete();

        return $this->successResponse(
            new UserResource($user),
            'User deleted successfully'
        );
    }

    public function restore($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        return $this->successResponse(
            new UserResource($user),
            'User restored successfully'
        );
    }
}