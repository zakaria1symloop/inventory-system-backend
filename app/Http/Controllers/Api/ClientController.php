<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::query();

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

        // Get delivery debt per client
        $deliveryDebts = DeliveryOrder::select(
                'client_id',
                DB::raw('SUM(amount_due - amount_collected) as delivery_debt')
            )
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
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
        ]);

        $client = Client::create($request->all());

        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        return response()->json($client->load(['orders', 'sales']));
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
}
