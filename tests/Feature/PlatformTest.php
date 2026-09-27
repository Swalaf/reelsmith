<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        $this->seed(CatalogSeeder::class);
        AiProvider::where('driver', 'none')->update(['status' => 'off']);
        AiProvider::where('slug', 'groq')->update(['status' => 'connected', 'api_key' => encrypt('k', false), 'priority' => 1]);
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function user(): User
    {
        return User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'Secret123', 'credits' => 100,
            'plan_id' => Plan::where('slug', 'free')->value('id'), 'email_verified_at' => now()]);
    }

    private function chat(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text]]]];
    }

    private function workflow(User $u, bool $live = false): Workflow
    {
        return Workflow::create(['user_id' => $u->id, 'name' => 'Copy then post', 'live' => $live, 'route' => 'Free first', 'settings' => ['creditCap' => 50],
            'hook_token' => 'tok123', 'nodes' => [
                ['id' => 1, 't' => 'webhook', 'title' => 'In', 'cfg' => ['Input (JSON)' => '{"topic":"tea"}']],
                ['id' => 2, 't' => 'aitext', 'title' => 'Copy', 'cfg' => ['Prompt' => 'Write about {input.topic}', 'Output variable' => 'copy']],
                ['id' => 3, 't' => 'http', 'title' => 'Post', 'cfg' => ['URL' => 'https://hooks.test/in', 'Method' => 'POST']],
                ['id' => 4, 't' => 'output', 'title' => 'Out', 'cfg' => ['Value' => '{copy}']],
            ], 'edges' => [[1, 2], [2, 3], [3, 4]]]);
    }

    public function test_platform_page_seeds_starter_data(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/platform/workflows')->assertOk();
        $this->assertGreaterThan(0, Workflow::where('user_id', $u->id)->count());
        $this->actingAs($u)->getJson('/platform/api/state')->assertOk()->assertJsonStructure(['workflows', 'runs', 'agents', 'characters', 'webhooks']);
    }

    public function test_workflow_run_executes_steps_and_charges_credits(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->chat('Tea is great.')), 'hooks.test/*' => Http::response(['ok' => true])]);
        $u = $this->user();
        $wf = $this->workflow($u);

        $id = $this->actingAs($u)->postJson("/platform/api/workflows/{$wf->id}/run", ['input' => []])->assertOk()->json('run.id');

        $run = WorkflowRun::find($id);
        $this->assertSame('Completed', $run->status, (string) $run->error);
        $this->assertSame(['done', 'done', 'done', 'done'], array_column($run->steps, 'status'));
        $this->assertSame('Tea is great.', $run->output['text']);
        $this->assertSame(99, $u->fresh()->credits);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.test/in' && str_contains($r->body(), 'Tea is great.'));
    }

    public function test_incoming_webhook_only_runs_live_workflows(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->chat('Hi')), 'hooks.test/*' => Http::response(['ok' => true])]);
        $u = $this->user();
        $wf = $this->workflow($u);

        $this->postJson('/hooks/in/tok123', ['topic' => 'x'])->assertStatus(409);
        $wf->update(['live' => true]);
        $this->postJson('/hooks/in/tok123', ['topic' => 'coffee'])->assertStatus(202);
        $this->postJson('/hooks/in/nope', [])->assertNotFound();

        $run = WorkflowRun::where('workflow_id', $wf->id)->latest('id')->first();
        $this->assertSame('Webhook', $run->trigger);
        $this->assertSame('Completed', $run->status);
    }

    public function test_outgoing_webhooks_are_signed(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->chat('Hi')), 'hooks.test/*' => Http::response(['ok' => true]), 'client.test/*' => Http::response('', 200)]);
        $u = $this->user();
        $secret = $this->actingAs($u)->postJson('/platform/api/webhooks', ['url' => 'https://client.test/rs', 'events' => ['run.completed']])->assertOk()->json('webhook.secret');
        $this->assertNotEmpty($secret);
        $wf = $this->workflow($u);
        $this->actingAs($u)->postJson("/platform/api/workflows/{$wf->id}/run", ['input' => []])->assertOk();

        Http::assertSent(function (HttpRequest $r) use ($secret) {
            return $r->url() === 'https://client.test/rs'
                && $r->header('X-Reelsmith-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), $secret)
                && $r['event'] === 'run.completed';
        });
        $this->assertSame(1, WebhookEvent::where('direction', 'OUT')->where('event', 'run.completed')->count());
    }

    public function test_agent_run_and_api_aliases(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->chat('Five hooks.'))]);
        $u = $this->user();
        $this->actingAs($u)->get('/platform')->assertOk();
        $agent = Agent::where('user_id', $u->id)->firstOrFail();

        $this->actingAs($u)->postJson("/platform/api/agents/{$agent->id}/run", ['message' => 'go'])->assertOk()->assertJsonPath('reply', 'Five hooks.');

        [, $key] = ApiKey::issue($u, 'Test');
        $this->withToken($key)->postJson('/api/agents/'.rawurlencode($agent->name).'/run', ['message' => 'go'])->assertOk();
        $wf = $this->workflow($u);
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $job = $this->withToken($key)->postJson('/api/workflows/run', ['workflow_id' => 'wf_'.$wf->id, 'input' => ['topic' => 'tea']])->assertStatus(202)->json('run_id');
        $this->withToken($key)->getJson('/api/jobs/'.$job)->assertOk()->assertJsonPath('status', 'completed');
    }
}
