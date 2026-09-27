<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files under storage/app/public that have been copied to cloud storage (S3, R2, Wasabi, B2…).
        Schema::create('cloud_files', function (Blueprint $table) {
            $table->string('path')->primary();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_files');
    }
};
