<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\Tenant;
use App\Models\TenantEmailMap;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Google\Client as GoogleClient;

class SaasAuthController extends Controller
{
    public function register(Request $request)
    {
        $registerWith = $request->input('register_with', 'email'); // 'email' or 'phone'

        $rules = [
            'register_with' => 'required|in:email,phone',
            'company_name' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
        ];

        if ($registerWith === 'email') {
            $rules['email'] = 'required|email|max:255';
        } else {
            $rules['phone'] = 'required|string|max:20';
        }

        $request->validate($rules, [
            'company_name.regex' => 'اسم الشركة يجب أن يكون كلمة واحدة بدون مسافات.',
            'phone.required' => 'رقم الهاتف مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
        ]);

        // Check uniqueness in master DB
        if ($registerWith === 'email') {
            if (TenantEmailMap::where('email', $request->email)->exists()) {
                return response()->json([
                    'message' => 'البريد الإلكتروني مسجل مسبقاً.',
                    'errors' => ['email' => ['البريد الإلكتروني مسجل مسبقاً.']],
                ], 422);
            }
        } else {
            if (TenantEmailMap::where('phone', $request->phone)->exists()) {
                return response()->json([
                    'message' => 'رقم الهاتف مسجل مسبقاً.',
                    'errors' => ['phone' => ['رقم الهاتف مسجل مسبقاً.']],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $limits = Tenant::planLimits();
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'database_name' => 'rb_tenant_temp',
                'plan' => 'free',
                'product_limit' => $limits['free']['products'],
                'user_limit' => $limits['free']['users'],
                'is_active' => true,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $tenant->database_name = 'rb_tenant_' . $tenant->id;
            $tenant->save();

            TenantEmailMap::create([
                'email' => $registerWith === 'email' ? $request->email : null,
                'phone' => $registerWith === 'phone' ? $request->phone : null,
                'tenant_id' => $tenant->id,
            ]);

            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan' => 'free',
                'amount' => 0,
                'status' => 'active',
                'starts_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'خطأ في إنشاء الحساب: ' . $e->getMessage()], 500);
        }

        try {
            TenantService::provision($tenant);
            TenantService::switchToTenant($tenant);

            $user = User::create([
                'name' => $request->name,
                'email' => $registerWith === 'email' ? $request->email : null,
                'phone' => $registerWith === 'phone' ? $request->phone : null,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'is_active' => true,
            ]);

            Warehouse::create([
                'name' => 'المستودع الرئيسي',
                'is_main' => true,
                'is_active' => true,
            ]);

            Artisan::call('db:seed', [
                '--class' => 'ClientCategorySeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            Artisan::call('db:seed', [
                '--class' => 'DefaultDataSeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            $token = $user->createToken('saas-token')->plainTextToken;
            TenantService::switchToMaster();

            // Send email OTP only for email registration
            $requiresVerification = false;
            if ($registerWith === 'email') {
                $requiresVerification = true;
                try {
                    $otp = Otp::generate($request->email, 'email_verification');
                    Mail::to($request->email)->send(new OtpMail(
                        $otp,
                        'email_verification',
                        $request->name
                    ));
                } catch (\Exception $e) {
                    // Don't fail registration if email sending fails
                }
            }

            return response()->json([
                'user' => $user,
                'token' => $token,
                'tenant_id' => $tenant->id,
                'requires_verification' => $requiresVerification,
                'otp_required' => $tenant->otp_required,
                'email_verified' => (bool) $user->email_verified_at,
                'register_with' => $registerWith,
            ], 201);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            $tenant->emailMaps()->delete();
            $tenant->subscriptions()->delete();
            $tenant->delete();
            return response()->json(['message' => 'خطأ في تجهيز قاعدة البيانات: ' . $e->getMessage()], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ], [
            'identifier.required' => 'البريد الإلكتروني أو رقم الهاتف مطلوب.',
        ]);

        $identifier = $request->identifier;
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);

        // Find tenant by email or phone
        $tenant = TenantService::findByIdentifier($identifier);

        if (!$tenant) {
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
            ], 401);
        }

        if (!$tenant->is_active) {
            return response()->json([
                'message' => 'الحساب غير مفعل.',
            ], 403);
        }

        TenantService::switchToTenant($tenant);

        $user = $isEmail
            ? User::where('email', $identifier)->first()
            : User::where('phone', $identifier)->first();

        if (!$user || !$user->password || !Hash::check($request->password, $user->password)) {
            TenantService::switchToMaster();
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
            ], 401);
        }

        if (!$user->is_active) {
            TenantService::switchToMaster();
            return response()->json([
                'message' => 'الحساب غير مفعل.',
            ], 403);
        }

        $token = $user->createToken('saas-token')->plainTextToken;

        TenantService::switchToMaster();

        return response()->json([
            'user' => $user,
            'token' => $token,
            'tenant_id' => $tenant->id,
            'email_verified' => (bool) $user->email_verified_at,
            'otp_required' => $tenant->otp_required,
        ]);
    }

