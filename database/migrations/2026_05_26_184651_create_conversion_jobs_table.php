<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->string('source_r2_path');
            $table->string('source_quality'); // 8K, 4K, HD
            $table->string('destination_folder');
            $table->string('target_quality'); // 4K, HD

            $table->string('callback_url');
            $table->string('callback_token')->nullable();
            $table->string('external_video_id')->nullable();

            $table->string('output_r2_path')->nullable();

            // pending, processing, completed, failed
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_jobs');
    }
};
