<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Plan checkout through Stripe Checkout or PayPal Orders (REST APIs, no SDK needed).
 *
 * Keys come from Admin → Settings → Payments (stored encrypted) or the STRIPE_SECRET /
 * PAYPAL_CLIENT_ID / PAYPAL_SECRET env vars. The buyer is redirected to the gateway and the
 * plan is applied only after the gateway confirms the payment on return (or via webhook).
 */
class Payments
{
    public static function stripeKey(): ?string
    {
        return Setting::value('set_payments_stripe_secret_key') ?: env('STRIPE_SECRET');
    }

    /** @return array{0: ?string, 1: ?string, 2: string} client id, secret, API base */
    public static function paypal(): array
    {
        $live = (Setting::value('set_payments_paypal_mode') ?: env('PAYPAL_MODE', 'live')) !== 'Sandbox' && env('PAYPAL_MODE') !== 'sandbox';

        return [Setting::value('set_payments_paypal_client_id') ?: env('PAYPAL_CLIENT_ID'), Setting::value('set_payments_paypal_secret') ?: env('PAYPAL_SECRET'),
            $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com'];
    }

    public static function currency(): string
    {
        return strtoupper(Setting::value('set_payments_currency') ?: 'USD');
    }

    /** Which gateways are usable right now (for the admin screen and checkout). */
    public static function status(): array
    {
        $enabled = (array) Setting::get('gateways', ['stripe' => true, 'paypal' => true]);
        [$ppId, $ppSecret] = static::paypal();

        return [
            'stripe' => ($enabled['stripe'] ?? true) && static::stripeKey(),
            'paypal' => ($enabled['paypal'] ?? true) && $ppId && $ppSecret,
            'testMode' => static::testModeAllowed(),
        ];
    }

    /** Without real keys, only local/debug installs may simulate payments. */
    public static function testModeAllowed(): bool
    {
        $forced = config('services.payments.test_mode');

        return $forced !== null ? (bool) $forced : (app()->environment('local', 'testing') || config('app.debug'));
    }

    /**
     * Start a checkout. Returns ['redirect' => url] for a real gateway, or ['paid' => true] in test mode.
     */
    public function start(User $user, Plan $plan, string $cycle, string $method): array
    {
        $amount = $cycle === 'yearly' ? round($plan->price * 12 * 0.8, 2) : (float) $plan->price;
        $status = static::status();
        $gateway = $method === 'PayPal' ? 'paypal' : 'stripe';

        if (! $status[$gateway]) {
            $other = $gateway === 'stripe' ? 'paypal' : 'stripe';
            if ($status[$other]) {
                $gateway = $other;
            } elseif ($status['testMode']) {
                $payment = $this->record($user, $plan, $cycle, 'Test mode', $amount, 'test_'.Str::lower(Str::random(10)));
                $this->fulfil($payment);

                return ['paid' => true];
            } else {
                abort(422, 'Online payments are not set up yet. Please contact support.');
            }
        }

        $ref = 'pay_'.Str::lower(Str::random(12));
        $return = url('/checkout/return?gateway='.$gateway.'&ref='.$ref);
        $cancel = url('/checkout?plan='.$plan->slug.'&cancelled=1');
        $label = $plan->name.' plan · '.$cycle;

        if ($gateway === 'stripe') {
            $res = Http::asForm()->withToken((string) static::stripeKey())->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $return.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancel,
                'client_reference_id' => $ref,
                'customer_email' => $user->email,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower(static::currency()),
                'line_items[0][price_data][unit_amount]' => (int) round($amount * 100),
                'line_items[0][price_data][product_data][name]' => $label,
                'metadata[ref]' => $ref,
            ]);
            if (! $res->successful()) {
                abort(422, 'Stripe: '.($res->json('error.message') ?? 'could not start checkout'));
            }
            $this->record($user, $plan, $cycle, 'Stripe', $amount, $ref, (string) $res->json('id'));

            return ['redirect' => (string) $res->json('url')];
        }

        [, , $api] = static::paypal();
        $res = Http::withToken($this->paypalToken())->post($api.'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [['reference_id' => $ref, 'description' => $label, 'amount' => ['currency_code' => static::currency(), 'value' => number_format($amount, 2, '.', '')]]],
            'application_context' => ['return_url' => $return, 'cancel_url' => $cancel, 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING'],
        ]);
        if (! $res->successful()) {
            abort(422, 'PayPal: '.($res->json('message') ?? 'could not start checkout'));
        }
        $approve = collect($res->json('links'))->firstWhere('rel', 'approve')['href'] ?? null;
        $this->record($user, $plan, $cycle, 'PayPal', $amount, $ref, (string) $res->json('id'));

