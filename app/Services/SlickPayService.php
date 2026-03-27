<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlickPayService
{
    protected string $baseUrl;
    protected string $publicKey;
    protected string $secretKey;

    public function __construct()
    {
        $sandbox = config('slickpay.sandbox', false);
        $this->publicKey = config('slickpay.public_key', '');
        $this->secretKey = config('slickpay.secret_key', '');
        $this->baseUrl = $sandbox
            ? 'https://devapi.slick-pay.com/api/v2'
            : 'https://prodapi.slick-pay.com/api/v2';
    }

    public function isConfigured(): bool
    {
        return !empty($this->publicKey);
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        try {
            $http = Http::withoutVerifying()->withHeaders([
                'Authorization' => 'Bearer ' . $this->publicKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $data),
                'POST' => $http->post($url, $data),
                default => throw new \Exception("Unsupported HTTP method: {$method}"),
            };

            return [
                'success' => $response->successful(),
                'data' => $response->json(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('SlickPay API Error', ['error' => $e->getMessage(), 'url' => $url]);
            return ['success' => false, 'data' => null, 'status' => 500];
        }
    }

    /**
     * Create an invoice (payment).
     * Returns { success, id, url, message } on success.
     */
    public function createInvoice(array $params): array
    {
        $payload = [
            'amount' => $params['amount'],
            'items' => $params['items'] ?? [
                [
                    'name' => $params['description'] ?? 'Subscription',
                    'price' => $params['amount'],
                    'quantity' => 1,
                ],
            ],
            'url' => $params['return_url'] ?? null,
            'webhook_url' => $params['webhook_url'] ?? null,
            'webhook_signature' => $this->secretKey,
            'webhook_meta_data' => $params['metadata'] ?? [],
            'note' => $params['description'] ?? '',
            'qrcode' => true,
        ];

        // Contact info (required if no contact UUID)
        if (!empty($params['firstname'])) {
            $payload['firstname'] = $params['firstname'];
            $payload['lastname'] = $params['lastname'] ?? '';
            $payload['email'] = $params['email'] ?? '';
            $payload['phone'] = $params['phone'] ?? '';
            $payload['address'] = $params['address'] ?? '';
        }

        return $this->request('POST', 'users/invoices', $payload);
    }

    /**
     * Get invoice details (check status).
     */
    public function getInvoice(int $invoiceId): array
    {
        return $this->request('GET', "users/invoices/{$invoiceId}");
    }

    /**
     * Validate webhook signature using the secret key.
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        return hash_equals($this->secretKey, $signature);
    }
}
