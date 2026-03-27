<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Caisse;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier', 'warehouse', 'user']);

        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->payment_status) {
            $query->where('payment_status', $request->payment_status);
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

        $purchases = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($purchases);
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'status' => 'nullable|in:pending,received',
            'paid_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.selling_price' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ]);

        $isDraft = ($request->status ?? 'received') === 'pending';

        DB::beginTransaction();

        try {
            $purchase = Purchase::create([
                'supplier_id' => $request->supplier_id,
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'shipping' => $request->shipping ?? 0,
                'timbre' => $request->timbre ?? 0,
                'note' => $request->note,
                'status' => $isDraft ? 'pending' : 'received',
            ]);

            foreach ($request->items as $item) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                // Only process stock and prices for received purchases (not drafts)
                if (!$isDraft) {
                    // Update product selling price if provided
                    if (isset($item['selling_price']) && $item['selling_price'] > 0) {
                        $product = \App\Models\Product::find($item['product_id']);
                        if ($product) {
                            $product->unit_price = $item['selling_price'];
                            $product->save();
                        }
                    }

                    // Record stock movement
                    StockMovement::record(
                        $item['product_id'],
                        $request->warehouse_id,
                        $item['quantity'],
                        StockMovement::TYPE_PURCHASE,
                        $purchase->reference,
                        $purchase,
                        $item['unit_price']
                    );
                }
            }

            $purchase->calculateTotals();

            // Draft: no payment, no supplier balance processing
            if ($isDraft) {
                DB::commit();
                return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product']), 201);
            }

            // Received: handle supplier balance and payments
            $paidAmount = $request->paid_amount ?? 0;

            if ($request->supplier_id) {
                $supplier = $purchase->supplier;

                // Add the purchase amount to supplier balance (debt increases)
                $supplier->updateBalance($purchase->grand_total, 'add');

                if ($paidAmount > 0) {
                    // Calculate how much goes to this purchase vs previous debt
                    $appliedToPurchase = min($paidAmount, $purchase->grand_total);
                    $appliedToPreviousDebt = max(0, $paidAmount - $purchase->grand_total);

                    // Create payment for this purchase
                    if ($appliedToPurchase > 0) {
                        $purchase->payments()->create([
                            'reference' => 'PAY-' . strtoupper(uniqid()),
                            'amount' => $appliedToPurchase,
                            'payment_method' => 'cash',
                            'date' => $request->date,
                            'notes' => 'دفعة عند الشراء',
                            'user_id' => auth()->id(),
                        ]);

                        $purchase->paid_amount = $appliedToPurchase;
                        $purchase->due_amount = max(0, $purchase->grand_total - $appliedToPurchase);

                        if ($purchase->due_amount <= 0) {
                            $purchase->payment_status = 'paid';
                            $purchase->due_amount = 0;
                        } else {
                            $purchase->payment_status = 'partial';
                        }

                        $purchase->save();

                        // Reduce supplier balance by amount paid
                        $supplier->updateBalance($appliedToPurchase, 'subtract');
                    }

                    // Apply extra to previous unpaid purchases (FIFO - oldest first)
                    if ($appliedToPreviousDebt > 0) {
                        $remainingExtra = $appliedToPreviousDebt;

                        $unpaidPurchases = Purchase::where('supplier_id', $supplier->id)
                            ->where('id', '!=', $purchase->id)
                            ->where('status', 'received') // Only received purchases have real debt
                            ->where('due_amount', '>', 0)
                            ->orderBy('date', 'asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        foreach ($unpaidPurchases as $oldPurchase) {
                            if ($remainingExtra <= 0) break;

                            $applyToThis = min($remainingExtra, $oldPurchase->due_amount);

                            $oldPurchase->payments()->create([
                                'reference' => 'PAY-' . strtoupper(uniqid()),
                                'amount' => $applyToThis,
                                'payment_method' => 'cash',
                                'date' => $request->date,
                                'notes' => 'تسديد من فاتورة ' . $purchase->reference,
                                'user_id' => auth()->id(),
                            ]);

                            $oldPurchase->paid_amount += $applyToThis;
                            $oldPurchase->due_amount = max(0, $oldPurchase->due_amount - $applyToThis);

                            if ($oldPurchase->due_amount <= 0) {
                                $oldPurchase->payment_status = 'paid';
                                $oldPurchase->due_amount = 0;
                            } else {
                                $oldPurchase->payment_status = 'partial';
                            }

                            $oldPurchase->save();
                            $supplier->updateBalance($applyToThis, 'subtract');
                            $remainingExtra -= $applyToThis;
                        }

                        // Overpayment beyond all debt
                        if ($remainingExtra > 0) {
                            $supplier->updateBalance($remainingExtra, 'subtract');
                        }
                    }
                }
            } else {
                // No supplier - handle payment for the purchase only
                if ($paidAmount > 0) {
                    $appliedAmount = min($paidAmount, $purchase->grand_total);
                    $purchase->paid_amount = $appliedAmount;
                    $purchase->due_amount = max(0, $purchase->grand_total - $appliedAmount);

                    if ($purchase->due_amount <= 0) {
                        $purchase->payment_status = 'paid';
                        $purchase->due_amount = 0;
                    } else {
                        $purchase->payment_status = 'partial';
                    }

                    $purchase->save();
                }
            }

            // Record caisse transaction (money going OUT to pay supplier)
            if ($paidAmount > 0) {
                $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'out',
                        $paidAmount,
                        Purchase::class,
                        $purchase->id,
                        'دفع فاتورة شراء ' . $purchase->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء إنشاء المشتريات', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Purchase $purchase)
    {
        return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product', 'payments', 'returns']));
    }

    public function update(Request $request, Purchase $purchase)
    {
        // Pending (draft) purchases can be fully edited - NO stock or balance impact
        if ($purchase->status === 'pending') {
            $request->validate([
                'supplier_id' => 'nullable|exists:suppliers,id',
                'warehouse_id' => 'nullable|exists:warehouses,id',
                'date' => 'nullable|date',
                'discount' => 'nullable|numeric|min:0',
                'tax' => 'nullable|numeric|min:0',
                'shipping' => 'nullable|numeric|min:0',
                'timbre' => 'nullable|numeric|min:0',
                'note' => 'nullable|string',
                'items' => 'nullable|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount' => 'nullable|numeric|min:0',
                'items.*.tax' => 'nullable|numeric|min:0',
            ]);

            $purchase->update($request->only(['supplier_id', 'warehouse_id', 'date', 'discount', 'tax', 'shipping', 'timbre', 'note']));

            if ($request->has('items')) {
                $purchase->items()->forceDelete();

                foreach ($request->items as $item) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                    ]);
                }
            }

            $purchase->calculateTotals();

            return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product']));
        }

        // Received purchases: allow full editing with stock reversal and balance adjustment
        if ($purchase->status === 'received') {
            $request->validate([
                'supplier_id' => 'nullable|exists:suppliers,id',
                'warehouse_id' => 'nullable|exists:warehouses,id',
                'date' => 'nullable|date',
                'discount' => 'nullable|numeric|min:0',
                'tax' => 'nullable|numeric|min:0',
                'shipping' => 'nullable|numeric|min:0',
                'timbre' => 'nullable|numeric|min:0',
                'note' => 'nullable|string',
                'items' => 'nullable|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount' => 'nullable|numeric|min:0',
                'items.*.tax' => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

            try {
                $oldGrandTotal = $purchase->grand_total;
                $oldSupplierId = $purchase->supplier_id;

                if ($request->has('items')) {
                    // Reverse old stock movements
                    foreach ($purchase->items as $oldItem) {
                        StockMovement::record(
                            $oldItem->product_id,
                            $purchase->warehouse_id,
                            $oldItem->quantity,
                            StockMovement::TYPE_PURCHASE_RETURN,
                            $purchase->reference . '-EDIT',
                            $purchase,
                            $oldItem->unit_price,
                            'تعديل فاتورة شراء'
                        );
                    }

                    // Delete old items and create new ones
                    $purchase->items()->forceDelete();

                    $warehouseId = $request->warehouse_id ?? $purchase->warehouse_id;

                    foreach ($request->items as $item) {
                        PurchaseItem::create([
                            'purchase_id' => $purchase->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'discount' => $item['discount'] ?? 0,
                            'tax' => $item['tax'] ?? 0,
                        ]);

                        // Record new stock movement
                        StockMovement::record(
                            $item['product_id'],
                            $warehouseId,
                            $item['quantity'],
                            StockMovement::TYPE_PURCHASE,
                            $purchase->reference . '-EDIT',
                            $purchase,
                            $item['unit_price']
                        );
                    }
                }

                $purchase->update($request->only(['supplier_id', 'warehouse_id', 'date', 'discount', 'tax', 'shipping', 'timbre', 'note']));
                $purchase->calculateTotals();
                $purchase->refresh();

                // Adjust supplier balance based on changes
                $newSupplierId = $purchase->supplier_id;
                $newGrandTotal = $purchase->grand_total;

                if ($oldSupplierId != $newSupplierId) {
                    // Supplier changed: remove debt from old, add to new
                    if ($oldSupplierId) {
                        \App\Models\Supplier::find($oldSupplierId)?->updateBalance($oldGrandTotal, 'subtract');
                    }
                    if ($newSupplierId) {
                        $purchase->supplier->updateBalance($newGrandTotal, 'add');
                    }
                } else if ($newSupplierId) {
                    // Same supplier: adjust by the difference
                    $totalDiff = $newGrandTotal - $oldGrandTotal;
                    if ($totalDiff != 0) {
                        $purchase->supplier->updateBalance(abs($totalDiff), $totalDiff > 0 ? 'add' : 'subtract');
                    }
                }

                DB::commit();

                return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product']));
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'حدث خطأ أثناء تحديث المشتريات', 'error' => $e->getMessage()], 500);
            }
        }

        // Fallback: only basic fields
        $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $purchase->update($request->only(['discount', 'tax', 'shipping', 'timbre', 'note']));
        $purchase->calculateTotals();

        return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product']));
    }

    /**
     * Confirm a pending purchase (transition to received)
     * Creates stock movements and increases supplier debt
     */
    public function confirm(Request $request, Purchase $purchase)
    {
        if ($purchase->status !== 'pending') {
            return response()->json(['message' => 'يمكن تأكيد المشتريات المعلقة فقط'], 400);
        }

        $request->validate([
            'paid_amount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Record stock movements for all items
            foreach ($purchase->items as $item) {
                StockMovement::record(
                    $item->product_id,
                    $purchase->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_PURCHASE,
                    $purchase->reference,
                    $purchase,
                    $item->unit_price
                );
            }

            // Update status to received
            $purchase->status = 'received';
            $purchase->save();

            // Recalculate totals
            $purchase->calculateTotals();

            // Handle supplier balance and payments
            $paidAmount = $request->paid_amount ?? 0;

            if ($purchase->supplier_id) {
                $supplier = $purchase->supplier;

                // Add purchase amount to supplier balance (debt increases)
                $supplier->updateBalance($purchase->grand_total, 'add');

                if ($paidAmount > 0) {
                    $appliedToPurchase = min($paidAmount, $purchase->grand_total);
                    $appliedToPreviousDebt = max(0, $paidAmount - $purchase->grand_total);

                    if ($appliedToPurchase > 0) {
                        $purchase->payments()->create([
                            'reference' => 'PAY-' . strtoupper(uniqid()),
                            'amount' => $appliedToPurchase,
                            'payment_method' => 'cash',
                            'date' => $purchase->date,
                            'notes' => 'دفعة عند تأكيد الشراء',
                            'user_id' => auth()->id(),
                        ]);

                        $purchase->paid_amount = $appliedToPurchase;
                        $purchase->due_amount = max(0, $purchase->grand_total - $appliedToPurchase);
                        $purchase->payment_status = $purchase->due_amount <= 0 ? 'paid' : 'partial';
                        $purchase->save();

                        $supplier->updateBalance($appliedToPurchase, 'subtract');
                    }

                    // Apply extra to previous unpaid purchases (FIFO)
                    if ($appliedToPreviousDebt > 0) {
                        $remainingExtra = $appliedToPreviousDebt;

                        $unpaidPurchases = Purchase::where('supplier_id', $supplier->id)
                            ->where('id', '!=', $purchase->id)
                            ->where('status', 'received')
                            ->where('due_amount', '>', 0)
                            ->orderBy('date', 'asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        foreach ($unpaidPurchases as $oldPurchase) {
                            if ($remainingExtra <= 0) break;

                            $applyToThis = min($remainingExtra, $oldPurchase->due_amount);

                            $oldPurchase->payments()->create([
                                'reference' => 'PAY-' . strtoupper(uniqid()),
                                'amount' => $applyToThis,
                                'payment_method' => 'cash',
                                'date' => $purchase->date,
                                'notes' => 'تسديد من فاتورة ' . $purchase->reference,
                                'user_id' => auth()->id(),
                            ]);

                            $oldPurchase->paid_amount += $applyToThis;
                            $oldPurchase->due_amount = max(0, $oldPurchase->due_amount - $applyToThis);
                            $oldPurchase->payment_status = $oldPurchase->due_amount <= 0 ? 'paid' : 'partial';
                            $oldPurchase->save();

                            $supplier->updateBalance($applyToThis, 'subtract');
                            $remainingExtra -= $applyToThis;
                        }

                        if ($remainingExtra > 0) {
                            $supplier->updateBalance($remainingExtra, 'subtract');
                        }
                    }
                }
            } else {
                // No supplier
                if ($paidAmount > 0) {
                    $appliedAmount = min($paidAmount, $purchase->grand_total);
                    $purchase->paid_amount = $appliedAmount;
                    $purchase->due_amount = max(0, $purchase->grand_total - $appliedAmount);
                    $purchase->payment_status = $purchase->due_amount <= 0 ? 'paid' : 'partial';
                    $purchase->save();
                }
            }

            // Record caisse transaction (money going OUT to pay supplier)
            if ($paidAmount > 0) {
                $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'out',
                        $paidAmount,
                        Purchase::class,
                        $purchase->id,
                        'دفع عند تأكيد فاتورة شراء ' . $purchase->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($purchase->load(['supplier', 'warehouse', 'user', 'items.product', 'payments']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء تأكيد المشتريات', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Purchase $purchase)
    {
        // Pending (draft) purchases can be freely deleted - no financial impact
        if ($purchase->status === 'pending') {
            $purchase->items()->forceDelete();
            $purchase->forceDelete();
            return response()->json(['message' => 'تم حذف المسودة بنجاح']);
        }

        // Received purchases: security checks for traceability
        if ($purchase->payment_status === 'paid') {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة مدفوعة. استخدم المرتجعات للحفاظ على التتبع المحاسبي'
            ], 400);
        }

        if ($purchase->payment_status === 'partial') {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها دفعات. استخدم المرتجعات للحفاظ على التتبع المحاسبي'
            ], 400);
        }

        if ($purchase->payments()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها دفعات مسجلة. استخدم المرتجعات بدلاً من ذلك'
            ], 400);
        }

        if ($purchase->returns()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها مرتجعات. هذه الفاتورة مرتبطة بعمليات أخرى'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Reverse stock movements (only for received purchases - which is guaranteed here)
            foreach ($purchase->items as $item) {
                StockMovement::record(
                    $item->product_id,
                    $purchase->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_PURCHASE_RETURN,
                    $purchase->reference . '-CANCEL',
                    $purchase,
                    $item->unit_price,
                    'إلغاء مشتريات'
                );
            }

            // Reverse supplier balance (debt decreases)
            if ($purchase->supplier_id) {
                $purchase->supplier->updateBalance($purchase->grand_total, 'subtract');
            }

            // Reverse caisse transactions (money comes back IN)
            if ($purchase->paid_amount > 0) {
                $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $purchase->paid_amount,
                        Purchase::class,
                        $purchase->id,
                        'إلغاء فاتورة شراء ' . $purchase->reference,
                        auth()->id()
                    );
                }
            }

            $purchase->delete();

            DB::commit();

            return response()->json(['message' => 'تم حذف المشتريات بنجاح']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء الحذف'], 500);
        }
    }

    public function createReturn(Request $request, Purchase $purchase)
    {
        // Only received purchases can have returns
        if ($purchase->status !== 'received') {
            return response()->json(['message' => 'لا يمكن إرجاع فاتورة مسودة'], 400);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $return = PurchaseReturn::create([
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'warehouse_id' => $purchase->warehouse_id,
                'user_id' => auth()->id(),
                'date' => now(),
                'note' => $request->note,
                'status' => 'approved',
            ]);

            foreach ($request->items as $item) {
                $purchaseItem = $purchase->items()->where('product_id', $item['product_id'])->first();
                if (!$purchaseItem) {
                    throw new \Exception('المنتج غير موجود في المشتريات');
                }

                // Validate return quantity doesn't exceed purchased quantity minus already returned
                $alreadyReturned = PurchaseReturnItem::whereHas('purchaseReturn', function ($q) use ($purchase) {
                    $q->where('purchase_id', $purchase->id);
                })->where('product_id', $item['product_id'])->sum('quantity');

                $maxReturnable = $purchaseItem->quantity - $alreadyReturned;
                if ($item['quantity'] > $maxReturnable) {
                    throw new \Exception("الكمية المرتجعة ({$item['quantity']}) أكبر من المتاح للإرجاع ({$maxReturnable})");
                }

                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $purchaseItem->unit_price,
                    'reason' => $item['reason'] ?? null,
                ]);

                // Record stock movement (subtract from warehouse)
                StockMovement::record(
                    $item['product_id'],
                    $purchase->warehouse_id,
                    $item['quantity'],
                    StockMovement::TYPE_PURCHASE_RETURN,
                    $return->reference,
                    $return,
                    $purchaseItem->unit_price,
                    $item['reason'] ?? null
                );
            }

            $return->calculateTotals();

            // Reduce supplier balance (they owe us less now)
            if ($purchase->supplier_id) {
                $purchase->supplier->updateBalance($return->total_amount, 'subtract');
            }

            // Adjust the purchase's due_amount
            $purchase->due_amount = max(0, $purchase->due_amount - $return->total_amount);
            $purchase->payment_status = $purchase->calculatePaymentStatus();
            $purchase->save();

            // Record caisse transaction (money coming IN — supplier refund)
            if ($return->total_amount > 0) {
                $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $return->total_amount,
                        PurchaseReturn::class,
                        $return->id,
                        'مرتجع شراء - فاتورة ' . $purchase->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($return->load(['supplier', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Add payment to purchase
     */
    public function addPayment(Request $request, Purchase $purchase)
    {
        // Only received purchases can accept payments
        if ($purchase->status !== 'received') {
            return response()->json(['message' => 'لا يمكن الدفع لفاتورة مسودة'], 400);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank,check,other',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $amount = $request->amount;

        // Check if amount is greater than due
        if ($amount > $purchase->due_amount) {
            return response()->json([
                'message' => 'المبلغ أكبر من المتبقي'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Create payment record
            $payment = $purchase->payments()->create([
                'reference' => 'PAY-' . strtoupper(uniqid()),
                'amount' => $amount,
                'payment_method' => $request->payment_method,
                'date' => $request->date,
                'notes' => $request->notes,
                'user_id' => auth()->id(),
            ]);

            // Update purchase paid amount and due amount
            $purchase->paid_amount += $amount;
            $purchase->due_amount = max(0, $purchase->due_amount - $amount);

            // Update payment status
            if ($purchase->due_amount <= 0) {
                $purchase->payment_status = 'paid';
                $purchase->due_amount = 0;
            } elseif ($purchase->paid_amount > 0) {
                $purchase->payment_status = 'partial';
            }

            $purchase->save();

            // Update supplier balance if exists
            if ($purchase->supplier_id) {
                $purchase->supplier->updateBalance($amount, 'subtract');
            }

            // Record caisse transaction (money going OUT)
            $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'out',
                    $amount,
                    Purchase::class,
                    $purchase->id,
                    'دفعة لفاتورة شراء ' . $purchase->reference,
                    auth()->id()
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح',
                'payment' => $payment,
                'purchase' => $purchase->fresh()->load(['supplier', 'warehouse', 'user', 'items.product', 'payments']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء تسجيل الدفعة'], 500);
        }
    }

    /**
     * Generate Purchase Facture PDF
     */
    public function generateFacturePdf(Purchase $purchase)
    {
        $purchase->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.facture-achat', compact('purchase', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->download("bon-achat-{$purchase->reference}.pdf");
    }

    /**
     * Stream Purchase Facture PDF (for printing)
     */
    public function streamFacturePdf(Purchase $purchase)
    {
        $purchase->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.facture-achat', compact('purchase', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->stream("bon-achat-{$purchase->reference}.pdf");
    }

    /**
     * Generate Bon de Commande (Purchase Order) PDF
     */
    public function generateBonCommandePdf(Purchase $purchase)
    {
        $purchase->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-commande-achat', compact('purchase', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->download("bon-commande-{$purchase->reference}.pdf");
    }

    /**
     * Stream Bon de Commande (Purchase Order) PDF (for printing)
     */
    public function streamBonCommandePdf(Purchase $purchase)
    {
        $purchase->load(['supplier', 'warehouse', 'user', 'items.product.unitBuy.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-commande-achat', compact('purchase', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->stream("bon-commande-{$purchase->reference}.pdf");
    }

    /**
     * Get company settings for PDF
     */
    private function getCompanySettings()
    {
        return [
            'company_name' => \App\Models\Setting::get('company_name', 'RAFIK BISKRA'),
            'company_address' => \App\Models\Setting::get('company_address', 'Biskra, Algerie'),
            'company_phone' => \App\Models\Setting::get('company_phone', '0555 123 456'),
            'company_email' => \App\Models\Setting::get('company_email', ''),
            'company_rc' => \App\Models\Setting::get('company_rc', ''),
            'company_nif' => \App\Models\Setting::get('company_nif', ''),
            'company_ai' => \App\Models\Setting::get('company_ai', ''),
            'company_nis' => \App\Models\Setting::get('company_nis', ''),
            'company_logo' => \App\Models\Setting::get('company_logo'),
        ];
    }
}
