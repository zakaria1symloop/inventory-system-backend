<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['user', 'payable' => function ($morphTo) {
            $morphTo->morphWith([
                \App\Models\Sale::class => ['client'],
                \App\Models\Purchase::class => ['supplier'],
            ]);
        }]);

        if ($request->payable_type) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->payment_method) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->source) {
            $source = $request->source;
            $query->whereHasMorph('payable', [Sale::class], fn($q) => $q->where('source', $source));
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHasMorph('payable', [Sale::class], function ($q2) use ($search) {
                      $q2->whereHas('client', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
                  })
                  ->orWhereHasMorph('payable', [Purchase::class], function ($q2) use ($search) {
                      $q2->whereHas('supplier', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
                  });
            });
        }

        $payments = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($payments);
    }

    public function storePurchasePayment(Request $request, Purchase $purchase)
    {
        if ($purchase->status !== 'received') {
            return response()->json(['message' => 'لا يمكن الدفع لفاتورة غير مؤكدة'], 400);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $requestAmount = (float) $request->amount;
        $dueAmount = (float) $purchase->due_amount;

        if ($requestAmount > $dueAmount + 0.01) {
            return response()->json(['message' => 'المبلغ أكبر من المستحق'], 400);
        }

        DB::beginTransaction();

        try {
            $payment = Payment::create([
                'payable_type' => Purchase::class,
                'payable_id' => $purchase->id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'date' => $request->date,
                'notes' => $request->notes,
                'user_id' => auth()->id(),
            ]);

            $purchase->paid_amount += $request->amount;
            $purchase->due_amount = $purchase->grand_total - $purchase->paid_amount;
            $purchase->payment_status = $purchase->calculatePaymentStatus();
            $purchase->save();

            if ($purchase->supplier_id) {
                $purchase->supplier->updateBalance($request->amount, 'subtract');
            }

            // Record caisse transaction (money going OUT to pay supplier)
            $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'out',
                    $request->amount,
                    Purchase::class,
                    $purchase->id,
                    'دفعة لفاتورة شراء ' . $purchase->reference,
                    auth()->id()
                );
            }

            DB::commit();

            return response()->json($payment->load(['user', 'payable']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Purchase payment failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'فشل تسجيل الدفعة: ' . $e->getMessage()], 500);
        }
    }

    public function storeSalePayment(Request $request, Sale $sale)
    {
        if ($sale->status === 'draft') {
            return response()->json(['message' => 'لا يمكن الدفع لفاتورة مسودة'], 400);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $requestAmount = (float) $request->amount;
        $dueAmount = (float) $sale->due_amount;

        if ($requestAmount > $dueAmount + 0.01) {
            return response()->json(['message' => 'المبلغ أكبر من المستحق'], 400);
        }

        DB::beginTransaction();

        try {
            $payment = Payment::create([
                'payable_type' => Sale::class,
                'payable_id' => $sale->id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'date' => $request->date,
                'notes' => $request->notes,
                'user_id' => auth()->id(),
            ]);

            $sale->paid_amount += $request->amount;
            $sale->due_amount = $sale->grand_total - $sale->paid_amount;
            $sale->payment_status = $sale->calculatePaymentStatus();
            $sale->save();

            if ($sale->client_id) {
                $sale->client->updateBalance($request->amount, 'subtract');
            }

            // Record caisse transaction (money coming IN from sale payment)
            $caisse = Caisse::where('user_id', auth()->id())->where('is_active', true)->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'in',
                    $request->amount,
                    Sale::class,
                    $sale->id,
                    'تحصيل من فاتورة ' . $sale->reference,
                    auth()->id()
                );
            }

            DB::commit();

            return response()->json($payment->load(['user', 'payable']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Sale payment failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'فشل تسجيل الدفعة: ' . $e->getMessage()], 500);
        }
    }

    public function storeClientPayment(Request $request, Client $client)
    {
        if (!auth()->user()->can_collect_debt && auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'ليس لديك صلاحية تحصيل الديون'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // Calculate actual combined debt (sales + delivery)
        $salesDebt = (float) Sale::where('client_id', $client->id)
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->sum('due_amount');
        $deliveryDebt = (float) \App\Models\DeliveryOrder::where('client_id', $client->id)
            ->whereIn('status', ['delivered', 'partial'])
            ->whereRaw('amount_due > amount_collected')
            ->selectRaw('SUM(amount_due - amount_collected) as total')
            ->value('total') ?? 0;
        $actualDebt = $salesDebt + $deliveryDebt;

        if ($request->amount > $actualDebt && $actualDebt > 0) {
            return response()->json(['message' => 'المبلغ أكبر من الدين الحالي (' . number_format($actualDebt, 0) . ' د.ج)'], 400);
        }

        DB::beginTransaction();

        try {
            // Create payment record (without payable since it's a general client payment)
            $payment = Payment::create([
                'payable_type' => null,
                'payable_id' => null,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method ?? 'cash',
                'date' => now(),
                'notes' => $request->notes ? "دفع دين للعميل: {$client->name} - {$request->notes}" : "دفع دين للعميل: {$client->name}",
                'user_id' => auth()->id(),
            ]);

            // Update client balance
            $client->updateBalance($request->amount, 'subtract');

            // Allocate payment to individual unpaid sales (oldest first)
            $remainingAmount = (float) $request->amount;
            $unpaidSales = Sale::where('client_id', $client->id)
                ->where('status', 'completed')
                ->where('due_amount', '>', 0)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            foreach ($unpaidSales as $sale) {
                if ($remainingAmount <= 0) break;

                $allocated = min($remainingAmount, (float) $sale->due_amount);
                $sale->paid_amount += $allocated;
                $sale->due_amount = $sale->grand_total - $sale->paid_amount;
                $sale->payment_status = $sale->calculatePaymentStatus();
                $sale->save();

                $remainingAmount -= $allocated;
            }

            // Record caisse transaction
            $caisse = Caisse::where('user_id', auth()->id())->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'in',
                    $request->amount,
                    'payment',
                    $payment->id,
                    "تحصيل دين عميل {$client->name}",
                    auth()->id()
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'تم تسجيل الدفعة بنجاح',
                'payment' => $payment,
                'new_balance' => $client->fresh()->balance
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Payment $payment)
    {
        return response()->json($payment->load(['user', 'payable']));
    }

    public function destroy(Payment $payment)
    {
        // Security: Only admin can delete payments for traceability
        if (auth()->user()->role !== 'admin') {
            return response()->json([
                'message' => 'فقط المدير يمكنه حذف الدفعات للحفاظ على التتبع المحاسبي'
            ], 403);
        }

        $payable = $payment->payable;

        if ($payable instanceof Purchase) {
            $payable->paid_amount -= $payment->amount;
            $payable->due_amount = $payable->grand_total - $payable->paid_amount;
            $payable->payment_status = $payable->calculatePaymentStatus();
            $payable->save();

            if ($payable->supplier_id) {
                $payable->supplier->updateBalance($payment->amount, 'add');
            }

            // Reverse caisse (money comes back IN)
            $caisse = Caisse::where('type', 'principale')->where('is_active', true)->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'in',
                    $payment->amount,
                    Purchase::class,
                    $payable->id,
                    'إلغاء دفعة لفاتورة شراء ' . $payable->reference,
                    auth()->id()
                );
            }
        } elseif ($payable instanceof Sale) {
            $payable->paid_amount -= $payment->amount;
            $payable->due_amount = $payable->grand_total - $payable->paid_amount;
            $payable->payment_status = $payable->calculatePaymentStatus();
            $payable->save();

            if ($payable->client_id) {
                $payable->client->updateBalance($payment->amount, 'add');
            }

            // Reverse caisse (money goes back OUT)
            $caisse = Caisse::where('user_id', $payable->user_id)->where('is_active', true)->first();
            if ($caisse) {
                $caisse->addTransaction(
                    'out',
                    $payment->amount,
                    Sale::class,
                    $payable->id,
                    'إلغاء دفعة لفاتورة ' . $payable->reference,
                    auth()->id()
                );
            }
        }

        $payment->delete();

        return response()->json(['message' => 'تم حذف الدفعة بنجاح']);
    }
}
