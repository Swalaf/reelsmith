<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->decimal('price', 8, 2)->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->string('videos_label')->default('Unlimited');
            $table->unsignedInteger('storage_gb')->default(1);
            $table->boolean('api_access')->default(false);
            $table->boolean('popular')->default(false);
            $table->json('features')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
            $table->string('status')->default('Active')->after('role');
            $table->integer('credits')->default(0)->after('status');
            $table->foreignId('plan_id')->nullable()->after('credits')->constrained()->nullOnDelete();
            $table->json('brand')->nullable()->after('plan_id');
            $table->json('onboarding')->nullable()->after('brand');
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->string('duration')->default('0:30');
            $table->string('ratio')->default('9:16');
            $table->unsignedInteger('scenes')->default(5);
            $table->unsignedInteger('uses')->default(0);
            $table->string('status')->default('Published');
            $table->string('created_by')->default('Reelsmith');
            $table->json('structure')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('Draft');
            $table->string('platform')->default('TikTok');
            $table->string('ratio')->default('9:16');
            $table->unsignedInteger('duration')->default(30);
            $table->json('idea')->nullable();
            $table->json('script')->nullable();
            $table->json('scenes')->nullable();
            $table->json('captions')->nullable();
            $table->string('voice')->nullable();
            $table->string('providers_used')->nullable();
            $table->unsignedInteger('credits_used')->default(0);
            $table->timestamp('render_started_at')->nullable();
            $table->unsignedInteger('render_seconds')->default(0);
            $table->string('output_path')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('mono', 8);
            $table->string('category');
            $table->string('tier')->nullable();
            $table->string('status')->default('off');
            $table->string('model')->nullable();
            $table->json('models')->nullable();
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->string('driver')->default('openai');
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedBigInteger('requests')->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('last_test')->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);
            $table->string('last4', 8);
            $table->string('key_hash', 64)->unique();
            $table->unsignedInteger('rate_limit')->default(60);
            $table->unsignedBigInteger('requests')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('topic')->nullable();
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('item');
            $table->string('gateway');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('Pending');
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level')->default('INFO');
            $table->string('channel')->default('app');
            $table->string('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'credit_transactions', 'payments', 'contact_messages', 'settings', 'api_keys', 'ai_providers', 'projects', 'templates'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['role', 'status', 'credits', 'brand', 'onboarding']);
        });
        Schema::dropIfExists('plans');
    }
};
