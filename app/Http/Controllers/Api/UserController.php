<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        if ($request->roles) {
            $query->whereIn('role', explode(',', $request->roles));
        } elseif ($request->role) {
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

        $users = $query->with(['warehouse' => function ($q) {
            $q->select('id', 'name')->withCount('stock');
        }])->latest()->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    private const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr',
        'outlook.com', 'outlook.fr', 'live.com', 'live.fr', 'aol.com',
        'icloud.com', 'me.com', 'mail.com', 'protonmail.com', 'proton.me',
        'gmx.com', 'gmx.fr', 'yandex.com', 'yandex.ru', 'zoho.com',
    ];

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users', function ($attr, $value, $fail) {
                $domain = strtolower(substr(strrchr($value, '@'), 1));
                if (in_array($domain, self::FREE_EMAIL_PROVIDERS, true)) {
                    $fail('البريد الإلكتروني يجب أن يكون بريد عمل (لا يقبل Gmail / Yahoo / Hotmail …)');
                }
            }],
            'password' => 'required|min:6',
            'phone' => 'nullable|string',
            'role' => 'required|in:admin,manager,seller,livreur,cashvan',
            'is_active' => 'boolean',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'create_warehouse' => 'nullable|boolean',
        ]);

        // Check user limit
        $tenant = $request->attributes->get('tenant');
        if ($tenant && $tenant->user_limit) {
            $currentCount = User::count();
            if ($currentCount >= $tenant->user_limit) {
                $extraPrice = Tenant::extraUserPrice()[$tenant->plan] ?? 0;
                $limits = Tenant::planLimits();
                $prices = Tenant::planPrices();

                // Build upgrade options (plans with higher user limits)
                $upgradeOptions = [];
                foreach ($limits as $key => $limit) {
                    if ($limit['users'] > $tenant->user_limit) {
                        $upgradeOptions[] = [
                            'plan' => $key,
                            'user_limit' => $limit['users'],
                            'price' => $prices[$key] ?? 0,
                        ];
                    }
                }

                return response()->json([
                    'message' => 'لقد وصلت للحد الأقصى من المستخدمين (' . $tenant->user_limit . '). قم بترقية خطتك لإضافة المزيد.',
                    'limit' => $tenant->user_limit,
                    'current' => $currentCount,
                    'plan' => $tenant->plan,
                    'extra_user_price' => $extraPrice,
                    'upgrade_options' => $upgradeOptions,
                ], 403);
            }
        }

        // Validate one warehouse per user
        if ($request->warehouse_id) {
            $existingUser = User::where('warehouse_id', $request->warehouse_id)->first();
            if ($existingUser) {
                return response()->json([
                    'message' => 'هذا المستودع مخصص لمستخدم آخر: ' . $existingUser->name,
                ], 422);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'is_active' => $request->is_active ?? true,
            'warehouse_id' => $request->warehouse_id,
        ]);

        // Auto-create warehouse for livreur/cashvan if requested
        if ($request->create_warehouse && in_array($user->role, ['livreur', 'cashvan']) && !$user->warehouse_id) {
            $warehouse = Warehouse::create([
                'name' => 'مستودع ' . $user->name,
                'is_main' => false,
                'is_active' => true,
            ]);
            $user->warehouse_id = $warehouse->id;
            $user->saveQuietly();
        }

        return response()->json($user->load('warehouse:id,name'), 201);
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'string|max:255',
            'email' => ['email', 'unique:users,email,' . $user->id, function ($attr, $value, $fail) {
                $domain = strtolower(substr(strrchr($value, '@'), 1));
                if (in_array($domain, self::FREE_EMAIL_PROVIDERS, true)) {
                    $fail('البريد الإلكتروني يجب أن يكون بريد عمل (لا يقبل Gmail / Yahoo / Hotmail …)');
                }
            }],
            'phone' => 'nullable|string',
            'role' => 'in:admin,manager,seller,livreur,cashvan',
            'is_active' => 'boolean',
            'can_collect_debt' => 'boolean',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        // Validate one warehouse per user
        if ($request->has('warehouse_id') && $request->warehouse_id) {
            $existingUser = User::where('warehouse_id', $request->warehouse_id)
                ->where('id', '!=', $user->id)
                ->first();
            if ($existingUser) {
                return response()->json([
                    'message' => 'هذا المستودع مخصص لمستخدم آخر: ' . $existingUser->name,
                ], 422);
            }
        }

        $user->update($request->only(['name', 'email', 'phone', 'role', 'is_active', 'can_collect_debt', 'warehouse_id']));

        return response()->json($user->load('warehouse:id,name'));
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك'], 400);
        }

        // Prevent deletion of hidden super admin
        if ($user->email === self::HIDDEN_ADMIN_EMAIL) {
            return response()->json(['message' => 'لا يمكن حذف هذا المستخدم'], 400);
        }

        // Check caisse balance before deletion
        $caisse = $user->caisse;
        if ($caisse && $caisse->balance > 0) {
            if ($request->transfer_to_admin) {
                $adminCaisse = Caisse::where('type', 'principale')->where('user_id', '!=', $user->id)->first();

                if (!$adminCaisse) {
                    return response()->json(['message' => 'لا يوجد صندوق رئيسي لتحويل الرصيد إليه'], 422);
                }

                DB::beginTransaction();
                try {
                    $adminCaisse->addTransaction('in', $caisse->balance, 'transfer', null,
                        'تحويل تلقائي قبل حذف المستخدم: ' . $user->name, auth()->id());

                    $caisse->addTransaction('out', $caisse->balance, 'transfer', null,
                        'تفريغ الصندوق قبل حذف المستخدم', auth()->id());

                    $user->delete();
                    DB::commit();

                    return response()->json(['message' => 'تم تحويل الرصيد وحذف المستخدم بنجاح']);
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['message' => $e->getMessage()], 500);
                }
            }

            return response()->json([
                'message' => 'يجب تفريغ الصندوق أولاً - الرصيد الحالي: ' . number_format($caisse->balance, 2) . ' د.ج',
                'caisse_balance' => (float) $caisse->balance,
                'caisse_id' => $caisse->id,
                'can_transfer' => true,
            ], 422);
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

    public function toggleCollectDebt(User $user)
    {
        if (!in_array($user->role, ['seller', 'livreur', 'cashvan'])) {
            return response()->json(['message' => 'هذا الخيار متاح فقط للبائعين والسائقين'], 400);
        }

        $user->update(['can_collect_debt' => !$user->can_collect_debt]);

        return response()->json($user);
    }

    public function toggleSellFromMainStock(User $user)
    {
        if ($user->role !== 'cashvan') {
            return response()->json(['message' => 'هذا الخيار متاح فقط لسائقي الكاشفان'], 400);
        }

        $user->update(['sell_from_main_stock' => !$user->sell_from_main_stock]);

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
