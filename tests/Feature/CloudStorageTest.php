<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\User;
use App\Support\Media;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.installed' => true]);
        $this->seed(CatalogSeeder::class);
        Storage::fake('public');
        Media::fake(Storage::fake('bucket'));
    }

    protected function tearDown(): void
    {
        Media::reset();
        parent::tearDown();
    }

    public function test_uploads_are_copied_to_the_bucket_and_served_from_it(): void
    {
        $u = User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'Secret123', 'credits' => 10, 'plan_id' => Plan::where('slug', 'free')->value('id'), 'email_verified_at' => now()]);
        $url = $this->actingAs($u)->post('/studio/media', ['file' => UploadedFile::fake()->image('a.png', 20, 20)], ['Accept' => 'application/json'])->assertOk()->json('media.0.url');
        $path = MediaAsset::first()->path;
        Storage::disk('bucket')->assertExists($path);
        $this->assertSame('https://cdn.test/'.$path, $url);

        $this->actingAs($u)->deleteJson('/studio/media/'.MediaAsset::first()->id)->assertOk();
        Storage::disk('bucket')->assertMissing($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_sync_pushes_new_files_prunes_old_renders_and_restores_them_on_demand(): void
    {
        Storage::disk('public')->put('renders/old.mp4', 'video');
        Storage::disk('public')->put('voices/preview.mp3', 'scratch');
        $this->assertSame('/storage/renders/old.mp4', parse_url(Media::url('renders/old.mp4'), PHP_URL_PATH));

        Artisan::call('reelsmith:storage-sync');
        Storage::disk('bucket')->assertExists('renders/old.mp4');
        Storage::disk('bucket')->assertMissing('voices/preview.mp3');
        $this->assertSame('https://cdn.test/renders/old.mp4', Media::url('renders/old.mp4'));

        touch(Storage::disk('public')->path('renders/old.mp4'), time() - 40 * 86400);
        Artisan::call('reelsmith:storage-sync', ['--prune-days' => 30]);
        Storage::disk('public')->assertMissing('renders/old.mp4');
        $this->assertNotNull(Media::local('renders/old.mp4'));
        $this->assertSame('video', Storage::disk('public')->get('renders/old.mp4'));
    }
}
