<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaasAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminSettingsController extends Controller
{
    public function listAdmins()
    {
        $admins = SaasAdmin::select('id', 'name', 'email', 'is_active', 'created_at')
            ->latest()
            ->get();

        return response()->json($admins);
    }

    public function createAdmin(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:saas_admins,email',
            'password' => 'required|string|min:6',
        ]);

        $admin = SaasAdmin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'تم إنشاء حساب المشرف بنجاح.',
            'admin' => $admin,
        ], 201);
    }

    public function toggleAdminActive(Request $request, $id)
    {
        $admin = SaasAdmin::findOrFail($id);

        // Prevent self-deactivation
        if ($admin->id === $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الخاص.',
            ], 403);
        }

        $admin->update(['is_active' => !$admin->is_active]);

        return response()->json([
            'message' => $admin->is_active ? 'تم تفعيل المشرف.' : 'تم تعطيل المشرف.',
            'admin' => $admin,
        ]);
    }

    public function deleteAdmin(Request $request, $id)
    {
        $admin = SaasAdmin::findOrFail($id);

        // Prevent self-deletion
        if ($admin->id === $request->user()->id) {
            return response()->json([
                'message' => 'لا يمكنك حذف حسابك الخاص.',
            ], 403);
        }

        $admin->delete();

        return response()->json([
            'message' => 'تم حذف حساب المشرف.',
        ]);
    }
}
