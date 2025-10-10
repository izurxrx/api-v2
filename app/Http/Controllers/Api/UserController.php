<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
    public function archived(Request $request)
    {
        $query = User::onlyTrashed();

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('contact_no', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return $this->successResponse(
            UserResource::collection($users),
            'Archived users retrieved successfully'
        );
    }
    
    public function index(Request $request)
    {
        $query = User::query();

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('contact_no', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return $this->successResponse(
            UserResource::collection($users),
            'Users retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:users',
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role' => 'required|in:Admin,Staff,Manager',
        ]);

        // Role-based restrictions
        if ($validated['role'] === 'Admin' && User::where('role', 'Admin')->exists()) {
            return response()->json(['message' => 'Cannot create another Admin.'], 422);
        }

        if ($validated['role'] === 'Manager' && auth()->user()?->isManager()) {
            // Managers cannot create Admin
            return response()->json(['message' => 'Managers cannot create Admins.'], 422);
        }

        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);

        return $this->successResponse(new UserResource($user), 'User created successfully', 201);
    }


    public function show(User $user)
    {
        return $this->successResponse(
            new UserResource($user),
            'User retrieved successfully'
        );
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'username' => ['required','string','max:50', Rule::unique('users')->ignore($user->id)],
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'role' => 'required|in:Admin,Staff,Manager',
        ]);

        $currentUser = auth()->user();

        // Restrict Manager editing Admin
        if ($currentUser->isManager() && $user->isAdmin()) {
            return response()->json(['message' => 'Managers cannot edit Admins.'], 422);
        }

        // Restrict Staff editing Admin or Manager
        if ($currentUser->isStaff() && ($user->isAdmin() || $user->isManager())) {
            return response()->json(['message' => 'Staff cannot edit Admins or Managers.'], 422);
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return $this->successResponse(new UserResource($user->fresh()), 'User updated successfully');
    }


    public function destroy(User $user)
    {
        $currentUser = auth()->user();

        // Protect Admin
        if ($user->isAdmin() && $user->id !== $currentUser->id) {
            return response()->json(['message' => 'Cannot delete another Admin.'], 422);
        }

        // Manager cannot delete Admin
        if ($currentUser->isManager() && $user->isAdmin()) {
            return response()->json(['message' => 'Managers cannot delete Admins.'], 422);
        }

        // Staff cannot delete Admin or Manager
        if ($currentUser->isStaff() && ($user->isAdmin() || $user->isManager())) {
            return response()->json(['message' => 'Staff cannot delete Admins or Managers.'], 422);
        }

        $user->delete();

        return $this->successResponse(null, 'User deleted successfully');
    }

    public function getRoles()
    {
        return $this->successResponse(User::ROLES);
    }

    public function restore($id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return $this->successResponse(
            new UserResource($user),
            'User restored successfully'
        );
    }
}