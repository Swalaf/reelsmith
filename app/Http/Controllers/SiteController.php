<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ContactMessage;
use App\Models\Plan;
use App\Services\Payments;
use App\Support\Boot;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120', 'email' => 'required|email|max:190',
            'topic' => 'nullable|string|max:120', 'message' => 'required|string|max:5000',
        ]);
        ContactMessage::create($data);
        ActivityLog::record('Contact message from '.$data['email'].' ('.($data['topic'] ?? 'general').')', 'app');

        return ['ok' => true];
    }

    /** Start a plan purchase: redirects to Stripe/PayPal (or completes instantly in local test mode). */
    public function checkout(Request $request, Payments $payments)
    {
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'cycle' => 'required|in:monthly,yearly', 'method' => 'nullable|string']);
        $plan = Plan::findOrFail($data['plan_id']);
        abort_if($plan->price <= 0, 422, 'Pick a paid plan.');

        $result = $payments->start($request->user(), $plan, $data['cycle'], (string) ($data['method'] ?? 'Card'));

        return $result + ['user' => Boot::me($request->user()->fresh())];
    }

    /** Buyer returns from the gateway. */
    public function checkoutReturn(Request $request, Payments $payments)
    {
        $ok = $payments->confirm((string) $request->query('gateway'), (string) $request->query('ref'), $request->query());

        return redirect('/checkout?'.($ok ? 'paid=1' : 'failed=1'));
    }

    public function razorpayWebhook(Request $request, Payments $payments)
    {
        return $payments->razorpayWebhook($request->getContent(), $request->header('X-Razorpay-Signature'))
            ? response()->json(['received' => true]) : response()->json(['error' => 'invalid signature'], 400);
    }

    public function paystackWebhook(Request $request, Payments $payments)
    {
        return $payments->paystackWebhook($request->getContent(), $request->header('X-Paystack-Signature'))
            ? response()->json(['received' => true]) : response()->json(['error' => 'invalid signature'], 400);
    }

    public function stripeWebhook(Request $request, Payments $payments)
    {
        return $payments->stripeWebhook($request->getContent(), $request->header('Stripe-Signature'))
            ? response()->json(['received' => true])
            : response()->json(['error' => 'invalid signature'], 400);
    }

    public function onboarding(Request $request)
    {
        $data = $request->validate(['uses' => 'array', 'uses.*' => 'string|max:60', 'topic' => 'nullable|string|max:200']);
        $request->user()->update(['onboarding' => $data]);

        return ['ok' => true];
    }
}
