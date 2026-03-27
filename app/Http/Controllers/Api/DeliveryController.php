<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\DeliveryReturn;
use App\Models\DeliveryStock;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VanReturn;
use App\Models\VanSession;
use App\Models\Warehouse;
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

            // Get livreur's warehouse (if assigned)
            $livreur = $delivery->livreur;
            $livreurWarehouseId = $livreur ? $livreur->warehouse_id : null;

            // Move stock: deduct from main warehouse, add to livreur's warehouse
            foreach ($delivery->stock as $deliveryStock) {
                // Deduct from main/source warehouse
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

                // Add to livreur's warehouse (if they have one)
                if ($livreurWarehouseId && $livreurWarehouseId != $delivery->warehouse_id) {
                    StockMovement::record(
                        $deliveryStock->product_id,
                        $livreurWarehouseId,
                        $deliveryStock->quantity_loaded,
                        StockMovement::TYPE_TRANSFER_IN,
                        $delivery->reference,
                        $delivery,
                        null,
                        'استلام بضاعة للتوصيل من ' . ($delivery->warehouse->name ?? '')
                    );
                }
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

        // If collecting more than amount_due (debt collection), check permission
        $amountCollected = $request->amount_collected ?? $deliveryOrder->amount_due;
        if ($amountCollected > $deliveryOrder->amount_due && !auth()->user()->can_collect_debt) {
            return response()->json(['message' => 'ليس لديك صلاحية تحصيل الديون. لا يمكنك تحصيل أكثر من مبلغ الفاتورة'], 403);
        }

        DB::beginTransaction();

        try {
            $deliveryOrder->markDelivered();

            // Update money collected
            $deliveryOrder->amount_collected = $amountCollected;
            $deliveryOrder->save();

            // Track delivery in delivery_stock and deduct from livreur's warehouse
            $livreur = $delivery->livreur;
            $livreurWarehouseId = $livreur ? $livreur->warehouse_id : null;

            foreach ($deliveryOrder->order->items as $item) {
                $deliveryStock = $delivery->stock()->where('product_id', $item->product_id)->first();
                if ($deliveryStock) {
                    $deliveryStock->recordDelivery($item->quantity_confirmed);
                }

                // Deduct delivered items from livreur's warehouse
                if ($livreurWarehouseId) {
                    StockMovement::record(
                        $item->product_id,
                        $livreurWarehouseId,
                        $item->quantity_confirmed,
                        StockMovement::TYPE_SALE,
                        $delivery->reference,
                        $delivery,
                        null,
                        'بيع عند التوصيل'
                    );
                }
            }

            // Update client balance based on payment
            if ($deliveryOrder->client_id) {
                $client = $deliveryOrder->client;
                if ($client) {
                    $difference = $deliveryOrder->amount_due - $amountCollected;

                    if ($difference > 0) {
                        // Underpayment - add to client's debt
                        $client->updateBalance($difference, 'add');
                    } elseif ($difference < 0) {
                        // Overpayment - subtract from client's old debt
                        $overpayment = abs($difference);
                        $client->updateBalance($overpayment, 'subtract');
                    }
                    // If difference == 0, no balance update needed (exact payment)
                }
            }

            $delivery->updateCounts();

            // Record caisse transaction for collected amount
            if ($amountCollected > 0) {
                $caisse = Caisse::where('user_id', $delivery->livreur_id)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $amountCollected,
                        'delivery',
                        $deliveryOrder->id,
                        "تحصيل توصيل طلب #{$deliveryOrder->order_id}",
                        auth()->id()
                    );
                }
            }

            // Auto-create sale record from delivered order
            $this->createSaleFromDelivery($delivery, $deliveryOrder, $amountCollected);

            DB::commit();

            // Calculate debt info
            $newDebt = max(0, $deliveryOrder->amount_due - $amountCollected);

            // Return delivery order with client (includes updated balance) and order
            return response()->json([
                'delivery_order' => $deliveryOrder->fresh()->load(['client', 'order']),
                'debt_info' => [
                    'amount_due' => $deliveryOrder->amount_due,
                    'amount_collected' => $amountCollected,
                    'new_debt' => $newDebt,
                    'client_balance' => $deliveryOrder->client ? $deliveryOrder->client->fresh()->balance : 0,
                ],
            ]);
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
            'items.*.quantity_delivered' => 'required|integer|min:0',
            'items.*.quantity_returned' => 'nullable|integer|min:0',
            'items.*.return_reason' => 'nullable|in:refused,damaged,excess,store_closed,wrong,other',
            'amount_collected' => 'nullable|numeric|min:0',
        ]);

        // If collecting more than what will be due (debt collection), check permission
        $requestedAmount = $request->input('amount_collected');
        if ($requestedAmount !== null && $requestedAmount !== '' && (float)$requestedAmount > $deliveryOrder->amount_due && !auth()->user()->can_collect_debt) {
            return response()->json(['message' => 'ليس لديك صلاحية تحصيل الديون. لا يمكنك تحصيل أكثر من مبلغ الفاتورة'], 403);
        }

        DB::beginTransaction();

        try {
            $deliveryOrder->markPartial();

            // Calculate the new amount_due based on delivered items
            $newAmountDue = 0;
            $order = $deliveryOrder->order;
            $livreur = $delivery->livreur;
            $livreurWarehouseId = $livreur ? $livreur->warehouse_id : null;

            foreach ($request->items as $item) {
                $orderItem = $order->items()->where('product_id', $item['product_id'])->first();
                if ($orderItem) {
                    $quantityDelivered = $item['quantity_delivered'];
                    $orderItem->quantity_delivered = $quantityDelivered;
                    $orderItem->save();

                    // Calculate amount for delivered items using proportional subtotal
                    // This matches the Flutter app calculation for consistency
                    if ($orderItem->quantity_confirmed > 0 && $quantityDelivered > 0) {
                        if ($quantityDelivered == $orderItem->quantity_confirmed) {
                            // Full quantity - use full subtotal
                            $itemTotal = $orderItem->subtotal;
                        } else {
                            // Partial quantity - calculate proportionally
                            $itemTotal = ($orderItem->subtotal / $orderItem->quantity_confirmed) * $quantityDelivered;
                        }
                        $newAmountDue += $itemTotal;
                    }

                    // Track delivery in delivery_stock
                    $deliveryStock = $delivery->stock()->where('product_id', $item['product_id'])->first();
                    if ($deliveryStock) {
                        $deliveryStock->recordDelivery($quantityDelivered);
                    }

                    // Deduct delivered items from livreur's warehouse
                    if ($livreurWarehouseId && $quantityDelivered > 0) {
                        StockMovement::record(
                            $item['product_id'],
                            $livreurWarehouseId,
                            $quantityDelivered,
                            StockMovement::TYPE_SALE,
                            $delivery->reference,
                            $delivery,
                            null,
                            'بيع عند التوصيل الجزئي'
                        );
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

                        // Returned items stay in livreur's warehouse
                        // They will be moved back to main warehouse when processReturns() is called
                    }
                }
            }

            // Update the amount_due to reflect only delivered items
            $deliveryOrder->amount_due = $newAmountDue;

            // Update money collected - handle null/missing values properly
            $amountCollected = $request->input('amount_collected');
            if ($amountCollected === null || $amountCollected === '') {
                // If no amount provided, default to full amount due (same as full delivery)
                $amountCollected = $newAmountDue;
            } else {
                $amountCollected = (float) $amountCollected;
            }
            $deliveryOrder->amount_collected = $amountCollected;

            $deliveryOrder->save();

            // Update client balance based on payment
            if ($deliveryOrder->client_id) {
                $client = $deliveryOrder->client;
                if ($client) {
                    $difference = $newAmountDue - $amountCollected;

                    if ($difference > 0) {
                        // Underpayment - add to client's debt
                        $client->updateBalance($difference, 'add');
                    } elseif ($difference < 0) {
                        // Overpayment - subtract from client's old debt
                        $overpayment = abs($difference);
                        $client->updateBalance($overpayment, 'subtract');
                    }
                    // If difference == 0, no balance update needed (exact payment)
                }
            }

            $delivery->updateCounts();

            // Record caisse transaction for collected amount
            if ($amountCollected > 0) {
                $caisse = Caisse::where('user_id', $delivery->livreur_id)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $amountCollected,
                        'delivery',
                        $deliveryOrder->id,
                        "تحصيل توصيل جزئي طلب #{$deliveryOrder->order_id}",
                        auth()->id()
                    );
                }
            }

            // Auto-create sale record from partial delivery
            $this->createSaleFromDelivery($delivery, $deliveryOrder, $amountCollected);

            DB::commit();

            // Return delivery order with client (includes updated balance) and order items
            return response()->json([
                'delivery_order' => $deliveryOrder->fresh()->load(['client', 'order.items']),
                'debt_info' => [
                    'amount_due' => $newAmountDue,
                    'amount_collected' => $amountCollected,
                    'new_debt' => max(0, $newAmountDue - $amountCollected),
                    'client_balance' => $deliveryOrder->client ? $deliveryOrder->client->fresh()->balance : 0,
                ],
            ]);
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

        $livreur = $delivery->livreur;
        $livreurWarehouseId = $livreur ? $livreur->warehouse_id : null;

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

            // Failed items stay in livreur's warehouse
            // They will be moved back to main warehouse when processReturns() is called
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

    /**
     * Create a sale record from a delivered/partial delivery order.
     * This records the delivery as a sale in the sales table (no stock deduction, already handled by delivery).
     */
    private function createSaleFromDelivery(Delivery $delivery, DeliveryOrder $deliveryOrder, float $amountCollected)
    {
        $order = $deliveryOrder->order;
        if (!$order) return;

        $sale = Sale::create([
            'client_id' => $deliveryOrder->client_id,
            'warehouse_id' => $delivery->livreur->warehouse_id ?? $order->warehouse_id,
            'user_id' => $delivery->livreur_id,
            'date' => now(),
            'discount' => $order->discount ?? 0,
            'tax' => $order->tax ?? 0,
            'shipping' => 0,
            'status' => 'completed',
            'source' => 'delivery',
            'note' => "توصيل طلب #{$order->reference}",
        ]);

        // Create sale items from order items (only delivered quantities)
        foreach ($order->items as $item) {
            $qty = $item->quantity_delivered ?? $item->quantity_confirmed;
            if ($qty <= 0) continue;

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $item->product_id,
                'quantity' => $qty,
                'unit_price' => $item->unit_price,
                'cost_price' => $item->product->cost_price ?? 0,
                'discount' => $item->discount ?? 0,
                'tax' => 0,
            ]);
        }

        $sale->calculateTotals();

        // Set paid amount from what was collected
        $sale->paid_amount = $amountCollected;
        $sale->due_amount = max(0, $sale->grand_total - $amountCollected);
        $sale->payment_status = $sale->calculatePaymentStatus();
        $sale->save();
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

        // Calculate real debt for each client (same as web does)
        if ($delivery) {
            $clientIds = $delivery->deliveryOrders->pluck('client_id')->unique()->filter();

            $salesDebts = Sale::select('client_id', DB::raw('SUM(due_amount) as total'))
                ->whereIn('client_id', $clientIds)
                ->where('status', 'completed')
                ->where('due_amount', '>', 0)
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            $deliveryDebts = DeliveryOrder::select('client_id', DB::raw('SUM(amount_due - amount_collected) as total'))
                ->whereIn('client_id', $clientIds)
                ->whereIn('status', ['delivered', 'partial'])
                ->whereRaw('amount_due > amount_collected')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            foreach ($delivery->deliveryOrders as $do) {
                if ($do->client) {
                    $salesDebt = (float) ($salesDebts[$do->client_id] ?? 0);
                    $deliveryDebt = (float) ($deliveryDebts[$do->client_id] ?? 0);
                    $do->client->balance = $salesDebt + $deliveryDebt;
                }
            }
        }

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
        // Check if user has permission to collect debt
        if (!auth()->user()->can_collect_debt) {
            return response()->json(['message' => 'ليس لديك صلاحية تحصيل الديون'], 403);
        }

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

            // Also update the corresponding Sale record
            $sale = Sale::where('source', 'delivery')
                ->where('note', 'like', "%#{$deliveryOrder->order_id}%")
                ->where('client_id', $deliveryOrder->client_id)
                ->first();

            if ($sale) {
                $sale->paid_amount += $amount;
                $sale->due_amount = max(0, $sale->grand_total - $sale->paid_amount);
                $sale->payment_status = $sale->calculatePaymentStatus();
                $sale->save();
            }

            // Record caisse transaction
            $caisse = Caisse::where('user_id', auth()->id())->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'in',
                    $amount,
                    'delivery',
                    $deliveryOrder->id,
                    "تحصيل دين توصيل #{$deliveryOrder->order_id}",
                    auth()->id()
                );
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
     * Livreur stock status - merchandise currently in trucks
     */
    public function livreurStock(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        // Active deliveries (preparing + in_progress)
        $activeDeliveries = Delivery::with([
            'livreur:id,name,phone,role',
            'vehicle:id,name,plate_number',
            'stock.product:id,name,barcode,retail_price,cost_price',
        ])
            ->whereIn('status', ['preparing', 'in_progress'])
            ->get();

        // Active van sessions (preparing + active)
        $activeVanSessions = \App\Models\VanSession::with([
            'livreur:id,name,phone,role',
            'vehicle:id,name,plate_number',
            'items.product:id,name,barcode,retail_price,cost_price',
        ])
            ->whereIn('status', ['preparing', 'active'])
            ->get();

        // Group by livreur
        $livreurMap = [];

        foreach ($activeDeliveries as $delivery) {
            $lid = $delivery->livreur_id;
            if (!isset($livreurMap[$lid])) {
                $livreurMap[$lid] = [
                    'user' => $delivery->livreur,
                    'deliveries' => [],
                    'van_sessions' => [],
                ];
            }

            $stockItems = $delivery->stock->map(function ($s) {
                $remaining = $s->quantity_loaded - $s->quantity_delivered - $s->quantity_returned;
                return [
                    'product_id' => $s->product_id,
                    'product' => $s->product,
                    'quantity_loaded' => (float) $s->quantity_loaded,
                    'quantity_delivered' => (float) $s->quantity_delivered,
                    'quantity_returned' => (float) $s->quantity_returned,
                    'remaining' => (float) $remaining,
                ];
            });

            $livreurMap[$lid]['deliveries'][] = [
                'id' => $delivery->id,
                'reference' => $delivery->reference,
                'status' => $delivery->status,
                'date' => $delivery->date,
                'vehicle' => $delivery->vehicle,
                'total_orders' => $delivery->total_orders,
                'delivered_count' => $delivery->delivered_count,
                'failed_count' => $delivery->failed_count,
                'total_amount' => (float) $delivery->total_amount,
                'collected_amount' => (float) $delivery->collected_amount,
                'stock' => $stockItems,
            ];
        }

        foreach ($activeVanSessions as $session) {
            $lid = $session->livreur_id;
            if (!isset($livreurMap[$lid])) {
                $livreurMap[$lid] = [
                    'user' => $session->livreur,
                    'deliveries' => [],
                    'van_sessions' => [],
                ];
            }

            $items = $session->items->map(function ($i) {
                $available = $i->quantity_loaded - $i->quantity_sold - $i->quantity_returned;
                return [
                    'product_id' => $i->product_id,
                    'product' => $i->product,
                    'quantity_loaded' => (float) $i->quantity_loaded,
                    'quantity_sold' => (float) $i->quantity_sold,
                    'quantity_returned' => (float) $i->quantity_returned,
                    'available' => (float) $available,
                ];
            });

            $livreurMap[$lid]['van_sessions'][] = [
                'id' => $session->id,
                'reference' => $session->reference,
                'status' => $session->status,
                'date' => $session->date,
                'vehicle' => $session->vehicle,
                'total_loaded_value' => (float) $session->total_loaded_value,
                'total_sales' => (float) $session->total_sales,
                'total_collected' => (float) $session->total_collected,
                'sales_count' => $session->sales_count,
                'items' => $items,
            ];
        }

        // Users with assigned warehouses (cashvan drivers via stock transfers)
        $warehouseUsers = \App\Models\User::whereNotNull('warehouse_id')
            ->where('is_active', true)
            ->whereIn('role', ['seller', 'livreur', 'cashvan'])
            ->with(['warehouse:id,name'])
            ->get();

        foreach ($warehouseUsers as $wUser) {
            $lid = $wUser->id;
            if (isset($livreurMap[$lid])) continue; // already listed via delivery/van session

            $stocks = \App\Models\Stock::with('product:id,name,barcode,retail_price,cost_price,pieces_per_package')
                ->where('warehouse_id', $wUser->warehouse_id)
                ->where('quantity', '>', 0)
                ->get();

            // Get today's sales for this user
            $todaySales = \App\Models\Sale::where('user_id', $wUser->id)
                ->where('warehouse_id', $wUser->warehouse_id)
                ->whereDate('date', now()->toDateString())
                ->get();

            // Get today's sold quantities per product
            $soldByProduct = \App\Models\SaleItem::whereIn('sale_id', $todaySales->pluck('id'))
                ->selectRaw('product_id, SUM(quantity) as total_sold')
                ->groupBy('product_id')
                ->pluck('total_sold', 'product_id');

            // Build items: current stock + sold today = original loaded
            // Collect all product_ids (from stock + sold)
            $allProductIds = $stocks->pluck('product_id')->merge($soldByProduct->keys())->unique();

            // Load products for sold-only items (not in current stock)
            $soldOnlyIds = $soldByProduct->keys()->diff($stocks->pluck('product_id'));
            $soldOnlyProducts = $soldOnlyIds->isNotEmpty()
                ? \App\Models\Product::whereIn('id', $soldOnlyIds)->get(['id', 'name', 'barcode', 'retail_price', 'cost_price', 'pieces_per_package'])->keyBy('id')
                : collect();

            $stockItems = collect();

            foreach ($stocks as $s) {
                $sold = (float) ($soldByProduct[$s->product_id] ?? 0);
                $available = (float) $s->quantity;
                $loaded = $available + $sold;
                $stockItems->push([
                    'product_id' => $s->product_id,
                    'product' => $s->product,
                    'quantity_loaded' => $loaded,
                    'quantity_sold' => $sold,
                    'quantity_returned' => 0,
                    'available' => $available,
                ]);
            }

            // Add products that were fully sold (no remaining stock)
            foreach ($soldOnlyIds as $pid) {
                $sold = (float) $soldByProduct[$pid];
                $product = $soldOnlyProducts[$pid] ?? null;
                if ($product) {
                    $stockItems->push([
                        'product_id' => $pid,
                        'product' => $product,
                        'quantity_loaded' => $sold,
                        'quantity_sold' => $sold,
                        'quantity_returned' => 0,
                        'available' => 0,
                    ]);
                }
            }

            if ($stockItems->isEmpty() && $todaySales->isEmpty()) continue;

            $livreurMap[$lid] = [
                'user' => $wUser->only(['id', 'name', 'phone', 'role']),
                'deliveries' => [],
                'van_sessions' => [],
                'warehouse_stock' => [
                    'warehouse' => $wUser->warehouse,
                    'items' => $stockItems->values(),
                    'sales_count' => $todaySales->count(),
                    'total_sales' => (float) $todaySales->sum('grand_total'),
                    'total_collected' => (float) $todaySales->sum('paid_amount'),
                ],
            ];
        }

        // Compute per-livreur totals
        $livreurs = collect($livreurMap)->values()->map(function ($entry) {
            $totalLoaded = 0;
            $totalRemaining = 0;

            foreach ($entry['deliveries'] as $d) {
                foreach ($d['stock'] as $s) {
                    $totalLoaded += $s['quantity_loaded'];
                    $totalRemaining += $s['remaining'];
                }
            }
            foreach ($entry['van_sessions'] as $v) {
                foreach ($v['items'] as $i) {
                    $totalLoaded += $i['quantity_loaded'];
                    $totalRemaining += $i['available'];
                }
            }
            if (isset($entry['warehouse_stock'])) {
                foreach ($entry['warehouse_stock']['items'] as $i) {
                    $totalLoaded += $i['quantity_loaded'];
                    $totalRemaining += $i['available'];
                }
            }

            $entry['totals'] = [
                'total_loaded' => $totalLoaded,
                'total_remaining' => $totalRemaining,
            ];

            return $entry;
        });

        return response()->json([
            'livreurs' => $livreurs,
            'summary' => [
                'total_active_livreurs' => $livreurs->count(),
                'total_active_deliveries' => $activeDeliveries->count(),
                'total_active_van_sessions' => $activeVanSessions->count(),
                'total_products_loaded' => $livreurs->sum('totals.total_loaded'),
                'total_products_remaining' => $livreurs->sum('totals.total_remaining'),
            ],
        ]);
    }

    /**
     * Get ALL debtors - combines sales debts and delivery debts
     */
    public function getAllDebtors(Request $request)
    {
        $debtors = collect();

        $warehouseId = $request->query('warehouse_id');
        $sellerId = $request->query('seller_id');

        // 1. Get clients with unpaid SALES (direct sales invoices)
        $salesQuery = \App\Models\Sale::select(
                'client_id',
                DB::raw('SUM(grand_total) as sales_total_due'),
                DB::raw('SUM(paid_amount) as sales_total_paid'),
                DB::raw('SUM(due_amount) as sales_total_remaining'),
                DB::raw('COUNT(*) as sales_count')
            )
            ->whereNotNull('client_id')
            ->where('status', 'completed')
            ->where('due_amount', '>', 0);

        if ($warehouseId) {
            $salesQuery->where('warehouse_id', $warehouseId);
        }
        if ($sellerId) {
            $salesQuery->where('user_id', $sellerId);
        }

        $salesDebtors = $salesQuery->groupBy('client_id')->get();

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
     * Return stock from a livreur back to a warehouse
     */
    public function returnStock(Request $request, $userId)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.source_type' => 'required|in:delivery,van_session,warehouse_stock',
            'items.*.source_id' => 'nullable|integer',
        ]);

        $targetUser = User::findOrFail($userId);
        $targetWarehouse = Warehouse::findOrFail($request->warehouse_id);

        // Helper to format qty (pieces) as cartons+pieces for human-readable errors
        $formatQty = function ($pieces, $piecesPerPackage) {
            $ppp = $piecesPerPackage ?: 1;
            if ($ppp <= 1) return "{$pieces}";
            $cartons = intdiv((int) $pieces, $ppp);
            $remainder = (int) $pieces % $ppp;
            if ($cartons > 0 && $remainder > 0) return "{$cartons} كرتون {$remainder} قطعة";
            if ($cartons > 0) return "{$cartons} كرتون";
            if ($remainder > 0) return "{$remainder} قطعة";
            return '0';
        };

        DB::beginTransaction();

        try {
            $processed = [];

            foreach ($request->items as $item) {
                $productId = $item['product_id'];
                $quantity = (int) $item['quantity'];
                $sourceType = $item['source_type'];
                $sourceId = $item['source_id'] ?? null;
                $product = \App\Models\Product::find($productId);
                $productName = $product->name ?? "منتج #{$productId}";
                $ppp = $product->pieces_per_package ?? 1;

                if ($sourceType === 'delivery') {
                    // Verify delivery belongs to user
                    $delivery = Delivery::where('id', $sourceId)
                        ->where('livreur_id', $userId)
                        ->whereIn('status', ['preparing', 'in_progress'])
                        ->firstOrFail();

                    $deliveryStock = DeliveryStock::where('delivery_id', $delivery->id)
                        ->where('product_id', $productId)
                        ->firstOrFail();

                    $remaining = $deliveryStock->quantity_loaded - $deliveryStock->quantity_delivered - $deliveryStock->quantity_returned;

                    if ($quantity > $remaining) {
                        throw new \Exception("الكمية المدخلة لـ {$productName} ({$formatQty($quantity, $ppp)}) أكبر من المتبقي ({$formatQty($remaining, $ppp)})");
                    }

                    // Create delivery return
                    DeliveryReturn::create([
                        'delivery_id' => $delivery->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'reason' => 'excess',
                        'returnable_to_stock' => true,
                        'unit_cost' => $product->cost_price ?? 0,
                        'loss_amount' => 0,
                        'processed' => true,
                        'processed_at' => now(),
                    ]);

                    $deliveryStock->recordReturn($quantity);

                    // Return to target warehouse
                    StockMovement::record(
                        $productId,
                        $targetWarehouse->id,
                        $quantity,
                        StockMovement::TYPE_DELIVERY_RETURN,
                        $delivery->reference,
                        $delivery,
                        null,
                        'إرجاع من السائق إلى المستودع'
                    );

                    $processed[] = ['product_id' => $productId, 'source' => 'delivery', 'quantity' => $quantity];

                } elseif ($sourceType === 'van_session') {
                    // Verify session belongs to user
                    $session = VanSession::where('id', $sourceId)
                        ->where('livreur_id', $userId)
                        ->whereIn('status', ['preparing', 'active'])
                        ->firstOrFail();

                    $sessionItem = $session->items()->where('product_id', $productId)->firstOrFail();
                    $available = $sessionItem->quantity_loaded - $sessionItem->quantity_sold - $sessionItem->quantity_returned;

                    if ($quantity > $available) {
                        throw new \Exception("الكمية المدخلة لـ {$productName} ({$formatQty($quantity, $ppp)}) أكبر من المتاح ({$formatQty($available, $ppp)})");
                    }

                    // Create van return
                    VanReturn::create([
                        'van_session_id' => $session->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'reason' => 'unsold',
                        'returnable_to_stock' => true,
                        'processed' => true,
                        'processed_at' => now(),
                    ]);

                    $sessionItem->quantity_returned += $quantity;
                    $sessionItem->save();

                    // Return to target warehouse
                    StockMovement::record(
                        $productId,
                        $targetWarehouse->id,
                        $quantity,
                        StockMovement::TYPE_VAN_RETURN,
                        $session->reference,
                        $session,
                        null,
                        'إرجاع من السائق إلى المستودع'
                    );

                    $processed[] = ['product_id' => $productId, 'source' => 'van_session', 'quantity' => $quantity];

                } elseif ($sourceType === 'warehouse_stock') {
                    // Verify user has a warehouse
                    if (!$targetUser->warehouse_id) {
                        throw new \Exception("المستخدم ليس لديه مستودع مخصص");
                    }

                    $stock = Stock::where('warehouse_id', $targetUser->warehouse_id)
                        ->where('product_id', $productId)
                        ->first();

                    $currentQty = $stock ? (int) $stock->quantity : 0;

                    if ($quantity > $currentQty) {
                        throw new \Exception("الكمية المدخلة لـ {$productName} ({$formatQty($quantity, $ppp)}) أكبر من المتوفر ({$formatQty($currentQty, $ppp)})");
                    }

                    // Deduct from user's warehouse
                    StockMovement::record(
                        $productId,
                        $targetUser->warehouse_id,
                        $quantity,
                        StockMovement::TYPE_TRANSFER,
                        'RETURN-' . $targetUser->id,
                        $targetUser,
                        null,
                        'تحويل إرجاع من السائق'
                    );

                    // Add to target warehouse
                    StockMovement::record(
                        $productId,
                        $targetWarehouse->id,
                        $quantity,
                        StockMovement::TYPE_TRANSFER_IN,
                        'RETURN-' . $targetUser->id,
                        $targetUser,
                        null,
                        'استلام إرجاع من السائق'
                    );

                    $processed[] = ['product_id' => $productId, 'source' => 'warehouse_stock', 'quantity' => $quantity];
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'تم إرجاع المنتجات بنجاح',
                'processed' => $processed,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
