<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Real AI media pipeline: render progress/music on projects, and provider drivers that can generate media. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedTinyInteger('render_progress')->default(0)->after('render_seconds');
            $table->string('render_stage')->nullable()->after('render_progress');
            $table->string('music')->nullable()->after('voice');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('verify_code')->nullable()->after('email_verified_at');
        });

        // Existing installs: point providers at the endpoints the generators call.
        $updates = [
            'cft' => ['base_url' => 'https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID'],
            'cf' => ['base_url' => 'https://api.cloudflare.com/client/v4/accounts/YOUR_ACCOUNT_ID'],
            'hft' => ['base_url' => 'https://router.huggingface.co/v1', 'model' => 'meta-llama/Llama-3.1-8B-Instruct'],
            'hf' => ['base_url' => 'https://router.huggingface.co/hf-inference'],
            'stab' => ['driver' => 'stability', 'base_url' => 'https://api.stability.ai'],
            'repi' => ['driver' => 'replicate', 'base_url' => 'https://api.replicate.com/v1'],
            'repv' => ['driver' => 'replicate', 'base_url' => 'https://api.replicate.com/v1'],
            'fal' => ['driver' => 'fal'],
            'run' => ['driver' => 'runway', 'model' => 'gen3a_turbo'],
            'luma' => ['driver' => 'luma'],
        ];
        foreach ($updates as $slug => $values) {
            DB::table('ai_providers')->where('slug', $slug)
                ->where(fn ($q) => $q->whereNull('base_url')->orWhere('base_url', 'not like', '%/accounts/%'))
                ->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['render_progress', 'render_stage', 'music']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('verify_code'));
    }
};
