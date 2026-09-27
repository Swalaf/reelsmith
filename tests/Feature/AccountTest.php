<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Totp;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        $this->seed(CatalogSeeder::class);
        Storage::fake('public');
    }

    private function user(string $plan = 'free', array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'Secret123', 'credits' => 100,
            'plan_id' => Plan::where('slug', $plan)->value('id'), 'email_verified_at' => now()], $attrs));
    }

    public function test_account_screens_render_with_data(): void
    {
        $u = $this->user();
        foreach (['media', 'credits', 'usage', 'api', 'settings', 'support'] as $screen) {
            $this->actingAs($u)->get('/studio/'.$screen)->assertOk();
        }
        $this->actingAs($u)->getJson('/studio/account')->assertOk()->assertJsonStructure(['media', 'credits' => ['balance', 'plans', 'history'], 'usage' => ['days', 'byCategory'], 'api', 'settings', 'tickets']);
    }

    public function test_media_upload_and_delete(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/studio/media', ['file' => UploadedFile::fake()->image('logo.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('media.0.source', 'Upload');
        $a = MediaAsset::firstOrFail();
        Storage::disk('public')->assertExists($a->path);
        $this->actingAs($u)->getJson('/studio/media')->assertJsonPath('media.0.path', $a->path);
        $this->actingAs($u)->deleteJson('/studio/media/'.$a->id)->assertOk();
        Storage::disk('public')->assertMissing($a->path);
    }

    public function test_api_keys_need_a_plan_with_api_access(): void
    {
        $this->actingAs($this->user('free'))->postJson('/studio/api-keys', ['name' => 'x'])->assertForbidden();
        Plan::where('slug', 'free')->update(['api_access' => true]);
        $key = $this->actingAs(User::first()->fresh())->postJson('/studio/api-keys', ['name' => 'CI'])->assertOk()->json('key');
        $this->withToken($key)->getJson('/api/v1/credits')->assertOk();
    }

    public function test_password_change_requires_current_password(): void
    {
        $u = $this->user();
        $this->actingAs($u)->putJson('/studio/account/password', ['current_password' => 'nope', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])->assertUnprocessable();
        $this->actingAs($u)->putJson('/studio/account/password', ['current_password' => 'Secret123', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])->assertOk();
        $this->assertTrue(password_verify('NewSecret123', $u->fresh()->password));
    }

    public function test_two_factor_setup_and_login_challenge(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson('/studio/account/2fa/setup')->assertOk();
        $secret = decrypt(session('2fa.pending'));
        $this->actingAs($u)->postJson('/studio/account/2fa/confirm', ['code' => '000000'])->assertUnprocessable();
        $codes = $this->actingAs($u)->postJson('/studio/account/2fa/confirm', ['code' => Totp::code($secret)])->assertOk()->json('codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($u->fresh()->hasTwoFactor());

        $this->post('/logout');
        $this->postJson('/login', ['email' => 'pat@example.com', 'password' => 'Secret123'])->assertOk()->assertJsonPath('twoFactor', true);
        $this->assertGuest();
        $this->postJson('/two-factor', ['code' => '000000'])->assertUnprocessable();
        $this->postJson('/two-factor', ['code' => Totp::code($secret)])->assertOk()->assertJsonPath('user.email', 'pat@example.com');
        $this->assertAuthenticatedAs($u);

        // A recovery code works once.
        $this->post('/logout');
        $this->postJson('/login', ['email' => 'pat@example.com', 'password' => 'Secret123'])->assertJsonPath('twoFactor', true);
        $this->postJson('/two-factor', ['recovery_code' => $codes[0]])->assertOk();
        $this->post('/logout');
        $this->postJson('/login', ['email' => 'pat@example.com', 'password' => 'Secret123']);
        $this->postJson('/two-factor', ['recovery_code' => $codes[0]])->assertUnprocessable();
    }

    public function test_admins_can_be_required_to_use_two_factor(): void
    {
        $admin = $this->user('free', ['role' => 'admin', 'email' => 'admin@example.com']);
        $this->actingAs($admin)->get('/admin')->assertOk();
        Setting::put('toggles', ['twofa' => true]);
        $this->actingAs($admin)->get('/admin')->assertRedirect('/studio/settings?setup2fa=1');
    }

    public function test_support_tickets_and_staff_replies(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson('/studio/tickets', ['subject' => 'Help', 'category' => 'Billing', 'message' => 'Charged twice'])->assertOk()->assertJsonPath('tickets.0.status', 'Open');
        $t = SupportTicket::firstOrFail();
        $admin = $this->user('free', ['role' => 'admin', 'email' => 'boss@example.com']);
        $this->actingAs($admin)->getJson('/studio/account')->assertJsonPath('tickets.0.subject', 'Help');
        $this->actingAs($admin)->postJson('/studio/tickets/'.$t->id.'/reply', ['message' => 'Refunded'])->assertOk();
        $this->assertSame('Answered', $t->fresh()->status);
        $other = $this->user('free', ['email' => 'other@example.com']);
        $this->actingAs($other)->postJson('/studio/tickets/'.$t->id.'/reply', ['message' => 'hi'])->assertNotFound();
    }
}
