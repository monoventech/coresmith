<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Stripe\Stripe;

final class FakeStripe
{
    public const SECRET = 'sk_test_coresmith_fake_key';

    public const WEBHOOK_SECRET = 'whsec_coresmith_fake_secret';

    public static function fake(array $responses = []): void
    {
        Stripe::setMaxNetworkRetries(0);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    public static function url(string $path): string
    {
        return 'api.stripe.com/v1/' . ltrim($path, '/');
    }

    public static function customer(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'customer',
            'email' => null,
            'name' => null,
            'invoice_settings' => ['default_payment_method' => null],
            'metadata' => [],
        ], $overrides);
    }

    public static function paymentMethod(string $id, string $brand = 'visa', string $last4 = '4242', int $month = 8, int $year = 2028): array
    {
        return [
            'id' => $id,
            'object' => 'payment_method',
            'type' => 'card',
            'customer' => null,
            'card' => ['brand' => $brand, 'last4' => $last4, 'exp_month' => $month, 'exp_year' => $year],
        ];
    }

    public static function subscription(string $id, string $customer, string $price, string $status = 'active', array $overrides = []): array
    {
        $start = now()->getTimestamp();

        return array_replace_recursive([
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'status' => $status,
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'trial_end' => null,
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_' . Str::lower(Str::random(14)),
                    'object' => 'subscription_item',
                    'price' => [
                        'id' => $price,
                        'object' => 'price',
                        'product' => 'prod_' . Str::after($price, 'price_'),
                        'recurring' => ['interval' => 'month', 'usage_type' => 'licensed'],
                    ],
                    'quantity' => 1,
                    'current_period_start' => $start,
                    'current_period_end' => now()->addDays(30)->getTimestamp(),
                ]],
            ],
        ], $overrides);
    }

    public static function checkoutSession(string $id, string $url, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'checkout.session',
            'url' => $url,
            'status' => 'open',
            'mode' => 'subscription',
            'customer' => null,
            'subscription' => null,
            'metadata' => [],
        ], $overrides);
    }

    public static function portalSession(string $url): array
    {
        return ['id' => 'bps_' . Str::lower(Str::random(14)), 'object' => 'billing_portal.session', 'url' => $url];
    }

    public static function invoice(string $id, string $customer, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'invoice',
            'customer' => $customer,
            'number' => null,
            'status' => 'paid',
            'total' => 1900,
            'currency' => 'usd',
            'period_start' => now()->getTimestamp(),
            'period_end' => now()->addDays(30)->getTimestamp(),
            'attempt_count' => 1,
            'next_payment_attempt' => null,
            'hosted_invoice_url' => null,
            'invoice_pdf' => null,
            'status_transitions' => ['paid_at' => now()->getTimestamp()],
            'created' => now()->getTimestamp(),
        ], $overrides);
    }

    public static function event(string $type, array $object, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'evt_' . Str::lower(Str::random(14)),
            'object' => 'event',
            'type' => $type,
            'created' => now()->getTimestamp(),
            'data' => ['object' => $object],
        ], $overrides);
    }

    public static function signedHeaders(string $payload, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'Stripe-Signature' => "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$payload}", $secret),
            'Content-Type' => 'application/json',
        ];
    }

    public static function error(string $type, string $message, int $status, ?string $code = null): PromiseInterface
    {
        return Http::response(['error' => array_filter(['type' => $type, 'message' => $message, 'code' => $code], fn(?string $value): bool => $value !== null)], $status);
    }
}
