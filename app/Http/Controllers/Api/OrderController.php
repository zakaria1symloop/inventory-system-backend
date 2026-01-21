<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['client', 'seller', 'warehouse', 'trip']);

        if ($request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->trip_id) {
            $query->where('trip_id', $request->trip_id);
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

        if ($request->search) {
            $query->where('reference', 'like', "%{$request->search}%");
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id' => 'nullable|exists:trips,id',
            'client_id' => 'required|exists:clients,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Validate stock availability for all items first (considering reserved quantities)
            $stockErrors = [];
            foreach ($request->items as $item) {
                $product = \App\Models\Product::find($item['product_id']);
                $productName = $product ? $product->name : 'غير معروف';

                // Use getAvailableStock to check stock minus reserved quantities
                $availableQty = Stock::getAvailableStock($item['product_id'], $request->warehouse_id);
                $requiredQty = $item['quantity'];

                if ($availableQty < $requiredQty) {
                    $stockErrors[] = "{$productName}: المطلوب {$requiredQty}، المتوفر {$availableQty}";
                }
            }

            if (count($stockErrors) > 0) {
                throw new \Exception("الكمية غير متوفرة: " . implode(' | ', $stockErrors));
            }

            $order = Order::create([
                'trip_id' => $request->trip_id,
                'client_id' => $request->client_id,
                'seller_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
                'date' => $request->date ?? now(),
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'payment_method' => $request->payment_method,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            foreach ($request->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity_ordered' => $item['quantity'],
                    'quantity_confirmed' => $item['quantity'], // Default to ordered quantity
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                ]);

                // Note: Orders do NOT reduce physical stock, they only reserve it
                // Stock is reduced when delivery starts (TYPE_DELIVERY_OUT)
            }

            $order->calculateTotals();

            DB::commit();

            return response()->json($order->load(['client', 'seller', 'warehouse', 'items.product.unitSale']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Order $order)
    {
        return response()->json($order->load(['client', 'seller', 'warehouse', 'trip', 'items.product.unitSale', 'deliveryOrders.delivery', 'problemReporter']));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $order->update($request->only(['discount', 'tax', 'notes']));
        $order->calculateTotals();

        return response()->json($order->load(['client', 'seller', 'items.product']));
    }

    public function destroy(Order $order)
    {
        // Security checks
        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'لا يمكن حذف الطلب. فقط الطلبات المعلقة يمكن حذفها'
            ], 400);
        }

        // Check if order is assigned to any delivery
        if ($order->deliveryOrders()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الطلب. هذا الطلب مرتبط بتوصيل'
            ], 400);
        }

        // Check if order has any delivery status
        if (in_array($order->status, ['delivered', 'partial', 'failed', 'postponed'])) {
            return response()->json([
                'message' => 'لا يمكن حذف طلب تم توصيله أو معالجته'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Note: No need to return stock since orders don't reduce physical stock
            // They only reserve stock, which is automatically unreserved when order is deleted
            $order->delete();

            DB::commit();
            return response()->json(['message' => 'تم حذف الطلب بنجاح']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function confirm(Order $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'الطلب ليس في حالة انتظار'], 400);
        }

        // Validate stock availability for all items (considering reserved quantities, excluding this order)
        $order->load(['items.product', 'warehouse']);
        $stockErrors = [];

        foreach ($order->items as $item) {
            // Use getAvailableStock to check stock minus reserved quantities from other orders
            // Exclude this order's items since we're checking if THIS order can be confirmed
            $availableQty = Stock::getAvailableStock($item->product_id, $order->warehouse_id, $order->id);
            $requiredQty = $item->quantity_ordered;

            if ($availableQty < $requiredQty) {
                $productName = $item->product ? $item->product->name : 'غير معروف';
                $stockErrors[] = "{$productName}: المطلوب {$requiredQty}، المتوفر {$availableQty}";
            }
        }

        if (count($stockErrors) > 0) {
            return response()->json([
                'message' => 'الكمية غير متوفرة للمنتجات التالية',
                'errors' => $stockErrors
            ], 400);
        }

        $order->confirm();

        return response()->json($order->load(['items.product.unitSale']));
    }

    public function cancel(Order $order)
    {
        if (!in_array($order->status, ['pending', 'confirmed'])) {
            return response()->json(['message' => 'لا يمكن إلغاء الطلب في هذه الحالة'], 400);
        }

        $order->cancel();

        return response()->json($order);
    }

    public function getPendingOrders(Request $request)
    {
        $orders = Order::pending()
            ->with(['client', 'seller', 'items.product.unitSale'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function getConfirmedOrders(Request $request)
    {
        $orders = Order::confirmed()
            ->with(['client', 'seller', 'items.product.unitSale'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function getMyOrders(Request $request)
    {
        $orders = Order::where('seller_id', auth()->id())
            ->with(['client', 'items.product.unitSale'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function updateItemQuantity(Request $request, Order $order, OrderItem $item)
    {
        $request->validate([
            'quantity_confirmed' => 'required|numeric|min:0',
        ]);

        if ($request->quantity_confirmed > $item->quantity_ordered) {
            return response()->json(['message' => 'الكمية المؤكدة أكبر من الكمية المطلوبة'], 400);
        }

        // Check available stock (considering reserved quantities, excluding current order)
        $availableQty = Stock::getAvailableStock($item->product_id, $order->warehouse_id, $order->id);

        // Add back the current item's confirmed quantity if it's already set (we're modifying it)
        if ($item->quantity_confirmed) {
            $availableQty += $item->quantity_confirmed;
        }

        if ($request->quantity_confirmed > $availableQty) {
            $productName = $item->product ? $item->product->name : 'غير معروف';
            return response()->json([
                'message' => "الكمية غير متوفرة للمنتج: {$productName}. المتوفر: {$availableQty}"
            ], 400);
        }

        $item->quantity_confirmed = $request->quantity_confirmed;
        $item->save();

        $order->calculateTotals();

        return response()->json($item->load('product'));
    }

    public function generatePdf(Order $order)
    {
        $order->load(['client', 'seller', 'warehouse', 'trip', 'items.product.unitSale']);

        $settings = $this->getCompanySettings();
        $pdf = Pdf::loadView('pdf.bon-commande', compact('order', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->download("bon-commande-{$order->reference}.pdf");
    }

    public function streamPdf(Order $order)
    {
        $order->load(['client', 'seller', 'warehouse', 'trip', 'items.product.unitSale']);

        $settings = $this->getCompanySettings();
        $pdf = Pdf::loadView('pdf.bon-commande', compact('order', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->stream("bon-commande-{$order->reference}.pdf");
    }

    private function getCompanySettings()
    {
        return [
            'company_name' => Setting::get('company_name', 'RAFIK BISKRA'),
            'company_address' => Setting::get('company_address', 'Biskra, Algerie'),
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

    public function reportProblem(Request $request, Order $order)
    {
        $request->validate([
            'problem_description' => 'required|string|max:1000',
        ]);

        $order->reportProblem($request->problem_description, auth()->id());

        return response()->json([
            'message' => 'تم الإبلاغ عن المشكلة بنجاح',
            'data' => $order->load(['client', 'problemReporter']),
        ]);
    }

    public function resolveProblem(Order $order)
    {
        if (!$order->has_problem) {
            return response()->json(['message' => 'لا توجد مشكلة مسجلة على هذا الطلب'], 400);
        }

        $order->resolveProblem();

        return response()->json([
            'message' => 'تم حل المشكلة بنجاح',
            'data' => $order,
        ]);
    }

    public function getOrdersWithProblems(Request $request)
    {
        $orders = Order::withProblems()
            ->with(['client', 'seller', 'problemReporter'])
            ->latest('problem_reported_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function getUnassignedOrders(Request $request)
    {
        $orders = Order::where('status', 'confirmed')
            ->with(['client', 'seller', 'warehouse', 'items.product.unitSale'])
            ->latest()
            ->get();

        return response()->json($orders);
    }
}
