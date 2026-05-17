<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaasPayment;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Services\SlickPayService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SaasPaymentController extends Controller
{
    protected SlickPayService $slickpay;

    public function __construct(SlickPayService $slickpay)
    {
        $this->slickpay = $slickpay;
    }

    /**
     * Initiate plan upgrade payment.
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            'plan' => 'required|string|in:pro,pro_ai,enterprise',
        ]);

        $tenant = $request->attributes->get('tenant');
        if (!$tenant) {
            return response()->json(['message' => 'المستأجر غير موجود'], 404);
        }

        $newPlan = $request->plan;
        $prices = Tenant::planPrices();
        $currentPrice = $prices[$tenant->plan] ?? 0;
        $newPrice = $prices[$newPlan] ?? 0;

        if ($newPrice <= $currentPrice) {
            return response()->json(['message' => 'لا يمكنك الترقية إلى خطة أقل أو مساوية'], 422);
        }

        if (!$this->slickpay->isConfigured()) {
            return response()->json(['message' => 'بوابة الدفع غير مفعّلة حالياً'], 503);
        }

        $amount = $newPrice;

        // Create payment record
        TenantService::switchToMaster();
        $payment = SaasPayment::create([
            'tenant_id' => $tenant->id,
            'plan_from' => $tenant->plan,
            'plan_to' => $newPlan,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $planNames = [
            'pro' => 'المحترف',
            'pro_ai' => 'المحترف + ذكاء اصطناعي',
            'enterprise' => 'المؤسسات',
        ];

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3001'));
        $backendUrl = config('app.url', env('APP_URL', 'http://localhost:8000'));

        $user = $request->user();

        $result = $this->slickpay->createInvoice([
            'amount' => $amount,
            'return_url' => "{$frontendUrl}/dashboard?payment=pending&plan={$newPlan}&payment_id={$payment->id}",
            'webhook_url' => "{$backendUrl}/api/saas/payments/webhook",
            'description' => "ترقية إلى خطة {$planNames[$newPlan]} — تراكسيرا",
            'firstname' => $user->name ?? $tenant->name ?? 'Client',
            'lastname' => $tenant->name ?? '',
            'email' => $user->email ?? '',
            'address' => 'Algeria',
            'metadata' => [
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
                'plan_from' => $tenant->plan,
                'plan_to' => $newPlan,
            ],
        ]);

        if (!$result['success']) {
            Log::error('SlickPay invoice creation failed', ['result' => $result]);
            $payment->update(['status' => 'failed']);
            $gatewayMessage = $result['data']['message']
                ?? $result['data']['error']
                ?? (is_array($result['data']) ? json_encode($result['data']) : null);
            return response()->json([
                'message' => 'فشل في إنشاء جلسة الدفع. حاول مرة أخرى.',
                'gateway_error' => $gatewayMessage,
                'gateway_status' => $result['status'] ?? null,
            ], 500);
        }

        // SlickPay wraps the invoice payload under `data` — unwrap if present
        $raw = $result['data'];
        $invoiceData = is_array($raw) && isset($raw['data']) ? $raw['data'] : $raw;
        $checkoutUrl = $invoiceData['url'] ?? $raw['url'] ?? null;
        $invoiceId = $invoiceData['id'] ?? $raw['id'] ?? null;

        $payment->update([
            'gateway_invoice_id' => $invoiceId,
            'gateway_checkout_url' => $checkoutUrl,
        ]);

        return response()->json([
            'payment_id' => $payment->id,
            'checkout_url' => $checkoutUrl,
            'amount' => $amount,
            'plan' => $newPlan,
        ]);
    }

    /**
     * Check payment status.
     */
    public function status(Request $request, $paymentId)
    {
        $tenant = $request->attributes->get('tenant');
        TenantService::switchToMaster();

        $payment = SaasPayment::where('id', $paymentId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'الدفعة غير موجودة'], 404);
        }

        // If still pending, check with SlickPay
        if ($payment->status === 'pending' && $payment->gateway_invoice_id) {
            $result = $this->slickpay->getInvoice((int) $payment->gateway_invoice_id);
            if ($result['success']) {
                $data = $result['data']['data'] ?? $result['data'] ?? [];
                $completed = $data['completed'] ?? null;
                if ($completed == 1) {
                    $this->activatePlan($payment);
                }
            }
        }

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'plan_to' => $payment->plan_to,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at?->toISOString(),
        ]);
    }

    /**
     * SlickPay webhook handler (public, no auth).
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Slickpay-Signature')
            ?? $request->header('signature')
            ?? '';

        // Validate signature if secret key is set
        $secretKey = config('slickpay.secret_key');
        if ($secretKey && $signature && !$this->slickpay->validateWebhookSignature($payload, $signature)) {
            Log::warning('SlickPay webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $body = json_decode($payload, true);
        $invoiceId = $body['id'] ?? null;
        $completed = $body['completed'] ?? null;
        $metadata = $body['webhook_meta_data'] ?? $body['metadata'] ?? [];

        Log::info('SlickPay webhook received', [
            'invoice_id' => $invoiceId,
            'completed' => $completed,
            'metadata' => $metadata,
        ]);

        TenantService::switchToMaster();
        $payment = null;

        if (!empty($metadata['payment_id'])) {
            $payment = SaasPayment::find($metadata['payment_id']);
        }

        if (!$payment && $invoiceId) {
            $payment = SaasPayment::where('gateway_invoice_id', $invoiceId)->first();
        }

        if (!$payment) {
            Log::warning('SlickPay webhook: payment not found', ['invoice_id' => $invoiceId, 'metadata' => $metadata]);
            return response()->json(['message' => 'Payment not found'], 200);
        }

        $payment->update(['gateway_response' => $body]);

        if ($completed == 1 && $payment->status !== 'paid') {
            $this->activatePlan($payment);
            Log::info("Plan upgraded for tenant {$payment->tenant_id}: {$payment->plan_from} → {$payment->plan_to}");
        } elseif ($completed === 0 || (isset($body['status']) && in_array($body['status'], ['failed', 'canceled', 'expired']))) {
            $payment->update(['status' => 'failed']);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    /**
     * Payment history for the tenant.
     */
    public function history(Request $request)
    {
        $tenant = $request->attributes->get('tenant');
        TenantService::switchToMaster();

        $payments = SaasPayment::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'plan_from', 'plan_to', 'amount', 'status', 'paid_at', 'created_at']);

        return response()->json($payments);
    }

    /**
     * Activate the new plan after successful payment.
     */
    protected function activatePlan(SaasPayment $payment): void
    {
        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $tenant = Tenant::find($payment->tenant_id);
        if (!$tenant) return;

        $limits = Tenant::planLimits();
        $planLimits = $limits[$payment->plan_to] ?? $limits['free'];

        $tenant->update([
            'plan' => $payment->plan_to,
            'product_limit' => $planLimits['products'],
            'user_limit' => $planLimits['users'],
        ]);

        // Create subscription record
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => $payment->plan_to,
            'amount' => $payment->amount,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }
}