    public function mobileLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            $user = User::where('email', $request->email)->first();

            if ($user && $user->password && \Hash::check($request->password, $user->password)) {
                if (!$user->is_active) {
                    TenantService::switchToMaster();
                    return response()->json(['message' => 'الحساب معطل.'], 403);
                }

                $token = $user->createToken('mobile-token')->plainTextToken;
                TenantService::switchToMaster();

                return response()->json([
                    'user'      => $user,
                    'token'     => $token,
                    'tenant_id' => $tenant->id,
                ]);
            }
        }

        TenantService::switchToMaster();

        return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 401);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Always return success for security (don't reveal if email exists)
        $emailMap = TenantEmailMap::where('email', $request->email)->first();

        if ($emailMap) {
            $tenant = Tenant::find($emailMap->tenant_id);

            if ($tenant && $tenant->is_active) {
                TenantService::switchToTenant($tenant);
                $user = User::where('email', $request->email)->first();
                TenantService::switchToMaster();

                $otp = Otp::generate($request->email, 'password_reset');

                Mail::to($request->email)->send(new OtpMail(
                    $otp,
                    'password_reset',
                    $user?->name
                ));
            }
        }

        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!Otp::verify($request->email, $request->otp, 'password_reset')) {
            return response()->json([
                'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
            ], 422);
        }

        $emailMap = TenantEmailMap::where('email', $request->email)->first();

        if (!$emailMap) {
            return response()->json([
                'message' => 'البريد الإلكتروني غير مسجل.',
            ], 404);
        }

        $tenant = Tenant::find($emailMap->tenant_id);

        if (!$tenant) {
            return response()->json([
                'message' => 'الحساب غير موجود.',
            ], 404);
        }

        TenantService::switchToTenant($tenant);
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            TenantService::switchToMaster();
            return response()->json([
                'message' => 'المستخدم غير موجود.',
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        TenantService::switchToMaster();

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح.',
        ]);
    }

    public function googleAuth(Request $request)
    {
        $request->validate([
            'credential' => 'required|string',
            // Optional fields for completing registration
            'company_name' => 'nullable|string|max:255|regex:/^\S+$/',
            'phone' => 'nullable|string|max:20',
        ]);

        // Verify Google ID token
        try {
            $client = new GoogleClient(['client_id' => config('services.google.client_id')]);
            $payload = $client->verifyIdToken($request->credential);

            if (!$payload) {
                return response()->json(['message' => 'رمز Google غير صالح.'], 401);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'خطأ في التحقق من حساب Google.'], 401);
        }

        $googleId = $payload['sub'];
        $email = $payload['email'];
        $name = $payload['name'] ?? '';

        // Check if user exists by email
        $emailMap = TenantEmailMap::where('email', $email)->first();

        if ($emailMap) {
            // ── Existing user: LOG IN ──
            $tenant = Tenant::find($emailMap->tenant_id);

            if (!$tenant || !$tenant->is_active) {
                return response()->json(['message' => 'الحساب غير مفعل.'], 403);
            }

            TenantService::switchToTenant($tenant);
            $user = User::where('email', $email)->first();

            if (!$user || !$user->is_active) {
                TenantService::switchToMaster();
                return response()->json(['message' => 'الحساب غير مفعل.'], 403);
            }

            // Link google_id if not already set
            if (!$user->google_id) {
                $user->update(['google_id' => $googleId]);
            }

            $token = $user->createToken('saas-token')->plainTextToken;
            TenantService::switchToMaster();

            return response()->json([
                'user' => $user,
                'token' => $token,
                'tenant_id' => $tenant->id,
                'email_verified' => (bool) $user->email_verified_at,
                'otp_required' => $tenant->otp_required,
            ]);
        }

        // ── New user: needs registration ──
        // If company_name not provided, return needs_registration
        if (!$request->company_name) {
            return response()->json([
                'status' => 'needs_registration',
                'email' => $email,
                'name' => $name,
                'google_id' => $googleId,
            ]);
        }

        // ── Complete Google registration ──
        DB::beginTransaction();
        try {
            $limits = Tenant::planLimits();
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'database_name' => 'rb_tenant_temp',
                'plan' => 'free',
                'product_limit' => $limits['free']['products'],
                'user_limit' => $limits['free']['users'],
                'is_active' => true,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $tenant->database_name = 'rb_tenant_' . $tenant->id;
            $tenant->save();

            TenantEmailMap::create([
                'email' => $email,
                'tenant_id' => $tenant->id,
            ]);

            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan' => 'free',
                'amount' => 0,
                'status' => 'active',
                'starts_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'خطأ في إنشاء الحساب: ' . $e->getMessage()], 500);
        }

        try {
            TenantService::provision($tenant);
            TenantService::switchToTenant($tenant);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $request->phone,
                'google_id' => $googleId,
                'password' => null,
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            Warehouse::create([
                'name' => 'المستودع الرئيسي',
                'is_main' => true,
                'is_active' => true,
            ]);

            Artisan::call('db:seed', [
                '--class' => 'ClientCategorySeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            Artisan::call('db:seed', [
                '--class' => 'DefaultDataSeeder',
                '--database' => 'tenant',
                '--force' => true,
            ]);

            $token = $user->createToken('saas-token')->plainTextToken;
            TenantService::switchToMaster();

            return response()->json([
                'user' => $user,
                'token' => $token,
                'tenant_id' => $tenant->id,
                'email_verified' => true,
                'otp_required' => false,
            ], 201);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            $tenant->emailMaps()->delete();
            $tenant->subscriptions()->delete();
            $tenant->delete();
            return response()->json(['message' => 'خطأ في تجهيز قاعدة البيانات: ' . $e->getMessage()], 500);
        }
    }

    public function sendVerificationOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $emailMap = TenantEmailMap::where('email', $request->email)->first();

        if (!$emailMap) {
            return response()->json(['message' => 'البريد الإلكتروني غير مسجل.'], 404);
        }

        $tenant = Tenant::find($emailMap->tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'الحساب غير موجود.'], 404);
        }

        TenantService::switchToTenant($tenant);
        $user = User::where('email', $request->email)->first();
        TenantService::switchToMaster();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'البريد الإلكتروني مفعّل مسبقاً.']);
        }

        $otp = Otp::generate($request->email, 'email_verification');

        Mail::to($request->email)->send(new OtpMail(
            $otp,
            'email_verification',
            $user->name
        ));

        return response()->json([
            'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني.',
        ]);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if (!Otp::verify($request->email, $request->otp, 'email_verification')) {
            return response()->json([
                'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
            ], 422);
        }

        $emailMap = TenantEmailMap::where('email', $request->email)->first();

        if (!$emailMap) {
            return response()->json(['message' => 'البريد الإلكتروني غير مسجل.'], 404);
        }

        $tenant = Tenant::find($emailMap->tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'الحساب غير موجود.'], 404);
        }

        TenantService::switchToTenant($tenant);
        $user = User::where('email', $request->email)->first();

        if ($user) {
            $user->update(['email_verified_at' => now()]);
        }

        TenantService::switchToMaster();

        return response()->json([
            'message' => 'تم تأكيد البريد الإلكتروني بنجاح.',
        ]);
    }
}