        return ['redirect' => $approve];
    }

    /** Buyer came back from the gateway: confirm with the gateway, then apply the plan. */
    public function confirm(string $gateway, string $ref, array $query): bool
    {
        $payment = Payment::where('reference', $ref)->first();
        if (! $payment) {
            return false;
        }
        if ($payment->status === 'Paid') {
            return true;
        }

        $paid = false;
        if ($gateway === 'stripe' && static::stripeKey()) {
            $session = Http::withToken((string) static::stripeKey())->get('https://api.stripe.com/v1/checkout/sessions/'.$payment->gateway_ref);
            $paid = $session->successful() && $session->json('payment_status') === 'paid' && $session->json('client_reference_id') === $ref;
        } elseif ($gateway === 'paypal') {
            [, , $api] = static::paypal();
            $capture = Http::withToken($this->paypalToken())->withBody('{}', 'application/json')->post($api.'/v2/checkout/orders/'.$payment->gateway_ref.'/capture');
            $paid = $capture->successful() && $capture->json('status') === 'COMPLETED';
            if (! $paid && $capture->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED') {
                $paid = Http::withToken($this->paypalToken())->get($api.'/v2/checkout/orders/'.$payment->gateway_ref)->json('status') === 'COMPLETED';
            }
        }

        if (! $paid) {
            $payment->update(['status' => 'Failed']);
            ActivityLog::record('Payment '.$ref.' was not confirmed by '.$gateway, 'payments', 'WARNING');

            return false;
        }
        $this->fulfil($payment);

        return true;
    }

    /** Stripe webhook (checkout.session.completed) — covers buyers who close the tab before returning. */
    public function stripeWebhook(string $payload, ?string $signature): bool
    {
        $secret = Setting::value('set_payments_stripe_webhook_secret') ?: env('STRIPE_WEBHOOK_SECRET');
        if (! $secret || ! $signature) {
            return false;
        }
        $parts = [];
        foreach (explode(',', $signature) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $parts[$k][] = $v;
        }
        $ts = $parts['t'][0] ?? '0';
        $expected = hash_hmac('sha256', $ts.'.'.$payload, $secret);
        if (abs(time() - (int) $ts) > 600 || ! collect($parts['v1'] ?? [])->contains(fn ($s) => hash_equals($expected, (string) $s))) {
            return false;
        }
        $event = json_decode($payload, true);
        if (($event['type'] ?? '') === 'checkout.session.completed' && ($event['data']['object']['payment_status'] ?? '') === 'paid') {
            $payment = Payment::where('reference', $event['data']['object']['client_reference_id'] ?? '')->first();
            if ($payment && $payment->status !== 'Paid') {
                $this->fulfil($payment);
            }
        }

        return true;
    }

    private function record(User $user, Plan $plan, string $cycle, string $gateway, float $amount, string $ref, ?string $gatewayRef = null): Payment
    {
        return Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'reference' => $ref, 'gateway_ref' => $gatewayRef,
            'item' => $plan->name.' · '.$cycle, 'gateway' => $gateway, 'amount' => $amount, 'status' => 'Pending']);
    }

    private function fulfil(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::lockForUpdate()->find($payment->id);
            if ($payment->status === 'Paid') {
                return;
            }
            $payment->update(['status' => 'Paid']);
            $plan = Plan::find($payment->plan_id);
            $user = $payment->user;
            if ($plan && $user) {
                $user->forceFill(['plan_id' => $plan->id])->save();
                $user->adjustCredits($plan->credits, $plan->name.' plan credits');
            }
            ActivityLog::record(($user?->name ?? 'User').' paid $'.number_format($payment->amount, 2).' for '.$payment->item.' via '.$payment->gateway, 'payments');
        });
    }

    private function paypalToken(): string
    {
        [$id, $secret, $api] = static::paypal();
        $res = Http::asForm()->withBasicAuth((string) $id, (string) $secret)->post($api.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (! $res->successful()) {
            abort(422, 'PayPal: invalid client ID or secret');
        }

        return (string) $res->json('access_token');
    }
}
