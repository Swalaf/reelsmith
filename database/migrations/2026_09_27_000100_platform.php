<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AI Platform: workflows + runs, characters, cinematic productions, agents, webhooks, API request log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('nodes')->nullable();
            $t->json('edges')->nullable();
            $t->json('settings')->nullable();
            $t->string('route')->default('Free first');
            $t->boolean('live')->default(false);
            $t->string('hook_token', 40)->unique();
            $t->unsignedInteger('runs_count')->default(0);
            $t->timestamp('last_run_at')->nullable();
            $t->timestamp('next_run_at')->nullable();
            $t->timestamps();
        });

        Schema::create('workflow_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind')->default('workflow');
            $t->string('name');
            $t->string('trigger')->default('Manual');
            $t->string('status')->default('Queued');
            $t->json('input')->nullable();
            $t->json('steps')->nullable();
            $t->json('log')->nullable();
            $t->json('output')->nullable();
            $t->unsignedInteger('credits')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('characters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('look')->nullable();
            $t->string('costume')->nullable();
            $t->string('personality')->nullable();
            $t->string('voice')->nullable();
            $t->string('image')->nullable();
            $t->timestamps();
        });

        Schema::create('productions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('logline')->nullable();
            $t->string('style')->default('Cinematic');
            $t->json('settings')->nullable();
            $t->json('toggles')->nullable();
            $t->json('scenes')->nullable();
            $t->json('shots')->nullable();
            $t->json('screenplay')->nullable();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('icon')->default('bot');
            $t->string('description')->nullable();
            $t->string('model')->nullable();
            $t->json('tools')->nullable();
            $t->json('perms')->nullable();
            $t->text('system')->nullable();
            $t->string('format')->default('Markdown');
            $t->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('url');
            $t->json('events')->nullable();
            $t->string('secret', 64);
            $t->unsignedInteger('deliveries')->default(0);
            $t->unsignedInteger('successes')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('webhook_id')->nullable()->constrained()->nullOnDelete();
            $t->string('direction', 3);
            $t->string('event');
            $t->unsignedSmallInteger('code')->default(0);
            $t->string('target')->nullable();
            $t->timestamps();
        });

        Schema::create('api_request_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $t->string('method', 8);
            $t->string('path');
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('ms')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['api_request_logs', 'webhook_events', 'webhooks', 'agents', 'productions', 'characters', 'workflow_runs', 'workflows'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
