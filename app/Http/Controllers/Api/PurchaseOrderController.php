<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse', 'user']);

        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
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
            $query->where(function ($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                  ->orWhereHas('supplier', function ($sq) use ($request) {
                      $sq->where('name', 'like', "%{$request->search}%");
                  });
            });
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $order = PurchaseOrder::create([
                'supplier_id' => $request->supplier_id,
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'shipping' => $request->shipping ?? 0,
                'note' => $request->note,
                'terms' => $request->terms,
                'status' => 'draft',
            ]);

            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);
            }

            $order->calculateTotals();

            DB::commit();

            return response()->json($order->load(['supplier', 'warehouse', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy', 'purchase']));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'لا يمكن تعديل طلب تم استلامه'], 400);
        }

        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'terms' => 'nullable|string',
            'status' => 'nullable|in:draft,sent,confirmed,cancelled',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $purchaseOrder->update([
                'supplier_id' => $request->supplier_id,
                'warehouse_id' => $request->warehouse_id,
                'date' => $request->date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'shipping' => $request->shipping ?? 0,
                'note' => $request->note,
                'terms' => $request->terms,
                'status' => $request->status ?? $purchaseOrder->status,
            ]);

            // Delete old items and create new ones
            $purchaseOrder->items()->delete();

            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);
            }

            $purchaseOrder->calculateTotals();

            DB::commit();

            return response()->json($purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'لا يمكن حذف طلب تم استلامه'], 400);
        }

        $purchaseOrder->delete();

        return response()->json(['message' => 'تم حذف بون الطلب بنجاح']);
    }

    /**
     * Update status only
     */
    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder)
    {
        $request->validate([
            'status' => 'required|in:draft,sent,confirmed,cancelled',
        ]);

        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'لا يمكن تغيير حالة طلب تم استلامه'], 400);
        }

        $purchaseOrder->update(['status' => $request->status]);

        return response()->json($purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product']));
    }

    /**
     * Convert purchase order to actual purchase (receive goods)
     */
    public function convertToPurchase(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'تم استلام هذا الطلب مسبقاً'], 400);
        }

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json(['message' => 'لا يمكن استلام طلب ملغي'], 400);
        }

        $request->validate([
            'paid_amount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Create purchase from order
            $purchase = Purchase::create([
                'supplier_id' => $purchaseOrder->supplier_id,
                'warehouse_id' => $purchaseOrder->warehouse_id,
                'user_id' => auth()->id(),
                'date' => now(),
                'discount' => $purchaseOrder->discount,
                'tax' => $purchaseOrder->tax,
                'shipping' => $purchaseOrder->shipping,
                'note' => $purchaseOrder->note . "\n[من بون الطلب: {$purchaseOrder->reference}]",
                'status' => 'received',
            ]);

            foreach ($purchaseOrder->items as $item) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'tax' => $item->tax,
                ]);

                // Record stock movement (this increases stock)
                StockMovement::record(
                    $item->product_id,
                    $purchaseOrder->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_PURCHASE,
                    $purchase->reference,
                    $purchase,
                    $item->unit_price
                );
            }

            $purchase->calculateTotals();

            // Handle payment if provided
            $paidAmount = $request->paid_amount ?? 0;
            if ($paidAmount > 0) {
                $purchase->payments()->create([
                    'reference' => 'PAY-' . strtoupper(uniqid()),
                    'amount' => min($paidAmount, $purchase->grand_total),
                    'payment_method' => 'cash',
                    'date' => now(),
                    'notes' => 'دفعة عند استلام بون الطلب',
                    'user_id' => auth()->id(),
                ]);

                $purchase->paid_amount = min($paidAmount, $purchase->grand_total);
                $purchase->due_amount = max(0, $purchase->grand_total - $paidAmount);

                if ($purchase->due_amount <= 0) {
                    $purchase->payment_status = 'paid';
                } else {
                    $purchase->payment_status = 'partial';
                }

                $purchase->save();

                // Update supplier balance
                if ($purchase->supplier_id) {
                    $purchase->supplier->updateBalance($purchase->grand_total - min($paidAmount, $purchase->grand_total), 'add');
                }
            } else {
                // No payment - add full amount to supplier balance
                if ($purchase->supplier_id) {
                    $purchase->supplier->updateBalance($purchase->grand_total, 'add');
                }
            }

            // Update purchase order status and link to purchase
            $purchaseOrder->update([
                'status' => 'received',
                'purchase_id' => $purchase->id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'تم استلام الطلب وإنشاء فاتورة الشراء بنجاح',
                'purchase_order' => $purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product']),
                'purchase' => $purchase->load(['supplier', 'warehouse', 'user', 'items.product']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate Bon de Commande PDF
     */
    public function generatePdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-commande-fournisseur', [
            'order' => $purchaseOrder,
            'settings' => $settings,
        ]);
        $pdf->setPaper('a4');

        return $pdf->download("bon-commande-{$purchaseOrder->reference}.pdf");
    }

    /**
     * Stream Bon de Commande PDF (for printing)
     */
    public function streamPdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-commande-fournisseur', [
            'order' => $purchaseOrder,
            'settings' => $settings,
        ]);
        $pdf->setPaper('a4');

        return $pdf->stream("bon-commande-{$purchaseOrder->reference}.pdf");
    }

    private function getCompanySettings()
    {
        return [
            'company_name' => \App\Models\Setting::get('company_name', 'RAFIK BISKRA'),
            'company_address' => \App\Models\Setting::get('company_address', 'Biskra, Algerie'),
            'company_phone' => \App\Models\Setting::get('company_phone', ''),
            'company_email' => \App\Models\Setting::get('company_email', ''),
            'company_rc' => \App\Models\Setting::get('company_rc', ''),
            'company_nif' => \App\Models\Setting::get('company_nif', ''),
            'company_ai' => \App\Models\Setting::get('company_ai', ''),
            'company_nis' => \App\Models\Setting::get('company_nis', ''),
            'company_rib' => \App\Models\Setting::get('company_rib', ''),
            'company_logo' => \App\Models\Setting::get('company_logo'),
        ];
    }
}
