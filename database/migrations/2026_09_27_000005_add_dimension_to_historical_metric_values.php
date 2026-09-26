<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-26: 旧帳票の集計値を時間帯・スタッフ枠・来店動機などの切り口付きで保持する。
 * NULL=店舗全体。既存行の意味は変えない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historical_metric_values', function (Blueprint $table): void {
            $table->string('dimension', 100)->nullable()->after('metric_code');
            $table->index(['metric_code', 'dimension', 'period_start'], 'hist_metric_dimension_idx');
        });
    }

    public function down(): void
    {
        Schema::table('historical_metric_values', function (Blueprint $table): void {
            $table->dropIndex('hist_metric_dimension_idx');
            $table->dropColumn('dimension');
        });
    }
};
