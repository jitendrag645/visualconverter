<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->unsignedSmallInteger('output_width')->nullable()->after('output_r2_path');
            $table->unsignedSmallInteger('output_height')->nullable()->after('output_width');
            $table->string('output_codec', 50)->nullable()->after('output_height');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_jobs', function (Blueprint $table) {
            $table->dropColumn(['output_width', 'output_height', 'output_codec']);
        });
    }
};
