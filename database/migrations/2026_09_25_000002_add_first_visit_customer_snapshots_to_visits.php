<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            // 初診完了時のみ保存。既存来店は根拠のない推測で埋めない。
            $table->string('first_visit_gender_snapshot', 10)->nullable();
            $table->unsignedSmallInteger('first_visit_age_years_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn(['first_visit_gender_snapshot', 'first_visit_age_years_snapshot']);
        });
    }
};
