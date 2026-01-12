<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['user', 'payable']);

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

        $payments = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($payments);
    }

    public function storePurchasePayment(Request $request, Purchase $purchase)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        if ($request->amount > $purchase->due_amount) {
            return response()->json(['message' => 'المبلغ أكبر من المستحق'], 400);
        }

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

        return response()->json($payment->load(['user', 'payable']), 201);
    }

    public function storeSalePayment(Request $request, Sale $sale)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        if ($request->amount > $sale->due_amount) {
            return response()->json(['message' => 'المبلغ أكبر من المستحق'], 400);
        }

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

        return response()->json($payment->load(['user', 'payable']), 201);
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
        } elseif ($payable instanceof Sale) {
            $payable->paid_amount -= $payment->amount;
            $payable->due_amount = $payable->grand_total - $payable->paid_amount;
            $payable->payment_status = $payable->calculatePaymentStatus();
            $payable->save();

            if ($payable->client_id) {
                $payable->client->updateBalance($payment->amount, 'add');
            }
        }

        $payment->delete();

        return response()->json(['message' => 'تم حذف الدفعة بنجاح']);
    }
}
