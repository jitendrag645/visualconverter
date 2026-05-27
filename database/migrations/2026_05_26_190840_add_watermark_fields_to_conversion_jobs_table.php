<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            // R2 path to the watermark image (used only when target_quality = WATERMARK)
            $table->string('watermark_path')->nullable()->after('external_video_id');
            // Destination folder inside the public R2 bucket for the watermarked file
            $table->string('watermark_folder')->nullable()->after('watermark_path');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->dropColumn(['watermark_path', 'watermark_folder']);
        });
    }
};
