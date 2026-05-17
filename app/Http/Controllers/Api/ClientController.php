<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\DeliveryOrder;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::with(['clientCategory', 'creator:id,name', 'warehouse:id,name', 'originalClient:id,name']);

        // Visibility: non-admin users see only clients from their warehouse
        $user = auth()->user();
        if (in_array($user->role, ['seller', 'livreur', 'cashvan'])) {
            $canSeeAll = Setting::get('seller_see_all_clients', 'false') === 'true';
            if (!$canSeeAll) {
                if ($user->warehouse_id) {
                    $query->where('warehouse_id', $user->warehouse_id);
                } else {
                    // No warehouse assigned - fall back to created_by
                    $query->where('created_by', $user->id);
                }
            }
        }

        if ($request->active_only) {
            $query->active();
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->has_balance) {
            $query->where('balance', '>', 0);
        }

        if ($request->source) {
            $query->where('source', $request->source);
        }

        if ($request->created_by) {
            $query->where('created_by', $request->created_by);
        }

        $clients = $query->latest()->paginate($request->per_page ?? 15);

        // Calculate combined debt for each client (sales + deliveries)
        $clientIds = collect($clients->items())->pluck('id');

        // Get sales debt per client (from actual unpaid sales)
        $salesDebts = \App\Models\Sale::select(
                'client_id',
                DB::raw('SUM(due_amount) as sales_debt')
            )
            ->whereIn('client_id', $clientIds)
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->groupBy('client_id')
            ->pluck('sales_debt', 'client_id');

        // Get delivery debt per client (exclude orders already linked to a sale to prevent double-count)
        $deliveryDebts = DeliveryOrder::select(
                'client_id',
                DB::raw('SUM(amount_due - amount_collected) as delivery_debt')
            )
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->whereNull('sale_id')
            ->groupBy('client_id')
            ->pluck('delivery_debt', 'client_id');

        // Add combined_debt to each client
        $clients->getCollection()->transform(function ($client) use ($salesDebts, $deliveryDebts) {
            $salesDebt = (float) ($salesDebts[$client->id] ?? 0);
            $deliveryDebt = (float) ($deliveryDebts[$client->id] ?? 0);
            $client->sales_debt = $salesDebt;
            $client->delivery_debt = $deliveryDebt;
            $client->combined_debt = $salesDebt + $deliveryDebt;
            return $client;
        });

        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'client_category_id' => 'nullable|exists:client_categories,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $data = $request->all();
        $data['created_by'] = auth()->id();

        // Auto-assign warehouse from creating user if not provided
        if (empty($data['warehouse_id']) && auth()->user()->warehouse_id) {
            $data['warehouse_id'] = auth()->user()->warehouse_id;
        }

        // Auto-assign default client category if none provided
        if (empty($data['client_category_id'])) {
            $defaultCategory = ClientCategory::where('is_default', true)->first();
            if ($defaultCategory) {
                $data['client_category_id'] = $defaultCategory->id;
            }
        }

        // Auto-detect source based on user role
        $role = auth()->user()->role;
        $data['source'] = in_array($role, ['seller', 'livreur']) ? 'app' : 'web';

        $client = Client::create($data);

        return response()->json($client->load('creator:id,name'), 201);
    }

    public function show(Client $client)
    {
        return response()->json($client->load(['orders', 'sales', 'clientCategory']));
    }

    public function update(Request $request, Client $client)
    {
        $request->validate([
            'name' => 'string|max:255',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'client_category_id' => 'nullable|exists:client_categories,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $client->update($request->all());

        return response()->json($client);
    }

    public function destroy(Client $client)
    {
        $client->delete();

        return response()->json(['message' => 'تم حذف العميل بنجاح']);
    }

    public function getBalance(Client $client)
    {
        return response()->json([
            'balance' => $client->balance,
            'credit_limit' => $client->credit_limit,
            'available_credit' => $client->availableCredit(),
        ]);
    }

    public function getOrders(Client $client, Request $request)
    {
        $orders = $client->orders()
            ->with(['items.product', 'seller'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function getSales(Client $client, Request $request)
    {
        $sales = $client->sales()
            ->with(['items.product', 'user'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($sales);
    }

    /**
     * Get unpaid sales (debt) for a client
     */
    public function getSalesDebt(Client $client)
    {
        $sales = \App\Models\Sale::with(['warehouse', 'user'])
            ->where('client_id', $client->id)
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'reference' => $sale->reference,
                    'warehouse_name' => $sale->warehouse->name ?? '',
                    'date' => $sale->date,
                    'status' => $sale->status,
                    'payment_status' => $sale->payment_status,
                    'grand_total' => (float) $sale->grand_total,
                    'paid_amount' => (float) $sale->paid_amount,
                    'due_amount' => (float) $sale->due_amount,
                    'days_old' => $sale->date ? \Carbon\Carbon::parse($sale->date)->diffInDays(now()) : null,
                ];
            });

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => $client->address,
                'balance' => (float) $client->balance,
            ],
            'sales' => $sales,
            'totals' => [
                'total_sales' => $sales->sum('grand_total'),
                'total_paid' => $sales->sum('paid_amount'),
                'total_remaining' => $sales->sum('due_amount'),
            ],
        ]);
    }

    /**
     * Transfer clients to a different warehouse
     */
    public function transferToWarehouse(Request $request)
    {
        $request->validate([
            'client_ids' => 'required|array|min:1',
            'client_ids.*' => 'exists:clients,id',
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $count = Client::whereIn('id', $request->client_ids)
            ->update(['warehouse_id' => $request->warehouse_id]);

        return response()->json([
            'message' => "تم نقل {$count} عميل بنجاح",
            'count' => $count,
        ]);
    }

    /**
     * Copy (duplicate) clients to another warehouse
     */
    public function copyToWarehouse(Request $request)
    {
        $request->validate([
            'client_ids' => 'required|array|min:1',
            'client_ids.*' => 'exists:clients,id',
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $clients = Client::whereIn('id', $request->client_ids)->get();
        $count = 0;

        foreach ($clients as $client) {
            $newClient = $client->replicate();
            $newClient->warehouse_id = $request->warehouse_id;
            $newClient->balance = 0;
            $newClient->code = null;
            $newClient->created_by = auth()->id();
            $newClient->copied_from = $client->id;
            $newClient->save();
            $count++;
        }

        return response()->json([
            'message' => "تم نسخ {$count} عميل بنجاح",
            'count' => $count,
        ]);
    }

    /**
     * Cancel copy - delete the copied client
     */
    public function cancelCopy(Client $client)
    {
        if (!$client->copied_from) {
            return response()->json(['message' => 'هذا العميل ليس نسخة'], 422);
        }

        $client->delete();

        return response()->json(['message' => 'تم إلغاء النسخة بنجاح']);
    }

    /**
     * Remove copy flag - make a copied client normal
     */
    public function removeCopyFlag(Client $client)
    {
        if (!$client->copied_from) {
            return response()->json(['message' => 'هذا العميل ليس نسخة'], 422);
        }

        $client->update(['copied_from' => null]);

        return response()->json(['message' => 'تم تحويل العميل إلى عميل عادي']);
    }

    /**
     * Build statement operations for a client
     */
    private function buildStatementData(Client $client, $dateFrom, $dateTo)
    {
        // Get ALL completed sales for this client
        $salesQuery = \App\Models\Sale::where('client_id', $client->id)
            ->where('status', 'completed');

        // Get all sale IDs for this client (for payment lookup)
        $allSaleIds = (clone $salesQuery)->pluck('id');

        // Opening balance: sales before date_from - payments before date_from
        $salesBeforeSum = (clone $salesQuery)->where('date', '<', $dateFrom)->sum('grand_total');
        $paymentsBeforeSum = \App\Models\Payment::where('payable_type', \App\Models\Sale::class)
            ->whereIn('payable_id', $allSaleIds)
            ->where('date', '<', $dateFrom)
            ->whereNull('deleted_at')
            ->sum('amount');
        $openingBalance = (float) $salesBeforeSum - (float) $paymentsBeforeSum;

        // Sales within date range
        $salesInRange = (clone $salesQuery)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Payments within date range (linked to client's sales)
        $paymentsInRange = \App\Models\Payment::where('payable_type', \App\Models\Sale::class)
            ->whereIn('payable_id', $allSaleIds)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->whereNull('deleted_at')
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Build operations list
        $operations = collect();

        foreach ($salesInRange as $sale) {
            $operations->push([
                'type' => 'facture',
                'code' => $sale->reference,
                'date' => $sale->date,
                'somme' => (float) $sale->grand_total,
                'versement' => 0,
            ]);
        }

        foreach ($paymentsInRange as $payment) {
            $operations->push([
                'type' => 'versement',
                'code' => $payment->reference ?? ('PAY-' . $payment->id),
                'date' => $payment->date,
                'somme' => 0,
                'versement' => (float) $payment->amount,
            ]);
        }

        // Sort by date, then by type (facture before versement on same date)
        $operations = $operations->sortBy([
            ['date', 'asc'],
            ['type', 'asc'], // 'facture' < 'versement' alphabetically, so asc puts facture first
        ])->values();

        // Calculate running balance
        $runningBalance = $openingBalance;
        $operations = $operations->map(function ($op) use (&$runningBalance) {
            $runningBalance += $op['somme'] - $op['versement'];
            $op['credit'] = round($runningBalance, 2);
            return $op;
        });

        $totalSomme = $operations->sum('somme');
        $totalVersement = $operations->sum('versement');

        return [
            'operations' => $operations->values()->all(),
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($runningBalance, 2),
            'total_somme' => round($totalSomme, 2),
            'total_versement' => round($totalVersement, 2),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * Get client statement data (JSON for preview)
     */
    public function getStatement(Request $request, Client $client)
    {
        $dateFrom = $request->input('date_from', now()->subYear()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        $data = $this->buildStatementData($client, $dateFrom, $dateTo);

        return response()->json($data);
    }

    /**
     * Generate client statement PDF
     */
    public function generateStatementPdf(Request $request, Client $client)
    {
        $client->load(['warehouse:id,name', 'clientCategory:id,name']);

        $dateFrom = $request->input('date_from', now()->subYear()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        $includeSections = [
            'include_info' => $request->input('include_info', 'true') === 'true',
            'include_legal' => $request->input('include_legal', 'true') === 'true',
        ];

        $statementData = $this->buildStatementData($client, $dateFrom, $dateTo);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.etat-client', [
            'client' => $client,
            'settings' => $settings,
            'includeSections' => $includeSections,
            'operations' => $statementData['operations'],
            'openingBalance' => $statementData['opening_balance'],
            'closingBalance' => $statementData['closing_balance'],
            'totalSomme' => $statementData['total_somme'],
            'totalVersement' => $statementData['total_versement'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
        $pdf->setPaper('a4');

        return $pdf->download("etat-client-{$client->id}.pdf");
    }

    private function getCompanySettings()
    {
        return [
            'company_name' => Setting::get('company_name', ''),
            'company_address' => Setting::get('company_address', ''),
            'company_phone' => Setting::get('company_phone', ''),
            'company_email' => Setting::get('company_email', ''),
            'company_rc' => Setting::get('company_rc', ''),
            'company_nif' => Setting::get('company_nif', ''),
            'company_ai' => Setting::get('company_ai', ''),
            'company_nis' => Setting::get('company_nis', ''),
            'company_rib' => Setting::get('company_rib', ''),
            'company_logo' => Setting::get('company_logo'),
        ];
    }
}
