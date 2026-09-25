<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ContactMessage;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\Boot;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /**
     * Upgrade to a paid plan. No card data reaches this server: the design's card form is
     * presentational until a real gateway (Stripe, PayPal, …) is connected, so orders are
     * recorded as paid in test mode and the plan + credits are applied immediately.
     */
    public function checkout(Request $request)
    {
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'cycle' => 'required|in:monthly,yearly', 'method' => 'nullable|string']);
        $plan = Plan::findOrFail($data['plan_id']);
        abort_if($plan->price <= 0, 422, 'Pick a paid plan.');

        $user = $request->user();
        $amount = $data['cycle'] === 'yearly' ? round($plan->price * 12 * 0.8, 2) : $plan->price;
        $gateway = ($data['method'] ?? 'Card') === 'PayPal' ? 'PayPal' : 'Stripe';
        $enabled = (array) Setting::get('gateways', ['stripe' => true, 'paypal' => true]);
        abort_if(($enabled[strtolower($gateway)] ?? true) === false, 422, $gateway.' is not enabled.');

        Payment::create(['user_id' => $user->id, 'reference' => 'txn_'.Str::lower(Str::random(8)), 'item' => $plan->name.' · '.$data['cycle'], 'gateway' => $gateway.' (test)', 'amount' => $amount, 'status' => 'Paid']);
        $user->plan_id = $plan->id;
        $user->save();
        $user->adjustCredits($plan->credits, $plan->name.' plan credits');
        ActivityLog::record($user->name.' upgraded to '.$plan->name.' · $'.number_format($amount, 2), 'payments');

        return ['ok' => true, 'user' => Boot::me($user->fresh())];
    }

    public function onboarding(Request $request)
    {
        $data = $request->validate(['uses' => 'array', 'uses.*' => 'string|max:60', 'topic' => 'nullable|string|max:200']);
        $request->user()->update(['onboarding' => $data]);

        return ['ok' => true];
    }
}
