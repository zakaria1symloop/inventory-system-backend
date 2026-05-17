<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Caisse;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['client', 'warehouse', 'user', 'items.product:id,pieces_per_package'])
            ->withCount('returns')
            ->withSum('returns as returns_total', 'total_amount');

        if ($request->client_id) {
            $query->where('client_id', $request->client_id);
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

        if ($request->source) {
            $query->where('source', $request->source);
        }

        $sales = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($sales);
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:completed,draft',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ]);

        $isDraft = ($request->status ?? 'completed') === 'draft';

        // Enforce client credit limit (max debt) for non-draft sales with a client
        if (!$isDraft && $request->client_id) {
            $client = Client::find($request->client_id);
            if ($client && (float) $client->credit_limit > 0) {
                $itemsTotal = 0;
                foreach ($request->items as $item) {
                    $qty = (float) ($item['quantity'] ?? 0);
                    $price = (float) ($item['unit_price'] ?? 0);
                    $disc = (float) ($item['discount'] ?? 0);
                    $tx = (float) ($item['tax'] ?? 0);
                    $itemsTotal += ($price * $qty) - $disc + $tx;
                }
                $expectedGrandTotal = $itemsTotal
                    - (float) ($request->discount ?? 0)
                    + (float) ($request->tax ?? 0)
                    + (float) ($request->shipping ?? 0)
                    + (float) ($request->timbre ?? 0);
                $paidAmount = (float) ($request->paid_amount ?? 0);
                $unpaid = max(0, $expectedGrandTotal - $paidAmount);
                $projectedBalance = (float) $client->balance + $unpaid;
                if ($projectedBalance > (float) $client->credit_limit) {
                    return response()->json([
                        'message' => sprintf(
                            'تجاوز سقف الدين للزبون %s. السقف: %s، الرصيد الحالي: %s، المبلغ غير المدفوع: %s',
                            $client->name,
                            number_format((float) $client->credit_limit, 2),
                            number_format((float) $client->balance, 2),
                            number_format($unpaid, 2)
                        ),
                        'errors' => [
                            'client_id' => ['Credit limit exceeded'],
                        ],
                    ], 422);
                }
            }
        }

        DB::beginTransaction();

        try {
            // Validate stock availability only for non-draft sales
            if (!$isDraft) {
                foreach ($request->items as $item) {
                    $product = \App\Models\Product::find($item['product_id']);
                    $productName = $product ? $product->name : 'غير معروف';
                    $ppp = $product ? ($product->pieces_per_package ?? 1) : 1;

                    $availableQty = Stock::getAvailableStock($item['product_id'], $request->warehouse_id);

                    // Stock might be in cartons (old data) or pieces (new data)
                    // If available < requested but available * ppp >= requested, stock is in cartons
                    if ($availableQty < $item['quantity'] && $ppp > 1 && ($availableQty * $ppp) >= $item['quantity']) {
                        // Stock is in cartons, convert to pieces first
                        $this->convertStockToPieces($item['product_id'], $request->warehouse_id, $ppp);
                        $availableQty = Stock::getAvailableStock($item['product_id'], $request->warehouse_id);
                    }

                    if ($availableQty < $item['quantity']) {
                        $piecesAvail = $availableQty;
                        $cartonsAvail = $ppp > 1 ? floor($piecesAvail / $ppp) : $piecesAvail;
                        $extraAvail = $ppp > 1 ? $piecesAvail % $ppp : 0;
                        $availDisplay = $ppp > 1 ? "{$cartonsAvail} كرتون" . ($extraAvail > 0 ? " + {$extraAvail} قطعة" : "") . " ({$piecesAvail} قطعة)" : "{$piecesAvail}";
                        throw new \Exception("الكمية غير متوفرة للمنتج: {$productName}. المتوفر: {$availDisplay}، المطلوب: {$item['quantity']} قطعة");
                    }
                }
            }

            // Auto-detect source based on user role
            $role = auth()->user()->role;
            $source = in_array($role, ['seller', 'livreur', 'cashvan']) ? 'app' : 'web';

            $sale = Sale::create([
                'client_id' => $request->client_id,
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'shipping' => $request->shipping ?? 0,
                'timbre' => $request->timbre ?? 0,
                'timbre_percentage' => $request->timbre_percentage ?? 0,
                'tax_percentage' => $request->tax_percentage ?? 0,
                'note' => $request->note,
                'status' => $isDraft ? 'draft' : 'completed',
                'source' => $source,
            ]);

            foreach ($request->items as $item) {
                $product = \App\Models\Product::find($item['product_id']);
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'cost_price' => $product->cost_price ?? 0,
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                // Only deduct stock for non-draft sales
                if (!$isDraft) {
                    StockMovement::record(
                        $item['product_id'],
                        $request->warehouse_id,
                        $item['quantity'],
                        StockMovement::TYPE_SALE,
                        $sale->reference,
                        $sale,
                        $item['unit_price']
                    );
                }
            }

            $sale->calculateTotals();

            $paidAmount = $request->paid_amount ?? 0;

            // Only handle payments for non-draft sales
            if (!$isDraft) {

                if ($request->client_id) {
                    $client = $sale->client;

                    // Add the sale amount to client balance (debt)
                    $client->updateBalance($sale->grand_total, 'add');

                    if ($paidAmount > 0) {
                        $appliedToSale = min($paidAmount, $sale->grand_total);
                        $appliedToPreviousDebt = max(0, $paidAmount - $sale->grand_total);

                        if ($appliedToSale > 0) {
                            $sale->payments()->create([
                                'reference' => 'PAY-' . strtoupper(uniqid()),
                                'amount' => $appliedToSale,
                                'payment_method' => 'cash',
                                'date' => $request->date,
                                'notes' => 'دفعة عند البيع',
                                'user_id' => auth()->id(),
                            ]);

                            $sale->paid_amount = $appliedToSale;
                            $sale->due_amount = $sale->grand_total - $appliedToSale;

                            if ($sale->due_amount <= 0) {
                                $sale->payment_status = 'paid';
                                $sale->due_amount = 0;
                            } else {
                                $sale->payment_status = 'partial';
                            }

                            $sale->save();

                            $client->updateBalance($appliedToSale, 'subtract');
                        }

                        if ($appliedToPreviousDebt > 0) {
                            $remainingExtra = $appliedToPreviousDebt;

                            $unpaidSales = Sale::where('client_id', $client->id)
                                ->where('id', '!=', $sale->id)
                                ->where('due_amount', '>', 0)
                                ->orderBy('date', 'asc')
                                ->orderBy('id', 'asc')
                                ->get();

                            foreach ($unpaidSales as $oldSale) {
                                if ($remainingExtra <= 0) break;

                                $applyToThis = min($remainingExtra, $oldSale->due_amount);

                                $oldSale->payments()->create([
                                    'reference' => 'PAY-' . strtoupper(uniqid()),
                                    'amount' => $applyToThis,
                                    'payment_method' => 'cash',
                                    'date' => $request->date,
                                    'notes' => 'تسديد من فاتورة ' . $sale->reference,
                                    'user_id' => auth()->id(),
                                ]);

                                $oldSale->paid_amount += $applyToThis;
                                $oldSale->due_amount -= $applyToThis;

                                if ($oldSale->due_amount <= 0) {
                                    $oldSale->payment_status = 'paid';
                                    $oldSale->due_amount = 0;
                                } else {
                                    $oldSale->payment_status = 'partial';
                                }

                                $oldSale->save();

                                $client->updateBalance($applyToThis, 'subtract');

                                $remainingExtra -= $applyToThis;
                            }

                            if ($remainingExtra > 0) {
                                $client->updateBalance($remainingExtra, 'subtract');
                            }
                        }
                    }
                } else {
                    // No client - cap payment at grand_total
                    if ($paidAmount > 0) {
                        $appliedAmount = min($paidAmount, $sale->grand_total);
                        $sale->paid_amount = $appliedAmount;
                        $sale->due_amount = max(0, $sale->grand_total - $appliedAmount);
                        $sale->payment_status = $sale->due_amount <= 0 ? 'paid' : 'partial';
                        $sale->save();
                    }
                }
            }

            // Record caisse transaction for paid amount
            if (!$isDraft && $paidAmount > 0) {
                $caisse = Caisse::where('user_id', auth()->id())->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $paidAmount,
                        Sale::class,
                        $sale->id,
                        'تحصيل من فاتورة ' . $sale->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Sale creation failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }

    /**
     * Confirm a draft sale - deduct stock, process payments, change status to completed
     */
    public function confirm(Sale $sale, Request $request)
    {
        if ($sale->status !== 'draft') {
            return response()->json(['message' => 'يمكن تأكيد المسودات فقط'], 400);
        }

        $request->validate([
            'paid_amount' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Load items once for both validation and stock deduction
            $items = $sale->items()->with('product')->get();

            // Validate stock availability
            foreach ($items as $item) {
                $productName = $item->product ? $item->product->name : 'غير معروف';
                $availableQty = Stock::getAvailableStock($item->product_id, $sale->warehouse_id);

                if ($availableQty < $item->quantity) {
                    throw new \Exception("الكمية غير متوفرة للمنتج: {$productName}. المتوفر: {$availableQty}، المطلوب: {$item->quantity}");
                }
            }

            // Deduct stock for each item
            foreach ($items as $item) {
                StockMovement::record(
                    $item->product_id,
                    $sale->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_SALE,
                    $sale->reference,
                    $sale,
                    $item->unit_price
                );
            }

            $sale->status = 'completed';
            // Initialize payment fields for the now-completed sale.
            // calculateTotals() sets due_amount = grand_total - paid_amount and payment_status.
            $sale->calculateTotals();
            $sale->refresh();

            // Handle payment
            $paidAmount = $request->paid_amount ?? 0;

            if ($sale->client_id) {
                $client = $sale->client;
                $client->updateBalance($sale->grand_total, 'add');

                if ($paidAmount > 0) {
                    $appliedToSale = min($paidAmount, $sale->grand_total);
                    $appliedToPreviousDebt = max(0, $paidAmount - $sale->grand_total);

                    if ($appliedToSale > 0) {
                        $sale->payments()->create([
                            'reference' => 'PAY-' . strtoupper(uniqid()),
                            'amount' => $appliedToSale,
                            'payment_method' => 'cash',
                            'date' => $sale->date,
                            'notes' => 'دفعة عند تأكيد البيع',
                            'user_id' => auth()->id(),
                        ]);

                        $sale->paid_amount = $appliedToSale;
                        $sale->due_amount = max(0, $sale->grand_total - $appliedToSale);
                        $sale->payment_status = $sale->due_amount <= 0 ? 'paid' : 'partial';
                        $sale->save();
                        $client->updateBalance($appliedToSale, 'subtract');
                    }

                    // Apply excess to previous unpaid sales (FIFO)
                    if ($appliedToPreviousDebt > 0) {
                        $remainingExtra = $appliedToPreviousDebt;

                        $unpaidSales = Sale::where('client_id', $client->id)
                            ->where('id', '!=', $sale->id)
                            ->where('status', 'completed')
                            ->where('due_amount', '>', 0)
                            ->orderBy('date', 'asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        foreach ($unpaidSales as $oldSale) {
                            if ($remainingExtra <= 0) break;

                            $applyToThis = min($remainingExtra, $oldSale->due_amount);

                            $oldSale->payments()->create([
                                'reference' => 'PAY-' . strtoupper(uniqid()),
                                'amount' => $applyToThis,
                                'payment_method' => 'cash',
                                'date' => $sale->date,
                                'notes' => 'تسديد من فاتورة ' . $sale->reference,
                                'user_id' => auth()->id(),
                            ]);

                            $oldSale->paid_amount += $applyToThis;
                            $oldSale->due_amount = max(0, $oldSale->due_amount - $applyToThis);
                            $oldSale->payment_status = $oldSale->due_amount <= 0 ? 'paid' : 'partial';
                            $oldSale->save();

                            $client->updateBalance($applyToThis, 'subtract');
                            $remainingExtra -= $applyToThis;
                        }

                        if ($remainingExtra > 0) {
                            $client->updateBalance($remainingExtra, 'subtract');
                        }
                    }
                }
            } else {
                // No client - cap payment at grand_total
                if ($paidAmount > 0) {
                    $appliedAmount = min($paidAmount, $sale->grand_total);
                    $sale->paid_amount = $appliedAmount;
                    $sale->due_amount = max(0, $sale->grand_total - $appliedAmount);
                    $sale->payment_status = $sale->due_amount <= 0 ? 'paid' : 'partial';
                    $sale->save();
                }
            }

            // Record caisse transaction
            if ($paidAmount > 0) {
                $caisse = Caisse::where('user_id', auth()->id())->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'in',
                        $paidAmount,
                        Sale::class,
                        $sale->id,
                        'تحصيل من فاتورة ' . $sale->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product', 'payments']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Sale $sale)
    {
        return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit', 'items.product.unitBuy.baseUnit', 'payments', 'returns']));
    }

    public function update(Request $request, Sale $sale)
    {
        // Draft sales can be fully edited (items, client, warehouse, etc.)
        if ($sale->status === 'draft') {
            $request->validate([
                'client_id' => 'nullable|exists:clients,id',
                'warehouse_id' => 'nullable|exists:warehouses,id',
                'date' => 'nullable|date',
                'discount' => 'nullable|numeric|min:0',
                'tax' => 'nullable|numeric|min:0',
                'shipping' => 'nullable|numeric|min:0',
                'timbre' => 'nullable|numeric|min:0',
                'note' => 'nullable|string',
                'paid_amount' => 'nullable|numeric|min:0',
                'items' => 'nullable|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.discount' => 'nullable|numeric|min:0',
                'items.*.tax' => 'nullable|numeric|min:0',
            ]);

            $sale->update($request->only(['client_id', 'warehouse_id', 'date', 'discount', 'tax', 'tax_percentage', 'shipping', 'timbre', 'timbre_percentage', 'paid_amount', 'note']));

            // If items are provided, replace them
            if ($request->has('items')) {
                $sale->items()->forceDelete();

                foreach ($request->items as $item) {
                    $product = \App\Models\Product::find($item['product_id']);
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'cost_price' => $product->cost_price ?? 0,
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                    ]);
                }
            }

            $sale->calculateTotals();

            return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product']));
        }

        // Non-draft (completed) sales: full edit with stock reversal
        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'timbre' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
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
            $oldGrandTotal = $sale->grand_total;
            $oldClientId = $sale->client_id;
            $oldPaidAmount = (float) $sale->paid_amount;

            // If items changed, reverse old stock and apply new
            if ($request->has('items')) {
                // Reverse old stock
                foreach ($sale->items as $item) {
                    StockMovement::record(
                        $item->product_id,
                        $sale->warehouse_id,
                        $item->quantity,
                        StockMovement::TYPE_SALE_RETURN,
                        $sale->reference . '-EDIT-REV',
                        $sale,
                        $item->unit_price,
                        'تعديل فاتورة - إرجاع قديم'
                    );
                }

                // Delete old items
                $sale->items()->forceDelete();

                // Create new items and deduct stock
                $newWarehouseId = $request->warehouse_id ?? $sale->warehouse_id;
                foreach ($request->items as $item) {
                    $product = \App\Models\Product::find($item['product_id']);
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'cost_price' => $product->cost_price ?? 0,
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                    ]);

                    StockMovement::record(
                        $item['product_id'],
                        $newWarehouseId,
                        $item['quantity'],
                        StockMovement::TYPE_SALE,
                        $sale->reference . '-EDIT-NEW',
                        $sale,
                        $item['unit_price'],
                        'تعديل فاتورة - خصم جديد'
                    );
                }
            }

            $sale->update($request->only(['client_id', 'warehouse_id', 'date', 'discount', 'tax', 'tax_percentage', 'shipping', 'timbre', 'timbre_percentage', 'paid_amount', 'note']));
            $sale->calculateTotals();
            $sale->refresh();

            $newPaidAmount = (float) $sale->paid_amount;

            // Adjust client balance — client owes (grand_total - paid_amount)
            if ($oldClientId) {
                $oldClient = Client::find($oldClientId);
                if ($oldClient) {
                    $oldClient->updateBalance(max(0, $oldGrandTotal - $oldPaidAmount), 'subtract');
                }
            }
            if ($sale->client_id && $sale->client) {
                $sale->client->updateBalance(max(0, $sale->grand_total - $newPaidAmount), 'add');
            }

            // Record caisse transaction for the payment delta (only if same client / no client change)
            $paidDelta = $newPaidAmount - $oldPaidAmount;
            if ($paidDelta != 0 && $oldClientId === $sale->client_id) {
                $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        $paidDelta > 0 ? 'in' : 'out',
                        abs($paidDelta),
                        Sale::class,
                        $sale->id,
                        ($paidDelta > 0 ? 'دفعة إضافية على فاتورة بيع ' : 'استرجاع دفعة فاتورة بيع ') . $sale->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();
            return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'خطأ في تعديل الفاتورة: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Sale $sale)
    {
        // Draft sales can be deleted freely (no stock was deducted)
        if ($sale->status === 'draft') {
            $sale->items()->forceDelete();
            $sale->forceDelete();
            return response()->json(['message' => 'تم حذف المسودة بنجاح']);
        }

        DB::beginTransaction();

        try {
            // 1. Delete associated returns and reverse their stock
            foreach ($sale->returns as $return) {
                foreach ($return->items as $returnItem) {
                    StockMovement::record(
                        $returnItem->product_id,
                        $sale->warehouse_id,
                        $returnItem->quantity,
                        StockMovement::TYPE_SALE,
                        $sale->reference . '-RET-CANCEL',
                        $sale,
                        $returnItem->unit_price ?? 0,
                        'إلغاء مرتجع مرتبط بفاتورة محذوفة'
                    );
                }
                $return->items()->delete();
                $return->delete();
            }

            // 2. Delete associated payments and reverse caisse
            foreach ($sale->payments as $payment) {
                $caisse = Caisse::where('user_id', $payment->user_id)->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'out',
                        $payment->amount,
                        Sale::class,
                        $sale->id,
                        'إلغاء دفعة - فاتورة ' . $sale->reference,
                        auth()->id()
                    );
                }
                $payment->delete();
            }

            // 3. Reverse stock movements (return items to warehouse)
            foreach ($sale->items as $item) {
                StockMovement::record(
                    $item->product_id,
                    $sale->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_SALE_RETURN,
                    $sale->reference . '-CANCEL',
                    $sale,
                    $item->unit_price,
                    'إلغاء فاتورة'
                );
            }

            // 4. Reverse client balance
            if ($sale->client_id) {
                $client = $sale->client;
                $client->updateBalance($sale->grand_total, 'subtract');
                if ($sale->paid_amount > 0) {
                    $client->updateBalance($sale->paid_amount, 'add');
                }
            }

            // 5. Mark as cancelled and soft delete
            $sale->status = 'cancelled';
            $sale->save();
            $sale->delete();

            DB::commit();

            return response()->json(['message' => 'تم إلغاء الفاتورة وإرجاع المخزون بنجاح']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء الإلغاء: ' . $e->getMessage()], 500);
        }
    }

    public function createReturn(Request $request, Sale $sale)
    {
        // Only completed sales can have returns
        if ($sale->status !== 'completed') {
            return response()->json(['message' => 'لا يمكن إرجاع فاتورة غير مكتملة'], 400);
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
            $return = SaleReturn::create([
                'sale_id' => $sale->id,
                'client_id' => $sale->client_id,
                'warehouse_id' => $sale->warehouse_id,
                'user_id' => auth()->id(),
                'date' => now(),
                'note' => $request->note,
                'status' => 'approved',
            ]);

            foreach ($request->items as $item) {
                $saleItem = $sale->items()->where('product_id', $item['product_id'])->first();
                if (!$saleItem) {
                    throw new \Exception('المنتج غير موجود في المبيعات');
                }

                // Validate return quantity doesn't exceed sold quantity minus already returned
                $alreadyReturned = SaleReturnItem::whereHas('saleReturn', function ($q) use ($sale) {
                    $q->where('sale_id', $sale->id);
                })->where('product_id', $item['product_id'])->sum('quantity');

                $maxReturnable = $saleItem->quantity - $alreadyReturned;
                if ($item['quantity'] > $maxReturnable) {
                    throw new \Exception("الكمية المرتجعة ({$item['quantity']}) أكبر من المتاح للإرجاع ({$maxReturnable})");
                }

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $saleItem->unit_price,
                    'reason' => $item['reason'] ?? null,
                ]);

                // Record stock movement (return to warehouse)
                StockMovement::record(
                    $item['product_id'],
                    $sale->warehouse_id,
                    $item['quantity'],
                    StockMovement::TYPE_SALE_RETURN,
                    $return->reference,
                    $return,
                    $saleItem->unit_price,
                    $item['reason'] ?? null
                );
            }

            $return->calculateTotals();

            if ($sale->client_id) {
                $sale->client->updateBalance($return->total_amount, 'subtract');
            }

            // Adjust the sale's due_amount
            $sale->due_amount = max(0, $sale->due_amount - $return->total_amount);
            $sale->payment_status = $sale->calculatePaymentStatus();
            $sale->save();

            // Record caisse transaction (money going OUT — refund to client)
            if ($return->total_amount > 0) {
                $caisse = Caisse::where('user_id', auth()->id())->where('is_active', true)->first();
                if ($caisse) {
                    $caisse->addTransaction(
                        'out',
                        $return->total_amount,
                        \App\Models\SaleReturn::class,
                        $return->id,
                        'مرتجع بيع - فاتورة ' . $sale->reference,
                        auth()->id()
                    );
                }
            }

            DB::commit();

            return response()->json($return->load(['client', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate Facture PDF (Invoice)
     */
    public function generateFacturePdf(Sale $sale)
    {
        $sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit', 'payments']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.facture', compact('sale', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->download("facture-{$sale->reference}.pdf");
    }

    /**
     * Stream Facture PDF (for printing)
     */
    public function streamFacturePdf(Sale $sale)
    {
        $sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit', 'payments']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.facture', compact('sale', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->stream("facture-{$sale->reference}.pdf");
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
            'company_rib' => \App\Models\Setting::get('company_rib', ''),
            'company_logo' => \App\Models\Setting::get('company_logo'),
        ];
    }

    /**
     * Generate Bon de Livraison PDF (Delivery Note)
     */
    public function generateBonLivraisonPdf(Sale $sale)
    {
        $sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-livraison', compact('sale', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->download("bon-livraison-{$sale->reference}.pdf");
    }

    /**
     * Stream Bon de Livraison PDF (for printing)
     */
    public function streamBonLivraisonPdf(Sale $sale)
    {
        $sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit']);
        $settings = $this->getCompanySettings();

        $pdf = Pdf::loadView('pdf.bon-livraison', compact('sale', 'settings'));
        $pdf->setPaper('a4');

        return $pdf->stream("bon-livraison-{$sale->reference}.pdf");
    }

    /**
     * Convert stock from cartons to pieces for legacy data.
     * If stock was stored as cartons (old purchases), multiply by pieces_per_package.
     */
    private function convertStockToPieces($productId, $warehouseId, $ppp)
    {
        $stock = Stock::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($stock && $ppp > 1) {
            $oldQty = $stock->quantity;
            $newQty = $oldQty * $ppp;
            $stock->quantity = $newQty;
            $stock->save();

            // Record an adjustment movement for the conversion
            StockMovement::record(
                $productId,
                $warehouseId,
                $newQty - $oldQty,
                StockMovement::TYPE_ADJUSTMENT,
                'CONV-' . now()->format('YmdHis'),
                null,
                null,
                "تحويل المخزون من كراتين إلى قطع ({$oldQty} كرتون × {$ppp} = {$newQty} قطعة)"
            );
        }
    }
}
