<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-25: コース（販売・契約上の商品）別の月間売上目標。店舗全体の月間目標（monthly_sales_targets）とは別。
 * コース＝回数券商品（ticket）・月額プラン（membership）・単発メニュー（service）。施術分析分類（M/T/A…）とは別概念。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sales_targets', function (Blueprint $table): void {
            $table->id();
            $table->date('target_month');
            $table->string('course_type', 16);
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('target_amount');
            $table->unsignedInteger('target_count')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['target_month', 'course_type', 'course_id'], 'course_sales_targets_unique');
        });
        DB::statement("ALTER TABLE course_sales_targets ADD CONSTRAINT chk_course_sales_targets_type CHECK (course_type IN ('ticket', 'membership', 'service'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('course_sales_targets');
    }
};
