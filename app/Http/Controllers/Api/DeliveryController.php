<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\DeliveryReturn;
use App\Models\DeliveryStock;
use App\Models\Order;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function index(Request $request)
    {
        $query = Delivery::with(['livreur', 'vehicle']);

        if ($request->livreur_id) {
            $query->where('livreur_id', $request->livreur_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        $deliveries = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($deliveries);
    }

    public function store(Request $request)
    {
        $request->validate([
            'livreur_id' => 'required|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'date' => 'required|date',
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Get warehouse from first order
            $firstOrder = Order::find($request->order_ids[0]);
            $warehouseId = $firstOrder->warehouse_id;

            $delivery = Delivery::create([
                'livreur_id' => $request->livreur_id,
                'vehicle_id' => $request->vehicle_id,
                'warehouse_id' => $warehouseId,
                'date' => $request->date,
                'notes' => $request->notes,
                'status' => 'preparing',
                'total_orders' => count($request->order_ids),
            ]);

            $productQuantities = [];
            $totalAmount = 0;

            foreach ($request->order_ids as $index => $orderId) {
                $order = Order::with('items')->find($orderId);

                if ($order->status !== 'confirmed') {
                    throw new \Exception('الطلب غير مؤكد: ' . $order->reference);
                }

                DeliveryOrder::create([
                    'delivery_id' => $delivery->id,
                    'order_id' => $orderId,
                    'client_id' => $order->client_id,
                    'delivery_order' => $index + 1,
                    'status' => 'pending',
                    'amount_due' => $order->grand_total,
                    'amount_collected' => 0,
                ]);

                $totalAmount += $order->grand_total;
                $order->assignToDelivery();

                foreach ($order->items as $item) {
                    $productId = $item->product_id;
                    if (!isset($productQuantities[$productId])) {
                        $productQuantities[$productId] = 0;
                    }
                    $productQuantities[$productId] += $item->quantity_confirmed;
                }
            }

            // Update total amount
            $delivery->total_amount = $totalAmount;
            $delivery->save();

            foreach ($productQuantities as $productId => $quantity) {
                DeliveryStock::create([
                    'delivery_id' => $delivery->id,
                    'product_id' => $productId,
                    'quantity_loaded' => $quantity,
                ]);
            }

            DB::commit();

            return response()->json($delivery->load(['livreur', 'vehicle', 'warehouse', 'deliveryOrders.order', 'stock.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Delivery $delivery)
    {
        return response()->json($delivery->load([
            'livreur',
            'vehicle',
            'deliveryOrders.order.items.product',
            'deliveryOrders.client',
            'stock.product',
            'returns.product'
        ]));
    }

    public function start(Delivery $delivery)
    {
        if ($delivery->status !== 'preparing') {
            return response()->json(['message' => 'التوصيل ليس في حالة تحضير'], 400);
        }

        DB::beginTransaction();

        try {
            // Validate stock availability for all products
            $stockErrors = [];
            foreach ($delivery->stock as $deliveryStock) {
                $stock = Stock::where('product_id', $deliveryStock->product_id)
                    ->where('warehouse_id', $delivery->warehouse_id)
                    ->first();

                $availableQty = $stock ? $stock->quantity : 0;
                $requiredQty = $deliveryStock->quantity_loaded;

                if ($availableQty < $requiredQty) {
                    $product = \App\Models\Product::find($deliveryStock->product_id);
                    $productName = $product ? $product->name : 'غير معروف';
                    $stockErrors[] = "{$productName}: المطلوب {$requiredQty}، المتوفر {$availableQty}";
                }
            }

            if (count($stockErrors) > 0) {
                return response()->json([
                    'message' => 'الكمية غير متوفرة في المخزون',
                    'errors' => $stockErrors
                ], 400);
            }

            // Deduct stock from warehouse (products go OUT with livreur)
            foreach ($delivery->stock as $deliveryStock) {
                StockMovement::record(
                    $deliveryStock->product_id,
                    $delivery->warehouse_id,
                    $deliveryStock->quantity_loaded,
                    StockMovement::TYPE_DELIVERY_OUT,
                    $delivery->reference,
                    $delivery,
                    null,
                    'خروج للتوصيل'
                );
            }

            $delivery->start();

            DB::commit();

            return response()->json($delivery->load(['stock.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function complete(Delivery $delivery)
    {
        if ($delivery->status !== 'in_progress') {
            return response()->json(['message' => 'التوصيل ليس قيد التنفيذ'], 400);
        }

        $delivery->complete();

        return response()->json($delivery->load(['deliveryOrders', 'returns']));
    }

    public function deliverOrder(Request $request, Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        // Allow delivery for pending or postponed orders
        if (!in_array($deliveryOrder->status, ['pending', 'postponed'])) {
            return response()->json(['message' => 'حالة الطلب غير صالحة'], 400);
        }

        $request->validate([
            'amount_collected' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $deliveryOrder->markDelivered();

            // Update money collected
            $amountCollected = $request->amount_collected ?? $deliveryOrder->amount_due;
            $deliveryOrder->amount_collected = $amountCollected;
            $deliveryOrder->save();

            // Track delivery in delivery_stock (stock already deducted when delivery started)
            foreach ($deliveryOrder->order->items as $item) {
                $deliveryStock = $delivery->stock()->where('product_id', $item->product_id)->first();
                if ($deliveryStock) {
                    $deliveryStock->recordDelivery($item->quantity_confirmed);
                }
            }

            // Update client balance - add debt if not fully paid
            $debtAmount = $deliveryOrder->amount_due - $amountCollected;
            if ($debtAmount > 0 && $deliveryOrder->client_id) {
                $client = $deliveryOrder->client;
                if ($client) {
                    $client->updateBalance($debtAmount, 'add');
                }
            }

            $delivery->updateCounts();

            DB::commit();

            return response()->json($deliveryOrder->load('order'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function partialDelivery(Request $request, Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_delivered' => 'required|numeric|min:0',
            'items.*.quantity_returned' => 'nullable|numeric|min:0',
            'items.*.return_reason' => 'nullable|in:refused,damaged,excess,store_closed,wrong,other',
            'amount_collected' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $deliveryOrder->markPartial();

            // Calculate the new amount_due based on delivered items
            $newAmountDue = 0;
            $order = $deliveryOrder->order;

            foreach ($request->items as $item) {
                $orderItem = $order->items()->where('product_id', $item['product_id'])->first();
                if ($orderItem) {
                    $quantityDelivered = $item['quantity_delivered'];
                    $orderItem->quantity_delivered = $quantityDelivered;
                    $orderItem->save();

                    // Calculate amount for delivered items only
                    // (unit_price * quantity_delivered) - proportional discount
                    $itemTotal = $orderItem->unit_price * $quantityDelivered;

                    // Apply proportional discount if any (discount is per total quantity, not per unit)
                    if ($orderItem->discount > 0 && $orderItem->quantity_confirmed > 0) {
                        $proportionalDiscount = ($orderItem->discount / $orderItem->quantity_confirmed) * $quantityDelivered;
                        $itemTotal -= $proportionalDiscount;
                    }

                    // Get tax from product if available
                    $product = $orderItem->product;
                    if ($product && $product->tax_percent > 0) {
                        $itemTotal += ($itemTotal * $product->tax_percent / 100);
                    }

                    $newAmountDue += $itemTotal;

                    // Track delivery in delivery_stock (stock already deducted when delivery started)
                    $deliveryStock = $delivery->stock()->where('product_id', $item['product_id'])->first();
                    if ($deliveryStock) {
                        $deliveryStock->recordDelivery($quantityDelivered);
                    }

                    if (isset($item['quantity_returned']) && $item['quantity_returned'] > 0) {
                        $returnReason = $item['return_reason'] ?? 'other';
                        $isReturnable = DeliveryReturn::isReturnableReason($returnReason);
                        $product = $orderItem->product;

                        DeliveryReturn::create([
                            'delivery_id' => $delivery->id,
                            'order_id' => $deliveryOrder->order_id,
                            'product_id' => $item['product_id'],
                            'quantity' => $item['quantity_returned'],
                            'reason' => $returnReason,
                            'returnable_to_stock' => $isReturnable,
                            'unit_cost' => $product->cost_price ?? 0,
                            'loss_amount' => !$isReturnable ? (($product->cost_price ?? 0) * $item['quantity_returned']) : 0,
                        ]);

                        if ($deliveryStock) {
                            $deliveryStock->recordReturn($item['quantity_returned']);
                        }
                    }
                }
            }

            // Update the amount_due to reflect only delivered items
            $deliveryOrder->amount_due = $newAmountDue;

            // Update money collected
            $amountCollected = 0;
            if ($request->has('amount_collected')) {
                $amountCollected = $request->amount_collected;
                $deliveryOrder->amount_collected = $amountCollected;
            }

            $deliveryOrder->save();

            // Update client balance - add debt if not fully paid
            $debtAmount = $newAmountDue - $amountCollected;
            if ($debtAmount > 0 && $deliveryOrder->client_id) {
                $client = $deliveryOrder->client;
                if ($client) {
                    $client->updateBalance($debtAmount, 'add');
                }
            }

            $delivery->updateCounts();

            DB::commit();

            return response()->json($deliveryOrder->load('order.items'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function failOrder(Request $request, Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $deliveryOrder->markFailed($request->reason);

        // Set amount_due to 0 since nothing was delivered
        $deliveryOrder->amount_due = 0;
        $deliveryOrder->amount_collected = 0;
        $deliveryOrder->save();

        $delivery->updateCounts();

        foreach ($deliveryOrder->order->items as $item) {
            $product = $item->product;

            DeliveryReturn::create([
                'delivery_id' => $delivery->id,
                'order_id' => $deliveryOrder->order_id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity_confirmed,
                'reason' => 'store_closed',
                'returnable_to_stock' => true, // Failed deliveries go back to stock
                'unit_cost' => $product->cost_price ?? 0,
                'loss_amount' => 0,
                'notes' => $request->reason,
            ]);

            $deliveryStock = $delivery->stock()->where('product_id', $item->product_id)->first();
            if ($deliveryStock) {
                $deliveryStock->recordReturn($item->quantity_confirmed);
            }
        }

        return response()->json($deliveryOrder);
    }

    public function postponeOrder(Request $request, Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $deliveryOrder->postpone($request->notes);
        $delivery->updateCounts();

        return response()->json($deliveryOrder);
    }

    public function processReturns(Request $request, Delivery $delivery)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $returns = $delivery->returns()->unprocessed()->get();

        foreach ($returns as $return) {
            $return->process($request->warehouse_id);
        }

        return response()->json(['message' => 'تمت معالجة المرتجعات بنجاح', 'count' => $returns->count()]);
    }

    public function processReturn(Request $request, Delivery $delivery, DeliveryReturn $return)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        if ($return->delivery_id !== $delivery->id) {
            return response()->json(['message' => 'المرتجع لا ينتمي لهذه التوصيلة'], 422);
        }

        if ($return->processed) {
            return response()->json(['message' => 'تمت معالجة هذا المرتجع مسبقاً'], 422);
        }

        $return->process($request->warehouse_id);

        return response()->json([
            'message' => 'تمت معالجة المرتجع بنجاح',
            'return' => $return->fresh()->load('product'),
        ]);
    }

    public function getMyActiveDelivery(Request $request)
    {
        $delivery = Delivery::where('livreur_id', auth()->id())
            ->whereIn('status', ['preparing', 'in_progress'])
            ->with(['deliveryOrders.order.items.product', 'deliveryOrders.client', 'stock.product'])
            ->first();

        return response()->json($delivery);
    }

    public function getMyDeliveries(Request $request)
    {
        $deliveries = Delivery::where('livreur_id', auth()->id())
            ->with(['deliveryOrders.client', 'deliveryOrders.order', 'returns'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($deliveries);
    }

    public function getDeliveryOrderItems(Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        // Ensure deliveryOrder belongs to this delivery
        if ($deliveryOrder->delivery_id !== $delivery->id) {
            return response()->json(['error' => 'Delivery order not found'], 404);
        }

        // Load the order with its items and product details (including unit info)
        $deliveryOrder->load(['order.items.product.unitSale', 'client']);

        // Check if order exists
        if (!$deliveryOrder->order) {
            \Log::error("DeliveryOrder {$deliveryOrder->id} has no order (order_id: {$deliveryOrder->order_id})");
            return response()->json([
                'delivery_order' => $deliveryOrder,
                'items' => [],
                'error' => 'Order not found'
            ]);
        }

        return response()->json([
            'delivery_order' => $deliveryOrder,
            'items' => $deliveryOrder->order->items->map(function ($item) {
                $product = $item->product;
                return [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'product_id' => $item->product_id,
                    'product_name' => $product->name ?? 'Produit supprimé',
                    'unit_short_name' => $product->unitSale->short_name ?? 'وحدة',
                    'pieces_per_package' => $product->pieces_per_package ?? 1,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_confirmed' => $item->quantity_confirmed ?? $item->quantity_ordered,
                    'quantity_delivered' => $item->quantity_delivered ?? 0,
                    'quantity_returned' => $item->quantity_returned ?? 0,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount ?? 0,
                    'subtotal' => $item->subtotal,
                    'notes' => $item->notes,
                ];
            }),
        ]);
    }

    /**
     * Collect payment for a delivery order
     */
    public function collectPayment(Request $request, Delivery $delivery, DeliveryOrder $deliveryOrder)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $amount = $request->amount;
        $remainingDue = $deliveryOrder->amount_due - $deliveryOrder->amount_collected;

        if ($amount > $remainingDue) {
            return response()->json([
                'message' => 'المبلغ أكبر من المتبقي'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update delivery order collected amount
            $deliveryOrder->amount_collected += $amount;
            $deliveryOrder->save();

            // Update delivery total collected
            $delivery->updateCounts();

            // Update client balance (subtract the payment from their debt)
            if ($deliveryOrder->client_id) {
                $client = $deliveryOrder->client;
                $client->updateBalance($amount, 'subtract');
            }

            DB::commit();

            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح',
                'delivery_order' => $deliveryOrder->fresh()->load('client'),
                'delivery' => $delivery->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء تسجيل الدفعة'], 500);
        }
    }

    /**
     * Get all clients with outstanding debt from deliveries
     */
    public function getDebtors(Request $request)
    {
        $query = DeliveryOrder::select(
                'client_id',
                DB::raw('SUM(amount_due) as total_due'),
                DB::raw('SUM(amount_collected) as total_collected'),
                DB::raw('SUM(amount_due - amount_collected) as total_remaining'),
                DB::raw('COUNT(*) as total_orders')
            )
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->groupBy('client_id')
            ->having('total_remaining', '>', 0);

        $debtors = $query->get()->map(function ($item) {
            $client = Client::find($item->client_id);
            return [
                'client_id' => $item->client_id,
                'client_name' => $client->name ?? 'عميل غير معروف',
                'client_phone' => $client->phone ?? '',
                'client_address' => $client->address ?? '',
                'total_due' => (float) $item->total_due,
                'total_collected' => (float) $item->total_collected,
                'total_remaining' => (float) $item->total_remaining,
                'total_orders' => (int) $item->total_orders,
                'client_balance' => $client ? (float) $client->balance : 0,
            ];
        });

        // Sort by remaining amount descending
        $debtors = $debtors->sortByDesc('total_remaining')->values();

        $totals = [
            'total_debtors' => $debtors->count(),
            'total_due' => $debtors->sum('total_due'),
            'total_collected' => $debtors->sum('total_collected'),
            'total_remaining' => $debtors->sum('total_remaining'),
        ];

        return response()->json([
            'data' => $debtors,
            'totals' => $totals,
        ]);
    }

    /**
     * Get unpaid delivery orders for a specific client
     */
    public function getClientDebt(Request $request, $clientId)
    {
        $orders = DeliveryOrder::with(['delivery.livreur', 'order'])
            ->where('client_id', $clientId)
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->orderByDesc('delivered_at')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'delivery_id' => $order->delivery_id,
                    'delivery_reference' => $order->delivery->reference ?? '',
                    'order_id' => $order->order_id,
                    'order_reference' => $order->order->reference ?? '',
                    'livreur_name' => $order->delivery->livreur->name ?? '',
                    'delivered_at' => $order->delivered_at,
                    'status' => $order->status,
                    'amount_due' => (float) $order->amount_due,
                    'amount_collected' => (float) $order->amount_collected,
                    'amount_remaining' => (float) ($order->amount_due - $order->amount_collected),
                ];
            });

        $client = Client::find($clientId);

        return response()->json([
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => $client->address,
                'balance' => (float) $client->balance,
            ] : null,
            'orders' => $orders,
            'totals' => [
                'total_due' => $orders->sum('amount_due'),
                'total_collected' => $orders->sum('amount_collected'),
                'total_remaining' => $orders->sum('amount_remaining'),
            ],
        ]);
    }

    /**
     * Get ALL debtors - combines sales debts and delivery debts
     */
    public function getAllDebtors(Request $request)
    {
        $debtors = collect();

        // 1. Get clients with unpaid SALES (direct sales invoices)
        $salesDebtors = \App\Models\Sale::select(
                'client_id',
                DB::raw('SUM(grand_total) as sales_total_due'),
                DB::raw('SUM(paid_amount) as sales_total_paid'),
                DB::raw('SUM(due_amount) as sales_total_remaining'),
                DB::raw('COUNT(*) as sales_count')
            )
            ->whereNotNull('client_id')
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->groupBy('client_id')
            ->get();

        // 2. Get clients with unpaid DELIVERIES
        $deliveryDebtors = DeliveryOrder::select(
                'client_id',
                DB::raw('SUM(amount_due) as delivery_total_due'),
                DB::raw('SUM(amount_collected) as delivery_total_collected'),
                DB::raw('SUM(amount_due - amount_collected) as delivery_total_remaining'),
                DB::raw('COUNT(*) as delivery_count')
            )
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->groupBy('client_id')
            ->having('delivery_total_remaining', '>', 0)
            ->get();

        // Combine both sources
        $clientIds = $salesDebtors->pluck('client_id')
            ->merge($deliveryDebtors->pluck('client_id'))
            ->unique();

        foreach ($clientIds as $clientId) {
            $client = Client::find($clientId);
            if (!$client) continue;

            $salesData = $salesDebtors->firstWhere('client_id', $clientId);
            $deliveryData = $deliveryDebtors->firstWhere('client_id', $clientId);

            $salesRemaining = $salesData ? (float) $salesData->sales_total_remaining : 0;
            $deliveryRemaining = $deliveryData ? (float) $deliveryData->delivery_total_remaining : 0;

            $debtors->push([
                'client_id' => $clientId,
                'client_name' => $client->name ?? 'عميل غير معروف',
                'client_phone' => $client->phone ?? '',
                'client_address' => $client->address ?? '',
                // Sales debt info
                'sales_total_due' => $salesData ? (float) $salesData->sales_total_due : 0,
                'sales_total_paid' => $salesData ? (float) $salesData->sales_total_paid : 0,
                'sales_total_remaining' => $salesRemaining,
                'sales_count' => $salesData ? (int) $salesData->sales_count : 0,
                // Delivery debt info
                'delivery_total_due' => $deliveryData ? (float) $deliveryData->delivery_total_due : 0,
                'delivery_total_collected' => $deliveryData ? (float) $deliveryData->delivery_total_collected : 0,
                'delivery_total_remaining' => $deliveryRemaining,
                'delivery_count' => $deliveryData ? (int) $deliveryData->delivery_count : 0,
                // Combined totals
                'total_remaining' => $salesRemaining + $deliveryRemaining,
                'total_orders' => ($salesData ? (int) $salesData->sales_count : 0) + ($deliveryData ? (int) $deliveryData->delivery_count : 0),
                'client_balance' => (float) $client->balance,
                // Flags for debt type
                'has_sales_debt' => $salesRemaining > 0,
                'has_delivery_debt' => $deliveryRemaining > 0,
            ]);
        }

        // Sort by total remaining amount descending
        $debtors = $debtors->sortByDesc('total_remaining')->values();

        $totals = [
            'total_debtors' => $debtors->count(),
            'sales_total_remaining' => $debtors->sum('sales_total_remaining'),
            'delivery_total_remaining' => $debtors->sum('delivery_total_remaining'),
            'total_remaining' => $debtors->sum('total_remaining'),
        ];

        return response()->json([
            'data' => $debtors,
            'totals' => $totals,
        ]);
    }

    /**
     * Get ALL debt details for a specific client (sales + deliveries)
     */
    public function getAllClientDebt(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        // Get unpaid sales
        $sales = \App\Models\Sale::with(['warehouse', 'user'])
            ->where('client_id', $clientId)
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'type' => 'sale',
                    'reference' => $sale->reference,
                    'warehouse_name' => $sale->warehouse->name ?? '',
                    'date' => $sale->date,
                    'amount_due' => (float) $sale->grand_total,
                    'amount_paid' => (float) $sale->paid_amount,
                    'amount_remaining' => (float) $sale->due_amount,
                    'days_old' => $sale->date ? \Carbon\Carbon::parse($sale->date)->diffInDays(now()) : null,
                ];
            });

        // Get unpaid deliveries
        $deliveries = DeliveryOrder::with(['delivery.livreur', 'order'])
            ->where('client_id', $clientId)
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->orderByDesc('delivered_at')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'type' => 'delivery',
                    'delivery_id' => $order->delivery_id,
                    'delivery_reference' => $order->delivery->reference ?? '',
                    'order_id' => $order->order_id,
                    'reference' => $order->order->reference ?? '',
                    'livreur_name' => $order->delivery->livreur->name ?? '',
                    'date' => $order->delivered_at,
                    'amount_due' => (float) $order->amount_due,
                    'amount_paid' => (float) $order->amount_collected,
                    'amount_remaining' => (float) ($order->amount_due - $order->amount_collected),
                    'days_old' => $order->delivered_at ? \Carbon\Carbon::parse($order->delivered_at)->diffInDays(now()) : null,
                ];
            });

        return response()->json([
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => $client->address,
                'balance' => (float) $client->balance,
            ] : null,
            'sales' => $sales,
            'deliveries' => $deliveries,
            'totals' => [
                'sales_total_due' => $sales->sum('amount_due'),
                'sales_total_paid' => $sales->sum('amount_paid'),
                'sales_total_remaining' => $sales->sum('amount_remaining'),
                'delivery_total_due' => $deliveries->sum('amount_due'),
                'delivery_total_paid' => $deliveries->sum('amount_paid'),
                'delivery_total_remaining' => $deliveries->sum('amount_remaining'),
                'total_remaining' => $sales->sum('amount_remaining') + $deliveries->sum('amount_remaining'),
            ],
        ]);
    }

}
