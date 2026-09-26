<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        $this->seed(CatalogSeeder::class);
        AiProvider::where('driver', 'none')->update(['status' => 'off']);
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function user(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'Secret123', 'credits' => 100,
            'plan_id' => Plan::where('slug', 'free')->value('id'), 'email_verified_at' => now()], $attrs));
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(64, 64);
        for ($i = 0; $i < 400; $i++) {
            imagesetpixel($im, random_int(0, 63), random_int(0, 63), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    public function test_scene_visual_falls_back_to_the_next_image_provider(): void
    {
        AiProvider::where('slug', 'stab')->update(['status' => 'connected', 'api_key' => encrypt('bad', false), 'priority' => 1]);
        AiProvider::where('slug', 'hf')->update(['status' => 'connected', 'api_key' => encrypt('good', false), 'priority' => 2]);
        Http::fake([
            'api.stability.ai/*' => Http::response(['message' => 'server error'], 500),
            'router.huggingface.co/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $u = $this->user();
        $p = $u->projects()->create(['name' => 'V', 'ratio' => '9:16', 'scenes' => [['id' => 1, 'prompt' => 'a lighthouse', 'narration' => 'n', 'dur' => 4, 'src' => 'AI Image']]]);

        $res = $this->actingAs($u)->postJson("/studio/projects/{$p->id}/scenes/1/visual", ['src' => 'AI Image'])->assertOk();
        $this->assertSame('Hugging Face', $res->json('provider'));
        $scene = $p->fresh()->scenes[0];
        Storage::disk('public')->assertExists($scene['img']);
        $this->assertSame(99, $u->fresh()->credits);
        $this->assertNotNull($res->json('project.scenes.0.imgUrl'));
    }

    public function test_scene_visual_reports_when_no_provider_is_connected(): void
    {
        $u = $this->user();
        $p = $u->projects()->create(['name' => 'V', 'scenes' => [['id' => 1, 'prompt' => 'x', 'dur' => 4]]]);
        $this->actingAs($u)->postJson("/studio/projects/{$p->id}/scenes/1/visual")->assertStatus(422)->assertJsonFragment(['message' => 'No Image AI provider is connected']);
        $this->assertSame(100, $u->fresh()->credits);
    }

    public function test_voice_uses_openai_tts_with_mapped_voice(): void
    {
        AiProvider::where('slug', 'oat')->update(['status' => 'connected', 'api_key' => encrypt('k', false)]);
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response(str_repeat('ID3', 200), 200, ['Content-Type' => 'audio/mpeg'])]);
        $u = $this->user();
        $this->actingAs($u)->postJson('/studio/voices/preview', ['voice' => 'Ava'])->assertOk()->assertJsonStructure(['url']);
        Http::assertSent(fn ($r) => $r['voice'] === 'nova' && str_contains($r['input'], 'Ava'));
    }

    public function test_stripe_checkout_redirects_and_applies_plan_only_after_confirmation(): void
    {
        Setting::putValues(['set_payments_stripe_secret_key' => 'sk_test_123']);
        $plan = Plan::where('slug', 'starter')->first();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            'api.stripe.com/v1/checkout/sessions/cs_1' => fn () => Http::response(['id' => 'cs_1', 'payment_status' => 'paid', 'client_reference_id' => Payment::first()->reference]),
        ]);
        $u = $this->user();

        $this->actingAs($u)->postJson('/checkout', ['plan_id' => $plan->id, 'cycle' => 'monthly', 'method' => 'Card'])
            ->assertOk()->assertJsonPath('redirect', 'https://checkout.stripe.com/c/pay/cs_1');
        $payment = Payment::first();
        $this->assertSame('Pending', $payment->status);
        $this->assertNotSame($plan->id, $u->fresh()->plan_id);

        $this->get('/checkout/return?gateway=stripe&ref='.$payment->reference.'&session_id=cs_1')->assertRedirect('/checkout?paid=1');
        $this->assertSame('Paid', $payment->fresh()->status);
        $this->assertSame($plan->id, $u->fresh()->plan_id);
        $this->assertSame(100 + $plan->credits, $u->fresh()->credits);

        // Returning twice doesn't add credits twice.
        $this->get('/checkout/return?gateway=stripe&ref='.$payment->reference)->assertRedirect('/checkout?paid=1');
        $this->assertSame(100 + $plan->credits, $u->fresh()->credits);
    }

    public function test_paypal_checkout_captures_order(): void
    {
        Setting::putValues(['set_payments_paypal_client_id' => 'id', 'set_payments_paypal_secret' => 'sec', 'set_payments_paypal_mode' => 'Sandbox']);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORD1', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD1']]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORD1/capture' => Http::response(['status' => 'COMPLETED']),
        ]);
        $u = $this->user();
        $plan = Plan::where('slug', 'professional')->first();
        $this->actingAs($u)->postJson('/checkout', ['plan_id' => $plan->id, 'cycle' => 'yearly', 'method' => 'PayPal'])
            ->assertOk()->assertJsonPath('redirect', 'https://www.sandbox.paypal.com/checkoutnow?token=ORD1');
        $this->get('/checkout/return?gateway=paypal&ref='.Payment::first()->reference.'&token=ORD1')->assertRedirect('/checkout?paid=1');
        $this->assertSame($plan->id, $u->fresh()->plan_id);
        $this->assertEquals(round(49 * 12 * 0.8, 2), Payment::first()->amount);
    }

    public function test_live_site_without_gateways_refuses_to_give_plans_away(): void
    {
        config(['services.payments.test_mode' => false]);
        $u = $this->user();
        $this->actingAs($u)->postJson('/checkout', ['plan_id' => Plan::where('slug', 'starter')->value('id'), 'cycle' => 'monthly'])->assertStatus(422);
        $this->assertSame(0, Payment::count());
    }

    public function test_payment_secrets_are_encrypted_and_masked(): void
    {
        $admin = $this->user(['role' => 'admin', 'email' => 'a@example.com']);
        $this->actingAs($admin)->putJson('/admin/settings', ['values' => ['set_payments_stripe_secret_key' => 'sk_live_secret', 'set_payments_currency' => 'EUR']])->assertOk();
        $raw = Setting::get('values')['set_payments_stripe_secret_key'];
        $this->assertStringStartsWith('enc:', $raw);
        $this->assertSame('sk_live_secret', Setting::value('set_payments_stripe_secret_key'));
        $this->assertSame(Setting::MASK, Setting::publicValues()['set_payments_stripe_secret_key']);

        // Saving the mask back keeps the stored secret.
        $this->putJson('/admin/settings', ['values' => ['set_payments_stripe_secret_key' => Setting::MASK]])->assertOk();
        $this->assertSame('sk_live_secret', Setting::value('set_payments_stripe_secret_key'));
    }

    public function test_email_verification_gates_video_creation(): void
    {
        $this->postJson('/register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'])
            ->assertOk()->assertJsonPath('verify', true);
        $u = User::where('email', 'new@example.com')->first();
        $this->assertNull($u->email_verified_at);
        $this->assertNotNull($u->verify_code);

        $this->postJson('/studio/projects', ['idea' => ['topic' => 'x']])->assertStatus(403);
        $this->postJson('/verify', ['code' => '000000'])->assertStatus(422);

        $u->forceFill(['verify_code' => hash('sha256', $u->id.'|123456').'|'.(time() + 60)])->save();
        $this->actingAs($u = $u->fresh())->postJson('/verify', ['code' => '123456'])->assertOk();
        $this->actingAs($u->fresh());
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->postJson('/studio/projects', ['idea' => ['topic' => 'x']])->assertOk();
    }
}
