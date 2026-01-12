<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class SettingController extends Controller
{
    /**
     * Get all settings
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    /**
     * Get settings by group
     */
    public function getByGroup($group)
    {
        $settings = Setting::getGroup($group);
        return response()->json($settings);
    }

    /**
     * Update settings
     */
    public function update(Request $request)
    {
        $settings = $request->all();

        foreach ($settings as $key => $value) {
            if ($key !== 'logo') {
                Setting::set($key, $value, $this->getGroupForKey($key));
            }
        }

        return response()->json([
            'message' => 'Parametres mis a jour avec succes',
            'data' => Setting::all()->pluck('value', 'key'),
        ]);
    }

    /**
     * Upload company logo
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            $oldLogo = Setting::get('company_logo');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
            }

            // Store new logo
            $path = $request->file('logo')->store('settings', 'public');
            Setting::set('company_logo', $path, 'company');

            return response()->json([
                'message' => 'Logo mis a jour avec succes',
                'path' => $path,
            ]);
        }

        return response()->json(['message' => 'Aucun fichier fourni'], 400);
    }

    /**
     * Delete company logo
     */
    public function deleteLogo()
    {
        $logo = Setting::get('company_logo');
        if ($logo && Storage::disk('public')->exists($logo)) {
            Storage::disk('public')->delete($logo);
        }
        Setting::set('company_logo', null, 'company');

        return response()->json(['message' => 'Logo supprime avec succes']);
    }

    /**
     * Get company info for PDF
     */
    public function getCompanyInfo()
    {
        return response()->json([
            'company_name' => Setting::get('company_name', 'Rafik Biskra'),
            'company_address' => Setting::get('company_address', 'Biskra, Algerie'),
            'company_phone' => Setting::get('company_phone', '0555123456'),
            'company_email' => Setting::get('company_email', ''),
            'company_rc' => Setting::get('company_rc', ''),
            'company_nif' => Setting::get('company_nif', ''),
            'company_ai' => Setting::get('company_ai', ''),
            'company_nis' => Setting::get('company_nis', ''),
            'company_logo' => Setting::get('company_logo'),
        ]);
    }

    /**
     * Get public company branding (for login page)
     */
    public function getPublicBranding()
    {
        return response()->json([
            'company_name' => Setting::get('company_name', 'نظام إدارة المخزون'),
            'company_logo' => Setting::get('company_logo'),
        ]);
    }

    /**
     * Determine group for setting key
     */
    private function getGroupForKey($key)
    {
        if (str_starts_with($key, 'company_')) {
            return 'company';
        }
        if (str_starts_with($key, 'invoice_')) {
            return 'invoice';
        }
        if (str_starts_with($key, 'notification_')) {
            return 'notifications';
        }
        return 'general';
    }

    /**
     * Check if settings password is set
     */
    public function hasPassword()
    {
        $password = Setting::get('settings_password');
        return response()->json([
            'has_password' => !empty($password),
        ]);
    }

    /**
     * Verify settings password
     */
    public function verifyPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $storedPassword = Setting::get('settings_password');

        if (empty($storedPassword)) {
            return response()->json([
                'verified' => true,
                'message' => 'Pas de mot de passe configure',
            ]);
        }

        $verified = Hash::check($request->password, $storedPassword);

        if ($verified) {
            return response()->json([
                'verified' => true,
                'message' => 'Mot de passe correct',
            ]);
        }

        return response()->json([
            'verified' => false,
            'message' => 'Mot de passe incorrect',
        ], 401);
    }

    /**
     * Set or update settings password
     */
    public function setPassword(Request $request)
    {
        $request->validate([
            'current_password' => 'nullable|string',
            'new_password' => 'required|string|min:4',
        ]);

        $storedPassword = Setting::get('settings_password');

        // If password already exists, verify current password
        if (!empty($storedPassword)) {
            if (empty($request->current_password) || !Hash::check($request->current_password, $storedPassword)) {
                return response()->json([
                    'message' => 'Mot de passe actuel incorrect',
                ], 401);
            }
        }

        // Set new password
        Setting::set('settings_password', Hash::make($request->new_password), 'security');

        return response()->json([
            'message' => 'Mot de passe mis a jour avec succes',
        ]);
    }

    /**
     * Remove settings password
     */
    public function removePassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $storedPassword = Setting::get('settings_password');

        if (empty($storedPassword)) {
            return response()->json([
                'message' => 'Aucun mot de passe a supprimer',
            ]);
        }

        if (!Hash::check($request->password, $storedPassword)) {
            return response()->json([
                'message' => 'Mot de passe incorrect',
            ], 401);
        }

        Setting::where('key', 'settings_password')->delete();

        return response()->json([
            'message' => 'Mot de passe supprime avec succes',
        ]);
    }
}
