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

        //Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('contact_no', 'like', "%{$search}%");
            });
        }

        //Filter by role
        if ($request->filled('role')) {
            $query->role($request->role);
        }

        //Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = (int) $request->get('per_page', 5);
        $users = $query->paginate($perPage);

        return $this->paginatedCollection($users, UserResource::class);
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

        return $this->paginatedCollection($users, UserResource::class);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:users',
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:roles,name',
        ]);
        
        $role = $request->input('role');

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

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'username' => ['required','string','max:50', Rule::unique('users')->ignore($user->id)],
            'full_name' => 'required|string|max:100',
            'contact_no' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
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