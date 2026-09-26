<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-20: 支払内訳を「施術等」「物販」へ明示配分する。旧日計表の施術支払方法・物販支払方法に対応。
 * 既存の支払内訳（配分なし）は「配分未記録」として扱い、推測で配分しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_tender_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_tender_id')->constrained('checkout_tenders')->restrictOnDelete();
            $table->string('allocation_category', 16);
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['checkout_tender_id', 'allocation_category'], 'checkout_tender_alloc_unique');
            $table->index('allocation_category');
        });
        DB::statement("ALTER TABLE checkout_tender_allocations ADD CONSTRAINT chk_checkout_tender_alloc_category CHECK (allocation_category IN ('treatment', 'retail'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_tender_allocations');
    }
};
