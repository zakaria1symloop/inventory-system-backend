<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['client', 'warehouse', 'user']);

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
            'note' => 'nullable|string',
            'paid_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Validate stock availability for all items first (considering reserved quantities from orders)
            foreach ($request->items as $item) {
                $product = \App\Models\Product::find($item['product_id']);
                $productName = $product ? $product->name : 'غير معروف';

                // Use getAvailableStock to check stock minus reserved quantities
                $availableQty = Stock::getAvailableStock($item['product_id'], $request->warehouse_id);

                if ($availableQty < $item['quantity']) {
                    throw new \Exception("الكمية غير متوفرة للمنتج: {$productName}. المتوفر: {$availableQty}، المطلوب: {$item['quantity']}");
                }
            }

            $sale = Sale::create([
                'client_id' => $request->client_id,
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
                'discount' => $request->discount ?? 0,
                'tax' => $request->tax ?? 0,
                'shipping' => $request->shipping ?? 0,
                'note' => $request->note,
                'status' => 'completed',
            ]);

            foreach ($request->items as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                // Record stock movement
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

            $sale->calculateTotals();

            // Handle payment if provided
            $paidAmount = $request->paid_amount ?? 0;

            if ($request->client_id) {
                $client = $sale->client;

                // Add the sale amount to client balance (debt)
                $client->updateBalance($sale->grand_total, 'add');

                if ($paidAmount > 0) {
                    // Calculate how much goes to this sale vs previous debt
                    $appliedToSale = min($paidAmount, $sale->grand_total);
                    $appliedToPreviousDebt = max(0, $paidAmount - $sale->grand_total);

                    // Create payment for this sale
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

                        // Reduce client balance by amount paid
                        $client->updateBalance($appliedToSale, 'subtract');
                    }

                    // Apply extra to previous unpaid sales (FIFO - oldest first)
                    if ($appliedToPreviousDebt > 0) {
                        $remainingExtra = $appliedToPreviousDebt;

                        // Get unpaid sales for this client (oldest first)
                        $unpaidSales = Sale::where('client_id', $client->id)
                            ->where('id', '!=', $sale->id)
                            ->where('due_amount', '>', 0)
                            ->orderBy('date', 'asc')
                            ->orderBy('id', 'asc')
                            ->get();

                        foreach ($unpaidSales as $oldSale) {
                            if ($remainingExtra <= 0) break;

                            $applyToThis = min($remainingExtra, $oldSale->due_amount);

                            // Create payment for this old sale
                            $oldSale->payments()->create([
                                'reference' => 'PAY-' . strtoupper(uniqid()),
                                'amount' => $applyToThis,
                                'payment_method' => 'cash',
                                'date' => $request->date,
                                'notes' => 'تسديد من فاتورة ' . $sale->reference,
                                'user_id' => auth()->id(),
                            ]);

                            // Update old sale
                            $oldSale->paid_amount += $applyToThis;
                            $oldSale->due_amount -= $applyToThis;

                            if ($oldSale->due_amount <= 0) {
                                $oldSale->payment_status = 'paid';
                                $oldSale->due_amount = 0;
                            } else {
                                $oldSale->payment_status = 'partial';
                            }

                            $oldSale->save();

                            // Reduce client balance
                            $client->updateBalance($applyToThis, 'subtract');

                            $remainingExtra -= $applyToThis;
                        }

                        // If there's still remaining (overpayment beyond all debt), just reduce balance
                        if ($remainingExtra > 0) {
                            $client->updateBalance($remainingExtra, 'subtract');
                        }
                    }
                }
            } else {
                // No client (cash sale) - handle payment for the sale only
                if ($paidAmount > 0 && $paidAmount <= $sale->grand_total) {
                    $sale->paid_amount = $paidAmount;
                    $sale->due_amount = $sale->grand_total - $paidAmount;

                    if ($sale->due_amount <= 0) {
                        $sale->payment_status = 'paid';
                        $sale->due_amount = 0;
                    } else {
                        $sale->payment_status = 'partial';
                    }

                    $sale->save();
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

    public function show(Sale $sale)
    {
        return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product.unitSale.baseUnit', 'items.product.unitBuy.baseUnit', 'payments', 'returns']));
    }

    public function update(Request $request, Sale $sale)
    {
        $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $sale->update($request->only(['discount', 'tax', 'shipping', 'note']));
        $sale->calculateTotals();

        return response()->json($sale->load(['client', 'warehouse', 'user', 'items.product']));
    }

    public function destroy(Sale $sale)
    {
        // Security checks for traceability
        if ($sale->payment_status === 'paid') {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة مدفوعة. استخدم المرتجعات للحفاظ على التتبع المحاسبي'
            ], 400);
        }

        if ($sale->payment_status === 'partial') {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها دفعات. استخدم المرتجعات للحفاظ على التتبع المحاسبي'
            ], 400);
        }

        // Check if has any payments
        if ($sale->payments()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها دفعات مسجلة. استخدم المرتجعات بدلاً من ذلك'
            ], 400);
        }

        // Check if has any returns
        if ($sale->returns()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف فاتورة بها مرتجعات. هذه الفاتورة مرتبطة بعمليات أخرى'
            ], 400);
        }

        DB::beginTransaction();

        try {
            foreach ($sale->items as $item) {
                // Record stock movement for cancellation
                StockMovement::record(
                    $item->product_id,
                    $sale->warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_SALE_RETURN,
                    $sale->reference . '-CANCEL',
                    $sale,
                    $item->unit_price,
                    'إلغاء مبيعات'
                );
            }

            if ($sale->client_id) {
                $sale->client->updateBalance($sale->grand_total, 'subtract');
            }

            $sale->delete();

            DB::commit();

            return response()->json(['message' => 'تم حذف المبيعات بنجاح']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء الحذف'], 500);
        }
    }

    public function createReturn(Request $request, Sale $sale)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
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

                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $saleItem->unit_price,
                    'reason' => $item['reason'] ?? null,
                ]);

                // Record stock movement
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
}
