<?php

namespace Tests\Feature;

use App\Jobs\RenderProject;
use App\Models\AiProvider;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReelsmithTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        $this->seed(CatalogSeeder::class);
        Http::preventStrayRequests();
    }

    private function user(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Jo Test', 'email' => 'jo@example.com', 'password' => 'Secret123', 'credits' => 100, 'plan_id' => Plan::where('slug', 'free')->value('id')], $attrs));
    }

    public function test_uninstalled_app_redirects_to_installer_and_installer_locks_after_install(): void
    {
        config(['app.installed' => false]);
        $this->get('/studio')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('x-dc', false);

        config(['app.installed' => true]);
        $this->get('/install')->assertRedirect('/');
    }

    public function test_public_pages_render_with_database_plans(): void
    {
        Plan::where('slug', 'starter')->update(['price' => 21]);
        $this->get('/')->assertOk()->assertSee('"price":21', false);
        $this->get('/pricing')->assertOk();
        $this->get('/legal/privacy')->assertOk()->assertSee('"legal":"privacy"', false);
        $this->get('/nope')->assertNotFound();
    }

    public function test_register_grants_signup_credits_and_logs_in(): void
    {
        $this->postJson('/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'])
            ->assertOk()->assertJsonPath('user.email', 'new@example.com');
        $this->assertAuthenticated();
        $this->assertSame(50, User::where('email', 'new@example.com')->value('credits'));
    }

    public function test_login_rejects_bad_password_and_suspended_users(): void
    {
        $this->user();
        $this->postJson('/login', ['email' => 'jo@example.com', 'password' => 'wrong'])->assertStatus(422);
        User::where('email', 'jo@example.com')->update(['status' => 'Suspended']);
        $this->postJson('/login', ['email' => 'jo@example.com', 'password' => 'Secret123'])->assertStatus(403)->assertJsonPath('suspended', true);
    }

    public function test_create_video_writes_script_and_render_charges_credits(): void
    {
        $u = $this->user();
        $res = $this->actingAs($u)->postJson('/studio/projects', ['idea' => ['topic' => 'Morning routines', 'tone' => 'Bold', 'platform' => 'TikTok', 'dur' => '30s', 'ratio' => '9:16']])
            ->assertOk()->assertJsonPath('project.status', 'Draft');
        $id = $res->json('project.id');
        $this->assertNotEmpty($res->json('project.scenes'));
        $this->assertStringContainsString('Morning routines', $res->json('project.script.hook'));

        $cost = Project::find($id)->renderCost();
        $this->postJson("/studio/projects/{$id}/render")->assertOk()->assertJsonPath('project.status', 'Processing');
        $this->assertSame(100 - $cost, $u->fresh()->credits);

        (new RenderProject($id))->handle();
        $this->assertSame('Completed', Project::find($id)->status);
    }

    public function test_render_is_refused_without_enough_credits(): void
    {
        $u = $this->user(['credits' => 1]);
        $p = $u->projects()->create(['name' => 'X', 'scenes' => [['dur' => 5, 'caption' => 'a']]]);
        $this->actingAs($u)->postJson("/studio/projects/{$p->id}/render")->assertStatus(422);
        $this->assertSame('Draft', $p->fresh()->status);
    }

    public function test_users_cannot_touch_other_users_projects_or_admin(): void
    {
        $owner = $this->user();
        $other = $this->user(['email' => 'other@example.com']);
        $p = $owner->projects()->create(['name' => 'Mine']);
        $this->actingAs($other)->getJson("/studio/projects/{$p->id}")->assertNotFound();
        $this->actingAs($other)->getJson('/admin')->assertForbidden();
        $this->actingAs($other)->putJson('/admin/providers/or', ['api_key' => 'x'])->assertForbidden();
    }

    public function test_admin_manages_users_plans_and_provider_keys_are_encrypted(): void
    {
        $admin = $this->user(['email' => 'admin@example.com', 'role' => 'admin']);
        $u = $this->user(['email' => 'u@example.com']);
        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->putJson("/admin/users/{$u->id}", ['status' => 'Suspended'])->assertOk()->assertJsonPath('user.status', 'Suspended');
        $this->postJson("/admin/users/{$u->id}/credits", ['mode' => 'Add', 'amount' => 500])->assertOk()->assertJsonPath('user.credits', 600);

        $this->postJson('/admin/plans', ['name' => 'Team', 'price' => 29, 'credits' => 3000, 'videos' => '60', 'storage_gb' => 20, 'features' => ['API access']])
            ->assertOk()->assertJsonPath('plan.name', 'Team');

        $this->putJson('/admin/providers/groq', ['api_key' => 'gsk_secret_value_1234', 'status' => 'connected'])->assertOk()->assertJsonPath('provider.status', 'connected');
        $raw = \DB::table('ai_providers')->where('slug', 'groq')->value('api_key');
        $this->assertStringNotContainsString('gsk_secret', $raw);
        $this->assertSame('gsk_secret_value_1234', AiProvider::where('slug', 'groq')->first()->api_key);
    }

    public function test_script_writer_uses_connected_openai_compatible_provider(): void
    {
        AiProvider::where('slug', 'groq')->first()->update(['api_key' => 'k', 'status' => 'connected']);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'title' => 'AI title', 'hook' => 'AI hook', 'body' => 'b', 'cta' => 'c',
            'scenes' => [['prompt' => 'p', 'narration' => 'n', 'caption' => 'cap', 'dur' => 6]],
        ])]]]])]);
        $this->actingAs($this->user())->postJson('/studio/projects', ['idea' => ['topic' => 'Anything']])
            ->assertOk()->assertJsonPath('project.script.hook', 'AI hook')->assertJsonPath('project.provs', 'Groq');
    }

    public function test_public_api_with_key(): void
    {
        $u = $this->user(['credits' => 500]);
        [, $plain] = ApiKey::issue($u, 'Test');

        $this->getJson('/api/v1/credits')->assertUnauthorized();
        $this->getJson('/api/v1/credits', ['Authorization' => "Bearer {$plain}"])->assertOk()->assertJsonPath('balance', 500);
        $res = $this->postJson('/api/v1/videos', ['topic' => 'History of coffee', 'duration' => 30], ['Authorization' => "Bearer {$plain}"])->assertStatus(202);
        $this->getJson('/api/v1/videos/'.$res->json('id'), ['Authorization' => "Bearer {$plain}"])->assertOk()->assertJsonPath('title', $res->json('title'));
        $this->getJson('/api/v1/templates', ['Authorization' => "Bearer {$plain}"])->assertOk()->assertJsonCount(16, 'data');
    }
}
