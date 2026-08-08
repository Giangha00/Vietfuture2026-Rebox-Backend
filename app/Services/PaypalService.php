<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaypalService
{
    public function isConfigured(): bool
    {
        return (bool) (config('rebox.paypal.client_id') && config('rebox.paypal.client_secret'));
    }

    public function mode(): string
    {
        return config('rebox.paypal.mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function baseUrl(): string
    {
        return $this->mode() === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function getAccessToken(): ?string
    {
        return Cache::remember('paypal_access_token', 300, function () {
            $response = Http::asForm()
                ->withBasicAuth(
                    (string) config('rebox.paypal.client_id'),
                    (string) config('rebox.paypal.client_secret')
                )
                ->post($this->baseUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                Log::warning('PayPal token failed', ['body' => $response->body()]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    public function createOrder(float $amount, string $currency, string $returnUrl, string $cancelUrl, string $customId = ''): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            throw new \RuntimeException('PayPal is not available.');
        }

        $response = Http::withToken($token)
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                    'custom_id' => $customId,
                ]],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal create order failed: '.$response->body());
        }

        $data = $response->json();
        $approveUrl = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return [
            'id' => $data['id'] ?? null,
            'approveUrl' => $approveUrl,
            'raw' => $data,
        ];
    }

    public function captureOrder(string $paypalOrderId): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            throw new \RuntimeException('PayPal is not available.');
        }

        $response = Http::withToken($token)
            ->withBody('{}', 'application/json')
            ->post($this->baseUrl().'/v2/checkout/orders/'.$paypalOrderId.'/capture');

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal capture failed: '.$response->body());
        }

        $data = $response->json();
        $captureId = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';

        return [
            'id' => $data['id'] ?? $paypalOrderId,
            'captureId' => $captureId,
            'status' => $data['status'] ?? '',
            'raw' => $data,
        ];
    }
}
