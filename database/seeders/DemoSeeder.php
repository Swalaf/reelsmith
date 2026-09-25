<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Services\ScriptWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Optional sample data so a fresh local install has something to look at (`php artisan db:seed --class=DemoSeeder`). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $plans = Plan::pluck('id', 'slug');
        $people = [['John Doe', 'john@acme.co', 'professional', 2480, 'Active'], ['Maya Chen', 'maya@northwind.io', 'agency', 860, 'Active'], ['Leo Martins', 'leo@brightpath.com', 'starter', 0, 'Active'], ['Aisha Bello', 'aisha.b@example.com', 'free', 120, 'Pending'], ['Ryan Patel', 'ryan@growthlab.co', 'free', 40, 'Suspended']];
        $writer = new ScriptWriter;
        $topics = [['Spring Collection Launch', 'Instagram Reels', '9:16', 'Completed'], ['Smart Bottle — Hydration Tips', 'TikTok', '9:16', 'Draft'], ['Q3 Product Walkthrough', 'YouTube', '16:9', 'Completed'], ['Onboarding Explainer v2', 'YouTube', '16:9', 'Failed'], ['Black Friday Teaser', 'Instagram Reels', '9:16', 'Draft']];

        foreach ($people as $i => $p) {
            $u = User::firstOrCreate(['email' => $p[1]], ['name' => $p[0], 'password' => 'password', 'plan_id' => $plans[$p[2]] ?? null, 'credits' => $p[3], 'status' => $p[4], 'email_verified_at' => now()]);
            if ($i === 0 && $u->projects()->count() === 0) {
                foreach ($topics as $j => $t) {
                    $idea = ['topic' => $t[0], 'tone' => 'Friendly', 'platform' => $t[1], 'dur' => '30s', 'ratio' => $t[2]];
                    $out = $writer->write($idea);
                    Project::create(['user_id' => $u->id, 'name' => $t[0], 'status' => $t[3], 'platform' => $t[1], 'ratio' => $t[2], 'duration' => 30, 'idea' => $idea, 'script' => $out['script'], 'scenes' => $out['scenes'], 'providers_used' => 'Built-in · Edge TTS', 'credits_used' => $t[3] === 'Completed' ? 14 : 0, 'error' => $t[3] === 'Failed' ? 'Voice provider returned 401' : null, 'created_at' => now()->subDays($j * 2)]);
                }
            }
            if ($p[2] !== 'free' && ! Payment::where('user_id', $u->id)->exists()) {
                $plan = Plan::find($plans[$p[2]]);
                Payment::create(['user_id' => $u->id, 'reference' => 'txn_'.Str::lower(Str::random(6)), 'item' => $plan->name.' · monthly', 'gateway' => 'Stripe', 'amount' => $plan->price, 'status' => 'Paid']);
            }
        }
        ActivityLog::record('Demo data seeded', 'app');
    }
}
