<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Plan checkout through Stripe Checkout, PayPal Orders, Razorpay Payment Links, Paystack
 * transactions (REST APIs, no SDKs) or a manual bank transfer approved by an administrator.
 *
 * Keys come from Admin → Settings → Payments (stored encrypted) or env vars (STRIPE_SECRET,
 * PAYPAL_CLIENT_ID/PAYPAL_SECRET, RAZORPAY_KEY_ID/RAZORPAY_KEY_SECRET, PAYSTACK_SECRET). The
 * buyer is redirected to the gateway and the plan is applied only after the gateway confirms
 * the payment on return (or via webhook).
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

    /** @return array{0: ?string, 1: ?string} key id, key secret */
    public static function razorpay(): array
    {
        return [Setting::value('set_payments_razorpay_key_id') ?: env('RAZORPAY_KEY_ID'), Setting::value('set_payments_razorpay_key_secret') ?: env('RAZORPAY_KEY_SECRET')];
    }

    public static function paystackKey(): ?string
    {
        return Setting::value('set_payments_paystack_secret_key') ?: env('PAYSTACK_SECRET');
    }

    /** Lines shown to the buyer for a bank transfer (Admin → Settings → Payments, separated by "|"). */
    public static function bankLines(): array
    {
        $raw = (string) (Setting::value('set_payments_bank_transfer_details') ?: env('BANK_TRANSFER_DETAILS', ''));

        return array_values(array_filter(array_map('trim', preg_split('/\s*[|\n]\s*/', $raw))));
    }

    public static function currency(): string
    {
        return strtoupper(Setting::value('set_payments_currency') ?: 'USD');
    }

    /** Which gateways are usable right now (for the admin screen and checkout). */
    public static function status(): array
    {
        $enabled = (array) Setting::get('gateways', []);
        [$ppId, $ppSecret] = static::paypal();
        [$rzId, $rzSecret] = static::razorpay();

        return [
            'stripe' => ($enabled['stripe'] ?? true) && (bool) static::stripeKey(),
            'paypal' => ($enabled['paypal'] ?? true) && $ppId && $ppSecret,
            'razorpay' => ($enabled['razorpay'] ?? true) && $rzId && $rzSecret,
            'paystack' => ($enabled['paystack'] ?? true) && (bool) static::paystackKey(),
            'bank' => ($enabled['bank'] ?? true) && static::bankLines() !== [],
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
    public const METHODS = ['Card' => 'stripe', 'PayPal' => 'paypal', 'Razorpay' => 'razorpay', 'Paystack' => 'paystack', 'Bank transfer' => 'bank'];

    /**
     * Start a checkout. Returns ['redirect' => url] for a hosted gateway, ['bank' => lines] for a
     * bank transfer, or ['paid' => true] in test mode.
     */
    public function start(User $user, Plan $plan, string $cycle, string $method): array
    {
        $amount = $cycle === 'yearly' ? round($plan->price * 12 * 0.8, 2) : (float) $plan->price;
        $status = static::status();
        $gateway = self::METHODS[$method] ?? 'stripe';

        if (! $status[$gateway]) {
            // The chosen method isn't configured: fall back to any configured online gateway.
            $other = collect(['stripe', 'paypal', 'razorpay', 'paystack'])->first(fn ($g) => $status[$g]);
            if ($other) {
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
        $minor = (int) round($amount * 100);

        if ($gateway === 'bank') {
            $lines = array_merge(static::bankLines(), ['Amount: '.static::currency().' '.number_format($amount, 2), 'Reference: '.strtoupper($ref)]);
            $payment = $this->record($user, $plan, $cycle, 'Bank transfer', $amount, $ref, null, ['instructions' => $lines]);
            try {
                Mail::raw("Thanks for ordering the {$label}.\n\nPlease transfer the amount below and include the reference so we can match your payment:\n\n".implode("\n", $lines)."\n\nYour plan and credits are activated as soon as the transfer arrives. You can see the status under Studio → Credits.",
                    fn ($m) => $m->to($user->email, $user->name)->subject('Bank transfer details · '.strtoupper($ref)));
            } catch (\Throwable $e) {
                ActivityLog::record('Could not email bank details to '.$user->email.': '.mb_substr($e->getMessage(), 0, 200), 'mail', 'ERROR');
            }
            ActivityLog::record($user->email.' chose bank transfer for '.$label.' ('.$payment->reference.') — awaiting approval', 'payments');

            return ['bank' => $lines, 'reference' => strtoupper($ref)];
        }

        if ($gateway === 'razorpay') {
            [$id, $secret] = static::razorpay();
            $res = Http::withBasicAuth((string) $id, (string) $secret)->post('https://api.razorpay.com/v1/payment_links', [
                'amount' => $minor, 'currency' => static::currency(), 'description' => $label, 'reference_id' => $ref,
                'customer' => ['name' => $user->name, 'email' => $user->email], 'notify' => ['email' => false, 'sms' => false],
                'callback_url' => $return, 'callback_method' => 'get', 'notes' => ['ref' => $ref],
            ]);
            if (! $res->successful()) {
                abort(422, 'Razorpay: '.($res->json('error.description') ?? 'could not start checkout'));
            }
            $this->record($user, $plan, $cycle, 'Razorpay', $amount, $ref, (string) $res->json('id'));

            return ['redirect' => (string) $res->json('short_url')];
        }

        if ($gateway === 'paystack') {
            $res = Http::withToken((string) static::paystackKey())->post('https://api.paystack.co/transaction/initialize', [
                'email' => $user->email, 'amount' => $minor, 'currency' => static::currency(), 'reference' => $ref, 'callback_url' => $return,
                'metadata' => ['plan' => $plan->slug, 'cycle' => $cycle],
            ]);
            if (! $res->successful() || ! $res->json('status')) {
                abort(422, 'Paystack: '.($res->json('message') ?? 'could not start checkout'));
            }
            $this->record($user, $plan, $cycle, 'Paystack', $amount, $ref, (string) $res->json('data.access_code'));

            return ['redirect' => (string) $res->json('data.authorization_url')];
        }

        if ($gateway === 'stripe') {
            $res = Http::asForm()->withToken((string) static::stripeKey())->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $return.'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancel,
                'client_reference_id' => $ref,
                'customer_email' => $user->email,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower(static::currency()),
                'line_items[0][price_data][unit_amount]' => $minor,
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
        } elseif ($gateway === 'razorpay') {
            $paid = $this->razorpayPaid($payment);
        } elseif ($gateway === 'paystack') {
            $paid = $this->paystackPaid($payment);
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

    private function razorpayPaid(Payment $payment): bool
    {
        [$id, $secret] = static::razorpay();
        if (! $id || ! $secret || ! $payment->gateway_ref) {
            return false;
        }
        $link = Http::withBasicAuth($id, $secret)->get('https://api.razorpay.com/v1/payment_links/'.$payment->gateway_ref);

        return $link->successful() && $link->json('status') === 'paid' && $link->json('reference_id') === $payment->reference
            && (int) $link->json('amount_paid') >= (int) round($payment->amount * 100);
    }

    private function paystackPaid(Payment $payment): bool
    {
        if (! static::paystackKey()) {
            return false;
        }
        $tx = Http::withToken((string) static::paystackKey())->get('https://api.paystack.co/transaction/verify/'.rawurlencode($payment->reference));

        return $tx->successful() && $tx->json('data.status') === 'success' && (int) $tx->json('data.amount') >= (int) round($payment->amount * 100);
    }

    /** Razorpay webhook (payment_link.paid), signed with the webhook secret. */
    public function razorpayWebhook(string $payload, ?string $signature): bool
    {
        $secret = Setting::value('set_payments_razorpay_webhook_secret') ?: env('RAZORPAY_WEBHOOK_SECRET');
        if (! $secret || ! $signature || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return false;
        }
        $event = json_decode($payload, true);
        if (($event['event'] ?? '') === 'payment_link.paid') {
            $payment = Payment::where('reference', $event['payload']['payment_link']['entity']['reference_id'] ?? '')->first();
            if ($payment && $payment->status !== 'Paid' && $this->razorpayPaid($payment)) {
                $this->fulfil($payment);
            }
        }

        return true;
    }

    /** Paystack webhook (charge.success), signed with the secret key (HMAC-SHA512). */
    public function paystackWebhook(string $payload, ?string $signature): bool
    {
        $secret = static::paystackKey();
        if (! $secret || ! $signature || ! hash_equals(hash_hmac('sha512', $payload, $secret), $signature)) {
            return false;
        }
        $event = json_decode($payload, true);
        if (($event['event'] ?? '') === 'charge.success') {
            $payment = Payment::where('reference', $event['data']['reference'] ?? '')->first();
            if ($payment && $payment->status !== 'Paid' && $this->paystackPaid($payment)) {
                $this->fulfil($payment);
            }
        }

        return true;
    }

    /** Admin → Payments: mark a bank transfer as received (or not). */
    public function settleBankTransfer(Payment $payment, bool $received, string $by): void
    {
        abort_unless($payment->gateway === 'Bank transfer' && $payment->status === 'Pending', 422, 'Only pending bank transfers can be approved or rejected.');
        if ($received) {
            $this->fulfil($payment);
            ActivityLog::record('Bank transfer '.$payment->reference.' approved by '.$by, 'payments');
        } else {
            $payment->update(['status' => 'Failed']);
            ActivityLog::record('Bank transfer '.$payment->reference.' rejected by '.$by, 'payments', 'WARNING');
        }
        $user = $payment->user;
        if ($user) {
            try {
                Mail::raw($received ? "We received your bank transfer ({$payment->reference}). Your {$payment->item} plan is now active." : "We could not match your bank transfer ({$payment->reference}). Reply to this email or open a support ticket and we'll sort it out.",
                    fn ($m) => $m->to($user->email, $user->name)->subject($received ? 'Payment received' : 'About your bank transfer'));
            } catch (\Throwable) {
            }
        }
    }

    private function record(User $user, Plan $plan, string $cycle, string $gateway, float $amount, string $ref, ?string $gatewayRef = null, ?array $meta = null): Payment
    {
        return Payment::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'reference' => $ref, 'gateway_ref' => $gatewayRef,
            'item' => $plan->name.' · '.$cycle, 'gateway' => $gateway, 'amount' => $amount, 'status' => 'Pending', 'meta' => $meta]);
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
