<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\VanReturn;
use App\Models\VanSale;
use App\Models\VanSaleItem;
use App\Models\Caisse;
use App\Models\VanSession;
use App\Models\VanSessionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VanSessionController extends Controller
{
    public function index(Request $request)
    {
        $query = VanSession::with(['livreur', 'vehicle', 'warehouse']);

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

        $sessions = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($sessions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'livreur_id' => 'required|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $session = VanSession::create([
                'livreur_id' => $request->livreur_id,
                'vehicle_id' => $request->vehicle_id,
                'warehouse_id' => $request->warehouse_id,
                'date' => $request->date,
                'notes' => $request->notes,
                'status' => 'preparing',
            ]);

            $totalValue = 0;

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                VanSessionItem::create([
                    'van_session_id' => $session->id,
                    'product_id' => $item['product_id'],
                    'quantity_loaded' => $item['quantity'],
                    'unit_cost' => $product->cost_price ?? 0,
                ]);

                $totalValue += $item['quantity'] * ($product->retail_price ?? 0);
            }

            $session->total_loaded_value = $totalValue;
            $session->save();

            DB::commit();

            return response()->json($session->load(['livreur', 'vehicle', 'warehouse', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(VanSession $vanSession)
    {
        return response()->json($vanSession->load([
            'livreur',
            'vehicle',
            'warehouse',
            'items.product',
            'sales.client',
            'sales.items.product',
            'returns.product'
        ]));
    }

    public function update(Request $request, VanSession $vanSession)
    {
        if ($vanSession->status !== 'preparing') {
            return response()->json(['message' => 'لا يمكن تعديل الجلسة بعد البدء'], 400);
        }

        $request->validate([
            'livreur_id' => 'sometimes|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'date' => 'sometimes|date',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $vanSession->update($request->only(['livreur_id', 'vehicle_id', 'date', 'notes']));

            if ($request->has('items')) {
                // Delete existing items and recreate
                $vanSession->items()->delete();

                $totalValue = 0;
                foreach ($request->items as $item) {
                    $product = Product::find($item['product_id']);

                    VanSessionItem::create([
                        'van_session_id' => $vanSession->id,
                        'product_id' => $item['product_id'],
                        'quantity_loaded' => $item['quantity'],
                        'unit_cost' => $product->cost_price ?? 0,
                    ]);

                    $totalValue += $item['quantity'] * ($product->retail_price ?? 0);
                }

                $vanSession->total_loaded_value = $totalValue;
                $vanSession->save();
            }

            DB::commit();

            return response()->json($vanSession->load(['livreur', 'vehicle', 'warehouse', 'items.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function start(VanSession $vanSession)
    {
        if ($vanSession->status !== 'preparing') {
            return response()->json(['message' => 'الجلسة ليست في حالة تحضير'], 400);
        }

        DB::beginTransaction();

        try {
            // Validate stock availability
            $stockErrors = [];
            foreach ($vanSession->items as $sessionItem) {
                $stock = Stock::where('product_id', $sessionItem->product_id)
                    ->where('warehouse_id', $vanSession->warehouse_id)
                    ->first();

                $availableQty = $stock ? $stock->quantity : 0;
                $requiredQty = $sessionItem->quantity_loaded;

                if ($availableQty < $requiredQty) {
                    $product = Product::find($sessionItem->product_id);
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

            // Deduct stock from warehouse
            foreach ($vanSession->items as $sessionItem) {
                StockMovement::record(
                    $sessionItem->product_id,
                    $vanSession->warehouse_id,
                    $sessionItem->quantity_loaded,
                    StockMovement::TYPE_VAN_OUT,
                    $vanSession->reference,
                    $vanSession,
                    null,
                    'خروج للبيع المتنقل'
                );
            }

            $vanSession->start();

            DB::commit();

            return response()->json($vanSession->load(['items.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function complete(Request $request, VanSession $vanSession)
    {
        if ($vanSession->status !== 'active') {
            return response()->json(['message' => 'الجلسة ليست نشطة'], 400);
        }

        DB::beginTransaction();

        try {
            // Process returns for unsold items
            foreach ($vanSession->items as $sessionItem) {
                $unsold = $sessionItem->quantity_loaded - $sessionItem->quantity_sold - $sessionItem->quantity_returned;

                if ($unsold > 0) {
                    // Create return record
                    VanReturn::create([
                        'van_session_id' => $vanSession->id,
                        'product_id' => $sessionItem->product_id,
                        'quantity' => $unsold,
                        'reason' => 'unsold',
                        'returnable_to_stock' => true,
                    ]);

                    // Update session item
                    $sessionItem->quantity_returned = $unsold;
                    $sessionItem->save();

                    // Return to stock
                    StockMovement::record(
                        $sessionItem->product_id,
                        $vanSession->warehouse_id,
                        $unsold,
                        StockMovement::TYPE_VAN_RETURN,
                        $vanSession->reference,
                        $vanSession,
                        null,
                        'إرجاع من البيع المتنقل'
                    );
                }
            }

            $vanSession->complete();

            DB::commit();

            return response()->json($vanSession->load(['items.product', 'sales', 'returns.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function cancel(VanSession $vanSession)
    {
        if (!in_array($vanSession->status, ['preparing', 'active'])) {
            return response()->json(['message' => 'لا يمكن إلغاء الجلسة'], 400);
        }

        DB::beginTransaction();

        try {
            // If active, return all loaded stock
            if ($vanSession->status === 'active') {
                foreach ($vanSession->items as $sessionItem) {
                    $remaining = $sessionItem->quantity_loaded - $sessionItem->quantity_sold;

                    if ($remaining > 0) {
                        StockMovement::record(
                            $sessionItem->product_id,
                            $vanSession->warehouse_id,
                            $remaining,
                            StockMovement::TYPE_VAN_RETURN,
                            $vanSession->reference,
                            $vanSession,
                            null,
                            'إرجاع بسبب إلغاء الجلسة'
                        );
                    }
                }
            }

            $vanSession->cancel();

            DB::commit();

            return response()->json($vanSession);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // Create a sale from the van
    public function createSale(Request $request, VanSession $vanSession)
    {
        if ($vanSession->status !== 'active') {
            return response()->json(['message' => 'الجلسة غير نشطة'], 400);
        }

        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Validate stock availability on van
            foreach ($request->items as $item) {
                $available = $vanSession->getAvailableStock($item['product_id']);
                if ($available < $item['quantity']) {
                    $product = Product::find($item['product_id']);
                    return response()->json([
                        'message' => "الكمية غير متوفرة: {$product->name}. المتوفر: {$available}"
                    ], 400);
                }
            }

            // Create sale
            $sale = VanSale::create([
                'van_session_id' => $vanSession->id,
                'client_id' => $request->client_id,
                'discount' => $request->discount ?? 0,
                'paid_amount' => $request->paid_amount ?? 0,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'notes' => $request->notes,
            ]);

            $totalAmount = 0;

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                $itemDiscount = $item['discount'] ?? 0;
                $subtotal = ($item['quantity'] * $item['unit_price'] * ($product->pieces_per_package ?? 1)) - $itemDiscount;

                VanSaleItem::create([
                    'van_sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $itemDiscount,
                    'subtotal' => $subtotal,
                ]);

                $totalAmount += $subtotal;

                // Update van session item
                $sessionItem = $vanSession->items()->where('product_id', $item['product_id'])->first();
                if ($sessionItem) {
                    $sessionItem->quantity_sold = round($sessionItem->quantity_sold + $item['quantity'], 2);
                    $sessionItem->save();
                }
            }

            // Calculate totals
            $sale->total_amount = $totalAmount;
            $sale->grand_total = $totalAmount - ($request->discount ?? 0);
            $sale->due_amount = $sale->grand_total - ($request->paid_amount ?? 0);
            $sale->payment_status = $sale->calculatePaymentStatus();
            $sale->save();

            // Update client balance if credit sale
            if ($request->client_id && $sale->due_amount > 0) {
                $client = Client::find($request->client_id);
                if ($client) {
                    $client->updateBalance($sale->due_amount, 'add');
                }
            }

            // Update session totals
            $vanSession->updateTotals();

            // Record caisse transaction for paid amount
            if (($request->paid_amount ?? 0) > 0) {
                $caisse = Caisse::where('user_id', $vanSession->livreur_id)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $request->paid_amount,
                        'van_sale',
                        $sale->id,
                        "Vente camion #{$sale->id}",
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($sale->load(['client', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // Get sales for a session
    public function getSales(VanSession $vanSession)
    {
        $sales = $vanSession->sales()->with(['client', 'items.product'])->get();
        return response()->json($sales);
    }

    // Get available products on van
    public function getAvailableProducts(VanSession $vanSession)
    {
        $items = $vanSession->items()->with(['product.categoryPrices'])->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'van_session_id' => $item->van_session_id,
                'product_id' => $item->product_id,
                'product' => $item->product,
                'quantity_loaded' => round((float)$item->quantity_loaded, 2),
                'quantity_sold' => round((float)$item->quantity_sold, 2),
                'quantity_returned' => round((float)$item->quantity_returned, 2),
                'unit_price' => $item->product->retail_price ?? $item->unit_cost,
                'unit_cost' => $item->unit_cost,
                'pieces_per_package' => $item->product->pieces_per_package ?? 1,
                'product_name' => $item->product->name ?? null,
                'available' => $item->available_quantity,
            ];
        })->values();

        return response()->json($items);
    }

    // Get session stats
    public function getStats(VanSession $vanSession)
    {
        $vanSession->updateTotals();

        $stats = [
            'total_loaded_value' => $vanSession->total_loaded_value,
            'total_sales' => $vanSession->total_sales,
            'total_collected' => $vanSession->total_collected,
            'total_credit' => $vanSession->total_credit,
            'total_returned_value' => $vanSession->total_returned_value,
            'sales_count' => $vanSession->sales_count,
            'products_summary' => $vanSession->items()->with('product')->get()->map(function ($item) {
                return [
                    'product_name' => $item->product->name,
                    'loaded' => $item->quantity_loaded,
                    'sold' => $item->quantity_sold,
                    'available' => $item->available_quantity,
                ];
            }),
        ];

        return response()->json($stats);
    }

    // For livreur app - get active session
    public function getActiveSession(Request $request)
    {
        $livreurId = $request->user()->id;

        $session = VanSession::where('livreur_id', $livreurId)
            ->whereIn('status', ['preparing', 'active'])
            ->with(['vehicle', 'warehouse', 'items.product'])
            ->latest()
            ->first();

        return response()->json($session);
    }

    public function destroy(VanSession $vanSession)
    {
        if ($vanSession->status !== 'preparing') {
            return response()->json(['message' => 'لا يمكن حذف جلسة تم بدؤها'], 400);
        }

        $vanSession->items()->delete();
        $vanSession->delete();

        return response()->json(['message' => 'تم حذف الجلسة بنجاح']);
    }
}
